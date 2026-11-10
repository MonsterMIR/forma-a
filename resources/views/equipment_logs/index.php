<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-truck me-2"></i>Журнал спецтехники</h1>
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
                <a href="/equipment-logs" class="btn btn-outline-secondary btn-sm ms-1">Сброс</a>
            </div>
        </form>
    </div>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Журналы <span class="badge bg-secondary"><?= $total ?></span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Дата</th><th>Рейс</th><th>Авиакомпания</th><th>Тип</th><th>Статус</th><th></th></tr>
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
                <td><span class="badge <?= $item['operation_type']==='arrival'?'bg-info text-dark':'bg-warning text-dark' ?>">
                    <?= $item['operation_type']==='arrival'?'↓ Прилёт':'↑ Вылет' ?>
                </span></td>
                <td><?php
                    $sc = $item['status']==='submitted'?'success':'secondary';
                    $sl = $item['status']==='submitted'?'Подано':'Черновик';
                ?><span class="badge bg-<?= $sc ?>"><?= $sl ?></span></td>
                <td>
                    <a href="/equipment-logs/flight/<?= (int)$item['flight_id'] ?>"
                       class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
