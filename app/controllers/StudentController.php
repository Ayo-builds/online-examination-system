<?php
class StudentController extends Controller
{
    public function __construct()
    {
        RoleGuard::require(['student']);
    }

   public function dashboard(): void
    {
        $studentId = (int) Auth::user()['id'];

        $this->view('student/dashboard', [
            'user'  => Auth::user(),
            'exams' => (new Exam())->availableForStudent($studentId),
            'now'   => time(),
        ]);
    }
}