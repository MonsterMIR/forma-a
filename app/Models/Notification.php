<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

/**
 * ДОБАВЛЕНО (06.08.2026): внутрисистемный журнал уведомлений — колокольчик
 * в шапке + источник данных для Web Push (см. NotificationService).
 */
class Notification extends Model
{
    protected string $table = 'notifications';

    public function create(array $data): int
    {
        return (int)$this->insert([
            'recipient_user_id' => (int)$data['recipient_user_id'],
            'flight_id'         => $data['flight_id'] ?? null,
            'type'              => $data['type'],
            'title'             => $data['title'],
            'body'              => $data['body'] ?? null,
            'url'               => $data['url'] ?? null,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    public function markPushSent(int $id): void
    {
        $this->query("UPDATE notifications SET push_sent_at = NOW() WHERE id = ? AND push_sent_at IS NULL", [$id]);
    }

    /**
     * Последние уведомления пользователя (для колокольчика) — непрочитанные
     * первыми, затем недавние прочитанные, всего не больше $limit.
     */
    public function getForUser(int $userId, int $limit = 20): array
    {
        return $this->query(
            "SELECT n.*, f.flight_number, f.flight_date
             FROM notifications n
             LEFT JOIN flights f ON f.id = n.flight_id
             WHERE n.recipient_user_id = ?
             ORDER BY (n.read_at IS NULL) DESC, n.created_at DESC
             LIMIT " . max(1, (int)$limit),
            [$userId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->query(
            "SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND read_at IS NULL",
            [$userId]
        );
        return (int)$stmt->fetchColumn();
    }

    public function markRead(int $id, int $userId): bool
    {
        $stmt = $this->query(
            "UPDATE notifications SET read_at = NOW() WHERE id = ? AND recipient_user_id = ? AND read_at IS NULL",
            [$id, $userId]
        );
        return $stmt->rowCount() > 0;
    }

    public function markAllRead(int $userId): int
    {
        $stmt = $this->query(
            "UPDATE notifications SET read_at = NOW() WHERE recipient_user_id = ? AND read_at IS NULL",
            [$userId]
        );
        return $stmt->rowCount();
    }

    /**
     * Статус доставки предзаказа по рейсу, сгруппированный по службе —
     * для дашборда супервайзера ("все ли службы получили уведомление").
     * Возвращает по одной строке на КАЖДОГО получателя (не агрегат),
     * контроллер сам решает, как группировать по роли на экране.
     */
    public function getDeliveryStatusForFlight(int $flightId, string $type): array
    {
        return $this->query(
            "SELECT n.recipient_user_id, n.read_at, n.push_sent_at, n.created_at,
                    u.full_name, r.code AS role_code, r.name AS role_name
             FROM notifications n
             JOIN users u ON u.id = n.recipient_user_id
             JOIN roles r ON r.id = u.role_id
             WHERE n.flight_id = ? AND n.type = ?
             ORDER BY r.code, u.full_name",
            [$flightId, $type]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }
}
