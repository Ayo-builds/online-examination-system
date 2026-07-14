<?php
class LecturerController extends Controller
{
    public function __construct()
    {
        RoleGuard::require(['lecturer']);
    }

    public function dashboard(): void
    {
        $this->view('lecturer/dashboard', ['user' => Auth::user()]);
    }
}