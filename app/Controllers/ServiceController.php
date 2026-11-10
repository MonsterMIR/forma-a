<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Validator;
use App\Core\ActivityLogger;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Currency;
use App\Models\TariffVersion;

class ServiceController extends Controller
{
    private Service         $serviceModel;
    private ServiceCategory $catModel;
    private Currency        $currencyModel;
    private TariffVersion   $tariffModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->serviceModel  = new Service();
        $this->catModel      = new ServiceCategory();
        $this->currencyModel = new Currency();
        $this->tariffModel   = new TariffVersion();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);

        $filters = [
            'search'      => $this->request->get('search', ''),
            'category_id' => $this->request->get('category_id', ''),
            'is_active'   => $this->request->get('is_active', ''),
        ];
        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $services   = $this->serviceModel->getFiltered($filters, $perPage, $offset);
        $total      = $this->serviceModel->countFiltered($filters);
        $categories = $this->catModel->getAllActive();

        $this->render('services/index', [
            'title'      => 'Services',
            'services'   => $services,
            'categories' => $categories,
            'filters'    => $filters,
            'pagination' => paginate($total, $perPage, $page),
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireRole(['admin', 'finance']);
        $categories = $this->catModel->getAllActive();
        $currencies = $this->currencyModel->getAllActive();
        $this->render('services/create', [
            'title'      => 'Create Service',
            'categories' => $categories,
            'currencies' => $currencies,
        ]);
    }

    public function store(array $params = []): void
    {
        $this->requireRole(['admin', 'finance']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/services/create');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        $validator->required('category_id', 'Category')
                  ->required('owner_role', 'Служба-владелец')
                  ->required('code', 'Code')
                  ->required('name', 'Name')
                  ->required('unit_name', 'Unit Name')
                  ->required('default_currency_id', 'Default Currency')
                  ->maxLength('code', 50, 'Code')
                  ->maxLength('name', 150, 'Name');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/services/create');
            return;
        }

        if ($this->serviceModel->codeExists(strtoupper(trim($data['code'])))) {
            View::setFlash('error', 'Service code already exists.');
            $this->redirect('/services/create');
            return;
        }

        $insertData = [
            'category_id'                  => (int)$data['category_id'],
            'owner_role'                   => !empty($data['owner_role']) ? trim($data['owner_role']) : null,
            'code'                         => strtoupper(trim($data['code'])),
            'name'                         => trim($data['name']),
            'description'                  => !empty($data['description']) ? trim($data['description']) : null,
            'unit_name'                    => trim($data['unit_name']),
            'default_currency_id'          => (int)$data['default_currency_id'],
            'vat_applicable_domestic'      => isset($data['vat_applicable_domestic']) ? 1 : 0,
            'vat_applicable_international' => isset($data['vat_applicable_international']) ? 1 : 0,
            'is_active'                    => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            'mtow_min'                     => ($data['mtow_min'] ?? '') !== '' ? (float)$data['mtow_min'] : null,
            'mtow_max'                     => ($data['mtow_max'] ?? '') !== '' ? (float)$data['mtow_max'] : null,
            'form_a_slot'                  => !empty($data['form_a_slot']) ? trim($data['form_a_slot']) : null,
            'exclusive_group'              => !empty($data['exclusive_group']) ? strtoupper(trim($data['exclusive_group'])) : null,
            'requires_manual_quantity'     => isset($data['requires_manual_quantity']) ? 1 : 0,
            'requires_dispatch_notice'     => isset($data['requires_dispatch_notice']) ? 1 : 0,
            // ДОБАВЛЕНО (14.08.2026, раунд 6): см. AutoChargeService::techPersonnelServiceCodes()
            'requires_tech_personnel'      => isset($data['requires_tech_personnel']) ? 1 : 0,
            // ДОБАВЛЕНО (14.08.2026, раунд 7): см. докблок в миграции round7 —
            // управляет бейджем "По умолчанию" в строках акта, не влияет на авторасчёт.
            'is_blanket'                   => isset($data['is_blanket']) ? 1 : 0,
            // ДОБАВЛЕНО (17.08.2026, раунд 9): см. докблок в миграции round9 —
            // услуга проходит обычный тайминг-флоу (Начать/Завершить), но НЕ
            // создаёт биллинговую запись (ServiceOrderController::confirmServiceCharge()).
            'is_non_billable'              => isset($data['is_non_billable']) ? 1 : 0,
            // ДОБАВЛЕНО (19.08.2026, раунд 21): см. докблок в миграции round21 —
            // услуга не имеет отдельного "начала" и "конца" (напр. МЕТЕО) —
            // на экране супервайзера одна кнопка "Оказано" вместо пары
            // "Начать"/"Завершить" (resources/views/service_orders/_supervisor_order_card.php).
            'no_timing'                    => isset($data['no_timing']) ? 1 : 0,
            // ДОБАВЛЕНО (25.09.2026, раунд 58, по прямому указанию
            // пользователя): услугу можно заказать на одном рейсе несколько
            // раз, каждый раз отдельно оплачивается — см. докблок в миграции
            // round58_repeatable_services.sql и
            // ServiceOrderController::addUnplanned()/confirmServiceCharge().
            'allow_repeat'                 => isset($data['allow_repeat']) ? 1 : 0,
            // ДОБАВЛЕНО (29.09.2026, раунд 64, по прямому указанию
            // пользователя): количество (часы) для услуги считается
            // автоматически из started_at/completed_at вместо ручного
            // ввода супервайзером — см. ServiceOrderController::
            // isQuantityFromTiming()/calcQuantityFromTiming(). Имеет смысл
            // только вместе с no_timing=0 (у услуги должен быть реальный
            // тайминг-флоу Начать/Завершить, иначе считать не из чего).
            'quantity_from_timing'         => isset($data['quantity_from_timing']) ? 1 : 0,
        ];

        $id = (int)$this->serviceModel->insert($insertData);

        ActivityLogger::log('service', $id, 'create_service', 'success',
            "Created service '{$insertData['name']}'", null, $insertData);

        View::setFlash('success', 'Service created successfully.');
        $this->redirect('/services');
    }

    public function show(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);
        $service = $this->serviceModel->findWithDetails((int)$params['id']);
        if (!$service) {
            View::setFlash('error', 'Service not found.');
            $this->redirect('/services');
            return;
        }
        $tariffs = $this->tariffModel->getAllForService((int)$params['id']);
        $this->render('services/show', [
            'title'   => 'Service: ' . $service['name'],
            'service' => $service,
            'tariffs' => $tariffs,
        ]);
    }

    public function edit(array $params = []): void
    {
        $this->requireRole(['admin', 'finance']);
        $service = $this->serviceModel->findWithDetails((int)$params['id']);
        if (!$service) {
            View::setFlash('error', 'Service not found.');
            $this->redirect('/services');
            return;
        }
        $categories = $this->catModel->getAllActive();
        $currencies = $this->currencyModel->getAllActive();
        $this->render('services/edit', [
            'title'      => 'Edit Service',
            'service'    => $service,
            'categories' => $categories,
            'currencies' => $currencies,
        ]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['admin', 'finance']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/services/' . $params['id'] . '/edit');
            return;
        }

        $service = $this->serviceModel->find((int)$params['id']);
        if (!$service) {
            View::setFlash('error', 'Service not found.');
            $this->redirect('/services');
            return;
        }

        $data = $this->request->all();
        $validator = new Validator($data);
        $validator->required('category_id', 'Category')
                  ->required('owner_role', 'Служба-владелец')
                  ->required('code', 'Code')
                  ->required('name', 'Name')
                  ->required('unit_name', 'Unit Name')
                  ->required('default_currency_id', 'Default Currency');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/services/' . $params['id'] . '/edit');
            return;
        }

        if ($this->serviceModel->codeExists(strtoupper(trim($data['code'])), (int)$params['id'])) {
            View::setFlash('error', 'Service code already exists.');
            $this->redirect('/services/' . $params['id'] . '/edit');
            return;
        }

        $updateData = [
            'category_id'                  => (int)$data['category_id'],
            'owner_role'                   => !empty($data['owner_role']) ? trim($data['owner_role']) : null,
            'code'                         => strtoupper(trim($data['code'])),
            'name'                         => trim($data['name']),
            'description'                  => !empty($data['description']) ? trim($data['description']) : null,
            'unit_name'                    => trim($data['unit_name']),
            'default_currency_id'          => (int)$data['default_currency_id'],
            'vat_applicable_domestic'      => isset($data['vat_applicable_domestic']) ? 1 : 0,
            'vat_applicable_international' => isset($data['vat_applicable_international']) ? 1 : 0,
            'is_active'                    => (int)($data['is_active'] ?? 1),
            'mtow_min'                     => ($data['mtow_min'] ?? '') !== '' ? (float)$data['mtow_min'] : null,
            'mtow_max'                     => ($data['mtow_max'] ?? '') !== '' ? (float)$data['mtow_max'] : null,
            'form_a_slot'                  => !empty($data['form_a_slot']) ? trim($data['form_a_slot']) : null,
            'exclusive_group'              => !empty($data['exclusive_group']) ? strtoupper(trim($data['exclusive_group'])) : null,
            'requires_manual_quantity'     => isset($data['requires_manual_quantity']) ? 1 : 0,
            'requires_dispatch_notice'     => isset($data['requires_dispatch_notice']) ? 1 : 0,
            // ДОБАВЛЕНО (14.08.2026, раунд 6): см. AutoChargeService::techPersonnelServiceCodes()
            'requires_tech_personnel'      => isset($data['requires_tech_personnel']) ? 1 : 0,
            // ДОБАВЛЕНО (14.08.2026, раунд 7): см. докблок в миграции round7 —
            // управляет бейджем "По умолчанию" в строках акта, не влияет на авторасчёт.
            'is_blanket'                   => isset($data['is_blanket']) ? 1 : 0,
            // ДОБАВЛЕНО (17.08.2026, раунд 9): см. докблок в миграции round9 —
            // услуга проходит обычный тайминг-флоу (Начать/Завершить), но НЕ
            // создаёт биллинговую запись (ServiceOrderController::confirmServiceCharge()).
            'is_non_billable'              => isset($data['is_non_billable']) ? 1 : 0,
            // ДОБАВЛЕНО (19.08.2026, раунд 21): см. докблок в миграции round21 —
            // услуга не имеет отдельного "начала" и "конца" (напр. МЕТЕО) —
            // на экране супервайзера одна кнопка "Оказано" вместо пары
            // "Начать"/"Завершить" (resources/views/service_orders/_supervisor_order_card.php).
            'no_timing'                    => isset($data['no_timing']) ? 1 : 0,
            // ДОБАВЛЕНО (25.09.2026, раунд 58) — см. докблок в store() выше.
            // ИСПРАВЛЕНО (05.10.2026, раунд 66): серверная страховка — для
            // формульной обязательной услуги (is_mandatory=1, см.
            // AutoChargeService) allow_repeat принудительно 0, что бы ни
            // пришло в запросе. Форма (edit.php) уже блокирует чекбокс, но
            // is_mandatory нигде в этой форме не редактируется — значит,
            // любой POST на этот эндпоинт для такой услуги не может быть
            // осознанной попыткой её включить, а только случайностью/прямым
            // запросом в обход формы.
            'allow_repeat'                 => (!empty($service['is_mandatory']))
                ? 0
                : (isset($data['allow_repeat']) ? 1 : 0),
            // ДОБАВЛЕНО (29.09.2026, раунд 64, по прямому указанию
            // пользователя): количество (часы) для услуги считается
            // автоматически из started_at/completed_at вместо ручного
            // ввода супервайзером — см. ServiceOrderController::
            // isQuantityFromTiming()/calcQuantityFromTiming(). Имеет смысл
            // только вместе с no_timing=0 (у услуги должен быть реальный
            // тайминг-флоу Начать/Завершить, иначе считать не из чего).
            'quantity_from_timing'         => isset($data['quantity_from_timing']) ? 1 : 0,
        ];

        $this->serviceModel->update((int)$params['id'], $updateData);

        ActivityLogger::log('service', (int)$params['id'], 'update_service', 'success',
            "Updated service '{$updateData['name']}'", $service, $updateData);

        View::setFlash('success', 'Service updated successfully.');
        $this->redirect('/services');
    }
}