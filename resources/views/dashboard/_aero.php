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
            <div class="fs-2 fw-bold text-success"><?= $stats['parking_submitted'] ?? 0 ?></div>
            <div class="small text-muted">Актов стоянки подано</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center p-3 <?= ($stats['parking_missing'] ?? 0) > 0 ? 'border-danger' : '' ?>">
            <div class="fs-2 fw-bold <?= ($stats['parking_missing'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>">
                <?= $stats['parking_missing'] ?? 0 ?>
            </div>
            <div class="small text-muted">Не оформлено</div>
        </div>
    </div>
</div>

<?php
$urgent = array_filter($todayFlights, fn($f) => empty($f['parking_status']));
if (!empty($urgent)):
?>
<div class="card border-0 shadow-sm mb-4 border-start border-danger border-3">
    <div class="card-header bg-white fw-semibold text-danger">
        <i class="bi bi-exclamation-circle me-2"></i>Требует вашего внимания (<?= count($urgent) ?>)
    </div>
    <div class="card-body d-flex flex-wrap gap-2">
        <?php foreach ($urgent as $f): ?>
        <a href="/parking-acts/flight/<?= (int)$f['id'] ?>"
           class="border rounded-3 p-2 d-flex align-items-center gap-2 text-decoration-none" style="background:#fff8f8">
            <div>
                <div class="fw-semibold small text-dark"><?= e($f['flight_number']) ?> <?= opBadge($f['operation_type']) ?></div>
                <div class="text-muted" style="font-size:0.7rem">
                    <?= $f['scheduled_time'] ? substr($f['scheduled_time'],0,5) : '—' ?> · <?= e($f['airline_name'] ?? '') ?>
                </div>
            </div>
            <span class="btn btn-sm btn-success py-1">Заполнить акт стоянки</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-geo-alt me-2"></i>Рейсы сегодня — акты готовности стоянки
        <span class="badge bg-secondary ms-2"><?= count($todayFlights) ?></span>
    </div>
    <?php if (empty($todayFlights)): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-geo-alt fs-2 d-block mb-2 opacity-25"></i>Рейсов нет
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Рейс</th><th>Тип</th><th>Авиакомпания</th>
                    <th>STD/STA</th><th>Стоянка</th>
                    <th>Акт стоянки<br><span class="fw-normal text-muted">T−30</span></th>
                    <th>FOD</th><th>Перрон</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($todayFlights as $f): ?>
            <tr class="<?= empty($f['parking_status']) ? 'table-warning' : '' ?>">
                <td class="fw-semibold"><?= e($f['flight_number']) ?></td>
                <td><?= opBadge($f['operation_type']) ?></td>
                <td><?= e($f['airline_name'] ?? '—') ?></td>
                <td><?= $f['scheduled_time'] ? substr($f['scheduled_time'],0,5) : '—' ?></td>
                <?php
                    // ИСПРАВЛЕНО (18.08.2026, раунд 19): раньше показывался только
                    // pa.stand_number (из уже поданного акта аэродромной службы) —
                    // если стоянку успел указать ЦОК или супервайзер до того, как
                    // aero открыла акт, здесь всё равно было бы "—". Теперь, как и
                    // на форме акта, при отсутствии собственного значения акта
                    // подставляется уже известный flights.mc_number.
                    $dashStand = $f['stand_number'] ?? $f['mc_number'] ?? null;
                ?>
                <td><?= $dashStand ? e($dashStand) : '—' ?></td>
                <td><?= docStatusBadge($f['parking_status'] ?? null) ?></td>
                <td>
                    <?php if ($f['parking_status'] ?? null): ?>
                    <span class="badge <?= $f['fod_clear'] ? 'bg-success' : 'bg-danger' ?>">
                        <?= $f['fod_clear'] ? '✓' : '✗' ?>
                    </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <?php if ($f['parking_status'] ?? null): ?>
                    <span class="badge <?= $f['runway_clear'] ? 'bg-success' : 'bg-danger' ?>">
                        <?= $f['runway_clear'] ? '✓' : '✗' ?>
                    </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <a href="/parking-acts/flight/<?= (int)$f['id'] ?>"
                       class="btn btn-sm <?= empty($f['parking_status']) ? 'btn-success' : 'btn-outline-success' ?> py-0">
                        <i class="bi bi-pencil"></i>
                        <?= empty($f['parking_status']) ? ' Заполнить' : ' Изменить' ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
