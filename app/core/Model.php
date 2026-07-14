<?php
abstract class Model
{
    protected PDO $db;
    protected string $table = '';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // The workhorse: every query in the system flows through here
    protected function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Fetch one row by primary key
    public function find(int $id): ?array
    {
        $row = $this->query(
            "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1",
            [$id]
        )->fetch();

        return $row ?: null;
    }

    // Fetch every row in the table
    public function all(): array
    {
        return $this->query("SELECT * FROM {$this->table}")->fetchAll();
    }
}