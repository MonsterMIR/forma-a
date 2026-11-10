<?php
$validHours = $validHours ?? 3;
$typeDefault = $_GET['type'] ?? '';
?>
<form method="post" action="/inspections">
  <?= csrf_field() ?>

  <div class="row g-3">
    <div class="col-md-4">
      <label class="form-label fw-bold">Тип осмотра *</label>
      <select name="inspection_type" class="form-select" required>
        <option value="">— выбрать —</option>
        <option value="runway" <?= $typeDefault==='runway'?'selected':'' ?>>ВПП (взлётно-посадочная полоса)</option>
        <option value="apron"  <?= $typeDefault==='apron' ?'selected':'' ?>>Перрон</option>
        <option value="both"   <?= $typeDefault==='both'  ?'selected':'' ?>>ВПП + Перрон</option>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label fw-bold">Дата и время осмотра *</label>
      <input type="datetime-local" name="inspected_at" class="form-control" required
             value="<?= date('Y-m-d\TH:i') ?>">
      <div class="form-text">Действителен <?= $validHours ?> часа после указанного времени</div>
    </div>

    <div class="col-md-4">
      <label class="form-label fw-bold">Результат *</label>
      <select name="result" class="form-select" required>
        <option value="clear">✓ Чисто — разрешён взлёт/посадка</option>
        <option value="issues_found">⚠ Выявлены нарушения (с ограничениями)</option>
        <option value="restricted">⚠ Ограниченная эксплуатация</option>
        <option value="closed">✗ Закрыто</option>
      </select>
    </div>

    <div class="col-md-6">
      <label class="form-label">ФИО инспектора</label>
      <input type="text" name="inspector_name" class="form-control"
             placeholder="Автоматически — текущий пользователь"
             value="<?= e($_SESSION['user']['full_name'] ?? '') ?>">
    </div>

    <div class="col-md-6">
      <label class="form-label">Примечания / нарушения</label>
      <textarea name="issues_notes" class="form-control" rows="2"
                placeholder="Описание выявленных нарушений, ограничений..."></textarea>
    </div>
  </div>

  <div class="mt-4">
    <button type="submit" class="btn btn-primary">Зафиксировать осмотр</button>
    <a href="/inspections" class="btn btn-outline-secondary ms-2">Отмена</a>
  </div>
</form>
