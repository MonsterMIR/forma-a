<?php if (!empty($manifest)): ?>
    <?php $manifestSubmitted = in_array($manifest['status'], ['submitted', 'accepted'], true); ?>
<div class="alert <?= $manifestSubmitted ? 'alert-success' : 'alert-warning' ?> d-flex align-items-start gap-2 mb-3">
    <i class="bi <?= $manifestSubmitted ? 'bi-clipboard-check-fill' : 'bi-hourglass-split' ?> fs-4 flex-shrink-0"></i>
    <div class="flex-grow-1">
        <?php if ($manifestSubmitted): ?>
        <div class="fw-semibold">
            Манифест подан
            <?php if (!empty($manifest['submitted_at'])): ?>
            <span class="fw-normal text-muted small">— <?= e($manifest['submitted_at']) ?></span>
            <?php endif; ?>
            <?php if (!empty($manifest['created_by_name'])): ?>
            <span class="fw-normal text-muted small">(<?= e($manifest['created_by_name']) ?>, СОП)</span>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-3 mt-1 small">
            <span><i class="bi bi-people-fill me-1"></i>Пассажиры: <strong><?= (int)$manifest['pax_total'] ?></strong>
                <span class="text-muted">(взр. <?= (int)$manifest['pax_adults'] ?>, дет. <?= (int)$manifest['pax_children'] ?>, млад. <?= (int)$manifest['pax_infants'] ?>)</span>
            </span>
            <span><i class="bi bi-person-badge-fill me-1"></i>Экипаж: <strong><?= (int)$manifest['pax_crew'] ?></strong></span>
            <?php if ((int)$manifest['pax_transit'] > 0): ?>
            <span><i class="bi bi-arrow-repeat me-1"></i>Транзит: <strong><?= (int)$manifest['pax_transit'] ?></strong></span>
            <?php endif; ?>
            <span><i class="bi bi-bag-fill me-1"></i>Багаж: <strong><?= (int)$manifest['baggage_pieces'] ?></strong> мест<?php if ((float)$manifest['baggage_weight_kg'] > 0): ?><span class="text-muted"> / <?= e(number_format((float)$manifest['baggage_weight_kg'], 1)) ?> кг</span><?php endif; ?></span>
            <?php if ((float)$manifest['cargo_weight_kg'] > 0): ?>
            <span><i class="bi bi-box-seam-fill me-1"></i>Груз: <strong><?= e(number_format((float)$manifest['cargo_weight_kg'], 1)) ?> кг</strong></span>
            <?php endif; ?>
            <?php if ((float)$manifest['mail_weight_kg'] > 0): ?>
            <span><i class="bi bi-envelope-fill me-1"></i>Почта: <strong><?= e(number_format((float)$manifest['mail_weight_kg'], 1)) ?> кг</strong></span>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="fw-semibold">Манифест пока только заполняется СОП — ещё не подан</div>
        <div class="small text-muted mt-1">Данные ниже предварительные и могут измениться: пассажиры <?= (int)$manifest['pax_total'] ?>, багаж <?= (int)$manifest['baggage_pieces'] ?> мест.</div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-circle-fill fs-4 flex-shrink-0"></i>
    <span>Манифест по этому рейсу ещё не заведён СОП — акт не сформируется, пока манифест не будет подан.</span>
</div>
<?php endif; ?>
