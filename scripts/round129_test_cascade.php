<?php
/**
 * scripts/round129_test_cascade.php
 * Транзакционный тест (rollBack в конце) каскада/отвязки при отмене рейса
 * после уточнения пользователя:
 *  - отмена прилёта -> связанный вылет отменяется каскадом
 *  - отмена прилёта блокируется целиком, если у связанного вылета уже акт/done
 *  - отмена вылета -> только вылет отменяется, связь снимается (unlink),
 *    прилёт остаётся активным и isFreed() для него == false (стоянка занята)
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

    function makeFlight($db, string $num, string $op, ?int $linkedId = null): int {
        $db->exec("
            INSERT INTO flights
                (airline_id, flight_number, flight_date, route_type, operation_type,
                 aircraft_type, aircraft_mtow, aircraft_registration, mc_number, notes, status, linked_flight_id)
            VALUES
                (138, '{$num}', '2026-12-05', 'international', '{$op}',
                 'TEST', 10, 'TESTR129', 'B-99Y', 'round129 cascade test', 'active',
                 " . ($linkedId !== null ? (int)$linkedId : 'NULL') . ")
        ");
        return (int)$db->lastInsertId();
    }

    // ───────────── СЦЕНАРИЙ A: отмена прилёта -> каскад на вылет ─────────────
    $arrA = makeFlight($db, 'TA129A', 'arrival');
    $depA = makeFlight($db, 'TD129A', 'departure', $arrA);
    $db->exec("UPDATE flights SET linked_flight_id = {$depA} WHERE id = {$arrA}");

    $res = $flightService->cancelFlight($arrA, 'Сценарий A: тест каскада', 1);
    check('A: cancelFlight(прилёт) success', $res['success'] === true);
    check('A: cascade_cancelled_id == вылет', (int)($res['cascade_cancelled_id'] ?? 0) === $depA);
    check('A: unlinked_from_id отсутствует в ответе (поле убрано, раунд 129 второе уточнение)', empty($res['unlinked_from_id']));

    $stA = $db->query("SELECT status FROM flights WHERE id = {$arrA}")->fetchColumn();
    $stD = $db->query("SELECT status, cancellation_reason FROM flights WHERE id = {$depA}")->fetch(PDO::FETCH_ASSOC);
    check('A: прилёт cancelled', $stA === 'cancelled');
    check('A: вылет тоже cancelled (каскад)', $stD['status'] === 'cancelled');
    check('A: причина вылета упоминает автоматическую отмену', str_contains($stD['cancellation_reason'], 'Автоматически отменён'));

    // ───────────── СЦЕНАРИЙ B: отмена прилёта блокируется, если у вылета уже акт ─────────────
    $arrB = makeFlight($db, 'TA129B', 'arrival');
    $depB = makeFlight($db, 'TD129B', 'departure', $arrB);
    $db->exec("UPDATE flights SET linked_flight_id = {$depB} WHERE id = {$arrB}");
    $actNumber = 'TEST-ACT-' . $depB;
    $db->exec("
        INSERT INTO acts
            (flight_id, act_number, act_date, route_type, operation_type, status,
             settlement_currency_id, created_by_user_id, created_at)
        VALUES
            ({$depB}, '{$actNumber}', CURDATE(), 'international', 'departure', 'draft',
             3, 1, NOW())
    ");

    $resB = $flightService->cancelFlight($arrB, 'Сценарий B: должно быть заблокировано', 1);
    check('B: отмена прилёта заблокирована целиком (есть акт у вылета)', $resB['success'] === false);

    $stArrB = $db->query("SELECT status FROM flights WHERE id = {$arrB}")->fetchColumn();
    check('B: прилёт НЕ отменён (откат)', $stArrB === 'active');

    // ───────────── СЦЕНАРИЙ C: отмена вылета -> только вылет, прилёт остаётся, связь СОХРАНЯЕТСЯ ─────────────
    // ИЗМЕНЕНО (27.10.2026, раунд 129, второе уточнение пользователя):
    // раньше (первая версия) отмена вылета СНИМАЛА связь (unlinkFlights()).
    // Пользователь скорректировал это решение — связь теперь никогда не
    // трогается, вместо неё очищается scheduled_time отменяемого рейса.
    // Подробные проверки нового поведения (очистка времени, resumeFlight())
    // см. scripts/round132_test_cancel_resume.php — здесь только то, что
    // раньше проверяла отвязка, приведено в соответствие.
    $arrC = makeFlight($db, 'TA129C', 'arrival');
    $depC = makeFlight($db, 'TD129C', 'departure', $arrC);
    $db->exec("UPDATE flights SET linked_flight_id = {$depC} WHERE id = {$arrC}");

    $resC = $flightService->cancelFlight($depC, 'Сценарий C: тест сохранения связи', 1);
    check('C: cancelFlight(вылет) success', $resC['success'] === true);
    check('C: cascade_cancelled_id пуст', empty($resC['cascade_cancelled_id']));

    $stDepC = $db->query("SELECT status, linked_flight_id, scheduled_time FROM flights WHERE id = {$depC}")->fetch(PDO::FETCH_ASSOC);
    $stArrC = $db->query("SELECT status, linked_flight_id FROM flights WHERE id = {$arrC}")->fetch(PDO::FETCH_ASSOC);
    check('C: вылет cancelled', $stDepC['status'] === 'cancelled');
    check('C: прилёт остался active', $stArrC['status'] === 'active');
    check('C: scheduled_time вылета очищено', $stDepC['scheduled_time'] === null);
    check('C: linked_flight_id у вылета СОХРАНЁН (не NULL)', (int)$stDepC['linked_flight_id'] === $arrC);
    check('C: linked_flight_id у прилёта СОХРАНЁН (не NULL)', (int)$stArrC['linked_flight_id'] === $depC);

    $standService = new \App\Services\ParkingStandService();
    $refl = new ReflectionMethod($standService, 'isFreed');
    $refl->setAccessible(true);
    $arrCRow = $db->query("SELECT * FROM flights WHERE id = {$arrC}")->fetch(PDO::FETCH_ASSOC);
    $freedC = $refl->invoke($standService, $arrCRow);
    check('C: isFreed(прилёт без связанного вылета) == false (стоянка занята)', $freedC === false);

    echo "\n" . ($fail === 0 ? "ВСЕ ПРОВЕРКИ ПРОШЛИ" : "ЕСТЬ ОШИБКИ: {$fail}") . "\n";

} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ':' . $e->getLine() . "\n";
    $fail++;
} finally {
    $db->rollBack();
    echo "rolled back (ничего не сохранено в проде)\n";
}

exit($fail === 0 ? 0 : 1);
