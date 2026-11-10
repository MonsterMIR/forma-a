<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-bell me-2"></i>Уведомления</h2>
</div>

<?php if (empty($notifications)): ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-bell-slash fs-1 d-block mb-2 opacity-50"></i>
    Уведомлений пока нет.
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <ul class="list-group list-group-flush">
        <?php foreach ($notifications as $n): ?>
        <li class="list-group-item py-3 <?= empty($n['read_at']) ? 'bg-light' : '' ?>">
            <a href="<?= e($n['url'] ?? '#') ?>" class="text-decoration-none text-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="fw-semibold">
                        <?php if (empty($n['read_at'])): ?><i class="bi bi-circle-fill text-primary" style="font-size:8px;"></i><?php endif; ?>
                        <?= e($n['title']) ?>
                    </div>
                    <div class="text-muted small flex-shrink-0"><?= e(format_datetime($n['created_at'])) ?></div>
                </div>
                <?php if (!empty($n['body'])): ?>
                <div class="text-muted small mt-1"><?= e($n['body']) ?></div>
                <?php endif; ?>
                <?php if (!empty($n['flight_number'])): ?>
                <div class="text-muted mt-1" style="font-size:0.75rem">
                    <i class="bi bi-airplane"></i> <?= e($n['flight_number']) ?> · <?= e(format_date($n['flight_date'])) ?>
                </div>
                <?php endif; ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
