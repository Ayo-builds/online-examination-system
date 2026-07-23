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

    // Published exams in the student's enrolled courses, with their attempt state
    public function availableForStudent(int $studentId): array
    {
        return $this->query(
            "SELECT e.id, e.title, e.instructions, e.duration_minutes,
                    e.window_start, e.window_end, e.questions_per_attempt,
                    c.course_code, c.title AS course_title,
                    a.id     AS attempt_id,
                    a.status AS attempt_status
             FROM exams e
             JOIN courses c     ON c.id = e.course_id
             JOIN enrollments n ON n.course_id = c.id AND n.student_id = ?
             LEFT JOIN exam_attempts a
                    ON a.exam_id = e.id AND a.student_id = ?
             WHERE e.status = 'published'
             ORDER BY e.window_end ASC",
            [$studentId, $studentId]
        )->fetchAll();
    }

    // Headline stats for one exam
    public function statistics(int $examId): array
    {
        $row = $this->query(
            "SELECT COUNT(*)                             AS attempts,
                    AVG(a.total_score)                   AS avg_score,
                    MIN(a.total_score)                   AS min_score,
                    MAX(a.total_score)                   AS max_score,
                    SUM(a.is_flagged)                    AS flagged_count,
                    SUM(a.status = 'auto_submitted')     AS auto_submitted_count,
                    SUM(a.grading_status = 'partial')    AS pending_grading
             FROM exam_attempts a
             WHERE a.exam_id = ?
               AND a.status IN ('submitted', 'auto_submitted')",
            [$examId]
        )->fetch();

        return $row ?: [];
    }

    // Every finished attempt's score, for the distribution
    public function scores(int $examId): array
    {
        return $this->query(
            "SELECT a.total_score, a.grading_status, u.full_name
             FROM exam_attempts a
             JOIN users u ON u.id = a.student_id
             WHERE a.exam_id = ?
               AND a.status IN ('submitted', 'auto_submitted')
             ORDER BY a.total_score DESC",
            [$examId]
        )->fetchAll();
    }
}