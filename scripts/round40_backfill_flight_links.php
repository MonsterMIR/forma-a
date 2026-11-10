<?php
declare(strict_types=1);

/**
 * РАУНД 40 (14.09.2026) — одноразовый разовый прогон автоматической
 * привязки рейс-прилёта/рейса-вылета для УЖЕ СУЩЕСТВУЮЩИХ в базе рейсов.
 *
 * Зачем нужен: FlightService::autoLinkFlight() (новая логика раунда 40)
 * запускается только при СОЗДАНИИ или РЕДАКТИРОВАНИИ рейса — рейсы, которые
 * уже лежат в базе с этого патча и никто их больше не трогает (например
 * flights/10+flights/11, flights/20+flights/21 на момент разбора — оба
 * рейса каждой пары так и остались не связаны, потому что были созданы ДО
 * раунда 40 и с тех пор не редактировались), сами по себе не свяжутся.
 * Этот скрипт проходит по всем ещё НЕ связанным рейсам и для каждого
 * пытается подобрать пару — та же логика (Flight::findLinkCandidates() +
 * FlightService::autoLinkFlight()), что и при обычном сохранении рейса.
 *
 * Безопасно запускать повторно (идемпотентно) — уже связанные рейсы просто
 * пропускаются.
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/round40_backfill_flight_links.php
 *
 * Выведет список того, что связал, и отдельно — рейсы, которые не удалось
 * связать автоматически (нет пары / неоднозначность несколько кандидатов),
 * с указанием причины.
 */

define('BASE_PATH', dirname(__DIR__));

// Тот же способ загрузки .env, что и в public/index.php — скрипт запускается
// из CLI, без веб-бутстрапа, поэтому подключаем конфигурацию окружения сам.
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
use App\Services\FlightService;

$db          = Database::getInstance();
$flightModel = new Flight();
$service     = new FlightService();

$stmt = $db->query(
    "SELECT id, flight_number, flight_date, operation_type, aircraft_registration
     FROM flights
     WHERE linked_flight_id IS NULL
     ORDER BY flight_date ASC, id ASC"
);
$unlinked = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Найдено ещё не связанных рейсов: " . count($unlinked) . "\n\n";

$linked    = 0;
$skippedNoReg = 0;
$skippedNoCandidate = 0;
$skippedAmbiguous = 0;

foreach ($unlinked as $f) {
    // Мог быть связан на предыдущей итерации этого же прогона (как "другая
    // сторона" уже обработанной пары) — перепроверяем текущее состояние.
    $fresh = $flightModel->find((int)$f['id']);
    if (!$fresh || !empty($fresh['linked_flight_id'])) {
        continue;
    }

    $result = $service->autoLinkFlight((int)$f['id']);
    $label  = "#{$f['id']} {$f['flight_number']} ({$f['flight_date']}, {$f['operation_type']}, борт "
        . ($f['aircraft_registration'] ?: '—') . ')';

    if ($result['linked'] ?? false) {
        if (($result['reason'] ?? '') === 'auto') {
            $linked++;
            echo "СВЯЗАН: {$label} <-> #{$result['linked_flight_id']}\n";
        }
        // reason === 'already_linked' сюда попасть не должно (мы уже
        // отфильтровали linked_flight_id IS NULL выше), но на всякий случай
        // просто пропускаем без вывода.
        continue;
    }

    switch ($result['reason'] ?? '') {
        case 'no_registration':
            $skippedNoReg++;
            echo "ПРОПУЩЕН (нет рег. номера ВС): {$label}\n";
            break;
        case 'ambiguous':
            $skippedAmbiguous++;
            $candList = implode(', ', array_map(
                static fn($c) => "#{$c['id']} {$c['flight_number']} ({$c['flight_date']})",
                $result['candidates'] ?? []
            ));
            echo "ПРОПУЩЕН (несколько кандидатов, требует ручной проверки): {$label} — кандидаты: {$candList}\n";
            break;
        case 'no_candidate':
            $skippedNoCandidate++;
            // Обычное дело — пара ещё не создана или борт разовый. Не шумим
            // подробным выводом по умолчанию, считаем только в сводке.
            break;
        default:
            echo "ПРОПУЩЕН ({$result['reason']}): {$label}\n";
    }
}

echo "\n--- Итог ---\n";
echo "Связано автоматически: {$linked}\n";
echo "Без рег. номера ВС: {$skippedNoReg}\n";
echo "Неоднозначно (несколько кандидатов): {$skippedAmbiguous}\n";
echo "Нет пары в системе (ожидаемо для многих): {$skippedNoCandidate}\n";
