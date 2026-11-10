<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-plus-circle"></i> <?= __('SCAT_CREATE') ?></h2>
    <a href="/service-categories" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
</div>

<div class="card border-0 shadow-sm" style="max-width:580px">
    <div class="card-body">
        <form method="POST" action="/service-categories/create">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold"><?= __('COMMON_CODE') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control" required maxlength="50"
                           style="text-transform:uppercase" value="<?= e($_POST['code'] ?? '') ?>">
                    <div class="form-text">Unique identifier, e.g. GROUND</div>
                </div>
                <div class="col-md-7">
                    <label class="form-label fw-semibold"><?= __('COMMON_NAME') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="120"
                           value="<?= e($_POST['name'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= __('COMMON_DESCRIPTION') ?> </label>
                    <input type="text" name="description" class="form-control" maxlength="255"
                           value="<?= e($_POST['description'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold"><?= __('COMMON_SORT_ORDER') ?> </label>
                    <input type="number" name="sort_order" class="form-control" min="0" value="<?= e($_POST['sort_order'] ?? 0) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold"><?= __('COMMON_STATUS') ?> </label>
                    <select name="is_active" class="form-select">
                        <option value="1"><?= __('COMMON_ACTIVE') ?></option>
                        <option value="0"><?= __('COMMON_INACTIVE') ?></option>
                    </select>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= __('COMMON_CREATE') ?></button>
                <a href="/service-categories" class="btn btn-outline-secondary"><?= __('COMMON_CANCEL') ?></a>
            </div>
        </form>
    </div>
</div>