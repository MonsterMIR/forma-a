<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use App\Models\UserSession;

/**
 * ДОБАВЛЕНО (07.11.2026, раунд 156, по прямому указанию пользователя):
 * аутентификация для мобильного приложения (Android) — первая версия,
 * пилотная роль admin (см. claude/analysis-2026-11-07-round156-*.md).
 *
 * Сознательно НЕ трогает существующий App\Core\Auth (куки/$_SESSION) —
 * веб-логин и всё, что на нём завязано, работает ровно как раньше. Вместо
 * этого — тот же самый механизм сессий, что уже используется под капотом
 * у Auth (таблица `user_sessions`, 64-символьный токен, см.
 * App\Core\SessionManager::createDbSession()), только токен передаётся не
 * через cookie, а явно в заголовке `Authorization: Bearer <token>` —
 * обычная схема для мобильных клиентов. Логин/логаут API выдают и гасят
 * ту же самую запись `user_sessions`, что и обычный веб-вход — то есть
 * администратор видит "активные сессии" пользователя одним списком,
 * независимо от того, вошёл он с телефона или с браузера (отдельной
 * инфраструктуры для API-токенов заводить не стали — она бы не отличалась
 * от уже существующей).
 */
class ApiAuth
{
    private static ?array $cachedUser = null;
    private static bool $resolved = false;

    public static function resolve(Request $request): ?array
    {
        if (self::$resolved) {
            return self::$cachedUser;
        }
        self::$resolved = true;

        $token = self::extractToken($request);
        if ($token === null || $token === '') {
            return null;
        }

        $sessionModel = new UserSession();
        $session = $sessionModel->findActiveByToken($token);
        if (!$session) {
            return null;
        }

        $config = require BASE_PATH . '/config/app.php';
        $lifetime = $config['session']['lifetime'];
        $lastActivity = strtotime($session['last_activity_at']);
        if (time() - $lastActivity > $lifetime) {
            return null;
        }

        $sessionModel->update($session['id'], ['last_activity_at' => date('Y-m-d H:i:s')]);

        $userModel = new User();
        $user = $userModel->findWithRole((int)$session['user_id']);
        if (!$user || !$user['is_active']) {
            return null;
        }

        $user['session_db_id'] = $session['id'];
        self::$cachedUser = $user;
        return self::$cachedUser;
    }

    public static function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header && preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
