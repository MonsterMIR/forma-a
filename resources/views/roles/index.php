<?php
use App\Core\Auth;
use App\Core\Permissions;

$currentUserId = Auth::id();

$moduleNames = [
    'flights'    => ['Рейсы', 'bi-airplane'],
    'acts'       => ['Акты (Форма А)', 'bi-file-earmark-check'],
    'manifests'  => ['Манифесты', 'bi-people'],
    'techcards'  => ['Технологические карты', 'bi-clipboard-check'],
    'equipment'  => ['Спецтехника', 'bi-truck'],
    'parking'    => ['Акты стоянки', 'bi-p-square'],
    'deviations' => ['Отклонения', 'bi-exclamation-triangle'],
    'airlines'   => ['Авиакомпании', 'bi-building'],
    'airports'   => ['Аэропорты', 'bi-geo-alt'],
    'services'   => ['Услуги', 'bi-list-check'],
    'tariffs'    => ['Тарифы', 'bi-currency-dollar'],
    'export'     => ['Экспорт в 1С', 'bi-box-arrow-up'],
    'files'      => ['Файлы', 'bi-file-earmark'],
    'audit'      => ['Аудит', 'bi-journal-text'],
    'users'      => ['Пользователи', 'bi-people-fill'],
    'settings'   => ['Настройки', 'bi-gear'],
];
$actionLabels = [
    'view'=>'просмотр','create'=>'создание','edit'=>'редактир.',
    'manage'=>'управление','approve'=>'утверждение','cancel'=>'отмена',
    'export'=>'экспорт','print'=>'печать','upload'=>'загрузка',
    'delete'=>'удаление','exec'=>'выполнение',
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-shield-check me-2"></i>Роли и структура служб</h1>
    <a href="/users/create" class="btn btn-primary btn-sm">
        <i class="bi bi-person-plus me-1"></i>Новый пользователь
    </a>
</div>

<!-- Быстрый перевод пользователя -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-arrow-left-right me-2"></i>Перевести пользователя в другую службу
    </div>
    <div class="card-body">
        <form method="POST" action="/roles/change-user-role" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Пользователь</label>
                <select name="user_id" class="form-select" required id="sel-user">
                    <option value="">— выберите —</option>
                    <?php foreach ($allUsers as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"
                            data-role="<?= e($u['role_code']) ?>"
                            <?= $u['id'] == $currentUserId ? 'disabled' : '' ?>>
                        <?= e($u['full_name']) ?>
                        (<?= e($u['username']) ?>)
                        — <?= e($u['role_name']) ?>
                        <?= !$u['is_active'] ? '[заблок.]' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Новая роль / служба</label>
                <select name="role_code" class="form-select" required id="sel-role">
                    <option value="">— выберите роль —</option>
                    <?php foreach (Permissions::allRoles() as $code => $name): ?>
                    <option value="<?= e($code) ?>">
                        <i class="bi <?= Permissions::roleIcon($code) ?>"></i>
                        <?= e($name) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"
                        onclick="return confirm('Подтвердить перевод пользователя?')">
                    <i class="bi bi-arrow-left-right me-1"></i>Перевести
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Организационная схема -->
<div class="row g-3 mb-4">
<?php foreach ($roles as $role): ?>
<div class="col-md-6 col-xl-4">
    <div class="card h-100 border-0 shadow-sm">

        <!-- Заголовок службы -->
        <div class="card-header d-flex align-items-center gap-2 bg-white border-bottom py-2">
            <span class="badge bg-<?= e($role['color']) ?> rounded-2 p-2 fs-6">
                <i class="bi <?= e($role['icon']) ?>"></i>
            </span>
            <div class="flex-grow-1 min-width-0">
                <div class="fw-bold small"><?= e($role['name']) ?></div>
                <code class="text-muted" style="font-size:0.7rem"><?= e($role['code']) ?></code>
            </div>
            <span class="badge bg-light text-dark border"><?= count($role['users']) ?> чел.</span>
        </div>

        <div class="card-body p-0">

            <!-- Сотрудники службы -->
            <?php if (empty($role['users'])): ?>
            <div class="text-center text-muted py-3 small">
                <i class="bi bi-person-x d-block fs-4 mb-1 opacity-25"></i>
                Нет сотрудников
            </div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($role['users'] as $u): ?>
            <li class="list-group-item py-2 px-3 d-flex align-items-center gap-2
                        <?= !$u['is_active'] ? 'opacity-50' : '' ?>">

                <!-- Аватар-инициалы -->
                <div class="rounded-circle bg-<?= e($role['color']) ?> bg-opacity-15
                            d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:32px;height:32px;font-size:0.75rem;font-weight:600">
                    <?= mb_strtoupper(mb_substr($u['full_name'], 0, 1)) ?>
                </div>

                <div class="flex-grow-1 min-width-0">
                    <div class="small fw-semibold text-truncate"><?= e($u['full_name']) ?></div>
                    <div class="text-muted" style="font-size:0.7rem">
                        @<?= e($u['username']) ?>
                        <?php if ($u['last_login_at']): ?>
                        · <span title="Последний вход">
                            <?= date('d.m.y', strtotime($u['last_login_at'])) ?>
                          </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Статус и действия -->
                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                    <?php if (!$u['is_active']): ?>
                        <span class="badge bg-danger" style="font-size:0.6rem">Заблок.</span>
                    <?php endif; ?>
                    <?php if ($u['id'] != $currentUserId): ?>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary border-0 py-0 px-1"
                                data-bs-toggle="dropdown" title="Действия">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                                <a class="dropdown-item small" href="/users/<?= (int)$u['id'] ?>/edit">
                                    <i class="bi bi-pencil me-2"></i>Редактировать
                                </a>
                            </li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <!-- Быстрый перевод в другую службу -->
                            <?php foreach (Permissions::allRoles() as $rCode => $rName):
                                if ($rCode === $role['code']) continue; ?>
                            <li>
                                <form method="POST" action="/roles/change-user-role">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="role_code" value="<?= e($rCode) ?>">
                                    <button type="submit" class="dropdown-item small"
                                            onclick="return confirm('Перевести в <?= e($rName) ?>?')">
                                        <i class="bi <?= Permissions::roleIcon($rCode) ?> me-2 text-muted"></i>
                                        → <?= e($rName) ?>
                                    </button>
                                </form>
                            </li>
                            <?php endforeach; ?>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li>
                                <form method="POST" action="/roles/toggle-user/<?= (int)$u['id'] ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="dropdown-item small
                                            <?= $u['is_active'] ? 'text-danger' : 'text-success' ?>"
                                            onclick="return confirm('<?= $u['is_active'] ? 'Заблокировать' : 'Активировать' ?> пользователя?')">
                                        <i class="bi bi-<?= $u['is_active'] ? 'person-x' : 'person-check' ?> me-2"></i>
                                        <?= $u['is_active'] ? 'Заблокировать' : 'Активировать' ?>
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                    <?php else: ?>
                    <span class="badge bg-primary" style="font-size:0.6rem">Вы</span>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
            <?php endif; ?>

        </div>

        <!-- Права доступа (сворачиваемые) -->
        <div class="card-footer bg-white border-top-0 p-0">
            <button class="btn btn-link btn-sm text-muted w-100 text-start px-3 py-2"
                    data-bs-toggle="collapse"
                    data-bs-target="#perms-<?= e($role['code']) ?>">
                <i class="bi bi-chevron-down me-1"></i>
                <span style="font-size:0.75rem">Права доступа (<?= count($role['permissions']) ?>)</span>
            </button>
            <div class="collapse" id="perms-<?= e($role['code']) ?>">
                <ul class="list-unstyled px-3 pb-2 mb-0" style="font-size:0.75rem">
                <?php
                $groups = [];
                foreach ($role['permissions'] as $p) {
                    [$mod, $act] = explode('.', $p, 2);
                    $groups[$mod][] = $actionLabels[$act] ?? $act;
                }
                foreach ($groups as $mod => $acts):
                    [$mName, $mIcon] = $moduleNames[$mod] ?? [$mod, 'bi-dot'];
                ?>
                <li class="d-flex align-items-start gap-2 mb-1">
                    <i class="bi <?= $mIcon ?> text-muted mt-1 flex-shrink-0" style="font-size:0.8rem"></i>
                    <span>
                        <span class="fw-medium"><?= $mName ?></span>
                        <span class="text-muted"> — <?= implode(', ', $acts) ?></span>
                    </span>
                </li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>

    </div>
</div>
<?php endforeach; ?>
</div>

<script>
// При выборе пользователя — подсвечиваем его текущую роль в select роли
document.getElementById('sel-user')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    const currentRole = opt.dataset.role;
    const selRole = document.getElementById('sel-role');
    // Убираем disabled у всех
    [...selRole.options].forEach(o => o.disabled = false);
    // Отмечаем текущую роль как disabled
    [...selRole.options].forEach(o => {
        if (o.value === currentRole) {
            o.disabled = true;
            o.text = o.text.replace(' (текущая)', '') + ' (текущая)';
        } else {
            o.text = o.text.replace(' (текущая)', '');
        }
    });
    selRole.value = '';
});
</script>
