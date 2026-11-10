<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-currency-dollar"></i> Tariff: <?= e($tariff['tariff_name']) ?></h2>
    <div class="d-flex gap-2">
        <?php if (\App\Core\Permissions::can('tariffs.manage')): ?>
        <a href="/tariffs/<?= (int)$tariff['id'] ?>/edit" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> <?= __('COMMON_EDIT') ?></a>
        <?php endif; ?>
        <a href="/tariffs" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
    </div>
</div>

<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-5">Tariff Name</dt>
            <dd class="col-sm-7"><?= e($tariff['tariff_name']) ?></dd>
            <dt class="col-sm-5"><?= __('ACTS_SERVICE') ?></dt>
            <dd class="col-sm-7"><a href="/services/<?= (int)$tariff['service_id'] ?>"><?= e($tariff['service_name']) ?></a></dd>
            <dt class="col-sm-5"><?= __('FLIGHTS_ROUTE_TYPE') ?></dt>
            <dd class="col-sm-7"><span class="badge bg-secondary"><?= e($tariff['route_type']) ?></span></dd>
            <dt class="col-sm-5"><?= __('FLIGHTS_OPERATION') ?></dt>
            <dd class="col-sm-7"><span class="badge bg-secondary"><?= e($tariff['operation_type']) ?></span></dd>
            <dt class="col-sm-5"><?= __('TARIFFS_PRICE') ?></dt>
            <dd class="col-sm-7 fw-semibold"><?= format_money($tariff['price'], 4) ?> <code><?= e($tariff['currency_code']) ?></code></dd>
            <dt class="col-sm-5"><?= __('TARIFFS_VAT_RATE') ?></dt>
            <dd class="col-sm-7"><?= e($tariff['vat_rate']) ?>%</dd>
            <dt class="col-sm-5"><?= __('TARIFFS_VALID_FROM') ?></dt>
            <dd class="col-sm-7"><?= format_date($tariff['valid_from']) ?></dd>
            <dt class="col-sm-5"><?= __('TARIFFS_VALID_TO') ?></dt>
            <dd class="col-sm-7"><?= $tariff['valid_to'] ? format_date($tariff['valid_to']) : '<span class="text-muted">' . __('TARIFFS_OPEN_ENDED') . '</span>' ?></dd>
            <dt class="col-sm-5"><?= __('COMMON_STATUS') ?></dt>
            <dd class="col-sm-7"><?= $tariff['is_active'] ? '<span class="badge bg-success">' . __('COMMON_ACTIVE') . '</span>' : '<span class="badge bg-secondary">' . __('COMMON_INACTIVE') . '</span>' ?></dd>
            <dt class="col-sm-5"><?= __('COMMON_CREATED_BY') ?></dt>
            <dd class="col-sm-7"><?= e($tariff['created_by_username'] ?? '—') ?></dd>
            <dt class="col-sm-5">Created At</dt>
            <dd class="col-sm-7"><?= format_datetime($tariff['created_at']) ?></dd>
        </dl>
    </div>
</div>
<?php if (!empty($tariffHistory ?? [])): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-clock-history me-2"></i>История версий тарифа
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th>Версия</th><th>Цена</th><th>Действует с</th><th>До</th><th>Статус</th></tr>
            </thead>
            <tbody>
            <?php foreach ($tariffHistory as $h): ?>
            <tr class="<?= $h['id'] == $tariff['id'] ? 'table-primary' : '' ?>">
                <td class="font-monospace small">#<?= $h['id'] ?></td>
                <td class="fw-bold"><?= number_format((float)$h['price'], 4) ?></td>
                <td><?= e($h['valid_from']) ?></td>
                <td><?= $h['valid_to'] ? e($h['valid_to']) : '<span class="text-success">∞</span>' ?></td>
                <td>
                    <?php if ($h['is_active']): ?>
                        <span class="badge bg-success">Активен</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Закрыт</span>
                    <?php endif; ?>
                    <?php if ($h['id'] == $tariff['id']): ?>
                        <span class="badge bg-primary ms-1">Текущий</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
