<?php
/**
 * scripts/round133_fix_approved_ramp_arrival_duplicates.php
 *
 * Round 131 нашёл: услуги RAMP_10..RAMP_251 (Рамп-хэндлинг по MTOW) из-за
 * устаревшего tariff_versions.operation_type='both' оказались заказываемыми
 * и на актах ПРИЛЁТА (где они не должны начисляться вовсе — Рамп-хэндлинг
 * положен один раз за ротацию, на вылет), и были реально начислены на 50
 * утверждённых (approved) актах прилёта за период 15.10–27.10.2026.
 *
 * Пользователь (round 132 продолжение, после выбора между кредит-нотами и
 * "оставить как есть"): "исправь все акты." — прямое указание исправить
 * сами утверждённые документы.
 *
 * Паттерн исправления — ТОЧНО тот же, что в round111 (задвоенный NIGHT fee
 * на 11 утверждённых актах): воспроизводим внутреннюю логику
 * ActService::deleteActItem() (удаление строки act_item_calculations +
 * строки act_items + пересчёт итогов акта), но БЕЗ гейта по статусу акта —
 * этот гейт существует для обычного UI-редактирования, не для
 * подтверждённого административного исправления системного бага.
 * Статус акта, подписи, approved_by_user_id/approved_at — НЕ трогаются.
 * На каждый акт добавляется пометка в notes + запись ActivityLogger.
 *
 * Режимы:
 *   php round133_fix_approved_ramp_arrival_duplicates.php --dry-run   (транзакция + rollBack)
 *   php round133_fix_approved_ramp_arrival_duplicates.php --commit    (реальное исполнение)
 *
 * Идемпотентен: выбирает только act_items, где services.code LIKE 'RAMP%'
 * И flights.operation_type='arrival' — после первого успешного запуска
 * повторный запуск найдёт 0 строк.
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
use App\Core\ActivityLogger;
use App\Models\Act;
use App\Models\ActItem;
use App\Models\ActItemCalculation;

$mode = $argv[1] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--commit'], true)) {
    fwrite(STDERR, "Usage: php {$argv[0]} --dry-run|--commit\n");
    exit(1);
}

$db = Database::getInstance();
$actModel  = new Act();
$itemModel = new ActItem();
$calcModel = new ActItemCalculation();

$db->beginTransaction();

try {
    $rows = $db->query("
        SELECT ai.id AS item_id, ai.act_id, ai.amount_without_vat, ai.vat_amount, ai.amount_with_vat,
               a.act_number, a.notes, a.total_amount_without_vat AS old_tw,
               a.total_vat_amount AS old_tv, a.total_amount_with_vat AS old_tt
        FROM act_items ai
        JOIN acts a ON a.id = ai.act_id
        JOIN flights f ON f.id = a.flight_id
        JOIN services s ON s.id = ai.service_id
        WHERE s.code LIKE 'RAMP%' AND f.operation_type = 'arrival'
        ORDER BY a.act_date, a.id
    ")->fetchAll();

    echo "Найдено строк к удалению: " . count($rows) . "\n";

    $totalRemoved = 0.0;
    $report = [];

    foreach ($rows as $row) {
        $itemId = (int)$row['item_id'];
        $actId  = (int)$row['act_id'];

        $item = $itemModel->find($itemId);
        $act  = $actModel->find($actId);
        if (!$item || !$act) {
            echo "ПРОПУСК item={$itemId} act={$actId} — не найден (уже обработан?)\n";
            continue;
        }

        // ── 1. Удаляем строку расчёта и саму строку акта (как deleteActItem(), без гейта по статусу) ──
        $calcModel->deleteByItem($itemId);
        $itemModel->delete($itemId);

        // ── 2. Пересчёт итогов акта (точно та же формула, что recalculateActTotals()) ──
        $remainingItems = $itemModel->getByAct($actId);
        $tw = $tv = $tt = 0.0;
        foreach ($remainingItems as $i) {
            $tw += (float)$i['amount_without_vat'];
            $tv += (float)$i['vat_amount'];
            $tt += (float)$i['amount_with_vat'];
        }
        $newTw = round($tw, 2);
        $newTv = round($tv, 2);
        $newTt = round($tt, 2);

        // ── 3. Пометка в notes (видна при печати/просмотре) ──
        $note = "[ИСПРАВЛЕНО ЗАДНИМ ЧИСЛОМ 27.10.2026, round 133] Удалена задвоенная строка "
              . "«Рамп-хэндлинг» (услуга {$item['service_name_snapshot']}, "
              . number_format((float)$item['amount_without_vat'], 2) . " без НДС) — по регламенту "
              . "Рамп-хэндлинг начисляется один раз за ротацию, на акте ВЫЛЕТА; здесь была ошибочно "
              . "задвоена и на акте ПРИЛЁТА (см. round131). Сумма акта уменьшена на эту строку.";
        $existingNotes = trim((string)($act['notes'] ?? ''));
        $newNotes = $existingNotes !== '' ? ($existingNotes . "\n\n" . $note) : $note;

        $actModel->update($actId, [
            'total_amount_without_vat' => $newTw,
            'total_vat_amount'         => $newTv,
            'total_amount_with_vat'    => $newTt,
            'notes'                    => $newNotes,
        ]);

        ActivityLogger::log(
            'act_item',
            $itemId,
            'round133_fix_ramp_arrival_duplicate',
            'success',
            "Удалена задвоенная строка RAMP на акте прилёта '{$act['act_number']}' (#{$actId}); "
                . "итог акта: {$row['old_tw']} → {$newTw} (без НДС)",
            ['act_item' => $item, 'act_totals_before' => [
                'total_amount_without_vat' => $row['old_tw'],
                'total_vat_amount'         => $row['old_tv'],
                'total_amount_with_vat'    => $row['old_tt'],
            ]],
            ['act_totals_after' => [
                'total_amount_without_vat' => $newTw,
                'total_vat_amount'         => $newTv,
                'total_amount_with_vat'    => $newTt,
            ]]
        );

        $totalRemoved += (float)$item['amount_without_vat'];
        $report[] = [
            'act_number' => $act['act_number'],
            'act_id'     => $actId,
            'removed'    => (float)$item['amount_without_vat'],
            'old_total'  => (float)$row['old_tw'],
            'new_total'  => $newTw,
        ];

        echo sprintf(
            "OK  act_id=%-5d %-20s удалено %10.2f | итого (без НДС): %10.2f -> %10.2f\n",
            $actId, $act['act_number'], (float)$item['amount_without_vat'], (float)$row['old_tw'], $newTw
        );
    }

    echo "\nВСЕГО обработано строк: " . count($report) . "\n";
    echo "ВСЕГО снято с актов (без НДС): " . number_format($totalRemoved, 2) . "\n";

    // ── Контрольная проверка: не осталось ли RAMP* на актах прилёта ──
    $leftover = $db->query("
        SELECT COUNT(*) FROM act_items ai
        JOIN acts a ON a.id = ai.act_id
        JOIN flights f ON f.id = a.flight_id
        JOIN services s ON s.id = ai.service_id
        WHERE s.code LIKE 'RAMP%' AND f.operation_type = 'arrival'
    ")->fetchColumn();
    echo "Контроль после исправления (должно быть 0): {$leftover}\n";

    // ── Контрольная проверка: сумма item'ов == totals на каждом затронутом акте ──
    $mismatch = 0;
    foreach ($report as $r) {
        $checkAct = $actModel->find($r['act_id']);
        $sumItems = 0.0;
        foreach ($itemModel->getByAct($r['act_id']) as $i) { $sumItems += (float)$i['amount_without_vat']; }
        if (abs($sumItems - (float)$checkAct['total_amount_without_vat']) > 0.009) {
            echo "MISMATCH act_id={$r['act_id']}: sum(items)={$sumItems} vs acts.total={$checkAct['total_amount_without_vat']}\n";
            $mismatch++;
        }
    }
    echo "Несовпадений сумм (должно быть 0): {$mismatch}\n";

    if ($mode === '--dry-run') {
        $db->rollBack();
        echo "\n=== DRY-RUN: транзакция ОТКАЧЕНА, в БД ничего не изменено ===\n";
    } else {
        if ($mismatch > 0 || (int)$leftover > 0) {
            $db->rollBack();
            echo "\n=== ОШИБКА КОНТРОЛЯ — транзакция ОТКАЧЕНА, commit прерван ===\n";
            exit(1);
        }
        $db->commit();
        echo "\n=== COMMIT: изменения записаны в БД ===\n";
        file_put_contents(
            BASE_PATH . '/private/backups/round133/round133_applied_report.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
} catch (\Throwable $e) {
    $db->rollBack();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
