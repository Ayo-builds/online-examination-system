<?php
/**
 * Failure mode 4: attempts whose candidate never came back.
 *
 *   php tests/sweep_test.php
 *
 * Runs against the test database only - bootstrap.php refuses anything else -
 * and drives the REAL application over HTTP through PHP's built-in server. The
 * sweep has no endpoint of its own: it runs as a side effect of staff page
 * loads, so the only honest test is to load those pages and look at the rows.
 *
 * Each actor (lecturer, admin, three students) gets its own cookie jar, so they
 * hold separate sessions exactly as separate machines on the LAN would.
 *
 * Every deadline here is written with MySQL's clock (DATE_SUB(NOW(), ...)),
 * because that is the clock the sweep compares against and the one start()
 * used to set deadline_at. PHP's clock plays no part in what is measured.
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

/** Nested arrays flattened to 'path.to.field' => value. */
function flatten(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : "$prefix.$k";
        if (is_array($v)) {
            $out += flatten($v, $key);
        } else {
            $out[$key] = $v;
        }
    }
    return $out;
}

/** Pass when $after equals $before field for field; on failure, name the fields. */
function unchanged(string $what, array $before, array $after): void
{
    $b = flatten($before);
    $a = flatten($after);

    $changed = [];
    foreach (array_unique(array_merge(array_keys($b), array_keys($a))) as $k) {
        $bv = array_key_exists($k, $b) ? var_export($b[$k], true) : '(absent)';
        $av = array_key_exists($k, $a) ? var_export($a[$k], true) : '(absent)';
        if ($bv !== $av) {
            $changed[] = "$k: $bv -> $av";
        }
    }

    check($what, $changed === [], implode('; ', array_slice($changed, 0, 6)));
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

// ---- The app, served for real ---------------------------------------------

$host = '127.0.0.1';
$port = 8098;
$base = "http://$host:$port/";

// Refuses to run if anything already holds the port, then starts its own
// server and stops it on every way out. See test_start_server().
test_start_server($host, $port);

test_reset_database();

// ---- A cookie-carrying HTTP client, one jar per actor ---------------------

$jars = [];
register_shutdown_function(static function () use (&$jars): void {
    foreach ($jars as $jar) {
        if (is_file($jar)) unlink($jar);
    }
});

/**
 * @return array{status:int, body:string}
 */
function http(string $actor, string $method, string $path, array $post = []): array
{
    global $base, $jars;

    $jars[$actor] = $jars[$actor] ?? tempnam(sys_get_temp_dir(), 'exam_sweep_');

    $ch = curl_init($base . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jars[$actor],
        CURLOPT_COOKIEFILE     => $jars[$actor],
        CURLOPT_TIMEOUT        => 10,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => $body];
}

/** Sign an actor in and return the CSRF token their session holds. */
function sign_in(string $actor, string $identifier, string $password): string
{
    $login = http($actor, 'GET', 'auth/login');
    preg_match('/name="csrf_token" value="([^"]+)"/', $login['body'], $m);
    $token = $m[1] ?? '';

    $res = http($actor, 'POST', 'auth/authenticate', [
        'csrf_token' => $token,
        'identifier' => $identifier,
        'password'   => $password,
    ]);
    check("$actor signed in", $res['status'] === 302 && $token !== '', 'status ' . $res['status']);

    return $token;
}

// ---- Fixture --------------------------------------------------------------

$db = Database::getInstance();

$db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?, 'lecturer')")
   ->execute(['Sweep Lecturer', 'sweep-lect@exam.local', Password::hash('lecturer-pass-123')]);
$lecturerId = (int) $db->lastInsertId();

$db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?, 'admin')")
   ->execute(['Sweep Admin', 'sweep-admin@exam.local', Password::hash('admin-pass-123')]);

$classId = (int) $db->query("SELECT id FROM classes ORDER BY id LIMIT 1")->fetchColumn();

$db->prepare("INSERT INTO courses (course_code, title, lecturer_id) VALUES (?,?,?)")
   ->execute(['SWP101', 'Sweep Course', $lecturerId]);
$courseId = (int) $db->lastInsertId();

// One student per scenario: an exam allows one attempt per student. Admission
// numbers follow this order: ADM/SWEEP/1 is 'abandoned', /7 is 'browser'.
$studentPass = 'student-pass-123';
$students = [];
foreach (['abandoned', 'four', 'live', 'submitter', 'returner', 'six', 'browser'] as $i => $who) {
    $db->prepare(
        "INSERT INTO users (full_name, admission_no, password_hash, role, class_id, status)
         VALUES (?,?,?, 'student', ?, 'active')"
    )->execute(['Student ' . ucfirst($who), 'ADM/SWEEP/' . ($i + 1), Password::hash($studentPass), $classId]);
    $students[$who] = (int) $db->lastInsertId();

    $db->prepare("INSERT INTO enrollments (student_id, course_id) VALUES (?,?)")
       ->execute([$students[$who], $courseId]);
}

$db->prepare(
    "INSERT INTO exams
        (course_id, title, instructions, duration_minutes, questions_per_attempt,
         shuffle_options, window_start, window_end, pass_mark, status)
     VALUES (?,?,?,?,?,1, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 6 HOUR), 40, 'published')"
)->execute([$courseId, 'Sweep Paper', 'Answer everything.', 60, 2]);
$examId = (int) $db->lastInsertId();

$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'mcq', ?, 10, ?)")
   ->execute([$courseId, 'Which is the capital?', $lecturerId]);
$mcqId = (int) $db->lastInsertId();

$db->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?,?,1)")
   ->execute([$mcqId, 'Correct one']);
$correctOptionId = (int) $db->lastInsertId();

$db->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?,?,0)")
   ->execute([$mcqId, 'Wrong one']);

$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'essay', ?, 10, ?)")
   ->execute([$courseId, 'Explain your reasoning.', $lecturerId]);
$essayId = (int) $db->lastInsertId();

foreach ([$mcqId, $essayId] as $qid) {
    $db->prepare("INSERT INTO exam_question_pool (exam_id, question_id) VALUES (?,?)")
       ->execute([$examId, $qid]);
}

$attemptModel = new Attempt();
$exam = (new Exam())->find($examId);

$attempts = [];
foreach ($students as $who => $sid) {
    $attempts[$who] = $attemptModel->start($examId, $sid, $exam);
}

function set_deadline(int $attemptId, string $sqlExpr): void
{
    Database::getInstance()
        ->prepare("UPDATE exam_attempts SET deadline_at = $sqlExpr WHERE id = ?")
        ->execute([$attemptId]);
}

function attempt_row(int $attemptId): array
{
    $stmt = Database::getInstance()->prepare("SELECT * FROM exam_attempts WHERE id = ?");
    $stmt->execute([$attemptId]);
    return $stmt->fetch();
}

/** Every column of one attempt and of each of its answers. */
function attempt_snapshot(int $attemptId): array
{
    $stmt = Database::getInstance()->prepare(
        "SELECT * FROM attempt_answers WHERE attempt_id = ? ORDER BY question_id"
    );
    $stmt->execute([$attemptId]);
    return ['attempt' => attempt_row($attemptId), 'answers' => $stmt->fetchAll()];
}

/** Every column of every attempt and every answer in the database. */
function everything_snapshot(): array
{
    $db = Database::getInstance();
    return [
        'attempts' => $db->query("SELECT * FROM exam_attempts ORDER BY id")->fetchAll(),
        'answers'  => $db->query("SELECT * FROM attempt_answers ORDER BY attempt_id, question_id")->fetchAll(),
    ];
}

// The abandoned candidate answered both questions, then vanished well past
// the grace period.
$attemptModel->saveAnswer($attempts['abandoned'], $mcqId, $correctOptionId, null);
$attemptModel->saveAnswer($attempts['abandoned'], $essayId, null, 'Half an answer, then the power went.');
set_deadline($attempts['abandoned'], 'DATE_SUB(NOW(), INTERVAL 10 MINUTE)');

// The boundary. The sweep closes an attempt only once it is MORE than the
// grace past its deadline: four minutes past is still the student's own time
// to submit late, six is not. Both from MySQL's clock, as the sweep measures.
// Nothing between here and the first sweep takes anywhere near a minute, so
// 'four' is still inside the grace when the lecturer's page load runs.
same('the grace period is five minutes', 5, Attempt::SWEEP_GRACE_MINUTES);
set_deadline($attempts['four'], 'DATE_SUB(NOW(), INTERVAL 4 MINUTE)');
set_deadline($attempts['six'],  'DATE_SUB(NOW(), INTERVAL 6 MINUTE)');

// 'live' keeps the hour start() gave it.

printf("fixture: exam %d, attempts %s\n", $examId, json_encode($attempts));

// ---- Student-side paths ---------------------------------------------------

section('Student-side paths');

$token = sign_in('submitter', 'ADM/SWEEP/4', $studentPass);
$res = http('submitter', 'POST', 'student/submitExam/' . $attempts['submitter'], [
    'csrf_token'    => $token,
    'unsaved_count' => 0,
]);
same('the submitter\'s submit went through', 302, $res['status']);

$row = attempt_row($attempts['submitter']);
same('it is submitted', 'submitted', $row['status']);
same('with no system-close marker', null, $row['closed_by_system_at']);

// A submit from the student's own browser that lands after the deadline: the
// timer fired late, or a network drop delayed the POST. It is auto_submitted
// but NOT closed by the system - a browser was there and reported its count.
$token = sign_in('browser', 'ADM/SWEEP/7', $studentPass);
set_deadline($attempts['browser'], 'DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
$res = http('browser', 'POST', 'student/submitExam/' . $attempts['browser'], [
    'csrf_token'    => $token,
    'unsaved_count' => 1,
]);
same('the late browser submit went through', 302, $res['status']);

$row = attempt_row($attempts['browser']);
same('it is auto-submitted', 'auto_submitted', $row['status']);
same('with no system-close marker, because a browser submitted it', null, $row['closed_by_system_at']);
same('and the count that browser reported', 1, (int) $row['unsaved_at_submit']);

// The candidate who comes back after the deadline: nobody submitted this
// paper either, and it is marked the same way the sweep marks one.
sign_in('returner', 'ADM/SWEEP/5', $studentPass);
set_deadline($attempts['returner'], 'DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
$res = http('returner', 'GET', 'student/exam/' . $attempts['returner']);
same('reopening the paper late redirects away from it', 302, $res['status']);

$row = attempt_row($attempts['returner']);
same('the late returner is auto-submitted', 'auto_submitted', $row['status']);
check('and marked as closed by the system', $row['closed_by_system_at'] !== null);
same('with submitted_at at the deadline, when the paper actually froze',
    $row['deadline_at'], $row['submitted_at']);

// Student pages close only their own attempt. Nothing else moved.
same('student pages did not sweep the abandoned attempt',
    'in_progress', attempt_row($attempts['abandoned'])['status']);

// Move every finished paper well past the grace, so its deadline alone would
// make it eligible. Only the status filter now keeps the sweep off these
// rows, which is what the next section needs to show.
$finished = [
    'submitter' => 'a submitted row',
    'browser'   => 'a row the browser auto-submitted',
    'returner'  => 'a row the system already closed',
];
$before = [];
foreach ($finished as $who => $label) {
    set_deadline($attempts[$who], 'DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    $before[$who] = attempt_snapshot($attempts[$who]);
}

// ---- A lecturer page load sweeps ------------------------------------------

section('A lecturer page load sweeps');

sign_in('lecturer', 'sweep-lect@exam.local', 'lecturer-pass-123');
$grading = http('lecturer', 'GET', 'lecturer/grading');
same('the grading queue loads', 200, $grading['status']);

$row = attempt_row($attempts['abandoned']);
same('the abandoned attempt is closed', 'auto_submitted', $row['status']);
check('and marked as closed by the system', $row['closed_by_system_at'] !== null);
same('submitted_at is the deadline, not the sweep time', $row['deadline_at'], $row['submitted_at']);
check('the real close time is after the deadline',
    strtotime((string) $row['closed_by_system_at']) > strtotime($row['deadline_at']));
same('the unsaved count is untouched: no browser reported one', 0, (int) $row['unsaved_at_submit']);
check('it was graded on what reached the server',
    (float) $row['total_score'] === 10.0, 'total_score ' . var_export($row['total_score'], true));
same('with its essay waiting for the lecturer', 'partial', $row['grading_status']);

$stmt = $db->prepare("SELECT essay_text, awarded_marks, graded_at FROM attempt_answers WHERE attempt_id = ? AND question_id = ?");
$stmt->execute([$attempts['abandoned'], $essayId]);
$essay = $stmt->fetch();
same('the saved essay text survived the sweep', 'Half an answer, then the power went.', $essay['essay_text']);
same('and is ungraded', null, $essay['awarded_marks']);

$row = attempt_row($attempts['six']);
same('six minutes past the deadline is closed', 'auto_submitted', $row['status']);
check('and marked as closed by the system', $row['closed_by_system_at'] !== null);
same('four minutes past the deadline is left alone',
    'in_progress', attempt_row($attempts['four'])['status']);
same('a live attempt is left alone', 'in_progress', attempt_row($attempts['live'])['status']);

foreach ($finished as $who => $label) {
    unchanged("$label, past its deadline, is untouched",
        $before[$who], attempt_snapshot($attempts[$who]));
}

check('the abandoned candidate now appears in the grading queue',
    strpos($grading['body'], 'Student Abandoned') !== false);
same('and the three papers nobody submitted carry the tag',
    3, substr_count($grading['body'], '>Not submitted<'));

// ---- What the lecturer sees on the paper ----------------------------------

section('What the lecturer sees on the paper');

$page = http('lecturer', 'GET', 'lecturer/gradeAttempt/' . $attempts['abandoned']);
same('the swept paper opens', 200, $page['status']);
check('with the no-submission banner', strpos($page['body'], 'No submission was received') !== false);
check('and without the unsaved-answers banner', strpos($page['body'], 'still unsaved') === false);

$page2 = http('lecturer', 'GET', 'lecturer/gradeAttempt/' . $attempts['submitter']);
same('a submitted paper opens', 200, $page2['status']);
check('without the no-submission banner', strpos($page2['body'], 'No submission was received') === false);

// ---- A second close must not regrade --------------------------------------

section('A second close must not regrade');

preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $m);
$res = http('lecturer', 'POST', 'lecturer/saveEssayGrade/' . $attempts['abandoned'], [
    'csrf_token'  => $m[1] ?? '',
    'question_id' => $essayId,
    'marks'       => 7,
]);
same('the lecturer grades the essay', 302, $res['status']);

check('the total now includes the essay',
    (float) attempt_row($attempts['abandoned'])['total_score'] === 17.0,
    'total_score ' . var_export(attempt_row($attempts['abandoned'])['total_score'], true));

// Pin the timestamps a regrade or a re-close would rewrite to a moment nothing
// in this run can produce. A rewrite then shows even when it happens inside
// the same second as the grading above.
$pinned = '2026-01-01 08:00:00';
$db->prepare("UPDATE exam_attempts SET closed_by_system_at = ? WHERE id = ?")
   ->execute([$pinned, $attempts['abandoned']]);
$db->prepare("UPDATE attempt_answers SET graded_at = ? WHERE attempt_id = ?")
   ->execute([$pinned, $attempts['abandoned']]);

$before = attempt_snapshot($attempts['abandoned']);

// What a sweep or a late submit arriving second would do.
same('closing it again reports that nothing was closed', false, (new Attempt())->autoSubmit($attempts['abandoned']));
same('a late submit arriving second is a no-op too', null,
    (new Attempt())->submitAndGrade($attempts['abandoned'], 'auto_submitted', 3));

$after = attempt_snapshot($attempts['abandoned']);
$stmt->execute([$attempts['abandoned'], $essayId]);
$essay = $stmt->fetch();
check('the essay mark survived', (float) $essay['awarded_marks'] === 7.0,
    'awarded_marks ' . var_export($essay['awarded_marks'], true));
same('and so did when it was graded', $pinned, $essay['graded_at']);
same('the total is unchanged', $before['attempt']['total_score'], $after['attempt']['total_score']);
same('grading is still complete', 'complete', $after['attempt']['grading_status']);
same('closed_by_system_at is unchanged', $pinned, $after['attempt']['closed_by_system_at']);
same('submitted_at is unchanged', $before['attempt']['submitted_at'], $after['attempt']['submitted_at']);
same('the unsaved count was not overwritten', 0, (int) $after['attempt']['unsaved_at_submit']);
unchanged('nothing else on the paper changed either', $before, $after);

// ---- What the student sees on their result page ---------------------------

section('What the student sees on their result page');

// The sidebar grade box, whatever it holds.
function grade_box(string $html): ?string
{
    return preg_match('/class="review-grade[^"]*">\s*([^<]*?)\s*</', $html, $m) ? $m[1] : null;
}

// A paper the system closed, its essay still unmarked: 'returner'.
$page = http('returner', 'GET', 'student/result/' . $attempts['returner']);
same('a system-closed paper\'s result opens', 200, $page['status']);
check('its status says the system closed it after time ran out',
    strpos($page['body'], 'Closed by the system after time ran out') !== false);
check('that no submission was received', strpos($page['body'], 'No submission') !== false
    && strpos($page['body'], 'was received; the answers saved before then were counted') !== false);
check('and not that it was submitted automatically',
    strpos($page['body'], 'submitted automatically when time expired') === false);
same('with an essay unmarked, the sidebar grade is Pending', 'Pending', grade_box($page['body']));

// A browser that did submit at the deadline keeps the old wording: 'browser'.
$page = http('browser', 'GET', 'student/result/' . $attempts['browser']);
same('a paper the browser submitted late opens', 200, $page['status']);
check('its status still says it was submitted automatically',
    strpos($page['body'], 'Finished, submitted automatically when time expired') !== false);
check('and not that the system closed it',
    strpos($page['body'], 'Closed by the system') === false);
same('its essay is unmarked too, so its grade is Pending', 'Pending', grade_box($page['body']));

// Marking complete - the essay was graded above, 17 of 20 - so the
// percentage appears: 'abandoned'.
sign_in('abandoned', 'ADM/SWEEP/1', $studentPass);
$page = http('abandoned', 'GET', 'student/result/' . $attempts['abandoned']);
same('a fully marked paper\'s result opens', 200, $page['status']);
same('once marking is complete, the sidebar grade is the percentage', '85%', grade_box($page['body']));

// ---- A second sweep changes nothing ---------------------------------------

section('A second sweep changes nothing');

// Every attempt is now finished, still live, or ('four') inside its grace, so
// a sweep has nothing to do - and must do nothing, to any row, including the
// papers it closed itself and the essay graded since.
//
// Pin every timestamp a re-close or a regrade would rewrite, as above. Without
// this, a second pass that rewrites rows within the same second as the first
// leaves them byte-identical and the comparison below cannot see it - which
// is exactly how this check passed against a sweep with no status filter
// before the pins were added.
$db->prepare("UPDATE exam_attempts SET closed_by_system_at = ? WHERE closed_by_system_at IS NOT NULL")
   ->execute([$pinned]);
$db->prepare("UPDATE attempt_answers SET graded_at = ? WHERE graded_at IS NOT NULL")
   ->execute([$pinned]);

$before = everything_snapshot();

$res = http('lecturer', 'GET', 'lecturer/grading');
same('a second lecturer page load succeeds', 200, $res['status']);
same('and a direct second sweep closes nothing', 0, (new Attempt())->sweepAbandoned());

unchanged('no row in exam_attempts or attempt_answers changed', $before, everything_snapshot());

// ---- Admin analytics sweeps too -------------------------------------------

section('Admin analytics sweeps too');

set_deadline($attempts['four'], 'DATE_SUB(NOW(), INTERVAL 10 MINUTE)');

sign_in('admin', 'sweep-admin@exam.local', 'admin-pass-123');
$res = http('admin', 'GET', 'admin/analytics');
same('admin analytics loads', 200, $res['status']);

$row = attempt_row($attempts['four']);
same('the now-abandoned attempt was closed by it', 'auto_submitted', $row['status']);
check('and marked', $row['closed_by_system_at'] !== null);
same('the live attempt is still live', 'in_progress', attempt_row($attempts['live'])['status']);
same('so analytics counts exactly one live attempt', 1,
    (int) (new Analytics())->systemCounts()['live_attempts']);

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
