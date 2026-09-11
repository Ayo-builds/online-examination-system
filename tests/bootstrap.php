<?php
/**
 * Test harness bootstrap. Every test script starts with:
 *
 *     require __DIR__ . '/bootstrap.php';
 *
 * It loads config/config.test.php - never config/config.php - so a test run
 * cannot change, or even read, the settings the working app uses. The main
 * config is not edited, not backed up and not restored, because it is never
 * touched in the first place.
 *
 * It also refuses, loudly, to run against the working database. A harness that
 * imports 250 students and then deletes them by batch id has no business being
 * able to aim itself at real data by a one-word typo in a config file.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$testConfig = APP_ROOT . '/config/config.test.php';

if (!is_file($testConfig)) {
    fwrite(STDERR, "No config/config.test.php.\n"
        . "Copy config/config.test.example.php to config/config.test.php first.\n");
    exit(1);
}

require_once $testConfig;

// ---- The guard ------------------------------------------------------------
// Belt and braces: the name must differ from the working database, and it must
// look like a test database. Either check alone would be enough; both together
// mean a careless edit has to defeat two independent conditions.
if (defined('PROTECTED_DB_NAME') && DB_NAME === PROTECTED_DB_NAME) {
    fwrite(STDERR, "REFUSING TO RUN: config.test.php points at the working database ("
        . DB_NAME . ").\n");
    exit(1);
}

if (strpos(DB_NAME, 'test') === false && strpos(DB_NAME, 'dryrun') === false) {
    fwrite(STDERR, "REFUSING TO RUN: DB_NAME '" . DB_NAME . "' does not look like a test\n"
        . "database. Name it something containing 'test' or 'dryrun'.\n");
    exit(1);
}

require_once APP_ROOT . '/app/core/helpers.php';

spl_autoload_register(static function (string $class): void {
    foreach (['core', 'controllers', 'models', 'middleware'] as $dir) {
        $file = APP_ROOT . '/app/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// ---- Diagnostics are failures ---------------------------------------------
// A notice or a deprecation in a view is a defect, not noise. Collect them so
// a test can assert none were raised, rather than letting them scroll past.
$GLOBALS['test_diagnostics'] = [];

error_reporting(E_ALL);
ini_set('display_errors', '0');

set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    $GLOBALS['test_diagnostics'][] = sprintf('%s:%d  %s', basename($file), $line, $message);
    return true;
});

/** Diagnostics raised since the last reset, then clear them. */
function test_diagnostics(): array
{
    $found = $GLOBALS['test_diagnostics'];
    $GLOBALS['test_diagnostics'] = [];
    return $found;
}

/** Drop and recreate the test database, then load the schema into it. */
function test_reset_database(): void
{
    $mysql = 'C:/xampp/mysql/bin/mysql.exe';
    $name  = DB_NAME;

    $pass = DB_PASS === '' ? '' : ' -p' . escapeshellarg(DB_PASS);
    $base = escapeshellarg($mysql) . ' -u ' . escapeshellarg(DB_USER) . $pass;

    shell_exec($base . ' -e ' . escapeshellarg(
        "DROP DATABASE IF EXISTS `$name`; "
        . "CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    ));

    shell_exec($base . ' ' . escapeshellarg($name) . ' < '
        . escapeshellarg(APP_ROOT . '/database/schema_import.sql'));
}

/**
 * Serve the real app through PHP's built-in server on $host:$port for the
 * rest of this script, and stop it on every way out.
 *
 * It refuses to share the port. A process already listening there was not
 * started by this run, so its docroot, config and database are unknown, and a
 * suite that quietly tests it proves nothing. That is not hypothetical: from
 * 9 to 11 Sep 2026 tests/autosave_test.php passed against a php -S left
 * running by hand, because its own server never started.
 *
 * Two Windows traps, both hit in this repository:
 *
 *  - The command is an ARRAY, so php.exe is started directly. A command
 *    string goes through cmd.exe; proc_terminate() then kills cmd.exe and
 *    php.exe lives on as an orphan still holding the port.
 *
 *  - The environment is the inherited one PLUS EXAM_CONFIG. An array passed
 *    here replaces the child's whole environment, and a Windows child without
 *    SystemRoot cannot start Winsock: "Failed to listen ... (reason: ?)".
 *
 * The shutdown function runs on the normal end, on exit() after a failed
 * assertion, on an uncaught exception and on a fatal error. It does not run
 * when the console is closed or Ctrl+C is pressed on Windows; a server left
 * that way is caught by the port check on the next run.
 */
function test_start_server(string $host, int $port): void
{
    $probe = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if ($probe) {
        fclose($probe);
        fwrite(STDERR, "REFUSING TO RUN: something is already listening on $host:$port.\n"
            . "This suite starts and stops its own server and will not test one it did\n"
            . "not start. Find the process with\n"
            . "    Get-NetTCPConnection -LocalPort $port -State Listen\n"
            . "stop it, and run again.\n");
        exit(1);
    }

    // Its output goes to a file, not a pipe: nothing reads a pipe while the
    // suite runs, and a full one would block the server mid-test.
    $log = (string) tempnam(sys_get_temp_dir(), 'exam_server_');

    $server = proc_open(
        [PHP_BINARY, '-S', "$host:$port", '-t', APP_ROOT . '/public', APP_ROOT . '/tests/router.php'],
        [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        APP_ROOT,
        array_merge(getenv(), ['EXAM_CONFIG' => 'config/config.test.php'])
    );

    if (!is_resource($server)) {
        fwrite(STDERR, "Could not start the built-in server.\n");
        exit(1);
    }

    register_shutdown_function(static function () use ($server, $log): void {
        if (proc_get_status($server)['running']) {
            proc_terminate($server);
        }
        proc_close($server);
        if (is_file($log)) {
            unlink($log);
        }
    });

    // Wait for it to accept connections rather than sleeping a fixed amount,
    // and stop waiting at once if it has already died.
    $up = false;
    for ($i = 0; $i < 100; $i++) {
        if (!proc_get_status($server)['running']) {
            break;
        }
        $sock = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($sock) {
            fclose($sock);
            $up = true;
            break;
        }
        usleep(100000);
    }

    // The error handler above records warnings even under @, so every probe
    // made before the server was listening left one. Those failures were the
    // point of probing; drop them so a suite's diagnostics check judges only
    // its own requests.
    test_diagnostics();

    if (!$up) {
        fwrite(STDERR, "Server did not come up on $host:$port.\n--- server output ---\n"
            . (string) file_get_contents($log) . "\n");
        exit(1);
    }
}

printf("test bootstrap: database '%s' on %s\n\n", DB_NAME, DB_HOST);
