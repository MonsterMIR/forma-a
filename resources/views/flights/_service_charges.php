<?php
/**
 * Блок "Оказанные услуги" для форм СОП/СПО/ССТ/Аэродромной.
 * Переменные: $flight, $myCharges, $availableServices, $sourceRole, $qtyHints
 *
 * $qtyHints — массив ['SERVICE_CODE' => ['qty' => N, 'hint' => 'текст подсказки']]
 * Используется для автоподстановки qty из документов служб:
 *   СОП: qty из манифеста (PAX, багаж)
 *   СПО: qty из техкарты (уборка=1, noshow=N мест)
 *   ССТ: qty из журнала (кол-во единиц техники)
 *   Аэродромная: qty = 1 (охрана, SITA)
 */
$myCharges         = $myCharges ?? [];
$availableServices = $availableServices ?? [];
$sourceRole        = $sourceRole ?? '';
$qtyHints          = $qtyHints ?? [];

// Группируем по категории
$grouped = [];
foreach ($availableServices as $s) {
    $cat = $s['category_name'] ?? 'Прочее';
    $grouped[$cat][] = $s;
}

// Карта: service_code → qty hint
$hintsByCode = [];
foreach ($availableServices as $s) {
    $code = $s['code'] ?? '';
    if ($code && isset($qtyHints[$code])) {
        $hintsByCode[$s['id']] = $qtyHints[$code];
    }
}
?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="bi bi-receipt me-2"></i>Оказанные услуги по рейсу
        </span>
        <span class="badge bg-<?= empty($myCharges) ? 'secondary' : 'success' ?>">
            <?= count($myCharges) ?> услуг
        </span>
    </div>

    <?php if (!empty($myCharges)): ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Услуга</th>
                    <th style="width:80px">Кол-во</th>
                    <th style="width:60px">Ед.</th>
                    <th>Примечание</th>
                    <th style="width:80px">Статус</th>
                    <th style="width:40px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($myCharges as $c): ?>
            <tr class="<?= !empty($c['consumed_in_act_id']) ? 'table-light' : '' ?>">
                <td><?= e($c['service_name']) ?></td>
                <td class="fw-bold">
                    <?= rtrim(rtrim(number_format((float)$c['quantity'], 4, '.', ''), '0'), '.') ?>
                </td>
                <td class="text-muted small"><?= e($c['unit_name'] ?? '') ?></td>
                <td class="text-muted small"><?= e($c['note'] ?? '—') ?></td>
                <td>
                    <?php if (!empty($c['consumed_in_act_id'])): ?>
                    <span class="badge bg-success" style="font-size:.65rem">
                        <i class="bi bi-check"></i> в акте
                    </span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark" style="font-size:.65rem">ожидает</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($c['consumed_in_act_id'])): ?>
                    <form method="POST" action="/service-charges/<?= (int)$c['id'] ?>/delete" class="d-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"
                                onclick="return confirm('Удалить услугу?')">
                            <i class="bi bi-trash" style="font-size:.7rem"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body text-center text-muted py-3 small">
        <i class="bi bi-inbox fs-3 d-block mb-1 opacity-25"></i>
        Услуги ещё не добавлены
    </div>
    <?php endif; ?>

    <div class="card-footer bg-white">
        <?php if (empty($availableServices)): ?>
        <div class="alert alert-warning small mb-0">
            <i class="bi bi-exclamation-circle me-1"></i>
            Нет доступных услуг для вашей службы. Обратитесь в коммерческую службу.
        </div>
        <?php else: ?>

        <?php if (!empty($qtyHints)): ?>
        <div class="alert alert-info small py-2 mb-2">
            <i class="bi bi-lightbulb me-1"></i>
            <strong>Автоподстановка количества:</strong> при выборе услуги количество заполнится автоматически из данных вашего документа.
        </div>
        <?php endif; ?>

        <form method="POST" action="/service-charges/add" class="row g-2 align-items-end" id="sc-form-<?= e($sourceRole) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="flight_id"   value="<?= (int)$flight['id'] ?>">
            <input type="hidden" name="source_role" value="<?= e($sourceRole) ?>">

            <div class="col-md-5">
                <label class="form-label small fw-semibold mb-1">Услуга</label>
                <select name="service_id" class="form-select form-select-sm"
                        id="sc-service-<?= e($sourceRole) ?>"
                        required
                        onchange="scAutoQty('<?= e($sourceRole) ?>')">
                    <option value="">— выберите услугу —</option>
                    <?php foreach ($grouped as $catName => $services): ?>
                    <optgroup label="<?= e($catName) ?>">
                        <?php foreach ($services as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"
                                data-qty="<?= isset($hintsByCode[$s['id']]) ? (int)$hintsByCode[$s['id']]['qty'] : '' ?>"
                                data-hint="<?= isset($hintsByCode[$s['id']]) ? e($hintsByCode[$s['id']]['hint']) : '' ?>">
                            <?= e($s['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">
                    Количество
                    <span id="sc-hint-<?= e($sourceRole) ?>" class="text-info fw-normal" style="font-size:.7rem"></span>
                </label>
                <input type="number" name="quantity"
                       id="sc-qty-<?= e($sourceRole) ?>"
                       class="form-control form-control-sm"
                       step="0.0001" min="0.0001" required value="1">
            </div>

            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Примечание</label>
                <input type="text" name="note"
                       class="form-control form-control-sm"
                       placeholder="необязательно" maxlength="255">
            </div>

            <div class="col-md-2">
                <button type="submit" class="btn btn-success btn-sm w-100">
                    <i class="bi bi-plus-circle me-1"></i>Добавить
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
function scAutoQty(role) {
    const sel  = document.getElementById('sc-service-' + role);
    const qtyF = document.getElementById('sc-qty-' + role);
    const hint = document.getElementById('sc-hint-' + role);
    if (!sel || !qtyF) return;

    const opt = sel.options[sel.selectedIndex];
    const q   = opt ? opt.getAttribute('data-qty') : '';
    const h   = opt ? opt.getAttribute('data-hint') : '';

    if (q && q !== '') {
        qtyF.value = q;
        if (hint) hint.textContent = '← ' + h;
    } else {
        qtyF.value = 1;
        if (hint) hint.textContent = '';
    }
}
</script>
