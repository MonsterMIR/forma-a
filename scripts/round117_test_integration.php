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
use App\Models\Aircraft;
use App\Services\FlightService;

$db = Database::getInstance();
$db->beginTransaction();

$fail = false;

try {
    $flightService = new FlightService();
    $aircraftModel = new Aircraft();

    $before = $aircraftModel->findByRegistration('P4VVIP');
    echo "P4VVIP в справочнике ДО: mtow=" . var_export($before['mtow'] ?? null, true) . "\n";

    $result1 = $flightService->createFlight([
        'airline_id'            => 155,
        'flight_number'         => 'P4VVIP',
        'flight_date'           => '2026-11-01',
        'operation_type'        => 'arrival',
        'origin_airport_id'     => 208,
        'destination_airport_id'=> 148,
        'aircraft_type'         => 'CL60',
        'aircraft_mtow'         => 99.99,
        'aircraft_registration' => 'P4VVIP',
        'scheduled_time'        => '10:00:00',
        'notes'                 => 'ТЕСТ раунд117 — будет откатан',
    ]);

    if (!$result1['success']) {
        echo "ТЕСТ 1: ОШИБКА создания рейса: {$result1['error']}\n";
        $fail = true;
    } else {
        $flightModel = new \App\Models\Flight();
        $created = $flightModel->find((int)$result1['id']);
        $actualMtow = (float)$created['aircraft_mtow'];
        if (abs($actualMtow - 22.00) < 0.001) {
            echo "ТЕСТ 1 OK: рейс #{$result1['id']} создан с MTOW {$actualMtow} (переопределено из справочника, введённое 99.99 проигнорировано)\n";
        } else {
            echo "ТЕСТ 1 ПРОВАЛ: ожидали MTOW 22.00 из справочника, получили {$actualMtow}\n";
            $fail = true;
        }
    }

    $testReg = 'TEST117X';
    $existingBefore = $aircraftModel->findByRegistration($testReg);
    echo "\n{$testReg} в справочнике ДО: " . ($existingBefore ? 'уже есть (!)' : 'нет (как и ожидалось)') . "\n";

    $result2 = $flightService->createFlight([
        'airline_id'            => 155,
        'flight_number'         => 'TEST117X',
        'flight_date'           => '2026-11-01',
        'operation_type'        => 'arrival',
        'origin_airport_id'     => 208,
        'destination_airport_id'=> 148,
        'aircraft_type'         => 'TEST',
        'aircraft_mtow'         => 12.34,
        'aircraft_registration' => $testReg,
        'scheduled_time'        => '11:00:00',
        'notes'                 => 'ТЕСТ раунд117 — будет откатан',
    ]);

    if (!$result2['success']) {
        echo "ТЕСТ 2: ОШИБКА создания рейса: {$result2['error']}\n";
        $fail = true;
    } else {
        $flightModel = new \App\Models\Flight();
        $created2 = $flightModel->find((int)$result2['id']);
        $actualMtow2 = (float)$created2['aircraft_mtow'];
        $afterReg = $aircraftModel->findByRegistration($testReg);
        $okMtow = abs($actualMtow2 - 12.34) < 0.001;
        $okSeeded = $afterReg && abs((float)$afterReg['mtow'] - 12.34) < 0.001;
        if ($okMtow && $okSeeded) {
            echo "ТЕСТ 2 OK: рейс #{$result2['id']} создан с MTOW {$actualMtow2}, новый борт {$testReg} заведён в справочник как подтверждённый с MTOW " . $afterReg['mtow'] . "\n";
        } else {
            echo "ТЕСТ 2 ПРОВАЛ: MTOW рейса=" . var_export($actualMtow2 ?? null, true) . " (ok=$okMtow), запись в справочнике=" . json_encode($afterReg, JSON_UNESCAPED_UNICODE) . " (ok=$okSeeded)\n";
            $fail = true;
        }
    }

    $disputedReg = 'A7CHF';
    $disputedBefore = $aircraftModel->findByRegistration($disputedReg);
    echo "\n{$disputedReg} в справочнике: mtow=" . var_export($disputedBefore['mtow'] ?? 'НЕТ ЗАПИСИ', true) . " (ожидаем NULL — спорный)\n";

    $result3 = $flightService->createFlight([
        'airline_id'            => 155,
        'flight_number'         => 'A7CHF',
        'flight_date'           => '2026-11-01',
        'operation_type'        => 'arrival',
        'origin_airport_id'     => 208,
        'destination_airport_id'=> 148,
        'aircraft_type'         => 'TEST',
        'aircraft_mtow'         => 48.90,
        'aircraft_registration' => $disputedReg,
        'scheduled_time'        => '12:00:00',
        'notes'                 => 'ТЕСТ раунд117 — будет откатан',
    ]);

    if (!$result3['success']) {
        echo "ТЕСТ 3: ОШИБКА создания рейса: {$result3['error']}\n";
        $fail = true;
    } else {
        $flightModel = new \App\Models\Flight();
        $created3 = $flightModel->find((int)$result3['id']);
        $actualMtow3 = (float)$created3['aircraft_mtow'];
        if (abs($actualMtow3 - 48.90) < 0.001) {
            echo "ТЕСТ 3 OK: рейс #{$result3['id']} создан с MTOW {$actualMtow3} (введённое значение сохранено, т.к. борт спорный — справочник не вмешивался)\n";
        } else {
            echo "ТЕСТ 3 ПРОВАЛ: ожидали сохранённое введённое значение 48.90, получили {$actualMtow3}\n";
            $fail = true;
        }
    }

} catch (\Throwable $e) {
    echo "ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $fail = true;
}

$db->rollBack();
echo "\n--- Транзакция откатана, никаких тестовых данных не осталось в БД. ---\n";
echo $fail ? "\nИТОГ: ЕСТЬ ПРОВАЛЫ\n" : "\nИТОГ: ВСЕ ТЕСТЫ OK\n";
