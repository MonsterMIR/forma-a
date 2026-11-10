<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\Aircraft;
use App\Models\Flight;

/**
 * ДОБАВЛЕНО (21.10.2026, раунд 108, по прямому указанию пользователя):
 * "справочник бортов" — READ-ONLY страница текущего статуса каждого борта
 * (на земле у нас / улетел), по данным последнего по факту движения из
 * `flights` (см. App\Models\Flight::getFleetStatusBoard()).
 *
 * Это первый, безопасный шаг пересмотра логики автосвязки рейсов, начатого
 * после случая P4VVIP (рейсы #84/#97, акт 0077, раунд 106) — сама логика
 * связки (Flight::findLinkCandidates()/FlightService::autoLinkFlight())
 * этим раундом ЕЩЁ НЕ меняется, страница ничего не записывает в БД.
 *
 * Доступ — та же роль-матрица, что и у /flights (Permissions::
 * 'flights.view'), т.к. это производный отчёт по тем же данным, которые
 * эти роли и так видят на странице рейсов; отдельное право не заводим.
 *
 * ИЗМЕНЕНО (22.10.2026, раунд 117, по прямому указанию пользователя):
 * страница стала не только read-only отчётом, но и админкой мастер-
 * справочника MTOW бортов (таблица `aircraft`, см. App\Models\Aircraft).
 * Повод и концепция подробно описаны в App\Models\Aircraft и
 * App\Services\FlightService (блоки "РАУНД 117") — вкратце: MTOW
 * физически принадлежит борту, а не рейсу, и теперь хранится один раз в
 * справочнике и подставляется автоматически при создании/редактировании
 * новых рейсов того же борта, вместо повторного ручного ввода диспетчером
 * ЦОК на каждом рейсе (что и порождало расхождения — 9 из 45 бортов на
 * момент раунда 117).
 *
 * Редактирование (confirm/save) и JSON lookup для форм рейсов — только
 * admin/cok (та же роль-матрица, что и у создания/редактирования самих
 * рейсов, см. FlightController::store()/update()) — просмотр (index)
 * остаётся доступен всей широкой роль-матрице, как и раньше.
 *
 * ДОБАВЛЕНО (24.10.2026, раунд 121, по прямому указанию пользователя):
 * попап "История борта" между колонками "Авиакомпания" и "Стоянка" —
 * полная история рейсов конкретного борта в нашем аэропорту (и прилёты,
 * и вылеты). Read-only отчёт (см. history()), доступ — та же широкая
 * роль-матрица, что и у index(), т.к. это просто детализация того же
 * справочника по одному борту, ничего не пишет в БД.
 */
class AircraftController extends Controller
{
    private Flight $flightModel;
    private Aircraft $aircraftModel;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->flightModel = new Flight();
        $this->aircraftModel = new Aircraft();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'sop', 'spo', 'sst', 'aero', 'spasop', 'commercial', 'finance', 'supervisor', 'gsm', 'medical', 'balance']);

        $this->render('aircraft/index', [
            'title'    => 'Справочник бортов',
            'items'    => $this->aircraftModel->getAllWithFleetStatus(),
            'canEdit'  => Auth::hasRole(['admin', 'cok']),
        ]);
    }

    /**
     * ДОБАВЛЕНО (06.11.2026, раунд 154, по прямому указанию пользователя):
     * включение/выключение признака «борт базируется в нашем аэропорту»
     * (App\Models\Aircraft::is_based) — см. докблок Aircraft::setBased()
     * и Flight::findChronologicalLinkCandidates(). Доступ — та же роль-
     * матрица, что и у save() (подтверждение MTOW): admin/cok.
     */
    public function setBased(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        $id           = (int)$this->request->input('id');
        $registration = trim((string)$this->request->input('registration', ''));
        $isBased      = (string)$this->request->input('is_based', '0') === '1';
        $note         = trim((string)$this->request->input('based_note', ''));

        if (!$id) {
            View::setFlash('error', 'Борт не найден.');
            $this->redirect('/aircraft-status');
            return;
        }

        $this->aircraftModel->setBased($id, $isBased, $note ?: null, (int)Auth::id());

        View::setFlash('success', $isBased
            ? 'Борт ' . ($registration ?: '') . ' отмечен как базирующийся в нашем аэропорту.'
            : 'Борт ' . ($registration ?: '') . ' снят с базирования в нашем аэропорту.');
        $this->redirect('/aircraft-status');
    }

    /**
     * ДОБАВЛЕНО (22.10.2026, раунд 117): подтверждение/правка MTOW борта
     * начальником ЦОК (или админом) — после утверждения спорного значения,
     * либо коррекция ошибочно подтверждённого. См. App\Models\Aircraft::confirm().
     */
    public function save(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        $id           = (int)$this->request->input('id');
        $registration = trim((string)$this->request->input('registration', ''));
        $aircraftType = trim((string)$this->request->input('aircraft_type', ''));
        $mtowRaw      = $this->request->input('mtow');
        $mtow         = ($mtowRaw !== null && $mtowRaw !== '' && is_numeric($mtowRaw)) ? (float)$mtowRaw : null;

        if (!$id || $mtow === null || $mtow <= 0) {
            View::setFlash('error', 'Проверьте введённые данные — MTOW должен быть указан и больше нуля.');
            $this->redirect('/aircraft-status');
            return;
        }

        $this->aircraftModel->confirm($id, $aircraftType ?: null, $mtow, (int)Auth::id());
        View::setFlash('success', 'MTOW борта ' . ($registration ?: '') . ' подтверждён: ' . $mtow . ' т.');
        $this->redirect('/aircraft-status');
    }

    /**
     * ДОБАВЛЕНО (22.10.2026, раунд 117): JSON-поиск борта по регистрации —
     * используется формами создания/редактирования рейса (flights/create.php,
     * flights/edit.php), чтобы подставить MTOW из справочника при вводе уже
     * известного (подтверждённого) борта. Ничего не пишет в БД.
     *
     * Ответ:
     *   found=false              — борт ещё не встречался (ни одной записи)
     *   found=true, resolved=true  — борт подтверждён, mtow/aircraft_type можно подставлять
     *   found=true, resolved=false — борт "спорный" (расхождение в истории,
     *                                 ждёт решения ЦОК) ЛИБО был вручную сброшен
     *                                 администратором — подставлять НЕЛЬЗЯ,
     *                                 обычный ручной ввод
     */
    public function lookup(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        $registration = trim((string)$this->request->get('registration', ''));
        header('Content-Type: application/json');

        if ($registration === '') {
            echo json_encode(['found' => false]);
            return;
        }

        $row = $this->aircraftModel->findByRegistration($registration);
        if (!$row) {
            echo json_encode(['found' => false]);
            return;
        }

        if ($row['mtow'] !== null) {
            echo json_encode([
                'found'         => true,
                'resolved'      => true,
                'mtow'          => (float)$row['mtow'],
                'aircraft_type' => $row['aircraft_type'],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode([
            'found'    => true,
            'resolved' => false,
        ]);
    }

    /**
     * ДОБАВЛЕНО (24.10.2026, раунд 121, по прямому указанию пользователя):
     * "добавь между Авиакомпания и Стоянка кнопку, которая в свою очередь
     * покажет в попап окне историю борта в нашем аэропорту" — JSON с
     * готовым HTML (та же схема, что и ActController::textBreakdown() /
     * ActService::buildBreakdownHtml() — попап списка актов, round71b/80):
     * все рейсы этого борта (и прилёты, и вылеты) в нашем аэропорту, новые
     * сверху. Read-only, ничего не пишет в БД. См. App\Models\Flight::
     * getHistoryByRegistration().
     */
    public function history(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'sop', 'spo', 'sst', 'aero', 'spasop', 'commercial', 'finance', 'supervisor', 'gsm', 'medical', 'balance']);

        $registration = trim((string)$this->request->get('registration', ''));
        header('Content-Type: application/json');

        if ($registration === '') {
            echo json_encode(['success' => false, 'message' => 'Не указана регистрация борта.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $rows = $this->flightModel->getHistoryByRegistration($registration);
        echo json_encode([
            'success'      => true,
            'registration' => $registration,
            'count'        => count($rows),
            'html'         => $this->renderHistoryHtml($rows),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * ДОБАВЛЕНО (24.10.2026, раунд 121) — см. history() выше. Разметка и
     * статус-бейджи акта намеренно зеркалят resources/views/flights/index.php
     * (та же палитра/подписи статусов акта), чтобы попап визуально не
     * расходился с тем, что пользователь уже видит на странице /flights.
     */
    private function renderHistoryHtml(array $rows): string
    {
        if (empty($rows)) {
            return '<div class="text-muted text-center py-3">Рейсов этого борта в системе не найдено.</div>';
        }

        $actBadgeClass = static function (?string $status): string {
            return match ($status) {
                'draft'     => 'bg-warning text-dark',
                'approved'  => 'bg-success',
                'exported'  => 'bg-primary',
                'cancelled' => 'bg-danger',
                default     => 'bg-secondary',
            };
        };
        $actStatusLabel = static function (?string $status): string {
            return match ($status) {
                'draft'     => 'Предв.',
                'approved'  => 'Утверждён',
                'exported'  => 'Экспорт.',
                'cancelled' => 'Отменён',
                default     => e((string)$status),
            };
        };

        ob_start();
        ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Рейс</th>
                        <th>Дата/время</th>
                        <th>Тип</th>
                        <th>Откуда</th>
                        <th>Куда</th>
                        <th>Стоянка</th>
                        <th>Связан</th>
                        <th>Акт</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $f): ?>
                    <tr>
                        <td><a href="/flights/<?= (int)$f['id'] ?>" target="_blank"><?= e($f['flight_number']) ?></a></td>
                        <td>
                            <?= e($f['flight_date']) ?>
                            <?php if (!empty($f['scheduled_time'])): ?> <?= e(substr((string)$f['scheduled_time'], 0, 5)) ?><?php endif; ?>
                        </td>
                        <td>
                            <?= $f['operation_type'] === 'arrival'
                                ? '<span class="badge bg-success">прилёт</span>'
                                : '<span class="badge bg-warning text-dark">вылет</span>' ?>
                        </td>
                        <td><?= e($f['origin_iata'] ?? $f['origin_name'] ?? '—') ?></td>
                        <td><?= e($f['destination_iata'] ?? $f['destination_name'] ?? '—') ?></td>
                        <td><?= e($f['mc_number'] ?? '—') ?></td>
                        <td>
                            <?= !empty($f['linked_flight_id'])
                                ? '<span class="badge bg-info text-dark">да</span>'
                                : '<span class="badge bg-light text-muted border">нет</span>' ?>
                        </td>
                        <td>
                            <?php if (empty($f['act_id'])): ?>
                                <span class="badge bg-secondary">нет акта</span>
                            <?php else: ?>
                                <a href="/acts/<?= (int)$f['act_id'] ?>" target="_blank" class="badge text-decoration-none <?= $actBadgeClass($f['act_status']) ?>">
                                    <?= $actStatusLabel($f['act_status']) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}
