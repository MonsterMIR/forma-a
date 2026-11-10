<?php
/**
 * ДОБАВЛЕНО (30.10.2026, раунд 139): вариант дизайна дашборда ЦОК "В —
 * Карточки-сводки" — дашборд как утренняя сводка, а не рабочий стол:
 * крупные карточки-показатели и списки вместо плотной таблицы. Рабочая
 * таблица рейсов осталась на /flights — здесь только обзор. Данные те
 * же, что и в _cok.php, никакой новой логики контроллера не требуется.
 * Переключается в /profile, users.dashboard_style = 'c'.
 */
include __DIR__ . '/_status_badge.php';

$hour = (int)date('G');
$greeting = match(true) {
    $hour < 6   => 'Доброй ночи',
    $hour < 12  => 'Доброе утро',
    $hour < 18  => 'Добрый день',
    default     => 'Добрый вечер',
};
$fullName = $user['full_name'] ?? '';
?>

<div class="mb-4">
    <div class="h4 mb-1"><?= e($greeting) ?><?= $fullName ? ', ' . e($fullName) : '' ?> — вот как выглядит сегодня</div>
    <div class="text-muted">Сводка на <?= date('d.m.Y') ?></div>
</div>

<!-- Крупные карточки-показатели -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:56px;height:56px;background:rgba(37,99,235,.1);color:var(--brand-primary);font-size:1.6rem;">
                    <i class="bi bi-airplane"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold"><?= $stats['flights_today'] ?? 0 ?></div>
                    <div class="text-muted small">Рейсов сегодня (↓<?= $stats['arrivals_today'] ?? 0 ?> / ↑<?= $stats['departures_today'] ?? 0 ?>)</div>
                </div>
                <span class="badge bg-primary ms-auto align-self-start">СЕГОДНЯ</span>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <a href="/acts?status=draft" class="card border-0 shadow-sm h-100 text-decoration-none text-reset">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:56px;height:56px;background:rgba(217,119,6,.12);color:var(--brand-warning);font-size:1.6rem;">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold"><?= $stats['acts_draft'] ?? 0 ?></div>
                    <div class="text-muted small">Актов в черновике</div>
                </div>
                <span class="badge bg-warning ms-auto align-self-start">ГОТОВО?</span>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl-4">
        <a href="/acts?status=approved" class="card border-0 shadow-sm h-100 text-decoration-none text-reset">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:56px;height:56px;background:rgba(22,163,74,.12);color:var(--brand-success);font-size:1.6rem;">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold"><?= $stats['acts_approved'] ?? 0 ?></div>
                    <div class="text-muted small">Актов утверждено</div>
                </div>
                <span class="badge bg-success ms-auto align-self-start">ГОТОВО</span>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100 <?= ($stats['overdue_docs'] ?? 0) > 0 ? 'border-danger' : '' ?>">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:56px;height:56px;background:rgba(220,38,38,.1);color:var(--brand-danger);font-size:1.6rem;">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold"><?= $stats['overdue_docs'] ?? 0 ?></div>
                    <div class="text-muted small">Просроченных документов</div>
                </div>
                <?php if (($stats['overdue_docs'] ?? 0) > 0): ?>
                <span class="badge bg-danger ms-auto align-self-start">СРОЧНО</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100 <?= ($stats['critical_today'] ?? 0) > 0 ? 'border-danger' : '' ?>">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:56px;height:56px;background:rgba(220,38,38,.1);color:var(--brand-danger);font-size:1.6rem;">
                    <i class="bi bi-shield-exclamation"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold"><?= $stats['deviations_today'] ?? 0 ?></div>
                    <div class="text-muted small">Отклонений сегодня (<?= $stats['critical_today'] ?? 0 ?> критич.)</div>
                </div>
                <?php if (($stats['critical_today'] ?? 0) > 0): ?>
                <span class="badge bg-danger ms-auto align-self-start">ВНИМАНИЕ</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100 bg-primary text-white">
            <div class="card-body d-flex flex-column justify-content-center gap-2 p-4">
                <a href="/flights/create" class="btn btn-light text-primary fw-semibold">
                    <i class="bi bi-plus-circle me-1"></i>Новый рейс
                </a>
                <a href="/flights/import" class="btn btn-outline-light">
                    <i class="bi bi-cloud-arrow-down me-1"></i>Импорт разрешения
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Широкие карточки-сводки -->
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-airplane"></i>Рейсы сегодня
            </div>
            <?php if (empty($todayFlights)): ?>
            <div class="card-body text-center text-muted py-4 small">Рейсов на сегодня нет</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach (array_slice($todayFlights, 0, 6) as $f): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                <div>
                    <span class="fw-semibold small"><?= e($f['flight_number']) ?></span>
                    <span class="text-muted small"> · <?= e($f['airline_name'] ?? '—') ?></span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <span class="text-muted small"><?= $f['scheduled_time'] ? substr($f['scheduled_time'],0,5) : '—' ?></span>
                    <?= opBadge($f['operation_type']) ?>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
            <?php if (count($todayFlights) > 6): ?>
            <div class="card-footer bg-white text-center small text-muted">и ещё <?= count($todayFlights) - 6 ?>…</div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="card-footer bg-white text-center">
                <a href="/flights" class="btn btn-sm btn-outline-primary w-100">Все рейсы →</a>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-list-check"></i>Требует внимания
            </div>
            <?php
                $attention = [];
                if (($stats['overdue_docs'] ?? 0) > 0) {
                    $attention[] = ['icon' => 'bi-exclamation-triangle', 'color' => 'danger', 'text' => $stats['overdue_docs'] . ' просроченных документов', 'href' => '/deviations'];
                }
                if (!empty($pendingConfirmationActs)) {
                    $attention[] = ['icon' => 'bi-pen', 'color' => 'success', 'text' => count($pendingConfirmationActs) . ' актов подписаны на планшете — ждут подтверждения', 'href' => '/acts'];
                }
                if (!empty($draftActs)) {
                    $attention[] = ['icon' => 'bi-file-earmark-text', 'color' => 'warning', 'text' => count($draftActs) . ' актов в черновике', 'href' => '/acts?status=draft'];
                }
                if (!empty($recentDeviations)) {
                    $attention[] = ['icon' => 'bi-shield-exclamation', 'color' => 'danger', 'text' => count($recentDeviations) . ' отклонений сегодня', 'href' => '/deviations'];
                }
            ?>
            <?php if (empty($attention)): ?>
            <div class="card-body text-center text-muted py-4 small">
                <i class="bi bi-check-circle fs-4 d-block mb-2 text-success opacity-75"></i>
                Всё спокойно
            </div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($attention as $item): ?>
            <li class="list-group-item py-2">
                <a href="<?= e($item['href']) ?>" class="d-flex align-items-center gap-2 text-decoration-none text-reset">
                    <i class="bi <?= $item['icon'] ?> text-<?= $item['color'] ?>"></i>
                    <span class="small"><?= e($item['text']) ?></span>
                </a>
            </li>
            <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-clock-history"></i>Последние отклонения
            </div>
            <?php if (empty($recentDeviations)): ?>
            <div class="card-body text-center text-muted py-4 small">Отклонений сегодня нет</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($recentDeviations as $d): ?>
            <?php $sc = match($d['severity']) { 'critical'=>'danger','significant'=>'warning','minor'=>'secondary', default=>'secondary' }; ?>
            <li class="list-group-item py-2">
                <div class="d-flex align-items-start gap-2">
                    <span class="rounded-circle bg-<?= $sc ?> flex-shrink-0 mt-1" style="width:8px;height:8px;"></span>
                    <div>
                        <div class="small"><?= e($d['flight_number']) ?> · +<?= $d['delay_minutes'] ?> мин · <?= e($d['doc_type']) ?></div>
                        <div class="text-muted" style="font-size:0.72rem"><?= e($d['airline_name']) ?></div>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
            <div class="card-footer bg-white text-center">
                <a href="/deviations" class="btn btn-sm btn-outline-danger w-100">Все отклонения →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="text-muted small mt-4 pt-3 border-top">
    <i class="bi bi-info-circle me-1"></i>Это обзор дня. Плотная рабочая таблица со всеми рейсами и фильтрами — на странице
    <a href="/flights">«Рейсы»</a>.
</div>
