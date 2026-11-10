<?php

declare(strict_types=1);

namespace App\Core;

class Csrf
{
    private const SESSION_KEY = '_csrf_token';
    private const FIELD_NAME  = '_csrf';

    public static function generate(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function token(): string
    {
        return self::generate();
    }

    public static function field(): string
    {
        $token = self::token();
        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="' . htmlspecialchars($token) . '">';
    }

    public static function verify(string $token): bool
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? '';
        return hash_equals($stored, $token);
    }

    /**
     * ДОБАВЛЕНО (аудит 10.07.2026): часть fetch()-запросов шлёт JSON-тело
     * (Content-Type: application/json), которое НЕ попадает в $_POST — токен
     * из тела там прочитать нельзя в принципе. Для таких запросов проверяем
     * заголовок X-CSRF-Token дополнительно к обычному полю формы.
     */
    public static function verifyRequest(Request $request): bool
    {
        $token = $request->input(self::FIELD_NAME, '');
        if ($token === '') {
            $token = $request->header('X-CSRF-Token') ?? '';
        }
        return self::verify((string)$token);
    }

    public static function fieldName(): string
    {
        return self::FIELD_NAME;
    }
}