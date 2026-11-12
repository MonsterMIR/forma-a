<?php include __DIR__ . '/_status_badge.php'; ?>

<?php
/* ИСПРАВЛЕНО (12.11.2026, раунд 174, по прямому указанию пользователя:
   "можно мусор убрать из отображения" — эта панель всегда настойчиво
   требовала "срочно" заполнить акт готовности стоянки (красный баннер
   "Требует вашего внимания" + два счётчика "подано"/"не оформлено"), хотя
   с раунда 44 (17.09.2026) эта форма вообще не участвует в расчёте
   сверхнормативной стоянки (OVER_PARK — считается по колодкам), а за всю
   историю системы ни разу реально не заполнялась (аудит раунда 42) —
   единственный аккаунт с ролью aero к тому же сейчас деактивирован.
   Баннер и два счётчика по ней убраны как чистый шум; сама форма и
   маршрут /parking-acts/flight/{id} не удалены и продолжают работать
   (на случай, если роль aero снова понадобится) — таблица ниже и ссылка
   "Заполнить"/"Изменить" в ней остаются. */
?>
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-primary"><?= $stats['flights_today'] ?? 0 ?></div>
            <div class="small text-muted">Рейсов сегодня</div>
        </div>
    </div>
</div>

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
