<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\ActivityLogger;
use App\Core\SessionManager;
use App\Models\User;

class AuthService
{
    private User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    public function attempt(string $username, string $password, string $ip, string $userAgent): array
    {
        $user = $this->userModel->findByUsername($username);

        if (!$user) {
            ActivityLogger::log(
                'auth', null, 'login', 'failure',
                "Login failed: user '{$username}' not found"
            );
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }

        if (!$user['is_active']) {
            ActivityLogger::log(
                'auth', (int)$user['id'], 'login', 'failure',
                "Login failed: user '{$username}' is inactive"
            );
            return ['success' => false, 'error' => 'Your account is inactive. Contact administrator.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            ActivityLogger::log(
                'auth', (int)$user['id'], 'login', 'failure',
                "Login failed: wrong password for '{$username}'"
            );
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }

        // Create DB session
        $session = SessionManager::createDbSession((int)$user['id'], $ip, $userAgent);

        // Update last login
        $this->userModel->updateLastLogin((int)$user['id']);

        // Set PHP auth
        Auth::login($user, $session['token'], $session['id']);

        ActivityLogger::log(
            'auth', (int)$user['id'], 'login', 'success',
            "User '{$username}' logged in successfully"
        );

        return ['success' => true, 'user' => $user];
    }

    public function logout(string $reason = 'logout'): void
    {
        $user      = Auth::user();
        $sessionId = Auth::sessionDbId();

        if ($sessionId) {
            SessionManager::closeDbSession($sessionId, $reason);
        }

        if ($user) {
            ActivityLogger::log(
                'auth', (int)$user['id'], 'logout', 'success',
                "User '{$user['username']}' logged out"
            );
        }

        Auth::logout();
        session_destroy();
    }
}