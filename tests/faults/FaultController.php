<?php
/**
 * Errors on purpose, for tests/error_test.php. TEST ONLY.
 *
 * Reachable only through tests/router.php, only under PHP's built-in server,
 * and only when the suite starts that server with EXAM_FAULTS=1. The app's
 * own autoloader searches app/ alone, so under Apache /fault/... is a 404.
 * Never move this file into app/: test E13 fails if it can be reached
 * without EXAM_FAULTS.
 *
 * Every action here is a page route: ErrorHandler::JSON_ROUTES names only the
 * real JSON endpoints, and JSON errors are tested on those.
 */
class FaultController extends Controller
{
    // An ordinary uncaught exception, with no database involved.
    public function plain(): void
    {
        throw new RuntimeException('Fault: plain exception');
    }

    // Half a page already written, inside a nested buffer the way the admin
    // views open their own, when the error comes.
    public function halfPage(): void
    {
        echo "<!DOCTYPE html>\n<html><body><p>HALF-PAGE-MARKER</p>";
        ob_start();
        echo '<p>NESTED-MARKER</p>';
        throw new RuntimeException('Fault: half page');
    }

    // Output already on its way to the browser: headers are final.
    public function flushed(): void
    {
        echo 'FLUSHED-MARKER';
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        throw new RuntimeException('Fault: after flush');
    }

    // A real fatal error, which no exception handler sees. Its own limit
    // first, so it can never eat the machine's memory if php.ini sets none.
    public function memory(): void
    {
        ini_set('memory_limit', '16M');
        $chunks = [];
        while (true) {
            $chunks[] = str_repeat('x', 1024 * 1024);
        }
    }

    // A transaction left open by the error.
    public function transaction(): void
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        $db->prepare("INSERT INTO login_attempts (identifier, attempts) VALUES ('error-test-transaction', 1)")
           ->execute();
        throw new RuntimeException('Fault: transaction left open');
    }

    // A warning, and then the page carries on.
    public function warning(): void
    {
        $empty = [];
        $value = $empty['missing'];
        echo 'WARNING-PAGE-COMPLETE' . (string) $value;
    }
}
