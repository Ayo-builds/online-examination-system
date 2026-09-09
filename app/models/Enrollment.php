<?php
class Enrollment extends Model
{
    protected string $table = 'enrollments';

    // All students enrolled in a course
    public function studentsInCourse(int $courseId): array
    {
        return $this->query(
            "SELECT u.id, u.full_name, u.admission_no, u.status
             FROM enrollments e
             JOIN users u ON u.id = e.student_id
             WHERE e.course_id = ?
             ORDER BY u.full_name",
            [$courseId]
        )->fetchAll();
    }

    // Active students NOT yet enrolled in a course (for the dropdown)
    public function studentsNotInCourse(int $courseId): array
    {
        return $this->query(
            "SELECT id, full_name FROM users
             WHERE role = 'student' AND status = 'active'
               AND id NOT IN (SELECT student_id FROM enrollments WHERE course_id = ?)
             ORDER BY full_name",
            [$courseId]
        )->fetchAll();
    }

    public function isEnrolled(int $studentId, int $courseId): bool
    {
        $row = $this->query(
            "SELECT 1 FROM enrollments WHERE student_id = ? AND course_id = ? LIMIT 1",
            [$studentId, $courseId]
        )->fetch();

        return $row !== false;
    }

    public function enroll(int $studentId, int $courseId): void
    {
        $this->query(
            "INSERT INTO enrollments (student_id, course_id) VALUES (?, ?)",
            [$studentId, $courseId]
        );
    }

    public function unenroll(int $studentId, int $courseId): void
    {
        $this->query(
            "DELETE FROM enrollments WHERE student_id = ? AND course_id = ?",
            [$studentId, $courseId]
        );
    }

    // ---- The enrolled list --------------------------------------------------
    //
    // Both methods below take the SAME EnrollmentListQuery and call where() on
    // it, so the count and the page are filtered identically by construction.
    // There is no second copy of the criteria to fall out of step.
    //
    // The join to users is INNER - an enrolment row cannot exist without its
    // student, the foreign key cascades on delete - but the join to classes is
    // LEFT, because a student may not be placed in a class yet and an INNER
    // join would drop them off the roll entirely.
    private const LIST_FROM = "  FROM enrollments e
                                 JOIN users u ON u.id = e.student_id
                            LEFT JOIN classes c ON c.id = u.class_id";

    public function countForList(EnrollmentListQuery $query): int
    {
        [$where, $bindings] = $query->where();

        return (int) $this->query(
            "SELECT COUNT(*)" . self::LIST_FROM . $where,
            $bindings
        )->fetchColumn();
    }

    public function forList(EnrollmentListQuery $query): array
    {
        [$where, $bindings] = $query->where();

        // LIMIT and OFFSET are cast to int in PHP and interpolated, not bound,
        // for the same reason as the users list: MySQL will not take a string
        // there with emulated prepares off, and an int cast is the whole of the
        // validation these two need. Both come from EnrollmentListQuery, where
        // page is floored at 1 and per_page is an allowlist member.
        $limit  = (int) $query->perPage;
        $offset = (int) $query->offset();

        return $this->query(
            "SELECT u.id, u.full_name, u.admission_no, u.status,
                    c.year_group, c.arm"
            . self::LIST_FROM
            . $where
            . $query->orderBy()
            . " LIMIT {$limit} OFFSET {$offset}",
            $bindings
        )->fetchAll();
    }

    // Is anyone at all enrolled on this course? Distinguishes "nothing matches
    // these filters" from "nobody is enrolled yet", which need different empty
    // states. Only asked when the page came back empty.
    public function anyInCourse(int $courseId): bool
    {
        return (int) $this->query(
            "SELECT COUNT(*) FROM enrollments WHERE course_id = ?",
            [$courseId]
        )->fetchColumn() > 0;
    }

    // The classes actually represented on this course's roll, for the filter
    // dropdown. Offering every class in the school would let an admin pick one
    // that can only ever return an empty list.
    public function classesInCourse(int $courseId): array
    {
        return $this->query(
            "SELECT DISTINCT c.id, c.year_group, c.arm
               FROM enrollments e
               JOIN users u ON u.id = e.student_id
               JOIN classes c ON c.id = u.class_id
              WHERE e.course_id = ?
           ORDER BY c.year_group, c.arm",
            [$courseId]
        )->fetchAll();
    }

    // ---- Bulk enrolment by class --------------------------------------------
    //
    // Who a bulk enrolment would affect, counted without writing anything. The
    // confirmation step renders these numbers, so an admin sees the size of
    // what they are about to do before they do it.
    //
    // Three numbers, because "30 students in SS3A" on its own does not explain
    // a run that reports 18:
    //
    //   eligible  active, in the class, not yet on the roll  -> will be enrolled
    //   already   in the class and already on the roll       -> will be skipped
    //   suspended in the class but suspended                 -> not touched
    //
    // Suspended students are left out for the same reason the whole-class
    // password reset leaves them out: enrolling an account that cannot sign in
    // puts a name on a register that will never sit the exam. Reactivating the
    // account and running this again picks them up, and because the run is
    // idempotent that costs nothing.
    //
    // @return array{eligible: int, already: int, suspended: int}
    public function bulkPreview(int $courseId, int $classId): array
    {
        $row = $this->query(
            "SELECT
                SUM(u.status = 'active'    AND e.student_id IS NULL) AS eligible,
                SUM(u.status = 'active'    AND e.student_id IS NOT NULL) AS already,
                SUM(u.status = 'suspended') AS suspended
               FROM users u
          LEFT JOIN enrollments e
                 ON e.student_id = u.id AND e.course_id = ?
              WHERE u.role = 'student'
                AND u.class_id = ?",
            [$courseId, $classId]
        )->fetch() ?: [];

        // An aggregate with no GROUP BY always returns its one row, but SUM()
        // over no rows is NULL - so an empty class reads as zeroes here rather
        // than as three nulls the view would have to defend against.
        return [
            'eligible'  => (int) ($row['eligible']  ?? 0),
            'already'   => (int) ($row['already']   ?? 0),
            'suspended' => (int) ($row['suspended'] ?? 0),
        ];
    }

    /**
     * Enrol a whole class on a course. Idempotent: students already on the roll
     * are skipped, not errored, so running it twice is not a mistake and
     * running it after adding one new student to the class enrols exactly that
     * student.
     *
     * KNOWN GAP: the enrollments table is (student_id, course_id) and nothing
     * else - no enrolled_at, no enrolled_by. So once this has run there is no
     * record of WHEN a student joined a roll, or whether they arrived through
     * this bulk action or were added one at a time. A roll that gains a name
     * between two exams cannot be explained after the fact, and the list this
     * feeds therefore has no date column to sort on. Deliberately not fixed
     * here: adding the column is a migration and a backfill decision of its
     * own, not something to slip into a list-and-filter change.
     *
     * The write is a single INSERT ... SELECT guarded by NOT EXISTS rather than
     * a SELECT in PHP followed by a loop of INSERTs. That matters for more than
     * tidiness: a read-then-write leaves a window in which another admin enrols
     * one of the same students, and the duplicate key that follows would roll
     * back the whole batch. Here the guard is evaluated by the database as part
     * of the same statement, so the race has nowhere to open.
     *
     * INSERT IGNORE would also swallow duplicates, and is rejected on purpose:
     * it swallows every other error too - a bad course id, a truncated value -
     * and reports the batch as a success that wrote fewer rows than expected.
     *
     * The three numbers returned are counted, never inferred. `already` is a
     * real COUNT over the same predicate the INSERT negates, taken before the
     * write; subtracting it from the candidates instead would have labelled
     * every row that failed for any other reason as "already enrolled", which
     * is the one thing this list must not get wrong.
     *
     * `unaccounted` is what is left over: candidates - enrolled - already. It
     * should be zero, and the caller reports it when it is not rather than
     * folding it into a friendlier figure. It can legitimately be non-zero:
     * under InnoDB's REPEATABLE READ the two COUNTs are consistent reads from
     * the transaction's snapshot, while INSERT ... SELECT performs a LOCKING
     * read of the latest committed rows. A concurrent enrolment committed
     * between the snapshot and the write is therefore visible to the guard but
     * not to the count, and the arithmetic goes one short. That is a true and
     * useful thing to say out loud, not something to round away.
     *
     * @return array{candidates: int, enrolled: int, already: int,
     *               unaccounted: int, error: ?string}
     */
    public function enrollClass(int $courseId, int $classId): array
    {
        // Opened BEFORE the counts, not after: both COUNTs and the INSERT have
        // to be inside the same transaction, or the numbers reported back would
        // describe a state of the table that the write never saw.
        $this->db->beginTransaction();

        try {
            // Everyone the run considers: active students in the class,
            // whether or not they are already on the roll.
            $candidates = (int) $this->query(
                "SELECT COUNT(*) FROM users
                  WHERE role = 'student' AND status = 'active' AND class_id = ?",
                [$classId]
            )->fetchColumn();

            // The rows the INSERT below will pass over, counted with EXISTS
            // against the same predicate it negates. This has to run before the
            // INSERT - afterwards every candidate is enrolled and the answer is
            // always the whole class.
            $already = (int) $this->query(
                "SELECT COUNT(*)
                   FROM users u
                  WHERE u.role = 'student'
                    AND u.status = 'active'
                    AND u.class_id = ?
                    AND EXISTS (
                        SELECT 1 FROM enrollments e
                         WHERE e.student_id = u.id AND e.course_id = ?
                    )",
                [$classId, $courseId]
            )->fetchColumn();

            $inserted = $this->query(
                "INSERT INTO enrollments (student_id, course_id)
                 SELECT u.id, ?
                   FROM users u
                  WHERE u.role = 'student'
                    AND u.status = 'active'
                    AND u.class_id = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM enrollments e
                         WHERE e.student_id = u.id AND e.course_id = ?
                    )",
                [$courseId, $classId, $courseId]
            )->rowCount();

            $this->db->commit();

            return [
                'candidates'  => $candidates,
                'enrolled'    => $inserted,
                'already'     => $already,
                'unaccounted' => $candidates - $inserted - $already,
                'error'       => null,
            ];
        } catch (PDOException $e) {
            $this->db->rollBack();
            // Never echo the driver message: it can carry row data.
            error_log('Bulk enrolment failed: ' . $e->getMessage());

            return [
                'candidates'  => 0,
                'enrolled'    => 0,
                'already'     => 0,
                'unaccounted' => 0,
                'error'       => 'The enrolment failed and nothing was written. '
                               . 'Please try again.',
            ];
        }
    }
}
