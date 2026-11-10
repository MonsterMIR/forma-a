<?php include __DIR__ . '/_status_badge.php'; ?>

<?php /* УПРОЩЕНО (21.09.2026, раунд 49, п.2, по прямому указанию
         пользователя): первичный (предварительный) манифест убран из
         процесса — остался только один манифест на рейс, дашборд СОП
         больше не показывает пару "предв./окончат.". */ ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-primary"><?= $stats['flights_today'] ?? 0 ?></div>
            <div class="small text-muted">Рейсов сегодня</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-success"><?= $stats['manifest_fin'] ?? 0 ?></div>
            <div class="small text-muted">Манифестов подано</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center p-3 <?= ($stats['manifests_missing'] ?? 0) > 0 ? 'border-danger' : '' ?>">
            <div class="fs-2 fw-bold <?= ($stats['manifests_missing'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>">
                <?= $stats['manifests_missing'] ?? 0 ?>
            </div>
            <div class="small text-muted">Не поданы</div>
        </div>
    </div>
</div>

<?php if (($stats['pax_total_today'] ?? 0) > 0): ?>
<div class="alert alert-info mb-3">
    <i class="bi bi-people me-2"></i>
    Всего пассажиров сегодня (по манифестам):
    <strong class="fs-5"><?= number_format($stats['pax_total_today']) ?></strong> чел.
</div>
<?php endif; ?>

<?php
// ДОБАВЛЕНО (04.11.2026, раунд 144, по прямому указанию пользователя):
// рейсы прошлых дней без манифеста — не зависит от "сегодня", чтобы не
// теряться (см. комментарий в DashboardController::dataSop()).
if (!empty($overdueFlights)):
?>
<div class="card border-0 shadow-sm mb-4 border-start border-danger border-3">
    <div class="card-header bg-danger bg-opacity-10 fw-semibold text-danger">
        <i class="bi bi-exclamation-triangle me-2"></i>Рейсы прошлых дней без манифеста (<?= count($overdueFlights) ?>)
    </div>
    <div class="card-body d-flex flex-wrap gap-2">
        <?php foreach ($overdueFlights as $f): ?>
        <div class="border rounded-3 p-2 d-flex align-items-center gap-2" style="background:#fff0f0">
            <div>
                <div class="fw-semibold small"><?= e($f['flight_number']) ?> <?= opBadge($f['operation_type']) ?></div>
                <div class="text-muted" style="font-size:0.7rem">
                    <?= date('d.m.Y', strtotime($f['flight_date'])) ?>
                    <?= $f['scheduled_time'] ? ' · ' . substr($f['scheduled_time'],0,5) : '' ?>
                    · <?= e($f['airline_name'] ?? '') ?>
                </div>
            </div>
            <a href="/manifests/flight/<?= (int)$f['id'] ?>" class="btn btn-sm btn-danger py-1">
                Манифест
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php
$urgent = array_filter($todayFlights, fn($f) => empty($f['manifest_fin_status']));
if (!empty($urgent)):
?>
<div class="card border-0 shadow-sm mb-4 border-start border-danger border-3">
    <div class="card-header bg-white fw-semibold text-danger">
        <i class="bi bi-exclamation-circle me-2"></i>Требует вашего внимания (<?= count($urgent) ?>)
    </div>
    <div class="card-body d-flex flex-wrap gap-2">
        <?php foreach ($urgent as $f): ?>
        <div class="border rounded-3 p-2 d-flex align-items-center gap-2" style="background:#fff8f8">
            <div>
                <div class="fw-semibold small"><?= e($f['flight_number']) ?> <?= opBadge($f['operation_type']) ?></div>
                <div class="text-muted" style="font-size:0.7rem">
                    <?= $f['scheduled_time'] ? substr($f['scheduled_time'],0,5) : '—' ?> · <?= e($f['airline_name'] ?? '') ?>
                </div>
            </div>
            <a href="/manifests/flight/<?= (int)$f['id'] ?>" class="btn btn-sm btn-success py-1">
                Манифест
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-people me-2"></i>Рейсы сегодня — статус манифестов
        <span class="badge bg-secondary ms-2"><?= count($todayFlights) ?></span>
    </div>
    <?php if (empty($todayFlights)): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-airplane fs-2 d-block mb-2 opacity-25"></i>Рейсов нет
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Рейс</th><th>Тип</th><th>Авиакомпания</th>
                    <th>STD/STA</th>
                    <th>Манифест<br><span class="fw-normal text-muted">T±20</span></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($todayFlights as $f): ?>
            <?php
                $needsAttention = empty($f['manifest_fin_status']);
            ?>
            <tr class="<?= $needsAttention ? 'table-warning' : '' ?>">
                <td class="fw-semibold"><?= e($f['flight_number']) ?></td>
                <td><?= opBadge($f['operation_type']) ?></td>
                <td><?= e($f['airline_name'] ?? '—') ?></td>
                <td><?= $f['scheduled_time'] ? substr($f['scheduled_time'],0,5) : '—' ?></td>
                <td>
                    <?= docStatusBadge($f['manifest_fin_status'] ?? null) ?>
                    <?php if ($f['manifest_fin_pax']): ?>
                    <span class="text-muted ms-1"><?= $f['manifest_fin_pax'] ?> PAX</span>
                    <?php endif; ?>
                </td>
                <td class="text-nowrap">
                    <a href="/manifests/flight/<?= (int)$f['id'] ?>"
                       class="btn btn-sm <?= empty($f['manifest_fin_status']) ? 'btn-success' : 'btn-outline-success' ?> py-0"
                       title="Манифест">
                        <i class="bi bi-pencil"></i> Манифест
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
