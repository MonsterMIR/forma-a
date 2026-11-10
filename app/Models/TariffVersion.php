<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class TariffVersion extends Model
{
    protected string $table = 'tariff_versions';

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['service_id'])) {
            $where[]  = "tv.service_id = ?";
            $params[] = (int)$filters['service_id'];
        }

        if (!empty($filters['route_type'])) {
            $where[]  = "(tv.route_type = ? OR tv.route_type = 'both')";
            $params[] = $filters['route_type'];
        }

        if (!empty($filters['operation_type'])) {
            $where[]  = "(tv.operation_type = ? OR tv.operation_type = 'both')";
            $params[] = $filters['operation_type'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "tv.is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT tv.*, s.name AS service_name, s.code AS service_code,
                    c.code AS currency_code, c.symbol AS currency_symbol,
                    u.username AS created_by_username
             FROM `tariff_versions` tv
             JOIN `services` s ON s.id = tv.service_id
             JOIN `currencies` c ON c.id = tv.currency_id
             LEFT JOIN `users` u ON u.id = tv.created_by_user_id
             WHERE {$whereStr}
             ORDER BY tv.valid_from DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return $stmt->fetchAll();
    }

    public function countFiltered(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['service_id'])) {
            $where[]  = "tv.service_id = ?";
            $params[] = (int)$filters['service_id'];
        }

        if (!empty($filters['route_type'])) {
            $where[]  = "(tv.route_type = ? OR tv.route_type = 'both')";
            $params[] = $filters['route_type'];
        }

        if (!empty($filters['operation_type'])) {
            $where[]  = "(tv.operation_type = ? OR tv.operation_type = 'both')";
            $params[] = $filters['operation_type'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "tv.is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT COUNT(*) FROM `tariff_versions` tv
             JOIN `services` s ON s.id = tv.service_id
             JOIN `currencies` c ON c.id = tv.currency_id
             WHERE {$whereStr}",
            $params
        );
        return (int)$stmt->fetchColumn();
    }

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->query(
            "SELECT tv.*, s.name AS service_name, s.code AS service_code,
                    s.unit_name, s.vat_applicable_domestic, s.vat_applicable_international,
                    c.code AS currency_code, c.symbol AS currency_symbol,
                    u.username AS created_by_username
             FROM `tariff_versions` tv
             JOIN `services` s ON s.id = tv.service_id
             JOIN `currencies` c ON c.id = tv.currency_id
             LEFT JOIN `users` u ON u.id = tv.created_by_user_id
             WHERE tv.id = ? LIMIT 1",
            [$id]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getActiveForService(int $serviceId, string $routeType, string $operationType, string $date): ?array
    {
        $stmt = $this->query(
            "SELECT tv.*, c.code AS currency_code
             FROM `tariff_versions` tv
             JOIN `currencies` c ON c.id = tv.currency_id
             WHERE tv.service_id = ?
               AND tv.is_active = 1
               AND (tv.route_type = ? OR tv.route_type = 'both')
               AND (tv.operation_type = ? OR tv.operation_type = 'both')
               AND tv.valid_from <= ?
               AND (tv.valid_to IS NULL OR tv.valid_to >= ?)
             ORDER BY tv.valid_from DESC
             LIMIT 1",
            [$serviceId, $routeType, $operationType, $date, $date]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Услуги с активным тарифом для данного маршрута/операции/даты.
     * Используется для построения списка выбора на формах служб.
     */
    /**
     * Услуги с активным тарифом, отфильтрованные по РЕАЛЬНОМУ владельцу услуги.
     *
     * ВАЖНО (аудит 09.07.2026): раньше фильтрация шла по service_categories.role_code —
     * то есть по категории, а не по фактическому владельцу услуги (services.owner_role).
     * Эти два поля могут расходиться (например FIRE лежит в категории MAIN с
     * role_code='aero', но реальный владелец — spasop), из-за чего услуга показывалась
     * не той службе. Теперь фильтруем строго по s.owner_role — единственному полю,
     * которое реально означает "чья это услуга".
     *
     * Формульные услуги (TLF/AV_SEC/NIGHT/OVER_PARK) считаются только автоматически
     * через AutoChargeService при подаче акта стоянки — они всегда исключены из
     * ручного выбора, независимо от роли, чтобы их нельзя было продублировать вручную.
     *
     * ДОБАВЛЕНО (14.08.2026, раунд 5): TECH_STAFF (тех.персонал спецтехники)
     * тоже переведён на авторасчёт — см. AutoChargeService::syncTechStaffCount().
     * Раньше ССТ должна была сама вручную выбрать эту услугу и указать
     * количество в своём чек-листе (Журнал спецтехники) — по новому
     * регламенту диспетчеры служб отдельно ничего не подтверждают, вся
     * фиксация идёт через супервайзера на перроне, поэтому количество теперь
     * считается автоматически по факту уже оказанных услуг-техники. Исключена
     * из ручного списка, чтобы ССТ случайно не продублировала её вручную поверх
     * авторасчёта.
     */
    // ДОБАВЛЕНО (15.10.2026, раунд 78, по прямому указанию пользователя):
    // SUPERV ("Контроль обслуживания/Supervision") — обязательная услуга по
    // умолчанию с раунда 32 (is_mandatory=1, mandatory_for='both'),
    // начисляется автоматически через AutoChargeService::
    // processFlightMandatoryCharges() на КАЖДЫЙ рейс. Но до этого раунда она
    // всё равно показывалась в чек-листе "добавить услугу" у супервайзера
    // (и везде, где используется getServicesWithActiveTariff()) — пользователь
    // указал, что этого не должно быть: услуга по умолчанию не должна
    // предлагаться для ручного добавления (риск задвоения, как было с
    // MARSHALLING, см. round 76). В отличие от MARSHALLING, SUPERV НЕ
    // деактивируется (is_active остаётся 1 — иначе сломается и авторасчёт,
    // который требует is_active=1), а просто добавлена в этот список
    // "только авторасчёт, никогда не в ручном списке" — ровно как TLF/AV_SEC
    // и т.п. выше.
    // ДОБАВЛЕНО (04.11.2026, раунд 145, по прямому указанию пользователя):
    // RAMP_10...RAMP_251 («Рамп-хэндлинг» / «Прием выпуск ВС») пропущены
    // здесь с момента их включения как обязательной услуги (раунд 74) —
    // из-за этого супервайзер/ЦОК всё это время видели и могли вручную
    // заказать эту услугу через попап "Добавить услугу" параллельно с
    // авторасчётом (AutoChargeService). Проверено по факту: ни разу не
    // привело к задвоению суммы в акте (авторасчёт сам не добавляет
    // услугу повторно, если её уже заказали руками) — но источник строки
    // в акте неверно помечался как "вручную супервайзером" вместо
    // "система", и смысл этой защиты (сюда попадают услуги, которые
    // ДОЛЖНЫ считаться только по формуле/регламенту) для RAMP_* не
    // работал. См. claude/analysis-2026-11-04-round145-ramp-manual-add-gap-fixed.md.
    // ДОБАВЛЕНО (04.11.2026, раунд 146, по прямому указанию пользователя,
    // после раунда 145 про RAMP_*): "все услуги которые система автоматом
    // добавляет не должна быть доступна для добавления вручную" — та же
    // дыра, что была у RAMP_* (раунд 145), системно проверена по ВСЕМ
    // услугам с services.is_mandatory=1 и закрыта разом, а не по одной.
    // Добавлены: метеообеспечение (MET_50/100/101), пассажирские сборы
    // (PAX_ARR/PAX_DEP/PAX_SEC/PAX_CORTEGE_ARR/PAX_CORTEGE_DEP), багаж
    // (BAG_ARR_PC/BAG_DEP_PC) — у всех allow_repeat уже был 0 (не трогали),
    // не хватало именно исключения из этого списка. Проверено SQL-запросом
    // по всей таблице services: после этого раунда каждый is_mandatory=1
    // код либо здесь, либо покрыт отдельным механизмом (NIGHT/OVER_PARK —
    // AutoChargeService::processParkingAct(), TECH_STAFF —
    // requires_tech_personnel). См.
    // claude/analysis-2026-11-04-round146-all-mandatory-services-auto-only.md.
    // ИСПРАВЛЕНО (04.11.2026, раунд 148, по факту найдено пользователем на
    // живом рейсе NWC9066, акт 0168/XX-NWC9066): раунд 146 (несколькими
    // часами ранее тем же днём) ошибочно включил сюда же PAX_ARR/PAX_DEP/
    // BAG_ARR_PC/BAG_DEP_PC/PAX_CORTEGE_ARR/PAX_CORTEGE_DEP — но эти 6 кодов
    // устроены ПРИНЦИПИАЛЬНО иначе, чем остальные в этом списке (TLF, RAMP_*,
    // MET_* и т.п.): те считаются АВТОРАСЧЁТОМ целиком, без какого-либо
    // участия человека — заявку (flight_service_orders) для них заводить не
    // нужно никогда. А эти 6 — это AutoChargeService::MANIFEST_DRIVEN_CODES:
    // сумма берётся из манифеста, НО начисление происходит, только если по
    // рейсу уже есть заявка со статусом 'done' — то есть супервайзер
    // физически подтвердил на планшете, что эту услугу оказали. Единственное
    // место, откуда вообще берётся такая заявка — это ЦОК-чек-лист при
    // создании/редактировании рейса и попап «Добавить услугу» супервайзера,
    // а оба используют этот же getServicesWithActiveTariff(). Из-за раунда
    // 146 эти 6 кодов пропали из обоих мест — т.е. начиная с раунда 146 их
    // физически стало НЕЛЬЗЯ ни завести на новый рейс, ни восстановить, если
    // галочку случайно сняли (редактирование рейса через syncPlan() при этом
    // ещё и молча УДАЛЯЕТ уже существующую, но не начатую заявку, раз её нет
    // в списке отмеченных — см. FlightServiceOrder::syncPlan()). Проверено
    // SQL-аудитом по всей базе: минимум 25 рейсов с 02.10.2026 по
    // 04.11.2026 имеют пассажиров/багаж/кортеж в поданном манифесте, но без
    // соответствующей заявки — требует отдельной проверки, не начислено ли
    // это и на уже УТВЕРЖДЁННЫХ актах (отдельный раунд). PAX_SEC в списке
    // остаётся — она НЕ входит в MANIFEST_DRIVEN_CODES, считается напрямую
    // по уже вставленной сумме PAX_DEP, собственной заявки никогда не имела
    // и не требует. См. claude/analysis-2026-11-04-round148-manifest-driven-codes-auto-only-regression-fixed.md.
    private const AUTO_ONLY_CODES = [
        'TLF', 'AV_SEC', 'NIGHT', 'OVER_PARK', 'TECH_STAFF', 'SUPERV',
        'RAMP_10', 'RAMP_25', 'RAMP_35', 'RAMP_50', 'RAMP_70', 'RAMP_100',
        'RAMP_150', 'RAMP_190', 'RAMP_230', 'RAMP_250', 'RAMP_251',
        'MET_50', 'MET_100', 'MET_101',
        'PAX_SEC',
    ];

    public function getServicesWithActiveTariff(
        string $routeType,
        string $operationType,
        string $date,
        ?string $roleCode = null,
        ?float $mtow = null   // MTOW рейса для фильтрации диапазонов
    ): array {
        // Фильтр по РЕАЛЬНОМУ владельцу услуги (services.owner_role), не по категории
        if ($roleCode) {
            $roleFilter = "AND s.owner_role = ?";
        } else {
            $roleFilter = "AND s.owner_role IS NOT NULL";
        }

        // Формульные услуги — только авторасчёт, никогда не в ручном списке
        $autoPlaceholders  = implode(',', array_fill(0, count(self::AUTO_ONLY_CODES), '?'));
        $autoExcludeFilter = "AND s.code NOT IN ({$autoPlaceholders})";

        // Фильтр по MTOW — исключаем услуги с диапазонами которые не подходят
        $mtowFilter = '';
        if ($mtow !== null && $mtow > 0) {
            $mtowFilter = "AND (
                s.mtow_min IS NULL  -- услуга без ограничений по MTOW
                OR (
                    s.mtow_min <= {$mtow}
                    AND (s.mtow_max IS NULL OR s.mtow_max >= {$mtow})
                )
            )";
        }

        // Порядок параметров должен точно совпадать с порядком плейсхолдеров ? в SQL ниже
        $params = [$routeType, $operationType, $date, $date];
        if ($roleCode) {
            $params[] = $roleCode;
        }
        $params = array_merge($params, self::AUTO_ONLY_CODES);

        $stmt = $this->query(
            "SELECT DISTINCT s.id, s.name, s.code, s.unit_name, s.owner_role,
                    s.mtow_min, s.mtow_max,
                    sc.name AS category_name
             FROM tariff_versions tv
             JOIN services s ON s.id = tv.service_id
             JOIN service_categories sc ON sc.id = s.category_id
             WHERE tv.is_active = 1
               AND s.is_active = 1
               AND (tv.route_type = ? OR tv.route_type = 'both')
               AND (tv.operation_type = ? OR tv.operation_type = 'both')
               AND tv.valid_from <= ?
               AND (tv.valid_to IS NULL OR tv.valid_to >= ?)
               {$roleFilter}
               {$autoExcludeFilter}
               {$mtowFilter}
             ORDER BY sc.name ASC, s.name ASC",
            $params
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getAllForService(int $serviceId): array
    {
        $stmt = $this->query(
            "SELECT tv.*, c.code AS currency_code, c.symbol AS currency_symbol
             FROM `tariff_versions` tv
             JOIN `currencies` c ON c.id = tv.currency_id
             WHERE tv.service_id = ?
             ORDER BY tv.valid_from DESC",
            [$serviceId]
        );
        return $stmt->fetchAll();
    }
}