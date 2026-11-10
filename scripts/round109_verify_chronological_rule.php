<?php
declare(strict_types=1);

/**
 * РАУНД 109 (21.10.2026) — read-only проверка нового
 * Flight::findChronologicalLinkCandidates() на живых данных после
 * деплоя. Ничего не пишет в БД.
 *
 * Для каждого непривязанного рейса с указанным бортом показывает, что
 * вернул бы новый метод (т.е. что сделал бы autoLinkFlight(), если бы
 * этот рейс сейчас пересохранили) — чтобы увидеть эффект до того, как
 * это начнёт срабатывать само по себе при реальных сохранениях ЦОК.
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/round109_verify_chronological_rule.php
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
use App\Models\Flight;

$db = Database::getInstance();
$flightModel = new Flight();

$stmt = $db->query(
    "SELECT id, flight_number, flight_date, scheduled_time, operation_type, aircraft_registration
     FROM flights
     WHERE linked_flight_id IS NULL
       AND aircraft_registration IS NOT NULL AND aircraft_registration <> ''
     ORDER BY aircraft_registration, flight_date, scheduled_time"
);
$unlinked = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Непривязанных рейсов с указанным бортом: " . count($unlinked) . "\n\n";

$wouldLink = 0;
$wouldAmbiguous = 0;
$wouldNone = 0;

foreach ($unlinked as $f) {
    $candidates = $flightModel->findChronologicalLinkCandidates(
        $f['aircraft_registration'],
        $f['operation_type'],
        $f['flight_date'],
        $f['scheduled_time'],
        (int)$f['id']
    );

    $dir = $f['operation_type'] === 'arrival' ? '↓ прилёт' : '↑ вылет ';
    $label = "#{$f['id']} {$dir} {$f['flight_number']} ({$f['aircraft_registration']}, {$f['flight_date']} {$f['scheduled_time']})";

    if (empty($candidates)) {
        echo "  {$label} -> НЕТ кандидата (останется без пары, как и сейчас)\n";
        $wouldNone++;
        continue;
    }

    $best = $candidates[0];
    $unique = count($candidates) === 1 || (int)$candidates[1]['minute_diff'] !== (int)$best['minute_diff'];

    if ($unique) {
        echo "  {$label} -> связался бы с #{$best['id']} {$best['flight_number']} ({$best['flight_date']} {$best['scheduled_time']}, "
            . "разница " . round($best['minute_diff']/60, 1) . "ч)\n";
        $wouldLink++;
    } else {
        echo "  {$label} -> НЕОДНОЗНАЧНО (" . count($candidates) . " кандидата(ов) на одинаковом расстоянии)\n";
        $wouldAmbiguous++;
    }
}

echo "\n--- Итог ---\n";
echo "Нашёлся бы однозначный кандидат: {$wouldLink}\n";
echo "Неоднозначно: {$wouldAmbiguous}\n";
echo "Кандидата нет: {$wouldNone}\n";
