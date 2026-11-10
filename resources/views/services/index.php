<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-list-check"></i> <?= __('SERVICES_TITLE') ?></h2>
    <?php if (has_role(['admin'])): ?>
    <a href="/services/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= __('SERVICES_CREATE') ?></a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="<?= __('SERVICES_SEARCH_PLACEHOLDER') ?>" value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-3">
                <select name="category_id" class="form-select form-select-sm">
                    <option value=""><?= __('SERVICES_ALL_CATEGORIES') ?></option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= $filters['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                        <?= e($cat['name']) ?>
                    </option>
                    <?php endforeach; ?>
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
                <a href="/services" class="btn btn-sm btn-outline-secondary"><?= __('COMMON_RESET') ?></a>
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
                        <th><?= __('COMMON_CODE') ?></th>
                        <th><?= __('COMMON_NAME') ?></th>
                        <th><?= __('SERVICES_CATEGORY_COL') ?></th>
                        <th><?= __('SERVICES_UNIT_COL') ?></th>
                        <th><?= __('SERVICES_CURRENCY_COL') ?></th>
                        <th>VAT Dom.</th>
                        <th>VAT Intl.</th>
                        <th><?= __('COMMON_STATUS') ?></th>
                        <th><?= __('COMMON_ACTIONS') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($services)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4"><?= __('SERVICES_NO_DATA') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($services as $s): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$s['id'] ?></td>
                        <td><code><?= e($s['code']) ?></code></td>
                        <td><a href="/services/<?= (int)$s['id'] ?>" class="text-decoration-none"><?= e($s['name']) ?></a></td>
                        <td class="small"><?= e($s['category_name']) ?></td>
                        <td class="small"><?= e($s['unit_name']) ?></td>
                        <td><code><?= e($s['currency_code']) ?></code></td>
                        <td class="text-center"><?= $s['vat_applicable_domestic'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>' ?></td>
                        <td class="text-center"><?= $s['vat_applicable_international'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>' ?></td>
                        <td><?= $s['is_active'] ? '<span class="badge bg-success">' . __('COMMON_ACTIVE') . '</span>' : '<span class="badge bg-secondary">' . __('COMMON_INACTIVE') . '</span>' ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="/services/<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                                <?php if (has_role(['admin'])): ?>
                                <a href="/services/<?= (int)$s['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
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