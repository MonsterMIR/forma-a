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
use App\Models\ServiceCategory;

class ServiceCategoryController extends Controller
{
    private ServiceCategory $catModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->catModel = new ServiceCategory();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);

        $filters = [
            'search'    => $this->request->get('search', ''),
            'is_active' => $this->request->get('is_active', ''),
        ];
        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $cats  = $this->catModel->getFiltered($filters, $perPage, $offset);
        $total = $this->catModel->countFiltered($filters);

        $this->render('service_categories/index', [
            'title'      => 'Service Categories',
            'categories' => $cats,
            'filters'    => $filters,
            'pagination' => paginate($total, $perPage, $page),
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireRole(['admin']);
        $this->render('service_categories/create', ['title' => 'Create Service Category']);
    }

    public function store(array $params = []): void
    {
        $this->requireRole(['admin']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/service-categories/create');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        $validator->required('code', 'Code')
                  ->required('name', 'Name')
                  ->maxLength('code', 50, 'Code')
                  ->maxLength('name', 120, 'Name');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/service-categories/create');
            return;
        }

        if ($this->catModel->codeExists(strtoupper(trim($data['code'])))) {
            View::setFlash('error', 'Category code already exists.');
            $this->redirect('/service-categories/create');
            return;
        }

        $insertData = [
            'code'        => strtoupper(trim($data['code'])),
            'name'        => trim($data['name']),
            'description' => !empty($data['description']) ? trim($data['description']) : null,
            'sort_order'  => isset($data['sort_order']) ? (int)$data['sort_order'] : 0,
            'is_active'   => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ];

        $id = (int)$this->catModel->insert($insertData);

        ActivityLogger::log('service_category', $id, 'create_service_category', 'success',
            "Created category '{$insertData['name']}'", null, $insertData);

        View::setFlash('success', 'Service category created successfully.');
        $this->redirect('/service-categories');
    }

    public function edit(array $params = []): void
    {
        $this->requireRole(['admin']);
        $cat = $this->catModel->find((int)$params['id']);
        if (!$cat) {
            View::setFlash('error', 'Category not found.');
            $this->redirect('/service-categories');
            return;
        }
        $this->render('service_categories/edit', ['title' => 'Edit Category', 'category' => $cat]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['admin']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/service-categories/' . $params['id'] . '/edit');
            return;
        }

        $cat = $this->catModel->find((int)$params['id']);
        if (!$cat) {
            View::setFlash('error', 'Category not found.');
            $this->redirect('/service-categories');
            return;
        }

        $data = $this->request->all();
        $validator = new Validator($data);
        $validator->required('code', 'Code')->required('name', 'Name');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/service-categories/' . $params['id'] . '/edit');
            return;
        }

        if ($this->catModel->codeExists(strtoupper(trim($data['code'])), (int)$params['id'])) {
            View::setFlash('error', 'Category code already exists.');
            $this->redirect('/service-categories/' . $params['id'] . '/edit');
            return;
        }

        $updateData = [
            'code'        => strtoupper(trim($data['code'])),
            'name'        => trim($data['name']),
            'description' => !empty($data['description']) ? trim($data['description']) : null,
            'sort_order'  => isset($data['sort_order']) ? (int)$data['sort_order'] : 0,
            'is_active'   => (int)($data['is_active'] ?? 1),
        ];

        $this->catModel->update((int)$params['id'], $updateData);

        ActivityLogger::log('service_category', (int)$params['id'], 'update_service_category', 'success',
            "Updated category '{$updateData['name']}'", $cat, $updateData);

        View::setFlash('success', 'Service category updated successfully.');
        $this->redirect('/service-categories');
    }
}