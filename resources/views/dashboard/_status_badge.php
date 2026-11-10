<?php
// Используется в партиалах: статус документа службы
function docStatusBadge(?string $status, string $labelOk = 'Подано', string $labelDraft = 'Черновик'): string {
    return match($status ?? '') {
        'submitted','accepted' => "<span class=\"badge bg-success\"><i class=\"bi bi-check-circle me-1\"></i>{$labelOk}</span>",
        'draft'                => "<span class=\"badge bg-warning text-dark\"><i class=\"bi bi-pencil me-1\"></i>{$labelDraft}</span>",
        default                => '<span class="badge bg-secondary"><i class="bi bi-dash me-1"></i>Не создан</span>',
    };
}
function opBadge(string $type): string {
    return $type === 'arrival'
        ? '<span class="badge bg-info text-dark">↓ Прил.</span>'
        : '<span class="badge bg-warning text-dark">↑ Выл.</span>';
}
