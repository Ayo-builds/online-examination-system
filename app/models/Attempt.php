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
        $this->query(
            "UPDATE exam_attempts
             SET status = 'auto_submitted', submitted_at = NOW()
             WHERE id = ? AND status = 'in_progress'",
            [$attemptId]
        );
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
}