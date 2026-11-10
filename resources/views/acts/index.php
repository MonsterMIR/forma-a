<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-file-earmark-check"></i> Acts</h2>
    <?php if (has_role(['admin'])): ?>
    <a href="/acts/create" class="btn btn-outline-secondary btn-sm" title="Ручное создание (резервный путь). Обычно акты формируются со страницы рейса.">
        <i class="bi bi-plus-lg"></i> <?= __('ACTS_CREATE') ?>
    </a>
    <?php endif; ?>
</div>

<div class="alert alert-info small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Акты формируются автоматически со страницы рейса (кнопка «Сформировать Форму А»),
    после того как все службы внесли свои услуги.
    <a href="/flights">Перейти к списку рейсов →</a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold"><?= __('COMMON_SEARCH') ?></label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="<?= __('ACTS_SEARCH_PLACEHOLDER') ?>" value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('COMMON_STATUS') ?> </label>
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="draft"     <?= $filters['status'] === 'draft'     ? 'selected' : '' ?>><?= __('STATUS_DRAFT') ?></option>
                    <option value="approved"  <?= $filters['status'] === 'approved'  ? 'selected' : '' ?>><?= __('STATUS_APPROVED') ?></option>
                    <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>><?= __('STATUS_CANCELLED') ?></option>
                    <option value="exported"  <?= $filters['status'] === 'exported'  ? 'selected' : '' ?>><?= __('STATUS_EXPORTED') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_ROUTE_TYPE') ?> </label>
                <select name="route_type" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="domestic"      <?= $filters['route_type'] === 'domestic'      ? 'selected' : '' ?>><?= __('FLIGHTS_DOMESTIC') ?></option>
                    <option value="international" <?= $filters['route_type'] === 'international' ? 'selected' : '' ?>><?= __('FLIGHTS_INTERNATIONAL') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_OPERATION') ?> </label>
                <select name="operation_type" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="arrival"   <?= $filters['operation_type'] === 'arrival'   ? 'selected' : '' ?>><?= __('FLIGHTS_ARRIVAL') ?></option>
                    <option value="departure" <?= $filters['operation_type'] === 'departure' ? 'selected' : '' ?>><?= __('FLIGHTS_DEPARTURE') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('ACTS_DATE_FROM') ?></label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('ACTS_DATE_TO') ?></label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold" title="Заполните только это поле, чтобы найти рейсы/акты/записи ровно за один день — само подставит ту же дату в поля «с»/«по» выше (round 164).">На дату</label>
                <input type="date" class="form-control form-control-sm" data-exact-date data-from="date_from" data-to="date_to">
            </div>
            <div class="col-auto d-flex gap-2 mt-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> <?= __('COMMON_SEARCH') ?></button>
                <a href="/acts" class="btn btn-sm btn-outline-secondary"><?= __('COMMON_RESET') ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th><?= __('ACTS_NUMBER') ?></th>
                        <th><?= __('COMMON_DATE') ?></th>
                        <th><?= __('ACTS_FLIGHT') ?></th>
                        <th><?= __('FLIGHTS_AIRLINE') ?></th>
                        <th><?= __('FLIGHTS_ROUTE_TYPE') ?></th>
                        <th><?= __('FILES_OP_TYPE') ?></th>
                        <th><?= __('COMMON_STATUS') ?></th>
                        <th class="text-end">Total w/ VAT</th>
                        <th><?= __('COMMON_CREATED_BY') ?></th>
                        <th><?= __('COMMON_ACTIONS') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($acts)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4"><?= __('ACTS_NO_DATA') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($acts as $act): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$act['id'] ?></td>
                        <td>
                            <a href="/acts/<?= (int)$act['id'] ?>" class="text-decoration-none fw-semibold">
                                <?= e(act_display_number($act)) ?>
                            </a>
                        </td>
                        <td class="small"><?= format_date($act['act_date']) ?></td>
                        <td class="small">
                            <strong><?= e($act['airline_iata'] ?? '') ?>-<?= e($act['flight_number']) ?></strong>
                            <span class="text-muted ms-1"><?= format_date($act['flight_date']) ?></span>
                            <?php if (!empty($act['origin_iata'])): ?>
                            <br><span class="text-muted" style="font-size:0.75rem"><?= e($act['origin_iata']) ?>→<?= e($act['destination_iata'] ?? '?') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($act['route_type'] === 'domestic'): ?>
                                <span class="badge bg-info text-dark">Dom.</span>
                            <?php else: ?>
                                <span class="badge bg-primary">Intl.</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($act['operation_type'] === 'arrival'): ?>
                                <span class="badge bg-success">Arr.</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Dep.</span>
                            <?php endif; ?>
                        </td>
                        <td><?= status_badge($act['status']) ?></td>
                        <td class="text-end fw-semibold">
                            <?= format_money($act['total_amount_with_vat']) ?>
                            <small class="text-muted"><?= e($act['currency_code']) ?></small>
                        </td>
                        <td class="small text-muted"><?= e($act['created_by_username']) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="/acts/<?= (int)$act['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                                <!-- ДОБАВЛЕНО (14.10.2026, раунд 71b, по прямому указанию
                                     пользователя): попап с текстовой расшифровкой акта
                                     (без НДС) прямо из списка, без перехода на страницу
                                     акта — см. #textBreakdownModal и JS в конце файла. -->
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal" data-bs-target="#textBreakdownModal"
                                        data-act-id="<?= (int)$act['id'] ?>"
                                        data-act-number="<?= e(act_display_number($act)) ?>"
                                        title="Текстовая расшифровка содержания акта (без НДС)">
                                    <i class="bi bi-card-text"></i>
                                </button>
                                <?php if (has_role(['admin', 'cok']) && in_array($act['status'], ['draft'])): ?>
                                <a href="/acts/<?= (int)$act['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
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
    <?php if ($pagination['last_page'] > 1): ?>
    <div class="card-footer bg-white">
        <?php include BASE_PATH . '/resources/views/layouts/_pagination.php'; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Text Breakdown Modal (ДОБАВЛЕНО 14.10.2026, раунд 71b) — один общий
     попап для всех строк списка, содержимое подгружается через fetch()
     при клике на кнопку (см. ActController::textBreakdown()). -->
<div class="modal fade" id="textBreakdownModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-card-text me-2"></i>Текстовая расшифровка акта
                    <span id="textBreakdownActNumber" class="text-muted"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="textBreakdownLoading" class="text-center text-muted py-4 d-none">
                    <div class="spinner-border spinner-border-sm me-2"></div> Загрузка…
                </div>
                <div id="textBreakdownError" class="alert alert-danger d-none"></div>
                <!-- ИЗМЕНЕНО (15.10.2026, раунд 80, по прямому указанию пользователя):
                     попап показывает таблицу (ActService::buildBreakdownHtml(), поле
                     data.html в JSON-ответе) вместо простого текста. data.text
                     (бывший единственный источник) хранится в JS-переменной
                     currentPlainText — только для кнопки "Копировать". -->
                <div id="textBreakdownContent" class="small"></div>
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
    var modalEl   = document.getElementById('textBreakdownModal');
    if (!modalEl) return;
    var loadingEl = document.getElementById('textBreakdownLoading');
    var errorEl   = document.getElementById('textBreakdownError');
    var contentEl = document.getElementById('textBreakdownContent');
    var numberEl  = document.getElementById('textBreakdownActNumber');
    var copyBtn   = document.getElementById('copyTextBreakdownBtn');
    // ДОБАВЛЕНО (15.10.2026, раунд 80): data.text (копия в обычном тексте,
    // не HTML) хранится отдельно от того, что рендерится в contentEl —
    // кнопка "Копировать" копирует именно это, не innerHTML таблицы.
    var currentPlainText = '';

    // ИСПРАВЛЕНО (14.10.2026, раунд 71c — кнопка не открывала попап): модалку
    // открывает сам Bootstrap по data-bs-toggle/data-bs-target на кнопке —
    // здесь только слушаем событие show.bs.modal и забираем кнопку, на
    // которую кликнули, из event.relatedTarget.
    modalEl.addEventListener('show.bs.modal', function(event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var actId     = btn.getAttribute('data-act-id');
        var actNumber = btn.getAttribute('data-act-number') || '';
        numberEl.textContent  = actNumber ? ('— ' + actNumber) : '';
        contentEl.innerHTML   = '';
        currentPlainText      = '';
        errorEl.classList.add('d-none');
        loadingEl.classList.remove('d-none');

        fetch('/acts/' + encodeURIComponent(actId) + '/text-breakdown', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            loadingEl.classList.add('d-none');
            if (data && data.success) {
                contentEl.innerHTML = data.html;
                currentPlainText    = data.text;
            } else {
                errorEl.textContent = (data && data.message) || 'Не удалось загрузить расшифровку.';
                errorEl.classList.remove('d-none');
            }
        })
        .catch(function() {
            loadingEl.classList.add('d-none');
            errorEl.textContent = 'Ошибка сети при загрузке расшифровки.';
            errorEl.classList.remove('d-none');
        });
    });

    copyBtn.addEventListener('click', function() {
        var text = currentPlainText;
        var originalHtml = copyBtn.innerHTML;
        function showCopied() {
            copyBtn.innerHTML = '<i class="bi bi-check-lg"></i> Скопировано';
            setTimeout(function() { copyBtn.innerHTML = originalHtml; }, 1500);
        }
        function fallbackCopy() {
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
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(showCopied).catch(fallbackCopy);
        } else {
            fallbackCopy();
        }
    });
})();
</script>