<?php
/**
 * ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): главная страница кабинета
 * хендлера — список своих представителей (handler_employees, привязанных
 * к своему handler_id), с возможностью добавить нового, отредактировать
 * данные, деактивировать или сбросить пароль.
 */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-people-fill"></i> Мои представители</h4>
    <a href="/handler-cabinet/representatives/create" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> Добавить представителя
    </a>
</div>

<div class="alert alert-info">
    Представитель, добавленный здесь, сразу появляется в списке для выбора подписанта
    на планшете супервайзера при подписании акта — отдельно входить в систему ему не
    нужно. Логин и пароль нужны только на случай, если понадобится подтвердить личность
    представителя отдельно.
</div>

<?php if ($handlerId <= 0): ?>
    <div class="alert alert-warning">
        Ваша учётная запись не привязана к хендлеру. Обратитесь в ЦОК.
    </div>
<?php elseif (empty($employees)): ?>
    <div class="alert alert-secondary">Пока нет ни одного представителя. Добавьте первого.</div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ФИО</th>
                        <th>Должность</th>
                        <th>Телефон</th>
                        <th>Email</th>
                        <th>Логин</th>
                        <th>Статус</th>
                        <th class="text-end">Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($emp['full_name']) ?></td>
                        <td><?= e($emp['position'] ?? '—') ?></td>
                        <td><?= e($emp['phone'] ?? '—') ?></td>
                        <td><?= e($emp['email'] ?? '—') ?></td>
                        <td><code><?= e($emp['login_username'] ?? '—') ?></code></td>
                        <td>
                            <?php if ($emp['is_active']): ?>
                                <span class="badge bg-success">Активен</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Неактивен</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="/handler-cabinet/representatives/<?= (int)$emp['id'] ?>/edit" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if (!empty($emp['user_id'])): ?>
                            <form method="POST" action="/handler-cabinet/representatives/<?= (int)$emp['id'] ?>/reset-password" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Сбросить пароль"
                                        onclick="return confirm('Выдать новый пароль этому представителю? Старый перестанет работать.');">
                                    <i class="bi bi-key"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" action="/handler-cabinet/representatives/<?= (int)$emp['id'] ?>/toggle" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm <?= $emp['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                    <i class="bi <?= $emp['is_active'] ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
