<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiAuth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * ДОБАВЛЕНО (07.11.2026, раунд 156): общий базовый класс для JSON-API
 * контроллеров мобильного приложения. См. App\Core\ApiAuth,
 * App\Middleware\ApiAuthMiddleware.
 */
abstract class ApiController extends Controller
{
    protected function apiUser(): array
    {
        // ApiAuthMiddleware уже гарантировал, что пользователь есть —
        // null здесь означал бы баг в маршрутизации (middleware не
        // подключён к роуту), поэтому не редиректим, а явно падаем в 401,
        // чтобы проблема была сразу заметна.
        $user = ApiAuth::resolve($this->request);
        if ($user === null) {
            $this->json(['success' => false, 'error' => 'unauthorized'], 401);
            exit;
        }
        return $user;
    }

    protected function requireApiRole(string|array $roles): array
    {
        $user = $this->apiUser();
        $allowed = is_array($roles) ? $roles : [$roles];
        if (!in_array($user['role_code'], $allowed, true)) {
            $this->json(['success' => false, 'error' => 'forbidden'], 403);
            exit;
        }
        return $user;
    }
}
