<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 <?= ($stats['acts_approved'] ?? 0) > 0 ? 'border-success' : '' ?>">
            <div class="fs-2 fw-bold text-success"><?= $stats['acts_approved'] ?? 0 ?></div>
            <div class="small text-muted">К экспорту в 1С</div>
            <?php if (($stats['acts_approved'] ?? 0) > 0): ?>
            <a href="/export" class="small text-success">экспортировать →</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-secondary"><?= $stats['acts_exported'] ?? 0 ?></div>
            <div class="small text-muted">Экспортировано</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-4 fw-bold text-primary">
                <?= number_format($stats['pending_usd'] ?? 0, 0) ?> $
            </div>
            <div class="small text-muted">К учёту (USD)</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-5 fw-bold text-primary">
                <?= number_format($stats['pending_uzs'] ?? 0, 0) ?> UZS
            </div>
            <div class="small text-muted">К учёту (UZS)</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Акты к экспорту -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">
                    <i class="bi bi-box-arrow-up me-2"></i>Утверждённые акты — к экспорту в 1С
                </span>
                <?php if (!empty($actsToExport)): ?>
                <a href="/export/xml" class="btn btn-sm btn-success">
                    <i class="bi bi-download me-1"></i>Экспорт XML
                </a>
                <?php endif; ?>
            </div>
            <?php if (empty($actsToExport)): ?>
            <div class="card-body text-center text-muted py-4">
                <i class="bi bi-check-circle fs-2 d-block mb-2 text-success opacity-50"></i>
                Все акты экспортированы
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Акт</th><th>Дата</th><th>Рейс</th>
                            <th>Авиакомпания</th><th class="text-end">Сумма</th><th>Валюта</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($actsToExport as $a): ?>
                    <tr>
                        <td class="fw-semibold font-monospace"><?= e($a['act_number']) ?></td>
                        <td><?= e($a['act_date']) ?></td>
                        <td><?= e($a['flight_number']) ?></td>
                        <td><?= e($a['airline_name']) ?></td>
                        <td class="text-end fw-bold">
                            <?= number_format((float)$a['total_amount_with_vat'], 2) ?>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= e($a['currency_code']) ?></span></td>
                        <td class="text-nowrap">
                            <a href="/acts/<?= (int)$a['id'] ?>/export/xml"
                               class="btn btn-sm btn-outline-success py-0" title="XML">
                                <i class="bi bi-filetype-xml"></i>
                            </a>
                            <a href="/acts/<?= (int)$a['id'] ?>"
                               class="btn btn-sm btn-outline-secondary py-0 ms-1">
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

    <!-- Последние экспортированные -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-check-circle text-secondary me-2"></i>Последние экспортированные
            </div>
            <?php if (empty($recentExported)): ?>
            <div class="card-body text-center text-muted py-4 small">Нет данных</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($recentExported as $a): ?>
            <li class="list-group-item py-2 px-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="fw-semibold small font-monospace"><?= e($a['act_number']) ?></div>
                        <div class="text-muted" style="font-size:0.75rem">
                            <?= e($a['flight_number']) ?> · <?= e($a['airline_name']) ?>
                        </div>
                    </div>
                    <span class="badge bg-secondary align-self-center">Экспорт.</span>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div class="card-footer bg-white text-center">
                <a href="/acts?status=exported" class="btn btn-sm btn-outline-secondary w-100">
                    История экспорта
                </a>
            </div>
        </div>
    </div>
</div>
