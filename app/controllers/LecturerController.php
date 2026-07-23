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

    // GET /lecturer/examPool/{courseId}/{examId}
    public function examPool(string $courseId = '', string $examId = ''): void
    {
        $courseId   = (int) $courseId;
        $examId     = (int) $examId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $exam = (new Exam())->findInCourse($examId, $courseId);
        if ($exam === null) {
            http_response_code(404);
            exit('404 — Exam not found.');
        }

        $this->view('lecturer/exam_pool', [
            'course'      => $course,
            'exam'        => $exam,
            'questions'   => (new Question())->byCourse($courseId),
            'poolIds'     => (new ExamPool())->questionIds($examId),
        ]);
    }

    // POST /lecturer/savePool/{courseId}/{examId}
    public function savePool(string $courseId = '', string $examId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('lecturer/dashboard');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('lecturer/dashboard');
        }

        $courseId   = (int) $courseId;
        $examId     = (int) $examId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $exam = (new Exam())->findInCourse($examId, $courseId);
        if ($exam === null) {
            http_response_code(404);
            exit('404 — Exam not found.');
        }

        // Pool is frozen once the exam leaves draft
        if ($exam['status'] !== 'draft') {
            $this->redirect('lecturer/examPool/' . $courseId . '/' . $examId);
        }

        $submitted = $_POST['question_ids'] ?? [];
        if (!is_array($submitted)) {
            $submitted = [];
        }

        // Keep only ids that genuinely belong to this course's bank
        $validIds = array_map(
            fn($q) => (int) $q['id'],
            (new Question())->byCourse($courseId)
        );

        $cleanIds = array_values(array_intersect(
            array_map('intval', $submitted),
            $validIds
        ));

        (new ExamPool())->replace($examId, $cleanIds);

        $this->redirect('lecturer/examPool/' . $courseId . '/' . $examId);
    }

    // POST /lecturer/publishExam/{courseId}/{examId}
    public function publishExam(string $courseId = '', string $examId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('lecturer/dashboard');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('lecturer/dashboard');
        }

        $courseId   = (int) $courseId;
        $examId     = (int) $examId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $examModel = new Exam();
        $exam = $examModel->findInCourse($examId, $courseId);
        if ($exam === null) {
            http_response_code(404);
            exit('404 — Exam not found.');
        }

        $errors = [];

        if ($exam['status'] !== 'draft') {
            $errors[] = 'Only draft exams can be published.';
        }

        $poolCount = (new ExamPool())->countForExam($examId);
        if ($poolCount < (int) $exam['questions_per_attempt']) {
            $errors[] = 'Pool has ' . $poolCount . ' question(s) but the exam draws '
                      . (int) $exam['questions_per_attempt'] . ' — add more to the pool.';
        }

        if (strtotime($exam['window_end']) <= time()) {
            $errors[] = 'The exam window has already ended — edit the window or create a new exam.';
        }

        if (!empty($errors)) {
            $this->view('lecturer/exam_pool', [
                'course'    => $course,
                'exam'      => $exam,
                'questions' => (new Question())->byCourse($courseId),
                'poolIds'   => (new ExamPool())->questionIds($examId),
                'errors'    => $errors,
            ]);
            return;
        }

        $examModel->setStatus($examId, 'published');

        $this->redirect('lecturer/exams/' . $courseId);
    }

    // POST /lecturer/closeExam/{courseId}/{examId}
    public function closeExam(string $courseId = '', string $examId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('lecturer/dashboard');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('lecturer/dashboard');
        }

        $courseId   = (int) $courseId;
        $examId     = (int) $examId;
        $lecturerId = (int) Auth::user()['id'];

        $course = (new Course())->findOwned($courseId, $lecturerId);
        if ($course === null) {
            http_response_code(404);
            exit('404 — Course not found.');
        }

        $examModel = new Exam();
        $exam = $examModel->findInCourse($examId, $courseId);
        if ($exam === null) {
            http_response_code(404);
            exit('404 — Exam not found.');
        }

        if ($exam['status'] === 'published') {
            $examModel->setStatus($examId, 'closed');
        }

        $this->redirect('lecturer/exams/' . $courseId);
    }

    // GET /lecturer/grading
    public function grading(): void
    {
        $lecturerId = (int) Auth::user()['id'];

        $this->view('lecturer/grading', [
            'attempts' => (new Attempt())->forLecturer($lecturerId),
        ]);
    }

    // GET /lecturer/gradeAttempt/{attemptId}
    public function gradeAttempt(string $attemptId = ''): void
    {
        $attemptId  = (int) $attemptId;
        $lecturerId = (int) Auth::user()['id'];

        $attemptModel = new Attempt();
        $attempt = $attemptModel->findForLecturer($attemptId, $lecturerId);

        if ($attempt === null) {
            http_response_code(404);
            exit('404 — Attempt not found.');
        }

        $this->view('lecturer/grade_attempt', [
            'attempt'   => $attempt,
            'answers'   => $attemptModel->answersForGrading($attemptId),
            'maxMarks'  => $attemptModel->maxMarks($attemptId),
        ]);
    }

    // POST /lecturer/saveEssayGrade/{attemptId}
    public function saveEssayGrade(string $attemptId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('lecturer/grading');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('lecturer/grading');
        }

        $attemptId  = (int) $attemptId;
        $lecturerId = (int) Auth::user()['id'];

        $attemptModel = new Attempt();
        $attempt = $attemptModel->findForLecturer($attemptId, $lecturerId);

        if ($attempt === null) {
            http_response_code(404);
            exit('404 — Attempt not found.');
        }

        $questionId = (int) ($_POST['question_id'] ?? 0);
        $marks      = (float) ($_POST['marks'] ?? -1);

        // The question must be an essay ON THIS attempt's paper, and marks in range
        $q = $this->query_essayOnPaper($attemptId, $questionId);
        if ($q === null) {
            $this->redirect('lecturer/gradeAttempt/' . $attemptId);
        }
        if ($marks < 0 || $marks > (float) $q['marks']) {
            $this->redirect('lecturer/gradeAttempt/' . $attemptId);
        }

        $attemptModel->gradeEssay($attemptId, $questionId, $marks);

        $this->redirect('lecturer/gradeAttempt/' . $attemptId);
    }

    // Small helper: confirm the question is an essay on this attempt's snapshot
    private function query_essayOnPaper(int $attemptId, int $questionId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT q.marks
             FROM attempt_questions aq
             JOIN questions q ON q.id = aq.question_id
             WHERE aq.attempt_id = ? AND aq.question_id = ? AND q.question_type = 'essay'
             LIMIT 1"
        );
        $stmt->execute([$attemptId, $questionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // GET /lecturer/activity/{attemptId}
    public function activity(string $attemptId = ''): void
    {
        $attemptId  = (int) $attemptId;
        $lecturerId = (int) Auth::user()['id'];

        $attempt = (new Attempt())->findForLecturer($attemptId, $lecturerId);
        if ($attempt === null) {
            http_response_code(404);
            exit('404 — Attempt not found.');
        }

        $logModel = new ActivityLog();

        $this->view('lecturer/activity', [
            'attempt' => $attempt,
            'counts'  => $logModel->countByType($attemptId),
            'events'  => $logModel->forAttempt($attemptId),
        ]);
    }

    // POST /lecturer/reviewFlag/{attemptId}
    public function reviewFlag(string $attemptId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('lecturer/grading');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('lecturer/grading');
        }

        $attemptId  = (int) $attemptId;
        $lecturerId = (int) Auth::user()['id'];

        $attempt = (new Attempt())->findForLecturer($attemptId, $lecturerId);
        if ($attempt === null) {
            http_response_code(404);
            exit('404 — Attempt not found.');
        }

        // 'clear' = decided it's innocent; 'keep' = confirm suspicion
        $decision = $_POST['decision'] ?? '';
        (new Attempt())->reviewFlag($attemptId, $decision === 'keep');

        $this->redirect('lecturer/activity/' . $attemptId);
    }
}