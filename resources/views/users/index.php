<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-people"></i> <?= __('USERS_TITLE') ?></h2>
    <a href="/users/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add User</a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="/users" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold"><?= __('COMMON_SEARCH') ?></label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="<?= __('USERS_SEARCH_PLACEHOLDER') ?>" value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold"><?= __('USERS_ROLE') ?> </label>
                <select name="role_id" class="form-select form-select-sm">
                    <option value=""><?= __('USERS_ALL_ROLES') ?></option>
                    <?php foreach ($roles as $role): ?>
                    <option value="<?= (int)$role['id'] ?>" <?= $filters['role_id'] == $role['id'] ? 'selected' : '' ?>>
                        <?= e($role['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('COMMON_STATUS') ?> </label>
                <select name="is_active" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="1" <?= $filters['is_active'] === '1' ? 'selected' : '' ?>><?= __('COMMON_ACTIVE') ?></option>
                    <option value="0" <?= $filters['is_active'] === '0' ? 'selected' : '' ?>><?= __('COMMON_INACTIVE') ?></option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> <?= __('COMMON_SEARCH') ?></button>
                <a href="/users" class="btn btn-sm btn-outline-secondary"><?= __('COMMON_RESET') ?></a>
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
                        <th><?= __('USERS_FULL_NAME') ?></th>
                        <th><?= __('USERS_USERNAME') ?></th>
                        <th><?= __('USERS_EMAIL') ?></th>
                        <th><?= __('USERS_ROLE') ?></th>
                        <th><?= __('COMMON_STATUS') ?></th>
                        <th><?= __('USERS_LAST_LOGIN') ?></th>
                        <th><?= __('COMMON_ACTIONS') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4"><?= __('USERS_NO_DATA') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$user['id'] ?></td>
                        <td><a href="/users/<?= (int)$user['id'] ?>" class="text-decoration-none"><?= e($user['full_name']) ?></a></td>
                        <td><code><?= e($user['username']) ?></code></td>
                        <td><?= e($user['email'] ?? '—') ?></td>
                        <td><span class="badge bg-<?= \App\Core\Permissions::roleBadgeColor($user['role_code']) ?>"><i class="bi <?= \App\Core\Permissions::roleIcon($user['role_code']) ?> me-1"></i><?= e($user['role_name'] ?? $user['role_code']) ?></span></td>
                        <td>
                            <?php if ($user['is_active']): ?>
                                <span class="badge bg-success"><?= __('COMMON_ACTIVE') ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?= __('COMMON_INACTIVE') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= format_datetime($user['last_login_at']) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="/users/<?= (int)$user['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                <form method="POST" action="/users/<?= (int)$user['id'] ?>/toggle" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm <?= $user['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                            onclick="return confirm(__('USERS_TOGGLE_CONFIRM'))">
                                        <i class="bi <?= $user['is_active'] ? 'bi-person-slash' : 'bi-person-check' ?>"></i>
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
    <?php if ($pagination['last_page'] > 1): ?>
    <div class="card-footer bg-white">
        <?php include BASE_PATH . '/resources/views/layouts/_pagination.php'; ?>
    </div>
    <?php endif; ?>
</div>