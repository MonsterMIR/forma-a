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
use App\Models\Airport;

class AirportController extends Controller
{
    private Airport $airportModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->airportModel = new Airport();
    }

    public function index(array $params = []): void
    {
        // ИСПРАВЛЕНО (03.10.2026, раунд 65): тот же пробел, что и в
        // AirlineController::index() / FlightController::index() —
        // gsm/medical/balance есть в Permissions::$matrix['airports.view'],
        // но не были добавлены сюда.
        $this->requireRole(['admin', 'cok', 'sop', 'spo', 'sst', 'aero', 'spasop', 'commercial', 'finance', 'gsm', 'medical', 'balance']);

        $filters = [
            'search'    => $this->request->get('search', ''),
            'is_active' => $this->request->get('is_active', ''),
        ];
        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $airports = $this->airportModel->getFiltered($filters, $perPage, $offset);
        $total    = $this->airportModel->countFiltered($filters);

        $this->render('airports/index', [
            'title'      => 'Airports',
            'airports'   => $airports,
            'filters'    => $filters,
            'pagination' => paginate($total, $perPage, $page),
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        $this->render('airports/create', ['title' => 'Create Airport']);
    }

    public function store(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/airports/create');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        $validator->required('name', 'Name')
                  ->maxLength('name', 150, 'Name');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/airports/create');
            return;
        }

        $insertData = [
            'iata_code' => !empty($data['iata_code']) ? strtoupper(trim($data['iata_code'])) : null,
            'icao_code' => !empty($data['icao_code']) ? strtoupper(trim($data['icao_code'])) : null,
            'name'      => trim($data['name']),
            'city'      => !empty($data['city']) ? trim($data['city']) : null,
            'country'   => !empty($data['country']) ? trim($data['country']) : null,
            'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ];

        $id = (int)$this->airportModel->insert($insertData);

        ActivityLogger::log('airport', $id, 'create_airport', 'success',
            "Created airport '{$insertData['name']}'", null, $insertData);

        View::setFlash('success', 'Airport created successfully.');
        $this->redirect('/airports');
    }

    public function edit(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        $airport = $this->airportModel->find((int)$params['id']);
        if (!$airport) {
            View::setFlash('error', 'Airport not found.');
            $this->redirect('/airports');
            return;
        }
        $this->render('airports/edit', ['title' => 'Edit Airport', 'airport' => $airport]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/airports/' . $params['id'] . '/edit');
            return;
        }

        $airport = $this->airportModel->find((int)$params['id']);
        if (!$airport) {
            View::setFlash('error', 'Airport not found.');
            $this->redirect('/airports');
            return;
        }

        $data = $this->request->all();
        $validator = new Validator($data);
        $validator->required('name', 'Name')->maxLength('name', 150, 'Name');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/airports/' . $params['id'] . '/edit');
            return;
        }

        $updateData = [
            'iata_code' => !empty($data['iata_code']) ? strtoupper(trim($data['iata_code'])) : null,
            'icao_code' => !empty($data['icao_code']) ? strtoupper(trim($data['icao_code'])) : null,
            'name'      => trim($data['name']),
            'city'      => !empty($data['city']) ? trim($data['city']) : null,
            'country'   => !empty($data['country']) ? trim($data['country']) : null,
            'is_active' => (int)($data['is_active'] ?? 1),
        ];

        $this->airportModel->update((int)$params['id'], $updateData);

        ActivityLogger::log('airport', (int)$params['id'], 'update_airport', 'success',
            "Updated airport '{$updateData['name']}'", $airport, $updateData);

        View::setFlash('success', 'Airport updated successfully.');
        $this->redirect('/airports');
    }
}