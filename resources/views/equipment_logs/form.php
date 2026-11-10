<?php if (!empty($lockStatus)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5"></i>
    <div><?= e(__('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))])) ?></div>
</div>
<?php endif; ?>
<?php include BASE_PATH . '/resources/views/partials/pending_orders_banner.php'; ?>


<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><i class="bi bi-truck me-2"></i>Журнал спецтехники</h2>
        <div class="text-muted small">
            Рейс: <strong><?= e($flight['flight_number']) ?></strong>
            · <?= e($flight['flight_date']) ?>
            · <?= $flight['operation_type']==='arrival'?'↓ Прилёт':'↑ Вылет' ?>
            · <?= e($flight['airline_name']) ?>
        </div>
    </div>
    <a href="/flights/<?= (int)$flight['id'] ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> К рейсу
    </a>
</div>

<?php if (!empty($log) && $log['status']==='submitted'): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle me-2"></i>
    Журнал подан <?= e($log['submitted_at'] ?? '') ?>
</div>
<?php endif; ?>

<form method="POST" action="/equipment-logs/save">
    <?= csrf_field() ?>
    <input type="hidden" name="flight_id" value="<?= (int)$flight['id'] ?>">

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-list-ul me-2"></i>Записи о технике</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-row">
                <i class="bi bi-plus-circle me-1"></i>Добавить технику
            </button>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0" id="eq-table">
                <thead class="table-light">
                    <tr>
                        <th style="min-width:180px">Тип техники</th>
                        <th style="min-width:120px">Гос. номер</th>
                        <th style="min-width:150px">Водитель/Оператор</th>
                        <th>Подача</th>
                        <th>Убытие</th>
                        <th>Выполнено</th>
                        <th>Примечание</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="eq-tbody">
                <?php
                $rows = !empty($logItems) ? $logItems : [[]];
                foreach ($rows as $i => $row):
                ?>
                <tr class="eq-row">
                    <td>
                        <select name="eq_type[]" class="form-select form-select-sm">
                            <option value="">— выберите —</option>
                            <?php foreach ($equipmentTypes as $code => $label): ?>
                            <option value="<?= e($code) ?>"
                                <?= (($row['equipment_type'] ?? '') === $code) ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="eq_number[]" class="form-control form-control-sm"
                               value="<?= e($row['equipment_number'] ?? '') ?>" placeholder="номер"></td>
                    <td><input type="text" name="eq_operator[]" class="form-control form-control-sm"
                               value="<?= e($row['operator_name'] ?? '') ?>" placeholder="ФИО"></td>
                    <td><input type="time" name="eq_arrival[]" class="form-control form-control-sm"
                               value="<?= e($row['arrival_time'] ?? '') ?>"></td>
                    <td><input type="time" name="eq_departure[]" class="form-control form-control-sm"
                               value="<?= e($row['departure_time'] ?? '') ?>"></td>
                    <td class="text-center">
                        <input type="checkbox" name="eq_done[<?= $i ?>]" value="1" class="form-check-input"
                               <?= !empty($row['operation_done']) ? 'checked' : '' ?>>
                    </td>
                    <td><input type="text" name="eq_notes[]" class="form-control form-control-sm"
                               value="<?= e($row['notes'] ?? '') ?>" placeholder="заметка"></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Примечания к журналу</label>
        <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($log['notes'] ?? '') ?></textarea>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" name="action" value="draft" class="btn btn-outline-primary">
            <i class="bi bi-floppy me-1"></i>Сохранить черновик
        </button>
        <button type="submit" name="action" value="submit" class="btn btn-success"
                onclick="return confirm('Подтвердить подачу журнала спецтехники?')">
            <i class="bi bi-check-circle me-1"></i>Подать журнал
        </button>
        <a href="/flights/<?= (int)$flight['id'] ?>" class="btn btn-outline-secondary ms-auto">Отмена</a>
    </div>
</form>

<script>
// Шаблон строки
const equipTypes = <?= json_encode(array_map(null, array_keys($equipmentTypes), array_values($equipmentTypes))) ?>;
let rowIndex = <?= count($rows) ?>;

function makeOptions() {
    return '<option value="">— выберите —</option>' +
        equipTypes.map(([code, label]) => `<option value="${code}">${label}</option>`).join('');
}

document.getElementById('btn-add-row').addEventListener('click', function() {
    const tr = document.createElement('tr');
    tr.className = 'eq-row';
    tr.innerHTML = `
        <td><select name="eq_type[]" class="form-select form-select-sm">${makeOptions()}</select></td>
        <td><input type="text" name="eq_number[]" class="form-control form-control-sm" placeholder="номер"></td>
        <td><input type="text" name="eq_operator[]" class="form-control form-control-sm" placeholder="ФИО"></td>
        <td><input type="time" name="eq_arrival[]" class="form-control form-control-sm"></td>
        <td><input type="time" name="eq_departure[]" class="form-control form-control-sm"></td>
        <td class="text-center"><input type="checkbox" name="eq_done[${rowIndex}]" value="1" class="form-check-input" checked></td>
        <td><input type="text" name="eq_notes[]" class="form-control form-control-sm" placeholder="заметка"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row"><i class="bi bi-trash"></i></button></td>
    `;
    document.getElementById('eq-tbody').appendChild(tr);
    rowIndex++;
});

document.getElementById('eq-tbody').addEventListener('click', function(e) {
    if (e.target.closest('.btn-remove-row')) {
        e.target.closest('tr').remove();
    }
});
</script>

<?php include __DIR__ . '/../flights/_service_charges.php'; ?>
