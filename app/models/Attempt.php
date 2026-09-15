<?php
class Attempt extends Model
{
    protected string $table = 'exam_attempts';

    // How long past its deadline an attempt is left for its own student to
    // close before the sweep does it for them. The student's submit is the only
    // thing that records unsaved_at_submit, and the page's timer fires that
    // submit AT the deadline: a sweep at deadline+0 would race it and erase the
    // record. Five minutes also covers a submit re-sent after a short network
    // drop. Waiting costs nothing, because saveAnswer refuses every write past
    // deadline_at - the paper's contents are frozen however late the sweep runs.
    public const SWEEP_GRACE_MINUTES = 5;

    // Answers already on their way when the candidate left the paper still
    // land for this long after the pause began. The page sends what it has the
    // moment it notices, but that request and the lock race each other across
    // the network, and a candidate's last sentence must not lose that race.
    public const SAVE_GRACE_SECONDS = 5;

    // A heartbeat arrives every 15 seconds. Three missed in a row is logged.
    // Only logged: a switch rebooting or a cable knocked out of a PC must never
    // pause someone's exam.
    public const HEARTBEAT_GAP_SECONDS = 45;

    public function findByExamAndStudent(int $examId, int $studentId): ?array
    {
        $row = $this->query(
            "SELECT * FROM exam_attempts WHERE exam_id = ? AND student_id = ? LIMIT 1",
            [$examId, $studentId]
        )->fetch();

        return $row ?: null;
    }

    // THE critical write: create attempt + frozen question snapshot, atomically.
    // Returns the new attempt id.
    public function start(int $examId, int $studentId, array $exam): int
    {
        try {
            $this->db->beginTransaction();

            // 1. The attempt row, with the deadline computed server-side, in SQL itself
            $this->query(
                "INSERT INTO exam_attempts (exam_id, student_id, started_at, deadline_at)
                 VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))",
                [$examId, $studentId, (int) $exam['duration_minutes']]
            );

            $attemptId = (int) $this->db->lastInsertId();

            // 2. Random draw from the pool
            $drawn = $this->query(
                "SELECT q.id, q.question_type
                 FROM exam_question_pool p
                 JOIN questions q ON q.id = p.question_id
                 WHERE p.exam_id = ?
                 ORDER BY RAND()
                 LIMIT " . (int) $exam['questions_per_attempt'],
                [$examId]
            )->fetchAll();

            if (count($drawn) < (int) $exam['questions_per_attempt']) {
                throw new RuntimeException('Pool too small for the configured draw.');
            }

            // 3. Freeze each question: display order + shuffled option order
            $snapStmt = $this->db->prepare(
                "INSERT INTO attempt_questions (attempt_id, question_id, display_order, option_order)
                 VALUES (?, ?, ?, ?)"
            );

            foreach ($drawn as $order => $q) {
                $optionOrder = null;

                if ($q['question_type'] === 'mcq' && (int) $exam['shuffle_options'] === 1) {
                    $optionIds = array_map(
                        fn($r) => (int) $r['id'],
                        $this->query(
                            "SELECT id FROM question_options WHERE question_id = ?",
                            [(int) $q['id']]
                        )->fetchAll()
                    );
                    shuffle($optionIds);
                    $optionOrder = json_encode($optionIds);
                }

                $snapStmt->execute([$attemptId, (int) $q['id'], $order + 1, $optionOrder]);
            }

            $this->db->commit();
            return $attemptId;

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // The attempt, but ONLY if it belongs to this student
    public function findOwned(int $attemptId, int $studentId): ?array
    {
        $row = $this->query(
            "SELECT a.*, e.title AS exam_title, e.instructions,
                    c.course_code
             FROM exam_attempts a
             JOIN exams e   ON e.id = a.exam_id
             JOIN courses c ON c.id = e.course_id
             WHERE a.id = ? AND a.student_id = ?
             LIMIT 1",
            [$attemptId, $studentId]
        )->fetch();

        return $row ?: null;
    }

    // Reconstruct the frozen paper: questions in snapshot order,
    // options in snapshot order, plus any answer already saved.
    public function questionsForAttempt(int $attemptId): array
    {
        $rows = $this->query(
            "SELECT aq.question_id, aq.display_order, aq.option_order,
                    q.question_type, q.question_text, q.marks,
                    ans.selected_option_id, ans.essay_text
             FROM attempt_questions aq
             JOIN questions q ON q.id = aq.question_id
             LEFT JOIN attempt_answers ans
                    ON ans.attempt_id = aq.attempt_id AND ans.question_id = aq.question_id
             WHERE aq.attempt_id = ?
             ORDER BY aq.display_order",
            [$attemptId]
        )->fetchAll();

        // Attach options (in frozen order) to each MCQ
        foreach ($rows as &$row) {
            $row['options'] = [];

            if ($row['question_type'] === 'mcq') {
                $allOptions = $this->query(
                    "SELECT id, option_text FROM question_options WHERE question_id = ?",
                    [(int) $row['question_id']]
                )->fetchAll();

                // Index by id for O(1) lookup
                $byId = [];
                foreach ($allOptions as $o) {
                    $byId[(int) $o['id']] = $o['option_text'];
                }

                // Rebuild in the frozen order from option_order JSON
                $order = json_decode($row['option_order'] ?? '[]', true) ?: [];
                foreach ($order as $optId) {
                    if (isset($byId[(int) $optId])) {
                        $row['options'][] = ['id' => (int) $optId, 'text' => $byId[(int) $optId]];
                    }
                }
            }
        }
        unset($row);

        return $rows;
    }

    // Close an attempt on the server's authority: its deadline has passed and
    // no submission came from the candidate's browser. Reached two ways - the
    // candidate reopening the paper late (StudentController::exam) and the
    // sweep below - and both are the same event, so both are marked the same.
    //
    // submitted_at is the deadline, not now. saveAnswer refuses everything
    // after deadline_at, so that is when the paper's contents actually froze,
    // and a paper swept on Monday must not tell the student it was completed
    // on Monday. When the server really closed it goes in closed_by_system_at,
    // which is also what tells the lecturer that nobody pressed Submit - and so
    // that unsaved_at_submit = 0 on this row means unknown, not none.
    //
    // Returns false when something else closed the attempt first.
    public function autoSubmit(int $attemptId): bool
    {
        return $this->claimAndGrade(
            "UPDATE exam_attempts
             SET status = 'auto_submitted', submitted_at = deadline_at,
                 closed_by_system_at = NOW()
             WHERE id = ? AND status = 'in_progress'",
            [$attemptId],
            $attemptId
        ) !== null;
    }

    // Close every attempt whose candidate never came back. Returns how many.
    //
    // Otherwise an attempt is closed only by its own student. One who never
    // returns - a power cut, a walk-out - leaves it in_progress for good: never
    // graded, missing from the grading queue and from every analytics query,
    // and counted as a live attempt forever. There is no cron on a school LAN,
    // so staff page loads run this instead (LecturerController's constructor,
    // AdminController::analytics).
    //
    // System-wide, not per lecturer: closing an expired attempt is not a
    // permission decision, it is exactly what the student's own return would
    // do. Each attempt is its own transaction, and one that fails is logged and
    // skipped - the sweep must never be the reason a lecturer's page is down.
    public function sweepAbandoned(): int
    {
        $ids = $this->query(
            "SELECT id FROM exam_attempts
             WHERE status = 'in_progress'
               AND deadline_at < NOW() - INTERVAL " . self::SWEEP_GRACE_MINUTES . " MINUTE"
        )->fetchAll(PDO::FETCH_COLUMN);

        $closed = 0;
        foreach ($ids as $id) {
            try {
                if ($this->autoSubmit((int) $id)) {
                    $closed++;
                }
            } catch (Throwable $e) {
                error_log('Attempt::sweepAbandoned: attempt ' . (int) $id . ': ' . $e->getMessage());
            }
        }

        return $closed;
    }

    // Is this question part of this attempt's frozen paper?
    public function questionInAttempt(int $attemptId, int $questionId): bool
    {
        $row = $this->query(
            "SELECT 1 FROM attempt_questions
             WHERE attempt_id = ? AND question_id = ? LIMIT 1",
            [$attemptId, $questionId]
        )->fetch();

        return $row !== false;
    }

    // ---- Pausing ------------------------------------------------------------
    //
    // Every time below comes from the database's NOW(), never PHP's clock. The
    // two already disagree in this codebase (deadline_at is written by MySQL
    // and has been compared in PHP), and a pause measured by one clock and
    // enforced by the other would open or shut the save window by hours.

    // Pause this attempt because the candidate left the paper. Returns
    // 'locked' for a new pause, 'appended' when it was already paused and this
    // trigger was added to the same pause, and 'closed' when there is nothing
    // to pause: submitted, closed, or past its deadline.
    //
    // A blur and a hidden tab usually arrive together. The decision is the row
    // count of ONE conditional UPDATE, never a read followed by a write: two
    // requests cannot both see "not locked", because InnoDB makes the second
    // UPDATE wait for the first to commit and then re-check its WHERE. The
    // lock row is inserted only by the request whose UPDATE changed the row,
    // and attempt_locks' unique open_attempt_id refuses a second open lock if
    // anything ever gets that wrong.
    //
    // The row is created holding {"triggers":[first]} and later triggers are
    // appended in a single statement. JSON_ARRAY_APPEND quietly does nothing
    // to a document without a triggers array, so it must exist from the start.
    public function lock(int $attemptId, string $trigger, ?int $blurMs): string
    {
        $entry = "JSON_OBJECT('type', ?, 'at', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'), 'blur_ms', CAST(? AS SIGNED))";

        try {
            $this->db->beginTransaction();

            $paused = $this->query(
                "UPDATE exam_attempts SET locked_at = NOW()
                  WHERE id = ? AND status = 'in_progress' AND locked_at IS NULL AND deadline_at > NOW()",
                [$attemptId]
            )->rowCount();

            if ($paused === 1) {
                // The lock's time is the attempt's, read back rather than taken
                // from a second NOW() that could fall in the next second.
                $this->query(
                    "INSERT INTO attempt_locks (attempt_id, trigger_type, detail, locked_at)
                     SELECT id, ?, JSON_OBJECT('triggers', JSON_ARRAY($entry)), locked_at
                       FROM exam_attempts WHERE id = ?",
                    [$trigger, $trigger, $blurMs, $attemptId]
                );
                $this->db->commit();
                return 'locked';
            }

            $appended = $this->query(
                "UPDATE attempt_locks l
                   JOIN exam_attempts a ON a.id = l.attempt_id
                    SET l.detail = JSON_ARRAY_APPEND(l.detail, '$.triggers', $entry)
                  WHERE l.attempt_id = ? AND l.unlocked_at IS NULL
                    AND a.status = 'in_progress' AND a.locked_at IS NOT NULL",
                [$trigger, $blurMs, $attemptId]
            )->rowCount();

            $this->db->commit();
            return $appended === 1 ? 'appended' : 'closed';

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Save one answer unless the attempt is paused and the grace has run out.
    // Returns 'saved', 'locked' or 'closed'.
    //
    // The window is decided in the same statement that reads the attempt, on
    // the database's clock, and the row stays locked until the answer is
    // written, so a pause cannot land between the check and the write.
    public function saveAnswerIfWritable(int $attemptId, int $questionId, ?int $optionId, ?string $essayText): string
    {
        try {
            $this->db->beginTransaction();

            $row = $this->query(
                "SELECT status = 'in_progress' AS open,
                        locked_at IS NULL OR NOW() <= locked_at + INTERVAL " . self::SAVE_GRACE_SECONDS . " SECOND AS writable
                   FROM exam_attempts WHERE id = ? FOR UPDATE",
                [$attemptId]
            )->fetch();

            if ($row === false || (int) $row['open'] !== 1) {
                $this->db->commit();
                return 'closed';
            }
            if ((int) $row['writable'] !== 1) {
                $this->db->commit();
                return 'locked';
            }

            $this->saveAnswer($attemptId, $questionId, $optionId, $essayText);
            $this->db->commit();
            return 'saved';

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // The page's pulse. Returns null when the attempt is not in progress, and
    // writes nothing then. Otherwise records it, logs a long silence before
    // it, and says whether the attempt is paused and how long is left, both by
    // the database's clock.
    public function heartbeat(int $attemptId): ?array
    {
        try {
            $this->db->beginTransaction();

            $row = $this->query(
                "SELECT status, locked_at IS NOT NULL AS locked,
                        TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS silence,
                        GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), deadline_at)) AS remaining
                   FROM exam_attempts WHERE id = ? FOR UPDATE",
                [$attemptId]
            )->fetch();

            if ($row === false || $row['status'] !== 'in_progress') {
                $this->db->commit();
                return null;
            }

            if ($row['silence'] !== null && (int) $row['silence'] > self::HEARTBEAT_GAP_SECONDS) {
                $this->query(
                    "INSERT INTO attempt_events (attempt_id, event_type, detail, created_at)
                     VALUES (?, 'heartbeat_gap', JSON_OBJECT('seconds', CAST(? AS SIGNED)), NOW())",
                    [$attemptId, (int) $row['silence']]
                );
            }

            $this->query("UPDATE exam_attempts SET last_seen_at = NOW() WHERE id = ?", [$attemptId]);

            $this->db->commit();
            return ['locked' => (int) $row['locked'] === 1, 'remaining' => (int) $row['remaining']];

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // A short blur that came back in time: worth recording, not worth a pause.
    // Written only while the attempt is in progress; returns false otherwise.
    public function recordBlurBlip(int $attemptId, ?int $ms): bool
    {
        return $this->query(
            "INSERT INTO attempt_events (attempt_id, event_type, detail, created_at)
             SELECT id, 'blur_blip', JSON_OBJECT('ms', CAST(? AS SIGNED)), NOW()
               FROM exam_attempts WHERE id = ? AND status = 'in_progress'",
            [$ms, $attemptId]
        )->rowCount() === 1;
    }

    // Save (insert or update) one answer. Upsert on the composite key.
    public function saveAnswer(int $attemptId, int $questionId, ?int $optionId, ?string $essayText): void
    {
        $this->query(
            "INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id, essay_text)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                selected_option_id = VALUES(selected_option_id),
                essay_text         = VALUES(essay_text)",
            [$attemptId, $questionId, $optionId, $essayText]
        );
    }

    // How many questions are on this attempt's paper. Used to clamp the
    // client-reported unsaved count: it cannot exceed the paper itself.
    public function questionCount(int $attemptId): int
    {
        $row = $this->query(
            "SELECT COUNT(*) AS n FROM attempt_questions WHERE attempt_id = ?",
            [$attemptId]
        )->fetch();

        return (int) $row['n'];
    }

    // Grade all MCQs against frozen correct answers; flag essays for manual grading.
    // Returns ['auto_score' => float, 'has_essays' => bool], or null when the
    // attempt had already been closed by someone else, or is paused, and
    // nothing was done.
    //
    // $unsavedAtSubmit is how many answers the browser still had outstanding.
    // It is recorded, never acted on: a failed save must not cost a student
    // marks, so it changes nothing about grading and only tells the school
    // afterwards that this paper was submitted with saves in flight.
    public function submitAndGrade(
        int $attemptId,
        string $finalStatus = 'submitted',
        int $unsavedAtSubmit = 0
    ): ?array {
        // The unsaved count rides along in the claim itself, so a paper can
        // never be marked submitted without the record of how it was.
        //
        // A paused attempt cannot be submitted, and that is decided here, in
        // the claim, not by a check before it: a pause arriving between a check
        // and this UPDATE would otherwise leave a submitted paper with an open
        // lock. autoSubmit() deliberately has no such condition, because a
        // paused paper must still close at its deadline.
        return $this->claimAndGrade(
            "UPDATE exam_attempts
             SET status = ?, submitted_at = NOW(), unsaved_at_submit = ?
             WHERE id = ? AND status = 'in_progress' AND locked_at IS NULL",
            [$finalStatus, $unsavedAtSubmit, $attemptId],
            $attemptId
        );
    }

    // Run $claimSql - an UPDATE that moves this attempt out of in_progress -
    // and grade the attempt, in one transaction. Returns null without grading
    // if the claim matched nothing.
    private function claimAndGrade(string $claimSql, array $params, int $attemptId): ?array
    {
        try {
            $this->db->beginTransaction();

            // The claim closes the attempt for exactly one caller. A student's
            // late submit, their return to the paper and the sweep can all reach
            // one attempt at the same moment; InnoDB serialises the UPDATEs and
            // every one after the first matches no row. The loser must stop
            // here: regrading a paper someone else closed writes NULL over any
            // essay mark a lecturer has awarded since.
            if ($this->query($claimSql, $params)->rowCount() === 0) {
                $this->db->commit();
                return null;
            }

            // Every question on this paper, with its type, marks, correct option,
            // and the student's saved answer (if any)
            $rows = $this->query(
                "SELECT aq.question_id, q.question_type, q.marks,
                        ans.selected_option_id, ans.essay_text,
                        (SELECT id FROM question_options
                          WHERE question_id = q.id AND is_correct = 1 LIMIT 1) AS correct_option_id
                 FROM attempt_questions aq
                 JOIN questions q ON q.id = aq.question_id
                 LEFT JOIN attempt_answers ans
                        ON ans.attempt_id = aq.attempt_id AND ans.question_id = aq.question_id
                 WHERE aq.attempt_id = ?",
                [$attemptId]
            )->fetchAll();

            $autoScore = 0.0;
            $hasEssays = false;

            $gradeStmt = $this->db->prepare(
                "INSERT INTO attempt_answers
                    (attempt_id, question_id, selected_option_id, essay_text, awarded_marks, graded_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    awarded_marks = VALUES(awarded_marks),
                    graded_at     = VALUES(graded_at)"
            );

            foreach ($rows as $r) {
                if ($r['question_type'] === 'mcq') {
                    $correct = $r['selected_option_id'] !== null
                            && (int) $r['selected_option_id'] === (int) $r['correct_option_id'];
                    $awarded = $correct ? (float) $r['marks'] : 0.0;
                    $autoScore += $awarded;

                    $gradeStmt->execute([
                        $attemptId, (int) $r['question_id'],
                        $r['selected_option_id'] !== null ? (int) $r['selected_option_id'] : null,
                        null, $awarded,
                    ]);
                } else {
                    // Essay: leave awarded_marks NULL (ungraded), just ensure a row exists
                    $hasEssays = true;
                    $gradeStmt->execute([
                        $attemptId, (int) $r['question_id'],
                        null, $r['essay_text'], null,
                    ]);
                }
            }

            // Grading status + running total
            $gradingStatus = $hasEssays ? 'partial' : 'complete';
            $this->query(
                "UPDATE exam_attempts
                 SET total_score = ?, grading_status = ?
                 WHERE id = ?",
                [$autoScore, $gradingStatus, $attemptId]
            );

            $this->db->commit();
            return ['auto_score' => $autoScore, 'has_essays' => $hasEssays];

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // All finished attempts for exams owned by this lecturer
    public function forLecturer(int $lecturerId): array
    {
        return $this->query(
            "SELECT a.id, a.status, a.total_score, a.grading_status, a.is_flagged,
                    a.submitted_at, a.closed_by_system_at, a.locked_at,
                    u.full_name AS student_name, u.admission_no,
                    cl.year_group, cl.arm,
                    e.title AS exam_title, e.pass_mark,
                    c.course_code
             FROM exam_attempts a
             JOIN exams e         ON e.id = a.exam_id
             JOIN courses c       ON c.id = e.course_id
             JOIN users u         ON u.id = a.student_id
             LEFT JOIN classes cl ON cl.id = u.class_id
             WHERE c.lecturer_id = ?
               AND a.status IN ('submitted', 'auto_submitted')
             ORDER BY (a.grading_status = 'partial') DESC,
                      a.is_flagged DESC,
                      a.submitted_at DESC",
            [$lecturerId]
        )->fetchAll();
    }

    // One attempt, but only if it belongs to an exam this lecturer owns
    public function findForLecturer(int $attemptId, int $lecturerId): ?array
    {
        $row = $this->query(
            "SELECT a.*, u.full_name AS student_name, u.admission_no,
                    cl.year_group, cl.arm,
                    e.title AS exam_title, e.pass_mark,
                    c.course_code, c.id AS course_id
             FROM exam_attempts a
             JOIN exams e         ON e.id = a.exam_id
             JOIN courses c       ON c.id = e.course_id
             JOIN users u         ON u.id = a.student_id
             LEFT JOIN classes cl ON cl.id = u.class_id
             WHERE a.id = ? AND c.lecturer_id = ?
             LIMIT 1",
            [$attemptId, $lecturerId]
        )->fetch();

        return $row ?: null;
    }

    public function setFlagged(int $attemptId, bool $flagged): void
    {
        $this->query(
            "UPDATE exam_attempts SET is_flagged = ? WHERE id = ?",
            [$flagged ? 1 : 0, $attemptId]
        );
    }

    // Full answer detail for grading: each question, the student's answer,
    // the correct option (for MCQs), and marks awarded so far.
    public function answersForGrading(int $attemptId): array
    {
        $rows = $this->query(
            "SELECT aq.question_id, aq.display_order,
                    q.question_type, q.question_text, q.marks,
                    ans.selected_option_id, ans.essay_text, ans.awarded_marks
             FROM attempt_questions aq
             JOIN questions q ON q.id = aq.question_id
             LEFT JOIN attempt_answers ans
                    ON ans.attempt_id = aq.attempt_id AND ans.question_id = aq.question_id
             WHERE aq.attempt_id = ?
             ORDER BY aq.display_order",
            [$attemptId]
        )->fetchAll();

        foreach ($rows as &$r) {
            $r['options'] = [];
            if ($r['question_type'] === 'mcq') {
                $r['options'] = $this->query(
                    "SELECT id, option_text, is_correct
                     FROM question_options WHERE question_id = ?",
                    [(int) $r['question_id']]
                )->fetchAll();
            }
        }
        unset($r);

        return $rows;
    }

    // Everything a student's own review needs: the frozen paper, what they
    // chose, which option was correct, and what each answer earned.
    //
    // answersForGrading() carries the same columns but returns options in
    // database order. A student reviewing their paper should see it in the
    // order they actually sat it, so this rebuilds each MCQ from the frozen
    // option_order the way questionsForAttempt() does.
    public function reviewForAttempt(int $attemptId): array
    {
        $rows = $this->query(
            "SELECT aq.question_id, aq.display_order, aq.option_order,
                    q.question_type, q.question_text, q.marks,
                    ans.selected_option_id, ans.essay_text, ans.awarded_marks
             FROM attempt_questions aq
             JOIN questions q ON q.id = aq.question_id
             LEFT JOIN attempt_answers ans
                    ON ans.attempt_id = aq.attempt_id AND ans.question_id = aq.question_id
             WHERE aq.attempt_id = ?
             ORDER BY aq.display_order",
            [$attemptId]
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['options'] = [];

            if ($row['question_type'] !== 'mcq') {
                continue;
            }

            $all = $this->query(
                "SELECT id, option_text, is_correct
                 FROM question_options WHERE question_id = ?",
                [(int) $row['question_id']]
            )->fetchAll();

            $byId = [];
            foreach ($all as $o) {
                $byId[(int) $o['id']] = $o;
            }

            $order = json_decode($row['option_order'] ?? '[]', true) ?: [];
            foreach ($order as $optId) {
                $optId = (int) $optId;
                if (!isset($byId[$optId])) {
                    continue;
                }
                $row['options'][] = [
                    'id'         => $optId,
                    'text'       => $byId[$optId]['option_text'],
                    'is_correct' => (int) $byId[$optId]['is_correct'] === 1,
                ];
            }
        }
        unset($row);

        return $rows;
    }

    // Award marks to ONE essay answer, then recompute the attempt total atomically.
    public function gradeEssay(int $attemptId, int $questionId, float $marks): void
    {
        try {
            $this->db->beginTransaction();

            // 1. Set this essay's awarded marks
            $this->query(
                "UPDATE attempt_answers
                 SET awarded_marks = ?, graded_at = NOW()
                 WHERE attempt_id = ? AND question_id = ?",
                [$marks, $attemptId, $questionId]
            );

            // 2. Recompute total from ALL awarded marks (never incremental)
            $sumRow = $this->query(
                "SELECT COALESCE(SUM(awarded_marks), 0) AS total
                 FROM attempt_answers
                 WHERE attempt_id = ? AND awarded_marks IS NOT NULL",
                [$attemptId]
            )->fetch();
            $total = (float) $sumRow['total'];

            // 3. Any essays still ungraded?
            $pending = $this->query(
                "SELECT 1
                 FROM attempt_questions aq
                 JOIN questions q ON q.id = aq.question_id
                 LEFT JOIN attempt_answers ans
                        ON ans.attempt_id = aq.attempt_id AND ans.question_id = aq.question_id
                 WHERE aq.attempt_id = ?
                   AND q.question_type = 'essay'
                   AND (ans.awarded_marks IS NULL)
                 LIMIT 1",
                [$attemptId]
            )->fetch();

            $status = $pending === false ? 'complete' : 'partial';

            // 4. Save total + grading status
            $this->query(
                "UPDATE exam_attempts SET total_score = ?, grading_status = ? WHERE id = ?",
                [$total, $status, $attemptId]
            );

            $this->db->commit();

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Max possible marks for this attempt (sum of its questions' marks)
    public function maxMarks(int $attemptId): float
    {
        $row = $this->query(
            "SELECT COALESCE(SUM(q.marks), 0) AS max_marks
             FROM attempt_questions aq
             JOIN questions q ON q.id = aq.question_id
             WHERE aq.attempt_id = ?",
            [$attemptId]
        )->fetch();

        return (float) $row['max_marks'];
    }

    public function reviewFlag(int $attemptId, bool $keepFlagged): void
    {
        $this->query(
            "UPDATE exam_attempts SET is_flagged = ? WHERE id = ?",
            [$keepFlagged ? 1 : 0, $attemptId]
        );
    }
}