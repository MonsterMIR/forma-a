<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-pencil-square"></i> <?= __('TARIFFS_EDIT') ?>: <?= e($tariff['tariff_name']) ?></h2>
    <a href="/tariffs/<?= (int)$tariff['id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
</div>

<div class="card border-0 shadow-sm" style="max-width:720px">
    <div class="card-body">
        <form method="POST" action="/tariffs/<?= (int)$tariff['id'] ?>/edit">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Service</label>
                    <input type="text" class="form-control" readonly value="<?= e($tariff['service_name']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('TARIFFS_NAME') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="tariff_name" class="form-control" required maxlength="150"
                           value="<?= e($tariff['tariff_name']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_ROUTE_TYPE') ?> <span class="text-danger">*</span></label>
                    <select name="route_type" class="form-select" required>
                        <option value="both" <?= $tariff['route_type'] === 'both' ? 'selected' : '' ?>><?= __('TARIFFS_BOTH') ?></option>
                        <option value="domestic" <?= $tariff['route_type'] === 'domestic' ? 'selected' : '' ?>><?= __('FLIGHTS_DOMESTIC') ?></option>
                        <option value="international" <?= $tariff['route_type'] === 'international' ? 'selected' : '' ?>><?= __('FLIGHTS_INTERNATIONAL') ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_OPERATION') ?> <span class="text-danger">*</span></label>
                    <select name="operation_type" class="form-select" required>
                        <option value="both" <?= $tariff['operation_type'] === 'both' ? 'selected' : '' ?>><?= __('TARIFFS_BOTH') ?></option>
                        <option value="arrival" <?= $tariff['operation_type'] === 'arrival' ? 'selected' : '' ?>><?= __('FLIGHTS_ARRIVAL') ?></option>
                        <option value="departure" <?= $tariff['operation_type'] === 'departure' ? 'selected' : '' ?>><?= __('FLIGHTS_DEPARTURE') ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('SERVICES_CURRENCY') ?> <span class="text-danger">*</span></label>
                    <select name="currency_id" class="form-select" required>
                        <?php foreach ($currencies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ($tariff['currency_id'] == $c['id']) ? 'selected' : '' ?>>
                            <?= e($c['code']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('TARIFFS_PRICE') ?> <span class="text-danger">*</span></label>
                    <input type="number" name="price" class="form-control" required min="0" step="0.0001"
                           value="<?= e($tariff['price']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('TARIFFS_VAT_RATE') ?></label>
                    <input type="number" name="vat_rate" class="form-control" min="0" max="100" step="0.01"
                           value="<?= e($tariff['vat_rate']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('TARIFFS_VALID_FROM') ?> <span class="text-danger">*</span></label>
                    <input type="date" name="valid_from" class="form-control" required value="<?= e($tariff['valid_from']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('TARIFFS_VALID_TO') ?> </label>
                    <input type="date" name="valid_to" class="form-control" value="<?= e($tariff['valid_to'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('COMMON_STATUS') ?> </label>
                    <select name="is_active" class="form-select">
                        <option value="1" <?= $tariff['is_active'] ? 'selected' : '' ?>><?= __('COMMON_ACTIVE') ?></option>
                        <option value="0" <?= !$tariff['is_active'] ? 'selected' : '' ?>><?= __('COMMON_INACTIVE') ?></option>
                    </select>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= __('COMMON_SAVE') ?> Changes</button>
                <a href="/tariffs/<?= (int)$tariff['id'] ?>" class="btn btn-outline-secondary"><?= __('COMMON_CANCEL') ?></a>
            </div>
        </form>
    </div>
</div>