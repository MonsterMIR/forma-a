<?php
use App\Core\Auth;
use App\Core\Permissions;
use App\Services\DeadlineService;

$user = Auth::user();
$role = $user['role_code'] ?? '';
$now  = time();
?>

<div class="card border-0 shadow-sm mt-4" id="deadlines">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-clock-history me-2"></i>Контроль сроков документооборота</span>
        <?php if (in_array($role, ['admin', 'cok']) && !($flight['documents_initialized'] ?? false)): ?>
        <button class="btn btn-sm btn-outline-primary" id="btn-init-docs"
                data-flight-id="<?= (int)$flight['id'] ?>">
            <i class="bi bi-play-circle me-1"></i>Инициализировать
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($deadlineDocs)): ?>
    <div class="card-body text-center py-4 text-muted">
        <i class="bi bi-clock fs-2 d-block mb-2"></i>
        <?php if ($flight['scheduled_time'] ?? null): ?>
            Нажмите «Инициализировать» для создания контрольных точек сроков
        <?php else: ?>
            Укажите плановое время рейса (STD/STA) для контроля сроков
        <?php endif; ?>
    </div>
    <?php else: ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Служба</th>
                    <th>Документ</th>
                    <th>Дедлайн</th>
                    <th>Срок</th>
                    <th>Статус</th>
                    <th>Подано</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($deadlineDocs as $doc): ?>
            <?php
                $color     = $doc['color'];
                $deadlineTs = $doc['deadline_at'] ? strtotime($doc['deadline_at']) : null;
                $timeLeft   = $doc['time_left'];
                $canSubmit  = in_array($role, ['admin', 'cok']) || $doc['service_role'] === $role;
                $isPending  = $doc['status'] === 'pending';
            ?>
            <tr class="<?= $doc['status'] === 'late' ? 'table-warning' : ($color === 'danger' ? 'table-danger' : '') ?>">
                <td>
                    <span class="badge bg-secondary"><?= DeadlineService::roleLabel($doc['service_role']) ?></span>
                </td>
                <td class="fw-medium"><?= e($doc['label']) ?></td>
                <td class="text-nowrap">
                    <?php if ($doc['deadline_at']): ?>
                        <?= date('d.m H:i', strtotime($doc['deadline_at'])) ?>
                        <div class="text-muted" style="font-size:0.7rem">
                            <?= $doc['deadline_minutes'] < 0 ? 'за ' . abs($doc['deadline_minutes']) . ' мин до' : 'через ' . $doc['deadline_minutes'] . ' мин после' ?>
                        </div>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-nowrap">
                    <?php if ($isPending && $timeLeft !== null): ?>
                        <?php if ($timeLeft < 0): ?>
                            <span class="text-danger fw-bold">
                                <i class="bi bi-clock-fill me-1"></i>
                                +<?= abs((int)ceil(-$timeLeft/60)) ?> мин
                            </span>
                        <?php elseif ($timeLeft < 900): ?>
                            <span class="text-warning fw-bold">
                                <i class="bi bi-clock me-1"></i>
                                <?= (int)ceil($timeLeft/60) ?> мин
                            </span>
                        <?php else: ?>
                            <span class="text-success">
                                <?= (int)floor($timeLeft/3600) ?>ч <?= (int)(($timeLeft%3600)/60) ?>мин
                            </span>
                        <?php endif; ?>
                    <?php elseif ($doc['status'] === 'submitted'): ?>
                        <span class="text-success"><i class="bi bi-check-circle me-1"></i>Вовремя</span>
                    <?php elseif ($doc['status'] === 'late'): ?>
                        <span class="text-warning"><i class="bi bi-exclamation-circle me-1"></i>+<?= $doc['delay_min'] ?? '?' ?> мин</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $statusMap = [
                        'pending'   => ['secondary', 'Ожидание'],
                        'submitted' => ['success',   'Подано'],
                        'late'      => ['warning',   'С опозд.'],
                        'accepted'  => ['success',   'Принято'],
                    ];
                    [$sc, $sl] = $statusMap[$doc['status']] ?? ['secondary', $doc['status']];
                    ?>
                    <span class="badge bg-<?= $sc ?>"><?= $sl ?></span>
                </td>
                <td class="text-muted text-nowrap">
                    <?php if ($doc['submitted_at']): ?>
                        <?= date('d.m H:i', strtotime($doc['submitted_at'])) ?>
                        <?php if ($doc['submitted_by_name']): ?>
                        <div style="font-size:0.7rem"><?= e($doc['submitted_by_name']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <?php if ($isPending && $canSubmit): ?>
                    <button class="btn btn-sm btn-outline-success btn-submit-doc"
                            data-doc-id="<?= (int)$doc['id'] ?>"
                            data-doc-label="<?= e($doc['label']) ?>">
                        <i class="bi bi-check-lg"></i> Подать
                    </button>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Отклонения по рейсу -->
<?php if (!empty($flightDeviations)): ?>
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-exclamation-triangle text-danger me-2"></i>
        Отклонения по рейсу <span class="badge bg-danger"><?= count($flightDeviations) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 small">
            <thead class="table-light">
                <tr><th>Служба</th><th>Документ</th><th>Тяжесть</th><th>Опозд.</th><th>Описание</th></tr>
            </thead>
            <tbody>
            <?php foreach ($flightDeviations as $dev): ?>
            <tr>
                <td><span class="badge bg-secondary"><?= DeadlineService::roleLabel($dev['service_role']) ?></span></td>
                <td><?= DeadlineService::docTypeLabel($dev['doc_type']) ?></td>
                <td>
                    <?php $sc = match($dev['severity']) { 'minor'=>'warning', 'significant'=>'orange', 'critical'=>'danger', default=>'secondary' }; ?>
                    <?php if ($dev['severity'] === 'significant'): ?>
                    <span class="badge text-white" style="background:#fd7e14"><?= DeadlineService::severityLabel($dev['severity']) ?></span>
                    <?php else: ?>
                    <span class="badge bg-<?= $sc ?>"><?= DeadlineService::severityLabel($dev['severity']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="fw-bold text-<?= $dev['delay_minutes'] > 60 ? 'danger' : 'warning' ?>">
                    +<?= $dev['delay_minutes'] ?> мин
                </td>
                <td class="text-muted"><?= e($dev['description'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
// Инициализация контрольных точек
document.getElementById('btn-init-docs')?.addEventListener('click', function() {
    const flightId = this.dataset.flightId;
    fetch(`/flights/${flightId}/init-deadlines`, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || ''
        },
        body: JSON.stringify({})
    })
    .then(r => r.json())
    .then(data => { if (data.success) location.reload(); });
});

// Подача документа
document.querySelectorAll('.btn-submit-doc').forEach(btn => {
    btn.addEventListener('click', function() {
        const docId = this.dataset.docId;
        const label = this.dataset.docLabel;
        if (!confirm(`Подтвердить подачу документа:\n«${label}»?`)) return;

        fetch(`/documents/${docId}/submit`, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || ''
            },
            body: JSON.stringify({})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (data.deviation) {
                    const sev = {minor:'⚠️ Незначительное', significant:'🟠 Существенное', critical:'🔴 Критическое'};
                    alert(`Документ подан с опозданием!\n${sev[data.deviation.severity] || ''}\nЗадержка: ${data.deviation.delay_minutes} мин`);
                }
                location.reload();
            } else {
                alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
            }
        });
    });
});
</script>
