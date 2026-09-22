<?php
/**
 * tests/error_test.php, server on 8091: a config that breaks the error page
 * itself (E15). APP_NAME is an array, so the page's htmlspecialchars(APP_NAME)
 * throws a TypeError, and ErrorHandler must fall back to a page that depends
 * on nothing.
 *
 * Defined first, so config.test.php's own APP_NAME only raises a "Constant
 * already defined" warning, which is logged and changes nothing.
 */

define('APP_NAME', ['broken on purpose']);
define('ERROR_LOG_FILE', APP_ROOT . '/tests/logs/error_test.log');

require __DIR__ . '/../config/config.test.php';
