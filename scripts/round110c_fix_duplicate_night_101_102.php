<?php
declare(strict_types=1);

/**
 * РАУНД 110b (21.10.2026) — находка при закрытии акта рейса #71: NIGHT
 * (сбор за посадку/взлёт ночью) посчитался и вошёл ОБА акта одной ротации
 * — и на акт прилёта (95, рейс #71), и на акт вылета (69, рейс #72), по
 * 100.00 на каждом (итого 200.00 вместо положенных 100.00 за одну
 * ротацию). Это прямое нарушение явно задокументированного правила
 * раунда 67 (AutoChargeService::processParkingAct(), комментарий "NIGHT"):
 * "сбор по-прежнему начисляется ОДИН раз за ротацию, просто триггер —
 * 'хотя бы одна нога ночью'". Причина — processParkingAct() вызывается
 * ОТДЕЛЬНО для каждого акта своей ротации (resync()/buildAct() вызывают
 * его с flight_id ТОГО акта, который сейчас собирают — не всегда рейса
 * прилёта, вопреки докблоку "$flight здесь всегда рейс прилёта"), и ничего
 * не проверяет, не начислен ли уже NIGHT на связанном рейсе той же
 * ротации — см. полный разбор и масштаб (ещё 10 approved-ротаций с тем
 * же дефектом) в claude/analysis-2026-10-21-round110-*.md.
 *
 * Это ПРОМЕЖУТОЧНЫЙ скрипт только для пары #71/#72 (оба акта ещё draft,
 * ничего не подписано/не утверждено с этим задвоенным содержимым — для
 * уже approved-ротаций нужно отдельное решение пользователя, этот скрипт
 * их не трогает).
 *
 * Оставляет NIGHT на акте ПРИЛЁТА (95, рейс #71) — это и есть
 * задокументированное каноническое место начисления ("на акте прилёта").
 * Удаляет строку с акта ВЫЛЕТА (69, рейс #72) и её исходную запись в
 * flight_service_charges, чтобы дубль не возродился при следующем
 * "Обновить".
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/round110b_fix_duplicate_night_71_72.php
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
use App\Models\Act;
use App\Models\FlightServiceCharge;
use App\Services\ActService;
use App\Services\AutoChargeService;

const DEPARTURE_ACT_ID    = 91;
const DEPARTURE_FLIGHT_ID = 102;
const DUP_ACT_ITEM_ID     = 1559; // NIGHT на акте 69 — дубль

$db          = Database::getInstance();
$actModel    = new Act();
$chargeModel = new FlightServiceCharge();
$actService  = new ActService();
$autoCharge  = new AutoChargeService();

$item = $db->prepare("SELECT ai.*, s.code FROM act_items ai JOIN services s ON s.id=ai.service_id WHERE ai.id=? AND ai.act_id=?");
$item->execute([DUP_ACT_ITEM_ID, DEPARTURE_ACT_ID]);
$row = $item->fetch(\PDO::FETCH_ASSOC);

if (!$row) {
    echo "Строка act_items id=" . DUP_ACT_ITEM_ID . " на акте " . DEPARTURE_ACT_ID . " не найдена — возможно, уже исправлено.\n";
    exit(0);
}
if ($row['code'] !== 'NIGHT') {
    fwrite(STDERR, "Строка {$row['id']} — не NIGHT (код {$row['code']}), прерываю на всякий случай.\n");
    exit(1);
}

echo "Удаляю дублирующую строку NIGHT (act_items id={$row['id']}, сумма={$row['amount_with_vat']}) с акта " . DEPARTURE_ACT_ID . "...\n";
$del = $actService->deleteActItem((int)$row['id']);
echo "  " . ($del['success'] ? 'OK' : ('ОШИБКА: ' . ($del['error'] ?? '?'))) . "\n";

// Удаляем исходную запись начисления (flight_service_charges) по рейсу #72,
// чтобы она не нашлась заново при следующем «Обновить».
$stmt = $db->prepare(
    "SELECT fsc.id FROM flight_service_charges fsc
     JOIN services s ON s.id = fsc.service_id AND s.code = 'NIGHT'
     WHERE fsc.flight_id = ?"
);
$stmt->execute([DEPARTURE_FLIGHT_ID]);
$chargeIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

foreach ($chargeIds as $chargeId) {
    $chargeModel->delete((int)$chargeId);
    echo "  Удалена запись flight_service_charges id={$chargeId} (рейс #" . DEPARTURE_FLIGHT_ID . ", NIGHT).\n";
}

ActivityLogger::log(
    'act', DEPARTURE_ACT_ID, 'manual_remove_duplicate_night', 'success',
    'Раунд 110b: удалена дублирующая строка NIGHT с акта вылета (' . DEPARTURE_ACT_ID . ', рейс #'
        . DEPARTURE_FLIGHT_ID . ') — NIGHT уже корректно начислен на акте прилёта (95, рейс #71) той же '
        . 'ротации; processParkingAct() ошибочно начислил его ещё раз при пересборке акта вылета '
        . '(подтверждённый баг, см. round110 .md — затрагивает ещё 10 уже утверждённых ротаций).',
    $row,
    null
);

echo "\n--- Проверка акта " . DEPARTURE_ACT_ID . " после исправления ---\n";
$unconsumed = $chargeModel->getUnconsumedByFlight(DEPARTURE_FLIGHT_ID);
$gaps       = $autoCharge->findMandatoryChargeGaps(DEPARTURE_FLIGHT_ID);
echo "Непогашенных начислений: " . count($unconsumed) . "\n";
echo "Пробелов по обязательным сборам: " . count($gaps) . "\n";

$actFinal = $actModel->find(DEPARTURE_ACT_ID);
echo "Акт " . DEPARTURE_ACT_ID . ": статус={$actFinal['status']}, сумма={$actFinal['total_amount_with_vat']}\n";
