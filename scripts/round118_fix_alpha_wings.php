<?php
declare(strict_types=1);
// РАУНД 118 (23.10.2026) — retroactive fix: flights #136/#137 (MDADA,
// 23.10.2026, разр. CAA UZB 280/10/2026) были созданы в момент, когда
// findOrCreateAirline() падал на INSERT из-за невалидного ICAO ("IATA",
// 4 буквы — баг парсера, см. round118). В результате обоим рейсам
// назначилась служебная авиакомпания-заглушка "Unknown Operator" (id 116)
// вместо реального оператора "ALPHA WINGS, LLC" (см. permit_raw_logs id=24).
// Парсер и findOrCreateAirline() уже исправлены — здесь только чиним
// задним числом эти 2 уже созданных рейса.

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
use App\Core\Request;
use App\Core\Response;
use App\Controllers\FlightController;

$db = Database::getInstance();

// Проверяем текущее состояние перед правкой
$before = $db->query("SELECT f.id, f.flight_number, f.flight_date, a.name FROM flights f JOIN airlines a ON a.id=f.airline_id WHERE f.id IN (136,137)")->fetchAll(PDO::FETCH_ASSOC);
echo "ДО:\n" . json_encode($before, JSON_UNESCAPED_UNICODE) . "\n";

$controller = new FlightController(new Request(), new Response());
$ref = new ReflectionMethod($controller, 'findOrCreateAirline');
$ref->setAccessible(true);
$airlineId = $ref->invoke($controller, 'ALPHA WINGS, LLC', null);

if (!$airlineId) {
    echo "ОШИБКА: не удалось создать/найти авиакомпанию ALPHA WINGS, LLC\n";
    exit(1);
}
echo "Авиакомпания ALPHA WINGS, LLC -> id {$airlineId}\n";

$upd = $db->prepare("UPDATE flights SET airline_id = ? WHERE id IN (136, 137)");
$upd->execute([$airlineId]);
echo "Обновлено рейсов: " . $upd->rowCount() . "\n";

$after = $db->query("SELECT f.id, f.flight_number, f.flight_date, a.name, a.icao_code FROM flights f JOIN airlines a ON a.id=f.airline_id WHERE f.id IN (136,137)")->fetchAll(PDO::FETCH_ASSOC);
echo "ПОСЛЕ:\n" . json_encode($after, JSON_UNESCAPED_UNICODE) . "\n";
