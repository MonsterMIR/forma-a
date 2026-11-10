<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

class AuthMiddleware
{
    public function handle(Request $request, Response $response): void
    {
        if (!Auth::check()) {
            View::setFlash('error', 'Please log in to continue.');
            $response->redirect('/login');
            exit;
        }
    }
}
