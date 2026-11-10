<?php
declare(strict_types=1);
// РАУНД 120 (23.10.2026) — тест синхронизации МС№ между связанными
// прилётом/вылетом ДО закрытия акта (по прямому указанию пользователя,
// живой случай UAG97Y #134/#135). Всё в транзакции с гарантированным
// rollBack() — никаких тестовых данных не остаётся в БД.

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

use App\Core\Database;
use App\Models\Flight;
use App\Services\FlightService;

$db = Database::getInstance();
$db->beginTransaction();
$fail = false;

try {
    $flightModel = new Flight();
    $flightService = new FlightService();

    // ── Тест 1: оба акта НЕ построены — меняем МС№ на прилёте, должно
    // синхронно перенестись на вылет. ──
    $arr = $flightModel->insert([
        'airline_id' => 155, 'flight_number' => 'TEST120A', 'flight_date' => '2026-11-05',
        'route_type' => 'international', 'operation_type' => 'arrival',
        'origin_airport_id' => 208, 'destination_airport_id' => 148,
        'aircraft_type' => 'TEST', 'aircraft_mtow' => 20.0, 'aircraft_registration' => 'TEST120A',
        'mc_number' => 'B-17', 'scheduled_time' => '10:00:00',
    ]);
    $dep = $flightModel->insert([
        'airline_id' => 155, 'flight_number' => 'TEST120A', 'flight_date' => '2026-11-06',
        'route_type' => 'international', 'operation_type' => 'departure',
        'origin_airport_id' => 148, 'destination_airport_id' => 208,
        'aircraft_type' => 'TEST', 'aircraft_mtow' => 20.0, 'aircraft_registration' => 'TEST120A',
        'mc_number' => 'B-17', 'scheduled_time' => '10:00:00',
    ]);
    $flightModel->update((int)$arr, ['linked_flight_id' => $dep]);
    $flightModel->update((int)$dep, ['linked_flight_id' => $arr]);

    // Меняем МС№ на рейсе прилёта (как через обычную форму редактирования)
    $arrRow = $flightModel->find((int)$arr);
    $formData = $arrRow;
    $formData['mc_number'] = 'B-18';
    $result = $flightService->updateFlight((int)$arr, $formData);
    if (!($result['success'] ?? false)) {
        echo "ТЕСТ 1: ОШИБКА updateFlight: " . ($result['error'] ?? '?') . "\n";
        $fail = true;
    } else {
        $depAfter = $flightModel->find((int)$dep);
        if (($depAfter['mc_number'] ?? null) === 'B-18') {
            echo "ТЕСТ 1 OK: МС№ прилёта изменён на B-18, вылет синхронно подхватил B-18 (было B-17)\n";
        } else {
            echo "ТЕСТ 1 ПРОВАЛ: вылет остался с МС№=" . ($depAfter['mc_number'] ?? 'NULL') . ", ожидали B-18\n";
            $fail = true;
        }
    }

    // ── Тест 2: у вылета УЖЕ заблокированный (approved) акт — синхронизация
    // должна отказаться писать в него, только залогировать конфликт. ──
    $arr2 = $flightModel->insert([
        'airline_id' => 155, 'flight_number' => 'TEST120B', 'flight_date' => '2026-11-07',
        'route_type' => 'international', 'operation_type' => 'arrival',
        'origin_airport_id' => 208, 'destination_airport_id' => 148,
        'aircraft_type' => 'TEST', 'aircraft_mtow' => 20.0, 'aircraft_registration' => 'TEST120B',
        'mc_number' => 'B-17', 'scheduled_time' => '10:00:00',
    ]);
    $dep2 = $flightModel->insert([
        'airline_id' => 155, 'flight_number' => 'TEST120B', 'flight_date' => '2026-11-08',
        'route_type' => 'international', 'operation_type' => 'departure',
        'origin_airport_id' => 148, 'destination_airport_id' => 208,
        'aircraft_type' => 'TEST', 'aircraft_mtow' => 20.0, 'aircraft_registration' => 'TEST120B',
        'mc_number' => 'B-17', 'scheduled_time' => '10:00:00',
    ]);
    $flightModel->update((int)$arr2, ['linked_flight_id' => $dep2]);
    $flightModel->update((int)$dep2, ['linked_flight_id' => $arr2]);

    // Строим заблокированный акт на вылете
    $db->prepare(
        "INSERT INTO acts (act_number, act_date, flight_id, route_type, operation_type, status, settlement_currency_id, created_by_user_id, created_at)
         VALUES (?, '2026-11-08', ?, 'international', 'departure', 'approved', 3, 1, NOW())"
    )->execute(['TEST120B-ACT', (int)$dep2]);

    $arr2Row = $flightModel->find((int)$arr2);
    $formData2 = $arr2Row;
    $formData2['mc_number'] = 'B-23R';
    $result2 = $flightService->updateFlight((int)$arr2, $formData2);
    if (!($result2['success'] ?? false)) {
        echo "ТЕСТ 2: ОШИБКА updateFlight: " . ($result2['error'] ?? '?') . "\n";
        $fail = true;
    } else {
        $dep2After = $flightModel->find((int)$dep2);
        if (($dep2After['mc_number'] ?? null) === 'B-17') {
            echo "ТЕСТ 2 OK: МС№ прилёта изменён на B-23R, вылет НЕ тронут (остался B-17) — у него заблокированный акт\n";
        } else {
            echo "ТЕСТ 2 ПРОВАЛ: вылет изменился на " . ($dep2After['mc_number'] ?? 'NULL') . ", хотя у него заблокированный акт — не должно было поменяться!\n";
            $fail = true;
        }
    }

} catch (\Throwable $e) {
    echo "ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $fail = true;
}

$db->rollBack();
echo "\n--- Транзакция откатана, никаких тестовых данных не осталось в БД. ---\n";
echo $fail ? "\nИТОГ: ЕСТЬ ПРОВАЛЫ\n" : "\nИТОГ: ВСЕ ТЕСТЫ OK\n";
