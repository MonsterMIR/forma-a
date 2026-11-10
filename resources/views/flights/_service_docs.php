<?php
use App\Core\Auth;
use App\Core\Permissions;

$user = Auth::user();
$role = $user['role_code'] ?? '';

$manifestPre  = $manifestPre  ?? null;
$manifestFinal= $manifestFinal?? null;
$techCard     = $techCard     ?? null;
$equipLog     = $equipLog     ?? null;
$parkingAct   = $parkingAct   ?? null;

$statusBadge = function(?array $doc) {
    if (!$doc) return '<span class="badge bg-secondary">Не создан</span>';
    return match($doc['status']) {
        'submitted' => '<span class="badge bg-success">Подано</span>',
        'accepted'  => '<span class="badge bg-primary">Принято</span>',
        default     => '<span class="badge bg-warning text-dark">Черновик</span>',
    };
};
?>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-folder2-open me-2"></i>Документы служб
    </div>
    <div class="card-body">
        <div class="row g-3">

            <?php /* УПРОЩЕНО (21.09.2026, раунд 49, п.2, по прямому указанию
                     пользователя): убран первичный (предварительный)
                     манифест — карточки "предв."/"окончат." объединены в
                     одну "Манифест". */ ?>
            <!-- СОП: Манифест -->
            <?php if (Permissions::can('manifests.view')): ?>
            <div class="col-md-6 col-xl-3">
                <div class="card border h-100">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-success p-2"><i class="bi bi-people-fill"></i></span>
                            <div>
                                <div class="fw-semibold" style="font-size:0.8rem">Манифест</div>
                                <div class="text-muted" style="font-size:0.7rem">СОП · T−20/+20</div>
                            </div>
                        </div>
                        <div class="mb-2"><?= $statusBadge($manifestFinal) ?></div>
                        <?php if ($manifestFinal): ?>
                        <div class="small text-muted">PAX: <strong><?= (int)$manifestFinal['pax_total'] ?></strong></div>
                        <?php endif; ?>
                        <?php if (Permissions::can('manifests.create')): ?>
                        <a href="/manifests/flight/<?= (int)$flight['id'] ?>"
                           class="btn btn-sm <?= $manifestFinal ? 'btn-outline-primary' : 'btn-outline-success' ?> w-100 mt-2">
                            <i class="bi bi-<?= $manifestFinal ? 'pencil' : 'plus-circle' ?> me-1"></i>
                            <?= $manifestFinal ? 'Изменить' : 'Создать' ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ИЗМЕНЕНО (14.08.2026): карточки СПО (Техкарта), ССТ (Спецтехника)
                 и Аэродромной службы (Акт стоянки) убраны из этого блока —
                 эти документы фактически не ведутся службами и карточки
                 всегда показывали "Не создан", не неся полезной информации.
                 Реальный документооборот по рейсу — только манифесты СОП.
                 Контроллеры/модели TechCard, EquipmentLog, ParkingAct в коде
                 оставлены (используются в AutoChargeService для расчёта
                 сборов и имеют собственные разделы в меню), просто убраны
                 отсюда. -->

        </div>
    </div>
</div>
