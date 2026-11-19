<?php
/**
 * ДОБАВЛЕНО (19.11.2026, раунд 190, по прямому указанию пользователя —
 * «анкета борта», макет согласован: https://claude.ai/artifact/M2KGo2XRpg8Lp4Mzy2KpHS):
 * единая карточка одного борта — рейсы+акты вперемешку с промежуточными
 * (вне-рейсовыми) услугами, по времени. См. App\Models\Aircraft::
 * getDossierTimeline(), App\Models\AircraftServiceLog.
 *
 * Доступ на просмотр — та же широкая роль-матрица, что и у index()/history().
 * Запись промежуточной услуги ($canLog) — admin + supervisor (по прямому
 * указанию пользователя: "коммерсам это безнадобности, админ да полный
 * доступ, цок смотритель, а супервайзер он вводит все эти данные").
 * Отмена ошибочной записи ($canCancel) — только admin.
 *
 * ФАЗА 1: цена/тариф сознательно не считается и не показывается в момент
 * записи услуги — см. докблок AircraftServiceLog. Поэтому там, где в
 * макете была "сумма несведённых услуг", здесь — только количество
 * записей.
 *
 * ДОБАВЛЕНО (19.11.2026, раунд 191, по прямому указанию пользователя):
 * Фаза 2 — перенос этих записей в акт ВЫЛЕТА с реальным расчётом суммы —
 * реализована (см. ActService::foldAircraftServiceLogIntoAct()),
 * срабатывает автоматически при каждой (пере)сборке акта. Услуги,
 * помеченные в справочнике как не тарифицируемые (services.is_non_billable,
 * например CHOCKS — "Установка/уборка колодок"), в акт никогда не
 * переносятся и остаются в статусе draft ПОСТОЯННО — это их ожидаемое
 * конечное состояние (просто факт для истории анкеты), не "зависшая"
 * запись.
 */
$aircraft   = $aircraft ?? [];
$timeline   = $timeline ?? [];
$draftCount = $draftCount ?? 0;
$canLog     = $canLog ?? false;
$canCancel  = $canCancel ?? false;
$services   = $services ?? [];

$flightsCount = count(array_filter($timeline, fn($e) => $e['type'] === 'flight'));
$actsCount    = count(array_filter($timeline, fn($e) => $e['type'] === 'flight' && !empty($e['data']['act_id'])));
$servicesCount = count(array_filter($timeline, fn($e) => $e['type'] === 'service' && $e['data']['status'] !== 'cancelled'));

$actBadgeClass = static function (?string $status): string {
    return match ($status) {
        'draft'     => 'bg-warning text-dark',
        'approved'  => 'bg-success',
        'exported'  => 'bg-primary',
        'cancelled' => 'bg-danger',
        default     => 'bg-secondary',
    };
};
$actStatusLabel = static function (?string $status): string {
    return match ($status) {
        'draft'     => 'Предв.',
        'approved'  => 'Утверждён',
        'exported'  => 'Экспорт.',
        'cancelled' => 'Отменён',
        default     => e((string)$status),
    };
};
$serviceStatusBadge = static function (array $s): string {
    // ИЗМЕНЕНО (19.11.2026, раунд 191): не тарифицируемая услуга
    // (services.is_non_billable=1, напр. CHOCKS) в статусе draft — это не
    // "ждёт свода в акт" (она туда никогда не попадёт, см.
    // AircraftServiceLog::getDraftsByAircraft()), а просто служебная
    // отметка для истории — отдельный, не тревожный бейдж.
    if ($s['status'] === 'draft' && !empty($s['service_is_non_billable'])) {
        return '<span class="badge bg-light text-muted border">служебная отметка</span>';
    }
    return match ($s['status']) {
        'draft'           => '<span class="badge bg-warning text-dark">не сведена в акт</span>',
        'folded_into_act' => '<span class="badge bg-secondary">вошла в акт' . (!empty($s['act_number']) ? ' №' . e((string)$s['act_number']) : '') . '</span>',
        'cancelled'       => '<span class="badge bg-light text-muted border">отменена</span>',
        default           => '',
    };
};
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-1">
                <li class="breadcrumb-item"><a href="/aircraft-status">Справочник бортов</a></li>
                <li class="breadcrumb-item active"><?= e($aircraft['registration'] ?? '') ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0">
            <i class="bi bi-airplane-engines me-2"></i><?= e($aircraft['registration'] ?? '') ?>
            <?php if (!empty($aircraft['is_based'])): ?>
                <span class="badge bg-primary align-middle"><i class="bi bi-house-door-fill me-1"></i>Базируется у нас</span>
            <?php endif; ?>
        </h1>
        <div class="text-muted small mt-1">
            <?= e($aircraft['aircraft_type'] ?? 'тип ВС не указан') ?>
            <?php if (($aircraft['mtow'] ?? null) !== null): ?>
                · MTOW <?= e((string)$aircraft['mtow']) ?> т
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canLog): ?>
    <button type="button" class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
        <i class="bi bi-plus-lg me-1"></i>Оказать услугу вне рейса
    </button>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-2">
                <div class="text-muted small">Рейсов</div>
                <div class="h4 mb-0"><?= $flightsCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-2">
                <div class="text-muted small">Актов</div>
                <div class="h4 mb-0"><?= $actsCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-2">
                <div class="text-muted small">Промежуточных услуг</div>
                <div class="h4 mb-0"><?= $servicesCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100 <?= $draftCount > 0 ? 'border-warning' : '' ?>">
            <div class="card-body py-2">
                <div class="text-muted small">Не сведено в акт</div>
                <div class="h4 mb-0 <?= $draftCount > 0 ? 'text-warning' : '' ?>"><?= $draftCount ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($draftCount > 0): ?>
<div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    <strong><?= $draftCount ?></strong> промежуточн<?= $draftCount === 1 ? 'ая услуга' : 'ых услуг(и)' ?>
    этого борта ещё не сведен<?= $draftCount === 1 ? 'а' : 'ы' ?> в акт.
    Будут автоматически добавлены (с реальной суммой) в акт вылета при его формировании/обновлении — вручную ничего делать не нужно.
</div>
<?php endif; ?>

<?php if ($canLog): ?>
<div class="collapse mb-3" id="addServiceForm">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Оказание услуги вне рейса</div>
        <div class="card-body">
            <form method="POST" action="/aircraft-status/<?= (int)($aircraft['id'] ?? 0) ?>/service" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1">Услуга</label>
                    <select name="service_id" class="form-select form-select-sm" required>
                        <option value="">— выберите —</option>
                        <?php foreach ($services as $svc): ?>
                        <option value="<?= (int)$svc['id'] ?>"><?= e($svc['name']) ?> (<?= e($svc['unit_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Количество</label>
                    <input type="number" name="quantity" class="form-control form-control-sm" step="0.01" min="0.01" value="1" required>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">Дата/время оказания</label>
                    <input type="datetime-local" name="rendered_at" class="form-control form-control-sm" value="<?= date('Y-m-d\TH:i') ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1">Примечание</label>
                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="необязательно">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Записать в анкету борта</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Хронология — рейсы, акты и промежуточные услуги</div>
    <div class="list-group list-group-flush">
        <?php if (empty($timeline)): ?>
        <div class="list-group-item text-center text-muted py-4">Для этого борта пока ничего не записано</div>
        <?php endif; ?>
        <?php foreach ($timeline as $entry): ?>
            <?php if ($entry['type'] === 'flight'): $f = $entry['data']; ?>
            <div class="list-group-item">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle me-2">рейс</span>
                        <a href="/flights/<?= (int)$f['id'] ?>" class="fw-semibold"><?= e($f['flight_number']) ?></a>
                        <?= $f['operation_type'] === 'arrival'
                            ? ' <span class="badge bg-success">прилёт</span>'
                            : ' <span class="badge bg-warning text-dark">вылет</span>' ?>
                        <span class="text-muted small ms-1">
                            <?= e($f['origin_iata'] ?? $f['origin_name'] ?? '—') ?> → <?= e($f['destination_iata'] ?? $f['destination_name'] ?? '—') ?>
                        </span>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted"><?= e($f['flight_date']) ?><?php if (!empty($f['scheduled_time'])): ?> <?= e(substr((string)$f['scheduled_time'], 0, 5)) ?><?php endif; ?></div>
                        <?php if (empty($f['act_id'])): ?>
                            <span class="badge bg-secondary">нет акта</span>
                        <?php else: ?>
                            <a href="/acts/<?= (int)$f['act_id'] ?>" class="badge text-decoration-none <?= $actBadgeClass($f['act_status']) ?>">
                                <?= $actStatusLabel($f['act_status']) ?><?php if (!empty($f['act_number'])): ?> №<?= e((string)$f['act_number']) ?><?php endif; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php else: $s = $entry['data']; ?>
            <div class="list-group-item <?= $s['status'] === 'draft' ? 'bg-warning-subtle' : '' ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="badge bg-light text-dark border me-2">вне рейса</span>
                        <?php if (!empty($s['service_is_non_billable'])): ?>
                        <span class="badge bg-light text-muted border me-1" title="Не тарифицируется, в акт не переносится">служебная</span>
                        <?php endif; ?>
                        <span class="fw-semibold"><?= e($s['service_name']) ?></span>
                        <span class="text-muted small ms-1"><?= e((string)$s['quantity']) ?> <?= e($s['service_unit_name'] ?? '') ?></span>
                        <?= $serviceStatusBadge($s) ?>
                        <?php if (!empty($s['notes'])): ?>
                        <div class="text-muted small"><?= e($s['notes']) ?></div>
                        <?php endif; ?>
                        <div class="text-muted small">
                            записал: <?= e($s['recorded_by_name'] ?? '—') ?>
                            <?php if ($s['status'] === 'cancelled' && !empty($s['cancelled_by_name'])): ?>
                                · отменил: <?= e($s['cancelled_by_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted"><?= e(substr((string)$s['rendered_at'], 0, 16)) ?></div>
                        <?php if ($canCancel && $s['status'] === 'draft'): ?>
                        <form method="POST" action="/aircraft-status/<?= (int)($aircraft['id'] ?? 0) ?>/service/<?= (int)$s['id'] ?>/cancel" class="d-inline"
                              onsubmit="return confirm('Отменить эту запись?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2 mt-1" title="Отменить запись">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="alert alert-info small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Промежуточные (вне-рейсовые) услуги записываются здесь без расчёта суммы — факт оказания, без тарифа/НДС
    (в момент записи ещё нет акта с реальными route_type/operation_type, по которым ищется тариф). Сумма
    считается позже, автоматически, в момент формирования/обновления акта вылета — тем же способом, что и для
    обычных услуг рейса, и дата/время оказания дописываются прямо в название строки акта (раздел «Прочие»).
    Услуги, помеченные в справочнике как не тарифицируемые (например «Установка/уборка колодок»), в акт не
    переносятся вообще — остаются в анкете просто как отметка для истории.
    Доступ на просмотр этой страницы — широкая роль-матрица (как у справочника бортов); запись услуги — admin/supervisor;
    отмена ошибочной записи — только admin; ЦОК видит анкету, но не редактирует.
</div>
