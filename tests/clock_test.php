<?php
/**
 * Step 4b: every deadline and window decision on the database's clock.
 *
 *   php tests/clock_test.php
 *
 * Runs against the test database only - bootstrap.php refuses anything else -
 * and drives the REAL application over HTTP through PHP's built-in server,
 * three times over:
 *
 *   127.0.0.1:8090  config/config.test.php             PHP and the database agree
 *   127.0.0.1:8089  tests/config.clock_offset.php      PHP 12 hours BEHIND the database
 *   127.0.0.1:8088  tests/config.clock_offset_east.php PHP 13 hours AHEAD of it
 *
 * A decision made with PHP's clock goes wrong on one offset server or the
 * other: behind, it thinks time is left when there is none and accepts too
 * late; ahead, it thinks time is up when it is not and closes too early. Every
 * boundary is tested on all three.
 *
 * "3 s before" and "3 s after" are made by moving deadline_at or a window edge
 * with the database's own clock (NOW() + INTERVAL ...), never by sleeping.
 *
 * This file resets the test database. The reset also removes the users
 * fixture: run tests/setup_users_fixture.php again afterwards before running
 * tests/enrollments_list_test.php.
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

// ---- The app, served three times --------------------------------------------

$host = '127.0.0.1';
$servers = [
    'normal' => "http://$host:8090/",
    'behind' => "http://$host:8089/",
    'ahead'  => "http://$host:8088/",
];

test_start_server($host, 8090);
test_start_server($host, 8089, 'tests/config.clock_offset.php');
test_start_server($host, 8088, 'tests/config.clock_offset_east.php');
test_reset_database();

// ---- HTTP, one cookie jar per actor and server ------------------------------

$jars = [];
register_shutdown_function(static function () use (&$jars): void {
    foreach ($jars as $jar) {
        if (is_file($jar)) unlink($jar);
    }
});

/**
 * @return array{status:int, body:string, json:?array, location:string, headers:array<string,string>}
 */
function http(string $who, string $method, string $path, array $post = [], string $server = 'normal'): array
{
    global $servers, $jars;

    $jar = $jars["$who@$server"] ??= (string) tempnam(sys_get_temp_dir(), 'exam_cookies_');

    $headers = [];
    $ch = curl_init($servers[$server] . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
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

    $json = json_decode($body, true);

    return [
        'status'   => $status,
        'body'     => $body,
        'json'     => is_array($json) ? $json : null,
        'location' => $headers['location'] ?? '',
        'headers'  => $headers,
    ];
}

/** Sign someone in on a server and return their session's CSRF token. */
function sign_in(string $who, string $identifier, string $password, string $server): string
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

/** Move an attempt's deadline to $seconds from now (negative: in the past), by the database's clock. */
function deadline_in(int $attemptId, int $seconds): void
{
    Database::getInstance()->prepare(
        "UPDATE exam_attempts SET deadline_at = NOW() + INTERVAL ? SECOND WHERE id = ?"
    )->execute([$seconds, $attemptId]);
}

// ---- Fixture ---------------------------------------------------------------

$teacherPass = 'teacher-pass-123';
$db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?, 'lecturer')")
   ->execute(['Clock Teacher', 'clock@exam.local', Password::hash($teacherPass)]);
$teacherId = (int) $db->lastInsertId();

$classId = (int) $db->query("SELECT id FROM classes ORDER BY id LIMIT 1")->fetchColumn();
$pass = 'student-pass-123';
$students = [];
foreach (['ada' => 'ADM/CLOCK/1', 'bola' => 'ADM/CLOCK/2'] as $who => $adm) {
    $db->prepare("INSERT INTO users (full_name, admission_no, password_hash, role, class_id) VALUES (?,?,?, 'student', ?)")
       ->execute([ucfirst($who), $adm, Password::hash($pass), $classId]);
    $students[$who] = (int) $db->lastInsertId();
}

$db->prepare("INSERT INTO courses (course_code, title, lecturer_id) VALUES (?,?,?)")
   ->execute(['CLOCK101', 'Clock Subject', $teacherId]);
$courseId = (int) $db->lastInsertId();
foreach ($students as $id) {
    $db->prepare("INSERT INTO enrollments (student_id, course_id) VALUES (?,?)")->execute([$id, $courseId]);
}

$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'essay', ?, 5, ?)")
   ->execute([$courseId, 'Describe a clock. Clock paper', $teacherId]);
$essayId = (int) $db->lastInsertId();

/** A published exam whose window runs between two SQL times. Its title is unique. */
function fresh_exam(string $opens = 'NOW() - INTERVAL 1 HOUR', string $closes = 'NOW() + INTERVAL 6 HOUR'): int
{
    global $db, $courseId, $essayId;

    static $n = 0;
    $n++;
    $db->prepare(
        "INSERT INTO exams (course_id, title, duration_minutes, questions_per_attempt, shuffle_options,
                            window_start, window_end, status)
         VALUES (?, ?, 60, 1, 0, $opens, $closes, 'published')"
    )->execute([$courseId, "Clock exam [$n]"]);   // bracketed, so [1] never matches inside [10]
    $examId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO exam_question_pool (exam_id, question_id) VALUES (?,?)")->execute([$examId, $essayId]);
    return $examId;
}

/** A new attempt, on a new exam, for $who. */
function fresh_attempt(string $who = 'ada', ?int $examId = null): int
{
    global $students;

    $examId ??= fresh_exam();
    return (new Attempt())->start($examId, $students[$who], (new Exam())->find($examId));
}

$tokens = [];
$teacherTokens = [];
foreach (array_keys($servers) as $server) {
    $tokens[$server]        = sign_in('ada', 'ADM/CLOCK/1', $pass, $server);
    $teacherTokens[$server] = sign_in('teacher', 'clock@exam.local', $teacherPass, $server);
}

$heartbeat = static fn(int $id, string $server): array
    => http('ada', 'POST', "student/heartbeat/$id", ['csrf_token' => $GLOBALS['tokens'][$server]], $server);

// ============================================================================
section('C1 control: each server\'s PHP clock is where it should be');

$expected = ['normal' => [0, 2], 'behind' => [-12 * 3600, 120], 'ahead' => [13 * 3600, 120]];
foreach ($expected as $server => [$offset, $slack]) {
    $res    = http('anon', 'GET', 'auth/login', [], $server);
    $phpNow = $res['headers']['x-test-php-now'] ?? null;
    $dbNow  = (string) scalar("SELECT NOW()");
    $apart  = $phpNow === null ? null : strtotime($phpNow) - strtotime($dbNow);
    check("C1 $server: PHP's clock is " . ($offset / 3600) . " h from the database's",
        $apart !== null && abs($apart - $offset) <= $slack,
        'PHP ' . var_export($phpNow, true) . ", database $dbNow");
}

foreach (array_keys($servers) as $server) {
    // ========================================================================
    section("C2 C3 $server: the page and the paper start from the heartbeat's time left");

    $a = fresh_attempt();
    $page = http('ada', 'GET', "student/exam/$a", [], $server);
    preg_match('/const remaining = (-?\d+);/', $page['body'], $m);
    $pageLeft = isset($m[1]) ? (int) $m[1] : null;
    $paper = http('ada', 'POST', "student/paper/$a", ['csrf_token' => $tokens[$server]], $server);
    $paperLeft = $paper['json']['remaining'] ?? null;
    $beatLeft = $heartbeat($a, $server)['json']['remaining'] ?? null;

    check("C2 $server: the page countdown and the heartbeat agree within 2 s",
        $pageLeft !== null && $beatLeft !== null && abs($pageLeft - $beatLeft) <= 2,
        "page $pageLeft, heartbeat $beatLeft");
    check("C3 $server: the paper's time left and the heartbeat's agree within 2 s",
        $paperLeft !== null && $beatLeft !== null && abs($paperLeft - $beatLeft) <= 2,
        "paper $paperLeft, heartbeat $beatLeft");
    check("C2 $server: and it is the hour this exam lasts", $beatLeft !== null && abs($beatLeft - 3600) <= 5,
        "heartbeat $beatLeft");

    // ========================================================================
    section("C4 $server: a save 3 s before the deadline is kept, one 3 s after is refused");

    $a = fresh_attempt();
    $save = static fn(string $text): array => http('ada', 'POST', "student/saveAnswer/$a",
        ['csrf_token' => $tokens[$server], 'question_id' => $essayId, 'essay_text' => $text], $server);
    $stored = static fn(): ?string => scalar("SELECT essay_text FROM attempt_answers WHERE attempt_id = ? AND question_id = ?",
        [$a, $essayId]) ?: null;

    deadline_in($a, 3);
    $res = $save('Saved 3 s before.');
    same("C4 $server: 3 s before: 200", 200, $res['status']);
    same("C4 $server: and stored", 'Saved 3 s before.', $stored());
    check("C4 $server: saved_at is the database's time of day", ($res['json']['saved_at'] ?? '') !== ''
        && abs(strtotime((string) $res['json']['saved_at']) - strtotime(substr((string) scalar("SELECT NOW()"), 11))) <= 5,
        'saved_at ' . var_export($res['json']['saved_at'] ?? null, true));

    deadline_in($a, -3);
    $res = $save('Sent 3 s after.');
    same("C4 $server: 3 s after: 409", 409, $res['status']);
    same("C4 $server: as closed", 'closed', $res['json']['error'] ?? null);
    same("C4 $server: and the earlier answer stands", 'Saved 3 s before.', $stored());

    // ========================================================================
    section("C5 $server: the exam page 3 s before and 3 s after");

    $a = fresh_attempt();
    deadline_in($a, 3);
    $res = http('ada', 'GET', "student/exam/$a", [], $server);
    same("C5 $server: 3 s before: the page is served", 200, $res['status']);
    same("C5 $server: and the attempt is still open", 'in_progress', scalar("SELECT status FROM exam_attempts WHERE id = ?", [$a]));

    deadline_in($a, -3);
    $res = http('ada', 'GET', "student/exam/$a", [], $server);
    same("C5 $server: 3 s after: sent away", 302, $res['status']);
    $row = one("SELECT status, closed_by_system_at FROM exam_attempts WHERE id = ?", [$a]);
    same("C5 $server: and the paper is closed by the system", 'auto_submitted', $row['status'] ?? null);
    check("C5 $server: with closed_by_system_at set", ($row['closed_by_system_at'] ?? null) !== null);

    // ========================================================================
    section("C6 $server: the paper 3 s before and 3 s after");

    $a = fresh_attempt();
    deadline_in($a, 3);
    $res = http('ada', 'POST', "student/paper/$a", ['csrf_token' => $tokens[$server]], $server);
    same("C6 $server: 3 s before: the paper is served", true, $res['json']['ok'] ?? null);

    deadline_in($a, -3);
    $res = http('ada', 'POST', "student/paper/$a", ['csrf_token' => $tokens[$server]], $server);
    same("C6 $server: 3 s after: 409", 409, $res['status']);
    same("C6 $server: as closed", 'closed', $res['json']['error'] ?? null);
    same("C6 $server: and the paper is closed", 'auto_submitted', scalar("SELECT status FROM exam_attempts WHERE id = ?", [$a]));

    // ========================================================================
    section("C7 $server: submitted in time or not");

    $early = fresh_attempt();
    deadline_in($early, 3);
    http('ada', 'POST', "student/submitExam/$early", ['csrf_token' => $tokens[$server], 'unsaved_count' => 0], $server);
    same("C7 $server: submitted 3 s before the deadline: submitted", 'submitted',
        scalar("SELECT status FROM exam_attempts WHERE id = ?", [$early]));

    $late = fresh_attempt();
    deadline_in($late, -3);
    http('ada', 'POST', "student/submitExam/$late", ['csrf_token' => $tokens[$server], 'unsaved_count' => 0], $server);
    same("C7 $server: submitted 3 s after: auto_submitted", 'auto_submitted',
        scalar("SELECT status FROM exam_attempts WHERE id = ?", [$late]));

    // ========================================================================
    section("C8 $server: a pause 3 s before and 3 s after");

    $early = fresh_attempt();
    deadline_in($early, 3);
    $res = http('ada', 'POST', "student/lock/$early", ['csrf_token' => $tokens[$server], 'trigger' => 'tab_hidden'], $server);
    same("C8 $server: 3 s before: paused", true, $res['json']['new'] ?? null);

    $late = fresh_attempt();
    deadline_in($late, -3);
    $res = http('ada', 'POST', "student/lock/$late", ['csrf_token' => $tokens[$server], 'trigger' => 'tab_hidden'], $server);
    same("C8 $server: 3 s after: 409", 409, $res['status']);
    same("C8 $server: no lock row", 0, (int) scalar("SELECT COUNT(*) FROM attempt_locks WHERE attempt_id = ?", [$late]));
    same("C8 $server: and the paper is closed", 'auto_submitted', scalar("SELECT status FROM exam_attempts WHERE id = ?", [$late]));

    // ========================================================================
    section("C9 $server: the sweep's 5 minutes, either side");

    $swept = fresh_attempt();
    $kept  = fresh_attempt();
    deadline_in($swept, -(Attempt::SWEEP_GRACE_MINUTES * 60 + 3));
    deadline_in($kept, -(Attempt::SWEEP_GRACE_MINUTES * 60 - 3));
    http('teacher', 'GET', 'lecturer/dashboard', [], $server);
    same("C9 $server: 5 min 3 s past its deadline: swept", 'auto_submitted',
        scalar("SELECT status FROM exam_attempts WHERE id = ?", [$swept]));
    same("C9 $server: 4 min 57 s past: left for its student", 'in_progress',
        scalar("SELECT status FROM exam_attempts WHERE id = ?", [$kept]));

    // ========================================================================
    section("C10 $server: the window, 3 s either side of each edge");

    $cases = [
        'opens in 3 s'   => ['NOW() + INTERVAL 3 SECOND', 'NOW() + INTERVAL 6 HOUR', false, 'Not open yet', 'This exam has not opened yet.'],
        'opened 3 s ago' => ['NOW() - INTERVAL 3 SECOND', 'NOW() + INTERVAL 6 HOUR', true,  'Start exam',   'Attempt quiz'],
        'closes in 3 s'  => ['NOW() - INTERVAL 1 HOUR',   'NOW() + INTERVAL 3 SECOND', true, 'Start exam',  'Attempt quiz'],
        'closed 3 s ago' => ['NOW() - INTERVAL 1 HOUR',   'NOW() - INTERVAL 3 SECOND', false, 'Window closed', 'This exam has closed.'],
    ];
    foreach ($cases as $label => [$opens, $closes, $startable, $dashboardSays, $pageSays]) {
        $examId = fresh_exam($opens, $closes);
        $title  = (string) scalar("SELECT title FROM exams WHERE id = ?", [$examId]);

        $dash = http('ada', 'GET', 'student/dashboard', [], $server);
        preg_match('/' . preg_quote($title, '/') . '.*?(Not open yet|Window closed|Start exam)/s', $dash['body'], $m);
        same("C10 $server, $label: the dashboard says $dashboardSays", $dashboardSays, $m[1] ?? null);

        $page = http('ada', 'GET', "student/attempt/$examId", [], $server);
        check("C10 $server, $label: the instructions page says $pageSays", strpos($page['body'], $pageSays) !== false);

        $res = http('ada', 'POST', "student/startExam/$examId", ['csrf_token' => $tokens[$server]], $server);
        $made = (int) scalar("SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ?", [$examId]);
        if ($startable) {
            same("C10 $server, $label: starting is allowed", 1, $made);
            check("C10 $server, $label: and goes to the paper", strpos($res['location'], 'student/exam/') !== false, $res['location']);
        } else {
            same("C10 $server, $label: starting is refused", 0, $made);
        }
    }
}

// ============================================================================
section('C14 correct answers wait for the window AND the last deadline plus grace');

const HIDDEN_NOTICE = 'stay hidden until the exam window has closed';

foreach (['normal', 'ahead'] as $server) {
    // Window closed 10 s ago. Ada finished; Bola, who started just before it
    // closed, is still sitting.
    $examId = fresh_exam('NOW() - INTERVAL 2 HOUR', 'NOW() - INTERVAL 10 SECOND');
    $ada  = fresh_attempt('ada', $examId);
    $bola = fresh_attempt('bola', $examId);
    (new Attempt())->submitAndGrade($ada);
    // Ada started early: her own deadline is long gone. Every attempt's
    // deadline counts, submitted or not, so hers must not be the one that
    // holds the answers back here.
    deadline_in($ada, -3600);
    $review = static fn(): string => http('ada', 'GET', "student/result/$ada", [], $server)['body'];

    deadline_in($bola, 60);
    check("C14 $server: window closed, but someone still sitting: hidden", strpos($review(), HIDDEN_NOTICE) !== false);

    deadline_in($bola, -(Attempt::SWEEP_GRACE_MINUTES * 60 - 3));
    check("C14 $server: their deadline passed 4 min 57 s ago, inside the grace: still hidden",
        strpos($review(), HIDDEN_NOTICE) !== false);

    deadline_in($bola, -(Attempt::SWEEP_GRACE_MINUTES * 60 + 3));
    check("C14 $server: 5 min 3 s after the last deadline: shown", strpos($review(), HIDDEN_NOTICE) === false);

    // Every deadline long past, but the window still open: hidden, because
    // someone may yet start.
    $examId = fresh_exam('NOW() - INTERVAL 3 HOUR', 'NOW() + INTERVAL 60 SECOND');
    $ada2 = fresh_attempt('ada', $examId);
    (new Attempt())->submitAndGrade($ada2);
    deadline_in($ada2, -2 * 3600);
    check("C14 $server: every deadline long past, window still open: hidden",
        strpos(http('ada', 'GET', "student/result/$ada2", [], $server)['body'], HIDDEN_NOTICE) !== false);
}

// ============================================================================
section('C11 the database session is pinned to +01:00');

same('C11 the session time zone is +01:00', '+01:00', scalar("SELECT @@session.time_zone"));
same('C11 NOW() is UTC plus one hour', 60, (int) scalar("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW() + INTERVAL 30 SECOND)"));

// ============================================================================
section('C13 no student code reads PHP\'s clock to decide anything');

$controller = (string) file_get_contents(APP_ROOT . '/app/controllers/StudentController.php');
same('C13 StudentController calls none of time(), strtotime(), date()', 0,
    preg_match_all('/\b(time|strtotime|date)\s*\(/', $controller));
foreach (['dashboard', 'attempt'] as $view) {
    $src = (string) file_get_contents(APP_ROOT . "/app/views/student/$view.php");
    same("C13 student/$view.php decides nothing with \$now", 0, preg_match_all('/\$now\b/', $src));
}

// ---- Diagnostics -----------------------------------------------------------

section('Diagnostics');
$diags = test_diagnostics();
check('no notices or deprecations were raised in this runner', $diags === [],
    implode(' | ', array_slice($diags, 0, 5)));

// ---- Result ---------------------------------------------------------------

echo "\n";
printf("PHP %s: %d passed, %d failed\n", PHP_VERSION, $passed, count($failed));

if ($failed !== []) {
    echo "\nFailures:\n";
    foreach ($failed as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

exit(0);
