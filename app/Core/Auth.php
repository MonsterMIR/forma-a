<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use App\Models\UserSession;

class Auth
{
    private static ?array $cachedUser = null;

    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        $token = $_SESSION['session_token'] ?? null;
        if (!$token) return null;

        $sessionModel = new UserSession();
        $session = $sessionModel->findActiveByToken($token);
        if (!$session) {
            self::clearSession();
            return null;
        }

        // Check lifetime
        $config = require BASE_PATH . '/config/app.php';
        $lifetime = $config['session']['lifetime'];
        $lastActivity = strtotime($session['last_activity_at']);
        if (time() - $lastActivity > $lifetime) {
            self::clearSession();
            return null;
        }

        // Update last activity
        $sessionModel->update($session['id'], ['last_activity_at' => date('Y-m-d H:i:s')]);

        $userModel = new User();
        $user = $userModel->findWithRole((int)$session['user_id']);
        if (!$user || !$user['is_active']) {
            self::clearSession();
            return null;
        }

        $user['session_db_id'] = $session['id'];
        self::$cachedUser = $user;
        return self::$cachedUser;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int)$user['id'] : null;
    }

    public static function sessionDbId(): ?int
    {
        $user = self::user();
        return $user ? (int)$user['session_db_id'] : null;
    }

    public static function hasRole(string|array $roles): bool
    {
        $user = self::user();
        if (!$user) return false;
        $allowed = is_array($roles) ? $roles : [$roles];
        return in_array($user['role_code'], $allowed, true);
    }

    public static function login(array $user, string $token, int $sessionDbId): void
    {
        session_regenerate_id(true);
        $_SESSION['session_token'] = $token;
        $user['session_db_id']     = $sessionDbId;
        self::$cachedUser          = $user;
    }

    public static function logout(): void
    {
        self::clearSession();
    }

    private static function clearSession(): void
    {
        self::$cachedUser = null;
        unset($_SESSION['session_token']);
    }
}