<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

class PassengerManifest extends Model
{
    protected string $table = 'passenger_manifests';

    public function getByFlight(int $flightId): array
    {
        return $this->query(
            "SELECT pm.*, u.full_name AS created_by_name
             FROM passenger_manifests pm
             LEFT JOIN users u ON u.id = pm.created_by_user_id
             WHERE pm.flight_id = ?
             ORDER BY pm.manifest_type ASC, pm.created_at DESC",
            [$flightId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getByFlightAndType(int $flightId, string $type): ?array
    {
        // ИЗМЕНЕНО (24.10.2026, раунд 122, по прямому указанию пользователя):
        // добавлен JOIN на users (created_by_name) — нужно для плашки
        // "Манифест подан (кто, СОП)" на экране супервайзера, см.
        // ServiceOrderController::supervisorFlight(). Остальные вызывающие
        // места (ManifestController, FlightController, getEffectiveForBilling()
        // ниже) используют только уже существовавшие поля — новый столбец
        // просто игнорируют, ничего не ломает.
        $row = $this->query(
            "SELECT pm.*, u.full_name AS created_by_name
             FROM passenger_manifests pm
             LEFT JOIN users u ON u.id = pm.created_by_user_id
             WHERE pm.flight_id = ? AND pm.manifest_type = ?
             ORDER BY pm.created_at DESC LIMIT 1",
            [$flightId, $type]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * ДОБАВЛЕНО (11.09.2026, раунд 38): единая точка правды — какой именно
     * манифест считать действующим для биллинга (PAX_ARR/PAX_DEP/BAG_ARR_PC/
     * BAG_DEP_PC, см. AutoChargeService) и для проверки "без манифеста нет
     * акта" (см. FlightController::buildAct()).
     *
     * УПРОЩЕНО (21.09.2026, раунд 49, п.2, по прямому указанию пользователя):
     * "первичный" (предварительный) манифест убран из процесса — СОП подаёт
     * только один, окончательный манифест (тип в БД по-прежнему называется
     * 'final' — переименовывать колонку/значение смысла нет, старые
     * 'preliminary'-записи прошлых рейсов остаются в истории, но НЕ
     * участвуют в этой проверке). Раньше здесь было два типа с приоритетом
     * final → preliminary — теперь только final.
     *
     * ВАЖНО: манифест в статусе 'draft' НЕ считается поданным — он ещё
     * редактируется СОП и не является окончательными данными. Только
     * 'submitted'/'accepted' годятся как основание для начисления денег или
     * формирования акта.
     */
    public function getEffectiveForBilling(int $flightId): ?array
    {
        $final = $this->getByFlightAndType($flightId, 'final');
        if ($final && in_array($final['status'], ['submitted', 'accepted'], true)) {
            return $final;
        }
        return null;
    }

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['status']))        { $where[] = 'pm.status = ?';         $params[] = $filters['status']; }
        if (!empty($filters['manifest_type'])) { $where[] = 'pm.manifest_type = ?';  $params[] = $filters['manifest_type']; }
        if (!empty($filters['date_from']))     { $where[] = 'f.flight_date >= ?';    $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))       { $where[] = 'f.flight_date <= ?';    $params[] = $filters['date_to']; }

        return $this->query(
            "SELECT pm.*, f.flight_number, f.flight_date, f.operation_type,
                    al.name AS airline_name
             FROM passenger_manifests pm
             JOIN flights f ON f.id = pm.flight_id
             JOIN airlines al ON al.id = f.airline_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY f.flight_date DESC, pm.manifest_type ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countFiltered(array $filters = []): int
    {
        $where = ['1=1']; $params = [];
        if (!empty($filters['status']))        { $where[] = 'pm.status = ?';         $params[] = $filters['status']; }
        if (!empty($filters['manifest_type'])) { $where[] = 'pm.manifest_type = ?';  $params[] = $filters['manifest_type']; }
        if (!empty($filters['date_from']))     { $where[] = 'f.flight_date >= ?';    $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))       { $where[] = 'f.flight_date <= ?';    $params[] = $filters['date_to']; }

        return (int)$this->query(
            "SELECT COUNT(*) FROM passenger_manifests pm
             JOIN flights f ON f.id = pm.flight_id
             WHERE " . implode(' AND ', $where),
            $params
        )->fetchColumn();
    }
}
