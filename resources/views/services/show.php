<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-list-check"></i> Service: <?= e($service['name']) ?></h2>
    <div class="d-flex gap-2">
        <?php if (has_role(['admin'])): ?>
        <a href="/services/<?= (int)$service['id'] ?>/edit" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> <?= __('COMMON_EDIT') ?></a>
        <?php endif; ?>
        <a href="/tariffs/create?service_id=<?= (int)$service['id'] ?>" class="btn btn-outline-success"><i class="bi bi-plus-lg"></i> <?= __('TARIFFS_CREATE') ?></a>
        <a href="/services" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><h6 class="mb-0 fw-semibold">Service Details</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Code</dt>
                    <dd class="col-sm-7"><code><?= e($service['code']) ?></code></dd>
                    <dt class="col-sm-5"><?= __('COMMON_NAME') ?></dt>
                    <dd class="col-sm-7"><?= e($service['name']) ?></dd>
                    <dt class="col-sm-5">Category</dt>
                    <dd class="col-sm-7"><?= e($service['category_name']) ?></dd>
                    <dt class="col-sm-5">Unit</dt>
                    <dd class="col-sm-7"><?= e($service['unit_name']) ?></dd>
                    <dt class="col-sm-5">Currency</dt>
                    <dd class="col-sm-7"><code><?= e($service['currency_code']) ?></code> <?= e($service['currency_symbol'] ?? '') ?></dd>
                    <dt class="col-sm-5">VAT Domestic</dt>
                    <dd class="col-sm-7"><?= $service['vat_applicable_domestic'] ? '<span class="badge bg-success">' . __('COMMON_YES') . '</span>' : '<span class="badge bg-secondary">' . __('COMMON_NO') . '</span>' ?></dd>
                    <dt class="col-sm-5">VAT Intl.</dt>
                    <dd class="col-sm-7"><?= $service['vat_applicable_international'] ? '<span class="badge bg-success">' . __('COMMON_YES') . '</span>' : '<span class="badge bg-secondary">' . __('COMMON_NO') . '</span>' ?></dd>
                    <dt class="col-sm-5"><?= __('COMMON_STATUS') ?></dt>
                    <dd class="col-sm-7"><?= $service['is_active'] ? '<span class="badge bg-success">' . __('COMMON_ACTIVE') . '</span>' : '<span class="badge bg-secondary">' . __('COMMON_INACTIVE') . '</span>' ?></dd>
                    <?php if ($service['description']): ?>
                    <dt class="col-sm-5">Description</dt>
                    <dd class="col-sm-7 small"><?= e($service['description']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">Tariff Versions</h6>
                <?php if (has_role(['admin'])): ?>
                <a href="/tariffs/create?service_id=<?= (int)$service['id'] ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-plus-lg"></i></a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?= __('COMMON_NAME') ?></th>
                                <th><?= __('TARIFFS_PRICE') ?></th>
                                <th><?= __('ACTS_VAT_PCT') ?></th>
                                <th><?= __('FLIGHTS_ROUTE_TYPE') ?></th>
                                <th>Op.</th>
                                <th><?= __('TARIFFS_VALID_FROM') ?></th>
                                <th><?= __('TARIFFS_VALID_TO') ?></th>
                                <th>Active</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($tariffs)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">No tariffs yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($tariffs as $t): ?>
                            <tr>
                                <td><a href="/tariffs/<?= (int)$t['id'] ?>" class="text-decoration-none small"><?= e($t['tariff_name']) ?></a></td>
                                <td class="text-end small"><?= format_money($t['price']) ?> <code><?= e($t['currency_code']) ?></code></td>
                                <td class="text-end small"><?= e($t['vat_rate']) ?>%</td>
                                <td class="small"><?= e($t['route_type']) ?></td>
                                <td class="small"><?= e($t['operation_type']) ?></td>
                                <td class="small"><?= format_date($t['valid_from']) ?></td>
                                <td class="small"><?= $t['valid_to'] ? format_date($t['valid_to']) : '∞' ?></td>
                                <td><?= $t['is_active'] ? '<span class="badge bg-success">' . __('COMMON_YES') . '</span>' : '<span class="badge bg-secondary">' . __('COMMON_NO') . '</span>' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>