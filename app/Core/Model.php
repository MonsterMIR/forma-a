<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

abstract class Model
{
    protected PDO $db;
    protected string $table;
    protected string $primaryKey = 'id';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function find(int|string $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findAll(array $conditions = [], string $orderBy = '', int $limit = 0, int $offset = 0): array
    {
        $sql = "SELECT * FROM `{$this->table}`";
        $params = [];

        if (!empty($conditions)) {
            $clauses = [];
            foreach ($conditions as $col => $val) {
                $clauses[] = "`{$col}` = ?";
                $params[] = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }

        if ($limit > 0) {
            $sql .= " LIMIT {$limit}";
            if ($offset > 0) {
                $sql .= " OFFSET {$offset}";
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function insert(array $data): int|string
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            "INSERT INTO `%s` (`%s`) VALUES (%s)",
            $this->table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));
        return $this->db->lastInsertId();
    }

    public function update(int|string $id, array $data): bool
    {
        if (empty($data)) return false;

        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = "`{$col}` = ?";
        }

        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE `%s` = ?",
            $this->table,
            implode(', ', $sets),
            $this->primaryKey
        );

        $params = array_values($data);
        $params[] = $id;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int|string $id): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = ?"
        );
        return $stmt->execute([$id]);
    }

    public function count(array $conditions = []): int
    {
        $sql = "SELECT COUNT(*) FROM `{$this->table}`";
        $params = [];

        if (!empty($conditions)) {
            $clauses = [];
            foreach ($conditions as $col => $val) {
                $clauses[] = "`{$col}` = ?";
                $params[] = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    protected function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        $this->db->rollBack();
    }
}