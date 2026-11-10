<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

/**
 * ДОБАВЛЕНО (аудит 03.08.2026, round 6): данные заправки — литры/плотность/
 * температура. Супервайзер вносит вместо обычной службы (FUEL_SVC.owner_role
 * = 'supervisor'), тоннаж для биллинга считается автоматически.
 */
class FlightFuelDetail extends Model
{
    protected string $table = 'flight_fuel_details';

    public function findByChargeId(int $chargeId): ?array
    {
        $row = $this->query(
            "SELECT * FROM flight_fuel_details WHERE flight_service_charge_id = ? LIMIT 1",
            [$chargeId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Данные ПЕРВОЙ заправки по рейсу (для обратной совместимости — не
     * используется в печати акта с раунда 141, см. findAllByFlightId()
     * ниже). Оставлено на случай, если где-то ещё понадобится «просто
     * какая-то одна» запись, не весь список.
     */
    public function findByFlightId(int $flightId): ?array
    {
        $rows = $this->findAllByFlightId($flightId);
        return $rows[0] ?? null;
    }

    /**
     * ДОБАВЛЕНО (30.10.2026, раунд 141, по прямому указанию пользователя):
     * на рейсе может быть несколько ЗАПРАВОК (основная + одна или больше
     * дозаправок, каждая — своя позиция плана/своя биллинговая запись, см.
     * round126). У каждой заправки СВОИ литры/плотность/температура — они
     * НЕ должны схлопываться в одну запись и не должны усредняться или
     * суммироваться между собой (сумма имеет смысл только для тоннажа в
     * биллинге, см. _merge_slot_items() в acts/print.php — это отдельный,
     * уже работающий механизм, не трогаем). Раньше здесь был findByFlightId()
     * с LIMIT 1 без ORDER BY — при 2+ заправках печатная форма акта тихо
     * показывала данные только ОДНОЙ из них (первая попавшаяся по порядку
     * чтения MySQL, не гарантированно первая по времени), остальные заправки
     * пропадали из печати совсем, хотя их тоннаж в общую сумму всё равно
     * шёл. Теперь возвращаем ВСЕ заправки рейса по порядку их фактического
     * возникновения (order_id — id позиции плана, назначается по порядку
     * создания: основная заправка/первая дозаправка/вторая и т.д., см.
     * ServiceOrderController::fuelForm()/saveFuel() — order_id сохраняется
     * там с раунда 126).
     *
     * @return array<int, array<string, mixed>> По одной записи на каждую
     *         заправку, в порядке совершения (индекс 0 — первая/основная).
     */
    public function findAllByFlightId(int $flightId): array
    {
        $rows = $this->query(
            "SELECT ffd.*, fsc.order_id
             FROM flight_fuel_details ffd
             JOIN flight_service_charges fsc ON fsc.id = ffd.flight_service_charge_id
             WHERE fsc.flight_id = ?
             ORDER BY fsc.order_id ASC, ffd.id ASC",
            [$flightId]
        )->fetchAll(\PDO::FETCH_ASSOC);
        return $rows ?: [];
    }
}
