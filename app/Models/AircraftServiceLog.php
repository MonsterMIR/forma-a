<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * ДОБАВЛЕНО (19.11.2026, раунд 190, по прямому указанию пользователя —
 * «анкета борта», см. claude/analysis-2026-11-19-round186/187/188/189):
 * промежуточные услуги, оказанные борту НЕ в рамках конкретного рейса/акта.
 *
 * Повод: `acts.flight_id` — UNIQUE NOT NULL (один акт = ровно один рейс,
 * это закреплено на уровне схемы БД, не только в коде), поэтому услугу,
 * оказанную борту между закрытым актом прилёта и ещё не созданным актом
 * вылета (борт стоит на стоянке несколько суток), сейчас физически
 * НЕКУДА записать ни в один существующий акт.
 *
 * Привязка — НАПРЯМУЮ к борту (aircraft_id), без промежуточной сущности
 * "визит"/"стоянка": пользователь обоснованно отклонил такую сущность
 * (round187) — регистрация борта уже глобально уникальна, а "сейчас ли
 * борт физически у нас" и так надёжно вычисляется по существующей логике
 * колодок/сопровождения (см. Aircraft::isRotationFreed(),
 * ParkingStandService::isFreed()) — заводить для этого второй, отдельно
 * управляемый источник истины означало бы повторить ровно тот класс
 * риска, из-за которого возник сам LSK3533/RA73533 инцидент (round183).
 *
 * Фаза 1 (этот раунд) СОЗНАТЕЛЬНО БЕЗ цены/тарифа/НДС — только факт "что
 * оказано, когда, кем, в каком количестве". Сумма не вычисляется и не
 * хранится на этом этапе: у отдельной, вне-рейсовой записи нет
 * естественного route_type/operation_type/act_date, которые требуются
 * существующему ActService::addActItem() для поиска тарифа — а
 * дублировать его финансовую логику заново, без этого контекста, означало
 * бы считать деньги "на глазок". Расчёт суммы откладывается на Фазу 2
 * (перенос записи в акт вылета), где эти поля уже будут известны из
 * реального акта — и там будет использован этот же, уже проверенный
 * ActService::addActItem(), а не отдельный параллельный расчёт.
 *
 * status:
 *   draft            — ещё не сведена в акт (видна как "висит" в анкете)
 *   folded_into_act  — перенесена в акт вылета (Фаза 2), act_id заполнен
 *   cancelled        — отменена администратором как ошибочная запись
 */
class AircraftServiceLog extends Model
{
    protected string $table = 'aircraft_service_log';

    public function createDraft(
        int $aircraftId,
        int $serviceId,
        string $renderedAt,
        float $quantity,
        ?string $notes,
        int $recordedByUserId
    ): int {
        return (int)$this->insert([
            'aircraft_id'         => $aircraftId,
            'service_id'          => $serviceId,
            'rendered_at'         => $renderedAt,
            'quantity'            => $quantity,
            'status'              => 'draft',
            'notes'               => ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
            'recorded_by_user_id' => $recordedByUserId,
        ]);
    }

    /**
     * Вся лента промежуточных услуг конкретного борта, новые сверху —
     * используется для "анкеты борта" (AircraftController::show()).
     *
     * ДОБАВЛЕНО (19.11.2026, раунд 191): s.is_non_billable прокинут в
     * выборку — нужен UI, чтобы отличить реально "ждущую свода в акт"
     * услугу от просто информационной отметки (см. getDraftsByAircraft()
     * ниже и AircraftController::show()).
     */
    public function getByAircraft(int $aircraftId): array
    {
        return $this->query(
            "SELECT l.*, s.name AS service_name, s.unit_name AS service_unit_name,
                    s.is_non_billable AS service_is_non_billable,
                    u.full_name AS recorded_by_name,
                    cu.full_name AS cancelled_by_name,
                    a.act_number AS act_number, a.status AS act_status
             FROM `aircraft_service_log` l
             JOIN `services` s ON s.id = l.service_id
             LEFT JOIN `users` u ON u.id = l.recorded_by_user_id
             LEFT JOIN `users` cu ON cu.id = l.cancelled_by_user_id
             LEFT JOIN `acts` a ON a.id = l.act_id
             WHERE l.aircraft_id = ?
             ORDER BY l.rendered_at DESC, l.id DESC",
            [$aircraftId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * ДОБАВЛЕНО (19.11.2026, раунд 191, по прямому указанию пользователя —
     * «анкета борта», Фаза 2): черновики этого борта, ГОТОВЫЕ к переносу в
     * акт вылета — т.е. ещё не сведены (status=draft) И тарифицируемые
     * (services.is_non_billable=0). Используется ActService::
     * foldAircraftServiceLogIntoAct().
     *
     * Услуги с is_non_billable=1 (например CHOCKS — «Установка/уборка
     * колодок»; по прямому указанию пользователя промежуточные
     * снятия/установки колодок между рейсами фиксируются именно как такая
     * услуга — просто факт в анкете для истории, "да пока так и сделай")
     * сюда сознательно НЕ попадают и никогда не будут перенесены ни в
     * какой акт — они так и останутся в статусе draft постоянно, это их
     * ожидаемое конечное состояние, а не "зависшая" запись.
     */
    public function getDraftsByAircraft(int $aircraftId): array
    {
        return $this->query(
            "SELECT l.*, s.name AS service_name, s.unit_name AS service_unit_name
             FROM `aircraft_service_log` l
             JOIN `services` s ON s.id = l.service_id
             WHERE l.aircraft_id = ? AND l.status = 'draft' AND s.is_non_billable = 0
             ORDER BY l.rendered_at ASC, l.id ASC",
            [$aircraftId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * ДОБАВЛЕНО (19.11.2026, раунд 191): запись перенесена в акт —
     * см. ActService::foldAircraftServiceLogIntoAct().
     */
    public function markFoldedIntoAct(int $id, int $actId): void
    {
        $this->update($id, [
            'status' => 'folded_into_act',
            'act_id' => $actId,
        ]);
    }

    /**
     * Админ отменяет ошибочно внесённую запись — только пока она в
     * статусе draft (запись, уже перенесённую в акт, отменять через этот
     * метод нельзя — это будет отдельная, бухгалтерская операция в Фазе 2).
     */
    public function cancel(int $id, int $cancelledByUserId): bool
    {
        $row = $this->find($id);
        if (!$row || $row['status'] !== 'draft') {
            return false;
        }

        $this->update($id, [
            'status'               => 'cancelled',
            'cancelled_by_user_id' => $cancelledByUserId,
            'cancelled_at'         => date('Y-m-d H:i:s'),
        ]);

        return true;
    }
}
