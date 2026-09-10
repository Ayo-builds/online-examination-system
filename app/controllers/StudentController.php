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

    // GET /student/attempt/{examId}
    //
    // The instructions page that precedes an attempt. It is read-only: it
    // starts nothing and writes nothing. The button on it posts to startExam,
    // which still owns every gate that decides whether an attempt may begin.
    public function attempt(string $examId = ''): void
    {
        $examId    = (int) $examId;
        $studentId = (int) Auth::user()['id'];

        $exam = (new Exam())->find($examId);
        if ($exam === null || $exam['status'] !== 'published') {
            $this->redirect('student/dashboard');
        }

        if (!(new Enrollment())->isEnrolled($studentId, (int) $exam['course_id'])) {
            http_response_code(403);
            exit('403. You are not enrolled in this course.');
        }

        // An attempt already under way goes back to the paper; a finished one
        // goes to the review. Only a student with no attempt sees this page.
        $existing = (new Attempt())->findByExamAndStudent($examId, $studentId);
        if ($existing !== null) {
            $this->redirect($existing['status'] === 'in_progress'
                ? 'student/exam/' . (int) $existing['id']
                : 'student/result/' . (int) $existing['id']);
        }

        $now    = time();
        $course = (new Course())->find((int) $exam['course_id']);

        $this->view('student/attempt', [
            'user'   => Auth::user(),
            'exam'   => $exam,
            'course' => $course,
            'now'    => $now,
            'open'   => $now >= strtotime($exam['window_start'])
                     && $now <= strtotime($exam['window_end']),
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
            exit('403. You are not enrolled in this course.');
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
                // Already started, so just continue
                $this->redirect('student/exam/' . (int) $existing['id']);
            }
            // Submitted/auto-submitted/invalidated: no second attempt
            $this->redirect('student/dashboard');
        }

        // All gates passed, so create the snapshot
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
            exit('404. Attempt not found.');
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

    // POST /student/saveAnswer/{attemptId}. AJAX, returns JSON
    public function saveAnswer(string $attemptId = ''): void
    {
        // This is an AJAX endpoint: always answer in JSON, even on failure.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'error' => 'method'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->json(['ok' => false, 'error' => 'csrf'], 403);
        }

        $attemptId = (int) $attemptId;
        $studentId = (int) Auth::user()['id'];

        $attemptModel = new Attempt();
        $attempt = $attemptModel->findOwned($attemptId, $studentId);

        if ($attempt === null) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        // Still open? (status + server deadline)
        if ($attempt['status'] !== 'in_progress'
            || strtotime($attempt['deadline_at']) <= time()) {
            $this->json(['ok' => false, 'error' => 'closed'], 409);
        }

        $questionId = (int) ($_POST['question_id'] ?? 0);

        // The question must be on THIS attempt's paper
        if (!$attemptModel->questionInAttempt($attemptId, $questionId)) {
            $this->json(['ok' => false, 'error' => 'bad_question'], 422);
        }

        $optionId  = isset($_POST['option_id']) && $_POST['option_id'] !== ''
                        ? (int) $_POST['option_id'] : null;
        $essayText = isset($_POST['essay_text'])
                        ? trim((string) $_POST['essay_text']) : null;

        // If an option was given, it must belong to this question
        if ($optionId !== null
            && !(new Question())->optionBelongsToQuestion($optionId, $questionId)) {
            $this->json(['ok' => false, 'error' => 'bad_option'], 422);
        }

        $attemptModel->saveAnswer($attemptId, $questionId, $optionId, $essayText);

        $this->json(['ok' => true, 'saved_at' => date('H:i:s')]);
    }


    // GET /student/sessionToken. AJAX, returns JSON
    //
    // Only reachable with a live student session, because the constructor's
    // RoleGuard redirects anyone else to the login page. That is exactly the
    // property that makes it useful: after a session dies mid-exam and the
    // student signs in again in another tab, the exam tab still holds the dead
    // token and every retry would fail forever. This hands it the live one.
    //
    // It discloses nothing to an attacker. Reading the response requires the
    // session cookie AND a same-origin request; a cross-origin script is
    // refused the body by the browser, and CSRF itself is unaffected because
    // a forged POST still cannot read this.
    public function sessionToken(): void
    {
        $this->json(['ok' => true, 'csrf_token' => Csrf::token()]);
    }

    // POST /student/logActivity/{attemptId}. AJAX, returns JSON
    public function logActivity(string $attemptId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->json(['ok' => false], 403);
        }

        $attemptId = (int) $attemptId;
        $studentId = (int) Auth::user()['id'];

        $attemptModel = new Attempt();
        $attempt = $attemptModel->findOwned($attemptId, $studentId);

        if ($attempt === null) {
            $this->json(['ok' => false], 404);
        }
        // Only log during a live attempt
        if ($attempt['status'] !== 'in_progress') {
            $this->json(['ok' => false, 'error' => 'closed'], 409);
        }

        // Whitelist the event types we accept
        $allowed = ['tab_switch', 'fullscreen_exit', 'window_blur',
                    'copy', 'paste', 'right_click', 'heartbeat_gap'];
        $eventType = $_POST['event_type'] ?? '';

        if (!in_array($eventType, $allowed, true)) {
            $this->json(['ok' => false, 'error' => 'bad_event'], 422);
        }

        $logModel = new ActivityLog();
        $logModel->record($attemptId, $eventType, ['ua' => $_SERVER['HTTP_USER_AGENT'] ?? '']);

        // Cross the threshold → flag the attempt for lecturer review
        $counts = $logModel->countByType($attemptId);
        $suspicious = 0;
        foreach ($counts as $c) {
            if (in_array($c['event_type'], ['tab_switch', 'window_blur', 'fullscreen_exit'], true)) {
                $suspicious += (int) $c['total'];
            }
        }

        if ($suspicious >= FLAG_THRESHOLD) {
            $attemptModel->setFlagged($attemptId, true);
        }

        $this->json(['ok' => true, 'suspicious' => $suspicious, 'flagged' => $suspicious >= FLAG_THRESHOLD]);
    }

    // POST /student/submitExam/{attemptId}
    public function submitExam(string $attemptId = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('student/dashboard');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->redirect('student/dashboard');
        }

        $attemptId = (int) $attemptId;
        $studentId = (int) Auth::user()['id'];

        $attemptModel = new Attempt();
        $attempt = $attemptModel->findOwned($attemptId, $studentId);

        if ($attempt === null) {
            http_response_code(404);
            exit('404. Attempt not found.');
        }

        // How many answers the browser still had outstanding. Reported by the
        // client, so it is clamped to the size of this paper and treated as a
        // diagnostic for the school rather than as evidence: nothing reads it
        // to compute or withhold a score. See migration 005.
        $unsaved = max(0, (int) ($_POST['unsaved_count'] ?? 0));
        $unsaved = min($unsaved, $attemptModel->questionCount($attemptId));

        // Only an in-progress attempt can be submitted
        if ($attempt['status'] === 'in_progress') {
            // Deadline passed? Grade as auto_submitted; else a normal submit.
            $status = strtotime($attempt['deadline_at']) <= time()
                    ? 'auto_submitted' : 'submitted';
            $attemptModel->submitAndGrade($attemptId, $status, $unsaved);
        }

        $this->redirect('student/result/' . $attemptId);
    }

    // GET /student/result/{attemptId}
    public function result(string $attemptId = ''): void
    {
        $attemptId = (int) $attemptId;
        $studentId = (int) Auth::user()['id'];

        $attemptModel = new Attempt();
        $attempt = $attemptModel->findOwned($attemptId, $studentId);

        if ($attempt === null || $attempt['status'] === 'in_progress') {
            $this->redirect('student/dashboard');
        }

        $exam = (new Exam())->find((int) $attempt['exam_id']);

        // Correct answers stay hidden until the whole window has closed.
        // Students sit at different times inside a window, so revealing them at
        // submission would hand the first finisher an answer key for everyone
        // still to sit the paper.
        $canReview = $exam !== null && time() > strtotime($exam['window_end']);

        $this->view('student/result', [
            'attempt'    => $attempt,
            'exam'       => $exam,
            'answers'    => $attemptModel->reviewForAttempt($attemptId),
            'can_review' => $canReview,
            'max_marks'  => $attemptModel->maxMarks($attemptId),
        ]);
    }
}