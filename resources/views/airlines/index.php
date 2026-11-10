<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-building"></i> <?= __('AIRLINES_TITLE') ?></h2>
    <?php if (has_role(['admin', 'cok'])): ?>
    <a href="/airlines/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= __('AIRLINES_CREATE') ?></a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="<?= __('AIRLINES_SEARCH_PLACEHOLDER') ?>" value="<?= e($filters['search']) ?>">
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
                <a href="/airlines" class="btn btn-sm btn-outline-secondary"><?= __('COMMON_RESET') ?></a>
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
                        <th><?= __('AIRPORTS_IATA') ?></th>
                        <th><?= __('AIRPORTS_ICAO') ?></th>
                        <th><?= __('COMMON_NAME') ?></th>
                        <th><?= __('COMMON_COUNTRY') ?></th>
                        <th><?= __('COMMON_STATUS') ?></th>
                        <th><?= __('COMMON_ACTIONS') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($airlines)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4"><?= __('AIRLINES_NO_DATA') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($airlines as $a): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$a['id'] ?></td>
                        <td><code><?= e($a['iata_code'] ?? '—') ?></code></td>
                        <td><code><?= e($a['icao_code'] ?? '—') ?></code></td>
                        <td><?= e($a['name']) ?></td>
                        <td><?= e($a['country'] ?? '—') ?></td>
                        <td><?= $a['is_active'] ? '<span class="badge bg-success">' . __('COMMON_ACTIVE') . '</span>' : '<span class="badge bg-secondary">' . __('COMMON_INACTIVE') . '</span>' ?></td>
                        <td>
                            <?php if (has_role(['admin', 'cok'])): ?>
                            <a href="/airlines/<?= (int)$a['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
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