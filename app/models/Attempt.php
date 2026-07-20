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
}