<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-person-badge"></i> Хендлеры</h2>
    <?php if (has_role(['admin', 'cok'])): ?>
    <a href="/handlers/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Новый хендлер</a>
    <?php endif; ?>
</div>

<p class="text-muted small">
    Хендлер — компания-агент, представляющая авиакомпанию на перроне. Именно
    сотрудник хендлера (не сотрудник самой авиакомпании) обычно ставит подпись
    представителя в акте. Привяжите хендлера к авиакомпаниям и заведите список
    его сотрудников — тогда на планшете при подписании акта их можно будет
    выбрать из списка, а не вводить ФИО вручную.
</p>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Поиск по названию" value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-3">
                <select name="is_active" class="form-select form-select-sm">
                    <option value="">Все</option>
                    <option value="1" <?= $filters['is_active'] === '1' ? 'selected' : '' ?>>Активные</option>
                    <option value="0" <?= $filters['is_active'] === '0' ? 'selected' : '' ?>>Неактивные</option>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Найти</button>
                <a href="/handlers" class="btn btn-sm btn-outline-secondary">Сброс</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Название</th>
                        <th>Сотрудников</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($handlers)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Хендлеры ещё не заведены.</td></tr>
                <?php else: ?>
                    <?php foreach ($handlers as $h): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$h['id'] ?></td>
                        <td><?= e($h['name']) ?></td>
                        <td><span class="badge bg-secondary"><?= (int)$h['employees_count'] ?></span></td>
                        <td><?= $h['is_active'] ? '<span class="badge bg-success">Активен</span>' : '<span class="badge bg-secondary">Неактивен</span>' ?></td>
                        <td>
                            <?php if (has_role(['admin', 'cok'])): ?>
                            <a href="/handlers/<?= (int)$h['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Открыть</a>
                            <form method="POST" action="/handlers/<?= (int)$h['id'] ?>/toggle" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <?= $h['is_active'] ? 'Деактивировать' : 'Активировать' ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
