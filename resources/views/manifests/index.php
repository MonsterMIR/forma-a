<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-people-fill me-2"></i>Манифесты</h1>
</div>

<?php /* УПРОЩЕНО (21.09.2026, раунд 49, п.2): фильтр "Тип" убран — теперь
         один манифест на рейс. Старые записи с manifest_type='preliminary'
         (до этого раунда) остаются в списке как история, без отдельного
         фильтра/колонки. */ ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Статус</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Все</option>
                    <option value="draft"     <?= $filters['status']==='draft'?'selected':'' ?>>Черновик</option>
                    <option value="submitted" <?= $filters['status']==='submitted'?'selected':'' ?>>Подано</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">С даты</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">По дату</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary btn-sm">Фильтр</button>
                <a href="/manifests" class="btn btn-outline-secondary btn-sm ms-1">Сброс</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        Манифесты <span class="badge bg-secondary"><?= $total ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Дата</th>
                    <th>Рейс</th>
                    <th>Авиакомпания</th>
                    <th>PAX</th>
                    <th>Багаж (кг)</th>
                    <th>Статус</th>
                    <th>Подано</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">Нет данных</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?= e($item['flight_date']) ?></td>
                <td class="fw-semibold"><?= e($item['flight_number']) ?></td>
                <td><?= e($item['airline_name']) ?></td>
                <td class="fw-bold"><?= (int)$item['pax_total'] ?></td>
                <td><?= number_format((float)$item['baggage_weight_kg'], 1) ?></td>
                <td>
                    <?php $sc = $item['status']==='submitted'?'success':'secondary'; ?>
                    <span class="badge bg-<?= $sc ?>">
                        <?= $item['status']==='submitted'?'Подано':'Черновик' ?>
                    </span>
                </td>
                <td class="text-muted small">
                    <?= $item['submitted_at'] ? date('d.m H:i', strtotime($item['submitted_at'])) : '—' ?>
                </td>
                <td>
                    <a href="/manifests/flight/<?= (int)$item['flight_id'] ?>"
                       class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
