<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ActivityLogger;
use App\Models\User;
use App\Models\UserSession;

class UserService
{
    private User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    public function createUser(array $data): array
    {
        if ($this->userModel->usernameExists($data['username'])) {
            return ['success' => false, 'error' => 'Username already exists.'];
        }

        if (!empty($data['email']) && $this->userModel->emailExists($data['email'])) {
            return ['success' => false, 'error' => 'Email already exists.'];
        }

        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);

        $insertData = [
            'role_id'       => (int)$data['role_id'],
            'full_name'     => trim($data['full_name']),
            'username'      => trim($data['username']),
            'email'         => !empty($data['email']) ? trim($data['email']) : null,
            'phone'         => !empty($data['phone']) ? trim($data['phone']) : null,
            'password_hash' => $passwordHash,
            'is_active'     => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            // ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): личный кабинет
            // представителя — привязка учётной записи к хендлеру (компании),
            // чьи утверждённые акты она видит в /portal. Осмысленно только
            // при role_code='representative', но поле nullable и безвредно
            // для остальных ролей.
            'handler_id'    => !empty($data['handler_id']) ? (int)$data['handler_id'] : null,
            // ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): должность — та же
            // колонка, что и в личном кабинете хендлера (см.
            // HandlerCabinetController); нужна, чтобы должность на экране
            // подписи акта подтягивалась автоматически и для учётных
            // записей, заведённых вручную ЦОК/админом (не только через
            // кабинет хендлера), см. HandlerEmployee::COLS_WITH_USER.
            'position'      => !empty($data['position']) ? trim($data['position']) : null,
        ];

        $id = (int)$this->userModel->insert($insertData);

        ActivityLogger::log(
            'user', $id, 'create_user', 'success',
            "Created user '{$insertData['username']}'",
            null,
            $insertData
        );

        return ['success' => true, 'id' => $id];
    }

    public function updateUser(int $id, array $data): array
    {
        $existing = $this->userModel->findWithRole($id);
        if (!$existing) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        if ($this->userModel->usernameExists($data['username'], $id)) {
            return ['success' => false, 'error' => 'Username already exists.'];
        }

        if (!empty($data['email']) && $this->userModel->emailExists($data['email'], $id)) {
            return ['success' => false, 'error' => 'Email already exists.'];
        }

        $updateData = [
            'role_id'    => (int)$data['role_id'],
            'full_name'  => trim($data['full_name']),
            'username'   => trim($data['username']),
            'email'      => !empty($data['email']) ? trim($data['email']) : null,
            'phone'      => !empty($data['phone']) ? trim($data['phone']) : null,
            'is_active'  => (int)$data['is_active'],
            // См. createUser() выше — раунд 49, п.5.
            'handler_id' => !empty($data['handler_id']) ? (int)$data['handler_id'] : null,
            // См. createUser() выше — раунд 51, п.3.
            'position'   => !empty($data['position']) ? trim($data['position']) : null,
        ];

        if (!empty($data['password'])) {
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $this->userModel->update($id, $updateData);

        ActivityLogger::log(
            'user', $id, 'update_user', 'success',
            "Updated user '{$updateData['username']}'",
            $existing,
            $updateData
        );

        return ['success' => true];
    }

    public function toggleActive(int $id): array
    {
        $user = $this->userModel->find($id);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        $newActive = $user['is_active'] ? 0 : 1;
        $this->userModel->update($id, ['is_active' => $newActive]);

        if (!$newActive) {
            $sessionModel = new UserSession();
            $sessionModel->closeAllForUser($id, 'deactivated');
        }

        ActivityLogger::log(
            'user', $id, $newActive ? 'activate_user' : 'deactivate_user', 'success',
            "User '{$user['username']}' " . ($newActive ? 'activated' : 'deactivated')
        );

        return ['success' => true, 'is_active' => $newActive];
    }
}