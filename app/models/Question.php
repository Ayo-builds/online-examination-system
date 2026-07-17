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
}