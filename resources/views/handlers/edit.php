<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-pencil-square"></i> Хендлер: <?= e($handler['name']) ?></h2>
    <a href="/handlers" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Данные хендлера</div>
            <div class="card-body">
                <form method="POST" action="/handlers/<?= (int)$handler['id'] ?>/edit">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Название <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="200"
                               value="<?= e($handler['name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Примечание</label>
                        <input type="text" name="note" class="form-control" maxlength="255"
                               value="<?= e($handler['note'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Статус</label>
                        <select name="is_active" class="form-select">
                            <option value="1" <?= $handler['is_active'] ? 'selected' : '' ?>>Активен</option>
                            <option value="0" <?= !$handler['is_active'] ? 'selected' : '' ?>>Неактивен</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Обслуживает авиакомпании</label>
                        <select name="airline_ids[]" class="form-select" multiple size="8">
                            <?php foreach ($airlines as $a): ?>
                            <option value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $linkedAirlines, true) ? 'selected' : '' ?>>
                                <?= e($a['name']) ?><?= $a['iata_code'] ? ' (' . e($a['iata_code']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Если для авиакомпании не выбран ни один хендлер — на планшете
                            при подписании акта будет предложен выбор из всех активных
                            хендлеров (и по-прежнему доступен ручной ввод ФИО).
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Сохранить</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                Сотрудники — те, кого можно выбрать подписантом акта
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>ФИО</th><th>Должность</th><th>Телефон</th><th>Email</th><th>Статус</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($employees)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Сотрудники ещё не добавлены.</td></tr>
                        <?php else: ?>
                            <?php foreach ($employees as $e): ?>
                            <tr>
                                <form method="POST" action="/handlers/employees/<?= (int)$e['id'] ?>/update">
                                <?= csrf_field() ?>
                                <td><input type="text" name="full_name" class="form-control form-control-sm" value="<?= e($e['full_name']) ?>" required></td>
                                <td><input type="text" name="position" class="form-control form-control-sm" value="<?= e($e['position'] ?? '') ?>"></td>
                                <td><input type="text" name="phone" class="form-control form-control-sm" value="<?= e($e['phone'] ?? '') ?>"></td>
                                <td><input type="email" name="email" class="form-control form-control-sm" value="<?= e($e['email'] ?? '') ?>"></td>
                                <td>
                                    <select name="is_active" class="form-select form-select-sm">
                                        <option value="1" <?= $e['is_active'] ? 'selected' : '' ?>>Активен</option>
                                        <option value="0" <?= !$e['is_active'] ? 'selected' : '' ?>>Неактивен</option>
                                    </select>
                                </td>
                                <td class="text-nowrap">
                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-check-lg"></i></button>
                                </td>
                                </form>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Добавить сотрудника</div>
            <div class="card-body">
                <form method="POST" action="/handlers/<?= (int)$handler['id'] ?>/employees/add" class="row g-2">
                    <?= csrf_field() ?>
                    <div class="col-md-4">
                        <input type="text" name="full_name" class="form-control form-control-sm" placeholder="ФИО *" required maxlength="150">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="position" class="form-control form-control-sm" placeholder="Должность" maxlength="150">
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="phone" class="form-control form-control-sm" placeholder="Телефон" maxlength="30">
                    </div>
                    <div class="col-md-2">
                        <input type="email" name="email" class="form-control form-control-sm" placeholder="Email" maxlength="150">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </form>
                <div class="form-text mt-2">
                    Email нужен, если хотите, чтобы этому сотруднику автоматически
                    уходила копия акта после утверждения (см. настройку почты).
                </div>
            </div>
        </div>
    </div>
</div>
