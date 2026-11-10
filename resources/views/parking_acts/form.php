<?php
$v = fn(string $f) => htmlspecialchars((string)($act[$f] ?? $_POST[$f] ?? ''));
$checked = fn(string $f) => (!empty($act[$f]) || !empty($_POST[$f])) ? 'checked' : '';
?>
<?php if (!empty($lockStatus)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5"></i>
    <div><?= e(__('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))])) ?></div>
</div>
<?php endif; ?>
<?php include BASE_PATH . '/resources/views/partials/pending_orders_banner.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><i class="bi bi-p-square me-2"></i>Акт готовности стоянки ВС</h2>
        <div class="text-muted small">
            Рейс: <strong><?= e($flight['flight_number']) ?></strong>
            · <?= e($flight['flight_date']) ?>
            · <?= e($flight['airline_name']) ?>
        </div>
    </div>
    <a href="/flights/<?= (int)$flight['id'] ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> К рейсу
    </a>
</div>

<?php if (!empty($act) && $act['status']==='submitted'): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle me-2"></i>Акт подан <?= e($act['submitted_at'] ?? '') ?>
</div>
<?php endif; ?>

<form method="POST" action="/parking-acts/save">
    <?= csrf_field() ?>
    <input type="hidden" name="flight_id" value="<?= (int)$flight['id'] ?>">

    <div class="card border-0 shadow-sm mb-4" style="max-width:640px">
        <div class="card-body row g-3">

            <div class="col-md-6">
                <label class="form-label fw-semibold">Номер стоянки</label>
                <?php
                    // ИСПРАВЛЕНО (18.08.2026, раунд 17): раньше это было отдельное
                    // текстовое поле в parking_acts.stand_number, никак не связанное
                    // ни со справочником стоянок (раунд 16), ни с полем flights.
                    // mc_number, которое реально печатается в акте формы А — Аэродромная
                    // служба здесь может первой достоверно знать, куда поставили борт
                    // (это их акт готовности стоянки), но их ввод раньше никуда, кроме
                    // этой формы, не попадал. Теперь — тот же справочник и тот же
                    // выпадающий список, что у ЦОК и супервайзера; при сохранении
                    // (ParkingActController::save()) значение синхронизируется в
                    // flights.mc_number, если оно там ещё не указано более свежим вводом.
                    // ДОПОЛНЕНО (18.08.2026, раунд 19): синхронизация была односторонней
                    // (сюда → flights.mc_number), но не в обратную сторону — если ЦОК
                    // или супервайзер уже указали стоянку ДО того, как аэродромная
                    // служба открыла этот акт, поле показывалось пустым и заставляло
                    // вводить стоянку заново. Теперь при отсутствии собственного
                    // значения акта подставляется уже известный flights.mc_number —
                    // aero видит и может подтвердить/поправить то, что уже известно,
                    // а не начинает с чистого листа.
                    $currentStand   = $act['stand_number'] ?? $flight['mc_number'] ?? ($_POST['stand_number'] ?? '');
                    $standCodes     = array_map(fn($s) => $s['code'], $parkingStands ?? []);
                    $currentIsKnown = $currentStand === '' || in_array($currentStand, $standCodes, true);
                ?>
                <select name="stand_number" class="form-select">
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
                <?php if (empty($parkingStands)): ?>
                <div class="form-text">Нет свободных стоянок (все заняты, либо справочник пуст — Справочники → Стоянки)</div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Время проверки</label>
                <input type="datetime-local" name="checked_at" class="form-control"
                       value="<?= $v('checked_at') ?>">
            </div>
            <div class="col-12"><hr class="my-1">
                <small class="text-uppercase text-muted fw-bold">Фактическое время стоянки ВС (для расчёта OVER_PARK)</small>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Прибытие на стоянку</label>
                <input type="datetime-local" name="aircraft_arrived_at" class="form-control"
                       value="<?= $v('aircraft_arrived_at') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Убытие со стоянки</label>
                <input type="datetime-local" name="aircraft_departed_at" class="form-control"
                       value="<?= $v('aircraft_departed_at') ?>">
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold">Инспектор аэродромной службы</label>
                <input type="text" name="inspector_name" class="form-control"
                       value="<?= $v('inspector_name') ?>" placeholder="ФИО">
            </div>

            <div class="col-12"><hr class="my-1"><strong class="small text-uppercase text-muted">Контрольные пункты</strong></div>

            <?php
            $checks = [
                'runway_clear' => 'Рулёжные дорожки и перрон свободны',
                'lighting_ok'  => 'Освещение стоянки исправно',
                'markings_ok'  => 'Разметка стоянки в норме',
                'fod_clear'    => 'Посторонние предметы убраны (FOD Clear)',
                'jetway_ok'    => 'Телетрап / трап в рабочем состоянии',
            ];
            foreach ($checks as $name => $label):
            ?>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="<?= $name ?>"
                           id="<?= $name ?>" value="1" <?= $checked($name) ?>>
                    <label class="form-check-label" for="<?= $name ?>"><?= $label ?></label>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="col-12">
                <label class="form-label fw-semibold">Замечания</label>
                <textarea name="remarks" class="form-control" rows="3"
                          placeholder="Описание замечаний или особых условий..."><?= $v('remarks') ?></textarea>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" name="action" value="draft" class="btn btn-outline-primary">
            <i class="bi bi-floppy me-1"></i>Сохранить черновик
        </button>
        <button type="submit" name="action" value="submit" class="btn btn-success"
                onclick="return confirm('Подтвердить подачу акта готовности стоянки?')">
            <i class="bi bi-check-circle me-1"></i>Подать акт
        </button>
        <a href="/flights/<?= (int)$flight['id'] ?>" class="btn btn-outline-secondary ms-auto">Отмена</a>
    </div>
</form>

<?php include __DIR__ . '/../flights/_service_charges.php'; ?>
