<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-arrow-up-right text-success me-2"></i>Экспорт в 1С:Бухгалтерия</h2>
</div>
<div class="row g-4">
    <div class="col-md-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-success text-white fw-semibold">
                <i class="bi bi-calendar-range me-2"></i>Пакетный экспорт за период
            </div>
            <div class="card-body">
                <p class="text-muted small">Все утверждённые акты за период → XML для 1С</p>
                <form method="GET" action="/export/xml">
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Дата с</label>
                            <input type="date" name="from" class="form-control form-control-sm"
                                   value="<?= date('Y-m-01') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Дата по</label>
                            <input type="date" name="to" class="form-control form-control-sm"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-filetype-xml me-1"></i>Скачать XML (для УОД)
                        </button>
                    </div>
                </form>
                <hr class="my-3">
                <form method="GET" action="/export/excel">
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <input type="date" name="from" class="form-control form-control-sm"
                                   value="<?= date('Y-m-01') ?>" required>
                        </div>
                        <div class="col-6">
                            <input type="date" name="to" class="form-control form-control-sm"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-outline-success">
                            <i class="bi bi-filetype-csv me-1"></i>Скачать CSV (для Excel → 1С)
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light fw-semibold">
                <i class="bi bi-info-circle me-2"></i>Как загрузить в 1С
            </div>
            <div class="card-body small">
                <ol class="ps-3 mb-0">
                    <li class="mb-2">Скачайте XML-файл кнопкой выше или со страницы акта</li>
                    <li class="mb-2">В 1С: <strong>Администрирование → Обслуживание → Универсальный обмен данными</strong></li>
                    <li class="mb-2">Вкладка <strong>«Загрузка данных»</strong></li>
                    <li class="mb-2">Поле «Имя файла для загрузки» → выберите скачанный XML</li>
                    <li class="mb-2">Нажмите <strong>«Загрузить данные»</strong></li>
                    <li>Документ «Реализация услуг: Акт» создаётся автоматически</li>
                </ol>
                <hr class="my-2">
                <div class="text-muted">
                    <strong>Формат:</strong> V8Exchange XML 2.1.8 &nbsp;|&nbsp;
                    <strong>Документ:</strong> Реализация услуг: Акт &nbsp;|&nbsp;
                    <strong>Вид операции:</strong> Оказание услуг
                </div>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
                <i class="bi bi-list-check me-2"></i>Утверждённые акты — индивидуальный экспорт
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Номер акта</th><th>Дата</th><th>Авиакомпания</th>
                            <th>Рейс</th><th class="text-end">Сумма</th><th>Вал.</th>
                            <th>Статус в 1С</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($approvedActs)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Нет утверждённых актов</td></tr>
                    <?php else: ?>
                        <?php foreach ($approvedActs as $act): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($act['act_number']) ?></td>
                            <td><?= format_date($act['act_date']) ?></td>
                            <td><?= e($act['airline_name']) ?> <span class="text-muted small"><?= e($act['airline_iata'] ?? '') ?></span></td>
                            <td><?= e($act['airline_iata'] ?? '') ?>-<?= e($act['flight_number']) ?></td>
                            <td class="text-end fw-semibold"><?= number_format((float)$act['total_amount_with_vat'], 2, '.', ' ') ?></td>
                            <td><?= e($act['currency_code']) ?></td>
                            <td>
                                <?php if (!empty($act['exported_at'])): ?>
                                    <span class="badge bg-success">Выгружен <?= format_date($act['exported_at']) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Не выгружен</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/acts/<?= (int)$act['id'] ?>/export/xml"
                                   class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-download"></i> XML
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
