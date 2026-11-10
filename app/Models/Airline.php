<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Airline extends Model
{
    protected string $table = 'airlines';

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(name LIKE ? OR iata_code LIKE ? OR icao_code LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT * FROM `airlines` WHERE {$whereStr}
             ORDER BY name ASC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return $stmt->fetchAll();
    }

    public function countFiltered(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(name LIKE ? OR iata_code LIKE ? OR icao_code LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT COUNT(*) FROM `airlines` WHERE {$whereStr}",
            $params
        );
        return (int)$stmt->fetchColumn();
    }

    public function getAllActive(): array
    {
        return $this->findAll(['is_active' => 1], 'name ASC');
    }
}