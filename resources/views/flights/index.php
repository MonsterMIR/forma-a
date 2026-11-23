<?php
/**
 * ДОБАВЛЕНО (23.11.2026, раунд 202, по прямому указанию пользователя —
 * жалоба на то, что рейсы в списке "не по порядку" по датам, затем
 * визуализация 4 вариантов и выбор пользователя "аккордеон + переключатель
 * видов"): страница теперь поддерживает два режима ($mode, см.
 * FlightController::index()):
 *   - 'list' — прежний плоский список без единого изменения в самой
 *     разметке строк/колонок (сортировка f.id DESC осталась как есть,
 *     см. раунд 36 — та сортировка это была прямая прошлая просьба
 *     пользователя, её никто не отменял);
 *   - 'days' — новый режим: рейсы сгруппированы по дате в Bootstrap-
 *     аккордеон (accordion, data-bs-parent — раскрыт всегда ровно один
 *     день, встроенное поведение Bootstrap 5, без единой строчки
 *     собственного JS), по умолчанию открыт день = сегодня (или первый
 *     день страницы, если сегодняшнего дня нет среди найденных).
 * Разметка одной строки таблицы и шапки таблицы вынесены в partials
 * _row.php/_head.php — используются в обоих режимах без дублирования.
 */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-airplane"></i> <?= __('FLIGHTS_TITLE') ?></h2>
    <?php if (has_role(['admin', 'cok'])): ?>
    <a href="/flights/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= __('FLIGHTS_CREATE') ?></a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <!-- ДОБАВЛЕНО (23.11.2026, раунд 202): без этого скрытого поля
                 применение/сброс фильтров внутри строки GET-формы молча
                 сбрасывало бы ?view=days обратно на 'list' (в форме нет
                 поля view) — пользователь оставался бы в режиме "По дням",
                 а после поиска неожиданно попадал бы обратно в "Список". -->
            <input type="hidden" name="view" value="<?= e($mode) ?>">
            <div class="col-md-3">
                <label class="form-label small fw-semibold"><?= __('COMMON_SEARCH') ?></label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="<?= __('FLIGHTS_SEARCH_PLACEHOLDER') ?>" value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_AIRLINE') ?> </label>
                <select name="airline_id" class="form-select form-select-sm">
                    <option value=""><?= __('FLIGHTS_ALL_AIRLINES') ?></option>
                    <?php foreach ($airlines as $al): ?>
                    <option value="<?= (int)$al['id'] ?>" <?= $filters['airline_id'] == $al['id'] ? 'selected' : '' ?>>
                        <?= e($al['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_ROUTE_TYPE') ?> </label>
                <select name="route_type" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="domestic" <?= $filters['route_type'] === 'domestic' ? 'selected' : '' ?>><?= __('FLIGHTS_DOMESTIC') ?></option>
                    <option value="international" <?= $filters['route_type'] === 'international' ? 'selected' : '' ?>><?= __('FLIGHTS_INTERNATIONAL') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_OPERATION') ?> </label>
                <select name="operation_type" class="form-select form-select-sm">
                    <option value=""><?= __('COMMON_ALL') ?></option>
                    <option value="arrival" <?= $filters['operation_type'] === 'arrival' ? 'selected' : '' ?>><?= __('FLIGHTS_ARRIVAL') ?></option>
                    <option value="departure" <?= $filters['operation_type'] === 'departure' ? 'selected' : '' ?>><?= __('FLIGHTS_DEPARTURE') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_DATE_FROM') ?></label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= __('FLIGHTS_DATE_TO') ?></label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold" title="Заполните только это поле, чтобы найти рейсы/акты/записи ровно за один день — само подставит ту же дату в поля «с»/«по» выше (round 164).">На дату</label>
                <input type="date" class="form-control form-control-sm" data-exact-date data-from="date_from" data-to="date_to">
            </div>
            <div class="col-auto d-flex gap-2 mt-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> <?= __('COMMON_SEARCH') ?></button>
                <a href="/flights<?= $mode === 'days' ? '?view=days' : '' ?>" class="btn btn-sm btn-outline-secondary"><?= __('COMMON_RESET') ?></a>
            </div>
        </form>
    </div>
</div>

<?php
// ДОБАВЛЕНО (23.11.2026, раунд 202): переключатель режима отображения —
// сохраняет все текущие фильтры из $_GET, меняет view.
// ИСПРАВЛЕНО (23.11.2026, по жалобе пользователя: "актуальный день в самом
// конце, открывается самый первый день"): здесь раньше стояло
// ['page' => 1] -- это ЯВНО подставляло page=1 в ссылку, и
// FlightController::index() не мог отличить такой клик от настоящего
// "перехода на страницу 1" через пагинацию, поэтому логика "открыть
// страницу с сегодняшним днём" (см. контроллер, $explicitPage === null)
// никогда не срабатывала при переключении на "По дням" -- всегда
// показывалась страница 1, то есть самые старые даты в базе. Теперь page
// просто убирается из ссылки (unset), а не подставляется принудительно --
// тогда в контроллере $this->request->get('page', null) действительно
// вернёт null, и страница с сегодняшним днём откроется как и задумано.
$viewQueryBase = $_GET;
unset($viewQueryBase['page']);
$viewLinkList = '?' . http_build_query(array_merge($viewQueryBase, ['view' => 'list']));
$viewLinkDays = '?' . http_build_query(array_merge($viewQueryBase, ['view' => 'days']));
?>
<div class="btn-group mb-3" role="group" aria-label="Режим отображения списка рейсов">
    <a href="<?= e($viewLinkList) ?>" class="btn btn-sm <?= $mode === 'list' ? 'btn-dark' : 'btn-outline-dark' ?>">
        <i class="bi bi-list-ul"></i> Список
    </a>
    <a href="<?= e($viewLinkDays) ?>" class="btn btn-sm <?= $mode === 'days' ? 'btn-dark' : 'btn-outline-dark' ?>">
        <i class="bi bi-calendar3"></i> По дням
    </a>
</div>

<?php if ($mode === 'days'): ?>

    <?php if (empty($groups)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center text-muted py-4"><?= __('FLIGHTS_NO_DATA') ?></div>
    </div>
    <?php else: ?>
    <?php
        // Те же метки "Сегодня"/"Завтра", что и на дашборде супервайзера —
        // см. resources/views/service_orders/supervisor_dashboard.php, раунд 155.
        // Здесь дополнительно есть "Вчера" (на дашборде супервайзера прошлых
        // дней не бывает — там только актуальные/будущие рейсы).
        $tomorrowDate  = date('Y-m-d', strtotime($today . ' +1 day'));
        $yesterdayDate = date('Y-m-d', strtotime($today . ' -1 day'));
    ?>
    <div class="accordion" id="flightsAccordion">
        <?php $gi = 0; foreach ($groups as $groupDate => $groupFlights): $gi++; ?>
        <?php
            if ($groupDate === $today) {
                $groupLabel = 'Сегодня, ' . format_date($groupDate);
            } elseif ($groupDate === $tomorrowDate) {
                $groupLabel = 'Завтра, ' . format_date($groupDate);
            } elseif ($groupDate === $yesterdayDate) {
                $groupLabel = 'Вчера, ' . format_date($groupDate);
            } else {
                $groupLabel = format_date($groupDate);
            }
            $isOpen     = ($groupDate === $defaultOpenDate);
            $collapseId = 'flightsDay' . $gi;
        ?>
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button <?= $isOpen ? '' : 'collapsed' ?>" type="button"
                        data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>"
                        aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="<?= $collapseId ?>">
                    <span class="fw-semibold me-2"><?= e($groupLabel) ?></span>
                    <span class="badge bg-secondary rounded-pill"><?= count($groupFlights) ?></span>
                </button>
            </h2>
            <div id="<?= $collapseId ?>" class="accordion-collapse collapse <?= $isOpen ? 'show' : '' ?>"
                 data-bs-parent="#flightsAccordion">
                <div class="accordion-body p-0">
                    <?php if (empty($groupFlights)): ?>
                    <div class="text-center text-muted py-3"><?= __('FLIGHTS_NO_DATA') ?></div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <?php include BASE_PATH . '/resources/views/flights/_head.php'; ?>
                            </thead>
                            <tbody>
                            <?php foreach ($groupFlights as $f): ?>
                                <?php include BASE_PATH . '/resources/views/flights/_row.php'; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($pagination['last_page'] > 1): ?>
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body">
            <?php include BASE_PATH . '/resources/views/layouts/_pagination.php'; ?>
        </div>
    </div>
    <?php endif; ?>

<?php else: ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <?php include BASE_PATH . '/resources/views/flights/_head.php'; ?>
                    </thead>
                    <tbody>
                    <?php if (empty($flights)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4"><?= __('FLIGHTS_NO_DATA') ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($flights as $f): ?>
                            <?php include BASE_PATH . '/resources/views/flights/_row.php'; ?>
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

<?php endif; ?>
