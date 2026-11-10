<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Models\Notification;
use App\Models\PushSubscription;

/**
 * ДОБАВЛЕНО (06.08.2026): единая точка рассылки уведомлений.
 *
 * Пишет в таблицу notifications (это и есть колокольчик в шапке — работает
 * всегда, без внешних зависимостей) и, если установлен Composer-пакет
 * minishlink/web-push и в .env заданы VAPID-ключи, дополнительно шлёт
 * настоящий Web Push в браузер получателя.
 *
 * Если пакет ещё не установлен (composer require minishlink/web-push
 * guzzlehttp/guzzle php-http/guzzle7-adapter) — sendPush() просто тихо
 * выходит, ничего не ломается, уведомления всё равно видны в колокольчике.
 */
class NotificationService
{
    private Notification $notificationModel;
    private PushSubscription $subscriptionModel;
    private \PDO $db;

    /** Роль службы → человекочитаемое имя, для текста уведомлений. */
    private const ROLE_NAMES = [
        'sop' => 'СОП', 'spo' => 'СПО', 'sst' => 'ССТ',
        'aero' => 'Аэродромная служба', 'spasop' => 'СПАСОП',
        'cok' => 'ЦОК', 'finance' => 'Финансовая служба',
        'commercial' => 'Коммерческая служба', 'supervisor' => 'Супервайзер',
        'admin' => 'Администратор',
        // ДОБАВЛЕНО (14.09.2026, раунд 41): новые владельцы услуг после
        // перераспределения — пока без личных кабинетов (см. ROLE_FLIGHT_URL
        // ниже: сознательно не добавлены туда, реальных получателей ещё нет).
        'gsm' => 'ГСМ', 'medical' => 'Здрав.пункт', 'balance' => 'ОЦ',
    ];

    /**
     * Роль службы → страница рейса именно у этой службы (куда вести из уведомления).
     *
     * ДОБАВЛЕНО (14.09.2026, раунд 41): gsm/medical/balance НЕ включены сюда
     * намеренно — у этих новых служб пока нет ни личных кабинетов, ни
     * отдельной страницы-журнала по рейсу (в отличие от sop/spo/sst/aero/
     * spasop, у каждой из которых есть свой раздел). notifyPreorderCreated()
     * ниже уже устроен так, что роль без записи здесь просто тихо
     * пропускается — уведомление никому не потеряется, оно просто не для
     * кого пока отправлять. Как только у одной из этих служб появится
     * личный кабинет и страница — добавить сюда её URL.
     */
    private const ROLE_FLIGHT_URL = [
        'sop'    => '/manifests/flight/%d',
        'spo'    => '/tech-cards/flight/%d',
        'sst'    => '/equipment-logs/flight/%d',
        'aero'   => '/parking-acts/flight/%d',
        'spasop' => '/emergency-logs/flight/%d',
    ];

    public function __construct()
    {
        $this->notificationModel = new Notification();
        $this->subscriptionModel = new PushSubscription();
        $this->db = Database::getInstance();
    }

    /**
     * Предзаказ ЦОК создан/дополнен — уведомляем каждую вовлечённую службу.
     * $serviceIds — ID услуг, которые реально ДОБАВИЛИСЬ в план сейчас
     * (не весь список отмеченного, а именно дельта) — иначе при каждом
     * открытии чек-листа служба получала бы уведомление заново.
     */
    public function notifyPreorderCreated(int $flightId, array $serviceIds, ?int $actorUserId): void
    {
        $serviceIds = array_values(array_unique(array_map('intval', $serviceIds)));
        if (empty($serviceIds)) return;

        $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT owner_role FROM services
             WHERE id IN ($placeholders) AND owner_role IS NOT NULL"
        );
        $stmt->execute($serviceIds);
        $roles = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'owner_role');
        if (empty($roles)) return;

        $flight = $this->flightBrief($flightId);
        if (!$flight) return;

        foreach ($roles as $role) {
            // ЦОК/финансы/коммерция сами инициируют предзаказ — их не оповещаем.
            if (!isset(self::ROLE_FLIGHT_URL[$role])) continue;

            $url = sprintf(self::ROLE_FLIGHT_URL[$role], $flightId);
            $this->notifyRole(
                $role,
                'preorder_created',
                'Новый предзаказ — рейс ' . $flight['flight_number'],
                'ЦОК заказал услуги/транспорт по рейсу ' . $flight['flight_number']
                    . ' от ' . $flight['flight_date'] . '. Проверьте страницу рейса.',
                $url,
                $flightId,
                $actorUserId
            );
        }
    }

    /**
     * Служба нажала «Техника выехала» по конкретной позиции — уведомляем
     * всех активных супервайзеров (супервайзер не привязан к конкретному
     * рейсу — на смене их может быть несколько на разных бортах).
     */
    public function notifyVehicleDispatched(int $flightId, string $serviceName, string $ownerRole, int $actorUserId): void
    {
        $flight = $this->flightBrief($flightId);
        if (!$flight) return;

        $roleName = self::ROLE_NAMES[$ownerRole] ?? $ownerRole;
        $this->notifyRole(
            'supervisor',
            'vehicle_dispatched',
            'Выехала техника — рейс ' . $flight['flight_number'],
            $roleName . ': «' . $serviceName . '» выехала к борту.',
            '/supervisor/flights/' . $flightId,
            $flightId,
            $actorUserId
        );
    }

    /**
     * Обе подписи на планшете собраны (супервайзер + представитель
     * авиакомпании) — акт ждёт подтверждения ЦОК.
     */
    public function notifyActReadyForConfirmation(int $flightId, int $actId, string $actNumber): void
    {
        $flight = $this->flightBrief($flightId);
        $flightLabel = $flight ? $flight['flight_number'] : ('рейс #' . $flightId);

        $this->notifyRole(
            'cok',
            'act_ready_for_confirmation',
            'Акт подписан на планшете — ' . $flightLabel,
            'Акт № ' . $actNumber . ' подписан супервайзером и представителем авиакомпании на перроне. Проверьте и подтвердите.',
            '/acts/' . $actId,
            $flightId,
            null
        );
    }

    /**
     * ДОБАВЛЕНО (21.09.2026, раунд 49, п.1): пересменка супервайзеров —
     * супервайзер А передаёт обслуживание рейса супервайзеру Б. Уведомляем
     * ТОЛЬКО принимающего (не всю роль супервайзеров, как notifyRole) —
     * это и есть отличие notifyUser() от notifyRole() ниже.
     */
    public function notifySupervisorHandover(int $flightId, int $fromUserId, int $toUserId, ?string $note): void
    {
        $flight = $this->flightBrief($flightId);
        $flightLabel = $flight ? $flight['flight_number'] : ('рейс #' . $flightId);

        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$fromUserId]);
        $fromName = (string)($stmt->fetchColumn() ?: ('пользователь #' . $fromUserId));

        $body = $fromName . ' передал(а) вам обслуживание рейса ' . $flightLabel . '.';
        if ($note !== null && $note !== '') {
            $body .= ' Примечание: ' . $note;
        }

        $this->notifyUser(
            $toUserId,
            'supervisor_handover',
            'Передача рейса — ' . $flightLabel,
            $body,
            '/supervisor/flights/' . $flightId,
            $flightId
        );
    }

    // ── Внутреннее ──────────────────────────────────────────────────────

    /** Уведомить ОДНОГО конкретного пользователя (не всю роль, см. notifyRole()). */
    private function notifyUser(int $userId, string $type, string $title, string $body, ?string $url, ?int $flightId): void
    {
        $stmt = $this->db->prepare("SELECT id, email, telegram_chat_id FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$userId]);
        $recipient = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$recipient) return;

        $notifId = $this->notificationModel->create([
            'recipient_user_id' => $userId,
            'flight_id'         => $flightId,
            'type'              => $type,
            'title'             => $title,
            'body'              => $body,
            'url'               => $url,
        ]);
        $this->sendPush($userId, $notifId, $title, $body, $url);

        $mailer = new MailerService();
        if ($mailer->isConfigured() && !empty($recipient['email'])) {
            $mailer->send($recipient['email'], '', $title, '<p>' . htmlspecialchars($body) . '</p>');
        }
        $telegram = new TelegramService();
        if ($telegram->isConfigured() && !empty($recipient['telegram_chat_id'])) {
            $telegram->sendMessage($recipient['telegram_chat_id'], "<b>{$title}</b>\n{$body}");
        }
    }

    private function flightBrief(int $flightId): ?array
    {
        $stmt = $this->db->prepare("SELECT flight_number, flight_date FROM flights WHERE id = ? LIMIT 1");
        $stmt->execute([$flightId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * ДОБАВЛЕНО (18.09.2026, раунд 46, п.4): при создании рейса ЦОК —
     * уведомить супервайзера, СОП и представителя хендлера (email/Telegram),
     * чтобы заранее подготовиться к рейсу. См. анализ раунда 46, п.4.
     *
     * Представитель хендлера — по email всем активным сотрудникам хендлера,
     * однозначно привязанного к авиакомпании этого рейса (см.
     * HandlerService::getCandidatesForAirline()). Если к авиакомпании явно
     * привязан не один хендлер (или ни одного) — заранее неизвестно, кому
     * именно писать, поэтому это письмо просто не отправляется (не
     * ошибка — см. анализ раунда 46, рекомендация завести "основного
     * контакта" на сотрудника, чтобы снять эту неоднозначность).
     */
    public function notifyFlightCreated(int $flightId, int $actorUserId): void
    {
        $flight = $this->flightBrief($flightId);
        if (!$flight) return;

        $stmt = $this->db->prepare("SELECT airline_id FROM flights WHERE id = ? LIMIT 1");
        $stmt->execute([$flightId]);
        $airlineId = (int)($stmt->fetchColumn() ?: 0);

        $title = 'Новый рейс — ' . $flight['flight_number'];
        $body  = 'ЦОК создал рейс ' . $flight['flight_number'] . ' от ' . $flight['flight_date'] . '. Подготовьтесь заранее.';
        $url   = '/flights/' . $flightId;

        $this->notifyRole('supervisor', 'flight_created', $title, $body, $url, $flightId, $actorUserId);
        $this->notifyRole('sop', 'flight_created', $title, $body, $url, $flightId, $actorUserId);

        if ($airlineId > 0) {
            $this->notifyHandlerOfFlightCreated($airlineId, $flight, $flightId);
        }
    }

    private function notifyHandlerOfFlightCreated(int $airlineId, array $flight, int $flightId): void
    {
        $candidates = (new HandlerService())->getCandidatesForAirline($airlineId);
        // Отправляем, только если однозначно определён РОВНО один хендлер —
        // иначе непонятно, чьих сотрудников уведомлять (см. докблок выше).
        if (!($candidates['matchedByAirline'] ?? false) || count($candidates['handlers']) !== 1) {
            return;
        }
        $handler = $candidates['handlers'][0];
        $mailer  = new MailerService();
        if (!$mailer->isConfigured()) return;

        $subject = 'Новый рейс — ' . $flight['flight_number'] . ' (' . $flight['flight_date'] . ')';
        $html = '<p>Здравствуйте!</p><p>ЦОК аэропорта создал рейс <b>' . htmlspecialchars($flight['flight_number'])
            . '</b> от ' . htmlspecialchars($flight['flight_date']) . '. Пожалуйста, подготовьте всё необходимое заранее.</p>';

        foreach ($handler['employees'] ?? [] as $employee) {
            if (empty($employee['email'])) continue;
            $mailer->send($employee['email'], $employee['full_name'], $subject, $html);
        }
    }

    /** Разослать всем активным пользователям указанной роли (кроме автора действия, если задан). */
    private function notifyRole(
        string $roleCode, string $type, string $title, string $body,
        ?string $url, ?int $flightId, ?int $excludeUserId
    ): void {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.email, u.telegram_chat_id FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE r.code = ? AND u.is_active = 1"
        );
        $stmt->execute([$roleCode]);
        $recipients = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($excludeUserId !== null) {
            $recipients = array_values(array_filter($recipients, fn($r) => (int)$r['id'] !== $excludeUserId));
        }
        if (empty($recipients)) return;

        $mailer   = new MailerService();
        $telegram = new TelegramService();
        $mailerReady   = $mailer->isConfigured();
        $telegramReady = $telegram->isConfigured();

        foreach ($recipients as $recipient) {
            $userId  = (int)$recipient['id'];
            $notifId = $this->notificationModel->create([
                'recipient_user_id' => $userId,
                'flight_id'         => $flightId,
                'type'              => $type,
                'title'             => $title,
                'body'              => $body,
                'url'               => $url,
            ]);
            $this->sendPush($userId, $notifId, $title, $body, $url);

            // ДОБАВЛЕНО (18.09.2026, раунд 46, п.4): те же самые каналы
            // мягкого отказа, что и push выше — email/Telegram у службы
            // и так уже могут быть не заполнены (см. ProfileController).
            if ($mailerReady && !empty($recipient['email'])) {
                $mailer->send($recipient['email'], '', $title, '<p>' . htmlspecialchars($body) . '</p>');
            }
            if ($telegramReady && !empty($recipient['telegram_chat_id'])) {
                $telegram->sendMessage($recipient['telegram_chat_id'], "<b>{$title}</b>\n{$body}");
            }
        }
    }

    /**
     * Отправка Web Push. Мягкий отказ на каждом шаге: если библиотека не
     * установлена, ключи не заданы, или у пользователя нет подписок —
     * просто выходим. Запись в notifications уже сделана в любом случае —
     * колокольчик в шапке работает независимо от push.
     */
    private function sendPush(int $userId, int $notifId, string $title, string $body, ?string $url): void
    {
        if (!class_exists(\Minishlink\WebPush\WebPush::class)) {
            return;
        }

        $configFile = BASE_PATH . '/config/push.php';
        if (!file_exists($configFile)) return;
        $config = require $configFile;
        if (empty($config['vapid_public_key']) || empty($config['vapid_private_key'])) {
            return;
        }

        $subs = $this->subscriptionModel->findByUser($userId);
        if (empty($subs)) return;

        try {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject'    => $config['vapid_subject'],
                    'publicKey'  => $config['vapid_public_key'],
                    'privateKey' => $config['vapid_private_key'],
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('NotificationService::sendPush WebPush init error: ' . $e->getMessage());
            return;
        }

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $url ?? '/notifications',
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subs as $sub) {
            try {
                $webPush->queueNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint' => $sub['endpoint'],
                        'keys'     => [
                            'p256dh' => $sub['p256dh_key'],
                            'auth'   => $sub['auth_key'],
                        ],
                    ]),
                    $payload
                );
            } catch (\Throwable $e) {
                error_log('NotificationService::sendPush queue error: ' . $e->getMessage());
            }
        }

        $anySent = false;
        try {
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();
                if ($report->isSuccess()) {
                    $this->subscriptionModel->touchUsed($endpoint);
                    $anySent = true;
                } elseif ($report->isSubscriptionExpired()) {
                    // Браузер отписал/протух endpoint — чистим, иначе будем долбить впустую.
                    $this->subscriptionModel->deleteByEndpoint($endpoint);
                } else {
                    error_log('NotificationService::sendPush failed for ' . $endpoint . ': ' . $report->getReason());
                }
            }
        } catch (\Throwable $e) {
            error_log('NotificationService::sendPush flush error: ' . $e->getMessage());
        }

        if ($anySent) {
            $this->notificationModel->markPushSent($notifId);
        }
    }
}
