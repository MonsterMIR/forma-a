<?php
/**
 * scripts/round134_audit_currency_vat.php — ТОЛЬКО ЧТЕНИЕ, ничего не меняет.
 *
 * Полный аудит всей базы на соответствие двум правилам (подтверждены
 * пользователем в round134, код уже реализует их с round77):
 *   1. Валюта акта = резидентство авиакомпании (airlines.is_resident):
 *      местная → UZS, иностранная → USD.
 *   2. НДС строки = тип рейса (flights.route_type, вычисляется по ОБОИМ
 *      аэропортам airports.is_local): местный → 12% (если у услуги
 *      vat_applicable_domestic=1), международный → 0%.
 *   route_type сам вычисляется по airports.is_local обоих аэропортов.
 *
 * Проверяет 4 вещи по ВСЕМ актам (любой статус):
 *   A. flights.route_type (сохранённый) vs пересчитанный по airports.is_local
 *   B. acts.settlement_currency_id (сохранённый) vs airlines.is_resident
 *   C. act_items.currency_id vs acts.settlement_currency_id (строка должна
 *      быть в валюте акта — иначе это именно тот баг, что лёг в основу
 *      round131: тариф выбран не в той валюте)
 *   D. act_items.vat_rate vs ожидаемый по правилу (route_type + service.vat_applicable_domestic)
 */

define('BASE_PATH', dirname(__DIR__));
$envFile = BASE_PATH . '/.env';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    $_ENV[$k] = $v; putenv("$k=$v");
}
require BASE_PATH . '/vendor/autoload.php';

use App\Core\Database;
$db = Database::getInstance();

// ───── A. route_type mismatch ─────
echo "=== A. flights.route_type (сохранённый) vs вычисленный по airports.is_local ===\n";
$rowsA = $db->query("
    SELECT f.id AS flight_id, f.flight_number, f.flight_date, f.operation_type, f.route_type AS stored_route_type,
           ao.is_local AS origin_local, ad.is_local AS dest_local,
           ao.name AS origin_name, ad.name AS dest_name,
           (SELECT id FROM acts WHERE flight_id = f.id LIMIT 1) AS act_id
    FROM flights f
    LEFT JOIN airports ao ON ao.id = f.origin_airport_id
    LEFT JOIN airports ad ON ad.id = f.destination_airport_id
")->fetchAll();
$mismatchA = [];
foreach ($rowsA as $r) {
    $originLocal = (bool)($r['origin_local'] ?? false);
    $destLocal   = (bool)($r['dest_local'] ?? false);
    $expected    = ($originLocal && $destLocal) ? 'domestic' : 'international';
    if ($expected !== $r['stored_route_type']) {
        $mismatchA[] = $r + ['expected_route_type' => $expected];
    }
}
echo "Рейсов с несовпадением route_type: " . count($mismatchA) . "\n";
foreach ($mismatchA as $m) {
    echo "  flight #{$m['flight_id']} {$m['flight_number']} {$m['flight_date']} ({$m['operation_type']}) "
       . "stored={$m['stored_route_type']} expected={$m['expected_route_type']} "
       . "origin=({$m['origin_name']},local=" . ($m['origin_local'] ?? 'NULL') . ") "
       . "dest=({$m['dest_name']},local=" . ($m['dest_local'] ?? 'NULL') . ") act_id=" . ($m['act_id'] ?? '-') . "\n";
}

// ───── B. currency mismatch ─────
echo "\n=== B. acts.settlement_currency_id (сохранённый) vs airlines.is_resident ===\n";
$rowsB = $db->query("
    SELECT a.id AS act_id, a.act_number, a.status, a.settlement_currency_id, c.code AS stored_currency_code,
           al.id AS airline_id, al.name AS airline_name, al.is_resident,
           a.total_amount_without_vat
    FROM acts a
    JOIN flights f ON f.id = a.flight_id
    JOIN airlines al ON al.id = f.airline_id
    LEFT JOIN currencies c ON c.id = a.settlement_currency_id
")->fetchAll();
$mismatchB = [];
foreach ($rowsB as $r) {
    $expectedCode = $r['is_resident'] ? 'UZS' : 'USD';
    if ($expectedCode !== $r['stored_currency_code']) {
        $mismatchB[] = $r + ['expected_currency_code' => $expectedCode];
    }
}
echo "Актов с несовпадением валюты: " . count($mismatchB) . "\n";
foreach ($mismatchB as $m) {
    echo "  act #{$m['act_id']} {$m['act_number']} [{$m['status']}] airline={$m['airline_name']} (is_resident={$m['is_resident']}) "
       . "stored_currency={$m['stored_currency_code']} expected={$m['expected_currency_code']} "
       . "total={$m['total_amount_without_vat']}\n";
}

// ───── C. item currency != act currency ─────
echo "\n=== C. act_items.currency_id vs acts.settlement_currency_id (строка в валюте акта?) ===\n";
$rowsC = $db->query("
    SELECT ai.id AS item_id, ai.act_id, a.act_number, a.status, a.settlement_currency_id, ai.currency_id AS item_currency_id,
           cAct.code AS act_currency_code, cItem.code AS item_currency_code,
           ai.service_name_snapshot, ai.amount_without_vat
    FROM act_items ai
    JOIN acts a ON a.id = ai.act_id
    LEFT JOIN currencies cAct ON cAct.id = a.settlement_currency_id
    LEFT JOIN currencies cItem ON cItem.id = ai.currency_id
    WHERE ai.currency_id <> a.settlement_currency_id
")->fetchAll();
echo "Строк, где валюта строки != валюте акта: " . count($rowsC) . "\n";
$sumC = 0.0;
foreach ($rowsC as $m) {
    $sumC += (float)$m['amount_without_vat'];
    echo "  item #{$m['item_id']} act #{$m['act_id']} {$m['act_number']} [{$m['status']}] "
       . "{$m['service_name_snapshot']}: строка={$m['item_currency_code']} акт={$m['act_currency_code']} "
       . "amount={$m['amount_without_vat']}\n";
}
echo "Сумма затронутых строк (смешанные валюты, без НДС, номинально): " . number_format($sumC, 2) . "\n";

// ───── D. vat_rate mismatch ─────
echo "\n=== D. act_items.vat_rate vs ожидаемый (route_type факт. рейса + service.vat_applicable_domestic) ===\n";
$rowsD = $db->query("
    SELECT ai.id AS item_id, ai.act_id, a.act_number, a.status, f.route_type AS flight_route_type,
           ai.vat_rate AS stored_vat_rate, ai.amount_without_vat, ai.vat_amount AS stored_vat_amount,
           s.vat_applicable_domestic, ai.service_name_snapshot
    FROM act_items ai
    JOIN acts a ON a.id = ai.act_id
    JOIN flights f ON f.id = a.flight_id
    JOIN services s ON s.id = ai.service_id
")->fetchAll();
$mismatchD = [];
foreach ($rowsD as $r) {
    $shouldBeZero = ($r['flight_route_type'] === 'international') || !$r['vat_applicable_domestic'];
    $shouldBeNonZero = !$shouldBeZero;
    $storedIsZero = ((float)$r['stored_vat_rate'] === 0.0);
    if (($shouldBeZero && !$storedIsZero) || ($shouldBeNonZero && $storedIsZero)) {
        $mismatchD[] = $r;
    }
}
echo "Строк с несовпадением НДС (0% vs положенные >0%, или наоборот): " . count($mismatchD) . "\n";
$sumD = 0.0;
foreach ($mismatchD as $m) {
    $sumD += (float)$m['stored_vat_amount'];
    echo "  item #{$m['item_id']} act #{$m['act_id']} {$m['act_number']} [{$m['status']}] route={$m['flight_route_type']} "
       . "vat_applicable_domestic={$m['vat_applicable_domestic']} stored_vat_rate={$m['stored_vat_rate']} "
       . "{$m['service_name_snapshot']} amount_without_vat={$m['amount_without_vat']} stored_vat_amount={$m['stored_vat_amount']}\n";
}
echo "Сумма НДС на затронутых строках (как сейчас записано, для масштаба): " . number_format($sumD, 2) . "\n";

echo "\n=== ИТОГО ===\n";
echo "A (route_type рейсов не совпадает): " . count($mismatchA) . "\n";
echo "B (валюта акта не совпадает с резидентством): " . count($mismatchB) . "\n";
echo "C (валюта строки != валюте акта): " . count($rowsC) . "\n";
echo "D (НДС строки не совпадает с правилом): " . count($mismatchD) . "\n";
