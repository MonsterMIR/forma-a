<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\TechCard;
use App\Models\Flight;
use App\Core\View;

class TechCardController extends Controller
{
    private TechCard $model;
    private Flight   $flightModel;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model       = new TechCard();
        $this->flightModel = new Flight();
    }

    // Список техкарт
    public function index(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "Техкарты" отключён для всех, кроме admin — см.
        // Permissions::$matrix, ключ techcards.* и комментарий там же.
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

        $this->render('tech_cards/index', [
            'title'   => 'Технологические карты',
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'filters' => $filters,
        ]);
    }

    // Форма создания/редактирования (GET)
    public function form(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "Техкарты" отключён для всех, кроме admin — см.
        // Permissions::$matrix, ключ techcards.* и комментарий там же.
        $this->requireRole(['admin']);
        $flightId = (int)$this->request->get('flight_id', $params['flight_id'] ?? 0);
        $flight   = $this->flightModel->findWithDetails($flightId);
        if (!$flight) { $this->redirect('/flights'); return; }

        $lockStatus = (new \App\Models\Act())->getLockStatus($flightId);

        // ДОБАВЛЕНО (аудит 10.07.2026): предзаказ ЦОК, ещё не оказанный этой службой
        $pendingOrders = [];
        try {
            $pendingOrders = (new \App\Models\FlightServiceOrder())->getPendingForRole($flightId, 'spo');
        } catch (\Throwable $e) {}

        $card = $this->model->getByFlight($flightId);

        $chargeModel = new \App\Models\FlightServiceCharge();
        $tariffModel = new \App\Models\TariffVersion();
        $allCharges  = [];
        $availableServices = [];
        try {
            $allCharges = $chargeModel->getAllByFlight($flightId);
            $availableServices = $tariffModel->getServicesWithActiveTariff(
                $flight['route_type'], $flight['operation_type'], date('Y-m-d'),
                'spo',
                isset($flight['aircraft_mtow']) && $flight['aircraft_mtow'] > 0
                    ? (float)$flight['aircraft_mtow']
                    : (isset($flight['mtow']) && $flight['mtow'] > 0 ? (float)$flight['mtow'] : null)
            );
        } catch (\Throwable $e) { error_log('TechCardController charges: ' . $e->getMessage()); }

        $myCharges = array_values(array_filter($allCharges, fn($c) => $c['source_role'] === 'spo'));

        // Подсказки qty из техкарты (почти все = 1, но подсказываем факты)
        $qtyHints = [];
        if ($card) {
            if (!empty($card['stairs_type']) && $card['stairs_type'] !== 'none') {
                $qtyHints['BRIDGE']   = ['qty' => 1, 'hint' => 'Трап подан'];
                $qtyHints['AIRSTAIRS']= ['qty' => 1, 'hint' => 'Трап подан'];
            }
            if (!empty($card['cleaning_done']) && $card['cleaning_done']) {
                $qtyHints['CLEAN_FULL'] = ['qty' => 1, 'hint' => 'Уборка выполнена'];
                $qtyHints['CLEAN_PART'] = ['qty' => 1, 'hint' => 'Уборка выполнена'];
            }
            if (!empty($card['water_done']) && $card['water_done']) {
                $qtyHints['WATER'] = ['qty' => 1, 'hint' => 'Водообеспечение выполнено'];
            }
            if (!empty($card['toilet_done']) && $card['toilet_done']) {
                $qtyHints['TOILET'] = ['qty' => 1, 'hint' => 'Обслуживание туалетов'];
            }
            if (!empty($card['baggage_count'])) {
                $qtyHints['NOSHOW'] = ['qty' => (int)$card['baggage_count'], 'hint' => 'Мест багажа: ' . $card['baggage_count']];
            }
            // RAMP-хэндлинг — всегда 1 рейс (тариф выбирается по MTOW)
            foreach ([
                'RAMP_10','RAMP_25','RAMP_35','RAMP_50',
                'RAMP_70','RAMP_100','RAMP_150','RAMP_190','RAMP_230','RAMP_250','RAMP_251',
                'PUSHBACK','BRIDGE','AIRSTAIRS',
                'BUS_PAX','BUS_CREW',
                'LOAD_CTL','WB_CTL','WB_CALC','BPI','FLYPLAN',
                'CLEAN_FULL','CLEAN_PART','CLEAN_DIRT','CLEAN_GAL',
                'CLEAN_TOIL','CLEAN_VAC','CLEAN_CREW','CLEAN_WASTE','CLEAN_HEAD',
                'WATER','TOILET',
            ] as $code) {
                if (!isset($qtyHints[$code])) {
                    $qtyHints[$code] = ['qty' => 1, 'hint' => '1 рейс'];
                }
            }
        }

        $this->render('tech_cards/form', [
            'title'             => $card ? 'Технологическая карта' : 'Новая технологическая карта',
            'flight'            => $flight,
            'card'              => $card,
            'myCharges'         => $myCharges,
            'availableServices' => $availableServices,
            'sourceRole'        => 'spo',
            'qtyHints'          => $qtyHints,
            'lockStatus'        => $lockStatus,
            'pendingOrders'     => $pendingOrders,
        ]);
    }

    // Сохранение (POST)
    public function save(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // раздел "Техкарты" отключён для всех, кроме admin — см.
        // Permissions::$matrix, ключ techcards.* и комментарий там же.
        $this->requireRole(['admin']);
        $flightId = (int)$this->request->input('flight_id');

        if ($lockStatus = (new \App\Models\Act())->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/tech-cards/flight/' . $flightId);
            return;
        }

        $userId   = Auth::id();

        $data = [
            'flight_id'          => $flightId,
            'aircraft_stand'     => $this->request->input('aircraft_stand') ?: null,
            'chocks_on_time'     => $this->request->input('chocks_on_time') ?: null,
            'chocks_off_time'    => $this->request->input('chocks_off_time') ?: null,
            'stairs_type'        => $this->request->input('stairs_type') ?: null,
            'stairs_attach_time' => $this->request->input('stairs_attach_time') ?: null,
            'stairs_remove_time' => $this->request->input('stairs_remove_time') ?: null,
            'cleaning_done'      => (int)(bool)$this->request->input('cleaning_done'),
            'cleaning_start'     => $this->request->input('cleaning_start') ?: null,
            'cleaning_end'       => $this->request->input('cleaning_end') ?: null,
            'water_done'         => (int)(bool)$this->request->input('water_done'),
            'toilet_done'        => (int)(bool)$this->request->input('toilet_done'),
            'safety_check_done'  => (int)(bool)$this->request->input('safety_check_done'),
            'safety_officer'     => $this->request->input('safety_officer') ?: null,
            'loading_start'      => $this->request->input('loading_start') ?: null,
            'loading_end'        => $this->request->input('loading_end') ?: null,
            'baggage_count'      => $this->request->input('baggage_count') !== '' ? (int)$this->request->input('baggage_count') : null,
            'cargo_weight_kg'    => $this->request->input('cargo_weight_kg') !== '' ? (float)$this->request->input('cargo_weight_kg') : null,
            'crew_signature'     => $this->request->input('crew_signature') ?: null,
            'handler_signature'  => $this->request->input('handler_signature') ?: null,
            'has_incidents'      => (int)(bool)$this->request->input('has_incidents'),
            'incidents_notes'    => $this->request->input('incidents_notes') ?: null,
            'notes'              => $this->request->input('notes') ?: null,
            'status'             => $this->request->input('action') === 'submit' ? 'submitted' : 'draft',
        ];

        $existing = $this->model->getByFlight($flightId);

        if ($existing) {
            if ($data['status'] === 'submitted') {
                $data['submitted_at'] = date('Y-m-d H:i:s');
            }
            $this->model->update($existing['id'], $data);
        } else {
            $data['created_by_user_id'] = $userId;
            if ($data['status'] === 'submitted') {
                $data['submitted_at'] = date('Y-m-d H:i:s');
            }
            $this->model->insert($data);
        }

        View::setFlash('success', 'Технологическая карта сохранена.');
        $this->redirect("/flights/{$flightId}");
    }
}
