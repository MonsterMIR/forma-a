<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

class EquipmentLog extends Model
{
    protected string $table = 'equipment_logs';

    public function getByFlight(int $flightId): ?array
    {
        $row = $this->query(
            "SELECT el.*, u.full_name AS created_by_name
             FROM equipment_logs el
             LEFT JOIN users u ON u.id = el.created_by_user_id
             WHERE el.flight_id = ? LIMIT 1",
            [$flightId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['status']))    { $where[] = 'el.status = ?';      $params[] = $filters['status']; }
        if (!empty($filters['date_from'])) { $where[] = 'f.flight_date >= ?'; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))   { $where[] = 'f.flight_date <= ?'; $params[] = $filters['date_to']; }

        return $this->query(
            "SELECT el.*, f.flight_number, f.flight_date, f.operation_type,
                    al.name AS airline_name
             FROM equipment_logs el
             JOIN flights f ON f.id = el.flight_id
             JOIN airlines al ON al.id = f.airline_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY f.flight_date DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countFiltered(array $filters = []): int
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['status']))    { $where[] = 'el.status = ?';      $params[] = $filters['status']; }
        if (!empty($filters['date_from'])) { $where[] = 'f.flight_date >= ?'; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))   { $where[] = 'f.flight_date <= ?'; $params[] = $filters['date_to']; }

        return (int)$this->query(
            "SELECT COUNT(*) FROM equipment_logs el
             JOIN flights f ON f.id = el.flight_id
             WHERE " . implode(' AND ', $where),
            $params
        )->fetchColumn();
    }
}
