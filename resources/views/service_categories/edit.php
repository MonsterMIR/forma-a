<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-pencil-square"></i> <?= __('SCAT_EDIT') ?>: <?= e($category['name']) ?></h2>
    <a href="/service-categories" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
</div>

<div class="card border-0 shadow-sm" style="max-width:580px">
    <div class="card-body">
        <form method="POST" action="/service-categories/<?= (int)$category['id'] ?>/edit">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold"><?= __('COMMON_CODE') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control" required maxlength="50"
                           style="text-transform:uppercase" value="<?= e($category['code']) ?>">
                </div>
                <div class="col-md-7">
                    <label class="form-label fw-semibold"><?= __('COMMON_NAME') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="120"
                           value="<?= e($category['name']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= __('COMMON_DESCRIPTION') ?> </label>
                    <input type="text" name="description" class="form-control" maxlength="255"
                           value="<?= e($category['description'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold"><?= __('COMMON_SORT_ORDER') ?> </label>
                    <input type="number" name="sort_order" class="form-control" min="0" value="<?= (int)$category['sort_order'] ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold"><?= __('COMMON_STATUS') ?> </label>
                    <select name="is_active" class="form-select">
                        <option value="1" <?= $category['is_active'] ? 'selected' : '' ?>><?= __('COMMON_ACTIVE') ?></option>
                        <option value="0" <?= !$category['is_active'] ? 'selected' : '' ?>><?= __('COMMON_INACTIVE') ?></option>
                    </select>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= __('COMMON_SAVE') ?></button>
                <a href="/service-categories" class="btn btn-outline-secondary"><?= __('COMMON_CANCEL') ?></a>
            </div>
        </form>
    </div>
</div>