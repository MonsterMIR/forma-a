<?php
// УПРОЩЕНО (21.09.2026, раунд 49, п.2, по прямому указанию пользователя):
// убран первичный (предварительный) манифест — теперь один манифест на рейс.
// Данные для формы: из существующего манифеста, либо из POST, либо пусто.
$src = $manifest ?? [];
$v = fn(string $f, mixed $def = '') => htmlspecialchars((string)($_POST[$f] ?? $src[$f] ?? $def));

// ДОБАВЛЕНО (12.10.2026, раунд 67, по прямому указанию пользователя):
// поданный манифест больше не редактируется — см. докблок в
// ManifestController::save(). Здесь просто отражаем это в форме: поля и
// кнопки отключаются, показывается замок вместо зелёной галочки.
$manifestLocked = !empty($manifest) && $manifest['status'] === 'submitted';
$disabledAttr   = $manifestLocked ? 'disabled' : '';
?>

<?php if (!empty($lockStatus)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5"></i>
    <div><?= e(__('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))])) ?></div>
</div>
<?php endif; ?>
<?php include BASE_PATH . '/resources/views/partials/pending_orders_banner.php'; ?>


<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0">
            <i class="bi bi-people-fill me-2"></i>Манифест
            <span class="badge bg-success ms-2 small">T−20 / T+20 мин</span>
        </h2>
        <div class="text-muted small">
            Рейс: <strong><?= e($flight['flight_number']) ?></strong>
            · <?= e($flight['flight_date']) ?>
            · <?= $flight['operation_type']==='arrival' ? '↓ Прилёт' : '↑ Вылет' ?>
            · <?= e($flight['airline_name']) ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="/flights/<?= (int)$flight['id'] ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> К рейсу
        </a>
    </div>
</div>

<?php if ($manifestLocked): ?>
<div class="alert alert-success d-flex align-items-center gap-2">
    <i class="bi bi-lock-fill fs-5"></i>
    <div>
        Манифест подан: <strong><?= e($manifest['submitted_at'] ?? '') ?></strong>
        <?= isset($manifest['pax_total']) ? "· Всего пассажиров: <strong>{$manifest['pax_total']}</strong>" : '' ?>
        <br><span class="small">Поданный манифест — официальный документ рейса, больше не редактируется. Нужна правка? Обратитесь в ЦОК.</span>
    </div>
</div>
<?php endif; ?>

<form method="POST" action="/manifests/save" style="max-width:760px">
    <?= csrf_field() ?>
    <input type="hidden" name="flight_id" value="<?= (int)$flight['id'] ?>">
    <input type="hidden" name="manifest_type" value="<?= e($type) ?>">

    <div class="row g-4">

        <!-- Пассажиры -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-people me-2 text-primary"></i>Пассажиры
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Взрослые (ADT)</label>
                            <input type="number" name="pax_adults" class="form-control"
                                   value="<?= $v('pax_adults', 0) ?>" <?= $disabledAttr ?> min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Дети 2–12 (CHD)</label>
                            <input type="number" name="pax_children" class="form-control"
                                   value="<?= $v('pax_children', 0) ?>" <?= $disabledAttr ?> min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Младенцы <2 (INF)</label>
                            <input type="number" name="pax_infants" class="form-control"
                                   value="<?= $v('pax_infants', 0) ?>" <?= $disabledAttr ?> min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Транзит (TRN)</label>
                            <input type="number" name="pax_transit" class="form-control"
                                   value="<?= $v('pax_transit', 0) ?>" <?= $disabledAttr ?> min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Экипаж (CRW)</label>
                            <input type="number" name="pax_crew" class="form-control"
                                   value="<?= $v('pax_crew', 0) ?>" <?= $disabledAttr ?> min="0">
                        </div>
                        <!-- ДОБАВЛЕНО (27.10.2026, раунд 130, по прямому указанию пользователя):
                             пассажиры кортежа (подъезд/отъезд от борта автоколонной, минуя
                             терминал) учитываются отдельно от обычных пассажиров — отдельное
                             поле манифеста, отдельная услуга в акте (сейчас по цене 0, см.
                             PAX_CORTEGE_ARR/PAX_CORTEGE_DEP), чтобы при появлении реального
                             тарифа ничего в этой форме/алгоритме менять не пришлось. -->
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Кортеж</label>
                            <input type="number" name="pax_cortege" class="form-control"
                                   value="<?= $v('pax_cortege', 0) ?>" <?= $disabledAttr ?> min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-muted">Итого PAX</label>
                            <div class="form-control bg-light fw-bold text-center" id="pax-total">
                                <?= ($src['pax_total'] ?? (($src['pax_adults'] ?? 0) + ($src['pax_children'] ?? 0) + ($src['pax_infants'] ?? 0) + ($src['pax_cortege'] ?? 0))) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Багаж и груз -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-luggage me-2 text-warning"></i>Багаж и груз
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Багаж (мест)</label>
                            <input type="number" name="baggage_pieces" class="form-control"
                                   value="<?= $v('baggage_pieces', 0) ?>" <?= $disabledAttr ?> min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Багаж (кг)</label>
                            <input type="number" name="baggage_weight_kg" class="form-control"
                                   value="<?= $v('baggage_weight_kg', 0) ?>" <?= $disabledAttr ?> min="0" step="0.1">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Ручная кладь (мест)</label>
                            <input type="number" name="carry_on_pieces" class="form-control"
                                   value="<?= $v('carry_on_pieces', 0) ?>" <?= $disabledAttr ?> min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Груз (кг)</label>
                            <input type="number" name="cargo_weight_kg" class="form-control"
                                   value="<?= $v('cargo_weight_kg', 0) ?>" <?= $disabledAttr ?> min="0" step="0.1">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Почта (кг)</label>
                            <input type="number" name="mail_weight_kg" class="form-control"
                                   value="<?= $v('mail_weight_kg', 0) ?>" <?= $disabledAttr ?> min="0" step="0.1">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Примечания -->
        <div class="col-12">
            <label class="form-label fw-semibold">Примечания</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="Особые отметки, VIP, WCHR, DEPU..." <?= $disabledAttr ?>><?= $v('notes') ?></textarea>
        </div>

    </div>

    <div class="mt-4 d-flex gap-2">
        <?php if ($manifestLocked): ?>
        <span class="text-muted small align-self-center">
            <i class="bi bi-lock-fill me-1"></i>Манифест подан и заблокирован для правки.
        </span>
        <?php else: ?>
        <button type="submit" name="action" value="draft" class="btn btn-outline-primary">
            <i class="bi bi-floppy me-1"></i>Сохранить черновик
        </button>
        <button type="submit" name="action" value="submit" class="btn btn-success"
                onclick="return confirm('Подтвердить подачу манифеста? После подачи манифест больше нельзя будет изменить.')">
            <i class="bi bi-check-circle me-1"></i>Подать манифест
        </button>
        <?php endif; ?>
        <a href="/flights/<?= (int)$flight['id'] ?>" class="btn btn-outline-secondary ms-auto">Отмена</a>
    </div>
</form>

<script>
// Авторасчёт итого PAX
const fields = ['pax_adults','pax_children','pax_infants','pax_cortege'];
function updateTotal() {
    const total = fields.reduce((s, f) => s + (parseInt(document.querySelector(`[name="${f}"]`)?.value) || 0), 0);
    document.getElementById('pax-total').textContent = total;
}
fields.forEach(f => document.querySelector(`[name="${f}"]`)?.addEventListener('input', updateTotal));
</script>

<?php include __DIR__ . '/../flights/_service_charges.php'; ?>
