<?php

declare(strict_types=1);

/**
 * ДОБАВЛЕНО (18.09.2026, раунд 46, п.4): Telegram-бот для уведомлений о
 * новых рейсах (супервайзер/представитель/СОП). Тот же принцип мягкого
 * отказа, что и у config/push.php и config/mail.php — пока TELEGRAM_BOT_TOKEN
 * не задан, TelegramService просто ничего не отправляет.
 *
 * Чтобы включить:
 *   1. Через @BotFather в Telegram создать бота, получить токен и username бота.
 *   2. Вписать в .env:
 *        TELEGRAM_ENABLED=true
 *        TELEGRAM_BOT_TOKEN=123456:AA...
 *        TELEGRAM_BOT_USERNAME=forma_a_tvt_bot   (без @, для ссылки-приглашения)
 *   3. Настроить вебхук один раз (с боевого сервера, где есть HTTPS):
 *        curl "https://api.telegram.org/bot<ТОКЕН>/setWebhook?url=https://forma.tvtairport.uz/telegram/webhook"
 *   4. Каждый пользователь один раз переходит по кнопке "Привязать Telegram"
 *      в своих "Личных данных" (см. ProfileController) — это откроет чат с
 *      ботом и после нажатия Start в системе сохранится его chat_id.
 *      Просто указать @username в профиле НЕДОСТАТОЧНО — Bot API не умеет
 *      писать по имени пользователя, только по chat_id.
 */
return [
    'enabled'      => filter_var($_ENV['TELEGRAM_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'bot_token'    => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
    'bot_username' => $_ENV['TELEGRAM_BOT_USERNAME'] ?? '',
];
