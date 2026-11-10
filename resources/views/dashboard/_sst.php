<?php include __DIR__ . '/_status_badge.php'; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-primary"><?= $stats['flights_today'] ?? 0 ?></div>
            <div class="small text-muted">Рейсов сегодня</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-success"><?= $stats['equipment_submitted'] ?? 0 ?></div>
            <div class="small text-muted">Журналов подано</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center p-3 <?= ($stats['equipment_missing'] ?? 0) > 0 ? 'border-danger' : '' ?>">
            <div class="fs-2 fw-bold <?= ($stats['equipment_missing'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>">
                <?= $stats['equipment_missing'] ?? 0 ?>
            </div>
            <div class="small text-muted">Не создано</div>
        </div>
    </div>
</div>

<?php
$urgent = array_filter($todayFlights, fn($f) => empty($f['equipment_status']));
if (!empty($urgent)):
?>
<div class="card border-0 shadow-sm mb-4 border-start border-danger border-3">
    <div class="card-header bg-white fw-semibold text-danger">
        <i class="bi bi-exclamation-circle me-2"></i>Требует вашего внимания (<?= count($urgent) ?>)
    </div>
    <div class="card-body d-flex flex-wrap gap-2">
        <?php foreach ($urgent as $f): ?>
        <a href="/equipment-logs/flight/<?= (int)$f['id'] ?>"
           class="border rounded-3 p-2 d-flex align-items-center gap-2 text-decoration-none" style="background:#fff8f8">
            <div>
                <div class="fw-semibold small text-dark"><?= e($f['flight_number']) ?> <?= opBadge($f['operation_type']) ?></div>
                <div class="text-muted" style="font-size:0.7rem">
                    <?= $f['scheduled_time'] ? substr($f['scheduled_time'],0,5) : '—' ?> · <?= e($f['airline_name'] ?? '') ?>
                </div>
            </div>
            <span class="btn btn-sm btn-secondary py-1">Заполнить журнал</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-truck me-2"></i>Рейсы сегодня — журнал спецтехники
        <span class="badge bg-secondary ms-2"><?= count($todayFlights) ?></span>
    </div>
    <?php if (empty($todayFlights)): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-truck fs-2 d-block mb-2 opacity-25"></i>Рейсов нет
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Рейс</th><th>Тип</th><th>Авиакомпания</th>
                    <th>STD/STA</th>
                    <th>Журнал спецтехники<br><span class="fw-normal text-muted">T+15/30</span></th>
                    <th>Единиц техники</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($todayFlights as $f): ?>
            <tr class="<?= empty($f['equipment_status']) ? 'table-warning' : '' ?>">
                <td class="fw-semibold"><?= e($f['flight_number']) ?></td>
                <td><?= opBadge($f['operation_type']) ?></td>
                <td><?= e($f['airline_name'] ?? '—') ?></td>
                <td><?= $f['scheduled_time'] ? substr($f['scheduled_time'],0,5) : '—' ?></td>
                <td><?= docStatusBadge($f['equipment_status'] ?? null) ?></td>
                <td>
                    <?php if ($f['equipment_count'] ?? 0): ?>
                    <span class="badge bg-light text-dark border"><?= $f['equipment_count'] ?> ед.</span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <a href="/equipment-logs/flight/<?= (int)$f['id'] ?>"
                       class="btn btn-sm <?= empty($f['equipment_status']) ? 'btn-secondary' : 'btn-outline-secondary' ?> py-0">
                        <i class="bi bi-pencil"></i>
                        <?= empty($f['equipment_status']) ? ' Заполнить' : ' Изменить' ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
