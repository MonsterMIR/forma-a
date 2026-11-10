<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-tags"></i> <?= __('SCAT_TITLE') ?></h2>
    <?php if (has_role(['admin'])): ?>
    <a href="/service-categories/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= __('SCAT_CREATE') ?></a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Search code, name…" value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-3">
                <select name="is_active" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="1" <?= $filters['is_active'] === '1' ? 'selected' : '' ?>><?= __('COMMON_ACTIVE') ?></option>
                    <option value="0" <?= $filters['is_active'] === '0' ? 'selected' : '' ?>><?= __('COMMON_INACTIVE') ?></option>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> <?= __('COMMON_SEARCH') ?></button>
                <a href="/service-categories" class="btn btn-sm btn-outline-secondary"><?= __('COMMON_RESET') ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th><?= __('COMMON_CODE') ?></th>
                    <th><?= __('COMMON_NAME') ?></th>
                    <th><?= __('COMMON_DESCRIPTION') ?></th>
                    <th><?= __('COMMON_SORT_ORDER') ?></th>
                    <th><?= __('COMMON_STATUS') ?></th>
                    <th><?= __('COMMON_ACTIONS') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($categories)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><?= __('SCAT_NO_DATA') ?></td></tr>
            <?php else: ?>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td class="text-muted small"><?= (int)$cat['id'] ?></td>
                    <td><code><?= e($cat['code']) ?></code></td>
                    <td><?= e($cat['name']) ?></td>
                    <td class="text-muted small"><?= e($cat['description'] ?? '—') ?></td>
                    <td><?= (int)$cat['sort_order'] ?></td>
                    <td><?= $cat['is_active'] ? '<span class="badge bg-success">' . __('COMMON_ACTIVE') . '</span>' : '<span class="badge bg-secondary">' . __('COMMON_INACTIVE') . '</span>' ?></td>
                    <td>
                        <?php if (has_role(['admin'])): ?>
                        <a href="/service-categories/<?= (int)$cat['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pagination['last_page'] > 1): ?>
    <div class="card-footer bg-white">
        <?php include BASE_PATH . '/resources/views/layouts/_pagination.php'; ?>
    </div>
    <?php endif; ?>
</div>