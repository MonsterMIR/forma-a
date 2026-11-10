<?php
/**
 * flights/create.php
 * - При выборе "Прилёт" → поле "Куда" = TVT (Ташкент-Восточный) автоматически
 * - При выборе "Вылет" → поле "Откуда" = TVT (Ташкент-Восточный) автоматически
 * - Поиск по аэропортам через Tom Select
 */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-plus-circle"></i> <?= __('FLIGHTS_CREATE') ?></h2>
    <a href="/flights" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?>
    </a>
</div>

<!-- Tom Select CSS для поиска в dropdown -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">

<div class="row g-4">
<div class="col-lg-7">
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/flights/create" id="flightCreateForm">
            <?= csrf_field() ?>
            <!-- ДОБАВЛЕНО (16.10.2026, раунд 93): одноразовый токен отправки —
                 см. FlightController::create()/store(). Защита от двойного
                 клика/повторной отправки формы (нашли реальный дубль AXS4/T7POL). -->
            <input type="hidden" name="_submit_token" value="<?= e($submitToken ?? '') ?>">
            <div class="row g-3">

                <!-- Авиакомпания с поиском -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">
                        <?= __('FLIGHTS_AIRLINE') ?> <span class="text-danger">*</span>
                    </label>
                    <select name="airline_id" id="airlineSelect" required>
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <?php foreach ($airlines as $al): ?>
                        <option value="<?= (int)$al['id'] ?>"
                            <?= (($_POST['airline_id'] ?? '') == $al['id']) ? 'selected' : '' ?>>
                            <?= e($al['iata_code'] ? $al['iata_code'] . ' — ' . $al['name'] : $al['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Номер рейса -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <?= __('FLIGHTS_NUMBER') ?> <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="flight_number" class="form-control" required
                           maxlength="20" style="text-transform:uppercase"
                           value="<?= e($_POST['flight_number'] ?? '') ?>"
                           placeholder="0001">
                </div>

                <!-- Дата рейса -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold">
                        <?= __('FLIGHTS_DATE') ?> <span class="text-danger">*</span>
                    </label>
                    <input type="date" name="flight_date" class="form-control" required
                           value="<?= e($_POST['flight_date'] ?? date('Y-m-d')) ?>">
                </div>

                <!-- Плановое время STD/STA -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold">
                        STD/STA <i class="bi bi-clock text-muted" title="Плановое время вылета/прилёта"></i>
                    </label>
                    <input type="time" name="scheduled_time" class="form-control"
                           value="<?= e($_POST['scheduled_time'] ?? '') ?>"
                           placeholder="00:00">
                    <div class="form-text">Для контроля сроков</div>
                </div>

                <!-- Тип маршрута -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <?= __('FLIGHTS_ROUTE_TYPE') ?> <span class="text-danger">*</span>
                    </label>
                    <select name="route_type" id="routeTypeSelect" class="form-select" required>
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <option value="domestic" <?= (($_POST['route_type'] ?? '') === 'domestic') ? 'selected' : '' ?>>
                            <?= __('FLIGHTS_DOMESTIC') ?>
                        </option>
                        <option value="international" <?= (($_POST['route_type'] ?? '') === 'international') ? 'selected' : '' ?>>
                            <?= __('FLIGHTS_INTERNATIONAL') ?>
                        </option>
                    </select>
                </div>

                <!-- Операция -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <?= __('FLIGHTS_OPERATION') ?> <span class="text-danger">*</span>
                    </label>
                    <select name="operation_type" id="operationSelect" class="form-select" required>
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <option value="arrival" <?= (($_POST['operation_type'] ?? '') === 'arrival') ? 'selected' : '' ?>>
                            <?= __('FLIGHTS_ARRIVAL') ?>
                        </option>
                        <option value="departure" <?= (($_POST['operation_type'] ?? '') === 'departure') ? 'selected' : '' ?>>
                            <?= __('FLIGHTS_DEPARTURE') ?>
                        </option>
                    </select>
                </div>

                <!-- Пустая колонка для выравнивания -->
                <div class="col-md-6"></div>

                <!-- Откуда — с поиском -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" id="originLabel">
                        <?= __('FLIGHTS_ORIGIN') ?>
                    </label>
                    <select name="origin_airport_id" id="originSelect">
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <?php foreach ($airports as $ap): ?>
                        <option value="<?= (int)$ap['id'] ?>"
                            data-iata="<?= e($ap['iata_code'] ?? '') ?>"
                            <?= (($_POST['origin_airport_id'] ?? '') == $ap['id']) ? 'selected' : '' ?>>
                            <?= e($ap['iata_code'] ? $ap['iata_code'] . ' — ' . $ap['name'] : $ap['name']) ?>
                            <?= $ap['city'] ? '(' . e($ap['city']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="originHint" class="form-text text-success d-none">
                        <i class="bi bi-geo-alt-fill"></i> <?= __('FLIGHTS_HOME_AIRPORT_AUTO') ?>
                    </div>
                </div>

                <!-- Куда — с поиском -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" id="destLabel">
                        <?= __('FLIGHTS_DESTINATION') ?>
                    </label>
                    <select name="destination_airport_id" id="destSelect">
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <?php foreach ($airports as $ap): ?>
                        <option value="<?= (int)$ap['id'] ?>"
                            data-iata="<?= e($ap['iata_code'] ?? '') ?>"
                            <?= (($_POST['destination_airport_id'] ?? '') == $ap['id']) ? 'selected' : '' ?>>
                            <?= e($ap['iata_code'] ? $ap['iata_code'] . ' — ' . $ap['name'] : $ap['name']) ?>
                            <?= $ap['city'] ? '(' . e($ap['city']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="destHint" class="form-text text-success d-none">
                        <i class="bi bi-geo-alt-fill"></i> <?= __('FLIGHTS_HOME_AIRPORT_AUTO') ?>
                    </div>
                </div>

                <!-- Регистрация ВС -->
                <!-- ИЗМЕНЕНО (12.10.2026, раунд 69, по прямому указанию пользователя):
                     поле сделано обязательным — без него система не может автоматически
                     найти и связать пару прилёт⇄вылет (FlightService::autoLinkFlight()),
                     и рейс навсегда остаётся непарным без возможности это даже заметить.
                     См. FlightController::store().
                     ИЗМЕНЕНО (22.10.2026, раунд 117, по прямому указанию пользователя):
                     поле перенесено ВЫШЕ типа ВС/MTOW и получило JS-поиск по справочнику
                     бортов (App\Controllers\AircraftController::lookup()) — если борт уже
                     известен и подтверждён, тип ВС/MTOW подставляются автоматически и
                     блокируются от редактирования (сервер всё равно их переопределит —
                     см. FlightService::createFlight() — поэтому и на форме скрываем
                     саму возможность разойтись). -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">
                        <?= __('FLIGHTS_AIRCRAFT_REG') ?>
                        <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="aircraft_registration" id="aircraftRegInput" class="form-control" maxlength="20" required
                           style="text-transform:uppercase"
                           placeholder="UK-67890"
                           value="<?= e($_POST['aircraft_registration'] ?? '') ?>"
                           title="Обязательно — по нему система находит и связывает пару рейсов прилёта/вылета того же борта">
                    <div class="form-text">Нужен, чтобы система связала пару прилёт⇄вылет этого борта</div>
                    <div id="aircraftRegistryHint" class="form-text d-none mt-1"></div>
                </div>

                <!-- Тип ВС -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_AIRCRAFT_TYPE') ?></label>
                    <input type="text" name="aircraft_type" id="aircraftTypeInput" class="form-control" maxlength="50"
                           placeholder="B-737, A-320..."
                           value="<?= e($_POST['aircraft_type'] ?? '') ?>">
                </div>

                <!-- MTOW -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold">
                        MTOW <span class="text-muted fw-normal small">(тонн)</span>
                        <span class="text-danger">*</span>
                    </label>
                    <input type="number" name="aircraft_mtow" id="aircraftMtowInput" class="form-control" required
                           min="1" max="650" step="0.1" placeholder="51.0"
                           value="<?= e($_POST['aircraft_mtow'] ?? '') ?>"
                           title="Max Take-Off Weight — необходим для расчёта TLF и зависимых сборов">
                    <div class="form-text">Для расчёта TLF, авиабезопасности, ночного сбора</div>
                </div>

                <!-- ДОБАВЛЕНО (аудит 09.07.2026, п.5): поля есть в БД и используются
                     в печатной Форме А (acts/print.php), но не были выведены в форму —
                     печатались пустыми.
                     ИЗМЕНЕНО (23.09.2026, раунд 52): видна только пара, отвечающая
                     текущей Операции — см. аналогичный комментарий в flights/edit.php.
                     Оба поля остаются в форме под настоящими именами. Пока Операция
                     ещё не выбрана (новый рейс), по умолчанию показываем пару прилёта. -->
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-12">
                    <div class="text-muted small fw-semibold text-uppercase mb-1">
                        Фактические данные обслуживания
                        <span class="fw-normal normal-case">(заполняются по факту / из документов служб)</span>
                    </div>
                </div>

                <?php $isArrival = (($_POST['operation_type'] ?? '') !== 'departure'); ?>
                <div class="col-md-3 dyn-field dyn-field-arrival<?= $isArrival ? '' : ' d-none' ?>">
                    <label class="form-label fw-semibold">Дата прилёта</label>
                    <input type="date" name="arr_date" class="form-control"
                           value="<?= e($_POST['arr_date'] ?? '') ?>">
                </div>
                <div class="col-md-3 dyn-field dyn-field-arrival<?= $isArrival ? '' : ' d-none' ?>">
                    <label class="form-label fw-semibold">Время прилёта (ATA)</label>
                    <input type="time" name="arr_time" class="form-control"
                           value="<?= e($_POST['arr_time'] ?? '') ?>">
                </div>
                <div class="col-md-3 dyn-field dyn-field-departure<?= $isArrival ? ' d-none' : '' ?>">
                    <label class="form-label fw-semibold">Дата вылета</label>
                    <input type="date" name="dep_date" class="form-control"
                           value="<?= e($_POST['dep_date'] ?? '') ?>">
                </div>
                <div class="col-md-3 dyn-field dyn-field-departure<?= $isArrival ? ' d-none' : '' ?>">
                    <label class="form-label fw-semibold">Время вылета (ATD)</label>
                    <input type="time" name="dep_time" class="form-control"
                           value="<?= e($_POST['dep_time'] ?? '') ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">PAX на прилёт</label>
                    <input type="number" name="pax_arrival" class="form-control" min="0"
                           value="<?= e($_POST['pax_arrival'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Из них INFANT (прилёт)</label>
                    <input type="number" name="infant_arrival" class="form-control" min="0"
                           value="<?= e($_POST['infant_arrival'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">PAX на вылет</label>
                    <input type="number" name="pax_departure" class="form-control" min="0"
                           value="<?= e($_POST['pax_departure'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Из них INFANT (вылет)</label>
                    <input type="number" name="infant_departure" class="form-control" min="0"
                           value="<?= e($_POST['infant_departure'] ?? '') ?>">
                </div>

                <?php /* ДОБАВЛЕНО (19.08.2026, раунд 21): DHC — запасной экипаж
                         / экипаж в качестве пассажиров. В печатной форме акта
                         эта подпись уже была, но никакого поля ввода и ячейки
                         значения не существовало вообще — чисто декоративная
                         метка (см. resources/views/acts/print.php). */ ?>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        DHC на прилёт
                        <i class="bi bi-info-circle text-muted" title="Deadhead crew — запасной экипаж/экипаж в качестве пассажиров"></i>
                    </label>
                    <input type="number" name="dhc_arrival" class="form-control" min="0"
                           value="<?= e($_POST['dhc_arrival'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        DHC на вылет
                        <i class="bi bi-info-circle text-muted" title="Deadhead crew — запасной экипаж/экипаж в качестве пассажиров"></i>
                    </label>
                    <input type="number" name="dhc_departure" class="form-control" min="0"
                           value="<?= e($_POST['dhc_departure'] ?? '') ?>">
                </div>

                <div class="col-md-3 dyn-field dyn-field-arrival<?= $isArrival ? '' : ' d-none' ?>">
                    <label class="form-label fw-semibold">
                        Время установки колодок
                        <i class="bi bi-info-circle text-muted" title="Печатается в Форме А отдельной строкой"></i>
                    </label>
                    <input type="text" name="chocks_on" class="form-control" placeholder="чч:мм"
                           maxlength="10" value="<?= e($_POST['chocks_on'] ?? '') ?>">
                </div>
                <div class="col-md-3 dyn-field dyn-field-departure<?= $isArrival ? ' d-none' : '' ?>">
                    <label class="form-label fw-semibold">
                        Время уборки колодок
                        <i class="bi bi-info-circle text-muted" title="Печатается в Форме А отдельной строкой"></i>
                    </label>
                    <input type="text" name="chocks_off" class="form-control" placeholder="чч:мм"
                           maxlength="10" value="<?= e($_POST['chocks_off'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        Номер маршалинга / MC№ <span class="text-danger">*</span>
                    </label>
                    <?php
                        // ИЗМЕНЕНО (18.08.2026, раунд 16): выбор из справочника стоянок
                        // вместо ручного набора — см. App\Models\ParkingStand.
                        // ИЗМЕНЕНО (23.09.2026, раунд 53, по прямому указанию
                        // пользователя): раньше рейс можно было свободно создать
                        // без стоянки — теперь поле обязательно (required —
                        // и на сервере, см. FlightController::store()).
                        $currentStand = $_POST['mc_number'] ?? '';
                    ?>
                    <select name="mc_number" class="form-select" required>
                        <option value="">— выберите стоянку —</option>
                        <?php foreach (($parkingStands ?? []) as $stand): ?>
                        <option value="<?= e($stand['code']) ?>" <?= $currentStand === $stand['code'] ? 'selected' : '' ?>>
                            <?= e($stand['code']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($parkingStands)): ?>
                    <div class="form-text text-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        Нет свободных стоянок (все заняты, либо справочник пуст — Справочники → Стоянки).
                        Создать рейс без стоянки нельзя — освободите/добавьте стоянку и повторите.
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Примечания -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= __('COMMON_NOTES') ?></label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="<?= __('COMMON_OPTIONAL') ?>"><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="flightCreateSubmitBtn">
                    <i class="bi bi-check-lg"></i> <?= __('FLIGHTS_CREATE_BTN') ?>
                </button>
                <a href="/flights" class="btn btn-outline-secondary"><?= __('COMMON_CANCEL') ?></a>
            </div>
        </form>
        <script>
        // ДОБАВЛЕНО (16.10.2026, раунд 92, по прямому указанию пользователя):
        // "двойной клик на кнопку создать рейс" — найдена реальная пара
        // дублирующихся рейсов (один и тот же вылет, создан дважды с разницей
        // в 3 секунды через эту форму), оба успели получить отдельные акты.
        // Серверная защита от дубля (Flight::findIdleDuplicate(), раунд 69)
        // уже есть, но она не исключает гонку при НАСТОЯЩЕМ почти
        // одновременном двойном клике (два запроса успевают пройти проверку
        // раньше, чем первый из них закоммитит INSERT) — здесь убираем саму
        // возможность отправить форму дважды: кнопка блокируется сразу при
        // отправке, второй клик просто не отправит второй запрос.
        (function () {
            var form = document.getElementById('flightCreateForm');
            var btn  = document.getElementById('flightCreateSubmitBtn');
            if (!form || !btn) return;
            form.addEventListener('submit', function () {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> <?= __('FLIGHTS_CREATE_BTN') ?>';
            });
        })();
        </script>
    </div>
</div>
</div>
<!-- /col-lg-7 -->

<!-- ДОБАВЛЕНО (03.08.2026): предзаказ услуг/транспорта прямо на форме
     создания — список зависит от типа маршрута/операции/MTOW слева,
     поэтому подгружается через fetch() по мере заполнения тех полей.
     Чекбоксы физически вне <form>, но привязаны атрибутом form="flightCreateForm"
     и уйдут вместе с остальными данными при отправке. -->
<div class="col-lg-5">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-clipboard2-check me-2"></i>Предзаказ услуг и транспорта
    </div>
    <div class="card-body" id="checklistBody">
        <div class="text-muted text-center py-4" id="checklistPlaceholder">
            <i class="bi bi-arrow-left-circle fs-3 d-block mb-2"></i>
            Заполните тип маршрута, операцию и MTOW слева —
            список услуг появится здесь
        </div>
        <div id="checklistContent" class="d-none"></div>
    </div>
</div>
</div>
<!-- /col-lg-5 -->
</div>
<!-- /row -->

<!-- Tom Select JS -->
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
// ID домашнего аэропорта TVT (Ташкент-Восточный) — из PHP
const HOME_AIRPORT_ID = <?= (int)($homeAirportId ?? 0) ?>;
const HOME_AIRPORT_LABEL = 'TVT — Ташкент-Восточный';

// Инициализируем Tom Select (поиск в dropdown)
const tsConfig = {
    placeholder: '<?= __('COMMON_SEARCH') ?>...',
    allowEmptyOption: true,
    searchField: ['text', 'value'],
    render: {
        option: function(data, escape) {
            return '<div>' + escape(data.text) + '</div>';
        }
    }
};

const tsAirline = new TomSelect('#airlineSelect', { ...tsConfig });
const tsOrigin  = new TomSelect('#originSelect',  { ...tsConfig });
const tsDest    = new TomSelect('#destSelect',    { ...tsConfig });

// ДОБАВЛЕНО (23.09.2026, раунд 52): показываем только ту пару
// дата+время прилёта/вылета и то поле колодок, которое относится к
// текущей Операции — см. аналогичную функцию в flights/edit.php.
function applyOperationFieldVisibility(operation) {
    const showArrival = operation !== 'departure';
    document.querySelectorAll('.dyn-field-arrival').forEach(el => el.classList.toggle('d-none', !showArrival));
    document.querySelectorAll('.dyn-field-departure').forEach(el => el.classList.toggle('d-none', showArrival));
}

// Автовыбор TVT при смене операции
document.getElementById('operationSelect').addEventListener('change', function() {
    applyHomeAirport(this.value);
    applyOperationFieldVisibility(this.value);
});

function applyHomeAirport(operation) {
    if (!HOME_AIRPORT_ID) return;

    if (operation === 'arrival') {
        // Прилёт → Куда = TVT (наш аэропорт)
        tsDest.setValue(HOME_AIRPORT_ID);
        document.getElementById('destHint').classList.remove('d-none');
        document.getElementById('originHint').classList.add('d-none');

    } else if (operation === 'departure') {
        // Вылет → Откуда = TVT (наш аэропорт)
        tsOrigin.setValue(HOME_AIRPORT_ID);
        document.getElementById('originHint').classList.remove('d-none');
        document.getElementById('destHint').classList.add('d-none');

    } else {
        document.getElementById('originHint').classList.add('d-none');
        document.getElementById('destHint').classList.add('d-none');
    }
}

// При изменении откуда/куда вручную — убираем подсказку
tsOrigin.on('change', function(val) {
    if (parseInt(val) !== HOME_AIRPORT_ID) {
        document.getElementById('originHint').classList.add('d-none');
    }
});
tsDest.on('change', function(val) {
    if (parseInt(val) !== HOME_AIRPORT_ID) {
        document.getElementById('destHint').classList.add('d-none');
    }
});

// При загрузке страницы — применяем если уже выбрана операция (после ошибки формы)
const currentOp = document.getElementById('operationSelect').value;
if (currentOp) applyHomeAirport(currentOp);
applyOperationFieldVisibility(currentOp);

// ── Чек-лист услуг/транспорта — подгружается по мере заполнения формы ──────
const ROLE_LABELS = {sop:'СОП', spo:'СПО', sst:'ССТ', aero:'Аэродромная', spasop:'СПАСОП'};
let checkedServiceIds = new Set();
<?php if (!empty($_POST['service_ids'])): ?>
(<?= json_encode(array_map('intval', (array)$_POST['service_ids'])) ?>).forEach(id => checkedServiceIds.add(id));
<?php endif; ?>

function checklistFieldsReady() {
    const rt = document.getElementById('routeTypeSelect').value;
    const op = document.getElementById('operationSelect').value;
    return rt && op;
}

function renderChecklist(data) {
    const content = document.getElementById('checklistContent');
    const placeholder = document.getElementById('checklistPlaceholder');

    if (!data.success || !data.services || Object.keys(data.services).length === 0) {
        content.classList.add('d-none');
        placeholder.classList.remove('d-none');
        placeholder.innerHTML = '<div class="text-muted small py-3">Нет доступных услуг для этих параметров рейса</div>';
        return;
    }

    let html = '<div class="row g-2">';
    for (const role in ROLE_LABELS) {
        if (!data.services[role] || !data.services[role].length) continue;
        const groupId = 'grp_c_' + role;
        const total = data.services[role].length;
        const checkedInGroup = data.services[role].filter(svc => checkedServiceIds.has(svc.id)).length;
        html += '<div class="col-12"><div class="border rounded">';
        html += '<div class="d-flex justify-content-between align-items-center p-2 checklist-group-header" '
             +  'style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#' + groupId + '">'
             +  '<span class="fw-semibold small text-uppercase text-muted">'
             +  '<i class="bi bi-chevron-right me-1 checklist-chevron"></i>' + ROLE_LABELS[role] + '</span>'
             +  '<span class="badge ' + (checkedInGroup ? 'bg-primary' : 'bg-light text-muted') + '" data-count-badge="' + groupId + '">'
             +  checkedInGroup + '/' + total + '</span>'
             +  '</div>';
        html += '<div class="collapse px-2 pb-2" id="' + groupId + '">';
        data.services[role].forEach(svc => {
            const checked = checkedServiceIds.has(svc.id) ? 'checked' : '';
            html += '<div class="form-check py-1">'
                 +  '<input class="form-check-input checklist-cb" type="checkbox" name="service_ids[]" '
                 +  'value="' + svc.id + '" id="csvc_' + svc.id + '" form="flightCreateForm" data-group="' + groupId + '" ' + checked + '>'
                 +  '<label class="form-check-label" for="csvc_' + svc.id + '">' + svc.name + '</label>'
                 +  '</div>';
        });
        html += '</div></div></div>';
    }
    html += '</div>';

    content.innerHTML = html;
    content.classList.remove('d-none');
    placeholder.classList.add('d-none');

    // Шеврон поворачивается при сворачивании/разворачивании группы
    content.querySelectorAll('.checklist-group-header').forEach(header => {
        const targetSel = header.getAttribute('data-bs-target');
        const target = content.querySelector(targetSel);
        const chevron = header.querySelector('.checklist-chevron');
        target.addEventListener('show.bs.collapse', () => chevron.classList.replace('bi-chevron-right', 'bi-chevron-down'));
        target.addEventListener('hide.bs.collapse', () => chevron.classList.replace('bi-chevron-down', 'bi-chevron-right'));
    });

    content.querySelectorAll('.checklist-cb').forEach(cb => {
        cb.addEventListener('change', function() {
            const id = parseInt(this.value);
            if (this.checked) checkedServiceIds.add(id); else checkedServiceIds.delete(id);
            const groupId = this.getAttribute('data-group');
            const badge = content.querySelector('[data-count-badge="' + groupId + '"]');
            if (badge) {
                const groupTotal = content.querySelectorAll('[data-group="' + groupId + '"]').length;
                const groupChecked = content.querySelectorAll('[data-group="' + groupId + '"]:checked').length;
                badge.textContent = groupChecked + '/' + groupTotal;
                badge.className = 'badge ' + (groupChecked ? 'bg-primary' : 'bg-light text-muted');
            }
        });
    });
}

let checklistTimer = null;
function loadChecklist() {
    if (!checklistFieldsReady()) return;

    const params = new URLSearchParams({
        route_type: document.getElementById('routeTypeSelect').value,
        operation_type: document.getElementById('operationSelect').value,
        mtow: document.querySelector('[name="aircraft_mtow"]').value || '',
        date: document.querySelector('[name="flight_date"]').value || ''
    });

    fetch('/flights/checklist-services?' + params.toString(), {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(renderChecklist)
    .catch(() => {
        document.getElementById('checklistPlaceholder').innerHTML =
            '<div class="text-danger small py-3">Не удалось загрузить список услуг</div>';
    });
}

function loadChecklistDebounced() {
    clearTimeout(checklistTimer);
    checklistTimer = setTimeout(loadChecklist, 500);
}

document.getElementById('routeTypeSelect').addEventListener('change', loadChecklist);
document.getElementById('operationSelect').addEventListener('change', loadChecklist);
document.querySelector('[name="aircraft_mtow"]').addEventListener('input', loadChecklistDebounced);
document.querySelector('[name="flight_date"]').addEventListener('change', loadChecklist);

// Если форма вернулась после ошибки валидации, а тип/операция уже выбраны — сразу подгружаем
if (checklistFieldsReady()) loadChecklist();

// ── ДОБАВЛЕНО (22.10.2026, раунд 117, по прямому указанию пользователя) ──
// Справочник бортов: при вводе регистрации уже известного (подтверждённого)
// борта — подставляем тип ВС и MTOW из справочника и блокируем эти поля от
// ручного изменения (сервер их всё равно переопределит — см.
// FlightService::createFlight() — поэтому не даём и на форме создать
// видимость, что можно ввести другое значение). Для нового либо спорного
// борта поля остаются как раньше — обычный ручной ввод.
(function () {
    const regInput  = document.getElementById('aircraftRegInput');
    const typeInput = document.getElementById('aircraftTypeInput');
    const mtowInput = document.getElementById('aircraftMtowInput');
    const hint      = document.getElementById('aircraftRegistryHint');
    if (!regInput || !typeInput || !mtowInput || !hint) return;

    let registryTimer = null;

    function showHint(html, cls) {
        hint.innerHTML = html;
        hint.className = 'form-text mt-1 ' + cls;
    }

    function hideHint() {
        hint.classList.add('d-none');
    }

    function unlockFields() {
        typeInput.readOnly = false;
        mtowInput.readOnly = false;
        typeInput.classList.remove('bg-light');
        mtowInput.classList.remove('bg-light');
    }

    function lockFieldsWith(aircraftType, mtow) {
        if (aircraftType) typeInput.value = aircraftType;
        mtowInput.value = mtow;
        typeInput.readOnly = true;
        mtowInput.readOnly = true;
        typeInput.classList.add('bg-light');
        mtowInput.classList.add('bg-light');
        loadChecklistDebounced();
    }

    function checkRegistry() {
        const reg = regInput.value.trim();
        unlockFields();
        if (reg.length < 3) { hideHint(); return; }

        fetch('/aircraft-status/lookup?registration=' + encodeURIComponent(reg), {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => r.json())
        .then(data => {
            if (data.found && data.resolved) {
                lockFieldsWith(data.aircraft_type, data.mtow);
                showHint('<i class="bi bi-check-circle-fill"></i> Борт уже известен — тип ВС и MTOW подставлены из справочника (' + data.mtow + ' т) и заблокированы от правки.', 'text-success');
            } else if (data.found && !data.resolved) {
                showHint('<i class="bi bi-exclamation-triangle-fill"></i> Борт есть в справочнике, но его MTOW спорный (расхождение в истории, ждёт решения ЦОК) — введите MTOW как обычно.', 'text-warning');
            } else {
                showHint('<i class="bi bi-info-circle"></i> Новый борт — введённый сейчас MTOW станет для него эталоном на будущее.', 'text-muted');
            }
            hint.classList.remove('d-none');
        })
        .catch(() => hideHint());
    }

    function checkRegistryDebounced() {
        clearTimeout(registryTimer);
        registryTimer = setTimeout(checkRegistry, 400);
    }

    regInput.addEventListener('input', checkRegistryDebounced);
    if (regInput.value.trim().length >= 3) checkRegistry();
})();
</script>
