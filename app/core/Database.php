<?php
class Database
{
    private static ?PDO $instance = null;

    // Private constructor: nobody outside can do "new Database()"
    private function __construct() {}

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                // Pin which wall clock NOW() reads: West Africa Time, +01:00.
                //
                // Every deadline and window decision compares a stored
                // DATETIME with NOW() in SQL (step 4b). A DATETIME carries no
                // time zone: it means whatever NOW() meant when it was written,
                // and teachers type windows in Lagos time. So NOW() must be
                // Lagos time on every server, whatever that server's own zone
                // setting says. Only its actual time must be right. Added on
                // 20 Jul 2026 (b861c45) after PHP and MySQL disagreed on a
                // deadline; a shared host whose MySQL runs on UTC is an hour
                // behind without it.
                //
                // An offset, not 'Africa/Lagos': a named zone needs MySQL's
                // time-zone tables, which XAMPP and most shared hosts lack.
                // Nigeria has no daylight saving, so +01:00 is always exact.
                // tests/clock_test.php (C11) fails if this line goes.
                self::$instance->exec("SET time_zone = '+01:00'");
            } catch (PDOException $e) {
                // Thrown, not echoed: ErrorHandler answers in the route's own
                // shape (a JSON route gets JSON) and logs the PDO reason as the
                // cause. The browser never sees it; it can name the account.
                throw new RuntimeException('Database connection failed.', 0, $e);
            }
        }
        return self::$instance;
    }

    /**
     * For ErrorHandler: roll back a transaction left open by a request that
     * failed. Never opens a connection, and never throws.
     *
     * @return string 'none', 'rolled back' or 'rollback failed', for the log
     */
    public static function rollBackIfOpen(): string
    {
        if (self::$instance === null) {
            return 'none';
        }
        try {
            if (!self::$instance->inTransaction()) {
                return 'none';
            }
            self::$instance->rollBack();
            return 'rolled back';
        } catch (Throwable $e) {
            return 'rollback failed';
        }
    }
}