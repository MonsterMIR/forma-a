<?php
use App\Core\Permissions;

// Шапка — одинакова для всех
$roleLabel = Permissions::allRoles()[$role] ?? $role;
$roleIcon  = Permissions::roleIcon($role);
$roleColor = Permissions::roleBadgeColor($role);
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <span class="badge bg-<?= $roleColor ?> p-3 rounded-3 fs-4">
        <i class="bi <?= $roleIcon ?>"></i>
    </span>
    <div>
        <h1 class="h3 mb-0">Рабочий стол</h1>
        <div class="text-muted small">
            <?= e($user['full_name']) ?> —
            <strong><?= e($roleLabel) ?></strong>
            <span class="ms-2 text-muted"><?= date('d.m.Y, l') ?></span>
        </div>
    </div>
</div>

<?php
// Подключаем нужный блок по роли
$partial = match($role) {
    'admin', 'cok' => '_cok',
    'sop'          => '_sop',
    'spo'          => '_spo',
    'sst'          => '_sst',
    'aero'         => '_aero',
    'commercial'   => '_commercial',
    'finance'      => '_finance',
    'spasop'       => '_spasop',
    default        => null,
};

// ДОБАВЛЕНО (30.10.2026, раунд 139): для ЦОК/admin дашборд теперь можно
// оформить одним из трёх альтернативных стилей (А/Б/В), помимо
// классического вида. Выбор — личный, хранится в users.dashboard_style
// (см. ProfileController, resources/views/profile/edit.php) и ни на что,
// кроме собственного экрана пользователя, не влияет. 'classic' и любое
// незнакомое значение — просто используют прежний _cok.php, так что
// старое поведение не меняется для тех, кто ничего не выбирал.
if ($partial === '_cok') {
    $dashboardStyle = $user['dashboard_style'] ?? 'classic';
    $partial = match($dashboardStyle) {
        'a'     => '_cok_a',
        'b'     => '_cok_b',
        'c'     => '_cok_c',
        default => '_cok',
    };
}

if ($partial) include __DIR__ . '/' . $partial . '.php';
?>
