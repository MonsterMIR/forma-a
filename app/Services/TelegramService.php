<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;

/**
 * ДОБАВЛЕНО (18.09.2026, раунд 46, п.4). Минимальная обёртка над Telegram
 * Bot API (через уже установленный guzzlehttp/guzzle — отдельный composer-
 * пакет для этого не нужен). Мягкий отказ на каждом шаге, как и у
 * NotificationService::sendPush()/MailerService — если бот не настроен
 * (config/telegram.php) или у получателя нет chat_id, просто ничего не
 * отправляем.
 */
class TelegramService
{
    private array $config;

    public function __construct()
    {
        $file = defined('BASE_PATH') ? BASE_PATH . '/config/telegram.php' : __DIR__ . '/../../config/telegram.php';
        $this->config = file_exists($file) ? (require $file) : [];
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['enabled']) && !empty($this->config['bot_token']);
    }

    public function sendMessage(string $chatId, string $text): bool
    {
        if (!$this->isConfigured() || $chatId === '') {
            return false;
        }

        try {
            $client = new Client(['timeout' => 10]);
            $response = $client->post(
                "https://api.telegram.org/bot{$this->config['bot_token']}/sendMessage",
                [
                    'json' => [
                        'chat_id'    => $chatId,
                        'text'       => $text,
                        'parse_mode' => 'HTML',
                    ],
                ]
            );
            $body = json_decode((string)$response->getBody(), true);
            return !empty($body['ok']);
        } catch (\Throwable $e) {
            error_log('TelegramService::sendMessage error: ' . $e->getMessage());
            return false;
        }
    }

    /** Ссылка "Привязать Telegram" для профиля — открывает чат с ботом с уже готовым /start <token>. */
    public function getLinkUrl(string $linkToken): ?string
    {
        if (empty($this->config['bot_username'])) {
            return null;
        }
        return 'https://t.me/' . $this->config['bot_username'] . '?start=' . $linkToken;
    }
}
