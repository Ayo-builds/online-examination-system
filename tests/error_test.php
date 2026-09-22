<?php
/**
 * Error handling: an error nobody caught gets an id, a log line and a safe
 * answer in its route's shape.
 *
 *   php tests/error_test.php
 *
 * Runs against the test database only - bootstrap.php refuses anything else -
 * and drives the REAL application over HTTP through PHP's built-in server:
 *
 *   127.0.0.1:8094  tests/config.errors.php         DISPLAY_ERRORS undefined, faults on
 *   127.0.0.1:8093  tests/config.errors_display.php DISPLAY_ERRORS true, faults on
 *   127.0.0.1:8092  tests/config.errors_hidden.php  DISPLAY_ERRORS false, faults OFF
 *   127.0.0.1:8091  tests/config.errors_broken_page.php  the error page breaks, faults on
 *
 * How errors are caused, with no "throw on purpose" route in the app:
 *
 *  - Real routes fail for real: exam_attempts is renamed in the TEST database
 *    and put back in a finally. A run that crashed mid-way is repaired at the
 *    start of the next.
 *  - The database goes away for real: the TEST database is dropped last, and
 *    rebuilt before the suite exits.
 *  - Faults that need code - a fatal, half a page, an open transaction - come
 *    from tests/faults/FaultController.php. tests/router.php loads it only when
 *    a server is started with EXAM_FAULTS=1, and Apache never runs that file.
 *
 * All four servers log to tests/logs/error_test.log, emptied at the start.
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

// ---- A crashed earlier run --------------------------------------------------
//
// If a previous run died between renaming exam_attempts and putting it back,
// the test database is left without that table. Put it back before anything
// else, and say so.

const RENAMED_TABLE = 'exam_attempts_error_test_renamed';

$root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = $root->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ?");
$tables->execute([DB_NAME]);
$tables = $tables->fetchAll(PDO::FETCH_COLUMN);
if (in_array(RENAMED_TABLE, $tables, true) && !in_array('exam_attempts', $tables, true)) {
    $root->exec('RENAME TABLE `' . DB_NAME . '`.`' . RENAMED_TABLE . '` TO `' . DB_NAME . '`.`exam_attempts`');
    echo "Restored exam_attempts in " . DB_NAME . ", left renamed by an earlier run that did not finish.\n\n";
}
$root = null;

// ---- The app, served four times ---------------------------------------------

$host = '127.0.0.1';
$servers = [
    'main'    => "http://$host:8094/",
    'display' => "http://$host:8093/",
    'hidden'  => "http://$host:8092/",
    'broken'  => "http://$host:8091/",
];

const LOG_FILE = APP_ROOT . '/tests/logs/error_test.log';
if (!is_dir(dirname(LOG_FILE))) {
    mkdir(dirname(LOG_FILE), 0775, true);
}
foreach ([LOG_FILE, LOG_FILE . '.1'] as $f) {
    if (is_file($f)) unlink($f);
}

test_start_server($host, 8094, 'tests/config.errors.php', ['EXAM_FAULTS' => '1']);
test_start_server($host, 8093, 'tests/config.errors_display.php', ['EXAM_FAULTS' => '1']);
test_start_server($host, 8092, 'tests/config.errors_hidden.php');
test_start_server($host, 8091, 'tests/config.errors_broken_page.php', ['EXAM_FAULTS' => '1']);
test_reset_database();

// ---- HTTP, one cookie jar per actor and server ------------------------------

$jars = [];
register_shutdown_function(static function () use (&$jars): void {
    foreach ($jars as $jar) {
        if (is_file($jar)) unlink($jar);
    }
});

/**
 * @return array{status:int, body:string, json:?array, headers:array<string,string>}
 */
function http(string $who, string $method, string $path, array $post = [], string $server = 'main'): array
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
        'status'  => $status,
        'body'    => $body,
        'json'    => is_array($json) ? $json : null,
        'headers' => $headers,
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

// ---- What an answer must look like -------------------------------------------

/** The id alphabet is Password.php's, exactly: no 0, 1, I, L or O. */
const ID_PATTERN = '/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}$/';

const SENTENCE = "Something went wrong on our side. It wasn't anything you did. If it keeps happening, report this code: ";

/** Things that would give an error's details away. None may reach a user while display is off. */
/** Paths appear both ways: app/ in the log, app\ in a trace on Windows. */
const DETAIL_PROBES = ['SQLSTATE', 'exam_system_test', 'exam_attempts', 'app/', 'app\\', '.php', 'Stack trace',
                       '#0 ', 'PDOException', 'RuntimeException', 'Allowed memory', 'Fault:'];

function page_text(string $body): string
{
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES)));
}

/** The id a page shows, or '' if it shows none in the agreed sentence. */
function page_id(string $body): string
{
    $text = page_text($body);
    $at = strpos($text, SENTENCE);
    if ($at === false) {
        return '';
    }
    $id = substr($text, $at + strlen(SENTENCE), 9);
    return preg_match(ID_PATTERN, $id) === 1 ? $id : '';
}

function probes_found(string $body): array
{
    return array_values(array_filter(DETAIL_PROBES, static fn(string $p): bool => stripos($body, $p) !== false));
}

/** Checks for a JSON route's 500. Returns its id. */
function json_error(string $label, array $res): string
{
    same("$label: status 500", 500, $res['status']);
    check("$label: Content-Type is JSON", strpos($res['headers']['content-type'] ?? '', 'application/json') === 0,
        $res['headers']['content-type'] ?? 'none');
    same("$label: Cache-Control no-store", 'no-store', $res['headers']['cache-control'] ?? null);
    same("$label: exactly the keys ok, error, id", ['ok', 'error', 'id'], array_keys($res['json'] ?? []));
    same("$label: ok is false", false, $res['json']['ok'] ?? null);
    same("$label: error is server", 'server', $res['json']['error'] ?? null);
    $id = (string) ($res['json']['id'] ?? '');
    check("$label: the id is XXXX-XXXX from Password.php's alphabet", preg_match(ID_PATTERN, $id) === 1, $id);
    return $id;
}

/** Checks for a page route's 500. Returns its id. */
function page_error(string $label, array $res): string
{
    same("$label: status 500", 500, $res['status']);
    check("$label: Content-Type is HTML", strpos($res['headers']['content-type'] ?? '', 'text/html') === 0,
        $res['headers']['content-type'] ?? 'none');
    same("$label: Cache-Control no-store", 'no-store', $res['headers']['cache-control'] ?? null);
    $id = page_id($res['body']);
    check("$label: the page says the agreed sentence with an id", $id !== '', substr(page_text($res['body']), 0, 200));
    return $id;
}

function log_lines(): array
{
    clearstatcache(true, LOG_FILE);
    return is_file(LOG_FILE) ? file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
}

/** The one log line carrying $id, checked to be exactly one. */
function log_line(string $label, string $id): string
{
    $lines = array_values(array_filter(log_lines(), static fn(string $l): bool => strpos($l, " $id ") !== false));
    same("$label: exactly one log line carries the id the user was shown", 1, count($lines));
    return $lines[0] ?? '';
}

/** The line in FaultController.php that holds $needle. */
function fault_line(string $needle): int
{
    foreach (file(APP_ROOT . '/tests/faults/FaultController.php') as $i => $line) {
        if (strpos($line, $needle) !== false) {
            return $i + 1;
        }
    }
    return 0;
}

function scalar_value(string $sql)
{
    return Database::getInstance()->query($sql)->fetchColumn();
}

// ---- Fixture ---------------------------------------------------------------

$db = Database::getInstance();

$classId = (int) $db->query("SELECT id FROM classes ORDER BY id LIMIT 1")->fetchColumn();
$pass = 'student-pass-123';
$db->prepare("INSERT INTO users (full_name, admission_no, password_hash, role, class_id) VALUES (?,?,?, 'student', ?)")
   ->execute(['Ada', 'ADM/ERR/1', Password::hash($pass), $classId]);
$studentId = (int) $db->lastInsertId();

$tokens = [];
foreach (['main', 'display', 'hidden'] as $server) {
    $tokens[$server] = sign_in('ada', 'ADM/ERR/1', $pass, $server);
}

$heartbeat = static fn(string $server, string $path = 'student/heartbeat/1'): array
    => http('ada', 'POST', $path, ['csrf_token' => $GLOBALS['tokens'][$server]], $server);

// ============================================================================
section('E4 every JSON action is a JSON route, and every JSON route exists');

$found = [];
foreach (glob(APP_ROOT . '/app/controllers/*Controller.php') as $file) {
    $class = basename($file, '.php');
    $src = (string) file_get_contents($file);
    preg_match_all('/public function (\w+)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as $i => [$name, $at]) {
        $end  = $m[1][$i + 1][1] ?? strlen($src);
        if (strpos(substr($src, $at, $end - $at), '$this->json(') !== false) {
            $found[] = strtolower(substr($class, 0, -strlen('Controller'))) . '/' . strtolower($name);
        }
    }
}
sort($found);
$listed = ErrorHandler::JSON_ROUTES;
sort($listed);
same('E4 JSON_ROUTES is exactly the set of actions that answer JSON', $found, $listed);
check('E4 found the six JSON actions, so the scan itself works', count($found) === 6, implode(', ', $found));

// ============================================================================
section('E13 no fault route outside the suite');

$res = http('anon', 'GET', 'fault/memory', [], 'hidden');
same('E13 /fault/memory without EXAM_FAULTS is a 404', 404, $res['status']);
$res = http('anon', 'GET', 'fault/plain', [], 'hidden');
same('E13 /fault/plain without EXAM_FAULTS is a 404', 404, $res['status']);

// ============================================================================
section('E11 and E7 a plain exception: logged with its id, file and line');

$res = http('anon', 'GET', 'fault/plain');
$plainId = page_error('E11 /fault/plain', $res);
$line = log_line('E7 plain', $plainId);
check('E7 the line has the class and message', strpos($line, 'error RuntimeException "Fault: plain exception"') !== false, $line);
$where = 'tests/faults/FaultController.php:' . fault_line("'Fault: plain exception'");
check('E7 the line has the file and line of the throw', strpos($line, $where) !== false, "$where in: $line");
check('E7 the line has the request', strpos($line, '| GET /fault/plain |') !== false, $line);
check('E11 the handler opened no connection and found no transaction', str_ends_with($line, '| tx=none'), $line);
same('E6 nothing of the details reaches the page (display undefined)', [], probes_found($res['body']));

// ============================================================================
section('E5 a real fatal error, caught by the shutdown function');

$res = http('anon', 'GET', 'fault/memory');
$fatalId = page_error('E5 /fault/memory', $res);
$line = log_line('E7 fatal', $fatalId);
check('E5 logged as a fatal E_ERROR, memory exhausted', strpos($line, 'fatal E_ERROR "Allowed memory size') !== false, $line);
check('E5 logged where it happened', strpos($line, 'tests/faults/FaultController.php:') !== false, $line);
same('E6 nothing of the fatal reaches the page (display undefined)', [], probes_found($res['body']));

// ============================================================================
section('E8 half a page already written is thrown away');

$res = http('anon', 'GET', 'fault/halfPage');
$halfId = page_error('E8 /fault/halfPage', $res);
check('E8 none of the half page survives', strpos($res['body'], 'HALF-PAGE-MARKER') === false
    && strpos($res['body'], 'NESTED-MARKER') === false, substr($res['body'], 0, 200));
check('E8 the answer is one whole document', strpos(ltrim($res['body']), '<!DOCTYPE html>') === 0
    && substr_count($res['body'], '<html') === 1, substr($res['body'], 0, 120));
log_line('E7 half page', $halfId);

// ============================================================================
section('E9 output already flushed: only the id is added');

$res = http('anon', 'GET', 'fault/flushed');
same('E9 the status already sent stays (200)', 200, $res['status']);
preg_match('/report this code: ([A-Z0-9]{4}-[A-Z0-9]{4})/', $res['body'], $m);
$flushedId = $m[1] ?? '';
check('E9 the body ends with the sentence and an id', preg_match(ID_PATTERN, $flushedId) === 1, substr($res['body'], -200));
same('E9 and nothing of the details', [], probes_found($res['body']));
check('E9 and no second page dropped into the half-sent one', stripos($res['body'], '<html') === false
    && strpos($res['body'], 'FLUSHED-MARKER') === 0, substr($res['body'], 0, 200));
log_line('E7 flushed', $flushedId);

// ============================================================================
section('E10 an open transaction is rolled back');

$res = http('anon', 'GET', 'fault/transaction');
$txId = page_error('E10 /fault/transaction', $res);
$line = log_line('E7 transaction', $txId);
check('E10 the log says the open transaction was rolled back', str_ends_with($line, '| tx=rolled back'), $line);
same('E10 and the row it wrote is not there', 0,
    (int) scalar_value("SELECT COUNT(*) FROM login_attempts WHERE identifier = 'error-test-transaction'"));

// ============================================================================
section('E12 a warning is logged and the page carries on');

$before = count(log_lines());
$res = http('anon', 'GET', 'fault/warning');
same('E12 status 200: the warning did not become an error', 200, $res['status']);
same('E12 the whole page arrived, and nothing of the warning with it', 'WARNING-PAGE-COMPLETE', $res['body']);
$new = array_slice(log_lines(), $before);
same('E12 one line was logged', 1, count($new));
check('E12 as a warning, with where it happened', strpos($new[0] ?? '', ' warning E_WARNING "Undefined array key') !== false
    && strpos($new[0] ?? '', 'tests/faults/FaultController.php:' . fault_line('$empty[\'missing\']')) !== false, $new[0] ?? '');

// ============================================================================
section('E15 the error page itself fails');

$res = http('anon', 'GET', 'fault/plain', [], 'broken');
same('E15 status 500', 500, $res['status']);
$brokenId = page_id($res['body']);
check('E15 the fallback page still says the sentence with an id', $brokenId !== '', substr($res['body'], 0, 300));
check('E15 and it is the fallback, which loads no stylesheet', strpos($res['body'], 'stylesheet') === false);
same('E15 nothing of the details', [], probes_found($res['body']));
$lines = array_values(array_filter(log_lines(), static fn(string $l): bool => strpos($l, " $brokenId ") !== false));
same('E15 two lines carry the id: the error, and the page that failed', 2, count($lines));
check('E15 the second says why the page failed', strpos($lines[1] ?? '', 'ErrorPageFailed') !== false
    && strpos($lines[1] ?? '', 'htmlspecialchars') !== false, $lines[1] ?? '');

// ============================================================================
section('E1 E2 E3 E6 E7 real routes, with exam_attempts gone');

$db->exec('RENAME TABLE exam_attempts TO ' . RENAMED_TABLE);
try {
    // E1: a JSON route.
    $res = $heartbeat('main');
    $jsonId = json_error('E1 POST student/heartbeat', $res);
    same('E6 the JSON carries nothing else (display undefined)', [], probes_found($res['body']));
    $line = log_line('E7 JSON route', $jsonId);
    check('E7 the line has the PDO error', strpos($line, 'error PDOException "SQLSTATE[42S02]') !== false, $line);
    check('E7 the line has the file it was thrown in, relative to the app', strpos($line, ' app/core/Model.php:') !== false, $line);
    check('E7 the line has the request and the user', strpos($line, "| POST /student/heartbeat/1 | user $studentId |") !== false, $line);

    // E3: the same route, however the URL spells it.
    $res = $heartbeat('main', 'Student//HeartBeat/1/');
    json_error('E3 POST Student//HeartBeat/1/', $res);

    // E2: a page route.
    $res = http('ada', 'GET', 'student/dashboard', [], 'main');
    $pageId = page_error('E2 GET student/dashboard', $res);
    same('E6 the page carries nothing else (display undefined)', [], probes_found($res['body']));
    $line = log_line('E7 page route', $pageId);
    check('E7 the line has the PDO error', strpos($line, 'error PDOException "SQLSTATE[42S02]') !== false, $line);

    // E6: DISPLAY_ERRORS explicitly false.
    $res = http('ada', 'GET', 'student/dashboard', [], 'hidden');
    page_error('E6 hidden server, page', $res);
    same('E6 nothing of the details on the page (display false)', [], probes_found($res['body']));
    $res = $heartbeat('hidden');
    json_error('E6 hidden server, JSON', $res);
    same('E6 nothing of the details in the JSON (display false)', [], probes_found($res['body']));

    // E6 positive control: with display on, the same probes do see details.
    $res = http('ada', 'GET', 'student/dashboard', [], 'display');
    $shownId = page_error('E6 display server, page', $res);
    $seen = probes_found($res['body']);
    check('E6 control: with display on, the probes find the details',
        in_array('SQLSTATE', $seen, true) && in_array('exam_attempts', $seen, true) && in_array('.php', $seen, true)
        && in_array('#0 ', $seen, true),
        implode(', ', $seen));
    log_line('E7 display server', $shownId);
    $res = $heartbeat('display');
    json_error('E6 display server, JSON', $res);
    same('E6 JSON never carries details, even with display on', [], probes_found($res['body']));
} finally {
    $db->exec('RENAME TABLE ' . RENAMED_TABLE . ' TO exam_attempts');
}
check('exam_attempts is back', (int) scalar_value("SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'exam_attempts'") === 1);

// ============================================================================
section('E17 the log is rotated at 5 MB, keeping one old file');

file_put_contents(LOG_FILE, str_repeat("filler line for the rotation test\n", 160000));   // about 5.4 MB
clearstatcache(true, LOG_FILE);
$filler = filesize(LOG_FILE);
$res = http('anon', 'GET', 'fault/plain');
$rotatedId = page_error('E17 /fault/plain', $res);
clearstatcache();
same('E17 the full log moved to .1', $filler, is_file(LOG_FILE . '.1') ? filesize(LOG_FILE . '.1') : null);
$lines = log_lines();
same('E17 the new log holds only the new line', 1, count($lines));
check('E17 and it is this error', strpos($lines[0] ?? '', " $rotatedId ") !== false, $lines[0] ?? '');

// ============================================================================
section('E14 the database is gone: JSON and page routes still answer properly');
//
// Last, because it drops the test database. It is rebuilt before exit.

$db = null;
$mysql = escapeshellarg('C:/xampp/mysql/bin/mysql.exe') . ' -u ' . escapeshellarg(DB_USER)
       . (DB_PASS === '' ? '' : ' -p' . escapeshellarg(DB_PASS));
shell_exec($mysql . ' -e ' . escapeshellarg('DROP DATABASE `' . DB_NAME . '`'));

try {
    $before = count(log_lines());

    $res = $heartbeat('main');
    $downJsonId = json_error('E14 POST student/heartbeat, database gone', $res);
    same('E14 nothing of the details in the JSON', [], probes_found($res['body']));
    $line = log_line('E14 JSON', $downJsonId);
    check('E14 logged as the connection failure, with its cause',
        strpos($line, 'error RuntimeException "Database connection failed. (caused by PDOException:') !== false, $line);
    check('E14 the handler did not try to connect again', str_ends_with($line, '| tx=none'), $line);

    $res = http('ada', 'GET', 'student/dashboard', [], 'main');
    $downPageId = page_error('E14 GET student/dashboard, database gone', $res);
    same('E14 nothing of the details on the page', [], probes_found($res['body']));
    log_line('E14 page', $downPageId);

    same('E14 one line per error, no second failure inside the handler', 2, count(log_lines()) - $before);
} finally {
    test_reset_database();
}

// ---- Diagnostics -----------------------------------------------------------

section('E16 Diagnostics');
$diags = test_diagnostics();
check('E16 no notices or deprecations were raised in this runner', $diags === [],
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
