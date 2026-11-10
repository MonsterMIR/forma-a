<?php

declare(strict_types=1);

/**
 * ДОБАВЛЕНО (06.08.2026): ключи VAPID для Web Push.
 * Сгенерировать: php scripts/generate_vapid_keys.php (один раз, после
 * composer require minishlink/web-push) — впишет готовые строки .env,
 * останется скопировать их в файл руками.
 *
 * Пока VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY не заданы — push просто не
 * отправляется (NotificationService тихо это обходит), колокольчик и
 * внутрисистемный журнал уведомлений при этом работают как обычно.
 */
return [
    'vapid_subject'    => $_ENV['VAPID_SUBJECT']     ?? 'mailto:admin@tvtairport.uz',
    'vapid_public_key'  => $_ENV['VAPID_PUBLIC_KEY']  ?? '',
    'vapid_private_key' => $_ENV['VAPID_PRIVATE_KEY'] ?? '',
];
