<?php
declare(strict_types=1);
// РАУНД 122 (24.10.2026) — проверка плашки "Манифест подан" у супервайзера:
// PassengerManifest::getByFlightAndType() теперь возвращает created_by_name
// (новый JOIN), и сценарий "манифест ещё draft" (транзакция + rollback —
// реальных draft-манифестов типа final в проде сейчас нет, создаём
// синтетический и откатываем). Read-only для реальных рейсов 133/125.

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
use App\Models\PassengerManifest;

$pm = new PassengerManifest();

// Тест 1: рейс 133, submitted, pax_crew=5, pax_transit=8, pax_total=0+0+0=0
$m133 = $pm->getByFlightAndType(133, 'final');
echo "Рейс 133: " . ($m133 ? "найден, status={$m133['status']}, pax_total={$m133['pax_total']}, crew={$m133['pax_crew']}, transit={$m133['pax_transit']}, created_by_name=" . ($m133['created_by_name'] ?? 'NULL') : "НЕ найден") . "\n";
echo ($m133 && array_key_exists('created_by_name', $m133)) ? "created_by_name присутствует в ответе — OK\n" : "created_by_name ОТСУТСТВУЕТ — ПРОВАЛ\n";

// Тест 2: рейс без какого-либо манифеста
$m125 = $pm->getByFlightAndType(125, 'final');
echo "\nРейс 125 (без манифеста): " . ($m125 === null ? "null — OK\n" : "ПРОВАЛ, вернулось: " . json_encode($m125) . "\n");

// Тест 3: синтетический draft-манифест (транзакция, откат)
$db = Database::getInstance();
$db->beginTransaction();
$fail = false;
try {
    $flightRow = $db->query("SELECT id FROM flights ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $testFlightId = (int)$flightRow['id'];
    $db->prepare("DELETE FROM passenger_manifests WHERE flight_id = ? AND manifest_type='final'")->execute([$testFlightId]);
    $db->prepare(
        "INSERT INTO passenger_manifests (flight_id, manifest_type, status, pax_adults, pax_children, pax_infants, pax_crew, pax_transit, baggage_pieces, baggage_weight_kg, created_by_user_id, created_at)
         VALUES (?, 'final', 'draft', 3, 1, 0, 4, 0, 5, 120.50, 1, NOW())"
    )->execute([$testFlightId]);

    $mDraft = $pm->getByFlightAndType($testFlightId, 'final');
    $isDraft = $mDraft && $mDraft['status'] === 'draft';
    echo "\nТест 3 (синтетический draft): " . ($isDraft ? "status=draft получен корректно — OK\n" : "ПРОВАЛ\n");
    if (!$isDraft) $fail = true;
} catch (\Throwable $e) {
    echo "ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n";
    $fail = true;
}
$db->rollBack();
echo "\n--- Транзакция откатана. ---\n";
echo $fail ? "\nИТОГ: ЕСТЬ ПРОВАЛЫ\n" : "\nИТОГ: ВСЕ ТЕСТЫ OK\n";
