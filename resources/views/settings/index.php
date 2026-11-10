<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-sliders"></i> <?= __('SETTINGS_TITLE') ?></h2>
</div>

<div class="card border-0 shadow-sm" style="max-width:800px">
    <div class="card-body">
        <form method="POST" action="/settings">
            <?= csrf_field() ?>
            <?php if (empty($settings)): ?>
                <div class="alert alert-info"><?= __('SETTINGS_NO_DATA') ?></div>
            <?php else: ?>
                <div class="table-responsive mb-4">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th><?= __('SETTINGS_KEY_COL') ?></th>
                                <th><?= __('SETTINGS_VALUE_COL') ?></th>
                                <th><?= __('COMMON_DESCRIPTION') ?></th>
                                <th><?= __('SETTINGS_UPDATED_BY_COL') ?></th>
                                <th><?= __('SETTINGS_UPDATED_AT_COL') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($settings as $s): ?>
                        <tr>
                            <td><code><?= e($s['setting_key']) ?></code></td>
                            <td>
                                <input type="text" name="settings[<?= e($s['setting_key']) ?>]"
                                       class="form-control form-control-sm"
                                       value="<?= e($s['setting_value'] ?? '') ?>">
                            </td>
                            <td class="small text-muted"><?= e($s['description'] ?? '—') ?></td>
                            <td class="small text-muted"><?= e($s['updated_by_username'] ?? '—') ?></td>
                            <td class="small text-muted"><?= format_datetime($s['updated_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <hr>
            <h6 class="fw-semibold mb-3">Add / Update Setting</h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Key</label>
                    <input type="text" name="settings[new_key_placeholder]" class="form-control"
                           placeholder="setting_key" id="newKeyInput"
                           oninput="updateNewKey(this.value)">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Value</label>
                    <input type="text" name="new_setting_value" class="form-control" placeholder="value" id="newValInput">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= __('COMMON_SAVE') ?> Settings</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateNewKey(val) {
    const input = document.getElementById('newKeyInput');
    input.name = 'settings[' + val.trim() + ']';
}
</script>