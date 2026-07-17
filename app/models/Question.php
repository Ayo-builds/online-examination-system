<?php
class Question extends Model
{
    protected string $table = 'questions';

    public function byCourse(int $courseId): array
    {
        return $this->query(
            "SELECT id, question_type, question_text, marks, created_at
             FROM questions
             WHERE course_id = ?
             ORDER BY created_at DESC",
            [$courseId]
        )->fetchAll();
    }

    public function countByCourse(int $courseId): array
    {
        return $this->query(
            "SELECT question_type, COUNT(*) AS total
             FROM questions
             WHERE course_id = ?
             GROUP BY question_type",
            [$courseId]
        )->fetchAll();
    }

    // Insert an MCQ + its options atomically. Returns new question id.
    public function createMcq(
        int $courseId,
        string $questionText,
        float $marks,
        int $createdBy,
        array $options,        // e.g. ['Lagos', 'Abuja', 'Kano', 'Ibadan']
        int $correctIndex      // 0-based index into $options
    ): int {
        try {
            $this->db->beginTransaction();

            $this->query(
                "INSERT INTO questions (course_id, question_type, question_text, marks, created_by)
                 VALUES (?, 'mcq', ?, ?, ?)",
                [$courseId, $questionText, $marks, $createdBy]
            );

            $questionId = (int) $this->db->lastInsertId();

            $optStmt = $this->db->prepare(
                "INSERT INTO question_options (question_id, option_text, is_correct)
                 VALUES (?, ?, ?)"
            );

            foreach ($options as $i => $text) {
                $optStmt->execute([$questionId, $text, $i === $correctIndex ? 1 : 0]);
            }

            $this->db->commit();
            return $questionId;

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function createEssay(
        int $courseId,
        string $questionText,
        float $marks,
        int $createdBy
    ): int {
        $this->query(
            "INSERT INTO questions (course_id, question_type, question_text, marks, created_by)
             VALUES (?, 'essay', ?, ?, ?)",
            [$courseId, $questionText, $marks, $createdBy]
        );

        return (int) $this->db->lastInsertId();
    }

    // Full question + its options (options empty for essays)
    public function findWithOptions(int $questionId, int $courseId): ?array
    {
        $question = $this->query(
            "SELECT * FROM questions WHERE id = ? AND course_id = ? LIMIT 1",
            [$questionId, $courseId]
        )->fetch();

        if ($question === false) {
            return null;
        }

        $question['options'] = $this->query(
            "SELECT id, option_text, is_correct
             FROM question_options
             WHERE question_id = ?
             ORDER BY id",
            [$questionId]
        )->fetchAll();

        return $question;
    }

    // Is this question referenced by any exam pool or attempt?
    public function isInUse(int $questionId): bool
    {
        $row = $this->query(
            "SELECT 1 FROM exam_question_pool WHERE question_id = ?
             UNION
             SELECT 1 FROM attempt_questions WHERE question_id = ?
             LIMIT 1",
            [$questionId, $questionId]
        )->fetch();

        return $row !== false;
    }

    public function delete(int $questionId): void
    {
        $this->query("DELETE FROM questions WHERE id = ?", [$questionId]);
    }
}