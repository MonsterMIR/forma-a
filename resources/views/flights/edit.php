<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">


<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-pencil-square"></i> <?= __('FLIGHTS_EDIT') ?>: <?= e($flight['flight_number']) ?></h2>
    <a href="/flights/<?= (int)$flight['id'] ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?>
    </a>
</div>

<?php if (($flight['status'] ?? 'active') === 'cancelled'): ?>
<!-- ДОБАВЛЕНО (27.10.2026, раунд 129). ИЗМЕНЕНО (тот же раунд, второе
     уточнение пользователя): отменённый рейс теперь не "финал" — дата/время
     вылета очищены ("отменён на неопределённое время"), связь со связанным
     рейсом (если была) сохранена, и здесь же кнопка "Возобновить рейс",
     которая запрашивает актуальные дату/время и возвращает рейс в работу. -->
<div class="alert alert-secondary d-flex justify-content-between align-items-center gap-2 mb-4 flex-wrap">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-slash-circle fs-5"></i>
        <div>
            <strong>Рейс отменён</strong><?= !empty($flight['cancelled_at']) ? (' ' . date('d.m.Y H:i', strtotime($flight['cancelled_at']))) : '' ?>
            — дата/время вылета очищены, точные пока не определены.
            <?php if (!empty($flight['cancellation_reason'])): ?>
                Причина: «<?= e($flight['cancellation_reason']) ?>».
            <?php endif; ?>
            <?php if (!empty($flight['linked_flight_id'])): ?>
                Связь со связанным рейсом #<?= (int)$flight['linked_flight_id'] ?> сохранена.
            <?php endif; ?>
        </div>
    </div>
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#resumeFlightModal">
        <i class="bi bi-play-circle"></i> Возобновить рейс
    </button>
</div>

<div class="modal fade" id="resumeFlightModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/flights/<?= (int)$flight['id'] ?>/resume" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-play-circle text-success"></i> Возобновить рейс <?= e($flight['flight_number']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size:.85rem">
                    Укажите актуальные дату и время рейса — рейс вернётся в работу (снова появится
                    у супервайзера, снова будет держать стоянку/участвовать в проверках на обычных
                    основаниях).
                </p>
                <div class="row g-2">
                    <div class="col-7">
                        <label class="form-label fw-semibold">Дата <span class="text-danger">*</span></label>
                        <input type="date" name="resume_date" class="form-control" required
                               value="<?= e($flight['flight_date'] ?? '') ?>">
                    </div>
                    <div class="col-5">
                        <label class="form-label fw-semibold">Время <span class="text-danger">*</span></label>
                        <input type="time" name="resume_time" class="form-control" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Закрыть</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-play-circle"></i> Возобновить рейс</button>
            </div>
        </form>
    </div>
</div>
<?php elseif (empty($existingAct)): ?>
<!-- ДОБАВЛЕНО (27.10.2026, раунд 129, по прямому указанию пользователя):
     "рейс создан по разрешению и по неким причинам рейс отменили совсем.
     в таком случае нам надо механизм для отмены рейса в системе" — см.
     FlightService::cancelFlight(). Доступно только пока по рейсу ещё нет
     ни одного акта (даже черновика) — иначе кнопка скрыта совсем, чтобы не
     провоцировать отмену уже как-то зафиксированного обслуживания. -->
<div class="card border-0 shadow-sm mb-4" style="border-left: 4px solid #dc3545 !important;">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="fw-bold text-danger"><i class="bi bi-exclamation-triangle"></i> Рейс не состоялся?</div>
            <div class="text-muted" style="font-size:.85rem">
                Если разрешение аннулировано или рейс по другой причине полностью отменён —
                пометьте рейс отменённым вместо того, чтобы оставлять его висеть как актуальный.
            </div>
        </div>
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelFlightModal">
            <i class="bi bi-slash-circle"></i> Отменить рейс
        </button>
    </div>
</div>

<div class="modal fade" id="cancelFlightModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/flights/<?= (int)$flight['id'] ?>/cancel" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-slash-circle text-danger"></i> Отменить рейс <?= e($flight['flight_number']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size:.85rem">
                    Рейс будет помечен как отменённый, дата/время вылета очистятся ("отменён на
                    неопределённое время"), рейс уберётся из рабочего списка супервайзера. Связь со
                    связанным рейсом (прилёт/вылет той же ротации), если она была, НЕ снимается —
                    если это вылет при ещё активном прилёте, стоянка продолжит считаться занятой.
                    Когда появится точная дата/время — используйте кнопку «Возобновить рейс».
                    Данные рейса сохранятся для истории — удалён он не будет.
                </p>
                <label class="form-label fw-semibold">Причина отмены <span class="text-danger">*</span></label>
                <textarea name="cancellation_reason" class="form-control" rows="3" required
                    placeholder="Например: разрешение аннулировано ЦАУ, борт не прилетел"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Закрыть</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-slash-circle"></i> Отменить рейс</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
<div class="col-lg-7">
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/flights/<?= (int)$flight['id'] ?>/edit" id="flightEditForm">
            <?= csrf_field() ?>
            <div class="row g-3">

                <!-- Авиакомпания с поиском -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_AIRLINE') ?> <span class="text-danger">*</span></label>
                    <select name="airline_id" id="editAirlineSelect" required>
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <?php foreach ($airlines as $al): ?>
                        <option value="<?= (int)$al['id'] ?>" <?= ($flight['airline_id'] == $al['id']) ? 'selected' : '' ?>>
                            <?= e($al['iata_code'] ? $al['iata_code'] . ' — ' . $al['name'] : $al['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_NUMBER') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="flight_number" class="form-control" required maxlength="20"
                           style="text-transform:uppercase" value="<?= e($flight['flight_number']) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_DATE') ?> <span class="text-danger">*</span></label>
                    <input type="date" name="flight_date" class="form-control" required value="<?= e($flight['flight_date']) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-semibold">STD/STA <i class="bi bi-clock text-muted"></i></label>
                    <input type="time" name="scheduled_time" class="form-control"
                           value="<?= e($flight['scheduled_time'] ?? '') ?>">
                    <div class="form-text">Для контроля сроков</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_ROUTE_TYPE') ?> <span class="text-danger">*</span></label>
                    <select name="route_type" id="editRouteTypeSelect" class="form-select" required>
                        <option value="domestic"      <?= $flight['route_type'] === 'domestic'      ? 'selected' : '' ?>><?= __('FLIGHTS_DOMESTIC') ?></option>
                        <option value="international" <?= $flight['route_type'] === 'international' ? 'selected' : '' ?>><?= __('FLIGHTS_INTERNATIONAL') ?></option>
                    </select>
                </div>

                <!-- Операция — триггер автовыбора TVT -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_OPERATION') ?> <span class="text-danger">*</span></label>
                    <select name="operation_type" id="editOperationSelect" class="form-select" required>
                        <option value="arrival"   <?= $flight['operation_type'] === 'arrival'   ? 'selected' : '' ?>><?= __('FLIGHTS_ARRIVAL') ?></option>
                        <option value="departure" <?= $flight['operation_type'] === 'departure' ? 'selected' : '' ?>><?= __('FLIGHTS_DEPARTURE') ?></option>
                    </select>
                </div>

                <div class="col-md-6"></div>

                <!-- Откуда — с поиском -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_ORIGIN') ?></label>
                    <select name="origin_airport_id" id="editOriginSelect">
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <?php foreach ($airports as $ap): ?>
                        <option value="<?= (int)$ap['id'] ?>"
                            <?= ($flight['origin_airport_id'] == $ap['id']) ? 'selected' : '' ?>>
                            <?= e($ap['iata_code'] ? $ap['iata_code'] . ' — ' . $ap['name'] : $ap['name']) ?>
                            <?= $ap['city'] ? '(' . e($ap['city']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="editOriginHint" class="form-text text-success d-none">
                        <i class="bi bi-geo-alt-fill"></i> <?= __('FLIGHTS_HOME_AIRPORT_AUTO') ?>
                    </div>
                </div>

                <!-- Куда — с поиском -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_DESTINATION') ?></label>
                    <select name="destination_airport_id" id="editDestSelect">
                        <option value=""><?= __('COMMON_SELECT') ?></option>
                        <?php foreach ($airports as $ap): ?>
                        <option value="<?= (int)$ap['id'] ?>"
                            <?= ($flight['destination_airport_id'] == $ap['id']) ? 'selected' : '' ?>>
                            <?= e($ap['iata_code'] ? $ap['iata_code'] . ' — ' . $ap['name'] : $ap['name']) ?>
                            <?= $ap['city'] ? '(' . e($ap['city']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="editDestHint" class="form-text text-success d-none">
                        <i class="bi bi-geo-alt-fill"></i> <?= __('FLIGHTS_HOME_AIRPORT_AUTO') ?>
                    </div>
                </div>

                <!-- ИЗМЕНЕНО (12.10.2026, раунд 69): поле сделано обязательным — см.
                     комментарий в flights/create.php и FlightController::update().
                     ИЗМЕНЕНО (22.10.2026, раунд 117, по прямому указанию пользователя):
                     поле перенесено ВЫШЕ типа ВС/MTOW и получило JS-поиск по справочнику
                     бортов (App\Controllers\AircraftController::lookup()) — см. аналогичный
                     блок в flights/create.php и FlightService::updateFlight(). -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">
                        <?= __('FLIGHTS_AIRCRAFT_REG') ?>
                        <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="aircraft_registration" id="aircraftRegInput" class="form-control" maxlength="20" required
                           style="text-transform:uppercase" value="<?= e($flight['aircraft_registration'] ?? '') ?>"
                           title="Обязательно — по нему система находит и связывает пару рейсов прилёта/вылета того же борта">
                    <div class="form-text">Нужен, чтобы система связала пару прилёт⇄вылет этого борта</div>
                    <div id="aircraftRegistryHint" class="form-text d-none mt-1"></div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold"><?= __('FLIGHTS_AIRCRAFT_TYPE') ?></label>
                    <input type="text" name="aircraft_type" id="aircraftTypeInput" class="form-control" maxlength="50"
                           value="<?= e($flight['aircraft_type'] ?? '') ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-semibold">
                        MTOW <span class="text-muted fw-normal small">(тонн)</span>
                        <span class="text-danger">*</span>
                    </label>
                    <input type="number" name="aircraft_mtow" id="aircraftMtowInput" class="form-control" required
                           min="1" max="650" step="0.1"
                           value="<?= e($flight['aircraft_mtow'] ?? '') ?>"
                           placeholder="51.0">
                    <div class="form-text">Для расчёта TLF и зависимых сборов</div>
                </div>

                <!-- ДОБАВЛЕНО (аудит 09.07.2026, п.5): поля есть в БД и используются
                     в печатной Форме А (acts/print.php), но не были выведены в форму —
                     печатались пустыми.
                     ИЗМЕНЕНО (23.09.2026, раунд 52): у рейса всегда ровно один
                     operation_type (прилёт либо вылет — второе "плечо" того же
                     борта, если оно есть, это ОТДЕЛЬНАЯ строка flights, см.
                     flights.linked_flight_id / FlightService::autoLinkFlight()).
                     Поэтому из двух пар arr_*/dep_* реально относится к этому
                     рейсу только одна — вторая всегда была декоративной и только
                     путала (пользователь сообщил про дубли дат/времени). Оба поля
                     остаются в форме под своими настоящими именами (значения не
                     теряются при сохранении), но видна только пара, отвечающая
                     текущей Операции — JS переключает видимость по её select'у. -->
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-12">
                    <div class="text-muted small fw-semibold text-uppercase mb-1">
                        Фактические данные обслуживания
                        <span class="fw-normal normal-case">(заполняются по факту / из документов служб)</span>
                    </div>
                </div>

                <?php $isArrival = ($flight['operation_type'] === 'arrival'); ?>
                <div class="col-md-3 dyn-field dyn-field-arrival<?= $isArrival ? '' : ' d-none' ?>">
                    <label class="form-label fw-semibold">Дата прилёта</label>
                    <input type="date" name="arr_date" class="form-control"
                           value="<?= e($flight['arr_date'] ?? '') ?>">
                </div>
                <div class="col-md-3 dyn-field dyn-field-arrival<?= $isArrival ? '' : ' d-none' ?>">
                    <label class="form-label fw-semibold">Время прилёта (ATA)</label>
                    <input type="time" name="arr_time" class="form-control"
                           value="<?= e($flight['arr_time'] ?? '') ?>">
                </div>
                <div class="col-md-3 dyn-field dyn-field-departure<?= $isArrival ? ' d-none' : '' ?>">
                    <label class="form-label fw-semibold">Дата вылета</label>
                    <input type="date" name="dep_date" class="form-control"
                           value="<?= e($flight['dep_date'] ?? '') ?>">
                </div>
                <div class="col-md-3 dyn-field dyn-field-departure<?= $isArrival ? ' d-none' : '' ?>">
                    <label class="form-label fw-semibold">Время вылета (ATD)</label>
                    <input type="time" name="dep_time" class="form-control"
                           value="<?= e($flight['dep_time'] ?? '') ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">PAX на прилёт</label>
                    <input type="number" name="pax_arrival" class="form-control" min="0"
                           value="<?= e($flight['pax_arrival'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Из них INFANT (прилёт)</label>
                    <input type="number" name="infant_arrival" class="form-control" min="0"
                           value="<?= e($flight['infant_arrival'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">PAX на вылет</label>
                    <input type="number" name="pax_departure" class="form-control" min="0"
                           value="<?= e($flight['pax_departure'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Из них INFANT (вылет)</label>
                    <input type="number" name="infant_departure" class="form-control" min="0"
                           value="<?= e($flight['infant_departure'] ?? '') ?>">
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
                           value="<?= e($flight['dhc_arrival'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        DHC на вылет
                        <i class="bi bi-info-circle text-muted" title="Deadhead crew — запасной экипаж/экипаж в качестве пассажиров"></i>
                    </label>
                    <input type="number" name="dhc_departure" class="form-control" min="0"
                           value="<?= e($flight['dhc_departure'] ?? '') ?>">
                </div>

                <div class="col-md-3 dyn-field dyn-field-arrival<?= $isArrival ? '' : ' d-none' ?>">
                    <label class="form-label fw-semibold">
                        Время установки колодок
                        <i class="bi bi-info-circle text-muted" title="Печатается в Форме А отдельной строкой"></i>
                    </label>
                    <input type="text" name="chocks_on" class="form-control" placeholder="чч:мм"
                           maxlength="10" value="<?= e($flight['chocks_on'] ?? '') ?>">
                </div>
                <div class="col-md-3 dyn-field dyn-field-departure<?= $isArrival ? ' d-none' : '' ?>">
                    <label class="form-label fw-semibold">
                        Время уборки колодок
                        <i class="bi bi-info-circle text-muted" title="Печатается в Форме А отдельной строкой"></i>
                    </label>
                    <input type="text" name="chocks_off" class="form-control" placeholder="чч:мм"
                           maxlength="10" value="<?= e($flight['chocks_off'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        Номер маршалинга / MC№ <span class="text-danger">*</span>
                    </label>
                    <?php
                        // ИЗМЕНЕНО (18.08.2026, раунд 16): выбор из справочника стоянок
                        // вместо ручного набора — см. App\Models\ParkingStand. Текущее
                        // значение всегда есть в списке опций, даже если его скрыли/
                        // удалили из справочника или оно осталось от старого свободного
                        // ввода — иначе форма молча потеряла бы его при сохранении.
                        // ИЗМЕНЕНО (23.09.2026, раунд 53, по прямому указанию
                        // пользователя): поле обязательно (required — и на
                        // сервере, см. FlightController::update()). У старого
                        // рейса без стоянки (создан до раунда 53) пустая опция
                        // остаётся в списке, чтобы форма не заблокировала показ
                        // текущего состояния, но сохранить с ней уже нельзя —
                        // придётся выбрать реальную стоянку.
                        $currentStand   = $flight['mc_number'] ?? '';
                        $standCodes     = array_map(fn($s) => $s['code'], $parkingStands ?? []);
                        $currentIsKnown = $currentStand === '' || in_array($currentStand, $standCodes, true);
                    ?>
                    <select name="mc_number" class="form-select" required>
                        <option value="">— выберите стоянку —</option>
                        <?php if (!$currentIsKnown): ?>
                        <option value="<?= e($currentStand) ?>" selected>
                            <?= e($currentStand) ?> (текущее значение)
                        </option>
                        <?php endif; ?>
                        <?php foreach (($parkingStands ?? []) as $stand): ?>
                        <option value="<?= e($stand['code']) ?>" <?= $currentStand === $stand['code'] ? 'selected' : '' ?>>
                            <?= e($stand['code']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($parkingStands) && $currentStand === ''): ?>
                    <div class="form-text text-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        Нет свободных стоянок (все заняты, либо справочник пуст — Справочники → Стоянки).
                        Сохранить рейс без стоянки нельзя — освободите/добавьте стоянку и повторите.
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold"><?= __('COMMON_NOTES') ?></label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($flight['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="flightEditSubmitBtn">
                    <i class="bi bi-check-lg"></i> <?= __('COMMON_SAVE') ?>
                </button>
                <a href="/flights/<?= (int)$flight['id'] ?>" class="btn btn-outline-secondary"><?= __('COMMON_CANCEL') ?></a>
            </div>
        </form>
        <script>
        // ДОБАВЛЕНО (16.10.2026, раунд 92) — см. подробный комментарий в
        // resources/views/flights/create.php: та же защита от двойного
        // клика/повторной отправки формы, здесь — для формы редактирования.
        (function () {
            var form = document.getElementById('flightEditForm');
            var btn  = document.getElementById('flightEditSubmitBtn');
            if (!form || !btn) return;
            form.addEventListener('submit', function () {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> <?= __('COMMON_SAVE') ?>';
            });
        })();
        </script>
    </div>
</div>
</div>
<!-- /col-lg-7 -->

<!-- ДОБАВЛЕНО (03.08.2026, round 9): тот же чек-лист, что и на форме создания.
     Показывает уже отмеченный план (currentOrders) + позволяет менять список,
     если MTOW/маршрут поменяли. Позиции, которые супервайзер уже начал/
     завершил — показаны, но недоступны для снятия галочки. -->
<div class="col-lg-5">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-clipboard2-check me-2"></i>Предзаказ услуг и транспорта
    </div>
    <div class="card-body" id="checklistBody">
        <div class="text-muted text-center py-4" id="checklistPlaceholder">Загрузка…</div>
        <div id="checklistContent" class="d-none"></div>
    </div>
</div>
</div>
<!-- /col-lg-5 -->
</div>
<!-- /row -->

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
const HOME_AIRPORT_ID = <?= (int)($homeAirportId ?? 0) ?>;

const tsConfig = { placeholder: 'Поиск...', allowEmptyOption: true };
new TomSelect('#editAirlineSelect', tsConfig);
const tsOrigin = new TomSelect('#editOriginSelect', tsConfig);
const tsDest   = new TomSelect('#editDestSelect',   tsConfig);

function applyHomeAirport(operation) {
    if (!HOME_AIRPORT_ID) return;

    if (operation === 'arrival') {
        tsDest.setValue(HOME_AIRPORT_ID);
        document.getElementById('editDestHint').classList.remove('d-none');
        document.getElementById('editOriginHint').classList.add('d-none');
    } else if (operation === 'departure') {
        tsOrigin.setValue(HOME_AIRPORT_ID);
        document.getElementById('editOriginHint').classList.remove('d-none');
        document.getElementById('editDestHint').classList.add('d-none');
    }
}

// ДОБАВЛЕНО (23.09.2026, раунд 52): показываем только ту пару
// дата+время прилёта/вылета и то поле колодок, которое относится к
// текущей Операции — вместо всех 4 полей arr_*/dep_* и обоих chocks_*
// сразу. Поля остаются в форме под настоящими именами (ничего не
// переименовывается и не теряется при сохранении), меняется только
// видимость через класс d-none.
function applyOperationFieldVisibility(operation) {
    const showArrival = operation === 'arrival';
    document.querySelectorAll('.dyn-field-arrival').forEach(el => el.classList.toggle('d-none', !showArrival));
    document.querySelectorAll('.dyn-field-departure').forEach(el => el.classList.toggle('d-none', showArrival));
}

document.getElementById('editOperationSelect').addEventListener('change', function() {
    applyHomeAirport(this.value);
    applyOperationFieldVisibility(this.value);
});

tsOrigin.on('change', function(val) {
    if (parseInt(val) !== HOME_AIRPORT_ID)
        document.getElementById('editOriginHint').classList.add('d-none');
});
tsDest.on('change', function(val) {
    if (parseInt(val) !== HOME_AIRPORT_ID)
        document.getElementById('editDestHint').classList.add('d-none');
});

// ── Чек-лист услуг/транспорта — та же логика, что на форме создания ────────
const ROLE_LABELS = {sop:'СОП', spo:'СПО', sst:'ССТ', aero:'Аэродромная', spasop:'СПАСОП'};

// Уже существующий план по рейсу — статус по каждой услуге. planned можно
// снять, in_progress/done — только показываем, менять нельзя.
// ДОБАВЛЕНО (07.09.2026): timing_note — причина, которую супервайзер указал
// при отмене (см. ServiceOrderController::removePlanned()). Раньше
// отменённая услуга в этом чек-листе выглядела как обычная незаказанная —
// пустой чекбокс без пометки, поэтому ЦОК не мог понять, что галочка тут
// вообще что-то "возвращает" назад, а не заказывает с нуля. Показываем
// причину отмены как бейдж — заодно даёт ЦОК контекст, почему супервайзер
// убрал услугу, прежде чем возвращать её обратно.
const EXISTING_ORDERS = <?= json_encode(array_map(function($o) {
    return ['service_id' => (int)$o['service_id'], 'status' => $o['status'], 'timing_note' => $o['timing_note'] ?? ''];
}, $currentOrders)) ?>;
const existingStatusById = {};
const existingNoteById = {};
EXISTING_ORDERS.forEach(o => { existingStatusById[o.service_id] = o.status; existingNoteById[o.service_id] = o.timing_note; });

let checkedServiceIds = new Set(EXISTING_ORDERS.filter(o => o.status !== 'cancelled').map(o => o.service_id));

// ИЗМЕНЕНО (17.08.2026): раньше в шапке группы было два бейджа —
// "предзаказано" (checkedInGroup, синий), который на самом деле мешал в
// одну кучу ВСЕ отмеченные позиции независимо от статуса (включая уже
// оказанные/в работе), и отдельно "оказано" (зелёный). По просьбе
// пользователя разнесены на 5 независимых счётчиков, которые в сумме
// дают total и никогда не пересекаются: заказано (только planned, синий),
// в процессе (жёлтый), завершено (зелёный), отменено (красный) — плюс
// общее количество отдельным серым бейджем.
function computeGroupCounts(serviceIds, isCheckedFn) {
    let ordered = 0, inProgress = 0, done = 0, cancelled = 0;
    serviceIds.forEach(id => {
        const status = existingStatusById[id];
        if (status === 'done') { done++; return; }
        if (status === 'in_progress') { inProgress++; return; }
        if (isCheckedFn(id)) { ordered++; }
        else if (status === 'cancelled') { cancelled++; }
    });
    return { total: serviceIds.length, ordered, inProgress, done, cancelled };
}

function statusBadgeHtml(status, note, checkedNow) {
    if (status === 'done') return ' <span class="badge bg-success">оказано</span>';
    if (status === 'in_progress') return ' <span class="badge bg-warning text-dark">в работе</span>';
    if (status === 'cancelled') {
        // ДОБАВЛЕНО (07.09.2026): галочка снова отмечена — значит эту
        // позицию восстанавливаем при сохранении, показываем это явно
        // вместо статичного "отменено", чтобы не выглядело, будто ничего
        // не изменится.
        if (checkedNow) {
            return ' <span class="badge bg-info text-dark">будет восстановлено</span>';
        }
        const title = note ? note.replace(/"/g, '&quot;') : 'Причина не указана';
        return ' <span class="badge bg-danger" title="' + title + '">отменено супервайзером</span>';
    }
    return '';
}

function countBadgesHtml(groupId, counts) {
    return '<span class="d-flex align-items-center gap-1">'
         +  '<span class="badge bg-secondary" data-total-badge="' + groupId + '" title="Всего услуг">'
         +  counts.total + '</span>'
         +  '<span class="badge ' + (counts.ordered ? 'bg-primary' : 'bg-light text-muted') + '" data-ordered-badge="' + groupId + '" title="Заказано">'
         +  counts.ordered + '</span>'
         +  '<span class="badge ' + (counts.inProgress ? 'bg-warning text-dark' : 'bg-light text-muted') + '" data-inprogress-badge="' + groupId + '" title="В процессе">'
         +  counts.inProgress + '</span>'
         +  '<span class="badge ' + (counts.done ? 'bg-success' : 'bg-light text-muted') + '" data-done-badge="' + groupId + '" title="Завершено">'
         +  counts.done + '</span>'
         +  '<span class="badge ' + (counts.cancelled ? 'bg-danger' : 'bg-light text-muted') + '" data-cancelled-badge="' + groupId + '" title="Отменено">'
         +  counts.cancelled + '</span>'
         +  '</span>';
}

function updateGroupBadges(groupId) {
    // ВАЖНО: не полагаемся на локальную переменную `content` из renderChecklist()
    // (она объявлена там через const и невидима в этой функции) — берём элемент
    // заново, иначе при переключении чекбокса будет ReferenceError.
    const content = document.getElementById('checklistContent');
    const groupCbs = Array.from(content.querySelectorAll('[data-group="' + groupId + '"]'));
    const serviceIds = groupCbs.map(cb => parseInt(cb.value));
    const checkedNow = new Set(groupCbs.filter(cb => cb.checked).map(cb => parseInt(cb.value)));
    const counts = computeGroupCounts(serviceIds, id => checkedNow.has(id));

    const setBadge = (attr, cls, value) => {
        const el = content.querySelector('[' + attr + '="' + groupId + '"]');
        if (el) { el.textContent = value; el.className = 'badge ' + cls; }
    };
    setBadge('data-total-badge', 'bg-secondary', counts.total);
    setBadge('data-ordered-badge', counts.ordered ? 'bg-primary' : 'bg-light text-muted', counts.ordered);
    setBadge('data-inprogress-badge', counts.inProgress ? 'bg-warning text-dark' : 'bg-light text-muted', counts.inProgress);
    setBadge('data-done-badge', counts.done ? 'bg-success' : 'bg-light text-muted', counts.done);
    setBadge('data-cancelled-badge', counts.cancelled ? 'bg-danger' : 'bg-light text-muted', counts.cancelled);
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
        const groupId = 'grp_e_' + role;
        const groupServiceIds = data.services[role].map(svc => svc.id);
        const counts = computeGroupCounts(groupServiceIds, id => checkedServiceIds.has(id));
        html += '<div class="col-12"><div class="border rounded">';
        html += '<div class="d-flex justify-content-between align-items-center p-2 checklist-group-header" '
             +  'style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#' + groupId + '">'
             +  '<span class="fw-semibold small text-uppercase text-muted">'
             +  '<i class="bi bi-chevron-right me-1 checklist-chevron"></i>' + ROLE_LABELS[role] + '</span>'
             +  countBadgesHtml(groupId, counts)
             +  '</div>';
        html += '<div class="collapse px-2 pb-2" id="' + groupId + '">';
        data.services[role].forEach(svc => {
            const status = existingStatusById[svc.id];
            const locked = status === 'in_progress' || status === 'done';
            const checked = checkedServiceIds.has(svc.id) ? 'checked' : '';
            const disabled = locked ? 'disabled' : '';
            const formAttr = locked ? '' : 'form="flightEditForm"'; // disabled и так не отправится, но на всякий случай не привязываем
            // ДОБАВЛЕНО (17.08.2026): бейдж статуса всегда обёрнут в span с
            // id — раньше строился один раз строкой и не мог обновиться без
            // полной перерисовки всего чек-листа; теперь statusBadgeHtml()
            // и applyOrderStatusUpdate() пишут в этот же span при автообновлении.
            html += '<div class="form-check py-1 d-flex justify-content-between align-items-center">'
                 +  '<span>'
                 +  '<input class="form-check-input checklist-cb" type="checkbox" name="service_ids[]" '
                 +  'value="' + svc.id + '" id="esvc_' + svc.id + '" data-group="' + groupId + '" ' + formAttr + ' ' + checked + ' ' + disabled + '>'
                 +  '<label class="form-check-label" for="esvc_' + svc.id + '">' + svc.name + '</label>'
                 +  '</span>'
                 +  '<span id="status_slot_' + svc.id + '">' + statusBadgeHtml(status, existingNoteById[svc.id], checked === 'checked') + '</span>'
                 +  '</div>';
        });
        html += '</div></div></div>';
    }
    html += '</div>';

    content.innerHTML = html;
    content.classList.remove('d-none');
    placeholder.classList.add('d-none');

    content.querySelectorAll('.checklist-group-header').forEach(header => {
        const targetSel = header.getAttribute('data-bs-target');
        const target = content.querySelector(targetSel);
        const chevron = header.querySelector('.checklist-chevron');
        target.addEventListener('show.bs.collapse', () => chevron.classList.replace('bi-chevron-right', 'bi-chevron-down'));
        target.addEventListener('hide.bs.collapse', () => chevron.classList.replace('bi-chevron-down', 'bi-chevron-right'));
    });

    content.querySelectorAll('.checklist-cb:not(:disabled)').forEach(cb => {
        cb.addEventListener('change', function() {
            const id = parseInt(this.value);
            if (this.checked) checkedServiceIds.add(id); else checkedServiceIds.delete(id);
            const groupId = this.getAttribute('data-group');
            updateGroupBadges(groupId);
            // ДОБАВЛЕНО (07.09.2026): для ранее отменённой услуги — живо
            // переключаем бейдж "отменено" ↔ "будет восстановлено" по мере
            // того, как ЦОК ставит/снимает галочку, ещё до сохранения формы.
            if (existingStatusById[id] === 'cancelled') {
                const slot = document.getElementById('status_slot_' + id);
                if (slot) slot.innerHTML = statusBadgeHtml('cancelled', existingNoteById[id], this.checked);
            }
        });
    });
}

let checklistTimer = null;
function loadChecklist() {
    const rt = document.getElementById('editRouteTypeSelect').value;
    const op = document.getElementById('editOperationSelect').value;
    if (!rt || !op) return;

    const params = new URLSearchParams({
        route_type: rt,
        operation_type: op,
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

document.getElementById('editRouteTypeSelect').addEventListener('change', loadChecklist);
document.getElementById('editOperationSelect').addEventListener('change', loadChecklist);
document.querySelector('[name="aircraft_mtow"]').addEventListener('input', loadChecklistDebounced);
document.querySelector('[name="flight_date"]').addEventListener('change', loadChecklist);

// У существующего рейса маршрут/операция уже заполнены — грузим сразу
loadChecklist();

// ДОБАВЛЕНО (17.08.2026): автообновление статусов "оказано"/"в работе"/
// "отменено" по чек-листу — раньше ЦОК видел свежий статус только после
// F5. Раз в 15 секунд тихо опрашиваем сервер и точечно обновляем ТОЛЬКО
// изменившиеся строки (чекбокс + бейджи), не перестраивая весь чек-лист —
// иначе слетели бы несохранённые отметки, которые ЦОК уже поставил, но ещё
// не сохранил формой. Опрос ставится на паузу, пока вкладка не видна
// (незачем дёргать сервер, если страница свёрнута/в фоне).
const ORDER_STATUS_FLIGHT_ID = <?= (int)$flight['id'] ?>;
const ORDER_STATUS_POLL_MS = 15000;
let orderStatusPollTimer = null;

function applyOrderStatusUpdate(orders) {
    const touchedGroups = new Set();
    orders.forEach(o => {
        const id = o.service_id;
        const newStatus = o.status;
        const oldStatus = existingStatusById[id];
        if (newStatus === oldStatus) return; // без изменений — пропускаем

        existingStatusById[id] = newStatus;
        if (newStatus === 'cancelled') existingNoteById[id] = o.timing_note || '';
        const cb = document.getElementById('esvc_' + id);
        if (!cb) return; // этой услуги нет в текущем чек-листе (другой тип рейса/операции)

        const groupId = cb.getAttribute('data-group');
        touchedGroups.add(groupId);

        const locked = newStatus === 'in_progress' || newStatus === 'done';
        cb.disabled = locked;
        if (locked) {
            cb.checked = true;
            checkedServiceIds.add(id);
        } else if (newStatus === 'cancelled' && oldStatus !== 'cancelled') {
            // Тот же принцип, что и при первой загрузке чек-листа: отменённая
            // позиция автоматически снимается с отметки.
            cb.checked = false;
            checkedServiceIds.delete(id);
        }

        const slot = document.getElementById('status_slot_' + id);
        if (slot) slot.innerHTML = statusBadgeHtml(newStatus, existingNoteById[id], cb.checked);
    });
    touchedGroups.forEach(groupId => updateGroupBadges(groupId));
}

function pollOrderStatuses() {
    if (document.hidden || !ORDER_STATUS_FLIGHT_ID) return;
    fetch('/flights/' + ORDER_STATUS_FLIGHT_ID + '/order-statuses', {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.ok ? r.json() : null)
    .then(data => {
        if (data && data.success && Array.isArray(data.orders)) {
            applyOrderStatusUpdate(data.orders);
        }
    })
    .catch(() => {}); // сетевой сбой одного тика не критичен — попробуем на следующем
}

if (ORDER_STATUS_FLIGHT_ID) {
    orderStatusPollTimer = setInterval(pollOrderStatuses, ORDER_STATUS_POLL_MS);
}

// ── ДОБАВЛЕНО (22.10.2026, раунд 117, по прямому указанию пользователя) ──
// Справочник бортов: см. идентичный блок и докблок в flights/create.php.
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
        if (typeof loadChecklistDebounced === 'function') loadChecklistDebounced();
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
    // На редактировании борт уже известен (это его собственный рейс) — проверяем
    // сразу при загрузке, НО без подмены уже сохранённого значения на лету, если
    // расхождение (это покажет лишь подсказку, не заблокирует поля принудительно,
    // пока пользователь сам не тронет поле регистрации).
    if (regInput.value.trim().length >= 3) checkRegistry();
})();
</script>