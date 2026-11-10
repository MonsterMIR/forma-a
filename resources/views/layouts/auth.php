<?php $appName = htmlspecialchars($_ENV['APP_NAME'] ?? 'Forma A'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($title) ? e($title) . ' — ' : '' ?><?= $appName ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime(BASE_PATH . "/public/assets/css/app.css") ?: time() ?>">
</head>
<body class="bg-light">
<div class="container">
    <div class="row justify-content-center min-vh-100 align-items-center">
        <div class="col-md-5 col-lg-4">
            <?php if (!empty($flash['error'])): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> <?= e($flash['error']) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($flash['success'])): ?>
                <div class="alert alert-success">
                    <?= e($flash['success']) ?>
                </div>
            <?php endif; ?>
            <?= $content ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>