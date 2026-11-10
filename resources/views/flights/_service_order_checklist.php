<?php
/**
 * ДОБАВЛЕНО (аудит 10.07.2026): предзаказ услуг/транспорта ЦОК.
 * $checklistServices — услуги, доступные этому рейсу, сгруппированные по owner_role
 * $plannedServiceIds — id услуг, уже отмеченных в плане
 * $currentOrders     — те же записи целиком (со статусом/фактом), для бейджей
 * $flight, $existingAct — уже есть в контексте flights/show.php
 */
use App\Core\Permissions;

$roleLabels = [
    'sop'    => 'СОП — пассажирские услуги',
    'spo'    => 'СПО — рамп / загрузка',
    'sst'    => 'ССТ — спецтехника',
    'aero'   => 'Аэродромная служба',
    'spasop' => 'СПАСОП',
    // ДОБАВЛЕНО (14.09.2026, раунд 41): новые владельцы услуг после
    // перераспределения. Без строки здесь их услуги отфильтровывались бы
    // молча (см. `if (empty($checklistServices[$role])) continue;` ниже) и
    // ЦОК не смог бы их вообще увидеть/отметить в предзаказе.
    'gsm'     => 'ГСМ',
    'medical' => 'Здрав.пункт',
    'balance' => 'ОЦ — отдел центровки',
];

$ordersByServiceId = [];
foreach (($currentOrders ?? []) as $o) {
    $ordersByServiceId[(int)$o['service_id']] = $o;
}

$canPlan  = Permissions::can('service_orders.plan');
$isLocked = $existingAct && in_array($existingAct['status'], \App\Models\Act::LOCKED_STATUSES, true);
?>

<?php if (!empty($checklistServices)): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-clipboard2-check me-2"></i>Предзаказ услуг и транспорта</span>
        <?php if ($isLocked): ?>
        <span class="badge bg-secondary"><i class="bi bi-lock"></i> Акт заблокирован</span>
        <?php elseif (!$canPlan): ?>
        <span class="text-muted small">Только просмотр</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($canPlan && !$isLocked): ?>
        <div class="alert alert-light border small mb-3">
            Отметьте, что понадобится по этому рейсу. Отмеченное сразу отразится на странице
            соответствующей службы и в дашборде супервайзера. Уже начатые/оказанные позиции снять нельзя.
        </div>
        <form method="post" action="/flights/<?= (int)$flight['id'] ?>/service-orders">
            <?= csrf_field() ?>
        <?php endif; ?>

        <div class="row g-3">
            <?php foreach ($roleLabels as $role => $label): ?>
                <?php if (empty($checklistServices[$role])) continue; ?>
                <div class="col-md-6">
                    <div class="border rounded p-2 h-100">
                        <div class="fw-semibold small text-uppercase text-muted mb-2"><?= e($label) ?></div>
                        <?php foreach ($checklistServices[$role] as $svc):
                            $sid      = (int)$svc['id'];
                            $order    = $ordersByServiceId[$sid] ?? null;
                            $checked  = in_array($sid, $plannedServiceIds ?? [], true);
                            $locked   = $order && $order['status'] !== 'planned'; // in_progress/done — не трогать
                        ?>
                        <div class="form-check d-flex align-items-center justify-content-between py-1">
                            <span>
                                <?php if ($canPlan && !$isLocked && !$locked): ?>
                                <input class="form-check-input me-2" type="checkbox"
                                       name="service_ids[]" value="<?= $sid ?>"
                                       id="svc_<?= $sid ?>" <?= $checked ? 'checked' : '' ?>>
                                <?php elseif ($checked): ?>
                                <i class="bi bi-check-square text-primary me-2"></i>
                                <?php else: ?>
                                <i class="bi bi-square text-muted me-2"></i>
                                <?php endif; ?>
                                <label class="form-check-label <?= $locked ? 'fw-semibold' : '' ?>" for="svc_<?= $sid ?>">
                                    <?= e($svc['name']) ?>
                                </label>
                            </span>
                            <?php if ($order): ?>
                                <span class="badge bg-<?= $order['status'] === 'done' ? 'success' : ($order['status'] === 'in_progress' ? 'warning text-dark' : 'secondary') ?>">
                                    <?= match($order['status']) {
                                        'done' => 'Оказано',
                                        'in_progress' => 'В работе',
                                        'planned' => 'Заказано',
                                        default => $order['status'],
                                    } ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($canPlan && !$isLocked): ?>
            <button type="submit" class="btn btn-primary mt-3">
                <i class="bi bi-save me-1"></i>Сохранить предзаказ
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
