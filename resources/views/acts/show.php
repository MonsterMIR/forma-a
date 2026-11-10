<?php
/**
 * resources/views/acts/show.php
 * Просмотр акта — двуязычный, с кнопкой Print и улучшенным UX
 */
?>
<?php
// ИЗМЕНЕНО (14.10.2026, раунд 71b): текстовая расшифровка акта теперь
// считается один раз в ActService::buildTextBreakdown() (вызывается из
// ActController::show()) и приходит сюда готовой переменной — та же
// логика переиспользуется и в попапе на странице /acts (список актов).
// Фолбэк на пустую строку — на случай, если кто-то вызовет этот шаблон
// напрямую без передачи textBreakdown.
$textBreakdown = $textBreakdown ?? '';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0 d-flex align-items-center gap-2">
        <i class="bi bi-file-earmark-text text-primary"></i>
        <?= __('ACTS_TITLE') ?>: <span class="fw-bold"><?= e(act_display_number($act)) ?></span>
        <span class="ms-2 badge badge-status-<?= e($act['status']) ?>">
            <?= __('ACTS_STATUS_' . strtoupper($act['status'])) ?>
        </span>
    </h2>

    <div class="d-flex gap-2 flex-wrap print-hide">
        <!-- Print always available -->
        <a href="/acts/<?= (int)$act['id'] ?>/print" target="_blank"
           class="btn btn-outline-dark btn-sm">
            <i class="bi bi-printer"></i> <?= __('ACTS_PRINT_FORM') ?>
        </a>

        <!-- ДОБАВЛЕНО (14.10.2026, раунд 71): попап с текстовой расшифровкой
             содержания акта без НДС — см. $textBreakdown выше и модалку
             #textBreakdownModal в конце файла. -->
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-toggle="modal" data-bs-target="#textBreakdownModal"
                title="Текстовая расшифровка содержания акта (без НДС)">
            <i class="bi bi-card-text"></i> Расшифровка
        </button>

        <?php if ($act['status'] === 'approved'): ?>
        <a href="/acts/<?= (int)$act['id'] ?>/export/xml"
           class="btn btn-outline-success btn-sm"
           title="Скачать XML для 1С (Универсальный обмен данными)">
            <i class="bi bi-filetype-xml"></i> XML → 1С
        </a>
        <a href="/acts/<?= (int)$act['id'] ?>/export/excel"
           class="btn btn-outline-success btn-sm"
           title="Скачать CSV/Excel для загрузки в 1С">
            <i class="bi bi-filetype-csv"></i> CSV → 1С
        </a>
        <?php endif; ?>

        <?php if (has_role(['admin', 'cok']) && $act['status'] === 'draft'): ?>
        <a href="/acts/<?= (int)$act['id'] ?>/edit"
           class="btn btn-outline-primary btn-sm">
            <i class="bi bi-pencil"></i> <?= __('COMMON_EDIT') ?>
        </a>
        <?php endif; ?>

        <?php if (has_role(['admin', 'cok']) && $act['status'] === 'draft'): ?>
        <button class="btn btn-success btn-sm"
                data-bs-toggle="modal" data-bs-target="#approveModal">
            <i class="bi bi-check-circle"></i> <?= __('ACTS_APPROVE') ?>
        </button>
        <?php endif; ?>

        <?php if (has_role(['admin', 'cok']) && in_array($act['status'], ['draft', 'approved'])): ?>
        <button class="btn btn-outline-danger btn-sm"
                data-bs-toggle="modal" data-bs-target="#cancelModal">
            <i class="bi bi-x-circle"></i> <?= __('ACTS_CANCEL_ACT') ?>
        </button>
        <?php endif; ?>

        <a href="/acts" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?>
        </a>
    </div>
</div>

<?php if ($act['status'] !== 'draft'): ?>
<div class="alert alert-<?= $act['status'] === 'approved' ? 'success' : ($act['status'] === 'cancelled' ? 'danger' : 'primary') ?> d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-info-circle-fill"></i>
    <span>
        <?= __('ACTS_EDIT_BLOCKED', ['status' => __('ACTS_STATUS_' . strtoupper($act['status']))]) ?>
        <?php if ($act['status'] === 'approved' && $act['approved_by_user_id']): ?>
            &nbsp;—&nbsp; <?= __('ACTS_APPROVED_BY') ?>: <strong><?= e($act['approved_by_fullname']) ?></strong>
            (<?= format_datetime($act['approved_at']) ?>)
        <?php endif; ?>
    </span>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- ===== LEFT COLUMN ===== -->
    <div class="col-lg-5">

        <!-- Act Header Details -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-info-circle text-primary me-1"></i> <?= __('ACTS_DETAILS') ?></span>
                <span class="badge badge-status-<?= e($act['status']) ?>"><?= __('ACTS_STATUS_' . strtoupper($act['status'])) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr>
                        <td class="text-muted fw-semibold" style="width:42%"><?= __('ACTS_NUMBER') ?></td>
                        <td class="fw-bold"><?= e(act_display_number($act)) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_DATE') ?></td>
                        <td><?= format_date($act['act_date']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_FLIGHT') ?></td>
                        <td>
                            <a href="/flights/<?= (int)$act['flight_id'] ?>" class="text-decoration-none fw-semibold">
                                <?= e($act['flight_number']) ?>
                            </a>
                            <span class="text-muted ms-1"><?= format_date($act['flight_date']) ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_AIRLINE') ?></td>
                        <td><?= e($act['airline_name']) ?> <code><?= e($act['airline_iata'] ?? '') ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_ORIGIN') ?></td>
                        <td><?= e($act['origin_name'] ?? '—') ?> <?= $act['origin_iata'] ? '<code>' . e($act['origin_iata']) . '</code>' : '' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_DESTINATION') ?></td>
                        <td><?= e($act['destination_name'] ?? '—') ?> <?= $act['destination_iata'] ? '<code>' . e($act['destination_iata']) . '</code>' : '' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_ROUTE_TYPE') ?></td>
                        <td>
                            <?php if ($act['route_type'] === 'domestic'): ?>
                                <span class="badge bg-info text-dark"><?= __('FLIGHTS_DOMESTIC') ?></span>
                            <?php else: ?>
                                <span class="badge bg-primary"><?= __('FLIGHTS_INTERNATIONAL') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_OPERATION') ?></td>
                        <td>
                            <?php if ($act['operation_type'] === 'arrival'): ?>
                                <span class="badge bg-success"><?= __('FLIGHTS_ARRIVAL') ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><?= __('FLIGHTS_DEPARTURE') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_CURRENCY') ?></td>
                        <td><code><?= e($act['currency_code']) ?></code> <?= e($act['currency_name']) ?></td>
                    </tr>
                    <?php if ($act['cb_rate']): ?>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_CB_RATE') ?></td>
                        <td><?= e($act['cb_rate']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_AIRCRAFT') ?></td>
                        <td><?= e($act['aircraft_type'] ?? '—') ?> <span class="text-muted"><?= e($act['aircraft_registration'] ?? '') ?></span></td>
                    </tr>
                    <?php if ($act['notes']): ?>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('COMMON_NOTES') ?></td>
                        <td><?= e($act['notes']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('COMMON_CREATED_BY') ?></td>
                        <td><?= e($act['created_by_fullname']) ?> <small class="text-muted"><?= format_datetime($act['created_at']) ?></small></td>
                    </tr>
                    <?php if ($act['approved_by_user_id']): ?>
                    <tr>
                        <td class="text-muted fw-semibold"><?= __('ACTS_APPROVED_BY') ?></td>
                        <td><?= e($act['approved_by_fullname']) ?> <small class="text-muted"><?= format_datetime($act['approved_at']) ?></small></td>
                    </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Totals -->
        <div class="card border-0 shadow-sm">
            <div class="card-header"><i class="bi bi-calculator text-primary me-1"></i> <?= __('ACTS_TOTALS') ?></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr>
                        <td class="text-muted"><?= __('ACTS_WITHOUT_VAT') ?></td>
                        <td class="text-end fw-semibold">
                            <?= format_money($act['total_amount_without_vat']) ?>
                            <code class="ms-1"><?= e($act['currency_code']) ?></code>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?= __('ACTS_VAT_AMOUNT') ?></td>
                        <td class="text-end">
                            <?= format_money($act['total_vat_amount']) ?>
                            <code class="ms-1"><?= e($act['currency_code']) ?></code>
                        </td>
                    </tr>
                    <tr class="table-primary">
                        <td class="fw-bold"><?= __('ACTS_WITH_VAT') ?></td>
                        <td class="text-end fw-bold fs-5">
                            <?= format_money($act['total_amount_with_vat']) ?>
                            <code class="ms-1"><?= e($act['currency_code']) ?></code>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== RIGHT COLUMN ===== -->
    <div class="col-lg-7">

        <!-- Act Items -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ul text-primary me-1"></i> <?= __('ACTS_ITEMS') ?></span>
                <?php if (has_role(['admin', 'cok']) && $act['status'] === 'draft'): ?>
                <a href="/acts/<?= (int)$act['id'] ?>/edit" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-plus-lg"></i> <?= __('ACTS_ADD_ITEM') ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table act-items-table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?= __('ACTS_SERVICE') ?></th>
                                <th><?= __('COMMON_CODE') ?> <?= __('ACTS_QUANTITY') ?></th>
                                <th class="text-end"><?= __('ACTS_UNIT_PRICE') ?></th>
                                <th class="text-end"><?= __('ACTS_VAT_PCT') ?></th>
                                <th class="text-end"><?= __('ACTS_WITHOUT_VAT') ?></th>
                                <th class="text-end"><?= __('ACTS_WITH_VAT') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-4 d-block mb-2 opacity-30"></i>
                                    <?= __('ACTS_NO_ITEMS') ?>
                                    <?php if (has_role(['admin', 'cok']) && $act['status'] === 'draft'): ?>
                                    <br>
                                    <a href="/acts/<?= (int)$act['id'] ?>/edit" class="btn btn-sm btn-outline-primary mt-2">
                                        <i class="bi bi-plus-lg"></i> <?= __('ACTS_ADD_ITEM') ?>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $currentCat = null;
                            foreach ($items as $item):
                                $catCode = $item['category_code'] ?? '';
                                $catName = $item['category_name'] ?? '';
                                if ($catCode !== $currentCat):
                                    $currentCat = $catCode;
                            ?>
                            <tr class="section-row-header">
                                <td colspan="7"><?= e($catName) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="<?= (float)$item['amount_with_vat'] > 0 ? 'has-value' : '' ?>">
                                <td class="text-muted"><?= (int)$item['line_no'] ?></td>
                                <td>
                                    <span class="d-block"><?= e($item['service_name_snapshot']) ?></span>
                                    <?php if ($item['calculation_note']): ?>
                                    <small class="text-muted fst-italic"><?= e($item['calculation_note']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="fw-semibold"><?= format_money($item['quantity'], 2) ?></span>
                                    <br><small class="text-muted"><?= e($item['unit_name_snapshot']) ?></small>
                                </td>
                                <td class="text-end">
                                    <?= format_money($item['unit_price'], 4) ?>
                                    <code class="ms-1 small"><?= e($item['currency_code']) ?></code>
                                </td>
                                <td class="text-end text-muted"><?= e($item['vat_rate']) ?>%</td>
                                <td class="text-end"><?= format_money($item['amount_without_vat']) ?></td>
                                <td class="text-end fw-semibold text-primary"><?= format_money($item['amount_with_vat']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Files -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-paperclip text-primary me-1"></i> <?= __('ACTS_FILES') ?></span>
                <?php if (has_role(['admin', 'cok'])): ?>
                <button class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#uploadModal">
                    <i class="bi bi-upload"></i> <?= __('COMMON_UPLOAD') ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th><?= __('FILES_ORIGINAL_NAME') ?></th>
                            <th><?= __('FILES_RELATION') ?></th>
                            <th class="text-end"><?= __('FILES_SIZE') ?></th>
                            <th><?= __('FILES_UPLOADED_AT') ?></th>
                            <th><?= __('COMMON_ACTIONS') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($files)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">
                            <i class="bi bi-folder2 d-block mb-1 opacity-30"></i>
                            <?= __('ACTS_NO_FILES') ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($files as $f): ?>
                        <tr>
                            <td class="small">
                                <i class="bi bi-file-earmark me-1 text-muted"></i>
                                <?= e($f['original_name']) ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($f['relation_type']) ?></span></td>
                            <td class="text-end small text-muted"><?= number_format($f['file_size_bytes'] / 1024, 1) ?> KB</td>
                            <td class="small text-muted"><?= format_datetime($f['uploaded_at']) ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="/files/<?= (int)$f['file_id'] ?>/download"
                                       class="btn btn-xs btn-outline-primary" title="<?= __('COMMON_DOWNLOAD') ?>">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <a href="/files/<?= (int)$f['file_id'] ?>/log"
                                       class="btn btn-xs btn-outline-secondary" title="<?= __('FILES_LOG') ?>">
                                        <i class="bi bi-journal-text"></i>
                                    </a>
                                    <?php if (has_role(['admin', 'cok'])): ?>
                                    <form method="POST" action="/files/<?= (int)$f['file_id'] ?>/delete" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="act_id" value="<?= (int)$act['id'] ?>">
                                        <button type="submit"
                                                class="btn btn-xs btn-outline-danger"
                                                onclick="return confirm('<?= __('COMMON_CONFIRM_DELETE') ?>')"
                                                title="<?= __('COMMON_DELETE') ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Status History -->
        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <i class="bi bi-clock-history text-primary me-1"></i> <?= __('ACTS_STATUS_HISTORY') ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th><?= __('ACTS_FROM_STATUS') ?></th>
                            <th><?= __('ACTS_TO_STATUS') ?></th>
                            <th><?= __('ACTS_CHANGED_BY') ?></th>
                            <th><?= __('ACTS_REASON') ?></th>
                            <th><?= __('ACTS_CHANGED_WHEN') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($history)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3"><?= __('ACTS_NO_HISTORY') ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($history as $h): ?>
                        <tr class="status-history-row">
                            <td><?= $h['old_status']
                                ? '<span class="badge badge-status-' . e($h['old_status']) . '">' . __('ACTS_STATUS_' . strtoupper($h['old_status'])) . '</span>'
                                : '<span class="text-muted">—</span>' ?></td>
                            <td><span class="badge badge-status-<?= e($h['new_status']) ?>"><?= __('ACTS_STATUS_' . strtoupper($h['new_status'])) ?></span></td>
                            <td class="text-muted"><?= e($h['username'] ?? '—') ?></td>
                            <td class="text-muted fst-italic"><?= e($h['reason'] ?? '—') ?></td>
                            <td class="text-muted"><?= format_datetime($h['changed_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODALS ===== -->

<!-- Approve Modal -->
<?php if (has_role(['admin', 'cok']) && $act['status'] === 'draft'): ?>
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?php $hasFiles = !empty($files); ?>
            <form method="POST" action="/acts/<?= (int)$act['id'] ?>/status"
                  enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="new_status" value="approved">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title text-success">
                        <i class="bi bi-check-circle-fill me-2"></i><?= __('ACTS_APPROVE') ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small"><?= __('ACTS_APPROVE_CONFIRM') ?></p>

                    <?php
                    // ДОБАВЛЕНО (05.08.2026): если обе подписи с планшета уже
                    // есть — скан не обязателен, объясняем это явно.
                    $hasBothSignatures = !empty($act['supervisor_signature_file_id'])
                        && !empty($act['airline_rep_signature_file_id']);
                    ?>
                    <?php if ($hasBothSignatures): ?>
                    <div class="alert alert-success py-2 small">
                        <i class="bi bi-pen-fill me-1"></i>
                        Обе подписи получены с планшета (супервайзер + представитель авиакомпании).
                        Скан можно не загружать.
                    </div>
                    <?php elseif ($hasFiles): ?>
                    <div class="alert alert-success py-2 small">
                        <i class="bi bi-check-circle-fill me-1"></i>
                        Файл уже прикреплён. Можно добавить ещё или утвердить сразу.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Загрузите скан или фото подписанного оригинала — либо получите обе подписи с планшета супервайзера.
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">
                            Скан/фото подписанного оригинала
                            <?= ($hasFiles || $hasBothSignatures) ? '<span class="text-muted">(необязательно)</span>' : '<span class="text-danger">*</span>' ?>
                        </label>
                        <input type="file" name="scan_file" class="form-control form-control-sm"
                               accept=".jpg,.jpeg,.png,.tiff,.tif,.heic,.heif,.pdf"
                               <?= (!$hasFiles && !$hasBothSignatures) ? 'required' : '' ?>>
                        <div class="form-text text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            PDF из системы или скан/фото бумажного оригинала. Чужой PDF будет отклонён.
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold"><?= __('ACTS_REASON_OPTIONAL') ?></label>
                        <input type="text" name="reason" class="form-control form-control-sm"
                               placeholder="<?= __('ACTS_REASON_OPTIONAL') ?>">
                    </div>

                    <hr class="my-2">
                    <div class="text-muted small fw-semibold mb-2">
                        Представитель авиакомпании (кто подписал со стороны заявителя)
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">ФИО</label>
                        <input type="text" name="airline_rep_name" class="form-control form-control-sm"
                               value="<?= e($act['airline_rep_name'] ?? '') ?>"
                               placeholder="Иванов И.И.">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Должность</label>
                        <input type="text" name="airline_rep_position" class="form-control form-control-sm"
                               value="<?= e($act['airline_rep_position'] ?? '') ?>"
                               placeholder="Представитель / Агент по наземному обслуживанию">
                    </div>
                    <div class="form-text text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Необязательно, но попадёт в печатную форму — если оставить пустым, поле в акте останется прочерком.
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-bs-dismiss="modal"><?= __('COMMON_CANCEL') ?></button>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check-lg"></i> <?= __('ACTS_APPROVE') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Cancel Modal -->
<?php if (has_role(['admin', 'cok']) && in_array($act['status'], ['draft', 'approved'])): ?>
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" action="/acts/<?= (int)$act['id'] ?>/status">
                <?= csrf_field() ?>
                <input type="hidden" name="new_status" value="cancelled">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-x-circle-fill me-2"></i><?= __('ACTS_CANCEL_ACT') ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small"><?= __('ACTS_CANCEL_CONFIRM') ?></p>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold"><?= __('ACTS_REASON_REQUIRED') ?> <span class="text-danger">*</span></label>
                        <input type="text" name="reason" class="form-control form-control-sm" required
                               placeholder="<?= __('ACTS_REASON') ?>...">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= __('COMMON_BACK') ?></button>
                    <button type="submit" class="btn btn-sm btn-danger">
                        <i class="bi bi-x-lg"></i> <?= __('ACTS_CANCEL_ACT') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Upload Modal -->
<?php if (has_role(['admin', 'cok'])): ?>
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/acts/<?= (int)$act['id'] ?>/files/upload"
                  enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2"></i><?= __('COMMON_UPLOAD') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('FILES_ORIGINAL_NAME') ?> <span class="text-danger">*</span></label>
                        <input type="file" name="file" class="form-control" required>
                        <div class="form-text"><?= __('FILES_UPLOAD_HINT') ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('FILES_RELATION_TYPE') ?></label>
                        <select name="relation_type" class="form-select">
                            <option value="attachment"><?= __('FILES_RELATION') ?>: <?= __('FILES_REL_ATTACHMENT') ?></option>
                            <option value="source"><?= __('FILES_REL_SOURCE') ?></option>
                            <option value="signed_copy"><?= __('FILES_REL_SIGNED') ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= __('COMMON_CANCEL') ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> <?= __('COMMON_UPLOAD') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Text Breakdown Modal (ДОБАВЛЕНО 14.10.2026, раунд 71) -->
<div class="modal fade" id="textBreakdownModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-card-text me-2"></i>Текстовая расшифровка акта</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- ИЗМЕНЕНО (15.10.2026, раунд 80, по прямому указанию пользователя):
                     попап показывает таблицу (ActService::buildBreakdownHtml()) вместо
                     простого текста. $breakdownHtml уже содержит готовую, экранированную
                     разметку — выводится без e(). Текстовая версия ($textBreakdown)
                     осталась только скрытым источником для кнопки "Копировать" ниже. -->
                <div id="textBreakdownContent" class="small"><?= $breakdownHtml ?></div>
                <pre id="textBreakdownPlainText" class="d-none"><?= e($textBreakdown) ?></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal"><?= __('COMMON_CLOSE') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="copyTextBreakdownBtn">
                    <i class="bi bi-clipboard"></i> Копировать
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var btn = document.getElementById('copyTextBreakdownBtn');
    if (!btn) return;
    var originalHtml = btn.innerHTML;
    function showCopied() {
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Скопировано';
        setTimeout(function() { btn.innerHTML = originalHtml; }, 1500);
    }
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        showCopied();
    }
    btn.addEventListener('click', function() {
        var text = document.getElementById('textBreakdownPlainText').textContent;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(showCopied).catch(function() { fallbackCopy(text); });
        } else {
            fallbackCopy(text);
        }
    });
})();
</script>