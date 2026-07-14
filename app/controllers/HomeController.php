<?php
class HomeController extends Controller
{
    public function index(): void
    {
        $this->json([
            'app'    => APP_NAME,
            'status' => 'MVC core online',
            'db'     => Database::getInstance() instanceof PDO ? 'connected' : 'failed',
        ]);
    }
}