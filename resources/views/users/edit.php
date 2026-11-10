<?php use App\Core\Permissions; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-person-plus"></i> <?= __('USERS_EDIT') ?></h2>
    <a href="/users" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
</div>

<div class="card border-0 shadow-sm" style="max-width:680px">
    <div class="card-body">
        <form method="POST" action="/users/<?= (int)$user['id'] ?>/edit">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('USERS_FULL_NAME') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required maxlength="150"
                           value="<?= e($_POST['full_name'] ?? $user['full_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('USERS_USERNAME') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" required maxlength="80"
                           value="<?= e($_POST['username'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('USERS_EMAIL') ?></label>
                    <input type="email" name="email" class="form-control" maxlength="150"
                           value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('USERS_PHONE') ?></label>
                    <input type="text" name="phone" class="form-control" maxlength="30"
                           value="<?= e($_POST['phone'] ?? '') ?>">
                </div>

                <!-- Роль с визуальным выбором -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= __('USERS_ROLE') ?> <span class="text-danger">*</span></label>
                    <div class="row g-2 mt-0">
                        <?php foreach ($roles as $role): ?>
                        <?php
                            $icon  = Permissions::roleIcon($role['code']);
                            $color = Permissions::roleBadgeColor($role['code']);
                            $sel   = (($_POST['role_id'] ?? '') == $role['id']);
                        ?>
                        <div class="col-md-6">
                            <label class="card border rounded-2 p-2 w-100 cursor-pointer mb-0
                                <?= $sel ? 'border-primary bg-primary bg-opacity-10' : '' ?>"
                                style="cursor:pointer">
                                <input type="radio" name="role_id" value="<?= (int)$role['id'] ?>"
                                       class="d-none role-radio" data-role-code="<?= e($role['code']) ?>"
                                       <?= $sel ? 'checked' : '' ?> required>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-<?= $color ?> p-2 rounded-2 flex-shrink-0">
                                        <i class="bi <?= $icon ?> fs-6"></i>
                                    </span>
                                    <div>
                                        <div class="fw-semibold small"><?= e($role['name']) ?></div>
                                        <div class="text-muted" style="font-size:0.7rem"><?= e($role['description'] ?? '') ?></div>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php /* См. users/create.php — раунд 49, п.5; расширено раунд 51, п.3. */ ?>
                <div class="col-12 d-none" id="handlerBox">
                    <div class="alert alert-secondary py-2 mb-2 small" id="handlerBoxNoteRep">
                        <i class="bi bi-info-circle"></i> Личный кабинет представителя пока
                        <strong>выключен</strong> (см. .env → PORTAL_ENABLED). Учётную запись
                        можно завести заранее — она заработает, когда кабинет включат.
                    </div>
                    <div class="alert alert-secondary py-2 mb-2 small d-none" id="handlerBoxNoteAdmin">
                        <i class="bi bi-info-circle"></i> Учётная запись хендлера — сразу
                        получит доступ к <code>/handler-cabinet</code>, где сможет заводить
                        своих представителей самостоятельно.
                    </div>
                    <label class="form-label fw-semibold">Хендлер (компания)</label>
                    <select name="handler_id" class="form-select">
                        <option value="">— не выбран —</option>
                        <?php foreach (($handlers ?? []) as $h): ?>
                        <option value="<?= (int)$h['id'] ?>" <?= (($_POST['handler_id'] ?? $user['handler_id'] ?? '') == $h['id']) ? 'selected' : '' ?>>
                            <?= e($h['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Учётная запись увидит в кабинете утверждённые акты/представителей только этого хендлера.</div>
                    <div class="mt-2">
                        <label class="form-label fw-semibold">Должность</label>
                        <input type="text" name="position" class="form-control" maxlength="150"
                               value="<?= e($_POST['position'] ?? $user['position'] ?? '') ?>">
                        <div class="form-text">Только для 'representative' — появится в акте рядом с подписью автоматически.</div>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('COMMON_STATUS') ?></label>
                    <select name="is_active" class="form-select">
                        <option value="1" <?= (($_POST['is_active'] ?? '1') == '1') ? 'selected' : '' ?>><?= __('COMMON_ACTIVE') ?></option>
                        <option value="0" <?= (($_POST['is_active'] ?? '') == '0') ? 'selected' : '' ?>><?= __('COMMON_INACTIVE') ?></option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Пароль <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
                    <div class="form-text">Минимум 8 символов</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Подтверждение пароля <span class="text-danger">*</span></label>
                    <input type="password" name="password_confirm" class="form-control" required minlength="8" autocomplete="new-password">
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> <?= __('COMMON_SAVE') ?>
                </button>
                <a href="/users" class="btn btn-outline-secondary"><?= __('COMMON_CANCEL') ?></a>
            </div>
        </form>
    </div>
</div>

<script>
// Подсветка выбранной роли
document.querySelectorAll('.role-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('label.card').forEach(l => {
            l.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
        });
        if (this.checked) {
            this.closest('label.card').classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
        }
        toggleHandlerBox();
    });
});
function toggleHandlerBox() {
    const checked = document.querySelector('.role-radio:checked');
    const code = checked ? checked.dataset.roleCode : null;
    const isRep = code === 'representative';
    const isHandlerAdmin = code === 'handler_admin';
    document.getElementById('handlerBox').classList.toggle('d-none', !isRep && !isHandlerAdmin);
    document.getElementById('handlerBoxNoteRep').classList.toggle('d-none', !isRep);
    document.getElementById('handlerBoxNoteAdmin').classList.toggle('d-none', !isHandlerAdmin);
}
toggleHandlerBox();
</script>
