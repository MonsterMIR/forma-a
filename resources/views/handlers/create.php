<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-plus-circle"></i> Новый хендлер</h2>
    <a href="/handlers" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
</div>

<div class="card border-0 shadow-sm" style="max-width:700px">
    <div class="card-body">
        <form method="POST" action="/handlers/create">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Название <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="200"
                           value="<?= e($_POST['name'] ?? '') ?>" placeholder="ООО «Хендлер-Сервис»">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Статус</label>
                    <select name="is_active" class="form-select">
                        <option value="1">Активен</option>
                        <option value="0">Неактивен</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Примечание</label>
                    <input type="text" name="note" class="form-control" maxlength="255"
                           value="<?= e($_POST['note'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Обслуживает авиакомпании</label>
                    <select name="airline_ids[]" class="form-select" multiple size="8">
                        <?php foreach ($airlines as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?><?= $a['iata_code'] ? ' (' . e($a['iata_code']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Удерживайте Ctrl (Cmd на Mac), чтобы выбрать несколько. Список сотрудников добавляется после сохранения.</div>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Создать</button>
                <a href="/handlers" class="btn btn-outline-secondary">Отмена</a>
            </div>
        </form>
    </div>
</div>
