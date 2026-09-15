<?php
/**
 * Stage 4: two things happening to one attempt at the same instant.
 *
 *   php tests/lock_race_test.php
 *
 * A blur and a hidden tab reach the server together; a pause lands in the
 * same instant as Submit. Over HTTP this cannot be tested here: on Windows
 * PHP's built-in server answers one request at a time, so two "simultaneous"
 * requests never overlap and a broken lock would pass. So each side of a race
 * is its own PHP process (tests/workers/lock_race_worker.php), connected to
 * the test database, released at one shared instant.
 *
 * Every round uses a fresh attempt. A round in which either worker was not
 * already waiting at the start instant counts as a failure: it raced nothing.
 *
 * No web server and no port. This file resets the test database and builds
 * everything from nothing. The reset also removes the users fixture: run
 * tests/setup_users_fixture.php again afterwards before running
 * tests/enrollments_list_test.php.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const ROUNDS = 50;
const BATCH  = 10;   // pairs released together; each pair races only itself

// ---- Assertions -----------------------------------------------------------

$passed = 0;
$failed = [];

function check(string $what, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        return;
    }

    $failed[] = $what . ($detail === '' ? '' : '  (' . $detail . ')');
    echo "  FAIL  $what" . ($detail === '' ? '' : "  ($detail)") . "\n";
}

function same(string $what, $expected, $actual): void
{
    check(
        $what,
        $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

test_reset_database();

// ---- Fixture ---------------------------------------------------------------

$db = Database::getInstance();

$db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES ('Race Teacher', 'race@exam.local', 'x', 'lecturer')")->execute();
$teacherId = (int) $db->lastInsertId();
$classId = (int) $db->query("SELECT id FROM classes ORDER BY id LIMIT 1")->fetchColumn();
$db->prepare("INSERT INTO users (full_name, admission_no, password_hash, role, class_id) VALUES ('Racer', 'ADM/RACE/1', 'y', 'student', ?)")->execute([$classId]);
$studentId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO courses (course_code, title, lecturer_id) VALUES ('RACE101', 'Race', ?)")->execute([$teacherId]);
$courseId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'mcq', 'Q', 1, ?)")->execute([$courseId, $teacherId]);
$questionId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, 'A', 1), (?, 'B', 0)")->execute([$questionId, $questionId]);

function fresh_attempt(): int
{
    global $db, $courseId, $questionId, $studentId;

    $db->prepare("INSERT INTO exams (course_id, title, duration_minutes, questions_per_attempt, shuffle_options,
                                     window_start, window_end, status)
                  VALUES (?, 'Race', 60, 1, 0, NOW() - INTERVAL 1 HOUR, NOW() + INTERVAL 6 HOUR, 'published')")
       ->execute([$courseId]);
    $examId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO exam_question_pool (exam_id, question_id) VALUES (?,?)")->execute([$examId, $questionId]);

    return (new Attempt())->start($examId, $studentId, (new Exam())->find($examId));
}

/**
 * Run ROUNDS rounds, BATCH at a time. Each round is [attempt id, action A,
 * action B]. Returns, per attempt id, [ready A, outcome A, ready B, outcome B].
 */
function race(array $actions): array
{
    $results = [];
    for ($done = 0; $done < ROUNDS; $done += BATCH) {
        $procs = [];
        // Far enough ahead that every worker in the batch has started PHP,
        // connected, and is waiting.
        $startAt = sprintf('%.6f', microtime(true) + 3.0);

        for ($i = 0; $i < min(BATCH, ROUNDS - $done); $i++) {
            $id = fresh_attempt();
            foreach ($actions as $side => $action) {
                $p = proc_open(
                    [PHP_BINARY, __DIR__ . '/workers/lock_race_worker.php', $action, (string) $id, $startAt],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    APP_ROOT
                );
                $procs[] = [$id, $side, $p, $pipes];
            }
        }

        foreach ($procs as [$id, $side, $p, $pipes]) {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);

            preg_match('/^RESULT (\d) (.*)$/m', $out, $m);
            $results[$id][$side] = [isset($m[1]) ? (int) $m[1] : 0, isset($m[2]) ? trim($m[2]) : 'no result: ' . trim($out)];
        }
    }
    return $results;
}

function lock_rows(int $id): array
{
    $stmt = Database::getInstance()->prepare("SELECT * FROM attempt_locks WHERE attempt_id = ?");
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

// ============================================================================
// Three triggers, not two. With two, the second request's UPDATE waits for the
// first to commit, so only one request ever appends and a lost append could
// never happen. With three, two appends overlap, which is what R2 needs.
section('R1 R2 a blur, a hidden tab and a fullscreen exit at the same instant');

$rounds = race(['blur' => 'lock_blur', 'hidden' => 'lock_hidden', 'fullscreen' => 'lock_fullscreen']);

$late = $errors = $notOneRow = $wrongOutcomes = $lostTriggers = $brokenInvariant = [];
foreach ($rounds as $id => $r) {
    if ($r['blur'][0] !== 1 || $r['hidden'][0] !== 1 || $r['fullscreen'][0] !== 1) $late[] = $id;

    $outcomes = [$r['blur'][1], $r['hidden'][1], $r['fullscreen'][1]];
    foreach ($outcomes as $o) {
        if (strpos($o, 'error') === 0 || strpos($o, 'no result') === 0) $errors[] = "$id: $o";
    }
    $sorted = $outcomes;
    sort($sorted);
    if ($sorted !== ['appended', 'appended', 'locked']) $wrongOutcomes[] = "$id: " . implode(', ', $outcomes);

    $rows = lock_rows($id);
    if (count($rows) !== 1) $notOneRow[] = "$id: " . count($rows) . ' rows';

    $types = [];
    foreach ($rows as $row) {
        foreach (json_decode((string) $row['detail'], true)['triggers'] ?? [] as $t) {
            $types[] = $t['type'];
        }
    }
    sort($types);
    if ($types !== ['fullscreen_exit', 'tab_hidden', 'window_blur']) $lostTriggers[] = "$id: " . implode(', ', $types);

    $lockedAt = Database::getInstance()->query("SELECT locked_at FROM exam_attempts WHERE id = $id")->fetchColumn();
    $open = count(array_filter($rows, static fn($x) => $x['unlocked_at'] === null));
    if (!($lockedAt !== null && $open === 1)) $brokenInvariant[] = $id;
}

same('R1 ' . ROUNDS . ' rounds ran', ROUNDS, count($rounds));
same('R1 every worker was waiting at the start instant, so every round was a real race', [], $late);
same('R1 no call raised an error', [], array_slice($errors, 0, 3));
same('R1 every round: one call paused the attempt and the other two joined that pause', [], array_slice($wrongOutcomes, 0, 3));
same('R1 every round ended with exactly one lock row', [], array_slice($notOneRow, 0, 3));
same('R1 and the attempt paused with that one lock open', [], array_slice($brokenInvariant, 0, 3));
same('R2 every round kept all three triggers in the lock\'s detail', [], array_slice($lostTriggers, 0, 3));

// ============================================================================
section('R3 a paused attempt cannot be submitted');

$r3 = fresh_attempt();
$attempts = new Attempt();
same('R3 the attempt pauses', 'locked', $attempts->lock($r3, 'fullscreen_exit', null));
same('R3 submitAndGrade on it does nothing and says so', null, $attempts->submitAndGrade($r3));
$row = $db->query("SELECT status, total_score, grading_status, locked_at FROM exam_attempts WHERE id = $r3")->fetch();
same('R3 it is still in progress', 'in_progress', $row['status']);
same('R3 ungraded', [null, 'pending'], [$row['total_score'], $row['grading_status']]);
check('R3 and still paused', $row['locked_at'] !== null);

// ============================================================================
section('R4 Submit and a pause at the same instant');

$rounds = race(['submit' => 'submit', 'lock' => 'lock_blur']);

$late = $errors = $bad = [];
$submittedFirst = $pausedFirst = 0;
foreach ($rounds as $id => $r) {
    if ($r['submit'][0] !== 1 || $r['lock'][0] !== 1) $late[] = $id;
    foreach ([$r['submit'][1], $r['lock'][1]] as $o) {
        if (strpos($o, 'error') === 0 || strpos($o, 'no result') === 0) $errors[] = "$id: $o";
    }

    $a = Database::getInstance()->query("SELECT status, locked_at FROM exam_attempts WHERE id = $id")->fetch();
    $rows = lock_rows($id);
    $open = count(array_filter($rows, static fn($x) => $x['unlocked_at'] === null));

    $submitted = $a['status'] === 'submitted' && $a['locked_at'] === null && count($rows) === 0
              && $r['submit'][1] === 'submitted' && $r['lock'][1] === 'closed';
    $paused    = $a['status'] === 'in_progress' && $a['locked_at'] !== null && $open === 1 && count($rows) === 1
              && $r['submit'][1] === 'refused' && $r['lock'][1] === 'locked';

    if ($submitted) {
        $submittedFirst++;
    } elseif ($paused) {
        $pausedFirst++;
    } else {
        $bad[] = sprintf('%d: status %s, locked_at %s, %d lock rows (%d open); submit said %s, lock said %s',
            $id, $a['status'], var_export($a['locked_at'], true), count($rows), $open, $r['submit'][1], $r['lock'][1]);
    }
}

same('R4 ' . ROUNDS . ' rounds ran', ROUNDS, count($rounds));
same('R4 every worker was waiting at the start instant, so every round was a real race', [], $late);
same('R4 no call raised an error', [], array_slice($errors, 0, 3));
same('R4 every round ended either submitted and never paused, or paused and never submitted', [], array_slice($bad, 0, 3));
printf("  (R4: submit won %d rounds, the pause won %d)\n", $submittedFirst, $pausedFirst);

// ---- Diagnostics ----------------------------------------------------------

section('Diagnostics');
$diags = test_diagnostics();
check('no notices or deprecations were raised', $diags === [],
    implode(' | ', array_slice($diags, 0, 5)));

// ---- Result ---------------------------------------------------------------

echo "\n";
printf("%d passed, %d failed\n", $passed, count($failed));

if ($failed !== []) {
    echo "\nFailures:\n";
    foreach ($failed as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

exit(0);
