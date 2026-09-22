<?php
/**
 * tests/error_test.php, server on 8093: details shown, as on a developer's
 * laptop. The positive control for E6: it proves the probes that check a
 * hidden page for details can see details when they are there.
 */

define('DISPLAY_ERRORS', true);
define('ERROR_LOG_FILE', APP_ROOT . '/tests/logs/error_test.log');

require __DIR__ . '/../config/config.test.php';
