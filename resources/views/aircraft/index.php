<?php
/**
 * ДОБАВЛЕНО (21.10.2026, раунд 108, по прямому указанию пользователя):
 * см. докблок App\Controllers\AircraftController — статус каждого борта
 * по его последнему по факту движению в `flights`.
 *
 * ИЗМЕНЕНО (22.10.2026, раунд 117, по прямому указанию пользователя):
 * добавлена колонка MTOW из справочника (таблица `aircraft`) + админка
 * подтверждения/правки для "спорных" бортов (расхождение в истории —
 * см. App\Models\Aircraft, App\Services\FlightService). Редактирование
 * видно только admin/cok ($canEdit, см. контроллер); остальным ролям —
 * как и раньше, read-only.
 */
$items = $items ?? [];
$canEdit = $canEdit ?? false;
// ИСПРАВЛЕНО (23.10.2026, раунд 119, по прямому указанию пользователя):
// "на земле" теперь берётся из Aircraft::getAllWithFleetStatus() —
// по подтверждению уборки колодок на вылете той же ротации (та же логика,
// что и у /parking-acts, ParkingStandService::isFreed()), а не просто по
// типу последней записи рейса (раньше рейс-вылет с ещё не наступившей
// датой уже считался "улетел", хотя борт физически ещё стоял у нас).
$onGroundCount = count(array_filter($items, fn($r) => !empty($r['on_ground'])));
$disputedCount = count(array_filter($items, fn($r) => $r['mtow'] === null));
// ДОБАВЛЕНО (06.11.2026, раунд 154, по прямому указанию пользователя):
// счётчик бортов, отмеченных как базирующиеся в нашем аэропорту.
$basedCount = count(array_filter($items, fn($r) => !empty($r['is_based'])));
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-airplane-engines me-2"></i>Справочник бортов</h1>
        <div class="text-muted small">Текущий статус + подтверждённый MTOW каждого борта (MTOW хранится один раз на борт и подставляется автоматически при создании новых рейсов — раунд 117)</div>
    </div>
</div>
<?php if ($disputedCount > 0): ?>
<div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    <strong><?= $disputedCount ?></strong> борт(ов) со спорным MTOW — в истории их рейсов встречались РАЗНЫЕ значения
    (ошибка ввода диспетчера ЦОК на одном из рейсов). До подтверждения правильного значения начальником ЦОК MTOW
    для этих бортов НЕ подставляется автоматически — на каждом новом рейсе требуется обычный ручной ввод.
</div>
<?php endif; ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Бортов в справочнике <span class="badge bg-secondary"><?= count($items) ?></span></span>
        <span>
            <?php if ($disputedCount > 0): ?>
            <span class="badge bg-warning text-dark me-1">Спорных: <?= $disputedCount ?></span>
            <?php endif; ?>
            <?php if ($basedCount > 0): ?>
            <span class="badge bg-primary me-1"><i class="bi bi-house-door-fill me-1"></i>Базируется у нас: <?= $basedCount ?></span>
            <?php endif; ?>
            <span class="badge bg-success">На земле сейчас: <?= $onGroundCount ?></span>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Борт</th>
                    <th>Тип ВС</th>
                    <th style="width:170px">MTOW (справочник)</th>
                    <!-- ДОБАВЛЕНО (06.11.2026, раунд 154, по прямому указанию
                         пользователя): признак «борт базируется в нашем
                         аэропорту» — у таких бортов цепочка визита обратная
                         (вылет -> прилёт, а не прилёт -> вылет), см.
                         App\Models\Aircraft::is_based. -->
                    <th style="width:160px">Базирование</th>
                    <th>Статус</th>
                    <th>Последнее движение</th>
                    <th>Авиакомпания</th>
                    <!-- ДОБАВЛЕНО (24.10.2026, раунд 121, по прямому указанию
                         пользователя): кнопка "История" между "Авиакомпания" и
                         "Стоянка" — попап со всеми рейсами этого борта в нашем
                         аэропорту (прилёты+вылеты), см. #aircraftHistoryModal и
                         JS внизу файла, AircraftController::history(). -->
                    <th>История</th>
                    <th>Стоянка</th>
                    <th>Связан с парой</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">В справочнике пока нет бортов</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $r): $onGround = !empty($r['on_ground']); $isLastMovementArrival = $r['operation_type'] === 'arrival'; $disputed = $r['mtow'] === null; ?>
            <tr class="<?= $disputed ? 'table-warning' : '' ?>">
                <td><code><?= e($r['registration']) ?></code></td>
                <td><?= e($r['reg_aircraft_type'] ?? $r['flight_aircraft_type'] ?? '—') ?></td>
                <td>
                    <?php if (!$disputed): ?>
                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                            <i class="bi bi-check-circle me-1"></i><?= e((string)$r['mtow']) ?> т
                        </span>
                        <?php if ($canEdit): ?>
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline"
                                data-bs-toggle="collapse" data-bs-target="#editMtow<?= (int)$r['aircraft_id'] ?>"
                                title="Исправить"><i class="bi bi-pencil-square"></i></button>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-exclamation-triangle me-1"></i>спорный, ждёт ЦОК
                        </span>
                        <?php if (!empty($r['resolution_notes'])): ?>
                        <div class="text-muted" style="font-size:0.7rem" title="<?= e($r['resolution_notes']) ?>">
                            <?= e(mb_strimwidth($r['resolution_notes'], 0, 90, '…')) ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($canEdit): ?>
                    <div class="collapse<?= $disputed ? ' show' : '' ?> mt-1" id="editMtow<?= (int)$r['aircraft_id'] ?>">
                        <form method="POST" action="/aircraft-status/save" class="d-flex gap-1 align-items-center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$r['aircraft_id'] ?>">
                            <input type="hidden" name="registration" value="<?= e($r['registration']) ?>">
                            <input type="number" name="mtow" class="form-control form-control-sm" style="width:85px"
                                   step="0.1" min="0.1" required
                                   value="<?= e($disputed ? '' : (string)$r['mtow']) ?>"
                                   placeholder="MTOW">
                            <input type="text" name="aircraft_type" class="form-control form-control-sm" style="width:80px"
                                   value="<?= e($r['reg_aircraft_type'] ?? '') ?>" placeholder="тип ВС">
                            <button type="submit" class="btn btn-sm btn-success py-0 px-2" title="Подтвердить">
                                <i class="bi bi-check-lg"></i>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $isBased = !empty($r['is_based']); ?>
                    <?php if ($isBased): ?>
                        <span class="badge bg-primary"><i class="bi bi-house-door-fill me-1"></i>Базируется у нас</span>
                        <?php if (!empty($r['based_note'])): ?>
                        <div class="text-muted" style="font-size:0.7rem" title="<?= e($r['based_note']) ?>">
                            <?= e(mb_strimwidth($r['based_note'], 0, 60, '…')) ?>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-light text-muted border">гостевой</span>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline"
                            data-bs-toggle="collapse" data-bs-target="#editBased<?= (int)$r['aircraft_id'] ?>"
                            title="Изменить признак базирования"><i class="bi bi-pencil-square"></i></button>
                    <div class="collapse mt-1" id="editBased<?= (int)$r['aircraft_id'] ?>">
                        <form method="POST" action="/aircraft-status/set-based" class="d-flex flex-column gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$r['aircraft_id'] ?>">
                            <input type="hidden" name="registration" value="<?= e($r['registration']) ?>">
                            <input type="hidden" name="is_based" value="<?= $isBased ? '0' : '1' ?>">
                            <input type="text" name="based_note" class="form-control form-control-sm"
                                   value="<?= e($r['based_note'] ?? '') ?>" placeholder="примечание (необязательно)">
                            <button type="submit" class="btn btn-sm <?= $isBased ? 'btn-outline-secondary' : 'btn-primary' ?> py-0 px-2">
                                <?= $isBased ? 'Снять базирование' : 'Отметить как базирующийся' ?>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($r['flight_id'])): ?>
                        <span class="badge bg-light text-muted border">нет рейсов</span>
                    <?php else: ?>
                        <?= $onGround
                            ? '<span class="badge bg-success"><i class="bi bi-geo-alt-fill me-1"></i>На земле у нас</span>'
                            : '<span class="badge bg-secondary"><i class="bi bi-airplane me-1"></i>Улетел</span>' ?>
                        <?php // ДОБАВЛЕНО (23.10.2026, раунд 119): если последняя запись —
                              // вылет, но борт всё ещё числится "на земле" (колодки не
                              // подтверждены), поясняем почему — иначе выглядит как
                              // противоречие самому себе (вылет введён, а статус "на земле"). ?>
                        <?php if ($onGround && !$isLastMovementArrival): ?>
                        <div class="text-muted" style="font-size:0.7rem">вылет введён, колодки не подтверждены</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($r['flight_id'])): ?>
                    <a href="/flights/<?= (int)$r['flight_id'] ?>"><?= e($r['flight_number']) ?></a>
                    <span class="text-muted">(<?= $isLastMovementArrival ? 'прилёт' : 'вылет' ?>)</span><br>
                    <span class="text-muted"><?= e($r['flight_date']) ?><?php if (!empty($r['scheduled_time'])): ?> <?= e(substr((string)$r['scheduled_time'], 0, 5)) ?><?php endif; ?></span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= e($r['airline_name'] ?? '—') ?></td>
                <td>
                    <!-- ДОБАВЛЕНО (24.10.2026, раунд 121, по прямому указанию
                         пользователя): "добавь между Авиакомпания и Стоянка
                         кнопку, которая покажет в попап окне историю борта в
                         нашем аэропорту" — попап подгружается через fetch()
                         при клике, см. JS внизу файла. -->
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#aircraftHistoryModal"
                            data-registration="<?= e($r['registration']) ?>"
                            title="История борта в нашем аэропорту">
                        <i class="bi bi-clock-history"></i>
                    </button>
                </td>
                <td><?= e($r['mc_number'] ?? '—') ?></td>
                <td>
                    <?= !empty($r['linked_flight_id'])
                        ? '<span class="badge bg-info text-dark">да</span>'
                        : '<span class="badge bg-light text-muted border">нет</span>' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="alert alert-info small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    MTOW подтверждённого борта хранится один раз в справочнике и автоматически подставляется при создании/редактировании
    любого нового рейса этого борта (значение с формы рейса в этом случае игнорируется). Статус движения — это тип
    ЕГО ПОСЛЕДНЕГО по дате/времени рейса в системе: прилёт = борт физически у нас на перроне («на земле»), вылет =
    борт улетел. Если борт показан «улетел», но по факту ещё стоит у нас (или наоборот), значит соответствующий
    рейс ещё не внесён или внесён с ошибкой в дате/времени.
</div>

<!-- Aircraft History Modal (ДОБАВЛЕНО 24.10.2026, раунд 121, по прямому
     указанию пользователя) — один общий попап для всех строк справочника,
     содержимое подгружается через fetch() при клике на кнопку "История"
     (см. AircraftController::history()). Та же схема, что и попап текстовой
     расшифровки акта на /acts (раунд 71b/80). -->
<div class="modal fade" id="aircraftHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-clock-history me-2"></i>История борта
                    <code id="aircraftHistoryReg" class="ms-1"></code>
                    <span class="text-muted small" id="aircraftHistoryCount"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="aircraftHistoryLoading" class="text-center text-muted py-4 d-none">
                    <div class="spinner-border spinner-border-sm me-2"></div> Загрузка…
                </div>
                <div id="aircraftHistoryError" class="alert alert-danger d-none"></div>
                <div id="aircraftHistoryContent" class="small"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal"><?= __('COMMON_CLOSE') ?></button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var modalEl   = document.getElementById('aircraftHistoryModal');
    if (!modalEl) return;
    var loadingEl = document.getElementById('aircraftHistoryLoading');
    var errorEl   = document.getElementById('aircraftHistoryError');
    var contentEl = document.getElementById('aircraftHistoryContent');
    var regEl     = document.getElementById('aircraftHistoryReg');
    var countEl   = document.getElementById('aircraftHistoryCount');

    // ИСПРАВЛЕНО-ПО-ОБРАЗЦУ (см. textBreakdownModal на /acts, раунд 71c —
    // кнопка не открывала попап): модалку открывает сам Bootstrap по
    // data-bs-toggle/data-bs-target на кнопке — здесь только слушаем
    // событие show.bs.modal и забираем кнопку, на которую кликнули, из
    // event.relatedTarget.
    modalEl.addEventListener('show.bs.modal', function(event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var registration = btn.getAttribute('data-registration') || '';
        regEl.textContent   = registration;
        countEl.textContent = '';
        contentEl.innerHTML = '';
        errorEl.classList.add('d-none');
        loadingEl.classList.remove('d-none');

        fetch('/aircraft-status/history?registration=' + encodeURIComponent(registration), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            loadingEl.classList.add('d-none');
            if (data && data.success) {
                contentEl.innerHTML = data.html;
                countEl.textContent = '— рейсов: ' + data.count;
            } else {
                errorEl.textContent = (data && data.message) || 'Не удалось загрузить историю борта.';
                errorEl.classList.remove('d-none');
            }
        })
        .catch(function() {
            loadingEl.classList.add('d-none');
            errorEl.textContent = 'Ошибка сети при загрузке истории борта.';
            errorEl.classList.remove('d-none');
        });
    });
})();
</script>
