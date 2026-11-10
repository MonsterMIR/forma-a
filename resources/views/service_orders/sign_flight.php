<div class="mb-3">
    <a href="/supervisor/flights/<?= (int)$flight['id'] ?>" class="text-muted text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i>Назад к рейсу
    </a>
    <h2 class="mb-0 mt-1"><i class="bi bi-pen me-2"></i>Подписание акта</h2>
    <div class="text-muted">
        <?= e($flight['flight_number']) ?> · Акт № <?= e(act_display_number($act)) ?>
        · <?= number_format((float)$act['total_amount_with_vat'], 2) ?> <?= e($act['settlement_currency_id'] == 2 ? 'USD' : 'UZS') ?>
    </div>
</div>

<div id="signToast" class="mb-3"></div>

<!-- ── Шаг 1: супервайзер ─────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3" id="stepSupervisor">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-1-circle-fill me-2"></i>Подпись супервайзера</span>
        <span class="badge bg-secondary" id="supStatusBadge">не подписано</span>
    </div>
    <div class="card-body">
        <div class="signature-pad-wrap">
            <canvas id="supCanvas" class="signature-canvas"></canvas>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary btn-lg" id="supClear">
                <i class="bi bi-eraser"></i> Очистить
            </button>
            <button type="button" class="btn btn-primary btn-lg flex-grow-1" id="supSave">
                <i class="bi bi-check-lg"></i> Сохранить подпись супервайзера
            </button>
        </div>
    </div>
</div>

<!-- ── Шаг 2: представитель авиакомпании ──────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3 <?= empty($act['supervisor_signature_file_id']) ? 'd-none' : '' ?>" id="stepRep">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-2-circle-fill me-2"></i>Представитель авиакомпании</span>
        <span class="badge bg-secondary" id="repStatusBadge">не подписано</span>
    </div>
    <div class="card-body">
        <?php $handlers = $handlerCandidates['handlers'] ?? []; ?>
        <?php if (!empty($handlers)): ?>
        <div class="row g-2 mb-2">
            <div class="col-md-6">
                <label class="form-label small">
                    Хендлер
                    <?php if ($handlerCandidates['matchedByFlight'] ?? false): ?>
                    <span class="text-success">(определён из заявки на рейс)</span>
                    <?php elseif (!($handlerCandidates['matchedByAirline'] ?? false)): ?>
                    <span class="text-muted">(для этой а/к не привязан — показаны все)</span>
                    <?php endif; ?>
                </label>
                <select class="form-select form-select-lg" id="repHandlerSelect">
                    <option value="">— ввести ФИО вручную —</option>
                    <?php foreach ($handlers as $h): ?>
                    <option value="<?= (int)$h['id'] ?>"
                        <?= ((int)($handlerCandidates['resolvedHandlerId'] ?? 0) === (int)$h['id']) ? 'selected' : '' ?>>
                        <?= e($h['name']) ?><?= ((int)($handlerCandidates['resolvedHandlerId'] ?? 0) === (int)$h['id']) ? ' ✓' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label small">Сотрудник хендлера</label>
                <select class="form-select form-select-lg" id="repEmployeeSelect" disabled>
                    <option value="">сначала выберите хендлера</option>
                </select>
            </div>
        </div>
        <input type="hidden" id="repEmployeeId" value="">
        <?php endif; ?>
        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label class="form-label small">ФИО</label>
                <input type="text" class="form-control form-control-lg" id="repName"
                       value="<?= e($act['airline_rep_name'] ?? '') ?>" placeholder="Иванов И.И.">
            </div>
            <div class="col-md-6">
                <label class="form-label small">Должность</label>
                <input type="text" class="form-control form-control-lg" id="repPosition"
                       value="<?= e($act['airline_rep_position'] ?? '') ?>" placeholder="Представитель">
            </div>
        </div>
        <div class="signature-pad-wrap">
            <canvas id="repCanvas" class="signature-canvas"></canvas>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary btn-lg" id="repClear">
                <i class="bi bi-eraser"></i> Очистить
            </button>
            <button type="button" class="btn btn-primary btn-lg flex-grow-1" id="repSave">
                <i class="bi bi-check-lg"></i> Сохранить подпись представителя
            </button>
        </div>
    </div>
</div>

<!-- ── Готово ──────────────────────────────────────────────────────────── -->
<?php /* ИЗМЕНЕНО (21.09.2026, раунд 49, п.4, по прямому указанию
         пользователя): текст меняется в JS ниже в зависимости от того,
         утвердился ли акт автоматически (обычный случай) или потребовалось
         ручное утверждение ЦОК (только если остались несинхронизированные
         расчёты услуг — см. ActSignatureController::saveSignature()). */ ?>
<div class="alert alert-success d-none" id="bothDoneAlert">
    <i class="bi bi-check-circle-fill me-2"></i>
    <span id="bothDoneAlertText">Обе подписи сохранены.</span>
</div>

<style>
.signature-pad-wrap {
    border: 2px dashed var(--border, #dee2e6);
    border-radius: .6rem;
    background: #fff;
    touch-action: none;
}
.signature-canvas {
    width: 100%;
    height: 220px;
    display: block;
    cursor: crosshair;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script>
const CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';
const FLIGHT_ID = <?= (int)$flight['id'] ?>;

function initPad(canvasId) {
    const canvas = document.getElementById(canvasId);
    // Ретина/чёткость + правильные пропорции при повороте планшета
    function resize() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        pad.clear();
    }
    const pad = new SignaturePad(canvas, {
        minWidth: 0.7,
        maxWidth: 3.2,          // разброс толщины линии от нажатия пера — "как ручкой"
        penColor: 'rgb(15, 23, 42)',
        backgroundColor: 'rgba(255,255,255,0)'
    });
    window.addEventListener('resize', resize);
    resize();
    return pad;
}

const supPad = initPad('supCanvas');
const repPad = initPad('repCanvas');

// ДОБАВЛЕНО (18.09.2026, раунд 46, п.5/п.6): хендлер → его сотрудники,
// чтобы при выборе хендлера сразу предложить список подписантов и
// автоматически подставить ФИО/должность — вместо набора текста на планшете.
const HANDLERS = <?= json_encode($handlerCandidates['handlers'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
const repHandlerSelect  = document.getElementById('repHandlerSelect');
const repEmployeeSelect = document.getElementById('repEmployeeSelect');
const repEmployeeIdInput = document.getElementById('repEmployeeId');
const repNameInput      = document.getElementById('repName');
const repPositionInput  = document.getElementById('repPosition');

if (repHandlerSelect) {
    repHandlerSelect.addEventListener('change', function() {
        const handler = HANDLERS.find(h => String(h.id) === this.value);
        repEmployeeIdInput.value = '';
        if (!handler) {
            repEmployeeSelect.disabled = true;
            repEmployeeSelect.innerHTML = '<option value="">сначала выберите хендлера</option>';
            return;
        }
        repEmployeeSelect.disabled = false;
        const employees = handler.employees || [];
        repEmployeeSelect.innerHTML = '<option value="">— выберите сотрудника —</option>' +
            employees.map(e => '<option value="' + e.id + '">' + e.full_name + (e.position ? ' — ' + e.position : '') + '</option>').join('');
    });
    repEmployeeSelect.addEventListener('change', function() {
        const handler = HANDLERS.find(h => String(h.id) === repHandlerSelect.value);
        const employee = handler ? (handler.employees || []).find(e => String(e.id) === this.value) : null;
        if (employee) {
            repEmployeeIdInput.value = employee.id;
            repNameInput.value = employee.full_name;
            repPositionInput.value = employee.position || '';
        } else {
            repEmployeeIdInput.value = '';
        }
    });

    // ДОБАВЛЕНО (22.09.2026, раунд 51): хендлер из заявки на рейс приходит
    // с сервера уже выбранным (атрибут selected, см. HandlerService::
    // getCandidatesForFlight()) — браузер НЕ генерирует событие 'change' для
    // изначально выбранного option при загрузке страницы, поэтому без этой
    // строки список сотрудников остался бы пустым, пока супервайзер сам не
    // переключит хендлера туда-обратно.
    if (repHandlerSelect.value) {
        repHandlerSelect.dispatchEvent(new Event('change'));
    }
}

document.getElementById('supClear').addEventListener('click', () => supPad.clear());
document.getElementById('repClear').addEventListener('click', () => repPad.clear());

function showToast(msg, type) {
    const cls = type === 'error' ? 'danger' : type;
    document.getElementById('signToast').innerHTML =
        '<div class="alert alert-' + cls + ' py-2 mb-0">' + msg + '</div>';
}

document.getElementById('supSave').addEventListener('click', function() {
    if (supPad.isEmpty()) { showToast('Распишитесь перед сохранением', 'error'); return; }
    const btn = this;
    btn.disabled = true;

    const body = new URLSearchParams();
    body.append('_csrf', CSRF);
    body.append('signature_png', supPad.toDataURL('image/png'));

    fetch('/supervisor/flights/' + FLIGHT_ID + '/sign/supervisor', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        if (!data.success) { showToast(data.message, 'error'); return; }
        document.getElementById('supStatusBadge').textContent = 'подписано';
        document.getElementById('supStatusBadge').className = 'badge bg-success';
        document.getElementById('stepRep').classList.remove('d-none');
        document.getElementById('stepRep').scrollIntoView({behavior: 'smooth', block: 'start'});
        showToast('Подпись супервайзера сохранена', 'success');
    })
    .catch(() => { btn.disabled = false; showToast('Ошибка сети — попробуйте ещё раз', 'error'); });
});

document.getElementById('repSave').addEventListener('click', function() {
    if (repPad.isEmpty()) { showToast('Попросите представителя расписаться', 'error'); return; }
    const btn = this;
    btn.disabled = true;

    const body = new URLSearchParams();
    body.append('_csrf', CSRF);
    body.append('signature_png', repPad.toDataURL('image/png'));
    body.append('rep_name', document.getElementById('repName').value);
    body.append('rep_position', document.getElementById('repPosition').value);
    if (repEmployeeIdInput && repEmployeeIdInput.value) {
        body.append('handler_employee_id', repEmployeeIdInput.value);
    }

    fetch('/supervisor/flights/' + FLIGHT_ID + '/sign/representative', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        if (!data.success) { showToast(data.message, 'error'); return; }
        document.getElementById('repStatusBadge').textContent = 'подписано';
        document.getElementById('repStatusBadge').className = 'badge bg-success';
        document.getElementById('bothDoneAlertText').textContent = data.auto_approved
            ? 'Обе подписи собраны — акт автоматически утверждён.'
            : 'Обе подписи сохранены, но акт передан на ручное утверждение ЦОК (см. сообщение выше).';
        document.getElementById('bothDoneAlert').classList.remove('d-none');
        document.getElementById('bothDoneAlert').scrollIntoView({behavior: 'smooth', block: 'start'});
        showToast(data.message || 'Подпись представителя сохранена', 'success');
    })
    .catch(() => { btn.disabled = false; showToast('Ошибка сети — попробуйте ещё раз', 'error'); });
});
</script>
