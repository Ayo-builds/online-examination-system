<?php
/**
 * The test configuration, with PHP's clock deliberately wrong.
 *
 * Served only by tests/lock_test.php, through PHP's built-in server and the
 * EXAM_CONFIG override that public/index.php honours under cli-server alone.
 *
 * The database connection keeps its time zone (+01:00, Database.php) while
 * PHP's moves to UTC-11. Every DATETIME the database writes then reads twelve
 * hours wrong through strtotime(). Anything that times a pause with PHP's
 * clock instead of the database's gives itself away under this config.
 *
 * West of the database, not east, on purpose: deadlines still compare in PHP
 * until step 4b, and read twelve hours late they look far off rather than
 * already past, so the rest of the app keeps working here.
 */

require __DIR__ . '/../config/config.test.php';

date_default_timezone_set('Pacific/Pago_Pago');
