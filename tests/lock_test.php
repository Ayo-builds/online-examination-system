<?php
/**
 * Stage 4: pausing an attempt, on the server.
 *
 *   php tests/lock_test.php
 *
 * Runs against the test database only - bootstrap.php refuses anything else -
 * and drives the REAL application over HTTP through PHP's built-in server:
 *
 *   127.0.0.1:8096  the test configuration
 *   127.0.0.1:8095  the same database, with PHP's clock twelve hours off
 *                   (tests/config.clock_offset.php)
 *
 * Both servers are started and stopped here, and each refuses a port that is
 * already taken.
 *
 * What HTTP cannot test is two requests arriving at once: on Windows the
 * built-in server answers one request at a time. That lives in
 * tests/lock_race_test.php, which races separate PHP processes against the
 * database directly.
 *
 * Moments like "3 seconds after the pause" are made by moving locked_at back
 * with the database's own clock, never by sleeping and never with PHP's time.
 *
 * This file resets the test database and builds everything from nothing. The
 * reset also removes the users fixture: run tests/setup_users_fixture.php again
 * afterwards before running tests/enrollments_list_test.php.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

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

// ---- The app, served twice ------------------------------------------------

$host = '127.0.0.1';
$servers = [
    'main'   => "http://$host:8096/",
    'offset' => "http://$host:8095/",
];

test_start_server($host, 8096);
test_start_server($host, 8095, 'tests/config.clock_offset.php');
test_reset_database();

// ---- HTTP, one cookie jar per actor ---------------------------------------

$jars = [];
register_shutdown_function(static function () use (&$jars): void {
    foreach ($jars as $jar) {
        if (is_file($jar)) unlink($jar);
    }
});

/**
 * @return array{status:int, body:string, json:?array, location:string}
 */
function http(string $who, string $method, string $path, array $post = [], string $server = 'main'): array
{
    global $servers, $jars;

    $jars[$who] ??= (string) tempnam(sys_get_temp_dir(), 'exam_cookies_');

    $headers = [];
    $ch = curl_init($servers[$server] . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jars[$who],
        CURLOPT_COOKIEFILE     => $jars[$who],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($body, true);

    return [
        'status'   => $status,
        'body'     => $body,
        'json'     => is_array($json) ? $json : null,
        'location' => $headers['location'] ?? '',
    ];
}

/** Sign someone in on a server and return their session's CSRF token. */
function sign_in(string $who, string $identifier, string $password, string $server = 'main'): string
{
    $login = http($who, 'GET', 'auth/login', [], $server);
    preg_match('/name="csrf_token" value="([^"]+)"/', $login['body'], $m);
    $token = $m[1] ?? '';

    $res = http($who, 'POST', 'auth/authenticate', [
        'csrf_token' => $token,
        'identifier' => $identifier,
        'password'   => $password,
    ], $server);
    check("$who signed in on $server", $res['status'] === 302, 'status ' . $res['status']);

    return $token;
}

// ---- Database helpers ------------------------------------------------------

$db = Database::getInstance();

function one(string $sql, array $params = []): ?array
{
    $stmt = Database::getInstance()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function scalar(string $sql, array $params = [])
{
    $stmt = Database::getInstance()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function locks_for(int $attemptId): array
{
    $stmt = Database::getInstance()->prepare("SELECT * FROM attempt_locks WHERE attempt_id = ? ORDER BY id");
    $stmt->execute([$attemptId]);
    return $stmt->fetchAll();
}

/** A value that may legitimately be null, or the string missing when the key is absent. */
function field(?array $row, string $key)
{
    return $row !== null && array_key_exists($key, $row) ? $row[$key] : 'missing';
}

function triggers_of(array $lock): array
{
    return json_decode((string) $lock['detail'], true)['triggers'] ?? [];
}

/** The rule the whole lock design stands on: paused exactly when one lock is open. */
function invariant(int $attemptId, string $when): void
{
    $a = one("SELECT locked_at FROM exam_attempts WHERE id = ?", [$attemptId]);
    $open = (int) scalar("SELECT COUNT(*) FROM attempt_locks WHERE attempt_id = ? AND unlocked_at IS NULL", [$attemptId]);
    check("invariant holds $when: locked_at is set exactly when one lock is open",
        ($a['locked_at'] !== null && $open === 1) || ($a['locked_at'] === null && $open === 0),
        'locked_at ' . var_export($a['locked_at'], true) . ", open locks $open");
}

/** Move a pause back in time, by the database's clock. */
function pause_began_seconds_ago(int $attemptId, int $seconds): void
{
    Database::getInstance()->prepare(
        "UPDATE exam_attempts SET locked_at = NOW() - INTERVAL ? SECOND WHERE id = ?"
    )->execute([$seconds, $attemptId]);
    Database::getInstance()->prepare(
        "UPDATE attempt_locks SET locked_at = NOW() - INTERVAL ? SECOND WHERE attempt_id = ? AND unlocked_at IS NULL"
    )->execute([$seconds, $attemptId]);
}

// ---- Fixture ---------------------------------------------------------------

$teacherPass = 'teacher-pass-123';
$db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?, 'lecturer')")
   ->execute(['Lock Teacher', 'lock@exam.local', Password::hash($teacherPass)]);
$teacherId = (int) $db->lastInsertId();

$classId = (int) $db->query("SELECT id FROM classes ORDER BY id LIMIT 1")->fetchColumn();
$pass = 'student-pass-123';
$students = [];
foreach (['ada' => 'ADM/LOCK/1', 'bola' => 'ADM/LOCK/2'] as $who => $adm) {
    $db->prepare("INSERT INTO users (full_name, admission_no, password_hash, role, class_id) VALUES (?,?,?, 'student', ?)")
       ->execute([ucfirst($who), $adm, Password::hash($pass), $classId]);
    $students[$who] = (int) $db->lastInsertId();
}

$db->prepare("INSERT INTO courses (course_code, title, lecturer_id) VALUES (?,?,?)")
   ->execute(['LOCK101', 'Lock Subject', $teacherId]);
$courseId = (int) $db->lastInsertId();
foreach ($students as $id) {
    $db->prepare("INSERT INTO enrollments (student_id, course_id) VALUES (?,?)")->execute([$id, $courseId]);
}

$mcqText   = 'Which gas do plants take in? Lock paper';
$essayText = 'Describe a paused exam. Lock paper';
$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'mcq', ?, 2, ?)")
   ->execute([$courseId, $mcqText, $teacherId]);
$mcqId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, 'Carbon dioxide', 1)")->execute([$mcqId]);
$rightOption = (int) $db->lastInsertId();
$db->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, 'Helium', 0)")->execute([$mcqId]);
$wrongOption = (int) $db->lastInsertId();
$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'essay', ?, 5, ?)")
   ->execute([$courseId, $essayText, $teacherId]);
$essayId = (int) $db->lastInsertId();

/** A new exam with a new attempt for $who, so no section depends on another. */
function fresh_attempt(string $who): int
{
    global $db, $courseId, $mcqId, $essayId, $students;

    $db->prepare(
        "INSERT INTO exams (course_id, title, duration_minutes, questions_per_attempt, shuffle_options,
                            window_start, window_end, status)
         VALUES (?, 'Lock exam', 60, 2, 0, NOW() - INTERVAL 1 HOUR, NOW() + INTERVAL 6 HOUR, 'published')"
    )->execute([$courseId]);
    $examId = (int) $db->lastInsertId();
    foreach ([$mcqId, $essayId] as $q) {
        $db->prepare("INSERT INTO exam_question_pool (exam_id, question_id) VALUES (?,?)")->execute([$examId, $q]);
    }

    $attempts = new Attempt();
    return $attempts->start($examId, $students[$who], (new Exam())->find($examId));
}

$activityLogsAtStart = (int) $db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();

$token = sign_in('ada', 'ADM/LOCK/1', $pass);

$lock = static fn(int $id, array $fields, string $who = 'ada', string $tok = '', string $server = 'main'): array
    => http($who, 'POST', "student/lock/$id", $fields + ['csrf_token' => $tok !== '' ? $tok : $GLOBALS['token']], $server);
$save = static fn(int $id, array $fields, string $who = 'ada', string $tok = '', string $server = 'main'): array
    => http($who, 'POST', "student/saveAnswer/$id", $fields + ['csrf_token' => $tok !== '' ? $tok : $GLOBALS['token']], $server);

// ============================================================================
section('L1 a first lock pauses the attempt and records its trigger');

$a1 = fresh_attempt('ada');
$res = $lock($a1, ['trigger' => 'window_blur', 'blur_ms' => '3100']);
same('L1 POST lock returns 200', 200, $res['status']);
same('L1 as a new pause', ['ok' => true, 'locked' => true, 'new' => true], $res['json']);

$attempt = one("SELECT locked_at FROM exam_attempts WHERE id = ?", [$a1]);
$locks   = locks_for($a1);
check('L1 the attempt has locked_at set', $attempt['locked_at'] !== null);
same('L1 one lock row', 1, count($locks));
same('L1 the lock row starts at the attempt\'s locked_at, to the second', $attempt['locked_at'], $locks[0]['locked_at'] ?? null);
same('L1 with the trigger that caused it', 'window_blur', $locks[0]['trigger_type'] ?? null);
same('L1 still open', null, field($locks[0] ?? null, 'unlocked_at'));

$t = triggers_of($locks[0] ?? ['detail' => '{}']);
same('L1 detail holds one trigger', 1, count($t));
same('L1 of that type', 'window_blur', $t[0]['type'] ?? null);
same('L1 at the database\'s time of the pause', $attempt['locked_at'], $t[0]['at'] ?? null);
same('L1 with its blur length as an integer', 3100, field($t[0] ?? null, 'blur_ms'));
invariant($a1, 'after a first lock');

// A malformed blur length is stored as null, never refused and never cast.
$a1b = fresh_attempt('ada');
$res = $lock($a1b, ['trigger' => 'window_blur', 'blur_ms' => '3200abc']);
same('L1 a lock with blur_ms "3200abc" is still a pause', true, $res['json']['new'] ?? null);
same('L1 and that blur_ms is stored as null, not 3200', null,
    field(triggers_of(locks_for($a1b)[0] ?? ['detail' => '{}'])[0] ?? null, 'blur_ms'));

$cases = [
    ['-1', null], ['600001', null], ['3.5', null], ['', null], [' 3200', null], ['1e3', null],
    ['0x10', null], ['03200', null], [['5'], null], ['0', 0], ['600000', 600000], ['42', 42],
];
foreach ($cases as [$raw, $expect]) {
    $res = $lock($a1b, ['trigger' => 'window_blur', 'blur_ms' => $raw]);
    $shown = is_array($raw) ? 'an array' : '"' . $raw . '"';
    same("L1 blur_ms $shown is accepted as a trigger", 200, $res['status']);
    $all = triggers_of(locks_for($a1b)[0]);
    same("L1 blur_ms $shown is stored as " . var_export($expect, true), $expect, field($all === [] ? null : end($all), 'blur_ms'));
}
$res = $lock($a1b, ['trigger' => 'tab_hidden', 'blur_ms' => '3000']);
$all = triggers_of(locks_for($a1b)[0]);
same('L1 a hidden tab carries no blur length even if one is sent', null, field($all === [] ? null : end($all), 'blur_ms'));
same('L1 and every one of those triggers joined the same single pause', 1, count(locks_for($a1b)));

// ============================================================================
section('L2 a second trigger joins the pause');

$a2 = fresh_attempt('ada');
$lock($a2, ['trigger' => 'window_blur', 'blur_ms' => '3050']);
$firstLockedAt = one("SELECT locked_at FROM exam_attempts WHERE id = ?", [$a2])['locked_at'];

// A second later, so a pause that moved would show it.
usleep(1100000);

$res = $lock($a2, ['trigger' => 'tab_hidden']);
same('L2 a second lock request returns 200', 200, $res['status']);
same('L2 not as a new pause', ['ok' => true, 'locked' => true, 'new' => false], $res['json']);
same('L2 still one lock row', 1, count(locks_for($a2)));
same('L2 locked_at did not move', $firstLockedAt, one("SELECT locked_at FROM exam_attempts WHERE id = ?", [$a2])['locked_at']);

$t = triggers_of(locks_for($a2)[0]);
same('L2 detail holds both triggers, in order', ['window_blur', 'tab_hidden'], array_column($t, 'type'));
check('L2 the second trigger has its own later time', ($t[1]['at'] ?? '') > ($t[0]['at'] ?? 'z'),
    json_encode($t));
invariant($a2, 'after a second trigger');

// ============================================================================
section('L3 every trigger is kept');

$res = $lock($a2, ['trigger' => 'fullscreen_exit']);
same('L3 a third trigger is accepted', 200, $res['status']);
same('L3 detail holds all three, in order', ['window_blur', 'tab_hidden', 'fullscreen_exit'],
    array_column(triggers_of(locks_for($a2)[0]), 'type'));
same('L3 in one lock row', 1, count(locks_for($a2)));

// ============================================================================
section('L4 a real submit is not a pause, and nothing pauses it afterwards');

$a4 = fresh_attempt('ada');
$res = http('ada', 'POST', "student/submitExam/$a4", ['csrf_token' => $token, 'unsaved_count' => 0]);
same('L4 a normal submit redirects', 302, $res['status']);
check('L4 to the result page', str_ends_with($res['location'], "student/result/$a4"), $res['location']);

$row = one("SELECT status, locked_at FROM exam_attempts WHERE id = ?", [$a4]);
same('L4 the attempt ends submitted', 'submitted', $row['status']);
same('L4 not locked', null, $row['locked_at']);
same('L4 with no lock row', 0, count(locks_for($a4)));

$res = $lock($a4, ['trigger' => 'tab_hidden']);
same('L4 a lock arriving after the submit returns 409', 409, $res['status']);
same('L4 as closed', 'closed', $res['json']['error'] ?? null);
same('L4 and creates no lock row', 0, count(locks_for($a4)));
same('L4 nor sets locked_at', null, one("SELECT locked_at FROM exam_attempts WHERE id = ?", [$a4])['locked_at']);

// ============================================================================
section('L5 a paused attempt cannot be submitted, or changed through Submit');

$a5 = fresh_attempt('ada');
same('L5 an essay saves before the pause', 200, $save($a5, ['question_id' => $essayId, 'essay_text' => 'Before the pause.'])['status']);
same('L5 a choice saves before the pause', 200, $save($a5, ['question_id' => $mcqId, 'option_id' => $wrongOption])['status']);
$lock($a5, ['trigger' => 'fullscreen_exit']);

$answersBefore = $db->query("SELECT question_id, selected_option_id, essay_text, awarded_marks, graded_at
                               FROM attempt_answers WHERE attempt_id = $a5 ORDER BY question_id")->fetchAll();

$res = http('ada', 'POST', "student/submitExam/$a5", [
    'csrf_token'    => $token,
    'unsaved_count' => 0,
    'answer'        => [$mcqId => $rightOption, $essayId => 'Changed while paused, sent with Submit.'],
]);
same('L5 submit while paused redirects', 302, $res['status']);
check('L5 back to the exam page, not the result', str_ends_with($res['location'], "student/exam/$a5"), $res['location']);

$row = one("SELECT status, total_score, grading_status, submitted_at FROM exam_attempts WHERE id = ?", [$a5]);
same('L5 the attempt is still in progress', 'in_progress', $row['status']);
same('L5 with no score', null, $row['total_score']);
same('L5 not graded', 'pending', $row['grading_status']);
same('L5 and no submitted time', null, $row['submitted_at']);
same('L5 every stored answer is exactly as it was, despite the answers in that POST',
    $answersBefore,
    $db->query("SELECT question_id, selected_option_id, essay_text, awarded_marks, graded_at
                  FROM attempt_answers WHERE attempt_id = $a5 ORDER BY question_id")->fetchAll());
invariant($a5, 'after a refused submit');

// submitExam never reads answer fields at all, paused or not. Proven the
// same way on an attempt that is not paused: it submits, and what is graded
// is what was saved, not what was posted.
$a5b = fresh_attempt('ada');
$save($a5b, ['question_id' => $essayId, 'essay_text' => 'Saved before Submit.']);
$save($a5b, ['question_id' => $mcqId, 'option_id' => $wrongOption]);
$res = http('ada', 'POST', "student/submitExam/$a5b", [
    'csrf_token'    => $token,
    'unsaved_count' => 0,
    'answer'        => [$mcqId => $rightOption, $essayId => 'Only in the POST.'],
]);
check('L5 an unpaused submit with answer fields goes to the result', str_ends_with($res['location'], "student/result/$a5b"), $res['location']);
same('L5 and grades the saved essay, not the posted one', 'Saved before Submit.',
    scalar("SELECT essay_text FROM attempt_answers WHERE attempt_id = ? AND question_id = ?", [$a5b, $essayId]));
same('L5 and the saved choice, not the posted one', $wrongOption,
    (int) scalar("SELECT selected_option_id FROM attempt_answers WHERE attempt_id = ? AND question_id = ?", [$a5b, $mcqId]));
same('L5 which scores 0, because the posted right answer was never used', '0.00',
    scalar("SELECT total_score FROM exam_attempts WHERE id = ?", [$a5b]));

// ============================================================================
section('L6 no paper while paused');

$a6 = fresh_attempt('ada');
$res = http('ada', 'POST', "student/paper/$a6", ['csrf_token' => $token]);
same('L6 unpaused, the paper returns 200', 200, $res['status']);
check('L6 with both questions in it (so the check below means something)',
    strpos($res['body'], json_encode($mcqText)) !== false && strpos($res['body'], json_encode($essayText)) !== false);

$lock($a6, ['trigger' => 'tab_hidden']);
$res = http('ada', 'POST', "student/paper/$a6", ['csrf_token' => $token]);
same('L6 paused, the paper returns 423', 423, $res['status']);
same('L6 as locked', 'locked', $res['json']['error'] ?? null);
check('L6 with no question text', strpos($res['body'], 'Lock paper') === false, $res['body']);

$res = http('ada', 'GET', "student/exam/$a6");
same('L6 the exam page itself still loads', 200, $res['status']);
check('L6 with no question text in it either', strpos($res['body'], 'Lock paper') === false);

// ============================================================================
section('L7 saves: accepted for 5 seconds after the pause, then 423');

$a7 = fresh_attempt('ada');
$lock($a7, ['trigger' => 'window_blur', 'blur_ms' => '3000']);

pause_began_seconds_ago($a7, 3);
$res = $save($a7, ['question_id' => $essayId, 'essay_text' => 'Three seconds into the pause.']);
same('L7 an essay 3 s after the pause is accepted', 200, $res['status']);
same('L7 and stored', 'Three seconds into the pause.',
    scalar("SELECT essay_text FROM attempt_answers WHERE attempt_id = ? AND question_id = ?", [$a7, $essayId]));
$res = $save($a7, ['question_id' => $mcqId, 'option_id' => $rightOption]);
same('L7 a choice 3 s after the pause is accepted', 200, $res['status']);

pause_began_seconds_ago($a7, 7);
$res = $save($a7, ['question_id' => $essayId, 'essay_text' => 'Seven seconds into the pause.']);
same('L7 an essay 7 s after the pause is refused with 423', 423, $res['status']);
same('L7 as locked', 'locked', $res['json']['error'] ?? null);
check('L7 never as closed, which would make the page submit', ($res['json']['error'] ?? null) !== 'closed');
same('L7 and the stored essay is unchanged', 'Three seconds into the pause.',
    scalar("SELECT essay_text FROM attempt_answers WHERE attempt_id = ? AND question_id = ?", [$a7, $essayId]));
$res = $save($a7, ['question_id' => $mcqId, 'option_id' => $wrongOption]);
same('L7 a choice 7 s after is refused too', 423, $res['status']);
same('L7 leaving the earlier choice', $rightOption,
    (int) scalar("SELECT selected_option_id FROM attempt_answers WHERE attempt_id = ? AND question_id = ?", [$a7, $mcqId]));

// ============================================================================
section('L8 the save window follows the database clock when PHP\'s is wrong');

$offsetToken = sign_in('ada_offset', 'ADM/LOCK/1', $pass, 'offset');
$a8 = fresh_attempt('ada');

// The control: prove the two clocks really disagree on this server before
// trusting anything below. The exam page's countdown is PHP's arithmetic; the
// heartbeat's is the database's.
$page = http('ada_offset', 'GET', "student/exam/$a8", [], 'offset');
preg_match('/const remaining = (-?\d+);/', $page['body'], $m);
$phpRemaining = isset($m[1]) ? (int) $m[1] : null;
$beat = http('ada_offset', 'POST', "student/heartbeat/$a8", ['csrf_token' => $offsetToken], 'offset');
$dbRemaining = $beat['json']['remaining'] ?? null;
check('L8 control: PHP and the database disagree about the time by more than 11 hours on this server',
    $phpRemaining !== null && $dbRemaining !== null && abs($phpRemaining - $dbRemaining) > 11 * 3600,
    "PHP says $phpRemaining s left, the database says $dbRemaining s");

$res = $lock($a8, ['trigger' => 'tab_hidden'], 'ada_offset', $offsetToken, 'offset');
same('L8 the offset server pauses the attempt', true, $res['json']['new'] ?? null);

pause_began_seconds_ago($a8, 3);
$res = $save($a8, ['question_id' => $essayId, 'essay_text' => 'Offset clock, 3 s.'], 'ada_offset', $offsetToken, 'offset');
same('L8 a save 3 s after the pause is accepted', 200, $res['status']);

pause_began_seconds_ago($a8, 7);
$res = $save($a8, ['question_id' => $essayId, 'essay_text' => 'Offset clock, 7 s.'], 'ada_offset', $offsetToken, 'offset');
same('L8 a save 7 s after the pause is refused', 423, $res['status']);
same('L8 leaving the 3 s answer', 'Offset clock, 3 s.',
    scalar("SELECT essay_text FROM attempt_answers WHERE attempt_id = ? AND question_id = ?", [$a8, $essayId]));

// ============================================================================
section('L9 heartbeat: records, logs a long silence, never pauses');

$a9 = fresh_attempt('ada');
$beat = fn(int $id): array => http('ada', 'POST', "student/heartbeat/$id", ['csrf_token' => $GLOBALS['token']]);
$events = static fn(int $id, string $type): array => $GLOBALS['db']->query(
    "SELECT detail FROM attempt_events WHERE attempt_id = $id AND event_type = '$type' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

$res = $beat($a9);
same('L9 the first heartbeat returns 200', 200, $res['status']);
same('L9 not paused', false, $res['json']['locked'] ?? null);
check('L9 with the time left by the database', is_int($res['json']['remaining'] ?? null)
    && $res['json']['remaining'] > 3500 && $res['json']['remaining'] <= 3600, var_export($res['json']['remaining'] ?? null, true));
check('L9 last_seen_at is recorded', scalar("SELECT last_seen_at FROM exam_attempts WHERE id = ?", [$a9]) !== null);
same('L9 and nothing is logged', [], $events($a9, 'heartbeat_gap'));

$db->prepare("UPDATE exam_attempts SET last_seen_at = NOW() - INTERVAL 30 SECOND WHERE id = ?")->execute([$a9]);
$beat($a9);
same('L9 after 30 s of silence nothing is logged', [], $events($a9, 'heartbeat_gap'));

$db->prepare("UPDATE exam_attempts SET last_seen_at = NOW() - INTERVAL 60 SECOND WHERE id = ?")->execute([$a9]);
$res = $beat($a9);
$gaps = $events($a9, 'heartbeat_gap');
same('L9 after 60 s of silence one gap is logged', 1, count($gaps));
$seconds = json_decode($gaps[0] ?? '{}', true)['seconds'] ?? null;
check('L9 recording how long the silence was', is_int($seconds) && $seconds >= 60 && $seconds < 70, var_export($seconds, true));
same('L9 and the attempt is NOT paused', null, scalar("SELECT locked_at FROM exam_attempts WHERE id = ?", [$a9]));
same('L9 no lock row', 0, count(locks_for($a9)));
same('L9 the response still says not paused', false, $res['json']['locked'] ?? null);

$lock($a9, ['trigger' => 'window_blur']);
same('L9 once paused, the heartbeat says so', true, $beat($a9)['json']['locked'] ?? null);

// ============================================================================
section('L10 events: a short blur, stored by the database\'s clock');

$a10 = fresh_attempt('ada');
$event = fn(int $id, array $fields): array => http('ada', 'POST', "student/event/$id", $fields + ['csrf_token' => $GLOBALS['token']]);

$res = $event($a10, ['type' => 'blur_blip', 'ms' => '800']);
same('L10 a blur_blip returns 200', 200, $res['status']);
$row = one("SELECT detail, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM attempt_events WHERE attempt_id = ? ORDER BY id DESC LIMIT 1", [$a10]);
same('L10 stored with its length', ['ms' => 800], json_decode($row['detail'] ?? '{}', true));
check('L10 at the database\'s current time', $row !== null && (int) $row['age'] >= 0 && (int) $row['age'] <= 5, var_export($row['age'] ?? null, true));

$cases = [['3200abc', null], ['-5', null], ['600001', null], ['7.5', null], ['', null], [['1'], null],
          ['0', 0], ['600000', 600000]];
foreach ($cases as [$raw, $expect]) {
    $shown = is_array($raw) ? 'an array' : '"' . $raw . '"';
    same("L10 ms $shown is accepted", 200, $event($a10, ['type' => 'blur_blip', 'ms' => $raw])['status']);
    $last = scalar("SELECT detail FROM attempt_events WHERE attempt_id = ? ORDER BY id DESC LIMIT 1", [$a10]);
    same("L10 ms $shown is stored as " . var_export($expect, true), ['ms' => $expect], json_decode((string) $last, true));
}
same('L10 a blur_blip with no ms is stored with null', 200, $event($a10, ['type' => 'blur_blip'])['status']);

$before = (int) scalar("SELECT COUNT(*) FROM attempt_events WHERE attempt_id = ?", [$a10]);
foreach (['paste_landed', 'heartbeat_gap', 'claim', 'nonsense', ''] as $type) {
    $res = $event($a10, ['type' => $type, 'ms' => '100']);
    same("L10 event type \"$type\" is refused with 422", 422, $res['status']);
}
same('L10 and none of those wrote a row', $before, (int) scalar("SELECT COUNT(*) FROM attempt_events WHERE attempt_id = ?", [$a10]));

// ============================================================================
section('L11 what a lock request may not do');

$a11 = fresh_attempt('ada');
foreach (['alt_tab', 'new_session', ''] as $trigger) {
    same("L11 trigger \"$trigger\" is refused with 422", 422, $lock($a11, ['trigger' => $trigger])['status']);
}
same('L11 a lock with no trigger at all is refused', 422, http('ada', 'POST', "student/lock/$a11", ['csrf_token' => $token])['status']);
same('L11 a lock with a bad CSRF token is refused', 403, http('ada', 'POST', "student/lock/$a11", ['csrf_token' => 'x', 'trigger' => 'tab_hidden'])['status']);
same('L11 none of that paused anything', 0, count(locks_for($a11)));

$bolas = fresh_attempt('bola');
same("L11 another student's attempt is 404", 404, $lock($bolas, ['trigger' => 'tab_hidden'])['status']);
same("L11 and was not paused", 0, count(locks_for($bolas)));

$a11b = fresh_attempt('ada');
$db->prepare("UPDATE exam_attempts SET deadline_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?")->execute([$a11b]);
$res = $lock($a11b, ['trigger' => 'tab_hidden']);
same('L11 past the deadline a lock returns 409', 409, $res['status']);
$row = one("SELECT status, closed_by_system_at, locked_at FROM exam_attempts WHERE id = ?", [$a11b]);
same('L11 and the attempt has been closed', 'auto_submitted', $row['status']);
check('L11 by the system', $row['closed_by_system_at'] !== null);
same('L11 without a pause', [null, 0], [$row['locked_at'], count(locks_for($a11b))]);

// ============================================================================
section('L12 a paper closed while paused says so');

$teacherToken = sign_in('teacher', 'lock@exam.local', $teacherPass);

$a12 = fresh_attempt('ada');
$save($a12, ['question_id' => $essayId, 'essay_text' => 'Written before the pause.']);
$lock($a12, ['trigger' => 'fullscreen_exit']);
$lockedAt12 = scalar("SELECT locked_at FROM exam_attempts WHERE id = ?", [$a12]);

$a12b = fresh_attempt('ada');

foreach ([$a12, $a12b] as $id) {
    $db->prepare("UPDATE exam_attempts SET deadline_at = NOW() - INTERVAL 10 MINUTE WHERE id = ?")->execute([$id]);
}

// Any Teacher page runs the sweep.
$grading = http('teacher', 'GET', 'lecturer/grading');
same('L12 the grading queue loads', 200, $grading['status']);

$row = one("SELECT status, closed_by_system_at, locked_at FROM exam_attempts WHERE id = ?", [$a12]);
same('L12 the sweep closed the paused attempt', 'auto_submitted', $row['status']);
check('L12 as the system', $row['closed_by_system_at'] !== null);
same('L12 keeping when it was paused', $lockedAt12, $row['locked_at']);
same('L12 and its lock row stays, still open, for the record', [1, null],
    [count(locks_for($a12)), field(locks_for($a12)[0] ?? null, 'unlocked_at')]);

/** The grading-queue row for one attempt. */
$rowFor = static function (string $html, int $id): string {
    foreach (explode('<tr', $html) as $chunk) {
        if (strpos($chunk, "gradeAttempt/$id\"") !== false) {
            return $chunk;
        }
    }
    return '';
};

$paused = $rowFor($grading['body'], $a12);
check('L12 the grading queue lists the paused paper', $paused !== '');
check('L12 tagged Closed while paused', strpos($paused, 'Closed while paused') !== false);
check('L12 and not Not submitted', strpos($paused, 'Not submitted') === false);

$plain = $rowFor($grading['body'], $a12b);
check('L12 an unpaused paper closed at its deadline is still Not submitted',
    strpos($plain, 'Not submitted') !== false && strpos($plain, 'Closed while paused') === false);

$grade = http('teacher', 'GET', "lecturer/gradeAttempt/$a12");
check('L12 the grade page says Closed while paused', strpos($grade['body'], 'Closed while paused.') !== false);
check('L12 and not No submission was received', strpos($grade['body'], 'No submission was received') === false);
$gradePlain = http('teacher', 'GET', "lecturer/gradeAttempt/$a12b");
check('L12 the unpaused paper\'s grade page still says No submission was received',
    strpos($gradePlain['body'], 'No submission was received') !== false && strpos($gradePlain['body'], 'Closed while paused') === false);

$result = http('ada', 'GET', "student/result/$a12");
check('L12 the student\'s result page says Closed while paused', strpos($result['body'], 'Closed while paused.') !== false);
$resultPlain = http('ada', 'GET', "student/result/$a12b");
check('L12 the unpaused one still says Closed by the system',
    strpos($resultPlain['body'], 'Closed by the system after time ran out') !== false
    && strpos($resultPlain['body'], 'Closed while paused') === false);

// ============================================================================
section('L13 the old monitor is gone');

$page = http('ada', 'GET', 'student/exam/' . fresh_attempt('ada'));
check('L13 the exam page has no logActivity in it', strpos($page['body'], 'logActivity') === false);
check('L13 nor the old monitor\'s event names', strpos($page['body'], "logEvent(") === false);

$res = http('ada', 'POST', "student/logActivity/$a1", ['csrf_token' => $token, 'event_type' => 'tab_switch']);
same('L13 POST logActivity is 404', 404, $res['status']);

// ============================================================================
section('L15 a finished attempt takes no heartbeat and no event');

$a15 = fresh_attempt('ada');
$beat($a15);
$db->prepare("UPDATE exam_attempts SET last_seen_at = NOW() - INTERVAL 2 MINUTE WHERE id = ?")->execute([$a15]);
http('ada', 'POST', "student/submitExam/$a15", ['csrf_token' => $token, 'unsaved_count' => 0]);
same('L15 the attempt is submitted', 'submitted', scalar("SELECT status FROM exam_attempts WHERE id = ?", [$a15]));

$seenBefore   = scalar("SELECT last_seen_at FROM exam_attempts WHERE id = ?", [$a15]);
$eventsBefore = (int) scalar("SELECT COUNT(*) FROM attempt_events WHERE attempt_id = ?", [$a15]);

$res = $beat($a15);
same('L15 a heartbeat on it returns 409', 409, $res['status']);
same('L15 as closed', 'closed', $res['json']['error'] ?? null);
same('L15 last_seen_at is unchanged', $seenBefore, scalar("SELECT last_seen_at FROM exam_attempts WHERE id = ?", [$a15]));

$res = $event($a15, ['type' => 'blur_blip', 'ms' => '500']);
same('L15 an event on it returns 409', 409, $res['status']);
same('L15 as closed', 'closed', $res['json']['error'] ?? null);
same('L15 and no event row was written, not even a heartbeat gap', $eventsBefore,
    (int) scalar("SELECT COUNT(*) FROM attempt_events WHERE attempt_id = ?", [$a15]));

// ============================================================================
section('L13 nothing wrote the old activity log during this run');

same('L13 activity_logs has exactly as many rows as when the run began', $activityLogsAtStart,
    (int) $db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn());

// ---- L14 Diagnostics ------------------------------------------------------

section('L14 Diagnostics');
$diags = test_diagnostics();
check('L14 no notices or deprecations were raised', $diags === [],
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
