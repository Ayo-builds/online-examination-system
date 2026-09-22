<?php
abstract class Controller
{
    // Render a full HTML page from app/views/
    protected function view(string $path, array $data = []): void
    {
        extract($data);   // ['exam' => $exam] becomes a local $exam variable

        $file = APP_ROOT . '/app/views/' . $path . '.php';

        // A missing view is a bug: ErrorHandler logs the path and shows the
        // user an error id, never the path itself.
        if (!file_exists($file)) {
            throw new RuntimeException('View not found: ' . $path);
        }

        require $file;
    }

    // Send a JSON response (for AJAX endpoints: auto-save, heartbeat, logging)
    protected function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    // Redirect to another route within the app
    protected function redirect(string $path): void
    {
        header('Location: ' . BASE_URL . ltrim($path, '/'));
        exit;
    }
}