<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * ДОБАВЛЕНО (22.10.2026, раунд 117, по прямому указанию пользователя):
 * мастер-справочник бортов — то, что физически принадлежит САМОМУ борту
 * (тип ВС, MTOW), а не конкретному рейсу. Повод: 9 из 45 бортов системы
 * имели РАЗНЫЙ MTOW на разных своих рейсах — диспетчер ЦОК вводил его
 * заново руками на каждом рейсе вместо того, чтобы взять один раз
 * подтверждённое значение (см. claude/analysis-2026-10-22-round116...
 * и обсуждение в чате 22.10.2026).
 *
 * `mtow = NULL` означает «значение ещё не подтверждено» — ТРИ случая:
 *  1) Борт новый, ни разу не встречался — обычный путь: ЦОК вводит вручную
 *     на форме рейса, FlightService::createFlight() сам заводит сюда
 *     запись с этим значением как подтверждённую.
 *  2) Борт УЖЕ встречался, но его MTOW расходился по истории рейсов на
 *     момент раунда 117 (9 бортов) — специально НЕ подтверждён
 *     автоматически, ждёт решения начальника ЦОК, какое значение верное
 *     (см. `resolution_notes` — там перечислены расходящиеся значения).
 *     Для таких бортов поведение формы рейса остаётся как было (ручной
 *     ввод MTOW), пока запись не подтвердят через /aircraft-status.
 *  3) Борт в справочнике есть, но его подтверждённый MTOW оказался
 *     ошибочным и администратор его СБРОСИЛ (редкий ручной случай) —
 *     ведёт себя как случай 2.
 *
 * Пока `mtow IS NULL` — FlightService НИЧЕГО не подставляет и не
 * блокирует поле, обычный ручной ввод. Как только `mtow` заполнен —
 * FlightService::createFlight()/updateFlight() всегда берут его ИЗ
 * справочника, значение с формы игнорируется (нельзя разойтись снова).
 */
class Aircraft extends Model
{
    protected string $table = 'aircraft';

    public function findByRegistration(string $registration): ?array
    {
        $registration = strtoupper(trim($registration));
        if ($registration === '') {
            return null;
        }
        $row = $this->query(
            "SELECT * FROM `aircraft` WHERE registration = ? LIMIT 1",
            [$registration]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Заводит борт с подтверждённым MTOW, если его ещё нет в справочнике.
     * НИКОГДА не перезаписывает уже существующую запись (ни подтверждённую,
     * ни «спорную») — для изменения уже существующей записи см. update()
     * через AircraftController::save() (ручное действие администратора/ЦОК).
     */
    public function createIfMissing(string $registration, ?string $aircraftType, float $mtow): void
    {
        $registration = strtoupper(trim($registration));
        if ($registration === '' || $this->findByRegistration($registration)) {
            return;
        }
        // ИЗМЕНЕНО (02.11.2026, раунд 142, по требованию бухгалтерии): MTOW
        // справочника бортов округляется вверх до целого — см. комментарий
        // в FlightService::createFlight()/updateFlight().
        $this->insert([
            'registration'  => $registration,
            'aircraft_type' => $aircraftType ? trim($aircraftType) : null,
            'mtow'          => (float)ceil($mtow),
        ]);
    }

    /**
     * Заводит/обновляет запись как «спорную» (mtow=NULL) — используется
     * сид-скриптом раунда 117 для бортов с расхождением в истории.
     */
    public function upsertUnresolved(string $registration, ?string $aircraftType, string $notes): void
    {
        $registration = strtoupper(trim($registration));
        if ($registration === '') {
            return;
        }
        $existing = $this->findByRegistration($registration);
        $data = [
            'registration'     => $registration,
            'aircraft_type'    => $aircraftType ? trim($aircraftType) : null,
            'mtow'             => null,
            'resolution_notes' => $notes,
        ];
        if ($existing) {
            $this->update((int)$existing['id'], $data);
        } else {
            $this->insert($data);
        }
    }

    /**
     * Ручное подтверждение/правка MTOW администратором (AircraftController::save()).
     */
    /**
     * ДОБАВЛЕНО (05.11.2026, раунд 152, найдено пользователем на борту T7SKY1):
     * пользователь ввёл MTOW 60т по ошибке на рейсе, затем исправил борт в
     * этом справочнике на верные 22т — но уже СУЩЕСТВУЮЩИЕ рейсы этого борта
     * остались со старым (60т) значением: confirm() ниже раньше только писал
     * в таблицу aircraft, подстановка в flights.aircraft_mtow была только
     * ОДНОСТОРОННЕЙ и только ВПЕРЁД — при создании/редактировании НОВОГО
     * рейса через lookup() (см. AircraftController::lookup(), раунд 117).
     * Задним числом на уже существующие рейсы ничего не распространялось.
     * Теперь подтверждение/правка MTOW в этом справочнике сразу синхронизирует
     * ВСЕ ещё не заблокированные (без утверждённого/отменённого/
     * экспортированного акта, см. Act::LOCKED_STATUSES) активные рейсы этого
     * борта — см. cascadeMtowToOpenFlights() ниже. Рейсы с уже заблокированным
     * актом намеренно не трогаются — это уже подписанный документ, его меняют
     * только отдельной административной правкой, как и везде в системе.
     */
    public function confirm(int $id, ?string $aircraftType, float $mtow, int $userId): void
    {
        $existing = $this->find($id);

        // ИЗМЕНЕНО (02.11.2026, раунд 142, по требованию бухгалтерии): округление
        // вверх до целого — та же причина, что и в createIfMissing() выше.
        $confirmedMtow = (float)ceil($mtow);
        $this->update($id, [
            'aircraft_type'      => $aircraftType ? trim($aircraftType) : null,
            'mtow'               => $confirmedMtow,
            'resolution_notes'   => null,
            'updated_by_user_id' => $userId,
        ]);

        if (!empty($existing['registration'] ?? null)) {
            $this->cascadeMtowToOpenFlights((string)$existing['registration'], $confirmedMtow);
        }
    }

    /**
     * Переносит подтверждённый MTOW на все ОТКРЫТЫЕ (не заблокированные
     * утверждённым/отменённым/экспортированным актом) активные рейсы этого
     * борта — см. докблок confirm() выше. Рейсы, где значение уже совпадает,
     * не трогаются (нет лишних записей в user_activity_log).
     */
    private function cascadeMtowToOpenFlights(string $registration, float $mtow): void
    {
        $locked = \App\Models\Act::LOCKED_STATUSES;
        $placeholders = implode(',', array_fill(0, count($locked), '?'));
        $rows = $this->query(
            "SELECT f.id, f.aircraft_mtow
             FROM `flights` f
             LEFT JOIN `acts` a ON a.flight_id = f.id
             WHERE f.aircraft_registration = ?
               AND f.status = 'active'
               AND (f.aircraft_mtow IS NULL OR f.aircraft_mtow <> ?)
               AND (a.id IS NULL OR a.status NOT IN ({$placeholders}))",
            array_merge([$registration, $mtow], $locked)
        )->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return;
        }

        $db  = \App\Core\Database::getInstance();
        $upd = $db->prepare("UPDATE `flights` SET aircraft_mtow = ? WHERE id = ?");
        foreach ($rows as $row) {
            $oldMtow = $row['aircraft_mtow'];
            $upd->execute([$mtow, (int)$row['id']]);
            \App\Core\ActivityLogger::log(
                'flight', (int)$row['id'], 'aircraft_mtow_cascade_update', 'success',
                "MTOW рейса синхронизирован со справочником бортов ({$registration}): "
                . ($oldMtow === null ? 'не указан' : $oldMtow) . " -> {$mtow} т.",
                ['aircraft_mtow' => $oldMtow],
                ['aircraft_mtow' => $mtow]
            );
        }
    }

    /**
     * ДОБАВЛЕНО (06.11.2026, раунд 154, по прямому указанию пользователя):
     * «базирование ВС» — признак, что борт постоянно/долгосрочно базируется
     * в нашем аэропорту (в отличие от обычных «гостевых» бортов, которые
     * просто периодически прилетают и улетают). Пользователь явно описал
     * ключевое отличие: у обычного борта цепочка визита «прилёт → вылет»
     * (прилетел, какое-то время постоял, улетел), а у базирующегося у нас
     * борта — НАОБОРОТ, «вылет → прилёт» (борт и так стоит у нас постоянно;
     * он сначала УЛЕТАЕТ куда-то, потом ВОЗВРАЩАЕТСЯ) — см. живой пример
     * T7AAA7 (round69/round135): первый зафиксированный в системе рейс
     * этого борта — ВЫЛЕТ без пары, что полностью соответствует этой модели.
     *
     * Этот раунд реализует только САМ ПРИЗНАК и правку направления
     * автоматической хронологической связки рейсов (см.
     * Flight::findChronologicalLinkCandidates(), FlightService::
     * autoLinkFlight()) — именно это пользователь прямо назвал незакрытым
     * вопросом. Тарифы для базирующихся бортов, аренда ангаров/техобслуживание
     * и механизм «промежуточных» актов, поглощаемых следующим актом вылета,
     * — отдельные, более крупные решения, которые пользователь попросил
     * сначала обсудить отдельно (см. round154 project doc, раздел
     * «Открытые вопросы») — в этом раунде НЕ реализованы.
     *
     * Список базирующихся бортов пользователь пока не назвал окончательно
     * («порядка 5-7 бортов», решение не принято) — поэтому по умолчанию
     * `is_based = 0` у всех бортов, включение — вручную через /aircraft-status
     * (admin/cok), см. setBased() ниже.
     */
    public function setBased(int $id, bool $isBased, ?string $note, int $userId): void
    {
        $existing = $this->find($id);
        if (!$existing) {
            return;
        }

        $this->update($id, [
            'is_based'           => $isBased ? 1 : 0,
            'based_note'         => ($note !== null && trim($note) !== '') ? trim($note) : null,
            'updated_by_user_id' => $userId,
        ]);

        \App\Core\ActivityLogger::log(
            'aircraft', $id, 'aircraft_set_based', 'success',
            'Борт ' . ($existing['registration'] ?? '') . ': признак базирования '
                . ((int)($existing['is_based'] ?? 0)) . ' -> ' . ($isBased ? 1 : 0),
            ['is_based' => (int)($existing['is_based'] ?? 0), 'based_note' => $existing['based_note'] ?? null],
            ['is_based' => $isBased ? 1 : 0, 'based_note' => $note]
        );
    }

    /**
     * Весь справочник + последнее по факту движение борта.
     *
     * ИСПРАВЛЕНО (23.10.2026, раунд 119, по прямому указанию пользователя):
     * "справочник бортов показывает на земле 2 борта, а стоянки показывают
     * занятыми почти все — это не связанные друг с другом данные?" — да,
     * были не связаны, и справочник бортов был НЕПРАВ. Раньше "на земле" /
     * "улетел" считалось тупо по тому, какой рейс для борта ПОСЛЕДНИЙ по
     * дате/времени в базе: если вылет — "улетел", даже если этот вылет
     * ещё НЕ НАСТУПИЛ (ЦОК часто вводит сразу весь визит по разрешению,
     * включая плановую дату вылета наперёд) или фактически не подтверждён
     * (колодки на вылете никто не отметил). У стоянок (ParkingStandService::
     * isFreed()) давно было строже и правильнее: стоянка считается
     * свободной ТОЛЬКО когда по вылету той же ротации реально подтверждена
     * уборка колодок. Теперь "на земле" в этом справочнике считается ТЕМ ЖЕ
     * способом (см. isRotationFreed()/resolveDepartureFlightId()/
     * resolveChocksOffDateTime() ниже — осознанно независимая копия той же
     * логики, что и в ParkingStandService/AutoChargeService — см. их
     * докблоки, почему каждый сервис держит свою копию, а не делит общий
     * класс), так что обе страницы (/aircraft-status и /parking-acts)
     * теперь согласованы.
     */
    /**
     * ДОБАВЛЕНО (04.11.2026, раунд 149, по прямому указанию пользователя,
     * найдено на бортах T7FFI/YLWAY): отменённый рейс (flights.status=
     * 'cancelled') исключён из подзапроса ranked ниже (AND f.status !=
     * 'cancelled') -- он не должен считаться последним движением борта,
     * т.к. физически не состоялся. Без этого фильтра ROW_NUMBER() мог
     * выбрать отменённый прилёт/вылет как самый свежий, и борт навсегда
     * зависал в справочнике как 'на земле у нас', даже если его реальный
     * (неотменённый) последний визит уже улетел с подтверждённой уборкой
     * колодок. Та же логика уже была в ParkingStandService::isFreed() с
     * раунда 129 -- справочник бортов (раунд 117/119) появился раньше
     * отмены рейсов (раунд 129) и не был тогда обновлён. См.
     * claude/analysis-2026-11-04-round149-fleet-status-cancelled-flight-bug-fixed.md.
     */
    public function getAllWithFleetStatus(): array
    {
        $rows = $this->query(
            "SELECT
                 a.id AS aircraft_id, a.registration, a.aircraft_type AS reg_aircraft_type, a.mtow,
                 a.resolution_notes, a.updated_at AS mtow_updated_at,
                 ranked.id AS flight_id, ranked.flight_number, ranked.flight_date,
                 ranked.scheduled_time, ranked.operation_type, ranked.aircraft_type AS flight_aircraft_type,
                 ranked.mc_number, ranked.linked_flight_id, al.name AS airline_name
             FROM `aircraft` a
             LEFT JOIN (
                 SELECT f.id, f.flight_number, f.flight_date, f.scheduled_time, f.operation_type,
                        f.aircraft_registration, f.aircraft_type, f.mc_number, f.linked_flight_id,
                        f.airline_id,
                        ROW_NUMBER() OVER (
                            PARTITION BY f.aircraft_registration
                            ORDER BY f.flight_date DESC, COALESCE(f.scheduled_time, '00:00:00') DESC, f.id DESC
                        ) AS rn
                 FROM `flights` f
                 WHERE f.aircraft_registration IS NOT NULL AND f.aircraft_registration <> ''
                   AND f.status != 'cancelled'
             ) ranked ON ranked.rn = 1 AND ranked.aircraft_registration = a.registration
             LEFT JOIN `airlines` al ON al.id = ranked.airline_id
             ORDER BY (a.mtow IS NULL) DESC, ranked.flight_date DESC, ranked.scheduled_time DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['on_ground'] = !empty($row['flight_id']) ? $this->isRotationFreed($row) === false : false;
        }
        unset($row);

        return $rows;
    }

    /**
     * ДОБАВЛЕНО (23.10.2026, раунд 119) — см. докблок getAllWithFleetStatus()
     * выше. true = по вылету этой ротации подтверждена уборка колодок
     * (борт реально улетел), false = не подтверждена (борт считается на
     * земле — либо вылета вообще ещё нет в базе, либо есть, но ещё не
     * подтверждён/не наступил).
     */
    private function isRotationFreed(array $row): bool
    {
        $departureFlightId = $this->resolveDepartureFlightId($row);
        if ($departureFlightId === null) {
            return false;
        }
        return $this->resolveChocksOffDateTime($departureFlightId) !== null;
    }

    private function resolveDepartureFlightId(array $row): ?int
    {
        $operationType = (string)($row['operation_type'] ?? '');
        if ($operationType === 'departure') {
            return (int)$row['flight_id'];
        }
        if ($operationType === 'arrival') {
            $linkedId = (int)($row['linked_flight_id'] ?? 0);
            if ($linkedId <= 0) {
                return null;
            }
            $linked = $this->query(
                "SELECT id, operation_type FROM `flights` WHERE id = ? LIMIT 1",
                [$linkedId]
            )->fetch(\PDO::FETCH_ASSOC);
            if ($linked && ($linked['operation_type'] ?? '') === 'departure') {
                return (int)$linked['id'];
            }
            return null;
        }
        return null;
    }

    private function resolveChocksOffDateTime(int $departureFlightId): ?string
    {
        $order = $this->query(
            "SELECT fso.completed_at FROM `flight_service_orders` fso
             JOIN `services` s ON s.id = fso.service_id
             WHERE fso.flight_id = ? AND s.code = 'CHOCKS' AND fso.status = 'done'
             LIMIT 1",
            [$departureFlightId]
        )->fetch(\PDO::FETCH_ASSOC);
        if ($order && !empty($order['completed_at'])) {
            return (string)$order['completed_at'];
        }

        $flight = $this->query(
            "SELECT flight_date, chocks_off FROM `flights` WHERE id = ? LIMIT 1",
            [$departureFlightId]
        )->fetch(\PDO::FETCH_ASSOC);
        if ($flight && !empty($flight['chocks_off']) && !empty($flight['flight_date'])) {
            return $flight['flight_date'] . ' ' . $flight['chocks_off'];
        }

        return null;
    }
}
