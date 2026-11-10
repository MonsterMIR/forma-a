<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\PassengerManifest;
use App\Models\Flight;

class ManifestController extends Controller
{
    private PassengerManifest $model;
    private Flight            $flightModel;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model       = new PassengerManifest();
        $this->flightModel = new Flight();
    }

    // Список манифестов
    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'sop', 'commercial', 'finance']);
        $perPage = 20;
        $page    = max(1, (int)$this->request->get('page', 1));
        $filters = [
            'status'        => $this->request->get('status', ''),
            'manifest_type' => $this->request->get('manifest_type', ''),
            'date_from'     => $this->request->get('date_from', date('Y-m-01')),
            'date_to'       => $this->request->get('date_to', date('Y-m-d')),
        ];
        $items = $this->model->getFiltered($filters, $perPage, ($page-1)*$perPage);
        $total = $this->model->countFiltered($filters);

        $this->render('manifests/index', [
            'title'   => 'Манифесты',
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'filters' => $filters,
        ]);
    }

    // Форма (GET)
    // УПРОЩЕНО (21.09.2026, раунд 49, п.2, по прямому указанию пользователя):
    // "убрать первичный манифест, оставить только окончательный, переименовать
    // её как просто манифест" — больше нет выбора типа, всегда один манифест
    // на рейс (в БД по-прежнему хранится как manifest_type='final' —
    // переименовывать колонку смысла нет, это просто внутреннее имя).
    public function form(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'sop']);
        $flightId = (int)$this->request->get('flight_id', $params['flight_id'] ?? 0);
        $type     = 'final';

        $flight = $this->flightModel->findWithDetails($flightId);
        if (!$flight) { $this->redirect('/flights'); return; }

        // ДОБАВЛЕНО (аудит 09.07.2026, п.1): статус акта — если рейс заблокирован,
        // форма всё равно показывается (для просмотра), но без возможности править.
        $lockStatus = (new \App\Models\Act())->getLockStatus($flightId);

        // ДОБАВЛЕНО (аудит 10.07.2026): предзаказ ЦОК, ещё не оказанный этой службой
        $pendingOrders = [];
        try {
            $pendingOrders = (new \App\Models\FlightServiceOrder())->getPendingForRole($flightId, 'sop');
        } catch (\Throwable $e) {}

        $manifest = $this->model->getByFlightAndType($flightId, $type);

        // Оказанные услуги СОП + доступные для выбора
        $chargeModel = new \App\Models\FlightServiceCharge();
        $tariffModel = new \App\Models\TariffVersion();
        $allCharges  = [];
        $availableServices = [];
        try {
            $allCharges = $chargeModel->getAllByFlight($flightId);
            $availableServices = $tariffModel->getServicesWithActiveTariff(
                $flight['route_type'], $flight['operation_type'], date('Y-m-d'),
                'sop',
                isset($flight['aircraft_mtow']) && $flight['aircraft_mtow'] > 0
                    ? (float)$flight['aircraft_mtow']
                    : (isset($flight['mtow']) && $flight['mtow'] > 0 ? (float)$flight['mtow'] : null)
            );
        } catch (\Throwable $e) { error_log('ManifestController charges: ' . $e->getMessage()); }

        $myCharges = array_values(array_filter($allCharges, fn($c) => $c['source_role'] === 'sop'));

        // Подсказки qty из манифеста для автоподстановки
        $qtyHints = [];
        if ($manifest) {
            $paxTotal = (int)$manifest['pax_adults']
                      + (int)$manifest['pax_children']
                      + (int)$manifest['pax_infants'];
            $bagPieces = (int)($manifest['baggage_pieces'] ?? 0);
            // PAX-услуги
            foreach (['PAX_ARR','PAX_DEP','PAX_SEC','CUPPS','DCS_AP','DCS_VPN'] as $code) {
                $qtyHints[$code] = ['qty' => $paxTotal, 'hint' => "PAX из манифеста: {$paxTotal}"];
            }
            // ДОБАВЛЕНО (27.10.2026, раунд 130): пассажиры кортежа — отдельный
            // счётчик (pax_cortege), не входит в $paxTotal выше (который
            // остаётся базой для обычных PAX_ARR/PAX_DEP/PAX_SEC и т.п.).
            $paxCortege = (int)($manifest['pax_cortege'] ?? 0);
            foreach (['PAX_CORTEGE_ARR','PAX_CORTEGE_DEP'] as $code) {
                $qtyHints[$code] = ['qty' => $paxCortege, 'hint' => "Кортеж из манифеста: {$paxCortege}"];
            }
            // Багаж
            foreach (['BAG_ARR_PC','BAG_DEP_PC'] as $code) {
                $qtyHints[$code] = ['qty' => $bagPieces, 'hint' => "Мест багажа из манифеста: {$bagPieces}"];
            }
        }

        $this->render('manifests/form', [
            'title'             => 'Манифест',
            'flight'            => $flight,
            'manifest'          => $manifest,
            'type'              => $type,
            'myCharges'         => $myCharges,
            'availableServices' => $availableServices,
            'sourceRole'        => 'sop',
            'qtyHints'          => $qtyHints,
            'lockStatus'        => $lockStatus,
            'pendingOrders'     => $pendingOrders,
        ]);
    }

    // Сохранение (POST)
    public function save(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'sop']);
        $flightId = (int)$this->request->input('flight_id');

        // ДОБАВЛЕНО (аудит 09.07.2026, п.1): жёсткая серверная блокировка записи.
        if ($lockStatus = (new \App\Models\Act())->getLockStatus($flightId)) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/manifests/flight/' . $flightId);
            return;
        }

        // ДОБАВЛЕНО (12.10.2026, раунд 67, по прямому указанию пользователя):
        // ранее поданный (status='submitted') манифест можно было молча
        // перезаписать новыми цифрами в любой момент — вплоть до того, что
        // уже посчитанные от старых цифр начисления (PAX_DEP/PAX_SEC/BAG_*)
        // никак не узнавали об изменении и оставались "зависшими" со старым
        // значением (см. находку по рейсу RSJ7165 #42 в
        // claude/analysis-2026-10-12-round67-...md). Раз манифест подан —
        // он становится официальным документом рейса и больше не
        // редактируется (та же философия, что и у акта — см.
        // Act::getLockStatus() выше: approved/cancelled/exported акт тоже
        // нельзя отредактировать назад). Если после подачи реально нужно
        // исправить данные — это отдельное решение ЦОК, а не тихая
        // переподача формы.
        $existingForLock = $this->model->getByFlightAndType($flightId, 'final');
        if ($existingForLock && $existingForLock['status'] === 'submitted') {
            View::setFlash('warning', __('MANIFEST_ALREADY_SUBMITTED', [
                ':at' => $existingForLock['submitted_at'] ?? '',
            ]));
            $this->redirect('/manifests/flight/' . $flightId);
            return;
        }

        // УПРОЩЕНО (21.09.2026, раунд 49, п.2): единственный тип манифеста —
        // 'final' (в БД имя колонки/значения не менялось, см. докблок form()).
        $type = 'final';

        $userId = Auth::id();
        $action = $this->request->input('action');

        $data = [
            'flight_id'         => $flightId,
            'manifest_type'     => $type,
            'status'            => $action === 'submit' ? 'submitted' : 'draft',
            'pax_adults'        => (int)$this->request->input('pax_adults', 0),
            'pax_children'      => (int)$this->request->input('pax_children', 0),
            'pax_infants'       => (int)$this->request->input('pax_infants', 0),
            'pax_transit'       => (int)$this->request->input('pax_transit', 0),
            'pax_crew'          => (int)$this->request->input('pax_crew', 0),
            // ДОБАВЛЕНО (27.10.2026, раунд 130, по прямому указанию
            // пользователя): пассажиры кортежа учитываются отдельно от
            // обычных пассажиров — см. AutoChargeService::MANIFEST_DRIVEN_CODES
            // (PAX_CORTEGE_ARR/PAX_CORTEGE_DEP) и докблок passenger_manifests.pax_cortege.
            'pax_cortege'       => (int)$this->request->input('pax_cortege', 0),
            'baggage_pieces'    => (int)$this->request->input('baggage_pieces', 0),
            'baggage_weight_kg' => (float)str_replace(',', '.', $this->request->input('baggage_weight_kg', 0)),
            'carry_on_pieces'   => (int)$this->request->input('carry_on_pieces', 0),
            'cargo_weight_kg'   => (float)str_replace(',', '.', $this->request->input('cargo_weight_kg', 0)),
            'mail_weight_kg'    => (float)str_replace(',', '.', $this->request->input('mail_weight_kg', 0)),
            'notes'             => $this->request->input('notes') ?: null,
        ];

        if ($data['status'] === 'submitted') {
            $data['submitted_at'] = date('Y-m-d H:i:s');
        }

        $existing = $this->model->getByFlightAndType($flightId, $type);
        if ($existing) {
            $this->model->update($existing['id'], $data);
        } else {
            $data['created_by_user_id'] = $userId;
            $this->model->insert($data);
        }

        View::setFlash('success', 'Манифест сохранён.');
        $this->redirect("/flights/{$flightId}");
    }
}
