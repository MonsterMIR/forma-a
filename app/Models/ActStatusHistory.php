<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class ActStatusHistory extends Model
{
    protected string $table = 'act_status_history';

    public function getByAct(int $actId): array
    {
        $stmt = $this->query(
            "SELECT ash.*, u.username, u.full_name
             FROM `act_status_history` ash
             LEFT JOIN `users` u ON u.id = ash.changed_by_user_id
             WHERE ash.act_id = ?
             ORDER BY ash.changed_at ASC",
            [$actId]
        );
        return $stmt->fetchAll();
    }

    public function record(int $actId, ?string $oldStatus, string $newStatus, ?int $userId, ?int $sessionId, ?string $reason = null): void
    {
        $this->insert([
            'act_id'              => $actId,
            'old_status'          => $oldStatus,
            'new_status'          => $newStatus,
            'changed_by_user_id'  => $userId,
            'session_id'          => $sessionId,
            'reason'              => $reason,
            'changed_at'          => date('Y-m-d H:i:s'),
        ]);
    }
}