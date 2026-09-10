<?php
declare(strict_types=1);

// ---- Session hardening (must run BEFORE session_start) ----
//
// Reject a session id the client invented rather than adopting it, which is
// the cheap half of session-fixation defence. Auth::attempt() already handles
// the other half by regenerating the id on successful login.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');   // never accept an id from the URL

// ---- Session lifetime (must also run BEFORE session_start) ----
//
// PHP's 24-minute default is shorter than an exam. A candidate who reads and
// thinks for 25 minutes without touching an answer lost their session, and
// every autosave after that failed CSRF verification - silently, because the
// old client swallowed anything that was not a 'closed' error. They kept
// typing into a void.
//
// Four hours clears the longest paper (about two) with room for a power cut
// and a resume in the middle of it. This only relaxes garbage collection; it
// does not keep anyone signed in past sign-out, and the cookie itself stays a
// browser-session cookie (lifetime 0), which is the safer default.
//
// Hardcoded rather than read from config: session_start() runs below, and the
// config file is not loaded until further down this same file. Reordering the
// bootstrap for one constant is not worth the risk.
ini_set('session.gc_maxlifetime', '14400');

// The Secure flag is set only when the request actually arrived over TLS.
// Hardcoding it true would silently break sign-in over plain HTTP, which is
// how this app runs on a local XAMPP box. Behind a TLS-terminating proxy
// (nginx on the live host) $_SERVER['HTTPS'] can be absent, so check the
// forwarded header and port too.
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

session_set_cookie_params([
    'path'     => '/',        // unchanged from the previous default
    'domain'   => '',         // host-only, as before
    'secure'   => $isHttps,   // HTTPS only, when we are on HTTPS
    'httponly' => true,       // JavaScript cannot read the session id
    'samesite' => 'Lax',      // Strict would log users out when they arrive
                              // from an external link; Lax still blocks the
                              // cross-site POSTs that matter for CSRF
]);

session_start();

// ---- Security headers (sent on every response) ----
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 0');

// APP_ROOT = the exam-system folder itself (one level UP from /public)
define('APP_ROOT', dirname(__DIR__));

// Which config to load. Normally there is no choice: config/config.php.
//
// A test run may point the app at its own config instead, but ONLY under PHP's
// built-in server, which is never how this application is served in production
// - Apache and nginx report 'apache2handler' and 'fpm-fcgi', not 'cli-server'.
// So this override cannot be reached over the network, and testing never
// requires editing config/config.php.
$configFile = APP_ROOT . '/config/config.php';

if (PHP_SAPI === 'cli-server') {
    $override = getenv('EXAM_CONFIG');
    if (is_string($override) && $override !== '') {
        $candidate = $override[0] === '/' || preg_match('#^[A-Za-z]:#', $override)
            ? $override
            : APP_ROOT . '/' . $override;

        if (is_file($candidate)) {
            $configFile = $candidate;
        }
    }
}

require_once $configFile;

// Plain functions, so the autoloader below cannot reach them.
require_once APP_ROOT . '/app/core/helpers.php';

// ---- Autoloader: loads class files on demand ----
spl_autoload_register(function (string $class) {
    $paths = [
        APP_ROOT . '/app/core/' . $class . '.php',
        APP_ROOT . '/app/controllers/' . $class . '.php',
        APP_ROOT . '/app/models/' . $class . '.php',
        APP_ROOT . '/app/middleware/' . $class . '.php',
    ];

    foreach ($paths as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ---- Hand the request to the Router ----
$router = new Router();
$router->dispatch($_GET['url'] ?? '');