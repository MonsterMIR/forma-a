<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class User extends Model
{
    protected string $table = 'users';

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->query(
            "SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM `users` u
             JOIN `roles` r ON r.id = u.role_id
             WHERE u.username = ? LIMIT 1",
            [$username]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findWithRole(int $id): ?array
    {
        $stmt = $this->query(
            "SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM `users` u
             JOIN `roles` r ON r.id = u.role_id
             WHERE u.id = ? LIMIT 1",
            [$id]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findAllWithRole(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "u.is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        if (!empty($filters['role_id'])) {
            $where[]  = "u.role_id = ?";
            $params[] = (int)$filters['role_id'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM `users` u
             JOIN `roles` r ON r.id = u.role_id
             WHERE {$whereStr}
             ORDER BY u.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return $stmt->fetchAll();
    }

    public function countWithFilters(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "u.is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        if (!empty($filters['role_id'])) {
            $where[]  = "u.role_id = ?";
            $params[] = (int)$filters['role_id'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT COUNT(*) FROM `users` u
             JOIN `roles` r ON r.id = u.role_id
             WHERE {$whereStr}",
            $params
        );
        return (int)$stmt->fetchColumn();
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) FROM `users` WHERE `username` = ?";
        $params = [$username];
        if ($excludeId) {
            $sql    .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $this->query($sql, $params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) FROM `users` WHERE `email` = ?";
        $params = [$email];
        if ($excludeId) {
            $sql    .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $this->query($sql, $params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function updateLastLogin(int $id): void
    {
        $this->update($id, ['last_login_at' => date('Y-m-d H:i:s')]);
    }
}