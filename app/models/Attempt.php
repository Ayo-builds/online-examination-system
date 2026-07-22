<?php
class Attempt extends Model
{
    protected string $table = 'exam_attempts';

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

            // 1. The attempt row — deadline computed server-side, in SQL itself
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

    // Mark an attempt auto-submitted (deadline reached). Grading happens in Step 35.
  public function autoSubmit(int $attemptId): void
    {
        // Auto-submit = grade with the auto_submitted status
        $this->submitAndGrade($attemptId, 'auto_submitted');
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

    // Grade all MCQs against frozen correct answers; flag essays for manual grading.
    // Returns ['auto_score' => float, 'has_essays' => bool].
    public function submitAndGrade(int $attemptId, string $finalStatus = 'submitted'): array
    {
        try {
            $this->db->beginTransaction();

            // Lock the attempt to in_progress → target status (idempotent guard)
            $this->query(
                "UPDATE exam_attempts
                 SET status = ?, submitted_at = NOW()
                 WHERE id = ? AND status = 'in_progress'",
                [$finalStatus, $attemptId]
            );

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
                    a.submitted_at,
                    u.full_name AS student_name,
                    e.title AS exam_title, e.pass_mark,
                    c.course_code
             FROM exam_attempts a
             JOIN exams e   ON e.id = a.exam_id
             JOIN courses c ON c.id = e.course_id
             JOIN users u   ON u.id = a.student_id
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
            "SELECT a.*, u.full_name AS student_name,
                    e.title AS exam_title, e.pass_mark,
                    c.course_code, c.id AS course_id
             FROM exam_attempts a
             JOIN exams e   ON e.id = a.exam_id
             JOIN courses c ON c.id = e.course_id
             JOIN users u   ON u.id = a.student_id
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
}