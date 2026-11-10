<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class ServiceCategory extends Model
{
    protected string $table = 'service_categories';

    public function getAllActive(): array
    {
        return $this->findAll(['is_active' => 1], 'sort_order ASC, name ASC');
    }

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(name LIKE ? OR code LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT * FROM `service_categories` WHERE {$whereStr}
             ORDER BY sort_order ASC, name ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return $stmt->fetchAll();
    }

    public function countFiltered(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(name LIKE ? OR code LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT COUNT(*) FROM `service_categories` WHERE {$whereStr}",
            $params
        );
        return (int)$stmt->fetchColumn();
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) FROM `service_categories` WHERE `code` = ?";
        $params = [$code];
        if ($excludeId) {
            $sql    .= " AND id != ?";
            $params[] = $excludeId;
        }
        return (int)$this->query($sql, $params)->fetchColumn() > 0;
    }
}