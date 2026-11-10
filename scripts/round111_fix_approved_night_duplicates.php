<?php
declare(strict_types=1);

/**
 * РАУНД 111 (21.10.2026) — исправление задвоенного ночного сбора (NIGHT)
 * на 11 уже ОДОБРЕННЫХ актах (реальные подписанные документы).
 *
 * Контекст (round110): processParkingAct() начислял NIGHT fee на КАЖДЫЙ
 * акт ротации независимо, без проверки, не начислен ли он уже на
 * связанном рейсе той же ротации — при обычной работе (кнопка
 * "Обновить"/"Сформировать Форму А" нажимается отдельно на акте прилёта
 * и отдельно на акте вылета) сбор удваивался. Код исправлен в round110
 * (AutoChargeService::processParkingAct() теперь проверяет
 * existsChargeForService() на связанном рейсе). Два ещё черновых
 * случая (#71/#72 и #101/#102) исправлены там же.
 *
 * Аудит по живой базе (21.10.2026) нашёл ещё 11 РОТАЦИЙ, где обе ноги
 * уже ОДОБРЕНЫ и обе несут строку NIGHT. Пользователь прямо подтвердил:
 * "если ты в этом уверен то исправь акты" — после того как ему был
 * показан полный список и объяснён риск. Правило исправления — то же,
 * что уже применялось в round110 к черновикам: сбор остаётся на акте
 * ПРИЛЁТА (так задумано изначально — "один раз за ротацию"), дублирующая
 * строка на акте ВЫЛЕТА удаляется.
 *
 * ВАЖНО — что этот скрипт НЕ делает:
 *   - НЕ меняет статус акта (остаётся 'approved' — это НЕ отмена
 *     утверждения, а точечное исправление ошибочной строки в уже
 *     утверждённом документе; approved_by_user_id/approved_at/подписи
 *     не трогаются — утверждение само по себе было законным действием
 *     подписантов, ошибка была в сумме, которую они увидели).
 *   - НЕ трогает flight_service_charges (как и ActService::deleteActItem()
 *     в обычной работе — см. round110, тот же паттерн).
 *   - НЕ использует ActService::deleteActItem() напрямую (он блокирует
 *     approved-акты по дизайну для обычного UI-редактирования) — здесь
 *     воспроизведена ТА ЖЕ внутренняя логика (удаление строки расчёта +
 *     удаление строки акта + пересчёт итогов) как осознанное
 *     административное исправление подтверждённого системного бага.
 *
 * Особые случаи (суммы на двух ногах отличались — см. анализ в
 * claude/analysis round111 doc):
 *   - TIS1501/1502 (act1 72.00 vs act58 80.64, разный НДС на двух ногах) —
 *     удаляется вылетная строка (act58, 80.64), остаётся прилётная (72.00).
 *   - TCCCM (act56 210.56 vs act70 188.00) — удаляется вылетная строка
 *     (act70, 188.00), остаётся прилётная (210.56).
 *   В обоих случаях решение то же самое правило "оставить на прилёте",
 *   просто как напоминание — итоговая сумма меняется на удаляемую сумму,
 *   а не "выбирается меньшая/большая".
 *
 * Безопасно запускать повторно (идемпотентно — если строки уже удалены,
 * просто ничего не находит и завершается).
 *
 * Запуск (по SSH, из корня public_html):
 *   php scripts/round111_fix_approved_night_duplicates.php
 */

define('BASE_PATH', dirname(__DIR__));

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

use App\Core\ActivityLogger;
use App\Models\Act;
use App\Models\ActItem;
use App\Models\ActItemCalculation;

$actModel  = new Act();
$itemModel = new ActItem();
$calcModel = new ActItemCalculation();

// act_item_id => краткое описание ротации (для лога/печати)
$targets = [
    1016 => 'VJT762 (9HVIH), act40 — вылетная нога, прилёт act42 сохраняет NIGHT',
    872  => 'NWC9065/9066 (RA09024), act49 — вылетная нога, прилёт act47 сохраняет NIGHT',
    783  => 'RSJ7165 (RA67165), act44 — вылетная нога, прилёт act35 сохраняет NIGHT',
    1039 => 'TIS1501/1502 (RA67250), act58 — вылетная нога (80.64, с НДС), прилёт act1 сохраняет NIGHT (72.00, без НДС)',
    528  => 'JTC9725/9726 (RA73532), act27 — вылетная нога, прилёт act24 сохраняет NIGHT',
    1208 => 'T7AAA7 (рейсы 63/75), act71 — вылетная нога, прилёт act59 сохраняет NIGHT',
    894  => 'LSM888 (T7FFI), act11 — вылетная нога, прилёт act10 сохраняет NIGHT',
    1333 => 'TCALG, act79 — вылетная нога, прилёт act78 сохраняет NIGHT',
    1190 => 'TCCCM, act70 — вылетная нога (188.00), прилёт act56 сохраняет NIGHT (210.56, суммы отличались)',
    1438 => 'TCOIL, act68 — вылетная нога, прилёт act85 сохраняет NIGHT',
    637  => 'TCTOZ, act34 — вылетная нога, прилёт act22 сохраняет NIGHT',
];

$backup = [];
$results = [];

foreach ($targets as $itemId => $label) {
    $item = $itemModel->find($itemId);
    if (!$item) {
        $results[] = "#{$itemId} ({$label}): СТРОКА НЕ НАЙДЕНА — возможно уже удалена ранее, пропускаю.";
        continue;
    }
    $actId = (int)$item['act_id'];
    $act   = $actModel->find($actId);
    if (!$act) {
        $results[] = "#{$itemId} ({$label}): акт #{$actId} не найден — прерываю для этой строки.";
        continue;
    }
    if ($act['status'] !== 'approved') {
        $results[] = "#{$itemId} ({$label}): акт '{$act['act_number']}' имеет статус '{$act['status']}' "
            . "(не 'approved' — возможно уже исправлен другим путём или это не тот акт) — пропускаю без изменений.";
        continue;
    }

    $backup[] = [
        'act_item' => $item,
        'act_before' => $act,
    ];

    // Точное воспроизведение внутренней логики ActService::deleteActItem(),
    // без её гейта по статусу акта (см. докблок выше).
    $calcModel->deleteByItem($itemId);
    $itemModel->delete($itemId);

    // Пересчёт итогов акта (= ActService::recalculateActTotals()).
    $remaining = $itemModel->getByAct($actId);
    $tw = $tv = $tt = 0.0;
    foreach ($remaining as $i) {
        $tw += (float)$i['amount_without_vat'];
        $tv += (float)$i['vat_amount'];
        $tt += (float)$i['amount_with_vat'];
    }
    $newTotals = [
        'total_amount_without_vat' => round($tw, 2),
        'total_vat_amount'         => round($tv, 2),
        'total_amount_with_vat'    => round($tt, 2),
    ];
    $actModel->update($actId, $newTotals);

    // Видимая на акте пометка об исправлении — для прозрачности при
    // печати/просмотре документа задним числом.
    $noteAppend = "\n[Раунд 111, 21.10.2026] Удалена задвоенная строка «Ночной сбор» "
        . "(" . number_format((float)$item['amount_with_vat'], 2) . "), ошибочно начисленная повторно на обеих "
        . "ногах одной ротации автоматическим расчётом (исправлено системно в round110). "
        . "Итог акта уменьшен с " . number_format((float)$act['total_amount_with_vat'], 2)
        . " до " . number_format($newTotals['total_amount_with_vat'], 2) . ".";
    $actModel->update($actId, ['notes' => rtrim((string)($act['notes'] ?? '')) . $noteAppend]);

    ActivityLogger::log(
        'act_item', $itemId, 'round111_fix_approved_night_duplicate', 'success',
        "Раунд 111: удалена задвоенная строка NIGHT ({$label}) из утверждённого акта "
            . "'{$act['act_number']}' — та же ротация уже несёт этот сбор на связанном акте прилёта. "
            . "Итог акта: " . number_format((float)$act['total_amount_with_vat'], 2)
            . " → " . number_format($newTotals['total_amount_with_vat'], 2) . ".",
        ['act_item' => $item, 'act_totals_before' => [
            'total_amount_without_vat' => $act['total_amount_without_vat'],
            'total_vat_amount'         => $act['total_vat_amount'],
            'total_amount_with_vat'    => $act['total_amount_with_vat'],
        ]],
        ['act_totals_after' => $newTotals]
    );

    $results[] = "#{$itemId} ({$label}): удалено. Итог акта '{$act['act_number']}': "
        . number_format((float)$act['total_amount_with_vat'], 2) . " → "
        . number_format($newTotals['total_amount_with_vat'], 2) . ".";
}

// Бэкап состояния "до" — на диск, для аудита.
file_put_contents(
    BASE_PATH . '/storage/round111_before_state_' . date('Ymd_His') . '.json',
    json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "Результаты:\n";
foreach ($results as $r) {
    echo "  - {$r}\n";
}
echo "\nГотово. Бэкап состояния 'до' сохранён в storage/round111_before_state_*.json\n";
