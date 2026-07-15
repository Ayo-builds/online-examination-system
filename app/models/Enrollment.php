<?php
class Enrollment extends Model
{
    protected string $table = 'enrollments';

    // All students enrolled in a course
    public function studentsInCourse(int $courseId): array
    {
        return $this->query(
            "SELECT u.id, u.full_name, u.email, u.status
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
}