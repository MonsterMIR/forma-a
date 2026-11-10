<?php

declare(strict_types=1);

namespace App\Core;

/**
 * INI-based translation system (Joomla-style)
 *
 * File format (resources/lang/en.ini):
 *   NAV_DASHBOARD="Dashboard"
 *   ACTS_TITLE="Acts"
 *   COMMON_SHOWING=":from–:to of :total records"
 *
 * Usage in views:
 *   __('NAV_DASHBOARD')
 *   __('COMMON_SHOWING', [':from' => 1, ':to' => 20, ':total' => 100])
 *
 * Adding new language:
 *   1. Create resources/lang/uz.ini
 *   2. Copy keys from en.ini, translate values
 *   Done. No PHP changes needed.
 */
class Lang
{
    private static array  $strings   = [];
    private static string $current   = 'ru';
    // Английский временно отключён по требованию заказчика (только RU интерфейс).
    // Чтобы вернуть мультиязычность: верните 'en' в $supported и включите переключатель в main.php.
    private static array  $supported = ['ru'];
    private static bool   $loaded    = false;

    public static function init(): void
    {
        // Язык зафиксирован на русском — определение по сессии/браузеру отключено.
        self::load(self::$current);
    }

    public static function set(string $lang): void
    {
        if (!in_array($lang, self::$supported, true)) return;
        self::$current  = $lang;
        self::$loaded   = false;
        self::$strings  = [];
        $_SESSION['lang'] = $lang;
        self::load($lang);
    }

    public static function get(): string
    {
        return self::$current;
    }

    /**
     * Translate a key with optional parameter substitution.
     *
     * @param string $key    INI key, e.g. 'ACTS_TITLE' or 'COMMON_SHOWING'
     * @param array  $params Replacement pairs, e.g. [':from' => 1, ':total' => 50]
     */
    public static function translate(string $key, array $params = []): string
    {
        if (!self::$loaded) {
            self::load(self::$current);
        }

        $text = self::$strings[$key] ?? $key;

        if (!empty($params)) {
            $text = str_replace(array_keys($params), array_values($params), $text);
        }

        return $text;
    }

    private static function load(string $lang): void
    {
        $file = BASE_PATH . '/resources/lang/' . $lang . '.ini';

        if (!file_exists($file)) {
            // Fallback to English
            $file = BASE_PATH . '/resources/lang/en.ini';
            if (!file_exists($file)) {
                self::$loaded = true;
                return;
            }
        }

        $parsed = parse_ini_file($file, false, INI_SCANNER_RAW);

        if ($parsed === false) {
            error_log("Lang: failed to parse {$file}");
            self::$loaded = true;
            return;
        }

        self::$strings = $parsed;
        self::$loaded  = true;
    }

    /**
     * Get list of supported languages with metadata.
     * Read from the [language] section in each INI file.
     */
    public static function getSupportedLanguages(): array
    {
        $result = [];
        foreach (self::$supported as $code) {
            $file = BASE_PATH . '/resources/lang/' . $code . '.ini';
            if (!file_exists($file)) continue;
            $parsed = parse_ini_file($file, true, INI_SCANNER_RAW);
            $result[$code] = [
                'code'   => $code,
                'name'   => $parsed['language']['name']   ?? strtoupper($code),
                'native' => $parsed['language']['native'] ?? strtoupper($code),
                'flag'   => $parsed['language']['flag']   ?? '',
            ];
        }
        return $result;
    }
}
