<?php
$isEdit = !empty($card);
$v = fn(string $f) => htmlspecialchars((string)($card[$f] ?? $_POST[$f] ?? ''));
$checked = fn(string $f) => (!empty($card[$f]) || !empty($_POST[$f])) ? 'checked' : '';
?>
<?php if (!empty($lockStatus)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5"></i>
    <div><?= e(__('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))])) ?></div>
</div>
<?php endif; ?>
<?php include BASE_PATH . '/resources/views/partials/pending_orders_banner.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Технологическая карта</h2>
        <div class="text-muted small">
            Рейс: <strong><?= e($flight['flight_number']) ?></strong>
            · <?= e($flight['flight_date']) ?>
            · <?= $flight['operation_type'] === 'arrival' ? '↓ Прилёт' : '↑ Вылет' ?>
            · <?= e($flight['airline_name']) ?>
        </div>
    </div>
    <a href="/flights/<?= (int)$flight['id'] ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> К рейсу
    </a>
</div>

<?php if (!empty($card) && $card['status'] === 'submitted'): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle me-2"></i>
    Карта подана <?= e($card['submitted_at'] ?? '') ?>
    <?= $card['created_by_name'] ? '· ' . e($card['created_by_name']) : '' ?>
</div>
<?php endif; ?>

<form method="POST" action="/tech-cards/save">
    <?= csrf_field() ?>
    <input type="hidden" name="flight_id" value="<?= (int)$flight['id'] ?>">

    <div class="row g-4">

        <!-- Стоянка и колодки -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-geo-alt me-2 text-primary"></i>Стоянка ВС
                </div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Место стоянки</label>
                        <input type="text" name="aircraft_stand" class="form-control"
                               value="<?= $v('aircraft_stand') ?>" placeholder="Пример: 12A">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Колодки — установлены</label>
                        <input type="time" name="chocks_on_time" class="form-control"
                               value="<?= $v('chocks_on_time') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Колодки — сняты</label>
                        <input type="time" name="chocks_off_time" class="form-control"
                               value="<?= $v('chocks_off_time') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Трап -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-ladder me-2 text-warning"></i>Трап / Телетрап
                </div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Тип трапа</label>
                        <select name="stairs_type" class="form-select">
                            <option value="">— не указано —</option>
                            <option value="mobile"  <?= ($v('stairs_type')==='mobile'?'selected':'') ?>>Мобильный трап</option>
                            <option value="bridge"  <?= ($v('stairs_type')==='bridge'?'selected':'') ?>>Телетрап (Jetway)</option>
                            <option value="none"    <?= ($v('stairs_type')==='none'?'selected':'') ?>>Не использовался</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Время подачи</label>
                        <input type="time" name="stairs_attach_time" class="form-control"
                               value="<?= $v('stairs_attach_time') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Время уборки</label>
                        <input type="time" name="stairs_remove_time" class="form-control"
                               value="<?= $v('stairs_remove_time') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Уборка и обслуживание -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-stars me-2 text-info"></i>Уборка и обслуживание
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="cleaning_done"
                                       id="cleaning_done" value="1" <?= $checked('cleaning_done') ?>>
                                <label class="form-check-label fw-semibold" for="cleaning_done">Уборка салона выполнена</label>
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Начало уборки</label>
                            <input type="time" name="cleaning_start" class="form-control form-control-sm"
                                   value="<?= $v('cleaning_start') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Конец уборки</label>
                            <input type="time" name="cleaning_end" class="form-control form-control-sm"
                                   value="<?= $v('cleaning_end') ?>">
                        </div>
                        <div class="col-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="water_done"
                                       id="water_done" value="1" <?= $checked('water_done') ?>>
                                <label class="form-check-label" for="water_done">Водообеспечение</label>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="toilet_done"
                                       id="toilet_done" value="1" <?= $checked('toilet_done') ?>>
                                <label class="form-check-label" for="toilet_done">Санузлы</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Авиабезопасность -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-shield-check me-2 text-success"></i>Авиабезопасность
                </div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="safety_check_done"
                                   id="safety_check_done" value="1" <?= $checked('safety_check_done') ?>>
                            <label class="form-check-label fw-semibold" for="safety_check_done">Досмотр ВС выполнен</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Ответственный сотрудник АБ</label>
                        <input type="text" name="safety_officer" class="form-control form-control-sm"
                               value="<?= $v('safety_officer') ?>" placeholder="ФИО">
                    </div>
                </div>
            </div>
        </div>

        <!-- Загрузка/выгрузка -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-box-seam me-2 text-warning"></i>Загрузка / Выгрузка
                </div>
                <div class="card-body row g-3">
                    <div class="col-6">
                        <label class="form-label small">Начало</label>
                        <input type="time" name="loading_start" class="form-control form-control-sm"
                               value="<?= $v('loading_start') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Окончание</label>
                        <input type="time" name="loading_end" class="form-control form-control-sm"
                               value="<?= $v('loading_end') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Кол-во мест багажа</label>
                        <input type="number" name="baggage_count" class="form-control form-control-sm"
                               value="<?= $v('baggage_count') ?>" min="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Груз (кг)</label>
                        <input type="number" name="cargo_weight_kg" class="form-control form-control-sm"
                               value="<?= $v('cargo_weight_kg') ?>" min="0" step="0.1">
                    </div>
                </div>
            </div>
        </div>

        <!-- Подписи -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-pen me-2"></i>Подписи
                </div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">КВС / Представитель экипажа</label>
                        <input type="text" name="crew_signature" class="form-control"
                               value="<?= $v('crew_signature') ?>" placeholder="ФИО">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Представитель хэндлера (СПО)</label>
                        <input type="text" name="handler_signature" class="form-control"
                               value="<?= $v('handler_signature') ?>" placeholder="ФИО">
                    </div>
                </div>
            </div>
        </div>

        <!-- Инциденты -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-exclamation-triangle me-2 text-danger"></i>Инциденты / Замечания
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="has_incidents"
                               id="has_incidents" value="1" <?= $checked('has_incidents') ?>>
                        <label class="form-check-label fw-semibold text-danger" for="has_incidents">
                            Зафиксированы инциденты / нарушения
                        </label>
                    </div>
                    <textarea name="incidents_notes" class="form-control" rows="3"
                              placeholder="Описание инцидентов..."><?= $v('incidents_notes') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Примечания -->
        <div class="col-12">
            <label class="form-label fw-semibold">Примечания</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="Дополнительная информация..."><?= $v('notes') ?></textarea>
        </div>

    </div>

    <!-- Кнопки -->
    <div class="mt-4 d-flex gap-2">
        <button type="submit" name="action" value="draft" class="btn btn-outline-primary">
            <i class="bi bi-floppy me-1"></i>Сохранить черновик
        </button>
        <button type="submit" name="action" value="submit" class="btn btn-success"
                onclick="return confirm('Подтвердить подачу технологической карты?')">
            <i class="bi bi-check-circle me-1"></i>Подать карту
        </button>
        <a href="/flights/<?= (int)$flight['id'] ?>" class="btn btn-outline-secondary ms-auto">Отмена</a>
    </div>
</form>

<?php include __DIR__ . '/../flights/_service_charges.php'; ?>
