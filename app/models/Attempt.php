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
}