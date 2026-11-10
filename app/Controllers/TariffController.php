<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Validator;
use App\Models\TariffVersion;
use App\Models\Service;
use App\Models\Currency;
use App\Services\TariffService;

class TariffController extends Controller
{
    private TariffService $tariffService;
    private TariffVersion $tariffModel;
    private Service       $serviceModel;
    private Currency      $currencyModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->tariffService = new TariffService();
        $this->tariffModel   = new TariffVersion();
        $this->serviceModel  = new Service();
        $this->currencyModel = new Currency();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);

        $filters = [
            'service_id'     => $this->request->get('service_id', ''),
            'route_type'     => $this->request->get('route_type', ''),
            'operation_type' => $this->request->get('operation_type', ''),
            'is_active'      => $this->request->get('is_active', ''),
        ];
        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $tariffs  = $this->tariffModel->getFiltered($filters, $perPage, $offset);
        $total    = $this->tariffModel->countFiltered($filters);
        $services = $this->serviceModel->getAllActive();

        $this->render('tariffs/index', [
            'title'      => 'Tariff Versions',
            'tariffs'    => $tariffs,
            'services'   => $services,
            'filters'    => $filters,
            'pagination' => paginate($total, $perPage, $page),
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireRole(['admin', 'finance', 'commercial']);
        $services   = $this->serviceModel->getAllActive();
        $currencies = $this->currencyModel->getAllActive();
        $preService = $this->request->get('service_id', '');
        $this->render('tariffs/create', [
            'title'      => 'Create Tariff',
            'services'   => $services,
            'currencies' => $currencies,
            'preService' => $preService,
        ]);
    }

    public function store(array $params = []): void
    {
        $this->requireRole(['admin', 'finance', 'commercial']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/tariffs/create');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        $validator->required('service_id', 'Service')
                  ->required('tariff_name', 'Tariff Name')
                  ->required('route_type', 'Route Type')
                  ->required('operation_type', 'Operation Type')
                  ->required('currency_id', 'Currency')
                  ->required('price', 'Price')
                  ->required('valid_from', 'Valid From')
                  ->positiveNumber('price', 'Price')
                  ->date('valid_from', 'Valid From')
                  ->inList('route_type', ['domestic', 'international', 'both'], 'Route Type')
                  ->inList('operation_type', ['arrival', 'departure', 'both'], 'Operation Type');

        if (!empty($data['valid_to'])) {
            $validator->date('valid_to', 'Valid To');
        }

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/tariffs/create');
            return;
        }

        $result = $this->tariffService->createTariff($data);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/tariffs/create');
            return;
        }

        View::setFlash('success', 'Tariff created successfully.');
        $this->redirect('/tariffs');
    }

    public function show(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);
        $tariff = $this->tariffModel->findWithDetails((int)$params['id']);
        if (!$tariff) {
            View::setFlash('error', 'Tariff not found.');
            $this->redirect('/tariffs');
            return;
        }
        $this->render('tariffs/show', ['title' => 'Tariff: ' . $tariff['tariff_name'], 'tariff' => $tariff]);
    }

    public function edit(array $params = []): void
    {
        $this->requireRole(['admin', 'finance', 'commercial']);
        $tariff = $this->tariffModel->findWithDetails((int)$params['id']);
        if (!$tariff) {
            View::setFlash('error', 'Tariff not found.');
            $this->redirect('/tariffs');
            return;
        }
        $services   = $this->serviceModel->getAllActive();
        $currencies = $this->currencyModel->getAllActive();
        $this->render('tariffs/edit', [
            'title'      => 'Edit Tariff',
            'tariff'     => $tariff,
            'services'   => $services,
            'currencies' => $currencies,
        ]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['admin', 'finance', 'commercial']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/tariffs/' . $params['id'] . '/edit');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        $validator->required('tariff_name', 'Tariff Name')
                  ->required('route_type', 'Route Type')
                  ->required('operation_type', 'Operation Type')
                  ->required('currency_id', 'Currency')
                  ->required('price', 'Price')
                  ->required('valid_from', 'Valid From')
                  ->positiveNumber('price', 'Price')
                  ->date('valid_from', 'Valid From')
                  ->inList('route_type', ['domestic', 'international', 'both'], 'Route Type')
                  ->inList('operation_type', ['arrival', 'departure', 'both'], 'Operation Type');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/tariffs/' . $params['id'] . '/edit');
            return;
        }

        $result = $this->tariffService->updateTariff((int)$params['id'], $data);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/tariffs/' . $params['id'] . '/edit');
            return;
        }

        View::setFlash('success', 'Tariff updated successfully.');
        $this->redirect('/tariffs');
    }
}