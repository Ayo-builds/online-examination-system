<?php
class StudentController extends Controller
{
    public function __construct()
    {
        RoleGuard::require(['student']);
    }

    public function dashboard(): void
    {
        $this->view('student/dashboard', ['user' => Auth::user()]);
    }
}