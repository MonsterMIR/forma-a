<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-person-circle"></i> User: <?= e($user['username']) ?></h2>
    <div class="d-flex gap-2">
        <a href="/users/<?= (int)$user['id'] ?>/edit" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> <?= __('COMMON_EDIT') ?></a>
        <a href="/users" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
    </div>
</div>

<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4"><?= __('COMMON_ID') ?></dt>
            <dd class="col-sm-8"><?= (int)$user['id'] ?></dd>

            <dt class="col-sm-4"><?= __('USERS_FULL_NAME') ?></dt>
            <dd class="col-sm-8"><?= e($user['full_name']) ?></dd>

            <dt class="col-sm-4"><?= __('USERS_USERNAME') ?></dt>
            <dd class="col-sm-8"><code><?= e($user['username']) ?></code></dd>

            <dt class="col-sm-4"><?= __('USERS_EMAIL') ?></dt>
            <dd class="col-sm-8"><?= e($user['email'] ?? '—') ?></dd>

            <dt class="col-sm-4"><?= __('USERS_PHONE') ?></dt>
            <dd class="col-sm-8"><?= e($user['phone'] ?? '—') ?></dd>

            <dt class="col-sm-4"><?= __('USERS_ROLE') ?></dt>
            <dd class="col-sm-8"><span class="badge bg-secondary"><?= e($user['role_code']) ?></span> <?= e($user['role_name']) ?></dd>

            <dt class="col-sm-4"><?= __('COMMON_STATUS') ?></dt>
            <dd class="col-sm-8">
                <?php if ($user['is_active']): ?>
                    <span class="badge bg-success"><?= __('COMMON_ACTIVE') ?></span>
                <?php else: ?>
                    <span class="badge bg-danger"><?= __('COMMON_INACTIVE') ?></span>
                <?php endif; ?>
            </dd>

            <dt class="col-sm-4"><?= __('USERS_LAST_LOGIN') ?></dt>
            <dd class="col-sm-8"><?= format_datetime($user['last_login_at']) ?></dd>

            <dt class="col-sm-4"><?= __('COMMON_CREATED_AT') ?></dt>
            <dd class="col-sm-8"><?= format_datetime($user['created_at']) ?></dd>

            <dt class="col-sm-4"><?= __('COMMON_UPDATED_AT') ?></dt>
            <dd class="col-sm-8"><?= format_datetime($user['updated_at']) ?></dd>
        </dl>
    </div>
</div>