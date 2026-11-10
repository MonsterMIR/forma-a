<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

class FlightServiceCharge extends Model
{
    protected string $table = 'flight_service_charges';

    public function getUnconsumedByFlight(int $flightId): array
    {
        return $this->query(
            "SELECT fsc.*, s.name AS service_name, s.code AS service_code, s.unit_name
             FROM flight_service_charges fsc
             JOIN services s ON s.id = fsc.service_id
             WHERE fsc.flight_id = ? AND fsc.consumed_in_act_id IS NULL
             ORDER BY fsc.is_auto DESC, fsc.created_at ASC",
            [$flightId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getAllByFlight(int $flightId): array
    {
        return $this->query(
            "SELECT fsc.*, s.name AS service_name, s.code AS service_code, s.unit_name,
                    u.full_name AS created_by_name
             FROM flight_service_charges fsc
             JOIN services s ON s.id = fsc.service_id
             LEFT JOIN users u ON u.id = fsc.created_by_user_id
             WHERE fsc.flight_id = ?
             ORDER BY fsc.is_auto DESC, fsc.created_at ASC",
            [$flightId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function existsAutoChargeForService(int $flightId, int $serviceId): bool
    {
        $stmt = $this->query(
            "SELECT COUNT(*) FROM flight_service_charges
             WHERE flight_id = ? AND service_id = ? AND is_auto = 1",
            [$flightId, $serviceId]
        );
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * ДОБАВЛЕНО (09.11.2026, раунд 161): нужна ActService::buildFromFlightCharges(),
     * чтобы решить, безопасно ли чистить существующие авто-строки акта по
     * этой услуге перед вставкой новой (см. её докблок и баг, найденный в
     * раунде 160/161 — claude/analysis-2026-11-09-round161-repeat-service-
     * -act-item-loss.md). Отвечает: есть ли для этого рейса+услуги хотя бы
     * одна запись, УЖЕ погашенная ИМЕННО в этот акт РАНЕЕ (из предыдущего,
     * отдельного вызова buildFromFlightCharges — например прошлой пересборки
     * в тот же день) — если да, значит в акте уже есть законная строка по
     * более раннему экземпляру этой услуги, и её нельзя стирать только
     * потому, что подоспел ещё один (повторный) экземпляр.
     */
    public function hasConsumedForActAndService(int $actId, int $flightId, int $serviceId): bool
    {
        $stmt = $this->query(
            "SELECT COUNT(*) FROM flight_service_charges
             WHERE flight_id = ? AND service_id = ? AND consumed_in_act_id = ?",
            [$flightId, $serviceId, $actId]
        );
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * ДОБАВЛЕНО (19.08.2026, раунд 21): проверка "есть ли ВООБЩЕ хоть какая-то
     * запись по этой услуге на этом рейсе" — ручная (супервайзер/служба) ИЛИ
     * автоматическая, не важно. Нужна AutoChargeService::processFlightMandatoryCharges()
     * ПЕРЕД попыткой авто-вставки: на таблице стоит UNIQUE(flight_id, service_id)
     * (см. миграцию flight_service_charges), поэтому если по обязательной
     * услуге (например МЕТЕО) уже есть РУЧНАЯ запись — добавленная супервайзером
     * до пересборки акта, — попытка авто-вставить ЕЩЁ ОДНУ строку по той же
     * услуге упадёт с ошибкой уникального ключа. existsAutoChargeForService()
     * для этого не годится — она видит только is_auto=1 записи, а ручную
     * запись как раз пропустит, поэтому обнаружено не было.
     *
     * Баг, который это вскрыло (найдено 19.08.2026 по жалобе пользователя на
     * расхождение в акте вылета QQE735 на $10): супервайзер вручную отметил
     * МЕТЕО до сборки акта → при сборке акта авторасчёт попытался ЕЩЁ РАЗ
     * вставить МЕТЕО автоматически → упал на UNIQUE-ключе → необработанное
     * исключение прервало ВЕСЬ foreach по обязательным услугам ПОСЕРЕДИНЕ →
     * TLF (следующая по алфавиту после MET_50) вообще не была посчитана на
     * этом рейсе, а AV_SEC (которая физически успела вставиться раньше MET_50
     * по алфавиту) осталась "подвешенной" — не смогла попасть в акт, потому
     * что в акте не было строки TLF, от которой считается её сумма.
     */
    public function existsChargeForService(int $flightId, int $serviceId): bool
    {
        $stmt = $this->query(
            "SELECT COUNT(*) FROM flight_service_charges
             WHERE flight_id = ? AND service_id = ?",
            [$flightId, $serviceId]
        );
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Удаляет авто-начисления по рейсу перед пересчётом.
     * Удаляет ВСЕ авто-записи (включая consumed), если соответствующий акт
     * ещё в статусе draft — это позволяет корректно пересчитать при повторном
     * submit акта стоянки без накопления дублей.
     *
     * ДОБАВЛЕНО (03.09.2026, по итогам аудита): необязательный параметр
     * $exceptServiceIds — id услуг, которые НЕ удалять даже при пересчёте.
     * Нужен AutoChargeService::processFlightMandatoryCharges() для защиты
     * TLF/AV_SEC от потери, когда MTOW рейса вдруг стал пустым/невалидным
     * ПОСЛЕ того, как эти строки уже были правильно посчитаны при прошлой
     * сборке акта — см. докблок в AutoChargeService для истории бага (акт
     * 0003/SU-1877: MTOW обнулился после сборки, ит очередная пересборка
     * акта втихую съедала уже верно посчитанные TLF+AV_SEC на 1738, потому
     * что без MTOW их нечем было пересчитать обратно).
     */
    public function deleteAutoChargesForFlight(int $flightId, array $exceptServiceIds = []): void
    {
        $db = \App\Core\Database::getInstance();

        $exceptSql    = '';
        $exceptParams = [];
        if (!empty($exceptServiceIds)) {
            $placeholders = implode(',', array_fill(0, count($exceptServiceIds), '?'));
            $exceptSql    = " AND service_id NOT IN ({$placeholders})";
            $exceptParams = array_values($exceptServiceIds);
        }

        // Удаляем unconsumed автозаписи
        $db->prepare(
            "DELETE FROM flight_service_charges
             WHERE flight_id = ? AND is_auto = 1 AND consumed_in_act_id IS NULL{$exceptSql}"
        )->execute(array_merge([$flightId], $exceptParams));

        // Удаляем consumed автозаписи если акт ещё draft (не утверждён)
        $db->prepare(
            "DELETE fsc FROM flight_service_charges fsc
             JOIN acts a ON a.id = fsc.consumed_in_act_id
             WHERE fsc.flight_id = ?
               AND fsc.is_auto = 1
               AND a.status = 'draft'{$exceptSql}"
        )->execute(array_merge([$flightId], $exceptParams));
    }

    /**
     * ДОБАВЛЕНО (14.08.2026, раунд 5): то же самое, что
     * deleteAutoChargesForFlight(), но по ОДНОЙ конкретной услуге — нужно
     * для AutoChargeService::syncTechStaffCount(), которая пересчитывает
     * только TECH_STAFF, не трогая остальные авто-начисления рейса. Удаляет
     * ЛЮБЫЕ (не только is_auto=1) непогашенные записи по этой услуге — на
     * случай, если раньше по этому рейсу услугу успели добавить вручную (до
     * того как её перевели на авторасчёт, см. TariffVersion::AUTO_ONLY_CODES) —
     * иначе ручная и авто-запись задвоятся в акте.
     */
    public function deleteChargesForFlightService(int $flightId, int $serviceId): void
    {
        $db = \App\Core\Database::getInstance();

        $db->prepare(
            "DELETE FROM flight_service_charges
             WHERE flight_id = ? AND service_id = ? AND consumed_in_act_id IS NULL"
        )->execute([$flightId, $serviceId]);

        $db->prepare(
            "DELETE fsc FROM flight_service_charges fsc
             JOIN acts a ON a.id = fsc.consumed_in_act_id
             WHERE fsc.flight_id = ?
               AND fsc.service_id = ?
               AND a.status = 'draft'"
        )->execute([$flightId, $serviceId]);
    }

    /**
     * ДОБАВЛЕНО (20.08.2026, раунд 22): количество по уже введённой услуге
     * этого рейса, по коду услуги — нужно AutoChargeService для авторасчёта
     * PAX_SEC (сбор безопасности пассажиров вылета), сумма которого зависит
     * от количества пассажиров, уже введённого супервайзером в PAX_DEP (то
     * же количество, что печатается в шапке акта DEP-LT). По аналогии с тем,
     * как AV_SEC берёт свою ставку от TLF (см. AutoChargeService::percentRate()),
     * но здесь нужно само количество, а не тариф. Если записи по услуге ещё
     * нет (например ЦОК ещё не отметил PAX_DEP) — возвращает null, а не 0,
     * чтобы вызывающий код мог отличить "пассажиров 0" от "ещё не введено".
     */
    public function getQuantityByCode(int $flightId, string $code): ?float
    {
        $stmt = $this->query(
            "SELECT fsc.quantity
             FROM flight_service_charges fsc
             JOIN services s ON s.id = fsc.service_id
             WHERE fsc.flight_id = ? AND s.code = ?
             LIMIT 1",
            [$flightId, $code]
        );
        $val = $stmt->fetchColumn();
        return $val !== false ? (float)$val : null;
    }

    public function markConsumed(array $chargeIds, int $actId): void
    {
        if (empty($chargeIds)) return;
        $db = \App\Core\Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($chargeIds), '?'));
        $db->prepare(
            "UPDATE flight_service_charges SET consumed_in_act_id = ? WHERE id IN ({$placeholders})"
        )->execute(array_merge([$actId], $chargeIds));
    }
}
