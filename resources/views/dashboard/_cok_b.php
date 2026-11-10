<?php
/**
 * ДОБАВЛЕНО (30.10.2026, раунд 139): вариант дизайна дашборда ЦОК "Б —
 * Командный центр" — таблица рейсов занимает всю ширину и доминирует на
 * экране, показатели сжаты в горизонтальную строку чипов сверху, срочное
 * (просроченные документы / критические отклонения) — баннером во всю
 * ширину над всем остальным. Вторичная информация (черновики, подписанные
 * на планшете, отклонения) сжата в одну строку компактных колонок под
 * таблицей — доступна, но не отвлекает от главного. Данные те же, что в
 * _cok.php, никакой новой логики контроллера не требуется. Переключается
 * в /profile, users.dashboard_style = 'b'.
 */
include __DIR__ . '/_status_badge.php';

$hasUrgent = ($stats['overdue_docs'] ?? 0) > 0 || ($stats['critical_today'] ?? 0) > 0;
?>

<?php if ($hasUrgent): ?>
<div class="alert alert-danger d-flex flex-wrap align-items-center gap-3 mb-3 border-0 shadow-sm">
    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
    <div class="flex-grow-1">
        <strong>Требует внимания:</strong>
        <?php if (($stats['overdue_docs'] ?? 0) > 0): ?>
            <?= $stats['overdue_docs'] ?> просроченных документов
        <?php endif; ?>
        <?php if (($stats['overdue_docs'] ?? 0) > 0 && ($stats['critical_today'] ?? 0) > 0): ?> · <?php endif; ?>
        <?php if (($stats['critical_today'] ?? 0) > 0): ?>
            <?= $stats['critical_today'] ?> критических отклонений сегодня
        <?php endif; ?>
    </div>
    <a href="/deviations" class="btn btn-sm btn-outline-danger bg-white">Смотреть →</a>
</div>
<?php endif; ?>

<!-- Строка показателей -->
<div class="d-flex flex-wrap align-items-stretch gap-2 mb-3">
    <div class="card border-0 shadow-sm px-3 py-2 flex-grow-1" style="min-width:140px;">
        <div class="d-flex align-items-center gap-2">
            <span class="fs-4 fw-bold text-primary"><?= $stats['flights_today'] ?? 0 ?></span>
            <div class="small text-muted lh-sm">Рейсов<br>сегодня</div>
        </div>
        <div class="mt-1 d-flex gap-1">
            <span class="badge bg-info text-dark" style="font-size:0.65rem">↓ <?= $stats['arrivals_today'] ?? 0 ?></span>
            <span class="badge bg-warning text-dark" style="font-size:0.65rem">↑ <?= $stats['departures_today'] ?? 0 ?></span>
        </div>
    </div>
    <a href="/acts?status=draft" class="card border-0 shadow-sm px-3 py-2 flex-grow-1 text-decoration-none" style="min-width:140px;">
        <span class="fs-4 fw-bold text-warning"><?= $stats['acts_draft'] ?? 0 ?></span>
        <div class="small text-muted">Черновиков актов</div>
    </a>
    <a href="/acts?status=approved" class="card border-0 shadow-sm px-3 py-2 flex-grow-1 text-decoration-none" style="min-width:140px;">
        <span class="fs-4 fw-bold text-success"><?= $stats['acts_approved'] ?? 0 ?></span>
        <div class="small text-muted">Актов утверждено</div>
    </a>
    <div class="card border-0 shadow-sm px-3 py-2 flex-grow-1" style="min-width:140px;">
        <span class="fs-4 fw-bold <?= $hasUrgent ? 'text-danger' : 'text-muted' ?>"><?= $stats['deviations_today'] ?? 0 ?></span>
        <div class="small text-muted">Отклонений сегодня</div>
    </div>
    <a href="/flights/create" class="btn btn-primary flex-grow-1 d-flex align-items-center justify-content-center gap-2" style="min-width:160px;">
        <i class="bi bi-plus-circle"></i>Новый рейс
    </a>
</div>

<!-- Главное — таблица рейсов -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-airplane"></i>Рейсы сегодня</span>
        <a href="/flights/import" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-cloud-arrow-down me-1"></i>Импорт разрешения
        </a>
    </div>
    <?php if (empty($todayFlights)): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-airplane fs-2 d-block mb-2 opacity-25"></i>
        Рейсов на сегодня нет
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Рейс</th><th>Тип</th><th>Авиакомпания</th>
                    <th>STD/STA</th><th>Акты</th><th>Доки</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($todayFlights as $f): ?>
            <tr class="<?= ($f['overdue_docs'] ?? 0) > 0 ? 'table-danger' : '' ?>">
                <td class="fw-semibold"><?= e($f['flight_number']) ?></td>
                <td><?= opBadge($f['operation_type']) ?></td>
                <td>
                    <?= e($f['airline_name'] ?? '—') ?>
                    <?php if ($f['airline_iata']): ?>
                    <span class="text-muted">(<?= e($f['airline_iata']) ?>)</span>
                    <?php endif; ?>
                </td>
                <td><?= $f['scheduled_time'] ? substr($f['scheduled_time'],0,5) : '—' ?></td>
                <td>
                    <?php if ($f['acts_count'] > 0): ?>
                        <span class="badge <?= $f['acts_draft'] > 0 ? 'bg-warning text-dark' : 'bg-success' ?>">
                            <?= $f['acts_draft'] > 0 ? $f['acts_draft'].' черн.' : '✓ '.$f['acts_count'] ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary">нет</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (($f['overdue_docs'] ?? 0) > 0): ?>
                    <span class="badge bg-danger">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <?= $f['overdue_docs'] ?> просроч.
                    </span>
                    <?php else: ?>
                    <span class="badge bg-success">ОК</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/flights/<?= (int)$f['id'] ?>" class="btn btn-sm btn-outline-primary py-0">
                        <i class="bi bi-eye"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Вторичная информация — сжата в одну строку -->
<div class="row g-2">
    <?php if (!empty($pendingConfirmationActs)): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm border-start border-success border-3 h-100">
            <div class="card-body py-2 px-3">
                <div class="small fw-semibold text-success mb-1">
                    <i class="bi bi-pen"></i>Ждут подтверждения (<?= count($pendingConfirmationActs) ?>)
                </div>
                <?php foreach (array_slice($pendingConfirmationActs, 0, 3) as $a): ?>
                <div class="d-flex justify-content-between" style="font-size:0.78rem">
                    <a href="/acts/<?= (int)$a['id'] ?>" class="text-decoration-none"><?= e(act_display_number($a)) ?></a>
                    <span class="text-muted"><?= e($a['flight_number']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-2 px-3">
                <div class="small fw-semibold text-warning mb-1">
                    <i class="bi bi-file-earmark-text"></i>Черновики актов (<?= count($draftActs) ?>)
                </div>
                <?php if (empty($draftActs)): ?>
                <div class="text-muted" style="font-size:0.78rem">Черновиков нет</div>
                <?php else: ?>
                <?php foreach (array_slice($draftActs, 0, 3) as $a): ?>
                <div class="d-flex justify-content-between" style="font-size:0.78rem">
                    <a href="/acts/<?= (int)$a['id'] ?>" class="text-decoration-none"><?= e(act_display_number($a)) ?></a>
                    <span class="text-muted"><?= e($a['flight_number']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($recentDeviations)): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm border-start border-danger border-3 h-100">
            <div class="card-body py-2 px-3">
                <div class="small fw-semibold text-danger mb-1">
                    <i class="bi bi-exclamation-triangle"></i>Отклонения (<?= count($recentDeviations) ?>)
                </div>
                <?php foreach (array_slice($recentDeviations, 0, 3) as $d): ?>
                <div class="d-flex justify-content-between" style="font-size:0.78rem">
                    <span><?= e($d['flight_number']) ?></span>
                    <span class="text-muted">+<?= $d['delay_minutes'] ?> мин</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
