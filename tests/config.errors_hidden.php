<?php
/**
 * tests/error_test.php, server on 8092: DISPLAY_ERRORS explicitly false, as a
 * school server's config says it. Started without EXAM_FAULTS, so it is also
 * the server that proves /fault/... does not exist outside the suite (E13).
 */

define('DISPLAY_ERRORS', false);
define('ERROR_LOG_FILE', APP_ROOT . '/tests/logs/error_test.log');

require __DIR__ . '/../config/config.test.php';
