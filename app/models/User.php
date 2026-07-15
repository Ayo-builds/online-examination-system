<?php
class User extends Model
{
    protected string $table = 'users';

    public function findByEmail(string $email): ?array
    {
        $row = $this->query(
            "SELECT * FROM users WHERE email = ? LIMIT 1",
            [$email]
        )->fetch();

        return $row ?: null;
    }

    // All users, newest first — for the admin list
    public function allByNewest(): array
    {
        return $this->query(
            "SELECT id, full_name, email, role, status, created_at
             FROM users
             ORDER BY created_at DESC"
        )->fetchAll();
    }

    public function create(string $fullName, string $email, string $password, string $role): int
    {
        $this->query(
            "INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)",
            [$fullName, $email, password_hash($password, PASSWORD_DEFAULT), $role]
        );

        return (int) $this->db->lastInsertId();
    }
   

    public function setStatus(int $id, string $status): void
    {
        $this->query(
            "UPDATE users SET status = ? WHERE id = ?",
            [$status, $id]
        );
    }
}
    
