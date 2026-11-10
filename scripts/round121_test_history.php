<?php
declare(strict_types=1);
// РАУНД 121 (24.10.2026) — проверка Flight::getHistoryByRegistration()
// и AircraftController::renderHistoryHtml() (через reflection, т.к. метод
// приватный) на реальных данных борта T7AAA7 (16 рейсов в базе — хороший
// кейс для проверки, что попап покажет всю историю, не только последний
// визит). Read-only, ничего не пишет и не меняет.

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

use App\Core\Request;
use App\Core\Response;
use App\Models\Flight;
use App\Controllers\AircraftController;

$flightModel = new Flight();
$rows = $flightModel->getHistoryByRegistration('T7AAA7');
echo "Строк для T7AAA7: " . count($rows) . "\n";
foreach (array_slice($rows, 0, 3) as $r) {
    echo "  #{$r['id']} {$r['flight_number']} {$r['operation_type']} {$r['flight_date']} МС={$r['mc_number']} act_status=" . ($r['act_status'] ?? 'NULL') . "\n";
}

$empty = $flightModel->getHistoryByRegistration('ZZZZ-NOPE-999');
echo "\nСтрок для несуществующей регистрации: " . count($empty) . " (ожидаем 0)\n";

$controller = new AircraftController(new Request(), new Response());
$ref = new ReflectionMethod($controller, 'renderHistoryHtml');
$ref->setAccessible(true);
$html = $ref->invoke($controller, $rows);
echo "\nДлина HTML для T7AAA7: " . strlen($html) . " символов\n";
echo (str_contains($html, '<table') && str_contains($html, 'T7AAA7') === false /* регистрация не выводится в самой таблице, это нормально */ )
    ? "HTML содержит таблицу — OK\n" : "HTML НЕ содержит таблицу — ПРОВАЛ\n";
echo str_contains($html, (string)$rows[0]['flight_number']) ? "Номер рейса в HTML найден — OK\n" : "Номер рейса НЕ найден — ПРОВАЛ\n";

$emptyHtml = $ref->invoke($controller, []);
echo "\nПустой список: " . (str_contains($emptyHtml, 'не найдено') ? "корректное сообщение — OK\n" : "ПРОВАЛ\n");
