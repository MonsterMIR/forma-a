<?php
/**
 * ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): форма добавления представителя.
 * Пароль и логин вводить не нужно — система генерирует их сама (см.
 * HandlerCabinetController::store()) и покажет один раз на следующем
 * экране.
 */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-person-plus"></i> Новый представитель</h4>
    <a href="/handler-cabinet" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
</div>

<div class="card border-0 shadow-sm" style="max-width: 560px;">
    <div class="card-body">
        <form method="POST" action="/handler-cabinet/representatives/create">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">ФИО <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control" required maxlength="150" autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Должность</label>
                <input type="text" name="position" class="form-control" maxlength="150"
                       placeholder="например, Начальник смены">
                <div class="form-text">Появится в акте рядом с подписью — автоматически, без ручного ввода на планшете.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Телефон</label>
                <input type="text" name="phone" class="form-control" maxlength="30">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Email</label>
                <input type="email" name="email" class="form-control" maxlength="150">
                <div class="form-text">Нужен, если хотите, чтобы этому представителю автоматически уходила копия акта после утверждения.</div>
            </div>
            <div class="alert alert-secondary small mb-3">
                Логин и пароль для входа система создаст автоматически и покажет один раз
                на следующем экране — сохраните их сразу.
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Создать</button>
        </form>
    </div>
</div>
