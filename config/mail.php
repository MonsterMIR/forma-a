<?php

declare(strict_types=1);

/**
 * ДОБАВЛЕНО (18.09.2026, раунд 46, п.2): SMTP для автоматической отправки
 * копии акта представителю авиакомпании/хендлера при утверждении акта.
 *
 * По тому же принципу, что и config/push.php: пока MAIL_HOST/MAIL_USERNAME/
 * MAIL_PASSWORD не заданы в .env (или MAIL_ENABLED=false) — MailerService
 * просто ничего не отправляет и тихо выходит, ничего не ломая. Остальная
 * система (уведомления в колокольчике, сам акт) работает как обычно.
 *
 * Впишите в .env, когда будут реальные данные почтового ящика:
 *   MAIL_ENABLED=true
 *   MAIL_HOST=smtp.yourprovider.uz
 *   MAIL_PORT=587
 *   MAIL_ENCRYPTION=tls        (tls — STARTTLS на 587, ssl — прямой TLS на 465, пусто — без шифрования)
 *   MAIL_USERNAME=noreply@tvtairport.uz
 *   MAIL_PASSWORD=...
 *   MAIL_FROM_ADDRESS=noreply@tvtairport.uz
 *   MAIL_FROM_NAME="Forma A — TVT"
 */
return [
    'enabled'      => filter_var($_ENV['MAIL_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'host'         => $_ENV['MAIL_HOST'] ?? '',
    'port'         => (int)($_ENV['MAIL_PORT'] ?? 587),
    'encryption'   => $_ENV['MAIL_ENCRYPTION'] ?? 'tls', // 'tls' (STARTTLS), 'ssl' (implicit TLS) или '' (без шифрования)
    'username'     => $_ENV['MAIL_USERNAME'] ?? '',
    'password'     => $_ENV['MAIL_PASSWORD'] ?? '',
    'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? '',
    'from_name'    => $_ENV['MAIL_FROM_NAME'] ?? 'Forma A',
    'timeout'      => (int)($_ENV['MAIL_TIMEOUT'] ?? 15),
];
