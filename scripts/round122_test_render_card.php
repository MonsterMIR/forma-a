<?php
declare(strict_types=1);
// РАУНД 122 — рендер фрагмента плашки "Манифест подан" изолированно (без
// полного layout) на реальных данных из БД, чтобы поймать любые runtime-
// ошибки (undefined index, деление и т.п.) до того, как это увидит
// супервайзер на планшете. Read-only. Фрагмент scripts/round122_card_fragment.php
// — дословная копия блока из resources/views/service_orders/supervisor_flight.php
// (строки 183-225), чтобы тест проверял реальный, уже задеплоенный код, а
// не переписанную копию.

define('BASE_PATH', dirname(__DIR__));
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
        $_ENV[$k] = $v; putenv("$k=$v");
    }
}
require BASE_PATH . '/vendor/autoload.php';

use App\Models\PassengerManifest;

function renderCard($manifest): string {
    ob_start();
    include BASE_PATH . '/scripts/round122_card_fragment.php';
    return ob_get_clean();
}

$pm = new PassengerManifest();

$cases = [
    'submitted (flight 133, crew+transit only, no regular pax)' => $pm->getByFlightAndType(133, 'final'),
    'submitted (flight 120, pax adults)'                        => $pm->getByFlightAndType(120, 'final'),
    'no manifest at all (flight 125)'                           => $pm->getByFlightAndType(125, 'final'),
];

error_reporting(E_ALL);
ini_set('display_errors', '1');

foreach ($cases as $label => $manifest) {
    echo "=== $label ===\n";
    try {
        $html = renderCard($manifest);
        echo "OK, длина HTML: " . strlen($html) . " символов\n";
        echo str_contains($html, 'alert') ? "содержит alert — OK\n" : "НЕ содержит alert — ПРОВАЛ\n";
    } catch (\Throwable $e) {
        echo "ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
