<?php
/**
 * Router for PHP's built-in server, so HTTP tests can drive the real app
 * without Apache and without config/config.php.
 *
 * The suites start and stop their own server through test_start_server() in
 * tests/bootstrap.php - autosave_test.php on 8099, sweep_test.php on 8098 -
 * and each refuses to run if its port is already taken.
 *
 * To serve the test app by hand, use a port the suites do not claim, and stop
 * the server when you are done with it:
 *
 *   bash:        EXAM_CONFIG=config/config.test.php php -S 127.0.0.1:8199 -t public tests/router.php
 *   PowerShell:  $env:EXAM_CONFIG = 'config/config.test.php'; php -S 127.0.0.1:8199 -t public tests/router.php
 *
 * Never 8098 or 8099. This recipe used to name 8099; a server started from it
 * was left running from 9 to 11 Sep 2026, and autosave_test.php passed against
 * that stranger for two days without ever starting its own.
 *
 * public/.htaccess does the same job under Apache: send anything that is not a
 * real file to index.php as ?url=... . The built-in server ignores .htaccess,
 * so that rewrite is reproduced here rather than left to differ silently
 * between how the app is tested and how it is served.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$file = __DIR__ . '/../public' . $path;

// A real file - stylesheet, image - is served as-is, exactly as the two
// RewriteCond lines in .htaccess allow.
if ($path !== '/' && is_file($file)) {
    return false;
}

// Everything else is a route. index.php reads $_GET['url'].
$_GET['url'] = ltrim($path, '/');

require __DIR__ . '/../public/index.php';
