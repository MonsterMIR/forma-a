<?php
declare(strict_types=1);
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
use App\Services\ParkingStandService;

$db = Database::getInstance();
$svc = new ParkingStandService();

$board = $svc->getStatusBoard();
$occupied = array_filter($board, fn($r) => $r['occupied']);

echo "Занятых стоянок (прямо или через парную): " . count($occupied) . " из " . count($board) . "\n\n";

foreach ($occupied as $row) {
    $f = $row['flight'];
    $reg = $f['aircraft_registration'] ?? '';

    $linkedDep = null;
    if ($f['operation_type'] === 'arrival' && !empty($f['linked_flight_id'])) {
        $stmt = $db->prepare("SELECT id, flight_number, flight_date, scheduled_time, chocks_off FROM flights WHERE id=?");
        $stmt->execute([(int)$f['linked_flight_id']]);
        $linkedDep = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif ($f['operation_type'] === 'departure') {
        $linkedDep = $f;
    }

    $chocksInfo = 'нет рейса вылета (не связан / не введён)';
    if ($linkedDep) {
        $chStmt = $db->prepare(
            "SELECT status, completed_at FROM flight_service_orders fso
             JOIN services s ON s.id = fso.service_id
             WHERE fso.flight_id = ? AND s.code = 'CHOCKS' LIMIT 1"
        );
        $chStmt->execute([(int)$linkedDep['id']]);
        $row2 = $chStmt->fetch(PDO::FETCH_ASSOC);
        $chocksInfo = 'вылет #' . $linkedDep['id'] . ' ' . $linkedDep['flight_number']
            . ' (' . $linkedDep['flight_date'] . '), CHOCKS status=' . ($row2['status'] ?? 'нет заявки')
            . ', completed_at=' . ($row2['completed_at'] ?? 'NULL')
            . ', chocks_off(ручное)=' . ($linkedDep['chocks_off'] ?: 'NULL');
    }

    $lastStmt = $db->prepare(
        "SELECT id, flight_number, flight_date, scheduled_time, operation_type
         FROM flights WHERE aircraft_registration = ?
         ORDER BY flight_date DESC, COALESCE(scheduled_time,'00:00:00') DESC, id DESC LIMIT 1"
    );
    $lastStmt->execute([$reg]);
    $last = $lastStmt->fetch(PDO::FETCH_ASSOC);
    $lastInfo = $last
        ? "#{$last['id']} {$last['flight_number']} {$last['operation_type']} ({$last['flight_date']})"
        : 'нет рейсов';

    echo "Стоянка {$row['stand']['code']}"
        . (!empty($row['occupied_via']) ? " (через {$row['occupied_via']})" : '')
        . ": занята рейсом #{$f['id']} {$f['flight_number']} {$f['operation_type']} ({$f['flight_date']}), борт {$reg}\n";
    echo "    Проверка колодок: {$chocksInfo}\n";
    echo "    Последний по дате/времени рейс этого борта (= что видит /aircraft-status): {$lastInfo}\n";
    echo "\n";
}
