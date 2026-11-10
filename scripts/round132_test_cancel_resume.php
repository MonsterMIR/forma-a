<?php
/**
 * scripts/round132_test_cancel_resume.php
 * Транзакционный тест (rollBack в конце), второе уточнение раунда 129:
 * отмена вылета больше не рвёт связь с прилётом, а очищает время вылета;
 * новая кнопка/метод "Возобновить рейс" (resumeFlight()) возвращает рейс в
 * работу с новой датой/временем, связь остаётся как была.
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

function makeFlight($db, string $num, string $op, ?int $linkedId = null, string $time = '12:00:00'): int {
    $db->exec("
        INSERT INTO flights
            (airline_id, flight_number, flight_date, route_type, operation_type,
             aircraft_type, aircraft_mtow, aircraft_registration, mc_number, notes, status,
             linked_flight_id, scheduled_time)
        VALUES
            (138, '{$num}', '2026-12-08', 'international', '{$op}',
             'TEST', 10, 'TESTR132', 'B-97Z', 'round132 test', 'active',
             " . ($linkedId !== null ? (int)$linkedId : 'NULL') . ", '{$time}')
    ");
    return (int)$db->lastInsertId();
}

try {
    $flightService = new FlightService();

    // ───────── Сценарий D: отмена ВЫЛЕТА при активном прилёте — связь НЕ рвётся ─────────
    $arrD = makeFlight($db, 'TA132D', 'arrival');
    $depD = makeFlight($db, 'TD132D', 'departure', $arrD);
    $db->exec("UPDATE flights SET linked_flight_id = {$depD} WHERE id = {$arrD}");

    $resD = $flightService->cancelFlight($depD, 'Сценарий D: тест — связь должна остаться', 1);
    check('D: cancelFlight(вылет) success', $resD['success'] === true);
    check('D: нет поля unlinked_from_id в ответе (убрано)', !array_key_exists('unlinked_from_id', $resD));

    $depRow = $db->query("SELECT status, scheduled_time, linked_flight_id, flight_date FROM flights WHERE id = {$depD}")->fetch(PDO::FETCH_ASSOC);
    $arrRow = $db->query("SELECT status, linked_flight_id FROM flights WHERE id = {$arrD}")->fetch(PDO::FETCH_ASSOC);
    check('D: вылет cancelled', $depRow['status'] === 'cancelled');
    check('D: scheduled_time вылета очищено (NULL)', $depRow['scheduled_time'] === null);
    check('D: flight_date вылета НЕ тронут (осталось значение)', $depRow['flight_date'] === '2026-12-08');
    check('D: linked_flight_id вылета НЕ снят — всё ещё указывает на прилёт', (int)$depRow['linked_flight_id'] === $arrD);
    check('D: прилёт остался active', $arrRow['status'] === 'active');
    check('D: linked_flight_id прилёта НЕ снят — всё ещё указывает на вылет', (int)$arrRow['linked_flight_id'] === $depD);

    // isFreed(прилёт) должна по-прежнему вернуть false — стоянка занята,
    // несмотря на отменённый (но не отвязанный) вылет без chocks_off.
    $standService = new \App\Services\ParkingStandService();
    $refl = new ReflectionMethod($standService, 'isFreed');
    $refl->setAccessible(true);
    $arrRowFull = $db->query("SELECT * FROM flights WHERE id = {$arrD}")->fetch(PDO::FETCH_ASSOC);
    $freed = $refl->invoke($standService, $arrRowFull);
    check('D: isFreed(прилёт) == false — стоянка занята (связь сохранена)', $freed === false);

    // ───────── Сценарий E: возобновление отменённого вылета ─────────
    $resumeResult = $flightService->resumeFlight($depD, '2026-12-10', '15:30', 1);
    check('E: resumeFlight() success', $resumeResult['success'] === true);
    if (!$resumeResult['success']) echo "   error: " . ($resumeResult['error'] ?? '?') . "\n";

    $depAfterResume = $db->query("SELECT status, flight_date, scheduled_time, cancelled_at, cancellation_reason, linked_flight_id FROM flights WHERE id = {$depD}")->fetch(PDO::FETCH_ASSOC);
    check('E: статус снова active', $depAfterResume['status'] === 'active');
    check('E: новая дата применилась', $depAfterResume['flight_date'] === '2026-12-10');
    check('E: новое время применилось', substr($depAfterResume['scheduled_time'], 0, 5) === '15:30');
    check('E: cancelled_at сброшен', $depAfterResume['cancelled_at'] === null);
    check('E: cancellation_reason сброшен', $depAfterResume['cancellation_reason'] === null);
    check('E: связь с прилётом сохранилась после возобновления', (int)$depAfterResume['linked_flight_id'] === $arrD);

    // Повторное возобновление уже активного рейса — отклонить
    $resumeAgain = $flightService->resumeFlight($depD, '2026-12-11', '10:00', 1);
    check('E: повторное возобновление активного рейса отклонено', $resumeAgain['success'] === false);

    // Некорректные дата/время — отклонить
    $arrE = makeFlight($db, 'TA132E', 'arrival');
    $flightService->cancelFlight($arrE, 'тест для некорректного возобновления', 1);
    $badDate = $flightService->resumeFlight($arrE, 'не-дата', '10:00', 1);
    check('E: некорректная дата отклонена', $badDate['success'] === false);
    $badTime = $flightService->resumeFlight($arrE, '2026-12-10', 'не-время', 1);
    check('E: некорректное время отклонено', $badTime['success'] === false);

    // ───────── Сценарий F: каскад (отмена прилёта) по-прежнему работает, И тоже чистит время ─────────
    $arrF = makeFlight($db, 'TA132F', 'arrival');
    $depF = makeFlight($db, 'TD132F', 'departure', $arrF);
    $db->exec("UPDATE flights SET linked_flight_id = {$depF} WHERE id = {$arrF}");

    $resF = $flightService->cancelFlight($arrF, 'Сценарий F: каскад + очистка времени', 1);
    check('F: cancelFlight(прилёт) success', $resF['success'] === true);
    check('F: cascade_cancelled_id == вылет', (int)($resF['cascade_cancelled_id'] ?? 0) === $depF);

    $arrFRow = $db->query("SELECT status, scheduled_time, linked_flight_id FROM flights WHERE id = {$arrF}")->fetch(PDO::FETCH_ASSOC);
    $depFRow = $db->query("SELECT status, scheduled_time, linked_flight_id FROM flights WHERE id = {$depF}")->fetch(PDO::FETCH_ASSOC);
    check('F: прилёт cancelled, время очищено', $arrFRow['status'] === 'cancelled' && $arrFRow['scheduled_time'] === null);
    check('F: вылет (каскад) cancelled, время очищено', $depFRow['status'] === 'cancelled' && $depFRow['scheduled_time'] === null);
    check('F: связь НЕ снята ни на прилёте, ни на вылете (каскад)', (int)$arrFRow['linked_flight_id'] === $depF && (int)$depFRow['linked_flight_id'] === $arrF);

    echo "\n" . ($fail === 0 ? "ВСЕ ПРОВЕРКИ ПРОШЛИ" : "ЕСТЬ ОШИБКИ: {$fail}") . "\n";

} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ':' . $e->getLine() . "\n";
    $fail++;
} finally {
    $db->rollBack();
    echo "rolled back (ничего не сохранено в проде)\n";
}

exit($fail === 0 ? 0 : 1);
