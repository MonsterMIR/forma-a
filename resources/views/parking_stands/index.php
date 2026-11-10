<?php use App\Core\Permissions; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-p-square me-2"></i>Справочник стоянок</h1>
        <div class="text-muted small">Список МС№, доступный для выбора на форме рейса и у супервайзера на планшете</div>
    </div>
</div>
<?php
    // ДОБАВЛЕНО (15.10.2026, раунд 79, по прямому указанию пользователя):
    // "в справочнике стоянки все отражаются как свободные, хотя занято
    // 3-6 стоянок!" — эта страница раньше показывала только КОНФИГУРАЦИЮ
    // стоянки (столбец "Статус": Активна/Скрыта — есть ли она в выпадающем
    // списке для выбора), а не её физическую занятость бортом прямо сейчас
    // — такого столбца тут не было вовсе. Реальная занятость уже верно
    // считалась (см. ParkingStandService::getStatusBoard(), round 48), но
    // показывалась только на /parking-acts, которая с раунда 45 видна
    // ТОЛЬКО admin. Теперь то же самое подключено и сюда — на страницу,
    // которую пользователь называет "справочником стоянок" и которую видят
    // и admin, и aero.
    $occupancyByCode = $occupancyByCode ?? [];
    $occupiedCount   = count(array_filter($occupancyByCode, fn($row) => $row['occupied']));
    // ДОБАВЛЕНО (20.10.2026, раунд 104): страница теперь доступна и
    // супервайзеру только на просмотр (Permissions::parking_stands.view) —
    // форма добавления и кнопки скрыть/удалить показываем только тем, у
    // кого есть parking_stands.manage (admin/aero), иначе супервайзер видел
    // бы элементы управления, которые при нажатии всё равно вернут 403.
    $canManage = Permissions::can('parking_stands.manage');
?>
<div class="row g-4">
    <?php if ($canManage): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-plus-circle me-2 text-success"></i>Добавить стоянку
            </div>
            <div class="card-body">
                <form method="POST" action="/parking-stands">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Код стоянки</label>
                        <input type="text" name="code" class="form-control" required maxlength="20"
                               style="text-transform:uppercase" placeholder="Например: B-22R">
                        <div class="form-text">Как печатать в акте — уникально</div>
                    </div>
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-plus-circle me-1"></i>Добавить
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-md-<?= $canManage ? '8' : '12' ?>">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Стоянки <span class="badge bg-secondary"><?= count($items) ?></span></span>
                <span class="badge bg-danger"><?= $occupiedCount ?> занято сейчас</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Код</th><th>В справочнике</th><th>Занятость сейчас</th><?php if ($canManage): ?><th></th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="<?= $canManage ? 4 : 3 ?>" class="text-center text-muted py-4">Справочник пуст<?= $canManage ? ' — добавьте стоянки слева' : '' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($items as $item): $occ = $occupancyByCode[$item['code']] ?? null; $f = $occ['flight'] ?? null; ?>
                    <tr class="<?= !$item['is_active'] ? 'opacity-50' : '' ?>">
                        <td>
                            <code><?= e($item['code']) ?></code>
                            <?php if (!empty($item['is_wide'])): ?>
                                <span class="badge bg-info text-dark ms-1" title="Широкая стоянка — занимает оба узких места своей пары">широкая</span>
                            <?php elseif (!empty($item['pair_group'])): ?>
                                <span class="badge bg-light text-muted border ms-1" title="Часть пары <?= e($item['pair_group']) ?></code>">∈ <?= e($item['pair_group']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $item['is_active']
                                ? '<span class="badge bg-success">Активна</span>'
                                : '<span class="badge bg-secondary">Скрыта</span>' ?>
                        </td>
                        <td>
                            <?php if ($occ === null): ?>
                                <span class="text-muted">—</span>
                            <?php elseif ($occ['occupied']): ?>
                                <span class="badge bg-danger me-1">Занята</span>
                                <?php if ($f): ?>
                                    <span class="small">
                                        <?= e($f['flight_number']) ?> (<?= e($f['aircraft_registration'] ?? '—') ?>,
                                        <?= e($f['airline_name'] ?? '—') ?>)<?php if (!empty($occ['occupied_since'])): ?>
                                        — с <?= e($occ['occupied_since']) ?><?php endif; ?><?php if (!empty($occ['occupied_via'])): ?>
                                        (через парную <?= e($occ['occupied_via']) ?>)<?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-success">Свободна</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canManage): ?>
                        <td class="text-nowrap">
                            <form method="POST"
                                  action="/parking-stands/<?= (int)$item['id'] ?>/toggle"
                                  class="d-inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                                        title="<?= $item['is_active'] ? 'Скрыть' : 'Активировать' ?>">
                                    <i class="bi bi-eye<?= $item['is_active'] ? '-slash' : '' ?>"></i>
                                </button>
                            </form>
                            <form method="POST"
                                  action="/parking-stands/<?= (int)$item['id'] ?>/delete"
                                  class="d-inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger py-0 px-2"
                                        onclick="return confirm('Удалить? Возможно только если ни у одного рейса не указана эта стоянка.')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="alert alert-info small mt-3 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            «В справочнике» — скрытые стоянки не появляются в выпадающем списке на форме рейса и у супервайзера,
            но сохраняются в уже заполненных актах. «Занятость сейчас» — отдельная, физическая занятость бортом
            прямо в эту минуту (то же, что показывает /parking-acts); освобождается автоматически после уборки
            колодок на вылете (поле «Занята с…» может отставать, если аэродромная служба не отметила уборку колодок).
            Значок «широкая» (B-20, B-22, B-23, B-24) — стоянка для крупного борта, физически занимает оба узких
            места своей пары (напр. B-22L и B-22R) — выбор широкой блокирует обе узкие, и наоборот; сами узкие
            места пары друг на друга не влияют.
        </div>
    </div>
</div>
