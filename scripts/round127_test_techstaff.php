<?php
declare(strict_types=1);
// РАУНД 127 — проверка AutoChargeService::syncTechStaffCount() на РЕАЛЬНОМ
// рейсе (#14, уже содержит PUSHBACK+TOILET), в транзакции с откатом —
// ничего не меняется в проде. Цель: понять, почему TECH_STAFF ни разу не
// появился в flight_service_charges ни по одному рейсу за всю историю.

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
use App\Services\AutoChargeService;

$db = Database::getInstance();
$db->beginTransaction();

try {
    $flightId = 14;

    $before = $db->prepare("SELECT COUNT(*) FROM flight_service_charges WHERE flight_id=? AND service_id=41");
    $before->execute([$flightId]);
    echo "TECH_STAFF записей ДО вызова: " . $before->fetchColumn() . "\n";

    $svc = new AutoChargeService();
    $svc->syncTechStaffCount($flightId);
    echo "syncTechStaffCount() выполнен без исключений.\n";

    $after = $db->prepare("SELECT quantity, note FROM flight_service_charges WHERE flight_id=? AND service_id=41");
    $after->execute([$flightId]);
    $row = $after->fetch(\PDO::FETCH_ASSOC);
    if ($row) {
        echo "TECH_STAFF ПОСЛЕ вызова: quantity={$row['quantity']}, note={$row['note']}\n";
    } else {
        echo "TECH_STAFF ПОСЛЕ вызова: ЗАПИСИ НЕТ (метод не вставил ничего).\n";
    }

} catch (\Throwable $e) {
    echo "ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

$db->rollBack();
echo "\n--- Транзакция откатана. ---\n";
