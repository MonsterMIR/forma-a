<?php
declare(strict_types=1);

/**
 * РАУНД 115 (22.10.2026) — полный аудит связок прилёт⇄вылет по ВСЕМ рейсам.
 * Read-only, без побочных эффектов.
 *
 * Проверяет для каждого борта (aircraft_registration):
 *  1. Связанные пары — взаимность (A->B и B->A), противоположность типов,
 *     хронологическую корректность (прилёт СТРОГО раньше вылета той же пары).
 *  2. Непарные рейсы — есть ли вообще шанс на валидную хронологическую пару
 *     среди остальных непарных рейсов того же борта (вручную, тем же
 *     правилом, что и Flight::findChronologicalLinkCandidates()).
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

$db = Database::getInstance();

$stmt = $db->query(
    "SELECT id, flight_number, operation_type, flight_date, scheduled_time, aircraft_registration,
            linked_flight_id,
            (SELECT COUNT(*) FROM acts WHERE flight_id = flights.id) AS act_count
     FROM flights
     WHERE aircraft_registration IS NOT NULL AND aircraft_registration != ''
     ORDER BY aircraft_registration, flight_date, scheduled_time, id"
);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byId = [];
foreach ($all as $f) { $byId[(int)$f['id']] = $f; }

$byReg = [];
foreach ($all as $f) { $byReg[$f['aircraft_registration']][] = $f; }

function ts(array $f): string {
    $time = !empty($f['scheduled_time']) ? $f['scheduled_time'] : '12:00:00';
    return $f['flight_date'] . ' ' . $time;
}

$anomalies = [];   // реальные проблемы — требуют внимания
$knownGaps = [];   // просто непарные, без кандидата вовсе (ожидаемо)
$fixable   = [];   // непарные, но валидный кандидат СУЩЕСТВУЕТ — похоже на недоработку

echo "Всего рейсов с бортом: " . count($all) . ", бортов: " . count($byReg) . "\n\n";

foreach ($byReg as $reg => $flights) {
    // ── 1. Проверка существующих связок ──
    foreach ($flights as $f) {
        $id = (int)$f['id'];
        $linkedId = (int)($f['linked_flight_id'] ?? 0);
        if ($linkedId <= 0) continue;
        if ($id > $linkedId) continue; // проверим пару один раз, со стороны меньшего id

        $linked = $byId[$linkedId] ?? null;
        if (!$linked) {
            $anomalies[] = "Борт {$reg}: рейс #{$id} ({$f['flight_number']}, {$f['operation_type']}) "
                . "связан с #{$linkedId}, но такого рейса не существует (битая ссылка).";
            continue;
        }

        $backLink = (int)($linked['linked_flight_id'] ?? 0);
        if ($backLink !== $id) {
            $anomalies[] = "Борт {$reg}: рейс #{$id} указывает на #{$linkedId}, но #{$linkedId} указывает "
                . "на #{$backLink} (не взаимно).";
        }

        if ($f['operation_type'] === $linked['operation_type']) {
            $anomalies[] = "Борт {$reg}: рейсы #{$id} и #{$linkedId} связаны, но оба '{$f['operation_type']}' "
                . "(должны быть прилёт+вылет).";
        }

        $arr = $f['operation_type'] === 'arrival' ? $f : ($linked['operation_type'] === 'arrival' ? $linked : null);
        $dep = $f['operation_type'] === 'departure' ? $f : ($linked['operation_type'] === 'departure' ? $linked : null);
        if ($arr && $dep) {
            if (ts($arr) >= ts($dep)) {
                $anomalies[] = "Борт {$reg}: ХРОНОЛОГИЯ НАРУШЕНА — прилёт #{$arr['id']} ({$arr['flight_number']}, "
                    . ts($arr) . ") НЕ РАНЬШЕ вылета #{$dep['id']} ({$dep['flight_number']}, " . ts($dep) . ").";
            }
        }
    }

    // ── 2. Проверка непарных рейсов — есть ли валидный кандидат ──
    $unlinked = array_filter($flights, fn($f) => empty($f['linked_flight_id']));
    foreach ($unlinked as $f) {
        $id = (int)$f['id'];
        $oppositeType = $f['operation_type'] === 'arrival' ? 'departure' : 'arrival';
        $cmp = $f['operation_type'] === 'arrival' ? '>' : '<';
        $myTs = ts($f);

        $candidates = [];
        foreach ($unlinked as $other) {
            if ((int)$other['id'] === $id) continue;
            if ($other['operation_type'] !== $oppositeType) continue;
            $otherTs = ts($other);
            $ok = $cmp === '>' ? ($otherTs > $myTs) : ($otherTs < $myTs);
            if ($ok) $candidates[] = $other;
        }

        if (empty($candidates)) {
            $knownGaps[] = "Борт {$reg}: #{$id} {$f['flight_number']} ({$f['operation_type']}, {$myTs}, "
                . "актов: {$f['act_count']}) — валидного кандидата на пару нет вообще среди непарных рейсов.";
        } else {
            $names = array_map(fn($c) => "#{$c['id']} {$c['flight_number']} ({$c['operation_type']}, " . ts($c) . ")", $candidates);
            $fixable[] = "Борт {$reg}: #{$id} {$f['flight_number']} ({$f['operation_type']}, {$myTs}, "
                . "актов: {$f['act_count']}) — ЕСТЬ хронологически валидный кандидат: " . implode('; ', $names);
        }
    }
}

echo "=== АНОМАЛИИ (реальные проблемы связок) ===\n";
if (empty($anomalies)) {
    echo "Не найдено.\n";
} else {
    foreach ($anomalies as $a) echo "[!] {$a}\n";
}

echo "\n=== НЕПАРНЫЕ С ВАЛИДНЫМ КАНДИДАТОМ (похоже на недоработку автосвязки) ===\n";
if (empty($fixable)) {
    echo "Не найдено.\n";
} else {
    foreach ($fixable as $f) echo "[?] {$f}\n";
}

echo "\n=== НЕПАРНЫЕ БЕЗ КАНДИДАТА ВООБЩЕ (вероятно правда данных) ===\n";
foreach ($knownGaps as $g) echo "    {$g}\n";

echo "\nИтог: аномалий=" . count($anomalies) . ", с кандидатом но не связаны=" . count($fixable)
    . ", без кандидата=" . count($knownGaps) . "\n";
