<?php
class ActivityLog extends Model
{
    protected string $table = 'activity_logs';

    public function record(int $attemptId, string $eventType, ?array $data = null): void
    {
        $this->query(
            "INSERT INTO activity_logs (attempt_id, event_type, event_data)
             VALUES (?, ?, ?)",
            [$attemptId, $eventType, $data !== null ? json_encode($data) : null]
        );
    }

    public function countByType(int $attemptId): array
    {
        return $this->query(
            "SELECT event_type, COUNT(*) AS total
             FROM activity_logs
             WHERE attempt_id = ?
             GROUP BY event_type",
            [$attemptId]
        )->fetchAll();
    }

    public function forAttempt(int $attemptId): array
    {
        return $this->query(
            "SELECT event_type, event_data, created_at
             FROM activity_logs
             WHERE attempt_id = ?
             ORDER BY created_at ASC",
            [$attemptId]
        )->fetchAll();
    }
}