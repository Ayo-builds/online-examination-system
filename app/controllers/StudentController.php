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

    // POST /student/startExam/{examId}
    public function startExam(string $examId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('student/dashboard');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('student/dashboard');
        }

        $examId    = (int) $examId;
        $studentId = (int) Auth::user()['id'];

        // 1. The exam must exist and be published
        $exam = (new Exam())->find($examId);
        if ($exam === null || $exam['status'] !== 'published') {
            $this->redirect('student/dashboard');
        }

        // 2. The student must be enrolled in its course
        if (!(new Enrollment())->isEnrolled($studentId, (int) $exam['course_id'])) {
            http_response_code(403);
            exit('403 — You are not enrolled in this course.');
        }

        // 3. The window must be open right now
        $now = time();
        if ($now < strtotime($exam['window_start']) || $now > strtotime($exam['window_end'])) {
            $this->redirect('student/dashboard');
        }

        // 4. No existing attempt (one per student per exam)
        $attemptModel = new Attempt();
        $existing = $attemptModel->findByExamAndStudent($examId, $studentId);

        if ($existing !== null) {
            if ($existing['status'] === 'in_progress') {
                // Already started — just continue
                $this->redirect('student/exam/' . (int) $existing['id']);
            }
            // Submitted/auto-submitted/invalidated: no second attempt
            $this->redirect('student/dashboard');
        }

        // All gates passed — create the snapshot
        $attemptId = $attemptModel->start($examId, $studentId, $exam);

        $this->redirect('student/exam/' . $attemptId);
    }

     // GET /student/exam/{attemptId}
    public function exam(string $attemptId = ''): void
    {
        $attemptId = (int) $attemptId;
        $studentId = (int) Auth::user()['id'];

        $attemptModel = new Attempt();
        $attempt = $attemptModel->findOwned($attemptId, $studentId);

        if ($attempt === null) {
            http_response_code(404);
            exit('404 — Attempt not found.');
        }

        // Already finished? Send to the (future) result page, not the exam
        if ($attempt['status'] !== 'in_progress') {
            $this->redirect('student/dashboard');
        }

        // Past the server deadline? Auto-submit instead of showing questions
        if (strtotime($attempt['deadline_at']) <= time()) {
            $attemptModel->autoSubmit($attemptId);
            $this->redirect('student/dashboard');
        }

        $this->view('student/exam', [
            'attempt'   => $attempt,
            'questions' => $attemptModel->questionsForAttempt($attemptId),
            'remaining' => strtotime($attempt['deadline_at']) - time(),  // seconds left
        ]);
    }
}