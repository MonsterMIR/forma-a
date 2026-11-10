<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-file-earmark-lock2"></i> Журнал разрешений</h2>
</div>

<div class="alert alert-secondary small">
    Исходный текст каждого разрешения, вставленного при импорте рейсов — сохраняется автоматически, до какого-либо
    редактирования. Видно только администратору.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small fw-semibold">Поиск (по тексту разрешения или логину)</label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Дата с</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Дата по</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold" title="Заполните только это поле, чтобы найти рейсы/акты/записи ровно за один день — само подставит ту же дату в поля «с»/«по» выше (round 164).">На дату</label>
                <input type="date" class="form-control form-control-sm" data-exact-date data-from="date_from" data-to="date_to">
            </div>
            <div class="col-auto d-flex gap-2 mt-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Фильтр</button>
                <a href="/permit-logs" class="btn btn-sm btn-outline-secondary">Сброс</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Когда</th>
                        <th>Кто ввёл</th>
                        <th>IP</th>
                        <th>Текст (начало)</th>
                        <th>Созданные рейсы</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Записей нет</td></tr>
                <?php else: ?>
                    <?php foreach ($records as $r): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td class="small text-muted text-nowrap"><?= format_datetime($r['created_at']) ?></td>
                        <td class="small"><?= e($r['username'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e($r['ip_address'] ?? '—') ?></td>
                        <td class="small text-muted" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= e(mb_substr(str_replace(["\r", "\n"], ' ', $r['raw_text']), 0, 90)) ?>
                        </td>
                        <td class="small">
                            <?php if (empty($r['flights'])): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <?php foreach ($r['flights'] as $f): ?>
                                    <a href="/flights/<?= (int)$f['flight_id'] ?>" class="badge bg-light text-dark border text-decoration-none">
                                        <?= e($f['flight_number']) ?> (<?= $f['operation_type'] === 'arrival' ? 'приб.' : 'выл.' ?>)
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="small"><a href="/permit-logs/<?= (int)$r['id'] ?>" class="btn btn-xs btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a></td>
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
