<?php
declare(strict_types=1);

/**
 * ДОБАВЛЕНО (06.08.2026): одноразовая генерация ключей VAPID для Web Push.
 *
 * Запуск (по SSH, из корня public_html, ПОСЛЕ composer require):
 *   php scripts/generate_vapid_keys.php
 *
 * Выведет готовые строки — скопировать в .env и сохранить файл.
 * Ключи создаются один раз и больше не меняются (иначе все существующие
 * подписки браузеров станут недействительны и пользователям придётся
 * заново разрешать уведомления).
 */

require __DIR__ . '/../vendor/autoload.php';

if (!class_exists(\Minishlink\WebPush\VAPID::class)) {
    fwrite(STDERR, "Библиотека minishlink/web-push не установлена.\n");
    fwrite(STDERR, "Выполните: composer require minishlink/web-push guzzlehttp/guzzle php-http/guzzle7-adapter\n");
    exit(1);
}

$keys = \Minishlink\WebPush\VAPID::createVapidKeys();

echo "Готово. Добавьте эти строки в .env (в корне public_html):\n\n";
echo "VAPID_SUBJECT=mailto:admin@tvtairport.uz\n";
echo "VAPID_PUBLIC_KEY={$keys['publicKey']}\n";
echo "VAPID_PRIVATE_KEY={$keys['privateKey']}\n\n";
echo "VAPID_SUBJECT можно заменить на реальный email администратора системы —\n";
echo "браузеры Chrome/Firefox иногда требуют его при первой подписке.\n";
