<?php
/** @var array $flight @var array $charges @var array $available */
$flightId = $flight['id'];
?>

<?php if (!empty($lockStatus)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-lock-fill fs-5"></i>
    <div><?= e(__('FLIGHT_LOCKED_BY_ACT', [':status' => __('ACTS_STATUS_' . strtoupper($lockStatus))])) ?></div>
</div>
<?php endif; ?>
<?php include BASE_PATH . '/resources/views/partials/pending_orders_banner.php'; ?>


<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h5 class="mb-0">
      <i class="bi bi-shield-fill-exclamation text-danger me-2"></i>
      СПАСОП — рейс <strong><?= e($flight['flight_number']) ?></strong>
      <span class="badge bg-secondary ms-1"><?= e($flight['flight_date']) ?></span>
    </h5>
    <small class="text-muted"><?= e($flight['airline_name'] ?? '—') ?></small>
  </div>
  <a href="/flights/<?= $flightId ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> К рейсу
  </a>
</div>

<?php if (has_role(['admin', 'cok', 'spasop'])): ?>
<div class="card mb-4 border-danger">
  <div class="card-header bg-danger text-white py-2">
    <i class="bi bi-plus-circle me-1"></i> Зафиксировать услугу СПАСОП
  </div>
  <div class="card-body">
    <form method="POST" action="/emergency-logs">
      <?= csrf_field() ?>
      <input type="hidden" name="flight_id" value="<?= $flightId ?>">
      <div class="row g-2 align-items-end">
        <div class="col-md-6">
          <label class="form-label fw-bold">Услуга *</label>
          <select name="service_id" class="form-select" required>
            <option value="">— выбрать услугу —</option>
            <?php foreach ($available as $svc): ?>
              <option value="<?= (int)$svc['service_id'] ?>">
                <?= e($svc['service_name']) ?> (<?= e($svc['unit_name']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($available)): ?>
            <div class="form-text text-warning">
              Нет активных услуг СПАСОП.
              <a href="/services">Проверить услуги →</a>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-2">
          <label class="form-label fw-bold">Количество *</label>
          <input type="number" name="quantity" class="form-control"
                 min="0.01" step="0.01" value="1" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Примечание</label>
          <input type="text" name="note" class="form-control" placeholder="опционально">
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-danger w-100">
            <i class="bi bi-save"></i> Сохранить
          </button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header py-2">
    <i class="bi bi-list-check me-1"></i>
    Оказанные услуги СПАСОП
    <span class="badge bg-danger ms-1"><?= count($charges) ?></span>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-dark">
        <tr>
          <th>Услуга</th>
          <th class="text-center">Ед. изм.</th>
          <th class="text-center">Количество</th>
          <th>Примечание</th>
          <th>Внёс</th>
          <th>Статус</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($charges)): ?>
        <tr>
          <td colspan="7" class="text-center text-muted py-3">
            <i class="bi bi-shield-fill-exclamation fs-4 d-block mb-2 opacity-25"></i>
            Услуги СПАСОП не зафиксированы
          </td>
        </tr>
      <?php endif; ?>
      <?php foreach ($charges as $c): ?>
        <tr>
          <td class="fw-semibold"><?= e($c['service_name']) ?></td>
          <td class="text-center"><?= e($c['unit_name']) ?></td>
          <td class="text-center fw-bold"><?= number_format((float)$c['quantity'], 2) ?></td>
          <td class="text-muted small"><?= e($c['note'] ?? '') ?></td>
          <td class="text-muted small"><?= e($c['created_by_name'] ?? '—') ?></td>
          <td>
            <?php if ($c['consumed_in_act_id']): ?>
              <span class="badge bg-success">
                <i class="bi bi-check-circle me-1"></i>Включено в акт
              </span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">
                <i class="bi bi-clock me-1"></i>Ожидание
              </span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if (!$c['consumed_in_act_id'] && has_role(['admin', 'cok', 'spasop'])): ?>
              <form method="POST" action="/emergency-logs/<?= (int)$c['id'] ?>/delete"
                    onsubmit="return confirm('Удалить запись?')">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-danger">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
