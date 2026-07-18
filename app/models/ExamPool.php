<?php
class ExamPool extends Model
{
    protected string $table = 'exam_question_pool';

    // Question ids currently in this exam's pool
    public function questionIds(int $examId): array
    {
        $rows = $this->query(
            "SELECT question_id FROM exam_question_pool WHERE exam_id = ?",
            [$examId]
        )->fetchAll();

        return array_map(fn($r) => (int) $r['question_id'], $rows);
    }

    public function countForExam(int $examId): int
    {
        $row = $this->query(
            "SELECT COUNT(*) AS total FROM exam_question_pool WHERE exam_id = ?",
            [$examId]
        )->fetch();

        return (int) $row['total'];
    }

    // Replace the pool wholesale, atomically
    public function replace(int $examId, array $questionIds): void
    {
        try {
            $this->db->beginTransaction();

            $this->query(
                "DELETE FROM exam_question_pool WHERE exam_id = ?",
                [$examId]
            );

            if (!empty($questionIds)) {
                $stmt = $this->db->prepare(
                    "INSERT INTO exam_question_pool (exam_id, question_id) VALUES (?, ?)"
                );
                foreach ($questionIds as $qid) {
                    $stmt->execute([$examId, $qid]);
                }
            }

            $this->db->commit();

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}