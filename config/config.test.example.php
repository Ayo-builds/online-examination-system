<?php
// Copy this file to config.test.php and adjust if your local MySQL differs.
//
// This is the config the test harness loads. It exists so that testing never
// requires editing config/config.php: the working database and the app's real
// settings stay exactly as you left them, whatever a test run does.
//
// The one rule this file must obey: DB_NAME must NOT be the working database.
// tests/bootstrap.php refuses to run if it is, because a harness that creates,
// imports and deletes should never be able to point itself at real data.

date_default_timezone_set('Africa/Lagos');

// ---- Database ---------------------------------------------------------------
// A throwaway database, created and dropped by the harness. Never the one the
// app uses day to day.
define('DB_HOST', 'localhost');
define('DB_NAME', 'exam_system_test');
define('DB_USER', 'root');
define('DB_PASS', '');

// The working database, named here only so bootstrap.php can refuse to run
// against it. Nothing connects to this.
define('PROTECTED_DB_NAME', 'exam_system');

// ---- Application ------------------------------------------------------------
define('APP_NAME', 'Online Examination System');

// Root, because the test HTTP server serves public/ directly rather than from
// a subdirectory the way XAMPP does.
define('BASE_URL', '/');

// ---- Sign-in throttling ------------------------------------------------------
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// ---- Invigilation ------------------------------------------------------------
define('FLAG_THRESHOLD', 3);
define('SUBMIT_GRACE_SECONDS', 30);
