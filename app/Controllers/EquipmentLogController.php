<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\EquipmentLog;
use App\Models\EquipmentLogItem;
use App\Models\Flight;

class EquipmentLogController extends Controller
{
    private EquipmentLog     $model;
    private EquipmentLogItem $itemModel;
    private Flight           $flightModel;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model       = new EquipmentLog();
        $this->itemModel   = new EquipmentLogItem();
        $this->flightModel = new Flight();
    }

    public function index(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "Спецтехника" отключён для всех, кроме admin — см.
        // Permissions::$matrix, ключ equipment.* и комментарий там же.
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

        $this->render('equipment_logs/index', [
            'title'   => 'Журнал спецтехники',
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'filters' => $filters,
        ]);
    }

    public function form(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "Спецтехника" отключён для всех, кроме admin — см.
        // Permissions::$matrix, ключ equipment.* и комментарий там же.
        $this->requireRole(['admin']);
        $flightId = (int)$this->request->get('flight_id', $params['flight_id'] ?? 0);
        $flight   = $this->flightModel->findWithDetails($flightId);
        if (!$flight) { $this->redirect('/flights'); return; }

        $lockStatus = (new \App\Models\Act())->getLockStatus($flightId);

        // ДОБАВЛЕНО (аудит 10.07.2026): предзаказ ЦОК, ещё не оказанный этой службой
        $pendingOrders = [];
        try {
            $pendingOrders = (new \App\Models\FlightServiceOrder())->getPendingForRole($flightId, 'sst');
        } catch (\Throwable $e) {}

        $log      = $this->model->getByFlight($flightId);
        $logItems = $log ? $this->itemModel->getByLog($log['id']) : [];

        // Стандартные типы спецтехники
        $equipmentTypes = [
            'GPU'             => 'GPU (наземный источник питания)',
            'Трап'            => 'Трап (мобильный)',
            'Телетрап'        => 'Телетрап / Jetway',
            'Буксир'          => 'Буксировщик ВС',
            'Топливозаправщик'=> 'Топливозаправщик',
            'Стартёр'         => 'Стартёр двигателей',
            'Кондиционер'     => 'Установка кондиционирования',
            'Автолифт'        => 'Автолифт для багажа',
            'Водовозка'       => 'Водовозка',
            'Ассенизатор'     => 'Ассенизационная машина',
            'Автобус'         => 'Автобус для пассажиров',
            'Спецтехника'     => 'Прочая спецтехника',
        ];

        // Типы техники из справочника ССТ
        try {
            $eqTypeModel   = new \App\Models\EquipmentType();
            $eqFromDb      = $eqTypeModel->getAllActive();
            if (!empty($eqFromDb)) {
                $equipmentTypes = [];
                foreach ($eqFromDb as $row) {
                    $equipmentTypes[$row['code']] = $row['name'];
                }
            }
        } catch (\Throwable $e) { /* fallback к захардкоженному списку */ }

        $chargeModel = new \App\Models\FlightServiceCharge();
        $tariffModel = new \App\Models\TariffVersion();
        $allCharges  = [];
        $availableServices = [];
        try {
            $allCharges = $chargeModel->getAllByFlight($flightId);
            $availableServices = $tariffModel->getServicesWithActiveTariff(
                $flight['route_type'], $flight['operation_type'], date('Y-m-d'),
                'sst',
                isset($flight['aircraft_mtow']) && $flight['aircraft_mtow'] > 0
                    ? (float)$flight['aircraft_mtow']
                    : (isset($flight['mtow']) && $flight['mtow'] > 0 ? (float)$flight['mtow'] : null)
            );
        } catch (\Throwable $e) { error_log('EquipmentLogController charges: ' . $e->getMessage()); }

        $myCharges = array_values(array_filter($allCharges, fn($c) => $c['source_role'] === 'sst'));

        // Подсказки qty из журнала спецтехники
        // Считаем сколько единиц каждого типа техники использовалось
        $qtyHints = [];
        if ($log && !empty($logItems)) {
            // Маппинг: тип техники → код услуги ССТ
            // ИСПРАВЛЕНО (14.08.2026, раунд 2, п.5): убрана строка
            // 'Топливозаправщик' => 'TECH_STAFF' — TECH_STAFF это НЕ сбор за
            // топливозаправщик, а "Техперсонал спецтехники" (форма А, ячейка
            // "Тех персонал для обслуж. GSE"), и маппинг ошибочно подставлял
            // туда только количество топливозаправщиков. Правильное значение
            // для TECH_STAFF считается отдельно ниже — по ВСЕМ единицам
            // спецтехники в журнале (см. пояснение пользователя: количество
            // тех.персонала должно соответствовать количеству тех.транспорта).
            // Отдельного сбора за сам топливозаправщик в справочнике услуг
            // ССТ нет (заправка ГСМ — услуга FUEL_SVC Аэродромной службы,
            // form_a_slot=am_fuel, не относится к этому журналу) — если для
            // топливозаправщика тоже нужен отдельный сбор, скажите, заведём
            // отдельную услугу.
            $typeToService = [
                'GPU'              => 'GPU',
                'Электропитание'   => 'GPU',
                'Трап'             => 'AIRSTAIRS',
                'Телетрап'         => 'BRIDGE',
                'Буксир'           => 'PUSHBACK',
                'Амбулифт'         => 'AMBU',
                'Автобус'          => 'BUS_PAX',
                'Кресло'           => 'WCHR',
                'Инвалид'          => 'WCHR',
                'Водовозка'        => 'WATER',
                'Ассенизатор'      => 'TOILET',
                'Кондиционер'      => 'AIRCOND',
                'Стартёр'          => 'AIR_START',
                'Кислород'         => 'OXYGEN',
                'Азот'             => 'NITROGEN',
            ];
            // Считаем единицы по типам
            $counts = [];
            foreach ($logItems as $item) {
                $t = $item['equipment_type'] ?? '';
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            }
            foreach ($counts as $type => $count) {
                foreach ($typeToService as $typeKey => $svcCode) {
                    if (stripos($type, $typeKey) !== false) {
                        $qtyHints[$svcCode] = ['qty' => $count, 'hint' => "{$type}: {$count} ед."];
                        break;
                    }
                }
            }

            // ДОБАВЛЕНО (14.08.2026, раунд 2, п.5): TECH_STAFF — количество
            // тех.персонала = общее количество единиц спецтехники, поданных
            // по рейсу (все строки журнала спецтехники), 1 человек на 1
            // единицу техники, по указанию пользователя. Считается ПОСЛЕ
            // маппинга выше и не зависит от него — берёт ВСЕ типы техники,
            // а не только те, что попали в $typeToService.
            $qtyHints['TECH_STAFF'] = [
                'qty'  => count($logItems),
                'hint' => 'по журналу спецтехники: ' . count($logItems) . ' ед. техники',
            ];

            // DEICE и прочие ССТ-услуги без прямой техники — qty=1 по умолчанию
            foreach ([
                'DEICE_T1','DEICE_T2','DEICE_T4',
                'DEICE_20','DEICE_100','DEICE_200','DEICE_201',
                'FIRSTAID','CATERING_TRUCK','AMBULANCE','OXYGEN','NITROGEN',
                'AIRSTAIRS',
            ] as $code) {
                if (!isset($qtyHints[$code])) {
                    $qtyHints[$code] = ['qty' => 1, 'hint' => 'введите вручную'];
                }
            }
        }

        $this->render('equipment_logs/form', [
            'title'             => 'Журнал спецтехники',
            'flight'            => $flight,
            'log'               => $log,
            'logItems'          => $logItems,
            'equipmentTypes'    => $equipmentTypes,
            'myCharges'         => $myCharges,
            'availableServices' => $availableServices,
            'sourceRole'        => 'sst',
            'qtyHints'          => $qtyHints,
            'lockStatus'        => $lockStatus,
            'pendingOrders'     => $pendingOrders,
        ]);
    }

    public function save(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "Спецтехника" отключён для всех, кроме admin — см.
        // Permissions::$matrix, ключ equipment.* и комментарий там же.
        $this->requireRole(['admin']);
        $flightId = (int)$this->request->input('flight_id');

        if ($lockStatus = (new \App\Models\Act())->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/equipment-logs/flight/' . $flightId);
            return;
        }

        $userId   = Auth::id();
        $action   = $this->request->input('action');

        $existing = $this->model->getByFlight($flightId);

        $logData = [
            'flight_id' => $flightId,
            'status'    => $action === 'submit' ? 'submitted' : 'draft',
            'notes'     => $this->request->input('notes') ?: null,
        ];
        if ($logData['status'] === 'submitted') {
            $logData['submitted_at'] = date('Y-m-d H:i:s');
        }

        if ($existing) {
            $this->model->update($existing['id'], $logData);
            $logId = $existing['id'];
            // Удаляем старые строки и перезаписываем
            $db = \App\Core\Database::getInstance();
            $db->prepare("DELETE FROM equipment_log_items WHERE log_id = ?")->execute([$logId]);
        } else {
            $logData['created_by_user_id'] = $userId;
            $logId = (int)$this->model->insert($logData);
        }

        // Сохраняем строки техники
        $types    = $this->request->input('eq_type')    ?? [];
        $numbers  = $this->request->input('eq_number')  ?? [];
        $operators= $this->request->input('eq_operator')?? [];
        $arrivals = $this->request->input('eq_arrival') ?? [];
        $departures=$this->request->input('eq_departure')?? [];
        $dones    = $this->request->input('eq_done')    ?? [];
        $notes    = $this->request->input('eq_notes')   ?? [];

        foreach ($types as $i => $type) {
            if (empty(trim($type))) continue;
            $this->itemModel->insert([
                'log_id'           => $logId,
                'equipment_type'   => trim($type),
                'equipment_number' => $numbers[$i] ?? null ?: null,
                'operator_name'    => $operators[$i] ?? null ?: null,
                'arrival_time'     => $arrivals[$i] ?? null ?: null,
                'departure_time'   => $departures[$i] ?? null ?: null,
                'operation_done'   => isset($dones[$i]) ? 1 : 0,
                'notes'            => $notes[$i] ?? null ?: null,
                'sort_order'       => $i,
            ]);
        }

        View::setFlash('success', 'Журнал спецтехники сохранён.');
        $this->redirect("/flights/{$flightId}");
    }
}
