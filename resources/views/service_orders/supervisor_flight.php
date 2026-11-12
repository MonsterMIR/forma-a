<?php
/**
 * ПЕРЕРАБОТАНО (04.08.2026): полноценный редизайн под планшет —
 * группировка по статусу (в работе → заказано → оказано), AJAX-действия
 * без перезагрузки страницы, правка времени начала/окончания по месту
 * (супервайзер не всегда успевает тапнуть ровно в момент события).
 */
// ДОБАВЛЕНО (14.09.2026, раунд 41): gsm/medical/balance — новые владельцы
// услуг после перераспределения (см. Permissions::GSM/MEDICAL/BALANCE).
// Без строки здесь их услуги видны только во вкладке "Все" — своей вкладки
// в фильтре не будет (см. `if (empty($deptCounts[$role])) continue;` ниже).
$deptLabels = [
    'sop' => 'СОП', 'spo' => 'СПО', 'sst' => 'ССТ', 'aero' => 'Аэродромная', 'spasop' => 'СПАСОП',
    'gsm' => 'ГСМ', 'medical' => 'Здрав.пункт', 'balance' => 'ОЦ',
];

// ИСПРАВЛЕНО (12.11.2026, раунд 173, по жалобе супервайзеров: отменённая
// услуга — например, автобус для пассажиров, который сначала заказали,
// потом отменили, а потом он снова понадобился — полностью пропадала со
// страницы после перезагрузки. Карточка для статуса cancelled уже была
// добавлена в _supervisor_order_card.php (см. докблок там), но сюда,
// в группировку по статусам, отменённые заявки не попадали вообще —
// карточка физически не могла отрендериться при обычной загрузке
// страницы (а не через AJAX-обновление без перезагрузки).
$byStatus = ['in_progress' => [], 'planned' => [], 'done' => [], 'cancelled' => []];
foreach ($orders as $o) {
    if (isset($byStatus[$o['status']])) $byStatus[$o['status']][] = $o;
}
$totalCount = count($orders);
$doneCount  = count($byStatus['done']);
$progressPct = $totalCount > 0 ? round($doneCount / $totalCount * 100) : 0;
?>

<div class="supervisor-sticky-header mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <a href="/supervisor" class="text-muted text-decoration-none small">
                <i class="bi bi-arrow-left me-1"></i>Все рейсы
            </a>
            <h2 class="mb-0 mt-1">
                <i class="bi bi-airplane-fill me-2"></i><?= e($flight['flight_number']) ?>
            </h2>
            <div class="text-muted">
                <?= e($flight['flight_date']) ?> <?= $flight['scheduled_time'] ? '· ' . e($flight['scheduled_time']) : '' ?>
                <?php /* ДОБАВЛЕНО (18.08.2026): номер стоянки виден сразу в шапке —
                         печатается в акте (MC№), но раньше его мог внести только ЦОК. */ ?>
                · МС№
                <?php if (!empty($flight['mc_number'])): ?>
                    <span class="fw-semibold"><?= e($flight['mc_number']) ?></span>
                <?php else: ?>
                    <span class="fst-italic">не указан</span>
                <?php endif; ?>
                <?php /* ДОБАВЛЕНО (23.10.2026, раунд 123, по прямому указанию пользователя):
                         MTOW борта — рядом с МС№/временем в шапке, чтобы супервайзер
                         видел его сразу, без захода в справочник бортов. Источник —
                         $flight['aircraft_mtow'] (уже подставляется FlightService из
                         справочника `aircraft`, см. раунд 117), доп. запросов не требуется. */ ?>
                · MTOW
                <?php if ($flight['aircraft_mtow'] !== null && (float)$flight['aircraft_mtow'] > 0): ?>
                    <span class="fw-semibold"><?= e((string)$flight['aircraft_mtow']) ?> т</span>
                <?php else: ?>
                    <span class="fst-italic">не указан</span>
                <?php endif; ?>
                <?php /* ДОБАВЛЕНО (02.11.2026, раунд 142, по прямому указанию
                         пользователя): фактическое время рейса (ATA/ATD) теперь
                         виден и правится супервайзером, не только ЦОК — тот же
                         паттерн, что и МС№/MTOW выше. */ ?>
                · <?= $flight['operation_type'] === 'arrival' ? 'ATA' : 'ATD' ?>
                <?php
                    $actualTimeField = $flight['operation_type'] === 'arrival' ? 'arr_time' : 'dep_time';
                    $actualTimeValue = $flight[$actualTimeField] ?? null;
                ?>
                <?php if (!empty($actualTimeValue)): ?>
                    <span class="fw-semibold"><?= e(substr((string)$actualTimeValue, 0, 5)) ?></span>
                <?php else: ?>
                    <span class="fst-italic">не указано</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-lg" onclick="location.reload()" title="Обновить и пересортировать">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
            <?php if (!empty($canSign)): ?>
            <a href="/supervisor/flights/<?= (int)$flight['id'] ?>/sign" class="btn btn-success btn-lg">
                <i class="bi bi-pen"></i> Подписать акт
            </a>
            <?php endif; ?>
            <?php /* ДОБАВЛЕНО (21.09.2026, раунд 49, п.3, по прямому указанию
                     пользователя): "формирование формы А продублировать
                     супервайзеру, чтобы он не ждал формирование от ЦОКа" —
                     см. FlightController::buildAct(). Кнопка видна, только
                     пока по рейсу ещё нет акта; условия (манифест, ATA/ATD)
                     проверяются на сервере — если не выполнены, супервайзер
                     увидит понятное сообщение после нажатия. */ ?>
            <?php if (!empty($canAct) && empty($act)): ?>
            <form method="post" action="/flights/<?= (int)$flight['id'] ?>/build-act" class="d-inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-file-earmark-plus"></i> Сформировать акт
                </button>
            </form>
            <?php endif; ?>
            <?php if (!empty($canAct)): ?>
            <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-toggle="collapse" data-bs-target="#standNumberBox">
                <i class="bi bi-geo-alt"></i> МС№
            </button>
            <?php /* ДОБАВЛЕНО (02.11.2026, раунд 142): фактическое время рейса
                     (ATA/ATD) — тот же collapse-паттерн, что у МС№ выше. */ ?>
            <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-toggle="collapse" data-bs-target="#actualTimeBox">
                <i class="bi bi-clock-history"></i> Факт. время
            </button>
            <?php /* ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть, по
                     прямому указанию пользователя): перенос уже стоящего
                     борта на другую стоянку посреди визита — отдельно от
                     МС№ выше (та — для первичного назначения на прилёте).
                     См. ServiceOrderController::moveStand(). Кнопка видна
                     только когда у рейса уже есть текущая стоянка — иначе
                     переносить пока нечего, см. $currentStandForMove ниже. */ ?>
            <?php if (!empty($currentStandForMove)): ?>
            <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-toggle="collapse" data-bs-target="#standMoveBox">
                <i class="bi bi-arrow-left-right"></i> Перенос стоянки
            </button>
            <?php endif; ?>
            <?php /* ДОБАВЛЕНО (21.08.2026, раунд 23): "Цель полёта" — тот же
                     collapse-паттерн, что и у МС№ выше. */ ?>
            <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-toggle="collapse" data-bs-target="#purposeBox">
                <i class="bi bi-flag"></i> Цель полёта
            </button>
            <?php endif; ?>
            <?php if (!empty($addableServices)): ?>
            <button type="button" class="btn btn-outline-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                <i class="bi bi-plus-lg"></i> Добавить услугу
            </button>
            <?php endif; ?>
            <?php /* ДОБАВЛЕНО (21.09.2026, раунд 49, п.1): пересменка — явная
                     передача обслуживания рейса другому супервайзеру, см.
                     ServiceOrderController::handover(). */ ?>
            <?php if (!empty($canAct) && !empty($supervisorsForHandover)): ?>
            <button type="button" class="btn btn-outline-warning btn-lg" data-bs-toggle="modal" data-bs-target="#handoverModal">
                <i class="bi bi-arrow-left-right"></i> Передать смену
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-toggle="collapse" data-bs-target="#historyBox">
                <i class="bi bi-clock-history"></i> История
            </button>
        </div>
    </div>

    <?php /* ДОБАВЛЕНО (23.09.2026, раунд 55, по прямому указанию пользователя):
             "должен видеть в статусах рейса имя супервайзера который уже
             ведёт рейс" + скрыть от путаницы между супервайзерами на одной
             смене — см. ServiceOrderController::supervisorFlight()
             ($handlingSupervisorName/$lockedByOtherSupervisor) и
             App\Models\Flight::tryClaimForSupervisor(). */ ?>
    <?php if (!empty($lockedByOtherSupervisor)): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mt-2 mb-0">
        <i class="bi bi-person-lock flex-shrink-0"></i>
        <span>Рейс сейчас ведёт супервайзер <strong><?= e($handlingSupervisorName ?? ('#' . (int)$handlingSupervisorId)) ?></strong> —
            карточка открыта только для просмотра. Чтобы взять рейс себе, попросите передать его через «Передать смену».</span>
    </div>
    <?php elseif (!empty($handlingSupervisorName)): ?>
    <div class="text-muted small mt-2">
        <i class="bi bi-person-check me-1"></i>Рейс ведёт: <strong><?= e($handlingSupervisorName) ?></strong>
    </div>
    <?php endif; ?>

    <?php if ($totalCount > 0): ?>
    <div class="mt-3">
        <div class="d-flex justify-content-between small text-muted mb-1">
            <span>Прогресс по рейсу</span>
            <span><?= $doneCount ?> из <?= $totalCount ?> оказано</span>
        </div>
        <div class="progress" style="height: 10px;">
            <div class="progress-bar bg-success" style="width: <?= $progressPct ?>%"></div>
        </div>
    </div>

    <?php
    // ДОБАВЛЕНО (04.08.2026): вкладки по службам — фильтр списка, чтобы не
    // листать все 25 позиций сразу, а сразу увидеть только нужную службу.
    // По умолчанию открыта вкладка СОП (если у рейса вообще есть услуги
    // СОП — иначе просто "Все", чтобы не показывать пустой список).
    $deptCounts = [];
    foreach ($orders as $o) {
        $role = $o['owner_role'] ?: 'other';
        $deptCounts[$role] = ($deptCounts[$role] ?? 0) + 1;
    }
    // ИЗМЕНЕНО (05.08.2026, round 16): если смотрит сама служба — открыта
    // её вкладка. Для ЦОК/админа/супервайзера — прежняя логика (СОП/Все).
    if (!empty($defaultDeptOverride) && !empty($deptCounts[$defaultDeptOverride])) {
        $defaultDept = $defaultDeptOverride;
    } else {
        $defaultDept = !empty($deptCounts['sop']) ? 'sop' : 'all';
    }
    ?>
    <div class="dept-tabs mt-3 d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm dept-tab <?= $defaultDept === 'all' ? 'active' : '' ?>" data-dept="all">
            Все <span class="badge bg-secondary"><?= $totalCount ?></span>
        </button>
        <?php foreach ($deptLabels as $role => $label): ?>
            <?php if (empty($deptCounts[$role])) continue; ?>
        <button type="button" class="btn btn-sm dept-tab <?= $defaultDept === $role ? 'active' : '' ?>" data-dept="<?= e($role) ?>">
            <?= e($label) ?> <span class="badge bg-secondary"><?= $deptCounts[$role] ?></span>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>


<?php /* ДОБАВЛЕНО (24.10.2026, раунд 122, по прямому указанию пользователя):
         "супервайзеры просят у себя в интерфейсе рейса уведомление о том
         что пассажирский манифест подан и цифры с него" — заметная плашка
         сразу под шапкой рейса. Источник данных — тот же манифест, что
         служит основанием для начисления PAX_ARR/PAX_DEP/BAG_ARR_PC/
         BAG_DEP_PC и для гейта формирования акта (раунд 38,
         PassengerManifest::getEffectiveForBilling()) — здесь просто
         показываем его текущее состояние, ничего не пишем. См.
         ServiceOrderController::supervisorFlight() ($manifest = ...). */ ?>
<?php if (!empty($manifest)): ?>
    <?php $manifestSubmitted = in_array($manifest['status'], ['submitted', 'accepted'], true); ?>
<div class="alert <?= $manifestSubmitted ? 'alert-success' : 'alert-warning' ?> d-flex align-items-start gap-2 mb-3">
    <i class="bi <?= $manifestSubmitted ? 'bi-clipboard-check-fill' : 'bi-hourglass-split' ?> fs-4 flex-shrink-0"></i>
    <div class="flex-grow-1">
        <?php if ($manifestSubmitted): ?>
        <div class="fw-semibold">
            Манифест подан
            <?php if (!empty($manifest['submitted_at'])): ?>
            <span class="fw-normal text-muted small">— <?= e($manifest['submitted_at']) ?></span>
            <?php endif; ?>
            <?php if (!empty($manifest['created_by_name'])): ?>
            <span class="fw-normal text-muted small">(<?= e($manifest['created_by_name']) ?>, СОП)</span>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-3 mt-1 small">
            <span><i class="bi bi-people-fill me-1"></i>Пассажиры: <strong><?= (int)$manifest['pax_total'] ?></strong>
                <span class="text-muted">(взр. <?= (int)$manifest['pax_adults'] ?>, дет. <?= (int)$manifest['pax_children'] ?>, млад. <?= (int)$manifest['pax_infants'] ?>)</span>
            </span>
            <span><i class="bi bi-person-badge-fill me-1"></i>Экипаж: <strong><?= (int)$manifest['pax_crew'] ?></strong></span>
            <?php if ((int)$manifest['pax_transit'] > 0): ?>
            <span><i class="bi bi-arrow-repeat me-1"></i>Транзит: <strong><?= (int)$manifest['pax_transit'] ?></strong></span>
            <?php endif; ?>
            <span><i class="bi bi-bag-fill me-1"></i>Багаж: <strong><?= (int)$manifest['baggage_pieces'] ?></strong> мест<?php if ((float)$manifest['baggage_weight_kg'] > 0): ?><span class="text-muted"> / <?= e(number_format((float)$manifest['baggage_weight_kg'], 1)) ?> кг</span><?php endif; ?></span>
            <?php if ((float)$manifest['cargo_weight_kg'] > 0): ?>
            <span><i class="bi bi-box-seam-fill me-1"></i>Груз: <strong><?= e(number_format((float)$manifest['cargo_weight_kg'], 1)) ?> кг</strong></span>
            <?php endif; ?>
            <?php if ((float)$manifest['mail_weight_kg'] > 0): ?>
            <span><i class="bi bi-envelope-fill me-1"></i>Почта: <strong><?= e(number_format((float)$manifest['mail_weight_kg'], 1)) ?> кг</strong></span>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="fw-semibold">Манифест пока только заполняется СОП — ещё не подан</div>
        <div class="small text-muted mt-1">Данные ниже предварительные и могут измениться: пассажиры <?= (int)$manifest['pax_total'] ?>, багаж <?= (int)$manifest['baggage_pieces'] ?> мест.</div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-circle-fill fs-4 flex-shrink-0"></i>
    <span>Манифест по этому рейсу ещё не заведён СОП — акт не сформируется, пока манифест не будет подан.</span>
</div>
<?php endif; ?>
<?php /* ДОБАВЛЕНО (21.09.2026, раунд 49, п.1): "Передать смену" — модалка с
         выбором принимающего супервайзера + необязательное примечание.
         Само по себе действие ничего не блокирует (супервайзер и так не
         привязан к рейсу — см. докблок ServiceOrderController), это явная
         фиксация факта пересменки в истории рейса + уведомление принимающему. */ ?>
<?php if (!empty($canAct) && !empty($supervisorsForHandover)): ?>
<div class="modal fade" id="handoverModal" tabindex="-1" aria-labelledby="handoverModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="/supervisor/flights/<?= (int)$flight['id'] ?>/handover">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="handoverModalLabel">Передать смену по рейсу <?= e($flight['flight_number']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Кому передать</label>
                        <select name="to_user_id" class="form-select form-select-lg" required>
                            <option value="">— выберите супервайзера —</option>
                            <?php foreach ($supervisorsForHandover as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Примечание (необязательно)</label>
                        <textarea name="note" class="form-control" rows="2" placeholder="Например: что уже сделано, на что обратить внимание"></textarea>
                    </div>
                    <div class="small text-muted">
                        Принимающий супервайзер получит уведомление и увидит запись в «Истории» ниже.
                        Сам рейс по-прежнему открыт для действий любому супервайзеру — эта отметка не блокирует доступ.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-left-right"></i> Передать</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php /* ДОБАВЛЕНО (21.09.2026, раунд 49, п.1): панель "История по рейсу" —
         полный журнал действий супервайзеров/ЦОК по этому рейсу (кто, что,
         когда), включая явные передачи смены выше. Источник —
         user_activity_log через ActivityLogger::log(), см.
         ServiceOrderController::logFlightAction(). */ ?>
<div class="collapse mb-3" id="historyBox">
    <div class="card border-secondary border-2 shadow-sm">
        <div class="card-body">
            <div class="fw-semibold mb-2">История действий по рейсу</div>
            <?php if (empty($history)): ?>
            <div class="text-muted small">Пока нет записей.</div>
            <?php else: ?>
            <div class="list-group list-group-flush" style="max-height: 360px; overflow-y: auto;">
                <?php foreach ($history as $h): ?>
                <div class="list-group-item px-0 py-2">
                    <div class="d-flex justify-content-between">
                        <span class="fw-semibold"><?= e($h['full_name'] ?: ($h['username'] ?: 'система')) ?></span>
                        <span class="text-muted small"><?= e($h['created_at']) ?></span>
                    </div>
                    <div class="small"><?= e($h['description'] ?: $h['action']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
/**
 * ДОБАВЛЕНО (06.08.2026): статус доставки уведомлений о предзаказе по
 * каждой вовлечённой службе — супервайзер должен видеть, что все службы
 * реально получили и (в идеале) прочитали уведомление, а не гадать.
 */
?>
<?php if (!empty($notificationStatus)): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold small d-flex align-items-center gap-2">
        <i class="bi bi-bell text-primary"></i> Уведомления службам о предзаказе
    </div>
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3">
            <?php foreach ($notificationStatus as $role => $info): ?>
                <?php
                    $total = count($info['recipients']);
                    $readCount = count(array_filter($info['recipients'], fn($r) => $r['read']));
                    $allRead = $total > 0 && $readCount === $total;
                    $anyPushed = count(array_filter($info['recipients'], fn($r) => $r['pushed'])) > 0;
                ?>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge role-badge-<?= e($role) ?> rounded-pill px-2">
                        <?= e($deptLabels[$role] ?? $role) ?>
                    </span>
                    <?php if ($allRead): ?>
                        <span class="text-success small"><i class="bi bi-check2-all"></i> Прочитано (<?= $readCount ?>/<?= $total ?>)</span>
                    <?php elseif ($anyPushed): ?>
                        <span class="text-muted small"><i class="bi bi-check2"></i> Доставлено, не прочитано (<?= $readCount ?>/<?= $total ?>)</span>
                    <?php else: ?>
                        <span class="text-muted small" title="Push мог не дойти (нет подписки в браузере) — уведомление всё равно в системе">
                            <i class="bi bi-envelope"></i> В системе (<?= $readCount ?>/<?= $total ?> прочитано)
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Плашка обратной связи после AJAX-действий -->
<div id="toastArea" class="mb-2"></div>

<?php if (!empty($canAct)): ?>
<!-- ДОБАВЛЕНО (18.08.2026): номер стоянки (MC№) — печатается в акте формы А
     рядом со строкой "Time of setting/removing chocks-LT", раньше вносился
     только ЦОК на форме рейса. Тот же collapse-паттерн, что у "Добавить
     услугу" ниже. -->
<div class="collapse mb-3" id="standNumberBox">
    <div class="card border-secondary border-2 shadow-sm">
        <div class="card-body">
            <div class="fw-semibold mb-2">Номер стоянки (МС№) — печатается в акте формы А</div>
            <?php
                // ДОБАВЛЕНО (18.08.2026, раунд 16): выбор из справочника вместо
                // ручного набора текста на планшете — см. App\Models\ParkingStand.
                // Текущее значение рейса всегда есть в списке опций, даже если
                // его скрыли/удалили из справочника или оно осталось от старого
                // свободного ввода — иначе форма молча его потеряла бы при
                // следующем сохранении.
                $currentStand   = $flight['mc_number'] ?? '';
                $standCodes     = array_map(fn($s) => $s['code'], $parkingStands ?? []);
                $currentIsKnown = $currentStand === '' || in_array($currentStand, $standCodes, true);
            ?>
            <form method="post" action="/supervisor/flights/<?= (int)$flight['id'] ?>/stand" class="d-flex gap-2 flex-wrap">
                <?= csrf_field() ?>
                <select name="mc_number" class="form-select form-select-lg" style="max-width: 240px;">
                    <option value="">— не указана —</option>
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
                <button type="submit" class="btn btn-secondary btn-lg">
                    <i class="bi bi-check-lg"></i> Сохранить
                </button>
            </form>
            <?php if (empty($parkingStands)): ?>
            <div class="small text-muted mt-2">
                Нет свободных стоянок — все заняты другими бортами, либо
                справочник пуст (Справочники → Стоянки).
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ДОБАВЛЕНО (02.11.2026, раунд 142, по прямому указанию пользователя —
     запрос супервайзеров): фактическое время рейса (ATA на прилёте / ATD на
     вылете, flights.arr_time/dep_time) раньше вносил только ЦОК на странице
     /flights/{id}/edit. Тот же collapse-паттерн, что у МС№ выше — одно и то
     же поле, супервайзер и ЦОК пишут в одно и то же место. Поле, доступное
     для правки, зависит от типа ЭТОГО рейса (см. ServiceOrderController::
     saveActualTime()) — на карточке прилёта это ATA, на карточке вылета — ATD. -->
<div class="collapse mb-3" id="actualTimeBox">
    <div class="card border-secondary border-2 shadow-sm">
        <div class="card-body">
            <?php
                $isArrivalFlight = $flight['operation_type'] === 'arrival';
                $actualTimeLabel = $isArrivalFlight ? 'Фактическое время прилёта (ATA)' : 'Фактическое время вылета (ATD)';
                $actualTimeCurrent = $flight[$isArrivalFlight ? 'arr_time' : 'dep_time'] ?? '';
                $actualTimeCurrent = $actualTimeCurrent ? substr((string)$actualTimeCurrent, 0, 5) : '';
            ?>
            <div class="fw-semibold mb-2"><?= e($actualTimeLabel) ?></div>
            <form method="post" action="/supervisor/flights/<?= (int)$flight['id'] ?>/actual-time" class="d-flex gap-2 flex-wrap">
                <?= csrf_field() ?>
                <input type="time" name="actual_time" class="form-control form-control-lg" style="max-width: 200px;"
                       value="<?= e($actualTimeCurrent) ?>">
                <button type="submit" class="btn btn-secondary btn-lg">
                    <i class="bi bi-check-lg"></i> Сохранить
                </button>
            </form>
        </div>
    </div>
</div>

<?php /* ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть, по прямому
         указанию пользователя): перенос борта на другую стоянку посреди
         визита (борт уже стоит на $currentStandForMove) — пишет историю
         в flight_stand_moves, старая стоянка освобождается немедленно,
         см. App\Services\ParkingStandService::recordMove(). В отличие от
         МС№ выше — это НЕ первичное назначение, а явный перенос уже
         находящегося на перроне борта. */ ?>
<?php if (!empty($currentStandForMove)): ?>
<div class="collapse mb-3" id="standMoveBox">
    <div class="card border-warning border-2 shadow-sm">
        <div class="card-body">
            <div class="fw-semibold mb-2">
                Перенос на другую стоянку — борт сейчас на
                <span class="text-primary"><?= e($currentStandForMove) ?></span>
            </div>
            <div class="small text-muted mb-2">
                Используйте, если борт физически переставили на перроне.
                Старая стоянка (<?= e($currentStandForMove) ?>) освободится
                для других ВС сразу после сохранения.
            </div>
            <form method="post" action="/supervisor/flights/<?= (int)$flight['id'] ?>/move-stand" class="d-flex gap-2 flex-wrap align-items-start">
                <?= csrf_field() ?>
                <select name="mc_number" class="form-select form-select-lg" style="max-width: 240px;" required>
                    <option value="">— выберите новую стоянку —</option>
                    <?php foreach (($parkingStands ?? []) as $stand): ?>
                    <?php if ($stand['code'] === $currentStandForMove) continue; ?>
                    <option value="<?= e($stand['code']) ?>"><?= e($stand['code']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="note" class="form-control form-control-lg" style="max-width: 280px;"
                       placeholder="Примечание (необязательно)">
                <button type="submit" class="btn btn-warning btn-lg">
                    <i class="bi bi-arrow-left-right"></i> Перенести
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php /* ДОБАВЛЕНО (21.08.2026, раунд 23): "Цель полёта" — печатается в акте
         формы А (см. resources/views/acts/print.php, строка "Flight №,purpose/
         №рейса, цель полета") среди 4 уже напечатанных на бланке колонок —
         супервайзер отмечает ровно одну. Значения ENUM 1-в-1 с колонками
         бланка, см. sql/round23_flight_purpose.sql. */ ?>
<div class="collapse mb-3" id="purposeBox">
    <div class="card border-secondary border-2 shadow-sm">
        <div class="card-body">
            <div class="fw-semibold mb-2">Цель полёта — печатается в акте формы А</div>
            <?php
                $purposeOptions = [
                    'pax_charter'   => 'Pax Charter',
                    'cargo_charter' => 'Cargo Charter',
                    'pax_business'  => 'Pax Business',
                    'tech_stop'     => 'Tex Stop',
                ];
                $currentPurpose = $flight['flight_purpose'] ?? '';
            ?>
            <form method="post" action="/supervisor/flights/<?= (int)$flight['id'] ?>/purpose" class="d-flex gap-3 flex-wrap align-items-center">
                <?= csrf_field() ?>
                <?php foreach ($purposeOptions as $val => $label): ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="flight_purpose"
                           id="purpose_<?= e($val) ?>" value="<?= e($val) ?>"
                           <?= $currentPurpose === $val ? 'checked' : '' ?>>
                    <label class="form-check-label" for="purpose_<?= e($val) ?>"><?= e($label) ?></label>
                </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-secondary btn-lg">
                    <i class="bi bi-check-lg"></i> Сохранить
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// ПЕРЕРАБОТАНО (07.09.2026): раньше был плоский <select> со всеми услугами
// одним длинным списком — заменено на поп-ап с двухшажным выбором (кнопки
// служб → плитки услуг конкретной службы), но после каждой услуги поп-ап
// сразу отправлял форму и закрывался — для нескольких услуг подряд
// супервайзеру приходилось открывать поп-ап заново на каждую.
//
// ПЕРЕРАБОТАНО ЕЩЁ РАЗ (08.09.2026, раунд 30) по просьбе пользователя:
// 1) мульти-выбор — тап по плитке теперь только выделяет её (можно
//    выбрать сколько угодно услуг, в т.ч. из разных служб), отправка —
//    отдельной кнопкой "Добавить выбранные" внизу поп-апа;
// 2) вкладки служб сделаны ПОСТОЯННО видимыми пилюлями над плитками
//    (как $deptLabels-вкладки в шапке страницы) — переключение службы не
//    сбрасывает уже сделанный на других вкладках выбор и не требует
//    кнопки "Назад" для возврата к списку служб.
if (!empty($addableServices)):
    $byRole = [];
    foreach ($addableServices as $svc) {
        $byRole[$svc['owner_role'] ?: 'other'][] = $svc;
    }
    $firstAddDept = null;
    foreach ($deptLabels as $role => $label) {
        if (!empty($byRole[$role])) { $firstAddDept = $role; break; }
    }
?>
<div class="modal fade" id="addServiceModal" tabindex="-1" aria-labelledby="addServiceModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title mb-0" id="addServiceModalLabel">Добавить услугу</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
      </div>
      <div class="modal-body">
        <!-- Постоянные вкладки служб — переключаются свободно, выбор с других вкладок не теряется -->
        <div class="dept-tabs d-flex gap-2 flex-wrap mb-3">
            <?php foreach ($deptLabels as $role => $label):
                if (empty($byRole[$role])) continue;
            ?>
            <button type="button" class="btn btn-sm dept-tab addservice-dept-pill <?= $role === $firstAddDept ? 'active' : '' ?>"
                    data-dept="<?= e($role) ?>" onclick="addServiceSwitchDept('<?= e($role) ?>')">
                <?= e($label) ?> <span class="badge bg-secondary"><?= count($byRole[$role]) ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Плитки услуг — по одному блоку на каждую службу, видна только вкладка активной службы -->
        <?php foreach ($deptLabels as $role => $label):
            if (empty($byRole[$role])) continue;
        ?>
        <div class="addservice-tiles <?= $role === $firstAddDept ? '' : 'd-none' ?>" id="addServiceTiles_<?= e($role) ?>">
            <div class="row g-2">
                <?php foreach ($byRole[$role] as $svc): ?>
                <div class="col-6 col-md-4">
                    <button type="button"
                            class="btn btn-outline-secondary w-100 h-100 py-3 px-2 service-tile-btn"
                            style="min-height: 84px; font-size: .95rem; line-height: 1.25; position: relative;"
                            data-service-id="<?= (int)$svc['id'] ?>"
                            onclick="addServiceToggleTile(this)">
                        <i class="bi bi-check-circle-fill service-tile-check position-absolute top-0 end-0 m-1 d-none"></i>
                        <?= e($svc['name']) ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer d-flex justify-content-between align-items-center">
        <div class="small text-muted" id="addServiceSelectedSummary">Услуги не выбраны</div>
        <button type="button" class="btn btn-primary btn-lg" id="addServiceConfirmBtn" disabled onclick="addServiceConfirm()">
            <i class="bi bi-plus-lg"></i> Добавить выбранные (<span id="addServiceSelectedCount">0</span>)
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ── Поп-ап "Добавить услугу" — мульти-выбор + постоянные вкладки служб ─────
var addServiceSelected = new Set();

function addServiceSwitchDept(role) {
    document.querySelectorAll('#addServiceModal .addservice-dept-pill').forEach(function (el) {
        el.classList.toggle('active', el.dataset.dept === role);
    });
    document.querySelectorAll('#addServiceModal .addservice-tiles').forEach(function (el) {
        el.classList.toggle('d-none', el.id !== 'addServiceTiles_' + role);
    });
}

function addServiceToggleTile(btn) {
    const id = btn.dataset.serviceId;
    const check = btn.querySelector('.service-tile-check');
    if (addServiceSelected.has(id)) {
        addServiceSelected.delete(id);
        btn.classList.remove('selected', 'btn-primary');
        btn.classList.add('btn-outline-secondary');
        if (check) check.classList.add('d-none');
    } else {
        addServiceSelected.add(id);
        btn.classList.add('selected', 'btn-primary');
        btn.classList.remove('btn-outline-secondary');
        if (check) check.classList.remove('d-none');
    }
    addServiceUpdateSummary();
}

function addServiceUpdateSummary() {
    const n = addServiceSelected.size;
    document.getElementById('addServiceSelectedCount').textContent = n;
    document.getElementById('addServiceConfirmBtn').disabled = (n === 0);
    document.getElementById('addServiceSelectedSummary').textContent =
        n === 0 ? 'Услуги не выбраны' : 'Выбрано услуг: ' + n;
}

function addServiceResetSelection() {
    addServiceSelected.clear();
    document.querySelectorAll('#addServiceModal .service-tile-btn.selected').forEach(function (btn) {
        btn.classList.remove('selected', 'btn-primary');
        btn.classList.add('btn-outline-secondary');
        const check = btn.querySelector('.service-tile-check');
        if (check) check.classList.add('d-none');
    });
    <?php if ($firstAddDept !== null): ?>
    addServiceSwitchDept('<?= e($firstAddDept) ?>');
    <?php endif; ?>
    addServiceUpdateSummary();
}

function addServiceConfirm() {
    if (addServiceSelected.size === 0) return;
    const confirmBtn = document.getElementById('addServiceConfirmBtn');
    confirmBtn.disabled = true;

    const body = new URLSearchParams();
    body.append('_csrf', CSRF_TOKEN_SUP);
    addServiceSelected.forEach(function (id) { body.append('service_ids[]', id); });

    fetch('/supervisor/flights/<?= (int)$flight['id'] ?>/add-services', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: body.toString()
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success && !data.added) {
                confirmBtn.disabled = false;
                showToast(data.message || 'Не удалось добавить услуги', 'error');
                return;
            }
            // Полная перезагрузка: новые позиции плана должны появиться как
            // полноценные карточки (см. _supervisor_order_card.php), а не
            // собираться заново через JS, как это уже сделано для схожего
            // случая в openManualEditPanel() выше по этому же файлу.
            showToast(data.message || 'Добавлено', 'success');
            setTimeout(function () { location.reload(); }, 500);
        })
        .catch(function () {
            confirmBtn.disabled = false;
            showToast('Ошибка сети — попробуйте ещё раз', 'error');
        });
}

// Каждый раз при открытии поп-апа — сбрасываем выбор с прошлого раза и
// возвращаемся на первую вкладку с доступными услугами.
var addServiceModalEl = document.getElementById('addServiceModal');
if (addServiceModalEl) {
    addServiceModalEl.addEventListener('show.bs.modal', addServiceResetSelection);
}
</script>
<?php endif; ?>

<?php if (empty($orders)): ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-clipboard-x fs-1 d-block mb-2"></i>
    ЦОК ещё не сформировал предзаказ по этому рейсу
    <?php if (!empty($addableServices)): ?>
    <div class="mt-2 small">Можете добавить нужное сами — кнопка «Добавить услугу» выше</div>
    <?php endif; ?>
</div>
<?php else: ?>

<?php
$sections = [
    'in_progress' => ['label' => 'В работе — сейчас', 'icon' => 'bi-hourglass-split', 'color' => 'warning'],
    'planned'     => ['label' => 'Заказано ЦОК',        'icon' => 'bi-clipboard',       'color' => 'secondary'],
    'done'        => ['label' => 'Оказано',              'icon' => 'bi-check-circle',    'color' => 'success'],
    // ДОБАВЛЕНО (12.11.2026, раунд 173): секция для отменённых услуг —
    // последняя по приоритету (не требует действия), но должна быть
    // видна, чтобы можно было заказать услугу повторно (см. кнопку
    // «Заказать ещё раз» в _supervisor_order_card.php).
    'cancelled'   => ['label' => 'Отменено',             'icon' => 'bi-x-circle',        'color' => 'secondary'],
];
foreach ($sections as $statusKey => $meta):
    if (empty($byStatus[$statusKey])) continue;
?>
<div class="mb-4 order-section" data-section="<?= $statusKey ?>">
    <div class="d-flex align-items-center gap-2 mb-2 text-<?= $meta['color'] ?>">
        <i class="bi <?= $meta['icon'] ?> fs-5"></i>
        <span class="fw-semibold text-uppercase small"><?= $meta['label'] ?></span>
        <span class="badge bg-<?= $meta['color'] ?> bg-opacity-25 text-<?= $meta['color'] ?>"><?= count($byStatus[$statusKey]) ?></span>
    </div>
    <div class="row g-3">
        <?php foreach ($byStatus[$statusKey] as $o): ?>
        <div class="col-12">
            <?php include __DIR__ . '/_supervisor_order_card.php'; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<script>
const CSRF_TOKEN_SUP = document.querySelector('meta[name=csrf-token]')?.content || '';
// ДОБАВЛЕНО (19.08.2026, раунд 21): нужно в buildActionButtons() для
// колодок (CHOCKS) — см. докблок в _supervisor_order_card.php. Здесь та же
// логика нужна на случай, когда карточка перерисовывается JS-ом (после
// "Сбросить") без полной перезагрузки страницы.
const FLIGHT_OPERATION_TYPE_SUP = '<?= e($flight['operation_type'] ?? '') ?>';

function showToast(message, type) {
    const area = document.getElementById('toastArea');
    const cls = type === 'error' ? 'danger' : type;
    area.innerHTML = '<div class="alert alert-' + cls + ' d-flex justify-content-between align-items-center py-2 mb-0">'
        + '<span>' + message + '</span>'
        + '<button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>'
        + '</div>';
    if (type === 'success') {
        setTimeout(() => { area.innerHTML = ''; }, 3000);
    }
}

function ajaxPost(url, params) {
    const body = new URLSearchParams(params);
    body.append('_csrf', CSRF_TOKEN_SUP);
    return fetch(url, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: body.toString()
    }).then(r => r.json());
}

function formatTime(dt) {
    if (!dt) return null;
    const d = new Date(dt.replace(' ', 'T'));
    return String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
}

// ── Действия start/complete/reset — без перезагрузки страницы ──────────────
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.order-action-btn');
    if (!btn) return;
    e.preventDefault();

    const orderId = btn.dataset.orderId;
    const action = btn.dataset.action;
    const card = btn.closest('.order-card');

    // ДОБАВЛЕНО (04.08.2026): для услуг с реальным количеством (пассажиры,
    // багаж, часы, литры) — прежде чем завершить, спрашиваем число, а не
    // молча берём default_quantity=1.
    if (action === 'complete' && card && card.dataset.requiresQty === '1') {
        promptQuantityAndComplete(orderId, card, btn);
        return;
    }

    runAction(orderId, action, {}, btn);
});

function runAction(orderId, action, extraParams, btn) {
    if (btn) btn.disabled = true;
    const params = Object.assign({action: action}, extraParams);

    ajaxPost('/supervisor/orders/' + orderId + '/timing', params)
        .then(data => {
            if (btn) btn.disabled = false;
            if (!data.success) {
                showToast(data.message, 'error');
                return;
            }
            if (data.warning) {
                showToast(data.warning, 'warning');
            } else {
                showToast(data.message || 'Готово', 'success');
            }
            updateCardFromOrder(orderId, data.order);
        })
        .catch(() => {
            if (btn) btn.disabled = false;
            showToast('Ошибка сети — попробуйте ещё раз', 'error');
        });
}

function promptQuantityAndComplete(orderId, card, btn) {
    const actionsEl = card.querySelector('.order-actions');
    if (!actionsEl || actionsEl.querySelector('.qty-prompt-input')) return; // уже открыт

    const defaultQty = card.dataset.defaultQty || '1';
    const unit = card.dataset.unit || '';
    const savedHtml = actionsEl.innerHTML;

    actionsEl.innerHTML =
        '<div class="d-flex align-items-center gap-2 qty-prompt">'
        + '<input type="number" step="0.01" min="0.01" class="form-control form-control-lg qty-prompt-input" '
        +   'style="width:110px;" value="' + defaultQty + '" placeholder="кол-во">'
        + '<span class="text-muted small">' + unit + '</span>'
        + '<button type="button" class="btn btn-success qty-prompt-confirm"><i class="bi bi-check-lg"></i></button>'
        + '<button type="button" class="btn btn-outline-secondary qty-prompt-cancel"><i class="bi bi-x-lg"></i></button>'
        + '</div>';

    const input = actionsEl.querySelector('.qty-prompt-input');
    input.focus();
    input.select();

    actionsEl.querySelector('.qty-prompt-cancel').addEventListener('click', () => {
        actionsEl.innerHTML = savedHtml;
    });
    actionsEl.querySelector('.qty-prompt-confirm').addEventListener('click', () => {
        const qty = input.value;
        if (!qty || parseFloat(qty) <= 0) {
            showToast('Введите количество больше нуля', 'error');
            return;
        }
        runAction(orderId, 'complete', {quantity: qty}, null);
    });
}

// ── Редактировать — задать время начала/окончания вручную, независимо от
// статуса (не всегда получается тапнуть Начать/Завершить вовремя) ──────────
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.order-edit-btn');
    if (!btn) return;
    e.preventDefault();
    openManualEditPanel(btn);
});

function openManualEditPanel(btn) {
    const card = btn.closest('.order-card');
    const actionsEl = card.querySelector('.order-actions');
    if (actionsEl.querySelector('.manual-edit-panel')) return; // уже открыт

    const orderId = btn.dataset.orderId;
    const savedHtml = actionsEl.innerHTML;
    const requiresQty = card.dataset.requiresQty === '1';
    const unit = card.dataset.unit || '';

    const curStart = card.querySelector('.time-value[data-field="started_at"]');
    const curEnd = card.querySelector('.time-value[data-field="completed_at"]');
    const startVal = curStart && curStart.textContent.trim() !== '—' ? curStart.textContent.trim() : '';
    const endVal = curEnd && curEnd.textContent.trim() !== '—' ? curEnd.textContent.trim() : '';
    const curQty = card.querySelector('.qty-value');
    const qtyVal = curQty ? curQty.textContent.trim() : (card.dataset.defaultQty || '1');

    let html = '<div class="manual-edit-panel w-100">'
        + '<div class="d-flex gap-2 flex-wrap align-items-end">'
        + '<div><label class="form-label small mb-1">Начало</label>'
        + '<input type="time" class="form-control form-control-lg manual-edit-start" value="' + startVal + '"></div>'
        + '<div><label class="form-label small mb-1">Окончание</label>'
        + '<input type="time" class="form-control form-control-lg manual-edit-end" value="' + endVal + '"></div>';
    if (requiresQty) {
        html += '<div><label class="form-label small mb-1">Количество (' + unit + ')</label>'
            + '<input type="number" step="0.01" min="0.01" class="form-control form-control-lg manual-edit-qty" '
            + 'style="width:110px;" value="' + qtyVal + '"></div>';
    }
    html += '<button type="button" class="btn btn-success manual-edit-save"><i class="bi bi-check-lg"></i> Сохранить</button>'
        + '<button type="button" class="btn btn-outline-secondary manual-edit-cancel"><i class="bi bi-x-lg"></i></button>'
        + '</div>'
        + '<div class="form-text">Окончание можно оставить пустым, если услуга ещё не закончена</div>'
        + '</div>';

    actionsEl.innerHTML = html;

    actionsEl.querySelector('.manual-edit-cancel').addEventListener('click', () => {
        actionsEl.innerHTML = savedHtml;
    });
    actionsEl.querySelector('.manual-edit-save').addEventListener('click', () => {
        const params = {
            started_at: actionsEl.querySelector('.manual-edit-start').value,
            completed_at: actionsEl.querySelector('.manual-edit-end').value,
        };
        if (requiresQty) {
            params.quantity = actionsEl.querySelector('.manual-edit-qty').value;
        }
        ajaxPost('/supervisor/orders/' + orderId + '/manual-edit', params)
            .then(data => {
                if (!data.success) {
                    showToast(data.message, 'error');
                    return;
                }
                // Полная перезагрузка, а не точечное обновление карточки: если
                // услуга только что перешла из "Заказано" — в её разметке
                // физически ещё нет мест под время/количество, проще и
                // надёжнее перерисовать всю страницу, чем собирать их через JS.
                showToast(data.warning || data.message || 'Сохранено', data.warning ? 'warning' : 'success');
                setTimeout(() => location.reload(), 600);
            })
            .catch(() => showToast('Ошибка сети — попробуйте ещё раз', 'error'));
    });
}

// ── Обновление карточки на месте (без переноса между секциями до обновления страницы) ──
function updateCardFromOrder(orderId, order) {
    const card = document.querySelector('.order-card[data-order-id="' + orderId + '"]');
    if (!card) return;

    card.classList.remove('status-planned', 'status-in_progress', 'status-done');
    card.classList.add('status-' + order.status);

    const badge = card.querySelector('.order-status-badge');
    if (badge) {
        const map = {planned: ['secondary','Заказано'], in_progress: ['warning text-dark','В работе'], done: ['success','Оказано']};
        const [cls, text] = map[order.status] || ['secondary', order.status];
        badge.className = 'badge order-status-badge fs-6 px-3 py-2 bg-' + cls;
        badge.textContent = text;
    }

    updateTimeChip(card, 'started_at', order.started_at);
    updateTimeChip(card, 'completed_at', order.completed_at);
    updateQtyChip(card, order.quantity);

    const actions = card.querySelector('.order-actions');
    if (actions) actions.innerHTML = buildActionButtons(orderId, order.status, card);

    recountProgress();
}

function updateTimeChip(card, field, value) {
    const chip = card.querySelector('.time-value[data-field="' + field + '"]');
    if (chip) chip.textContent = formatTime(value) || '—';
}

function updateQtyChip(card, quantity) {
    if (quantity === undefined || quantity === null) return;
    let chip = card.querySelector('.qty-chip');
    const unit = card.dataset.unit || '';
    const qtyText = String(parseFloat(quantity)).replace(/\.00$/, '');
    if (chip) {
        chip.querySelector('.qty-value').textContent = qtyText;
    } else if (card.dataset.requiresQty === '1' || card.dataset.quantityFromTiming === '1') {
        // ИЗМЕНЕНО (29.09.2026, раунд 64): чип количества нужно вставлять и
        // для услуг с quantity_from_timing=1 (GPU, AIRCOND) — у них
        // requiresQty теперь '0' (ручной ввод убран), но количество
        // (посчитанные сервером часы) всё равно нужно показать супервайзеру
        // сразу после "Завершить", без перезагрузки страницы.
        // Чипа ещё нет (только что подтвердили впервые) — добавляем
        const timesRow = card.querySelector('.text-muted.small.d-flex');
        if (timesRow) {
            timesRow.insertAdjacentHTML('beforeend',
                '<span class="qty-chip" data-order-id="' + card.dataset.orderId + '">'
                + '<i class="bi bi-123"></i> <span class="qty-value">' + qtyText + '</span> '
                + '<span class="text-muted">' + unit + '</span>'
                + '<button type="button" class="qty-edit-btn" title="Изменить количество"><i class="bi bi-pencil-fill" style="font-size:.7rem;"></i></button>'
                + '</span>'
            );
        }
    }
}

function buildActionButtons(orderId, status, card) {
    const isFuel = card && card.dataset.serviceCode === 'FUEL_SVC';
    if (isFuel && status === 'cancelled') {
        // ДОБАВЛЕНО (30.10.2026, раунд 141): см. тот же фикс и докблок в
        // _supervisor_order_card.php — без этой проверки отменённая
        // дозаправка оставалась с живой кнопкой "Внести данные заправки".
        return '<span class="text-muted small fst-italic">Отменено</span>';
    }
    if (isFuel) {
        const label = status === 'done' ? 'Изменить данные заправки' : 'Внести данные заправки';
        const cls = status === 'done' ? 'btn-outline-primary' : 'btn-primary';
        let fuelHtml = '<a href="/supervisor/orders/' + orderId + '/fuel" class="btn ' + cls + '">'
             + '<i class="bi bi-fuel-pump-fill"></i> ' + label + '</a>';
        // ДОБАВЛЕНО (30.10.2026, раунд 140): топливо раньше никогда не получало
        // кнопку "Убрать из плана" даже в статусе planned — см. тот же фикс и
        // докблок в _supervisor_order_card.php (это JS-копия той же разметки,
        // используется после ajax-действий без перезагрузки страницы).
        if (status === 'planned') {
            fuelHtml += '<button class="btn btn-outline-danger order-remove-btn" data-order-id="' + orderId + '" title="Убрать из плана — не нужна">'
                      + '<i class="bi bi-trash"></i></button>';
        }
        return fuelHtml;
    }
    if (status === 'planned' && card && card.dataset.serviceCode === 'CHOCKS') {
        // См. докблок в _supervisor_order_card.php — колодки: одно действие
        // вместо пары "Начать/Завершить", подпись зависит от плеча рейса.
        const chocksLabel = FLIGHT_OPERATION_TYPE_SUP === 'departure' ? 'Колодки убраны' : 'Колодки установлены';
        return '<button class="btn btn-primary order-action-btn" data-order-id="' + orderId + '" data-action="complete">'
             + '<i class="bi bi-check-lg"></i> ' + chocksLabel + '</button>'
             + '<button class="btn btn-outline-danger order-remove-btn" data-order-id="' + orderId + '" title="Убрать из плана — не нужна">'
             + '<i class="bi bi-trash"></i></button>';
    }
    if (status === 'planned' && card && card.dataset.noTiming === '1') {
        // См. докблок в _supervisor_order_card.php — услуги без тайминга
        // (напр. МЕТЕО): одна кнопка "Оказано" вместо "Начать"/"Завершить".
        return '<button class="btn btn-primary order-action-btn" data-order-id="' + orderId + '" data-action="complete">'
             + '<i class="bi bi-check-lg"></i> Оказано</button>'
             + '<button class="btn btn-outline-danger order-remove-btn" data-order-id="' + orderId + '" title="Убрать из плана — не нужна">'
             + '<i class="bi bi-trash"></i></button>';
    }
    if (status === 'planned') {
        return '<button class="btn btn-primary order-action-btn" data-order-id="' + orderId + '" data-action="start">'
             + '<i class="bi bi-play-fill"></i> Начать</button>'
             + '<button class="btn btn-outline-danger order-remove-btn" data-order-id="' + orderId + '" title="Убрать из плана — не нужна">'
             + '<i class="bi bi-trash"></i></button>';
    }
    if (status === 'in_progress') {
        return '<button class="btn btn-success order-action-btn" data-order-id="' + orderId + '" data-action="complete">'
             + '<i class="bi bi-check-lg"></i> Завершить</button>'
             + '<button class="btn btn-outline-secondary order-action-btn" data-order-id="' + orderId + '" data-action="reset" title="Сбросить">'
             + '<i class="bi bi-arrow-counterclockwise"></i></button>';
    }
    if (status === 'done') {
        return '<button class="btn btn-outline-secondary order-action-btn" data-order-id="' + orderId + '" data-action="reset">'
             + '<i class="bi bi-arrow-counterclockwise"></i> Отменить</button>';
    }
    return '';
}

function recountProgress() {
    const total = document.querySelectorAll('.order-card').length;
    const done = document.querySelectorAll('.order-card.status-done').length;
    const bar = document.querySelector('.progress-bar');
    if (bar && total > 0) bar.style.width = Math.round(done/total*100) + '%';
}

// ── Вкладки по службам — фильтр списка ──────────────────────────────────────
document.querySelectorAll('.dept-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.dept-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        applyDeptFilter(this.dataset.dept);
    });
});

// Применяем фильтр по умолчанию (СОП, если есть) сразу при загрузке
const initialActiveTab = document.querySelector('.dept-tab.active');
if (initialActiveTab) applyDeptFilter(initialActiveTab.dataset.dept);

function applyDeptFilter(dept) {
    document.querySelectorAll('.order-card').forEach(card => {
        const matches = (dept === 'all') || (card.dataset.department === dept);
        card.closest('.col-12').classList.toggle('d-none', !matches);
    });
    // Прячем секцию целиком (заголовок "В работе"/"Заказано"/"Оказано"),
    // если после фильтра в ней не осталось ни одной видимой карточки
    document.querySelectorAll('.order-section').forEach(section => {
        const anyVisible = Array.from(section.querySelectorAll('.col-12'))
            .some(c => !c.classList.contains('d-none'));
        section.classList.toggle('d-none', !anyVisible);
    });
}

// ── Убрать ещё не начатую услугу из плана — требует причину ────────────────
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.order-remove-btn');
    if (!btn) return;
    e.preventDefault();
    promptRemoveReason(btn);
});

function promptRemoveReason(btn) {
    const card = btn.closest('.order-card');
    const actionsEl = card.querySelector('.order-actions');
    if (actionsEl.querySelector('.remove-prompt-input')) return; // уже открыт

    const orderId = btn.dataset.orderId;
    const savedHtml = actionsEl.innerHTML;

    actionsEl.innerHTML =
        '<div class="d-flex align-items-center gap-2 remove-prompt w-100">'
        + '<input type="text" class="form-control form-control-lg remove-prompt-input" '
        +   'placeholder="Причина — почему не нужна">'
        + '<button type="button" class="btn btn-danger remove-prompt-confirm"><i class="bi bi-trash"></i></button>'
        + '<button type="button" class="btn btn-outline-secondary remove-prompt-cancel"><i class="bi bi-x-lg"></i></button>'
        + '</div>';

    const input = actionsEl.querySelector('.remove-prompt-input');
    input.focus();

    actionsEl.querySelector('.remove-prompt-cancel').addEventListener('click', () => {
        actionsEl.innerHTML = savedHtml;
    });
    actionsEl.querySelector('.remove-prompt-confirm').addEventListener('click', () => {
        const reason = input.value.trim();
        if (!reason) {
            showToast('Укажите причину', 'error');
            return;
        }
        ajaxPost('/supervisor/orders/' + orderId + '/remove', {reason: reason})
            .then(data => {
                if (!data.success) {
                    showToast(data.message, 'error');
                    return;
                }
                const wrapper = card.closest('.col-12');
                if (wrapper) wrapper.remove();
                showToast('Услуга убрана из плана', 'success');
                recountProgress();
            })
            .catch(() => showToast('Ошибка сети — попробуйте ещё раз', 'error'));
    });
}

// ── Правка времени по месту — тап на карандаш ───────────────────────────────
document.addEventListener('click', function(e) {
    const editBtn = e.target.closest('.time-edit-btn');
    if (editBtn) {
        e.preventDefault();
        const chip = editBtn.closest('.time-chip');
        openTimeEditor(chip);
        return;
    }
    const saveBtn = e.target.closest('.time-save-btn');
    if (saveBtn) {
        e.preventDefault();
        const chip = saveBtn.closest('.time-chip');
        saveTimeEdit(chip);
        return;
    }
    const cancelBtn = e.target.closest('.time-cancel-btn');
    if (cancelBtn) {
        e.preventDefault();
        const chip = cancelBtn.closest('.time-chip');
        closeTimeEditor(chip);
        return;
    }
});

function openTimeEditor(chip) {
    if (chip.querySelector('.time-edit-input')) return; // уже открыт
    const currentText = chip.querySelector('.time-value').textContent.trim();
    const currentTime = currentText !== '—' ? currentText : '';
    chip.dataset.editing = '1';
    chip.insertAdjacentHTML('beforeend',
        '<input type="time" class="form-control form-control-sm time-edit-input" value="' + currentTime + '">'
        + '<button class="time-edit-btn time-save-btn" title="Сохранить"><i class="bi bi-check-lg text-success"></i></button>'
        + '<button class="time-edit-btn time-cancel-btn" title="Отмена"><i class="bi bi-x-lg text-danger"></i></button>'
    );
    chip.querySelector('.time-value').classList.add('d-none');
    chip.querySelector('.time-edit-btn:not(.time-save-btn):not(.time-cancel-btn)').classList.add('d-none');
}

function closeTimeEditor(chip) {
    chip.querySelectorAll('.time-edit-input, .time-save-btn, .time-cancel-btn').forEach(el => el.remove());
    chip.querySelector('.time-value').classList.remove('d-none');
    const pencil = chip.querySelector('.time-edit-btn');
    if (pencil) pencil.classList.remove('d-none');
    delete chip.dataset.editing;
}

function saveTimeEdit(chip) {
    const input = chip.querySelector('.time-edit-input');
    const time = input.value;
    if (!time) { closeTimeEditor(chip); return; }

    const card = chip.closest('.order-card');
    const orderId = card.dataset.orderId;
    const field = chip.dataset.field;

    ajaxPost('/supervisor/orders/' + orderId + '/set-time', {field: field, time: time})
        .then(data => {
            if (!data.success) {
                showToast(data.message, 'error');
                return;
            }
            chip.querySelector('.time-value').textContent = time;
            showToast('Время обновлено', 'success');
            closeTimeEditor(chip);
        })
        .catch(() => showToast('Ошибка сети — попробуйте ещё раз', 'error'));
}

// ── Правка количества по месту — та же логика, что и у времени ─────────────
document.addEventListener('click', function(e) {
    const editBtn = e.target.closest('.qty-edit-btn');
    if (editBtn) {
        e.preventDefault();
        openQtyEditor(editBtn.closest('.qty-chip'));
        return;
    }
    const saveBtn = e.target.closest('.qty-save-btn');
    if (saveBtn) {
        e.preventDefault();
        saveQtyEdit(saveBtn.closest('.qty-chip'));
        return;
    }
    const cancelBtn = e.target.closest('.qty-cancel-btn');
    if (cancelBtn) {
        e.preventDefault();
        closeQtyEditor(cancelBtn.closest('.qty-chip'));
        return;
    }
});

function openQtyEditor(chip) {
    if (chip.querySelector('.qty-edit-input')) return;
    const current = chip.querySelector('.qty-value').textContent.trim();
    chip.insertAdjacentHTML('beforeend',
        '<input type="number" step="0.01" min="0.01" class="form-control form-control-sm qty-edit-input" style="width:80px;" value="' + current + '">'
        + '<button class="qty-edit-btn qty-save-btn" title="Сохранить"><i class="bi bi-check-lg text-success"></i></button>'
        + '<button class="qty-edit-btn qty-cancel-btn" title="Отмена"><i class="bi bi-x-lg text-danger"></i></button>'
    );
    chip.querySelector('.qty-value').classList.add('d-none');
    chip.querySelector('.qty-edit-btn:not(.qty-save-btn):not(.qty-cancel-btn)').classList.add('d-none');
}

function closeQtyEditor(chip) {
    chip.querySelectorAll('.qty-edit-input, .qty-save-btn, .qty-cancel-btn').forEach(el => el.remove());
    chip.querySelector('.qty-value').classList.remove('d-none');
    const pencil = chip.querySelector('.qty-edit-btn');
    if (pencil) pencil.classList.remove('d-none');
}

function saveQtyEdit(chip) {
    const input = chip.querySelector('.qty-edit-input');
    const qty = input.value;
    if (!qty || parseFloat(qty) <= 0) { showToast('Количество должно быть больше нуля', 'error'); return; }

    const orderId = chip.dataset.orderId;

    ajaxPost('/supervisor/orders/' + orderId + '/set-quantity', {quantity: qty})
        .then(data => {
            if (!data.success) {
                showToast(data.message, 'error');
                return;
            }
            chip.querySelector('.qty-value').textContent = qty;
            showToast('Количество обновлено', 'success');
            closeQtyEditor(chip);
        })
        .catch(() => showToast('Ошибка сети — попробуйте ещё раз', 'error'));
}
</script>
