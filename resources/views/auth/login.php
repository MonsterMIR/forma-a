<div class="card shadow-sm">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <h4 class="fw-bold"><i class="bi bi-file-earmark-text text-primary"></i> Forma A</h4>
            <p class="text-muted small"><?= __('AUTH_SUBTITLE') ?></p>
        </div>
        <form method="POST" action="/login">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold"><?= __('USERS_USERNAME') ?> </label>
                <input type="text" name="username" class="form-control" required
                       autofocus autocomplete="username"
                       value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold"><?= __('AUTH_PASSWORD') ?></label>
                <input type="password" name="password" class="form-control" required
                       autocomplete="current-password">
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                </button>
            </div>
        </form>
    </div>
</div>