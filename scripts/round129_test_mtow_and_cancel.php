<?php
/**
 * scripts/round129_test_mtow_and_cancel.php
 *
 * Транзакционный тест (без следа в проде, всегда rollBack в конце):
 *  1) Проверяет исправление "MTOW навсегда блокирует сохранение рейса"
 *     (FlightService::updateFlight()) на реальном застрявшем рейсе #104.
 *  2) Проверяет новый механизм отмены рейса (FlightService::cancelFlight())
 *     на синтетическом тестовом рейсе.
 */

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
use App\Services\FlightService;

$db = Database::getInstance();
$db->beginTransaction();

$fail = 0;

function check(string $label, bool $cond): void {
    global $fail;
    echo ($cond ? "OK   " : "FAIL ") . $label . "\n";
    if (!$cond) $fail++;
}

try {
    $flightService = new FlightService();

    // ──────────────────────────────────────────────────────────
    // ТЕСТ 1: рейс #104 (LSM888/T7FFI) — раньше ЛЮБОЕ сохранение формы
    // падало с "MTOW нельзя изменить", т.к. справочник (31.8) расходится
    // со значением, зафиксированным в утверждённом акте связанного рейса
    // #103 (32).
    // ──────────────────────────────────────────────────────────
    $flightStmt = $db->prepare("SELECT * FROM flights WHERE id = 104");
    $flightStmt->execute();
    $f104 = $flightStmt->fetch(PDO::FETCH_ASSOC);
    check('рейс #104 найден', (bool)$f104);

    $aircraftStmt = $db->prepare("SELECT mtow FROM aircraft WHERE registration = 'T7FFI'");
    $aircraftStmt->execute();
    $registryMtowBefore = (float)$aircraftStmt->fetchColumn();
    check('справочник T7FFI = 31.8 до теста', abs($registryMtowBefore - 31.8) < 0.001);

    $formData = [
        'airline_id'             => $f104['airline_id'],
        'flight_number'          => $f104['flight_number'],
        'flight_date'            => $f104['flight_date'],
        'route_type'             => $f104['route_type'],
        'operation_type'         => $f104['operation_type'],
        'origin_airport_id'      => $f104['origin_airport_id'],
        'destination_airport_id' => $f104['destination_airport_id'],
        'aircraft_type'          => $f104['aircraft_type'],
        'aircraft_mtow'          => $f104['aircraft_mtow'],
        'aircraft_registration'  => $f104['aircraft_registration'],
        'scheduled_time'         => $f104['scheduled_time'],
        'mc_number'              => $f104['mc_number'],
        'notes'                  => $f104['notes'] . ' [round129 test]',
    ];

    $result = $flightService->updateFlight(104, $formData);
    check('updateFlight(104, ...) теперь success=true (раньше падало)', $result['success'] === true);
    if (!$result['success']) {
        echo "   error: " . ($result['error'] ?? '?') . "\n";
    }

    $flightStmt->execute();
    $f104After = $flightStmt->fetch(PDO::FETCH_ASSOC);
    check('aircraft_mtow рейса #104 остался 32.00 (как в утверждённом акте #103)',
        abs((float)$f104After['aircraft_mtow'] - 32.0) < 0.001);
    check('notes обновились (само сохранение реально прошло)',
        str_contains((string)$f104After['notes'], '[round129 test]'));

    $aircraftStmt->execute();
    $registryMtowAfter = (float)$aircraftStmt->fetchColumn();
    check('справочник T7FFI НЕ тронут задним числом, остался 31.8',
        abs($registryMtowAfter - 31.8) < 0.001);

    // ──────────────────────────────────────────────────────────
    // ТЕСТ 2: механизм отмены рейса — синтетический рейс без акта.
    // ──────────────────────────────────────────────────────────
    $db->exec("
        INSERT INTO flights
            (airline_id, flight_number, flight_date, route_type, operation_type,
             aircraft_type, aircraft_mtow, aircraft_registration, mc_number, notes, status)
        VALUES
            (138, 'TEST129', '2026-12-01', 'international', 'arrival',
             'TEST', 10, 'TESTREG', 'B-99Z', 'round129 synthetic test flight', 'active')
    ");
    $testFlightId = (int)$db->lastInsertId();
    check('синтетический тестовый рейс создан', $testFlightId > 0);

    $anyServiceId = (int)$db->query("SELECT id FROM services LIMIT 1")->fetchColumn();
    $db->exec("
        INSERT INTO flight_service_orders (flight_id, service_id, status, created_at)
        VALUES ({$testFlightId}, {$anyServiceId}, 'planned', NOW())
    ");

    $cancelResult = $flightService->cancelFlight($testFlightId, 'Тест: разрешение аннулировано', 1);
    check('cancelFlight() success=true', $cancelResult['success'] === true);
    if (!$cancelResult['success']) {
        echo "   error: " . ($cancelResult['error'] ?? '?') . "\n";
    }

    $check = $db->prepare("SELECT status, cancellation_reason, cancelled_by_user_id FROM flights WHERE id = ?");
    $check->execute([$testFlightId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    check('статус рейса -> cancelled', $row['status'] === 'cancelled');
    check('причина отмены сохранена', $row['cancellation_reason'] === 'Тест: разрешение аннулировано');

    $ordersCheck = $db->prepare("SELECT status FROM flight_service_orders WHERE flight_id = ?");
    $ordersCheck->execute([$testFlightId]);
    $orderStatus = $ordersCheck->fetchColumn();
    check('планируемый заказ услуги автоматически отменён', $orderStatus === 'cancelled');

    // повторная отмена уже отменённого рейса должна быть отклонена
    $secondCancel = $flightService->cancelFlight($testFlightId, 'ещё раз', 1);
    check('повторная отмена отклонена', $secondCancel['success'] === false);

    // отмена рейса с уже утверждённым актом должна быть отклонена —
    // пробуем на рейсе #103 (LSM888 arrival, акт approved)
    $cancelWithAct = $flightService->cancelFlight(103, 'тест: не должно сработать', 1);
    check('отмена рейса с существующим актом отклонена (#103)', $cancelWithAct['success'] === false);

    // ──────────────────────────────────────────────────────────
    // ТЕСТ 3: isFreed() — отменённый рейс не держит стоянку.
    // ──────────────────────────────────────────────────────────
    $standService = new \App\Services\ParkingStandService();
    $refl = new ReflectionMethod($standService, 'isFreed');
    $refl->setAccessible(true);
    $cancelledFlightRow = $db->query("SELECT * FROM flights WHERE id = {$testFlightId}")->fetch(PDO::FETCH_ASSOC);
    $freed = $refl->invoke($standService, $cancelledFlightRow);
    check('isFreed() == true для отменённого рейса (стоянка B-99Z свободна)', $freed === true);

    echo "\n" . ($fail === 0 ? "ВСЕ ПРОВЕРКИ ПРОШЛИ" : "ЕСТЬ ОШИБКИ: {$fail}") . "\n";

} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ':' . $e->getLine() . "\n";
    $fail++;
} finally {
    $db->rollBack();
    echo "rolled back (ничего не сохранено в проде)\n";
}

exit($fail === 0 ? 0 : 1);
