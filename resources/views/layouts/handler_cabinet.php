<?php
/**
 * ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): отдельный минимальный layout для
 * личного кабинета хендлера — по образцу layouts/portal.php (раунд 49).
 * Сознательно НЕ переиспользует layouts/main.php — там полное меню ЦОК/
 * служб, которое хендлеру ни видеть, ни тем более использовать не следует
 * (роль handler_admin не участвует в общей матрице Permissions, см.
 * Permissions::HANDLER_ADMIN).
 */
$appName = htmlspecialchars($_ENV['APP_NAME'] ?? 'Forma A');
$user    = $auth_user ?? null;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(\App\Core\Csrf::token()) ?>">
    <title><?= isset($title) ? e($title) . ' — ' : '' ?><?= $appName ?> · Кабинет хендлера</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime(BASE_PATH . "/public/assets/css/app.css") ?: time() ?>">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
    <div class="container-fluid px-3">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="/handler-cabinet">
            <span class="brand-icon">✈</span>
            <span><?= $appName ?> · Кабинет хендлера</span>
        </a>
        <div class="d-flex align-items-center gap-3">
            <?php if ($user): ?>
                <span class="text-light small">
                    <i class="bi bi-person-circle"></i> <?= e($user['full_name'] ?? $user['username'] ?? '') ?>
                </span>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-light" href="/logout">
                <i class="bi bi-box-arrow-right"></i> Выйти
            </a>
        </div>
    </div>
</nav>

<div class="container my-4">
    <?php if (!empty($flash['error'])): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= e($flash['error']) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash['success'])): ?>
        <div class="alert alert-success"><?= e($flash['success']) ?></div>
    <?php endif; ?>

    <?= $content ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
