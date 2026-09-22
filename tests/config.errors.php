<?php
/**
 * tests/error_test.php, server on 8094: the test configuration with its own
 * error log, and DISPLAY_ERRORS left undefined, which must count as off.
 *
 * Served only through PHP's built-in server and the EXAM_CONFIG override that
 * public/index.php honours under cli-server alone.
 */

define('ERROR_LOG_FILE', APP_ROOT . '/tests/logs/error_test.log');

require __DIR__ . '/../config/config.test.php';
