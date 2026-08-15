<?php
declare(strict_types=1);

/**
 * Marketing site bootstrap.
 *
 * These pages are served DIRECTLY by Apache — public/.htaccess passes real files
 * straight through, so nothing here goes near the Router, a Controller, a Model,
 * or the database. This file exists only to borrow APP_NAME and BASE_URL from the
 * app's own config so the two stay in sync, and to send the same security headers
 * public/index.php sends (which marketing pages would otherwise miss entirely).
 *
 * It starts no session and opens no PDO connection.
 */

$configFile = dirname(__DIR__, 3) . '/config/config.php';

if (!defined('APP_NAME') && is_readable($configFile)) {
    require_once $configFile;
}

// Standalone fallbacks, so a missing/renamed config degrades to a working page
// rather than a stack of undefined-constant errors.
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Online Examination System');
}
if (!defined('BASE_URL')) {
    define('BASE_URL', '/exam-system/public/');
}

// Mirror the headers set in public/index.php.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0');
}

/** Absolute URL for anything under public/. */
function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/** Absolute URL for a marketing asset. */
function asset(string $path): string
{
    return url('marketing/assets/' . ltrim($path, '/'));
}

/** Escape for HTML output. */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Where the sign-in CTA points — the EXISTING auth route, untouched. */
const LOGIN_URL_PATH = 'auth/login';
