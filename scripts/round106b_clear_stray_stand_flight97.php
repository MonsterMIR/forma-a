<?php
declare(strict_types=1);

/**
 * РАУНД 106, часть Б (20.10.2026) — чистка побочного эффекта ошибочной
 * связки #84/#97 (см. round106_fix_act77_p4vvip.php и round106 .md).
 *
 * При ошибочной автосвязке рейса #97 (прилёт UZSS->TVT, 20.10.2026 20:00,
 * ещё не состоялся) с чужим рейсом #84 (вылет TVT->UCFM, 18.10.2026)
 * syncLinkedAircraftData() скопировала стоянку B-24R с #84 на #97
 * (activity-лог id 3425, new_values: {"mc_number":"B-24R",...}) — ЦОК
 * реально эту стоянку для #97 не назначал, её перенесло автоматически
 * только из-за неверной связки. Рейсы уже расцеплены (round106), но само
 * скопированное значение mc_number осталось на #97 — из-за этого табло
 * стоянок (/parking-stands) прямо сейчас может ошибочно показывать B-24R
 * занятой рейсом #97, хотя тот ещё не прилетел и стоянка ему реально не
 * назначена.
 *
 * Скрипт очищает flights.mc_number у #97 (возвращает в NULL — "ещё не
 * назначено"), чтобы реальную стоянку ЦОК/СОП назначили сами, когда борт
 * действительно сядет сегодня вечером.
 *
 * Безопасно запускать повторно.
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
use App\Models\Flight;

const ARRIVAL_FLIGHT_ID = 97;

$flightModel = new Flight();
$flight = $flightModel->find(ARRIVAL_FLIGHT_ID);
if (!$flight) {
    fwrite(STDERR, "Рейс #97 не найден — прерываю.\n");
    exit(1);
}

$current = $flight['mc_number'] ?? null;
echo "До исправления: #97.mc_number = " . ($current ?: '—') . "\n";

if ($current !== 'B-24R') {
    echo "Значение уже не 'B-24R' (кто-то успел изменить вручную) — ничего не трогаю, на всякий случай.\n";
    exit(0);
}

$flightModel->update(ARRIVAL_FLIGHT_ID, ['mc_number' => null]);
ActivityLogger::log(
    'flight', ARRIVAL_FLIGHT_ID, 'manual_clear_stray_stand', 'success',
    'Раунд 106б: очищена стоянка B-24R на рейсе #97 — была скопирована автоматически из-за ошибочной связки '
        . 'с рейсом #84 (см. round106), реально ЦОК её не назначал; борт ещё не прилетел',
    ['mc_number' => 'B-24R'],
    ['mc_number' => null]
);

echo "Готово: #97.mc_number очищена. Реальную стоянку нужно будет назначить, когда борт прилетит сегодня вечером.\n";
