<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

class RoleMiddleware
{
    private array $roles;

    public function __construct(array $roles = [])
    {
        $this->roles = $roles;
    }

    public function handle(Request $request, Response $response): void
    {
        if (!Auth::hasRole($this->roles)) {
            http_response_code(403);
            $view = new View();
            $view->render('errors/403', ['layout' => 'main']);
            exit;
        }
    }
}