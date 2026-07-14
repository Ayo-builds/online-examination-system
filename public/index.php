<?php
declare(strict_types=1);

session_start();

// APP_ROOT = the exam-system folder itself (one level UP from /public)
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/config.php';

// ---- Autoloader: loads class files on demand ----
spl_autoload_register(function (string $class) {
    $paths = [
        APP_ROOT . '/app/core/' . $class . '.php',
        APP_ROOT . '/app/controllers/' . $class . '.php',
        APP_ROOT . '/app/models/' . $class . '.php',
        APP_ROOT . '/app/middleware/' . $class . '.php',
    ];

    foreach ($paths as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ---- Hand the request to the Router ----
$router = new Router();
$router->dispatch($_GET['url'] ?? '');