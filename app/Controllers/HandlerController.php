<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ActivityLogger;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Models\Airline;
use App\Models\Handler;
use App\Models\HandlerEmployee;

/**
 * ДОБАВЛЕНО (18.09.2026, раунд 46): справочник "Хендлеры" — компании-агенты,
 * реально ставящие подпись представителя авиакомпании в акте, плюс список
 * их сотрудников, чтобы на планшете выбирать ФИО из списка, а не печатать
 * вручную. См. анализ раунда 46, п.5/п.6.
 *
 * Доступ: ЦОК и админ, как и раньше, плюс (ДОБАВЛЕНО 20.10.2026, раунд 101,
 * по прямому указанию пользователя) коммерческая служба — именно она
 * первой знает о реальном хендлере (заключает договор) и теперь отвечает
 * за создание/подтверждение новых записей в этом справочнике. Импорт
 * разрешений (FlightController::findOrCreateHandler()) больше не создаёт
 * хендлеров автоматически при несовпадении имени — см. round 101/round 100
 * (дубли TARMAC/TARMAC OPS/TARMAC-OPS и т.п.), только подставляет уже
 * существующего (с нормализацией пунктуации) или оставляет рейс без
 * привязки с предупреждением.
 */
class HandlerController extends Controller
{
    private Handler         $handlerModel;
    private HandlerEmployee $employeeModel;
    private Airline         $airlineModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->handlerModel  = new Handler();
        $this->employeeModel = new HandlerEmployee();
        $this->airlineModel  = new Airline();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial']);

        $filters = [
            'search'    => $this->request->get('search', ''),
            'is_active' => $this->request->get('is_active', ''),
        ];
        $handlers = $this->handlerModel->getFiltered($filters, 200, 0);

        // Для каждого хендлера сразу считаем число сотрудников — избегаем
        // N+1 отдельных переходов, чтобы увидеть, у кого список пуст.
        foreach ($handlers as &$h) {
            $h['employees_count'] = count($this->employeeModel->getByHandler((int)$h['id'], false));
        }
        unset($h);

        $this->render('handlers/index', [
            'title'    => 'Хендлеры',
            'handlers' => $handlers,
            'filters'  => $filters,
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial']);
        $airlines = $this->airlineModel->getFiltered(['is_active' => '1'], 500, 0);
        $this->render('handlers/create', ['title' => 'Новый хендлер', 'airlines' => $airlines]);
    }

    public function store(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/handlers/create');
            return;
        }

        $data = $this->request->all();
        $validator = new Validator($data);
        $validator->required('name', 'Название')->maxLength('name', 200, 'Название');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/handlers/create');
            return;
        }

        $name = trim($data['name']);
        if ($this->handlerModel->nameExists($name)) {
            View::setFlash('error', 'Хендлер с таким названием уже существует.');
            $this->redirect('/handlers/create');
            return;
        }

        $id = (int)$this->handlerModel->insert([
            'name'      => $name,
            'note'      => !empty($data['note']) ? trim($data['note']) : null,
            'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ]);

        $airlineIds = array_map('intval', (array)($data['airline_ids'] ?? []));
        if (!empty($airlineIds)) {
            $this->handlerModel->syncAirlines($id, $airlineIds);
        }

        ActivityLogger::log('handler', $id, 'create_handler', 'success', "Создан хендлер '{$name}'");

        View::setFlash('success', 'Хендлер добавлен.');
        $this->redirect('/handlers/' . $id . '/edit');
    }

    public function edit(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial']);
        $handler = $this->handlerModel->find((int)$params['id']);
        if (!$handler) {
            View::setFlash('error', 'Хендлер не найден.');
            $this->redirect('/handlers');
            return;
        }

        $employees      = $this->employeeModel->getByHandler((int)$handler['id'], false);
        $airlines       = $this->airlineModel->getFiltered(['is_active' => '1'], 500, 0);
        $linkedAirlines = $this->handlerModel->getAirlineIds((int)$handler['id']);

        $this->render('handlers/edit', [
            'title'          => 'Хендлер: ' . $handler['name'],
            'handler'        => $handler,
            'employees'      => $employees,
            'airlines'       => $airlines,
            'linkedAirlines' => $linkedAirlines,
        ]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/handlers/' . $params['id'] . '/edit');
            return;
        }

        $handler = $this->handlerModel->find((int)$params['id']);
        if (!$handler) {
            View::setFlash('error', 'Хендлер не найден.');
            $this->redirect('/handlers');
            return;
        }

        $data = $this->request->all();
        $validator = new Validator($data);
        $validator->required('name', 'Название')->maxLength('name', 200, 'Название');
        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/handlers/' . $params['id'] . '/edit');
            return;
        }

        $name = trim($data['name']);
        if ($this->handlerModel->nameExists($name, (int)$params['id'])) {
            View::setFlash('error', 'Хендлер с таким названием уже существует.');
            $this->redirect('/handlers/' . $params['id'] . '/edit');
            return;
        }

        $updateData = [
            'name'      => $name,
            'note'      => !empty($data['note']) ? trim($data['note']) : null,
            'is_active' => (int)($data['is_active'] ?? 1),
        ];
        $this->handlerModel->update((int)$params['id'], $updateData);
        $this->handlerModel->syncAirlines((int)$params['id'], array_map('intval', (array)($data['airline_ids'] ?? [])));

        ActivityLogger::log('handler', (int)$params['id'], 'update_handler', 'success',
            "Обновлён хендлер '{$name}'", $handler, $updateData);

        View::setFlash('success', 'Хендлер обновлён.');
        $this->redirect('/handlers/' . $params['id'] . '/edit');
    }

    public function toggleActive(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial']);
        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/handlers');
            return;
        }
        $handler = $this->handlerModel->find((int)$params['id']);
        if (!$handler) {
            View::setFlash('error', 'Хендлер не найден.');
            $this->redirect('/handlers');
            return;
        }
        $newActive = $handler['is_active'] ? 0 : 1;
        $this->handlerModel->update((int)$params['id'], ['is_active' => $newActive]);
        ActivityLogger::log('handler', (int)$params['id'], $newActive ? 'activate_handler' : 'deactivate_handler', 'success',
            "Хендлер '{$handler['name']}' " . ($newActive ? 'активирован' : 'деактивирован'));
        View::setFlash('success', 'Статус хендлера обновлён.');
        $this->redirect('/handlers');
    }

    // ── Сотрудники хендлера ──────────────────────────────────────────────

    public function addEmployee(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial']);
        $handlerId = (int)$params['id'];
        $handler   = $this->handlerModel->find($handlerId);
        if (!$handler) {
            View::setFlash('error', 'Хендлер не найден.');
            $this->redirect('/handlers');
            return;
        }
        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/handlers/' . $handlerId . '/edit');
            return;
        }

        $data = $this->request->all();
        $validator = new Validator($data);
        $validator->required('full_name', 'ФИО')->maxLength('full_name', 150, 'ФИО');
        if (!empty($data['email'])) {
            $validator->email('email', 'Email');
        }
        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/handlers/' . $handlerId . '/edit');
            return;
        }

        $id = (int)$this->employeeModel->insert([
            'handler_id' => $handlerId,
            'full_name'  => trim($data['full_name']),
            'position'   => !empty($data['position']) ? trim($data['position']) : null,
            'phone'      => !empty($data['phone']) ? trim($data['phone']) : null,
            'email'      => !empty($data['email']) ? trim($data['email']) : null,
            'is_active'  => 1,
        ]);

        ActivityLogger::log('handler_employee', $id, 'create_handler_employee', 'success',
            "Добавлен сотрудник хендлера '{$handler['name']}': " . trim($data['full_name']));

        View::setFlash('success', 'Сотрудник добавлен.');
        $this->redirect('/handlers/' . $handlerId . '/edit');
    }

    public function updateEmployee(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial']);
        $employeeId = (int)$params['employee_id'];
        $employee   = $this->employeeModel->find($employeeId);
        if (!$employee) {
            View::setFlash('error', 'Сотрудник не найден.');
            $this->redirect('/handlers');
            return;
        }
        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/handlers/' . $employee['handler_id'] . '/edit');
            return;
        }

        $data = $this->request->all();
        $validator = new Validator($data);
        $validator->required('full_name', 'ФИО')->maxLength('full_name', 150, 'ФИО');
        if (!empty($data['email'])) {
            $validator->email('email', 'Email');
        }
        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/handlers/' . $employee['handler_id'] . '/edit');
            return;
        }

        $updateData = [
            'full_name' => trim($data['full_name']),
            'position'  => !empty($data['position']) ? trim($data['position']) : null,
            'phone'     => !empty($data['phone']) ? trim($data['phone']) : null,
            'email'     => !empty($data['email']) ? trim($data['email']) : null,
            'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : (int)$employee['is_active'],
        ];
        $this->employeeModel->update($employeeId, $updateData);

        ActivityLogger::log('handler_employee', $employeeId, 'update_handler_employee', 'success',
            "Обновлён сотрудник хендлера: {$updateData['full_name']}", $employee, $updateData);

        View::setFlash('success', 'Данные сотрудника обновлены.');
        $this->redirect('/handlers/' . $employee['handler_id'] . '/edit');
    }

    public function toggleEmployee(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial']);
        $employeeId = (int)$params['employee_id'];
        $employee   = $this->employeeModel->find($employeeId);
        if (!$employee) {
            View::setFlash('error', 'Сотрудник не найден.');
            $this->redirect('/handlers');
            return;
        }
        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/handlers/' . $employee['handler_id'] . '/edit');
            return;
        }
        $newActive = $employee['is_active'] ? 0 : 1;
        $this->employeeModel->update($employeeId, ['is_active' => $newActive]);
        ActivityLogger::log('handler_employee', $employeeId, $newActive ? 'activate_handler_employee' : 'deactivate_handler_employee', 'success',
            "Сотрудник '{$employee['full_name']}' " . ($newActive ? 'активирован' : 'деактивирован'));
        View::setFlash('success', 'Статус сотрудника обновлён.');
        $this->redirect('/handlers/' . $employee['handler_id'] . '/edit');
    }
}
