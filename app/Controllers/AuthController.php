<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Auth;
use App\Services\AuthService;
use App\Core\Validator;

class AuthController extends Controller
{
    private AuthService $authService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->authService = new AuthService();
    }

    public function loginForm(array $params = []): void
    {
        if (Auth::check()) {
            // ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): представитель, уже
            // вошедший в портал, при повторном заходе на /login должен
            // попасть обратно в /portal, а не в /dashboard (там нет прав).
            $role = Auth::user()['role_code'] ?? '';
            // ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): у хендлера (роль
            // handler_admin) свой кабинет — см. комментарий в login() ниже.
            $this->redirect(match ($role) {
                'representative' => '/portal',
                'handler_admin'  => '/handler-cabinet',
                default          => '/dashboard',
            });
            return;
        }
        $this->render('auth/login', ['layout' => 'auth', 'title' => 'Login']);
    }

    public function login(array $params = []): void
    {
        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token. Please try again.');
            $this->redirect('/login');
            return;
        }

        $username = trim($this->request->input('username', ''));
        $password = $this->request->input('password', '');

        $validator = new Validator(['username' => $username, 'password' => $password]);
        $validator->required('username', 'Username')
                  ->required('password', 'Password');

        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/login');
            return;
        }

        $result = $this->authService->attempt(
            $username,
            $password,
            $this->request->ip,
            $this->request->userAgent
        );

        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/login');
            return;
        }

        // ДОБАВЛЕНО (21.09.2026, раунд 49, п.5, по прямому указанию
        // пользователя): личный кабинет представителя — "в тестовом режиме
        // эта функция будет реализовываться но пока не действовать".
        // Учётные записи с ролью 'representative' можно завести заранее
        // (Пользователи → Создать), но пока флаг PORTAL_ENABLED выключен
        // (config/portal.php) — им не даём войти вообще, чтобы ничего в
        // системе фактически не изменилось до явного включения.
        if (($result['user']['role_code'] ?? '') === 'representative') {
            $portalConfig = require BASE_PATH . '/config/portal.php';
            if (empty($portalConfig['enabled'])) {
                $this->authService->logout('portal_disabled');
                View::setFlash('error', 'Личный кабинет представителя ещё не запущен. Обратитесь в ЦОК.');
                $this->redirect('/login');
                return;
            }
            $this->redirect('/portal');
            return;
        }

        // ДОБАВЛЕНО (22.09.2026, раунд 51, п.3, по прямому указанию
        // пользователя): личный кабинет хендлера (управление своими
        // представителями, см. HandlerCabinetController) — В ОТЛИЧИЕ от
        // 'representative' выше, НЕ под флагом PORTAL_ENABLED: не даёт
        // удалённого доступа к актам, только управляет списком тех, кто
        // выбирается на уже существующем и всегда включённом экране
        // подписи на планшете супервайзера — работает сразу после
        // установки патча, без отдельного включения.
        if (($result['user']['role_code'] ?? '') === 'handler_admin') {
            $this->redirect('/handler-cabinet');
            return;
        }

        $this->redirect('/dashboard');
    }

    public function logout(array $params = []): void
    {
        $this->authService->logout('logout');
        View::setFlash('success', 'You have been logged out.');
        $this->redirect('/login');
    }
}