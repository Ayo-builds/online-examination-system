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
}