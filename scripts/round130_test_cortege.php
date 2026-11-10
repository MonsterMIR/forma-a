<?php
/**
 * scripts/round130_test_cortege.php
 * Транзакционный тест (rollBack в конце) механизма "кортеж" (раунд 130):
 *  - манифест сохраняет pax_cortege отдельно от обычных pax;
 *  - PAX_CORTEGE_ARR/PAX_CORTEGE_DEP виден как заказываемая СОП услуга
 *    (есть активный тариф, цена 0);
 *  - после подтверждения услуги (status=done) авторасчёт подхватывает
 *    количество именно из pax_cortege (а НЕ из общего pax_total);
 *  - обычный PAX_ARR/PAX_DEP расчёт (adults+children) не затронут кортежем.
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
use App\Services\AutoChargeService;
use App\Models\TariffVersion;

$db = Database::getInstance();
$db->beginTransaction();

$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    echo ($cond ? "OK   " : "FAIL ") . $label . "\n";
    if (!$cond) $fail++;
}

try {
    $autoCharge = new AutoChargeService();

    $paxCortegeArrId = (int)$db->query("SELECT id FROM services WHERE code = 'PAX_CORTEGE_ARR'")->fetchColumn();
    $paxArrId        = (int)$db->query("SELECT id FROM services WHERE code = 'PAX_ARR'")->fetchColumn();
    check('сервис PAX_CORTEGE_ARR найден', $paxCortegeArrId > 0);
    check('сервис PAX_ARR найден', $paxArrId > 0);

    // ───────── Доступность PAX_CORTEGE_ARR в списке "доступных услуг СОП" ─────────
    $tariffModel = new TariffVersion();
    $available = $tariffModel->getServicesWithActiveTariff('international', 'arrival', date('Y-m-d'), 'sop', null);
    $codes = array_column($available, 'code');
    check('PAX_CORTEGE_ARR виден в списке услуг СОП (есть активный тариф)', in_array('PAX_CORTEGE_ARR', $codes, true));

    // ───────── Синтетический рейс-прилёт ─────────
    $db->exec("
        INSERT INTO flights
            (airline_id, flight_number, flight_date, route_type, operation_type,
             aircraft_type, aircraft_mtow, aircraft_registration, mc_number, notes, status)
        VALUES
            (138, 'TC130A', '2026-12-10', 'international', 'arrival',
             'TEST', 10, 'TESTR130', 'B-98Z', 'round130 cortege test', 'active')
    ");
    $flightId = (int)$db->lastInsertId();
    check('синтетический рейс создан', $flightId > 0);

    // Манифест: 5 взрослых, 2 детей, 1 младенец, 3 кортеж — final/submitted.
    $db->exec("
        INSERT INTO passenger_manifests
            (flight_id, manifest_type, status, pax_adults, pax_children, pax_infants,
             pax_transit, pax_crew, pax_cortege, baggage_pieces, created_by_user_id, submitted_at)
        VALUES
            ({$flightId}, 'final', 'submitted', 5, 2, 1, 0, 0, 3, 10, 1, NOW())
    ");

    $m = $db->query("SELECT pax_total, pax_cortege FROM passenger_manifests WHERE flight_id = {$flightId}")->fetch(PDO::FETCH_ASSOC);
    check('pax_total включает кортеж (5+2+1+3=11)', (int)$m['pax_total'] === 11);
    check('pax_cortege сохранён отдельно (3)', (int)$m['pax_cortege'] === 3);

    // ───────── Без подтверждения заказа услуги — авторасчёт не должен начислить кортеж ─────────
    $autoCharge->processFlightMandatoryCharges($flightId);
    $chargeCortege = $db->query("SELECT quantity FROM flight_service_charges WHERE flight_id = {$flightId} AND service_id = {$paxCortegeArrId}")->fetch(PDO::FETCH_ASSOC);
    check('без подтверждения СОП кортеж НЕ начислен (норма, не пробел)', $chargeCortege === false);

    // Обычный PAX_ARR тоже не должен начислиться без подтверждения — таково
    // поведение системы и до раунда 130 (не регрессия).
    $chargePaxArr = $db->query("SELECT quantity FROM flight_service_charges WHERE flight_id = {$flightId} AND service_id = {$paxArrId}")->fetch(PDO::FETCH_ASSOC);
    check('без подтверждения СОП обычный PAX_ARR тоже не начислен (без изменений)', $chargePaxArr === false);

    // ───────── Подтверждаем ОБЕ услуги (имитация "Оказано" на планшете СОП) ─────────
    $db->exec("
        INSERT INTO flight_service_orders (flight_id, service_id, status, completed_at)
        VALUES ({$flightId}, {$paxCortegeArrId}, 'done', NOW()),
               ({$flightId}, {$paxArrId}, 'done', NOW())
    ");

    $autoCharge->processFlightMandatoryCharges($flightId);

    $chargeCortege2 = $db->query("SELECT quantity FROM flight_service_charges WHERE flight_id = {$flightId} AND service_id = {$paxCortegeArrId}")->fetch(PDO::FETCH_ASSOC);
    check('после подтверждения кортеж начислен', $chargeCortege2 !== false);
    check('количество кортежа = pax_cortege (3), а НЕ pax_total (11)', $chargeCortege2 && (float)$chargeCortege2['quantity'] === 3.0);

    $chargePaxArr2 = $db->query("SELECT quantity FROM flight_service_charges WHERE flight_id = {$flightId} AND service_id = {$paxArrId}")->fetch(PDO::FETCH_ASSOC);
    check('обычный PAX_ARR начислен', $chargePaxArr2 !== false);
    check('количество PAX_ARR = adults+children (5+2=7), кортеж НЕ приплюсован', $chargePaxArr2 && (float)$chargePaxArr2['quantity'] === 7.0);

    // Тариф на кортеж сейчас 0 — убедимся, что сама строка тарифа это подтверждает
    // (сумма акта по этой строке будет 0, до появления реального тарифа).
    $tariffRow = $db->query(
        "SELECT price FROM tariff_versions WHERE service_id = {$paxCortegeArrId} AND is_active = 1 LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    check('активный тариф кортежа = 0', $tariffRow && (float)$tariffRow['price'] === 0.0);

    echo "\n" . ($fail === 0 ? "ВСЕ ПРОВЕРКИ ПРОШЛИ" : "ЕСТЬ ОШИБКИ: {$fail}") . "\n";

} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ':' . $e->getLine() . "\n";
    $fail++;
} finally {
    $db->rollBack();
    echo "rolled back (ничего не сохранено в проде)\n";
}

exit($fail === 0 ? 0 : 1);
