<?php
/**
 * resources/views/acts/create.php
 * Создание акта — номер присваивается системой
 */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">
        <i class="bi bi-plus-circle text-primary me-2"></i><?= __('ACTS_CREATE') ?>
    </h2>
    <a href="/acts" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?>
    </a>
</div>

<!-- Информационный блок об автонумерации -->
<div class="alert alert-info d-flex align-items-start gap-3 mb-4">
    <i class="bi bi-info-circle-fill fs-5 mt-1 flex-shrink-0"></i>
    <div>
        <strong><?= __('ACTS_AUTONUMBER_TITLE') ?></strong><br>
        <span class="text-muted"><?= __('ACTS_AUTONUMBER_HINT') ?></span>
        <div class="mt-2 d-flex align-items-center gap-2">
            <span class="badge bg-primary fs-6 px-3 py-2 font-monospace">
                <?= e($nextNumber) ?>
            </span>
            <small class="text-muted"><?= __('ACTS_AUTONUMBER_PREVIEW') ?><br>
                <em>Формат: NNNN/IATA-Рейс &mdash; XX заменится кодом авиакомпании</em>
            </small>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm" style="max-width:760px">
    <div class="card-header">
        <i class="bi bi-file-earmark-plus me-1"></i> <?= __('ACTS_FILL_DETAILS') ?>
    </div>
    <div class="card-body">
        <form method="POST" action="/acts/create">
            <?= csrf_field() ?>
            <div class="row g-3">

                <!-- Рейс -->
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        <?= __('ACTS_FLIGHT') ?> <span class="text-danger">*</span>
                    </label>
                    <select name="flight_id" class="form-select" required id="flightSelect">
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <?php foreach ($flights as $f): ?>
                        <option value="<?= (int)$f['id'] ?>"
                                data-route="<?= e($f['route_type']) ?>"
                                data-operation="<?= e($f['operation_type']) ?>"
                                <?= (($_POST['flight_id'] ?? '') == $f['id']) ? 'selected' : '' ?>>
                            <?= format_flight_label($f) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text"><?= __('ACTS_FLIGHT_HINT') ?></div>
                </div>

                <!-- Тип маршрута + Операция (автозаполняются из рейса) -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">
                        <?= __('ACTS_ROUTE_TYPE') ?> <span class="text-danger">*</span>
                    </label>
                    <select name="route_type" class="form-select" required id="routeTypeSelect">
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <option value="domestic"
                            <?= (($_POST['route_type'] ?? '') === 'domestic') ? 'selected' : '' ?>>
                            <?= __('FLIGHTS_DOMESTIC') ?>
                        </option>
                        <option value="international"
                            <?= (($_POST['route_type'] ?? '') === 'international') ? 'selected' : '' ?>>
                            <?= __('FLIGHTS_INTERNATIONAL') ?>
                        </option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">
                        <?= __('ACTS_OPERATION') ?> <span class="text-danger">*</span>
                    </label>
                    <select name="operation_type" class="form-select" required id="operationTypeSelect">
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <option value="arrival"
                            <?= (($_POST['operation_type'] ?? '') === 'arrival') ? 'selected' : '' ?>>
                            <?= __('FLIGHTS_ARRIVAL') ?>
                        </option>
                        <option value="departure"
                            <?= (($_POST['operation_type'] ?? '') === 'departure') ? 'selected' : '' ?>>
                            <?= __('FLIGHTS_DEPARTURE') ?>
                        </option>
                    </select>
                </div>

                <!-- Валюта расчёта: domestic=UZS авто, international=выбор -->
                <div class="col-md-6" id="currencyBlock">
                    <label class="form-label fw-semibold">
                        <?= __('ACTS_CURRENCY') ?> <span class="text-danger">*</span>
                    </label>
                    <!-- Domestic: hidden, auto-set to UZS -->
                    <div id="currencyDomestic" style="display:none">
                        <input type="text" class="form-control bg-light"
                               value="UZS — <?= __('ACTS_CURRENCY_AUTO_DOMESTIC') ?>" readonly>
                        <input type="hidden" name="settlement_currency_id" id="currencyHidden" value="">
                    </div>
                    <!-- International: selectable -->
                    <div id="currencyIntl">
                        <select name="settlement_currency_id" id="currencySelect" class="form-select" required>
                            <option value=""><?= __('COMMON_SELECT') ?></option>
                            <?php foreach ($currencies as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                data-code="<?= e($c['code']) ?>"
                                <?= (($_POST['settlement_currency_id'] ?? '') == $c['id']) ? 'selected' : '' ?>>
                                <?= e($c['code']) ?> — <?= e($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-info"><i class="bi bi-info-circle"></i> <?= __('ACTS_CURRENCY_AUTO_INTL') ?></div>
                    </div>
                </div>

                <!-- Курс ЦБ: обязателен для domestic, опционален для international -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" id="cbRateLabel">
                        <?= __('ACTS_CB_RATE') ?>
                        <span id="cbRateRequired" class="text-danger" style="display:none">*</span>
                        <span id="cbRateOptional" class="text-muted fw-normal small">(<?= __('COMMON_OPTIONAL') ?>)</span>
                    </label>
                    <div class="input-group">
                        <input type="number" name="cb_rate" id="cbRateInput" class="form-control"
                               min="0" step="0.01"
                               placeholder="e.g. 12700.00"
                               value="<?= e($_POST['cb_rate'] ?? '') ?>">
                        <span class="input-group-text text-muted small" id="cbRateSuffix">USD/UZS</span>
                    </div>
                    <div class="form-text"><?= __('ACTS_CB_RATE_HINT') ?></div>
                </div>

                <!-- Примечания -->
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        <?= __('COMMON_NOTES') ?>
                        <span class="text-muted fw-normal small">(<?= __('COMMON_OPTIONAL') ?>)</span>
                    </label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="<?= __('ACTS_NOTES_PLACEHOLDER') ?>"><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>

            </div>

            <!-- Уведомление о дефолтных услугах -->
            <div class="alert alert-success mt-4 mb-3 d-flex align-items-start gap-2">
                <i class="bi bi-check-circle-fill mt-1 flex-shrink-0"></i>
                <div class="small">
                    <strong><?= __('ACTS_DEFAULT_SERVICES_TITLE') ?></strong><br>
                    <?= __('ACTS_DEFAULT_SERVICES_HINT') ?>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> <?= __('ACTS_CREATE_BTN') ?>
                </button>
                <a href="/acts" class="btn btn-outline-secondary"><?= __('COMMON_CANCEL') ?></a>
            </div>
        </form>
    </div>
</div>

<script>
// UZS currency ID (set from PHP)
<?php
// Find UZS currency ID for JS
$uzsCurrencyId = 0;
if (!empty($currencies)) {
    foreach ($currencies as $c) {
        if (($c['code'] ?? '') === 'UZS') { $uzsCurrencyId = (int)$c['id']; break; }
    }
}
?>
const UZS_ID = <?= $uzsCurrencyId ?>;

function applyRouteType(routeType) {
    const isDomestic = (routeType === 'domestic');

    // Currency block
    document.getElementById('currencyDomestic').style.display = isDomestic ? '' : 'none';
    document.getElementById('currencyIntl').style.display     = isDomestic ? 'none' : '';
    document.getElementById('currencySelect').required        = !isDomestic;
    document.getElementById('currencySelect').disabled        = isDomestic;

    if (isDomestic && UZS_ID) {
        document.getElementById('currencyHidden').value    = UZS_ID;
        document.getElementById('currencyHidden').disabled = false;
    } else {
        document.getElementById('currencyHidden').disabled = true;
    }

    // CB Rate
    document.getElementById('cbRateRequired').style.display = isDomestic ? '' : 'none';
    document.getElementById('cbRateOptional').style.display = isDomestic ? 'none' : '';
    document.getElementById('cbRateInput').required         = isDomestic;

    // Suffix label
    const selOpt = document.getElementById('currencySelect');
    const code = isDomestic ? 'UZS'
        : (selOpt.options[selOpt.selectedIndex]?.dataset?.code || '?');
    document.getElementById('cbRateSuffix').textContent = 'USD/' + code;
}

// Flight select — auto-fill route type + operation
document.getElementById('flightSelect').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    if (opt.dataset.route) {
        document.getElementById('routeTypeSelect').value = opt.dataset.route;
        applyRouteType(opt.dataset.route);
    }
    if (opt.dataset.operation) {
        document.getElementById('operationTypeSelect').value = opt.dataset.operation;
    }
});

document.getElementById('routeTypeSelect').addEventListener('change', function () {
    applyRouteType(this.value);
});

document.getElementById('currencySelect').addEventListener('change', function () {
    const code = this.options[this.selectedIndex]?.dataset?.code || '?';
    document.getElementById('cbRateSuffix').textContent = 'USD/' + code;
});

// Init on page load
applyRouteType(document.getElementById('routeTypeSelect').value);
</script>