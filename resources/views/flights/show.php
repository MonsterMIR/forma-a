<?php use App\Core\Permissions; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">
        <i class="bi bi-airplane-fill me-2"></i>
        Рейс: <?= e($flight['flight_number']) ?>
        <span class="badge <?= $flight['operation_type'] === 'arrival' ? 'bg-info text-dark' : 'bg-warning text-dark' ?> ms-2">
            <?= $flight['operation_type'] === 'arrival' ? '↓ Прилёт' : '↑ Вылет' ?>
        </span>
    </h2>
    <div class="d-flex gap-2">
        <?php $isLocked = $existingAct && in_array($existingAct['status'], \App\Models\Act::LOCKED_STATUSES, true); ?>
        <?php if (Permissions::can('flights.edit') && !$isLocked): ?>
        <a href="/flights/<?= (int)$flight['id'] ?>/edit" class="btn btn-outline-primary">
            <i class="bi bi-pencil"></i> Изменить
        </a>
        <?php elseif ($isLocked): ?>
        <span class="btn btn-outline-secondary disabled" title="<?= e(__('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($existingAct['status']))])) ?>">
            <i class="bi bi-lock"></i> Акт <?= e(__('ACTS_STATUS_' . strtoupper($existingAct['status']))) ?> — рейс заблокирован
        </span>
        <?php endif; ?>
        <a href="/flights" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Назад
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Основная информация -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-2"></i>Данные рейса
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Номер рейса</dt>
                    <dd class="col-sm-7 fw-bold fs-5"><?= e($flight['flight_number']) ?></dd>

                    <dt class="col-sm-5">Дата</dt>
                    <dd class="col-sm-7"><?= e($flight['flight_date']) ?></dd>

                    <dt class="col-sm-5">Плановое время (STD/STA)</dt>
                    <dd class="col-sm-7">
                        <?php if ($flight['scheduled_time']): ?>
                            <span class="badge bg-primary fs-6"><?= e($flight['scheduled_time']) ?></span>
                        <?php else: ?>
                            <span class="text-danger small">
                                <i class="bi bi-exclamation-circle me-1"></i>Не указано
                                <?php if (Permissions::can('flights.edit')): ?>
                                — <a href="/flights/<?= (int)$flight['id'] ?>/edit">добавить</a>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </dd>

                    <?php
                        // ИЗМЕНЕНО (23.09.2026, раунд 52): раньше здесь показывался
                        // мёртвый флаг 'actual_time' (ATD/ATA), который никто не
                        // заполнял осмысленно — форма редактирования его больше не
                        // собирает (см. FlightService/flights/edit.php). Вместо
                        // него показываем реальное фактическое время по операции
                        // этого рейса (arr_time/dep_time) и время колодок — те же
                        // поля, что уходят в печатную Форму А (acts/print.php) и
                        // проверяются при формировании акта (FlightController::
                        // buildAct()), но раньше вообще не выводились на этой
                        // странице.
                        $isArrivalShow = $flight['operation_type'] === 'arrival';
                        $factLabel     = $isArrivalShow ? 'Фактическое время прилёта (ATA)' : 'Фактическое время вылета (ATD)';
                        $factTime      = $isArrivalShow ? ($flight['arr_time'] ?? null) : ($flight['dep_time'] ?? null);
                        $chocksLabel   = $isArrivalShow ? 'Время установки колодок' : 'Время уборки колодок';
                        $chocksTime    = $isArrivalShow ? ($flight['chocks_on'] ?? null) : ($flight['chocks_off'] ?? null);
                    ?>
                    <dt class="col-sm-5"><?= e($factLabel) ?></dt>
                    <dd class="col-sm-7">
                        <?php if ($factTime): ?>
                            <span class="badge bg-success fs-6"><?= e($factTime) ?></span>
                        <?php else: ?>
                            <span class="text-danger small">
                                <i class="bi bi-exclamation-circle me-1"></i>Не указано
                                <?php if (Permissions::can('flights.edit')): ?>
                                — <a href="/flights/<?= (int)$flight['id'] ?>/edit">добавить</a>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-5"><?= e($chocksLabel) ?></dt>
                    <dd class="col-sm-7">
                        <?= $chocksTime ? e($chocksTime) : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-5">Авиакомпания</dt>
                    <dd class="col-sm-7">
                        <?= e($flight['airline_name']) ?>
                        <?php if ($flight['airline_iata']): ?>
                        <span class="badge bg-light text-dark border ms-1"><?= e($flight['airline_iata']) ?></span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-5">Маршрут</dt>
                    <dd class="col-sm-7">
                        <?php if ($flight['operation_type'] === 'arrival'): ?>
                            <?= e($flight['origin_name'] ?? '—') ?>
                            <?= $flight['origin_iata'] ? '(' . e($flight['origin_iata']) . ')' : '' ?>
                            → <strong>TVT</strong>
                        <?php else: ?>
                            <strong>TVT</strong> →
                            <?= e($flight['destination_name'] ?? '—') ?>
                            <?= $flight['destination_iata'] ? '(' . e($flight['destination_iata']) . ')' : '' ?>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-5">Тип маршрута</dt>
                    <dd class="col-sm-7">
                        <?php if ($flight['route_type'] === 'domestic'): ?>
                            <span class="badge bg-info text-dark">Внутренний</span>
                        <?php else: ?>
                            <span class="badge bg-primary">Международный</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-5">Тип ВС</dt>
                    <dd class="col-sm-7"><?= e($flight['aircraft_type'] ?? '—') ?></dd>

                    <dt class="col-sm-5">MTOW</dt>
                    <dd class="col-sm-7">
                        <?= $flight['aircraft_mtow'] ? e($flight['aircraft_mtow']) . ' т' : '—' ?>
                    </dd>

                    <dt class="col-sm-5">Рег. номер ВС</dt>
                    <dd class="col-sm-7"><?= e($flight['aircraft_registration'] ?? '—') ?></dd>

                    <?php
                        // ДОБАВЛЕНО (18.08.2026, раунд 19): номер стоянки (MC№) до сих
                        // пор нигде не отображался на обычной странице просмотра рейса —
                        // его можно было увидеть только у того, кто сам его вводит (форма
                        // ЦОК, планшет супервайзера, акт аэродромной службы). Остальные
                        // роли (СОП, финансы, коммерческая служба и т.д.), которым нужно
                        // просто ПОСМОТРЕТЬ, куда поставили борт, такой возможности не
                        // имели. Показываем то же самое поле flights.mc_number, которое
                        // печатается в акте формы А — без права редактирования здесь.
                    ?>
                    <dt class="col-sm-5">Стоянка (МС№)</dt>
                    <dd class="col-sm-7">
                        <?php if (!empty($flight['mc_number'])): ?>
                        <span class="badge bg-secondary fs-6"><?= e($flight['mc_number']) ?></span>
                        <?php else: ?>
                        <span class="text-muted">— не указана —</span>
                        <?php endif; ?>
                    </dd>

                    <?php
                        // ДОБАВЛЕНО (раунд 33, 09.09.2026): связка рейса прилёта и рейса
                        // вылета одного и того же борта на одной стоянке (визит ВС) — по
                        // прямому указанию пользователя ("самолет между прилетом и вылетом
                        // занимает конкретную стоянку, башмаки установлены в одном акте,
                        // а снимаются в другом"). См. FlightService::linkFlights().
                        //
                        // ИЗМЕНЕНО (раунд 40, 14.09.2026, по прямому указанию пользователя):
                        // связывание раньше требовало ручного выбора рейса-пары из списка
                        // ("Связать"). Убрано — борт не может между прилётом и вылетом сменить
                        // регистрацию, поэтому пара определяется однозначно и система связывает
                        // её сама при создании/редактировании рейса (FlightService::autoLinkFlight()).
                        // Оставлена только "Отвязать" — на случай ошибочной связи (например,
                        // опечатка в бортовом номере).
                    ?>
                    <dt class="col-sm-5">Связка прилёт⇄вылет</dt>
                    <dd class="col-sm-7">
                        <?php if (!empty($linkedFlight)): ?>
                            <a href="/flights/<?= (int)$linkedFlight['linked_flight_id'] ?>" class="fw-semibold text-decoration-none">
                                <?= $linkedFlight['linked_operation_type'] === 'arrival' ? '↓' : '↑' ?>
                                <?= e($linkedFlight['linked_flight_number']) ?> (<?= e($linkedFlight['linked_flight_date']) ?>)
                            </a>
                            <?php if (!empty($linkedFlight['linked_act_id'])): ?>
                                — акт <a href="/acts/<?= (int)$linkedFlight['linked_act_id'] ?>"><?= e($linkedFlight['linked_act_number']) ?></a>
                            <?php endif; ?>
                            <?php if (Permissions::can('flights.edit')): ?>
                            <form method="POST" action="/flights/<?= (int)$flight['id'] ?>/unlink" class="d-inline ms-2" onsubmit="return confirm('Снять привязку рейсов?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-link-45deg"></i> Отвязать</button>
                            </form>
                            <?php endif; ?>
                        <?php elseif (count($linkCandidates ?? []) > 1): ?>
                            <span class="text-muted">— не привязан —</span>
                            <div class="form-text text-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                Найдено несколько непривязанных рейсов того же борта (<?= e($flight['aircraft_registration']) ?>)
                                — система не может однозначно определить пару
                                автоматически:
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($linkCandidates as $c): ?>
                                    <li>
                                        <a href="/flights/<?= (int)$c['id'] ?>">
                                            <?= $c['operation_type'] === 'arrival' ? '↓ Прилёт' : '↑ Вылет' ?>
                                            <?= e($c['flight_number']) ?> — <?= e($c['flight_date']) ?>
                                        </a>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                Уточните нужную пару через форму «Связать вручную…» ниже.
                            </div>
                        <?php else: ?>
                            <span class="text-muted">— не привязан —</span>
                            <?php if (empty($flight['aircraft_registration'])): ?>
                            <div class="form-text text-warning"><i class="bi bi-exclamation-triangle"></i> Укажите рег. номер ВС, чтобы система могла найти и связать пару автоматически.</div>
                            <?php /* ДОБАВЛЕНО (21.10.2026, раунд 109, по прямому указанию пользователя):
                                     подсказка по истории стоянки — ТОЛЬКО подсказка человеку, не
                                     автоматическая связка (стоянки переиспользуются разными бортами,
                                     см. Flight::findLastFlightOnStand()). */ ?>
                            <?php if (!empty($standHint)): ?>
                            <div class="form-text">
                                <i class="bi bi-info-circle"></i>
                                Подсказка: на стоянке <?= e($flight['mc_number']) ?> последним (до этого рейса) числился борт
                                <strong><?= e($standHint['aircraft_registration']) ?></strong>
                                — рейс <a href="/flights/<?= (int)$standHint['id'] ?>"><?= e($standHint['flight_number']) ?></a>
                                от <?= e($standHint['flight_date']) ?> (<?= $standHint['operation_type'] === 'arrival' ? 'прилёт' : 'вылет' ?>).
                                Возможно, это тот же борт — проверьте перед вводом номера.
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <div class="form-text">Тот же борт (<?= e($flight['aircraft_registration']) ?>) — рейс противоположного плеча ещё не создан в системе. Свяжется автоматически, как только появится.</div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php
                            // ДОБАВЛЕНО (12.10.2026, раунд 69, по прямому указанию пользователя):
                            // ручная связка восстановлена как предохранительный клапан — для
                            // случаев, когда авто-подбор не может помочь (рейс уже ошибочно
                            // связан с другим, напр. с неотработанной "заглушкой", и поэтому
                            // не попадает в findLinkCandidates() ни для себя, ни для настоящей
                            // пары). См. FlightController::link() / FlightService::linkFlights() —
                            // при связывании старая связь на любой из сторон снимается
                            // автоматически. Доступно всегда (не только когда "не привязан"),
                            // чтобы можно было поправить и уже существующую, но неверную связь.
                        ?>
                        <?php if (Permissions::can('flights.edit')): ?>
                        <details class="mt-2">
                            <summary class="small text-primary" style="cursor:pointer">Связать вручную…</summary>
                            <form method="POST" action="/flights/<?= (int)$flight['id'] ?>/link" class="d-flex gap-2 align-items-center mt-2"
                                  onsubmit="return confirm('Связать этот рейс с указанным рейсом? Если у любого из них уже есть связь — она будет снята.');">
                                <?= csrf_field() ?>
                                <input type="number" name="target_flight_id" class="form-control form-control-sm" style="width:120px"
                                       placeholder="ID рейса" min="1" required>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Связать</button>
                            </form>
                            <div class="form-text">
                                Укажите ID рейса-пары (виден в адресе страницы рейса, /flights/<em>ID</em>).
                                Связать можно только рейс прилёта с рейсом вылета того же борта (совпадающий рег. номер ВС).
                            </div>
                        </details>
                        <?php endif; ?>
                    </dd>

                    <?php if ($flight['perm_num']): ?>
                    <dt class="col-sm-5">№ Разрешения</dt>
                    <dd class="col-sm-7 font-monospace small"><?= e($flight['perm_num']) ?></dd>
                    <?php endif; ?>

                    <?php if ($flight['notes']): ?>
                    <dt class="col-sm-5">Примечания</dt>
                    <dd class="col-sm-7 text-muted"><?= e($flight['notes']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <!-- Акт по рейсу (краткая карточка — подробности и формирование в блоке "Форма А" ниже) -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <span class="fw-semibold"><i class="bi bi-file-earmark-check me-2"></i>Акт (Форма А)</span>
            </div>
            <div class="card-body">
                <?php if (empty($existingAct)): ?>
                <div class="text-center text-muted py-3">
                    <i class="bi bi-file-earmark fs-2 d-block mb-2"></i>
                    Акт ещё не сформирован
                    <div class="small mt-1">См. блок «Форма А» ниже для формирования</div>
                </div>
                <?php else: ?>
                <a href="/acts/<?= (int)$existingAct['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center border rounded p-2 text-decoration-none">
                    <span class="fw-semibold"><?= e(act_display_number($existingAct)) ?></span>
                    <span class="badge bg-<?= $existingAct['status'] === 'approved' ? 'success' : ($existingAct['status'] === 'draft' ? 'warning text-dark' : ($existingAct['status'] === 'exported' ? 'primary' : 'danger')) ?>">
                        <?= match($existingAct['status']) {
                            'draft'=>'Черновик','approved'=>'Утверждён','exported'=>'Экспортирован','cancelled'=>'Отменён',
                            default => $existingAct['status']
                        } ?>
                    </span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ДОБАВЛЕНО (03.08.2026): предзаказ услуг убран со страницы просмотра —
     это режим "посмотреть", не "спланировать". Планирование теперь
     живёт на форме создания (/flights/create) и редактирования
     (/flights/{id}/edit), где ему логически место. -->

<!-- Документы служб -->
<?php include __DIR__ . '/_service_docs.php'; ?>

<!-- Форма А: сводка услуг и формирование акта -->
<?php include __DIR__ . '/_act_summary.php'; ?>

<!-- Блок контроля сроков -->
<?php include __DIR__ . '/_deadlines.php'; ?>