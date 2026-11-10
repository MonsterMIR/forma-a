<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

class TechCard extends Model
{
    protected string $table = 'tech_cards';

    public function getByFlight(int $flightId): ?array
    {
        $row = $this->query(
            "SELECT tc.*, u.full_name AS created_by_name
             FROM tech_cards tc
             LEFT JOIN users u ON u.id = tc.created_by_user_id
             WHERE tc.flight_id = ? LIMIT 1",
            [$flightId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['status']))    { $where[] = 'tc.status = ?';        $params[] = $filters['status']; }
        if (!empty($filters['date_from'])) { $where[] = 'f.flight_date >= ?';   $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))   { $where[] = 'f.flight_date <= ?';   $params[] = $filters['date_to']; }

        return $this->query(
            "SELECT tc.*, f.flight_number, f.flight_date, f.operation_type,
                    al.name AS airline_name, al.iata_code AS airline_iata
             FROM tech_cards tc
             JOIN flights f ON f.id = tc.flight_id
             JOIN airlines al ON al.id = f.airline_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY f.flight_date DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countFiltered(array $filters = []): int
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['status']))    { $where[] = 'tc.status = ?';      $params[] = $filters['status']; }
        if (!empty($filters['date_from'])) { $where[] = 'f.flight_date >= ?'; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))   { $where[] = 'f.flight_date <= ?'; $params[] = $filters['date_to']; }

        return (int)$this->query(
            "SELECT COUNT(*) FROM tech_cards tc
             JOIN flights f ON f.id = tc.flight_id
             WHERE " . implode(' AND ', $where),
            $params
        )->fetchColumn();
    }
}
