<?php
/**
 * One side of a race, for tests/lock_race_test.php. Not a test on its own.
 *
 *   php tests/workers/lock_race_worker.php <action> <attempt id> <start at>
 *
 * Connects to the test database, then waits for <start at> (a Unix time with
 * microseconds, shared by both workers in a round) before doing exactly one
 * thing to the attempt:
 *
 *   lock_blur        Attempt::lock(id, 'window_blur', 3000)
 *   lock_hidden      Attempt::lock(id, 'tab_hidden', null)
 *   lock_fullscreen  Attempt::lock(id, 'fullscreen_exit', null)
 *   submit           Attempt::submitAndGrade(id)
 *
 * The last line of output is "RESULT <ready> <outcome>". <ready> is 1 when the
 * worker was already connected and waiting before the start instant; a worker
 * that arrived late did not race anything, and the driver treats that as a
 * failed round rather than a pass.
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/../bootstrap.php';
ob_end_clean();

[, $action, $attemptId, $startAt] = $argv;
$attemptId = (int) $attemptId;
$startAt   = (float) $startAt;

// Connect and warm up before the start, so both workers race the database and
// not each other's process start-up.
$attempts = new Attempt();
Database::getInstance()->query('SELECT 1')->fetchColumn();

$ready = microtime(true) < $startAt ? 1 : 0;
while (microtime(true) < $startAt) {
    usleep(200);
}

try {
    switch ($action) {
        case 'lock_blur':
            $outcome = $attempts->lock($attemptId, 'window_blur', 3000);
            break;
        case 'lock_hidden':
            $outcome = $attempts->lock($attemptId, 'tab_hidden', null);
            break;
        case 'lock_fullscreen':
            $outcome = $attempts->lock($attemptId, 'fullscreen_exit', null);
            break;
        case 'submit':
            $outcome = $attempts->submitAndGrade($attemptId) === null ? 'refused' : 'submitted';
            break;
        default:
            $outcome = 'error unknown action';
    }
} catch (Throwable $e) {
    $outcome = 'error ' . str_replace(["\r", "\n"], ' ', get_class($e) . ': ' . $e->getMessage());
}

echo "RESULT $ready $outcome\n";
