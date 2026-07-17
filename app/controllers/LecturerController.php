<?php
class LecturerController extends Controller
{
    public function __construct()
    {
        RoleGuard::require(['lecturer']);
    }

  public function dashboard(): void
    {
        $lecturerId = (int) Auth::user()['id'];
        $courses = (new Course())->byLecturer($lecturerId);

        $this->view('lecturer/dashboard', [
            'user'    => Auth::user(),
            'courses' => $courses,
        ]);
    }
}