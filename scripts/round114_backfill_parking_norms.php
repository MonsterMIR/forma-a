<?php
declare(strict_types=1);

/**
 * РАУНД 114 (22.10.2026) — бэкфилл недостающих норм бесплатной стоянки.
 *
 * Контекст: найдено 17 авиакомпаний без единой записи в
 * airline_parking_norms (см. claude/analysis round113/114) — из-за этого
 * сверхнормативная стоянка (OVER_PARK) тихо не считалась на их рейсах
 * (пример: DXT9686/AIR MANAGEMENT GROUP). По прямому указанию пользователя:
 * "при создании авиакомпании автоматом проставлялся норма бесплатной
 * стоянки 2 часа 30 минут. и исправь эти не достающиеся нормы."
 *
 * Значение 2.5ч (пассажирская) / 3.5ч (грузовая) — не произвольное: это
 * фактический единый стандарт ВО ВСЕХ 129 существующих записях
 * airline_parking_norms на момент раунда 114 (SELECT DISTINCT
 * free_hours_pax, free_hours_cargo — одна единственная комбинация).
 *
 * Идемпотентно — AirlineParkingNorm::upsert() ничего не трогает, если
 * норма у авиакомпании уже есть.
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/round114_backfill_parking_norms.php
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
use App\Models\AirlineParkingNorm;

$db = Database::getInstance();
$normModel = new AirlineParkingNorm();

$stmt = $db->query(
    "SELECT al.id, al.name FROM airlines al
     LEFT JOIN airline_parking_norms n ON n.airline_id = al.id
     WHERE n.id IS NULL
     ORDER BY al.id"
);
$missing = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($missing)) {
    echo "Авиакомпаний без нормы стоянки не найдено — нечего заполнять.\n";
    exit(0);
}

echo "Авиакомпаний без нормы: " . count($missing) . "\n\n";

foreach ($missing as $al) {
    $normModel->upsert(
        (int)$al['id'], 2.5, 3.5,
        'Раунд 114: бэкфилл недостающей нормы (дефолт 2ч30м/3ч30м) — найдено при разборе DXT9686',
        1 // System Administrator — тот же приём, что и в round110/111
    );
    echo "  #{$al['id']} {$al['name']} — норма 2.50ч/3.50ч проставлена.\n";
}

echo "\nГотово. Проверка после заполнения:\n";
$stmt = $db->query(
    "SELECT COUNT(*) FROM airlines al
     LEFT JOIN airline_parking_norms n ON n.airline_id = al.id
     WHERE n.id IS NULL"
);
$stillMissing = (int)$stmt->fetchColumn();
echo "Осталось без нормы: {$stillMissing} (должно быть 0)\n";
