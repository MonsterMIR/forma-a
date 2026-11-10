<?php
declare(strict_types=1);

/**
 * РАУНД 108c (21.10.2026) — read-only сверка: что изменится, если правило
 * автосвязки рейсов переписать на модель «последнее по времени движение
 * борта закрывает/открывает визит» (обсуждение раунда 108, по прямому
 * указанию пользователя, после случая P4VVIP — раунд 106).
 *
 * НИЧЕГО не пишет в БД. Только отчёт: сравнивает то, что уже СВЯЗАНО в
 * `flights.linked_flight_id` сейчас, с тем, что получилось бы по новому
 * правилу, если применить его к текущим данным с нуля.
 *
 * Новое правило (симуляция по каждому борту отдельно, в хронологическом
 * порядке по flight_date+scheduled_time):
 *   - ПРИЛЁТ открывает визит. Если на момент этого прилёта уже был
 *     открытый (ещё не закрытый вылетом) визит того же борта — старый
 *     визит остаётся незакрытым («сирота-прилёт», та же ситуация, что и
 *     сейчас не распознаётся автоматически), новый прилёт открывает
 *     СВОЙ собственный визит.
 *   - ВЫЛЕТ закрывает ТОЛЬКО самый последний ещё не закрытый визit того
 *     же борта (если такого нет — вылет остаётся без пары, как и
 *     сейчас в случае отсутствия предшествующего прилёта в системе).
 *   - Новый ПРИЛЁТ никогда не подхватывает себе чужой, уже прошедший
 *     вылет («сирота-вылет») — именно это и было корневой причиной бага
 *     P4VVIP (рейсы #84/#97).
 *
 * Вывод:
 *   1) Для каждой уже связанной сейчас пары — совпадает ли она с тем,
 *      что дало бы новое правило. Если НЕТ — это регрессия, нельзя
 *      включать новое правило, пока не разобрана.
 *   2) Пары, которые новое правило создало бы, а сейчас не связаны —
 *      потенциальные новые находки (или ложные срабатывания — к каждой
 *      нужно будет присмотреться перед включением правила).
 *   3) Рейсы, которые новое правило оставляет сиротами (без пары) —
 *      для информации, не ошибка.
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/round108c_verify_new_linking_rule.php
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

$db = Database::getInstance();

$stmt = $db->query(
    "SELECT id, flight_number, flight_date, scheduled_time, operation_type,
            aircraft_registration, linked_flight_id
     FROM `flights`
     WHERE aircraft_registration IS NOT NULL AND aircraft_registration <> ''
     ORDER BY aircraft_registration ASC,
              flight_date ASC, COALESCE(scheduled_time, '00:00:00') ASC, id ASC"
);
$allFlights = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byReg = [];
foreach ($allFlights as $f) {
    $byReg[$f['aircraft_registration']][] = $f;
}

// Симуляция нового правила по каждому борту.
$simulatedLinks = []; // flightId => linkedFlightId (симметрично проставим оба направления)
$orphans        = []; // flightId => ['type' => 'arrival'|'departure', 'reason' => ...]

foreach ($byReg as $reg => $flights) {
    $openArrivalId = null; // id последнего прилёта, который ещё не закрыт вылетом

    foreach ($flights as $f) {
        $id   = (int)$f['id'];
        $type = $f['operation_type'];

        if ($type === 'arrival') {
            if ($openArrivalId !== null) {
                // Предыдущий прилёт так и не был закрыт вылетом — остаётся сиротой.
                $orphans[$openArrivalId] = ['type' => 'arrival', 'reason' => 'новый прилёт того же борта наступил раньше, чем нашёлся вылет для предыдущего'];
            }
            $openArrivalId = $id;
        } elseif ($type === 'departure') {
            if ($openArrivalId !== null) {
                $simulatedLinks[$id] = $openArrivalId;
                $simulatedLinks[$openArrivalId] = $id;
                $openArrivalId = null;
            } else {
                $orphans[$id] = ['type' => 'departure', 'reason' => 'нет предшествующего не закрытого прилёта того же борта в системе'];
            }
        }
    }
    if ($openArrivalId !== null) {
        $orphans[$openArrivalId] = ['type' => 'arrival', 'reason' => 'последнее движение борта в системе — этот прилёт, вылет после него ещё не внесён (борт «на земле»)'];
    }
}

$byId = [];
foreach ($allFlights as $f) {
    $byId[(int)$f['id']] = $f;
}

// 1) Сравнение текущих связок с тем, что дало бы новое правило.
$currentlyLinked = array_filter($allFlights, fn($f) => $f['linked_flight_id'] !== null);
$checked = [];
$matches = 0;
$regressions = [];

foreach ($currentlyLinked as $f) {
    $id = (int)$f['id'];
    if (isset($checked[$id])) continue;
    $linkedId = (int)$f['linked_flight_id'];
    $checked[$id] = true;
    $checked[$linkedId] = true;

    $simulatedPartner = $simulatedLinks[$id] ?? null;

    if ($simulatedPartner === $linkedId) {
        $matches++;
    } else {
        $regressions[] = [
            'flight_a'          => $f,
            'current_partner_id'=> $linkedId,
            'simulated_partner_id' => $simulatedPartner,
        ];
    }
}

echo "=== РАУНД 108c: сверка текущей связки vs. нового правила ===\n\n";
echo "Всего связанных пар сейчас: " . count($currentlyLinked) / 2 . " (" . count($currentlyLinked) . " рейсов)\n";
echo "Совпали с новым правилом: " . ($matches) . " пар\n";
echo "НЕ совпали (потенциальная регрессия): " . count($regressions) . " пар\n\n";

if (!empty($regressions)) {
    echo "--- Детали несовпадений ---\n";
    foreach ($regressions as $r) {
        $a = $r['flight_a'];
        $partnerCurrent = $byId[$r['current_partner_id']] ?? null;
        $partnerSim     = $r['simulated_partner_id'] !== null ? ($byId[$r['simulated_partner_id']] ?? null) : null;

        echo "Рейс #{$a['id']} {$a['flight_number']} ({$a['operation_type']}, {$a['flight_date']} {$a['scheduled_time']}, борт {$a['aircraft_registration']})\n";
        echo "  Сейчас связан с: #" . $r['current_partner_id']
            . ($partnerCurrent ? " {$partnerCurrent['flight_number']} ({$partnerCurrent['operation_type']}, {$partnerCurrent['flight_date']})" : '') . "\n";
        echo "  По новому правилу: " . ($r['simulated_partner_id'] !== null
                ? "#{$r['simulated_partner_id']}" . ($partnerSim ? " {$partnerSim['flight_number']} ({$partnerSim['operation_type']}, {$partnerSim['flight_date']})" : '')
                : "нет пары (сирота)") . "\n\n";
    }
}

// 2) Новые находки — пары, которые новое правило бы создало, а сейчас не связаны.
$newFindings = [];
$seenPairs = [];
foreach ($simulatedLinks as $id => $partnerId) {
    $pairKey = min($id, $partnerId) . '-' . max($id, $partnerId);
    if (isset($seenPairs[$pairKey])) continue;
    $seenPairs[$pairKey] = true;

    $f = $byId[$id];
    if ($f['linked_flight_id'] === null) {
        $newFindings[] = [$id, $partnerId];
    }
}

echo "\n=== Новые находки (новое правило связало бы, сейчас не связаны) ===\n";
echo "Пар: " . count($newFindings) . "\n";
foreach ($newFindings as [$id, $partnerId]) {
    $a = $byId[$id];
    $b = $byId[$partnerId];
    echo "  #{$a['id']} {$a['flight_number']} ({$a['operation_type']}, {$a['flight_date']} {$a['scheduled_time']})"
        . " <-> #{$b['id']} {$b['flight_number']} ({$b['operation_type']}, {$b['flight_date']} {$b['scheduled_time']})"
        . " — борт {$a['aircraft_registration']}\n";
}

// 3) Сироты по новому правилу.
echo "\n=== Рейсы-сироты по новому правилу (" . count($orphans) . ") ===\n";
foreach ($orphans as $id => $info) {
    $f = $byId[$id];
    echo "  #{$f['id']} {$f['flight_number']} ({$f['operation_type']}, {$f['flight_date']} {$f['scheduled_time']}, борт {$f['aircraft_registration']}) — {$info['reason']}\n";
}

echo "\n=== Итог ===\n";
if (empty($regressions)) {
    echo "РЕГРЕССИЙ НЕТ: все текущие связанные пары совпадают с результатом нового правила.\n"
        . "Новое правило можно безопасно включать (после отдельного решения по новым находкам, если они есть).\n";
} else {
    echo "ВНИМАНИЕ: " . count($regressions) . " пар(ы) расходятся — нужно разобрать каждую ПЕРЕД включением нового правила.\n";
}
