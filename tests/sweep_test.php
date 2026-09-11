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
 * Each actor (lecturer, admin, two students) gets its own cookie jar, so they
 * hold separate sessions exactly as separate machines on the LAN would.
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

// ---- The app, served for real ---------------------------------------------

$host = '127.0.0.1';
$port = 8098;
$base = "http://$host:$port/";

test_reset_database();

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$server = proc_open(
    sprintf(
        '%s -S %s:%d -t public %s',
        escapeshellarg(PHP_BINARY),
        $host,
        $port,
        escapeshellarg(APP_ROOT . '/tests/router.php')
    ),
    $descriptors,
    $pipes,
    APP_ROOT,
    // The inherited environment PLUS the override, never the override alone.
    // An array here replaces the child's whole environment, and on Windows a
    // child without SystemRoot cannot initialise Winsock: the server exits
    // with "Failed to listen ... (reason: ?)".
    array_merge(getenv(), ['EXAM_CONFIG' => 'config/config.test.php'])
);

if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the built-in server.\n");
    exit(1);
}

register_shutdown_function(static function () use ($server, $pipes): void {
    foreach ($pipes as $p) {
        if (is_resource($p)) fclose($p);
    }
    proc_terminate($server);
    proc_close($server);
});

$up = false;
for ($i = 0; $i < 100; $i++) {
    $sock = @fsockopen($host, $port, $errno, $errstr, 0.2);
    if ($sock) { fclose($sock); $up = true; break; }
    usleep(100000);
}

if (!$up) {
    // Say why, rather than leaving a bare timeout to guess at.
    $status = proc_get_status($server);
    stream_set_blocking($pipes[2], false);
    fwrite(STDERR, "Server did not come up on $host:$port.\n"
        . 'running: ' . var_export($status['running'], true)
        . ', exitcode: ' . var_export($status['exitcode'], true) . "\n"
        . "stderr:\n" . (string) stream_get_contents($pipes[2]) . "\n");
    exit(1);
}

// The bootstrap's handler records warnings even under @, so every probe that
// ran before the server was listening left one behind. Those failures were
// the point of probing; drop them so Diagnostics judges only what follows.
test_diagnostics();

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

// One student per scenario: an exam allows one attempt per student.
$studentPass = 'student-pass-123';
$students = [];
foreach (['abandoned', 'grace', 'live', 'submitter', 'returner'] as $i => $who) {
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

// The abandoned candidate answered both questions, then vanished well past
// the grace period.
$attemptModel->saveAnswer($attempts['abandoned'], $mcqId, $correctOptionId, null);
$attemptModel->saveAnswer($attempts['abandoned'], $essayId, null, 'Half an answer, then the power went.');
set_deadline($attempts['abandoned'], 'DATE_SUB(NOW(), INTERVAL 10 MINUTE)');

// Past the deadline, but still inside the grace window: their own late submit
// may yet arrive, and it must be allowed to.
check('the grace window is longer than the in-grace fixture', Attempt::SWEEP_GRACE_MINUTES > 2);
set_deadline($attempts['grace'], 'DATE_SUB(NOW(), INTERVAL 2 MINUTE)');

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

$stmt = $db->prepare("SELECT essay_text, awarded_marks FROM attempt_answers WHERE attempt_id = ? AND question_id = ?");
$stmt->execute([$attempts['abandoned'], $essayId]);
$essay = $stmt->fetch();
same('the saved essay text survived the sweep', 'Half an answer, then the power went.', $essay['essay_text']);
same('and is ungraded', null, $essay['awarded_marks']);

same('an attempt inside the grace window is left alone',
    'in_progress', attempt_row($attempts['grace'])['status']);
same('a live attempt is left alone', 'in_progress', attempt_row($attempts['live'])['status']);
same('a student-submitted attempt is not re-marked',
    null, attempt_row($attempts['submitter'])['closed_by_system_at']);

check('the abandoned candidate now appears in the grading queue',
    strpos($grading['body'], 'Student Abandoned') !== false);
same('and both papers nobody submitted carry the tag', 2, substr_count($grading['body'], '>Not submitted<'));

same('a second sweep finds nothing to do', 0, (new Attempt())->sweepAbandoned());

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

$before = attempt_row($attempts['abandoned']);
check('the total now includes the essay', (float) $before['total_score'] === 17.0,
    'total_score ' . var_export($before['total_score'], true));

// What a sweep or a late submit arriving second would do.
same('closing it again reports that nothing was closed', false, (new Attempt())->autoSubmit($attempts['abandoned']));
same('a late submit arriving second is a no-op too', null,
    (new Attempt())->submitAndGrade($attempts['abandoned'], 'auto_submitted', 3));

$after = attempt_row($attempts['abandoned']);
$stmt->execute([$attempts['abandoned'], $essayId]);
check('the essay mark survived', (float) $stmt->fetch()['awarded_marks'] === 7.0);
same('the total is unchanged', $before['total_score'], $after['total_score']);
same('grading is still complete', 'complete', $after['grading_status']);
same('submitted_at is unchanged', $before['submitted_at'], $after['submitted_at']);
same('the unsaved count was not overwritten', 0, (int) $after['unsaved_at_submit']);

// ---- Admin analytics sweeps too -------------------------------------------

section('Admin analytics sweeps too');

set_deadline($attempts['grace'], 'DATE_SUB(NOW(), INTERVAL 10 MINUTE)');

sign_in('admin', 'sweep-admin@exam.local', 'admin-pass-123');
$res = http('admin', 'GET', 'admin/analytics');
same('admin analytics loads', 200, $res['status']);

$row = attempt_row($attempts['grace']);
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
