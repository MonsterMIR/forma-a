<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Validator;
use App\Models\Act;
use App\Models\ActItem;
use App\Models\ActStatusHistory;
use App\Models\ActFile;
use App\Models\Flight;
use App\Models\FlightServiceOrder;
use App\Models\Currency;
use App\Models\Service;
use App\Models\TariffVersion;
use App\Services\ActService;
use App\Services\FileService;

class ActController extends Controller
{
    private ActService       $actService;
    private FileService      $fileService;
    private Act              $actModel;
    private ActItem          $actItemModel;
    private ActStatusHistory $historyModel;
    private ActFile          $actFileModel;
    private Flight           $flightModel;
    private FlightServiceOrder $serviceOrderModel;
    private Currency         $currencyModel;
    private Service          $serviceModel;
    private TariffVersion    $tariffModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->actService       = new ActService();
        $this->fileService      = new FileService();
        $this->actModel         = new Act();
        $this->actItemModel     = new ActItem();
        $this->historyModel     = new ActStatusHistory();
        $this->actFileModel     = new ActFile();
        $this->flightModel      = new Flight();
        $this->serviceOrderModel = new FlightServiceOrder();
        $this->currencyModel    = new Currency();
        $this->serviceModel     = new Service();
        $this->tariffModel      = new TariffVersion();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);

        $filters = [
            'search'         => $this->request->get('search', ''),
            'status'         => $this->request->get('status', ''),
            'route_type'     => $this->request->get('route_type', ''),
            'operation_type' => $this->request->get('operation_type', ''),
            'date_from'      => $this->request->get('date_from', ''),
            'date_to'        => $this->request->get('date_to', ''),
        ];

        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $acts  = $this->actModel->getFiltered($filters, $perPage, $offset);
        $total = $this->actModel->countFiltered($filters);

        $this->render('acts/index', [
            'title'      => __('ACTS_TITLE'),
            'acts'       => $acts,
            'filters'    => $filters,
            'pagination' => paginate($total, $perPage, $page),
        ]);
    }

    // =========================================================
    // CREATE — форма создания акта (БЕЗ поля номера)
    // =========================================================
    public function create(array $params = []): void
    {
        // Ручное создание акта оставлено только для администратора как резервный
        // путь (исправление ошибок, нештатные ситуации). Основной workflow —
        // кнопка "Сформировать Форму А" на странице рейса (FlightController::buildAct),
        // которая гарантирует "один рейс = один акт" и автосборку услуг служб.
        $this->requireRole(['admin']);

        try {
            $seqService = new \App\Services\DocumentSequenceService();
            $rawPreview = $seqService->preview('act');
            // Показываем формат: 0010/XX-XXXX (XX — код авиакомпании из рейса)
            preg_match('/(\d{4})$/', $rawPreview, $m);
            $seq = $m[1] ?? '????';
            $nextNumber = "{$seq}/XX-XXXX";
        } catch (\Throwable $e) {
            $nextNumber = '????/XX-XXXX'; // table not yet created
        }

        $flights    = $this->flightModel->getFiltered([], 200, 0);
        $currencies = $this->currencyModel->getAllActive();

        $this->render('acts/create', [
            'title'      => __('ACTS_CREATE'),
            'flights'    => $flights,
            'currencies' => $currencies,
            'nextNumber' => $nextNumber,  // только для показа, не для ввода
        ]);
    }

    // =========================================================
    // STORE — сохранение нового акта
    // =========================================================
    public function store(array $params = []): void
    {
        $this->requireRole(['admin']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/acts/create');
            return;
        }

        $data = $this->request->all();

        // Валидация — act_number НЕ входит (генерируется системой)
        $validator = new Validator($data);
        $validator->required('flight_id', __('ACTS_FLIGHT'))
                  ->required('route_type', __('ACTS_ROUTE_TYPE'))
                  ->required('operation_type', __('ACTS_OPERATION'))
                  ->required('settlement_currency_id', __('ACTS_CURRENCY'))
                  ->inList('route_type', ['domestic', 'international'], __('ACTS_ROUTE_TYPE'))
                  ->inList('operation_type', ['arrival', 'departure'], __('ACTS_OPERATION'));

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/acts/create');
            return;
        }

        // Один рейс = один акт (UNIQUE constraint в БД) — проверяем заранее,
        // чтобы дать понятную ошибку вместо падения SQL.
        $actModel = new \App\Models\Act();
        if ($actModel->findByFlightId((int)$data['flight_id'])) {
            View::setFlash('error', 'По этому рейсу уже существует акт. Используйте кнопку «Сформировать/Обновить Форму А» на странице рейса.');
            $this->redirect('/acts/create');
            return;
        }

        $result = $this->actService->createAct($data);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/acts/create');
            return;
        }

        // Раунд 35: сразу после createAct() номер ещё не финализирован
        // (заглушка) — показываем ЦОК понятную формулировку, а не 'PRELIM-...'.
        View::setFlash('success', __('ACTS_CREATED_SUCCESS', ['number' => 'ПРЕДВАРИТЕЛЬНО'])
            . ' Строки акта не добавлены автоматически (ручное создание) — добавьте услуги вручную или используйте «Сформировать Форму А» со страницы рейса.'
            . ' Официальный номер будет присвоен после подписания супервайзером и представителем авиакомпании.');
        $this->redirect('/acts/' . $result['id'] . '/edit');
    }

    /**
     * Пересобрать акт — подтянуть новые услуги, добавленные службами после
     * создания акта. Тонкая обёртка над FlightController::buildAct (единая
     * точка сборки), чтобы не дублировать логику в двух контроллерах.
     */
    public function resync(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        $actId = (int)($params['id'] ?? 0);

        $act = $this->actModel->find($actId);
        if (!$act) { $this->redirect('/acts'); return; }

        if (in_array($act['status'], ['approved', 'cancelled', 'exported'], true)) {
            View::setFlash('error', 'Акт уже утверждён/закрыт — обновление недоступно.');
            $this->redirect('/acts/' . $actId);
            return;
        }

        // ДОБАВЛЕНО (23.09.2026, раунд 55, по итогам аудита): "Обновить"
        // раньше пересчитывал обязательные сборы (TLF/AV_SEC/NIGHT/
        // OVER_PARK/METEO и т.п.) без единой проверки — тот же гейт
        // (манифест + ATA/ATD + колодки), что стоит перед ПЕРВИЧНЫМ
        // формированием акта в FlightController::buildAct(), сюда не
        // проецировался. На практике акт не может возникнуть в обход этого
        // гейта иначе как через ручное «Создать акт» администратором
        // (ActController::store() — резервный путь для нештатных ситуаций,
        // сам по себе авто-сборы не считает), но именно после такого
        // ручного создания «Обновить» мог досчитать TLF и производные по
        // рейсу, у которого ATA/ATD/колодки/манифест ещё не заполнены.
        // Используем тот же AutoChargeService::getActFormationBlockers(),
        // что и buildAct() — см. её докблок.
        $flightForGate = $this->flightModel->find((int)$act['flight_id']);
        if ($flightForGate) {
            $blockingReasons = (new \App\Services\AutoChargeService())->getActFormationBlockers($flightForGate);
            if (!empty($blockingReasons)) {
                View::setFlash('error', 'Обновление акта недоступно — '
                    . implode('; ', $blockingReasons) . '. Заполните и повторите.');
                $this->redirect('/acts/' . $actId . '/edit');
                return;
            }
        }

        // ДОБАВЛЕНО (09.09.2026, раунд 32): найден реальный разрыв между
        // докблоком этого метода ("тонкая обёртка над FlightController::
        // buildAct, единая точка сборки") и фактическим кодом — resync()
        // на самом деле НЕ вызывал AutoChargeService::processFlightMandatoryCharges()
        // (это делал только сам buildAct() при первичном формировании акта).
        // Из-за этого обязательные услуги, ставшие обязательными ПОСЛЕ
        // создания акта (напр. по просьбе пользователя — Контроль
        // обслуживания/Supervision и Прием выпуск ВС/Marshalling, см.
        // sql/round32_mandatory_superv_marshalling.sql), никогда не
        // появлялись в уже существующих черновиках актов через "Обновить" —
        // только при формировании АКТА С НУЛЯ. Теперь пересчитываем
        // обязательные сборы тем же способом, что и buildAct() (с учётом
        // акта стоянки, если он уже подан — для корректных NIGHT/OVER_PARK),
        // прежде чем подтягивать начисления в акт.
        // ИЗМЕНЕНО (17.09.2026, раунд 44): processParkingAct() больше не
        // принимает параметры и не зависит от parking_acts.status — сам
        // находит время стоянки по колодкам (см. AutoChargeService,
        // докблок processParkingAct()). Вызываем безусловно.
        $mandatoryWarnings = [];
        try {
            $flightId   = (int)$act['flight_id'];
            $autoCharge = new \App\Services\AutoChargeService();
            $mandatoryWarnings = $autoCharge->processParkingAct($flightId);
        } catch (\Throwable $e) {
            error_log('resync: mandatory auto-charges failed: ' . $e->getMessage());
            $mandatoryWarnings[] = 'Автоматический расчёт обязательных сборов не выполнен: ' . $e->getMessage();
        }

        $buildResult = $this->actService->buildFromFlightCharges($actId, (int)$act['flight_id']);

        if (($buildResult['added'] ?? 0) > 0) {
            View::setFlash('success', 'Добавлено новых услуг: ' . $buildResult['added'] . '.');
        } else {
            View::setFlash('success', 'Новых услуг от служб не найдено — акт уже актуален.');
        }
        if (!empty($buildResult['skipped'])) {
            View::setFlash('warning', implode('; ', $buildResult['skipped']));
        }
        if (!empty($mandatoryWarnings)) {
            View::setFlash('warning', implode(' ', $mandatoryWarnings));
        }
        // ДОБАВЛЕНО (10.09.2026, раунд 35): состав акта изменился уже ПОСЛЕ
        // того как его подписали — подписи автоматически сброшены (см.
        // ActService::buildFromFlightCharges()), нужно подписать заново.
        if (!empty($buildResult['signatures_reset'])) {
            View::setFlash('warning', 'Состав акта изменился после подписания — обе подписи сброшены, нужно подписать заново.');
        }

        $this->redirect('/acts/' . $actId . '/edit');
    }

    public function show(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);

        $act = $this->actModel->findWithDetails((int)$params['id']);
        if (!$act) {
            View::setFlash('error', 'Act not found.');
            $this->redirect('/acts');
            return;
        }

        $items   = $this->actItemModel->getByAct((int)$params['id']);
        $history = $this->historyModel->getByAct((int)$params['id']);
        $files   = $this->actFileModel->getByAct((int)$params['id']);
        $scans   = $this->actFileModel->getScansForAct((int)$params['id']);

        // ДОБАВЛЕНО (14.10.2026, раунд 71): см. ActService::buildTextBreakdown()
        // — текстовая расшифровка для попапа "Расшифровка" на этой странице.
        // ДОБАВЛЕНО (15.10.2026, раунд 80, по прямому указанию пользователя):
        // попап теперь показывает таблицу (см. ActService::buildBreakdownHtml()),
        // $textBreakdown остался только как источник текста для кнопки
        // "Копировать" (копируется как текст, не как HTML-разметка).
        $textBreakdown = $this->actService->buildTextBreakdown($act, $items);
        $breakdownHtml = $this->actService->buildBreakdownHtml($act, $items);

        $this->render('acts/show', [
            'title'         => __('ACTS_TITLE') . ': ' . act_display_number($act),
            'act'           => $act,
            'items'         => $items,
            'history'       => $history,
            'files'         => $files,
            'scans'         => $scans,
            'textBreakdown' => $textBreakdown,
            'breakdownHtml' => $breakdownHtml,
        ]);
    }

    /**
     * ДОБАВЛЕНО (14.10.2026, раунд 71, по прямому указанию пользователя:
     * "попап должен быть на странице /acts рядом с кнопкой просмотра").
     * JSON-эндпоинт для попапа «Расшифровка» в СПИСКЕ актов (/acts) —
     * грузится через fetch() по клику на кнопку рядом с «Просмотр», без
     * перехода на страницу самого акта. Та же текстовая расшифровка, что
     * и на странице акта — см. ActService::buildTextBreakdown() (общая
     * логика, не продублирована).
     */
    public function textBreakdown(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);

        header('Content-Type: application/json');

        $act = $this->actModel->findWithDetails((int)$params['id']);
        if (!$act) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Act not found.']);
            return;
        }

        $items = $this->actItemModel->getByAct((int)$params['id']);
        $text  = $this->actService->buildTextBreakdown($act, $items);
        // ДОБАВЛЕНО (15.10.2026, раунд 80): HTML-таблица для отображения в
        // попапе списка актов (/acts) — см. ActService::buildBreakdownHtml().
        // $text остаётся для кнопки "Копировать".
        $html  = $this->actService->buildBreakdownHtml($act, $items);

        echo json_encode([
            'success' => true,
            'title'   => act_display_number($act),
            'text'    => $text,
            'html'    => $html,
        ]);
    }

    public function edit(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        $act = $this->actModel->findWithDetails((int)$params['id']);
        if (!$act) {
            View::setFlash('error', 'Act not found.');
            $this->redirect('/acts');
            return;
        }

        if (in_array($act['status'], ['approved', 'cancelled', 'exported'], true)) {
            View::setFlash('warning', __('ACTS_EDIT_BLOCKED', ['status' => __('ACTS_STATUS_' . strtoupper($act['status']))]));
            $this->redirect('/acts/' . $params['id']);
            return;
        }

        $flights    = $this->flightModel->getFiltered([], 200, 0);
        $currencies = $this->currencyModel->getAllActive();
        $items      = $this->actItemModel->getByAct((int)$params['id']);
        $services   = $this->serviceModel->getAllActive();

        $this->render('acts/edit', [
            'title'      => __('COMMON_EDIT') . ' ' . __('ACTS_TITLE') . ': ' . act_display_number($act),
            'act'        => $act,
            'flights'    => $flights,
            'currencies' => $currencies,
            'items'      => $items,
            'services'   => $services,
        ]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/acts/' . $params['id'] . '/edit');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        $validator->required('flight_id', __('ACTS_FLIGHT'))
                  ->required('route_type', __('ACTS_ROUTE_TYPE'))
                  ->required('operation_type', __('ACTS_OPERATION'))
                  ->required('settlement_currency_id', __('ACTS_CURRENCY'))
                  ->inList('route_type', ['domestic', 'international'], __('ACTS_ROUTE_TYPE'))
                  ->inList('operation_type', ['arrival', 'departure'], __('ACTS_OPERATION'));

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/acts/' . $params['id'] . '/edit');
            return;
        }

        $result = $this->actService->updateAct((int)$params['id'], $data);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/acts/' . $params['id'] . '/edit');
            return;
        }

        View::setFlash('success', __('ACTS_SAVED_SUCCESS'));
        $this->redirect('/acts/' . $params['id']);
    }

    public function addItem(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/acts/' . $params['id'] . '/edit');
            return;
        }

        $data      = $this->request->all();
        $validator = new Validator($data);
        $validator->required('service_id', __('ACTS_SERVICE'))
                  ->required('quantity', __('ACTS_QUANTITY'))
                  ->positiveNumber('quantity', __('ACTS_QUANTITY'));

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/acts/' . $params['id'] . '/edit');
            return;
        }

        $result = $this->actService->addActItem((int)$params['id'], $data);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
        } else {
            View::setFlash('success', __('ACTS_ITEM_ADDED'));
        }
        $this->redirect('/acts/' . $params['id'] . '/edit');
    }

    public function updateItem(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/acts/' . $params['act_id'] . '/edit');
            return;
        }

        $data      = $this->request->all();
        $validator = new Validator($data);
        $validator->required('quantity', __('ACTS_QUANTITY'))
                  ->positiveNumber('quantity', __('ACTS_QUANTITY'));

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/acts/' . $params['act_id'] . '/edit');
            return;
        }

        $result = $this->actService->updateActItem((int)$params['item_id'], $data);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
        } else {
            View::setFlash('success', __('ACTS_ITEM_SAVED'));
        }
        $this->redirect('/acts/' . $params['act_id'] . '/edit');
    }

    public function deleteItem(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/acts/' . $params['act_id'] . '/edit');
            return;
        }

        $result = $this->actService->deleteActItem((int)$params['item_id']);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
        } else {
            View::setFlash('success', __('ACTS_ITEM_DELETED'));
        }
        $this->redirect('/acts/' . $params['act_id'] . '/edit');
    }

    // =========================================================
    // CHANGE STATUS — с обязательным сканом при утверждении
    // =========================================================
    public function changeStatus(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/acts/' . $params['id']);
            return;
        }

        $newStatus = $this->request->input('new_status', '');
        $reason    = $this->request->input('reason', '');

        if (!in_array($newStatus, ['approved', 'cancelled', 'exported'], true)) {
            View::setFlash('error', 'Invalid status.');
            $this->redirect('/acts/' . $params['id']);
            return;
        }

        $scanFileId = null;

        // При утверждении — обязательно загружаем скан
        if ($newStatus === 'approved') {
            if ($this->request->hasFile('scan_file')) {
                // strict=true: PDF без ВКД тоже блокируется при утверждении
                $uploadResult = $this->fileService->uploadForAct(
                    $this->request->files['scan_file'],
                    (int)$params['id'],
                    'signed_copy',
                    true  // strictPdfVerify
                );

                if (!$uploadResult['success']) {
                    View::setFlash('error', __('ACTS_SCAN_UPLOAD_FAILED') . ': ' . $uploadResult['error']);
                    $this->redirect('/acts/' . $params['id']);
                    return;
                }

                $scanFileId = $uploadResult['file_id'];
            }
        }

        $result = $this->actService->changeStatus(
            (int)$params['id'],
            $newStatus,
            $reason ?: null,
            $scanFileId,
            $this->request->input('airline_rep_name', null) ?: null,
            $this->request->input('airline_rep_position', null) ?: null
        );

        if (!$result['success']) {
            if (($result['code'] ?? '') === 'SCAN_REQUIRED') {
                View::setFlash('error', __('ACTS_SCAN_REQUIRED'));
            } else {
                View::setFlash('error', $result['error']);
            }
        } else {
            View::setFlash('success', __('ACTS_STATUS_CHANGED', [':status' => __('ACTS_STATUS_' . strtoupper($newStatus))]));
        }

        $this->redirect('/acts/' . $params['id']);
    }

    public function getTariffForService(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        $serviceId     = (int)$this->request->get('service_id', 0);
        $routeType     = $this->request->get('route_type', '');
        $operationType = $this->request->get('operation_type', '');
        $date          = $this->request->get('date', date('Y-m-d'));

        if (!$serviceId || !$routeType || !$operationType) {
            $this->json(['success' => false, 'error' => 'Missing parameters.']);
            return;
        }

        $tariff = $this->tariffModel->getActiveForService($serviceId, $routeType, $operationType, $date);

        // Добавляем service_code в ответ (нужен для JS чтобы определить % услуги)
        if ($tariff) {
            $svcModel = new \App\Models\Service();
            $svc = $svcModel->find($serviceId);
            $tariff['service_code'] = $svc['code'] ?? '';
        }

        $this->json(['success' => true, 'tariff' => $tariff]);
    }

    public function printForm(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);

        $data = $this->buildPrintData((int)$params['id']);
        if (!$data) {
            View::setFlash('error', 'Act not found.');
            $this->redirect('/acts');
            return;
        }

        $this->view->render('acts/print', [
            'layout' => 'print',
            'title'  => 'Форма А — Акт № ' . act_display_number($data['act']),
            'act'    => $data['act'],
            'items'  => $data['items'],
        ]);
    }

    /**
     * ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): та же самая сборка данных
     * печатной формы, что и printForm() выше (перенесена сюда БЕЗ изменения
     * логики — printForm() теперь просто вызывает этот метод), но БЕЗ
     * requireRole() — вызывающий код сам отвечает за авторизацию. Нужна
     * для генерации PDF (см. App\Services\ActPdfService::renderHtml()) —
     * там просмотр акта уже проверен иначе (представитель видит только
     * акты своего хендлера, см. RepresentativeController), а не по общей
     * матрице ролей acts.print (admin/cok/commercial/finance), в которую
     * представитель никогда не будет входить.
     */
    public function buildPrintData(int $actId): ?array
    {
        $act = $this->actModel->findWithDetails($actId);
        if (!$act) {
            return null;
        }

        // УБРАНО (11.09.2026, раунд 38, по прямому указанию пользователя):
        // раньше здесь время прилёта/вылета в шапке акта подменялось
        // временем старта/завершения услуги "Лидирование ВС" (LEAD) (раунд 3).
        // Пользователь указал, что это неверно: время прилёта/вылета должно
        // вводиться ЦОК вручную на странице рейса (поля flights.arr_date/
        // arr_time/dep_date/dep_time — они и раньше существовали в форме
        // редактирования рейса, см. resources/views/flights/edit.php), а не
        // молча подменяться значением, которое зафиксировал супервайзер по
        // ДРУГОЙ услуге (лидирование ВС — это не факт прилёта/вылета самого
        // по себе). Теперь используются ИСКЛЮЧИТЕЛЬНО поля рейса, без
        // привязки к LEAD service order. Оба поля стали обязательными для
        // формирования акта — см. FlightController::buildAct().
        //
        // ПРИВЯЗКА ВРЕМЕНИ КОЛОДОК (раунд 9) — СОХРАНЕНА, по уточнению
        // пользователя при разборе этого же раунда 38: время установки/
        // уборки колодок по-прежнему в первую очередь берётся из отдельной
        // услуги Аэродромной службы CHOCKS (started_at = установка, нужна
        // для акта ПРИЛЁТА; completed_at = уборка, акт ВЫЛЕТА), а поле
        // рейса chocks_on/chocks_off — запасной вариант, если заказа CHOCKS
        // ещё нет. Само по себе наличие ЛИБО того, ЛИБО другого стало
        // обязательным для формирования акта — см. buildAct() и её приватный
        // помощник resolveChocksTime().
        $chocksOrder = $this->serviceOrderModel->findByFlightAndServiceCode((int)$act['flight_id'], 'CHOCKS');
        if ($chocksOrder) {
            if (($act['operation_type'] ?? '') === 'arrival' && !empty($chocksOrder['started_at'])) {
                $act['chocks_on'] = substr($chocksOrder['started_at'], 11, 5);
            } elseif (($act['operation_type'] ?? '') === 'departure' && !empty($chocksOrder['completed_at'])) {
                $act['chocks_off'] = substr($chocksOrder['completed_at'], 11, 5);
            }
        }
        //
        // ИСПРАВЛЕНО (14.08.2026, раунд 8, актуально и сейчас): $act
        // приходит из Act::findWithDetails(), которая выбирает
        // f.arr_date/arr_time/dep_date/dep_time/chocks_on/chocks_off
        // НАПРЯМУЮ из `flights` — а там у одного и того же рейса вполне
        // может быть заполнена и дата прилёта, и дата вылета (обычный
        // recurring-рейс/разворот), независимо от того, какой именно акт
        // (прилёта или вылета) сейчас печатается. Правило заказчика: "акт
        // прилёта содержит данные акта прилёта, акт вылета содержит данные
        // вылета, ничего лишнего" — поэтому здесь ЯВНО обнуляем поля
        // противоположной операции, а не полагаемся на то, что они и так
        // окажутся пустыми.
        if (($act['operation_type'] ?? '') === 'arrival') {
            $act['dep_date']   = null;
            $act['dep_time']   = null;
            $act['chocks_off'] = null;
        } elseif (($act['operation_type'] ?? '') === 'departure') {
            // ИЗМЕНЕНО (18.09.2026, раунд 44, по прямому указанию
            // пользователя, продолжение того же исключения из правила
            // раунда 8 — см. предыдущую версию этого блока): вместо
            // отдельной подписи "Посадка (рейс прилёта): ..." теперь дата и
            // время посадки печатаются прямо в штатной строке "Дата и время
            // посадки" (ARR-LT) акта вылета — со связанного рейса прилёта
            // той же ротации (flights.linked_flight_id, раунд 33). Само
            // время установки колодок отдельно больше не печатается — вместо
            // него в строке колодок теперь итоговый РАСЧЁТ времени стоянки
            // (см. $act['parking_calc_display'] ниже) — по прямому указанию
            // пользователя: "думаю поставить расчет времени стоянки" вместо
            // голых цифр. chocks_on акта вылета как таковой (своего времени
            // установки колодок на этом акте нет — это акт вылета) остаётся
            // пустым.
            $act['chocks_on'] = null;

            $depFlight   = $this->flightModel->find((int)$act['flight_id']);
            $linkedArrId = (int)($depFlight['linked_flight_id'] ?? 0);
            if ($linkedArrId > 0) {
                $arrFlight = $this->flightModel->find($linkedArrId);
                if ($arrFlight && ($arrFlight['operation_type'] ?? '') === 'arrival') {
                    // ИСПРАВЛЕНО (18.09.2026, раунд 44): flights.arr_date у
                    // многих рейсов не заполняется отдельно (ЦОК вводит
                    // только время посадки arr_time, дату отдельно не
                    // трогает) — ровно для этого случая печатная форма НА
                    // САМОМ АКТЕ ПРИЛЁТА подставляет вместо пустого arr_date
                    // запасной вариант flight_date этого же рейса (см.
                    // print.php, $arrDate = ... ($isArr ? format_date($act['flight_date']...) : '')).
                    // Взяв здесь только «сырое» значение arr_date без такого
                    // же запасного варианта, получали дату пустой, а время —
                    // заполненным (то, что заметил пользователь: "время
                    // подтянул, а дату — нет"). Теперь применяем тот же
                    // самый запасной вариант, что и на акте прилёта — дата
                    // посадки рейса прилёта = его собственный flight_date,
                    // если arr_date отдельно не заполнен.
                    $act['arr_date'] = !empty($arrFlight['arr_date']) ? $arrFlight['arr_date'] : $arrFlight['flight_date'];
                    $act['arr_time'] = $arrFlight['arr_time'] ?? null;
                } else {
                    $act['arr_date'] = null;
                    $act['arr_time'] = null;
                }
            } else {
                $act['arr_date'] = null;
                $act['arr_time'] = null;
            }

            // ДОБАВЛЕНО (18.09.2026, раунд 44): расчёт времени стоянки — те
            // же самые числа (время колодок, норма, сверхнормативные часы и
            // сутки), что AutoChargeService::processParkingAct() использует
            // для начисления OVER_PARK, здесь только для отображения, без
            // побочных эффектов (см. AutoChargeService::
            // getParkingTimingSummary() — общий, ничего не начисляющий метод,
            // вынесенный именно затем, чтобы не дублировать эту логику).
            $parkingSummary = (new \App\Services\AutoChargeService())
                ->getParkingTimingSummary((int)$act['flight_id']);
            $act['parking_calc_display'] = $this->formatParkingCalcDisplay($parkingSummary);
        }

        $items = $this->actItemModel->getByAct($actId);

        return ['act' => $act, 'items' => $items];
    }

    /**
     * ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): рендерит ту же самую печатную
     * форму (acts/print.php через layout 'print'), что видит пользователь
     * по кнопке "Печать", но возвращает готовый HTML строкой вместо вывода
     * в браузер — для генерации PDF (см. App\Services\ActPdfService).
     * Без requireRole() — как и buildPrintData() выше, авторизация на
     * совести вызывающего кода.
     */
    public function renderPrintHtml(int $actId): ?string
    {
        $data = $this->buildPrintData($actId);
        if (!$data) {
            return null;
        }

        ob_start();
        $this->view->render('acts/print', [
            'layout' => 'print',
            'title'  => 'Форма А — Акт № ' . act_display_number($data['act']),
            'act'    => $data['act'],
            'items'  => $data['items'],
        ]);
        return ob_get_clean();
    }

    /**
     * ДОБАВЛЕНО (18.09.2026, раунд 44): человекочитаемая строка расчёта
     * времени стоянки для печати на акте вылета (см. printForm() выше) —
     * из результата AutoChargeService::getParkingTimingSummary(). Пустая
     * строка означает "печатать нечего" (ячейка остаётся пустой, как и до
     * появления этого расчёта) — например когда рейсы ещё не связаны и
     * печатать по сути нечего.
     */
    private function formatParkingCalcDisplay(array $summary): string
    {
        $fmtHm = static function (?float $hours): string {
            if ($hours === null) return '';
            $h = (int)floor($hours);
            $m = (int)round(($hours - $h) * 60);
            if ($m === 60) { $h++; $m = 0; }
            return $m > 0 ? "{$h}ч{$m}м" : "{$h}ч";
        };

        switch ($summary['status']) {
            case 'ok':
                // ИЗМЕНЕНО (25.09.2026, раунд 57, п.21 ч.3 Правил КМ №218):
                // добавлен 15-минутный буфер поверх нормы в отображаемую
                // формулу — та же величина, что реально вычитается в
                // getParkingTimingSummary(), иначе печатная форма показывала
                // бы расчёт, не совпадающий с фактически начисленной суммой.
                $stay       = $fmtHm($summary['hours']);
                $norm       = $fmtHm($summary['freeHours']);
                $bufferMin  = (int)round(($summary['bufferHours'] ?? 0) * 60);
                $normLabel  = $bufferMin > 0 ? "{$norm} + {$bufferMin}м буфер" : $norm;
                if ((int)$summary['overDays'] > 0) {
                    return "Расчёт стоянки: {$stay} − {$normLabel} (норма, п.21 Правил КМ №218) → {$summary['overDays']} сут. сверхнормативно";
                }
                return "Расчёт стоянки: {$stay} — в пределах нормы ({$normLabel}), доплата не начисляется";
            case 'blocked_no_chocks':
                return 'Расчёт стоянки не выполнен: не зафиксировано время колодок';
            case 'blocked_no_norm':
                return 'Расчёт стоянки не выполнен: не задана норма бесплатной стоянки для авиакомпании';
            case 'blocked_not_linked':
            case 'no_mtow':
            default:
                return '';
        }
    }

    // =========================================================
    // PUBLIC VERIFICATION — открывается при сканировании QR
    // Без авторизации — публичная страница
    // =========================================================
    public function verifyPublic(array $params = []): void
    {
        $code = preg_replace('/[^A-Z0-9]/', '', strtoupper($params['code'] ?? ''));

        if (empty($code)) {
            http_response_code(400);
            echo '<p style="font-family:sans-serif;padding:2rem">Неверный код верификации.</p>';
            return;
        }

        // Ищем акт по верификационному коду
        // Перебираем последние 200 актов (оптимально для небольшой БД)
        $acts = $this->actModel->findAll([], 'id DESC', 200);
        $foundAct = null;

        foreach ($acts as $act) {
            $expected = act_verify_code(
                $act['act_number'],
                $act['act_date'],
                (float)$act['total_amount_with_vat']
            );
            if ($expected === $code) {
                $foundAct = $act;
                break;
            }
        }

        if (!$foundAct) {
            http_response_code(404);
            $this->renderVerifyPage(null, $code);
            return;
        }

        // Загружаем детали акта
        $fullAct = $this->actModel->findWithDetails((int)$foundAct['id']);
        $this->renderVerifyPage($fullAct, $code);
    }

    private function renderVerifyPage(?array $act, string $code): void
    {
        $appName = $_ENV['APP_NAME'] ?? 'Forma A';
        $appUrl  = $_ENV['APP_URL']  ?? '';
        ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Верификация акта — <?= e($appName) ?></title>
<style>
body { font-family: Arial, sans-serif; margin: 0; background: #f5f7fa; color: #222; }
.card { max-width: 500px; margin: 40px auto; background: #fff; border-radius: 8px;
        box-shadow: 0 2px 12px rgba(0,0,0,.1); overflow: hidden; }
.header { padding: 20px 24px; color: #fff; }
.header.ok  { background: #1a6b3a; }
.header.err { background: #8b0000; }
.header h2 { margin: 0 0 4px; font-size: 1.3rem; }
.header p  { margin: 0; font-size: .9rem; opacity: .85; }
.body { padding: 20px 24px; }
.row { display: flex; justify-content: space-between; padding: 8px 0;
       border-bottom: 1px solid #eee; font-size: .95rem; }
.row:last-child { border-bottom: none; }
.lbl { color: #666; }
.val { font-weight: bold; }
.code { font-family: monospace; font-size: 1.1rem; letter-spacing: 2px;
        background: #f0f3f8; padding: 4px 10px; border-radius: 4px; }
.footer { text-align: center; padding: 12px; color: #999; font-size: .8rem; border-top: 1px solid #eee; }
</style>
</head>
<body>
<div class="card">
<?php if ($act): ?>
    <div class="header ok">
        <h2>✅ Документ подлинный</h2>
        <p>Акт найден в системе и прошёл верификацию</p>
    </div>
    <div class="body">
        <div class="row"><span class="lbl">Номер акта</span><span class="val"><?= e($act['act_number']) ?></span></div>
        <div class="row"><span class="lbl">Дата</span><span class="val"><?= format_date($act['act_date'], 'd.m.Y') ?></span></div>
        <div class="row"><span class="lbl">Авиакомпания</span><span class="val"><?= e($act['airline_name']) ?> (<?= e($act['airline_iata'] ?? '') ?>)</span></div>
        <div class="row"><span class="lbl">Рейс</span><span class="val"><?= e($act['flight_number']) ?></span></div>
        <div class="row"><span class="lbl">Маршрут</span><span class="val"><?= e($act['origin_iata'] ?? '—') ?> → <?= e($act['destination_iata'] ?? '—') ?></span></div>
        <div class="row"><span class="lbl">Дата рейса</span><span class="val"><?= format_date($act['flight_date'], 'd.m.Y') ?></span></div>
        <div class="row"><span class="lbl">Статус</span><span class="val"><?= e(strtoupper($act['status'])) ?></span></div>
        <div class="row"><span class="lbl">Сумма с НДС</span><span class="val"><?= number_format((float)$act['total_amount_with_vat'], 2, '.', ' ') ?> <?= e($act['currency_code']) ?></span></div>
        <?php if ($act['status'] === 'approved' && $act['approved_by_fullname']): ?>
        <div class="row"><span class="lbl">Утверждён</span><span class="val"><?= e($act['approved_by_fullname']) ?><br><small><?= format_datetime($act['approved_at']) ?></small></span></div>
        <?php endif; ?>
        <div class="row"><span class="lbl">Код ВКД</span><span class="val"><span class="code"><?= e($code) ?></span></span></div>
    </div>
<?php else: ?>
    <div class="header err">
        <h2>❌ Документ не найден</h2>
        <p>Код верификации не соответствует ни одному акту в системе</p>
    </div>
    <div class="body">
        <div class="row"><span class="lbl">Код ВКД</span><span class="val"><span class="code"><?= e($code) ?></span></span></div>
        <p style="color:#8b0000;margin-top:12px">Этот документ может быть поддельным или изменённым.</p>
    </div>
<?php endif; ?>
    <div class="footer"><?= e($appName) ?> — система верификации документов</div>
</div>
</body>
</html>
<?php
    }
}