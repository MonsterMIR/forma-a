<?php
/**
 * ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): логин и пароль показываются
 * ровно один раз, сразу после создания/сброса — см. HandlerCabinetController::
 * store()/resetPassword(). Пароль нигде дальше не хранится в открытом
 * виде (в БД — только password_hash), поэтому если хендлер закроет эту
 * страницу не сохранив пароль — единственный выход, это сбросить его
 * заново кнопкой "Сбросить пароль" в списке представителей.
 */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-check-circle text-success"></i> <?= e($title) ?></h4>
    <a href="/handler-cabinet" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> К списку</a>
</div>

<div class="card border-0 shadow-sm" style="max-width: 560px;">
    <div class="card-body">
        <p class="mb-3">
            <?= !empty($isReset) ? 'Новый пароль для' : 'Учётная запись создана для' ?>
            <strong><?= e($fullName) ?></strong>.
        </p>

        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            Сохраните эти данные сейчас — пароль больше нигде не будет показан в открытом виде.
        </div>

        <table class="table table-bordered">
            <tr>
                <th style="width: 140px;">Логин</th>
                <td><code class="fs-5"><?= e($username) ?></code></td>
            </tr>
            <tr>
                <th>Пароль</th>
                <td><code class="fs-5"><?= e($password) ?></code></td>
            </tr>
        </table>

        <a href="/handler-cabinet" class="btn btn-primary"><i class="bi bi-check-lg"></i> Готово</a>
    </div>
</div>
