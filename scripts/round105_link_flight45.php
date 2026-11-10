<?php
declare(strict_types=1);

/**
 * РАУНД 105 (20.10.2026) — одноразовое ручное исправление рейса #45.
 *
 * Контекст (по прямому указанию пользователя): "Рейс № 45 (T7AAA7) без
 * бортового номера — известно с 12.10 (раунд 70), через 8 дней всё ещё не
 * исправлено — требуется ручная впись силами ЦОК. пара этого рейса 44
 * рейс. свяжи если сможешь."
 *
 * Разбор: рейс #45 (прилёт, 09.10.2026, flight_number T7AAA7, тип GL7T,
 * MTOW 52.10) не имеет aircraft_registration — поэтому
 * FlightService::autoLinkFlight() никогда не мог его связать (нужен
 * непустой бортовой номер для поиска кандидатов, см. её докблок).
 * Рейс #44 (вылет, та же дата 09.10.2026, тот же flight_number T7AAA7,
 * тот же тип GL7T, MTOW 52.00, борт T7AAA7) — его пара: один и тот же
 * локальный борт, прилёт и вылет в один день, стоянки разные (B-14/B-12,
 * перестановка на перроне в рамках одного визита — не противоречие).
 *
 * Почему это нельзя было найти автоматически даже скриптом round40
 * (scripts/round40_backfill_flight_links.php): помимо отсутствия
 * регистрации на #45, у этого борта оказалось ДВА несвязанных рейса на
 * вылет с регистрацией T7AAA7 (id 44 от 09.10 и id 25 от 13.10) — обычный
 * автоподбор (findLinkCandidates) увидел бы два кандидата и отказался бы
 * выбирать (reason=ambiguous, см. докблок autoLinkFlight()). Дата у #44 и
 * #45 совпадает один-в-один, поэтому пара однозначна для человека — и
 * выбирается здесь явно, через ручной FlightService::linkFlights(),
 * тот же метод, что стоит за восстановленной в раунде 69 кнопкой "Связать"
 * на странице рейса.
 *
 * Оба акта (0031 на #44, 0032 на #45) уже approved — поэтому
 * syncLinkedAircraftData() внутри linkFlights() не станет перезаписывать
 * MTOW/стоянку автоматически (целевая сторона заблокирована), просто
 * зафиксирует расхождение в activity-логе — это ожидаемое, безопасное
 * поведение (см. докблок syncLinkedAircraftData(), раунд 104).
 *
 * Безопасно запускать повторно — если #45 уже связан с #44, скрипт
 * сообщит об этом и ничего не изменит.
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/round105_link_flight45.php
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
use App\Services\FlightService;

const ARRIVAL_FLIGHT_ID   = 45;
const DEPARTURE_FLIGHT_ID = 44;

$flightModel = new Flight();
$service     = new FlightService();

$arrival   = $flightModel->find(ARRIVAL_FLIGHT_ID);
$departure = $flightModel->find(DEPARTURE_FLIGHT_ID);

if (!$arrival || !$departure) {
    fwrite(STDERR, "Один из рейсов (#45 прилёт / #44 вылет) не найден — прерываю.\n");
    exit(1);
}

echo "До исправления:\n";
echo "  #44 (вылет):  flight_number={$departure['flight_number']} date={$departure['flight_date']} reg="
    . ($departure['aircraft_registration'] ?: '—') . " linked_flight_id=" . ($departure['linked_flight_id'] ?: '—') . "\n";
echo "  #45 (прилёт): flight_number={$arrival['flight_number']} date={$arrival['flight_date']} reg="
    . ($arrival['aircraft_registration'] ?: '—') . " linked_flight_id=" . ($arrival['linked_flight_id'] ?: '—') . "\n\n";

if (!empty($arrival['linked_flight_id'])) {
    echo "Рейс #45 уже связан (linked_flight_id={$arrival['linked_flight_id']}) — ничего не делаю.\n";
    exit(0);
}

$depReg = strtoupper(trim((string)($departure['aircraft_registration'] ?? '')));
if ($depReg === '') {
    fwrite(STDERR, "У рейса #44 самого нет бортового номера — восстанавливать #45 не от чего, прерываю.\n");
    exit(1);
}

if ((string)$arrival['operation_type'] !== 'arrival' || (string)$departure['operation_type'] !== 'departure') {
    fwrite(STDERR, "Ожидалось #45=прилёт, #44=вылет — фактическая конфигурация другая, прерываю на всякий случай.\n");
    exit(1);
}

if ((string)$arrival['flight_date'] !== (string)$departure['flight_date']) {
    fwrite(STDERR, "Даты рейсов #44 и #45 не совпадают — похоже, это не та пара, прерываю.\n");
    exit(1);
}

// Шаг 1: восстанавливаем бортовой номер на #45 с его пары (#44) — то же
// самое значение, которое ЦОК вписал бы вручную в форму редактирования.
$flightModel->update(ARRIVAL_FLIGHT_ID, ['aircraft_registration' => $depReg]);
ActivityLogger::log(
    'flight', ARRIVAL_FLIGHT_ID, 'manual_fix_registration', 'success',
    "Раунд 105: восстановлен бортовой номер {$depReg} на рейсе #45 (прилёт) с его пары — рейса #44 (вылет), "
        . "по прямому указанию пользователя (известный пробел с раунда 70, 8 дней без ручной правки ЦОК)",
    ['aircraft_registration' => null],
    ['aircraft_registration' => $depReg]
);
echo "Шаг 1: бортовой номер {$depReg} записан на рейс #45.\n";

// Шаг 2: явная связка (не автоподбор — у борта T7AAA7 есть ещё один
// несвязанный вылет, #25 от 13.10, который сделал бы автоподбор
// неоднозначным; дата #44 совпадает с #45 один-в-один, поэтому пара
// выбирается здесь явно и осознанно).
$result = $service->linkFlights(ARRIVAL_FLIGHT_ID, DEPARTURE_FLIGHT_ID);
if (!$result['success']) {
    fwrite(STDERR, "Шаг 2: связка не удалась — {$result['error']}\n");
    exit(1);
}
echo "Шаг 2: рейс #45 связан с рейсом #44.\n";

$arrivalAfter   = $flightModel->find(ARRIVAL_FLIGHT_ID);
$departureAfter = $flightModel->find(DEPARTURE_FLIGHT_ID);
echo "\nПосле исправления:\n";
echo "  #44 (вылет):  reg=" . ($departureAfter['aircraft_registration'] ?: '—')
    . " linked_flight_id=" . ($departureAfter['linked_flight_id'] ?: '—') . "\n";
echo "  #45 (прилёт): reg=" . ($arrivalAfter['aircraft_registration'] ?: '—')
    . " linked_flight_id=" . ($arrivalAfter['linked_flight_id'] ?: '—') . "\n";
echo "\nГотово. MTOW (52.00 на вылете / 52.10 на прилёте) и стоянки (B-12/B-14) оставлены как есть — "
    . "оба акта уже approved, автоматическая синхронизация их не трогает (см. activity-лог, событие linked_data_conflict, если появится).\n";
