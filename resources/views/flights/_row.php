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
        <?php /* ДОБАВЛЕНО (18.11.2026, раунд 179, по прямому
                 указанию пользователя): дата и время создания
                 самого рейса в системе (flights.created_at) —
                 отдельно от даты/времени самого рейса выше. */ ?>
        <?php if (!empty($f['created_at'])): ?>
        <br><span class="text-muted" style="font-size:0.7rem">Создан: <?= e(format_datetime($f['created_at'])) ?></span>
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
