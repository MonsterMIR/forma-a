<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-file-earmark-lock2"></i> Разрешение #<?= (int)$record['id'] ?></h2>
    <a href="/permit-logs" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> К списку</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body small">
                <div class="mb-1"><span class="text-muted">Когда:</span> <?= format_datetime($record['created_at']) ?></div>
                <div class="mb-1"><span class="text-muted">Кто ввёл:</span> <?= e($record['username'] ?? '—') ?></div>
                <div class="mb-0"><span class="text-muted">IP:</span> <?= e($record['ip_address'] ?? '—') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted mb-2">Созданные из этого текста рейсы:</div>
                <?php if (empty($flights)): ?>
                    <span class="text-muted small">Ни одного рейса не было создано (разбор не завершён, отменён, или всё отклонено на шаге проверки).</span>
                <?php else: ?>
                    <?php foreach ($flights as $f): ?>
                        <a href="/flights/<?= (int)$f['id'] ?>" class="badge bg-light text-dark border text-decoration-none me-1 mb-1">
                            <?= e($f['flight_number']) ?> (<?= $f['operation_type'] === 'arrival' ? 'прибытие' : 'вылет' ?>, <?= format_date($f['flight_date']) ?>)
                            <?= !empty($f['aircraft_registration']) ? ' — ' . e($f['aircraft_registration']) : '' ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><strong>Исходный текст разрешения (как было вставлено)</strong></div>
    <div class="card-body">
        <pre class="small bg-light p-3 rounded" style="white-space: pre-wrap; word-break: break-word;"><?= e($record['raw_text']) ?></pre>
    </div>
</div>
