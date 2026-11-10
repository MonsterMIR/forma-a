<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Airport extends Model
{
    protected string $table = 'airports';

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(name LIKE ? OR iata_code LIKE ? OR city LIKE ? OR country LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
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
            "SELECT * FROM `airports` WHERE {$whereStr}
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
            $where[]  = "(name LIKE ? OR iata_code LIKE ? OR city LIKE ? OR country LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
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
            "SELECT COUNT(*) FROM `airports` WHERE {$whereStr}",
            $params
        );
        return (int)$stmt->fetchColumn();
    }

    public function getAllActive(): array
    {
        return $this->findAll(['is_active' => 1], 'name ASC');
    }

    /**
     * Находит аэропорт по IATA-коду (используется для определения "домашнего"
     * аэропорта TVT — Ташкент-Восточный — при автоподстановке в форме рейса).
     */
    public function findByIata(string $iataCode): ?array
    {
        $stmt = $this->query(
            "SELECT * FROM `airports` WHERE iata_code = ? LIMIT 1",
            [$iataCode]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }
}