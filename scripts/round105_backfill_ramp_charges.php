<?php
declare(strict_types=1);

/**
 * РАУНД 105 (20.10.2026) — одноразовая чистка ложных срабатываний
 * scripts/invariant_checks.php по обязательным сборам.
 *
 * Контекст (по прямому указанию пользователя): "Проверил
 * invariant_checks.php сегодня, 20.10 — 10 «ошибок» по обязательным
 * сборам, но все 10 оказались ложным срабатыванием самой проверки: акты
 * (0004, 0006, 0008, 0009, 0015, 0016, 0019–0021) уже содержат верный
 * RAMP_* (ретроактивно исправлен раундом 81), а сама проверка сравнивается
 * с таблицей flight_service_charges, которую раунд 81 обновил только в
 * act_items — косметическая рассинхронизация, на деньги не влияет, но
 * стоит почистить, чтобы отчёт не пугал ложной тревогой."
 *
 * Разбор корневой причины (подтверждён запросами к БД 20.10.2026): раунд
 * 74 сделал RAMP_* обязательными сборами ВЗАМЕН нулевого MARSHALLING, но
 * сознательно не трогал уже approved акты на тот момент (см. докблок
 * раунда 74). Раунд 81 ретроактивно исправил суммы в act_items/acts для
 * этих 9 актов — но НЕ добавил соответствующую строку в
 * flight_service_charges (она используется ТОЛЬКО
 * AutoChargeService::findMandatoryChargeGaps() -> existsChargeForService(),
 * не влияет на сами акты/деньги). Поэтому invariant_checks.php видит для
 * этих 9 рейсов обязательный RAMP_* "посчитанным нигде" и ошибочно репортит
 * это как пробел, хотя в самом акте строка давно корректна.
 *
 * Что делает скрипт: для каждого из 9 рейсов добавляет В
 * flight_service_charges строку с тем самым RAMP_*-сервисом и количеством,
 * которые уже стоят в act_items соответствующего (approved) акта —
 * source_role='system', is_auto=1, consumed_in_act_id=<act_id> (сбор уже
 * "израсходован" в этом акте, не висит неучтённым), note с явной отметкой
 * "round 105, задним числом" — тот же стиль, что и у прежних ретроактивных
 * авто-строк (см. OVER_PARK заметки раунда 87/88). На act_items/acts/суммы
 * НЕ влияет вообще — только убирает ложное срабатывание invariant_checks.php.
 *
 * Список (flight_id => [service_code, act_id]) собран и сверен вручную
 * запросами к БД 20.10.2026 — act_items.service_id для каждого акта уже
 * верный RAMP_*, здесь только зеркалируем его в flight_service_charges.
 *
 * Безопасно запускать повторно — на таблице UNIQUE(flight_id, service_id),
 * если строка уже есть, вставка будет пропущена (поймаем исключение и
 * сообщим, а не прервём остальные).
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/round105_backfill_ramp_charges.php
 */

define('BASE_PATH', dirname(__DIR__));

$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

require BASE_PATH . '/vendor/autoload.php';

use App\Core\ActivityLogger;
use App\Core\Database;
use App\Models\FlightServiceCharge;

// flight_id => [act_id, service_code, quantity] — сверено с act_items 20.10.2026.
$rows = [
    13 => ['act_id' => 4,  'code' => 'RAMP_25',  'qty' => 1.0],
    11 => ['act_id' => 6,  'code' => 'RAMP_70',  'qty' => 1.0],
    6  => ['act_id' => 8,  'code' => 'RAMP_10',  'qty' => 1.0],
    12 => ['act_id' => 9,  'code' => 'RAMP_25',  'qty' => 1.0],
    15 => ['act_id' => 15, 'code' => 'RAMP_50',  'qty' => 1.0],
    8  => ['act_id' => 16, 'code' => 'RAMP_35',  'qty' => 1.0],
    31 => ['act_id' => 19, 'code' => 'RAMP_50',  'qty' => 1.0],
    23 => ['act_id' => 20, 'code' => 'RAMP_150', 'qty' => 1.0],
    19 => ['act_id' => 21, 'code' => 'RAMP_50',  'qty' => 1.0],
];

$db          = Database::getInstance();
$chargeModel = new FlightServiceCharge();

$serviceIds = [];
$stmt = $db->query("SELECT id, code FROM services WHERE code LIKE 'RAMP_%'");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $svc) {
    $serviceIds[$svc['code']] = (int)$svc['id'];
}

$inserted = 0;
$skipped  = 0;

foreach ($rows as $flightId => $info) {
    $code      = $info['code'];
    $actId     = $info['act_id'];
    $qty       = $info['qty'];
    $serviceId = $serviceIds[$code] ?? null;

    if ($serviceId === null) {
        echo "ПРОПУЩЕН рейс #{$flightId}: сервис с кодом {$code} не найден в services.\n";
        $skipped++;
        continue;
    }

    if ($chargeModel->existsChargeForService($flightId, $serviceId)) {
        echo "ПРОПУЩЕН рейс #{$flightId}: строка {$code} в flight_service_charges уже есть (повторный запуск?).\n";
        $skipped++;
        continue;
    }

    $chargeModel->insert([
        'flight_id'          => $flightId,
        'service_id'         => $serviceId,
        'order_id'           => null,
        'source_role'        => 'system',
        'source_doc_type'    => null,
        'source_doc_id'      => null,
        'quantity'           => $qty,
        'is_auto'            => 1,
        'note'               => "Авторасчёт (задним числом, round 105, 20.10.2026): зеркалировано с act_items акта #{$actId} "
            . "— раунд 81 исправил сумму в акте, но не добавил строку сюда; эта таблица используется только "
            . "invariant_checks.php, на деньги/акт не влияет.",
        'consumed_in_act_id' => $actId,
        'created_by_user_id' => null,
    ]);

    ActivityLogger::log(
        'flight', $flightId, 'manual_backfill_charge', 'success',
        "Раунд 105: добавлена строка {$code} в flight_service_charges (зеркало act_items акта #{$actId}, исправлено раундом 81) "
            . "— убирает ложное срабатывание invariant_checks.php",
        null,
        ['service_id' => $serviceId, 'code' => $code, 'quantity' => $qty, 'consumed_in_act_id' => $actId]
    );

    echo "ДОБАВЛЕН: рейс #{$flightId} <- {$code} (акт #{$actId}, qty={$qty}).\n";
    $inserted++;
}

echo "\n--- Итог ---\n";
echo "Добавлено строк: {$inserted}\n";
echo "Пропущено: {$skipped}\n";
