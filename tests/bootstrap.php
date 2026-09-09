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

printf("test bootstrap: database '%s' on %s\n\n", DB_NAME, DB_HOST);
