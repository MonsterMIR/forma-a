<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

class CsrfMiddleware
{
    public function handle(Request $request, Response $response): void
    {
        if (in_array($request->method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            if (!Csrf::verifyRequest($request)) {
                http_response_code(419);
                $view = new View();
                $view->render('errors/419', ['layout' => 'auth']);
                exit;
            }
        }
    }
}
