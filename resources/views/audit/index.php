<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-journal-text"></i> <?= __('AUDIT_TITLE') ?></h2>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-semibold">User</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value=""><?= __('AUDIT_ALL_USERS') ?></option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $filters['user_id'] == $u['id'] ? 'selected' : '' ?>>
                        <?= e($u['username']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Entity Type</label>
                <input type="text" name="entity_type" class="form-control form-control-sm"
                       placeholder="e.g. act, user…" value="<?= e($filters['entity_type']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Action</label>
                <input type="text" name="action" class="form-control form-control-sm"
                       placeholder="e.g. login, create_act…" value="<?= e($filters['action']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Result</label>
                <select name="action_result" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="success" <?= $filters['action_result'] === 'success' ? 'selected' : '' ?>><?= __('AUDIT_SUCCESS') ?></option>
                    <option value="failure" <?= $filters['action_result'] === 'failure' ? 'selected' : '' ?>><?= __('AUDIT_FAILURE') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Date From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Date To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold"><?= __('COMMON_SEARCH') ?></label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="<?= __('AUDIT_SEARCH_PLACEHOLDER') ?>" value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-auto d-flex gap-2 mt-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> <?= __('COMMON_FILTER') ?></button>
                <a href="/audit" class="btn btn-sm btn-outline-secondary"><?= __('COMMON_RESET') ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th><?= __('AUDIT_WHEN') ?></th>
                        <th><?= __('AUDIT_USER') ?></th>
                        <th><?= __('AUDIT_ENTITY_COL') ?></th>
                        <th>Entity ID</th>
                        <th><?= __('AUDIT_ACTION') ?></th>
                        <th><?= __('AUDIT_RESULT') ?></th>
                        <th><?= __('AUDIT_IP') ?></th>
                        <th><?= __('COMMON_DESCRIPTION') ?></th>
                        <th><?= __('AUDIT_CHANGES') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4"><?= __('AUDIT_NO_DATA') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($records as $r): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td class="small text-muted text-nowrap"><?= format_datetime($r['created_at']) ?></td>
                        <td class="small"><?= e($r['username'] ?? '—') ?></td>
                        <td><span class="badge bg-light text-dark border small"><?= e($r['entity_type']) ?></span></td>
                        <td class="small text-muted"><?= $r['entity_id'] ? (int)$r['entity_id'] : '—' ?></td>
                        <td><code class="small"><?= e($r['action']) ?></code></td>
                        <td>
                            <?php if ($r['action_result'] === 'success'): ?>
                                <span class="badge bg-success">ok</span>
                            <?php else: ?>
                                <span class="badge bg-danger">fail</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= e($r['ip_address'] ?? '—') ?></td>
                        <td class="small text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                            title="<?= e($r['description'] ?? '') ?>">
                            <?= e(mb_substr($r['description'] ?? '', 0, 60)) ?>
                        </td>
                        <td class="small">
                            <?php if ($r['old_values'] || $r['new_values']): ?>
                            <button class="btn btn-xs btn-outline-secondary py-0 px-1 small"
                                    data-bs-toggle="modal" data-bs-target="#changesModal"
                                    data-old="<?= e($r['old_values'] ?? '') ?>"
                                    data-new="<?= e($r['new_values'] ?? '') ?>">
                                <i class="bi bi-eye"></i>
                            </button>
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

<!-- Changes Modal -->
<div class="modal fade" id="changesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <h6 class="text-muted">Old Values</h6>
                        <pre class="bg-light p-2 rounded small" id="oldValuesDisplay" style="max-height:300px;overflow:auto"></pre>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">New Values</h6>
                        <pre class="bg-light p-2 rounded small" id="newValuesDisplay" style="max-height:300px;overflow:auto"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('changesModal').addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    const old = btn.getAttribute('data-old');
    const nw  = btn.getAttribute('data-new');
    const fmt = v => { try { return JSON.stringify(JSON.parse(v), null, 2); } catch { return v || '—'; } };
    document.getElementById('oldValuesDisplay').textContent = fmt(old);
    document.getElementById('newValuesDisplay').textContent = fmt(nw);
});
</script>