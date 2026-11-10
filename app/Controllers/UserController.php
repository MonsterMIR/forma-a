<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Validator;
use App\Models\User;
use App\Models\Role;
use App\Services\UserService;

class UserController extends Controller
{
    private UserService $userService;
    private User        $userModel;
    private Role        $roleModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->userService = new UserService();
        $this->userModel   = new User();
        $this->roleModel   = new Role();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin']);

        $filters = [
            'search'    => $this->request->get('search', ''),
            'is_active' => $this->request->get('is_active', ''),
            'role_id'   => $this->request->get('role_id', ''),
        ];

        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $users = $this->userModel->findAllWithRole($filters, $perPage, $offset);
        $total = $this->userModel->countWithFilters($filters);
        $roles = $this->roleModel->getAllRoles();

        $this->render('users/index', [
            'title'      => 'Users',
            'users'      => $users,
            'roles'      => $roles,
            'filters'    => $filters,
            'pagination' => paginate($total, $perPage, $page),
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireRole(['admin']);
        $roles = $this->roleModel->getAllRoles();
        // ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): для роли 'representative' —
        // выбор хендлера (компании), чьи акты видит учётная запись в /portal.
        $handlers = [];
        try { $handlers = (new \App\Models\Handler())->getAllActive(); } catch (\Throwable $e) {}
        $this->render('users/create', [
            'title'    => 'Create User',
            'roles'    => $roles,
            'handlers' => $handlers,
        ]);
    }

    public function store(array $params = []): void
    {
        $this->requireRole(['admin']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/users/create');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        $validator->required('full_name', 'Full Name')
                  ->required('username', 'Username')
                  ->required('role_id', 'Role')
                  ->required('password', 'Password')
                  ->minLength('password', 8, 'Password')
                  ->maxLength('username', 80, 'Username')
                  ->email('email', 'Email');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/users/create');
            return;
        }

        $result = $this->userService->createUser($data);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/users/create');
            return;
        }

        View::setFlash('success', 'User created successfully.');
        $this->redirect('/users');
    }

    public function show(array $params = []): void
    {
        $this->requireRole(['admin']);
        $user = $this->userModel->findWithRole((int)$params['id']);
        if (!$user) {
            View::setFlash('error', 'User not found.');
            $this->redirect('/users');
            return;
        }
        $this->render('users/show', ['title' => 'User: ' . $user['username'], 'user' => $user]);
    }

    public function edit(array $params = []): void
    {
        $this->requireRole(['admin']);
        $user = $this->userModel->findWithRole((int)$params['id']);
        if (!$user) {
            View::setFlash('error', 'User not found.');
            $this->redirect('/users');
            return;
        }
        $roles = $this->roleModel->getAllRoles();
        $handlers = [];
        try { $handlers = (new \App\Models\Handler())->getAllActive(); } catch (\Throwable $e) {}
        $this->render('users/edit', [
            'title'    => 'Edit User',
            'user'     => $user,
            'roles'    => $roles,
            'handlers' => $handlers,
        ]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['admin']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/users/' . $params['id'] . '/edit');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        $validator->required('full_name', 'Full Name')
                  ->required('username', 'Username')
                  ->required('role_id', 'Role')
                  ->maxLength('username', 80, 'Username')
                  ->email('email', 'Email');

        if (!empty($data['password'])) {
            $validator->minLength('password', 8, 'Password');
        }

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/users/' . $params['id'] . '/edit');
            return;
        }

        $result = $this->userService->updateUser((int)$params['id'], $data);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/users/' . $params['id'] . '/edit');
            return;
        }

        View::setFlash('success', 'User updated successfully.');
        $this->redirect('/users');
    }

    public function toggleActive(array $params = []): void
    {
        $this->requireRole(['admin']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/users');
            return;
        }

        $result = $this->userService->toggleActive((int)$params['id']);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
        } else {
            View::setFlash('success', 'User status updated.');
        }
        $this->redirect('/users');
    }
}