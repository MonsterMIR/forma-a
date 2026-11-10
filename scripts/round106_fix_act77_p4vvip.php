<?php
declare(strict_types=1);

/**
 * РАУНД 106 (20.10.2026) — разблокировка акта 0077/XX-P4VVIP (рейс #84).
 *
 * Контекст (по скриншоту пользователя "Не могу утвердить акт"): акт 0077
 * (рейс #84, P4VVIP, вылет 18.10.2026) не утверждается — блокировка
 * UNSYNCED_CHARGES (раунд 34/93): "В акт не внесены уже подтверждённые
 * услуги... Не внесено: Сбор за посадку/взлет ночью / Night fee (×0.2% от
 * TLF)".
 *
 * Разбор корневой причины (запросы к БД и activity-логу 20.10.2026):
 *  - Рейс #84 (вылет, 18.10.2026 06:39) — международный, TVT -> UCFM
 *    (Шымкент), давно создан и обслужен.
 *  - Рейс #97 (прилёт, P4VVIP, 20.10.2026 20:00) создан СЕГОДНЯ в 12:31 —
 *    внутренний рейс, UZSS (Самарканд) -> TVT. Система автоматически
 *    связала его с рейсом #84 (единственный несвязанный вылет этого
 *    борта на тот момент, см. FlightService::autoLinkFlight()) как "одна
 *    стоянка/визит".
 *  - Но это ФИЗИЧЕСКИ не может быть одним визитом: #84 — международный
 *    вылет TVT->UCFM 18.10, #97 — внутренний прилёт UZSS->TVT 20.10, то
 *    есть это два РАЗНЫХ, НЕСВЯЗАННЫХ отрезка маршрута одного и того же
 *    борта (борт улетел в Шымкент, затем, видимо, был в Самарканде, и
 *    только сейчас возвращается в TVT) — не "прилетел и стоит, потом
 *    улетел". Вылет #84 на ДВА ДНЯ РАНЬШЕ прилёта #97 — автосвязка не
 *    проверяет порядок дат (правило раунда 33/40/69 требует только
 *    совпадение борта + противоположный тип + единственность кандидата).
 *  - В системе вообще НЕТ рейса прилёта, предшествующего вылету #84 (ни
 *    одного P4VVIP/CL60 рейса до 18.10) — то есть прилёт этого самого
 *    визита (из которого потом состоялся вылет в Шымкент) в систему
 *    никогда не вносился. Поэтому TLF (и % от неё — NIGHT/AV_SEC) для
 *    этого визита никогда не считался — ни на одном акте.
 *  - В 17:11 сегодня ЦОК нажал «Обновить» на акте 0077 —
 *    AutoChargeService::processParkingAct(84) вызывается с ID рейса
 *    ВЫЛЕТА напрямую (см. ActController::resync()), а не только для рейса
 *    прилёта, как подразумевает её докблок ("$flight здесь всегда рейс
 *    прилёта") — вставил NIGHT (0.2, ночной интервал по STD 07:00) в
 *    flight_service_charges рейса #84. Дальше buildFromFlightCharges()
 *    пыталась найти сумму TLF: своей строки TLF на акте вылета нет (и не
 *    может быть, см. правило раунда 21); у связанного "прилёта" #97 акта
 *    вообще ещё не существует (прилёт только сегодня вечером). TLF найти
 *    негде — NIGHT осталась НЕ внесена в акт (unconsumed), и так и будет
 *    оставаться при каждом «Обновить» — бесконечная блокировка.
 *
 * Что делает скрипт:
 *  1. Расцепляет #84 и #97 (FlightService::unlinkFlights) — это не один
 *     визит, связь ошибочна и может испортить данные дальше (например
 *     перенести MTOW/стоянку между разными визитами).
 *  2. Удаляет зависшую, принципиально непригодную к расчёту запись NIGHT
 *     из flight_service_charges рейса #84 (consumed_in_act_id IS NULL,
 *     is_auto=1) — TLF для этого визита нет и взять неоткуда (прилёт в
 *     систему не внесён), посчитать 20% от несуществующей суммы нельзя.
 *     Строка удаляется, а не "прощается" молча — см. вывод скрипта и
 *     activity-лог, пользователь должен решить отдельно (см. round106
 *     .md), вносить ли исторический рейс прилёта для TLF этого визита.
 *
 * ВАЖНО: деньги/act_items не трогает — NIGHT в сам акт 0077 так и не
 * попала (была unconsumed), строка в act_items не создавалась. Эффект —
 * только снятие блокировки UNSYNCED_CHARGES.
 *
 * Безопасно запускать повторно.
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/round106_fix_act77_p4vvip.php
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
use App\Models\Flight;
use App\Models\FlightServiceCharge;
use App\Services\AutoChargeService;
use App\Services\FlightService;

const DEPARTURE_FLIGHT_ID = 84; // P4VVIP, вылет 18.10.2026, акт 0077
const WRONG_ARRIVAL_ID    = 97; // P4VVIP, прилёт 20.10.2026 — отдельный визит

$db          = Database::getInstance();
$flightModel = new Flight();
$chargeModel = new FlightServiceCharge();
$flightSvc   = new FlightService();
$autoCharge  = new AutoChargeService();

$departure = $flightModel->find(DEPARTURE_FLIGHT_ID);
$arrival   = $flightModel->find(WRONG_ARRIVAL_ID);

if (!$departure || !$arrival) {
    fwrite(STDERR, "Один из рейсов (#84 / #97) не найден — прерываю.\n");
    exit(1);
}

echo "До исправления:\n";
echo "  #84 (вылет 18.10):  linked_flight_id=" . ($departure['linked_flight_id'] ?: '—') . "\n";
echo "  #97 (прилёт 20.10): linked_flight_id=" . ($arrival['linked_flight_id'] ?: '—') . "\n\n";

// Шаг 1: расцепляем — это не один визит (вылет раньше прилёта физически
// невозможен для одной стоянки).
if (!empty($departure['linked_flight_id']) && (int)$departure['linked_flight_id'] === WRONG_ARRIVAL_ID) {
    $unlinkResult = $flightSvc->unlinkFlights(DEPARTURE_FLIGHT_ID);
    if ($unlinkResult['success']) {
        echo "Шаг 1: рейсы #84 и #97 расцеплены (ошибочная связка — вылет раньше прилёта).\n";
    } else {
        fwrite(STDERR, "Шаг 1: расцепить не удалось — " . ($unlinkResult['error'] ?? '?') . "\n");
    }
} else {
    echo "Шаг 1: рейсы уже не связаны между собой — пропускаю.\n";
}

// Шаг 2: удаляем зависшую NIGHT-начисление рейса #84 — TLF для этого
// визита взять неоткуда (прилёт не внесён в систему), посчитать 20% не от
// чего, а оставленная висеть запись блокирует approve() навсегда.
$nightId = null;
$stmt = $db->prepare("SELECT id FROM services WHERE code = 'NIGHT' LIMIT 1");
$stmt->execute();
$nightId = (int)$stmt->fetchColumn();

$removed = 0;
if ($nightId) {
    $stmt = $db->prepare(
        "SELECT * FROM flight_service_charges WHERE flight_id = ? AND service_id = ? AND consumed_in_act_id IS NULL"
    );
    $stmt->execute([DEPARTURE_FLIGHT_ID, $nightId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $chargeModel->delete((int)$row['id']);
        ActivityLogger::log(
            'flight', DEPARTURE_FLIGHT_ID, 'manual_remove_stray_charge', 'success',
            "Раунд 106: удалена непогашенная запись NIGHT (id={$row['id']}) из flight_service_charges — "
                . "TLF для этого визита взять неоткуда (рейс прилёта, предшествующий #84, в системе отсутствует), "
                . "запись блокировала утверждение акта 0077 (UNSYNCED_CHARGES) без возможности когда-либо "
                . "корректно её погасить",
            $row,
            null
        );
        echo "Шаг 2: удалена зависшая запись NIGHT (id={$row['id']}, qty={$row['quantity']}).\n";
        $removed++;
    }
    if ($removed === 0) {
        echo "Шаг 2: зависших записей NIGHT по рейсу #84 не найдено — пропускаю.\n";
    }
} else {
    fwrite(STDERR, "Шаг 2: сервис NIGHT не найден в справочнике — пропускаю.\n");
}

// Проверка: теперь ли акт 0077 может быть утверждён (по тем же правилам,
// что ActService::changeStatus() использует для блокировки approve()).
$unconsumed    = $chargeModel->getUnconsumedByFlight(DEPARTURE_FLIGHT_ID);
$mandatoryGaps = $autoCharge->findMandatoryChargeGaps(DEPARTURE_FLIGHT_ID);

echo "\n--- Проверка готовности к утверждению (рейс #84) ---\n";
echo "Непогашенных начислений: " . count($unconsumed) . (empty($unconsumed) ? ' (OK)' : '') . "\n";
foreach ($unconsumed as $u) {
    echo "  - {$u['service_name']} ×{$u['quantity']}\n";
}
echo "Пробелов по обязательным сборам: " . count($mandatoryGaps) . (empty($mandatoryGaps) ? ' (OK)' : '') . "\n";
foreach ($mandatoryGaps as $g) {
    echo "  - {$g['service_name']}: {$g['reason']}\n";
}

if (empty($unconsumed) && empty($mandatoryGaps)) {
    echo "\nГотово: блокировка UNSYNCED_CHARGES снята, акт 0077 можно утверждать через интерфейс "
        . "(нужен скан/подписи — это отдельное требование, не трогал).\n";
} else {
    echo "\nВНИМАНИЕ: остались незакрытые проверки выше — акт всё ещё не утвердится, нужен доп. разбор.\n";
}

echo "\nНапоминание: TLF (и соответственно NIGHT/AV_SEC) для ЭТОГО визита (рейс #84, 18.10.2026) "
    . "так и не посчитан нигде — рейс прилёта, предшествующий этому вылету, в системе отсутствует. "
    . "Это отдельный открытый вопрос, не исправленный этим скриптом (см. round106 .md в проекте).\n";
