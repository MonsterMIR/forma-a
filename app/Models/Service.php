<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Service extends Model
{
    protected string $table = 'services';

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->query(
            "SELECT s.*, sc.name AS category_name, c.code AS currency_code, c.symbol AS currency_symbol
             FROM `services` s
             JOIN `service_categories` sc ON sc.id = s.category_id
             JOIN `currencies` c ON c.id = s.default_currency_id
             WHERE s.id = ? LIMIT 1",
            [$id]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(s.name LIKE ? OR s.code LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        if (!empty($filters['category_id'])) {
            $where[]  = "s.category_id = ?";
            $params[] = (int)$filters['category_id'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "s.is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT s.*, sc.name AS category_name, c.code AS currency_code
             FROM `services` s
             JOIN `service_categories` sc ON sc.id = s.category_id
             JOIN `currencies` c ON c.id = s.default_currency_id
             WHERE {$whereStr}
             ORDER BY sc.sort_order ASC, s.name ASC
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
            $where[]  = "(s.name LIKE ? OR s.code LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        if (!empty($filters['category_id'])) {
            $where[]  = "s.category_id = ?";
            $params[] = (int)$filters['category_id'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "s.is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT COUNT(*) FROM `services` s
             JOIN `service_categories` sc ON sc.id = s.category_id
             JOIN `currencies` c ON c.id = s.default_currency_id
             WHERE {$whereStr}",
            $params
        );
        return (int)$stmt->fetchColumn();
    }

    public function getAllActive(): array
    {
        $stmt = $this->query(
            "SELECT s.*, sc.name AS category_name, sc.code AS category_code,
                    sc.sort_order AS category_sort,
                    c.code AS currency_code
             FROM `services` s
             JOIN `service_categories` sc ON sc.id = s.category_id
             JOIN `currencies` c ON c.id = s.default_currency_id
             WHERE s.is_active = 1
             ORDER BY sc.sort_order ASC, s.name ASC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Возвращает все активные услуги, помеченные как "по умолчанию".
     * @deprecated Автоматическое добавление в акт убрано (см. ActService).
     * Услуги теперь берутся только из flight_service_charges.
     * Метод оставлен для экстренного ручного использования администратором.
     */
    public function getDefaultServices(): array
    {
        // is_default column is added by migration_001_mysql8.sql
        // If migration not run yet, return empty array (graceful fallback)
        try {
            $stmt = $this->query(
                "SELECT s.*, sc.name AS category_name, sc.code AS category_code,
                        c.code AS currency_code, c.id AS currency_id_resolved
                 FROM `services` s
                 JOIN `service_categories` sc ON sc.id = s.category_id
                 JOIN `currencies` c ON c.id = s.default_currency_id
                 WHERE s.is_active = 1 AND s.is_default = 1
                 ORDER BY sc.sort_order ASC, s.name ASC"
            );
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            // Column is_default does not exist yet - migration not run
            return [];
        }
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) FROM `services` WHERE `code` = ?";
        $params = [$code];
        if ($excludeId) {
            $sql    .= " AND id != ?";
            $params[] = $excludeId;
        }
        return (int)$this->query($sql, $params)->fetchColumn() > 0;
    }
}
