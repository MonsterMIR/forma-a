<?php
/**
 * ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): детальная карточка акта в личном
 * кабинете представителя — только просмотр ключевых полей + скачивание
 * PDF. Полная печатная форма (со всеми юридическими реквизитами) доступна
 * через /portal/acts/{id}/pdf — см. ActPdfService (переиспользует ту же
 * разметку, что и acts/print.php, см. ActController::renderPrintHtml()).
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-file-earmark-text"></i> Акт № <?= e($act['act_number']) ?></h4>
    <a href="/portal" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> К списку</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted small">Рейс</div>
                <div class="fw-semibold"><?= e($act['flight_number']) ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Дата рейса</div>
                <div class="fw-semibold"><?= e($act['flight_date']) ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Статус</div>
                <div><?= status_badge($act['status']) ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Авиакомпания</div>
                <div class="fw-semibold"><?= e($act['airline_name'] ?? '') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Тип/рег. номер ВС</div>
                <div class="fw-semibold"><?= e($act['aircraft_type'] ?? '') ?> / <?= e($act['aircraft_registration'] ?? '') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Утверждён</div>
                <div class="fw-semibold"><?= $act['approved_at'] ? e($act['approved_at']) : '—' ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Сумма без НДС</div>
                <div class="fw-semibold"><?= number_format((float)$act['total_amount_without_vat'], 2) ?> <?= e($act['currency_symbol'] ?? $act['currency_code'] ?? '') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">НДС</div>
                <div class="fw-semibold"><?= number_format((float)$act['total_vat_amount'], 2) ?> <?= e($act['currency_symbol'] ?? $act['currency_code'] ?? '') ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Сумма с НДС</div>
                <div class="fw-bold fs-5"><?= number_format((float)$act['total_amount_with_vat'], 2) ?> <?= e($act['currency_symbol'] ?? $act['currency_code'] ?? '') ?></div>
            </div>
        </div>
    </div>
</div>

<a href="/portal/acts/<?= (int)$act['id'] ?>/pdf" class="btn btn-primary">
    <i class="bi bi-file-earmark-pdf"></i> Скачать PDF
</a>
