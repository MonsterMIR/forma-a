<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Act extends Model
{
    protected string $table = 'acts';

    /**
     * Найти акт рейса (один рейс = один акт, см. UNIQUE acts.flight_id)
     */
    public function findByFlightId(int $flightId): ?array
    {
        $row = $this->query(
            "SELECT * FROM acts WHERE flight_id = ? LIMIT 1",
            [$flightId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Статусы акта, при которых рейс и все документы по нему (манифесты, техкарты,
     * журналы спецтехники, акты стоянки, СПАСОП, услуги) заблокированы для правки.
     *
     * ДОБАВЛЕНО (аудит 09.07.2026, п.1): раньше после утверждения акта рейс и все
     * документы служб оставались редактируемыми — можно было незаметно изменить
     * данные, уже вошедшие в подписанный акт.
     */
    public const LOCKED_STATUSES = ['approved', 'cancelled', 'exported'];

    /**
     * Возвращает статус акта, если рейс заблокирован для редактирования,
     * иначе null (рейс свободен для правки — акта ещё нет или он в статусе draft).
     */
    public function getLockStatus(int $flightId): ?string
    {
        $act = $this->findByFlightId($flightId);
        if ($act && in_array($act['status'], self::LOCKED_STATUSES, true)) {
            return $act['status'];
        }
        return null;
    }

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(a.act_number LIKE ? OR f.flight_number LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        if (!empty($filters['status'])) {
            $where[]  = "a.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['route_type'])) {
            $where[]  = "a.route_type = ?";
            $params[] = $filters['route_type'];
        }

        if (!empty($filters['operation_type'])) {
            $where[]  = "a.operation_type = ?";
            $params[] = $filters['operation_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[]  = "a.act_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[]  = "a.act_date <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['created_by_user_id'])) {
            $where[]  = "a.created_by_user_id = ?";
            $params[] = (int)$filters['created_by_user_id'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT a.*,
                    f.flight_number, f.flight_date,
                    al.name AS airline_name,
                    sc.code AS currency_code,
                    u.username AS created_by_username,
                    ua.username AS approved_by_username
             FROM `acts` a
             JOIN `flights` f ON f.id = a.flight_id
             JOIN `airlines` al ON al.id = f.airline_id
             JOIN `currencies` sc ON sc.id = a.settlement_currency_id
             JOIN `users` u ON u.id = a.created_by_user_id
             LEFT JOIN `users` ua ON ua.id = a.approved_by_user_id
             WHERE {$whereStr}
             ORDER BY a.act_date DESC, a.act_number DESC
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
            $where[]  = "(a.act_number LIKE ? OR f.flight_number LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        if (!empty($filters['status'])) {
            $where[]  = "a.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['route_type'])) {
            $where[]  = "a.route_type = ?";
            $params[] = $filters['route_type'];
        }

        if (!empty($filters['operation_type'])) {
            $where[]  = "a.operation_type = ?";
            $params[] = $filters['operation_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[]  = "a.act_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[]  = "a.act_date <= ?";
            $params[] = $filters['date_to'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT COUNT(*) FROM `acts` a
             JOIN `flights` f ON f.id = a.flight_id
             JOIN `airlines` al ON al.id = f.airline_id
             JOIN `currencies` sc ON sc.id = a.settlement_currency_id
             WHERE {$whereStr}",
            $params
        );
        return (int)$stmt->fetchColumn();
    }

    public function findWithDetails(int $id): ?array
    {
        // ИСПРАВЛЕНО (14.08.2026, раунд 2, п.2/3): в акте не отражались MTOW и
        // фактические PAX прилёта/вылета — эти данные есть в `flights`, но
        // раньше не выбирались в этом запросе, поэтому в шаблоне печати
        // печатался прочерк. Даты/время прилёта-вылета (arr_date/arr_time/
        // dep_date/dep_time) тоже добавлены здесь как запасной источник —
        // основной источник времени теперь берётся из порядка "Лидирование ВС"
        // (см. ActController::printForm()), а эти поля остаются как fallback,
        // если такого заказа услуги нет.
        $stmt = $this->query(
            "SELECT a.*,
                    f.flight_number, f.flight_date, f.aircraft_type, f.aircraft_registration,
                    f.aircraft_mtow AS mtow, f.arr_date, f.arr_time, f.dep_date, f.dep_time,
                    f.pax_arrival, f.pax_departure, f.infant_arrival, f.infant_departure,
                    f.dhc_arrival, f.dhc_departure, f.flight_purpose,
                    f.chocks_on, f.chocks_off, f.mc_number,
                    al.name AS airline_name, al.iata_code AS airline_iata,
                    oa.name AS origin_name, oa.iata_code AS origin_iata,
                    da.name AS destination_name, da.iata_code AS destination_iata,
                    sc.code AS currency_code, sc.symbol AS currency_symbol, sc.name AS currency_name,
                    u.username AS created_by_username, u.full_name AS created_by_fullname,
                    ua.username AS approved_by_username, ua.full_name AS approved_by_fullname,
                    us.full_name AS tablet_supervisor_fullname,
                    h.name AS handler_name
             FROM `acts` a
             JOIN `flights` f ON f.id = a.flight_id
             JOIN `airlines` al ON al.id = f.airline_id
             LEFT JOIN `airports` oa ON oa.id = f.origin_airport_id
             LEFT JOIN `airports` da ON da.id = f.destination_airport_id
             JOIN `currencies` sc ON sc.id = a.settlement_currency_id
             JOIN `users` u ON u.id = a.created_by_user_id
             LEFT JOIN `users` ua ON ua.id = a.approved_by_user_id
             LEFT JOIN `users` us ON us.id = a.tablet_supervisor_user_id
             LEFT JOIN `handlers` h ON h.id = a.handler_id
             WHERE a.id = ? LIMIT 1",
            [$id]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function actNumberExists(string $actNumber, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) FROM `acts` WHERE `act_number` = ?";
        $params = [$actNumber];
        if ($excludeId) {
            $sql    .= " AND id != ?";
            $params[] = $excludeId;
        }
        return (int)$this->query($sql, $params)->fetchColumn() > 0;
    }

    public function getApprovedByPeriod(string $from, string $to): array
    {
        $stmt = $this->query(
            "SELECT a.*,
                    f.flight_number, f.flight_date,
                    f.aircraft_type, f.aircraft_mtow, f.aircraft_registration,
                    al.name AS airline_name, al.iata_code AS airline_iata,
                    oa.name AS origin_name,      oa.iata_code AS origin_iata,
                    da.name AS destination_name, da.iata_code AS destination_iata,
                    sc.code AS currency_code, sc.name AS currency_name,
                    u.full_name  AS created_by_fullname,
                    ua.full_name AS approved_by_fullname
             FROM `acts` a
             JOIN `flights` f      ON f.id  = a.flight_id
             JOIN `airlines` al    ON al.id = f.airline_id
             LEFT JOIN `airports` oa ON oa.id = f.origin_airport_id
             LEFT JOIN `airports` da ON da.id = f.destination_airport_id
             JOIN `currencies` sc  ON sc.id  = a.settlement_currency_id
             JOIN `users` u        ON u.id   = a.created_by_user_id
             LEFT JOIN `users` ua  ON ua.id  = a.approved_by_user_id
             WHERE a.status = 'approved'
               AND DATE(a.act_date) BETWEEN ? AND ?
             ORDER BY a.act_date ASC, a.id ASC",
            [$from, $to]
        );
        return $stmt->fetchAll();
    }

    /**
     * ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): акты, видимые в личном
     * кабинете представителя — только утверждённые/выгруженные и только
     * своего хендлера (acts.handler_id, раунд 46). Черновики представитель
     * не видит принципиально — это внутренняя кухня ЦОК до утверждения.
     */
    public function getApprovedByHandler(int $handlerId, int $limit = 200): array
    {
        $stmt = $this->query(
            "SELECT a.*,
                    f.flight_number, f.flight_date,
                    al.name AS airline_name, al.iata_code AS airline_iata,
                    sc.code AS currency_code, sc.symbol AS currency_symbol
             FROM `acts` a
             JOIN `flights` f   ON f.id  = a.flight_id
             JOIN `airlines` al ON al.id = f.airline_id
             JOIN `currencies` sc ON sc.id = a.settlement_currency_id
             WHERE a.handler_id = ?
               AND a.status IN ('approved', 'exported')
             ORDER BY a.approved_at DESC, a.id DESC
             LIMIT {$limit}",
            [$handlerId]
        );
        return $stmt->fetchAll();
    }
}