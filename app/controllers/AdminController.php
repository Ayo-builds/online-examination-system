<?php
class AdminController extends Controller
{
    public function __construct()
    {
        RoleGuard::require(['admin']);
    }

    public function dashboard(): void
    {
        $this->view('admin/dashboard', ['user' => Auth::user()]);
    }
}