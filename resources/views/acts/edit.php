<?php
/**
 * acts/edit.php
 * - Price comes ONLY from tariff (read-only, shown as info)
 * - User enters QUANTITY only
 * - VAT applied automatically (domestic=12%, international=0%)
 * - Currency: domestic=UZS auto, international=chosen
 */
$isDomestic = ($act['route_type'] === 'domestic');
$settleCurrency = e($act['currency_code'] ?? 'UZS');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">
        <i class="bi bi-pencil-square text-primary me-2"></i>
        <?= __('ACTS_EDIT_TITLE', [':number' => e(act_display_number($act))]) ?>
    </h2>
    <a href="/acts/<?= (int)$act['id'] ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> <?= __('ACTS_VIEW_ACT') ?>
    </a>
</div>

<!-- Act header form -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><?= __('ACTS_ACT_DETAILS') ?></h6></div>
    <div class="card-body">
        <form method="POST" action="/acts/<?= (int)$act['id'] ?>/edit">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('ACTS_NUMBER') ?></label>
                    <input type="text" class="form-control bg-light" value="<?= e(act_display_number($act)) ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('ACTS_DATE') ?></label>
                    <input type="text" class="form-control bg-light" value="<?= format_date($act['act_date']) ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('ACTS_FLIGHT') ?> <span class="text-danger">*</span></label>
                    <select name="flight_id" class="form-select" required>
                        <?php foreach ($flights as $f): ?>
                        <option value="<?= (int)$f['id'] ?>" <?= ($act['flight_id'] == $f['id']) ? 'selected' : '' ?>>
                            <?= format_flight_label($f) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_ROUTE_TYPE') ?> <span class="text-danger">*</span></label>
                    <select name="route_type" class="form-select" required>
                        <option value="domestic"      <?= $act['route_type']==='domestic'      ? 'selected' : '' ?>><?= __('FLIGHTS_DOMESTIC') ?></option>
                        <option value="international" <?= $act['route_type']==='international' ? 'selected' : '' ?>><?= __('FLIGHTS_INTERNATIONAL') ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_OPERATION') ?> <span class="text-danger">*</span></label>
                    <select name="operation_type" class="form-select" required>
                        <option value="arrival"   <?= $act['operation_type']==='arrival'   ? 'selected' : '' ?>><?= __('FLIGHTS_ARRIVAL') ?></option>
                        <option value="departure" <?= $act['operation_type']==='departure' ? 'selected' : '' ?>><?= __('FLIGHTS_DEPARTURE') ?></option>
                    </select>
                </div>

                <!-- Currency: domestic=UZS fixed, international=selectable -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <?= __('ACTS_CURRENCY') ?> <span class="text-danger">*</span>
                    </label>
                    <?php if ($isDomestic): ?>
                        <input type="text" class="form-control bg-light" value="UZS — <?= __('ACTS_CURRENCY_AUTO_DOMESTIC') ?>" readonly>
                        <input type="hidden" name="settlement_currency_id" value="<?= (int)$act['settlement_currency_id'] ?>">
                    <?php else: ?>
                        <select name="settlement_currency_id" class="form-select" required>
                            <?php foreach ($currencies as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ($act['settlement_currency_id']==$c['id']) ? 'selected' : '' ?>>
                                <?= e($c['code']) ?> — <?= e($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-info"><i class="bi bi-info-circle"></i> <?= __('ACTS_CURRENCY_AUTO_INTL') ?></div>
                    <?php endif; ?>
                </div>

                <!-- CB Rate: required for domestic, optional for international -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <?= __('ACTS_CB_RATE') ?>
                        <?php if ($isDomestic): ?>
                            <span class="text-danger">*</span>
                        <?php else: ?>
                            <span class="text-muted small fw-normal">(<?= __('COMMON_OPTIONAL') ?>)</span>
                        <?php endif; ?>
                    </label>
                    <div class="input-group">
                        <input type="number" name="cb_rate" class="form-control"
                               min="0" step="0.01" <?= $isDomestic ? 'required' : '' ?>
                               value="<?= e($act['cb_rate'] ?? '') ?>"
                               placeholder="e.g. 12700.00">
                        <span class="input-group-text text-muted small">USD/<?= $settleCurrency ?></span>
                    </div>
                    <div class="form-text">
                        <?= __('ACTS_CB_RATE_LABEL', [':currency' => $settleCurrency]) ?>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold"><?= __('COMMON_NOTES') ?></label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($act['notes'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> <?= __('ACTS_SAVE_DETAILS') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Act Items -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><?= __('ACTS_ITEMS') ?></h6>
        <button class="btn btn-sm btn-outline-success" data-bs-toggle="collapse" data-bs-target="#addItemForm">
            <i class="bi bi-plus-lg"></i> <?= __('ACTS_ADD_ITEM') ?>
        </button>
    </div>

    <!-- VAT info banner -->
    <div class="px-3 pt-2 pb-0">
        <?php if ($isDomestic): ?>
        <div class="alert alert-warning py-2 mb-2 small d-flex align-items-center gap-2">
            <i class="bi bi-percent flex-shrink-0"></i>
            <span><strong><?= __('FLIGHTS_DOMESTIC') ?>:</strong> <?= __('ACTS_VAT_AUTO') ?> — НДС 12%</span>
        </div>
        <?php else: ?>
        <div class="alert alert-info py-2 mb-2 small d-flex align-items-center gap-2">
            <i class="bi bi-globe flex-shrink-0"></i>
            <span><strong><?= __('FLIGHTS_INTERNATIONAL') ?>:</strong> <?= __('ACTS_VAT_AUTO') ?> — НДС 0%</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add Item Form -->
    <div class="collapse" id="addItemForm">
        <div class="card-body border-bottom bg-light">
            <form method="POST" action="/acts/<?= (int)$act['id'] ?>/items/add">
                <?= csrf_field() ?>
                <div class="row g-2 align-items-end">

                    <div class="col-md-5">
                        <label class="form-label small fw-semibold"><?= __('ACTS_SERVICE') ?> <span class="text-danger">*</span></label>
                        <select name="service_id" class="form-select form-select-sm" id="serviceSelect" required>
                            <option value=""><?= __('COMMON_SELECT') ?></option>
                            <?php
                            $lastCat = null;
                            foreach ($services as $s):
                                if ($s['category_code'] !== $lastCat) {
                                    if ($lastCat !== null) echo '</optgroup>';
                                    echo '<optgroup label="' . e($s['category_name']) . '">';
                                    $lastCat = $s['category_code'];
                                }
                            ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                            <?php endforeach; if ($lastCat !== null) echo '</optgroup>'; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small fw-semibold"><?= __('ACTS_QTY') ?> <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" id="qtyInput"
                               class="form-control form-control-sm"
                               required min="0.0001" step="0.0001" value="1">
                    </div>

                    <!-- Tariff info (read-only display after loading) -->
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold"><?= __('ACTS_PRICE_USD') ?></label>
                        <input type="text" id="priceDisplay" class="form-control form-control-sm bg-light"
                               readonly placeholder="<?= __('ACTS_LOAD_TARIFF_BTN') ?>…">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small fw-semibold">VAT%</label>
                        <input type="text" id="vatDisplay" class="form-control form-control-sm bg-light"
                               readonly value="<?= $isDomestic ? '12' : '0' ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">
                            <?= __('ACTS_PRICE_SETTLEMENT', [':currency' => $settleCurrency]) ?>
                        </label>
                        <input type="text" id="amountDisplay" class="form-control form-control-sm bg-light fw-semibold"
                               readonly placeholder="—">
                    </div>

                    <!-- Hidden fields -->
                    <input type="hidden" name="tariff_version_id" id="tariffVersionId" value="">

                    <div class="col-12">
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-info" id="loadTariffBtn">
                                <i class="bi bi-arrow-down-circle"></i> <?= __('ACTS_LOAD_TARIFF_BTN') ?>
                            </button>
                            <span id="tariffInfo" class="text-muted small fst-italic"></span>
                        </div>
                    </div>

                    <div class="col-md-6 mt-1">
                        <label class="form-label small fw-semibold"><?= __('ACTS_CALC_NOTE') ?></label>
                        <input type="text" name="calculation_note" class="form-control form-control-sm"
                               placeholder="<?= __('ACTS_CALC_NOTE_PLACEHOLDER') ?>">
                    </div>

                    <div class="col-12 mt-2 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-success" id="addItemSubmit" disabled>
                            <i class="bi bi-plus-lg"></i> <?= __('ACTS_ADD_ITEM_BTN') ?>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="collapse" data-bs-target="#addItemForm">
                            <?= __('COMMON_CANCEL') ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Items Table -->
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th><?= __('ACTS_SERVICE') ?></th>
                        <th><?= __('ACTS_UNIT') ?></th>
                        <th class="text-end"><?= __('ACTS_QTY') ?></th>
                        <th class="text-end"><?= __('ACTS_PRICE_USD') ?></th>
                        <th class="text-center">VAT%</th>
                        <th class="text-end"><?= __('ACTS_TOTAL_WITHOUT_VAT') ?> <small class="opacity-75"><?= $settleCurrency ?></small></th>
                        <th class="text-end"><?= __('ACTS_TOTAL_VAT') ?> <small class="opacity-75"><?= $settleCurrency ?></small></th>
                        <th class="text-end"><?= __('ACTS_TOTAL_WITH_VAT') ?> <small class="opacity-75"><?= $settleCurrency ?></small></th>
                        <th><?= __('COMMON_ACTIONS') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-3">
                        <i class="bi bi-inbox d-block fs-4 mb-1 opacity-30"></i>
                        <?= __('ACTS_NO_ITEMS_YET') ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$item['line_no'] ?></td>
                        <td class="small">
                            <?= e($item['service_name_snapshot']) ?>
                            <?php /* ИЗМЕНЕНО (14.08.2026, раунд 7): бейдж "По умолчанию" раньше
                                     показывался по ai.is_default — а это чисто техническая
                                     отметка "строка добавлена системой при сборке акта"
                                     (нужна для дедупликации при пересборке, см.
                                     ActItem::deleteAutoByActAndService()), из-за чего бейдж
                                     ошибочно появлялся на ВСЕХ авто-собранных строках, включая
                                     реально запрошенные/оказанные услуги (PAX, багаж, автобус,
                                     МА-7, тех.персонал). Теперь бейдж показывает
                                     s.is_blanket — услуга, которая оказывается на каждый рейс
                                     без отдельной заявки службы (взлёт-посадка,
                                     авиабезопасность, лидирование ВС и т.п., см. миграцию
                                     round7). */ ?>
                            <?php if ($item['service_is_blanket'] ?? 0): ?>
                            <span class="badge bg-secondary ms-1" style="font-size:0.6rem"><?= __('ACTS_IS_DEFAULT_BADGE') ?></span>
                            <?php endif; ?>
                            <?php if ($item['calculation_note']): ?>
                            <br><span class="text-muted fst-italic" style="font-size:0.75rem"><?= e($item['calculation_note']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= e($item['unit_name_snapshot']) ?></td>
                        <td class="text-end small"><?= format_money($item['quantity'], 4) ?></td>
                        <td class="text-end small">
                            <?= format_money($item['unit_price'], 4) ?>
                            <code class="small">USD</code>
                            <?php if ($item['cb_rate'] > 0): ?>
                            <br><span class="text-muted" style="font-size:0.7rem">×<?= format_money($item['cb_rate'], 2) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center small"><?= e($item['vat_rate']) ?>%</td>
                        <td class="text-end small"><?= format_money($item['amount_without_vat']) ?></td>
                        <td class="text-end small text-warning"><?= format_money($item['vat_amount']) ?></td>
                        <td class="text-end small fw-bold text-success"><?= format_money($item['amount_with_vat']) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <button class="btn btn-xs btn-outline-primary"
                                        onclick="openEditItem(<?= (int)$item['id'] ?>, <?= (float)$item['quantity'] ?>, '<?= e($item['calculation_note'] ?? '') ?>')"
                                        title="<?= __('COMMON_EDIT') ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="/acts/<?= (int)$act['id'] ?>/items/<?= (int)$item['id'] ?>/delete" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-xs btn-outline-danger"
                                            onclick="return confirm('<?= __('COMMON_CONFIRM_DELETE') ?>')"
                                            title="<?= __('COMMON_DELETE') ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Totals -->
    <div class="card-footer bg-white">
        <div class="row justify-content-end">
            <div class="col-md-4">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted small"><?= __('ACTS_TOTAL_WITHOUT_VAT') ?>:</td>
                        <td class="text-end fw-semibold"><?= format_money($act['total_amount_without_vat']) ?> <code><?= $settleCurrency ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted small"><?= __('ACTS_TOTAL_VAT') ?>:</td>
                        <td class="text-end text-warning"><?= format_money($act['total_vat_amount']) ?> <code><?= $settleCurrency ?></code></td>
                    </tr>
                    <tr class="fw-bold table-success">
                        <td><?= __('ACTS_TOTAL_WITH_VAT') ?>:</td>
                        <td class="text-end fs-6"><?= format_money($act['total_amount_with_vat']) ?> <code><?= $settleCurrency ?></code></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit Item Modal (quantity only) -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" id="editItemForm">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title"><?= __('ACTS_EDIT_ITEM_TITLE') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('ACTS_QTY') ?> <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" id="editQty"
                               class="form-control" required min="0.0001" step="0.0001">
                        <div class="form-text"><?= __('ACTS_TARIFF_PRICE_READONLY') ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('ACTS_CALC_NOTE') ?></label>
                        <input type="text" name="calculation_note" id="editNote" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= __('COMMON_CANCEL') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= __('ACTS_SAVE_ITEM') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const ACT_ID    = <?= (int)$act['id'] ?>;
const ACT_ROUTE = '<?= e($act['route_type']) ?>';
const ACT_OP    = '<?= e($act['operation_type']) ?>';
const ACT_DATE    = '<?= e($act['act_date']) ?>';
const CB_RATE     = <?= (float)($act['cb_rate'] ?? 0) ?>;
const IS_DOMESTIC = <?= $isDomestic ? 'true' : 'false' ?>;
// MTOW и TLF для расчёта % услуг
const FLIGHT_MTOW = <?= (float)($act['aircraft_mtow'] ?? 0) ?>;
const TLF_USD     = <?= (float)($act['aircraft_mtow'] ?? 0) * 20 ?>; // MTOW × $20 (базовый TLF per ton)
// % услуги: qty=процент, price=TLF_USD
const PCT_SERVICES = ['AV_SEC','NIGHT','OVER_PARK'];

// Load tariff button: auto-fills price (read-only), calculates preview
document.getElementById('loadTariffBtn').addEventListener('click', function () {
    const serviceId = document.getElementById('serviceSelect').value;
    if (!serviceId) {
        alert('<?= __('ACTS_SELECT_SERVICE_FIRST') ?>');
        return;
    }

    fetch('/acts/api/tariff-for-service'
        + '?service_id=' + serviceId
        + '&route_type=' + ACT_ROUTE
        + '&operation_type=' + ACT_OP
        + '&date=' + ACT_DATE)
    .then(r => r.json())
    .then(data => {
        if (data.success && data.tariff) {
            const t = data.tariff;
            const svcCode = data.tariff.service_code || '';
            const isPctSvc = ['AV_SEC','NIGHT','OVER_PARK'].includes(svcCode);

            document.getElementById('vatDisplay').value      = IS_DOMESTIC ? '12' : '0';
            document.getElementById('tariffVersionId').value = t.id;
            document.getElementById('addItemSubmit').disabled = false;

            if (isPctSvc && TLF_USD > 0) {
                // % услуга: qty = процент, price = TLF_USD
                const pct = parseFloat(t.price); // 0.10 / 0.20 / 0.25
                document.getElementById('qtyInput').value   = pct;
                document.getElementById('priceDisplay').value = TLF_USD.toFixed(2) + ' USD (TLF)';
                const pctLabel = (pct * 100).toFixed(0) + '%';
                document.getElementById('tariffInfo').textContent =
                    pctLabel + ' от TLF = ' + pctLabel + ' × ' + TLF_USD.toFixed(2) + ' USD';
                updateAmountPreview(TLF_USD);
            } else {
                document.getElementById('priceDisplay').value = t.price + ' USD';
                document.getElementById('tariffInfo').textContent = '<?= __('ACTS_TARIFF_LABEL') ?>: ' + t.tariff_name;
                updateAmountPreview(parseFloat(t.price));
            }

            if (isPctSvc && TLF_USD === 0) {
                document.getElementById('tariffInfo').textContent =
                    '⚠️ MTOW не указан в рейсе — невозможно рассчитать % от TLF. Укажите MTOW в рейсе.';
            }
        } else {
            document.getElementById('tariffInfo').textContent = '<?= __('ACTS_TARIFF_NOT_FOUND') ?>';
            document.getElementById('priceDisplay').value    = '';
            document.getElementById('tariffVersionId').value = '';
            document.getElementById('addItemSubmit').disabled = true;
        }
    })
    .catch(() => alert('Error loading tariff.'));
});

// Live quantity input → update amount preview
document.getElementById('qtyInput').addEventListener('input', function () {
    const priceText = document.getElementById('priceDisplay').value;
    const priceMatch = priceText.match(/[\d.]+/);
    if (priceMatch) updateAmountPreview(parseFloat(priceMatch[0]));
});

function updateAmountPreview(priceUsd, serviceCode) {
    const qty     = parseFloat(document.getElementById('qtyInput').value) || 0;
    const vatRate = IS_DOMESTIC ? 12 : 0;
    if (CB_RATE <= 0) return;

    let withoutVat = qty * priceUsd * CB_RATE;
    const withVat  = withoutVat * (1 + vatRate / 100);
    document.getElementById('amountDisplay').value =
        withoutVat.toFixed(2) + (vatRate > 0 ? ' → ' + withVat.toFixed(2) : '');
}

// Edit item modal (quantity only)
function openEditItem(itemId, qty, note) {
    document.getElementById('editQty').value  = qty;
    document.getElementById('editNote').value = note;
    document.getElementById('editItemForm').action =
        '/acts/' + ACT_ID + '/items/' + itemId + '/update';
    new bootstrap.Modal(document.getElementById('editItemModal')).show();
}
</script>
