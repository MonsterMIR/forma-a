<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

/**
 * ДОБАВЛЕНО (18.09.2026, раунд 46, п.4): принимает вебхук от Telegram —
 * ровно один сценарий: пользователь открыл диалог с ботом по ссылке
 * "Привязать Telegram" (см. ProfileController/TelegramService::getLinkUrl())
 * и нажал Start. Telegram сам присылает сюда апдейт с командой
 * "/start <токен>" — по токену находим пользователя (см.
 * users.telegram_link_token, выданный на странице профиля) и сохраняем его
 * chat_id. Это ЕДИНСТВЕННЫЙ способ узнать chat_id — Bot API не даёт писать
 * пользователю просто по @username.
 *
 * Без AuthMiddleware по конструкции — этот URL дёргает сам Telegram, не
 * браузер пользователя (см. routes/web.php, инструкция по настройке
 * вебхука — config/telegram.php).
 */
class TelegramController extends Controller
{
    public function webhook(array $params = []): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $update = json_decode($raw, true);
        if (!is_array($update)) {
            $this->json(['ok' => true]);
            return;
        }

        $message = $update['message'] ?? null;
        $text    = trim((string)($message['text'] ?? ''));
        $chatId  = $message['chat']['id'] ?? null;

        if ($chatId !== null && str_starts_with($text, '/start ')) {
            $token = trim(substr($text, 7));
            if ($token !== '') {
                // Обновляем напрямую по токену — отдельный метод в модели не
                // заводим ради единственного места использования.
                $db = \App\Core\Database::getInstance();
                $upd = $db->prepare(
                    "UPDATE users SET telegram_chat_id = ?, telegram_link_token = NULL
                     WHERE telegram_link_token = ? LIMIT 1"
                );
                $upd->execute([(string)$chatId, $token]);

                if ($upd->rowCount() > 0) {
                    (new \App\Services\TelegramService())->sendMessage(
                        (string)$chatId,
                        '✅ Telegram привязан к вашему аккаунту в Forma A. Теперь уведомления о рейсах будут приходить сюда.'
                    );
                }
            }
        }

        // Telegram ожидает 200 OK на любой апдейт, иначе будет повторять доставку.
        $this->json(['ok' => true]);
    }
}
