<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\FlightServiceCharge;
use App\Core\Database;
use App\Services\MtowValidator;

class ServiceChargeController extends Controller
{
    private FlightServiceCharge $model;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model = new FlightServiceCharge();
    }

    public function add(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'sop', 'spo', 'sst', 'aero', 'spasop']);

        $flightId   = (int)$this->request->input('flight_id');
        $serviceId  = (int)$this->request->input('service_id');
        $quantity   = (float)$this->request->input('quantity');
        $note       = trim((string)$this->request->input('note', ''));
        $sourceRole = $this->request->input('source_role', '');

        if (!$flightId || !$serviceId || $quantity <= 0) {
            View::setFlash('error', 'Заполните услугу и количество.');
            $this->redirectBack($sourceRole, $flightId);
            return;
        }

        // ДОБАВЛЕНО (12.10.2026, раунд 67): PAX_ARR/PAX_DEP/BAG_ARR_PC/
        // BAG_DEP_PC по прямому указанию пользователя (раунд 38) считаются
        // ТОЛЬКО из пассажирского манифеста при сборке акта — супервайзер
        // лишь подтверждает факт оказания услуги, количество вручную не
        // вводится никем. ServiceOrderController::confirmServiceCharge()
        // уже исключает эти 4 кода для роли 'supervisor' — но этот метод
        // (общая форма "+ Добавить услугу", доступна СОП/СПО/ССТ/
        // аэродромной/СПАСОП) такого исключения не имел, что позволяло
        // внести по ним ПРОИЗВОЛЬНОЕ количество вручную, минуя манифест.
        // Реальный случай из дампа 12.10.2026 (рейс #42, RSJ7165): СОП внёс
        // PAX_DEP=1 вручную через эту форму, а поданный позже манифест
        // показал 0 пассажиров — начисление осталось "висеть" с неверным
        // количеством, не связанным с официальным манифестом (см.
        // claude/analysis-2026-10-12-round67-...md). Закрываем ту же
        // лазейку здесь.
        $manifestDrivenCodes = ['PAX_ARR', 'PAX_DEP', 'BAG_ARR_PC', 'BAG_DEP_PC'];
        $stmtSvcCode = Database::getInstance()->prepare("SELECT code, name FROM services WHERE id = ? LIMIT 1");
        $stmtSvcCode->execute([$serviceId]);
        $svcForCheck = $stmtSvcCode->fetch(\PDO::FETCH_ASSOC);
        if ($svcForCheck && in_array($svcForCheck['code'], $manifestDrivenCodes, true)) {
            View::setFlash('error',
                'Количество по услуге «' . $svcForCheck['name'] . '» вводится только через манифест рейса '
                . '(Манифест → подать/посмотреть) — вручную здесь не вносится.'
            );
            $this->redirectBack($sourceRole, $flightId);
            return;
        }

        // ДОБАВЛЕНО (аудит 09.07.2026, п.1): блокировка после утверждения акта.
        if ($lockStatus = (new \App\Models\Act())->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirectBack($sourceRole, $flightId);
            return;
        }

        // ── Валидация MTOW ───────────────────────────────────────────────────
        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT aircraft_mtow, mtow FROM flights WHERE id = ? LIMIT 1");
        $stmt->execute([$flightId]);
        $flight = $stmt->fetch(\PDO::FETCH_ASSOC);
        $mtow   = (float)($flight['aircraft_mtow'] ?? $flight['mtow'] ?? 0);

        if ($mtow > 0) {
            $mtowValidator = new MtowValidator();
            $check = $mtowValidator->validate($serviceId, $mtow);
            if (!$check['valid']) {
                View::setFlash('error', $check['message']);
                $this->redirectBack($sourceRole, $flightId);
                return;
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $sourceRole = $sourceRole ?: (Auth::user()['role_code'] ?? 'unknown');

        // ── Проверка дублей между службами ──────────────────────────────────
        // Та же логика, что и в EmergencyLogController — распространена на все
        // службы (СОП/СПО/ССТ/Аэродромная), а не только на СПАСОП.
        // Услуга уже внесена ДРУГИМ источником (в т.ч. 'system' — авторасчёт)?
        $stmtOther = $db->prepare(
            "SELECT id, source_role FROM flight_service_charges
             WHERE flight_id = ? AND service_id = ? AND source_role != ?
             LIMIT 1"
        );
        $stmtOther->execute([$flightId, $serviceId, $sourceRole]);
        $byOther = $stmtOther->fetch(\PDO::FETCH_ASSOC);
        if ($byOther) {
            $otherLabel = match($byOther['source_role']) {
                'system'     => 'автоматически (авторасчёт)',
                'supervisor' => 'подтверждена супервайзером на перроне',
                default      => 'службой «' . $byOther['source_role'] . '»',
            };
            View::setFlash('error',
                'Эта услуга уже внесена ' . $otherLabel . '. Внесение дублей не допускается — обратитесь в ЦОК.'
            );
            $this->redirectBack($sourceRole, $flightId);
            return;
        }

        // ── Взаимоисключающие группы услуг (exclusive_group) ─────────────────
        // ДОБАВЛЕНО (аудит 10.07.2026): вместо хардкода "DCS_AP нельзя с DCS_VPN"
        // используем поле services.exclusive_group — назначается через
        // Справочники → Услуги, без участия разработчика. Если у вносимой
        // услуги задана группа — проверяем, нет ли на этом рейсе уже другой
        // услуги из той же группы.
        $stmtGroup = $db->prepare("SELECT exclusive_group FROM services WHERE id = ? LIMIT 1");
        $stmtGroup->execute([$serviceId]);
        $myGroup = $stmtGroup->fetchColumn();
        if ($myGroup) {
            $stmtGroupConflict = $db->prepare(
                "SELECT s2.name FROM flight_service_charges fsc
                 JOIN services s2 ON s2.id = fsc.service_id
                 WHERE fsc.flight_id = ? AND s2.exclusive_group = ? AND s2.id != ?
                 LIMIT 1"
            );
            $stmtGroupConflict->execute([$flightId, $myGroup, $serviceId]);
            $conflictName = $stmtGroupConflict->fetchColumn();
            if ($conflictName) {
                View::setFlash('error',
                    'Нельзя внести — на этом рейсе уже оказана взаимоисключающая услуга «' . $conflictName . '» (одна из группы, обе одновременно не применяются).'
                );
                $this->redirectBack($sourceRole, $flightId);
                return;
            }
        }

        // ── Своя запись уже есть? Обновляем количество вместо второй строки ──
        $stmtOwn = $db->prepare(
            "SELECT id, consumed_in_act_id FROM flight_service_charges
             WHERE flight_id = ? AND service_id = ? AND source_role = ?
             LIMIT 1"
        );
        $stmtOwn->execute([$flightId, $serviceId, $sourceRole]);
        $existing = $stmtOwn->fetch(\PDO::FETCH_ASSOC);

        if ($existing && $existing['consumed_in_act_id']) {
            View::setFlash('error', 'Эта услуга уже включена в акт — изменение количества здесь невозможно. Обратитесь в ЦОК.');
            $this->redirectBack($sourceRole, $flightId);
            return;
        }

        try {
            if ($existing) {
                $this->model->update((int)$existing['id'], [
                    'quantity'   => $quantity,
                    'note'       => $note ?: null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                View::setFlash('success', 'Количество по услуге обновлено.');
            } else {
                $this->model->insert([
                    'flight_id'          => $flightId,
                    'service_id'         => $serviceId,
                    'source_role'        => $sourceRole,
                    'source_doc_type'    => null,
                    'quantity'           => $quantity,
                    'is_auto'            => 0,
                    'note'               => $note ?: null,
                    'created_by_user_id' => Auth::id() ?: null,
                ]);
                View::setFlash('success', 'Услуга добавлена в список оказанных по рейсу.');
            }
        } catch (\Throwable $e) {
            error_log('ServiceChargeController::add error: ' . $e->getMessage());
            View::setFlash('error', 'Ошибка при сохранении услуги: ' . $e->getMessage());
        }
        $this->redirectBack($sourceRole, $flightId);
    }

    public function delete(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'sop', 'spo', 'sst', 'aero', 'spasop']);
        $id = (int)($params['id'] ?? 0);

        $charge = $this->model->find($id);
        if (!$charge) { $this->redirect('/flights'); return; }

        if (!empty($charge['consumed_in_act_id'])) {
            View::setFlash('error', 'Услуга уже включена в акт — удаление невозможно.');
            $this->redirectBack($charge['source_role'], (int)$charge['flight_id']);
            return;
        }

        if ($lockStatus = (new \App\Models\Act())->getLockStatus((int)$charge['flight_id'])) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirectBack($charge['source_role'], (int)$charge['flight_id']);
            return;
        }

        $flightId   = (int)$charge['flight_id'];
        $sourceRole = $charge['source_role'];
        $this->model->delete($id);

        View::setFlash('success', 'Услуга удалена.');
        $this->redirectBack($sourceRole, $flightId);
    }

    private function redirectBack(string $sourceRole, int $flightId): void
    {
        // ИСПРАВЛЕНО (аудит 09.07.2026): не было записей для 'aero' и 'spasop' —
        // Аэродромная служба после добавления услуги улетала на общую страницу
        // рейса вместо своей формы акта стоянки.
        $map = [
            'sop'     => "/manifests/flight/{$flightId}",
            'spo'     => "/tech-cards/flight/{$flightId}",
            'sst'     => "/equipment-logs/flight/{$flightId}",
            'aero'    => "/parking-acts/flight/{$flightId}",
            'spasop'  => "/emergency-logs/flight/{$flightId}",
        ];
        $this->redirect($map[$sourceRole] ?? "/flights/{$flightId}");
    }
}