<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', __DIR__);

// Load .env file
// ИЗМЕНЕНО (17.08.2026, раунд 9): перенесено ВЫШЕ блока display_errors —
// раньше .env грузился ПОСЛЕ него, поэтому решение "показывать ли ошибки"
// не могло опираться на APP_DEBUG из .env (см. ниже).
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

// ИСПРАВЛЕНО (17.08.2026, раунд 9): раньше display_errors/display_startup_errors
// включались БЕЗУСЛОВНО (плюс ниже, в обработчике исключений, ветка с полным
// stack trace была включена через `if (true)` — вторая, "безопасная" ветка
// была фактически мёртвым кодом). В сумме это значило, что ЛЮБАЯ необработанная
// ошибка — включая на публичных страницах без входа, например /verify/XXXX —
// показывала любому посетителю сайта полный stack trace: пути на сервере,
// номера строк, внутреннюю логику приложения. Теперь это управляется тем же
// APP_DEBUG, что уже был заведён в config/app.php ('debug' => ...), но нигде
// реально не использовался. По умолчанию (APP_DEBUG не задан или не "true")
// ошибки не показываются пользователю — только логируются в файл, как и
// раньше. Включить видимый trace для отладки — оставить в .env строку
// APP_DEBUG=true (только на тестовом окружении, не на боевом сервере).
$appDebug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('display_startup_errors', $appDebug ? '1' : '0');
error_reporting(E_ALL);

// ИСПРАВЛЕНО (14.08.2026, п.4): нигде в приложении не был явно задан часовой
// пояс. PHP по умолчанию использует часовой пояс сервера (часто UTC), а
// DeadlineService сравнивает время из PHP (strtotime/DateTimeImmutable) со
// временем из MySQL (NOW()) — при рассинхроне поясов между веб-сервером и
// БД контроль сроков документооборота считает опоздания/остаток времени
// неверно (расхождение на величину сдвига пояса, для Ташкента — 5 часов).
// Аэропорт работает по времени Ташкента, поэтому фиксируем пояс явно.
date_default_timezone_set('Asia/Tashkent');

// Autoloader
require_once BASE_PATH . '/vendor/autoload.php';

use App\Core\Router;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Core\Lang;

// Boot database
Database::getInstance();

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('forma_a_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Boot i18n — must be AFTER session_start()
Lang::init();

// Load routes
$router = new Router();
require_once BASE_PATH . '/routes/web.php';

// Dispatch
$request  = new Request();
$response = new Response();

try {
    $router->dispatch($request, $response);
} catch (\Throwable $e) {
    $config  = require BASE_PATH . '/config/app.php';
    $logFile = $config['log_path'] . '/error-' . date('Y-m-d') . '.log';
    $msg = '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL
        . $e->getTraceAsString() . PHP_EOL;
    @file_put_contents($logFile, $msg, FILE_APPEND);

    http_response_code(500);
    // ИСПРАВЛЕНО (17.08.2026, раунд 9): было `if (true)` — ветка с полным
    // stack trace показывалась ВСЕГДА, "безопасная" ветка ниже не срабатывала
    // никогда. Теперь показывается только при APP_DEBUG=true (см. $appDebug
    // выше) — обычным пользователям на боевом сервере видно только общий
    // текст, полная трассировка по-прежнему пишется в лог-файл (см. выше).
    if ($appDebug) {
        echo '<pre style="padding:2rem;font-size:13px;">' . htmlspecialchars($msg) . '</pre>';
    } else {
        echo '<div style="font-family:sans-serif;padding:4rem;text-align:center;">
            <h1 style="color:#dc2626;">500 — Server Error</h1>
            <p>Something went wrong. Please try again or contact administrator.</p>
            <a href="/dashboard" style="color:#2563eb;">← Dashboard</a>
          </div>';
    }
}
