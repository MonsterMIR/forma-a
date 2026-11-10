<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ActivityLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Models\User;
use App\Services\TelegramService;

/**
 * ДОБАВЛЕНО (18.09.2026, раунд 46, п.3): "Личные данные" — раздел личного
 * кабинета, где ЛЮБОЙ авторизованный пользователь (не только admin) может
 * сам указать/поправить свой email, телефон и телеграм — то, чего раньше
 * можно было добиться только через admin → /users/{id}/edit.
 *
 * Смена и фамилии, и роли, и пароля — сознательно НЕ входят сюда: пароль
 * меняется отдельно (см. AuthController, если есть), роль/имя — прерогатива
 * admin (см. UserController). Здесь только контактные данные, нужные для
 * автоматических писем/уведомлений (см. NotificationService, MailerService).
 *
 * ДОБАВЛЕНО (30.10.2026, раунд 139): сюда же — личный выбор стиля
 * дашборда (классический / А / Б / В), см. dashboard/index.php. Доступен
 * только ролям admin/cok — альтернативные варианты сделаны только для
 * дашборда ЦОК (_cok_a/_cok_b/_cok_c). Как и остальные поля на этой
 * странице, это личная настройка самого пользователя, не админская.
 */
class ProfileController extends Controller
{
    private User $userModel;

    /** Роли, для которых на дашборде есть альтернативные стили (А/Б/В). */
    private const DASHBOARD_STYLE_ROLES = ['admin', 'cok'];
    private const DASHBOARD_STYLE_VALUES = ['classic', 'a', 'b', 'c'];

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->userModel = new User();
    }

    public function edit(array $params = []): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $fresh = $this->userModel->findWithRole((int)$user['id']);

        // ДОБАВЛЕНО (18.09.2026, раунд 46, п.4): ссылка "Привязать Telegram".
        // Просто вписать @username (поле telegram_username выше) недостаточно
        // для реальной отправки ботом — нужен chat_id, который узнаётся
        // только после того, как пользователь сам нажмёт Start у бота (см.
        // TelegramController::webhook()). Поэтому при каждом заходе на
        // страницу, если chat_id ещё не привязан, готовим одноразовый токен.
        $telegramLinkUrl = null;
        $telegram = new TelegramService();
        if ($telegram->isConfigured() && empty($fresh['telegram_chat_id'])) {
            $token = $fresh['telegram_link_token'] ?? null;
            if (!$token) {
                $token = bin2hex(random_bytes(16));
                $this->userModel->update((int)$user['id'], ['telegram_link_token' => $token]);
            }
            $telegramLinkUrl = $telegram->getLinkUrl($token);
        }

        $this->render('profile/edit', [
            'title'               => 'Личные данные',
            'me'                  => $fresh,
            'telegramLinkUrl'     => $telegramLinkUrl,
            'canPickDashboardStyle' => in_array($fresh['role_code'] ?? '', self::DASHBOARD_STYLE_ROLES, true),
        ]);
    }

    public function update(array $params = []): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->redirect('/login');
            return;
        }

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/profile');
            return;
        }

        $data = $this->request->all();

        $validator = new Validator($data);
        if (!empty($data['email'])) {
            $validator->email('email', 'Email');
        }
        if (!empty($data['telegram_username'])) {
            $validator->maxLength('telegram_username', 64, 'Телеграм');
        }
        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/profile');
            return;
        }

        $email = !empty($data['email']) ? trim($data['email']) : null;
        if ($email !== null && $this->userModel->emailExists($email, (int)$user['id'])) {
            View::setFlash('error', 'Этот email уже используется другим пользователем.');
            $this->redirect('/profile');
            return;
        }

        // Телеграм — принимаем и "@username", и "username", храним без "@".
        $telegram = null;
        if (!empty($data['telegram_username'])) {
            $telegram = ltrim(trim($data['telegram_username']), '@');
        }

        $updateData = [
            'email'             => $email,
            'phone'             => !empty($data['phone']) ? trim($data['phone']) : null,
            'telegram_username' => $telegram,
        ];

        // ДОБАВЛЕНО (30.10.2026, раунд 139): стиль дашборда — только для
        // ролей, у которых он вообще есть (см. DASHBOARD_STYLE_ROLES), и
        // только одно из известных значений (защита от произвольной
        // строки, даже если форму отредактируют в браузере).
        if (
            in_array($user['role_code'] ?? '', self::DASHBOARD_STYLE_ROLES, true)
            && isset($data['dashboard_style'])
            && in_array($data['dashboard_style'], self::DASHBOARD_STYLE_VALUES, true)
        ) {
            $updateData['dashboard_style'] = $data['dashboard_style'];
        }

        $before = $this->userModel->find((int)$user['id']);
        $this->userModel->update((int)$user['id'], $updateData);

        ActivityLogger::log('user', (int)$user['id'], 'update_own_profile', 'success',
            'Пользователь обновил свои личные данные', $before, $updateData);

        View::setFlash('success', 'Личные данные обновлены.');
        $this->redirect('/profile');
    }
}
