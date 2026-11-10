<?php
declare(strict_types=1);

/**
 * РЕГРЕССИОННЫЕ ПРОВЕРКИ НА ИНВАРИАНТЫ (12.10.2026, по итогам сверки round69).
 *
 * Зачем: за историю проекта несколько категорий багов (обязательные сборы —
 * раунды 32/34/54/58/60/63; непарные рейсы — раунд 40/69; дрейф ролей/прав —
 * раунды 41/45/65) находились РЕАКТИВНО — через жалобу пользователя или
 * ручной разовый аудит БД. Их общая причина — после каждой находки баг
 * исправлялся точечно, но не оставалось СПОСОБА автоматически проверить,
 * что то же самое правило не нарушится снова после следующей правки.
 *
 * Этот скрипт — не полноценный тест-фреймворк (PHPUnit в проект не
 * заводился), а по образцу уже существующего в проекте паттерна одноразовых
 * CLI-скриптов (см. scripts/round40_backfill_flight_links.php) — набор
 * ЧИТАЮЩИХ (ни одного INSERT/UPDATE/DELETE) проверок трёх ключевых
 * инвариантов системы на ТЕКУЩЕМ состоянии боевой БД. Безопасно запускать
 * повторно и в любое время, в т.ч. под cron/по расписанию — никаких побочных
 * эффектов.
 *
 * Проверки:
 *   1. check_mandatory_charge_gaps — для каждого рейса с актом (любого
 *      статуса) пересчитывает AutoChargeService::findMandatoryChargeGaps()
 *      (чистый метод, добавлен в раунде 60) и сообщает рейсы, где
 *      обязательный сбор должен быть посчитан, но его нет. На approved-актах
 *      это ОШИБКА (акт утверждён с недосчитанной суммой), на draft — просто
 *      предупреждение (ЦОК ещё может нажать «Обновить» перед утверждением).
 *   2. check_unpaired_flights — рейсы с указанным бортовым номером, но без
 *      привязки к парному рейсу (linked_flight_id IS NULL). Не все такие
 *      находки — новый баг: часть уже разобрана и осознанно оставлена
 *      непривязанной (см. claude/analysis-2026-10-12-round69-...md, п.5,
 *      T7AAA7 №44/45/25) — скрипт просто показывает список с контекстом
 *      (дата, борт, акт), финальное суждение "это баг или нет" — за
 *      человеком. Отдельно — рейсы вовсе БЕЗ бортового номера (никогда не
 *      смогут связаться автоматически).
 *   3. check_departure_without_arrival_act — акт рейса ВЫЛЕТА существует
 *      (draft/approved/любой), а у связанного рейса ПРИЛЁТА той же ротации
 *      (тот же борт, flights.linked_flight_id) акт ВООБЩЕ не сформирован
 *      (ни черновика). Добавлено в раунде 113 (22.10.2026) по реальному
 *      случаю — рейс DXT9686: акт вылета (0091/XX-DXT9686) был утверждён,
 *      пока манифест на прилёт не был подан и акт прилёта не существовал —
 *      сбор за взлёт-посадку (TLF, начисляется ТОЛЬКО на акте прилёта) и
 *      уже подтверждённые службами услуги прилёта остались полностью
 *      невыставленными. С раунда 113 ActService::changeStatus() блокирует
 *      НОВЫЕ подобные утверждения (см. код 'LINKED_ARRIVAL_ACT_MISSING') —
 *      эта проверка нужна, чтобы найти уже СУЩЕСТВУЮЩИЕ (одобренные до
 *      раунда 113) случаи, которые новый гейт задним числом не ловит.
 *   4. check_airlines_without_parking_norm — авиакомпании без записи в
 *      airline_parking_norms (раунд 114, реальный случай DXT9686/AIR
 *      MANAGEMENT GROUP — без нормы сверхнормативная стоянка OVER_PARK
 *      тихо не считается вообще). С раунда 114 норма проставляется
 *      автоматически при создании авиакомпании (AirlineController::
 *      store(), FlightController::ensureDefaultParkingNorm()), и
 *      findMandatoryChargeGaps() блокирует утверждение акта вылета при
 *      отсутствующей норме — эта проверка на тот случай, если норму всё же
 *      кто-то удалит вручную или запись попадёт в БД в обход обоих путей.
 *   5. check_role_matrix_drift — сверяет справочник ролей в БД (таблица
 *      `roles`) со списком, зашитым в App\Core\Permissions::allRoles() —
 *      единственным местом, которое реально решает, что роль может делать
 *      (см. Permissions::can()). Рассинхрон в любую сторону означает: либо
 *      роль есть в БД, но Permissions про неё не знает (её права неявно
 *      пустые — пользователь залогинится, но ничего не увидит), либо роль
 *      зашита в коде, но в БД её нет (мёртвый код, безопасно, но говорит о
 *      незавершённой миграции).
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/invariant_checks.php
 *
 * Код возврата: 0 — все проверки чистые, 1 — найдена хотя бы одна ошибка
 * (не считая предупреждений) — удобно для cron/скрипта-обёртки.
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

use App\Core\Database;
use App\Core\Permissions;
use App\Services\AutoChargeService;

$db = Database::getInstance();

$errors   = 0;
$warnings = 0;

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 1. Обязательные сборы — пробелы на уже созданных актах
// ─────────────────────────────────────────────────────────────────────────
section('1. Обязательные услуги в актах (findMandatoryChargeGaps)');

$autoCharge = new AutoChargeService();

$stmt = $db->query(
    "SELECT a.id AS act_id, a.act_number, a.status, f.id AS flight_id,
            f.flight_number, f.flight_date, f.operation_type, f.aircraft_registration
     FROM acts a
     JOIN flights f ON f.id = a.flight_id
     ORDER BY f.flight_date ASC, a.id ASC"
);
$actsWithFlights = $stmt->fetchAll(PDO::FETCH_ASSOC);

$gapsFound = 0;
foreach ($actsWithFlights as $row) {
    $gaps = $autoCharge->findMandatoryChargeGaps((int)$row['flight_id']);
    if (empty($gaps)) continue;

    $gapsFound++;
    $codes = implode(', ', array_map(static fn($g) => $g['code'], $gaps));
    $label = "акт {$row['act_number']} (статус {$row['status']}), рейс #{$row['flight_id']} "
        . "{$row['flight_number']} ({$row['flight_date']}, {$row['operation_type']}, борт "
        . ($row['aircraft_registration'] ?: '—') . ")";

    if (in_array($row['status'], ['approved', 'exported'], true)) {
        $errors++;
        echo "[ОШИБКА]      {$label} — пробелы: {$codes}\n";
        foreach ($gaps as $g) {
            echo "                - {$g['code']}: {$g['reason']}\n";
        }
    } else {
        $warnings++;
        echo "[ПРЕДУПРЕЖД.] {$label} — пробелы: {$codes} (акт ещё не утверждён)\n";
    }
}
if ($gapsFound === 0) {
    echo "OK — пробелов по обязательным сборам не найдено ни в одном из " . count($actsWithFlights) . " актов.\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Непарные рейсы
// ─────────────────────────────────────────────────────────────────────────
section('2. Непарные рейсы (прилёт/вылет)');

$stmt = $db->query(
    "SELECT id, flight_number, flight_date, operation_type, aircraft_registration,
            (SELECT COUNT(*) FROM acts WHERE flight_id = flights.id) AS act_count
     FROM flights
     WHERE linked_flight_id IS NULL
     ORDER BY flight_date ASC, id ASC"
);
$unlinked = $stmt->fetchAll(PDO::FETCH_ASSOC);

$withReg = array_filter($unlinked, static fn($f) => !empty($f['aircraft_registration']));
$noReg   = array_filter($unlinked, static fn($f) => empty($f['aircraft_registration']));

if (empty($withReg)) {
    echo "OK — все рейсы с указанным бортовым номером привязаны к паре.\n";
} else {
    $warnings += count($withReg);
    echo "[ПРЕДУПРЕЖД.] " . count($withReg) . " рейс(ов) с бортом, но без пары — проверьте, не новый ли случай "
        . "(часть уже разобрана и оставлена непривязанной намеренно, см. round69, п.5):\n";
    foreach ($withReg as $f) {
        echo "                - #{$f['id']} {$f['flight_number']} ({$f['flight_date']}, {$f['operation_type']}, "
            . "борт {$f['aircraft_registration']}, актов: {$f['act_count']})\n";
    }
}

if (!empty($noReg)) {
    $errors += count($noReg);
    echo "[ОШИБКА]      " . count($noReg) . " рейс(ов) ВООБЩЕ без бортового номера — не смогут связаться "
        . "автоматически никогда (валидатор формы должен был это заблокировать с round69 — проверьте, не "
        . "через импорт ли они созданы):\n";
    foreach ($noReg as $f) {
        echo "                - #{$f['id']} {$f['flight_number']} ({$f['flight_date']}, {$f['operation_type']}, "
            . "актов: {$f['act_count']})\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 3. Акт вылета есть, а у связанного прилёта акта нет вообще (раунд 113)
// ─────────────────────────────────────────────────────────────────────────
section('3. Акт вылета без акта прилёта той же ротации (TLF-дыра)');

$stmt = $db->query(
    "SELECT a.id AS act_id, a.act_number, a.status, f.id AS flight_id, f.flight_number,
            f.flight_date, f.aircraft_registration, f.linked_flight_id,
            lf.id AS linked_id, lf.operation_type AS linked_op,
            (SELECT id FROM acts WHERE flight_id = lf.id) AS linked_act_id
     FROM acts a
     JOIN flights f ON f.id = a.flight_id
     LEFT JOIN flights lf ON lf.id = f.linked_flight_id
     WHERE a.operation_type = 'departure' AND f.linked_flight_id IS NOT NULL"
);
$departureActs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$holeFound = 0;
foreach ($departureActs as $row) {
    if (($row['linked_op'] ?? '') !== 'arrival') continue; // связан не с прилётом — не наш случай
    if (!empty($row['linked_act_id'])) continue;            // у прилёта акт есть — всё в порядке

    $holeFound++;
    $label = "акт {$row['act_number']} (статус {$row['status']}), рейс #{$row['flight_id']} "
        . "{$row['flight_number']} ({$row['flight_date']}, борт " . ($row['aircraft_registration'] ?: '—')
        . ") -> связанный прилёт #{$row['linked_id']} БЕЗ АКТА ВООБЩЕ";

    if (in_array($row['status'], ['approved', 'exported'], true)) {
        $errors++;
        echo "[ОШИБКА]      {$label} — сбор за взлёт-посадку (TLF) и услуги прилёта, скорее всего, "
            . "не выставлены вообще. Нужно сформировать акт прилёта и решить, как доначислить.\n";
    } else {
        $warnings++;
        echo "[ПРЕДУПРЕЖД.] {$label} (акт вылета ещё не утверждён — с раунда 113 утверждение будет "
            . "заблокировано, пока не появится акт прилёта).\n";
    }
}
if ($holeFound === 0) {
    echo "OK — ни одного акта вылета без акта прилёта той же ротации не найдено (" . count($departureActs) . " проверено).\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 4. Авиакомпании без нормы бесплатной стоянки (раунд 114)
// ─────────────────────────────────────────────────────────────────────────
section('4. Авиакомпании без нормы бесплатной стоянки (airline_parking_norms)');

$stmt = $db->query(
    "SELECT al.id, al.name FROM airlines al
     LEFT JOIN airline_parking_norms n ON n.airline_id = al.id
     WHERE n.id IS NULL
     ORDER BY al.id"
);
$airlinesNoNorm = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($airlinesNoNorm)) {
    echo "OK — у всех авиакомпаний есть норма бесплатной стоянки.\n";
} else {
    // С раунда 114 норма проставляется автоматически при создании
    // авиакомпании (AirlineController::store(), FlightController::
    // ensureDefaultParkingNorm()) — появление здесь чего-либо означает
    // либо старую запись, созданную в обход этих путей (прямой INSERT в
    // БД, миграция и т.п.), либо норму, кем-то удалённую вручную. Без неё
    // сверхнормативная стоянка (OVER_PARK) тихо не считается — см. round113/114.
    $warnings += count($airlinesNoNorm);
    echo "[ПРЕДУПРЕЖД.] " . count($airlinesNoNorm) . " авиакомпани(я/й) без нормы стоянки — "
        . "OVER_PARK не будет считаться на их рейсах, пока не заполните Справочники → Нормы стоянки:\n";
    foreach ($airlinesNoNorm as $al) {
        echo "                - #{$al['id']} {$al['name']}\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 5. Дрейф справочника ролей (БД) vs App\Core\Permissions (код)
// ─────────────────────────────────────────────────────────────────────────
section('5. Роли: справочник БД (roles) vs Permissions::allRoles() в коде');

$stmt = $db->query("SELECT code FROM roles ORDER BY code");
$dbRoles   = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'code');
$codeRoles = array_keys(Permissions::allRoles());

$onlyInDb   = array_values(array_diff($dbRoles, $codeRoles));
$onlyInCode = array_values(array_diff($codeRoles, $dbRoles));

if (empty($onlyInDb) && empty($onlyInCode)) {
    echo "OK — все " . count($dbRoles) . " ролей из БД присутствуют в Permissions::allRoles() и наоборот.\n";
} else {
    if (!empty($onlyInDb)) {
        $errors++;
        echo "[ОШИБКА]      роли есть в БД (таблица roles), но НЕТ в Permissions::allRoles() — у пользователей "
            . "с такой ролью права будут неявно пустыми: " . implode(', ', $onlyInDb) . "\n";
    }
    if (!empty($onlyInCode)) {
        $warnings++;
        echo "[ПРЕДУПРЕЖД.] роли зашиты в Permissions::allRoles(), но НЕТ в БД (таблица roles) — мёртвый код, "
            . "не ошибка, но незавершённая миграция: " . implode(', ', $onlyInCode) . "\n";
    }
}

// Дополнительно: пользователи, чей role_id не резолвится ни в одну строку
// справочника roles (битая ссылка — не должно быть возможно при FK, но
// проверяем явно, т.к. это критично для входа пользователя).
$stmt = $db->query(
    "SELECT u.id, u.username FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE r.id IS NULL"
);
$orphanUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!empty($orphanUsers)) {
    $errors++;
    echo "[ОШИБКА]      пользователи с role_id, не найденным в справочнике roles (не смогут корректно "
        . "авторизоваться): " . implode(', ', array_map(static fn($u) => "#{$u['id']} {$u['username']}", $orphanUsers)) . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n=== Итог ===\n";
echo "Ошибок: {$errors}\n";
echo "Предупреждений: {$warnings}\n";

exit($errors > 0 ? 1 : 0);
