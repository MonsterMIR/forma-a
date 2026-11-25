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

    /**
     * ДОБАВЛЕНО (25.11.2026, раунд 211, по жалобе пользователя -- "при
     * редактировании данных, при сохранении, страница возвращается на
     * самую первую [страницу списка], а не остаётся на той, на которой
     * начинался процесс редактирования"): захватывает URL страницы
     * списка (номер страницы + активные фильтры), с которой пользователь
     * перешёл на форму редактирования, через заголовок Referer -- чтобы
     * после сохранения update() вернул именно туда, а не на первую
     * страницу списка без фильтров. Принимается ТОЛЬКО если путь Referer
     * совпадает с ожидаемым $expectedPath (например, '/airports') --
     * хост и остальные части Referer игнорируются, используется только
     * путь+query. Вызывается в edit() (GET), результат передаётся во
     * вьюху и сохраняется в форме скрытым полем _return_url.
     */
    protected function captureListReturnUrl(string $expectedPath): ?string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer === '') {
            return null;
        }
        $parts = parse_url($referer);
        if ($parts === false || ($parts['path'] ?? '') !== $expectedPath) {
            return null;
        }
        $query = $parts['query'] ?? '';
        return $expectedPath . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Проверяет return-URL, пришедший обратно из скрытого поля формы
     * (_return_url), перед использованием в redirect() внутри update() --
     * то же ограничение по пути, что и в captureListReturnUrl(), на
     * случай ручной подмены значения в запросе (защита от открытого
     * редиректа: путь должен совпадать с $expectedPath, произвольный
     * внешний/другой внутренний URL не принимается).
     */
    protected function sanitizeReturnUrl(?string $url, string $expectedPath): ?string
    {
        if (empty($url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['path'] ?? '') !== $expectedPath) {
            return null;
        }
        $query = $parts['query'] ?? '';
        return $expectedPath . ($query !== '' ? '?' . $query : '');
    }
}