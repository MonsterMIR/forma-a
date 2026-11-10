<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiAuth;
use App\Core\Request;
use App\Core\Response;
use App\Core\SessionManager;
use App\Models\User;

/**
 * ДОБАВЛЕНО (07.11.2026, раунд 156, по прямому указанию пользователя):
 * вход/выход для мобильного приложения (Android) — первая версия, см.
 * claude/analysis-2026-11-07-round156-android-app-pilot.md.
 *
 * Намеренно НЕ переиспользует App\Services\AuthService::attempt() —
 * тот метод жёстко вызывает App\Core\Auth::login(), который пишет в
 * $_SESSION/PHP-cookie (нужно для браузера, не нужно и не вредно, но
 * избыточно для мобильного клиента). Вместо этого — та же самая проверка
 * логина/пароля и то же самое создание записи в `user_sessions`
 * (App\Core\SessionManager::createDbSession(), без изменений), только
 * токен возвращается в JSON-ответе, а не кладётся в cookie.
 */
class AuthController extends ApiController
{
    private User $userModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->userModel = new User();
    }

    public function login(array $params = []): void
    {
        $username = trim((string)$this->request->input('username', ''));
        $password = (string)$this->request->input('password', '');

        if ($username === '' || $password === '') {
            $this->json(['success' => false, 'error' => 'Укажите логин и пароль.'], 422);
            return;
        }

        $user = $this->userModel->findByUsername($username);
        if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
            \App\Core\ActivityLogger::log(
                'auth', $user['id'] ?? null, 'api_login', 'failure',
                "Вход в мобильное приложение не удался для '{$username}'"
            );
            $this->json(['success' => false, 'error' => 'Неверный логин или пароль.'], 401);
            return;
        }

        $session = SessionManager::createDbSession((int)$user['id'], $this->request->ip, $this->request->userAgent . ' (Android app)');
        $this->userModel->updateLastLogin((int)$user['id']);

        \App\Core\ActivityLogger::log(
            'auth', (int)$user['id'], 'api_login', 'success',
            "Пользователь '{$username}' вошёл в мобильное приложение"
        );

        unset($user['password_hash']);

        $this->json([
            'success' => true,
            'token'   => $session['token'],
            'user'    => [
                'id'        => (int)$user['id'],
                'username'  => $user['username'],
                'full_name' => $user['full_name'] ?? $user['username'],
                'role_code' => $user['role_code'],
                'role_name' => $user['role_name'],
            ],
        ]);
    }

    public function logout(array $params = []): void
    {
        $user = $this->apiUser();
        SessionManager::closeDbSession((int)$user['session_db_id'], 'api_logout');

        \App\Core\ActivityLogger::log(
            'auth', (int)$user['id'], 'api_logout', 'success',
            "Пользователь '{$user['username']}' вышел из мобильного приложения"
        );

        $this->json(['success' => true]);
    }

    public function me(array $params = []): void
    {
        $user = $this->apiUser();
        $this->json([
            'success' => true,
            'user'    => [
                'id'        => (int)$user['id'],
                'username'  => $user['username'],
                'full_name' => $user['full_name'] ?? $user['username'],
                'role_code' => $user['role_code'],
                'role_name' => $user['role_name'],
            ],
        ]);
    }
}
