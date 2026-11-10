<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class SystemSetting extends Model
{
    protected string $table = 'system_settings';

    public function getValue(string $key, mixed $default = null): mixed
    {
        $stmt = $this->query(
            "SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = ? LIMIT 1",
            [$key]
        );
        $row = $stmt->fetch();
        if ($row === false) return $default;
        return $row['setting_value'] ?? $default;
    }

    public function setValue(string $key, ?string $value, ?int $userId = null): void
    {
        $stmt = $this->query(
            "SELECT id FROM `system_settings` WHERE `setting_key` = ? LIMIT 1",
            [$key]
        );
        $existing = $stmt->fetch();

        if ($existing) {
            $this->query(
                "UPDATE `system_settings`
                 SET `setting_value` = ?, `updated_by_user_id` = ?, `updated_at` = ?
                 WHERE `setting_key` = ?",
                [$value, $userId, date('Y-m-d H:i:s'), $key]
            );
        } else {
            $this->insert([
                'setting_key'         => $key,
                'setting_value'       => $value,
                'updated_by_user_id'  => $userId,
            ]);
        }
    }

    public function getAllSettings(): array
    {
        $stmt = $this->query(
            "SELECT ss.*, u.username AS updated_by_username
             FROM `system_settings` ss
             LEFT JOIN `users` u ON u.id = ss.updated_by_user_id
             ORDER BY ss.setting_key ASC"
        );
        return $stmt->fetchAll();
    }
}