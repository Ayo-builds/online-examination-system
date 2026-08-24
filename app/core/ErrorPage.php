<?php
class ErrorPage
{
    public static function show(int $code, string $message): void
    {
        http_response_code($code);

        $file = APP_ROOT . '/app/views/error.php';
        if (file_exists($file)) {
            require $file;
        } else {
            echo $code . '. ' . htmlspecialchars($message);
        }
        exit;
    }
}