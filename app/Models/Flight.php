<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Flight extends Model
{
    protected string $table = 'flights';

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(f.flight_number LIKE ? OR al.name LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        if (!empty($filters['airline_id'])) {
            $where[]  = "f.airline_id = ?";
            $params[] = (int)$filters['airline_id'];
        }

        if (!empty($filters['route_type'])) {
            $where[]  = "f.route_type = ?";
            $params[] = $filters['route_type'];
        }

        if (!empty($filters['operation_type'])) {
            $where[]  = "f.operation_type = ?";
            $params[] = $filters['operation_type'];
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

        // ДОБАВЛЕНО (11.09.2026, раунд 37): LEFT JOIN acts — на странице
        // /flights нужно показывать статус акта по рейсу (нет акта /
        // предварительный / утверждён и т.п.), см. resources/views/flights/index.php.
        // flights.id уникален для acts.flight_id, так что JOIN не размножает строки.
        // ДОБАВЛЕНО (05.11.2026, раунд 153, по прямому указанию пользователя):
        // статус манифеста рейса (финальный) в списке /flights — та же
        // коррелированная подзапрос-логика, что уже используется на
        // дашборде супервайзера (см. round128, ServiceOrderController::dashboard()),
        // без N+1 запросов на каждую строку.
        $stmt = $this->query(
            "SELECT f.*,
                    al.name AS airline_name, al.iata_code AS airline_iata,
                    oa.name AS origin_name, oa.iata_code AS origin_iata,
                    da.name AS destination_name, da.iata_code AS destination_iata,
                    a.id AS act_id, a.act_number AS act_number, a.number_finalized AS act_number_finalized,
                    a.status AS act_status,
                    (SELECT pm.status FROM passenger_manifests pm
                      WHERE pm.flight_id = f.id AND pm.manifest_type = 'final'
                      ORDER BY pm.created_at DESC LIMIT 1) AS manifest_status
             FROM `flights` f
             JOIN `airlines` al ON al.id = f.airline_id
             LEFT JOIN `airports` oa ON oa.id = f.origin_airport_id
             LEFT JOIN `airports` da ON da.id = f.destination_airport_id
             LEFT JOIN `acts` a ON a.flight_id = f.id
             WHERE {$whereStr}
             -- ИЗМЕНЕНО (10.09.2026, раунд 36): раньше сортировалось по
             -- f.flight_date DESC, f.flight_number ASC — из-за этого номера
             -- в колонке '#' (id рейса) на странице /flights шли не по
             -- порядку. По просьбе пользователя — сортировка по id по убыванию.
             ORDER BY f.id DESC
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
            $where[]  = "(f.flight_number LIKE ? OR al.name LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        if (!empty($filters['airline_id'])) {
            $where[]  = "f.airline_id = ?";
            $params[] = (int)$filters['airline_id'];
        }

        if (!empty($filters['route_type'])) {
            $where[]  = "f.route_type = ?";
            $params[] = $filters['route_type'];
        }

        if (!empty($filters['operation_type'])) {
            $where[]  = "f.operation_type = ?";
            $params[] = $filters['operation_type'];
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

        $stmt = $this->query(
            "SELECT COUNT(*) FROM `flights` f
             JOIN `airlines` al ON al.id = f.airline_id
             LEFT JOIN `airports` oa ON oa.id = f.origin_airport_id
             LEFT JOIN `airports` da ON da.id = f.destination_airport_id
             WHERE {$whereStr}",
            $params
        );
        return (int)$stmt->fetchColumn();
    }

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->query(
            "SELECT f.*,
                    al.name AS airline_name, al.iata_code AS airline_iata,
                    oa.name AS origin_name, oa.iata_code AS origin_iata,
                    da.name AS destination_name, da.iata_code AS destination_iata
             FROM `flights` f
             JOIN `airlines` al ON al.id = f.airline_id
             LEFT JOIN `airports` oa ON oa.id = f.origin_airport_id
             LEFT JOIN `airports` da ON da.id = f.destination_airport_id
             WHERE f.id = ? LIMIT 1",
            [$id]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * РАУНД 33 (09.09.2026): рейс + краткие данные связанного рейса
     * (другое плечо той же стоянки — прилёт⇄вылет одного борта), если
     * привязка (`flights.linked_flight_id`) установлена. См.
     * App\Services\FlightService::linkFlights().
     */
    public function findWithLinked(int $id): ?array
    {
        $stmt = $this->query(
            "SELECT f.*,
                    lf.flight_number   AS linked_flight_number,
                    lf.flight_date     AS linked_flight_date,
                    lf.operation_type  AS linked_operation_type,
                    lf.mc_number       AS linked_mc_number,
                    lf.chocks_on       AS linked_chocks_on,
                    lf.chocks_off      AS linked_chocks_off,
                    a.id               AS linked_act_id,
                    a.act_number       AS linked_act_number
             FROM `flights` f
             LEFT JOIN `flights` lf ON lf.id = f.linked_flight_id
             LEFT JOIN `acts` a ON a.flight_id = lf.id
             WHERE f.id = ? LIMIT 1",
            [$id]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * РАУНД 33 (09.09.2026): кандидаты на привязку "другого плеча" рейса —
     * рейсы того же борта (aircraft_registration), противоположного
     * operation_type, ещё НЕ привязанные ни к чему (linked_flight_id IS
     * NULL).
     *
     * ИЗМЕНЕНО (12.10.2026, раунд 69, по прямому указанию пользователя):
     * убрано окно дат (было ±5 дней от даты текущего рейса). Причина:
     * "есть люди или компании которые местные и у них есть борт. он может
     * простоять тут хоть месяц... заявка на местный борт может прийти на
     * вылет в одиночку, так как заявка на прилет был пару месяцев назад...
     * привязка прилета и вылета должно быть по номерам бортов, он же не
     * может просто так сменить номер борта" — местный борт (пример из
     * обсуждения: T7AAA7) может стоять на стоянке десятки дней между
     * прилётом и вылетом, и окно в 5 дней искусственно исключало именно
     * такие, самые однозначные по сути пары (один и тот же борт, больше
     * вариантов физически нет), оставляя их непарными навсегда без
     * возможности это даже обнаружить.
     *
     * Почему снятие окна безопасно: единственная защита от неверной
     * привязки здесь — это правило "кандидат РОВНО ОДИН" в
     * FlightService::autoLinkFlight() (см. её докблок) — оно не зависело
     * от окна дат и продолжает работать как раньше. Если у одного борта
     * одновременно висит НЕСКОЛЬКО непривязанных рейсов противоположного
     * направления (например, борт летал несколько раз и что-то не
     * довели до конца) — это и раньше, и теперь не угадывается
     * автоматически, видно предупреждением со списком на странице рейса
     * (flights/show.php), и теперь есть ручная связка
     * (FlightController::link()) чтобы разобраться точечно.
     *
     * ИЗВЕСТНЫЙ ПРОБЕЛ (зафиксирован 21.10.2026, раунд 106/108, по
     * прямому указанию пользователя — случай P4VVIP, рейсы #84/#97): это
     * правило не проверяет ХРОНОЛОГИЧЕСКИЙ порядок — что рейс прилёта не
     * может быть позже рейса вылета в одной паре "один визит". Из-за этого
     * единственный когда-либо непривязанный вылет борта может быть
     * ошибочно предложен в пару совершенно отдельному, более позднему
     * прилёту того же борта (другой заход на посадку, не тот же самый
     * визит). Решение по итогам обсуждения 21.10.2026 — не чинить точечно
     * внутри этого метода, а целиком пересмотреть принцип подбора пары на
     * "последнее по времени движение борта закрывает/открывает визит"
     * (см. project: claude/analysis-2026-10-21-round108-*.md) — сам этот
     * метод будет переписан отдельным раундом после проверки нового
     * правила на всех уже связанных парах. Справочник статуса бортов
     * (AircraftController, этот же раунд) — первый, безопасный read-only
     * шаг к этому пересмотру, самого findLinkCandidates() ещё не меняет.
     *
     * Сортировка — по близости даты к текущему рейсу (для показа в списке
     * кандидатов при неоднозначности).
     */
    public function findLinkCandidates(
        string $registration,
        string $operationType,
        string $flightDate,
        int $excludeId
    ): array {
        $oppositeType = $operationType === 'arrival' ? 'departure' : 'arrival';

        $stmt = $this->query(
            "SELECT f.id, f.flight_number, f.flight_date, f.operation_type,
                    f.mc_number, f.aircraft_registration,
                    al.name AS airline_name,
                    ABS(DATEDIFF(f.flight_date, ?)) AS date_diff
             FROM `flights` f
             LEFT JOIN `airlines` al ON al.id = f.airline_id
             WHERE f.aircraft_registration = ?
               AND f.operation_type = ?
               AND f.id != ?
               AND f.linked_flight_id IS NULL
             ORDER BY date_diff ASC, f.flight_date ASC
             LIMIT 20",
            [$flightDate, $registration, $oppositeType, $excludeId]
        );
        return $stmt->fetchAll();
    }

    /**
     * ДОБАВЛЕНО (21.10.2026, раунд 109, по прямому указанию пользователя,
     * по итогам разбора случая P4VVIP — раунды 106/108/108c): отдельный,
     * более строгий подбор кандидата ДЛЯ АВТОМАТИЧЕСКОЙ связки
     * (FlightService::autoLinkFlight()). В отличие от findLinkCandidates()
     * выше (используется ТОЛЬКО для ручного экрана "Связать вручную…" на
     * странице рейса, где финальное решение всегда за человеком — список
     * кандидатов там НЕ сужен намеренно, иначе пропадёт возможность
     * вручную поправить редкий, но реальный случай вроде пары #44/#45,
     * см. round105, где порядок дат внутри пары был нетипичным), здесь
     * действует правило "прилёт открывает визит, вылет закрывает последний
     * ещё не закрытый визит того же борта" — модель, описанная и
     * проверенная на всех исторических парах в
     * scripts/round108c_verify_new_linking_rule.php (45 из 52 совпали, 5
     * расхождений разобраны отдельно, см. claude/analysis-2026-10-21-
     * round108c-linking-rule-verification.md).
     *
     * Правило направления (f.flight_date + f.scheduled_time, COALESCE на
     * NULL-время — в данных почти всегда заполнено, NULL — редкое
     * исключение):
     *  - текущий рейс — ПРИЛЁТ: кандидат (вылет) должен быть СТРОГО ПОЗЖЕ
     *    по времени — визит, который этот прилёт открывает, может
     *    закрыться только будущим вылетом, а не чужим прошлым;
     *  - текущий рейс — ВЫЛЕТ: кандидат (прилёт) должен быть СТРОГО
     *    РАНЬШЕ — вылет закрывает визит, открытый раньше него, а не
     *    позже.
     * Ровно этой проверки не хватало правилу раунда 33/40/69 — из-за её
     * отсутствия единственный когда-либо непривязанный вылет борта мог
     * быть ошибочно предложен в пару более позднему прилёту того же
     * борта (другой, отдельный визит) — см. случай P4VVIP, рейсы #84/#97.
     *
     * Среди кандидатов, прошедших фильтр направления, возвращаем
     * отсортированными по близости по времени (минута в минуту ближе —
     * первый) — FlightService::autoLinkFlight() сама решает: связывать
     * ли автоматически (однозначно ближайший) или это неоднозначность
     * (два кандидата на одинаковом расстоянии от текущего рейса).
     */
    /**
     * ДОБАВЛЕНО (06.11.2026, раунд 154, по прямому указанию пользователя):
     * параметр $isBased — для борта, который постоянно базируется в нашем
     * аэропорту (см. App\Models\Aircraft::is_based), цепочка визита
     * ПРОТИВОПОЛОЖНАЯ обычной: не «прилёт → вылет» (гостевой борт прилетел,
     * постоял, улетел), а «вылет → прилёт» (борт и так стоит у нас; сначала
     * УЛЕТАЕТ, потом ВОЗВРАЩАЕТСЯ). Поэтому для таких бортов направление
     * поиска кандидата разворачивается: вылет ищет более ПОЗДНИЙ прилёт
     * (который его «закроет», т.е. борт вернётся), прилёт ищет более
     * РАННИЙ вылет (который этот визит «открыл», т.е. борт улетал перед
     * этим). Для обычных бортов ($isBased=false) поведение не изменилось.
     */
    public function findChronologicalLinkCandidates(
        string $registration,
        string $operationType,
        string $flightDate,
        ?string $scheduledTime,
        int $excludeId,
        bool $isBased = false
    ): array {
        $oppositeType = $operationType === 'arrival' ? 'departure' : 'arrival';
        $refTime      = ($scheduledTime !== null && $scheduledTime !== '') ? $scheduledTime : '12:00:00';
        $openingType  = $isBased ? 'departure' : 'arrival';
        $cmp          = $operationType === $openingType ? '>' : '<';

        $stmt = $this->query(
            "SELECT f.id, f.flight_number, f.flight_date, f.scheduled_time, f.operation_type,
                    f.mc_number, f.aircraft_registration,
                    al.name AS airline_name,
                    ABS(TIMESTAMPDIFF(
                        MINUTE,
                        TIMESTAMP(f.flight_date, COALESCE(f.scheduled_time, '12:00:00')),
                        TIMESTAMP(?, ?)
                    )) AS minute_diff
             FROM `flights` f
             LEFT JOIN `airlines` al ON al.id = f.airline_id
             WHERE f.aircraft_registration = ?
               AND f.operation_type = ?
               AND f.id != ?
               AND f.linked_flight_id IS NULL
               AND TIMESTAMP(f.flight_date, COALESCE(f.scheduled_time, '12:00:00')) {$cmp} TIMESTAMP(?, ?)
             ORDER BY minute_diff ASC, f.id ASC
             LIMIT 20",
            [$flightDate, $refTime, $registration, $oppositeType, $excludeId, $flightDate, $refTime]
        );
        return $stmt->fetchAll();
    }

    /**
     * ДОБАВЛЕНО (12.10.2026, раунд 69, по прямому указанию пользователя):
     * обнаружение "забытого" рейса того же борта и того же направления
     * (operation_type) — чтобы не допустить повторного создания рейса,
     * который на самом деле уже существует в системе, просто ещё не был
     * отработан (а про него забыли и завели заново). Именно так появился
     * непарный дубль AXS4 — см.
     * claude/analysis-2026-10-12-round69-flight-pairing-enforcement.md.
     *
     * "Забытый, бездействующий" (idle) определяем узко и намеренно
     * консервативно — ТОЛЬКО по отсутствию акта (acts) и начисленных
     * сборов (flight_service_charges), а НЕ по linked_flight_id: ошибочно
     * связанный рейс тоже может быть тем самым дублем-забытым (ровно так
     * было с флайтом №1 в round69 — он был связан с никогда не
     * отработанной "заглушкой"). Намеренно НЕ смотрим на частоту борта —
     * пользователь сам предупредил, что один и тот же борт может летать
     * часто (T7AAA7), поэтому окно дат узкое (по умолчанию то же ±5 дней,
     * что и у findLinkCandidates()), и совпадение требует ТОЧНО такого же
     * operation_type (в отличие от findLinkCandidates(), которая ищет
     * противоположный — там ищем пару, здесь — потенциальный дубль).
     */
    public function findIdleDuplicate(
        string $registration,
        string $operationType,
        string $flightDate,
        int $windowDays = 5
    ): ?array {
        $stmt = $this->query(
            "SELECT f.id, f.flight_number, f.flight_date, f.operation_type, f.mc_number,
                    ABS(DATEDIFF(f.flight_date, ?)) AS date_diff
             FROM `flights` f
             WHERE f.aircraft_registration = ?
               AND f.operation_type = ?
               AND ABS(DATEDIFF(f.flight_date, ?)) <= ?
               AND NOT EXISTS (SELECT 1 FROM `acts` a WHERE a.flight_id = f.id)
               AND NOT EXISTS (SELECT 1 FROM `flight_service_charges` c WHERE c.flight_id = f.id)
             ORDER BY date_diff ASC, f.flight_date ASC
             LIMIT 1",
            [$flightDate, $registration, $operationType, $flightDate, $windowDays]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * ДОБАВЛЕНО (18.09.2026, раунд 48): все рейсы, у которых сейчас указан
     * данный номер стоянки (mc_number) — используется ParkingStandService
     * для проверки занятости стоянки перед тем, как назначить её другому
     * борту. $excludeId — исключить сам рейс (при редактировании), чтобы
     * повторное сохранение того же значения не считалось конфликтом само
     * с собой.
     */
    public function findByStandNumber(string $standNumber, ?int $excludeId = null): array
    {
        $sql = "SELECT f.*, al.name AS airline_name
                FROM `flights` f
                LEFT JOIN `airlines` al ON al.id = f.airline_id
                WHERE f.mc_number = ?";
        $params = [$standNumber];

        if ($excludeId !== null) {
            $sql .= " AND f.id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * ДОБАВЛЕНО (21.10.2026, раунд 109, по прямому указанию пользователя):
     * подсказка оператору, когда у рейса ОТСУТСТВУЕТ или неоднозначен
     * бортовой номер (ровно случай рейса #45, round105) — какой борт
     * последним по времени занимал ЭТУ ЖЕ стоянку (mc_number) до этого
     * рейса. НЕ используется для автоматической связки (стоянки
     * переиспользуются разными бортами, сама по себе занятость стоянки
     * не доказывает, что это тот же борт — см. обсуждение 21.10.2026) —
     * только как подсказка человеку на странице рейса, последнее слово
     * всегда за ЦОК/оператором.
     *
     * Намеренно смотрит на `flights.mc_number` напрямую (а не на
     * flight_stand_moves — та таблица хранит только переносы стоянки
     * ВНУТРИ уже известного визита, по arrival_flight_id, и не годится
     * как общий журнал "кто и когда стоял на стоянке X" через разные
     * борта, см. ParkingStandService::recordMove()).
     */
    public function findLastFlightOnStand(
        string $standCode,
        string $beforeFlightDate,
        ?string $beforeTime,
        int $excludeId
    ): ?array {
        $refTime = ($beforeTime !== null && $beforeTime !== '') ? $beforeTime : '12:00:00';

        $stmt = $this->query(
            "SELECT f.id, f.flight_number, f.flight_date, f.scheduled_time, f.operation_type,
                    f.aircraft_registration
             FROM `flights` f
             WHERE f.mc_number = ?
               AND f.id != ?
               AND f.aircraft_registration IS NOT NULL AND f.aircraft_registration <> ''
               AND TIMESTAMP(f.flight_date, COALESCE(f.scheduled_time, '12:00:00')) < TIMESTAMP(?, ?)
             ORDER BY f.flight_date DESC, COALESCE(f.scheduled_time, '00:00:00') DESC, f.id DESC
             LIMIT 1",
            [$standCode, $excludeId, $beforeFlightDate, $refTime]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * ДОБАВЛЕНО (23.09.2026, раунд 55, по прямому указанию пользователя):
     * "у нас может быть несколько супервайзеров на одной смене... скрыть
     * рейс одного супервайзера от другого чтобы не путались". Закрепляет
     * рейс за супервайзером, который первым выполнил по нему реальное
     * действие (не просто открыл карточку на просмотр — см. вызовы этого
     * метода в ServiceOrderController/FlightController). Если рейс уже
     * закреплён за ДРУГИМ супервайзером — ничего не меняет и сообщает, за
     * кем он сейчас числится, чтобы вызывающий код мог заблокировать
     * действие с понятной причиной. Если рейс уже закреплён за ТЕМ ЖЕ
     * супервайзером или ещё ни за кем — закрепляет (в т.ч. идемпотентно) и
     * пропускает действие.
     *
     * Явная передача другому супервайзеру ("Передать смену", см.
     * ServiceOrderController::handover()) — единственный способ сменить
     * владельца; сам этот метод никогда не отбирает рейс у текущего
     * владельца в пользу кого-то другого.
     *
     * @return array{allowed: bool, owner_id: ?int, owner_name: ?string}
     */
    public function tryClaimForSupervisor(int $flightId, int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT f.handling_supervisor_id, u.full_name AS owner_name
             FROM `flights` f
             LEFT JOIN `users` u ON u.id = f.handling_supervisor_id
             WHERE f.id = ? LIMIT 1"
        );
        $stmt->execute([$flightId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['allowed' => false, 'owner_id' => null, 'owner_name' => null];
        }

        $ownerId = $row['handling_supervisor_id'] !== null ? (int)$row['handling_supervisor_id'] : null;

        if ($ownerId === null) {
            $this->update($flightId, [
                'handling_supervisor_id'          => $userId,
                'handling_supervisor_assigned_at' => date('Y-m-d H:i:s'),
            ]);
            return ['allowed' => true, 'owner_id' => $userId, 'owner_name' => null];
        }

        if ($ownerId === $userId) {
            return ['allowed' => true, 'owner_id' => $userId, 'owner_name' => null];
        }

        return ['allowed' => false, 'owner_id' => $ownerId, 'owner_name' => $row['owner_name']];
    }

    /**
     * ДОБАВЛЕНО (23.09.2026, раунд 55): переносит закрепление рейса за
     * супервайзером на другого — вызывается из "Передать смену"
     * (ServiceOrderController::handover()), единственного места, где смена
     * владельца допустима явно, в обход обычного "первый действующий
     * забирает себе" в tryClaimForSupervisor().
     */
    public function setHandlingSupervisor(int $flightId, ?int $userId): void
    {
        $this->update($flightId, [
            'handling_supervisor_id'          => $userId,
            'handling_supervisor_assigned_at' => $userId !== null ? date('Y-m-d H:i:s') : null,
        ]);
    }

    /**
     * ДОБАВЛЕНО (21.10.2026, раунд 108, по прямому указанию пользователя):
     * "давай вести справочник по бортам, когда он нам прилетел, когда
     * улетел... если этот борт улетел с нашего аэропорта то он может
     * прилететь, а если ещё не улетел то не может прилететь". Первый шаг
     * пересмотра логики связки рейсов (см. докблок findLinkCandidates()
     * выше) — пока только READ-ONLY отчёт, саму связку ещё не меняет.
     *
     * Для каждого борта (aircraft_registration), у которого есть хотя бы
     * один рейс в системе, возвращает ОДНУ строку — его САМОЕ ПОЗДНЕЕ по
     * факту движение (по flight_date + scheduled_time, не по дате
     * создания записи — важно для задним числом внесённых рейсов).
     * Тип этого движения и есть текущий статус борта: прилёт = борт сейчас
     * у нас ("на земле"), вылет = борт улетел. Окно COALESCE на
     * scheduled_time — если время не указано, считаем начало суток, чтобы
     * сравнение было детерминированным, а не падало на NULL.
     *
     * Намеренно вычисляется "на лету" (ROW_NUMBER() по `flights`), а не
     * хранится отдельной таблицей — пока это ровно одно и то же: отдельная
     * таблица рано или поздно разойдётся с реальными данными (поле задним
     * числом поправили, рейс удалили и т.п.), а вычисление из `flights`
     * разойтись не может в принципе.
     */
    public function getFleetStatusBoard(): array
    {
        $stmt = $this->query(
            "SELECT ranked.id, ranked.flight_number, ranked.flight_date, ranked.scheduled_time,
                    ranked.operation_type, ranked.aircraft_registration, ranked.aircraft_type,
                    ranked.mc_number, ranked.linked_flight_id,
                    al.name AS airline_name
             FROM (
                 SELECT f.id, f.flight_number, f.flight_date, f.scheduled_time, f.operation_type,
                        f.aircraft_registration, f.aircraft_type, f.mc_number, f.linked_flight_id,
                        f.airline_id,
                        ROW_NUMBER() OVER (
                            PARTITION BY f.aircraft_registration
                            ORDER BY f.flight_date DESC, COALESCE(f.scheduled_time, '00:00:00') DESC, f.id DESC
                        ) AS rn
                 FROM `flights` f
                 WHERE f.aircraft_registration IS NOT NULL AND f.aircraft_registration <> ''
             ) ranked
             LEFT JOIN `airlines` al ON al.id = ranked.airline_id
             WHERE ranked.rn = 1
             ORDER BY ranked.flight_date DESC, ranked.scheduled_time DESC"
        );
        return $stmt->fetchAll();
    }

    /**
     * ДОБАВЛЕНО (24.10.2026, раунд 121, по прямому указанию пользователя):
     * попап "История борта" в /aircraft-status (между колонками
     * "Авиакомпания" и "Стоянка") — полная история рейсов конкретного
     * борта (по регистрации) В НАШЕМ АЭРОПОРТУ: и прилёты, и вылеты, все
     * визиты, новые сверху. См. App\Controllers\AircraftController::history().
     *
     * Намеренно БЕЗ фильтра по датам/лимита — у местных бортов (пример из
     * раунда 69 — T7AAA7) история может быть длинной, но это ровно то,
     * что нужно посмотреть в попапе целиком, а не "последние N" (отдельной
     * пагинации в попапе пока не делаем — если справочник вырастет
     * настолько, что это станет проблемой, добавим отдельно).
     */
    public function getHistoryByRegistration(string $registration): array
    {
        $registration = strtoupper(trim($registration));
        if ($registration === '') {
            return [];
        }

        $stmt = $this->query(
            "SELECT f.id, f.flight_number, f.flight_date, f.scheduled_time, f.operation_type,
                    f.mc_number, f.linked_flight_id, f.route_type,
                    al.name AS airline_name, al.iata_code AS airline_iata,
                    oa.name AS origin_name, oa.iata_code AS origin_iata,
                    da.name AS destination_name, da.iata_code AS destination_iata,
                    a.id AS act_id, a.status AS act_status, a.act_number AS act_number,
                    a.number_finalized AS act_number_finalized
             FROM `flights` f
             LEFT JOIN `airlines` al ON al.id = f.airline_id
             LEFT JOIN `airports` oa ON oa.id = f.origin_airport_id
             LEFT JOIN `airports` da ON da.id = f.destination_airport_id
             LEFT JOIN `acts` a ON a.flight_id = f.id
             WHERE f.aircraft_registration = ?
             ORDER BY f.flight_date DESC, COALESCE(f.scheduled_time, '00:00:00') DESC, f.id DESC",
            [$registration]
        );
        return $stmt->fetchAll();
    }
}
