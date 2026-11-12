<?php
/** @var array $o Текущая позиция плана (из родительского foreach) */
/** @var array $deptLabels */
/** @var array $flight Родительская переменная — рейс, на котором стоит карточка */
$isFuel = ($o['service_code'] ?? '') === 'FUEL_SVC';
// ДОБАВЛЕНО (19.08.2026, раунд 21, подтверждено пользователем): колодки
// (CHOCKS) — не обычная услуга с полным циклом "Начать/Завершить" в
// пределах ОДНОГО акта. Физически это одно событие на каждом плече: на
// ПРИЛЁТЕ их только УСТАНАВЛИВАЮТ (одно действие), на ВЫЛЕТЕ — только
// УБИРАЮТ (тоже одно действие). Раньше здесь была обычная пара кнопок
// "Начать"/"Завершить", что позволяло (и требовало) отмечать оба события
// на КАЖДОМ из двух актов — по словам пользователя, это концептуально
// неверно: "колодки не могут быть установлены и сняты в одном акте
// прилёта или вылета". Технически ничего не меняем в хранении (та же
// таблица flight_service_orders, то же действие 'complete' — оно уже само
// подставляет started_at, если он ещё не был проставлен, см.
// ServiceOrderController::updateTiming()) — меняем только то, что
// показываем: одна кнопка вместо двух, подпись зависит от типа операции.
$isChocks = ($o['service_code'] ?? '') === 'CHOCKS';
$chocksLabel = ($flight['operation_type'] ?? '') === 'departure'
    ? 'Колодки убраны' : 'Колодки установлены';
// ДОБАВЛЕНО (11.09.2026, раунд 38): по указанию пользователя — колодки это
// "услуга по умолчанию", на прилёте это ПОСТАНОВКА, на вылете — СНЯТИЕ.
// Заголовок карточки раньше всегда показывал общее название услуги из
// справочника ("Установка/уборка колодок") независимо от плеча рейса —
// теперь название в заголовке тоже зависит от operation_type, как и кнопка
// ниже (см. $chocksLabel), вместо общей формулировки.
$chocksTitle = ($flight['operation_type'] ?? '') === 'departure'
    ? 'Снятие колодок' : 'Постановка колодок';
// ДОБАВЛЕНО (19.08.2026, раунд 21, подтверждено пользователем): часть услуг
// (сейчас — МЕТЕО) физически не имеет процесса "начали → идёт → закончили"
// — это просто факт оказания в моменте, у него нет ни старта, ни
// завершения. Признак `services.no_timing` (sql/round21_no_timing_column.sql)
// — та же кнопка "Оказано", что и раньше при "Завершить", но без
// промежуточного шага "Начать"/статуса "в работе". Колодки (см. $isChocks
// выше) — отдельный случай той же природы, но с другой подписью и логикой
// плеча рейса, поэтому проверяется первым.
$isNoTiming = !empty($o['no_timing']);
$deptLabel = $deptLabels[$o['owner_role']] ?? e((string)$o['owner_role']);
$startedTime   = $o['started_at']   ? date('H:i', strtotime($o['started_at']))   : null;
$completedTime = $o['completed_at'] ? date('H:i', strtotime($o['completed_at'])) : null;
$requiresQty   = !empty($o['requires_manual_quantity']);
$defaultQty    = $o['default_quantity'] ?? 1;
// fact_quantity — реальное количество, если услуга уже подтверждена (JOIN на flight_service_charges)
$factQty       = $o['fact_quantity'] ?? null;
// ДОБАВЛЕНО (29.09.2026, раунд 64, по прямому указанию пользователя): для
// услуг с services.quantity_from_timing=1 (сейчас — GPU, AIRCOND) количество
// (часы) больше не вводится вручную — оно считается сервером из
// started_at/completed_at (см. ServiceOrderController::calcQuantityFromTiming()).
// Чип с количеством при этом всё равно нужно показывать (иначе супервайзер
// не увидит, сколько часов насчиталось) — раньше чип показывался только при
// $requiresQty, поэтому его условие ниже расширено.
$quantityFromTiming = !empty($o['quantity_from_timing']);
?>
<div class="card order-card status-<?= e($o['status']) ?>" data-order-id="<?= (int)$o['id'] ?>"
     data-service-code="<?= e($o['service_code'] ?? '') ?>"
     data-requires-qty="<?= $requiresQty ? '1' : '0' ?>"
     data-quantity-from-timing="<?= $quantityFromTiming ? '1' : '0' ?>"
     data-default-qty="<?= e((string)$defaultQty) ?>"
     data-unit="<?= e($o['unit_name'] ?? '') ?>"
     data-no-timing="<?= $isNoTiming ? '1' : '0' ?>"
     data-department="<?= e($o['owner_role'] ?? '') ?>">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <div class="fs-5 fw-semibold">
                <?= $isFuel ? '<i class="bi bi-fuel-pump-fill me-1 text-primary"></i>' : '' ?><?= e($isChocks ? $chocksTitle : $o['service_name']) ?>
            </div>
            <div class="text-muted small d-flex align-items-center gap-2 flex-wrap mt-1">
                <span><?= e($deptLabel) ?></span>

                <?php /* ИЗМЕНЕНО (19.08.2026, раунд 21): для CHOCKS/no_timing нет
                         отдельного "начала" как события — не показываем чип
                         started_at, только момент оказания (completed_at ниже). */ ?>
                <?php if ($o['status'] !== 'planned' && !$isChocks && !$isNoTiming): ?>
                <span class="time-chip" data-field="started_at">
                    <i class="bi bi-play-circle"></i>
                    <span class="time-value" data-field="started_at"><?= $startedTime ?? '—' ?></span>
                    <?php if ($canAct ?? false): ?><button type="button" class="time-edit-btn" title="Изменить время начала"><i class="bi bi-pencil-fill" style="font-size:.7rem;"></i></button><?php endif; ?>
                </span>
                <?php endif; ?>

                <?php if ($o['status'] === 'done'): ?>
                <span class="time-chip" data-field="completed_at">
                    <i class="bi bi-stop-circle"></i>
                    <span class="time-value" data-field="completed_at"><?= $completedTime ?? '—' ?></span>
                    <?php if ($canAct ?? false): ?><button type="button" class="time-edit-btn" title="Изменить время окончания"><i class="bi bi-pencil-fill" style="font-size:.7rem;"></i></button><?php endif; ?>
                </span>
                <?php endif; ?>

                <?php /* ИЗМЕНЕНО (29.09.2026, раунд 64): чип количества теперь
                         показывается и для услуг с quantity_from_timing=1 —
                         у них тоже есть значащее "количество" (часы,
                         посчитанные сервером), просто оно не вводилось
                         вручную. */ ?>
                <?php if ($o['status'] === 'done' && ($requiresQty || $quantityFromTiming) && !$isFuel): ?>
                <span class="qty-chip" data-order-id="<?= (int)$o['id'] ?>">
                    <i class="bi bi-123"></i>
                    <span class="qty-value"><?= e(rtrim(rtrim(number_format((float)$factQty, 2, '.', ''), '0'), '.')) ?></span>
                    <span class="text-muted"><?= e($o['unit_name'] ?? '') ?></span>
                    <?php if ($canAct ?? false): ?><button type="button" class="qty-edit-btn" title="Изменить количество"><i class="bi bi-pencil-fill" style="font-size:.7rem;"></i></button><?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="badge order-status-badge fs-6 px-3 py-2 bg-<?= $o['status'] === 'done' ? 'success' : ($o['status'] === 'in_progress' ? 'warning text-dark' : 'secondary') ?>">
                <?= match($o['status']) {
                    'done' => 'Оказано',
                    'in_progress' => 'В работе',
                    'planned' => 'Заказано',
                    'cancelled' => 'Отменено',
                    default => $o['status'],
                } ?>
            </span>

            <div class="order-actions d-flex gap-2">
                <?php if (!($canAct ?? false)): ?>
                    <span class="text-muted small fst-italic">
                        <?= $o['status'] === 'done' ? 'Подтверждено супервайзером' : ($o['status'] === 'in_progress' ? 'Выполняется' : 'Ожидает') ?>
                    </span>
                <?php elseif ($isFuel && $o['status'] === 'cancelled'): ?>
                    <?php /* ДОБАВЛЕНО (30.10.2026, раунд 141): до этого раунда
                             ветка $isFuel ниже показывала кнопку "Внести данные
                             заправки" ДЛЯ ЛЮБОГО статуса, включая 'cancelled' —
                             она не проверяла статус вообще, только done/не-done
                             для текста кнопки. После раунда 140 (кнопка "Убрать
                             из плана" у топлива) карточка могла реально оказаться
                             в статусе 'cancelled' — и эта живая кнопка позволяла
                             тихо "воскресить" отклонённую дозаправку, просто
                             заполнив форму (саму форму это тоже не проверяло,
                             см. фикс в ServiceOrderController::fuelForm()/
                             saveFuel() тем же раундом). Теперь для cancelled —
                             нейтральная подпись без единой кнопки, как и у
                             карточек остальных услуг в этом статусе. */ ?>
                    <span class="text-muted small fst-italic">Отменено</span>
                <?php elseif ($isFuel): ?>
                    <a href="/supervisor/orders/<?= (int)$o['id'] ?>/fuel"
                       class="btn <?= $o['status'] === 'done' ? 'btn-outline-primary' : 'btn-primary' ?>">
                        <i class="bi bi-fuel-pump-fill"></i>
                        <?= $o['status'] === 'done' ? 'Изменить данные заправки' : 'Внести данные заправки' ?>
                    </a>
                    <?php /* ДОБАВЛЕНО (26.10.2026, раунд 126, по прямому указанию
                             пользователя — "почти все услуги можно дозаказать,
                             прошляпили именно дозаправку"): топливо (FUEL_SVC,
                             services.allow_repeat=1 уже стоит в БД) обслуживается
                             этим отдельным веткой-ссылкой на форму заправки (а не
                             общей логикой ниже, см. блок "elseif status==='done'"),
                             поэтому до этого раунда кнопка "Заказать ещё раз" для
                             топлива физически никогда не отрисовывалась — ветка
                             $isFuel перехватывала карточку раньше, чем код доходил
                             до общего done-блока с этой кнопкой. Сама форма заправки
                             правит ОДНУ конкретную позицию плана (см. исправление
                             order_id в ServiceOrderController::fuelForm()/saveFuel()
                             тем же раундом) — для ВТОРОЙ дозаправки нужна ВТОРАЯ
                             позиция плана, вот эта кнопка её и создаёт, тем же
                             механизмом, что и у всех остальных allow_repeat-услуг. */ ?>
                    <?php if ($o['status'] === 'done' && !empty($o['allow_repeat']) && empty($openServiceIds[(int)$o['service_id']])): ?>
                    <form method="post" action="/supervisor/flights/<?= (int)$flight['id'] ?>/add-service" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="service_id" value="<?= (int)$o['service_id'] ?>">
                        <button type="submit" class="btn btn-outline-primary" title="Ещё одна дозаправка — оплачивается отдельно">
                            <i class="bi bi-arrow-repeat"></i> Дозаправить ещё раз
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php /* ДОБАВЛЕНО (30.10.2026, раунд 140, по жалобе
                             пользователя: "супервайзер заказав услугу заправка
                             воздушного судна при отказе услуги не может
                             отменить как другие услуги в таком случае"): ветка
                             $isFuel перехватывает карточку раньше общих
                             planned/in_progress/done блоков ниже — у них у всех
                             есть кнопка "Убрать из плана" (order-remove-btn) для
                             статуса 'planned', а у топлива её не было вообще, в
                             любом статусе. Backend (removePlanned()) уже
                             одинаково работает для любой услуги со
                             status='planned' — ограничения не было в контроллере,
                             только в вёрстке. Показываем ту же кнопку, что и у
                             остальных услуг, только пока заправка ещё НЕ внесена
                             (planned) — если данные уже внесены (done), отмена
                             делается через "Отменить"/сброс формы заправки, а не
                             через удаление позиции плана. */ ?>
                    <?php if ($o['status'] === 'planned'): ?>
                    <button class="btn btn-outline-danger order-remove-btn" data-order-id="<?= (int)$o['id'] ?>" title="Убрать из плана — не нужна">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                <?php elseif ($o['status'] === 'planned' && $isChocks): ?>
                    <button class="btn btn-primary order-action-btn" data-order-id="<?= (int)$o['id'] ?>" data-action="complete">
                        <i class="bi bi-check-lg"></i> <?= e($chocksLabel) ?>
                    </button>
                    <button class="btn btn-outline-secondary order-edit-btn" data-order-id="<?= (int)$o['id'] ?>" title="Редактировать время вручную">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-outline-danger order-remove-btn" data-order-id="<?= (int)$o['id'] ?>" title="Убрать из плана — не нужна">
                        <i class="bi bi-trash"></i>
                    </button>
                <?php elseif ($o['status'] === 'planned' && $isNoTiming): ?>
                    <button class="btn btn-primary order-action-btn" data-order-id="<?= (int)$o['id'] ?>" data-action="complete">
                        <i class="bi bi-check-lg"></i> Оказано
                    </button>
                    <button class="btn btn-outline-danger order-remove-btn" data-order-id="<?= (int)$o['id'] ?>" title="Убрать из плана — не нужна">
                        <i class="bi bi-trash"></i>
                    </button>
                <?php elseif ($o['status'] === 'planned'): ?>
                    <button class="btn btn-primary order-action-btn" data-order-id="<?= (int)$o['id'] ?>" data-action="start">
                        <i class="bi bi-play-fill"></i> Начать
                    </button>
                    <button class="btn btn-outline-secondary order-edit-btn" data-order-id="<?= (int)$o['id'] ?>" title="Редактировать время вручную">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-outline-danger order-remove-btn" data-order-id="<?= (int)$o['id'] ?>" title="Убрать из плана — не нужна">
                        <i class="bi bi-trash"></i>
                    </button>
                <?php elseif ($o['status'] === 'in_progress'): ?>
                    <button class="btn btn-success order-action-btn" data-order-id="<?= (int)$o['id'] ?>" data-action="complete">
                        <i class="bi bi-check-lg"></i> Завершить
                    </button>
                    <button class="btn btn-outline-secondary order-edit-btn" data-order-id="<?= (int)$o['id'] ?>" title="Редактировать время вручную">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-outline-secondary order-action-btn" data-order-id="<?= (int)$o['id'] ?>" data-action="reset" title="Сбросить">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                <?php elseif ($o['status'] === 'done'): ?>
                    <button class="btn btn-outline-secondary order-edit-btn" data-order-id="<?= (int)$o['id'] ?>" title="Редактировать время/количество">
                        <i class="bi bi-pencil"></i> Редактировать
                    </button>
                    <button class="btn btn-outline-secondary order-action-btn" data-order-id="<?= (int)$o['id'] ?>" data-action="reset">
                        <i class="bi bi-arrow-counterclockwise"></i> Отменить
                    </button>
                    <?php /* ДОБАВЛЕНО (25.09.2026, раунд 58, по прямому указанию
                             пользователя): часть услуг (services.allow_repeat=1 —
                             см. экран "Услуги") может быть оказана на одном рейсе
                             несколько раз, каждый раз отдельно оплачивается.
                             Кнопка видна только если по этой же услуге на рейсе
                             СЕЙЧАС нет другого открытого (заказано/в работе)
                             экземпляра — см. $openServiceIds в
                             ServiceOrderController::supervisorFlight(). Обычный
                             POST на тот же маршрут, что и "Добавить услугу"
                             (ServiceOrderController::addUnplanned()) — заводит
                             новую позицию плана, полная перезагрузка страницы,
                             т.к. появляется целая новая карточка, а не меняется
                             текущая. */ ?>
                    <?php if (!empty($o['allow_repeat']) && empty($openServiceIds[(int)$o['service_id']])): ?>
                    <form method="post" action="/supervisor/flights/<?= (int)$flight['id'] ?>/add-service" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="service_id" value="<?= (int)$o['service_id'] ?>">
                        <button type="submit" class="btn btn-outline-primary" title="Услугу можно оказать повторно — оплачивается отдельно">
                            <i class="bi bi-arrow-repeat"></i> Заказать ещё раз
                        </button>
                    </form>
                    <?php endif; ?>
                <?php elseif ($o['status'] === 'cancelled'): ?>
                    <?php /* ДОБАВЛЕНО (12.11.2026, раунд 173, по жалобе супервайзеров:
                             заказали автобус для пассажиров, отказались, он снова
                             понадобился — и дозаказать было нельзя): раньше для
                             статуса 'cancelled' у НЕ-топливных услуг не было вообще
                             ни одной ветки в этой цепочке if/elseif — блок действий
                             оставался полностью пустым (ни подписи "Отменено", ни
                             кнопки), а услуга пропадала и из выпадающего списка
                             "Добавить услугу" (т.к. supervisorFlight() исключает её
                             оттуда по самому факту наличия строки плана, независимо
                             от статуса) — то есть отменённую услугу было физически
                             неоткуда дозаказать. Решение то же, что уже работает для
                             топлива (round126/140) и для 'done' выше (round58): если
                             у услуги allow_repeat=1 и сейчас нет другого открытого
                             (planned/in_progress) экземпляра — показываем ту же кнопку
                             "Заказать ещё раз", создающую НОВУЮ позицию плана (история
                             отмены в старой записи не трогается, видна как есть). Для
                             услуг без allow_repeat — только нейтральная подпись
                             "Отменено", без кнопки (как и раньше для топлива). */ ?>
                    <span class="text-muted small fst-italic me-2">Отменено</span>
                    <?php if (!empty($o['allow_repeat']) && empty($openServiceIds[(int)$o['service_id']])): ?>
                    <form method="post" action="/supervisor/flights/<?= (int)$flight['id'] ?>/add-service" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="service_id" value="<?= (int)$o['service_id'] ?>">
                        <button type="submit" class="btn btn-outline-primary" title="Услугу можно заказать повторно — оплачивается отдельно">
                            <i class="bi bi-arrow-repeat"></i> Заказать ещё раз
                        </button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
