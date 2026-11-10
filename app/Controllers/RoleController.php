<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Permissions;
use App\Core\View;
use App\Models\Role;
use App\Models\User;

class RoleController extends Controller
{
    private Role $roleModel;
    private User $userModel;

    public function __construct(
        \App\Core\Request  $request,
        \App\Core\Response $response
    ) {
        parent::__construct($request, $response);
        $this->roleModel = new Role();
        $this->userModel = new User();
    }

    /**
     * Главная страница — организационная схема + права
     */
    public function index(array $params = []): void
    {
        $this->requireRole(['admin']);
        $db = Database::getInstance();

        $roles = $this->roleModel->getAllRoles();

        // Для каждой роли подгружаем пользователей
        $rolesWithData = array_map(function($role) use ($db) {
            $stmt = $db->prepare(
                "SELECT id, full_name, username, email, is_active, last_login_at
                 FROM users WHERE role_id = ? ORDER BY full_name ASC"
            );
            $stmt->execute([$role['id']]);
            $role['users']       = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $role['permissions'] = Permissions::permissionsFor($role['code']);
            $role['icon']        = Permissions::roleIcon($role['code']);
            $role['color']       = Permissions::roleBadgeColor($role['code']);
            return $role;
        }, $roles);

        // Все пользователи для формы перевода
        $allUsers = $db->query(
            "SELECT u.id, u.full_name, u.username, u.is_active,
                    r.code AS role_code, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             ORDER BY r.code ASC, u.full_name ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->render('roles/index', [
            'title'    => 'Роли и организационная структура',
            'roles'    => $rolesWithData,
            'allUsers' => $allUsers,
        ]);
    }

    /**
     * Изменить роль пользователя
     */
    public function changeUserRole(array $params = []): void
    {
        $this->requireRole(['admin']);

        $userId    = (int)$this->request->input('user_id');
        $roleCode  = trim($this->request->input('role_code', ''));
        $validRoles = array_keys(Permissions::allRoles());

        if (!$userId || !in_array($roleCode, $validRoles, true)) {
            View::setFlash('error', 'Некорректные данные.');
            $this->redirect('/roles');
            return;
        }

        // Нельзя снять роль admin у себя
        if (Auth::id() === $userId && $roleCode !== 'admin') {
            View::setFlash('error', 'Нельзя снять роль администратора у себя.');
            $this->redirect('/roles');
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id FROM roles WHERE code = ? LIMIT 1");
        $stmt->execute([$roleCode]);
        $role = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$role) {
            View::setFlash('error', 'Роль не найдена.');
            $this->redirect('/roles');
            return;
        }

        $user = $this->userModel->find($userId);
        if (!$user) {
            View::setFlash('error', 'Пользователь не найден.');
            $this->redirect('/roles');
            return;
        }

        $this->userModel->update($userId, ['role_id' => (int)$role['id']]);

        \App\Core\ActivityLogger::log(
            'user', $userId, 'change_role', 'success',
            "Role changed to '{$roleCode}' for user '{$user['username']}'",
            null, ['role_code' => $roleCode]
        );

        View::setFlash('success',
            "Роль пользователя «{$user['full_name']}» изменена на «" .
            (Permissions::allRoles()[$roleCode] ?? $roleCode) . "»."
        );
        $this->redirect('/roles');
    }

    /**
     * Быстрое включение/отключение пользователя
     */
    public function toggleUser(array $params = []): void
    {
        $this->requireRole(['admin']);
        $userId = (int)($params['id'] ?? 0);

        if (Auth::id() === $userId) {
            View::setFlash('error', 'Нельзя заблокировать себя.');
            $this->redirect('/roles');
            return;
        }

        $user = $this->userModel->find($userId);
        if (!$user) { $this->redirect('/roles'); return; }

        $newStatus = $user['is_active'] ? 0 : 1;
        $this->userModel->update($userId, ['is_active' => $newStatus]);

        View::setFlash('success',
            "Пользователь «{$user['full_name']}» " .
            ($newStatus ? 'активирован' : 'заблокирован') . "."
        );
        $this->redirect('/roles');
    }
}
