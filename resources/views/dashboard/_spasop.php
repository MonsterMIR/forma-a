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
            <div class="fs-2 fw-bold text-success"><?= $stats['spasop_filled'] ?? 0 ?></div>
            <div class="small text-muted">Услуги внесены</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center p-3 <?= ($stats['spasop_missing'] ?? 0) > 0 ? 'border-warning' : '' ?>">
            <div class="fs-2 fw-bold <?= ($stats['spasop_missing'] ?? 0) > 0 ? 'text-warning' : 'text-muted' ?>">
                <?= $stats['spasop_missing'] ?? 0 ?>
            </div>
            <div class="small text-muted">Без услуг СПАСОП</div>
        </div>
    </div>
</div>

<?php
$urgent = array_filter($todayFlights, fn($f) => ($f['spasop_count'] ?? 0) == 0);
if (!empty($urgent)):
?>
<div class="card border-0 shadow-sm mb-4 border-start border-danger border-3">
    <div class="card-header bg-white fw-semibold text-danger">
        <i class="bi bi-exclamation-circle me-2"></i>Требует внимания (<?= count($urgent) ?>)
    </div>
    <div class="card-body d-flex flex-wrap gap-2">
        <?php foreach ($urgent as $f): ?>
        <a href="/emergency-logs/flight/<?= (int)$f['id'] ?>"
           class="border rounded-3 p-2 d-flex align-items-center gap-2 text-decoration-none" style="background:#fff8f8">
            <div>
                <div class="fw-semibold small text-dark"><?= e($f['flight_number']) ?> <?= opBadge($f['operation_type']) ?></div>
                <div class="text-muted" style="font-size:0.7rem">
                    <?= $f['scheduled_time'] ? substr($f['scheduled_time'],0,5) : '—' ?>
                    · <?= e($f['airline_name'] ?? '') ?>
                </div>
            </div>
            <span class="btn btn-sm btn-danger py-1">Внести услуги</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-shield-fill-exclamation text-danger me-2"></i>Рейсы сегодня — СПАСОП
        <span class="badge bg-secondary ms-2"><?= count($todayFlights) ?></span>
    </div>
    <?php if (empty($todayFlights)): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-shield-fill-exclamation fs-2 d-block mb-2 opacity-25"></i>Рейсов нет
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Рейс</th>
                    <th>Тип</th>
                    <th>Авиакомпания</th>
                    <th>STD/STA</th>
                    <th>Услуги СПАСОП</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($todayFlights as $f): ?>
            <tr class="<?= ($f['spasop_count'] ?? 0) == 0 ? 'table-warning' : '' ?>">
                <td class="fw-semibold"><?= e($f['flight_number']) ?></td>
                <td><?= opBadge($f['operation_type']) ?></td>
                <td><?= e($f['airline_name'] ?? '—') ?></td>
                <td><?= $f['scheduled_time'] ? substr($f['scheduled_time'],0,5) : '—' ?></td>
                <td>
                    <?php if (($f['spasop_count'] ?? 0) > 0): ?>
                        <span class="badge bg-success">
                            <i class="bi bi-check-circle me-1"></i><?= $f['spasop_count'] ?> услуг внесено
                        </span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-clock me-1"></i>Не внесено
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/emergency-logs/flight/<?= (int)$f['id'] ?>"
                       class="btn btn-sm <?= ($f['spasop_count'] ?? 0) == 0 ? 'btn-danger' : 'btn-outline-secondary' ?> py-0">
                        <i class="bi bi-pencil"></i>
                        <?= ($f['spasop_count'] ?? 0) == 0 ? ' Внести' : ' Изменить' ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
