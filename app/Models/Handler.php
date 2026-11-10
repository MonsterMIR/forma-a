<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * ДОБАВЛЕНО (18.09.2026, раунд 46): справочник хендлеров — компаний-агентов,
 * представляющих авиакомпанию на перроне. Подпись в акте со стороны
 * "представителя авиакомпании" фактически ставит сотрудник хендлера, а не
 * сотрудник самой авиакомпании (см. HandlerEmployee, AirlineHandler и
 * анализ раунда 46, п.5/п.6).
 */
class Handler extends Model
{
    protected string $table = 'handlers';

    public function getFiltered(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = '`name` LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = '`is_active` = ?';
            $params[] = (int)$filters['is_active'];
        }
        $whereStr = implode(' AND ', $where);

        $sql = "SELECT * FROM `handlers` WHERE {$whereStr} ORDER BY `name` ASC";
        if ($limit > 0) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }
        return $this->query($sql, $params)->fetchAll();
    }

    public function countFiltered(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];
        if (!empty($filters['search'])) {
            $where[]  = '`name` LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = '`is_active` = ?';
            $params[] = (int)$filters['is_active'];
        }
        $whereStr = implode(' AND ', $where);
        return (int)$this->query("SELECT COUNT(*) FROM `handlers` WHERE {$whereStr}", $params)->fetchColumn();
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM `handlers` WHERE `name` = ?';
        $params = [$name];
        if ($excludeId) {
            $sql      .= ' AND id != ?';
            $params[]  = $excludeId;
        }
        return (int)$this->query($sql, $params)->fetchColumn() > 0;
    }

    /** Хендлеры, привязанные к конкретной авиакомпании (см. airline_handlers). */
    public function getForAirline(int $airlineId): array
    {
        return $this->query(
            "SELECT h.* FROM `handlers` h
             JOIN `airline_handlers` ah ON ah.handler_id = h.id
             WHERE ah.airline_id = ? AND h.is_active = 1
             ORDER BY h.name ASC",
            [$airlineId]
        )->fetchAll();
    }

    /** Все активные хендлеры — используется, когда для а/к ничего явно не привязано. */
    public function getAllActive(): array
    {
        return $this->query("SELECT * FROM `handlers` WHERE `is_active` = 1 ORDER BY `name` ASC")->fetchAll();
    }

    public function getAirlineIds(int $handlerId): array
    {
        $rows = $this->query(
            "SELECT airline_id FROM `airline_handlers` WHERE handler_id = ?",
            [$handlerId]
        )->fetchAll();
        return array_map('intval', array_column($rows, 'airline_id'));
    }

    public function syncAirlines(int $handlerId, array $airlineIds): void
    {
        $this->query("DELETE FROM `airline_handlers` WHERE handler_id = ?", [$handlerId]);
        foreach (array_unique(array_map('intval', $airlineIds)) as $airlineId) {
            if ($airlineId <= 0) continue;
            $this->query(
                "INSERT INTO `airline_handlers` (airline_id, handler_id) VALUES (?, ?)",
                [$airlineId, $handlerId]
            );
        }
    }
}
