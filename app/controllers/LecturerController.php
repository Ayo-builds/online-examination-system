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

    // GET /lecturer/questions/{courseId}
    public function questions(string $courseId = ''): void
    {
        $courseId   = (int) $courseId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $questionModel = new Question();

        $this->view('lecturer/questions', [
            'course'    => $course,
            'questions' => $questionModel->byCourse($courseId),
        ]);
    }
}