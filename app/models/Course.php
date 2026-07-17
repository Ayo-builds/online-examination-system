<?php
class Course extends Model
{
    protected string $table = 'courses';

    // All courses with their lecturer's name attached
    public function allWithLecturer(): array
    {
        return $this->query(
            "SELECT c.id, c.course_code, c.title, u.full_name AS lecturer_name
             FROM courses c
             JOIN users u ON u.id = c.lecturer_id
             ORDER BY c.course_code"
        )->fetchAll();
    }

    public function findByCode(string $code): ?array
    {
        $row = $this->query(
            "SELECT * FROM courses WHERE course_code = ? LIMIT 1",
            [$code]
        )->fetch();

        return $row ?: null;
    }

    public function create(string $code, string $title, int $lecturerId): int
    {
        $this->query(
            "INSERT INTO courses (course_code, title, lecturer_id) VALUES (?, ?, ?)",
            [$code, $title, $lecturerId]
        );

        return (int) $this->db->lastInsertId();
        
    }

    // Courses taught by one lecturer, with enrollment counts
    public function byLecturer(int $lecturerId): array
    {
        return $this->query(
            "SELECT c.id, c.course_code, c.title,
                    COUNT(e.student_id) AS student_count
             FROM courses c
             LEFT JOIN enrollments e ON e.course_id = c.id
             WHERE c.lecturer_id = ?
             GROUP BY c.id, c.course_code, c.title
             ORDER BY c.course_code",
            [$lecturerId]
        )->fetchAll();
    }

    // Returns the course ONLY if it belongs to this lecturer; null otherwise
    public function findOwned(int $courseId, int $lecturerId): ?array
    {
        $row = $this->query(
            "SELECT * FROM courses WHERE id = ? AND lecturer_id = ? LIMIT 1",
            [$courseId, $lecturerId]
        )->fetch();

        return $row ?: null;
    }
}