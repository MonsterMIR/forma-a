<?php
declare(strict_types=1);

/**
 * РАУНД 110 (21.10.2026) — по прямому указанию пользователя ("Закрой акт
 * рейса 71 сам. Эти диспетчеры меня достали факт время ата не внесли. И
 * сейчас не позволяет так как стоянка занята другим бортом"):
 *
 * Разбор (см. claude/analysis-2026-10-21-round110-flight71-act-closed-pax-sec-explained.md
 * для полного объяснения пользователю):
 *  - Акт 69 (рейс #72, T7MIDB, вылет) не утверждался — Night fee (0.2% от
 *    TLF) не мог посчитаться, потому что TLF нигде не существует: у рейса
 *    #71 (прилёт той же ротации, 15.10) акта не было ВООБЩЕ.
 *  - Сформировать акт на #71 штатно (FlightController::buildAct(), кнопка
 *    "Сформировать Форму А") не удавалось — AutoChargeService::
 *    getActFormationBlockers() требует ATA (flights.arr_time), а его
 *    диспетчеры никогда не вносили.
 *  - Попытка вписать ATA через форму редактирования рейса
 *    (FlightService::updateFlight()) падает на ParkingStandService::
 *    findConflict() — та по ошибке сравнивает СТАРЫЙ, давно закрытый визит
 *    (#71/#72, 15-16.10) с СОВЕРШЕННО ДРУГИМ бортом, который сейчас (на
 *    21.10) физически стоит на той же стоянке B-24L (рейсы #103/#104,
 *    T7FFI) — проверка не смотрит на даты визитов, только на "свободна ли
 *    стоянка СЕЙЧАС" — для редактирования уже закрытого исторического
 *    рейса это ложное срабатывание (отдельный баг, не чинится этим
 *    раундом, см. .md).
 *
 * Что делает скрипт (используя ТЕ ЖЕ сервисы, что и штатный UI-путь —
 * FlightController::buildAct()/ActController::resync(), просто в обход
 * HTTP-слоя и сломанной проверки стоянки):
 *  1. Напрямую (Flight::update(), в обход FlightService::updateFlight() и
 *     её конфликтной проверки) проставляет рейсу #71 arr_date/arr_time —
 *     ПОМЕТКА: реального подтверждённого ATA нигде в системе нет (ни в
 *     flights, ни в parking_acts — пусто, ни в CHOCKS-заявке — её
 *     completed_at 2026-10-20 13:44, это явно задним числом отмеченная
 *     галочка, а не факт прилёта 15.10). Используется административная
 *     оценка 12:00 (та же условность "время неизвестно", что уже принята
 *     в раунде 109 для сортировки рейсов) — отмечено в flights.notes и
 *     activity-логе как неподтверждённое, чтобы не выглядело достоверным
 *     фактом для будущего аудита.
 *  2. Создаёт акт на рейс #71 (route_type=international, operation_type=
 *     arrival, settlement_currency_id=2/USD — те же значения, что
 *     buildAct() использует по умолчанию для international).
 *  3. processParkingAct(71) + syncTechStaffCount(71) + buildFromFlightCharges() —
 *     точно та же последовательность вызовов, что в buildAct().
 *  4. Тот же пересчёт для акта 69 (рейс #72) — эквивалент нажатия
 *     "Обновить".
 *  5. Проверяет, что по рейсу #72 больше нет непогашенных начислений и
 *     пробелов по обязательным сборам.
 *  6. У акта 69 УЖЕ стоят обе подписи (супервайзер + представитель
 *     авиакомпании, сохранены 20.10) — утверждение тогда не прошло только
 *     из-за блокировки расчёта (ActSignatureController::saveSignature()
 *     пытается утвердить акт автоматически сразу после второй подписи,
 *     см. раунд 49 — здесь это не удалось именно из-за TLF-пробела). Раз
 *     подписи уже есть по-настоящему, довершает то, что система сама
 *     должна была сделать 20.10 — changeStatus(69, 'approved').
 *  7. Акт на рейс #71 (новый) подписей/скана не имеет — ОСТАЁТСЯ в
 *     статусе draft, как и положено, approve не трогаем.
 *
 * Безопасно запускать повторно (идемпотентные проверки на каждом шаге).
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/round110_close_flight71_act.php
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
use App\Models\Act;
use App\Models\Flight;
use App\Models\FlightServiceCharge;
use App\Services\ActService;
use App\Services\AutoChargeService;

const ARRIVAL_FLIGHT_ID   = 71;
const DEPARTURE_FLIGHT_ID = 72;
const DEPARTURE_ACT_ID    = 69;

$flightModel  = new Flight();
$actModel     = new Act();
$chargeModel  = new FlightServiceCharge();
$actService   = new ActService();
$autoCharge   = new AutoChargeService();

$arrival = $flightModel->find(ARRIVAL_FLIGHT_ID);
if (!$arrival) { fwrite(STDERR, "Рейс #71 не найден.\n"); exit(1); }

echo "=== Шаг 1: ATA (arr_time) для рейса #71 ===\n";
if (empty($arrival['arr_time'])) {
    $note = trim((string)($arrival['notes'] ?? ''));
    $note .= ($note !== '' ? "\n" : '') . 'Раунд 110 (21.10.2026): ATA (arr_time) в карточке рейса — АДМИНИСТРАТИВНАЯ '
        . 'ОЦЕНКА (12:00, 15.10), настоящее подтверждённое время нигде в системе не найдено '
        . '(диспетчеры не внесли; CHOCKS-заявка отмечена выполненной только 20.10 — задним числом, '
        . 'не отражает реальный момент прилёта 15.10). Поправьте, если реальное время станет известно.';

    $flightModel->update(ARRIVAL_FLIGHT_ID, [
        'arr_date' => '2026-10-15',
        'arr_time' => '12:00:00',
        'notes'    => $note,
    ]);
    ActivityLogger::log(
        'flight', ARRIVAL_FLIGHT_ID, 'manual_fix_ata_estimate', 'success',
        'Раунд 110: ATA (arr_time) проставлен административной оценкой (12:00, 15.10) — реального '
            . 'подтверждённого времени нет нигде в системе (диспетчеры не внесли). Сделано напрямую через '
            . 'Flight::update(), в обход FlightService::updateFlight() — её проверка занятости стоянки '
            . '(ParkingStandService::findConflict()) ошибочно блокирует редактирование этого давно закрытого '
            . 'визита из-за СОВЕРШЕННО ДРУГОГО борта, сейчас занимающего ту же стоянку B-24L (см. round110 .md).',
        ['arr_date' => null, 'arr_time' => null],
        ['arr_date' => '2026-10-15', 'arr_time' => '12:00:00']
    );
    echo "  Проставлено: arr_date=2026-10-15, arr_time=12:00:00 (ОЦЕНКА, не подтверждённый факт).\n";
} else {
    echo "  Уже заполнено: arr_date={$arrival['arr_date']} arr_time={$arrival['arr_time']} — пропускаю.\n";
}

$arrival = $flightModel->find(ARRIVAL_FLIGHT_ID);
$blockers = $autoCharge->getActFormationBlockers($arrival);
echo "  Блокеры формирования акта по рейсу #71: " . (empty($blockers) ? 'нет (OK)' : implode('; ', $blockers)) . "\n\n";
if (!empty($blockers)) {
    fwrite(STDERR, "Остались блокеры — прерываю, дальше без толку.\n");
    exit(1);
}

echo "=== Шаг 2: акт на рейс #71 ===\n";
$existingAct71 = $actModel->findByFlightId(ARRIVAL_FLIGHT_ID);
if ($existingAct71) {
    $act71Id = (int)$existingAct71['id'];
    echo "  Акт уже существует: id={$act71Id}, статус={$existingAct71['status']} — пересобираю.\n";
} else {
    // ПРИМЕЧАНИЕ: ActService::createAct() сам берёт Auth::id()/sessionDbId()
    // для created_by_user_id (NOT NULL в БД) — в CLI-скрипте нет вошедшего
    // пользователя, поэтому тот метод здесь напрямую не подходит.
    // Повторяем её же логику (route_type/cb_rate/валюта — те же правила,
    // см. ActService::createAct()/resolveSettlementCurrency()), проставляя
    // created_by_user_id = 1 (System Administrator) — явно административное
    // создание, см. activity-лог ниже.
    $actNumber = 'PRELIM-FL' . ARRIVAL_FLIGHT_ID;
    $insertData71 = [
        'act_number'               => $actNumber,
        'number_finalized'         => 0,
        'act_date'                 => date('Y-m-d'),
        'flight_id'                => ARRIVAL_FLIGHT_ID,
        'route_type'               => $arrival['route_type'],
        'operation_type'           => $arrival['operation_type'],
        'status'                   => 'draft',
        'cb_rate'                  => null,
        'settlement_currency_id'   => 2,
        'total_amount_without_vat' => 0.00,
        'total_vat_amount'         => 0.00,
        'total_amount_with_vat'    => 0.00,
        'notes'                    => 'Раунд 110: акт сформирован администратором (CLI-скрипт) — рейс прилёта '
            . 'без акта 5+ дней блокировал NIGHT fee на связанном акте вылета (0069/XX).',
        'created_by_user_id'       => 1, // System Administrator — нет веб-сессии в CLI
        'created_in_session_id'    => null,
    ];
    $act71Id = (int)$actModel->insert($insertData71);
    ActivityLogger::log(
        'act', $act71Id, 'create_act', 'success',
        'Раунд 110: акт создан администратором для рейса #71 (прилёт) — был пропущен диспетчерами, '
            . 'блокировал TLF/NIGHT на связанном акте 0069/XX (рейс #72).',
        null,
        $insertData71
    );
    echo "  Создан акт id={$act71Id} (ПРЕДВАРИТЕЛЬНО, draft).\n";
}

echo "  Авторасчёт обязательных сборов (processParkingAct)...\n";
$warnings71 = $autoCharge->processParkingAct(ARRIVAL_FLIGHT_ID);
foreach ($warnings71 as $w) { echo "    [предупр.] {$w}\n"; }
$autoCharge->syncTechStaffCount(ARRIVAL_FLIGHT_ID);

$build71 = $actService->buildFromFlightCharges($act71Id, ARRIVAL_FLIGHT_ID);
echo "  Сборка строк акта: " . ($build71['success'] ? 'OK' : ('ОШИБКА: ' . ($build71['error'] ?? '?'))) . "\n\n";

$act69 = DEPARTURE_ACT_ID;
echo "=== Шаг 3: пересборка акта {$act69} (рейс #72, эквивалент «Обновить») ===\n";
$departure = $flightModel->find(DEPARTURE_FLIGHT_ID);
$blockers72 = $autoCharge->getActFormationBlockers($departure);
echo "  Блокеры формирования акта по рейсу #72: " . (empty($blockers72) ? 'нет (OK)' : implode('; ', $blockers72)) . "\n";

$warnings72 = $autoCharge->processParkingAct(DEPARTURE_FLIGHT_ID);
foreach ($warnings72 as $w) { echo "    [предупр.] {$w}\n"; }
$autoCharge->syncTechStaffCount(DEPARTURE_FLIGHT_ID);

$build72 = $actService->buildFromFlightCharges(DEPARTURE_ACT_ID, DEPARTURE_FLIGHT_ID);
echo "  Сборка строк акта: " . ($build72['success'] ? 'OK' : ('ОШИБКА: ' . ($build72['error'] ?? '?'))) . "\n\n";

echo "=== Шаг 4: проверка готовности акта {$act69} к утверждению ===\n";
$unconsumed = $chargeModel->getUnconsumedByFlight(DEPARTURE_FLIGHT_ID);
$gaps       = $autoCharge->findMandatoryChargeGaps(DEPARTURE_FLIGHT_ID);
echo "  Непогашенных начислений: " . count($unconsumed) . "\n";
foreach ($unconsumed as $u) { echo "    - {$u['service_name']} ×{$u['quantity']}\n"; }
echo "  Пробелов по обязательным сборам: " . count($gaps) . "\n";
foreach ($gaps as $g) { echo "    - {$g['service_name']}: {$g['reason']}\n"; }
echo "\n";

echo "=== Шаг 5: утверждение акта {$act69} ===\n";
$act69Row = $actModel->find(DEPARTURE_ACT_ID);
if ($act69Row['status'] === 'approved') {
    echo "  Уже утверждён — пропускаю.\n";
} elseif (!empty($unconsumed) || !empty($gaps)) {
    echo "  ОСТАЛИСЬ ПРОБЕЛЫ ВЫШЕ — утверждать не буду, нужен доп. разбор.\n";
} else {
    $hasBothSignatures = !empty($act69Row['supervisor_signature_file_id']) && !empty($act69Row['airline_rep_signature_file_id']);
    echo "  Обе подписи уже сохранены ранее (20.10): " . ($hasBothSignatures ? 'да' : 'нет') . "\n";
    $approveResult = $actService->changeStatus(DEPARTURE_ACT_ID, 'approved', 'Раунд 110: TLF/NIGHT пробел устранён (создан акт рейса-прилёта #71), подписи уже были — довершаю утверждение.');
    if ($approveResult['success']) {
        echo "  Акт {$act69} утверждён.\n";
    } else {
        echo "  НЕ утверждён: " . ($approveResult['error'] ?? '?') . " (код: " . ($approveResult['code'] ?? '-') . ")\n";
    }
}

echo "\n=== Итог ===\n";
$act71Final = $actModel->findByFlightId(ARRIVAL_FLIGHT_ID);
$act69Final = $actModel->find(DEPARTURE_ACT_ID);
echo "Акт рейса #71: id={$act71Final['id']}, статус={$act71Final['status']}, сумма={$act71Final['total_amount_with_vat']}\n";
echo "Акт {$act69}: статус={$act69Final['status']}, сумма={$act69Final['total_amount_with_vat']}\n";
