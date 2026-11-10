<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-dark"><?= $stats['acts_month'] ?? 0 ?></div>
            <div class="small text-muted">Актов за месяц</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-2 fw-bold text-info">$&nbsp;<?= number_format($stats['revenue_month'] ?? 0, 0) ?></div>
            <div class="small text-muted">Выручка USD (месяц)</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 bg-dark text-white">
            <a href="/acts" class="btn btn-outline-light btn-sm mb-1">Все акты</a>
            <a href="/tariffs" class="btn btn-outline-light btn-sm">Тарифы</a>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <a href="/deviations" class="btn btn-outline-danger btn-sm mb-1 w-100">Отклонения</a>
            <a href="/flights" class="btn btn-outline-secondary btn-sm w-100">Рейсы</a>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Выручка по авиакомпаниям -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-graph-up me-2"></i>Выручка по авиакомпаниям (месяц)
            </div>
            <?php if (empty($revenueByAirline)): ?>
            <div class="card-body text-center text-muted py-4">Нет данных</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Авиакомпания</th><th>Актов</th><th class="text-end">USD</th></tr>
                    </thead>
                    <tbody>
                    <?php
                    $maxRev = max(array_column($revenueByAirline, 'revenue_usd') ?: [1]);
                    foreach ($revenueByAirline as $r):
                        $pct = $maxRev > 0 ? round($r['revenue_usd'] / $maxRev * 100) : 0;
                    ?>
                    <tr>
                        <td>
                            <?php if ($r['iata_code']): ?>
                            <span class="badge bg-light text-dark border me-1"><?= e($r['iata_code']) ?></span>
                            <?php endif; ?>
                            <?= e($r['airline_name']) ?>
                            <div class="progress mt-1" style="height:3px">
                                <div class="progress-bar bg-info" style="width:<?= $pct ?>%"></div>
                            </div>
                        </td>
                        <td><?= $r['acts_count'] ?></td>
                        <td class="text-end fw-bold">$&nbsp;<?= number_format((float)$r['revenue_usd'], 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Акты этой недели -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-file-earmark-check me-2"></i>Акты этой недели
            </div>
            <?php if (empty($weekActs)): ?>
            <div class="card-body text-center text-muted py-4">Нет актов</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($weekActs as $a): ?>
            <li class="list-group-item py-2 px-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold small"><?= e($a['act_number']) ?></div>
                        <div class="text-muted" style="font-size:0.75rem">
                            <?= e($a['flight_number']) ?> · <?= e($a['airline_name']) ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <?php
                        $sc = match($a['status']){'approved'=>'success','exported'=>'primary','cancelled'=>'danger',default=>'warning'};
                        $sl = match($a['status']){'approved'=>'Утв.','exported'=>'Экспорт.','cancelled'=>'Отм.',default=>'Черн.'};
                        ?>
                        <span class="badge bg-<?= $sc ?>"><?= $sl ?></span>
                        <div class="text-muted" style="font-size:0.7rem">
                            $&nbsp;<?= number_format((float)$a['total_amount_without_vat'],0) ?>
                        </div>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Актуальные тарифы -->
    <div class="col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-currency-dollar me-2"></i>Активные тарифы
            </div>
            <?php if (empty($recentTariffs)): ?>
            <div class="card-body text-center text-muted py-4">Нет тарифов</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($recentTariffs as $t): ?>
            <li class="list-group-item py-2 px-3">
                <div class="small fw-semibold text-truncate"><?= e($t['service_name']) ?></div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted" style="font-size:0.75rem">с <?= e($t['valid_from']) ?></span>
                    <span class="fw-bold small">$&nbsp;<?= number_format((float)$t['price'],2) ?></span>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
            <div class="card-footer bg-white text-center">
                <a href="/tariffs" class="btn btn-sm btn-outline-dark w-100">Все тарифы</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
