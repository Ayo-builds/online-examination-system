<?php
class Exam extends Model
{
    protected string $table = 'exams';

    public function byCourse(int $courseId): array
    {
        return $this->query(
            "SELECT e.*,
                    (SELECT COUNT(*) FROM exam_question_pool p WHERE p.exam_id = e.id) AS pool_count
             FROM exams e
             WHERE e.course_id = ?
             ORDER BY e.created_at DESC",
            [$courseId]
        )->fetchAll();
    }

    public function findInCourse(int $examId, int $courseId): ?array
    {
        $row = $this->query(
            "SELECT * FROM exams WHERE id = ? AND course_id = ? LIMIT 1",
            [$examId, $courseId]
        )->fetch();

        return $row ?: null;
    }

    public function create(
        int $courseId,
        string $title,
        string $instructions,
        int $durationMinutes,
        string $windowStart,
        string $windowEnd,
        int $questionsPerAttempt,
        float $passMark
    ): int {
        $this->query(
            "INSERT INTO exams
               (course_id, title, instructions, duration_minutes,
                window_start, window_end, questions_per_attempt, pass_mark)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$courseId, $title, $instructions, $durationMinutes,
             $windowStart, $windowEnd, $questionsPerAttempt, $passMark]
        );

        return (int) $this->db->lastInsertId();
    }

    public function setStatus(int $examId, string $status): void
    {
        $this->query(
            "UPDATE exams SET status = ? WHERE id = ?",
            [$status, $examId]
        );
    }
}