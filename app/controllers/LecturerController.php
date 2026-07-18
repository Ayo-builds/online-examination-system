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

    // GET /lecturer/createEssay/{courseId}
    public function createEssay(string $courseId = ''): void
    {
        $courseId   = (int) $courseId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $this->view('lecturer/create_essay', ['course' => $course]);
    }

    // POST /lecturer/storeEssay/{courseId}
    public function storeEssay(string $courseId = ''): void
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

        $errors = [];

        if ($questionText === '') {
            $errors[] = 'Question text is required.';
        }
        if ($marks <= 0 || $marks > 100) {
            $errors[] = 'Marks must be between 0.5 and 100.';
        }

        if (!empty($errors)) {
            $this->view('lecturer/create_essay', [
                'course' => $course,
                'errors' => $errors,
                'old'    => ['question_text' => $questionText, 'marks' => $marks],
            ]);
            return;
        }

        (new Question())->createEssay($courseId, $questionText, $marks, $lecturerId);

        $this->redirect('lecturer/questions/' . $courseId);
    }

    // GET /lecturer/question/{courseId}/{questionId}
    public function question(string $courseId = '', string $questionId = ''): void
    {
        $courseId   = (int) $courseId;
        $questionId = (int) $questionId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $question = (new Question())->findWithOptions($questionId, $courseId);
        if ($question === null) {
            http_response_code(404);
            exit('404 — Question not found.');
        }

        $this->view('lecturer/question_detail', [
            'course'   => $course,
            'question' => $question,
            'inUse'    => (new Question())->isInUse($questionId),
        ]);
    }

    // POST /lecturer/deleteQuestion/{courseId}/{questionId}
    public function deleteQuestion(string $courseId = '', string $questionId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('lecturer/dashboard');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('lecturer/dashboard');
        }

        $courseId   = (int) $courseId;
        $questionId = (int) $questionId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $questionModel = new Question();

        $question = $questionModel->findWithOptions($questionId, $courseId);
        if ($question === null) {
            http_response_code(404);
            exit('404 — Question not found.');
        }

        if ($questionModel->isInUse($questionId)) {
            // Refuse: it's part of an exam or answered attempt
            $this->redirect('lecturer/question/' . $courseId . '/' . $questionId);
        }

        $questionModel->delete($questionId);

        $this->redirect('lecturer/questions/' . $courseId);
    }

    // GET /lecturer/exams/{courseId}
    public function exams(string $courseId = ''): void
    {
        $courseId   = (int) $courseId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $this->view('lecturer/exams', [
            'course' => $course,
            'exams'  => (new Exam())->byCourse($courseId),
        ]);
    }

    // GET /lecturer/createExam/{courseId} + POST /lecturer/storeExam/{courseId}
    public function createExam(string $courseId = ''): void
    {
        $courseId   = (int) $courseId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $this->view('lecturer/create_exam', ['course' => $course]);
    }

    public function storeExam(string $courseId = ''): void
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

        $title        = trim($_POST['title'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $duration     = (int) ($_POST['duration_minutes'] ?? 0);
        $windowStart  = trim($_POST['window_start'] ?? '');
        $windowEnd    = trim($_POST['window_end'] ?? '');
        $perAttempt   = (int) ($_POST['questions_per_attempt'] ?? 0);
        $passMark     = (float) ($_POST['pass_mark'] ?? 0);

        $errors = [];

        if ($title === '' || mb_strlen($title) > 150) {
            $errors[] = 'Title is required (max 150 characters).';
        }
        if ($duration < 5 || $duration > 300) {
            $errors[] = 'Duration must be between 5 and 300 minutes.';
        }
        if ($perAttempt < 1) {
            $errors[] = 'Questions per attempt must be at least 1.';
        }
        if ($passMark < 0 || $passMark > 100) {
            $errors[] = 'Pass mark must be between 0 and 100.';
        }

        // Window validation: parse the datetime-local values
        $startTs = strtotime($windowStart);
        $endTs   = strtotime($windowEnd);

        if ($startTs === false || $endTs === false) {
            $errors[] = 'Both window dates are required.';
        } elseif ($endTs <= $startTs) {
            $errors[] = 'Window end must be after window start.';
        } elseif ($endTs <= time()) {
            $errors[] = 'Window end must be in the future.';
        }

        if (!empty($errors)) {
            $this->view('lecturer/create_exam', [
                'course' => $course,
                'errors' => $errors,
                'old'    => [
                    'title' => $title, 'instructions' => $instructions,
                    'duration_minutes' => $duration,
                    'window_start' => $windowStart, 'window_end' => $windowEnd,
                    'questions_per_attempt' => $perAttempt, 'pass_mark' => $passMark,
                ],
            ]);
            return;
        }

        $examId = (new Exam())->create(
            $courseId, $title, $instructions, $duration,
            date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs),
            $perAttempt, $passMark
        );

        $this->redirect('lecturer/examPool/' . $courseId . '/' . $examId);
    }
}