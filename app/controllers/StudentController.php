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

        // Each exam carries not_yet_open and window_closed, judged by the
        // database's clock. Nothing on this page reads PHP's.
        $this->view('student/dashboard', [
            'user'  => Auth::user(),
            'exams' => (new Exam())->availableForStudent($studentId),
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
            exit('403. You are not enrolled in this subject.');
        }

        // An attempt already under way goes back to the paper; a finished one
        // goes to the review. Only a student with no attempt sees this page.
        $existing = (new Attempt())->findByExamAndStudent($examId, $studentId);
        if ($existing !== null) {
            $this->redirect($existing['status'] === 'in_progress'
                ? 'student/exam/' . (int) $existing['id']
                : 'student/result/' . (int) $existing['id']);
        }

        $course = (new Course())->find((int) $exam['course_id']);

        // The window as the database's clock sees it (Exam::find()).
        $this->view('student/attempt', [
            'user'         => Auth::user(),
            'exam'         => $exam,
            'course'       => $course,
            'not_yet_open' => (int) $exam['not_yet_open'] === 1,
            'open'         => (int) $exam['not_yet_open'] === 0 && (int) $exam['window_closed'] === 0,
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
            exit('403. You are not enrolled in this subject.');
        }

        // 3. The window must be open right now, by the database's clock
        if ((int) $exam['not_yet_open'] === 1 || (int) $exam['window_closed'] === 1) {
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
    //
    // The page around the paper, not the paper. No question or option text is
    // in this response: the page fetches it from paper() once the candidate
    // is in fullscreen. With JavaScript off, all anyone sees is a notice.
    public function exam(string $attemptId = ''): void
    {
        // Never restored from the browser's cache by Back or Forward.
        header('Cache-Control: no-store');

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

        // Past the deadline, by the database's clock? Close it instead of
        // showing the page.
        if ($attemptModel->closeIfExpired($attemptId)) {
            $this->redirect('student/dashboard');
        }

        // The countdown starts from the database's seconds left. The page then
        // only counts down; the server decides everything.
        $this->view('student/exam', [
            'attempt'   => $attempt,
            'remaining' => max(0, (int) $attempt['seconds_left']),
        ]);
    }

    // POST /student/paper/{attemptId}. AJAX, returns JSON
    //
    // The only route by which question text reaches a browser. POST with the
    // CSRF token rather than GET, so the paper cannot be read by typing this
    // URL into the address bar with JavaScript turned off.
    //
    // Only what the candidate's page draws is sent. option_order has already
    // been applied, and nothing that marks an option correct is selected.
    public function paper(string $attemptId = ''): void
    {
        header('Cache-Control: no-store');

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
        if ($attempt['status'] !== 'in_progress') {
            $this->json(['ok' => false, 'error' => 'closed'], 409);
        }

        // Past the deadline: close it here, exactly as exam() would, rather
        // than handing out a paper nobody may still answer.
        if ($attemptModel->closeIfExpired($attemptId)) {
            $this->json(['ok' => false, 'error' => 'closed'], 409);
        }

        // Paused: no questions until an invigilator unlocks it. A refresh, a
        // new tab or another computer asks this same question and gets the
        // same answer, because the pause lives on the attempt.
        if ($attempt['locked_at'] !== null) {
            $this->json(['ok' => false, 'error' => 'locked'], 423);
        }

        $questions = array_map(static fn(array $q): array => [
            'question_id'        => (int) $q['question_id'],
            'display_order'      => (int) $q['display_order'],
            'question_type'      => $q['question_type'],
            'question_text'      => $q['question_text'],
            'marks'              => $q['marks'],
            'options'            => $q['options'],
            'selected_option_id' => $q['selected_option_id'] === null ? null : (int) $q['selected_option_id'],
            'essay_text'         => $q['essay_text'],
        ], $attemptModel->questionsForAttempt($attemptId));

        $this->json([
            'ok'        => true,
            'remaining' => max(0, (int) $attempt['seconds_left']),
            'questions' => $questions,
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

        // Already closed? The deadline itself is checked where the answer is
        // written, on the database's clock (saveAnswerIfWritable).
        if ($attempt['status'] !== 'in_progress') {
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

        // Paused and past the grace: refused as 'locked', which the page must
        // never mistake for 'closed' - that one submits the paper.
        $savedAt = null;
        $outcome = $attemptModel->saveAnswerIfWritable($attemptId, $questionId, $optionId, $essayText, $savedAt);

        if ($outcome === 'locked') {
            $this->json(['ok' => false, 'error' => 'locked'], 423);
        }
        if ($outcome === 'closed') {
            $this->json(['ok' => false, 'error' => 'closed'], 409);
        }

        $this->json(['ok' => true, 'saved_at' => $savedAt]);
    }

    // The ways a page may report that its candidate left the paper. new_session
    // is not among them: only the server can tell that another browser opened
    // the attempt.
    private const CLIENT_LOCK_TRIGGERS = ['window_blur', 'tab_hidden', 'fullscreen_exit'];

    // How long a blur lasted, as the page measured it. A whole number of
    // milliseconds up to ten minutes, or nothing: a value that is not exactly
    // that is stored as null rather than refused, because refusing it would
    // refuse the pause, and rather than cast, because "3200abc" is not 3200.
    private static function blurMilliseconds($raw): ?int
    {
        if (!is_string($raw) || !preg_match('/^(0|[1-9][0-9]{0,5})$/', $raw)) {
            return null;
        }
        $ms = (int) $raw;
        return $ms <= 600000 ? $ms : null;
    }

    // POST /student/lock/{attemptId}. AJAX, returns JSON
    //
    // The page saw the candidate leave: a blur that outlasted its grace, a
    // hidden tab, or fullscreen given up. Pausing an attempt that is already
    // paused adds the trigger to that pause and answers the same way, so a
    // blur and a hidden tab arriving together are one pause.
    public function lock(string $attemptId = ''): void
    {
        header('Cache-Control: no-store');

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

        $trigger = $_POST['trigger'] ?? '';
        if (!is_string($trigger) || !in_array($trigger, self::CLIENT_LOCK_TRIGGERS, true)) {
            $this->json(['ok' => false, 'error' => 'bad_trigger'], 422);
        }

        // Whether it is still in progress and inside its deadline is not
        // checked out here: lock() decides both inside its own UPDATE, on the
        // database's clock, where a submit arriving at the same instant cannot
        // slip between the check and the pause.
        $blurMs = $trigger === 'window_blur' ? self::blurMilliseconds($_POST['blur_ms'] ?? null) : null;

        $outcome = $attemptModel->lock($attemptId, $trigger, $blurMs);

        // Nothing to pause. If that is because the deadline has passed, close
        // the paper rather than leave it for the sweep.
        if ($outcome === 'closed') {
            $attemptModel->closeIfExpired($attemptId);
            $this->json(['ok' => false, 'error' => 'closed'], 409);
        }

        $this->json(['ok' => true, 'locked' => true, 'new' => $outcome === 'locked']);
    }

    // POST /student/heartbeat/{attemptId}. AJAX, returns JSON
    //
    // Sent every 15 seconds. Tells the page whether its attempt is paused,
    // which is how a page learns of a pause it did not cause, and how long is
    // left by the database's clock. A long silence before it is logged and
    // never pauses anyone.
    public function heartbeat(string $attemptId = ''): void
    {
        header('Cache-Control: no-store');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'error' => 'method'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->json(['ok' => false, 'error' => 'csrf'], 403);
        }

        $attemptId = (int) $attemptId;
        $studentId = (int) Auth::user()['id'];

        $attemptModel = new Attempt();
        if ($attemptModel->findOwned($attemptId, $studentId) === null) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        // Whether it is still in progress is decided inside heartbeat(), on the
        // locked row, not by a check out here that could go stale.
        $beat = $attemptModel->heartbeat($attemptId);
        if ($beat === null) {
            $this->json(['ok' => false, 'error' => 'closed'], 409);
        }

        $this->json(['ok' => true, 'locked' => $beat['locked'], 'remaining' => $beat['remaining']]);
    }

    // POST /student/event/{attemptId}. AJAX, returns JSON
    //
    // Something worth recording that is not a pause. For now only a blur that
    // came back inside its grace.
    public function event(string $attemptId = ''): void
    {
        header('Cache-Control: no-store');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'error' => 'method'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $this->json(['ok' => false, 'error' => 'csrf'], 403);
        }

        $attemptId = (int) $attemptId;
        $studentId = (int) Auth::user()['id'];

        $attemptModel = new Attempt();
        if ($attemptModel->findOwned($attemptId, $studentId) === null) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        if (($_POST['type'] ?? '') !== 'blur_blip') {
            $this->json(['ok' => false, 'error' => 'bad_event'], 422);
        }

        if (!$attemptModel->recordBlurBlip($attemptId, self::blurMilliseconds($_POST['ms'] ?? null))) {
            $this->json(['ok' => false, 'error' => 'closed'], 409);
        }

        $this->json(['ok' => true]);
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

        // Only an in-progress attempt can be submitted. Answer fields in this
        // POST are never read: what is graded is what saveAnswer stored, so a
        // paused candidate cannot slip changed answers in through Submit.
        if ($attempt['status'] === 'in_progress') {
            // Submitted or auto_submitted (deadline passed) is decided inside
            // submitAndGrade(), on the database's clock. So is the refusal
            // while paused, by its claim rather than a check here that a pause
            // could slip past. Back to the paper, which is where a paused
            // candidate is told why.
            if ($attemptModel->submitAndGrade($attemptId, $unsaved) === null) {
                $now = $attemptModel->findOwned($attemptId, $studentId);
                if ($now !== null && $now['status'] === 'in_progress') {
                    $this->redirect('student/exam/' . $attemptId);
                }
            }
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

        // Correct answers stay hidden until the window has closed AND every
        // attempt on this exam is past its deadline plus the sweep's grace,
        // judged by the database's clock (Exam::answerReveal()). Students sit
        // at different times, and one who starts just before the window closes
        // sits on after it; revealing earlier would hand an answer key to
        // someone still sitting the paper.
        $reveal = (new Exam())->answerReveal((int) $attempt['exam_id']);

        $this->view('student/result', [
            'attempt'    => $attempt,
            'exam'       => $exam,
            'answers'    => $attemptModel->reviewForAttempt($attemptId),
            'can_review' => $exam !== null && $reveal['revealed'],
            'reveal_at'  => $reveal['reveal_at'],
            'max_marks'  => $attemptModel->maxMarks($attemptId),
        ]);
    }
}