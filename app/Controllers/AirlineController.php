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
use App\Models\Airline;

class AirlineController extends Controller
{
    private Airline $airlineModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->airlineModel = new Airline();
    }

    public function index(array $params = []): void
    {
        // ИСПРАВЛЕНО (03.10.2026, раунд 65): gsm/medical/balance есть в
        // Permissions::$matrix['airlines.view'] (раунд 41), но этот
        // requireRole() (отдельный, продублированный список) не был
        // обновлён — тот же класс бага, что и с supervisor/flights.view
        // (см. FlightController::index()). supervisor сюда НЕ добавлен —
        // в матрице airlines.view супервайзера нет, и это правильно.
        $this->requireRole(['admin', 'cok', 'sop', 'spo', 'sst', 'aero', 'spasop', 'commercial', 'finance', 'gsm', 'medical', 'balance']);

        $filters = [
            'search'    => $this->request->get('search', ''),
            'is_active' => $this->request->get('is_active', ''),
        ];
        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $airlines = $this->airlineModel->getFiltered($filters, $perPage, $offset);
        $total    = $this->airlineModel->countFiltered($filters);

        $this->render('airlines/index', [
            'title'      => 'Airlines',
            'airlines'   => $airlines,
            'filters'    => $filters,
            'pagination' => paginate($total, $perPage, $page),
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        $this->render('airlines/create', ['title' => 'Create Airline']);
    }

    public function store(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/airlines/create');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        $validator->required('name', 'Name')
                  ->maxLength('name', 150, 'Name')
                  ->maxLength('iata_code', 2, 'IATA Code')
                  ->maxLength('icao_code', 3, 'ICAO Code');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/airlines/create');
            return;
        }

        $insertData = [
            'iata_code' => !empty($data['iata_code']) ? strtoupper(trim($data['iata_code'])) : null,
            'icao_code' => !empty($data['icao_code']) ? strtoupper(trim($data['icao_code'])) : null,
            'name'      => trim($data['name']),
            'country'   => !empty($data['country']) ? trim($data['country']) : null,
            'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ];

        $id = (int)$this->airlineModel->insert($insertData);

        // ДОБАВЛЕНО (22.10.2026, раунд 114, по прямому указанию пользователя
        // — найден реальный случай: у авиакомпании DXT9686/AIR MANAGEMENT
        // GROUP не было нормы бесплатной стоянки вообще, из-за чего
        // сверхнормативная стоянка (OVER_PARK) тихо не считалась даже при
        // утверждённом акте вылета. Пользователь: "при создании
        // авиакомпании автоматом проставлялся норма бесплатной стоянки
        // 2 часа 30 минут" — значение 2.5ч/3.5ч (пассажирская/грузовая) уже
        // является фактическим единым стандартом по всем 129 существующим
        // записям airline_parking_norms на момент раунда 114 (см. аудит
        // round86) — используем то же самое значение как дефолт, чтобы не
        // вводить новую, ничем не обоснованную цифру. ЦОК может изменить
        // норму вручную позже через Справочники → Нормы стоянки — это лишь
        // гарантирует, что запись никогда не отсутствует вовсе.
        (new \App\Models\AirlineParkingNorm())->upsert(
            $id, 2.0, 3.0, 'Авто-норма по умолчанию при создании авиакомпании (раунд 174, обновлено с 2.5/3.5)',
            (int)(\App\Core\Auth::id() ?? 1)
        );

        ActivityLogger::log('airline', $id, 'create_airline', 'success',
            "Created airline '{$insertData['name']}'", null, $insertData);

        View::setFlash('success', 'Airline created successfully.');
        $this->redirect('/airlines');
    }

    public function edit(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        $airline = $this->airlineModel->find((int)$params['id']);
        if (!$airline) {
            View::setFlash('error', 'Airline not found.');
            $this->redirect('/airlines');
            return;
        }
        $this->render('airlines/edit', ['title' => 'Edit Airline', 'airline' => $airline]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/airlines/' . $params['id'] . '/edit');
            return;
        }

        $airline = $this->airlineModel->find((int)$params['id']);
        if (!$airline) {
            View::setFlash('error', 'Airline not found.');
            $this->redirect('/airlines');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        $validator->required('name', 'Name')
                  ->maxLength('name', 150, 'Name');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/airlines/' . $params['id'] . '/edit');
            return;
        }

        $updateData = [
            'iata_code' => !empty($data['iata_code']) ? strtoupper(trim($data['iata_code'])) : null,
            'icao_code' => !empty($data['icao_code']) ? strtoupper(trim($data['icao_code'])) : null,
            'name'      => trim($data['name']),
            'country'   => !empty($data['country']) ? trim($data['country']) : null,
            'is_active' => (int)($data['is_active'] ?? 1),
        ];

        $this->airlineModel->update((int)$params['id'], $updateData);

        ActivityLogger::log('airline', (int)$params['id'], 'update_airline', 'success',
            "Updated airline '{$updateData['name']}'", $airline, $updateData);

        View::setFlash('success', 'Airline updated successfully.');
        $this->redirect('/airlines');
    }
}