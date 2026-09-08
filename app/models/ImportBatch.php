<?php
/**
 * One bulk student import, recorded so it can be undone.
 *
 * The batch row outlives the accounts it created. Deleting a batch removes the
 * students, not the record of the import, so "what did that wrong file do, and
 * has it been cleaned up?" stays answerable afterwards.
 */
class ImportBatch extends Model
{
    protected string $table = 'import_batches';

    public function create(string $filename, int $rowCount, ?int $importedBy): string
    {
        $id = bin2hex(random_bytes(16));

        $this->query(
            "INSERT INTO import_batches (id, filename, row_count, imported_by) VALUES (?, ?, ?, ?)",
            [$id, mb_substr($filename, 0, 255), $rowCount, $importedBy]
        );

        return $id;
    }

    /**
     * Every batch, newest first, with how many of its accounts still exist and
     * how many of those have sat an exam.
     *
     * remaining tells an admin whether there is anything left to delete;
     * locked tells them, before they click, that some of it will refuse to go.
     */
    public function allWithCounts(): array
    {
        return $this->query(
            "SELECT b.id, b.filename, b.row_count, b.created_at,
                    u.full_name AS imported_by_name,
                    (SELECT COUNT(*) FROM users s
                      WHERE s.import_batch_id = b.id) AS remaining,
                    (SELECT COUNT(DISTINCT a.student_id) FROM exam_attempts a
                       JOIN users s2 ON s2.id = a.student_id
                      WHERE s2.import_batch_id = b.id) AS locked
               FROM import_batches b
          LEFT JOIN users u ON u.id = b.imported_by
           ORDER BY b.created_at DESC"
        )->fetchAll();
    }

    // Not find(): Model::find() takes an int primary key, and a batch id is a
    // 32-character hex string. Overriding it with a different parameter type is
    // a fatal error in PHP 8, so this carries its own name.
    public function findBatch(string $id): ?array
    {
        $row = $this->query(
            "SELECT * FROM import_batches WHERE id = ? LIMIT 1",
            [$id]
        )->fetch();

        return $row ?: null;
    }

    /**
     * The accounts this batch created, each flagged with whether it has an exam
     * attempt against it.
     *
     * An attempt is a student's work, and exam_attempts.student_id is RESTRICT,
     * so the database refuses to delete such an account anyway. Checking here
     * turns that refusal into a list of names an admin can act on instead of a
     * failed statement.
     *
     * Enrolments do cascade, which is right: removing a mistakenly imported
     * student should take their course enrolments with them.
     */
    public function members(string $id): array
    {
        return $this->query(
            "SELECT s.id, s.full_name, s.admission_no,
                    c.year_group, c.arm,
                    (SELECT COUNT(*) FROM exam_attempts a WHERE a.student_id = s.id) AS attempts
               FROM users s
          LEFT JOIN classes c ON c.id = s.class_id
              WHERE s.import_batch_id = ?
           ORDER BY s.admission_no",
            [$id]
        )->fetchAll();
    }

    /**
     * Delete the accounts this batch created, except any that have sat an exam.
     *
     * @return array{deleted:int, skipped:array, error:?string}
     */
    public function deleteMembers(string $id): array
    {
        $members = $this->members($id);

        $deletable = [];
        $skipped   = [];

        foreach ($members as $m) {
            if ((int) $m['attempts'] > 0) {
                $skipped[] = $m;
            } else {
                $deletable[] = $m;
            }
        }

        if ($deletable === []) {
            return ['deleted' => 0, 'skipped' => $skipped, 'error' => null];
        }

        $this->db->beginTransaction();

        try {
            // Deleted one by one against an explicit guard rather than in a
            // single IN (...) statement. The subquery is the second lock on the
            // door: even if members() were somehow stale, a student who has
            // started an exam between then and now is still not deleted.
            $stmt = $this->db->prepare(
                "DELETE FROM users
                  WHERE id = ?
                    AND import_batch_id = ?
                    AND role = 'student'
                    AND NOT EXISTS (SELECT 1 FROM exam_attempts a WHERE a.student_id = users.id)"
            );

            $deleted = 0;
            foreach ($deletable as $m) {
                $stmt->execute([$m['id'], $id]);
                $deleted += $stmt->rowCount();
            }

            $this->db->commit();

            return ['deleted' => $deleted, 'skipped' => $skipped, 'error' => null];
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('Import batch delete failed: ' . $e->getMessage());

            return [
                'deleted' => 0,
                'skipped' => $skipped,
                'error'   => 'The accounts could not be deleted and nothing was removed. '
                           . 'Some of them may hold records elsewhere in the system.',
            ];
        }
    }
}
