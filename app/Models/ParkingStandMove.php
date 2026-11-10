<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDO;

/**
 * ДОБАВЛЕНО (20.10.2026, раунд 104, по прямому указанию пользователя):
 * "стоянка привязывается в начале на акт прилета, на акте вылета может
 * быть другая стоянка, так как во время стоянки борт могут перенести на
 * другую стоянку... получается мы должны вести учет стоянки с учетом
 * того что может быть смена стоянки. естественно при смене стоянки.
 * освобожденная стоянка должна стать доступной для других ВС."
 *
 * До этого раунда занятость стоянки определялась ТОЛЬКО по двум статичным
 * полям (flights.mc_number на рейсе прилёта и на связанном рейсе вылета) —
 * если борт переставляли посреди визита, старая стоянка оставалась
 * "занятой" до самого вылета (см. анализ раунда 104, часть 3). Эта таблица
 * — история переносов В РАМКАХ ОДНОГО ВИЗИТА (одна ротация борта:
 * прилёт -> ... -> вылет), ключ — ВСЕГДА id рейса ПРИЛЁТА (якорь визита,
 * не меняется при переносах). ПОСЛЕДНЯЯ по времени/id запись для данного
 * arrival_flight_id — это физически ТЕКУЩАЯ стоянка визита.
 *
 * Биллинг стоянки эта таблица НЕ затрагивает — плата считается от
 * прилёта до вылета независимо от того, сколько раз и куда борт
 * переставляли (так и было сказано пользователем), см. AutoChargeService.
 * Используется только App\Services\ParkingStandService — единой точкой
 * проверки занятости/освобождения стоянок.
 */
class ParkingStandMove extends Model
{
    protected string $table = 'flight_stand_moves';

    public function create(array $data): int|string
    {
        return $this->insert($data);
    }

    /**
     * Полная история переносов данного визита, по возрастанию времени —
     * для отображения (История на планшете супервайзера пишется отдельно
     * через user_activity_log/logFlightAction, эта выборка — для будущих
     * экранов, которым понадобится именно хронология перестановок).
     */
    public function getHistoryForArrival(int $arrivalFlightId): array
    {
        return $this->query(
            "SELECT * FROM flight_stand_moves WHERE arrival_flight_id = ? ORDER BY moved_at ASC, id ASC",
            [$arrivalFlightId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Текущая (последняя) запись переноса для визита, либо null, если по
     * этому визиту переносов ещё не было ни одного (в т.ч. самого первого
     * "первичного назначения" — см. ParkingStandService::recordMove(),
     * который сам создаёт первую запись при самом первом переносе).
     */
    public function getLatestForArrival(int $arrivalFlightId): ?array
    {
        $stmt = $this->query(
            "SELECT * FROM flight_stand_moves WHERE arrival_flight_id = ? ORDER BY moved_at DESC, id DESC LIMIT 1",
            [$arrivalFlightId]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function hasAnyMoveForArrival(int $arrivalFlightId): bool
    {
        $stmt = $this->query(
            "SELECT COUNT(*) FROM flight_stand_moves WHERE arrival_flight_id = ?",
            [$arrivalFlightId]
        );
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * id рейсов-прилётов (визитов), у которых ПОСЛЕДНЯЯ по времени/id
     * запись переноса указывает именно на $standCode — то есть эта
     * стоянка сейчас физически занята этими визитами (обычно — одним,
     * но метод не предполагает этого и возвращает все совпадения, чтобы
     * вызывающий код сам решил, что делать при двойном совпадении —
     * это само по себе был бы признак гонки/ошибки данных).
     */
    public function findActiveArrivalIdsByStand(string $standCode, ?int $excludeArrivalId = null): array
    {
        $sql = "SELECT psm.arrival_flight_id
                FROM flight_stand_moves psm
                WHERE psm.stand_code = ?
                  AND psm.id = (
                      SELECT MAX(id) FROM flight_stand_moves WHERE arrival_flight_id = psm.arrival_flight_id
                  )";
        $params = [$standCode];
        if ($excludeArrivalId !== null) {
            $sql .= " AND psm.arrival_flight_id != ?";
            $params[] = $excludeArrivalId;
        }
        $stmt = $this->query($sql, $params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
