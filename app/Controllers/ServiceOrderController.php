<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Core\Csrf;
use App\Core\ActivityLogger;
use App\Models\Flight;
use App\Models\Act;
use App\Models\TariffVersion;
use App\Models\FlightServiceOrder;
use App\Models\FlightServiceCharge;
use App\Models\FlightFuelDetail;
use App\Models\ParkingStand;
use App\Models\UserActivityLog;
use App\Services\NotificationService;
use App\Services\ParkingStandService;

/**
 * ДОБАВЛЕНО (аудит 10.07.2026): предзаказ услуг/транспорта ЦОК при ведении
 * рейса + дашборд/тайминг супервайзера на планшете.
 *
 * Модель ролей (важно, зафиксировано 03.08.2026):
 *  - ЦОК планирует состав заказа (что нужно) — сидит в кабинете, не видит
 *    перрон в реальном времени.
 *  - Супервайзер — по сути тот же ЦОК, но физически на перроне: видит
 *    весь план, контролирует и фиксирует тайминг оказания каждой позиции
 *    от начала до конца, может добавить услугу, которую ЦОК не
 *    предусмотрел. Топливо — особый случай, вводит литры/плотность/
 *    температуру вместо простого тайминга.
 *  - Супервайзер не привязан к конкретному рейсу ЗАРАНЕЕ — берёт в работу
 *    любой видимый ему активный рейс (на смене несколько супервайзеров
 *    работают параллельно на разных бортах).
 *  - Учётные записи супервайзеров — обычные пользователи с role_code=
 *    'supervisor', заводятся как все остальные через Пользователи → Создать.
 *
 * ИЗМЕНЕНО (23.09.2026, раунд 55, по прямому указанию пользователя):
 * первое реальное действие супервайзера по рейсу автоматически закрепляет
 * рейс за ним (`flights.handling_supervisor_id`, см. App\Models\Flight::
 * tryClaimForSupervisor()) — пока рейс закреплён за одним супервайзером,
 * остальные супервайзеры (не ЦОК/админ) его не видят в общем списке
 * (dashboard()) и не могут по нему действовать (guardSupervisorOwnership()),
 * чтобы несколько супервайзеров на одной смене не путались на одних и тех
 * же бортах. Передать рейс другому супервайзеру — только явно, кнопкой
 * «Передать смену» (handover()).
 */
class ServiceOrderController extends Controller
{
    private FlightServiceOrder $model;
    private Flight             $flightModel;
    private Act                $actModel;
    private TariffVersion      $tariffModel;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model       = new FlightServiceOrder();
        $this->flightModel = new Flight();
        $this->actModel    = new Act();
        $this->tariffModel = new TariffVersion();
    }

    /**
     * Сохранение чек-листа предзаказа (вызывается со страницы рейса, ЦОК).
     * Логика вынесена в FlightServiceOrder::syncPlan() — используется тут
     * же, а также в FlightController::update() (чек-лист на форме
     * редактирования рейса).
     */
    public function save(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        $flightId = (int)$params['id'];

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/flights/' . $flightId);
            return;
        }

        $flight = $this->flightModel->find($flightId);
        if (!$flight) {
            View::setFlash('error', 'Рейс не найден.');
            $this->redirect('/flights');
            return;
        }

        $checkedServiceIds = array_map('intval', (array)$this->request->input('service_ids', []));
        $result = $this->model->syncPlan($flightId, $checkedServiceIds, Auth::id());

        // ДОБАВЛЕНО (06.08.2026): уведомляем службы только по реально новым
        // позициям (added_service_ids), не по всему отмеченному списку —
        // иначе служба получала бы уведомление заново при каждом сохранении.
        if (!empty($result['added_service_ids'])) {
            try {
                (new NotificationService())->notifyPreorderCreated($flightId, $result['added_service_ids'], Auth::id());
            } catch (\Throwable $e) {
                error_log('ServiceOrderController::save notify error: ' . $e->getMessage());
            }
        }

        $msg = "Предзаказ обновлён: добавлено {$result['added']}, снято {$result['removed']}.";
        if (!empty($result['restored'])) {
            $msg .= " Восстановлено ранее убранных супервайзером: {$result['restored']}.";
        }
        if ($result['skipped'] > 0) {
            $msg .= " {$result['skipped']} позиций уже в работе супервайзера — не тронуты.";
        }
        View::setFlash('success', $msg);
        $this->redirect('/flights/' . $flightId);
    }

    // ── Дашборд супервайзера ────────────────────────────────────────────────

    public function dashboard(array $params = []): void
    {
        // ИЗМЕНЕНО (05.08.2026, round 16): службы тоже могут зайти
        // посмотреть план по рейсам — без прав на действия.
        $this->requireRole(['admin', 'cok', 'supervisor', 'sop', 'spo', 'sst', 'aero', 'spasop']);

        $today      = date('Y-m-d');
        $viewerRole = Auth::user()['role_code'] ?? '';
        $viewerId   = (int)Auth::id();
        $db = \App\Core\Database::getInstance();
        // Database::getInstance() — голый PDO. У него query() принимает
        // $fetchMode вторым аргументом, а не массив параметров — поэтому
        // ЗДЕСЬ ВСЕГДА prepare()+execute(), никогда $db->query($sql, $params).
        // ДОБАВЛЕНО (18.09.2026, раунд 47, по просьбе супервайзеров): в
        // предварительной карточке рейса нужны направление (откуда/куда) и
        // место стоянки (МС№) — раньше было видно только номер рейса,
        // авиакомпанию, дату/время и тип (прилёт/вылет). Стоянка — то же
        // самое поле flights.mc_number, что супервайзер сам вводит на
        // странице рейса (см. saveStandNumber() ниже) и что показывается на
        // печатной форме акта — здесь просто выводится, ничего нового не
        // считаем.
        //
        // ИЗМЕНЕНО (23.09.2026, раунд 55, по прямому указанию пользователя —
        // 3 отдельных замечания к списку рейсов у супервайзера):
        //
        // п.3: раньше список ограничивался условием `f.flight_date >=
        // вчера` — рейс, по которому акт так и не сформировали, просто
        // пропадал из виду примерно через сутки, хотя проблема (акта нет)
        // никуда не делась. Условие по дате убрано полностью — рейс виден,
        // пока по нему нет полностью закрытого акта, независимо от того,
        // сколько дней прошло с даты рейса.
        //
        // п.4: рейс с уже утверждённым/выгруженным актом (обе подписи
        // собраны, супервайзеру по нему больше нечего делать) теперь
        // скрывается — раньше висел в общем списке наравне с активными.
        //
        // п.5: рейс без MTOW и/или без стоянки (МС№) супервайзеру не
        // показывается вообще — актуально прежде всего для рейсов,
        // заведённых импортом разрешений ГА (в разрешении MTOW взять
        // неоткуда, см. анализ раунда 53), пока ЦОК не внесёт оба значения
        // вручную в карточке рейса.
        //
        // п.6: супервайзер не видит рейс, уже закреплённый (см.
        // App\Models\Flight::tryClaimForSupervisor()) за ДРУГИМ
        // супервайзером — чтобы несколько супервайзеров на одной смене не
        // путались на одних и тех же бортах. ЦОК/админ видят всё как
        // раньше — ограничение только для роли supervisor.
        $where  = [
            "(a.id IS NULL OR a.status NOT IN ('approved', 'exported'))",
            "f.aircraft_mtow IS NOT NULL AND f.aircraft_mtow > 0",
            "f.mc_number IS NOT NULL AND f.mc_number <> ''",
            // ДОБАВЛЕНО (27.10.2026, раунд 129, по прямому указанию
            // пользователя): отменённый рейс (FlightService::cancelFlight())
            // не состоялся физически — супервайзеру по нему делать нечего,
            // не должен занимать место в рабочем списке.
            "f.status != 'cancelled'",
        ];
        $wparams = [];

        if ($viewerRole === 'supervisor') {
            $where[]   = "(f.handling_supervisor_id IS NULL OR f.handling_supervisor_id = ?)";
            $wparams[] = $viewerId;
        }

        // ДОБАВЛЕНО (26.10.2026, раунд 128, по прямому указанию пользователя
        // — "чтобы узнать, манифест подан или нет, приходится заходить в
        // каждый рейс"): статус манифеста прямо в списке рейсов, без захода
        // в карточку. Берём статус ПОСЛЕДНЕГО манифеста типа 'final' по
        // рейсу — та же логика выбора, что и PassengerManifest::
        // getByFlightAndType($id, 'final') на самой странице рейса (раунд
        // 122), просто инлайн как подзапрос, чтобы не плодить N+1 на список
        // из полутора сотен рейсов. NULL — манифеста вообще нет; 'draft' —
        // ещё заполняется; 'submitted'/'accepted' — подан (см. разметку в
        // supervisor_dashboard.php).
        // ДОБАВЛЕНО (09.11.2026, раунд 160, по прямому указанию пользователя
        // — "в каждом рейсе добавь бортовой номер ВС, хендлер"): обе
        // колонки уже были в системе (flights.aircraft_registration — с
        // самого начала; flights.resolved_handler_id — парсер разрешений
        // ГА, раунд 124), просто не выводились на этой карточке списка.
        // aircraft_registration уже приходит через f.*, добавлен только
        // JOIN на handlers ради имени хендлера (сам resolved_handler_id —
        // это просто id, без JOIN было бы нечего показать).
        $sql = "SELECT f.*, al.name AS airline_name,
                       oa.iata_code AS origin_iata, oa.name AS origin_name,
                       da.iata_code AS destination_iata, da.name AS destination_name,
                       (SELECT COUNT(*) FROM flight_service_orders fso WHERE fso.flight_id = f.id) AS orders_count,
                       (SELECT COUNT(*) FROM flight_service_orders fso WHERE fso.flight_id = f.id AND fso.status = 'done') AS orders_done,
                       hs.full_name AS handling_supervisor_name,
                       h.name AS handler_name,
                       (SELECT pm.status FROM passenger_manifests pm
                         WHERE pm.flight_id = f.id AND pm.manifest_type = 'final'
                         ORDER BY pm.created_at DESC LIMIT 1) AS manifest_status
                FROM flights f
                LEFT JOIN airlines al ON al.id = f.airline_id
                LEFT JOIN airports oa ON oa.id = f.origin_airport_id
                LEFT JOIN airports da ON da.id = f.destination_airport_id
                LEFT JOIN acts a ON a.flight_id = f.id
                LEFT JOIN users hs ON hs.id = f.handling_supervisor_id
                LEFT JOIN handlers h ON h.id = f.resolved_handler_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY f.flight_date ASC, f.scheduled_time ASC
                LIMIT 150";
        $stmt = $db->prepare($sql);
        $stmt->execute($wparams);
        $flights = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->render('service_orders/supervisor_dashboard', [
            'title'   => 'Супервайзер — рейсы',
            'flights' => $flights,
            'today'   => $today,
        ]);
    }

    public function supervisorFlight(array $params = []): void
    {
        // ИЗМЕНЕНО (05.08.2026, round 16): службы тоже могут открыть план по
        // рейсу — но только на просмотр (см. $canAct ниже).
        $this->requireRole(['admin', 'cok', 'supervisor', 'sop', 'spo', 'sst', 'aero', 'spasop']);
        $flightId = (int)$params['id'];

        $viewerRole = \App\Core\Auth::user()['role_code'] ?? '';
        $canAct     = in_array($viewerRole, ['admin', 'cok', 'supervisor'], true);

        $flight = $this->flightModel->find($flightId);
        if (!$flight) {
            View::setFlash('error', 'Рейс не найден.');
            $this->redirect('/supervisor');
            return;
        }

        // ДОБАВЛЕНО (23.09.2026, раунд 55, по прямому указанию пользователя):
        // если рейс уже закреплён (см. App\Models\Flight::
        // tryClaimForSupervisor()) за ДРУГИМ супервайзером — этот просмотр
        // не заявляет права на рейс (закрепление ставится только реальным
        // действием, см. guardSupervisorOwnership() на write-методах ниже),
        // но карточка становится "только для чтения" и показывает, кто
        // сейчас ведёт рейс — чтобы супервайзеры не путались, кто чем
        // занимается. ЦОК/админ ограничение не касается.
        $lockedByOtherSupervisor = false;
        $handlingSupervisorName  = null;
        $handlingSupervisorId    = !empty($flight['handling_supervisor_id']) ? (int)$flight['handling_supervisor_id'] : null;
        if ($handlingSupervisorId !== null) {
            $stmtOwner = \App\Core\Database::getInstance()->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            $stmtOwner->execute([$handlingSupervisorId]);
            $handlingSupervisorName = $stmtOwner->fetchColumn() ?: null;
            if ($viewerRole === 'supervisor' && $handlingSupervisorId !== (int)Auth::id()) {
                $lockedByOtherSupervisor = true;
                $canAct = false;
            }
        }

        $orders = $this->model->getByFlightWithFact($flightId);

        // ДОБАВЛЕНО (25.09.2026, раунд 58): для карточки услуги с
        // services.allow_repeat=1 в статусе 'done' — можно ли прямо сейчас
        // показать кнопку "Заказать ещё раз"? Да, если по этой же услуге на
        // этом рейсе НЕТ другого открытого (planned/in_progress) экземпляра
        // — см. _supervisor_order_card.php. Считаем один раз на весь список,
        // а не отдельным запросом на каждую карточку.
        $openServiceIds = [];
        foreach ($orders as $o) {
            if (in_array($o['status'], ['planned', 'in_progress'], true)) {
                $openServiceIds[(int)$o['service_id']] = true;
            }
        }

        $addableServices = [];
        if ($canAct) {
            // Супервайзер может добавить услугу, которую ЦОК не предусмотрел —
            // тот же справочник доступных услуг (по route_type/operation_type/
            // MTOW рейса), что видит ЦОК в чек-листе, минус то, что уже в плане.
            $alreadyPlannedIds = array_map(fn($o) => (int)$o['service_id'], $orders);
            try {
                $all = $this->tariffModel->getServicesWithActiveTariff(
                    $flight['route_type'],
                    $flight['operation_type'],
                    $flight['flight_date'],
                    null,
                    $flight['aircraft_mtow'] !== null ? (float)$flight['aircraft_mtow'] : null
                );
                foreach ($all as $svc) {
                    if (!in_array((int)$svc['id'], $alreadyPlannedIds, true)) {
                        $addableServices[] = $svc;
                    }
                }
            } catch (\Throwable $e) {}
        }

        $defaultDept = in_array($viewerRole, ['sop', 'spo', 'sst', 'aero', 'spasop'], true)
            ? $viewerRole
            : null;

        // ДОБАВЛЕНО (05.08.2026, round 17): если акт по рейсу уже сформирован
        // и ещё не утверждён — показываем ссылку на подписание.
        $act = (new \App\Models\Act())->findByFlightId($flightId);
        $canSign = $canAct && $act && !in_array($act['status'], ['approved', 'exported'], true);


        // ДОБАВЛЕНО (24.10.2026, раунд 122, по прямому указанию пользователя):
        // "супервайзеры просят у себя в интерфейсе рейса уведомление о том
        // что пассажирский манифест подан и цифры с него" — тот же манифест
        // (тип 'final'), что служит основанием для начисления услуг и гейта
        // формирования акта (раунд 38, PassengerManifest::getEffectiveForBilling()),
        // но здесь берём его НЕЗАВИСИМО от статуса (draft тоже возвращаем) —
        // супервайзеру полезно видеть и то, что манифест ещё заполняется, не
        // только то, что он уже подан. Видно и ЦОК, и любому супервайзеру —
        // не завязано на $canAct, как и $history выше.
        $manifest = null;
        try {
            $manifest = (new \App\Models\PassengerManifest())->getByFlightAndType($flightId, 'final');
        } catch (\Throwable $e) {}
        // ДОБАВЛЕНО (06.08.2026): статус доставки уведомлений о предзаказе —
        // супервайзер должен видеть, что каждая вовлечённая служба реально
        // получила (и в идеале прочитала) уведомление, а не гадать.
        $notificationStatus = [];
        if ($canAct) {
            $rows = (new \App\Models\Notification())->getDeliveryStatusForFlight($flightId, 'preorder_created');
            foreach ($rows as $row) {
                $role = $row['role_code'];
                if (!isset($notificationStatus[$role])) {
                    $notificationStatus[$role] = ['role_name' => $row['role_name'], 'recipients' => []];
                }
                $notificationStatus[$role]['recipients'][] = [
                    'full_name' => $row['full_name'],
                    'read'      => !empty($row['read_at']),
                    'pushed'    => !empty($row['push_sent_at']),
                ];
            }
        }

        // ДОБАВЛЕНО (18.08.2026, раунд 16): список стоянок для выпадающего
        // списка МС№ вместо ручного ввода — см. App\Models\ParkingStand.
        // ИЗМЕНЕНО (18.09.2026, раунд 48, по прямому указанию пользователя):
        // занятые чужими бортами стоянки вообще не предлагаются в списке —
        // см. ParkingStandService::getAvailableStands().
        $parkingStands = [];
        if ($canAct) {
            try {
                $parkingStands = (new ParkingStandService())->getAvailableStands(
                    (new ParkingStand())->getAllActive(),
                    ['aircraft_registration' => $flight['aircraft_registration'] ?? null],
                    $flightId
                );
            } catch (\Throwable $e) {}
        }

        // ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть): текущая стоянка
        // визита (с учётом возможных переносов, см. ParkingStandService::
        // getCurrentStandForArrival()) — показывается в кнопке/форме
        // "Перенос стоянки" на планшете супервайзера; null, если стоянка
        // ещё не назначена вовсе (тогда переносить пока нечего).
        $currentStandForMove = null;
        if ($canAct) {
            try {
                $currentStandForMove = (new ParkingStandService())->getCurrentStandForArrival($flight);
            } catch (\Throwable $e) {}
        }

        // ДОБАВЛЕНО (21.09.2026, раунд 49, п.1): история действий по рейсу
        // (кто и что делал) — чтобы при пересменке супервайзер, принимающий
        // рейс, видел, что уже сделано предыдущим, а не спрашивал заново.
        // Видна и ЦОК, и любому супервайзеру — не только $canAct.
        $history = [];
        try {
            $history = (new UserActivityLog())->getForEntity('flight', $flightId, 100);
        } catch (\Throwable $e) {}

        // Список супервайзеров для "Передать смену" — все активные, кроме
        // текущего (самому себе передавать нет смысла).
        $supervisorsForHandover = [];
        if ($canAct) {
            $db2 = \App\Core\Database::getInstance();
            $stmt2 = $db2->prepare(
                "SELECT u.id, u.full_name FROM users u
                 JOIN roles r ON r.id = u.role_id
                 WHERE r.code = 'supervisor' AND u.is_active = 1 AND u.id != ?
                 ORDER BY u.full_name"
            );
            $stmt2->execute([Auth::id()]);
            $supervisorsForHandover = $stmt2->fetchAll(\PDO::FETCH_ASSOC);
        }

        $this->render('service_orders/supervisor_flight', [
            'title'                  => 'План по рейсу — ' . $flight['flight_number'],
            'flight'                 => $flight,
            'orders'                 => $orders,
            'openServiceIds'         => $openServiceIds,
            'addableServices'        => $addableServices,
            'canAct'                 => $canAct,
            'defaultDeptOverride'    => $defaultDept,
            'canSign'                => $canSign,
            'act'                    => $act,
            'manifest'               => $manifest,
            'notificationStatus'     => $notificationStatus,
            'parkingStands'          => $parkingStands,
            'currentStandForMove'    => $currentStandForMove,
            'history'                => $history,
            'supervisorsForHandover' => $supervisorsForHandover,
            'handlingSupervisorId'   => $handlingSupervisorId,
            'handlingSupervisorName' => $handlingSupervisorName,
            'lockedByOtherSupervisor'=> $lockedByOtherSupervisor,
        ]);
    }

    /**
     * ДОБАВЛЕНО (23.09.2026, раунд 55): вызывается первой строкой в каждом
     * write-действии супервайзера по рейсу (кроме handover() — явная
     * передача сама меняет владельца). Роли admin/cok им не ограничены —
     * правило существует только для того, чтобы супервайзеры на одной
     * смене не наступали друг другу на одни и те же рейсы (см.
     * App\Models\Flight::tryClaimForSupervisor()). Если рейс ещё ничей —
     * закрепляет его за вызывающим (первое реальное действие = "взял в
     * работу"). Возвращает null, если действие можно продолжать, иначе —
     * готовое сообщение об ошибке (вызывающий метод сам решает, отдать его
     * через respondJson() или через flash+redirect — оба стиля есть в этом
     * контроллере).
     */
    private function supervisorOwnershipError(int $flightId): ?string
    {
        $viewerRole = Auth::user()['role_code'] ?? '';
        if ($viewerRole !== 'supervisor') {
            return null;
        }

        $result = $this->flightModel->tryClaimForSupervisor($flightId, (int)Auth::id());
        if ($result['allowed']) {
            return null;
        }

        $ownerName = $result['owner_name'] ?: ('пользователь #' . $result['owner_id']);
        return 'Рейс сейчас ведёт супервайзер ' . $ownerName
            . ' — действие недоступно. Если рейс нужно принять, попросите передать его через «Передать смену».';
    }

    /**
     * ДОБАВЛЕНО (21.09.2026, раунд 49, п.1): явная передача обслуживания
     * рейса одним супервайзером другому при пересменке.
     *
     * ИЗМЕНЕНО (23.09.2026, раунд 55, по прямому указанию пользователя):
     * раньше это была ЧИСТО фиксация факта в истории рейса + уведомление —
     * технически рейс оставался открыт для любого супервайзера (см. старый
     * докблок класса). Теперь у рейса есть реальный владелец
     * (`flights.handling_supervisor_id`, App\Models\Flight::
     * tryClaimForSupervisor()/setHandlingSupervisor()) — эта кнопка
     * единственный способ передать его другому супервайзеру: обычные
     * действия (тайминг, стоянка, добавление услуги и т.п.) закрепляют
     * рейс за первым, кто их выполнил, и блокируют для остальных
     * супервайзеров (guardSupervisorOwnership()/supervisorOwnershipError()).
     */
    public function handover(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$params['id'];
        $flight   = $this->flightModel->find($flightId);
        if (!$flight) {
            View::setFlash('error', 'Рейс не найден.');
            $this->redirect('/supervisor');
            return;
        }

        $toUserId = (int)$this->request->input('to_user_id', 0);
        $note     = trim((string)$this->request->input('note', ''));

        $db = \App\Core\Database::getInstance();
        $stmt = $db->prepare(
            "SELECT u.id, u.full_name FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND r.code = 'supervisor' AND u.is_active = 1 LIMIT 1"
        );
        $stmt->execute([$toUserId]);
        $toUser = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$toUser) {
            View::setFlash('error', 'Выберите супервайзера, которому передаётся рейс.');
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }
        if ($toUserId === (int)Auth::id()) {
            View::setFlash('error', 'Нельзя передать рейс самому себе.');
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        // ДОБАВЛЕНО (23.09.2026, раунд 55, по прямому указанию пользователя):
        // нельзя передать рейс, который сейчас ведёт ДРУГОЙ супервайзер, не
        // будучи им самим (или ЦОК/админом) — иначе один супервайзер мог бы
        // молча "перекинуть" чужой рейс третьему, о чём владелец не узнает.
        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $fromUser = Auth::user();
        $description = 'Передача обслуживания рейса: ' . ($fromUser['full_name'] ?? ('пользователь #' . Auth::id()))
            . ' → ' . $toUser['full_name'] . ($note !== '' ? '. Примечание: ' . $note : '.');

        // ДОБАВЛЕНО (23.09.2026, раунд 55, по прямому указанию пользователя):
        // раньше "Передать смену" была только записью в истории + уведомлением
        // ("технически ничего не переключает" — см. докблок handover() выше).
        // Теперь реально переносит закрепление рейса (flights.
        // handling_supervisor_id, см. App\Models\Flight::
        // tryClaimForSupervisor()/setHandlingSupervisor()) — с этого момента
        // рейс пропадает из общего списка у передающего и появляется у
        // принимающего (см. dashboard()).
        $this->flightModel->setHandlingSupervisor($flightId, $toUserId);

        ActivityLogger::log('flight', $flightId, 'supervisor_handover', 'success', $description,
            ['from_user_id' => Auth::id()], ['to_user_id' => $toUserId]);

        try {
            (new NotificationService())->notifySupervisorHandover($flightId, (int)Auth::id(), $toUserId, $note ?: null);
        } catch (\Throwable $e) {
            error_log('ServiceOrderController::handover notify error: ' . $e->getMessage());
        }

        View::setFlash('success', 'Рейс передан супервайзеру: ' . $toUser['full_name'] . '.');
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    /**
     * ДОБАВЛЕНО (21.09.2026, раунд 49, п.1): единая точка логирования
     * действий супервайзера/ЦОК на странице рейса — см. UserActivityLog::
     * getForEntity(), которая читает то, что сюда пишется, для панели
     * "История по рейсу".
     */
    private function logFlightAction(int $flightId, string $action, string $description): void
    {
        ActivityLogger::log('flight', $flightId, $action, 'success', $description);
    }

    private function serviceName(int $serviceId): string
    {
        static $cache = [];
        if (!isset($cache[$serviceId])) {
            $db = \App\Core\Database::getInstance();
            $stmt = $db->prepare("SELECT name FROM services WHERE id = ? LIMIT 1");
            $stmt->execute([$serviceId]);
            $name = $stmt->fetchColumn();
            $cache[$serviceId] = $name !== false ? (string)$name : ('услуга #' . $serviceId);
        }
        return $cache[$serviceId];
    }

    /**
     * ДОБАВЛЕНО (12.11.2026, раунд 174): код услуги (services.code) по id —
     * тот же паттерн кэширования, что и serviceName() выше. Нужен
     * updateTiming(), чтобы отличить отметку CHOCKS от остальных услуг
     * (FlightServiceOrder::find() — обычный Model::find(), без JOIN на
     * services).
     */
    private function serviceCode(int $serviceId): string
    {
        static $cache = [];
        if (!isset($cache[$serviceId])) {
            $db = \App\Core\Database::getInstance();
            $stmt = $db->prepare("SELECT code FROM services WHERE id = ? LIMIT 1");
            $stmt->execute([$serviceId]);
            $code = $stmt->fetchColumn();
            $cache[$serviceId] = $code !== false ? (string)$code : '';
        }
        return $cache[$serviceId];
    }

    /**
     * Супервайзер добавляет услугу, которую ЦОК не заказал заранее —
     * например возникла по ходу обслуживания. Попадает в тот же план
     * (flight_service_orders), видно и у соответствующей службы, и у ЦОК —
     * источник виден через ordered_by_user_id.
     *
     * ИЗМЕНЕНО (25.09.2026, раунд 58, по прямому указанию пользователя):
     * та же точка входа теперь используется и для повторного заказа услуги
     * (кнопка "Заказать ещё раз" на уже завершённой карточке, см.
     * _supervisor_order_card.php) — отдельного метода для этого специально
     * не заводили, чтобы не дублировать проверки владения/блокировки актом.
     * Для услуг с services.allow_repeat=0 поведение не изменилось —
     * блокируем по ЛЮБОЙ существующей записи (findOne). Для allow_repeat=1
     * блокируем только по ОТКРЫТОЙ записи (findOpen) — повтор разрешён,
     * если предыдущий экземпляр уже закрыт (done/cancelled).
     */
    public function addUnplanned(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/supervisor');
            return;
        }

        $flightId  = (int)$params['id'];
        $serviceId = (int)$this->request->input('service_id', 0);

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if (!$serviceId) {
            View::setFlash('error', 'Выберите услугу.');
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $allowRepeat = (bool)$this->serviceAllowsRepeat($serviceId);
        // Есть ли ВООБЩЕ хоть одна предыдущая запись (любого статуса) по
        // этой услуге на этом рейсе — нужно и для блокировки не-repeat
        // услуг, и просто для пометки "(повторно)" в журнале при repeat.
        $anyPrior = $this->model->findOne($flightId, $serviceId);
        $blockingExisting = $allowRepeat
            ? $this->model->findOpen($flightId, $serviceId)
            : $anyPrior;
        if ($blockingExisting) {
            View::setFlash('error', $allowRepeat
                ? 'Эта услуга уже в работе по рейсу — дождитесь завершения, затем закажите ещё раз.'
                : 'Эта услуга уже в плане по рейсу.');
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $db = \App\Core\Database::getInstance();
        $db->prepare(
            "INSERT INTO flight_service_orders (flight_id, service_id, ordered_by_user_id, status, created_at)
             VALUES (?, ?, ?, 'planned', NOW())"
        )->execute([$flightId, $serviceId, Auth::id()]);

        $this->logFlightAction($flightId, 'supervisor_add_service',
            'Добавлена услуга «' . $this->serviceName($serviceId) . '»' . ($anyPrior ? ' (повторно)' : '') . ' (вне предзаказа ЦОК).');

        View::setFlash('success', 'Услуга добавлена в план по рейсу.');
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    /**
     * ДОБАВЛЕНО (25.09.2026, раунд 58): признак allow_repeat услуги, с
     * лёгким кэшем на запрос — используется в addUnplanned()/
     * addUnplannedBulk(), чтобы не дублировать SELECT на каждую услугу.
     */
    private array $allowRepeatCache = [];

    private function serviceAllowsRepeat(int $serviceId): bool
    {
        if (!array_key_exists($serviceId, $this->allowRepeatCache)) {
            $db = \App\Core\Database::getInstance();
            $stmt = $db->prepare("SELECT allow_repeat FROM services WHERE id = ? LIMIT 1");
            $stmt->execute([$serviceId]);
            $this->allowRepeatCache[$serviceId] = (bool)$stmt->fetchColumn();
        }
        return $this->allowRepeatCache[$serviceId];
    }

    /**
     * ДОБАВЛЕНО (08.09.2026, раунд 30): мульти-выбор в поп-апе "Добавить
     * услугу" — супервайзер по просьбе пользователя должен уметь набрать
     * сразу несколько услуг (в т.ч. из разных служб) и подтвердить одним
     * действием, вместо повторения addUnplanned() отдельно на каждую.
     *
     * Это НАМЕРЕННО не переиспользует FlightServiceOrder::syncPlan() —
     * тот метод предназначен для полной синхронизации чек-листа ЦОК
     * (снимает/отменяет позиции, отсутствующие в переданном списке) и
     * непригоден для аддитивного "добавить ещё" супервайзера: полный
     * список того, что сейчас отмечено в плане, здесь просто не передаётся
     * и передаваться не должен — иначе один и тот же поп-ап случайно мог
     * бы снять уже оказываемые/оказанные позиции, которые в это открытие
     * поп-апа просто не были в списке "доступных к добавлению".
     *
     * Только AJAX (используется из JS модалки) — старый addUnplanned()
     * с одиночным service_id и полным редиректом оставлен как есть на
     * случай, если на него где-то ещё есть прямые ссылки/старые клиенты.
     */
    public function addUnplannedBulk(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);
        $isAjax = $this->request->header('X-Requested-With') === 'XMLHttpRequest';

        if (!Csrf::verifyRequest($this->request)) {
            $msg = 'Неверный CSRF-токен.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$params['id'];
        $rawIds = $this->request->input('service_ids', []);
        if (!is_array($rawIds)) {
            $rawIds = [$rawIds];
        }
        $serviceIds = array_values(array_unique(array_filter(array_map('intval', $rawIds))));

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            if ($isAjax) { $this->respondJson(false, $ownershipError); return; }
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if (empty($serviceIds)) {
            $msg = 'Выберите хотя бы одну услугу.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            $msg = __('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))]);
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('warning', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $db = \App\Core\Database::getInstance();
        $userId = Auth::id();
        $added = 0;
        $skipped = 0;
        foreach ($serviceIds as $serviceId) {
            // Та же защита от дублей, что и в addUnplanned() — на случай,
            // если та же услуга уже в плане (в т.ч. кто-то другой успел
            // добавить её параллельно, пока был открыт поп-ап). ИЗМЕНЕНО
            // (25.09.2026, раунд 58) — для allow_repeat=1 услуг блокируем
            // только по открытой записи, как и в addUnplanned(); в этот
            // поп-ап такие услуги обычно и не попадают (см. $alreadyPlannedIds
            // в supervisorFlight()), но проверка сохранена для устойчивости.
            $existing = $this->serviceAllowsRepeat($serviceId)
                ? $this->model->findOpen($flightId, $serviceId)
                : $this->model->findOne($flightId, $serviceId);
            if ($existing) {
                $skipped++;
                continue;
            }
            $db->prepare(
                "INSERT INTO flight_service_orders (flight_id, service_id, ordered_by_user_id, status, created_at)
                 VALUES (?, ?, ?, 'planned', NOW())"
            )->execute([$flightId, $serviceId, $userId]);
            $added++;
        }

        if ($added > 0) {
            $msg = $added === 1 ? 'Добавлена 1 услуга в план по рейсу.' : "Добавлено услуг в план: {$added}.";
            $this->logFlightAction($flightId, 'supervisor_add_service',
                "Добавлено услуг в план (вне предзаказа ЦОК): {$added}.");
        } else {
            $msg = 'Все выбранные услуги уже в плане по рейсу.';
        }
        if ($skipped > 0) {
            $msg .= " Уже были в плане: {$skipped}.";
        }

        if ($isAjax) {
            $this->respondJson(true, $msg, ['added' => $added, 'skipped' => $skipped]);
            return;
        }
        View::setFlash($added > 0 ? 'success' : 'warning', $msg);
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    /**
     * ДОБАВЛЕНО (18.08.2026): номер стоянки (MC№) супервайзер указывает
     * прямо со страницы рейса на планшете. Поле `flights.mc_number` уже
     * существовало и уже печатается в акте формы А (ActController::
     * printForm() → resources/views/acts/print.php, строка "MC№" рядом с
     * "Time of setting/removing chocks-LT") — раньше его мог заполнить
     * только ЦОК на форме создания/редактирования рейса (/flights/{id}/edit,
     * requireRole admin/cok). ЦОК обычно создаёт рейс ДО того, как известен
     * реальный номер стоянки (или его позже меняют диспетчеры перрона) —
     * супервайзер узнаёт фактический номер первым, стоя у борта, поэтому
     * даём право редактировать то же самое поле и ему, без создания
     * отдельной колонки/таблицы во избежание двух разных источников правды.
     */
    public function saveStandNumber(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$params['id'];
        $flight   = $this->flightModel->find($flightId);
        if (!$flight) {
            View::setFlash('error', 'Рейс не найден.');
            $this->redirect('/supervisor');
            return;
        }

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        // Та же нормализация, что и на форме ЦОК (см. FlightService::
        // createFlight()/updateFlight()) — верхний регистр, пусто = NULL.
        $standNumber = trim((string)$this->request->input('mc_number', ''));
        $standNumber = $standNumber !== '' ? strtoupper($standNumber) : null;

        // ДОБАВЛЕНО (18.09.2026, раунд 48, по прямому указанию пользователя):
        // "Стоянка после того как на него поставили ВС она не доступна для
        // других ВС пока не будет освобождена" — см. ParkingStandService.
        // ИЗМЕНЕНО (12.11.2026, раунд 174, по прямому указанию
        // пользователя): супервайзер больше не блокируется старым
        // определением занятости при простом назначении МС№, только
        // предупреждается. Настоящая блокировка — в момент физической
        // установки колодок (см. updateTiming() ниже).
        $standWarning = null;
        if ($standNumber !== null) {
            $standService = new ParkingStandService();
            $plannedConflict = $standService->findPlannedConflict(
                ['mc_number' => $standNumber, 'aircraft_registration' => $flight['aircraft_registration'] ?? null],
                $flightId
            );
            if ($plannedConflict) {
                $standWarning = $standService->describeConflict($plannedConflict);
            }
        }

        $this->flightModel->update($flightId, ['mc_number' => $standNumber]);

        $this->logFlightAction($flightId, 'supervisor_set_stand', $standNumber
            ? "Установлен номер стоянки: {$standNumber}."
            : 'Номер стоянки очищен.');

        $standMsg = $standNumber
            ? "Номер стоянки сохранён: {$standNumber}."
            : 'Номер стоянки очищен.';
        if ($standWarning !== null) {
            $standMsg .= ' Внимание: ' . $standWarning;
        }
        View::setFlash('success', $standMsg);
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    /**
     * ДОБАВЛЕНО (02.11.2026, раунд 142, по прямому указанию пользователя —
     * запрос супервайзеров): фактическое время рейса (ATA на прилёте / ATD
     * на вылете, flights.arr_time/dep_time) раньше вносил только ЦОК на
     * странице /flights/{id}/edit — супервайзер, который физически у борта
     * первым видит момент посадки/взлёта, такой возможности не имел (в
     * отличие от МС№, см. saveStandNumber() выше). Тот же паттерн: одно и
     * то же поле, супервайзер и ЦОК пишут в одно и то же место, не два
     * разных источника правды.
     *
     * Поле, которое можно править, определяется ТИПОМ ЭТОГО рейса
     * (operation_type) — на карточке прилёта доступен только arr_time
     * (ATA), на карточке вылета — только dep_time (ATD); второе поле со
     * страницы супервайзера недостижимо (нет формы под него), подставлять
     * его отсюда не пытаемся даже при подменённом имени поля в запросе.
     */
    public function saveActualTime(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$params['id'];
        $flight   = $this->flightModel->find($flightId);
        if (!$flight) {
            View::setFlash('error', 'Рейс не найден.');
            $this->redirect('/supervisor');
            return;
        }

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $operationType = (string)($flight['operation_type'] ?? '');
        $field = $operationType === 'arrival'
            ? 'arr_time'
            : ($operationType === 'departure' ? 'dep_time' : null);
        if ($field === null) {
            View::setFlash('error', 'Не удалось определить тип рейса.');
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $time = trim((string)$this->request->input('actual_time', ''));
        if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            View::setFlash('error', 'Некорректный формат времени.');
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $this->flightModel->update($flightId, [$field => $time !== '' ? $time : null]);

        $label = $operationType === 'arrival' ? 'ATA (время прилёта)' : 'ATD (время вылета)';
        $this->logFlightAction($flightId, 'supervisor_set_actual_time', $time !== ''
            ? "Установлено фактическое время рейса, {$label}: {$time}."
            : "Фактическое время рейса ({$label}) очищено.");

        View::setFlash('success', $time !== ''
            ? "Фактическое время сохранено: {$time}."
            : 'Фактическое время очищено.');
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    /**
     * ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть, по прямому указанию
     * пользователя): "стоянка привязывается в начале на акт прилета, на
     * акте вылета может быть другая стоянка, так как во время стоянки
     * борт могут перенести на другую стоянку... освобожденная стоянка
     * должна стать доступной для других ВС." — в отличие от
     * saveStandNumber() выше (которая просто ЗАДАЁТ МС№ текущему
     * просматриваемому рейсу и используется для ПЕРВИЧНОГО назначения на
     * прилёте), это действие — ПЕРЕНОС уже стоящего борта на другую
     * стоянку посреди визита: пишет историю в flight_stand_moves (а не
     * напрямую flights.mc_number рейса прилёта — оно остаётся историческим
     * "куда прилетел"), см. App\Services\ParkingStandService::recordMove().
     * Доступно с любой из двух страниц ротации (прилёт или вылет) — внутри
     * recordMove() сам находит якорь-прилёт этой ротации.
     *
     * Подтверждено пользователем прямым текстом, что это единственный канал
     * таких действий: "сейчас по идеи без ведома супервайзеров на перроне
     * ничего не происходить, все возможные действия с ВС только через
     * супервайзеров" — поэтому доступ (requireRole/ownership/lock) — тот же,
     * что и у saveStandNumber() выше, без исключений.
     */
    public function moveStand(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$params['id'];
        $flight   = $this->flightModel->find($flightId);
        if (!$flight) {
            View::setFlash('error', 'Рейс не найден.');
            $this->redirect('/supervisor');
            return;
        }

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $newStand = strtoupper(trim((string)$this->request->input('mc_number', '')));
        $note     = trim((string)$this->request->input('note', ''));

        $result = (new ParkingStandService())->recordMove($flight, $newStand, $note !== '' ? $note : null, (int)Auth::id());

        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $logMsg = "Перенос стоянки: {$result['old_stand']} → {$result['new_stand']}."
            . ($note !== '' ? " Примечание: {$note}." : '')
            . (!empty($result['note']) ? " ({$result['note']})" : '');
        $this->logFlightAction($flightId, 'supervisor_move_stand', $logMsg);

        View::setFlash('success', "Борт перенесён: {$result['old_stand']} → {$result['new_stand']}."
            . (!empty($result['note']) ? " Внимание: {$result['note']}" : ''));
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    /**
     * ДОБАВЛЕНО (21.08.2026, раунд 23): "Цель полёта" — печатная форма акта
     * (print.php, строка "Flight №,purpose/ №рейса, цель полета") уже давно
     * печатала 4 колонки-заголовка по официальному бланку (Pax Charter /
     * Cargo Charter / Pax Business / Tex Stop), но значение под ними никогда
     * не заполнялось — ни поля ввода, ни столбца в БД не было (в раунде 21
     * пользователь решил тогда не заводить это поле). Теперь подтверждено:
     * супервайзер отмечает ОДИН из 4 вариантов на планшете, тем же паттерном,
     * что и МС№ выше (saveStandNumber()).
     */
    public function savePurpose(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$params['id'];
        if (!$this->flightModel->find($flightId)) {
            View::setFlash('error', 'Рейс не найден.');
            $this->redirect('/supervisor');
            return;
        }

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $allowed = ['pax_charter', 'cargo_charter', 'pax_business', 'tech_stop'];
        $purpose = (string)$this->request->input('flight_purpose', '');
        $purpose = in_array($purpose, $allowed, true) ? $purpose : null;

        $this->flightModel->update($flightId, ['flight_purpose' => $purpose]);

        $labels = [
            'pax_charter'   => 'Pax Charter',
            'cargo_charter' => 'Cargo Charter',
            'pax_business'  => 'Pax Business',
            'tech_stop'     => 'Tex Stop',
        ];

        $this->logFlightAction($flightId, 'supervisor_set_purpose', $purpose
            ? 'Установлена цель полёта: ' . $labels[$purpose] . '.'
            : 'Цель полёта очищена.');

        View::setFlash('success', $purpose
            ? 'Цель полёта сохранена: ' . $labels[$purpose] . '.'
            : 'Цель полёта очищена.');
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    /**
     * ДОБАВЛЕНО (04.08.2026): убрать из плана услугу, которую ЦОК заказал
     * ошибочно или она больше не нужна — прямо с экрана супервайзера, без
     * похода к ЦОК. Разрешено только для ещё НЕ начатых позиций (status=
     * planned) — если уже "В работе"/"Оказано", для этого есть "Сбросить"
     * в updateTiming(), а полностью убрать из плана можно только пока
     * ничего реально не произошло.
     */
    /**
     * ИЗМЕНЕНО (04.08.2026): раньше физически удаляло строку плана — теперь
     * требует причину и переводит в статус 'cancelled' с сохранением
     * причины (timing_note), не удаляя запись — история "что и почему
     * убрали" не теряется.
     */
    public function removePlanned(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);
        $isAjax = $this->request->header('X-Requested-With') === 'XMLHttpRequest';

        if (!Csrf::verifyRequest($this->request)) {
            $msg = 'Неверный CSRF-токен.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $orderId = (int)$params['id'];
        $reason  = trim((string)$this->request->input('reason', ''));
        $order   = $this->model->find($orderId);

        if (!$order) {
            $msg = 'Позиция плана не найдена.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$order['flight_id'];

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            if ($isAjax) { $this->respondJson(false, $ownershipError); return; }
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($order['status'] !== 'planned') {
            $msg = 'Можно убрать только ещё не начатую позицию. Для уже начатой — используйте «Сбросить».';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($reason === '') {
            $msg = 'Укажите причину — почему услуга не нужна.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            $msg = __('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))]);
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('warning', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $this->model->update($orderId, [
            'status' => 'cancelled',
            'timing_note' => 'Убрано супервайзером: ' . $reason,
        ]);

        $this->logFlightAction($flightId, 'supervisor_remove_service',
            'Услуга «' . $this->serviceName((int)$order['service_id']) . '» убрана из плана. Причина: ' . $reason);

        if ($isAjax) {
            $this->respondJson(true, 'Услуга убрана из плана.');
            return;
        }
        View::setFlash('success', 'Услуга убрана из плана.');
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    /**
     * Быстрая фиксация тайминга — планшет, минимум полей, один тап.
     * action = start | complete | reset
     *
     * ДОБАВЛЕНО (04.08.2026): работает и как обычный POST (редирект), и как
     * AJAX (JSON-ответ, без перезагрузки страницы) — определяется по
     * заголовку X-Requested-With. Экран супервайзера теперь использует
     * AJAX-версию, чтобы тапы отрабатывали мгновенно, без мигания страницы.
     */
    public function updateTiming(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);
        $isAjax = $this->request->header('X-Requested-With') === 'XMLHttpRequest';

        if (!Csrf::verifyRequest($this->request)) {
            $msg = 'Неверный CSRF-токен.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $orderId = (int)$params['id'];
        $action  = (string)$this->request->input('action', '');
        $order   = $this->model->find($orderId);

        if (!$order) {
            $msg = 'Позиция плана не найдена.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $flightId  = (int)$order['flight_id'];
        $serviceId = (int)$order['service_id'];

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            if ($isAjax) { $this->respondJson(false, $ownershipError); return; }
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            $msg = __('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))]);
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('warning', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $db = \App\Core\Database::getInstance();
        $warning = null;

        switch ($action) {
            case 'start':
                $this->model->update($orderId, [
                    'status' => 'in_progress', 'started_at' => date('Y-m-d H:i:s'),
                ]);
                $this->logFlightAction($flightId, 'supervisor_service_start',
                    'Начато оказание услуги «' . $this->serviceName($serviceId) . '».');
                break;

            case 'complete':
                // ДОБАВЛЕНО (12.11.2026, раунд 174, по прямому указанию
                // пользователя): "правильно будет установка колодок =
                // занятию стоянки" — именно отметка «Колодки установлены»
                // на рейсе ПРИЛЁТА теперь и есть момент физического
                // занятия стоянки, а не момент записи МС№ при планировании
                // (см. ParkingStandService::findConflict()/
                // directOccupant()). Жёсткая блокировка — ТОЛЬКО здесь.
                if ($this->serviceCode($serviceId) === 'CHOCKS') {
                    $flightForChocks = $this->flightModel->find($flightId);
                    if ($flightForChocks && ($flightForChocks['operation_type'] ?? '') === 'arrival') {
                        $standService = new ParkingStandService();
                        $standCode = $standService->getCurrentStandForArrival($flightForChocks);
                        if ($standCode !== null) {
                            $standConflict = $standService->findConflict(
                                ['mc_number' => $standCode, 'aircraft_registration' => $flightForChocks['aircraft_registration'] ?? null],
                                $flightId
                            );
                            if ($standConflict) {
                                $msg = $standService->describeConflict($standConflict);
                                if ($isAjax) { $this->respondJson(false, $msg); return; }
                                View::setFlash('error', $msg);
                                $this->redirect('/supervisor/flights/' . $flightId);
                                return;
                            }
                        }
                    }
                }

                $startedAt = $order['started_at'] ?: date('Y-m-d H:i:s');
                $completedAt = date('Y-m-d H:i:s');
                $this->model->update($orderId, [
                    'status' => 'done', 'started_at' => $startedAt, 'completed_at' => $completedAt,
                ]);

                // ДОБАВЛЕНО (04.08.2026): часть услуг (пассажиры, багаж, часы,
                // литры) не может браться как default_quantity=1 — количество
                // реально меняется от рейса к рейсу. Если фронтенд прислал
                // quantity (услуга помечена requires_manual_quantity) — берём
                // введённое супервайзером число, иначе — прежнее поведение.
                $manualQty = $this->request->input('quantity', null);
                $manualQty = ($manualQty !== null && $manualQty !== '' && is_numeric($manualQty))
                    ? (float)$manualQty : null;

                // ДОБАВЛЕНО (29.09.2026, раунд 64, по прямому указанию
                // пользователя): для услуг с services.quantity_from_timing=1
                // (сейчас — GPU, AIRCOND) количество (часы) больше не вводится
                // супервайзером вручную — оно считается автоматически из
                // started_at/completed_at, которые и так уже фиксируются этим
                // же тайминг-флоу. Ручной ввод (если вдруг всё равно пришёл в
                // запросе) для таких услуг игнорируется — источник истины
                // только сервер. См. calcQuantityFromTiming() — округление
                // вверх с шагом 0.5 часа.
                if ($this->isQuantityFromTiming($serviceId)) {
                    $manualQty = $this->calcQuantityFromTiming($startedAt, $completedAt);
                }

                $chargeResult = $this->confirmServiceCharge($flightId, $serviceId, $manualQty, $orderId);
                if (!$chargeResult['success']) {
                    $warning = $chargeResult['error'];
                }
                $this->logFlightAction($flightId, 'supervisor_service_complete',
                    'Завершено оказание услуги «' . $this->serviceName($serviceId) . '».' . ($warning ? ' (' . $warning . ')' : ''));
                break;

            case 'reset':
                // ИЗМЕНЕНО (25.09.2026, раунд 58): раньше искали биллинговую
                // запись по (flight_id, service_id, source_role) — верно,
                // пока на flight_service_charges был UNIQUE(flight_id,
                // service_id) и у услуги могла быть только одна такая
                // запись. Теперь при allow_repeat=1 на рейсе может быть
                // несколько экземпляров одной услуги — сброс должен снимать
                // ТОЛЬКО запись именно ЭТОЙ позиции плана (order_id), иначе
                // рискуем удалить чужую (другого экземпляра) биллинговую
                // запись.
                $stmt = $db->prepare(
                    "SELECT id, consumed_in_act_id FROM flight_service_charges
                     WHERE order_id = ? LIMIT 1"
                );
                $stmt->execute([$orderId]);
                $charge = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($charge && $charge['consumed_in_act_id']) {
                    $msg = 'Услуга уже включена в акт — сброс невозможен. Обратитесь в ЦОК.';
                    if ($isAjax) { $this->respondJson(false, $msg); return; }
                    View::setFlash('error', $msg);
                    $this->redirect('/supervisor/flights/' . $flightId);
                    return;
                }
                if ($charge) {
                    $db->prepare("DELETE FROM flight_service_charges WHERE id = ?")->execute([$charge['id']]);
                }

                $this->model->update($orderId, [
                    'status' => 'planned', 'started_at' => null, 'completed_at' => null,
                ]);
                $this->logFlightAction($flightId, 'supervisor_service_reset',
                    'Сброшен тайминг услуги «' . $this->serviceName($serviceId) . '».');
                break;

            default:
                $msg = 'Неизвестное действие.';
                if ($isAjax) { $this->respondJson(false, $msg); return; }
                View::setFlash('error', $msg);
                $this->redirect('/supervisor/flights/' . $flightId);
                return;
        }

        if ($isAjax) {
            $updated = $this->model->find($orderId);

            // Если только что подтвердили — подтягиваем реальное количество
            // (введённое вручную или default_quantity), чтобы фронтенд сразу
            // показал верное число, не дожидаясь перезагрузки страницы.
            $chargeQty = null;
            if ($action === 'complete') {
                // ИЗМЕНЕНО (25.09.2026, раунд 58) — по order_id, не по
                // (flight_id, service_id): см. пояснение у действия 'reset' выше.
                $stmtQ = $db->prepare("SELECT quantity FROM flight_service_charges WHERE order_id = ? LIMIT 1");
                $stmtQ->execute([$orderId]);
                $chargeQty = $stmtQ->fetchColumn();
            }

            $this->respondJson(true, $warning ?? 'Готово.', [
                'order' => [
                    'id'           => (int)$updated['id'],
                    'status'       => $updated['status'],
                    'started_at'   => $updated['started_at'],
                    'completed_at' => $updated['completed_at'],
                    'quantity'     => $chargeQty !== false ? $chargeQty : null,
                ],
                'warning' => $warning,
            ]);
            return;
        }

        if ($warning) { View::setFlash('warning', $warning); }
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    /**
     * ДОБАВЛЕНО (04.08.2026): правка уже зафиксированного времени начала/
     * окончания. Супервайзер не всегда успевает тапнуть ровно в момент
     * события — тут можно скорректировать время постфактум, отдельно от
     * смены статуса.
     */
    /**
     * ДОБАВЛЕНО (04.08.2026): полноценное ручное редактирование — задать
     * время начала/окончания напрямую, независимо от текущего статуса, не
     * проходя через "Начать"→"Завершить". Супервайзер не всегда успевает
     * тапнуть вовремя — иногда узнаёт об услуге и её времени уже постфактум.
     */
    public function manualEdit(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);
        $isAjax = $this->request->header('X-Requested-With') === 'XMLHttpRequest';

        if (!Csrf::verifyRequest($this->request)) {
            $msg = 'Неверный CSRF-токен.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $orderId = (int)$params['id'];
        $order   = $this->model->find($orderId);

        if (!$order) {
            $msg = 'Позиция плана не найдена.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $flightId  = (int)$order['flight_id'];
        $serviceId = (int)$order['service_id'];

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            if ($isAjax) { $this->respondJson(false, $ownershipError); return; }
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            $msg = __('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))]);
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('warning', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $startedInput   = trim((string)$this->request->input('started_at', ''));
        $completedInput = trim((string)$this->request->input('completed_at', ''));
        $timePattern    = '/^([01]\d|2[0-3]):([0-5]\d)$/';

        if ($startedInput === '' && $completedInput === '') {
            $msg = 'Укажите хотя бы время начала.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }
        if ($startedInput !== '' && !preg_match($timePattern, $startedInput)) {
            $msg = 'Некорректное время начала.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }
        if ($completedInput !== '' && !preg_match($timePattern, $completedInput)) {
            $msg = 'Некорректное время окончания.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $baseDate = !empty($order['started_at']) ? substr($order['started_at'], 0, 10) : date('Y-m-d');

        $newStartedAt   = $startedInput   !== '' ? $baseDate . ' ' . $startedInput . ':00'   : $order['started_at'];
        $newCompletedAt = $completedInput !== '' ? $baseDate . ' ' . $completedInput . ':00' : $order['completed_at'];

        if ($newStartedAt && $newCompletedAt && strtotime($newCompletedAt) < strtotime($newStartedAt)) {
            $msg = 'Время окончания не может быть раньше времени начала.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $wasDone = $order['status'] === 'done';
        $newStatus = $order['status'];
        if ($newCompletedAt) {
            $newStatus = 'done';
        } elseif ($newStartedAt && $order['status'] === 'planned') {
            $newStatus = 'in_progress';
        }

        $this->model->update($orderId, [
            'status'       => $newStatus,
            'started_at'   => $newStartedAt,
            'completed_at' => $newCompletedAt,
        ]);

        $warning = null;
        // Если только что впервые дошли до "оказано" через ручное
        // редактирование (а не через кнопку "Завершить") — так же создаём
        // биллинговую запись, с тем же количеством, что и обычным путём.
        if ($newStatus === 'done' && !$wasDone) {
            $manualQty = $this->request->input('quantity', null);
            $manualQty = ($manualQty !== null && $manualQty !== '' && is_numeric($manualQty)) ? (float)$manualQty : null;

            // ДОБАВЛЕНО (29.09.2026, раунд 64): тот же автоматический расчёт
            // часов, что и в обычном "Завершить" (см. action() выше) — этот
            // путь срабатывает, когда супервайзер выставляет время вручную
            // (кнопка "Редактировать") и услуга ТОЛЬКО ЧТО впервые дошла до
            // "оказано" этим способом, а не кнопкой "Завершить".
            if ($newStatus === 'done' && $newStartedAt && $newCompletedAt && $this->isQuantityFromTiming($serviceId)) {
                $manualQty = $this->calcQuantityFromTiming($newStartedAt, $newCompletedAt);
            }

            $chargeResult = $this->confirmServiceCharge($flightId, $serviceId, $manualQty, $orderId);
            if (!$chargeResult['success']) {
                $warning = $chargeResult['error'];
            }
        }

        $this->logFlightAction($flightId, 'supervisor_manual_edit',
            'Ручная правка времени услуги «' . $this->serviceName($serviceId) . '»: начало '
                . ($newStartedAt ?: '—') . ', окончание ' . ($newCompletedAt ?: '—') . '.');

        if ($isAjax) {
            $updated = $this->model->find($orderId);
            $db = \App\Core\Database::getInstance();
            // ИЗМЕНЕНО (25.09.2026, раунд 58) — по order_id, см. пояснение
            // в updateTiming().
            $stmtQ = $db->prepare("SELECT quantity FROM flight_service_charges WHERE order_id = ? LIMIT 1");
            $stmtQ->execute([$orderId]);
            $chargeQty = $stmtQ->fetchColumn();

            $this->respondJson(true, $warning ?? 'Сохранено.', [
                'order' => [
                    'id'           => (int)$updated['id'],
                    'status'       => $updated['status'],
                    'started_at'   => $updated['started_at'],
                    'completed_at' => $updated['completed_at'],
                    'quantity'     => $chargeQty !== false ? $chargeQty : null,
                ],
                'warning' => $warning,
            ]);
            return;
        }

        if ($warning) { View::setFlash('warning', $warning); }
        View::setFlash('success', 'Сохранено.');
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    public function setTime(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);
        $isAjax = $this->request->header('X-Requested-With') === 'XMLHttpRequest';

        if (!Csrf::verifyRequest($this->request)) {
            $msg = 'Неверный CSRF-токен.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $orderId = (int)$params['id'];
        $field   = (string)$this->request->input('field', '');
        $time    = (string)$this->request->input('time', '');
        $order   = $this->model->find($orderId);

        if (!$order || !in_array($field, ['started_at', 'completed_at'], true)) {
            $msg = 'Некорректный запрос.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$order['flight_id'];

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            if ($isAjax) { $this->respondJson(false, $ownershipError); return; }
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            $msg = __('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))]);
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('warning', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            $msg = 'Некорректное время.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        // Дата берётся из уже записанного значения этого же поля, если оно
        // есть, иначе — из второго поля (чтобы день не "уехал" при правке
        // случая около полуночи), иначе — сегодняшняя.
        if (!empty($order[$field])) {
            $baseDate = substr($order[$field], 0, 10);
        } elseif ($field === 'completed_at' && !empty($order['started_at'])) {
            $baseDate = substr($order['started_at'], 0, 10);
        } elseif ($field === 'started_at' && !empty($order['completed_at'])) {
            $baseDate = substr($order['completed_at'], 0, 10);
        } else {
            $baseDate = date('Y-m-d');
        }
        $newDateTime = $baseDate . ' ' . $time . ':00';

        $startedAt   = $field === 'started_at'   ? $newDateTime : ($order['started_at'] ?? null);
        $completedAt = $field === 'completed_at' ? $newDateTime : ($order['completed_at'] ?? null);
        if ($startedAt && $completedAt && strtotime($completedAt) < strtotime($startedAt)) {
            $msg = 'Время завершения не может быть раньше времени начала.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $this->model->update($orderId, [$field => $newDateTime]);

        $fieldLabel = $field === 'started_at' ? 'начала' : 'окончания';
        $this->logFlightAction($flightId, 'supervisor_manual_edit',
            'Ручная правка времени ' . $fieldLabel . ' услуги «' . $this->serviceName((int)$order['service_id']) . '»: ' . $newDateTime . '.');

        if ($isAjax) {
            $this->respondJson(true, 'Время обновлено.', [$field => $newDateTime]);
            return;
        }
        View::setFlash('success', 'Время обновлено.');
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    private function respondJson(bool $success, string $message, array $extra = []): void
    {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    }

    /**
     * ДОБАВЛЕНО (06.08.2026): ответственный в службе (сама служба, не
     * супервайзер) отмечает, что техника/автобус выехали к борту —
     * отдельное событие от "Начать", которое супервайзер жмёт уже У БОРТА.
     * Уведомляет всех активных супервайзеров. Кнопка на экране службы
     * показывается только если у услуги services.requires_dispatch_notice=1
     * (см. Услуги -> Редактировать) — не все позиции требуют этого шага.
     */
    public function dispatch(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'sop', 'spo', 'sst', 'aero', 'spasop']);
        $isAjax = $this->request->header('X-Requested-With') === 'XMLHttpRequest';

        if (!Csrf::verifyRequest($this->request)) {
            $msg = 'Неверный CSRF-токен.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/flights');
            return;
        }

        $orderId = (int)$params['id'];
        $order = $this->model->find($orderId);
        if (!$order) {
            $msg = 'Позиция плана не найдена.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/flights');
            return;
        }

        $flightId = (int)$order['flight_id'];
        $user = Auth::user();
        $role = $user['role_code'] ?? '';

        // Служба может отметить выдвижение только своей же техники —
        // admin/cok могут за любую (на случай, если ответственного нет на месте).
        $service = (new \App\Models\Service())->find((int)$order['service_id']);
        if (!$service) {
            $msg = 'Услуга не найдена.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/flights/' . $flightId);
            return;
        }
        if (!in_array($role, ['admin', 'cok'], true) && $service['owner_role'] !== $role) {
            $msg = 'Эта позиция принадлежит другой службе.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/flights/' . $flightId);
            return;
        }
        if (empty($service['requires_dispatch_notice'])) {
            $msg = 'Для этой услуги отметка о выдвижении не требуется.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/flights/' . $flightId);
            return;
        }

        $updated = $this->model->markDispatched($orderId, (int)Auth::id());
        if (!$updated) {
            $msg = 'Не удалось отметить выдвижение — позиция отменена?';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/flights/' . $flightId);
            return;
        }

        try {
            (new NotificationService())->notifyVehicleDispatched(
                $flightId, $service['name'], $service['owner_role'], (int)Auth::id()
            );
        } catch (\Throwable $e) {
            error_log('ServiceOrderController::dispatch notify error: ' . $e->getMessage());
        }

        $msg = 'Отмечено: техника выехала. Супервайзер уведомлён.';
        if ($isAjax) { $this->respondJson(true, $msg, ['dispatched_at' => $updated['dispatched_at']]); return; }
        View::setFlash('success', $msg);
        $this->redirect('/flights/' . $flightId);
    }

    /**
     * Создаёт биллинговую запись (flight_service_charges) при подтверждении
     * услуги супервайзером — та же защита от дублей и взаимоисключающих
     * групп, что и у ручного ввода службой (ServiceChargeController::add()),
     * только source_role = 'supervisor'. Количество — services.default_quantity
     * (по умолчанию 1) — супервайзер подтверждает факт, не вводит количество
     * вручную. Если для какой-то услуги важно реальное количество (как для
     * топлива — литры/плотность), для неё нужен свой отдельный экран ввода,
     * как уже сделано для FUEL_SVC.
     *
     * ИЗМЕНЕНО (25.09.2026, раунд 58, по прямому указанию пользователя):
     * добавлен $orderId — раньше идемпотентность ("уже есть — не дублируем")
     * проверялась по (flight_id, service_id), что было верно, пока на
     * flight_service_charges стоял UNIQUE(flight_id, service_id) — у услуги
     * могла быть только одна биллинговая запись, и эта проверка заодно
     * защищала от двойного клика "Завершить". Теперь, когда
     * services.allow_repeat=1 разрешает несколько экземпляров одной услуги
     * на рейсе, та же проверка ошибочно приняла бы уже оплаченный ПЕРВЫЙ
     * экземпляр за дубль и молча не создала бы запись для второго. Проверка
     * переведена на order_id — "эта КОНКРЕТНАЯ заявка уже породила запись?" —
     * что даёт ту же защиту от двойного клика, но корректно для каждого
     * экземпляра по отдельности, включая старые (не repeat) услуги.
     */
    /**
     * ДОБАВЛЕНО (29.09.2026, раунд 64): true, если у услуги количество
     * (часы) считается автоматически из started_at/completed_at, а не
     * вводится супервайзером вручную. Флаг настраивается на странице
     * "Услуги" (services.quantity_from_timing), сейчас включён у GPU и
     * AIRCOND. Специально не завязано на код услуги в PHP — новую почасовую
     * услугу с тайминг-флоу можно включить в этот режим просто галочкой в
     * админке, без правки кода.
     */
    private function isQuantityFromTiming(int $serviceId): bool
    {
        $db = \App\Core\Database::getInstance();
        $stmt = $db->prepare("SELECT quantity_from_timing FROM services WHERE id = ? LIMIT 1");
        $stmt->execute([$serviceId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * ДОБАВЛЕНО (29.09.2026, раунд 64, по прямому указанию пользователя).
     * ИЗМЕНЕНО (15.10.2026, раунд 90, по прямому указанию пользователя):
     * шаг округления увеличен с 0.5ч до 1.0ч — количество часов из
     * фактического времени оказания услуги (started_at → completed_at),
     * округлённое ВВЕРХ с шагом 1 час (целый час) — то есть 5 минут = 1ч,
     * 30 минут = 1ч, 61 минута = 2ч, 90 минут = 2ч, 91 минута = 2ч (до
     * 120 минут), 121 минута = 3ч. Минимум — 1 час (даже если завершили
     * мгновенно), т.к. flight_service_charges.quantity должно быть > 0.
     */
    private function calcQuantityFromTiming(string $startedAt, string $completedAt): float
    {
        $seconds = max(0, strtotime($completedAt) - strtotime($startedAt));
        $steps = (int)ceil($seconds / 3600); // шаг 3600 сек = 1 час (round 90)
        if ($steps < 1) {
            $steps = 1;
        }
        return (float)$steps;
    }

    private function confirmServiceCharge(int $flightId, int $serviceId, ?float $manualQty, int $orderId): array
    {
        $db = \App\Core\Database::getInstance();

        $stmt = $db->prepare("SELECT id FROM flight_service_charges WHERE order_id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        if ($stmt->fetchColumn()) {
            return ['success' => true]; // запись по этой заявке уже есть — не дублируем, это не ошибка
        }

        $stmtSvc = $db->prepare(
            "SELECT code, exclusive_group, default_quantity, requires_manual_quantity, name, is_non_billable
             FROM services WHERE id = ? LIMIT 1"
        );
        $stmtSvc->execute([$serviceId]);
        $svc = $stmtSvc->fetch(\PDO::FETCH_ASSOC);

        // ДОБАВЛЕНО (17.08.2026, раунд 9): некоторые услуги подтверждаются
        // тем же тайминг-флоу "Начать/Завершить" (нужно зафиксировать время
        // оказания), но НЕ должны создавать биллинговую запись — например
        // установка/уборка колодок (CHOCKS): обязательная операция на
        // перроне, время которой печатается в шапке акта, но плата за неё
        // не взимается и в сумму акта она не входит. Признак —
        // services.is_non_billable (редактируется на странице услуги, как и
        // requires_tech_personnel/is_blanket). Тайминг при этом уже
        // зафиксирован вызывающим кодом (updateTiming()/manualEdit()
        // обновляют flight_service_orders ДО вызова этого метода) — здесь
        // просто не создаём flight_service_charges и выходим успешно.
        if ($svc && !empty($svc['is_non_billable'])) {
            return ['success' => true];
        }

        // ДОБАВЛЕНО (11.09.2026, раунд 38): PAX_ARR/PAX_DEP/BAG_ARR_PC/
        // BAG_DEP_PC — по прямому указанию пользователя супервайзер больше
        // НЕ считает и не вводит количество пассажиров/багажа, а просто
        // фиксирует факт оказания услуги (тайминг уже записан вызывающим
        // кодом ДО этого метода). Количество для акта подтягивается из
        // пассажирского манифеста при сборке акта — см.
        // AutoChargeService::processFlightMandatoryCharges() /
        // manifestQuantityForCode(). Здесь для этих 4 кодов сознательно НЕ
        // создаём биллинговую запись вообще (даже если фронтенд прислал
        // quantity по какой-то старой ссылке/кэшу — игнорируем её).
        if ($svc && in_array($svc['code'], ['PAX_ARR', 'PAX_DEP', 'BAG_ARR_PC', 'BAG_DEP_PC'], true)) {
            return ['success' => true];
        }

        // ДОБАВЛЕНО (04.08.2026): для услуг с requires_manual_quantity=1
        // (пассажиры, багаж, часы, литры и т.п.) — количество обязано быть
        // введено вручную, дефолт=1 тут был бы просто неверной суммой в акте.
        if ($svc && (int)$svc['requires_manual_quantity'] === 1 && $manualQty === null) {
            return [
                'success' => false,
                'error' => 'Для услуги «' . $svc['name'] . '» нужно ввести фактическое количество — тайминг зафиксирован, но в акт услуга ещё не внесена.',
            ];
        }
        if ($manualQty !== null && $manualQty <= 0) {
            return ['success' => false, 'error' => 'Количество должно быть больше нуля.'];
        }

        if ($svc && !empty($svc['exclusive_group'])) {
            $stmtConflict = $db->prepare(
                "SELECT s2.name FROM flight_service_charges fsc
                 JOIN services s2 ON s2.id = fsc.service_id
                 WHERE fsc.flight_id = ? AND s2.exclusive_group = ? AND s2.id != ?
                 LIMIT 1"
            );
            $stmtConflict->execute([$flightId, $svc['exclusive_group'], $serviceId]);
            $conflictName = $stmtConflict->fetchColumn();
            if ($conflictName) {
                return [
                    'success' => false,
                    'error' => 'Тайминг зафиксирован, но услуга НЕ внесена в акт — на этом рейсе уже оказана взаимоисключающая услуга «' . $conflictName . '». Обратитесь в ЦОК.',
                ];
            }
        }

        $qty = $manualQty ?? ($svc ? (float)$svc['default_quantity'] : 1.0);
        $db->prepare(
            "INSERT INTO flight_service_charges
                (flight_id, service_id, order_id, source_role, quantity, is_auto, created_by_user_id, created_at)
             VALUES (?, ?, ?, 'supervisor', ?, 0, ?, NOW())"
        )->execute([$flightId, $serviceId, $orderId, $qty, Auth::id()]);

        return ['success' => true];
    }

    /**
     * ДОБАВЛЕНО (04.08.2026): правка уже внесённого количества постфактум —
     * та же логика, что и setTime() для времени. Пригодится, если реальное
     * число (например пассажиров) уточнилось уже после подтверждения.
     */
    public function updateQuantity(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);
        $isAjax = $this->request->header('X-Requested-With') === 'XMLHttpRequest';

        if (!Csrf::verifyRequest($this->request)) {
            $msg = 'Неверный CSRF-токен.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $orderId  = (int)$params['id'];
        $quantity = $this->request->input('quantity', '');
        $order    = $this->model->find($orderId);

        if (!$order || !is_numeric($quantity) || (float)$quantity <= 0) {
            $msg = 'Некорректное количество.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$order['flight_id'];

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            if ($isAjax) { $this->respondJson(false, $ownershipError); return; }
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            $msg = __('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))]);
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('warning', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $db = \App\Core\Database::getInstance();
        // ИЗМЕНЕНО (25.09.2026, раунд 58) — по order_id ($orderId уже
        // определён выше), не по (flight_id, service_id): при
        // allow_repeat=1 у услуги на рейсе может быть несколько записей,
        // править нужно именно запись ЭТОЙ позиции плана. См. то же
        // пояснение у updateTiming()/confirmServiceCharge().
        $stmt = $db->prepare(
            "SELECT id, consumed_in_act_id FROM flight_service_charges
             WHERE order_id = ? LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $charge = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$charge) {
            $msg = 'Услуга ещё не подтверждена — сначала завершите её.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }
        if ($charge['consumed_in_act_id']) {
            $msg = 'Услуга уже включена в акт — количество можно поправить только через ЦОК.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $db->prepare("UPDATE flight_service_charges SET quantity = ?, updated_at = NOW() WHERE id = ?")
           ->execute([(float)$quantity, $charge['id']]);

        if ($isAjax) {
            $this->respondJson(true, 'Количество обновлено.', ['quantity' => (float)$quantity]);
            return;
        }
        View::setFlash('success', 'Количество обновлено.');
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    // ── Топливо (FUEL_SVC) — особый случай, не просто тайминг ──────────────

    public function fuelForm(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);
        $orderId = (int)$params['id'];
        $order   = $this->model->find($orderId);

        if (!$order) {
            View::setFlash('error', 'Позиция плана не найдена.');
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$order['flight_id'];

        // ДОБАВЛЕНО (30.10.2026, раунд 141): после round140 (кнопка "Убрать
        // из плана" у топлива) заправка может оказаться в статусе
        // 'cancelled' — форма заправки не проверяла это вообще и позволяла
        // тихо "воскресить" отклонённую дозаправку простым заполнением
        // формы. Карточка больше не даёт сюда перейти (см. фикс в
        // _supervisor_order_card.php/supervisor_flight.php тем же раундом),
        // но прямой переход по старой/сохранённой ссылке — всё ещё
        // возможен, поэтому проверка нужна и на сервере, а не только в UI.
        if ($order['status'] === 'cancelled') {
            View::setFlash('error', 'Эта заправка отменена и убрана из плана — внести по ней данные нельзя. Если заправка всё же нужна, закажите её заново.');
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $flight = $this->flightModel->find($flightId);

        // ИСПРАВЛЕНО (04.08.2026): query() в Model — protected, вызывать его
        // снаружи класса (из контроллера) нельзя, PHP это блокирует на уровне
        // видимости методов. Раньше это падало с фатальной ошибкой при первом
        // же открытии формы топлива. Теперь — обычный PDO, как везде в этом
        // контроллере при работе с сырыми запросами.
        // ИСПРАВЛЕНО (26.10.2026, раунд 126, по прямому указанию пользователя
        // — "почти все услуги можно дозаказать, прошляпили именно
        // дозаправку"): раньше биллинговая запись заправки искалась по
        // (flight_id, service_id) — тот же пробел, что round58 уже закрыл
        // для ВСЕХ остальных allow_repeat-услуг через order_id (см.
        // confirmServiceCharge()/updateQuantity() выше), но топливо тогда
        // не затронули — у него свой отдельный флоу (эта форма), заведённый
        // раньше round58 и не обновлённый вместе с остальными. Из-за этого
        // ВТОРАЯ дозаправка (новая позиция плана, allow_repeat=1 у FUEL_SVC
        // уже стоит в БД) находила и ПЕРЕЗАПИСЫВАЛА ту же самую запись, что
        // и первая — вторая заявка молча замещала первую вместо отдельного
        // начисления. Теперь — строго по order_id этой КОНКРЕТНОЙ позиции
        // плана, как и везде. Старые записи (order_id был NULL) приведены
        // в порядок разовым бэкфиллом (раунд 126, 49 строк, см. анализ).
        $existingDetails = null;
        $db = \App\Core\Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id FROM flight_service_charges WHERE order_id = ? LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $existingCharge = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existingCharge) {
            $existingDetails = (new FlightFuelDetail())->findByChargeId((int)$existingCharge['id']);
        }

        $this->render('service_orders/fuel_form', [
            'title'  => 'Заправка — рейс ' . ($flight['flight_number'] ?? ''),
            'flight' => $flight,
            'order'  => $order,
            'details'=> $existingDetails,
        ]);
    }

    public function saveFuel(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/supervisor');
            return;
        }

        $orderId = (int)$params['id'];
        $order   = $this->model->find($orderId);
        if (!$order) {
            View::setFlash('error', 'Позиция плана не найдена.');
            $this->redirect('/supervisor');
            return;
        }

        $flightId  = (int)$order['flight_id'];
        $serviceId = (int)$order['service_id'];

        if ($ownershipError = $this->supervisorOwnershipError($flightId)) {
            View::setFlash('error', $ownershipError);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        // ДОБАВЛЕНО (30.10.2026, раунд 141): см. тот же фикс и докблок в
        // fuelForm() выше — сохранение тоже должно отказать по отменённой
        // позиции плана, не только показ формы (иначе прямой POST минуя
        // форму всё равно мог бы "воскресить" отклонённую дозаправку).
        if ($order['status'] === 'cancelled') {
            View::setFlash('error', 'Эта заправка отменена и убрана из плана — сохранить по ней данные нельзя. Если заправка всё же нужна, закажите её заново.');
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if ($lockStatus = $this->actModel->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $liters      = (float)$this->request->input('liters', 0);
        $density     = (float)$this->request->input('density', 0);
        $temperature = $this->request->input('temperature', '');
        $fuelGrade   = trim((string)$this->request->input('fuel_grade', ''));

        if ($liters <= 0 || $density <= 0) {
            View::setFlash('error', 'Укажите литры и плотность — оба поля обязательны и больше нуля.');
            $this->redirect('/supervisor/orders/' . $orderId . '/fuel');
            return;
        }
        if ($density < 0.5 || $density > 1.2) {
            View::setFlash('error', 'Плотность выглядит как опечатка (обычно 0.75–0.85 кг/л). Проверьте значение.');
            $this->redirect('/supervisor/orders/' . $orderId . '/fuel');
            return;
        }

        $tonnes = round($liters * $density / 1000, 4);
        $db = \App\Core\Database::getInstance();
        $userId = Auth::id();

        // ИСПРАВЛЕНО (26.10.2026, раунд 126) — см. подробный комментарий в
        // fuelForm() выше: поиск/создание биллинговой записи этой заправки
        // теперь строго по order_id ($orderId уже определён выше, из
        // $params['id']), а не по (flight_id, service_id) — иначе вторая
        // дозаправка (вторая позиция плана по той же услуге) находила и
        // молча перезаписывала запись первой. INSERT теперь явно сохраняет
        // order_id — раньше не сохранял вообще, из-за чего ДАЖЕ первая
        // заправка на рейсе оставалась не привязанной к своей позиции плана.
        $stmt = $db->prepare(
            "SELECT id FROM flight_service_charges WHERE order_id = ? LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $chargeId = $stmt->fetchColumn();

        if ($chargeId) {
            $db->prepare("UPDATE flight_service_charges SET quantity = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$tonnes, $chargeId]);
        } else {
            $db->prepare(
                "INSERT INTO flight_service_charges
                    (flight_id, service_id, order_id, source_role, quantity, is_auto, created_by_user_id, created_at)
                 VALUES (?, ?, ?, 'supervisor', ?, 0, ?, NOW())"
            )->execute([$flightId, $serviceId, $orderId, $tonnes, $userId]);
            $chargeId = (int)$db->lastInsertId();
        }

        $stmtDetails = $db->prepare(
            "SELECT id FROM flight_fuel_details WHERE flight_service_charge_id = ? LIMIT 1"
        );
        $stmtDetails->execute([$chargeId]);
        $detailsId = $stmtDetails->fetchColumn();

        $tempValue = ($temperature !== '' && $temperature !== null) ? (float)$temperature : null;

        if ($detailsId) {
            $db->prepare(
                "UPDATE flight_fuel_details
                 SET fuel_grade = ?, liters = ?, density = ?, temperature = ?, computed_tonnes = ?, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$fuelGrade ?: null, $liters, $density, $tempValue, $tonnes, $detailsId]);
        } else {
            $db->prepare(
                "INSERT INTO flight_fuel_details
                    (flight_service_charge_id, fuel_grade, liters, density, temperature, computed_tonnes, entered_by_user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([$chargeId, $fuelGrade ?: null, $liters, $density, $tempValue, $tonnes, $userId]);
        }

        $this->model->update($orderId, [
            'status'       => 'done',
            'started_at'   => $order['started_at'] ?: date('Y-m-d H:i:s'),
            'completed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logFlightAction($flightId, 'supervisor_fuel',
            'Заправка «' . $this->serviceName($serviceId) . '»: ' . $liters . ' л × ' . $density . ' = ' . $tonnes . ' т.');

        View::setFlash('success', "Заправка сохранена: {$liters} л × {$density} = {$tonnes} т.");
        $this->redirect('/supervisor/flights/' . $flightId);
    }
}
