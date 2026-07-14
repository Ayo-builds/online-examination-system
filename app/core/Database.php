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
            } catch (PDOException $e) {
                // Never echo $e->getMessage() to the browser — it can leak credentials
                error_log($e->getMessage());
                exit('Database connection failed.');
            }
        }
        return self::$instance;
    }
}