<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\UserController;
use App\Controllers\RoleController;
use App\Controllers\AirlineController;
use App\Controllers\AirportController;
use App\Controllers\FlightController;
use App\Controllers\ServiceCategoryController;
use App\Controllers\ServiceController;
use App\Controllers\TariffController;
use App\Controllers\InspectionController;
use App\Controllers\EmergencyLogController;
use App\Controllers\ServiceOrderController;
use App\Controllers\ActController;
use App\Controllers\ExportController;
use App\Controllers\FileController;
use App\Controllers\ActSignatureController;
use App\Controllers\AuditController;
use App\Controllers\PermitLogController;
use App\Controllers\SettingsController;
use App\Controllers\DeviationController;
use App\Controllers\ManifestController;
use App\Controllers\TechCardController;
use App\Controllers\EquipmentLogController;
use App\Controllers\ParkingActController;
use App\Controllers\ServiceChargeController;
use App\Controllers\AirlineParkingNormController;
use App\Controllers\ParkingStandController;
use App\Controllers\AircraftController;
use App\Controllers\EquipmentTypeController;
use App\Controllers\LangController;
use App\Controllers\NotificationController;
use App\Controllers\PushController;
use App\Controllers\HandlerController;
use App\Controllers\ProfileController;
use App\Controllers\TelegramController;
use App\Controllers\RepresentativeController;
use App\Controllers\HandlerCabinetController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Controllers\Api\AuthController as ApiAuthController;
use App\Controllers\Api\AdminController as ApiAdminController;
use App\Middleware\ApiAuthMiddleware;

// =====================================================
// Auth
// =====================================================
$router->get('/login',  [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login'], [CsrfMiddleware::class]);
$router->get('/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);

// =====================================================
// Language switcher  ← НОВЫЙ МАРШРУТ
// =====================================================

// Справочник спецтехники ССТ
$router->get('/equipment-types',                   [EquipmentTypeController::class, 'index'],   [AuthMiddleware::class]);
$router->post('/equipment-types',                  [EquipmentTypeController::class, 'store'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/equipment-types/{id}/toggle',      [EquipmentTypeController::class, 'toggle'],  [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/equipment-types/{id}/delete',      [EquipmentTypeController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

$router->post('/lang', [LangController::class, 'switch'], [CsrfMiddleware::class]);

// =====================================================
// Dashboard
// =====================================================
$router->get('/dashboard', [DashboardController::class, 'index'], [AuthMiddleware::class]);
$router->get('/',          [DashboardController::class, 'index'], [AuthMiddleware::class]);

// =====================================================
// ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): личный кабинет представителя.
// Доступ проверяется внутри RepresentativeController (роль + флаг
// PORTAL_ENABLED в config/portal.php); AuthMiddleware здесь — только
// "пользователь вообще залогинен", как и для остальных разделов.
// =====================================================
$router->get('/portal',                 [RepresentativeController::class, 'dashboard'],   [AuthMiddleware::class]);
$router->get('/portal/acts/{id}',       [RepresentativeController::class, 'show'],         [AuthMiddleware::class]);
$router->get('/portal/acts/{id}/pdf',   [RepresentativeController::class, 'downloadPdf'],  [AuthMiddleware::class]);

// =====================================================
// ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): личный кабинет хендлера —
// управление своими представителями (см. HandlerCabinetController). Доступ
// проверяется внутри контроллера (роль handler_admin + users.handler_id);
// НЕ под флагом PORTAL_ENABLED, в отличие от /portal выше.
// =====================================================
$router->get('/handler-cabinet',                              [HandlerCabinetController::class, 'dashboard'],     [AuthMiddleware::class]);
$router->get('/handler-cabinet/representatives/create',        [HandlerCabinetController::class, 'create'],        [AuthMiddleware::class]);
$router->post('/handler-cabinet/representatives/create',       [HandlerCabinetController::class, 'store'],         [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/handler-cabinet/representatives/{id}/edit',     [HandlerCabinetController::class, 'edit'],          [AuthMiddleware::class]);
$router->post('/handler-cabinet/representatives/{id}/edit',    [HandlerCabinetController::class, 'update'],        [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/handler-cabinet/representatives/{id}/toggle',  [HandlerCabinetController::class, 'toggle'],        [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/handler-cabinet/representatives/{id}/reset-password', [HandlerCabinetController::class, 'resetPassword'], [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Users
// =====================================================
$router->get('/users',              [UserController::class, 'index'],        [AuthMiddleware::class]);
$router->get('/users/create',       [UserController::class, 'create'],       [AuthMiddleware::class]);
$router->post('/users/create',      [UserController::class, 'store'],        [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/users/{id}',         [UserController::class, 'show'],         [AuthMiddleware::class]);
$router->get('/users/{id}/edit',    [UserController::class, 'edit'],         [AuthMiddleware::class]);
$router->post('/users/{id}/edit',   [UserController::class, 'update'],       [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/users/{id}/toggle', [UserController::class, 'toggleActive'], [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Личные данные (раунд 46, п.3) — доступно любому авторизованному
// пользователю для СВОЕГО же профиля, не только admin
// =====================================================
$router->get('/profile',  [ProfileController::class, 'edit'],   [AuthMiddleware::class]);
$router->post('/profile', [ProfileController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Хендлеры и их сотрудники (раунд 46, п.5/п.6)
// =====================================================
$router->get('/handlers',                                   [HandlerController::class, 'index'],         [AuthMiddleware::class]);
$router->get('/handlers/create',                             [HandlerController::class, 'create'],        [AuthMiddleware::class]);
$router->post('/handlers/create',                            [HandlerController::class, 'store'],         [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/handlers/{id}/edit',                          [HandlerController::class, 'edit'],          [AuthMiddleware::class]);
$router->post('/handlers/{id}/edit',                         [HandlerController::class, 'update'],        [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/handlers/{id}/toggle',                       [HandlerController::class, 'toggleActive'],  [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/handlers/{id}/employees/add',                [HandlerController::class, 'addEmployee'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/handlers/employees/{employee_id}/update',    [HandlerController::class, 'updateEmployee'],[AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/handlers/employees/{employee_id}/toggle',    [HandlerController::class, 'toggleEmployee'],[AuthMiddleware::class, CsrfMiddleware::class]);

// Вебхук Telegram-бота (раунд 46, п.4) — без AuthMiddleware, дёргает сам
// Telegram, не браузер пользователя. См. config/telegram.php.
$router->post('/telegram/webhook', [TelegramController::class, 'webhook']);

// =====================================================
// Roles
// =====================================================
$router->get('/roles',                        [RoleController::class, 'index'],          [AuthMiddleware::class]);
$router->post('/roles/change-user-role',       [RoleController::class, 'changeUserRole'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/roles/toggle-user/{id}',       [RoleController::class, 'toggleUser'],       [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Airlines
// =====================================================
$router->get('/airlines',            [AirlineController::class, 'index'],  [AuthMiddleware::class]);
$router->get('/airlines/create',     [AirlineController::class, 'create'], [AuthMiddleware::class]);
$router->post('/airlines/create',    [AirlineController::class, 'store'],  [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/airlines/{id}/edit',  [AirlineController::class, 'edit'],   [AuthMiddleware::class]);
$router->post('/airlines/{id}/edit', [AirlineController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Airports
// =====================================================
$router->get('/airports',            [AirportController::class, 'index'],  [AuthMiddleware::class]);
$router->get('/airports/create',     [AirportController::class, 'create'], [AuthMiddleware::class]);
$router->post('/airports/create',    [AirportController::class, 'store'],  [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/airports/{id}/edit',  [AirportController::class, 'edit'],   [AuthMiddleware::class]);
$router->post('/airports/{id}/edit', [AirportController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Flights
// ВАЖНО: статичные маршруты ВЫШЕ динамических (/flights/{id})
// =====================================================
$router->get('/flights',               [FlightController::class, 'index'],         [AuthMiddleware::class]);
$router->get('/flights/create',        [FlightController::class, 'create'],        [AuthMiddleware::class]);
$router->get('/flights/checklist-services', [FlightController::class, 'checklistServicesJson'], [AuthMiddleware::class]);
$router->post('/flights/create',       [FlightController::class, 'store'],         [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/flights/import',        [FlightController::class, 'importPage'],    [AuthMiddleware::class]);
$router->post('/flights/import/parse', [FlightController::class, 'importParse'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/flights/import/confirm',[FlightController::class, 'importConfirm'],[AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/flights/{id}',          [FlightController::class, 'show'],          [AuthMiddleware::class]);
$router->get('/flights/{id}/edit',     [FlightController::class, 'edit'],          [AuthMiddleware::class]);
$router->post('/flights/{id}/edit',    [FlightController::class, 'update'],        [AuthMiddleware::class, CsrfMiddleware::class]);
// ДОБАВЛЕНО (27.10.2026, раунд 129): отмена рейса, который был создан, но не состоялся — см. FlightController::cancel()
$router->post('/flights/{id}/cancel',  [FlightController::class, 'cancel'],        [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/flights/{id}/resume',  [FlightController::class, 'resume'],        [AuthMiddleware::class, CsrfMiddleware::class]);
// ДОБАВЛЕНО (17.08.2026): автообновление статусов чек-листа без F5, см. FlightController::orderStatusesJson()
$router->get('/flights/{id}/order-statuses', [FlightController::class, 'orderStatusesJson'], [AuthMiddleware::class]);
// ДОБАВЛЕНО (раунд 33, 09.09.2026): связка рейса прилёта и рейса вылета
// одного и того же борта (одна стоянка/визит) — см. FlightService::linkFlights().
// УБРАНО (раунд 40, 14.09.2026) → ВОССТАНОВЛЕНО (раунд 69, 12.10.2026, по
// прямому указанию пользователя) как предохранительный клапан для починки
// уже испорченных связей — авто-подбор (FlightService::autoLinkFlight())
// остаётся основным механизмом, см. докблок у FlightController::link().
$router->post('/flights/{id}/link',    [FlightController::class, 'link'],          [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/flights/{id}/unlink',  [FlightController::class, 'unlink'],        [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Service Categories
// =====================================================
$router->get('/service-categories',            [ServiceCategoryController::class, 'index'],  [AuthMiddleware::class]);
$router->get('/service-categories/create',     [ServiceCategoryController::class, 'create'], [AuthMiddleware::class]);
$router->post('/service-categories/create',    [ServiceCategoryController::class, 'store'],  [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/service-categories/{id}/edit',  [ServiceCategoryController::class, 'edit'],   [AuthMiddleware::class]);
$router->post('/service-categories/{id}/edit', [ServiceCategoryController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Services
// =====================================================
$router->get('/services',            [ServiceController::class, 'index'],  [AuthMiddleware::class]);
$router->get('/services/create',     [ServiceController::class, 'create'], [AuthMiddleware::class]);
$router->post('/services/create',    [ServiceController::class, 'store'],  [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/services/{id}',       [ServiceController::class, 'show'],   [AuthMiddleware::class]);
$router->get('/services/{id}/edit',  [ServiceController::class, 'edit'],   [AuthMiddleware::class]);
$router->post('/services/{id}/edit', [ServiceController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Tariffs
// =====================================================
$router->get('/tariffs',            [TariffController::class, 'index'],  [AuthMiddleware::class]);
$router->get('/tariffs/create',     [TariffController::class, 'create'], [AuthMiddleware::class]);
$router->post('/tariffs/create',    [TariffController::class, 'store'],  [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/tariffs/{id}',       [TariffController::class, 'show'],   [AuthMiddleware::class]);
$router->get('/tariffs/{id}/edit',  [TariffController::class, 'edit'],   [AuthMiddleware::class]);
$router->post('/tariffs/{id}/edit', [TariffController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Acts
// ВАЖНО: статичные маршруты (/acts/create, /acts/api/...)
// должны быть ВЫШЕ динамических (/acts/{id})
// =====================================================
$router->get('/acts',                                  [ActController::class, 'index'],              [AuthMiddleware::class]);
$router->get('/acts/create',                           [ActController::class, 'create'],             [AuthMiddleware::class]);
$router->post('/acts/create',                          [ActController::class, 'store'],              [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/acts/api/tariff-for-service',           [ActController::class, 'getTariffForService'],[AuthMiddleware::class]);
$router->get('/acts/{id}',                             [ActController::class, 'show'],               [AuthMiddleware::class]);
$router->get('/acts/{id}/edit',                        [ActController::class, 'edit'],               [AuthMiddleware::class]);
$router->post('/acts/{id}/edit',                       [ActController::class, 'update'],             [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/acts/{id}/resync',                     [ActController::class, 'resync'],             [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/acts/{id}/print',                       [ActController::class, 'printForm'],          [AuthMiddleware::class]);
$router->get('/acts/{id}/text-breakdown',              [ActController::class, 'textBreakdown'],      [AuthMiddleware::class]);
$router->post('/acts/{id}/status',                     [ActController::class, 'changeStatus'],       [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/acts/{id}/items/add',                  [ActController::class, 'addItem'],            [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/acts/{act_id}/items/{item_id}/update', [ActController::class, 'updateItem'],         [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/acts/{act_id}/items/{item_id}/delete', [ActController::class, 'deleteItem'],         [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Files
// =====================================================
$router->get('/files',                       [FileController::class, 'index'],    [AuthMiddleware::class]);
$router->post('/acts/{act_id}/files/upload', [FileController::class, 'upload'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/files/{id}/download',         [FileController::class, 'download'], [AuthMiddleware::class]);
$router->post('/files/{id}/delete',          [FileController::class, 'delete'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/files/{id}/log',              [FileController::class, 'log'],      [AuthMiddleware::class]);

// =====================================================
// Audit
// =====================================================
$router->get('/audit', [AuditController::class, 'index'], [AuthMiddleware::class]);

// =====================================================
// Журнал разрешений (ДОБАВЛЕНО 20.10.2026, раунд 103) — строго для
// администратора, см. докблок PermitLogController.
// =====================================================
$router->get('/permit-logs',      [PermitLogController::class, 'index'], [AuthMiddleware::class]);
$router->get('/permit-logs/{id}', [PermitLogController::class, 'show'],  [AuthMiddleware::class]);

// =====================================================
// Public verification (no auth)
// =====================================================
$router->get('/verify/{code}', [ActController::class, 'verifyPublic']);

// =====================================================
// Export to 1C
// =====================================================
$router->get('/export',               [ExportController::class, 'index'],          [AuthMiddleware::class]);
$router->get('/export/xml',           [ExportController::class, 'exportXmlBatch'], [AuthMiddleware::class]);
$router->get('/acts/{id}/export/xml',   [ExportController::class, 'exportXml'],       [AuthMiddleware::class]);
$router->get('/acts/{id}/export/excel', [ExportController::class, 'exportExcel'],     [AuthMiddleware::class]);
$router->get('/export/excel',           [ExportController::class, 'exportExcelBatch'],[AuthMiddleware::class]);
$router->get('/export/api',             [ExportController::class, 'apiActs'],         [AuthMiddleware::class]);
$router->get('/export/api/{act_number}',[ExportController::class, 'apiActDetail'],    [AuthMiddleware::class]);

// =====================================================
// Контроль сроков / Журнал отклонений
// =====================================================
$router->get('/deviations',                     [DeviationController::class, 'index'],     [AuthMiddleware::class]);
$router->post('/documents/{doc_id}/submit',     [DeviationController::class, 'submitDoc'], [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/flights/{id}/init-deadlines',   [FlightController::class, 'initDeadlines'],[AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/flights/{id}/build-act',        [FlightController::class, 'buildAct'],     [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Манифесты СОП
// =====================================================
$router->get('/manifests',                    [ManifestController::class, 'index'], [AuthMiddleware::class]);
$router->get('/manifests/flight/{flight_id}', [ManifestController::class, 'form'],  [AuthMiddleware::class]);
$router->post('/manifests/save',              [ManifestController::class, 'save'],  [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Технологические карты (СПО)
// =====================================================
$router->get('/tech-cards',                [TechCardController::class, 'index'], [AuthMiddleware::class]);
$router->get('/tech-cards/flight/{flight_id}', [TechCardController::class, 'form'],  [AuthMiddleware::class]);
$router->post('/tech-cards/save',          [TechCardController::class, 'save'],  [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Журнал спецтехники (ССТ)
// =====================================================
$router->get('/equipment-logs',                    [EquipmentLogController::class, 'index'], [AuthMiddleware::class]);
$router->get('/equipment-logs/flight/{flight_id}', [EquipmentLogController::class, 'form'],  [AuthMiddleware::class]);
$router->post('/equipment-logs/save',              [EquipmentLogController::class, 'save'],  [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// Акты готовности стоянки (Аэродромная)
// =====================================================
$router->get('/parking-acts',                    [ParkingActController::class, 'index'], [AuthMiddleware::class]);
$router->get('/parking-acts/flight/{flight_id}', [ParkingActController::class, 'form'],  [AuthMiddleware::class]);
$router->post('/parking-acts/save',              [ParkingActController::class, 'save'],  [AuthMiddleware::class, CsrfMiddleware::class]);

// Оказанные услуги по рейсу (от служб СОП/СПО/ССТ) — собираются в акт
$router->post('/service-charges/add',            [ServiceChargeController::class, 'add'],     [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/service-charges/{id}/delete',    [ServiceChargeController::class, 'delete'],  [AuthMiddleware::class, CsrfMiddleware::class]);

// Нормы бесплатной стоянки по авиакомпаниям (Коммерческая служба)
$router->get('/airline-parking-norms',           [AirlineParkingNormController::class, 'index'], [AuthMiddleware::class]);
$router->post('/airline-parking-norms/save',     [AirlineParkingNormController::class, 'save'],  [AuthMiddleware::class, CsrfMiddleware::class]);

// ДОБАВЛЕНО (18.08.2026, раунд 16): справочник стоянок (МС№) — см. App\Models\ParkingStand
$router->get('/parking-stands',                  [ParkingStandController::class, 'index'],   [AuthMiddleware::class]);
$router->post('/parking-stands',                 [ParkingStandController::class, 'store'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/parking-stands/{id}/toggle',     [ParkingStandController::class, 'toggle'],  [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/parking-stands/{id}/delete',     [ParkingStandController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/aircraft-status',                   [AircraftController::class, 'index'],      [AuthMiddleware::class]);
// ДОБАВЛЕНО (22.10.2026, раунд 117, по прямому указанию пользователя): админка
// подтверждения MTOW (save, только admin/cok) + JSON-поиск борта для форм
// рейсов (lookup, только admin/cok — та же роль-матрица, что и создание/
// редактирование самих рейсов, см. FlightController).
$router->post('/aircraft-status/save',             [AircraftController::class, 'save'],       [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/aircraft-status/set-based',         [AircraftController::class, 'setBased'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/aircraft-status/lookup',            [AircraftController::class, 'lookup'],      [AuthMiddleware::class]);
$router->get('/aircraft-status/history',            [AircraftController::class, 'history'],     [AuthMiddleware::class]);

// =====================================================
// Settings
// =====================================================
$router->get('/settings',  [SettingsController::class, 'index'],  [AuthMiddleware::class]);
$router->post('/settings', [SettingsController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

// ── СПАСОП — журнал аварийно-спасательной службы ──
$router->get('/emergency-logs/flight/{flight_id}', [EmergencyLogController::class, 'flightIndex'], [AuthMiddleware::class]);
$router->post('/emergency-logs',                   [EmergencyLogController::class, 'store'],       [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/emergency-logs/{id}/delete',       [EmergencyLogController::class, 'destroy'],     [AuthMiddleware::class, CsrfMiddleware::class]);

// ДОБАВЛЕНО (аудит 10.07.2026): предзаказ услуг ЦОК + дашборд/тайминг супервайзера
$router->post('/flights/{id}/service-orders',       [ServiceOrderController::class, 'save'],             [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/supervisor',                         [ServiceOrderController::class, 'dashboard'],        [AuthMiddleware::class]);
$router->get('/supervisor/flights/{id}',             [ServiceOrderController::class, 'supervisorFlight'], [AuthMiddleware::class]);
$router->post('/supervisor/orders/{id}/timing',      [ServiceOrderController::class, 'updateTiming'],     [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/supervisor/orders/{id}/set-time',     [ServiceOrderController::class, 'setTime'],          [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/supervisor/orders/{id}/set-quantity', [ServiceOrderController::class, 'updateQuantity'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/supervisor/orders/{id}/remove',        [ServiceOrderController::class, 'removePlanned'],    [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/supervisor/orders/{id}/manual-edit',   [ServiceOrderController::class, 'manualEdit'],       [AuthMiddleware::class, CsrfMiddleware::class]);

// ДОБАВЛЕНО (06.08.2026): служба отмечает выдвижение техники к борту —
// НЕ через /supervisor/..., это действие самой службы (sop/spo/sst/aero/spasop),
// а не супервайзера. Кнопка — на странице рейса у службы (см. partials/pending_orders_banner.php).
$router->post('/service-orders/{id}/dispatch', [ServiceOrderController::class, 'dispatch'], [AuthMiddleware::class, CsrfMiddleware::class]);

// ДОБАВЛЕНО (05.08.2026): подпись супервайзера и представителя авиакомпании на планшете
$router->get('/supervisor/flights/{id}/sign',              [ActSignatureController::class, 'signForm'],                  [AuthMiddleware::class]);
$router->post('/supervisor/flights/{id}/sign/supervisor',  [ActSignatureController::class, 'saveSupervisorSignature'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/supervisor/flights/{id}/sign/representative', [ActSignatureController::class, 'saveRepresentativeSignature'], [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/acts/signature/{file_id}',                  [ActSignatureController::class, 'viewSignature'],             [AuthMiddleware::class]);
$router->get('/supervisor/orders/{id}/fuel',          [ServiceOrderController::class, 'fuelForm'],         [AuthMiddleware::class]);
$router->post('/supervisor/orders/{id}/fuel',         [ServiceOrderController::class, 'saveFuel'],         [AuthMiddleware::class, CsrfMiddleware::class]);
// ДОБАВЛЕНО (03.08.2026): супервайзер добавляет незапланированную ЦОК услугу
$router->post('/supervisor/flights/{id}/add-service', [ServiceOrderController::class, 'addUnplanned'],     [AuthMiddleware::class, CsrfMiddleware::class]);
// ДОБАВЛЕНО (08.09.2026, раунд 30): мульти-выбор услуг в поп-апе супервайзера — один AJAX-запрос на несколько service_ids сразу (см. ServiceOrderController::addUnplannedBulk()).
$router->post('/supervisor/flights/{id}/add-services', [ServiceOrderController::class, 'addUnplannedBulk'], [AuthMiddleware::class, CsrfMiddleware::class]);
// ДОБАВЛЕНО (18.08.2026): супервайзер указывает/поправляет номер стоянки
// (MC№) прямо с перрона — раньше это поле flights.mc_number мог менять
// только ЦОК на форме редактирования рейса, а супервайзер, который
// физически видит, куда реально поставили борт, такой возможности не имел.
$router->post('/supervisor/flights/{id}/stand',       [ServiceOrderController::class, 'saveStandNumber'],  [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/supervisor/flights/{id}/actual-time', [ServiceOrderController::class, 'saveActualTime'], [AuthMiddleware::class, CsrfMiddleware::class]);
// ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть): перенос борта на другую стоянку посреди визита — см. ServiceOrderController::moveStand().
$router->post('/supervisor/flights/{id}/move-stand',  [ServiceOrderController::class, 'moveStand'],        [AuthMiddleware::class, CsrfMiddleware::class]);
// ДОБАВЛЕНО (21.08.2026, раунд 23): "Цель полёта" — см. ServiceOrderController::savePurpose().
$router->post('/supervisor/flights/{id}/purpose',     [ServiceOrderController::class, 'savePurpose'],      [AuthMiddleware::class, CsrfMiddleware::class]);
// ДОБАВЛЕНО (21.09.2026, раунд 49, п.1): явная передача обслуживания рейса
// другому супервайзеру при пересменке — см. ServiceOrderController::handover().
$router->post('/supervisor/flights/{id}/handover',    [ServiceOrderController::class, 'handover'],         [AuthMiddleware::class, CsrfMiddleware::class]);

// ── Осмотры ВПП / Перрона ──
$router->get('/inspections',        [InspectionController::class, 'index'],  [AuthMiddleware::class]);
$router->get('/inspections/create', [InspectionController::class, 'create'], [AuthMiddleware::class]);
$router->post('/inspections',       [InspectionController::class, 'store'],  [AuthMiddleware::class, CsrfMiddleware::class]);
$router->get('/inspections/check',  [InspectionController::class, 'check'],  [AuthMiddleware::class]);

// =====================================================
// ДОБАВЛЕНО (06.08.2026): уведомления (колокольчик) + Web Push
// =====================================================
$router->get('/notifications',                  [NotificationController::class, 'index'],        [AuthMiddleware::class]);
$router->get('/notifications/unread-count',     [NotificationController::class, 'unreadCount'],  [AuthMiddleware::class]);
$router->post('/notifications/{id}/read',        [NotificationController::class, 'markRead'],      [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/notifications/mark-all-read',    [NotificationController::class, 'markAllRead'],   [AuthMiddleware::class, CsrfMiddleware::class]);

$router->get('/push/public-key',  [PushController::class, 'publicKey'],   [AuthMiddleware::class]);
$router->post('/push/subscribe',   [PushController::class, 'subscribe'],   [AuthMiddleware::class, CsrfMiddleware::class]);
$router->post('/push/unsubscribe', [PushController::class, 'unsubscribe'], [AuthMiddleware::class, CsrfMiddleware::class]);

// =====================================================
// ДОБАВЛЕНО (07.11.2026, раунд 156, по прямому указанию пользователя):
// JSON-API для мобильного приложения (Android) — пилотная версия,
// роль admin ("просмотр для руководства"). См. докблоки
// App\Core\ApiAuth, App\Controllers\Api\ApiController,
// App\Controllers\Api\AdminController. Используют тот же механизм
// сессий (`user_sessions`), что и обычный веб-вход, но токен передаётся
// в заголовке Authorization: Bearer <token>, а не в cookie. Существующий
// веб-вход (AuthController, AuthService, Auth) не затронут.
// =====================================================
$router->post('/api/v1/auth/login',          [ApiAuthController::class, 'login'],  []);
$router->post('/api/v1/auth/logout',         [ApiAuthController::class, 'logout'], [ApiAuthMiddleware::class]);
$router->get('/api/v1/auth/me',              [ApiAuthController::class, 'me'],     [ApiAuthMiddleware::class]);

$router->get('/api/v1/admin/summary',        [ApiAdminController::class, 'summary'],       [ApiAuthMiddleware::class]);
$router->get('/api/v1/admin/flights',        [ApiAdminController::class, 'flights'],       [ApiAuthMiddleware::class]);
$router->get('/api/v1/admin/acts',           [ApiAdminController::class, 'acts'],          [ApiAuthMiddleware::class]);
$router->get('/api/v1/admin/acts/{id}',      [ApiAdminController::class, 'actDetail'],     [ApiAuthMiddleware::class]);
$router->get('/api/v1/admin/aircraft',       [ApiAdminController::class, 'aircraft'],      [ApiAuthMiddleware::class]);
$router->get('/api/v1/admin/parking-stands', [ApiAdminController::class, 'parkingStands'], [ApiAuthMiddleware::class]);
