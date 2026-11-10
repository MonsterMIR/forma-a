<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-p-square me-2"></i>Акты готовности стоянки</h1>
</div>

<?php
    // ДОБАВЛЕНО (18.09.2026, раунд 48, по прямому указанию пользователя):
    // "https://formb.tvtairport.uz/parking-acts должен отражать актуальную
    // информацию о состоянии стоянок." — сводка занята/свободна по каждой
    // стоянке справочника ПРЯМО СЕЙЧАС, отдельно от списка поданных актов
    // ниже (тот — история чек-листов, эта — текущее состояние). См.
    // App\Services\ParkingStandService::getStatusBoard().
    $occupiedCount = count(array_filter($standBoard ?? [], fn($row) => $row['occupied']));
    $freeCount     = count($standBoard ?? []) - $occupiedCount;
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-grid-3x3-gap me-1"></i>Текущее состояние стоянок</span>
        <span>
            <span class="badge bg-danger me-1"><?= $occupiedCount ?> занято</span>
            <span class="badge bg-success"><?= $freeCount ?> свободно</span>
        </span>
    </div>
    <?php if (empty($standBoard)): ?>
    <div class="card-body text-center text-muted py-4">
        Справочник стоянок пуст — Справочники → Стоянки
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Стоянка</th><th>Статус</th><th>Рейс</th><th>Борт</th><th>Авиакомпания</th><th>Занята с</th></tr>
            </thead>
            <tbody>
            <?php foreach ($standBoard as $row): $stand = $row['stand']; $f = $row['flight']; ?>
            <tr>
                <td class="fw-semibold"><?= e($stand['code']) ?></td>
                <td>
                    <?php if ($row['occupied']): ?>
                        <span class="badge bg-danger">Занята</span>
                        <?php if (!empty($row['occupied_via'])): ?>
                        <div class="small text-muted">через парную <?= e($row['occupied_via']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-success">Свободна</span>
                    <?php endif; ?>
                </td>
                <td><?= $f ? e($f['flight_number']) . ' (' . e($f['flight_date']) . ')' : '—' ?></td>
                <td><?= $f ? e($f['aircraft_registration'] ?? '—') : '—' ?></td>
                <td><?= $f ? e($f['airline_name'] ?? '—') : '—' ?></td>
                <td><?= $row['occupied_since'] ? e($row['occupied_since']) : ($row['occupied'] ? '—' : '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Все статусы</option>
                    <option value="draft"     <?= $filters['status']==='draft'?'selected':'' ?>>Черновик</option>
                    <option value="submitted" <?= $filters['status']==='submitted'?'selected':'' ?>>Подано</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary btn-sm">Фильтр</button>
                <a href="/parking-acts" class="btn btn-outline-secondary btn-sm ms-1">Сброс</a>
            </div>
        </form>
    </div>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Акты <span class="badge bg-secondary"><?= $total ?></span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Дата</th><th>Рейс</th><th>Авиакомпания</th><th>Стоянка</th><th>Статус</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Нет данных</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?= e($item['flight_date']) ?></td>
                <td class="fw-semibold"><?= e($item['flight_number']) ?></td>
                <td><?= e($item['airline_name']) ?></td>
                <td><?= e($item['stand_number'] ?? '—') ?></td>
                <td><?php
                    $sc = $item['status']==='submitted'?'success':'secondary';
                ?><span class="badge bg-<?= $sc ?>"><?= $item['status']==='submitted'?'Подано':'Черновик' ?></span></td>
                <td>
                    <a href="/parking-acts/flight/<?= (int)$item['flight_id'] ?>"
                       class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
