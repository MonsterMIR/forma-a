<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-folder2-open"></i> <?= __('FILES_TITLE') ?></h2>
</div>

<?php if (empty($files)): ?>
<div class="alert alert-info"><i class="bi bi-info-circle"></i> No files found.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= __('FILES_ORIGINAL_NAME') ?></th>
                        <th><?= __('FILES_RELATION') ?></th>
                        <th>MIME</th>
                        <th><?= __('FILES_SIZE') ?></th>
                        <th>SHA256</th>
                        <th><?= __('FILES_UPLOADED_BY_COL') ?></th>
                        <th><?= __('FILES_UPLOADED_AT_COL') ?></th>
                        <th><?= __('FILES_OPS') ?></th>
                        <th><?= __('COMMON_ACTIONS') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $f): ?>
                <tr>
                    <td class="small"><?= e($f['original_name']) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= e($f['relation_type']) ?></span></td>
                    <td class="small text-muted"><?= e($f['mime_type'] ?? '—') ?></td>
                    <td class="small text-muted"><?= number_format($f['file_size_bytes'] / 1024, 1) ?> KB</td>
                    <td class="small text-muted font-monospace"><?= $f['sha256_hash'] ? substr(e($f['sha256_hash']), 0, 12) . '…' : '—' ?></td>
                    <td class="small"><?= e($f['created_by_username'] ?? '—') ?></td>
                    <td class="small text-muted"><?= format_datetime($f['uploaded_at']) ?></td>
                    <td class="text-center"><span class="badge bg-secondary"><?= (int)$f['operation_count'] ?></span></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="/files/<?= (int)$f['file_id'] ?>/download" class="btn btn-sm btn-outline-primary" title="<?= __('COMMON_DOWNLOAD') ?>">
                                <i class="bi bi-download"></i>
                            </a>
                            <a href="/files/<?= (int)$f['file_id'] ?>/log" class="btn btn-sm btn-outline-secondary" title="<?= __('FILES_LOG') ?>">
                                <i class="bi bi-journal-text"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>