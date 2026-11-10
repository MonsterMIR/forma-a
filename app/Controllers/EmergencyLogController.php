<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Validator;
use App\Core\Auth;
use App\Core\ActivityLogger;
use App\Core\Database;

/**
 * EmergencyLogController — Журнал СПАСОП
 * Служба поискового и аварийно-спасательного обеспечения полётов.
 */
class EmergencyLogController extends Controller
{
    private \PDO $db;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->db = Database::getInstance();
    }

    /** Список услуг СПАСОП по рейсу */
    public function flightIndex(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "СПАСОП" отключён для всех, кроме admin — см.
        // Permissions::$matrix, ключ emergency.* и комментарий там же.
        $this->requireRole(['admin']);

        $flightId = (int)$params['flight_id'];
        $flight   = $this->getFlightOrFail($flightId);

        // Услуги СПАСОП для этого рейса (без tariff_version_id — его нет в схеме)
        $stmt = $this->db->prepare(
            "SELECT fsc.id, fsc.service_id, fsc.quantity, fsc.source_role,
                    fsc.note, fsc.consumed_in_act_id, fsc.created_at,
                    s.name AS service_name, s.unit_name,
                    u.full_name AS created_by_name
             FROM flight_service_charges fsc
             JOIN services s ON s.id = fsc.service_id
             LEFT JOIN users u ON u.id = fsc.created_by_user_id
             WHERE fsc.flight_id = ? AND fsc.source_role = 'spasop'
             ORDER BY fsc.created_at ASC"
        );
        $stmt->execute([$flightId]);
        $charges = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Считаем суммы динамически через активный тариф
        $today = date('Y-m-d');
        foreach ($charges as &$c) {
            $tariff = $this->findTariff(
                (int)$c['service_id'],
                $flight['route_type'],
                $flight['operation_type'],
                $today
            );
            $c['price']           = $tariff ? (float)$tariff['price']    : 0.0;
            $c['vat_rate']        = $tariff ? (float)$tariff['vat_rate'] : 0.0;
            $c['currency_code']   = $tariff ? $tariff['currency_code']   : '—';
            $c['amount']          = $c['price'] * (float)$c['quantity'];
            $c['vat_amount']      = $c['amount'] * ($c['vat_rate'] / 100);
            $c['amount_with_vat'] = $c['amount'] + $c['vat_amount'];
        }
        unset($c);

        // Доступные услуги СПАСОП (owner_role = spasop) с активным тарифом
        $available = $this->getAvailableServices(
            $flight['route_type'],
            $flight['operation_type'],
            $today
        );

        $lockStatus = (new \App\Models\Act())->getLockStatus($flightId);

        // ДОБАВЛЕНО (аудит 10.07.2026): предзаказ ЦОК, ещё не оказанный СПАСОП
        $pendingOrders = [];
        try {
            $pendingOrders = (new \App\Models\FlightServiceOrder())->getPendingForRole($flightId, 'spasop');
        } catch (\Throwable $e) {}

        $this->render('emergency_logs/flight_index', [
            'title'      => 'СПАСОП — рейс ' . $flight['flight_number'],
            'flight'     => $flight,
            'charges'    => $charges,
            'available'  => $available,
            'lockStatus' => $lockStatus,
            'pendingOrders' => $pendingOrders,
        ]);
    }

    /** Сохранить услугу СПАСОП */
    public function store(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "СПАСОП" отключён для всех, кроме admin — см.
        // Permissions::$matrix, ключ emergency.* и комментарий там же.
        $this->requireRole(['admin']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/flights');
            return;
        }

        $data     = $this->request->all();
        $flightId = (int)($data['flight_id'] ?? 0);
        $flight   = $this->getFlightOrFail($flightId);

        if ($lockStatus = (new \App\Models\Act())->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect("/emergency-logs/flight/{$flightId}");
            return;
        }

        $v = new Validator($data);
        $v->required('service_id', 'Услуга')
          ->required('quantity',   'Количество')
          ->positiveNumber('quantity', 'Количество');

        if ($v->fails()) {
            View::setFlash('error', $v->firstError());
            $this->redirect("/emergency-logs/flight/{$flightId}");
            return;
        }

        $serviceId = (int)$data['service_id'];
        $quantity  = (float)$data['quantity'];
        $note      = !empty($data['note']) ? trim($data['note']) : null;

        // Проверяем — не внесена ли уже эта услуга ДРУГОЙ службой
        $stmtOther = $this->db->prepare(
            "SELECT id, source_role FROM flight_service_charges
             WHERE flight_id = ? AND service_id = ? AND source_role != 'spasop'
             LIMIT 1"
        );
        $stmtOther->execute([$flightId, $serviceId]);
        $byOther = $stmtOther->fetch(\PDO::FETCH_ASSOC);
        if ($byOther) {
            $otherLabel = match($byOther['source_role']) {
                'system'     => 'автоматически (авторасчёт)',
                'supervisor' => 'подтверждена супервайзером на перроне',
                default      => 'службой «' . $byOther['source_role'] . '»',
            };
            View::setFlash('error',
                'Эта услуга уже внесена ' . $otherLabel . '. ' .
                'Внесение дублей не допускается — обратитесь в ЦОК.'
            );
            $this->redirect("/emergency-logs/flight/{$flightId}");
            return;
        }

        // ── Взаимоисключающие группы услуг (exclusive_group) ─────────────────
        $stmtGroup = $this->db->prepare("SELECT exclusive_group FROM services WHERE id = ? LIMIT 1");
        $stmtGroup->execute([$serviceId]);
        $myGroup = $stmtGroup->fetchColumn();
        if ($myGroup) {
            $stmtGroupConflict = $this->db->prepare(
                "SELECT s2.name FROM flight_service_charges fsc
                 JOIN services s2 ON s2.id = fsc.service_id
                 WHERE fsc.flight_id = ? AND s2.exclusive_group = ? AND s2.id != ?
                 LIMIT 1"
            );
            $stmtGroupConflict->execute([$flightId, $myGroup, $serviceId]);
            $conflictName = $stmtGroupConflict->fetchColumn();
            if ($conflictName) {
                View::setFlash('error',
                    'Нельзя внести — на этом рейсе уже оказана взаимоисключающая услуга «' . $conflictName . '».'
                );
                $this->redirect("/emergency-logs/flight/{$flightId}");
                return;
            }
        }

        // Проверяем — уже есть такая услуга для этого рейса от СПАСОП?
        $stmt = $this->db->prepare(
            "SELECT id FROM flight_service_charges
             WHERE flight_id = ? AND service_id = ? AND source_role = 'spasop'
             LIMIT 1"
        );
        $stmt->execute([$flightId, $serviceId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            // Обновляем количество
            $this->db->prepare(
                "UPDATE flight_service_charges
                 SET quantity = ?, note = ?, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$quantity, $note, $existing['id']]);

            ActivityLogger::log('emergency_log', $existing['id'], 'update', 'success',
                "СПАСОП: обновлена услуга service_id={$serviceId} qty={$quantity}");
        } else {
            // Вставляем новую запись
            $this->db->prepare(
                "INSERT INTO flight_service_charges
                 (flight_id, service_id, source_role, quantity, note, created_by_user_id)
                 VALUES (?, ?, 'spasop', ?, ?, ?)"
            )->execute([$flightId, $serviceId, $quantity, $note, Auth::id()]);

            $newId = (int)$this->db->lastInsertId();
            ActivityLogger::log('emergency_log', $newId, 'create', 'success',
                "СПАСОП: добавлена услуга service_id={$serviceId} qty={$quantity}");
        }

        View::setFlash('success', 'Услуга СПАСОП сохранена.');
        $this->redirect("/emergency-logs/flight/{$flightId}");
    }

    /** Удалить услугу СПАСОП */
    public function destroy(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "СПАСОП" отключён для всех, кроме admin — см.
        // Permissions::$matrix, ключ emergency.* и комментарий там же.
        $this->requireRole(['admin']);

        $id = (int)$params['id'];
        $stmt = $this->db->prepare(
            "SELECT fsc.*, f.id AS flt_id
             FROM flight_service_charges fsc
             JOIN flights f ON f.id = fsc.flight_id
             WHERE fsc.id = ? AND fsc.source_role = 'spasop'"
        );
        $stmt->execute([$id]);
        $charge = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$charge) {
            View::setFlash('error', 'Запись не найдена.');
            $this->redirect('/flights');
            return;
        }

        if ($charge['consumed_in_act_id']) {
            View::setFlash('error', 'Нельзя удалить — услуга уже включена в акт.');
            $this->redirect("/emergency-logs/flight/{$charge['flt_id']}");
            return;
        }

        if ($lockStatus = (new \App\Models\Act())->getLockStatus((int)$charge['flt_id'])) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect("/emergency-logs/flight/{$charge['flt_id']}");
            return;
        }

        $this->db->prepare(
            "DELETE FROM flight_service_charges WHERE id = ? AND source_role = 'spasop'"
        )->execute([$id]);

        ActivityLogger::log('emergency_log', $id, 'delete', 'success', 'Удалена услуга СПАСОП');
        View::setFlash('success', 'Услуга удалена.');
        $this->redirect("/emergency-logs/flight/{$charge['flt_id']}");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Доступные услуги СПАСОП с активным тарифом */
    private function getAvailableServices(
        string $routeType,
        string $operationType,
        string $date
    ): array {
        $stmt = $this->db->prepare(
            "SELECT s.id AS service_id, s.name AS service_name, s.unit_name,
                    tv.price, tv.vat_rate, c.code AS currency_code
             FROM services s
             JOIN tariff_versions tv ON tv.service_id = s.id
             JOIN currencies c ON c.id = tv.currency_id
             WHERE s.owner_role = 'spasop'
               AND s.is_active  = 1
               AND tv.is_active  = 1
               AND tv.route_type IN (?, 'both')
               AND tv.operation_type IN (?, 'both')
               AND tv.valid_from <= ?
               AND (tv.valid_to IS NULL OR tv.valid_to >= ?)
             ORDER BY s.name ASC"
        );
        $stmt->execute([$routeType, $operationType, $date, $date]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Найти активный тариф для услуги */
    private function findTariff(
        int $serviceId,
        string $routeType,
        string $operationType,
        string $date
    ): ?array {
        $stmt = $this->db->prepare(
            "SELECT tv.*, c.code AS currency_code
             FROM tariff_versions tv
             JOIN currencies c ON c.id = tv.currency_id
             WHERE tv.service_id = ?
               AND tv.is_active   = 1
               AND tv.route_type IN (?, 'both')
               AND tv.operation_type IN (?, 'both')
               AND tv.valid_from <= ?
               AND (tv.valid_to IS NULL OR tv.valid_to >= ?)
             ORDER BY tv.valid_from DESC
             LIMIT 1"
        );
        $stmt->execute([$serviceId, $routeType, $operationType, $date, $date]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Получить рейс или редирект */
    private function getFlightOrFail(int $id): array
    {
        $stmt = $this->db->prepare(
            "SELECT f.*, al.name AS airline_name, al.iata_code AS airline_iata
             FROM flights f
             LEFT JOIN airlines al ON al.id = f.airline_id
             WHERE f.id = ?"
        );
        $stmt->execute([$id]);
        $flight = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$flight) {
            View::setFlash('error', 'Рейс не найден.');
            $this->redirect('/flights');
            exit;
        }
        return $flight;
    }
}