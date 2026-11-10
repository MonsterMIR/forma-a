<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-airplane"></i> <?= __('FLIGHTS_TITLE') ?></h2>
    <?php if (has_role(['admin', 'cok'])): ?>
    <a href="/flights/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= __('FLIGHTS_CREATE') ?></a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold"><?= __('COMMON_SEARCH') ?></label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="<?= __('FLIGHTS_SEARCH_PLACEHOLDER') ?>" value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_AIRLINE') ?> </label>
                <select name="airline_id" class="form-select form-select-sm">
                    <option value=""><?= __('FLIGHTS_ALL_AIRLINES') ?></option>
                    <?php foreach ($airlines as $al): ?>
                    <option value="<?= (int)$al['id'] ?>" <?= $filters['airline_id'] == $al['id'] ? 'selected' : '' ?>>
                        <?= e($al['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_ROUTE_TYPE') ?> </label>
                <select name="route_type" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="domestic" <?= $filters['route_type'] === 'domestic' ? 'selected' : '' ?>><?= __('FLIGHTS_DOMESTIC') ?></option>
                    <option value="international" <?= $filters['route_type'] === 'international' ? 'selected' : '' ?>><?= __('FLIGHTS_INTERNATIONAL') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_OPERATION') ?> </label>
                <select name="operation_type" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="arrival" <?= $filters['operation_type'] === 'arrival' ? 'selected' : '' ?>><?= __('FLIGHTS_ARRIVAL') ?></option>
                    <option value="departure" <?= $filters['operation_type'] === 'departure' ? 'selected' : '' ?>><?= __('FLIGHTS_DEPARTURE') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_DATE_FROM') ?></label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_DATE_TO') ?></label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold" title="Заполните только это поле, чтобы найти рейсы/акты/записи ровно за один день — само подставит ту же дату в поля «с»/«по» выше (round 164).">На дату</label>
                <input type="date" class="form-control form-control-sm" data-exact-date data-from="date_from" data-to="date_to">
            </div>
            <div class="col-auto d-flex gap-2 mt-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> <?= __('COMMON_SEARCH') ?></button>
                <a href="/flights" class="btn btn-sm btn-outline-secondary"><?= __('COMMON_RESET') ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-nowrap" style="width:1%">#</th>
                        <th class="text-nowrap" style="width:1%"><?= __('ACTS_FLIGHT') ?></th>
                        <th class="text-nowrap" style="width:1%"><?= __('COMMON_DATE') ?></th>
                        <th><?= __('FLIGHTS_AIRLINE') ?></th>
                        <th><?= __('FLIGHTS_ROUTE_TYPE_COL') ?></th>
                        <th><?= __('FILES_OP_TYPE') ?></th>
                        <!-- ИЗМЕНЕНО (05.11.2026, раунд 153, по прямому указанию пользователя):
                             колонки "Откуда"/"Куда" объединены в одну "Маршрут" —
                             аэропорт вылета и аэропорт прилёта через стрелку, в
                             естественном порядке origin -> destination (для прилёта
                             аэропорт TVT стоит вторым, для вылета — первым, т.к. так
                             уже устроены origin_airport_id/destination_airport_id). -->
                        <th>Маршрут</th>
                        <th><?= __('FLIGHTS_AIRCRAFT_TYPE') ?></th>
                        <!-- ДОБАВЛЕНО (11.09.2026, раунд 37): статус акта по рейсу -->
                        <th>Акт</th>
                        <!-- ДОБАВЛЕНО (05.11.2026, раунд 153, по прямому указанию пользователя):
                             статус манифеста по рейсу — та же логика, что и на
                             дашборде супервайзера (round128). -->
                        <th>Манифест</th>
                        <th><?= __('COMMON_ACTIONS') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($flights)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4"><?= __('FLIGHTS_NO_DATA') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($flights as $f): ?>
                    <?php /* ДОБАВЛЕНО (27.10.2026, раунд 129): отменённый рейс
                             (flights.status='cancelled') визуально приглушён —
                             виден в истории, но явно не "обычный актуальный". */ ?>
                    <tr<?= (($f['status'] ?? 'active') === 'cancelled') ? ' class="opacity-75"' : '' ?>>
                        <td class="text-muted small"><?= (int)$f['id'] ?></td>
                        <td>
                            <a href="/flights/<?= (int)$f['id'] ?>" class="text-decoration-none fw-semibold">
                                <?= e($f['airline_iata'] ?? '') ?>-<?= e($f['flight_number']) ?>
                            </a>
                            <?php if (($f['status'] ?? 'active') === 'cancelled'): ?>
                            <span class="badge bg-dark" title="<?= e($f['cancellation_reason'] ?? '') ?>">
                                <i class="bi bi-slash-circle"></i> Отменён
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= format_date($f['flight_date']) ?>
                            <?php /* ДОБАВЛЕНО (11.09.2026, раунд 37): плановое время
                                     рейса (STD/STA) — раньше в списке была только дата */ ?>
                            <?php if (!empty($f['scheduled_time'])): ?>
                            <br><span class="text-muted small"><?= e(substr($f['scheduled_time'], 0, 5)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php /* ИСПРАВЛЕНО (10.09.2026, раунд 36): в этой ячейке
                                     по ошибке выводился маршрут (Откуда→Куда), дублируя
                                     колонки "Откуда"/"Куда" ниже, а название авиакомпании
                                     пряталось мелким текстом в колонке "Рейс". Теперь здесь
                                     выводится сама авиакомпания, как и написано в заголовке. */ ?>
                            <?= e($f['airline_name']) ?>
                            <?php if (!empty($f['airline_iata'])): ?>
                            <span class="text-muted">(<?= e($f['airline_iata']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($f['route_type'] === 'domestic'): ?>
                                <span class="badge bg-info text-dark"><?= __('FLIGHTS_DOMESTIC') ?></span>
                            <?php else: ?>
                                <span class="badge bg-primary"><?= __('FLIGHTS_INTERNATIONAL') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($f['operation_type'] === 'arrival'): ?>
                                <span class="badge bg-success"><?= __('FLIGHTS_ARRIVAL') ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><?= __('FLIGHTS_DEPARTURE') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <?php /* ИЗМЕНЕНО (05.11.2026, раунд 153, по прямому указанию
                                     пользователя): "Откуда"/"Куда" объединены в одну колонку
                                     "Маршрут" — ISL&nbsp;&rarr;&nbsp;TVT при прилёте,
                                     TVT&nbsp;&rarr;&nbsp;BED при вылете (порядок как
                                     есть в данных: origin -> destination). */ ?>
                            <?= e($f['origin_iata'] ?? $f['origin_name'] ?? '—') ?>
                            <i class="bi bi-arrow-right text-muted small"></i>
                            <?= e($f['destination_iata'] ?? $f['destination_name'] ?? '—') ?>
                        </td>
                        <td class="small text-muted"><?= e($f['aircraft_type'] ?? '—') ?></td>
                        <td>
                            <?php /* ДОБАВЛЕНО (11.09.2026, раунд 37): статус акта по
                                     рейсу — нет акта / предварительный (черновик, ещё не
                                     утверждён ЦОК) / утверждён / экспортирован / отменён.
                                     "Предварительный" здесь — это status='draft', вне
                                     зависимости от того, финализирован ли уже номер акта
                                     (см. раунд 35, acts.number_finalized) — с точки зрения
                                     ЦОК это всё ещё черновик, пока сам не нажал "Подтвердить". */ ?>
                            <?php if (empty($f['act_id'])): ?>
                                <span class="badge bg-secondary">Нет акта</span>
                            <?php else: ?>
                                <?php
                                    $actBadgeClass = match($f['act_status']) {
                                        'draft'     => 'bg-warning text-dark',
                                        'approved'  => 'bg-success',
                                        'exported'  => 'bg-primary',
                                        'cancelled' => 'bg-danger',
                                        default     => 'bg-secondary',
                                    };
                                    $actStatusLabel = match($f['act_status']) {
                                        'draft'     => 'Предварительный',
                                        'approved'  => 'Утверждён',
                                        'exported'  => 'Экспортирован',
                                        'cancelled' => 'Отменён',
                                        default     => e($f['act_status']),
                                    };
                                    $actNumberLabel = !empty($f['act_number_finalized'])
                                        ? e($f['act_number'])
                                        : 'ПРЕДВАРИТЕЛЬНО';
                                ?>
                                <a href="/acts/<?= (int)$f['act_id'] ?>" class="badge text-decoration-none <?= $actBadgeClass ?>">
                                    <?= $actStatusLabel ?>
                                </a>
                                <div class="text-muted" style="font-size:0.7rem"><?= $actNumberLabel ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php /* ДОБАВЛЕНО (05.11.2026, раунд 153, по прямому указанию
                                     пользователя): статус манифеста по рейсу, та же
                                     трёхцветная логика, что и на дашборде супервайзера
                                     (round128) — зелёный/жёлтый/красный. */ ?>
                            <?php
                                $manifestStatus = $f['manifest_status'] ?? null;
                                $manifestBadge  = match($manifestStatus) {
                                    'submitted', 'accepted' => ['bg-success', 'Манифест подан'],
                                    'draft'                 => ['bg-warning text-dark', 'Манифест заполняется'],
                                    default                 => ['bg-danger', 'Манифест не подан'],
                                };
                            ?>
                            <span class="badge <?= $manifestBadge[0] ?>"><?= $manifestBadge[1] ?></span>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="/flights/<?= (int)$f['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                                <?php if (has_role(['admin', 'cok'])): ?>
                                <a href="/flights/<?= (int)$f['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pagination['last_page'] > 1): ?>
    <div class="card-footer bg-white">
        <?php include BASE_PATH . '/resources/views/layouts/_pagination.php'; ?>
    </div>
    <?php endif; ?>
</div>