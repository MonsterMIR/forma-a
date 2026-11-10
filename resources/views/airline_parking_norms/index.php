<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-clock-history me-2"></i>Нормы бесплатной стоянки</h1>
        <div class="text-muted small">Используется для авторасчёта OVER_PARK при подаче акта стоянки</div>
    </div>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        Авиакомпании <span class="badge bg-secondary"><?= count($items) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Авиакомпания</th>
                    <th style="width:120px">Норма pax (ч)</th>
                    <th style="width:120px">Норма cargo (ч)</th>
                    <th>Примечание</th>
                    <th style="width:130px">Обновлено</th>
                    <th style="width:60px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
            <tr class="<?= empty($item['norm_id']) ? 'table-warning' : '' ?>">
                <td>
                    <span class="fw-semibold"><?= e($item['airline_name']) ?></span>
                    <?php if ($item['iata_code']): ?>
                    <span class="badge bg-light text-dark border ms-1"><?= e($item['iata_code']) ?></span>
                    <?php endif; ?>
                    <?php if (empty($item['norm_id'])): ?>
                    <div class="text-danger" style="font-size:0.7rem">
                        <i class="bi bi-exclamation-circle me-1"></i>Норма не задана
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="POST" action="/airline-parking-norms/save"
                          class="d-flex gap-1 align-items-center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="airline_id" value="<?= (int)$item['airline_id'] ?>">
                        <input type="number" name="free_hours_pax"
                               class="form-control form-control-sm" style="width:90px"
                               step="0.5" min="0" required
                               value="<?= e((string)($item['free_hours_pax'] ?? '')) ?>">
                </td>
                <td>
                        <input type="number" name="free_hours_cargo"
                               class="form-control form-control-sm" style="width:90px"
                               step="0.5" min="0" required
                               value="<?= e((string)($item['free_hours_cargo'] ?? '')) ?>">
                </td>
                <td>
                        <input type="text" name="notes"
                               class="form-control form-control-sm"
                               value="<?= e($item['notes'] ?? '') ?>"
                               placeholder="необязательно">
                </td>
                <td class="text-muted">
                    <?= $item['updated_at'] ? date('d.m.Y H:i', strtotime($item['updated_at'])) : '—' ?>
                </td>
                <td>
                        <button type="submit" class="btn btn-sm btn-success py-0 px-2">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
