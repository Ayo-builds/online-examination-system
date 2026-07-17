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

    // GET /lecturer/createMcq/{courseId}
    public function createMcq(string $courseId = ''): void
    {
        $courseId   = (int) $courseId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $this->view('lecturer/create_mcq', ['course' => $course]);
    }

    // POST /lecturer/storeMcq/{courseId}
    public function storeMcq(string $courseId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('lecturer/dashboard');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('lecturer/dashboard');
        }

        $courseId   = (int) $courseId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $questionText = trim($_POST['question_text'] ?? '');
        $marks        = (float) ($_POST['marks'] ?? 0);
        $options      = $_POST['options'] ?? [];
        $correctIndex = (int) ($_POST['correct'] ?? -1);

        $errors = [];

        if ($questionText === '') {
            $errors[] = 'Question text is required.';
        }
        if ($marks <= 0 || $marks > 100) {
            $errors[] = 'Marks must be between 0.5 and 100.';
        }

        // Options: expect exactly 4, all non-empty
        if (!is_array($options) || count($options) !== 4) {
            $errors[] = 'Exactly four options are required.';
        } else {
            $options = array_map(fn($o) => trim((string) $o), $options);
            foreach ($options as $i => $opt) {
                if ($opt === '') {
                    $errors[] = 'Option ' . chr(65 + $i) . ' cannot be empty.';
                }
            }
            if (count(array_unique($options)) !== count($options)) {
                $errors[] = 'Options must be different from each other.';
            }
        }

        if ($correctIndex < 0 || $correctIndex > 3) {
            $errors[] = 'Please mark which option is correct.';
        }

        if (!empty($errors)) {
            $this->view('lecturer/create_mcq', [
                'course' => $course,
                'errors' => $errors,
                'old'    => [
                    'question_text' => $questionText,
                    'marks'         => $marks,
                    'options'       => $options,
                    'correct'       => $correctIndex,
                ],
            ]);
            return;
        }

        (new Question())->createMcq(
            $courseId, $questionText, $marks, $lecturerId, $options, $correctIndex
        );

        $this->redirect('lecturer/questions/' . $courseId);
    }
}