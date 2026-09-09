<?php
/**
 * Router for PHP's built-in server, so HTTP tests can drive the real app
 * without Apache and without config/config.php.
 *
 *   EXAM_CONFIG=config/config.test.php php -S 127.0.0.1:8099 -t public tests/router.php
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
