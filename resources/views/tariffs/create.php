<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-plus-circle"></i> <?= __('TARIFFS_CREATE') ?></h2>
    <a href="/tariffs" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
</div>

<div class="card border-0 shadow-sm" style="max-width:720px">
    <div class="card-body">
        <form method="POST" action="/tariffs/create">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Service <span class="text-danger">*</span></label>
                    <select name="service_id" class="form-select" required>
                        <option value="">— Select service —</option>
                        <?php foreach ($services as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"
                            <?= (($_POST['service_id'] ?? $preService) == $s['id']) ? 'selected' : '' ?>>
                            <?= e($s['name']) ?> (<?= e($s['code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('TARIFFS_NAME') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="tariff_name" class="form-control" required maxlength="150"
                           value="<?= e($_POST['tariff_name'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_ROUTE_TYPE') ?> <span class="text-danger">*</span></label>
                    <select name="route_type" class="form-select" required>
                        <option value="both" <?= (($_POST['route_type'] ?? 'both') === 'both') ? 'selected' : '' ?>><?= __('TARIFFS_BOTH') ?></option>
                        <option value="domestic" <?= (($_POST['route_type'] ?? '') === 'domestic') ? 'selected' : '' ?>><?= __('FLIGHTS_DOMESTIC') ?></option>
                        <option value="international" <?= (($_POST['route_type'] ?? '') === 'international') ? 'selected' : '' ?>><?= __('FLIGHTS_INTERNATIONAL') ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_OPERATION') ?> <span class="text-danger">*</span></label>
                    <select name="operation_type" class="form-select" required>
                        <option value="both" <?= (($_POST['operation_type'] ?? 'both') === 'both') ? 'selected' : '' ?>><?= __('TARIFFS_BOTH') ?></option>
                        <option value="arrival" <?= (($_POST['operation_type'] ?? '') === 'arrival') ? 'selected' : '' ?>><?= __('FLIGHTS_ARRIVAL') ?></option>
                        <option value="departure" <?= (($_POST['operation_type'] ?? '') === 'departure') ? 'selected' : '' ?>><?= __('FLIGHTS_DEPARTURE') ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('SERVICES_CURRENCY') ?> <span class="text-danger">*</span></label>
                    <select name="currency_id" class="form-select" required>
                        <option value="">— Select —</option>
                        <?php foreach ($currencies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (($_POST['currency_id'] ?? '') == $c['id']) ? 'selected' : '' ?>>
                            <?= e($c['code']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('TARIFFS_PRICE') ?> <span class="text-danger">*</span></label>
                    <input type="number" name="price" class="form-control" required min="0" step="0.0001"
                           value="<?= e($_POST['price'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('TARIFFS_VAT_RATE') ?></label>
                    <input type="number" name="vat_rate" class="form-control" min="0" max="100" step="0.01"
                           value="<?= e($_POST['vat_rate'] ?? '0') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('TARIFFS_VALID_FROM') ?> <span class="text-danger">*</span></label>
                    <input type="date" name="valid_from" class="form-control" required
                           value="<?= e($_POST['valid_from'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('TARIFFS_VALID_TO') ?> </label>
                    <input type="date" name="valid_to" class="form-control"
                           value="<?= e($_POST['valid_to'] ?? '') ?>">
                    <div class="form-text">Leave empty for open-ended</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('COMMON_STATUS') ?> </label>
                    <select name="is_active" class="form-select">
                        <option value="1"><?= __('COMMON_ACTIVE') ?></option>
                        <option value="0"><?= __('COMMON_INACTIVE') ?></option>
                    </select>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= __('COMMON_CREATE') ?> Tariff</button>
                <a href="/tariffs" class="btn btn-outline-secondary"><?= __('COMMON_CANCEL') ?></a>
            </div>
        </form>
    </div>
</div>