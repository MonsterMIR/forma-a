<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-journal-text"></i> File Log</h2>
    <a href="javascript:history.back()" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
</div>

<div class="card border-0 shadow-sm mb-4" style="max-width:700px">
    <div class="card-header bg-white"><h6 class="mb-0 fw-semibold">File Details</h6></div>
    <div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-sm-4">Original Name</dt>
            <dd class="col-sm-8"><?= e($file['original_name']) ?></dd>
            <dt class="col-sm-4">Stored Name</dt>
            <dd class="col-sm-8 font-monospace"><?= e($file['stored_name']) ?></dd>
            <dt class="col-sm-4">MIME Type</dt>
            <dd class="col-sm-8"><?= e($file['mime_type'] ?? '—') ?></dd>
            <dt class="col-sm-4">Size</dt>
            <dd class="col-sm-8"><?= number_format($file['file_size_bytes'] / 1024, 2) ?> KB</dd>
            <dt class="col-sm-4">SHA256</dt>
            <dd class="col-sm-8 font-monospace small"><?= e($file['sha256_hash'] ?? '—') ?></dd>
            <dt class="col-sm-4"><?= __('COMMON_STATUS') ?></dt>
            <dd class="col-sm-8"><span class="badge bg-secondary"><?= e($file['upload_status']) ?></span></dd>
            <dt class="col-sm-4">Uploaded By</dt>
            <dd class="col-sm-8"><?= e($file['uploader_username'] ?? '—') ?></dd>
            <dt class="col-sm-4">Uploaded At</dt>
            <dd class="col-sm-8"><?= format_datetime($file['uploaded_at']) ?></dd>
            <dt class="col-sm-4">Operation Count</dt>
            <dd class="col-sm-8"><span class="badge bg-info text-dark"><?= (int)$file['operation_count'] ?></span></dd>
        </dl>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h6 class="mb-0 fw-semibold">Operation History</h6></div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th><?= __('FILES_OP_TYPE') ?></th>
                    <th><?= __('AUDIT_RESULT') ?></th>
                    <th><?= __('AUDIT_USER') ?></th>
                    <th><?= __('FILES_DETAILS') ?></th>
                    <th><?= __('AUDIT_WHEN') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?= __('FILES_NO_OPERATIONS') ?></td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="text-muted small"><?= (int)$log['id'] ?></td>
                    <td><span class="badge bg-secondary"><?= e($log['operation_type']) ?></span></td>
                    <td>
                        <?php if ($log['operation_result'] === 'success'): ?>
                            <span class="badge bg-success">success</span>
                        <?php else: ?>
                            <span class="badge bg-danger">failed</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e($log['username'] ?? '—') ?></td>
                    <td class="small text-muted"><?= e($log['details'] ?? '—') ?></td>
                    <td class="small text-muted"><?= format_datetime($log['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>