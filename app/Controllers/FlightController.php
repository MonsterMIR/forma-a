<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Validator;
use App\Models\Flight;
use App\Models\Airline;
use App\Models\Airport;
use App\Services\FlightService;
use App\Services\DeadlineService;
use App\Models\FlightDocument;
use App\Models\FlightDeviation;
use App\Models\TechCard;
use App\Models\EquipmentLog;
use App\Models\ParkingAct;
use App\Models\PassengerManifest;
use App\Services\ActService;
use App\Models\FlightServiceOrder;
use App\Models\TariffVersion;
use App\Models\ParkingStand;
use App\Services\NotificationService;

class FlightController extends Controller
{
    private FlightService $flightService;
    private Flight        $flightModel;
    private Airline       $airlineModel;
    private Airport       $airportModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->flightService = new FlightService();
        $this->flightModel   = new Flight();
        $this->airlineModel  = new Airline();
        $this->airportModel  = new Airport();
    }

    public function index(array $params = []): void
    {
        // ИСПРАВЛЕНО (03.10.2026, раунд 65): список рейсов блокировал
        // supervisor/gsm/medical/balance с 403, хотя пункт меню "Все рейсы"
        // показывается им же — Permissions::can('flights.view') для этих
        // 4 ролей истинно (см. Permissions::$matrix), а этот requireRole()
        // дублировал список ролей отдельно и не был обновлён вместе с
        // матрицей. Явный баг-репорт пользователя: "супервайзер не имеет
        // доступа к информации по рейсам, а должен" — клик по "Все рейсы"
        // в меню вёл на 403. GSM/MEDICAL/BALANCE добавлены туда же — тот же
        // пробел, пока без видимых живых пользователей (роли завели в
        // раунде 41), чтобы не наступить на те же грабли повторно, когда
        // для них заведут логины.
        $this->requireRole(['admin', 'cok', 'sop', 'spo', 'sst', 'aero', 'spasop', 'commercial', 'finance', 'supervisor', 'gsm', 'medical', 'balance']);

        $filters = [
            'search'         => $this->request->get('search', ''),
            'airline_id'     => $this->request->get('airline_id', ''),
            'route_type'     => $this->request->get('route_type', ''),
            'operation_type' => $this->request->get('operation_type', ''),
            'date_from'      => $this->request->get('date_from', ''),
            'date_to'        => $this->request->get('date_to', ''),
        ];

        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $flights  = $this->flightModel->getFiltered($filters, $perPage, $offset);
        $total    = $this->flightModel->countFiltered($filters);
        $airlines = $this->airlineModel->getAllActive();

        $this->render('flights/index', [
            'title'      => 'Flights',
            'flights'    => $flights,
            'airlines'   => $airlines,
            'filters'    => $filters,
            'pagination' => paginate($total, $perPage, $page),
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        // ДОБАВЛЕНО (10.11.2026, раунд 165) — см. докблок View::setOldInput().
        // Если это повторный показ формы после неудачного сохранения —
        // подкладываем то, что человек реально ввёл, обратно в $_POST: вся
        // уже существующая в этом шаблоне разметка (`$_POST['поле'] ?? ''`)
        // начинает работать так, как и было задумано, без переписывания
        // самого шаблона.
        $old = View::getOldInput();
        if (!empty($old)) {
            $_POST = $old;
        }

        $airlines = $this->airlineModel->getAllActive();
        $airports = $this->airportModel->getAllActive();
        // ДОБАВЛЕНО (18.08.2026, раунд 16): список стоянок для выпадающего
        // списка МС№ — см. App\Models\ParkingStand.
        // ИЗМЕНЕНО (18.09.2026, раунд 48, по прямому указанию пользователя):
        // занятые стоянки вообще не предлагаются в списке — см.
        // ParkingStandService::getAvailableStands(). Рейс ещё не создан,
        // регистрация борта ещё не введена (это то же самое поле формы) —
        // поэтому исключаются ВСЕ занятые стоянки. Обычный сценарий, когда
        // рейс вылета продолжает стоянку своего же прилёта — оставить поле
        // пустым, FlightService сам подставит стоянку после автосвязывания.
        $parkingStands = [];
        try {
            // ИЗМЕНЕНО (10.11.2026, раунд 165): при первом, чистом показе формы
            // $_POST пуст — поведение не изменилось (исключаются все занятые
            // стоянки, см. докблок класса). Но если это повторный показ после
            // ошибки и борт уже был введён (см. $old выше) — список стоянок
            // должен сразу это учитывать, а не исключать "свою" же стоянку,
            // как будто регистрация ещё не вводилась.
            $parkingStands = (new \App\Services\ParkingStandService())
                ->getAvailableStands(
                    (new ParkingStand())->getAllActive(),
                    ['aircraft_registration' => $_POST['aircraft_registration'] ?? null]
                );
        } catch (\Throwable $e) {}
        // ДОБАВЛЕНО (16.10.2026, раунд 93, по прямому указанию пользователя,
        // по итогам задвоенного рейса AXS4/T7POL — см.
        // claude/analysis-2026-10-16-round92-double-submit-duplicate-flight-axs4.md):
        // одноразовый токен отправки формы — закрывает двойной клик/повторную
        // отправку НАДЁЖНО, а не только на уровне клиентской защиты (кнопка
        // disabled, round 92) или проверки на дубль по смыслу
        // (Flight::findIdleDuplicate(), round 69, у которой теоретически была
        // гонка "проверили — потом записали"). Токен кладётся в сессию при
        // отрисовке формы и СЪЕДАЕТСЯ (удаляется) при первой же успешной
        // проверке в store() — см. там. PHP по умолчанию блокирует файл
        // сессии на всё время запроса (session_start() с file-based
        // хранилищем), поэтому даже два почти одновременных запроса от того
        // же браузера обрабатываются строго по очереди — второй увидит токен
        // уже удалённым первым и будет отклонён, без исключения по времени.
        $submitToken = bin2hex(random_bytes(16));
        $_SESSION['pending_flight_create_tokens'][$submitToken] = time();
        // Чистим токены старше часа, чтобы массив в сессии не рос бесконечно
        // у пользователя, который держит вкладку с формой открытой подолгу.
        foreach (($_SESSION['pending_flight_create_tokens'] ?? []) as $t => $ts) {
            if (time() - (int)$ts > 3600) {
                unset($_SESSION['pending_flight_create_tokens'][$t]);
            }
        }

        $this->render('flights/create', [
            'title'         => 'Create Flight',
            'airlines'      => $airlines,
            'airports'      => $airports,
            'homeAirportId' => $this->getHomeAirportId(),
            'parkingStands' => $parkingStands,
            'submitToken'   => $submitToken,
        ]);
    }

    /**
     * ID "домашнего" аэропорта TVT (Ташкент-Восточный) — при выборе операции
     * прилёт/вылет на форме рейса он автоматически подставляется в поле
     * "куда"/"откуда" (см. flights/create.php и flights/edit.php).
     * ИСПРАВЛЕНО: раньше ID нигде не передавался в шаблон (HOME_AIRPORT_ID
     * оставался 0), из-за чего вся JS-логика автоподстановки молча не
     * срабатывала.
     */
    private ?int $homeAirportIdCache = null;
    private function getHomeAirportId(): int
    {
        if ($this->homeAirportIdCache === null) {
            $airport = $this->airportModel->findByIata('TVT');
            $this->homeAirportIdCache = $airport ? (int)$airport['id'] : 0;
        }
        return $this->homeAirportIdCache;
    }

    /**
     * ДОБАВЛЕНО (03.08.2026): JSON-эндпоинт для чек-листа услуг прямо на форме
     * создания/редактирования рейса. Список зависит от route_type/
     * operation_type/MTOW — полей той же формы — поэтому дёргается через
     * fetch() по мере заполнения, а не строится один раз на сервере.
     */
    public function checklistServicesJson(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        $routeType     = (string)$this->request->get('route_type', '');
        $operationType = (string)$this->request->get('operation_type', '');
        $date          = (string)$this->request->get('date', date('Y-m-d'));
        $mtowRaw       = $this->request->get('mtow', '');
        $mtow          = ($mtowRaw !== '' && is_numeric($mtowRaw)) ? (float)$mtowRaw : null;

        if (!in_array($routeType, ['domestic', 'international'], true)
            || !in_array($operationType, ['arrival', 'departure'], true)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'route_type/operation_type обязательны']);
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $checklistServices = [];
        try {
            $all = (new \App\Models\TariffVersion())->getServicesWithActiveTariff(
                $routeType, $operationType, $date, null, $mtow
            );
            foreach ($all as $svc) {
                $role = $svc['owner_role'] ?: 'other';
                $checklistServices[$role][] = ['id' => (int)$svc['id'], 'name' => $svc['name']];
            }
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Ошибка загрузки списка услуг']);
            return;
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'services' => $checklistServices]);
    }

    /**
     * ДОБАВЛЕНО (17.08.2026): лёгкий JSON-эндпоинт для автообновления статусов
     * предзаказа услуг на форме редактирования рейса, без перезагрузки
     * страницы. Пользователь пожаловался: ЦОК был вынужден жать F5, чтобы
     * узнать, какая услуга уже оказана/в процессе — сама карточка чек-листа
     * грузится один раз через checklistServicesJson() и дальше не обновляется.
     * Отдаём ТОЛЬКО service_id+status (без описаний услуг — они не меняются
     * и уже есть на клиенте) — страница опрашивает этот эндпоинт раз в
     * несколько секунд и точечно обновляет бейджи/чекбоксы в JS, не перестраивая
     * весь чек-лист (иначе пропали бы несохранённые отметки ЦОК).
     */
    public function orderStatusesJson(array $params = []): void
    {
        // Тот же список ролей, что у edit() (единственная страница, которая
        // сейчас дёргает этот эндпоинт) — не расширяем доступ без необходимости.
        $this->requireRole(['admin', 'cok']);

        $flightId = (int)($params['id'] ?? 0);
        header('Content-Type: application/json');
        if ($flightId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Некорректный ID рейса']);
            return;
        }

        try {
            $orders = (new FlightServiceOrder())->getByFlightWithFact($flightId);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Ошибка загрузки статусов']);
            return;
        }

        $result = array_map(static function ($o) {
            return [
                'service_id'  => (int)$o['service_id'],
                'status'      => $o['status'],
                // ДОБАВЛЕНО (07.09.2026): причина отмены — нужна
                // edit.php/applyOrderStatusUpdate(), чтобы показать её в
                // бейдже "отменено", если супервайзер снял услугу, пока
                // страница ЦОК была открыта (см. round 29 в README).
                'timing_note' => $o['timing_note'] ?? '',
            ];
        }, $orders);

        echo json_encode(['success' => true, 'orders' => $result]);
    }

    public function store(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/flights/create');
            return;
        }

        $data = $this->request->all();

        // ДОБАВЛЕНО (16.10.2026, раунд 93) — см. docblock в create() выше.
        // Токен должен существовать в сессии и ещё не быть "съеденным"
        // предыдущим запросом; сразу же удаляем его, прежде чем идти дальше
        // — это и есть сама защита (одноразовость), а не просто логирование.
        $submitToken = (string)($data['_submit_token'] ?? '');
        $pending     = $_SESSION['pending_flight_create_tokens'] ?? [];
        if ($submitToken === '' || !isset($pending[$submitToken])) {
            \App\Core\ActivityLogger::log(
                'flight', null, 'duplicate_submit_blocked', 'failure',
                'Повторная/неизвестная отправка формы создания рейса — возможный двойной клик, запрос отклонён до создания рейса.'
            );
            View::setFlash('error', 'Эта форма уже была отправлена (возможно, повторный клик) — рейс не создан повторно. Если рейс не появился в списке, создайте его заново через новую форму.');
            $this->redirect('/flights');
            return;
        }
        unset($_SESSION['pending_flight_create_tokens'][$submitToken]);

        // ДОБАВЛЕНО (23.09.2026, раунд 53, по прямому указанию пользователя):
        // MTOW и стоянка (mc_number) раньше визуально выглядели обязательными
        // на форме (метка MTOW уже была помечена звёздочкой "*"), но реально
        // ничем не проверялись — рейс можно было создать вообще без них. От
        // MTOW зависит расчёт TLF, авиабезопасности и ночного сбора
        // (AutoChargeService), а без стоянки рейс невозможно корректно
        // разместить/проверить на конфликт (см. ParkingStandService,
        // раунд 48) — оба поля сделаны обязательными и на сервере, и в
        // разметке формы (атрибут required — см. flights/create.php).
        $validator = new Validator($data);
        $validator->required('airline_id', 'Airline')
                  ->required('flight_number', 'Flight Number')
                  ->required('flight_date', 'Flight Date')
                  ->required('route_type', 'Route Type')
                  ->required('operation_type', 'Operation Type')
                  ->required('aircraft_mtow', 'MTOW')
                  ->positiveNumber('aircraft_mtow', 'MTOW')
                  // ДОБАВЛЕНО (12.10.2026, раунд 69, по прямому указанию пользователя): без борта рейс никогда не
                  // свяжется в пару (FlightService::autoLinkFlight() ищет только по aircraft_registration) — см.
                  // claude/analysis-2026-10-12-round69-flight-pairing-enforcement.md.
                  ->required('aircraft_registration', 'Рег. номер ВС')
                  ->required('mc_number', 'Стоянка (MC№)')
                  ->date('flight_date', 'Flight Date')
                  ->inList('route_type', ['domestic', 'international'], 'Route Type')
                  ->inList('operation_type', ['arrival', 'departure'], 'Operation Type');

        if ($validator->fails()) {
            // ДОБАВЛЕНО (10.11.2026, раунд 165) — см. докблок View::setOldInput().
            View::setOldInput($data);
            View::setFlash('error', $validator->firstError());
            $this->redirect('/flights/create');
            return;
        }

        $result = $this->flightService->createFlight($data);
        if (!$result['success']) {
            View::setOldInput($data);
            View::setFlash('error', $result['error']);
            $this->redirect('/flights/create');
            return;
        }

        // ДОБАВЛЕНО (18.09.2026, раунд 46, п.4): уведомление о новом рейсе —
        // супервайзеру, СОП и (если однозначно определён) хендлеру
        // авиакомпании, чтобы заранее подготовиться. См. NotificationService::
        // notifyFlightCreated(). Мягкий отказ — сбой уведомления не должен
        // мешать создать рейс.
        try {
            (new NotificationService())->notifyFlightCreated((int)$result['id'], (int)\App\Core\Auth::id());
        } catch (\Throwable $e) {
            error_log('FlightController::store notifyFlightCreated error: ' . $e->getMessage());
        }

        // ДОБАВЛЕНО (03.08.2026): чек-лист услуг теперь прямо на форме создания —
        // отмеченное сохраняется в план (flight_service_orders) сразу вместе с рейсом.
        $serviceIds = array_map('intval', (array)$this->request->input('service_ids', []));
        $addedCount = 0;
        if (!empty($serviceIds)) {
            try {
                // ИЗМЕНЕНО (06.08.2026): createBulk() теперь возвращает ID
                // добавленных позиций (не count) — нужно, чтобы уведомить
                // именно вовлечённые службы о свежесозданном предзаказе.
                $addedServiceIds = (new \App\Models\FlightServiceOrder())
                    ->createBulk((int)$result['id'], $serviceIds, \App\Core\Auth::id());
                $addedCount = count($addedServiceIds);
                if ($addedCount > 0) {
                    (new NotificationService())->notifyPreorderCreated(
                        (int)$result['id'], $addedServiceIds, \App\Core\Auth::id()
                    );
                }
            } catch (\Throwable $e) {
                error_log('FlightController::store bulk service_ids error: ' . $e->getMessage());
            }
        }

        // ИСПРАВЛЕНО (03.08.2026): раньше после создания рейса ЦОК попадал
        // обратно на общий список — до чек-листа предзаказа нужно было ещё
        // найти свежий рейс и кликнуть в него. Теперь сразу открывается
        // страница созданного рейса.
        $msg = $addedCount > 0
            ? "Рейс создан, предзаказ сохранён ({$addedCount} услуг)."
            : 'Рейс создан. Отметьте нужные услуги и транспорт ниже.';
        // ДОБАВЛЕНО (12.11.2026, раунд 174): не блокирующее предупреждение
        // о стоянке — см. FlightService::createFlight()/findPlannedConflict().
        if (!empty($result['warning'])) {
            $msg .= ' Внимание: ' . $result['warning'];
        }
        View::setFlash('success', $msg);
        $this->redirect('/flights/' . $result['id']);
    }

    public function show(array $params = []): void
    {
        // ИСПРАВЛЕНО (03.10.2026, раунд 65): та же логика, что и в index() —
        // gsm/medical/balance есть в Permissions::$matrix['flights.view'],
        // но не были добавлены сюда.
        $this->requireRole(['admin', 'cok', 'sop', 'spo', 'sst', 'aero', 'spasop', 'commercial', 'finance', 'supervisor', 'gsm', 'medical', 'balance']);
        $flight = $this->flightModel->findWithDetails((int)$params['id']);
        if (!$flight) {
            View::setFlash('error', 'Flight not found.');
            $this->redirect('/flights');
            return;
        }

        // ДОБАВЛЕНО (10.11.2026, раунд 167, по жалобе пользователя): карточка
        // рейса показывала "Время установки/уборки колодок" всегда как "—",
        // хотя колодки по факту фиксируются супервайзером через чек-лист
        // (заказ услуги CHOCKS, started_at/completed_at) практически всегда.
        // Причина — resources/views/flights/show.php читало это значение
        // НАПРЯМУЮ из flights.chocks_on/chocks_off (ручные поля карточки
        // рейса), которые почти никогда не заполняются вручную (см. round 84:
        // по факту время колодок идёт через заказ CHOCKS, не через ручной
        // ввод). Та же проблема уже была решена для печатной Формы А
        // (ActController::buildPrintData()) и для автоначислений
        // (AutoChargeService::resolveChocksOnDateTime()/
        // resolveChocksOffDateTime()) — здесь применяем тот же приоритет
        // источников: сначала подтверждённый заказ CHOCKS, ручное поле — как
        // запасной вариант, если заказа ещё нет.
        $chocksOrder = (new FlightServiceOrder())->findByFlightAndServiceCode((int)$flight['id'], 'CHOCKS');
        if ($chocksOrder) {
            if (($flight['operation_type'] ?? '') === 'arrival' && !empty($chocksOrder['started_at'])) {
                $flight['chocks_on'] = substr($chocksOrder['started_at'], 11, 5);
            } elseif (($flight['operation_type'] ?? '') === 'departure' && !empty($chocksOrder['completed_at'])) {
                $flight['chocks_off'] = substr($chocksOrder['completed_at'], 11, 5);
            }
        }

        // Контроль сроков документооборота
        $deadlineService  = new DeadlineService();
        $flightDocModel   = new FlightDocument();
        $flightDevModel   = new FlightDeviation();

        $deadlineDocs      = $deadlineService->getFlightStatus((int)$params['id']);
        // ИЗМЕНЕНО (14.08.2026, п.4): не показываем на странице рейса отклонения
        // по типам документов, убранным из контроля сроков (СПО/ССТ/Аэро,
        // предварительный акт ЦОК, передача в коммерцию) — см. DeadlineService.
        $flightDeviations  = array_values(array_filter(
            $flightDevModel->getByFlight((int)$params['id']),
            fn($d) => DeadlineService::isTrackedDocType($d['doc_type'])
        ));

        // Документы служб
        $techCardModel    = new TechCard();
        $equipLogModel    = new EquipmentLog();
        $parkingActModel  = new ParkingAct();
        $manifestModel    = new PassengerManifest();

        // УПРОЩЕНО (21.09.2026, раунд 49, п.2): первичный (предварительный)
        // манифест убран из процесса — карточка на странице рейса больше
        // его не показывает (см. resources/views/flights/_service_docs.php),
        // поэтому и не запрашиваем.
        $techCard = $equipLog = $parkingAct = $manifestFinal = null;
        try { $techCard      = $techCardModel->getByFlight((int)$params['id']); } catch (\Throwable $e) {}
        try { $equipLog      = $equipLogModel->getByFlight((int)$params['id']); } catch (\Throwable $e) {}
        try { $parkingAct    = $parkingActModel->getByFlight((int)$params['id']); } catch (\Throwable $e) {}
        try { $manifestFinal = $manifestModel->getByFlightAndType((int)$params['id'], 'final'); } catch (\Throwable $e) {}

        // Услуги, накопленные службами по рейсу (для Формы А)
        $chargeModel = new \App\Models\FlightServiceCharge();
        $allCharges  = [];
        try { $allCharges = $chargeModel->getAllByFlight((int)$params['id']); } catch (\Throwable $e) {}

        // Уже существующий акт по этому рейсу (один рейс = один акт)
        $actModel    = new \App\Models\Act();
        $existingAct = null;
        try { $existingAct = $actModel->findByFlightId((int)$params['id']); } catch (\Throwable $e) {}

        // ДОБАВЛЕНО (аудит 10.07.2026): предзаказ услуг/транспорта ЦОК —
        // полный список услуг, доступных для этого рейса (по route_type/
        // operation_type/MTOW), сгруппированный по службе-владельцу, плюс
        // то, что уже отмечено в плане.
        $checklistServices = [];
        $plannedServiceIds = [];
        try {
            $orderModel  = new FlightServiceOrder();
            $tariffModel = new TariffVersion();
            $allAvailable = $tariffModel->getServicesWithActiveTariff(
                $flight['route_type'],
                $flight['operation_type'],
                $flight['flight_date'],
                null, // без фильтра по роли — нужны услуги всех служб сразу
                $flight['aircraft_mtow'] !== null ? (float)$flight['aircraft_mtow'] : null
            );
            foreach ($allAvailable as $svc) {
                $role = $svc['owner_role'] ?: 'other';
                $checklistServices[$role][] = $svc;
            }
            $currentOrders = $orderModel->getByFlightWithFact((int)$params['id']);
            foreach ($currentOrders as $o) {
                $plannedServiceIds[] = (int)$o['service_id'];
            }
        } catch (\Throwable $e) {
            $currentOrders = [];
        }

        // ДОБАВЛЕНО (раунд 33, 09.09.2026): связка рейса прилёта и рейса
        // вылета одного и того же борта (одна стоянка/визит ВС) — см.
        // App\Services\FlightService::linkFlights(). Если рейс уже связан —
        // подтягиваем краткие данные другого плеча и его акт; если ещё
        // не связан — подбираем кандидатов на привязку (тот же борт,
        // противоположный тип рейса, ещё не привязан ни к чему).
        $linkedFlight   = null;
        $linkCandidates = [];
        try {
            $withLinked = $this->flightModel->findWithLinked((int)$params['id']);
            if ($withLinked && !empty($withLinked['linked_flight_id'])) {
                $linkedFlight = $withLinked;
            } elseif (!empty($flight['aircraft_registration'])) {
                $linkCandidates = $this->flightModel->findLinkCandidates(
                    $flight['aircraft_registration'],
                    $flight['operation_type'],
                    $flight['flight_date'],
                    (int)$flight['id']
                );
            }
        } catch (\Throwable $e) {
            error_log('FlightController::show link lookup failed: ' . $e->getMessage());
        }

        // ДОБАВЛЕНО (21.10.2026, раунд 109, по прямому указанию пользователя):
        // подсказка оператору, когда у рейса НЕТ бортового номера (ровно
        // случай рейса #45, round105, где регистрация отсутствовала 8 дней)
        // — какой борт последним по времени занимал ЭТУ ЖЕ стоянку
        // (mc_number). ТОЛЬКО подсказка, не автоматическая связка — см.
        // докблок Flight::findLastFlightOnStand(): стоянки переиспользуются
        // разными бортами, сама по себе занятость стоянки не доказывает,
        // что это тот же борт.
        $standHint = null;
        if (empty($flight['aircraft_registration']) && !empty($flight['mc_number'])) {
            try {
                $standHint = $this->flightModel->findLastFlightOnStand(
                    $flight['mc_number'],
                    $flight['flight_date'],
                    $flight['scheduled_time'] ?? null,
                    (int)$flight['id']
                );
            } catch (\Throwable $e) {
                error_log('FlightController::show stand hint lookup failed: ' . $e->getMessage());
            }
        }

        $this->render('flights/show', [
            'title'             => 'Рейс: ' . $flight['flight_number'],
            'flight'            => $flight,
            'deadlineDocs'      => $deadlineDocs,
            'flightDeviations'  => $flightDeviations,
            'techCard'          => $techCard,
            'equipLog'          => $equipLog,
            'parkingAct'        => $parkingAct,
            'manifestFinal'     => $manifestFinal,
            'allCharges'        => $allCharges,
            'existingAct'       => $existingAct,
            'checklistServices' => $checklistServices,
            'plannedServiceIds' => $plannedServiceIds,
            'currentOrders'     => $currentOrders,
            'linkedFlight'      => $linkedFlight,
            'linkCandidates'    => $linkCandidates,
            'standHint'         => $standHint,
        ]);
    }

    // УБРАНО (14.09.2026, раунд 40, по прямому указанию пользователя):
    // ручное связывание рейса прилёта/вылета через выбор из выпадающего
    // списка (был метод link(), маршрут POST /flights/{id}/link — см.
    // routes/web.php). Пользователь справедливо указал: "самолёт не может
    // между прилётом и вылетом сменить... регистрационный номер" — то есть
    // подбор пары полностью детерминирован (тот же борт, противоположная
    // операция) и не требует участия человека. Теперь это делает система
    // автоматически при каждом создании/редактировании рейса — см.
    // FlightService::autoLinkFlight(), вызывается из createFlight()/
    // updateFlight(). Метод unlinkFlights()/действие "Отвязать" ниже
    // оставлены — это по-прежнему полезная ручная функция для исправления
    // ошибочной связи (например, опечатка в регистрации была связана раньше,
    // чем её заметили и исправили).

    /**
     * РАУНД 33 (09.09.2026): снять привязку рейса прилёта/вылета.
     */
    // ВОССТАНОВЛЕНО (12.10.2026, раунд 69, по прямому указанию пользователя):
    // ручное связывание было убрано в раунде 40 в пользу полностью
    // автоматического auto-link (FlightService::autoLinkFlight()). Но у
    // авто-подбора слепая зона: Flight::findLinkCandidates() ищет только
    // среди рейсов с linked_flight_id IS NULL — если рейс уже (ошибочно)
    // связан с чем-то другим (например, с неотработанной "заглушкой"),
    // он никогда не попадёт в кандидаты на ПРАВИЛЬНУЮ пару. Именно так
    // возник непарный рейс AXS4 — см.
    // claude/analysis-2026-10-12-round69-flight-pairing-enforcement.md.
    // Восстанавливаем ручное связывание как предохранительный клапан —
    // auto-link остаётся основным механизмом. FlightService::linkFlights()
    // уже сама проверяет совпадение борта/направления и снимает прежнюю
    // связь на любой стороне перед тем как связать заново.
    public function link(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/flights/' . (int)$params['id']);
            return;
        }

        $targetId = (int)$this->request->input('target_flight_id', 0);
        if ($targetId <= 0) {
            View::setFlash('error', 'Укажите ID рейса, с которым нужно связать.');
            $this->redirect('/flights/' . (int)$params['id']);
            return;
        }

        $result = $this->flightService->linkFlights((int)$params['id'], $targetId);
        View::setFlash($result['success'] ? 'success' : 'error',
            $result['success'] ? 'Рейсы связаны.' : $result['error']);
        $this->redirect('/flights/' . (int)$params['id']);
    }

    public function unlink(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/flights/' . (int)$params['id']);
            return;
        }

        $result = $this->flightService->unlinkFlights((int)$params['id']);
        View::setFlash($result['success'] ? 'success' : 'error',
            $result['success'] ? 'Привязка снята.' : $result['error']);
        $this->redirect('/flights/' . (int)$params['id']);
    }

    /**
     * Сформировать Форму А (акт) по рейсу — единственная точка создания акта.
     *
     * Если акта ещё нет — создаёт его и сразу собирает все накопленные
     * услуги служб (один рейс = один акт, см. UNIQUE acts.flight_id).
     * Если акт уже существует и не утверждён — подтягивает новые услуги,
     * добавленные службами после первого формирования (ничего не дублируя,
     * так как уже перенесённые услуги помечаются consumed_in_act_id).
     */
    // УБРАНО (23.09.2026, раунд 55): resolveChocksTime() перенесена в
    // AutoChargeService::getActFormationBlockers() (через уже существующие
    // resolveChocksOnDateTime()/resolveChocksOffDateTime()) — см. её докблок.

    public function buildAct(array $params = []): void
    {
        // ИЗМЕНЕНО (21.09.2026, раунд 49, п.3, по прямому указанию
        // пользователя): "формирование формы А продублировать супервайзеру,
        // чтобы он не ждал формирование от ЦОКа" — супервайзер теперь тоже
        // может сформировать акт (те же обязательные условия — манифест +
        // время, см. $blockingReasons ниже), стоя у борта, не дожидаясь,
        // пока ЦОК откроет рейс в своём кабинете. Акт от этого не становится
        // "супервайзерским" — та же таблица acts, тот же статус 'draft',
        // ЦОК видит и управляет им как обычно (редактирование строк,
        // утверждение — по-прежнему только admin/cok, см. ActController).
        $this->requireRole(['admin', 'cok', 'supervisor']);
        $viewerRole = \App\Core\Auth::user()['role_code'] ?? '';
        $isCokLike  = in_array($viewerRole, ['admin', 'cok'], true);
        $flightId = (int)($params['id'] ?? 0);

        // ДОБАВЛЕНО (21.09.2026, раунд 49, п.3): супервайзер не имеет доступа
        // к /flights, /flights/{id}/edit, /acts/{id} (ЦОК/финансы/коммерция
        // — см. ActController::show()) — все "возвратные" редиректы этого
        // метода для супервайзера ведут на его собственную страницу рейса.
        $fallbackUrl = $isCokLike ? '/flights' : ('/supervisor/flights/' . $flightId);

        $flight = $this->flightModel->find($flightId);
        if (!$flight) {
            View::setFlash('error', 'Рейс не найден.');
            $this->redirect($fallbackUrl);
            return;
        }

        // ДОБАВЛЕНО (23.09.2026, раунд 55, по прямому указанию пользователя):
        // формирование акта — такое же действие супервайзера по рейсу, как
        // тайминг/стоянка/услуги (см. ServiceOrderController::
        // supervisorOwnershipError()) — не должно быть доступно супервайзеру,
        // если рейс уже закреплён за ДРУГИМ супервайзером. ЦОК/админ не
        // ограничены.
        if ($viewerRole === 'supervisor') {
            $claim = $this->flightModel->tryClaimForSupervisor($flightId, (int)\App\Core\Auth::id());
            if (!$claim['allowed']) {
                $ownerName = $claim['owner_name'] ?: ('пользователь #' . $claim['owner_id']);
                View::setFlash('error', 'Рейс сейчас ведёт супервайзер ' . $ownerName
                    . ' — формирование акта недоступно. Если рейс нужно принять, попросите передать его через «Передать смену».');
                $this->redirect($fallbackUrl);
                return;
            }
        }

        // ДОБАВЛЕНО (11.09.2026, раунд 38, по прямому указанию пользователя):
        // жёсткий блок формирования/пересборки акта, если не выполнены
        // обязательные условия. Проверяется ДО любого создания/изменения
        // акта — ни новый акт, ни пересборка существующего черновика не
        // должны проходить, пока список пуст.
        //
        // 1) "Без манифеста ЦОК не должен сформировать акт" — манифест
        //    обязателен ВСЕГДА (даже у рейса без пассажиров — тогда в нём
        //    просто указан 0, но документ всё равно подан, экипаж-то есть).
        //    См. App\Models\PassengerManifest::getEffectiveForBilling() и
        //    App\Services\AutoChargeService::hasSubmittedManifest().
        // 2) Время прилёта/вылета — обязательно ТОЛЬКО само время (ATA/ATD,
        //    flights.arr_time/dep_time), вносимое ЦОК вручную на странице
        //    рейса. Раньше в акте подменялись временем услуги LEAD (см.
        //    правку, убранную в этом же раунде в ActController::printForm()).
        //    Время установки/снятия колодок — привязка к заказу услуги
        //    CHOCKS СОХРАНЕНА (см. printForm()), поэтому здесь проверяем
        //    результат ПО ТОЙ ЖЕ логике: либо заказ CHOCKS подтверждён
        //    супервайзером, либо заполнено запасное ручное поле рейса — см.
        //    resolveChocksTime() ниже. Всё это обязательно для формирования акта.
        //
        // ИСПРАВЛЕНО (14.09.2026, по итогам разбора рейса flights/11):
        // отдельные поля "Дата прилёта"/"Дата вылета" (arr_date/dep_date)
        // раньше ТОЖЕ требовались этим гейтом наравне со временем — но
        // выяснилось, что ни один рейс в проде их никогда не заполняет
        // (ЦОК вносит только время, дату отдельно не трогает — это и
        // заблокировало формирование акта по рейсу 11, хотя время вылета
        // было указано). Печатная форма (resources/views/acts/print.php,
        // $arrDate/$depDate) уже ДАВНО и осознанно подставляет flight_date
        // самого рейса как запасной вариант, когда arr_date/dep_date пусты
        // (см. её комментарий, раунд 8) — то есть отдельная дата ATA/ATD
        // нужна лишь для редкого случая, когда факт. прилёт/вылет пересекает
        // полночь относительно flight_date, а в остальном полностью
        // дублирует уже известную дату рейса. По решению пользователя гейт
        // приведён к тому же правилу: дата отдельно не проверяется (поле в
        // форме рейса остаётся — на случай, когда ЦОК всё же нужно её явно
        // переопределить), обязательно только время.
        // ИЗМЕНЕНО (23.09.2026, раунд 55): сама проверка вынесена в
        // AutoChargeService::getActFormationBlockers() (раньше жила только
        // здесь, инлайн) — чтобы ActController::resync() ("Обновить" на
        // странице акта) тоже мог её применить и не пересчитывал
        // обязательные сборы по рейсу, у которого нет манифеста/времени/
        // колодок (см. её докблок).
        $autoChargeGate  = new \App\Services\AutoChargeService();
        $blockingReasons = $autoChargeGate->getActFormationBlockers($flight);

        if (!empty($blockingReasons)) {
            View::setFlash('error', 'Форма А не сформирована — '
                . implode('; ', $blockingReasons) . '. Заполните и повторите.');
            // Супервайзеру некуда идти на /flights/{id}/edit (только ЦОК/админ) —
            // время ATA/ATD и МС№ он и так правит прямо со своей страницы рейса.
            $this->redirect($isCokLike ? "/flights/{$flightId}/edit" : $fallbackUrl);
            return;
        }

        $actModel   = new \App\Models\Act();
        $actService = new ActService();

        $existing = $actModel->findByFlightId($flightId);

        if ($existing) {
            if (in_array($existing['status'], ['approved', 'cancelled', 'exported'], true)) {
                View::setFlash('error', 'Акт по этому рейсу уже утверждён/закрыт — формирование недоступно.');
                // /acts/{id} супервайзеру недоступен (ЦОК/финансы/коммерция) —
                // ему в этом статусе делать тут больше нечего, акт уже закрыт.
                $this->redirect($isCokLike ? ('/acts/' . $existing['id']) : $fallbackUrl);
                return;
            }
            $actId = (int)$existing['id'];

            // Обновляем cb_rate если передан вручную или получаем с ЦБ
            if ($flight['route_type'] === 'domestic') {
                $manualRate = (float)$this->request->input('cb_rate', 0);
                if ($manualRate > 1) {
                    $actModel->update($actId, ['cb_rate' => $manualRate]);
                } elseif ((float)($existing['cb_rate'] ?? 0) <= 1.0) {
                    // Пробуем ЦБ, но не блокируем если недоступен
                    try {
                        $cbu = new \App\Services\CbuExchangeRateService();
                        $res = $cbu->getRateForDate($flight['flight_date'] ?? date('Y-m-d'));
                        if ($res && $res['rate'] > 1) {
                            $actModel->update($actId, ['cb_rate' => $res['rate']]);
                        }
                    } catch (\Throwable $e) {}
                }
            }
        } else {
            // Новый акт
            $cbRate = null;
            $manualRate = (float)$this->request->input('cb_rate', 0);

            if ($flight['route_type'] === 'domestic') {
                if ($manualRate > 1) {
                    $cbRate = $manualRate;
                } else {
                    // Пробуем ЦБ
                    try {
                        $cbu    = new \App\Services\CbuExchangeRateService();
                        $result = $cbu->getRateForDate($flight['flight_date'] ?? date('Y-m-d'));
                        if ($result && $result['rate'] > 1) {
                            $cbRate = $result['rate'];
                        }
                    } catch (\Throwable $e) {}
                }
                // Если не получили — используем 1 и предупреждаем
                if (!$cbRate) {
                    $cbRate = 1;
                    View::setFlash('warning', 'Курс ЦБ не получен (cbu.uz недоступен). Укажите курс USD/UZS в редактировании акта.');
                }
            }

            $createResult = $actService->createAct([
                'flight_id'              => $flightId,
                'route_type'             => $flight['route_type'],
                'operation_type'         => $flight['operation_type'],
                // Для domestic валюта определится автоматически как UZS (см. resolveSettlementCurrency).
                // Для international по умолчанию USD — стандарт международных расчётов в системе.
                'settlement_currency_id' => 2,
                'cb_rate'                => $cbRate,
            ]);
            if (!$createResult['success']) {
                View::setFlash('error', 'Не удалось создать акт: ' . $createResult['error']);
                $this->redirect("/flights/{$flightId}");
                return;
            }
            $actId = (int)$createResult['id'];
        }

        // Если акт уже существовал — сбрасываем consumed_in_act_id у авто-записей
        // (они могли быть помечены consumed но не попасть в act_items из-за ошибки).
        // Также удаляем строки акта чтобы пересобрать с чистого листа.
        if ($existing) {
            $db = \App\Core\Database::getInstance();
            // Сбрасываем consumed для авто-начислений этого рейса
            $db->prepare(
                "UPDATE flight_service_charges
                 SET consumed_in_act_id = NULL
                 WHERE flight_id = ? AND is_auto = 1 AND consumed_in_act_id = ?"
            )->execute([$flightId, $actId]);
            // Сбрасываем consumed для ручных записей служб (не вошедших в act_items)
            $db->prepare(
                "UPDATE flight_service_charges fsc
                 SET fsc.consumed_in_act_id = NULL
                 WHERE fsc.flight_id = ?
                   AND fsc.consumed_in_act_id = ?
                   AND NOT EXISTS (
                       SELECT 1 FROM act_items ai WHERE ai.act_id = ? LIMIT 1
                   )"
            )->execute([$flightId, $actId, $actId]);
        }

        // Сбрасываем consumed у записей которые помечены но не попали в act_items
        // Это гарантирует что buildFromFlightCharges найдёт их повторно
        try {
            $db = \App\Core\Database::getInstance();
            $db->prepare(
                "UPDATE flight_service_charges fsc
                 SET fsc.consumed_in_act_id = NULL
                 WHERE fsc.flight_id = ?
                   AND fsc.consumed_in_act_id = ?
                   AND NOT EXISTS (
                       SELECT 1 FROM act_items ai
                       WHERE ai.act_id = ?
                         AND ai.service_id = fsc.service_id
                   )"
            )->execute([$flightId, $actId, $actId]);
        } catch (\Throwable $e) {
            error_log('buildAct: reset consumed failed: ' . $e->getMessage());
        }

        // ДОБАВЛЕНО (14.08.2026, раунд 2, п.4): пересчитываем обязательные
        // основные услуги (TLF/AV_SEC/МЕТЕО) при каждой сборке акта.
        //
        // ИЗМЕНЕНО (17.09.2026, раунд 44, по прямому указанию пользователя):
        // раньше NIGHT/OVER_PARK считались только если Аэродромная служба
        // отдельно подавала форму «Акт готовности стоянки ВС»
        // (parking_acts.status='submitted') — на практике эта форма почти
        // никогда не заполняется (см. аудит раунда 42), из-за чего
        // OVER_PARK физически не мог начислиться. processParkingAct() теперь
        // сам находит время стоянки по колодкам (started_at/completed_at
        // услуги CHOCKS или ручные поля рейса) и не принимает параметров —
        // вызываем его БЕЗУСЛОВНО, без проверки parking_acts, точно так же,
        // как processFlightMandatoryCharges() (который он вызывает сам первым
        // шагом внутри себя).
        $mandatoryWarnings = [];
        try {
            $autoCharge = new \App\Services\AutoChargeService();
            $mandatoryWarnings = $autoCharge->processParkingAct($flightId);
        } catch (\Throwable $e) {
            error_log('buildAct: mandatory auto-charges failed: ' . $e->getMessage());
            $mandatoryWarnings[] = 'Автоматический расчёт обязательных сборов не выполнен: ' . $e->getMessage();
        }

        // ДОБАВЛЕНО (14.08.2026, раунд 5): количество тех.персонала
        // спецтехники (TECH_STAFF) теперь тоже считается автоматически при
        // каждой сборке акта — по числу уже оказанных услуг-единиц техники
        // по рейсу (см. AutoChargeService::syncTechStaffCount() и список
        // TECH_VEHICLE_CODES). Больше не требует отдельного подтверждения от
        // ССТ через Журнал спецтехники.
        try {
            (new \App\Services\AutoChargeService())->syncTechStaffCount($flightId);
        } catch (\Throwable $e) {
            error_log('buildAct: tech staff sync failed: ' . $e->getMessage());
            $mandatoryWarnings[] = 'Авторасчёт тех.персонала спецтехники не выполнен: ' . $e->getMessage();
        }

        $buildResult = $actService->buildFromFlightCharges($actId, $flightId);

        $added = $buildResult['added'] ?? 0;
        $addedNames = $buildResult['added_names'] ?? [];
        $skipped    = $buildResult['skipped'] ?? [];
        // ИЗМЕНЕНО (14.08.2026, раунд 2, п.4): $mandatoryWarnings добавлены к
        // предупреждениям сборки акта — раньше вызовы setFlash('warning', ...)
        // по очереди затирали друг друга (сессия хранит одно сообщение на
        // тип), теперь все предупреждения собираются в одно сообщение.
        $warnings   = array_merge($mandatoryWarnings, $buildResult['warnings'] ?? []);

        if ($added > 0) {
            $msg = "Форма А сформирована. Добавлено услуг: {$added}";
            if (!empty($addedNames)) {
                $msg .= ' (' . implode(', ', $addedNames) . ')';
            }
            View::setFlash('success', $msg . '.');
        } else {
            View::setFlash('info', 'Новых услуг от служб не найдено — все уже включены или ни одна служба не добавляла услуги.');
        }
        if (!empty($skipped)) {
            View::setFlash('warning', 'Не добавлены: ' . implode('; ', $skipped));
        }
        if (!empty($warnings)) {
            View::setFlash('warning', implode(' ', $warnings));
        }

        // ДОБАВЛЕНО (21.09.2026, раунд 49, п.1+п.3): фиксируем в истории
        // рейса, кто сформировал акт — особенно важно, когда это сделал
        // супервайзер, а не ЦОК (см. п.3 этого раунда).
        \App\Core\ActivityLogger::log('flight', $flightId, 'act_build', 'success',
            ($isCokLike ? 'ЦОК сформировал(а)' : 'Супервайзер сформировал(а)') . ' акт Формы А (добавлено услуг: ' . $added . ').');

        // ЦОК/админ — как раньше, сразу в редактирование акта (строки,
        // валюта и т.п.). Супервайзеру эта страница недоступна (см.
        // ActController::edit(), только admin/cok) — его возвращает на
        // страницу рейса, откуда сразу видна кнопка "Подписать акт" (см.
        // ServiceOrderController::supervisorFlight(), $canSign).
        $this->redirect($isCokLike ? ('/acts/' . $actId . '/edit') : ('/supervisor/flights/' . $flightId));
    }

    public function initDeadlines(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        $flightId = (int)($params['id'] ?? 0);
        $service  = new DeadlineService();
        $result   = $service->initForFlight($flightId);
        $this->json(['success' => $result]);
    }

    public function edit(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        $flight = $this->flightModel->findWithDetails((int)$params['id']);
        if (!$flight) {
            View::setFlash('error', 'Flight not found.');
            $this->redirect('/flights');
            return;
        }

        // ДОБАВЛЕНО (10.11.2026, раунд 165, по жалобе пользователя — живой
        // случай рейса #311: создан импортом без регистрации и стоянки, ЦОК
        // вводит регистрацию на форме редактирования — а список стоянок ниже
        // по-прежнему исключает "свою" стоянку, потому что вычисляется по
        // СТАРОЙ (ещё пустой в базе) регистрации, и сохранить нельзя, пока
        // стоянка (обязательное поле, раунд 53) не выбрана — замкнутый круг.
        // Раньше update() при такой ошибке просто редиректил сюда же, и ВСЁ
        // только что введённое (включая сам борт) терялось — edit() заново
        // читал рейс из базы с нуля. Теперь update() сохраняет отправленные
        // данные (View::setOldInput()) перед редиректом, а здесь мы
        // накладываем их поверх данных из базы — форма при повторном показе
        // видит именно то, что ЦОК только что ввёл, а список стоянок ниже
        // (который читает $flight['aircraft_registration']) пересчитывается
        // уже с учётом этого борта — "своя" стоянка в нём снова появляется.
        $oldInput = View::getOldInput();
        if (!empty($oldInput)) {
            $flight = array_merge($flight, $oldInput);
        }

        // ДОБАВЛЕНО (аудит 09.07.2026, п.1): если по рейсу уже есть утверждённый/
        // отменённый/экспортированный акт — редактирование данных рейса запрещено,
        // чтобы не расходиться с данными, уже зафиксированными в подписанном акте.
        $lockStatus = (new \App\Models\Act())->getLockStatus((int)$params['id']);
        if ($lockStatus) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/flights/' . $params['id']);
            return;
        }

        $airlines = $this->airlineModel->getAllActive();
        $airports = $this->airportModel->getAllActive();

        // ДОБАВЛЕНО (03.08.2026, round 9): текущий план по рейсу — чтобы на
        // форме редактирования чек-лист сразу показывал уже отмеченное,
        // а не только новый список с нуля.
        $currentOrders = [];
        try {
            $currentOrders = (new \App\Models\FlightServiceOrder())->getByFlightWithFact((int)$params['id']);
        } catch (\Throwable $e) {}

        // ДОБАВЛЕНО (18.08.2026, раунд 16): список стоянок для выпадающего
        // списка МС№ — см. App\Models\ParkingStand.
        // ИЗМЕНЕНО (18.09.2026, раунд 48, по прямому указанию пользователя):
        // занятые чужими бортами стоянки вообще не предлагаются в списке —
        // см. ParkingStandService::getAvailableStands(). Своя же текущая
        // стоянка (тот же aircraft_registration) из списка не пропадает.
        $parkingStands = [];
        try {
            $parkingStands = (new \App\Services\ParkingStandService())->getAvailableStands(
                (new ParkingStand())->getAllActive(),
                ['aircraft_registration' => $flight['aircraft_registration'] ?? null],
                (int)$params['id']
            );
        } catch (\Throwable $e) {}

        // ДОБАВЛЕНО (27.10.2026, раунд 129): нужно знать на форме, можно ли
        // вообще предложить кнопку "Отменить рейс" — см. докблок
        // FlightService::cancelFlight() (отмена запрещена, если по рейсу
        // уже есть хоть какой-то акт, даже черновик).
        $existingActForCancel = null;
        try {
            $existingActForCancel = (new \App\Models\Act())->findByFlightId((int)$params['id']);
        } catch (\Throwable $e) {}

        $this->render('flights/edit', [
            'title'         => 'Edit Flight',
            'flight'        => $flight,
            'airlines'      => $airlines,
            'airports'      => $airports,
            'currentOrders' => $currentOrders,
            'homeAirportId' => $this->getHomeAirportId(),
            'parkingStands' => $parkingStands,
            'existingAct'   => $existingActForCancel,
        ]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        // Защита от прямого POST в обход формы (см. edit() выше)
        $lockStatus = (new \App\Models\Act())->getLockStatus((int)$params['id']);
        if ($lockStatus) {
            View::setFlash('warning', __('FLIGHT_LOCKED_BY_ACT', [
                ':status' => __('ACTS_STATUS_' . strtoupper($lockStatus)),
            ]));
            $this->redirect('/flights/' . $params['id']);
            return;
        }

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/flights/' . $params['id'] . '/edit');
            return;
        }

        $data = $this->request->all();

        // ДОБАВЛЕНО (23.09.2026, раунд 53): та же проверка, что и в store() —
        // см. комментарий там. Обязательность распространяется и на
        // редактирование, чтобы старый рейс без MTOW/стоянки нельзя было
        // просто пересохранить как есть — придётся заполнить при первом же
        // редактировании.
        $validator = new Validator($data);
        $validator->required('airline_id', 'Airline')
                  ->required('flight_number', 'Flight Number')
                  ->required('flight_date', 'Flight Date')
                  ->required('route_type', 'Route Type')
                  ->required('operation_type', 'Operation Type')
                  ->required('aircraft_mtow', 'MTOW')
                  ->positiveNumber('aircraft_mtow', 'MTOW')
                  // ДОБАВЛЕНО (12.10.2026, раунд 69, по прямому указанию пользователя): без борта рейс никогда не
                  // свяжется в пару (FlightService::autoLinkFlight() ищет только по aircraft_registration) — см.
                  // claude/analysis-2026-10-12-round69-flight-pairing-enforcement.md.
                  ->required('aircraft_registration', 'Рег. номер ВС')
                  ->required('mc_number', 'Стоянка (MC№)')
                  ->date('flight_date', 'Flight Date')
                  ->inList('route_type', ['domestic', 'international'], 'Route Type')
                  ->inList('operation_type', ['arrival', 'departure'], 'Operation Type');

        if ($validator->fails()) {
            // ДОБАВЛЕНО (10.11.2026, раунд 165) — см. докблок View::setOldInput().
            View::setOldInput($data);
            View::setFlash('error', $validator->firstError());
            $this->redirect('/flights/' . $params['id'] . '/edit');
            return;
        }

        $result = $this->flightService->updateFlight((int)$params['id'], $data);
        if (!$result['success']) {
            View::setOldInput($data);
            View::setFlash('error', $result['error']);
            $this->redirect('/flights/' . $params['id'] . '/edit');
            return;
        }

        // ДОБАВЛЕНО (03.08.2026, round 9): тот же чек-лист услуг теперь и на
        // форме редактирования — используем ту же общую логику, что и на
        // отдельной странице рейса (ServiceOrderController::save()), через
        // FlightServiceOrder::syncPlan(). Позиции, которые супервайзер уже
        // начал/завершил, не трогаются, даже если галочку сняли.
        $checkedServiceIds = array_map('intval', (array)$this->request->input('service_ids', []));
        $syncMsg = '';
        try {
            $sync = (new \App\Models\FlightServiceOrder())
                ->syncPlan((int)$params['id'], $checkedServiceIds, \App\Core\Auth::id());
            if ($sync['added'] || $sync['removed']) {
                $syncMsg = " Предзаказ: добавлено {$sync['added']}, снято {$sync['removed']}.";
            }
            if ($sync['skipped'] > 0) {
                $syncMsg .= " {$sync['skipped']} позиций уже в работе супервайзера — не тронуты.";
            }
            // ДОБАВЛЕНО (06.08.2026): уведомляем службы только по позициям,
            // добавленным именно сейчас (не по всему списку заново).
            if (!empty($sync['added_service_ids'])) {
                (new NotificationService())->notifyPreorderCreated(
                    (int)$params['id'], $sync['added_service_ids'], \App\Core\Auth::id()
                );
            }
        } catch (\Throwable $e) {
            error_log('FlightController::update syncPlan error: ' . $e->getMessage());
        }

        // ДОБАВЛЕНО (12.11.2026, раунд 174): не блокирующее предупреждение
        // о стоянке — см. FlightService::updateFlight()/findPlannedConflict().
        $standWarningMsg = !empty($result['warning']) ? (' Внимание: ' . $result['warning']) : '';
        View::setFlash('success', 'Рейс обновлён.' . $syncMsg . $standWarningMsg);
        $this->redirect('/flights/' . $params['id']);
    }

    /**
     * POST /flights/{id}/cancel
     *
     * ДОБАВЛЕНО (27.10.2026, раунд 129, по прямому указанию пользователя):
     * механизм отмены рейса, который был создан (обычно по разрешению), но
     * по факту не состоялся — см. докблок FlightService::cancelFlight().
     */
    public function cancel(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/flights/' . $params['id'] . '/edit');
            return;
        }

        $reason = trim((string)$this->request->input('cancellation_reason', ''));
        $result = $this->flightService->cancelFlight(
            (int)$params['id'],
            $reason,
            (int)\App\Core\Auth::id()
        );

        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/flights/' . $params['id'] . '/edit');
            return;
        }

        // ИЗМЕНЕНО (27.10.2026, раунд 129, второе уточнение, по прямому
        // указанию пользователя): связь с парным рейсом больше никогда не
        // снимается при отмене — связанный рейс либо каскадно отменяется
        // вместе (отмена прилёта), либо остаётся как есть, связь сохранена
        // (отмена вылета). Дата/время самого отменённого рейса очищены —
        // см. докблок FlightService::performFlightCancel().
        $msg = 'Рейс отменён, дата/время вылета очищены — используйте кнопку '
            . '«Возобновить рейс», когда станут известны актуальные дата и время.';
        if (!empty($result['cascade_cancelled_id'])) {
            $msg .= " Связанный рейс вылета #{$result['cascade_cancelled_id']} той же ротации "
                . 'отменён автоматически вместе с прилётом (та же причина).';
        } elseif (!empty($result['linked_flight_id'])) {
            $msg .= " Связь с рейсом #{$result['linked_flight_id']} той же ротации сохранена — "
                . 'стоянка продолжает считаться занятой.';
        }
        View::setFlash('success', $msg);
        $this->redirect('/flights/' . $params['id']);
    }

    /**
     * POST /flights/{id}/resume
     *
     * ДОБАВЛЕНО (27.10.2026, раунд 129, второе уточнение, по прямому
     * указанию пользователя): "появляется кнопка возобновить рейс, при
     * нажатии которого требуется ввести актуальную дату и время рейса" —
     * см. докблок FlightService::resumeFlight().
     */
    public function resume(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/flights/' . $params['id'] . '/edit');
            return;
        }

        $newDate = trim((string)$this->request->input('resume_date', ''));
        $newTime = trim((string)$this->request->input('resume_time', ''));
        $result  = $this->flightService->resumeFlight(
            (int)$params['id'],
            $newDate,
            $newTime,
            (int)\App\Core\Auth::id()
        );

        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/flights/' . $params['id'] . '/edit');
            return;
        }

        View::setFlash('success', 'Рейс возобновлён: ' . $newDate . ' ' . $newTime . '.');
        $this->redirect('/flights/' . $params['id']);
    }

    // ================================================================
    // Импорт рейсов из разрешений ГА (3-шаговый wizard)
    // ================================================================

    /**
     * GET /flights/import
     * Показывает страницу импорта
     */
    public function importPage(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        $this->render('flights/import', [
            'title'      => 'Создание рейсов из разрешения',
            'csrf_token' => Csrf::token(),
        ]);
    }

    /**
     * POST /flights/import/parse  (AJAX)
     * Парсит текст разрешения, возвращает обогащённые данные рейсов
     */
    public function importParse(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        ob_start(); // буферизуем любой вывод PHP (warnings и т.д.)
        header('Content-Type: application/json');

        try {
        $text = trim($this->request->input('permission_text', ''));
        if (strlen($text) < 20) {
            echo json_encode(['success' => false, 'error' => 'Вставьте текст разрешения (минимум 20 символов)']);
            return;
        }

        // ДОБАВЛЕНО (20.10.2026, раунд 103, по прямому указанию пользователя):
        // сохраняем ИСХОДНЫЙ вставленный текст разрешения "как есть", ДО
        // любого парсинга/редактирования — скрытый журнал, видимый только
        // администратору (см. PermitLogController, requireRole(['admin'])
        // строго, нигде в обычном интерфейсе не отображается и не
        // упоминается). Пишем независимо от результата парсинга —
        // неудачный/пустой разбор тоже значим для контроля. Привязка к
        // конкретным созданным рейсам (permit_raw_log_flights) — в
        // importConfirm() ниже, по возвращённому отсюда permitLogId.
        $permitLogId = null;
        try {
            $logDb = \App\Core\Database::getInstance();
            $logStmt = $logDb->prepare(
                "INSERT INTO permit_raw_logs (raw_text, imported_by_user_id, ip_address, created_at)
                 VALUES (?, ?, ?, NOW())"
            );
            $logStmt->execute([
                $text,
                $this->currentUser()['id'] ?? null,
                $this->request->ip ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            ]);
            $permitLogId = (int)$logDb->lastInsertId();
        } catch (\Throwable $e) {
            // Журнал не должен ломать обычный импорт — только логируем.
            error_log('permit_raw_logs INSERT error: ' . $e->getMessage());
        }

        $parser     = new \App\Services\PermissionParser();
        $rawFlights = $parser->parse($text);

        // ИЗМЕНЕНО (23.10.2026, раунд 124, по прямому указанию пользователя):
        // добавлен третий формат — внутреннее уведомление ЦОК о планировании
        // (см. PermissionParser::parsePlanningNoticeFormat(), пример MFX-801/802).
        // Проверяем ДО общей CAA/TARMAC-развилки, т.к. у него свой чёткий маркер.
        if (stripos($text, 'PLANIROWANIJA') !== false || stripos($text, 'SOOBQAJU') !== false) {
            $format = 'PLANNING_NOTICE';
        } else {
            $format = (stripos($text, 'CAA UZB') !== false || stripos($text, 'PERM NUM') !== false)
                ? 'CAA_UZB' : 'TARMAC';
        }

        if (empty($rawFlights)) {
            // Диагностика: собираем все ICAO-коды, которые встречаются в тексте,
            // чтобы понять под каким кодом упоминается наш аэропорт
            preg_match_all('/\b([A-Z]{4})\b/', strtoupper($text), $icaoMatches);
            $foundIcao = array_unique($icaoMatches[1] ?? []);
            sort($foundIcao);
            $hint = 'Найдены ICAO-коды в тексте: ' . (empty($foundIcao) ? 'нет' : implode(', ', $foundIcao));
            echo json_encode([
                'success' => false,
                'error'   => 'Рейсы для UZTP не найдены в тексте разрешения. ' . $hint
            ]);
            return;
        }

        $db      = \App\Core\Database::getInstance();
        $flights = [];

        foreach ($rawFlights as $f) {
            // ИСПРАВЛЕНО (23.10.2026, раунд 118): $warnings не инициализировался
            // перед циклом и не сбрасывался на каждой итерации — массив
            // накапливался от рейса к рейсу (каждый следующий рейс в партии
            // показывал ЧУЖИЕ предупреждения вперемешку со своими, плюс
            // PHP Warning "Undefined variable $warnings" на первой итерации).
            // Нашёл в логах апача (повторяется на каждом /flights/import).
            $warnings = [];
            $isArr      = ($f['direction'] ?? 'ARR') === 'ARR';
            $originIcao = $isArr ? ($f['other_airport'] ?? '') : 'UZTP';
            $destIcao   = $isArr ? 'UZTP' : ($f['other_airport'] ?? '');

            // Собираем datetime строки.
            // ИСПРАВЛЕНО (17.08.2026): разрешения ЦАА дают время в Zulu/UTC
            // (суффикс "Z" — ETD/ETA), а система работает по времени
            // Ташкента (UTC+5, см. date_default_timezone_set() в
            // public/index.php) — именно с ним потом сравнивается контроль
            // сроков документооборота (DeadlineService, см. round 8). Раньше
            // время из разрешения записывалось "как есть", без конвертации —
            // расхождение на 5 часов, а для рейсов поздно вечером по Zulu
            // (после ~19:00Z) дата в Ташкенте уже СЛЕДУЮЩИЕ сутки — календарная
            // дата рейса тоже могла определиться неверно. Конвертируем через
            // DateTime, чтобы дата и время, показанные ЦОК на шаге проверки и
            // сохранённые в рейс, были уже в местном времени Ташкента.
            $dt = ($f['date'] ?? '') . ' ' . ($f['time_utc'] ?? '00:00') . ':00';
            if (!empty($f['date']) && !empty($f['time_utc'])) {
                try {
                    $utcDt = new \DateTime($f['date'] . ' ' . $f['time_utc'] . ':00', new \DateTimeZone('UTC'));
                    $utcDt->setTimezone(new \DateTimeZone('Asia/Tashkent'));
                    $dt = $utcDt->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {
                    // Не удалось разобрать дату/время — оставляем как есть
                    // (не блокируем импорт из-за одного поля, ЦОК поправит вручную).
                }
            }
            $scheduledDep = $isArr ? null : $dt;
            $scheduledArr = $isArr ? $dt : null;

            // Ищем аэропорты (по ICAO, с запасным вариантом по IATA для
            // 3-буквенных кодов — см. комментарий выше, раунд 124; та же
            // логика, что в findOrCreateAirport() ниже, который реально
            // создаёт/резолвит привязку в момент importConfirm()).
            $stOrig = $db->prepare("SELECT id, name FROM airports WHERE icao_code = ? OR (CHAR_LENGTH(?) = 3 AND iata_code = ?) LIMIT 1");
            $stOrig->execute([$originIcao, $originIcao, $originIcao]); $originAp = $stOrig->fetch(\PDO::FETCH_ASSOC) ?: null;
            $stDest = $db->prepare("SELECT id, name FROM airports WHERE icao_code = ? OR (CHAR_LENGTH(?) = 3 AND iata_code = ?) LIMIT 1");
            $stDest->execute([$destIcao, $destIcao, $destIcao]);   $destAp   = $stDest->fetch(\PDO::FETCH_ASSOC) ?: null;

            // Проверяем дубль.
            // ИСПРАВЛЕНО (21.08.2026): раньше ключом дубля был только
            // (flight_number, date) — без operation_type. Ломалось на
            // сквозных рейсах/техпосадках, где ОДИН И ТОТ ЖЕ позывной
            // используется и для прилёта, и для вылета в один день (напр.
            // KOC22: ETD LTFE...ETA UZTP, затем тем же рейсом ETD UZTP...ETA
            // VRDA — прилёт и вылет с одинаковым номером и датой). Обе ноги
            // считались "дублем" друг друга, хотя это два разных, законных
            // рейса (прилёт и вылет) — вторая нога молча пропускалась.
            // Дубль — это ТА ЖЕ операция (прилёт/вылет) с тем же номером и
            // датой, а не любая нога рейса с совпадающим номером+датой.
            $stmt2 = $db->prepare("SELECT id FROM flights WHERE flight_number = ? AND flight_date = ? AND operation_type = ? LIMIT 1");
            $stmt2->execute([strtoupper($f['call_sign'] ?? ''), $f['date'] ?? '', $isArr ? 'arrival' : 'departure']);
            $dup = $stmt2->fetch(\PDO::FETCH_ASSOC) ?: null;

            // Ищем авиакомпанию в базе
            $dbAirline = null;
            if (!empty($f['operator_icao'])) {
                $s = $db->prepare("SELECT id FROM airlines WHERE icao_code = ? LIMIT 1");
                $s->execute([$f['operator_icao']]);
                $dbAirline = $s->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
            if (!$dbAirline && !empty($f['operator'])) {
                $s = $db->prepare("SELECT id FROM airlines WHERE LOWER(name) LIKE ? LIMIT 1");
                $s->execute(['%' . strtolower($f['operator']) . '%']);
                $dbAirline = $s->fetch(\PDO::FETCH_ASSOC) ?: null;
            }

            // ДОБАВЛЕНО (27.10.2026, раунд 129, по прямому указанию
            // пользователя — живой кейс QQE531/A7CHG: "мтов подставился
            // автоматом, а на вылет не сел, пришлось вносить вручную"):
            // разрешения (особенно формат CAA UZB и внутренние уведомления
            // ЦОК о планировании) почти никогда не содержат MTOW текстом —
            // PermissionParser не извлекает его ни для одного из этих двух
            // форматов (единственное исключение — TARMAC/REVISION, см.
            // parseRevisionFormat()). До этой правки поле 'mtow' просто
            // уходило в предпросмотр пустым, БЕЗ предупреждения (в отличие,
            // например, от пустой регистрации ВС) и без самой возможности
            // его увидеть или вписать — у таблицы предпросмотра вообще не
            // было столбца MTOW (см. resources/views/flights/import.php).
            // ЦОК обнаруживал проблему только значительно позже, уже на
            // странице самого рейса (где это обязательное поле, раунд 53),
            // и был вынужден дозаполнять его руками "задним числом" — хотя
            // для уже ИЗВЕСТНОГО борта (справочник `aircraft`, раунд 117)
            // значение в этот момент уже есть в системе и может быть
            // показано сразу. Здесь — та же подстановка из справочника,
            // что применяется при создании/редактировании рейса
            // (FlightService::createFlight()/updateFlight()), но уже на
            // шаге предпросмотра.
            $previewMtow = null;
            if (!empty($f['mtow'])) {
                $previewMtow = (float)preg_replace('/[^0-9.]/', '', (string)$f['mtow']);
                if ($previewMtow > 1000) $previewMtow = round($previewMtow / 1000, 2);
                if ($previewMtow <= 0) $previewMtow = null;
            }
            if ($previewMtow === null && !empty($f['reg'])) {
                $regLookup = $db->prepare("SELECT mtow FROM aircraft WHERE registration = ? LIMIT 1");
                $regLookup->execute([strtoupper(trim((string)$f['reg']))]);
                $knownMtow = $regLookup->fetchColumn();
                if ($knownMtow !== false && $knownMtow !== null) {
                    $previewMtow = (float)$knownMtow;
                }
            }

            // Предупреждения
            // ДОБАВЛЕНО (12.10.2026, раунд 69): рег. номер ВС — по уточнению
            // пользователя, в разрешении он пишется всегда, так что реально
            // пустым он быть не должен; но регулярки парсера (REG\s*:\s*(\S+)
            // и аналог в CAA-формате, см. PermissionParser) могут не
            // совпасть при небольшом отклонении формата текста разрешения —
            // тогда поле выйдет пустым, хотя в самом документе борт есть.
            // Раньше это проходило НЕЗАМЕЧЕННЫМ (рейс выглядел полностью
            // готовым, "ready: true", и создавался без борта — то есть
            // ровно так, как не должно: без регистрации рейс никогда не
            // свяжется в пару, см. FlightService::autoLinkFlight()).
            // Предупреждение, а не блокировка — ЦОК видит и может вписать
            // борт вручную прямо в таблице предпросмотра перед подтверждением.
            if (empty($f['call_sign']))    $warnings[] = 'Не указан позывной/номер рейса';
            if (empty($f['date']))         $warnings[] = 'Не удалось определить дату';
            if (empty($f['other_airport'])) $warnings[] = 'Не указан второй аэропорт';
            if (empty($f['reg']))          $warnings[] = 'Не распознан рег. номер ВС — без него рейс не свяжется в пару автоматически, впишите вручную';
            // ДОБАВЛЕНО (27.10.2026, раунд 129): см. комментарий у $previewMtow
            // выше — раньше пустой MTOW на шаге предпросмотра проходил
            // полностью незамеченным.
            if ($previewMtow === null)     $warnings[] = 'Не указан MTOW — впишите вручную в графе ВС/MTOW (поле обязательно для расчёта TLF и ночного сбора)';
            if (!$originAp)                $warnings[] = "Аэропорт {$originIcao} не найден в базе";
            if (!$destAp)                  $warnings[] = "Аэропорт {$destIcao} не найден в базе";

            $flights[] = [
                'flight_number'         => strtoupper($f['call_sign'] ?? ''),
                'origin_icao'           => $originIcao,
                'destination_icao'      => $destIcao,
                'origin_name'           => $originAp['name'] ?? '',
                'destination_name'      => $destAp['name']   ?? '',
                'scheduled_departure'   => $scheduledDep,
                'scheduled_arrival'     => $scheduledArr,
                'aircraft_registration' => strtoupper($f['reg'] ?? ''),
                'aircraft_type'         => $f['aircraft_type'] ?? '',
                'operator_name'         => $f['operator']      ?? '',
                'operator_icao'         => $f['operator_icao'] ?? null,
                'pax'                   => $f['pax'],
                'flight_purpose'        => $f['purpose']       ?? 'BUSINESS',
                'direction'             => $f['direction']     ?? 'ARR',
                'perm_num'              => $f['perm_num']      ?? null,
                'mtow'                  => $previewMtow,
                'alt_note'              => $f['alt_note']      ?? null,
                // ДОБАВЛЕНО (22.09.2026, раунд 51): хендлер из строки "ACC
                // AGR WITH ..." — показываем ЦОК на шаге проверки, чтобы не
                // было "невидимой магии"; реальное разрешение/создание
                // записи в справочнике происходит в importConfirm().
                'handler_name'          => $f['handler_name']  ?? null,
                'is_duplicate'          => (bool)$dup,
                'duplicate_id'          => $dup['id']          ?? null,
                'ready'                 => empty($warnings),
                'warnings'              => $warnings,
                'touches_our_airport'   => true,
                'selected'              => !$dup,
                'db_airline_id'         => $dbAirline['id']    ?? null,
            ];
        }

        ob_end_clean();
        echo json_encode(['success' => true, 'data' => ['format' => $format, 'flights' => $flights, 'permit_log_id' => $permitLogId]]);

        } catch (\Throwable $e) {
            $phpOutput = ob_get_clean();
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage() . ' (line ' . $e->getLine() . ')',
                'debug'   => $phpOutput ?: null,
            ]);
        }
    }

    /**
     * POST /flights/import/confirm  (AJAX)
     * Создаёт рейсы из подтверждённых данных
     */
    public function importConfirm(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        header('Content-Type: application/json');

        $flightsJson = $this->request->input('flights_json', '[]');
        $flightsData = json_decode($flightsJson, true);
        // ДОБАВЛЕНО (20.10.2026, раунд 103): id записи скрытого журнала
        // разрешений (см. importParse() выше) — если есть, привязываем к
        // ней каждый реально созданный рейс ниже.
        $permitLogId = (int)$this->request->input('permit_log_id', 0);

        if (!is_array($flightsData) || empty($flightsData)) {
            echo json_encode(['success' => false, 'error' => 'Нет данных для создания рейсов']);
            return;
        }

        $db      = \App\Core\Database::getInstance();
        $created = [];
        $skipped = [];
        $errors  = [];

        foreach ($flightsData as $f) {
            $flightNumber = strtoupper($f['flight_number'] ?? '');
            $flDate       = substr($f['scheduled_departure'] ?? $f['scheduled_arrival'] ?? '', 0, 10);
            // Тип операции — нужен уже здесь, для проверки дубля (см. ниже),
            // не только для createFlight() дальше по коду.
            $operationType = ($f['direction'] ?? 'ARR') === 'ARR' ? 'arrival' : 'departure';

            try {
                // Финальная проверка дубля.
                // ИСПРАВЛЕНО (21.08.2026): та же причина, что и в importParse()
                // выше — ключ дубля дополнен operation_type, иначе прилёт и
                // вылет одним и тем же позывным в один день (сквозной рейс/
                // техпосадка, напр. KOC22 LTFE→UZTP→VRDA) ошибочно считались
                // дублем друг друга, и вторая нога молча пропускалась.
                $stDup = $db->prepare("SELECT id FROM flights WHERE flight_number = ? AND flight_date = ? AND operation_type = ? LIMIT 1");
                $stDup->execute([$flightNumber, $flDate, $operationType]);
                $dup = $stDup->fetch(\PDO::FETCH_ASSOC) ?: null;
                if ($dup) {
                    $skipped[] = ['flight_number' => $flightNumber, 'duplicate_id' => $dup['id']];
                    continue;
                }

                // Находим/создаём аэропорты (см. findOrCreateAirport — ИСПРАВЛЕНО п.3:
                // раньше при отсутствии аэропорта в базе поле молча оставалось NULL)
                $originCreated = $destCreated = false;
                $originAirportId = $this->findOrCreateAirport($f['origin_icao'] ?? '', $originCreated);
                $destAirportId   = $this->findOrCreateAirport($f['destination_icao'] ?? '', $destCreated);
                $airportWarnings = [];
                if (!$originAirportId && !empty($f['origin_icao'])) {
                    $airportWarnings[] = "не удалось определить аэропорт вылета «{$f['origin_icao']}»";
                } elseif ($originCreated) {
                    $airportWarnings[] = "аэропорт «{$f['origin_icao']}» отсутствовал в справочнике — создан черновиком";
                }
                if (!$destAirportId && !empty($f['destination_icao'])) {
                    $airportWarnings[] = "не удалось определить аэропорт прилёта «{$f['destination_icao']}»";
                } elseif ($destCreated) {
                    $airportWarnings[] = "аэропорт «{$f['destination_icao']}» отсутствовал в справочнике — создан черновиком";
                }

                // Авиакомпания: несколько уровней поиска
                $airlineId = null;
                if (!empty($f['db_airline_id'])) {
                    $airlineId = (int)$f['db_airline_id'];
                }
                if (!$airlineId) {
                    $airlineId = $this->findOrCreateAirline(
                        $f['operator_name'] ?? '',
                        $f['operator_icao'] ?? null
                    );
                }
                // УБРАНО (12.10.2026, раунд 69, по прямому указанию пользователя):
                // "в базе есть несколько рейсов которые при создании рейсов
                // получилось так что номер рейса сел в базу как авиакомпания".
                // Здесь была ветка "если operator пустой — создаём авиакомпанию
                // по рег. номеру ВС (или, если и его нет, по номеру рейса)".
                // Проблема: у бизнес-джетов позывной в разрешении часто САМ
                // РАВЕН бортовому номеру (напр. 2PTXC, TCTOZ — см. рейсы
                // flights/5,6,26,27), а имя операторa в тексте разрешения
                // при этом часто не указано вовсе. В результате бортовой
                // номер/позывной утекал в справочник `airlines` КАК БУДТО
                // это реальное название авиакомпании — подтверждено в БД:
                // airlines id 124 (VHVFW), 126 (RA67589), 127 (N100BD),
                // 132 (N706GA), 135 (2PTXC), 140 (TCTOZ) — ни одно из этих
                // "названий" не авиакомпания, а бортовой номер/позывной.
                // Ветка просто убрана: ниже и так есть общий резерв —
                // findOrCreateAirline('Unknown Operator', null) — это и
                // есть правильный результат, когда оператор в разрешении
                // действительно не указан, вместо выдумывания названия
                // авиакомпании из технического идентификатора борта.

                // Тип операции — уже вычислен выше, до проверки дубля.

                // MTOW: пробуем конвертировать строку в число
                $mtow = null;
                if (!empty($f['mtow'])) {
                    $mtow = (float)preg_replace('/[^0-9.]/', '', (string)$f['mtow']);
                    // Если в кг — переводим в тонны
                    if ($mtow > 1000) $mtow = round($mtow / 1000, 2);
                    if ($mtow <= 0) $mtow = null;
                }

                // Примечания: номер разрешения + цель + (если было "TYPE: X
                // OR Y REG: A OR B" в разрешении) заметка про неподтверждённый
                // тип/борт — см. PermissionParser::parseCAAFormat().
                $notes = implode(' | ', array_filter([
                    !empty($f['perm_num'])       ? 'Разр.: ' . $f['perm_num'] : '',
                    !empty($f['flight_purpose'])  ? 'Цель: '  . $f['flight_purpose'] : '',
                    !empty($f['alt_note'])        ? $f['alt_note'] : '',
                ]));

                // ДОБАВЛЕНО (22.09.2026, раунд 51): хендлер из заявки —
                // ищем совпадение в справочнике "Хендлеры" (findOrCreateHandler).
                // ИЗМЕНЕНО (20.10.2026, раунд 101): больше не заводит новую
                // запись сам при отсутствии совпадения — см. докблок метода.
                // Если не нашли — рейс всё равно создаётся, просто без
                // привязанного хендлера, и ниже добавляется предупреждение.
                $resolvedHandlerId = !empty($f['handler_name'])
                    ? $this->findOrCreateHandler((string)$f['handler_name'])
                    : null;
                if (!empty($f['handler_name']) && !$resolvedHandlerId) {
                    $airportWarnings[] = "хендлер «{$f['handler_name']}» не найден в справочнике — рейс создан без привязки, обратитесь в коммерческую службу для добавления/уточнения названия";
                }

                if (!$airlineId) {
                    // Последний резерв: создаём авиакомпанию с именем "Unknown"
                    $airlineId = $this->findOrCreateAirline('Unknown Operator', null);
                }
                if (!$airlineId) {
                    throw new \RuntimeException(
                        "Не удалось создать авиакомпанию. Добавьте её вручную в справочник."
                    );
                }

                // ДОБАВЛЕНО (17.08.2026): раньше scheduled_time вообще не
                // передавался в createFlight() — плановое время STD/STA не
                // сохранялось (хотя разрешение его прямо содержит, ETD/ETA),
                // и как следствие НИ ОДНОМУ рейсу, созданному через импорт,
                // не инициализировался контроль сроков документооборота
                // (FlightService::createFlight() вызывает
                // DeadlineService::initForFlight() только когда scheduled_time
                // не пуст — см. ниже). Берём время из уже сконвертированного
                // в местное (Ташкент) scheduled_departure/scheduled_arrival
                // (см. importParse() выше) — просто часть HH:MM:SS.
                // НЕ трогаем arr_date/arr_time/dep_date/dep_time — это поля
                // ФАКТИЧЕСКОГО времени (ATA/ATD, см. комментарии колонок в
                // БД), которые печатаются в акте формы А как факт оказания
                // услуги; плановое время из разрешения — не то же самое, что
                // подтверждённое фактическое, и не должно попадать в них
                // молча в обход реального подтверждения службой.
                $schedDt       = $f['scheduled_departure'] ?? $f['scheduled_arrival'] ?? null;
                $scheduledTime = $schedDt ? substr($schedDt, 11, 8) : null;

                $result = $this->flightService->createFlight([
                    'airline_id'             => $airlineId,
                    'flight_number'          => $flightNumber,
                    'flight_date'            => $flDate,
                    'route_type'             => 'international',
                    'operation_type'         => $operationType,
                    'origin_airport_id'      => $originAirportId,
                    'destination_airport_id' => $destAirportId,
                    'aircraft_type'          => $f['aircraft_type']         ?? null,
                    'aircraft_mtow'          => $mtow,
                    'aircraft_registration'  => $f['aircraft_registration'] ?? null,
                    'scheduled_time'         => $scheduledTime,
                    'notes'                  => $notes ?: null,
                    'resolved_handler_id'    => $resolvedHandlerId,
                ]);

                // ДОБАВЛЕНО (20.10.2026, раунд 103): привязка созданного
                // рейса к скрытому журналу исходного текста разрешения.
                if ($permitLogId > 0 && !empty($result['id'])) {
                    try {
                        $linkStmt = $db->prepare(
                            "INSERT IGNORE INTO permit_raw_log_flights (permit_raw_log_id, flight_id) VALUES (?, ?)"
                        );
                        $linkStmt->execute([$permitLogId, (int)$result['id']]);
                    } catch (\Throwable $e) {
                        error_log('permit_raw_log_flights INSERT error: ' . $e->getMessage());
                    }
                }

                $created[] = [
                    'flight_number' => $flightNumber,
                    'route'         => ($f['origin_icao'] ?? '') . ' → ' . ($f['destination_icao'] ?? ''),
                    'departure'     => $f['scheduled_departure'] ?? $f['scheduled_arrival'] ?? '',
                    'flight_id'     => $result['id'],
                    'warnings'      => $airportWarnings,
                ];

            } catch (\Exception $e) {
                $errors[] = [
                    'flight_number' => $flightNumber,
                    'error'         => $e->getMessage(),
                ];
            }
        }

        $summary = 'Создано: ' . count($created)
            . ', пропущено (дубли): ' . count($skipped)
            . ', ошибок: ' . count($errors);

        echo json_encode([
            'success' => true,
            'data'    => compact('summary', 'created', 'skipped', 'errors'),
        ]);
    }

    // ================================================================
    // Приватные хелперы
    // ================================================================

    /**
     * ДОБАВЛЕНО (аудит 09.07.2026, п.3): раньше аэропорт только искался
     * (SELECT), но никогда не создавался — если ICAO-код из разрешения не
     * находился в справочнике `airports`, рейс всё равно создавался, но с
     * origin_airport_id / destination_airport_id = NULL, молча. Теперь —
     * по аналогии с findOrCreateAirline — создаём минимальную запись
     * аэропорта с пометкой на дозаполнение, вместо тихой потери данных.
     */
    private function findOrCreateAirport(string $icao, bool &$wasCreated = false): ?int
    {
        $wasCreated = false;
        $icao = strtoupper(trim($icao));
        if ($icao === '') return null;

        $db = \App\Core\Database::getInstance();

        // 1. Ищем по ICAO (4 буквы)
        $s = $db->prepare("SELECT id FROM airports WHERE icao_code = ? LIMIT 1");
        $s->execute([$icao]);
        $row = $s->fetch(\PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];

        // 2. Запасной вариант — вдруг код на самом деле IATA (3 буквы)
        if (strlen($icao) === 3) {
            $s = $db->prepare("SELECT id FROM airports WHERE iata_code = ? LIMIT 1");
            $s->execute([$icao]);
            $row = $s->fetch(\PDO::FETCH_ASSOC);
            if ($row) return (int)$row['id'];
        }

        // 3. Не найден нигде — создаём минимальную запись, чтобы рейс не терял
        // привязку к аэропорту молча. Название явно помечено как черновое —
        // коммерческая/справочная служба должна дозаполнить город/страну.
        try {
            $s = $db->prepare(
                "INSERT INTO airports (icao_code, iata_code, name, is_active, created_at)
                 VALUES (?, NULL, ?, 1, NOW())"
            );
            $placeholderName = "Требует уточнения ({$icao})";
            $s->execute([strlen($icao) === 4 ? $icao : null, $placeholderName]);
            $newId = (int)$db->lastInsertId();
            if ($newId > 0) {
                error_log("findOrCreateAirport: создан черновой аэропорт '{$icao}' (id={$newId}) — требует дозаполнения");
                $wasCreated = true;
                return $newId;
            }
        } catch (\Throwable $e) {
            error_log('findOrCreateAirport INSERT error: ' . $e->getMessage());
        }

        return null;
    }

    private function findOrCreateAirline(string $name, ?string $icao): ?int
    {
        $db = \App\Core\Database::getInstance();

        // ИСПРАВЛЕНО (23.10.2026, раунд 118): нашли в логах апача
        // "findOrCreateAirline INSERT error: ... Data too long for column
        // 'icao_code'" — колонка airlines.icao_code — char(3) (ИКАО-код
        // авиакомпании всегда ровно 3 буквы), а регэксп парсера
        // (PermissionParser::parseCAAFormat(), "ICAO:\s*(\w*)") не
        // ограничивает длину — если в тексте разрешения после "ICAO:"
        // случайно попало что-то длиннее (опечатка/нестандартный формат
        // документа), INSERT падал целиком, а резервная ветка ниже тогда
        // заводила авиакомпанию как "Unknown Operator" — то есть реальное
        // ИМЯ оператора (которое вообще не виновато) тоже терялось. Теперь
        // невалидный ICAO просто игнорируется (как будто его не было),
        // имя оператора используется как обычно.
        if (!empty($icao) && !preg_match('/^[A-Z]{3}$/', strtoupper($icao))) {
            error_log("findOrCreateAirline: невалидный ICAO-код «{$icao}» (ожидались ровно 3 буквы) — игнорируется, используется только имя «{$name}»");
            $icao = null;
        }

        // 1. Ищем по ICAO коду (самый надёжный способ)
        if (!empty($icao)) {
            $s = $db->prepare("SELECT id FROM airlines WHERE icao_code = ? LIMIT 1");
            $s->execute([strtoupper($icao)]);
            $row = $s->fetch(\PDO::FETCH_ASSOC);
            if ($row) return (int)$row['id'];
        }

        // 2. Ищем по названию
        if (!empty($name)) {
            $s = $db->prepare("SELECT id FROM airlines WHERE LOWER(name) LIKE ? LIMIT 1");
            $s->execute(['%' . strtolower($name) . '%']);
            $row = $s->fetch(\PDO::FETCH_ASSOC);
            if ($row) return (int)$row['id'];
        }

        // 3. Создаём новую авиакомпанию
        // Если name пустой — используем icao как имя (технические рейсы часто не имеют названия)
        $insertName = !empty($name) ? $name : ($icao ? 'Unknown (' . strtoupper($icao) . ')' : null);
        if (empty($insertName)) {
            error_log('findOrCreateAirline: both name and icao are empty, cannot create airline');
            return null;
        }

        try {
            $s = $db->prepare("INSERT INTO airlines (name, icao_code, is_active, created_at) VALUES (?, ?, 1, NOW())");
            $s->execute([$insertName, !empty($icao) ? strtoupper($icao) : null]);
            $newId = (int)$db->lastInsertId();
            if ($newId > 0) {
                $this->ensureDefaultParkingNorm($newId);
                return $newId;
            }
        } catch (\Throwable $e) {
            error_log('findOrCreateAirline INSERT error: ' . $e->getMessage());
        }

        // Fallback — ищем только что созданную запись
        $s = $db->prepare("SELECT id FROM airlines WHERE name = ? LIMIT 1");
        $s->execute([$insertName]);
        $row = $s->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $this->ensureDefaultParkingNorm((int)$row['id']);
            return (int)$row['id'];
        }
        return null;
    }

    /**
     * ДОБАВЛЕНО (22.10.2026, раунд 114, по прямому указанию пользователя):
     * авиакомпании, заведённые автоматически через импорт разрешения (этот
     * метод — findOrCreateAirline()), раньше никогда не получали норму
     * бесплатной стоянки (airline_parking_norms) — отсюда и реальный
     * случай DXT9686/AIR MANAGEMENT GROUP (создана именно так), где
     * сверхнормативная стоянка тихо не считалась. См. тот же комментарий в
     * AirlineController::store() — та же логика, тот же дефолт 2.5ч/3.5ч
     * (фактический единый стандарт по всем существующим записям, round86).
     * upsert() сам идемпотентен (не создаёт вторую запись, если норма уже
     * есть — например задана вручную до того, как сюда дошёл код) —
     * безопасно вызывать и из fallback-ветки на гонку вставки.
     */
    private function ensureDefaultParkingNorm(int $airlineId): void
    {
        try {
            (new \App\Models\AirlineParkingNorm())->upsert(
                $airlineId, 2.0, 3.0, 'Авто-норма по умолчанию при создании авиакомпании (раунд 174, обновлено с 2.5/3.5)',
                (int)(\App\Core\Auth::id() ?? 1)
            );
        } catch (\Throwable $e) {
            error_log('ensureDefaultParkingNorm error for airline ' . $airlineId . ': ' . $e->getMessage());
        }
    }

    /**
     * ДОБАВЛЕНО (22.09.2026, раунд 51, по прямому указанию пользователя):
     * хендлер, распознанный из строки заявки "PAYMENT IN A/P ... ACC AGR
     * WITH <хендлер>" (см. PermissionParser) — назначается хендлером ЭТОГО
     * КОНКРЕТНОГО рейса (flights.resolved_handler_id), приоритетнее общей
     * привязки авиакомпания→хендлер из airline_handlers (см. HandlerService::
     * getCandidatesForFlight()). Та же логика поиска-по-имени, что и
     * findOrCreateAirline() выше — сначала ищем существующего хендлера по
     * названию, и только если не нашли — заводим нового черновиком
     * (`handlers.name` уникален, поэтому повторный импорт того же хендлера
     * по разным рейсам не создаёт дублей).
     */
    private function findOrCreateHandler(?string $name): ?int
    {
        $name = trim((string)$name);
        if ($name === '') return null;

        $db = \App\Core\Database::getInstance();

        $s = $db->prepare("SELECT id FROM handlers WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $s->execute([$name]);
        $row = $s->fetch(\PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];

        // ИЗМЕНЕНО (20.10.2026, раунд 101, по прямому указанию пользователя):
        // раньше несовпадение по точному имени "мягко" пыталось найти
        // подстроку (LIKE), а при неудаче ТИХО заводило нового хендлера —
        // это и плодило дубли вида TARMAC / TARMAC OPS / TARMAC-OPS /
        // TARMAC-OPS 12413 / GH TARMAC UMS FUEL (см. round 100). Теперь:
        // 1) сравниваем по нормализованному виду (без пробелов/дефисов/
        //    регистра) — ловит пунктуационные варианты одного и того же
        //    названия ("TARMAC-OPS" == "TARMAC OPS"), но не более далёкие
        //    варианты ("GH TARMAC UMS FUEL" остаётся отдельным именем, это
        //    осознанно — не гадаем);
        // 2) если даже так не нашли — НЕ создаём новую запись сами.
        //    Создание/подтверждение новых хендлеров теперь — зона
        //    ответственности коммерческой службы (у них первичный источник
        //    истины — договор), не автоматики импорта. Рейс при этом всё
        //    равно создаётся без задержки, просто без привязанного
        //    хендлера — предупреждение об этом показывается на экране
        //    подтверждения импорта (см. FlightController::importConfirm()).
        $normalized = static fn(string $v): string => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v) ?? '');
        $target = $normalized($name);
        if ($target !== '') {
            $s = $db->prepare("SELECT id, name FROM handlers WHERE is_active = 1");
            $s->execute();
            foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
                if ($normalized((string)$candidate['name']) === $target) {
                    return (int)$candidate['id'];
                }
            }
        }

        error_log("findOrCreateHandler: хендлер '{$name}' не найден в справочнике — рейс создан БЕЗ автопривязки хендлера, требуется добавить/сопоставить вручную (коммерческая служба)");
        return null;
    }
}