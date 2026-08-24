<?php
declare(strict_types=1);

// ---- Session hardening (must run BEFORE session_start) ----
//
// Reject a session id the client invented rather than adopting it, which is
// the cheap half of session-fixation defence. Auth::attempt() already handles
// the other half by regenerating the id on successful login.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');   // never accept an id from the URL

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

require_once APP_ROOT . '/config/config.php';

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