<div class="card shadow-sm">
    <div class="card-body p-4 text-center">
        <div class="display-4 fw-bold text-warning mb-3">419</div>
        <h4><?= __('ERROR_419_TITLE') ?></h4>
        <p class="text-muted">Your session has expired or the CSRF token is invalid.</p>
        <a href="javascript:history.back()" class="btn btn-outline-secondary me-2"><?= __('ERROR_GO_BACK') ?></a>
        <a href="/login" class="btn btn-primary"><?= __('ERROR_LOGIN_AGAIN') ?></a>
    </div>
</div>