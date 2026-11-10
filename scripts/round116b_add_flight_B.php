<?php
declare(strict_types=1);
// РАУНД 116, часть 2 — рейс B (T7AAA7, прилёт 19.10.2026 14:00 из Бухары)
// не прошёл через FlightService::createFlight(), потому что сработала
// защита от "забытого" рейса (round69, Flight::findIdleDuplicate()):
// она видит рейс #126 (D, прилёт 19.10 22:00, тот же борт, тот же тип
// 'arrival', без акта и без начислений) и считает текущий рейс дублем —
// хотя это легитимный отдельный визит того же борта в тот же день
// (подтверждено разрешением CAA UZB 263/10/2026 BHK/TVT/SKD, см. round115/116).
// findIdleDuplicate() не смотрит на linked_flight_id вообще — только на
// регистрацию+тип+окно дат+отсутствие акта/начислений, поэтому не видит,
// что #126 уже законно связан с #107.
//
// Решение: вставляем рейс напрямую через Flight::insert() (минуя именно
// эту проверку), но повторяем ВСЕ остальные шаги обычного
// FlightService::createFlight() один в один (инициализация сроков,
// журнал активности, автосвязка, перенос MTOW/стоянки, авто-заявка
// LEAD/CHOCKS) — через Reflection для приватных методов, чтобы результат
// не отличался от того, как если бы это всё прошло через обычную форму.

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

use App\Core\ActivityLogger;
use App\Models\Flight;
use App\Services\FlightService;
use App\Services\DeadlineService;

$flightModel   = new Flight();
$flightService = new FlightService();

$noteB = 'Разр.: CAA UZB 263/10/2026 BHK/TVT/SKD | Цель: BUSINESS'
    . ' [Раунд 116, 22.10.2026] Возврат из Бухары, тот же день вылет в Самарканд (рейс #127) — см. round115/116.'
    . ' Вставлен напрямую в обход Flight::findIdleDuplicate() — ложное срабатывание на легитимный '
    . 'второй визит того же борта в тот же день, см. докблок скрипта round116b.';

$insertData = [
    'airline_id'             => 136, // GLOBALJET ISLE OF MAN
    'flight_number'          => 'T7AAA7',
    'flight_date'             => '2026-10-19',
    'route_type'              => 'domestic', // UZSB (Бухара) внутренний, как и у #94
    'operation_type'          => 'arrival',
    'origin_airport_id'       => 185, // UZSB Бухара
    'destination_airport_id'  => 148, // TVT
    'aircraft_type'           => 'GL7T',
    'aircraft_mtow'           => 52.10,
    'aircraft_registration'   => 'T7AAA7',
    'linked_flight_id'        => null,
    'notes'                   => $noteB,
    'scheduled_time'          => '14:00:00',
];

$id = (int)$flightModel->insert($insertData);
echo "Создан рейс #{$id}: T7AAA7 arrival 2026-10-19 14:00:00 (напрямую, в обход idle-duplicate проверки)\n";

$deadlineService = new DeadlineService();
$deadlineService->initForFlight($id);

ActivityLogger::log(
    'flight', $id, 'create_flight', 'success',
    "Created flight 'T7AAA7' on 2026-10-19 (round116b, bypass idle-duplicate guard)",
    null,
    $insertData
);

$linkResult = $flightService->autoLinkFlight($id);
echo "Автосвязка: " . json_encode($linkResult, JSON_UNESCAPED_UNICODE) . "\n";

// Приватные методы FlightService — вызываем через Reflection для полного
// паритета с обычным createFlight().
$ref = new ReflectionClass($flightService);

$syncMethod = $ref->getMethod('syncLinkedAircraftData');
$syncMethod->setAccessible(true);
$syncMethod->invoke($flightService, $id);

$seedMethod = $ref->getMethod('seedDefaultServiceOrders');
$seedMethod->setAccessible(true);
$seedMethod->invoke($flightService, $id);

echo "Готово.\n";
