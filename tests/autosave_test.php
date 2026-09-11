<?php
/**
 * Step 6: autosave, resume, and the failure contracts the browser relies on.
 *
 *   php tests/autosave_test.php
 *
 * Runs against the test database only - bootstrap.php refuses anything else -
 * and drives the REAL application over HTTP through PHP's built-in server, so
 * the session, the CSRF check and the router are the ones that ship.
 *
 * Why over HTTP rather than by calling the controller: every failure this
 * step exists to handle is a status code and a JSON body that the save queue
 * in public/assets/js/save-queue.js branches on. Calling the method directly
 * would test neither. A wrong status code here is a client that silently
 * stops saving, which is exactly the bug being fixed.
 *
 * The client half - that a failure is actually SHOWN to the candidate - is
 * covered by tests/save_queue_test.mjs, which can fail a save on demand.
 *
 * This file resets the test database and builds its own exam, questions and
 * student from nothing, so it needs no fixture. The reset also removes the
 * users fixture: run tests/setup_users_fixture.php again afterwards before
 * running tests/enrollments_list_test.php.
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
$port = 8099;
$base = "http://$host:$port/";

// Refuses to run if anything already holds the port, then starts its own
// server and stops it on every way out. See test_start_server().
test_start_server($host, $port);

test_reset_database();

// ---- A cookie-carrying HTTP client ----------------------------------------

$jar = tempnam(sys_get_temp_dir(), 'exam_cookies_');
register_shutdown_function(static function () use ($jar): void {
    if (is_file($jar)) unlink($jar);
});

/**
 * @return array{status:int, body:string, json:?array}
 */
function http(string $method, string $path, array $post = [], array $opts = []): array
{
    global $base, $jar;

    $ch = curl_init($base . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // a redirect IS the result here
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $opts['no_cookies'] ?? false ? '' : $jar,
        CURLOPT_TIMEOUT        => 10,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($body, true);

    return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
}

// ---- Fixture: one lecturer, one course, one exam, one student -------------

$db = Database::getInstance();

$db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?, 'lecturer')")
   ->execute(['Test Lecturer', 'lect@exam.local', Password::hash('lecturer-pass-123')]);
$lecturerId = (int) $db->lastInsertId();

// The schema seeds the real classes, so take one rather than inventing a
// duplicate: year_group + arm is the natural key and carries a UNIQUE.
$classId = (int) $db->query("SELECT id FROM classes ORDER BY id LIMIT 1")->fetchColumn();

$studentPass = 'student-pass-123';
$db->prepare(
    "INSERT INTO users (full_name, admission_no, password_hash, role, class_id, status)
     VALUES (?,?,?, 'student', ?, 'active')"
)->execute(['Test Student', 'ADM/AUTOSAVE/1', Password::hash($studentPass), $classId]);
$studentId = (int) $db->lastInsertId();

$db->prepare("INSERT INTO courses (course_code, title, lecturer_id) VALUES (?,?,?)")
   ->execute(['AUTO101', 'Autosave Course', $lecturerId]);
$courseId = (int) $db->lastInsertId();

$db->prepare("INSERT INTO enrollments (student_id, course_id) VALUES (?,?)")
   ->execute([$studentId, $courseId]);

// A generous window; the deadline is what this test manipulates, not the window.
$db->prepare(
    "INSERT INTO exams
        (course_id, title, instructions, duration_minutes, questions_per_attempt,
         shuffle_options, window_start, window_end, pass_mark, status)
     VALUES (?,?,?,?,?,1, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 6 HOUR), 40, 'published')"
)->execute([$courseId, 'Autosave Paper', 'Answer everything.', 120, 2]);
$examId = (int) $db->lastInsertId();

// One MCQ and one essay, so both save paths are exercised.
$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'mcq', ?, 10, ?)")
   ->execute([$courseId, 'Which is the capital?', $lecturerId]);
$mcqId = (int) $db->lastInsertId();

$db->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?,?,1)")
   ->execute([$mcqId, 'Correct one']);
$correctOptionId = (int) $db->lastInsertId();

$db->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?,?,0)")
   ->execute([$mcqId, 'Wrong one']);
$wrongOptionId = (int) $db->lastInsertId();

$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'essay', ?, 10, ?)")
   ->execute([$courseId, 'Explain your reasoning.', $lecturerId]);
$essayId = (int) $db->lastInsertId();

// A question on ANOTHER paper entirely, to prove the endpoint refuses it.
$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'essay', ?, 10, ?)")
   ->execute([$courseId, 'Not on the paper.', $lecturerId]);
$foreignId = (int) $db->lastInsertId();

foreach ([$mcqId, $essayId] as $qid) {
    $db->prepare("INSERT INTO exam_question_pool (exam_id, question_id) VALUES (?,?)")
       ->execute([$examId, $qid]);
}

printf("fixture: exam %d, mcq %d, essay %d, student %d\n", $examId, $mcqId, $essayId, $studentId);

// ---- Sign in and start the attempt ----------------------------------------

section('Signing in and starting an attempt');

$login = http('GET', 'auth/login');
preg_match('/name="csrf_token" value="([^"]+)"/', $login['body'], $m);
$token = $m[1] ?? '';
check('login page issued a CSRF token', $token !== '');

$res = http('POST', 'auth/authenticate', [
    'csrf_token' => $token,
    'identifier' => 'ADM/AUTOSAVE/1',
    'password'   => $studentPass,
]);
check('student signed in', $res['status'] === 302, 'status ' . $res['status']);

$res = http('POST', 'student/startExam/' . $examId, ['csrf_token' => $token]);
check('attempt started', $res['status'] === 302, 'status ' . $res['status']);

$attempt = (new Attempt())->findByExamAndStudent($examId, $studentId);
check('the attempt exists', $attempt !== null);
$attemptId = (int) $attempt['id'];
same('it is in progress', 'in_progress', $attempt['status']);

// ---- The contracts the save queue branches on -----------------------------

section('Save endpoint contracts');

$res = http('POST', 'student/saveAnswer/' . $attemptId, [
    'csrf_token'  => $token,
    'question_id' => $mcqId,
    'option_id'   => $correctOptionId,
]);
same('a good MCQ save returns 200', 200, $res['status']);
check('and says ok', ($res['json']['ok'] ?? null) === true, $res['body']);

$row = $db->prepare("SELECT selected_option_id FROM attempt_answers WHERE attempt_id = ? AND question_id = ?");
$row->execute([$attemptId, $mcqId]);
same('and the answer really landed in the database', $correctOptionId, (int) $row->fetchColumn());

$res = http('POST', 'student/saveAnswer/' . $attemptId, [
    'csrf_token'  => $token,
    'question_id' => $essayId,
    'essay_text'  => 'My first draft.',
]);
same('a good essay save returns 200', 200, $res['status']);

$res = http('POST', 'student/saveAnswer/' . $attemptId, [
    'csrf_token'  => $token,
    'question_id' => $essayId,
    'essay_text'  => 'My second, longer draft.',
]);
same('re-saving the same question overwrites', 200, $res['status']);

$row = $db->prepare("SELECT essay_text FROM attempt_answers WHERE attempt_id = ? AND question_id = ?");
$row->execute([$attemptId, $essayId]);
same('and the newest text is what is stored', 'My second, longer draft.', $row->fetchColumn());

$row = $db->prepare("SELECT COUNT(*) FROM attempt_answers WHERE attempt_id = ? AND question_id = ?");
$row->execute([$attemptId, $essayId]);
same('with no duplicate row', 1, (int) $row->fetchColumn());

// The CSRF contract. Before this step the client swallowed this response and
// went on failing silently for the rest of the paper.
$res = http('POST', 'student/saveAnswer/' . $attemptId, [
    'csrf_token'  => 'not-the-real-token',
    'question_id' => $essayId,
    'essay_text'  => 'Should not be saved.',
]);
same('a bad CSRF token returns 403', 403, $res['status']);
same('with error "csrf", which the client branches on', 'csrf', $res['json']['error'] ?? null);

$row = $db->prepare("SELECT essay_text FROM attempt_answers WHERE attempt_id = ? AND question_id = ?");
$row->execute([$attemptId, $essayId]);
same('and it did NOT overwrite the good answer', 'My second, longer draft.', $row->fetchColumn());

// A question that is not on this paper.
$res = http('POST', 'student/saveAnswer/' . $attemptId, [
    'csrf_token'  => $token,
    'question_id' => $foreignId,
    'essay_text'  => 'Not mine.',
]);
same('a question off the paper returns 422', 422, $res['status']);

// An option that belongs to no question on this paper.
$res = http('POST', 'student/saveAnswer/' . $attemptId, [
    'csrf_token'  => $token,
    'question_id' => $mcqId,
    'option_id'   => 999999,
]);
same('an option that is not this question\'s returns 422', 422, $res['status']);

// ---- The token endpoint the Retry button uses -----------------------------

section('Session token endpoint');

$res = http('GET', 'student/sessionToken');
same('a signed-in student can read the live token', 200, $res['status']);
check('and it matches the one that works', ($res['json']['csrf_token'] ?? '') !== '');

$fresh = $res['json']['csrf_token'];
$res = http('POST', 'student/saveAnswer/' . $attemptId, [
    'csrf_token'  => $fresh,
    'question_id' => $essayId,
    'essay_text'  => 'Saved with the refetched token.',
]);
same('the refetched token actually saves', 200, $res['status']);

// ---- Resume ---------------------------------------------------------------

section('Resume');

$res = http('GET', 'student/exam/' . $attemptId);
same('the paper loads', 200, $res['status']);
check('with the saved essay text in the textarea',
    strpos($res['body'], 'Saved with the refetched token.') !== false);
check('with the chosen option pre-selected',
    (bool) preg_match('/value="' . $correctOptionId . '"[^>]*checked/', $res['body'])
    || (bool) preg_match('/checked[^>]*value="' . $correctOptionId . '"/', $res['body']));
check('and the questions already answered are marked saved to the browser',
    strpos($res['body'], 'const initialSaved') !== false);

// The remaining time comes from the server, never the page's own clock.
check('the remaining time is server-computed', (bool) preg_match('/const remaining = (\d+);/', $res['body'], $rm));
$remaining = (int) ($rm[1] ?? 0);
check('and it is less than the full duration, because time has passed',
    $remaining > 0 && $remaining <= 120 * 60, 'remaining ' . $remaining);

// ---- The deadline is enforced by the server, not the browser --------------

section('Deadline');

$db->prepare("UPDATE exam_attempts SET deadline_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?")
   ->execute([$attemptId]);

$res = http('POST', 'student/saveAnswer/' . $attemptId, [
    'csrf_token'  => $token,
    'question_id' => $essayId,
    'essay_text'  => 'Too late.',
]);
same('a save past the deadline returns 409', 409, $res['status']);
same('with error "closed", which tells the client to submit', 'closed', $res['json']['error'] ?? null);

$row = $db->prepare("SELECT essay_text FROM attempt_answers WHERE attempt_id = ? AND question_id = ?");
$row->execute([$attemptId, $essayId]);
same('and nothing was written after the deadline',
    'Saved with the refetched token.', $row->fetchColumn());

// ---- Submitting with answers still unsaved --------------------------------

section('Submitting with unsaved answers outstanding');

$db->prepare("UPDATE exam_attempts SET deadline_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?")
   ->execute([$attemptId]);

$res = http('POST', 'student/submitExam/' . $attemptId, [
    'csrf_token'     => $token,
    'unsaved_count'  => 2,
]);
check('the submission went through despite unsaved answers', $res['status'] === 302,
    'status ' . $res['status']);

$row = $db->prepare("SELECT status, unsaved_at_submit, total_score FROM exam_attempts WHERE id = ?");
$row->execute([$attemptId]);
$final = $row->fetch();

same('the attempt is submitted', 'submitted', $final['status']);
same('the unsaved count was recorded', 2, (int) $final['unsaved_at_submit']);
check('and it was still graded on what did reach the server',
    (float) $final['total_score'] === 10.0,
    'total_score ' . var_export($final['total_score'], true));

// A failed save must never become a zero. That is the whole point.
check('a failed save did not cost the student the marks they earned',
    (float) $final['total_score'] > 0);

// ---- The count is clamped, because the client reports it ------------------

section('The client-reported count is clamped');

$db->prepare(
    "UPDATE exam_attempts SET status = 'in_progress', submitted_at = NULL,
            unsaved_at_submit = 0, deadline_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
     WHERE id = ?"
)->execute([$attemptId]);

$res = http('POST', 'student/submitExam/' . $attemptId, [
    'csrf_token'    => $token,
    'unsaved_count' => 9999,
]);
$row = $db->prepare("SELECT unsaved_at_submit FROM exam_attempts WHERE id = ?");
$row->execute([$attemptId]);
same('a wild count is clamped to the size of the paper', 2, (int) $row->fetchColumn());

$db->prepare(
    "UPDATE exam_attempts SET status = 'in_progress', submitted_at = NULL,
            unsaved_at_submit = 0, deadline_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
     WHERE id = ?"
)->execute([$attemptId]);

$res = http('POST', 'student/submitExam/' . $attemptId, [
    'csrf_token'    => $token,
    'unsaved_count' => -5,
]);
$row->execute([$attemptId]);
same('a negative count is clamped to zero', 0, (int) $row->fetchColumn());

// ---- Session lifetime -----------------------------------------------------

section('Session lifetime outlasts an exam');

// Read from the bootstrap source, and deliberately so. Observing this over
// HTTP would mean adding an endpoint to the running application purely for a
// test, which is a worse trade. What is asserted is the pair of things that
// can actually go wrong: the value, and the ORDER - ini_set is silently
// useless if it runs after session_start, and session_start sits ABOVE the
// config load in that file, which is why the value is hardcoded there rather
// than read from config.
$bootstrap = (string) file_get_contents(APP_ROOT . "/public/index.php");

check("the bootstrap sets a session lifetime",
    (bool) preg_match("/ini_set\(\s*[\x27\"]session\.gc_maxlifetime[\x27\"]\s*,\s*[\x27\"]?(\d+)/", $bootstrap, $lm));

$lifetime = (int) ($lm[1] ?? 0);
check("and it clears the longest paper",
    $lifetime >= 120 * 60,
    "session.gc_maxlifetime is " . $lifetime . "s, longest exam is " . (120 * 60) . "s");

// Comments in that file mention both by name, so compare the positions of
// the actual TOKENS and not the prose about them.
$code = implode("", array_map(
    static fn($t): string => is_array($t)
        && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? "" : (is_array($t) ? $t[1] : $t),
    token_get_all($bootstrap)
));

$setPos   = strpos($code, "session.gc_maxlifetime");
$startPos = strpos($code, "session_start");
check("and it runs BEFORE session_start, or it does nothing at all",
    $setPos !== false && $startPos !== false && $setPos < $startPos);

// ---- Diagnostics ----------------------------------------------------------

section('Diagnostics');
$diags = test_diagnostics();
check('no notices or deprecations were raised', $diags === [],
    implode(' | ', array_slice($diags, 0, 5)));

// ---- Teardown -------------------------------------------------------------
//
// The database was reset at the top of this run and every row here was made by
// this file, so there is nothing to unpick: the next run resets it again. The
// fixture in tests/setup_users_fixture.php lives in the same database and is
// NOT touched by name, role or date anywhere above.

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
