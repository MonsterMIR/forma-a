<?php
declare(strict_types=1);
// РАУНД 117 (22.10.2026) — первичное заполнение справочника бортов
// (таблица `aircraft`) из уже существующей истории `flights`.
// По прямому указанию пользователя: 9 бортов с расхождением MTOW в
// истории (см. обсуждение в чате 22.10.2026, round116) СЕЙЧАС НЕ
// заводятся с конкретным значением — заводятся как "спорные"
// (mtow=NULL, resolution_notes = список расходящихся значений), ждут
// решения начальника ЦОК. Остальные борта (у которых MTOW по истории
// везде совпадает) заводятся сразу подтверждёнными.

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
use App\Models\Aircraft;

$db = Database::getInstance();
$aircraftModel = new Aircraft();

$stmt = $db->query(
    "SELECT aircraft_registration, aircraft_type, aircraft_mtow, flight_date, scheduled_time
     FROM flights
     WHERE aircraft_registration IS NOT NULL AND aircraft_registration != ''
     ORDER BY aircraft_registration, flight_date, scheduled_time"
);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byReg = [];
foreach ($all as $f) {
    $byReg[$f['aircraft_registration']][] = $f;
}

$resolved = 0;
$unresolved = 0;

foreach ($byReg as $reg => $flights) {
    $mtows = [];
    foreach ($flights as $f) {
        if ($f['aircraft_mtow'] !== null) {
            $mtows[(string)$f['aircraft_mtow']] = true;
        }
    }
    $distinctMtows = array_keys($mtows);

    // Тип ВС — берём самый частый (на случай опечаток в типе тоже), при
    // равенстве — последний по дате.
    $typeCounts = [];
    foreach ($flights as $f) {
        if (!empty($f['aircraft_type'])) {
            $typeCounts[$f['aircraft_type']] = ($typeCounts[$f['aircraft_type']] ?? 0) + 1;
        }
    }
    arsort($typeCounts);
    $aircraftType = array_key_first($typeCounts);

    if (count($distinctMtows) === 1) {
        $aircraftModel->createIfMissing($reg, $aircraftType, (float)$distinctMtows[0]);
        $resolved++;
        echo "OK   {$reg}: MTOW {$distinctMtows[0]} ({$aircraftType}) — подтверждено, " . count($flights) . " рейс(ов)\n";
    } elseif (count($distinctMtows) === 0) {
        // Такого на момент раунда 117 нет (у всех рейсов MTOW обязателен),
        // но на всякий случай — не заводим вообще, пусть будет как новый борт.
        echo "SKIP {$reg}: ни одного MTOW в истории — не заводим, будет как новый борт при следующем рейсе\n";
    } else {
        $details = [];
        foreach ($flights as $f) {
            if ($f['aircraft_mtow'] === null) continue;
            $details[] = $f['aircraft_mtow'] . ' (' . $f['flight_date'] . ')';
        }
        $notes = 'Раунд 117: расхождение MTOW в истории рейсов — ' . implode(', ', $details)
            . '. Ждёт решения начальника ЦОК, какое значение верное (см. /aircraft-status).';
        $aircraftModel->upsertUnresolved($reg, $aircraftType, $notes);
        $unresolved++;
        echo "WAIT {$reg}: расхождение (" . implode(' / ', $distinctMtows) . ") — заведён как СПОРНЫЙ, mtow=NULL\n";
    }
}

echo "\nИтого: подтверждено {$resolved}, спорных (ждут решения) {$unresolved}, всего бортов " . count($byReg) . "\n";
