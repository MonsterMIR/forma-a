<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\ParkingAct;
use App\Models\Flight;
use App\Models\ParkingStand;
use App\Services\ParkingStandService;

class ParkingActController extends Controller
{
    private ParkingAct $model;
    private Flight     $flightModel;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model       = new ParkingAct();
        $this->flightModel = new Flight();
    }

    public function index(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "Стоянки" (Акт готовности стоянки ВС) отключён для всех,
        // кроме admin — см. Permissions::$matrix, ключ parking.* и
        // комментарий там же. Форма и так с раунда 44 больше не используется
        // для расчёта OVER_PARK.
        $this->requireRole(['admin']);
        $perPage = 20;
        $page    = max(1, (int)$this->request->get('page', 1));
        $filters = [
            'status'    => $this->request->get('status', ''),
            'date_from' => $this->request->get('date_from', date('Y-m-01')),
            'date_to'   => $this->request->get('date_to', date('Y-m-d')),
        ];
        $items = $this->model->getFiltered($filters, $perPage, ($page-1)*$perPage);
        $total = $this->model->countFiltered($filters);

        // ДОБАВЛЕНО (18.09.2026, раунд 48, по прямому указанию пользователя):
        // "https://formb.tvtairport.uz/parking-acts должен отражать
        // актуальную информацию о состоянии стоянок." — сводка занята/
        // свободна по каждой стоянке справочника, см. ParkingStandService::
        // getStatusBoard(). Отдельно от списка актов ниже (акты — история
        // подачи чек-листов, сводка — текущее состояние прямо сейчас).
        $standBoard = [];
        try {
            $standBoard = (new \App\Services\ParkingStandService())->getStatusBoard();
        } catch (\Throwable $e) {
            error_log('ParkingActController::index getStatusBoard error: ' . $e->getMessage());
        }

        $this->render('parking_acts/index', [
            'title'      => 'Акты готовности стоянки',
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'filters'    => $filters,
            'standBoard' => $standBoard,
        ]);
    }

    public function form(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "Стоянки" (Акт готовности стоянки ВС) отключён для всех,
        // кроме admin — см. Permissions::$matrix, ключ parking.* и
        // комментарий там же. Форма и так с раунда 44 больше не используется
        // для расчёта OVER_PARK.
        $this->requireRole(['admin']);
        $flightId = (int)$this->request->get('flight_id', $params['flight_id'] ?? 0);
        $flight   = $this->flightModel->findWithDetails($flightId);
        if (!$flight) { $this->redirect('/flights'); return; }

        $lockStatus = (new \App\Models\Act())->getLockStatus($flightId);

        // ДОБАВЛЕНО (аудит 10.07.2026): предзаказ ЦОК, ещё не оказанный этой службой
        $pendingOrders = [];
        try {
            $pendingOrders = (new \App\Models\FlightServiceOrder())->getPendingForRole($flightId, 'aero');
        } catch (\Throwable $e) {}

        $act = $this->model->getByFlight($flightId);

        // Услуги Аэродромной службы
        $chargeModel = new \App\Models\FlightServiceCharge();
        $tariffModel = new \App\Models\TariffVersion();
        $allCharges  = [];
        $availableServices = [];
        try {
            $allCharges = $chargeModel->getAllByFlight($flightId);
            $availableServices = $tariffModel->getServicesWithActiveTariff(
                $flight['route_type'], $flight['operation_type'], date('Y-m-d'),
                'aero',
                isset($flight['aircraft_mtow']) && $flight['aircraft_mtow'] > 0
                    ? (float)$flight['aircraft_mtow']
                    : (isset($flight['mtow']) && $flight['mtow'] > 0 ? (float)$flight['mtow'] : null)
            );
        } catch (\Throwable $e) { error_log('ParkingActController charges: ' . $e->getMessage()); }

        $myCharges = array_values(array_filter($allCharges, fn($c) => $c['source_role'] === 'aero'));

        // Подсказки qty из акта стоянки
        $qtyHints = [];
        if ($act) {
            // Охрана ВС: часы = (departed_at - arrived_at)
            if (!empty($act['aircraft_arrived_at']) && !empty($act['aircraft_departed_at'])) {
                $diff = (strtotime($act['aircraft_departed_at']) - strtotime($act['aircraft_arrived_at'])) / 3600;
                $hours = max(1, round($diff, 2));
                $qtyHints['AC_GUARD'] = ['qty' => $hours, 'hint' => "Часов стоянки: {$hours}"];
            }
            // SITA и прочее — qty=1
            $qtyHints['SITA'] = ['qty' => 1, 'hint' => '1 отправка'];
        }

        // ДОБАВЛЕНО (18.08.2026, раунд 17): справочник стоянок для выпадающего
        // списка "Номер стоянки" — раньше это было свободное текстовое поле,
        // не связанное со справочником parking_stands (раунд 16).
        // ИЗМЕНЕНО (18.09.2026, раунд 48, по прямому указанию пользователя):
        // занятые чужими бортами стоянки вообще не предлагаются в списке —
        // см. ParkingStandService::getAvailableStands().
        $parkingStands = [];
        try {
            $parkingStands = (new ParkingStandService())->getAvailableStands(
                (new ParkingStand())->getAllActive(),
                ['aircraft_registration' => $flight['aircraft_registration'] ?? null],
                $flightId
            );
        } catch (\Throwable $e) {}

        $this->render('parking_acts/form', [
            'title'             => 'Акт готовности стоянки ВС',
            'flight'            => $flight,
            'act'               => $act,
            'myCharges'         => $myCharges,
            'availableServices' => $availableServices,
            'sourceRole'        => 'aero',
            'qtyHints'          => $qtyHints,
            'lockStatus'        => $lockStatus,
            'pendingOrders'     => $pendingOrders,
            'parkingStands'     => $parkingStands,
        ]);
    }

    public function save(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "Стоянки" (Акт готовности стоянки ВС) отключён для всех,
        // кроме admin — см. Permissions::$matrix, ключ parking.* и
        // комментарий там же. Форма и так с раунда 44 больше не используется
        // для расчёта OVER_PARK.
        $this->requireRole(['admin']);
        $flightId = (int)$this->request->input('flight_id');

        if ($lockStatus = (new \App\Models\Act())->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/parking-acts/flight/' . $flightId);
            return;
        }

        $userId   = Auth::id();
        $action   = $this->request->input('action');

        $data = [
            'flight_id'            => $flightId,
            'status'               => $action === 'submit' ? 'submitted' : 'draft',
            'stand_number'         => $this->request->input('stand_number') ?: null,
            'checked_at'           => $this->request->input('checked_at') ?: null,
            'aircraft_arrived_at'  => $this->request->input('aircraft_arrived_at') ?: null,
            'aircraft_departed_at' => $this->request->input('aircraft_departed_at') ?: null,
            'runway_clear'         => (int)(bool)$this->request->input('runway_clear'),
            'lighting_ok'          => (int)(bool)$this->request->input('lighting_ok'),
            'markings_ok'          => (int)(bool)$this->request->input('markings_ok'),
            'fod_clear'            => (int)(bool)$this->request->input('fod_clear'),
            'jetway_ok'            => (int)(bool)$this->request->input('jetway_ok'),
            'remarks'              => $this->request->input('remarks') ?: null,
            'inspector_name'       => $this->request->input('inspector_name') ?: null,
        ];

        if ($data['status'] === 'submitted') {
            $data['submitted_at'] = date('Y-m-d H:i:s');
        }

        $existing = $this->model->getByFlight($flightId);
        if ($existing) {
            $this->model->update($existing['id'], $data);
        } else {
            $data['created_by_user_id'] = $userId;
            $this->model->insert($data);
        }

        // ДОБАВЛЕНО (18.08.2026, раунд 17): синхронизация в flights.mc_number —
        // единственное поле, которое реально печатается в акте формы А (см.
        // resources/views/acts/print.php, строка "MC№"). Аэродромная служба
        // здесь часто первой достоверно знает, куда поставили борт (это их
        // акт готовности стоянки), но раньше их ввод никуда, кроме этой формы,
        // не попадал. Синхронизируем только когда стоянка реально указана —
        // пустое значение (например, при раннем черновике) не должно затирать
        // номер, уже введённый ЦОК или супервайзером.
        // ИЗМЕНЕНО (18.09.2026, раунд 48, по прямому указанию пользователя):
        // "Стоянка после того как на него поставили ВС она не доступна для
        // других ВС пока не будет освобождена" — см. ParkingStandService.
        // Если указанная здесь стоянка уже занята ДРУГИМ бортом — не
        // затираем flights.mc_number этим значением (сам акт готовности
        // стоянки при этом всё равно сохраняется, это отдельный чек-лист
        // Аэродромной службы), а предупреждаем при сохранении ниже.
        $standConflictMsg = null;
        if (!empty($data['stand_number'])) {
            $standService = new ParkingStandService();
            $conflict = $standService->findConflict(
                ['mc_number' => $data['stand_number'], 'aircraft_registration' => $this->flightModel->find($flightId)['aircraft_registration'] ?? null],
                $flightId
            );
            if ($conflict) {
                $standConflictMsg = $standService->describeConflict($conflict)
                    . ' Номер стоянки НЕ сохранён в карточке рейса — проверьте вручную.';
            } else {
                $this->flightModel->update($flightId, ['mc_number' => $data['stand_number']]);
            }
        }

        // При подаче акта — запускаем автоформульный расчёт TLF/AV_SEC/NIGHT/OVER_PARK
        //
        // ИЗМЕНЕНО (17.09.2026, раунд 44, по прямому указанию пользователя):
        // processParkingAct() больше не берёт время стоянки из этой формы
        // (aircraft_arrived_at/aircraft_departed_at) — начало/конец стоянки
        // теперь определяются по времени установки/уборки колодок (услуга
        // CHOCKS у супервайзера либо ручные поля рейса), а не по данным
        // Аэродромной службы отсюда. Эта форма («Акт готовности стоянки ВС»)
        // сама по себе не убрана (см. README раунда 42/44) — просто больше не
        // единственный источник данных для OVER_PARK, метод больше не
        // принимает параметров.
        if ($data['status'] === 'submitted') {
            try {
                $autoCharge = new \App\Services\AutoChargeService();
                $warnings   = $autoCharge->processParkingAct($flightId);
                if (!empty($warnings)) {
                    View::setFlash('warning', 'Акт сохранён. Внимание: ' . implode(' ', $warnings) . ($standConflictMsg ? ' ' . $standConflictMsg : ''));
                } elseif ($standConflictMsg) {
                    View::setFlash('warning', 'Акт стоянки сохранён. Сборы рассчитаны автоматически. ' . $standConflictMsg);
                } else {
                    View::setFlash('success', 'Акт стоянки сохранён. Сборы (TLF, авиабезопасность и др.) рассчитаны автоматически.');
                }
            } catch (\Throwable $e) {
                error_log('AutoChargeService error: ' . $e->getMessage());
                View::setFlash('warning', 'Акт сохранён, но автоматический расчёт сборов не выполнен: ' . $e->getMessage() . ($standConflictMsg ? ' ' . $standConflictMsg : ''));
            }
        } elseif ($standConflictMsg) {
            View::setFlash('warning', 'Акт готовности стоянки сохранён (черновик). ' . $standConflictMsg);
        } else {
            View::setFlash('success', 'Акт готовности стоянки сохранён (черновик).');
        }

        $this->redirect("/flights/{$flightId}");
    }
}
