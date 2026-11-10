<?php include __DIR__ . '/_status_badge.php'; ?>

<!-- Счётчики ЦОК -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center p-3">
                <div class="fs-2 fw-bold text-primary"><?= $stats['flights_today'] ?? 0 ?></div>
                <div class="small text-muted">Рейсов сегодня</div>
                <div class="mt-1 d-flex justify-content-center gap-1">
                    <span class="badge bg-info text-dark" style="font-size:0.65rem">↓ <?= $stats['arrivals_today'] ?? 0 ?></span>
                    <span class="badge bg-warning text-dark" style="font-size:0.65rem">↑ <?= $stats['departures_today'] ?? 0 ?></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center p-3">
                <div class="fs-2 fw-bold text-warning"><?= $stats['acts_draft'] ?? 0 ?></div>
                <div class="small text-muted">Актов в черновике</div>
                <a href="/acts?status=draft" class="small text-warning">открыть →</a>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center p-3">
                <div class="fs-2 fw-bold text-success"><?= $stats['acts_approved'] ?? 0 ?></div>
                <div class="small text-muted">Актов утверждено</div>
                <a href="/acts?status=approved" class="small text-success">открыть →</a>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100 <?= ($stats['overdue_docs'] ?? 0) > 0 ? 'border-danger' : '' ?>">
            <div class="card-body text-center p-3">
                <div class="fs-2 fw-bold <?= ($stats['overdue_docs'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>">
                    <?= $stats['overdue_docs'] ?? 0 ?>
                </div>
                <div class="small text-muted">Просроченных доков</div>
                <?php if (($stats['overdue_docs'] ?? 0) > 0): ?>
                <a href="/deviations" class="small text-danger">смотреть →</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100 <?= ($stats['critical_today'] ?? 0) > 0 ? 'border-danger' : '' ?>">
            <div class="card-body text-center p-3">
                <div class="fs-2 fw-bold <?= ($stats['critical_today'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>">
                    <?= $stats['deviations_today'] ?? 0 ?>
                </div>
                <div class="small text-muted">Отклонений сегодня</div>
                <?php if (($stats['critical_today'] ?? 0) > 0): ?>
                <span class="small text-danger">🔴 <?= $stats['critical_today'] ?> критич.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100 bg-primary text-white">
            <div class="card-body d-flex flex-column justify-content-center p-3 gap-1">
                <a href="/flights/create" class="btn btn-sm btn-light text-primary fw-semibold">
                    <i class="bi bi-plus-circle me-1"></i>Новый рейс
                </a>
                <a href="/flights" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-airplane me-1"></i>Рейсы → Форма А
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Рейсы сегодня -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-airplane me-2"></i>Рейсы сегодня</span>
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
                <table class="table table-hover align-middle mb-0 small">
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
                            <a href="/flights/<?= (int)$f['id'] ?>"
                               class="btn btn-sm btn-outline-primary py-0">
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
    </div>

    <!-- Правая колонка -->
    <div class="col-lg-4 d-flex flex-column gap-4">

        <!-- ДОБАВЛЕНО (06.08.2026): акты, подписанные на планшете обеими
             сторонами (супервайзер + представитель авиакомпании), но ещё не
             подтверждённые ЦОК. Раньше об этом узнавали, только если сами
             зайдут проверить — теперь ещё и уведомление приходит в колокольчик,
             а здесь видно сразу при входе в систему. -->
        <?php if (!empty($pendingConfirmationActs)): ?>
        <div class="card border-0 shadow-sm border-start border-success border-3">
            <div class="card-header bg-white fw-semibold text-success">
                <i class="bi bi-pen me-2"></i>Подписаны на планшете — ждут подтверждения
            </div>
            <ul class="list-group list-group-flush">
            <?php foreach ($pendingConfirmationActs as $a): ?>
            <li class="list-group-item py-2 px-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold small"><?= e(act_display_number($a)) ?></div>
                        <div class="text-muted" style="font-size:0.75rem">
                            <?= e($a['flight_number']) ?> · <?= e($a['flight_date']) ?> · <?= e($a['airline_name']) ?>
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="/acts/<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-secondary py-0">
                            <i class="bi bi-eye"></i>
                        </a>
                        <form method="POST" action="/acts/<?= (int)$a['id'] ?>/status" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="new_status" value="approved">
                            <button type="submit" class="btn btn-sm btn-success py-0">
                                <i class="bi bi-check-lg"></i> Подтвердить
                            </button>
                        </form>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Черновики актов -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-file-earmark-text text-warning me-2"></i>
                Акты — черновики
            </div>
            <?php if (empty($draftActs)): ?>
            <div class="card-body text-center text-muted py-3 small">Черновиков нет</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($draftActs as $a): ?>
            <li class="list-group-item py-2 px-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold small"><?= e(act_display_number($a)) ?></div>
                    <div class="text-muted" style="font-size:0.75rem">
                        <?= e($a['flight_number']) ?> · <?= e($a['flight_date']) ?>
                    </div>
                </div>
                <a href="/acts/<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-warning py-0">
                    <i class="bi bi-pencil"></i>
                </a>
            </li>
            <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div class="card-footer bg-white text-center">
                <a href="/flights" class="btn btn-sm btn-success w-100">
                    <i class="bi bi-airplane me-1"></i>К рейсам — сформировать Форму А
                </a>
            </div>
        </div>

        <!-- Последние отклонения -->
        <?php if (!empty($recentDeviations)): ?>
        <div class="card border-0 shadow-sm border-start border-danger border-3">
            <div class="card-header bg-white fw-semibold text-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>Отклонения сегодня
            </div>
            <ul class="list-group list-group-flush">
            <?php foreach ($recentDeviations as $d): ?>
            <?php
                $sc = match($d['severity']) { 'critical'=>'danger','significant'=>'warning','minor'=>'secondary', default=>'secondary' };
            ?>
            <li class="list-group-item py-2 px-3">
                <div class="d-flex justify-content-between">
                    <span class="fw-semibold small"><?= e($d['flight_number']) ?></span>
                    <span class="badge bg-<?= $sc ?>" style="font-size:0.65rem">
                        <?= match($d['severity']){'critical'=>'Критич.','significant'=>'Сущ.','minor'=>'Незнач.',default=>$d['severity']} ?>
                    </span>
                </div>
                <div class="text-muted" style="font-size:0.75rem">
                    +<?= $d['delay_minutes'] ?> мин · <?= e($d['doc_type']) ?>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
            <div class="card-footer bg-white text-center">
                <a href="/deviations" class="btn btn-sm btn-outline-danger w-100">Все отклонения</a>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
