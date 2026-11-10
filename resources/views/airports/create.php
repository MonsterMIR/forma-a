<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-plus-circle"></i> <?= __('AIRPORTS_CREATE') ?></h2>
    <a href="/airports" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
</div>

<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-body">
        <form method="POST" action="/airports/create">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= __('COMMON_NAME') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="150"
                           value="<?= e($_POST['name'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('AIRPORTS_IATA') ?> </label>
                    <input type="text" name="iata_code" class="form-control" maxlength="3"
                           style="text-transform:uppercase" value="<?= e($_POST['iata_code'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('AIRPORTS_ICAO') ?> </label>
                    <input type="text" name="icao_code" class="form-control" maxlength="4"
                           style="text-transform:uppercase" value="<?= e($_POST['icao_code'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('COMMON_CITY') ?> </label>
                    <input type="text" name="city" class="form-control" maxlength="100"
                           value="<?= e($_POST['city'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('COMMON_COUNTRY') ?> </label>
                    <input type="text" name="country" class="form-control" maxlength="100"
                           value="<?= e($_POST['country'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('COMMON_STATUS') ?> </label>
                    <select name="is_active" class="form-select">
                        <option value="1"><?= __('COMMON_ACTIVE') ?></option>
                        <option value="0"><?= __('COMMON_INACTIVE') ?></option>
                    </select>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= __('COMMON_CREATE') ?></button>
                <a href="/airports" class="btn btn-outline-secondary"><?= __('COMMON_CANCEL') ?></a>
            </div>
        </form>
    </div>
</div>