<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

class ParkingAct extends Model
{
    protected string $table = 'parking_acts';

    public function getByFlight(int $flightId): ?array
    {
        $row = $this->query(
            "SELECT pa.*, u.full_name AS created_by_name
             FROM parking_acts pa
             LEFT JOIN users u ON u.id = pa.created_by_user_id
             WHERE pa.flight_id = ? LIMIT 1",
            [$flightId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['status']))    { $where[] = 'pa.status = ?';      $params[] = $filters['status']; }
        if (!empty($filters['date_from'])) { $where[] = 'f.flight_date >= ?'; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))   { $where[] = 'f.flight_date <= ?'; $params[] = $filters['date_to']; }

        return $this->query(
            "SELECT pa.*, f.flight_number, f.flight_date, f.operation_type,
                    al.name AS airline_name
             FROM parking_acts pa
             JOIN flights f ON f.id = pa.flight_id
             JOIN airlines al ON al.id = f.airline_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY f.flight_date DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countFiltered(array $filters = []): int
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['status']))    { $where[] = 'pa.status = ?';      $params[] = $filters['status']; }
        if (!empty($filters['date_from'])) { $where[] = 'f.flight_date >= ?'; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))   { $where[] = 'f.flight_date <= ?'; $params[] = $filters['date_to']; }

        return (int)$this->query(
            "SELECT COUNT(*) FROM parking_acts pa
             JOIN flights f ON f.id = pa.flight_id
             WHERE " . implode(' AND ', $where),
            $params
        )->fetchColumn();
    }
}
