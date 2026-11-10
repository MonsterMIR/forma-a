<?php
/**
 * scripts/round135_fix_t7aaa7_crossed_links.php
 *
 * Round 115 (22.10.2026) нашёл у борта T7AAA7 перекрёстную связку
 * прилёт⇄вылет (физически невозможный порядок: вылет привязан к прилёту,
 * случившемуся РАНЬШЕ этого вылета, или наоборот). Исправление было
 * подготовлено, но ждало подтверждения пользователя — round135 (27.10.2026,
 * "подчисти косяковые рейсы T7AAA7/P4VVIP", пользователь выбрал "только
 * исправить связки") это подтверждение даёт.
 *
 * Сейчас (до исправления):
 *   #11  вылет 05.10 12:00  linked_flight_id -> 24
 *   #24  прилёт 07.10 03:30 linked_flight_id -> 11
 *   #44  вылет 09.10 15:05  linked_flight_id -> 45
 *   #45  прилёт 09.10 18:05 linked_flight_id -> 44
 *   #25  вылет 13.10 09:00  linked_flight_id -> NULL
 *
 * После (правильные хронологические пары "стоянка на земле": прилёт,
 * затем ближайший следующий по времени вылет того же борта):
 *   #11  -> NULL   (первый зафиксированный рейс борта — прилёта-кандидата
 *                   раньше него в системе нет; реальный пробел данных, не
 *                   лечится перелинковкой)
 *   #24  (прилёт 07.10 03:30) -> 44 (вылет 09.10 15:05)
 *   #44  -> 24
 *   #45  (прилёт 09.10 18:05) -> 25 (вылет 13.10 09:00)
 *   #25  -> 45
 *
 * Это ЧИСТО структурное исправление поля flights.linked_flight_id у 5
 * строк. Акты, суммы, статусы, approved_by_user_id — НЕ трогаются вообще
 * (подтверждено отдельной проверкой ниже: до и после снимок таблицы acts
 * по затронутым рейсам идентичен).
 *
 * Режимы:
 *   php round135_fix_t7aaa7_crossed_links.php --dry-run   (транзакция + rollBack)
 *   php round135_fix_t7aaa7_crossed_links.php --commit    (реальное исполнение)
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
use App\Core\ActivityLogger;

$mode = $argv[1] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--commit'], true)) {
    fwrite(STDERR, "Usage: php {$argv[0]} --dry-run|--commit\n");
    exit(1);
}

$db = Database::getInstance();

$expectedBefore = [
    11 => 24,
    24 => 11,
    44 => 45,
    45 => 44,
    25 => null,
];

$patch = [
    11 => null,
    24 => 44,
    44 => 24,
    45 => 25,
    25 => 45,
];

function fetchLinks(\PDO $db, array $ids): array
{
    $in = implode(',', array_map('intval', $ids));
    $rows = $db->query("SELECT id, flight_number, operation_type, flight_date, scheduled_time, linked_flight_id FROM flights WHERE id IN ($in)")->fetchAll();
    $out = [];
    foreach ($rows as $r) { $out[(int)$r['id']] = $r; }
    return $out;
}

function actsSnapshot(\PDO $db, array $flightIds): array
{
    $in = implode(',', array_map('intval', $flightIds));
    $rows = $db->query("
        SELECT flight_id, id, act_number, status, total_amount_without_vat, total_vat_amount, total_amount_with_vat
        FROM acts WHERE flight_id IN ($in)
    ")->fetchAll();
    $out = [];
    foreach ($rows as $r) { $out[(int)$r['flight_id']] = $r; }
    return $out;
}

$ids = array_keys($expectedBefore);

$before = fetchLinks($db, $ids);
echo "=== ТЕКУЩЕЕ СОСТОЯНИЕ (до патча) ===\n";
foreach ($ids as $id) {
    $r = $before[$id];
    echo sprintf("  #%-4d %-10s %-9s %s %s  linked_flight_id=%s\n",
        $id, $r['flight_number'], $r['operation_type'], $r['flight_date'], $r['scheduled_time'],
        $r['linked_flight_id'] ?? 'NULL');
}

$mismatch = false;
foreach ($expectedBefore as $id => $expectedLink) {
    $actual = $before[$id]['linked_flight_id'] !== null ? (int)$before[$id]['linked_flight_id'] : null;
    if ($actual !== $expectedLink) {
        echo "СТОП: #$id уже имеет linked_flight_id=" . ($actual ?? 'NULL') . ", ожидалось " . ($expectedLink ?? 'NULL') . " — данные изменились с момента round115, патч не применяю.\n";
        $mismatch = true;
    }
}
if ($mismatch) { exit(1); }

$actsBefore = actsSnapshot($db, $ids);

$db->beginTransaction();
try {
    foreach ($patch as $id => $newLink) {
        $stmt = $db->prepare("UPDATE flights SET linked_flight_id = :link WHERE id = :id");
        $stmt->execute(['link' => $newLink, 'id' => $id]);
        ActivityLogger::log(
            'flight',
            $id,
            'round135_fix_t7aaa7_crossed_link',
            'success',
            "Исправлена перекрёстная связка T7AAA7 (round115/round135): linked_flight_id "
                . ($before[$id]['linked_flight_id'] ?? 'NULL') . " -> " . ($newLink ?? 'NULL'),
            ['linked_flight_id_before' => $before[$id]['linked_flight_id']],
            ['linked_flight_id_after' => $newLink]
        );
    }

    $after = fetchLinks($db, $ids);
    echo "\n=== ПОСЛЕ ПАТЧА ===\n";
    foreach ($ids as $id) {
        $r = $after[$id];
        echo sprintf("  #%-4d %-10s %-9s %s %s  linked_flight_id=%s\n",
            $id, $r['flight_number'], $r['operation_type'], $r['flight_date'], $r['scheduled_time'],
            $r['linked_flight_id'] ?? 'NULL');
    }

    $reciprocityOk = true;
    $pairs = [[24, 44], [45, 25]];
    foreach ($pairs as [$a, $b]) {
        $la = $after[$a]['linked_flight_id'] !== null ? (int)$after[$a]['linked_flight_id'] : null;
        $lb = $after[$b]['linked_flight_id'] !== null ? (int)$after[$b]['linked_flight_id'] : null;
        if ($la !== $b || $lb !== $a) {
            echo "ОШИБКА ВЗАИМНОСТИ: #$a->$la, #$b->$lb (ожидалось #$a<->#$b)\n";
            $reciprocityOk = false;
        }
    }
    if ($after[11]['linked_flight_id'] !== null) {
        echo "ОШИБКА: #11 должен быть NULL, сейчас " . $after[11]['linked_flight_id'] . "\n";
        $reciprocityOk = false;
    }
    echo "Взаимность связок: " . ($reciprocityOk ? "OK" : "ОШИБКА") . "\n";

    $chronoOk = true;
    foreach ($pairs as [$a, $b]) {
        $ra = $after[$a]; $rb = $after[$b];
        $arrRow = $ra['operation_type'] === 'arrival' ? $ra : $rb;
        $depRow = $ra['operation_type'] === 'departure' ? $ra : $rb;
        $arrTs = strtotime($arrRow['flight_date'] . ' ' . $arrRow['scheduled_time']);
        $depTs = strtotime($depRow['flight_date'] . ' ' . $depRow['scheduled_time']);
        if (!($arrTs < $depTs)) {
            echo "ОШИБКА ХРОНОЛОГИИ: прилёт #{$arrRow['id']} НЕ раньше вылета #{$depRow['id']}\n";
            $chronoOk = false;
        }
    }
    echo "Хронология пар (прилёт раньше вылета): " . ($chronoOk ? "OK" : "ОШИБКА") . "\n";

    $actsAfter = actsSnapshot($db, $ids);
    $actsUnchanged = ($actsBefore == $actsAfter);
    echo "Акты (суммы/статусы/номера) не изменились: " . ($actsUnchanged ? "OK" : "ОШИБКА!!!") . "\n";

    if ($mode === '--dry-run') {
        $db->rollBack();
        echo "\n=== DRY-RUN: транзакция ОТКАЧЕНА, в БД ничего не изменено ===\n";
    } else {
        if (!$reciprocityOk || !$chronoOk || !$actsUnchanged) {
            $db->rollBack();
            echo "\n=== ОШИБКА КОНТРОЛЯ — транзакция ОТКАЧЕНА, commit прерван ===\n";
            exit(1);
        }
        $db->commit();
        echo "\n=== COMMIT: изменения записаны в БД ===\n";
    }
} catch (\Throwable $e) {
    $db->rollBack();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
