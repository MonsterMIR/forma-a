<?php
declare(strict_types=1);
// РАУНД 126 (26.10.2026) — проверка исправления дозаправки: имитирует ТОЧНО
// ту же последовательность SQL-операций, что теперь выполняет
// ServiceOrderController::saveFuel() (после исправления, по order_id), на
// синтетическом рейсе, в транзакции с гарантированным rollBack() — никаких
// тестовых данных не остаётся в БД.

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

$db = Database::getInstance();
$db->beginTransaction();
$fail = false;

try {
    // Синтетический рейс (минимально необходимые поля)
    $flightId = (int)$db->query(
        "INSERT INTO flights (airline_id, flight_number, flight_date, route_type, operation_type,
                               origin_airport_id, destination_airport_id, aircraft_type, aircraft_mtow,
                               aircraft_registration, mc_number, scheduled_time)
         VALUES (155, 'TEST126', '2026-11-10', 'international', 'arrival', 208, 148, 'TEST', 20.0, 'TEST126', 'B-1', '10:00:00')"
    ) ? $db->lastInsertId() : 0;
    if (!$flightId) { throw new \RuntimeException('не удалось создать тестовый рейс'); }

    $serviceId = 127; // FUEL_SVC

    // ── Заказ №1 (позиция плана) — "заказали 10 тонн" ──
    $db->prepare(
        "INSERT INTO flight_service_orders (flight_id, service_id, ordered_by_user_id, status, created_at)
         VALUES (?, ?, 1, 'done', NOW())"
    )->execute([$flightId, $serviceId]);
    $order1Id = (int)$db->lastInsertId();

    // saveFuel()-эквивалент для заказа №1: 12000л × 0.800 = 9.6 т (пример)
    $tonnes1 = round(12000 * 0.800 / 1000, 4);
    $stmt = $db->prepare("SELECT id FROM flight_service_charges WHERE order_id = ? LIMIT 1");
    $stmt->execute([$order1Id]);
    $charge1Id = $stmt->fetchColumn();
    if (!$charge1Id) {
        $db->prepare(
            "INSERT INTO flight_service_charges
                (flight_id, service_id, order_id, source_role, quantity, is_auto, created_by_user_id, created_at)
             VALUES (?, ?, ?, 'supervisor', ?, 0, 1, NOW())"
        )->execute([$flightId, $serviceId, $order1Id, $tonnes1]);
        $charge1Id = (int)$db->lastInsertId();
    }

    // ── Заказ №2 (вторая позиция плана, та же услуга) — "дозаправили ещё 2 тонны" ──
    $db->prepare(
        "INSERT INTO flight_service_orders (flight_id, service_id, ordered_by_user_id, status, created_at)
         VALUES (?, ?, 1, 'done', NOW())"
    )->execute([$flightId, $serviceId]);
    $order2Id = (int)$db->lastInsertId();

    $tonnes2 = round(2500 * 0.800 / 1000, 4);
    $stmt2 = $db->prepare("SELECT id FROM flight_service_charges WHERE order_id = ? LIMIT 1");
    $stmt2->execute([$order2Id]);
    $charge2Id = $stmt2->fetchColumn();
    if (!$charge2Id) {
        $db->prepare(
            "INSERT INTO flight_service_charges
                (flight_id, service_id, order_id, source_role, quantity, is_auto, created_by_user_id, created_at)
             VALUES (?, ?, ?, 'supervisor', ?, 0, 1, NOW())"
        )->execute([$flightId, $serviceId, $order2Id, $tonnes2]);
        $charge2Id = (int)$db->lastInsertId();
    }

    // ── Проверки ──
    if ($order1Id === $order2Id) {
        echo "ПРОВАЛ: два заказа получили один и тот же id?!\n"; $fail = true;
    }
    if ((int)$charge1Id === (int)$charge2Id) {
        echo "ПРОВАЛ: обе дозаправки схлопнулись в ОДНУ биллинговую запись (баг до исправления).\n";
        $fail = true;
    } else {
        echo "OK: два отдельных заказа → ДВЕ отдельные биллинговые записи (charge1={$charge1Id}, charge2={$charge2Id}).\n";
    }

    // Повторный вызов saveFuel() для заказа №1 (ЦОК/супервайзер поправил
    // данные) должен ОБНОВИТЬ ту же запись charge1, а не создать третью.
    $tonnes1b = round(12500 * 0.800 / 1000, 4);
    $stmtR = $db->prepare("SELECT id FROM flight_service_charges WHERE order_id = ? LIMIT 1");
    $stmtR->execute([$order1Id]);
    $charge1IdAgain = $stmtR->fetchColumn();
    if ((int)$charge1IdAgain !== (int)$charge1Id) {
        echo "ПРОВАЛ: повторное обращение к заказу №1 не нашло ту же запись.\n"; $fail = true;
    } else {
        $db->prepare("UPDATE flight_service_charges SET quantity = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$tonnes1b, $charge1IdAgain]);
        echo "OK: редактирование заказа №1 обновило ЕГО запись (charge={$charge1IdAgain}), не создав новую.\n";
    }

    // Сумма по рейсу (то, что увидит buildFromFlightCharges()/печатная форма
    // через _merge_slot_items()) — должна быть tonnes1b + tonnes2.
    $sumStmt = $db->prepare("SELECT SUM(quantity) FROM flight_service_charges WHERE flight_id = ? AND service_id = ?");
    $sumStmt->execute([$flightId, $serviceId]);
    $sum = (float)$sumStmt->fetchColumn();
    $expected = round($tonnes1b + $tonnes2, 4);
    if (abs($sum - $expected) > 0.0001) {
        echo "ПРОВАЛ: сумма по рейсу = {$sum}, ожидали {$expected}\n"; $fail = true;
    } else {
        echo "OK: сумма двух дозаправок по рейсу = {$sum} т (как и должно печататься в акте после суммирования слота).\n";
    }

    // flight_fuel_details — по UNIQUE(flight_service_charge_id) каждая
    // заправка должна получить СВОЙ ряд деталей, без конфликта.
    $db->prepare(
        "INSERT INTO flight_fuel_details (flight_service_charge_id, liters, density, computed_tonnes, entered_by_user_id, created_at)
         VALUES (?, 12500, 0.800, ?, 1, NOW())"
    )->execute([$charge1Id, $tonnes1b]);
    $db->prepare(
        "INSERT INTO flight_fuel_details (flight_service_charge_id, liters, density, computed_tonnes, entered_by_user_id, created_at)
         VALUES (?, 2500, 0.800, ?, 1, NOW())"
    )->execute([$charge2Id, $tonnes2]);
    echo "OK: flight_fuel_details — по одной записи на каждую заправку, без конфликта UNIQUE.\n";

} catch (\Throwable $e) {
    echo "ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $fail = true;
}

$db->rollBack();
echo "\n--- Транзакция откатана, никаких тестовых данных не осталось в БД. ---\n";
echo $fail ? "\nИТОГ: ЕСТЬ ПРОВАЛЫ\n" : "\nИТОГ: ВСЕ ТЕСТЫ OK\n";
