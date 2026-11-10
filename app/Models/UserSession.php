<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class UserSession extends Model
{
    protected string $table = 'user_sessions';

    public function findActiveByToken(string $token): ?array
    {
        $stmt = $this->query(
            "SELECT * FROM `user_sessions`
             WHERE `session_token` = ? AND `is_active` = 1 LIMIT 1",
            [$token]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getActiveSessionsForUser(int $userId): array
    {
        $stmt = $this->query(
            "SELECT * FROM `user_sessions`
             WHERE `user_id` = ? AND `is_active` = 1
             ORDER BY `started_at` DESC",
            [$userId]
        );
        return $stmt->fetchAll();
    }

    public function closeAllForUser(int $userId, string $reason = 'forced'): void
    {
        $this->query(
            "UPDATE `user_sessions`
             SET `is_active` = 0, `ended_at` = ?, `logout_reason` = ?
             WHERE `user_id` = ? AND `is_active` = 1",
            [date('Y-m-d H:i:s'), $reason, $userId]
        );
    }
}