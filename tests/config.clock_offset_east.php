<?php
/**
 * The test configuration, with PHP's clock deliberately wrong the other way:
 * thirteen hours AHEAD of the database.
 *
 * Served only by tests/clock_test.php, through PHP's built-in server and the
 * EXAM_CONFIG override that public/index.php honours under cli-server alone.
 *
 * The database connection keeps its time zone (+01:00, Database.php) while
 * PHP's moves to UTC+14. Every DATETIME the database writes then reads
 * thirteen hours early through strtotime(), so a decision made with PHP's
 * clock thinks a deadline or a window's end has already passed. This catches
 * what tests/config.clock_offset.php (PHP behind) cannot: a paper closed, or
 * a save refused, too EARLY. Between them the two catch both directions.
 */

require __DIR__ . '/../config/config.test.php';

date_default_timezone_set('Pacific/Kiritimati');
