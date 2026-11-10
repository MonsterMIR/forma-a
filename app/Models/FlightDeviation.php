<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class FlightDeviation extends Model
{
    protected string $table = 'flight_deviations';

    /**
     * Найти уже существующее отклонение по конкретному документу — используется,
     * чтобы не создавать дубль, если отклонение уже было зафиксировано автоматически
     * (updateOverdueStatuses) до того, как документ фактически подали (submitDocument).
     */
    public function findByDocId(int $flightDocId): ?array
    {
        $row = $this->query(
            "SELECT * FROM flight_deviations WHERE flight_doc_id = ? LIMIT 1",
            [$flightDocId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getByFlight(int $flightId): array
    {
        return $this->query(
            "SELECT fd.*, u.full_name AS registered_by_name
             FROM flight_deviations fd
             LEFT JOIN users u ON u.id = fd.registered_by_user_id
             WHERE fd.flight_id = ?
             ORDER BY fd.created_at DESC",
            [$flightId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getFiltered(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['severity'])) {
            $where[]  = "fd.severity = ?";
            $params[] = $filters['severity'];
        }
        if (!empty($filters['service_role'])) {
            $where[]  = "fd.service_role = ?";
            $params[] = $filters['service_role'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = "f.flight_date >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[]  = "f.flight_date <= ?";
            $params[] = $filters['date_to'];
        }

        $whereStr = implode(' AND ', $where);

        return $this->query(
            "SELECT fd.*, f.flight_number, f.flight_date, f.operation_type,
                    al.name AS airline_name, al.iata_code AS airline_iata,
                    u.full_name AS registered_by_name
             FROM flight_deviations fd
             JOIN flights f ON f.id = fd.flight_id
             JOIN airlines al ON al.id = f.airline_id
             LEFT JOIN users u ON u.id = fd.registered_by_user_id
             WHERE {$whereStr}
             ORDER BY fd.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countFiltered(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['severity']))    { $where[] = "fd.severity = ?";     $params[] = $filters['severity']; }
        if (!empty($filters['service_role'])){ $where[] = "fd.service_role = ?"; $params[] = $filters['service_role']; }
        if (!empty($filters['date_from']))   { $where[] = "f.flight_date >= ?";  $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))     { $where[] = "f.flight_date <= ?";  $params[] = $filters['date_to']; }

        return (int)$this->query(
            "SELECT COUNT(*) FROM flight_deviations fd
             JOIN flights f ON f.id = fd.flight_id
             WHERE " . implode(' AND ', $where),
            $params
        )->fetchColumn();
    }

    public function getSummaryByRole(string $dateFrom, string $dateTo): array
    {
        return $this->query(
            "SELECT fd.service_role,
                    COUNT(*) AS total,
                    SUM(fd.severity = 'minor') AS minor,
                    SUM(fd.severity = 'significant') AS significant,
                    SUM(fd.severity = 'critical') AS critical
             FROM flight_deviations fd
             JOIN flights f ON f.id = fd.flight_id
             WHERE f.flight_date BETWEEN ? AND ?
             GROUP BY fd.service_role
             ORDER BY total DESC",
            [$dateFrom, $dateTo]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }
}
