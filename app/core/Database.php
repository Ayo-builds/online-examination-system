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
                // Align MySQL's session clock with PHP's (+01:00 = WAT / Africa/Lagos)
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