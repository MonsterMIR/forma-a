<?php /** @var array $inspections @var array $current @var array $filters @var array $pagination */ ?>

<!-- Текущий статус ВПП/перрона -->
<div class="row mb-3">
  <?php foreach (['runway' => 'ВПП', 'apron' => 'Перрон'] as $type => $label): ?>
  <div class="col-md-6">
    <?php $c = $current[$type] ?? null; ?>
    <div class="card border-<?= $c ? ($c['result']==='clear'?'success':'warning') : 'danger' ?>">
      <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between">
        <div>
          <strong><?= $label ?></strong>
          <?php if ($c): ?>
            <span class="badge bg-<?= $c['result']==='clear' ? 'success' : 'warning' ?> ms-2">
              <?= $c['result']==='clear' ? '✓ Чистый' : '⚠ Ограничения' ?>
            </span>
            <div class="text-muted small">
              Осмотрено: <?= e($c['inspected_at']) ?> &nbsp;|&nbsp;
              Действует до: <strong><?= e($c['valid_until']) ?></strong>
            </div>
          <?php else: ?>
            <span class="badge bg-danger ms-2">✗ Нет действующего осмотра</span>
          <?php endif; ?>
        </div>
        <?php if (has_role(['admin','aero'])): ?>
        <a href="/inspections/create?type=<?= $type ?>" class="btn btn-sm btn-outline-primary">
          + Зафиксировать
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Фильтры -->
<form class="row g-2 mb-3" method="get">
  <div class="col-auto">
    <select name="type" class="form-select form-select-sm">
      <option value="">Все типы</option>
      <option value="runway"  <?= $filters['type']==='runway'  ?'selected':'' ?>>ВПП</option>
      <option value="apron"   <?= $filters['type']==='apron'   ?'selected':'' ?>>Перрон</option>
      <option value="both"    <?= $filters['type']==='both'    ?'selected':'' ?>>ВПП+Перрон</option>
    </select>
  </div>
  <div class="col-auto">
    <input type="date" name="date" class="form-control form-control-sm"
           value="<?= e($filters['date']) ?>">
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-secondary">Фильтр</button>
    <a href="/inspections" class="btn btn-sm btn-outline-secondary">Сброс</a>
  </div>
</form>

<!-- Таблица -->
<table class="table table-sm table-hover align-middle">
  <thead class="table-dark">
    <tr>
      <th>Дата/время осмотра</th>
      <th>Тип</th>
      <th>Результат</th>
      <th>Действителен до</th>
      <th>Статус</th>
      <th>Инспектор</th>
      <th>Примечания</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($inspections)): ?>
    <tr><td colspan="7" class="text-center text-muted py-3">Осмотров не найдено</td></tr>
  <?php endif; ?>
  <?php foreach ($inspections as $i): ?>
    <tr>
      <td><?= e($i['inspected_at']) ?></td>
      <td>
        <?php $types=['runway'=>'ВПП','apron'=>'Перрон','both'=>'ВПП+Перрон']; ?>
        <?= $types[$i['inspection_type']] ?? $i['inspection_type'] ?>
      </td>
      <td>
        <?php $badges=['clear'=>'success','issues_found'=>'warning','restricted'=>'warning','closed'=>'danger']; ?>
        <?php $labels=['clear'=>'Чисто','issues_found'=>'Нарушения','restricted'=>'Ограничения','closed'=>'Закрыто']; ?>
        <span class="badge bg-<?= $badges[$i['result']]??'secondary' ?>">
          <?= $labels[$i['result']] ?? $i['result'] ?>
        </span>
      </td>
      <td><?= e($i['valid_until']) ?></td>
      <td>
        <?php if ($i['is_active_now']): ?>
          <span class="badge bg-success">✓ Действует</span>
        <?php else: ?>
          <span class="badge bg-secondary">Истёк</span>
        <?php endif; ?>
      </td>
      <td><?= e($i['inspector_name'] ?? $i['created_by_name'] ?? '—') ?></td>
      <td class="text-muted small"><?= e($i['issues_notes'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php include __DIR__ . '/../layouts/_pagination.php'; ?>

<?php if (has_role(['admin','aero'])): ?>
<div class="mt-3">
  <a href="/inspections/create" class="btn btn-primary">
    + Зафиксировать новый осмотр
  </a>
</div>
<?php endif; ?>
