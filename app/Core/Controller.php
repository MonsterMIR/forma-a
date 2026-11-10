<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected View $view;
    protected Request $request;
    protected Response $response;

    public function __construct(Request $request, Response $response)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->view     = new View();
    }

    protected function render(string $view, array $data = [], int $status = 200): void
    {
        $this->response->setStatus($status);
        $this->view->render($view, $data);
    }

    protected function redirect(string $url): void
    {
        $this->response->redirect($url);
    }

    protected function json(array $data, int $status = 200): void
    {
        $this->response->json($data, $status);
    }

    protected function currentUser(): ?array
    {
        return Auth::user();
    }

    protected function requireRole(string|array $roles): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->redirect('/login');
            exit;
        }
        $allowed = is_array($roles) ? $roles : [$roles];
        if (!in_array($user['role_code'], $allowed, true)) {
            http_response_code(403);
            $this->render('errors/403');
            exit;
        }
    }
}