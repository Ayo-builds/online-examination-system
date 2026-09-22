<?php
/**
 * The test configuration, with PHP's clock deliberately wrong.
 *
 * Served only by tests/lock_test.php and tests/clock_test.php, through PHP's
 * built-in server and the EXAM_CONFIG override that public/index.php honours
 * under cli-server alone.
 *
 * The database connection keeps its time zone (+01:00, Database.php) while
 * PHP's moves to UTC-11. Every DATETIME the database writes then reads twelve
 * hours wrong through strtotime(). Anything that decides a pause, a deadline
 * or a window with PHP's clock instead of the database's gives itself away
 * under this config: it thinks there is time left when there is none, so it
 * accepts too LATE. tests/config.clock_offset_east.php catches the other way.
 */

require __DIR__ . '/../config/config.test.php';

date_default_timezone_set('Pacific/Pago_Pago');
