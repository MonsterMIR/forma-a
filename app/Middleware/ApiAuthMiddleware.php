<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiAuth;
use App\Core\Request;
use App\Core\Response;

/**
 * ДОБАВЛЕНО (07.11.2026, раунд 156): аналог AuthMiddleware для JSON-API
 * (мобильное приложение) — вместо редиректа на /login при отсутствии
 * доступа отдаёт JSON 401, как и положено API. См. App\Core\ApiAuth.
 */
class ApiAuthMiddleware
{
    public function handle(Request $request, Response $response): void
    {
        if (ApiAuth::resolve($request) === null) {
            $response->json(['success' => false, 'error' => 'unauthorized'], 401);
            exit;
        }
    }
}
