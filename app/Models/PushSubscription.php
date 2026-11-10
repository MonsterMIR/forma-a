<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

/**
 * ДОБАВЛЕНО (06.08.2026): подписки Web Push (PushSubscription из браузера).
 * У одного пользователя может быть несколько активных подписок —
 * телефон, планшет, рабочий ПК — все получают push одновременно.
 */
class PushSubscription extends Model
{
    protected string $table = 'push_subscriptions';

    /**
     * Сохраняет/обновляет подписку. endpoint — естественный ключ (один
     * endpoint = одна пара браузер+устройство), поэтому UPSERT по хэшу.
     */
    public function upsert(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        $hash = hash('sha256', $endpoint);
        $existing = $this->query(
            "SELECT id FROM push_subscriptions WHERE endpoint_hash = ? LIMIT 1",
            [$hash]
        )->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            $this->query(
                "UPDATE push_subscriptions
                 SET user_id = ?, p256dh_key = ?, auth_key = ?, user_agent = ?, last_used_at = NOW()
                 WHERE id = ?",
                [$userId, $p256dh, $auth, $userAgent, $existing['id']]
            );
            return;
        }

        $this->insert([
            'user_id'       => $userId,
            'endpoint'      => $endpoint,
            'endpoint_hash' => $hash,
            'p256dh_key'    => $p256dh,
            'auth_key'      => $auth,
            'user_agent'    => $userAgent,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function deleteByEndpointValue(int $userId, string $endpoint): void
    {
        $this->query(
            "DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?",
            [$userId, hash('sha256', $endpoint)]
        );
    }

    /** Используется NotificationService при разборе "протухших" подписок из отчёта Web Push. */
    public function deleteByEndpoint(string $endpoint): void
    {
        $this->query("DELETE FROM push_subscriptions WHERE endpoint_hash = ?", [hash('sha256', $endpoint)]);
    }

    public function touchUsed(string $endpoint): void
    {
        $this->query(
            "UPDATE push_subscriptions SET last_used_at = NOW() WHERE endpoint_hash = ?",
            [hash('sha256', $endpoint)]
        );
    }

    public function findByUser(int $userId): array
    {
        return $this->query(
            "SELECT * FROM push_subscriptions WHERE user_id = ?",
            [$userId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countForUser(int $userId): int
    {
        $stmt = $this->query("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?", [$userId]);
        return (int)$stmt->fetchColumn();
    }
}
