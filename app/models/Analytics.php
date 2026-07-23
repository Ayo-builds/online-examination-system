<?php
class Analytics extends Model
{
    protected string $table = 'exam_attempts';   // nominal; queries span tables

    // Top-line counts across the whole system
    public function systemCounts(): array
    {
        $row = $this->query(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active') AS students,
                (SELECT COUNT(*) FROM users WHERE role = 'lecturer' AND status = 'active') AS lecturers,
                (SELECT COUNT(*) FROM users WHERE status = 'suspended')                    AS suspended,
                (SELECT COUNT(*) FROM courses)                                             AS courses,
                (SELECT COUNT(*) FROM questions)                                           AS questions,
                (SELECT COUNT(*) FROM exams WHERE status = 'published')                    AS published_exams,
                (SELECT COUNT(*) FROM exams WHERE status = 'draft')                        AS draft_exams,
                (SELECT COUNT(*) FROM exam_attempts
                  WHERE status IN ('submitted','auto_submitted'))                          AS completed_attempts,
                (SELECT COUNT(*) FROM exam_attempts WHERE status = 'in_progress')          AS live_attempts"
        )->fetch();

        return $row ?: [];
    }

    // Integrity signals across all attempts
    public function integritySignals(): array
    {
        $row = $this->query(
            "SELECT COUNT(*)                                    AS total,
                    SUM(is_flagged)                             AS flagged,
                    SUM(status = 'auto_submitted')              AS timed_out,
                    (SELECT COUNT(*) FROM activity_logs)        AS total_events
             FROM exam_attempts
             WHERE status IN ('submitted','auto_submitted')"
        )->fetch();

        return $row ?: [];
    }

    // Activity event breakdown, most common first
    public function eventBreakdown(): array
    {
        return $this->query(
            "SELECT event_type, COUNT(*) AS total
             FROM activity_logs
             GROUP BY event_type
             ORDER BY total DESC"
        )->fetchAll();
    }

    // Per-course summary: enrollments, exams, attempts
    public function courseSummary(): array
    {
        return $this->query(
            "SELECT c.course_code, c.title, u.full_name AS lecturer_name,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id)  AS students,
                    (SELECT COUNT(*) FROM exams x WHERE x.course_id = c.id)        AS exams,
                    (SELECT COUNT(*) FROM exam_attempts a
                      JOIN exams x2 ON x2.id = a.exam_id
                      WHERE x2.course_id = c.id
                        AND a.status IN ('submitted','auto_submitted'))            AS attempts
             FROM courses c
             JOIN users u ON u.id = c.lecturer_id
             ORDER BY c.course_code"
        )->fetchAll();
    }
}