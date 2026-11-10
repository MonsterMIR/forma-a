<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
$envFile = BASE_PATH . '/.env';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    $_ENV[$k] = $v; putenv("$k=$v");
}
require BASE_PATH . '/vendor/autoload.php';

$m = new App\Models\Aircraft();
$rows = $m->getAllWithFleetStatus();
$onGround = array_filter($rows, fn($r) => !empty($r['on_ground']));
echo "Всего бортов: " . count($rows) . ", на земле (новая логика): " . count($onGround) . "\n\n";
foreach ($onGround as $r) {
    echo $r['registration'] . ' - рейс #' . $r['flight_id'] . ' ' . $r['flight_number'] . ' ' . $r['operation_type'] . ' (' . $r['flight_date'] . ')' . "\n";
}
