<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-tablet me-2"></i>Супервайзер — рейсы</h2>
</div>

<?php if (empty($flights)): ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-airplane fs-1 d-block mb-2"></i>
    Активных рейсов нет
</div>
<?php else: ?>
<?php
    // ДОБАВЛЕНО (07.11.2026, раунд 155, по прямому указанию пользователя —
    // просьба супервайзеров сгруппировать список по датам): список уже
    // приходит отсортированным по f.flight_date ASC, f.scheduled_time ASC
    // (ServiceOrderController::dashboard(), без изменений в этом раунде) —
    // здесь он просто визуально разбивается по дате, порядок внутри каждой
    // даты (по времени) и порядок самих дат (ближайшая сверху) не меняется,
    // т.к. PHP-массив с ключами-датами сохраняет порядок первой вставки.
    $groupsByDate = [];
    foreach ($flights as $f) {
        $groupsByDate[$f['flight_date']][] = $f;
    }
    $tomorrowDate = date('Y-m-d', strtotime($today . ' +1 day'));
?>
<?php foreach ($groupsByDate as $groupDate => $groupFlights): ?>
    <?php
        if ($groupDate === $today) {
            $groupDateLabel = 'Сегодня, ' . format_date($groupDate);
        } elseif ($groupDate === $tomorrowDate) {
            $groupDateLabel = 'Завтра, ' . format_date($groupDate);
        } else {
            $groupDateLabel = format_date($groupDate);
        }
    ?>
    <div class="d-flex align-items-center gap-2 mb-2 mt-4">
        <h5 class="mb-0 text-dark"><i class="bi bi-calendar3 me-2"></i><?= e($groupDateLabel) ?></h5>
        <span class="badge bg-secondary rounded-pill"><?= count($groupFlights) ?></span>
    </div>
<div class="row g-3 mb-2">
    <?php foreach ($groupFlights as $f): ?>
    <div class="col-12 col-md-6 col-lg-4">
        <a href="/supervisor/flights/<?= (int)$f['id'] ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 supervisor-flight-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="fs-4 fw-bold text-dark"><?= e($f['flight_number']) ?></div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <span class="badge <?= $f['operation_type'] === 'arrival' ? 'bg-info text-dark' : 'bg-warning text-dark' ?>">
                                <?= $f['operation_type'] === 'arrival' ? '↓ Прилёт' : '↑ Вылет' ?>
                            </span>
                            <?php
                                // ДОБАВЛЕНО (26.10.2026, раунд 128, по прямому указанию
                                // пользователя — "чтобы узнать, манифест подан или нет,
                                // приходится заходить в каждый рейс... в этом дашборде
                                // сразу отметь наличие манифеста"): компактный
                                // бейдж статуса манифеста прямо на карточке списка —
                                // не нужно открывать рейс, чтобы узнать. Полная
                                // расшифровка (цифры, кто подал) по-прежнему только
                                // внутри рейса (см. раунд 122) — здесь только статус.
                                // $f['manifest_status'] — из подзапроса в
                                // ServiceOrderController::dashboard().
                                $manifestStatus = $f['manifest_status'] ?? null;
                                if (in_array($manifestStatus, ['submitted', 'accepted'], true)) {
                                    $manifestBadgeClass = 'bg-success';
                                    $manifestBadgeIcon  = 'bi-check-circle-fill';
                                    $manifestBadgeText  = 'Манифест подан';
                                } elseif ($manifestStatus === 'draft') {
                                    $manifestBadgeClass = 'bg-warning text-dark';
                                    $manifestBadgeIcon  = 'bi-pencil-fill';
                                    $manifestBadgeText  = 'Манифест заполняется';
                                } else {
                                    $manifestBadgeClass = 'bg-danger';
                                    $manifestBadgeIcon  = 'bi-x-circle-fill';
                                    $manifestBadgeText  = 'Манифест не подан';
                                }
                            ?>
                            <span class="badge <?= $manifestBadgeClass ?>" title="<?= e($manifestBadgeText) ?>">
                                <i class="bi <?= $manifestBadgeIcon ?>"></i> <?= e($manifestBadgeText) ?>
                            </span>
                        </div>
                    </div>
                    <div class="text-muted small mb-1">
                        <?= e($f['airline_name'] ?? 'Авиакомпания не указана') ?>
                    </div>
                    <?php
                        // ДОБАВЛЕНО (18.09.2026, раунд 47, по просьбе супервайзеров):
                        // направление рейса — откуда/куда, а не только тип
                        // прилёт/вылет (тот уже был виден по бейджу выше).
                        $originLabel      = $f['origin_iata'] ?? $f['origin_name'] ?? null;
                        $destinationLabel = $f['destination_iata'] ?? $f['destination_name'] ?? null;
                    ?>
                    <?php if ($originLabel || $destinationLabel): ?>
                    <div class="text-muted small mb-1">
                        <i class="bi bi-signpost-split me-1"></i><?= e($originLabel ?? '—') ?> → <?= e($destinationLabel ?? '—') ?>
                    </div>
                    <?php endif; ?>
                    <div class="text-muted small mb-1">
                        <i class="bi bi-calendar3 me-1"></i><?= e($f['flight_date']) ?>
                        <?php if ($f['scheduled_time']): ?>
                            &nbsp;<i class="bi bi-clock ms-2 me-1"></i><?= e($f['scheduled_time']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted small mb-1">
                        <i class="bi bi-p-square me-1"></i>Стоянка:
                        <?= !empty($f['mc_number']) ? '<span class="fw-semibold text-dark">' . e($f['mc_number']) . '</span>' : '<span class="fst-italic">не назначена</span>' ?>
                        <?php /* ДОБАВЛЕНО (23.10.2026, раунд 125, по прямому указанию
                                 пользователя — "мтов есть внутри рейса, а в дашборде
                                 нет"): MTOW уже выводился на самой странице рейса
                                 (раунд 123), на карточках списка /supervisor — нет.
                                 Тот же $f['aircraft_mtow'] — уже есть в выборке
                                 ServiceOrderController::dashboard() (f.*), доп.
                                 запросов не требуется. Рядом со стоянкой, как и на
                                 странице рейса. */ ?>
                        &nbsp;·&nbsp;MTOW:
                        <?= ($f['aircraft_mtow'] !== null && (float)$f['aircraft_mtow'] > 0) ? '<span class="fw-semibold text-dark">' . e((string)$f['aircraft_mtow']) . ' т</span>' : '<span class="fst-italic">не указан</span>' ?>
                    </div>
                    <?php /* ДОБАВЛЕНО (09.11.2026, раунд 160, по прямому указанию
                             пользователя — "в каждом рейсе добавь бортовой номер
                             ВС, хендлер"): обе карточки списка (как и MTOW/Стоянка
                             выше, раунд 125) до этого были видны только внутри
                             самого рейса, не в общем списке. aircraft_registration —
                             f.* (без доп. запроса), handler_name — новый JOIN на
                             handlers по f.resolved_handler_id (ServiceOrderController::
                             dashboard()). Хендлер определяется автоматически парсером
                             разрешений ГА (раунд 124) — может быть не определён (не
                             каждая заявка это позволяет), тогда показываем "не указан",
                             как и для остальных необязательных полей на этой карточке. */ ?>
                    <div class="text-muted small mb-1">
                        <i class="bi bi-upc-scan me-1"></i>Борт:
                        <?= !empty($f['aircraft_registration']) ? '<span class="fw-semibold text-dark">' . e($f['aircraft_registration']) . '</span>' : '<span class="fst-italic">не указан</span>' ?>
                        &nbsp;·&nbsp;Хендлер:
                        <?= !empty($f['handler_name']) ? '<span class="fw-semibold text-dark">' . e($f['handler_name']) . '</span>' : '<span class="fst-italic">не указан</span>' ?>
                    </div>
                    <?php /* ДОБАВЛЕНО (23.09.2026, раунд 55, по прямому указанию
                             пользователя): кто уже ведёт рейс — актуально прежде
                             всего для ЦОК/админа, видящих рейсы всех супервайзеров
                             сразу (сами супервайзеры в этом списке и так видят
                             только незанятые рейсы + свои собственные, см.
                             ServiceOrderController::dashboard()). */ ?>
                    <?php if (!empty($f['handling_supervisor_name'])): ?>
                    <div class="text-muted small mb-3">
                        <i class="bi bi-person-check me-1"></i>Ведёт: <span class="fw-semibold text-dark"><?= e($f['handling_supervisor_name']) ?></span>
                    </div>
                    <?php else: ?>
                    <div class="mb-3"></div>
                    <?php endif; ?>
                    <?php
                        $total = (int)$f['orders_count'];
                        $done  = (int)$f['orders_done'];
                        $pct   = $total > 0 ? round($done / $total * 100) : 0;
                    ?>
                    <?php if ($total > 0): ?>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" style="width: <?= $pct ?>%"></div>
                    </div>
                    <div class="small text-muted mt-1"><?= $done ?> из <?= $total ?> позиций оказано</div>
                    <?php else: ?>
                    <div class="small text-muted"><i class="bi bi-dash-circle me-1"></i>Предзаказ ещё не сформирован ЦОК</div>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

