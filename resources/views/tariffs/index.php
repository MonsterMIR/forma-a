<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-currency-dollar"></i> <?= __('TARIFFS_TITLE') ?></h2>
    <?php if (\App\Core\Permissions::can('tariffs.manage')): ?>
    <a href="/tariffs/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= __('TARIFFS_CREATE') ?></a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <select name="service_id" class="form-select form-select-sm">
                    <option value=""><?= __('TARIFFS_ALL_SERVICES') ?></option>
                    <?php foreach ($services as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $filters['service_id'] == $s['id'] ? 'selected' : '' ?>>
                        <?= e($s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="route_type" class="form-select form-select-sm">
                    <option value=""><?= __('ACTS_ALL_ROUTES') ?></option>
                    <option value="domestic" <?= $filters['route_type'] === 'domestic' ? 'selected' : '' ?>><?= __('FLIGHTS_DOMESTIC') ?></option>
                    <option value="international" <?= $filters['route_type'] === 'international' ? 'selected' : '' ?>><?= __('FLIGHTS_INTERNATIONAL') ?></option>
                    <option value="both" <?= $filters['route_type'] === 'both' ? 'selected' : '' ?>><?= __('TARIFFS_BOTH') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="operation_type" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="arrival" <?= $filters['operation_type'] === 'arrival' ? 'selected' : '' ?>><?= __('FLIGHTS_ARRIVAL') ?></option>
                    <option value="departure" <?= $filters['operation_type'] === 'departure' ? 'selected' : '' ?>><?= __('FLIGHTS_DEPARTURE') ?></option>
                    <option value="both" <?= $filters['operation_type'] === 'both' ? 'selected' : '' ?>><?= __('TARIFFS_BOTH') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="is_active" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="1" <?= $filters['is_active'] === '1' ? 'selected' : '' ?>><?= __('COMMON_ACTIVE') ?></option>
                    <option value="0" <?= $filters['is_active'] === '0' ? 'selected' : '' ?>><?= __('COMMON_INACTIVE') ?></option>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
                <a href="/tariffs" class="btn btn-sm btn-outline-secondary"><?= __('COMMON_RESET') ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th><?= __('COMMON_NAME') ?></th>
                        <th><?= __('ACTS_SERVICE') ?></th>
                        <th><?= __('TARIFFS_PRICE') ?></th>
                        <th><?= __('ACTS_VAT_PCT') ?></th>
                        <th><?= __('FLIGHTS_ROUTE_TYPE') ?></th>
                        <th><?= __('FILES_OP_TYPE') ?></th>
                        <th><?= __('TARIFFS_VALID_FROM') ?></th>
                        <th><?= __('TARIFFS_VALID_TO') ?></th>
                        <th><?= __('COMMON_STATUS') ?></th>
                        <th><?= __('COMMON_ACTIONS') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($tariffs)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4"><?= __('TARIFFS_NO_DATA') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($tariffs as $t): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$t['id'] ?></td>
                        <td><a href="/tariffs/<?= (int)$t['id'] ?>" class="text-decoration-none"><?= e($t['tariff_name']) ?></a></td>
                        <td class="small"><?= e($t['service_name']) ?></td>
                        <td class="text-end"><?= format_money($t['price']) ?> <code class="small"><?= e($t['currency_code']) ?></code></td>
                        <td class="text-end"><?= e($t['vat_rate']) ?>%</td>
                        <td class="small"><?= e($t['route_type']) ?></td>
                        <td class="small"><?= e($t['operation_type']) ?></td>
                        <td class="small"><?= format_date($t['valid_from']) ?></td>
                        <td class="small"><?= $t['valid_to'] ? format_date($t['valid_to']) : '∞' ?></td>
                        <td><?= $t['is_active'] ? '<span class="badge bg-success">' . __('COMMON_ACTIVE') . '</span>' : '<span class="badge bg-secondary">' . __('COMMON_INACTIVE') . '</span>' ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="/tariffs/<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                                <?php if (\App\Core\Permissions::can('tariffs.manage')): ?>
                                <a href="/tariffs/<?= (int)$t['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pagination['last_page'] > 1): ?>
    <div class="card-footer bg-white">
        <?php include BASE_PATH . '/resources/views/layouts/_pagination.php'; ?>
    </div>
    <?php endif; ?>
</div>