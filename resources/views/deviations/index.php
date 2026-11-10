<?php
use App\Services\DeadlineService;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Журнал отклонений</h1>
</div>

<!-- Сводка по службам -->
<?php if (!empty($summary)): ?>
<div class="row g-3 mb-4">
    <?php foreach ($summary as $s): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="fw-semibold mb-2"><?= DeadlineService::roleLabel($s['service_role']) ?></div>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge bg-warning text-dark">
                        <i class="bi bi-exclamation-circle me-1"></i><?= $s['minor'] ?> незнач.
                    </span>
                    <span class="badge" style="background:#fd7e14">
                        <i class="bi bi-exclamation-triangle me-1"></i><?= $s['significant'] ?> сущ.
                    </span>
                    <span class="badge bg-danger">
                        <i class="bi bi-x-circle me-1"></i><?= $s['critical'] ?> крит.
                    </span>
                </div>
                <div class="text-muted small mt-2">Всего: <?= $s['total'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Фильтры -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Тяжесть</label>
                <select name="severity" class="form-select form-select-sm">
                    <option value="">Все</option>
                    <option value="minor"       <?= $filters['severity'] === 'minor' ? 'selected' : '' ?>>Незначительное</option>
                    <option value="significant" <?= $filters['severity'] === 'significant' ? 'selected' : '' ?>>Существенное</option>
                    <option value="critical"    <?= $filters['severity'] === 'critical' ? 'selected' : '' ?>>Критическое</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Служба</label>
                <select name="service_role" class="form-select form-select-sm">
                    <option value="">Все службы</option>
                    <?php foreach (['sop'=>'СОП','spo'=>'СПО','sst'=>'ССТ','aero'=>'Аэродромная','cok'=>'ЦОК'] as $code=>$name): ?>
                    <option value="<?= $code ?>" <?= $filters['service_role'] === $code ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">С даты</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">По дату</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-funnel me-1"></i>Фильтр
                </button>
            </div>
            <div class="col-md-2">
                <a href="/deviations" class="btn btn-outline-secondary btn-sm w-100">Сбросить</a>
            </div>
        </form>
    </div>
</div>

<!-- Таблица -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Отклонения <span class="badge bg-secondary"><?= $total ?></span></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Дата</th>
                    <th>Рейс</th>
                    <th>Авиакомпания</th>
                    <th>Служба</th>
                    <th>Документ</th>
                    <th>Тяжесть</th>
                    <th>Опозд.</th>
                    <th>Описание</th>
                    <th>Зарег.</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($deviations)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">Отклонений не найдено</td></tr>
            <?php endif; ?>
            <?php foreach ($deviations as $d): ?>
            <?php
                $sevColor = match($d['severity']) {
                    'minor'       => 'warning',
                    'significant' => 'orange',
                    'critical'    => 'danger',
                    default       => 'secondary'
                };
            ?>
            <tr>
                <td class="text-nowrap"><?= e($d['flight_date']) ?></td>
                <td>
                    <a href="/flights/<?= (int)$d['flight_id'] ?>#deadlines" class="fw-semibold text-decoration-none">
                        <?= e($d['flight_number']) ?>
                    </a>
                    <div class="small text-muted">
                        <?= $d['operation_type'] === 'arrival' ? '↓ Прилёт' : '↑ Вылет' ?>
                    </div>
                </td>
                <td><?= e($d['airline_name']) ?></td>
                <td>
                    <span class="badge bg-secondary"><?= DeadlineService::roleLabel($d['service_role']) ?></span>
                </td>
                <td class="small"><?= DeadlineService::docTypeLabel($d['doc_type']) ?></td>
                <td>
                    <?php if ($d['severity'] === 'significant'): ?>
                        <span class="badge text-white" style="background:#fd7e14">
                            <i class="bi bi-exclamation-triangle me-1"></i>Существенное
                        </span>
                    <?php else: ?>
                        <span class="badge bg-<?= $sevColor ?>">
                            <i class="bi bi-<?= $d['severity'] === 'critical' ? 'x-circle' : 'exclamation-circle' ?> me-1"></i>
                            <?= DeadlineService::severityLabel($d['severity']) ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td class="fw-bold <?= $d['delay_minutes'] > 60 ? 'text-danger' : ($d['delay_minutes'] > 15 ? 'text-warning' : '') ?>">
                    +<?= $d['delay_minutes'] ?> мин
                </td>
                <td class="small text-muted" style="max-width:200px">
                    <?= e($d['description'] ?? '—') ?>
                </td>
                <td class="small text-muted text-nowrap">
                    <?= e($d['registered_by_name'] ?? 'авто') ?><br>
                    <?= $d['created_at'] ? date('d.m H:i', strtotime($d['created_at'])) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Пагинация -->
    <?php if ($total > $perPage): ?>
    <div class="card-footer bg-white">
        <?php
        $totalPages = (int)ceil($total / $perPage);
        $queryBase  = http_build_query(array_diff_key($filters, ['page'=>'']));
        ?>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= $queryBase ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
