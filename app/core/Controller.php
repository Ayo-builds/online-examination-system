<?php
abstract class Controller
{
    // Render a full HTML page from app/views/
    protected function view(string $path, array $data = []): void
    {
        extract($data);   // ['exam' => $exam] becomes a local $exam variable

        $file = APP_ROOT . '/app/views/' . $path . '.php';

        if (!file_exists($file)) {
            http_response_code(500);
            exit('View not found: ' . htmlspecialchars($path));
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