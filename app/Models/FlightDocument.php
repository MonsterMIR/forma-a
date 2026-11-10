<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class FlightDocument extends Model
{
    protected string $table = 'flight_documents';

    public function getByFlight(int $flightId): array
    {
        return $this->query(
            "SELECT fd.*, u.full_name AS submitted_by_name
             FROM flight_documents fd
             LEFT JOIN users u ON u.id = fd.submitted_by_user_id
             WHERE fd.flight_id = ?
             ORDER BY fd.deadline_minutes ASC",
            [$flightId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getByFlightAndType(int $flightId, string $docType): ?array
    {
        $row = $this->query(
            "SELECT * FROM flight_documents WHERE flight_id = ? AND doc_type = ? LIMIT 1",
            [$flightId, $docType]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getPendingOverdue(): array
    {
        // ИЗМЕНЕНО (27.10.2026, раунд 129, по прямому указанию пользователя):
        // добавлено "AND f.status != 'cancelled'" — отменённый рейс
        // (FlightService::cancelFlight()) не состоялся физически, так что
        // его "просроченные" документы не должны и дальше порождать новые
        // записи в flight_deviations (updateOverdueStatuses() вызывается на
        // каждый просмотр статуса сроков документооборота) — без этой
        // правки отменённый рейс продолжал бы бесконечно копить нарушения.
        return $this->query(
            "SELECT fd.*, f.flight_number, f.flight_date, f.operation_type,
                    al.name AS airline_name
             FROM flight_documents fd
             JOIN flights f ON f.id = fd.flight_id
             JOIN airlines al ON al.id = f.airline_id
             WHERE fd.status = 'pending'
               AND fd.deadline_at IS NOT NULL
               AND fd.deadline_at < NOW()
               AND f.status != 'cancelled'
             ORDER BY fd.deadline_at ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getStatsByRole(string $role, string $dateFrom, string $dateTo): array
    {
        return $this->query(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'submitted') AS on_time,
                SUM(status = 'late') AS late,
                SUM(status = 'pending' AND deadline_at < NOW()) AS overdue
             FROM flight_documents fd
             JOIN flights f ON f.id = fd.flight_id
             WHERE fd.service_role = ?
               AND f.flight_date BETWEEN ? AND ?",
            [$role, $dateFrom, $dateTo]
        )->fetch(\PDO::FETCH_ASSOC);
    }
}
