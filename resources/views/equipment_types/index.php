<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-truck me-2"></i>Справочник спецтехники</h1>
        <div class="text-muted small">Типы техники, доступные при заполнении журнала ССТ</div>
    </div>
    <a href="/equipment-logs" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> К журналам
    </a>
</div>
<div class="row g-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-plus-circle me-2 text-success"></i>Добавить тип техники
            </div>
            <div class="card-body">
                <form method="POST" action="/equipment-types">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Код</label>
                        <input type="text" name="code" class="form-control" required maxlength="50"
                               placeholder="Например: ЭлектроКар">
                        <div class="form-text">Уникальный короткий код</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Название</label>
                        <input type="text" name="name" class="form-control" required maxlength="150"
                               placeholder="Например: Электрокар перронный">
                    </div>
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-plus-circle me-1"></i>Добавить
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                Типы техники <span class="badge bg-secondary"><?= count($items) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Код</th><th>Название</th><th>Статус</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Справочник пуст</td></tr>
                    <?php endif; ?>
                    <?php foreach ($items as $item): ?>
                    <tr class="<?= !$item['is_active'] ? 'opacity-50' : '' ?>">
                        <td><code><?= e($item['code']) ?></code></td>
                        <td><?= e($item['name']) ?></td>
                        <td>
                            <?= $item['is_active']
                                ? '<span class="badge bg-success">Активен</span>'
                                : '<span class="badge bg-secondary">Скрыт</span>' ?>
                        </td>
                        <td class="text-nowrap">
                            <form method="POST"
                                  action="/equipment-types/<?= (int)$item['id'] ?>/toggle"
                                  class="d-inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                                        title="<?= $item['is_active'] ? 'Скрыть' : 'Активировать' ?>">
                                    <i class="bi bi-eye<?= $item['is_active'] ? '-slash' : '' ?>"></i>
                                </button>
                            </form>
                            <form method="POST"
                                  action="/equipment-types/<?= (int)$item['id'] ?>/delete"
                                  class="d-inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger py-0 px-2"
                                        onclick="return confirm('Удалить? Возможно только если не используется.')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="alert alert-info small mt-3 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            Скрытые типы не появляются в выпадающем списке, но сохраняются в уже созданных записях.
        </div>
    </div>
</div>
