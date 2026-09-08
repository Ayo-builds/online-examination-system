<?php
// Copy this file to config.php and fill in your own values.
// config.php is gitignored on purpose: it is the only place environment truth
// lives, so a clone never carries one machine's credentials to another.

// ---- Timezone ---------------------------------------------------------------
// Exam deadlines are WRITTEN by MySQL (NOW()) and ENFORCED by PHP (time()).
// If those two clocks disagree, every exam timer is wrong by the offset, and a
// paper will either expire the moment it opens or run long. Set this to the
// server's own timezone and verify both agree before letting anyone sit an exam.
// See OFFLINE-DEPLOYMENT.md, step 5.
date_default_timezone_set('Africa/Lagos');

// ---- Database ---------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'exam_system');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// ---- Application ------------------------------------------------------------
define('APP_NAME', 'Online Examination System');

// Where the app is reachable from a browser. Trailing slash required.
//   XAMPP, app in htdocs   ->  '/exam-system/public/'
//   public/ is the docroot ->  '/'
// This is not a hostname: the same value works for localhost and a LAN IP.
define('BASE_URL', '/exam-system/public/');

// ---- Sign-in throttling ------------------------------------------------------
// Both are required. Auth::recordFailure() references them directly, so a
// missing value is a fatal error on the first failed sign-in, not a warning.
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// ---- Invigilation ------------------------------------------------------------
// Activity events on a single attempt before it is flagged for lecturer review.
define('FLAG_THRESHOLD', 3);

// Defined for compatibility. Nothing in the application currently reads it.
define('SUBMIT_GRACE_SECONDS', 30);
