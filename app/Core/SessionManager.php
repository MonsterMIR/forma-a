<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\UserSession;

class SessionManager
{
    public static function createDbSession(int $userId, string $ip, string $userAgent): array
    {
        $token = bin2hex(random_bytes(32)); // 64 hex chars

        $model = new UserSession();
        $id = $model->insert([
            'user_id'          => $userId,
            'session_token'    => $token,
            'ip_address'       => $ip,
            'user_agent'       => $userAgent,
            'started_at'       => date('Y-m-d H:i:s'),
            'last_activity_at' => date('Y-m-d H:i:s'),
            'is_active'        => 1,
        ]);

        return ['id' => (int)$id, 'token' => $token];
    }

    public static function closeDbSession(int $sessionId, string $reason = 'logout'): void
    {
        $model = new UserSession();
        $model->update($sessionId, [
            'is_active'     => 0,
            'ended_at'      => date('Y-m-d H:i:s'),
            'logout_reason' => $reason,
        ]);
    }
}