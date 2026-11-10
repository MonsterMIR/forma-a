<?php
// resources/views/flights/import_permission.php
$this->layout('layouts/app', ['title' => 'Импорт рейсов из разрешений ГА']);
?>

<div class="container-fluid py-4">
  <div class="row justify-content-center">
    <div class="col-lg-10">

      <!-- Шапка -->
      <div class="d-flex align-items-center gap-3 mb-4">
        <a href="/flights" class="btn btn-sm btn-outline-secondary">← Рейсы</a>
        <div>
          <h4 class="mb-0">Импорт из разрешений ГА</h4>
          <small class="text-muted">Только рейсы через <strong>UZTP</strong> (Ташкент-Восточный)</small>
        </div>
      </div>

      <!-- Шаг 1: ввод текста -->
      <div id="step-input">
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="row g-4">
              <div class="col-md-8">
                <label class="form-label fw-semibold">Текст разрешения ГА</label>
                <textarea id="perm-text" class="form-control font-monospace" rows="14"
                  placeholder="Вставьте текст разрешения от ГА Узбекистана...&#10;&#10;Поддерживаются все 4 формата: REVISION, CAA UZB (одиночный/многоаэропортный/многосегментный).&#10;Можно вставить сразу несколько разрешений."></textarea>
              </div>
              <div class="col-md-4">
                <div class="alert alert-info py-2 px-3 small mb-3">
                  <strong>Поддерживаемые форматы:</strong><br>
                  1. REVISION (Tarmac Ops)<br>
                  2. CAA UZB — одиночный рейс<br>
                  3. CAA UZB — несколько аэропортов<br>
                  4. CAA UZB — многосегментный
                </div>
                <div class="alert alert-success py-2 px-3 small">
                  <i class="bi bi-funnel"></i>
                  <strong>Фильтрация:</strong> из текста будут извлечены только ноги с прилётом или вылетом через <strong>UZTP</strong>.
                </div>
              </div>
            </div>

            <div class="mt-3 d-flex gap-2 align-items-center">
              <button id="btn-parse" class="btn btn-primary">
                <i class="bi bi-search me-1"></i> Анализировать разрешение
              </button>
              <span id="parse-spinner" class="spinner-border spinner-border-sm text-secondary d-none" role="status"></span>
              <span id="parse-error" class="text-danger small"></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Шаг 2: предварительный просмотр -->
      <div id="step-preview" class="d-none">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-1">Предварительный просмотр</h5>
            <div id="preview-summary" class="text-muted small"></div>
          </div>
          <div class="d-flex gap-2">
            <button id="btn-back" class="btn btn-outline-secondary btn-sm">← Назад</button>
            <button id="btn-confirm" class="btn btn-success btn-sm">
              <i class="bi bi-check-circle me-1"></i> Создать рейсы
            </button>
          </div>
        </div>
        <div id="flights-list"></div>
      </div>

    </div>
  </div>
</div>

<!-- Шаблон карточки рейса -->
<template id="tpl-card">
  <div class="card shadow-sm mb-3 flight-card">
    <div class="card-body py-3">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
            <span class="badge dir-badge"></span>
            <strong class="fs-6 call-sign-label"></strong>
            <code class="small text-muted reg-label"></code>
            <small class="text-muted type-label"></small>
          </div>
          <small class="text-muted operator-label"></small>
        </div>
        <button class="btn btn-sm btn-outline-secondary btn-edit">
          <i class="bi bi-pencil"></i> Изменить
        </button>
      </div>

      <!-- Маршрут -->
      <div class="bg-light rounded d-flex align-items-center justify-content-between px-4 py-2 mb-3 route-bar">
        <div class="text-center">
          <div class="fs-4 fw-semibold from-code"></div>
          <small class="from-label text-muted"></small>
          <div class="small time-from"></div>
        </div>
        <div class="text-center text-muted">
          <div>→</div>
          <small class="route-date"></small>
        </div>
        <div class="text-center">
          <div class="fs-4 fw-semibold to-code"></div>
          <small class="to-label text-muted"></small>
          <div class="small time-to"></div>
        </div>
      </div>

      <!-- Поля (только для просмотра) -->
      <div class="row g-2 view-fields small">
        <div class="col-md-2"><span class="text-muted">Дата:</span> <span class="f-date"></span></div>
        <div class="col-md-2"><span class="text-muted">Время UTC:</span> <span class="f-time"></span></div>
        <div class="col-md-3"><span class="text-muted">Авиакомпания:</span> <span class="f-operator"></span></div>
        <div class="col-md-2"><span class="text-muted">PAX:</span> <span class="f-pax"></span></div>
        <div class="col-md-3"><span class="text-muted">Цель:</span> <span class="f-purpose"></span></div>
        <div class="col-md-5"><span class="text-muted">Разрешение ГА:</span> <span class="f-perm"></span></div>
        <div class="col-md-2"><span class="text-muted">ICAO опер.:</span> <span class="f-icao"></span></div>
        <div class="col-md-2"><span class="text-muted">MTOW:</span> <span class="f-mtow"></span></div>
        <!-- ДОБАВЛЕНО (22.09.2026, раунд 51): хендлер, распознанный из строки
             "ACC AGR WITH ..." заявки — просмотр-только, редактируется потом
             в справочнике "Хендлеры", если понадобится поправить. -->
        <div class="col-md-5"><span class="text-muted">Хендлер (из заявки):</span> <span class="f-handler"></span></div>
      </div>

      <!-- Поля редактирования (скрыты по умолчанию) -->
      <div class="row g-2 edit-fields d-none small">
        <div class="col-md-2"><label class="form-label mb-0 text-muted x-small">Дата</label><input class="form-control form-control-sm" name="date"></div>
        <div class="col-md-2"><label class="form-label mb-0 text-muted x-small">Время UTC</label><input class="form-control form-control-sm" name="time_utc"></div>
        <div class="col-md-3"><label class="form-label mb-0 text-muted x-small">Авиакомпания</label><input class="form-control form-control-sm" name="operator"></div>
        <div class="col-md-1"><label class="form-label mb-0 text-muted x-small">PAX</label><input class="form-control form-control-sm" name="pax" type="number"></div>
        <div class="col-md-2"><label class="form-label mb-0 text-muted x-small">Тип ВС</label><input class="form-control form-control-sm" name="aircraft_type"></div>
        <div class="col-md-2"><label class="form-label mb-0 text-muted x-small">Рег. знак</label><input class="form-control form-control-sm" name="reg"></div>
        <div class="col-md-3"><label class="form-label mb-0 text-muted x-small">Цель рейса</label><input class="form-control form-control-sm" name="purpose"></div>
        <div class="col-md-4"><label class="form-label mb-0 text-muted x-small">Разрешение ГА</label><input class="form-control form-control-sm" name="perm_num"></div>
        <div class="col-md-2"><label class="form-label mb-0 text-muted x-small">ICAO оператора</label><input class="form-control form-control-sm" name="operator_icao"></div>
        <div class="col-md-2"><label class="form-label mb-0 text-muted x-small">MTOW</label><input class="form-control form-control-sm" name="mtow"></div>
        <div class="col-12">
          <button class="btn btn-sm btn-success btn-save-edit me-2">Сохранить</button>
          <button class="btn btn-sm btn-outline-secondary btn-cancel-edit">Отмена</button>
        </div>
      </div>
    </div>
  </div>
</template>

<!-- Скрытая форма для финального сабмита -->
<form id="form-submit" method="POST" action="/flights/store-from-permission" class="d-none">
  <?= csrf_field() ?>
  <input type="hidden" name="flights_json" id="flights-json-input">
</form>

<script>
let parsedFlights = [];

// ─── Парсинг ───────────────────────────────────────────────────
document.getElementById('btn-parse').addEventListener('click', async () => {
  const text = document.getElementById('perm-text').value.trim();
  if (!text) return;

  const spinner = document.getElementById('parse-spinner');
  const errEl   = document.getElementById('parse-error');
  spinner.classList.remove('d-none');
  errEl.textContent = '';

  try {
    const res  = await fetch('/flights/parse-permission', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ text, _token: document.querySelector('[name=_token]')?.value || '' })
    });
    const data = await res.json();

    if (!data.success) {
      errEl.textContent = data.error || 'Ошибка';
    } else {
      parsedFlights = data.flights;
      renderPreview();
      document.getElementById('step-input').classList.add('d-none');
      document.getElementById('step-preview').classList.remove('d-none');
    }
  } catch (e) {
    errEl.textContent = 'Сетевая ошибка: ' + e.message;
  }
  spinner.classList.add('d-none');
});

// ─── Назад ─────────────────────────────────────────────────────
document.getElementById('btn-back').addEventListener('click', () => {
  document.getElementById('step-preview').classList.add('d-none');
  document.getElementById('step-input').classList.remove('d-none');
});

// ─── Подтверждение ─────────────────────────────────────────────
document.getElementById('btn-confirm').addEventListener('click', () => {
  document.getElementById('flights-json-input').value = JSON.stringify(parsedFlights);
  document.getElementById('form-submit').submit();
});

// ─── Рендер карточек ───────────────────────────────────────────
function renderPreview() {
  const list = document.getElementById('flights-list');
  const tpl  = document.getElementById('tpl-card');
  list.innerHTML = '';

  const arrN = parsedFlights.filter(f => f.direction === 'ARR').length;
  const depN = parsedFlights.filter(f => f.direction === 'DEP').length;
  document.getElementById('preview-summary').innerHTML =
    `<span class="text-success">▼ Прилётов: ${arrN}</span> &nbsp; <span class="text-warning">▲ Вылетов: ${depN}</span> &nbsp; Итого: ${parsedFlights.length}`;
  document.getElementById('btn-confirm').textContent = `✓ Создать рейсы (${parsedFlights.length})`;

  parsedFlights.forEach((f, idx) => {
    const card = tpl.content.cloneNode(true);
    const el   = card.querySelector('.flight-card');
    const isArr = f.direction === 'ARR';

    el.style.borderLeft = `3px solid ${isArr ? '#198754' : '#ffc107'}`;

    // Бэдж
    const badge = el.querySelector('.dir-badge');
    badge.textContent = isArr ? '▼ Прилёт' : '▲ Вылет';
    badge.className   = `badge ${isArr ? 'bg-success' : 'bg-warning text-dark'} dir-badge`;

    el.querySelector('.call-sign-label').textContent = f.call_sign;
    el.querySelector('.reg-label').textContent        = f.reg;
    el.querySelector('.type-label').textContent       = f.aircraft_type;
    el.querySelector('.operator-label').textContent   = f.operator;

    // Маршрут
    const fromCode = isArr ? f.other_airport : 'UZTP';
    const toCode   = isArr ? 'UZTP' : f.other_airport;
    el.querySelector('.from-code').textContent = fromCode;
    el.querySelector('.to-code').textContent   = toCode;
    el.querySelector('.from-code').style.color = isArr ? '#6c757d' : '#ffc107';
    el.querySelector('.to-code').style.color   = isArr ? '#198754' : '#6c757d';
    el.querySelector('.to-label').textContent  = isArr ? 'Ташкент-Восточный' : '';
    el.querySelector('.from-label').textContent = !isArr ? 'Ташкент-Восточный' : '';
    el.querySelector('.route-date').textContent = f.date;
    el.querySelector('.time-from').textContent  = !isArr ? `ETD ${f.time_utc}Z` : '';
    el.querySelector('.time-to').textContent    = isArr  ? `ETA ${f.time_utc}Z` : '';

    // Поля просмотра
    el.querySelector('.f-date').textContent     = f.date;
    el.querySelector('.f-time').textContent     = f.time_utc;
    el.querySelector('.f-operator').textContent = f.operator;
    el.querySelector('.f-pax').textContent      = f.pax ?? '—';
    el.querySelector('.f-purpose').textContent  = f.purpose;
    el.querySelector('.f-perm').textContent     = f.perm_num ?? '—';
    el.querySelector('.f-icao').textContent     = f.operator_icao ?? '—';
    el.querySelector('.f-mtow').textContent     = f.mtow ?? '—';
    el.querySelector('.f-handler').textContent  = f.handler_name ?? '—';

    // Заполняем поля редактирования
    ['call_sign','reg','aircraft_type','operator','operator_icao','date','time_utc','pax','purpose','perm_num','mtow'].forEach(k => {
      const inp = el.querySelector(`[name="${k}"]`);
      if (inp) inp.value = f[k] ?? '';
    });

    // Кнопка редактирования
    el.querySelector('.btn-edit').addEventListener('click', () => {
      el.querySelector('.view-fields').classList.add('d-none');
      el.querySelector('.edit-fields').classList.remove('d-none');
      el.querySelector('.btn-edit').classList.add('d-none');
    });
    el.querySelector('.btn-save-edit').addEventListener('click', () => {
      ['call_sign','reg','aircraft_type','operator','operator_icao','date','time_utc','pax','purpose','perm_num','mtow'].forEach(k => {
        const inp = el.querySelector(`[name="${k}"]`);
        if (inp) parsedFlights[idx][k] = inp.value || (k === 'pax' ? null : null) || inp.value;
      });
      list.innerHTML = '';
      renderPreview();
    });
    el.querySelector('.btn-cancel-edit').addEventListener('click', () => {
      el.querySelector('.view-fields').classList.remove('d-none');
      el.querySelector('.edit-fields').classList.add('d-none');
      el.querySelector('.btn-edit').classList.remove('d-none');
    });

    list.appendChild(card);
  });
}
</script>