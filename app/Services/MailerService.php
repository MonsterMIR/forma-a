<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ДОБАВЛЕНО (18.09.2026, раунд 46, п.2): минимальный SMTP-клиент без внешних
 * зависимостей (composer-пакетов) — в песочнице разработки нет доступа к
 * Packagist, а на боевом хостинге лишняя внешняя библиотека ради отправки
 * простого письма с вложением не нужна. Реализует ровно тот минимум SMTP
 * (EHLO/STARTTLS/AUTH LOGIN/MAIL FROM/RCPT TO/DATA), который принимают
 * практически все провайдеры (Gmail, Yandex, mail.uz и т.п.).
 *
 * Мягкий отказ на каждом шаге — по тому же принципу, что и
 * NotificationService::sendPush(): если в .env не заданы данные почты
 * (см. config/mail.php), send() просто возвращает false и ничего не
 * ломает — акт всё равно утверждается, письмо просто не уходит.
 */
class MailerService
{
    private array $config;

    public function __construct()
    {
        $file = defined('BASE_PATH') ? BASE_PATH . '/config/mail.php' : __DIR__ . '/../../config/mail.php';
        $this->config = file_exists($file) ? (require $file) : [];
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['enabled'])
            && !empty($this->config['host'])
            && !empty($this->config['from_address']);
    }

    /**
     * @param array<int, array{path:string, name:string, mime?:string}> $attachments
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, array $attachments = []): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("MailerService::send: invalid recipient address '{$toEmail}'");
            return false;
        }

        try {
            $socket = $this->connect();
            $this->expect($socket, 220);

            $localHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $this->command($socket, "EHLO {$localHost}", 250, true);

            if (($this->config['encryption'] ?? '') === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed.');
                }
                $this->command($socket, "EHLO {$localHost}", 250, true);
            }

            if (!empty($this->config['username'])) {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($this->config['username']), 334);
                $this->command($socket, base64_encode((string)$this->config['password']), 235);
            }

            $fromAddress = $this->config['from_address'];
            $this->command($socket, "MAIL FROM:<{$fromAddress}>", 250);
            $this->command($socket, "RCPT TO:<{$toEmail}>", 250);
            $this->command($socket, 'DATA', 354);

            $raw = $this->buildMessage($toEmail, $toName, $subject, $htmlBody, $attachments);
            fwrite($socket, $raw . "\r\n.\r\n");
            $this->expect($socket, 250);

            $this->command($socket, 'QUIT', 221);
            fclose($socket);

            return true;
        } catch (\Throwable $e) {
            error_log('MailerService::send error: ' . $e->getMessage());
            return false;
        }
    }

    /** @return resource */
    private function connect()
    {
        $host   = $this->config['host'];
        $port   = (int)($this->config['port'] ?? 587);
        $scheme = ($this->config['encryption'] ?? '') === 'ssl' ? 'ssl://' : '';
        $timeout = (int)($this->config['timeout'] ?? 15);

        $socket = @stream_socket_client(
            "{$scheme}{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!$socket) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, $timeout);
        return $socket;
    }

    /**
     * Отправляет команду и проверяет, что ответ начинается с ожидаемого
     * кода. $multiline=true — вычитываем весь блок ответа EHLO (несколько
     * строк "250-..." и последняя "250 ...").
     * @param resource $socket
     */
    private function command($socket, string $line, int $expectCode, bool $multiline = false): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expectCode, $multiline);
    }

    /** @param resource $socket */
    private function expect($socket, int $expectCode, bool $multiline = false): void
    {
        $line = '';
        do {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new \RuntimeException('SMTP: connection closed unexpectedly.');
            }
        } while ($multiline && isset($line[3]) && $line[3] === '-');

        $code = (int)substr($line, 0, 3);
        if ($code !== $expectCode) {
            throw new \RuntimeException("SMTP: expected {$expectCode}, got: " . trim($line));
        }
    }

    private function buildMessage(string $toEmail, string $toName, string $subject, string $htmlBody, array $attachments): string
    {
        $fromAddress = $this->config['from_address'];
        $fromName    = $this->config['from_name'] ?? $fromAddress;
        $boundary    = 'b46_' . bin2hex(random_bytes(12));

        $headers = [
            'From: ' . $this->encodeHeaderAddress($fromName, $fromAddress),
            'To: ' . $this->encodeHeaderAddress($toName, $toEmail),
            'Subject: ' . $this->encodeHeaderWord($subject),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'forma-a') . '>',
        ];

        if (empty($attachments)) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody));
        }

        $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

        $parts = "--{$boundary}\r\n";
        $parts .= "Content-Type: text/html; charset=UTF-8\r\n";
        $parts .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $parts .= chunk_split(base64_encode($htmlBody)) . "\r\n";

        foreach ($attachments as $att) {
            if (empty($att['path']) || !is_file($att['path'])) continue;
            $data = base64_encode((string)file_get_contents($att['path']));
            $mime = $att['mime'] ?? 'application/octet-stream';
            $name = $att['name'] ?? basename($att['path']);
            $parts .= "--{$boundary}\r\n";
            $parts .= "Content-Type: {$mime}; name=\"{$name}\"\r\n";
            $parts .= "Content-Transfer-Encoding: base64\r\n";
            $parts .= "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n";
            $parts .= chunk_split($data) . "\r\n";
        }
        $parts .= "--{$boundary}--";

        // Экранируем строки, начинающиеся с точки (dot-stuffing SMTP DATA) —
        // без этого письмо могло бы преждевременно оборваться на "\r\n.\r\n".
        $parts = preg_replace('/\r\n\./', "\r\n..", $parts);

        return implode("\r\n", $headers) . "\r\n\r\n" . $parts;
    }

    private function encodeHeaderAddress(string $name, string $address): string
    {
        return $this->encodeHeaderWord($name) . " <{$address}>";
    }

    private function encodeHeaderWord(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $text)) {
            return $text;
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
}
