<?php
/**
 * ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): редактирование данных своего
 * представителя. Логин не меняется (генерируется один раз при создании),
 * пароль меняется только через отдельную кнопку "Сбросить пароль" в
 * списке (см. dashboard.php) — здесь только обычные данные.
 */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil-square"></i> Представитель: <?= e($employee['full_name']) ?></h4>
    <a href="/handler-cabinet" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
</div>

<div class="card border-0 shadow-sm" style="max-width: 560px;">
    <div class="card-body">
        <form method="POST" action="/handler-cabinet/representatives/<?= (int)$employee['id'] ?>/edit">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">Логин</label>
                <input type="text" class="form-control" value="<?= e($employee['login_username'] ?? '—') ?>" disabled>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">ФИО <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control" required maxlength="150"
                       value="<?= e($employee['full_name']) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Должность</label>
                <input type="text" name="position" class="form-control" maxlength="150"
                       value="<?= e($employee['position'] ?? '') ?>">
                <div class="form-text">Появится в акте рядом с подписью — автоматически, без ручного ввода на планшете.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Телефон</label>
                <input type="text" name="phone" class="form-control" maxlength="30"
                       value="<?= e($employee['phone'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Email</label>
                <input type="email" name="email" class="form-control" maxlength="150"
                       value="<?= e($employee['email'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Сохранить</button>
        </form>
    </div>
</div>
