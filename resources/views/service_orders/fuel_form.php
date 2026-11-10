<div class="mb-3">
    <a href="/supervisor/flights/<?= (int)$flight['id'] ?>" class="text-muted text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i>Назад к рейсу
    </a>
    <h2 class="mb-0 mt-1"><i class="bi bi-fuel-pump-fill me-2"></i>Заправка ВС</h2>
    <div class="text-muted"><?= e($flight['flight_number'] ?? '') ?></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" action="/supervisor/orders/<?= (int)$order['id'] ?>/fuel">
            <?= csrf_field() ?>

            <div class="mb-4">
                <label class="form-label fw-semibold fs-5">Марка/сорт топлива <span class="text-muted fw-normal">(необязательно)</span></label>
                <input type="text" name="fuel_grade" class="form-control form-control-lg"
                       value="<?= e($details['fuel_grade'] ?? '') ?>" placeholder="напр. Jet A-1">
            </div>

            <div class="row g-3 mb-4">
                <div class="col-6">
                    <label class="form-label fw-semibold fs-5">Литры <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" min="0.01" name="liters" id="liters"
                           class="form-control form-control-lg fuel-input" style="font-size:1.5rem; padding:1rem;"
                           value="<?= e($details['liters'] ?? '') ?>" required inputmode="decimal">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold fs-5">Плотность, кг/л <span class="text-danger">*</span></label>
                    <input type="number" step="0.0001" min="0.01" name="density" id="density"
                           class="form-control form-control-lg fuel-input" style="font-size:1.5rem; padding:1rem;"
                           value="<?= e($details['density'] ?? '0.7850') ?>" required inputmode="decimal">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold fs-5">Температура, °C <span class="text-muted fw-normal">(необязательно)</span></label>
                <input type="number" step="0.1" name="temperature" id="temperature"
                       class="form-control form-control-lg" style="font-size:1.3rem; padding:.85rem;"
                       value="<?= e($details['temperature'] ?? '') ?>" inputmode="decimal">
            </div>

            <div class="alert alert-primary d-flex justify-content-between align-items-center fs-4 mb-4">
                <span>Итого:</span>
                <strong id="tonnes-preview">
                    <?= isset($details['computed_tonnes']) ? number_format((float)$details['computed_tonnes'], 4) : '0.0000' ?> т
                </strong>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100 py-3 fs-5">
                <i class="bi bi-check-lg me-1"></i>Сохранить заправку
            </button>
        </form>
    </div>
</div>

<script>
function recalcTonnes() {
    const liters  = parseFloat(document.getElementById('liters').value)  || 0;
    const density = parseFloat(document.getElementById('density').value) || 0;
    const tonnes  = (liters * density / 1000);
    document.getElementById('tonnes-preview').textContent = tonnes.toFixed(4) + ' т';
}
document.getElementById('liters').addEventListener('input', recalcTonnes);
document.getElementById('density').addEventListener('input', recalcTonnes);
recalcTonnes();
</script>

