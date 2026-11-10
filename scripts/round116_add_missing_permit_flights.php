<?php
declare(strict_types=1);
// РАУНД 116 (22.10.2026) — ввод рейсов из вновь полученных разрешений,
// закрывающих часть дыр хронологической связки (round115).
// Использует FlightService::createFlight() — тот же путь, что и обычная
// форма ЦОК (автосвязка, перенос MTOW/стоянки, авто-заявка LEAD/CHOCKS,
// журнал активности), просто без HTTP/CSRF/сессии.
//
// Порядок вставки ВАЖЕН для корректной жадной автосвязки
// (FlightService::autoLinkFlight() берёт ближайшего по времени
// НЕсвязанного кандидата) — см. анализ в раунде 116.

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

use App\Services\FlightService;

$flightService = new FlightService();

function makeFlight(FlightService $svc, array $data): int {
    $result = $svc->createFlight($data);
    if (!$result['success']) {
        echo "ОШИБКА при создании {$data['flight_number']} {$data['operation_type']} {$data['flight_date']} {$data['scheduled_time']}: "
            . $result['error'] . "\n";
        exit(1);
    }
    $id = (int)$result['id'];
    echo "Создан рейс #{$id}: {$data['flight_number']} {$data['operation_type']} {$data['flight_date']} {$data['scheduled_time']}\n";
    return $id;
}

// ── P4VVIP: прилёт 18.10.2026 22:00 из UCFM (Бишкек), разр. 243/10/2026 TVT ──
$idA = makeFlight($flightService, [
    'airline_id'            => 155, // GLOBAL JET ARUBA V.B.A.
    'flight_number'         => 'P4VVIP',
    'flight_date'           => '2026-10-18',
    'operation_type'        => 'arrival',
    'origin_airport_id'     => 208, // UCFM (Бишкек)
    'destination_airport_id'=> 148, // TVT
    'aircraft_type'         => 'CL60',
    'aircraft_mtow'         => 22.00,
    'aircraft_registration' => 'P4VVIP',
    'scheduled_time'        => '22:00:00',
    'notes'                 => 'Разр.: CAA UZB 243/10/2026 TVT | Цель: BUSINESS'
        . ' [Раунд 116, 22.10.2026] Возврат из Бишкека тем же днём — закрывает дыру связки между рейсами #84 и #98 (см. round115).',
]);

// ── T7AAA7: 19.10.2026 — прилёт из Бухары 14:00, вылет в Самарканд 15:00,
//    прилёт из Самарканда 22:00. Разр. 263/10/2026 BHK/TVT/SKD.
//    Порядок вставки: D (22:00) → C (15:00) → B (14:00), см. docblock выше. ──

$noteD = 'Разр.: CAA UZB 263/10/2026 BHK/TVT/SKD | Цель: BUSINESS'
    . ' [Раунд 116, 22.10.2026] Возврат из Самарканда — закрывает дыру связки между #94 и #107 (см. round115).';
$idD = makeFlight($flightService, [
    'airline_id'            => 136, // GLOBALJET ISLE OF MAN
    'flight_number'         => 'T7AAA7',
    'flight_date'           => '2026-10-19',
    'operation_type'        => 'arrival',
    'origin_airport_id'     => 169, // UZSS Самарканд
    'destination_airport_id'=> 148, // TVT
    'aircraft_type'         => 'GL7T',
    'aircraft_mtow'         => 52.10,
    'aircraft_registration' => 'T7AAA7',
    'scheduled_time'        => '22:00:00',
    'notes'                 => $noteD,
]);

$noteCD = 'Разр.: CAA UZB 263/10/2026 BHK/TVT/SKD | Цель: BUSINESS'
    . ' [Раунд 116, 22.10.2026] Короткий вылет в Самарканд и обратно в тот же день (см. round115).';
$idC = makeFlight($flightService, [
    'airline_id'            => 136,
    'flight_number'         => 'T7AAA7',
    'flight_date'           => '2026-10-19',
    'operation_type'        => 'departure',
    'origin_airport_id'     => 148, // TVT
    'destination_airport_id'=> 169, // UZSS Самарканд
    'aircraft_type'         => 'GL7T',
    'aircraft_mtow'         => 52.10,
    'aircraft_registration' => 'T7AAA7',
    'scheduled_time'        => '15:00:00',
    'notes'                 => $noteCD,
]);

$noteB = 'Разр.: CAA UZB 263/10/2026 BHK/TVT/SKD | Цель: BUSINESS'
    . ' [Раунд 116, 22.10.2026] Возврат из Бухары, следующий вылет — см. рейс #' . $idC . ' (см. round115).';
$idB = makeFlight($flightService, [
    'airline_id'            => 136,
    'flight_number'         => 'T7AAA7',
    'flight_date'           => '2026-10-19',
    'operation_type'        => 'arrival',
    'origin_airport_id'     => 185, // UZSB Бухара
    'destination_airport_id'=> 148, // TVT
    'aircraft_type'         => 'GL7T',
    'aircraft_mtow'         => 52.10,
    'aircraft_registration' => 'T7AAA7',
    'scheduled_time'        => '14:00:00',
    'notes'                 => $noteB,
]);

echo "\nНовые ID: A(P4VVIP прилёт 18.10 22:00)=$idA, D(T7AAA7 прилёт 19.10 22:00)=$idD, "
    . "C(T7AAA7 вылет 19.10 15:00)=$idC, B(T7AAA7 прилёт 19.10 14:00)=$idB\n";
