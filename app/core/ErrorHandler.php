<?php
/**
 * The one place an error nobody caught is turned into a safe answer.
 *
 * Installed on the first lines of public/index.php, before the session and
 * the config, so nothing that runs after it can fail unhandled:
 *
 *  - an uncaught exception (set_exception_handler),
 *  - a real fatal error, which no exception handler sees: memory exhausted, a
 *    class that cannot be compiled (register_shutdown_function),
 *  - a warning, notice or deprecation, which is only logged. PHP then carries
 *    on exactly as it did before this class existed, so nothing that works
 *    today starts failing because of it.
 *
 * Every error gets a short id, XXXX-XXXX, that is both shown to the user and
 * written on its log line, so a Teacher can read it out and the line can be
 * found. What the user sees depends on the route, decided from the URL before
 * any controller runs:
 *
 *  - a JSON route answers 500 {"ok":false,"error":"server","id":...} and never
 *    anything more, not even on a developer's laptop;
 *  - every other route answers 500 with a plain page carrying the id, plus the
 *    details only when config says DISPLAY_ERRORS is true.
 *
 * Written for PHP 8.0 and checked on 8.5: no enums, readonly, never or
 * first-class callables, and no output-buffer callbacks.
 */
final class ErrorHandler
{
    /**
     * The routes whose callers read JSON, as Router::routeKey() spells them.
     * tests/error_test.php (E4) fails if an action that calls $this->json() is
     * missing here, or if one listed here no longer exists.
     */
    public const JSON_ROUTES = [
        'student/paper',
        'student/saveanswer',
        'student/lock',
        'student/heartbeat',
        'student/event',
        'student/sessiontoken',
    ];

    /** Errors that end the script. PHP hands these only to a shutdown function. */
    private const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR
                              | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /** Password.php's alphabet: no 0/O or 1/I/L, so the id survives being read aloud. */
    private const ID_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** At this size the log is moved aside to .1 (one old file is kept). */
    private const ROTATE_BYTES = 5 * 1024 * 1024;

    private const MESSAGE_MAX = 1000;

    private static bool $json = false;
    private static string $url = '';
    private static bool $answered = false;

    /** Freed at shutdown, so a memory-exhaustion fatal still has room to answer. */
    private static ?string $reserve = null;

    public static function install(string $url): void
    {
        self::$url  = $url;
        self::$json = in_array(Router::routeKey($url), self::JSON_ROUTES, true);

        // Hidden until config says otherwise, whatever this server's php.ini
        // says, so an error during bootstrap never shows details on a server.
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        // Ours, not php.ini's output_buffering, so a half-rendered page can be
        // thrown away the same way on every host. No callback: PHP 8.5
        // deprecates output handlers that misbehave, and none is needed.
        ob_start();

        self::$reserve = str_repeat(' ', 32768);

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /** Called once config is loaded: only an explicit true shows details. */
    public static function applyConfig(): void
    {
        ini_set('display_errors', self::displayErrors() ? '1' : '0');
    }

    public static function handleException(Throwable $e): void
    {
        if (self::$answered) {
            return;
        }
        self::$answered = true;

        $id = self::newId();
        $tx = self::rollBack();

        self::log($id, 'error', get_class($e), self::describe($e), $e->getFile(), $e->getLine(), $tx);
        self::respond($id, self::displayErrors() ? self::details($e) : null);
    }

    /**
     * Warnings, notices and deprecations: logged, never turned into
     * exceptions. Returning false hands them back to PHP, which shows them
     * where display is on and writes its own log, as it always has.
     */
    public static function handleError(int $type, string $message, string $file, int $line): bool
    {
        // Silenced with @ or masked by error_reporting: not ours to report.
        if ((error_reporting() & $type) === 0) {
            return false;
        }
        // The fatal kinds that pass through here end the script; the shutdown
        // function logs them once, with the id the user is shown.
        if (($type & self::FATAL_TYPES) !== 0) {
            return false;
        }

        self::log(self::newId(), self::levelOf($type), self::typeName($type), $message, $file, $line, null);
        return false;
    }

    public static function handleShutdown(): void
    {
        self::$reserve = null;

        $error = error_get_last();
        if ($error === null || ($error['type'] & self::FATAL_TYPES) === 0 || self::$answered) {
            return;
        }
        self::$answered = true;

        // Memory ran out: give the answer a little room of its own.
        if (strpos($error['message'], 'Allowed memory size') === 0) {
            @ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1024 * 1024));
        }

        // PHP 8.5 can append a backtrace to a fatal's message. The log keeps
        // the message itself; the trace stays out of the one-line format.
        $message = explode("\n", $error['message'], 2)[0];

        $id = self::newId();
        $tx = self::rollBack();

        self::log($id, 'fatal', self::typeName($error['type']), $message, $error['file'], $error['line'], $tx);
        self::respond($id, self::displayErrors()
            ? self::typeName($error['type']) . ': ' . $error['message'] . "\n" . $error['file'] . ':' . $error['line']
            : null);
    }

    // ---- The answer ----------------------------------------------------------

    private static function respond(string $id, ?string $details): void
    {
        // Throw away everything already written, including the nested buffers
        // views open for themselves, so the user never gets half a page.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(500);
            // What the failed request meant to send is void. The session cookie
            // and the security headers stay.
            foreach (['Location', 'Content-Type', 'Content-Disposition', 'Content-Length', 'Refresh'] as $name) {
                header_remove($name);
            }
            header('Cache-Control: no-store');
            header(self::$json ? 'Content-Type: application/json' : 'Content-Type: text/html; charset=UTF-8');
        }

        if (self::$json) {
            echo json_encode(['ok' => false, 'error' => 'server', 'id' => $id]);
            return;
        }

        // Something already went out, so the status and headers are final.
        // Add the id and nothing else.
        if (headers_sent()) {
            echo "\n<p>Something went wrong on our side. It wasn't anything you did. "
               . 'If it keeps happening, report this code: ' . $id . ".</p>\n";
            return;
        }

        try {
            echo self::renderPage($id, $details);
        } catch (Throwable $pageFailed) {
            // The error page itself failed. Log that under the same id, and
            // send a page that depends on nothing at all.
            self::log($id, 'error', 'ErrorPageFailed', self::describe($pageFailed),
                $pageFailed->getFile(), $pageFailed->getLine(), null);
            echo self::fallbackPage($id);
        }
    }

    private static function renderPage(string $id, ?string $details): string
    {
        $errorId      = $id;
        $errorDetails = $details;

        ob_start();
        try {
            require APP_ROOT . '/app/views/error_500.php';
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    private static function fallbackPage(string $id): string
    {
        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head><meta charset=\"UTF-8\"><title>Something went wrong</title></head>\n"
             . "<body>\n<p>Something went wrong on our side. It wasn't anything you did. "
             . 'If it keeps happening, report this code: <strong>' . $id . "</strong>.</p>\n</body>\n</html>\n";
    }

    // ---- The log -------------------------------------------------------------

    /**
     * One line per error:
     *   2026-09-22T10:40:10+01:00 K7QM-4XPA error PDOException "…" app/models/Attempt.php:351 | POST student/heartbeat/9 | user 38 | tx=rolled back
     *
     * Never the request body or query string: no essays, no passwords.
     * Writing it can never become a second error.
     */
    private static function log(string $id, string $level, string $kind, string $message,
                                string $file, int $line, ?string $tx): void
    {
        $message = preg_replace('/\s*\R\s*/', ' ', $message) ?? $message;
        if (strlen($message) > self::MESSAGE_MAX) {
            $message = substr($message, 0, self::MESSAGE_MAX) . '…';
        }

        $user = isset($_SESSION) && isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : '-';

        $entry = sprintf(
            '%s %s %s %s %s %s:%d | %s %s | user %s%s',
            date('c'),
            $id,
            $level,
            $kind,
            json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            self::relative($file),
            $line,
            $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            '/' . ltrim(self::$url, '/'),
            $user,
            $tx === null ? '' : ' | tx=' . $tx
        );

        $file = self::logFile();
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        clearstatcache(true, $file);
        if (is_file($file) && (int) @filesize($file) >= self::ROTATE_BYTES) {
            @rename($file, $file . '.1');
        }

        if (@file_put_contents($file, $entry . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log($entry);
        }
    }

    private static function logFile(): string
    {
        if (defined('ERROR_LOG_FILE') && is_string(ERROR_LOG_FILE) && ERROR_LOG_FILE !== '') {
            return ERROR_LOG_FILE;
        }
        return APP_ROOT . '/logs/app-errors.log';
    }

    // ---- Small parts ---------------------------------------------------------

    private static function displayErrors(): bool
    {
        return defined('DISPLAY_ERRORS') && DISPLAY_ERRORS === true;
    }

    /**
     * An open transaction is rolled back. MySQL would do the same when the
     * connection closes at the end of the request; doing it here frees row
     * locks before the page goes out, and the log says a transaction was left
     * open, which points at code that forgot its own rollback. Never opens a
     * connection that does not already exist.
     */
    private static function rollBack(): string
    {
        if (!class_exists('Database', false)) {
            return 'none';
        }
        return Database::rollBackIfOpen();
    }

    private static function newId(): string
    {
        $max = strlen(self::ID_ALPHABET) - 1;
        $id  = '';
        for ($i = 0; $i < 8; $i++) {
            $id .= self::ID_ALPHABET[random_int(0, $max)];
        }
        return substr($id, 0, 4) . '-' . substr($id, 4);
    }

    /** The message, with whatever caused it. */
    private static function describe(Throwable $e): string
    {
        $text = $e->getMessage();
        for ($cause = $e->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $text .= ' (caused by ' . get_class($cause) . ': ' . $cause->getMessage() . ')';
        }
        return $text;
    }

    private static function details(Throwable $e): string
    {
        $text = '';
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $text .= ($text === '' ? '' : "\n\nCaused by: ")
                   . get_class($t) . ': ' . $t->getMessage() . "\n"
                   . $t->getFile() . ':' . $t->getLine() . "\n"
                   . $t->getTraceAsString();
        }
        return $text;
    }

    private static function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $root = str_replace('\\', '/', APP_ROOT) . '/';
        return stripos($file, $root) === 0 ? substr($file, strlen($root)) : $file;
    }

    private static function levelOf(int $type): string
    {
        if (($type & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) !== 0) {
            return 'warning';
        }
        if (($type & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
            return 'deprecated';
        }
        return 'notice';
    }

    private static function typeName(int $type): string
    {
        $names = [
            E_ERROR => 'E_ERROR', E_PARSE => 'E_PARSE', E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR', E_USER_ERROR => 'E_USER_ERROR',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', E_WARNING => 'E_WARNING',
            E_USER_WARNING => 'E_USER_WARNING', E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_NOTICE => 'E_NOTICE',
            E_USER_NOTICE => 'E_USER_NOTICE', E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];
        return $names[$type] ?? 'E_' . $type;
    }
}
