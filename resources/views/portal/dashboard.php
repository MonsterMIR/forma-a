<?php
/**
 * ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): главная страница личного кабинета
 * представителя — список утверждённых/выгруженных актов своего хендлера
 * (см. RepresentativeController::dashboard(), Act::getApprovedByHandler()).
 */
?>
<h4 class="mb-3"><i class="bi bi-file-earmark-text"></i> Мои акты (форма А)</h4>

<?php if (empty($acts)): ?>
    <div class="alert alert-secondary">
        Пока нет утверждённых актов, доступных для просмотра. Как только акт по вашему
        рейсу будет утверждён ЦОК, он появится здесь.
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>№ акта</th>
                        <th>Рейс</th>
                        <th>Дата рейса</th>
                        <th>Авиакомпания</th>
                        <th>Статус</th>
                        <th class="text-end">Сумма с НДС</th>
                        <th>Утверждён</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($acts as $act): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($act['act_number']) ?></td>
                        <td><?= e($act['flight_number']) ?></td>
                        <td><?= e($act['flight_date']) ?></td>
                        <td><?= e($act['airline_name']) ?> <span class="text-muted">(<?= e($act['airline_iata'] ?? '') ?>)</span></td>
                        <td><?= status_badge($act['status']) ?></td>
                        <td class="text-end"><?= number_format((float)$act['total_amount_with_vat'], 2) ?> <?= e($act['currency_symbol'] ?? $act['currency_code'] ?? '') ?></td>
                        <td><?= $act['approved_at'] ? e($act['approved_at']) : '—' ?></td>
                        <td class="text-end">
                            <a href="/portal/acts/<?= (int)$act['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i> Открыть
                            </a>
                            <a href="/portal/acts/<?= (int)$act['id'] ?>/pdf" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-file-earmark-pdf"></i> PDF
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
