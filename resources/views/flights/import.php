<?php
/**
 * resources/views/flights/import.php
 *
 * Страница: Создание рейсов из разрешения CAA UZB / TARMAC
 */
?>

<style>
/* ── Основные стили страницы ── */
.import-page { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }

/* Шаг-индикатор */
.steps-bar {
    display: flex;
    align-items: center;
    gap: 0;
    margin-bottom: 2rem;
    background: #f8f9fc;
    border-radius: 12px;
    padding: 1rem 1.5rem;
    border: 1px solid #e2e8f0;
}
.step-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    position: relative;
}
.step-item:not(:last-child)::after {
    content: '';
    position: absolute;
    right: 0;
    top: 50%;
    width: 100%;
    height: 2px;
    background: #e2e8f0;
    z-index: 0;
    left: 50%;
}
.step-badge {
    width: 32px; height: 32px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 0.85rem;
    background: #e2e8f0; color: #94a3b8;
    position: relative; z-index: 1;
    flex-shrink: 0;
    transition: all .3s;
}
.step-label { font-size: 0.82rem; color: #94a3b8; font-weight: 500; white-space: nowrap; }

.step-item.active   .step-badge { background: #3b82f6; color: #fff; }
.step-item.active   .step-label { color: #1e40af; font-weight: 700; }
.step-item.done     .step-badge { background: #10b981; color: #fff; }
.step-item.done     .step-label { color: #065f46; }

/* Карточки шагов */
.step-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    padding: 1.75rem;
    margin-bottom: 1.5rem;
    display: none;
    animation: fadeIn .3s ease;
}
.step-card.active { display: block; }

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Текстовая область */
.permission-textarea {
    width: 100%;
    min-height: 240px;
    font-family: 'Courier New', Courier, monospace;
    font-size: 0.82rem;
    line-height: 1.5;
    padding: 1rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    resize: vertical;
    color: #334155;
    background: #f8fafc;
    transition: border-color .2s;
}
.permission-textarea:focus {
    outline: none;
    border-color: #3b82f6;
    background: #fff;
}

/* Кнопка парсинга */
.btn-parse {
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    color: #fff;
    border: none;
    padding: 0.7rem 2rem;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: opacity .2s, transform .1s;
}
.btn-parse:hover  { opacity: 0.9; }
.btn-parse:active { transform: scale(0.98); }
.btn-parse:disabled { opacity: 0.6; cursor: wait; }

/* AI-метка */
.ai-badge {
    background: linear-gradient(135deg, #8b5cf6, #ec4899);
    color: #fff;
    font-size: 0.65rem;
    font-weight: 800;
    padding: 2px 6px;
    border-radius: 4px;
    letter-spacing: .05em;
}

/* Формат-бейдж */
.format-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: .04em;
}
.format-caa    { background: #dbeafe; color: #1e40af; }
.format-tarmac { background: #dcfce7; color: #166534; }
/* ДОБАВЛЕНО (23.10.2026, раунд 124): уведомление ЦОК о планировании
   (не разрешение ЦАУ) — см. PermissionParser::parsePlanningNoticeFormat() */
.format-notice { background: #fef3c7; color: #92400e; }

/* Таблица предпросмотра */
.preview-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.83rem;
}
.preview-table th {
    background: #f1f5f9;
    padding: 0.65rem 0.75rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .04em;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 2;
}
.preview-table td {
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.preview-table tr:hover td { background: #f8fafc; }

/* Строка выбора */
.preview-table tr.row-selected   td { background: #eff6ff; }
.preview-table tr.row-duplicate  td { background: #fef9c3; }
.preview-table tr.row-not-ours   td { background: #f9fafb; opacity: .7; }

/* Статус-пилюли */
.pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
}
.pill-ok      { background: #dcfce7; color: #166534; }
.pill-warn    { background: #fef3c7; color: #92400e; }
.pill-error   { background: #fee2e2; color: #991b1b; }
.pill-dup     { background: #fde8d8; color: #9a3412; }
.pill-us      { background: #dbeafe; color: #1e40af; }

/* Редактируемые ячейки */
.editable-input {
    border: 1px solid transparent;
    border-radius: 4px;
    padding: 2px 6px;
    background: transparent;
    width: 100%;
    font-size: 0.82rem;
    color: inherit;
    transition: border-color .2s, background .2s;
}
.editable-input:focus {
    outline: none;
    border-color: #93c5fd;
    background: #fff;
}

/* Предупреждения */
.warning-list {
    list-style: none;
    padding: 0; margin: 2px 0 0 0;
}
.warning-list li {
    font-size: 0.7rem;
    color: #b45309;
    line-height: 1.4;
}
.warning-list li::before { content: '⚠ '; }

/* Итоговая панель подтверждения */
.confirm-bar {
    position: sticky;
    bottom: 0;
    background: #fff;
    border-top: 2px solid #e2e8f0;
    padding: 1rem 1.75rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    box-shadow: 0 -4px 12px rgba(0,0,0,.06);
    z-index: 10;
    border-radius: 0 0 12px 12px;
}
.confirm-counter { font-size: 0.92rem; color: #475569; font-weight: 500; }
.confirm-counter span { font-weight: 800; color: #1e40af; font-size: 1.1rem; }

.btn-confirm {
    background: #10b981;
    color: #fff;
    border: none;
    padding: 0.7rem 2.2rem;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 0.5rem;
    transition: background .2s;
}
.btn-confirm:hover    { background: #059669; }
.btn-confirm:disabled { opacity: .5; cursor: not-allowed; }

/* Результат создания */
.result-card {
    border-radius: 8px;
    padding: 1rem 1.25rem;
    margin-bottom: 0.75rem;
    font-size: 0.85rem;
}
.result-created { background: #dcfce7; border-left: 4px solid #10b981; }
.result-skipped { background: #fef9c3; border-left: 4px solid #f59e0b; }
.result-error   { background: #fee2e2; border-left: 4px solid #ef4444; }

/* Loader */
.loader-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(255,255,255,.75);
    z-index: 100;
    align-items: center; justify-content: center;
    flex-direction: column; gap: 1rem;
}
.loader-overlay.active { display: flex; }
.loader-spinner {
    width: 48px; height: 48px;
    border: 4px solid #e2e8f0;
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.loader-text { font-size: 0.9rem; color: #64748b; font-weight: 500; }

/* Пустое состояние */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #94a3b8;
}
.empty-state-icon { font-size: 3rem; margin-bottom: 0.75rem; }
</style>

<div class="import-page">

    <!-- ── Заголовок ── -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="mb-1 fw-bold" style="color:#1e293b">
                ✈ Создание рейсов из разрешения
            </h2>
            <p class="text-muted mb-0" style="font-size:.85rem">
                Вставьте текст разрешения CAA UZB или заявки TARMAC — система распознает рейсы автоматически
            </p>
        </div>
        <a href="/flights" class="btn btn-outline-secondary btn-sm">
            ← К списку рейсов
        </a>
    </div>

    <!-- ── Шаг-индикатор ── -->
    <div class="steps-bar">
        <div class="step-item active" id="step-ind-1">
            <div class="step-badge">1</div>
            <span class="step-label">Вставить текст</span>
        </div>
        <div class="step-item" id="step-ind-2">
            <div class="step-badge">2</div>
            <span class="step-label">Проверить рейсы</span>
        </div>
        <div class="step-item" id="step-ind-3">
            <div class="step-badge">3</div>
            <span class="step-label">Создать в системе</span>
        </div>
    </div>

    <!-- ══════════════════════════════════
         ШАГ 1 — Ввод текста разрешения
    ══════════════════════════════════ -->
    <div class="step-card active" id="step-1">
        <h5 class="fw-bold mb-1" style="color:#1e293b">
            Шаг 1. Вставьте текст разрешения
        </h5>
        <p class="text-muted mb-3" style="font-size:.83rem">
            Поддерживаются форматы: <strong>CAA UZB</strong> (официальные разрешения),
            <strong>TARMAC / REVISION</strong> (телексные заявки).
            Можно вставить сразу несколько разрешений подряд.
        </p>

        <textarea
            id="permission-text"
            class="permission-textarea"
            placeholder="CAA UZB 114/05/2026 TVT&#10;ATTN: CLAY LACY AVIATION...&#10;&#10;— или —&#10;&#10;OPERATOR: AIRCOMPANY NORTH-WEST LLC.&#10;CALL SIGN : NWC9033 / NWC9034&#10;..."
        ></textarea>

        <div class="d-flex align-items-center gap-3 mt-3">
            <button class="btn-parse" id="btn-parse" onclick="parsePermission()">
                <span class="ai-badge">AI</span>
                Распознать рейсы
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="clearForm()">
                Очистить
            </button>
            <div id="parse-error" class="text-danger fw-semibold" style="font-size:.83rem;display:none"></div>
        </div>

        <!-- Подсказка по форматам -->
        <div class="mt-4 p-3 rounded" style="background:#f8fafc;border:1px dashed #cbd5e1;">
            <p class="mb-2 fw-bold" style="font-size:.78rem;color:#475569;text-transform:uppercase;letter-spacing:.04em">
                Примеры поддерживаемых форматов
            </p>
            <div class="row g-2">
                <div class="col-md-6">
                    <div style="font-size:.72rem;color:#64748b;font-family:monospace;background:#fff;padding:.75rem;border-radius:6px;border:1px solid #e2e8f0;">
                        <strong style="color:#1e40af">CAA UZB:</strong><br>
                        CAA UZB 114/05/2026 TVT<br>
                        ATTN: CLAY LACY AVIATION  ICAO: CLY<br>
                        N1209G ETD EGKB 13MAY2026 11:00Z<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;ETA UZTP 13MAY2026 17:20Z
                    </div>
                </div>
                <div class="col-md-6">
                    <div style="font-size:.72rem;color:#64748b;font-family:monospace;background:#fff;padding:.75rem;border-radius:6px;border:1px solid #e2e8f0;">
                        <strong style="color:#166534">TARMAC:</strong><br>
                        OPERATOR: NORTH-WEST LLC<br>
                        CALL SIGN: NWC9033 / NWC9034<br>
                        REG: RA-73575  TYPE: BD-700<br>
                        15MAY26 NWC9033 UUWW 1630–UZTP 1950 PAX04
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════
         ШАГ 2 — Предпросмотр рейсов
    ══════════════════════════════════ -->
    <div class="step-card" id="step-2">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div>
                <h5 class="fw-bold mb-1" style="color:#1e293b">
                    Шаг 2. Проверьте распознанные рейсы
                </h5>
                <p class="text-muted mb-0" style="font-size:.83rem">
                    Формат: <span id="format-badge-placeholder"></span>
                    &nbsp;·&nbsp; Распознано рейсов: <strong id="total-count">0</strong>
                    &nbsp;·&nbsp; Выбрано для создания: <strong id="selected-count">0</strong>
                </p>
            </div>
            <button class="btn btn-outline-secondary btn-sm" onclick="goToStep(1)">
                ← Изменить текст
            </button>
        </div>

        <!-- Таблица предпросмотра -->
        <div style="overflow-x:auto;max-height:520px;overflow-y:auto;border-radius:8px;border:1px solid #e2e8f0;">
            <table class="preview-table">
                <thead>
                    <tr>
                        <th style="width:36px">
                            <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)"
                                   title="Выбрать все">
                        </th>
                        <th>#</th>
                        <th>Рейс</th>
                        <th>Маршрут</th>
                        <th>Вылет (UTC)</th>
                        <th>Прилёт (UTC)</th>
                        <th>ВС / Рег.</th>
                        <th>MTOW, т</th>
                        <th>Авиакомпания</th>
                        <th>PAX</th>
                        <th>Цель</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody id="preview-tbody">
                    <tr>
                        <td colspan="12">
                            <div class="empty-state">
                                <div class="empty-state-icon">✈</div>
                                <div>Рейсы появятся после распознавания</div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Нижняя панель подтверждения -->
        <div class="confirm-bar">
            <div class="confirm-counter">
                Выбрано <span id="confirm-count">0</span> рейсов для создания
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary" onclick="goToStep(1)">← Назад</button>
                <button class="btn-confirm" id="btn-confirm" onclick="confirmImport()" disabled>
                    ✓ Создать рейсы
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════
         ШАГ 3 — Результат создания
    ══════════════════════════════════ -->
    <div class="step-card" id="step-3">
        <h5 class="fw-bold mb-1" style="color:#1e293b">Шаг 3. Результат</h5>
        <p class="text-muted mb-3" style="font-size:.83rem" id="result-summary"></p>

        <div id="result-created-block" style="display:none">
            <h6 class="fw-bold mb-2 text-success">✅ Создано</h6>
            <p class="text-muted small mb-2">
                <i class="bi bi-info-circle"></i>
                Рейс создан без предзаказа услуг и транспорта — при импорте из
                разрешения этот шаг не проходится (в отличие от формы ручного
                создания рейса). Откройте рейс по ссылке ниже и отметьте нужные
                услуги в карточке «Предзаказ услуг и транспорта» на странице
                редактирования.
            </p>
            <div id="result-created-list"></div>
        </div>

        <div id="result-skipped-block" style="display:none" class="mt-3">
            <h6 class="fw-bold mb-2" style="color:#b45309">⚠ Пропущено (дубли)</h6>
            <div id="result-skipped-list"></div>
        </div>

        <div id="result-error-block" style="display:none" class="mt-3">
            <h6 class="fw-bold mb-2 text-danger">✗ Ошибки</h6>
            <div id="result-error-list"></div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <a href="/flights" class="btn btn-primary">
                📋 Открыть список рейсов
            </a>
            <button class="btn btn-outline-secondary" onclick="resetForm()">
                + Загрузить ещё одно разрешение
            </button>
        </div>
    </div>

</div><!-- /import-page -->

<!-- ── Loader overlay ── -->
<div class="loader-overlay" id="loader">
    <div class="loader-spinner"></div>
    <div class="loader-text" id="loader-text">Распознаём разрешение...</div>
</div>

<script>
// ════════════════════════════════════════════════════════════
// Состояние
// ════════════════════════════════════════════════════════════
let parsedFlights = [];
let parsedLogId   = null; // ДОБАВЛЕНО (раунд 103): id записи скрытого журнала разрешений
const CSRF_TOKEN  = '<?= htmlspecialchars($csrf_token) ?>';

// ════════════════════════════════════════════════════════════
// ШАГ 1 → Парсинг
// ════════════════════════════════════════════════════════════
async function parsePermission() {
    const text = document.getElementById('permission-text').value.trim();

    if (text.length < 20) {
        showError('parse-error', 'Вставьте текст разрешения (минимум 20 символов).');
        return;
    }

    hideError('parse-error');
    showLoader('ИИ распознаёт разрешение...');
    setParseBtn(true);

    try {
        const fd = new FormData();
        fd.append('_csrf',            CSRF_TOKEN); // ИСПРАВЛЕНО 10.07.2026: было 'csrf_token', не совпадало с Csrf::FIELD_NAME
        fd.append('permission_text',  text);

        const res  = await fetch('/flights/import/parse', {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd,
        });

        const json = await res.json();

        if (!json.success) {
            showError('parse-error', json.error || 'Ошибка распознавания.');
            return;
        }

        parsedFlights = json.data.flights;
        parsedLogId   = json.data.permit_log_id ?? null;
        renderPreview(json.data.flights, json.data.format);
        goToStep(2);

    } catch (e) {
        showError('parse-error', 'Сетевая ошибка: ' + e.message);
    } finally {
        hideLoader();
        setParseBtn(false);
    }
}

// ════════════════════════════════════════════════════════════
// Рендер таблицы предпросмотра
// ════════════════════════════════════════════════════════════
function renderPreview(flights, format) {
    // Формат-бейдж
    const fmtEl = document.getElementById('format-badge-placeholder');
    const fmtClass = format === 'CAA_UZB' ? 'format-caa' : (format === 'PLANNING_NOTICE' ? 'format-notice' : 'format-tarmac');
    const fmtLabel = format === 'CAA_UZB' ? 'CAA UZB' : (format === 'PLANNING_NOTICE' ? 'Уведомление ЦОК (не разрешение ЦАУ)' : 'TARMAC / REVISION');
    fmtEl.innerHTML = `<span class="format-badge ${fmtClass}">${fmtLabel}</span>`;

    document.getElementById('total-count').textContent = flights.length;

    const tbody = document.getElementById('preview-tbody');
    tbody.innerHTML = '';

    if (!flights.length) {
        tbody.innerHTML = '<tr><td colspan="12"><div class="empty-state"><div>Рейсы не распознаны</div></div></td></tr>';
        return;
    }

    flights.forEach((f, idx) => {
        const row = buildRow(f, idx);
        tbody.appendChild(row);
    });

    updateSelectionCount();
}

function buildRow(f, idx) {
    const tr = document.createElement('tr');
    tr.id = `row-${idx}`;

    // Классы строки
    if (f.is_duplicate) tr.classList.add('row-duplicate');
    else if (f.selected)  tr.classList.add('row-selected');
    if (!f.touches_our_airport) tr.classList.add('row-not-ours');

    // Статус-пилюля
    let statusHtml = '';
    if (f.is_duplicate) {
        statusHtml = `<span class="pill pill-dup">Дубль #${f.duplicate_id}</span>`;
    } else if (!f.ready) {
        statusHtml = `<span class="pill pill-warn">Требует правки</span>`;
    } else {
        statusHtml = `<span class="pill pill-ok">Готов</span>`;
    }
    if (f.touches_our_airport) {
        statusHtml += ` <span class="pill pill-us" title="Касается аэропорта TVT">TVT</span>`;
    }

    // Предупреждения
    let warningsHtml = '';
    if (f.warnings && f.warnings.length) {
        warningsHtml = '<ul class="warning-list">'
            + f.warnings.map(w => `<li>${escHtml(w)}</li>`).join('')
            + '</ul>';
    }

    // Направление
    const dirIcon = f.direction === 'DEP' ? '↗' : f.direction === 'ARR' ? '↘' : '↔';

    tr.innerHTML = `
        <td>
            <input type="checkbox" class="flight-checkbox" data-idx="${idx}"
                   ${f.selected && !f.is_duplicate ? 'checked' : ''}
                   ${f.is_duplicate ? 'disabled title="Рейс уже существует"' : ''}
                   onchange="onCheckboxChange(${idx}, this)">
        </td>
        <td><span style="color:#94a3b8;font-size:.75rem">${idx + 1}</span></td>
        <td>
            <input class="editable-input fw-bold" style="width:100px;font-family:monospace"
                   data-idx="${idx}" data-field="flight_number"
                   value="${escHtml(f.flight_number)}"
                   onchange="updateField(${idx}, 'flight_number', this.value)">
            <div style="font-size:.68rem;color:#94a3b8;margin-top:2px">${dirIcon} ${escHtml(f.flight_purpose)}</div>
        </td>
        <td>
            <div class="d-flex align-items-center gap-1">
                <input class="editable-input" style="width:52px;font-family:monospace;font-weight:700"
                       data-idx="${idx}" data-field="origin_icao"
                       value="${escHtml(f.origin_icao)}"
                       onchange="updateField(${idx}, 'origin_icao', this.value)">
                <span style="color:#94a3b8">→</span>
                <input class="editable-input" style="width:52px;font-family:monospace;font-weight:700"
                       data-idx="${idx}" data-field="destination_icao"
                       value="${escHtml(f.destination_icao)}"
                       onchange="updateField(${idx}, 'destination_icao', this.value)">
            </div>
            <div style="font-size:.68rem;color:#64748b;margin-top:2px">
                ${escHtml(f.origin_name)} → ${escHtml(f.destination_name)}
            </div>
        </td>
        <td>
            <input type="datetime-local" class="editable-input" style="width:160px"
                   data-idx="${idx}" data-field="scheduled_departure"
                   value="${datetimeToInput(f.scheduled_departure)}"
                   onchange="updateField(${idx}, 'scheduled_departure', inputToDatetime(this.value))">
        </td>
        <td>
            <input type="datetime-local" class="editable-input" style="width:160px"
                   data-idx="${idx}" data-field="scheduled_arrival"
                   value="${datetimeToInput(f.scheduled_arrival)}"
                   onchange="updateField(${idx}, 'scheduled_arrival', inputToDatetime(this.value))">
        </td>
        <td>
            <input class="editable-input" style="width:80px;font-family:monospace"
                   data-idx="${idx}" data-field="aircraft_registration"
                   value="${escHtml(f.aircraft_registration)}"
                   onchange="updateField(${idx}, 'aircraft_registration', this.value)">
            <div style="font-size:.68rem;color:#94a3b8;margin-top:2px">${escHtml(f.aircraft_type)}</div>
        </td>
        <td>
            <input class="editable-input" style="width:64px;text-align:center"
                   type="number" step="0.1" min="0"
                   data-idx="${idx}" data-field="mtow"
                   value="${f.mtow !== null && f.mtow !== undefined ? f.mtow : ''}"
                   placeholder="—"
                   onchange="updateField(${idx}, 'mtow', this.value ? parseFloat(this.value) : null)">
        </td>
        <td>
            <div style="font-size:.82rem;font-weight:600">${escHtml(f.operator_name)}</div>
            <div style="font-size:.68rem;color:#94a3b8">
                ${f.operator_icao ? 'ICAO: ' + escHtml(f.operator_icao) : ''}
                ${f.db_airline_id
                    ? '<span class="pill pill-ok" style="font-size:.65rem">в базе</span>'
                    : '<span class="pill pill-warn" style="font-size:.65rem">будет создана</span>'}
            </div>
        </td>
        <td>
            <input class="editable-input" style="width:42px;text-align:center"
                   type="number" min="0" max="999"
                   data-idx="${idx}" data-field="pax"
                   value="${f.pax !== null ? f.pax : ''}"
                   placeholder="—"
                   onchange="updateField(${idx}, 'pax', this.value ? parseInt(this.value) : null)">
        </td>
        <td>
            <select class="editable-input" style="width:110px"
                    data-idx="${idx}" data-field="flight_purpose"
                    onchange="updateField(${idx}, 'flight_purpose', this.value)">
                ${purposeOptions(f.flight_purpose)}
            </select>
        </td>
        <td>
            ${statusHtml}
            ${warningsHtml}
        </td>
    `;

    return tr;
}

function purposeOptions(selected) {
    const opts = {
        'COMMERCIAL':  'Коммерческий',
        'BUSINESS':    'Деловой',
        'FERRY':       'Перегоночный',
        'EMPTY':       'Пустой',
        'POSITIONING': 'Позиционирование',
        'MIXED':       'Смешанный',
    };
    return Object.entries(opts)
        .map(([v, l]) => `<option value="${v}" ${v === (selected||'').toUpperCase() ? 'selected' : ''}>${l}</option>`)
        .join('');
}

// ════════════════════════════════════════════════════════════
// Управление выбором строк
// ════════════════════════════════════════════════════════════
function onCheckboxChange(idx, el) {
    parsedFlights[idx].selected = el.checked;
    const row = document.getElementById(`row-${idx}`);
    row.classList.toggle('row-selected', el.checked);
    updateSelectionCount();
}

function toggleSelectAll(masterCb) {
    document.querySelectorAll('.flight-checkbox:not(:disabled)').forEach(cb => {
        cb.checked = masterCb.checked;
        const idx = parseInt(cb.dataset.idx);
        parsedFlights[idx].selected = masterCb.checked;
        document.getElementById(`row-${idx}`).classList.toggle('row-selected', masterCb.checked);
    });
    updateSelectionCount();
}

function updateSelectionCount() {
    const count = parsedFlights.filter(f => f.selected && !f.is_duplicate).length;
    document.getElementById('selected-count').textContent = count;
    document.getElementById('confirm-count').textContent  = count;
    document.getElementById('btn-confirm').disabled = count === 0;
}

// ════════════════════════════════════════════════════════════
// Редактирование поля
// ════════════════════════════════════════════════════════════
function updateField(idx, field, value) {
    parsedFlights[idx][field] = value;
}

// ════════════════════════════════════════════════════════════
// ШАГ 2 → Подтверждение и создание
// ════════════════════════════════════════════════════════════
async function confirmImport() {
    const selected = parsedFlights.filter(f => f.selected && !f.is_duplicate);

    if (!selected.length) {
        alert('Выберите хотя бы один рейс.');
        return;
    }

    if (!confirm(`Создать ${selected.length} рейс(ов) в системе?`)) return;

    showLoader('Создаём рейсы...');
    document.getElementById('btn-confirm').disabled = true;

    try {
        const fd = new FormData();
        fd.append('_csrf',       CSRF_TOKEN); // ИСПРАВЛЕНО 10.07.2026: было 'csrf_token', не совпадало с Csrf::FIELD_NAME
        fd.append('flights_json', JSON.stringify(selected));
        fd.append('permit_log_id', parsedLogId ?? '');

        const res  = await fetch('/flights/import/confirm', {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd,
        });

        const json = await res.json();

        if (!json.success) {
            alert('Ошибка: ' + (json.error || 'Неизвестная ошибка'));
            return;
        }

        renderResult(json.data);
        goToStep(3);

    } catch (e) {
        alert('Сетевая ошибка: ' + e.message);
    } finally {
        hideLoader();
        document.getElementById('btn-confirm').disabled = false;
    }
}

// ════════════════════════════════════════════════════════════
// Рендер результата (шаг 3)
// ════════════════════════════════════════════════════════════
function renderResult(data) {
    document.getElementById('result-summary').textContent = data.summary;

    if (data.created && data.created.length) {
        document.getElementById('result-created-block').style.display = 'block';
        document.getElementById('result-created-list').innerHTML =
            data.created.map(r =>
                `<div class="result-card result-created">
                    <strong>${escHtml(r.flight_number)}</strong>
                    &nbsp;·&nbsp; ${escHtml(r.route)}
                    &nbsp;·&nbsp; ${escHtml(r.departure)}
                    &nbsp;·&nbsp;
                    <a href="/flights/${r.flight_id}/edit" target="_blank">Открыть рейс #${r.flight_id} и отметить услуги</a>
                    ${(r.warnings && r.warnings.length) ? `
                    <div class="text-warning small mt-1">
                        <i class="bi bi-exclamation-triangle"></i>
                        ${r.warnings.map(escHtml).join('; ')} —
                        аэропорт создан черновиком, дозаполните в Справочники → Аэропорты.
                    </div>` : ''}
                </div>`
            ).join('');
    }

    if (data.skipped && data.skipped.length) {
        document.getElementById('result-skipped-block').style.display = 'block';
        document.getElementById('result-skipped-list').innerHTML =
            data.skipped.map(r =>
                `<div class="result-card result-skipped">
                    <strong>${escHtml(r.flight_number)}</strong>
                    — дубль существующего рейса
                    <a href="/flights/${r.duplicate_id}" target="_blank">#${r.duplicate_id}</a>
                </div>`
            ).join('');
    }

    if (data.errors && data.errors.length) {
        document.getElementById('result-error-block').style.display = 'block';
        document.getElementById('result-error-list').innerHTML =
            data.errors.map(r =>
                `<div class="result-card result-error">
                    <strong>${escHtml(r.flight_number)}</strong>: ${escHtml(r.error)}
                </div>`
            ).join('');
    }
}

// ════════════════════════════════════════════════════════════
// Навигация по шагам
// ════════════════════════════════════════════════════════════
function goToStep(n) {
    [1, 2, 3].forEach(i => {
        document.getElementById(`step-${i}`).classList.toggle('active', i === n);
        const ind = document.getElementById(`step-ind-${i}`);
        ind.classList.remove('active', 'done');
        if (i < n)       ind.classList.add('done');
        else if (i === n) ind.classList.add('active');
    });

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    parsedFlights = [];
    document.getElementById('permission-text').value = '';
    document.getElementById('preview-tbody').innerHTML = '';
    document.getElementById('result-created-block').style.display = 'none';
    document.getElementById('result-skipped-block').style.display = 'none';
    document.getElementById('result-error-block').style.display   = 'none';
    document.getElementById('result-summary').textContent = '';
    goToStep(1);
}

function clearForm() {
    document.getElementById('permission-text').value = '';
    document.getElementById('permission-text').focus();
}

// ════════════════════════════════════════════════════════════
// Утилиты
// ════════════════════════════════════════════════════════════
function showLoader(text) {
    document.getElementById('loader-text').textContent = text || 'Загрузка...';
    document.getElementById('loader').classList.add('active');
}
function hideLoader() {
    document.getElementById('loader').classList.remove('active');
}
function setParseBtn(disabled) {
    document.getElementById('btn-parse').disabled = disabled;
}
function showError(id, msg) {
    const el = document.getElementById(id);
    if (el) { el.textContent = msg; el.style.display = 'block'; }
}
function hideError(id) {
    const el = document.getElementById(id);
    if (el) { el.style.display = 'none'; el.textContent = ''; }
}
function escHtml(str) {
    if (!str && str !== 0) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
// "2026-05-13 11:00:00"  →  "2026-05-13T11:00"
function datetimeToInput(dt) {
    if (!dt) return '';
    return dt.replace(' ', 'T').substring(0, 16);
}
// "2026-05-13T11:00"  →  "2026-05-13 11:00:00"
function inputToDatetime(val) {
    if (!val) return '';
    return val.replace('T', ' ') + ':00';
}

// ── Разрешаем отправку Enter в textarea только с Shift ──
document.getElementById('permission-text').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { /* ничего */ }
});
</script>