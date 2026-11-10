<?php
use App\Core\Permissions;
$appName = htmlspecialchars($_ENV['APP_NAME'] ?? 'Forma A');
$user    = $auth_user ?? null;
$lang    = \App\Core\Lang::get();
$role    = $user['role_code'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- ДОБАВЛЕНО (аудит 10.07.2026): нужен для fetch()-запросов с JSON-телом
         (init-deadlines, documents/submit) — раньше JS искал этот тег, но
         его не было, поэтому токен реально никогда не отправлялся. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(\App\Core\Csrf::token()) ?>">
    <title><?= isset($title) ? e($title) . ' — ' : '' ?><?= $appName ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime(BASE_PATH . "/public/assets/css/app.css") ?: time() ?>">
    <style>
        .role-badge-cok        { background: #0d6efd; }
        .role-badge-sop        { background: #0dcaf0; color:#000; }
        .role-badge-spo        { background: #ffc107; color:#000; }
        .role-badge-sst        { background: #6c757d; }
        .role-badge-aero       { background: #198754; }
        .role-badge-commercial { background: #212529; }
        .role-badge-finance    { background: #6f42c1; }
        .role-badge-spasop     { background: #dc3545; }
        .role-badge-admin      { background: #dc3545; }
        <?php /* ДОБАВЛЕНО (14.09.2026, раунд 41): новые владельцы услуг. */ ?>
        .role-badge-gsm        { background: #fd7e14; }
        .role-badge-medical    { background: #d63384; }
        .role-badge-balance    { background: #6610f2; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
    <div class="container-fluid px-3">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="/dashboard">
            <span class="brand-icon">✈</span>
            <span><?= $appName ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                <!-- Dashboard — все -->
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/dashboard') || ($_SERVER['REQUEST_URI'] ?? '') === '/' ? 'active' : '' ?>"
                       href="/dashboard">
                        <i class="bi bi-speedometer2"></i> <?= __('NAV_DASHBOARD') ?>
                    </a>
                </li>

                <!-- Акты — ЦОК, Коммерческая, Финансовая, Админ -->
                <?php if (Permissions::can('acts.view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/acts') ? 'active' : '' ?>"
                       href="/acts">
                        <i class="bi bi-file-earmark-check"></i> <?= __('NAV_ACTS') ?>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Рейсы — все роли -->
                <?php if (Permissions::can('flights.view')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-airplane"></i> <?= __('NAV_FLIGHTS') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="/flights">
                            <i class="bi bi-list-ul me-2"></i>Все рейсы
                        </a></li>
                        <li><a class="dropdown-item" href="/aircraft-status">
                            <i class="bi bi-airplane-engines me-2"></i>Справочник бортов
                        </a></li>
                        <?php if (Permissions::can('flights.create')): ?>
                        <li><a class="dropdown-item" href="/flights/create">
                            <i class="bi bi-plus-circle me-2"></i>Новый рейс
                        </a></li>
                        <li><a class="dropdown-item" href="/flights/import">
                            <i class="bi bi-cloud-arrow-down me-2"></i>Импорт из разрешения
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php /* ДОБАВЛЕНО (20.10.2026, раунд 104, по прямому указанию пользователя):
                   супервайзеру включили просмотр перечня стоянок и занятости (какой борт
                   где стоит), см. Permissions::parking_stands.view. Условие на !manage —
                   чтобы не дублировать пункт для admin/aero, у которых то же самое уже есть
                   в выпадающем списке "Справочники" → "Стоянки (МС№)" (parking_stands.manage). */ ?>
                <?php if (Permissions::can('parking_stands.view') && !Permissions::can('parking_stands.manage')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/parking-stands') ? 'active' : '' ?>"
                       href="/parking-stands">
                        <i class="bi bi-p-square"></i> Стоянки
                    </a>
                </li>
                <?php endif; ?>

                <!-- Справочники — ЦОК и Админ -->
                <?php if (Permissions::can('airlines.view')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-grid"></i> <?= __('NAV_REFERENCES') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="/airlines">
                            <i class="bi bi-building me-2"></i><?= __('NAV_AIRLINES') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/airports">
                            <i class="bi bi-geo-alt me-2"></i><?= __('NAV_AIRPORTS') ?>
                        </a></li>
                        <?php if (Permissions::can('handlers.view')): ?>
                        <li><a class="dropdown-item <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/handlers') ? 'active' : '' ?>" href="/handlers">
                            <i class="bi bi-person-badge me-2"></i>Хендлеры
                        </a></li>
                        <?php endif; ?>
                        <?php if (Permissions::can('services.view')): ?>
                        <li><hr class="dropdown-divider opacity-25"></li>
                        <li><h6 class="dropdown-header text-uppercase small opacity-50"><?= __('NAV_SERVICES') ?></h6></li>
                        <li><a class="dropdown-item" href="/service-categories">
                            <i class="bi bi-tags me-2"></i><?= __('NAV_SERVICE_CATEGORIES') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/services">
                            <i class="bi bi-list-check me-2"></i><?= __('NAV_SERVICES') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/tariffs">
                            <i class="bi bi-currency-dollar me-2"></i><?= __('NAV_TARIFFS') ?>
                        </a></li>
                        <?php if (Permissions::can('parking_norms.manage')): ?>
                        <li><a class="dropdown-item" href="/airline-parking-norms">
                            <i class="bi bi-clock-history me-2"></i>Нормы стоянки
                        </a></li>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php /* ИСПРАВЛЕНО (18.08.2026, раунд 18): вынесено из-под
                           services.view — у aero этого права нет (не связано с
                           ценами/тарифами), а именно aero теперь тоже управляет
                           справочником стоянок (см. Permissions::
                           parking_stands.manage), иначе пункт меню был бы для неё
                           недостижим, хотя контроллер уже разрешал доступ. */ ?>
                        <?php if (Permissions::can('parking_stands.manage')): ?>
                        <li><a class="dropdown-item" href="/parking-stands">
                            <i class="bi bi-p-square me-2"></i>Стоянки (МС№)
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Экспорт в 1С — только Финансовая и Админ -->
                <?php if (Permissions::can('export.view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/export') ? 'active' : '' ?>"
                       href="/export">
                        <i class="bi bi-box-arrow-up-right me-1"></i>1С
                    </a>
                </li>
                <?php endif; ?>

                <!-- Документы служб -->
                <?php if (Permissions::can('manifests.view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/manifests') ? 'active' : '' ?>"
                       href="/manifests">
                        <i class="bi bi-people"></i> Манифесты
                    </a>
                </li>
                <?php endif; ?>
                <?php if (Permissions::can('techcards.view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/tech-cards') ? 'active' : '' ?>"
                       href="/tech-cards">
                        <i class="bi bi-clipboard-check"></i> Техкарты
                    </a>
                </li>
                <?php endif; ?>
                <?php if (Permissions::can('equipment.view')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/equipment') ? 'active' : '' ?>"
                       href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-truck"></i> Спецтехника
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="/equipment-logs">
                            <i class="bi bi-journal-text me-2"></i>Журналы
                        </a></li>
                        <?php if (Permissions::can('equipment_types.manage')): ?>
                        <li><a class="dropdown-item" href="/equipment-types">
                            <i class="bi bi-gear me-2"></i>Справочник техники
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if (Permissions::can('parking.view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/parking-acts') ? 'active' : '' ?>"
                       href="/parking-acts">
                        <i class="bi bi-p-square"></i> Стоянки
                    </a>
                </li>
                <?php endif; ?>

                <?php if (Permissions::can('emergency.view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/emergency-logs') ? 'active' : '' ?>"
                       href="/flights">
                        <i class="bi bi-shield-fill-exclamation text-danger"></i> СПАСОП
                    </a>
                </li>
                <?php endif; ?>

                <!-- Журнал отклонений — ЦОК, Коммерческая, Финансовая, Админ -->
                <?php if (Permissions::can('deviations.view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/deviations') ? 'active' : '' ?>"
                       href="/deviations">
                        <i class="bi bi-exclamation-triangle"></i> Отклонения
                    </a>
                </li>
                <?php endif; ?>

                <!-- Аудит — ЦОК и Админ -->
                <?php if (Permissions::can('audit.view')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/audit') ? 'active' : '' ?>"
                       href="/audit">
                        <i class="bi bi-journal-text"></i> <?= __('NAV_AUDIT') ?>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Администрирование — только Админ -->
                <?php if (Permissions::can('users.manage')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-shield-lock"></i> <?= __('NAV_ADMIN') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="/users">
                            <i class="bi bi-people me-2"></i><?= __('NAV_USERS') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/roles">
                            <i class="bi bi-shield-check me-2"></i><?= __('NAV_ROLES') ?>
                        </a></li>
                        <li><hr class="dropdown-divider opacity-25"></li>
                        <li><a class="dropdown-item" href="/settings">
                            <i class="bi bi-gear me-2"></i><?= __('NAV_SETTINGS') ?>
                        </a></li>
                        <?php if (Permissions::can('permit_logs.view')): ?>
                        <li><a class="dropdown-item" href="/permit-logs">
                            <i class="bi bi-file-earmark-lock2 me-2"></i>Журнал разрешений
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

            </ul>

            <!-- Правая часть: пользователь -->
            <!-- Переключатель языка отключён по требованию заказчика (только RU). -->
            <ul class="navbar-nav ms-auto align-items-center gap-2">

                <!-- ДОБАВЛЕНО (06.08.2026): колокольчик уведомлений -->
                <?php if ($user): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link position-relative" href="#" id="notifBell" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                        <i class="bi bi-bell fs-5"></i>
                        <span id="notifCount" class="badge bg-danger rounded-pill position-absolute d-none"
                              style="top:2px; left:20px; font-size:0.6rem;">0</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-dark dropdown-menu-end p-0" style="width:340px; max-height:70vh; overflow-y:auto;">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom border-secondary">
                            <span class="fw-semibold small">Уведомления</span>
                            <a href="#" id="notifMarkAllRead" class="small text-decoration-none">Прочитать всё</a>
                        </div>
                        <ul class="list-unstyled mb-0" id="notifList">
                            <li class="px-3 py-4 text-center text-muted small">Загрузка…</li>
                        </ul>
                        <div class="text-center py-2 border-top border-secondary">
                            <a href="/notifications" class="small text-decoration-none">Все уведомления</a>
                        </div>
                    </div>
                </li>
                <?php endif; ?>

                <!-- Пользователь -->
                <?php if ($user): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
                        <span class="badge role-badge-<?= htmlspecialchars($role) ?> rounded-pill px-2">
                            <i class="bi <?= Permissions::roleIcon($role) ?>"></i>
                        </span>
                        <span class="d-none d-md-inline small"><?= htmlspecialchars($user['full_name']) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
                        <li>
                            <h6 class="dropdown-header">
                                <div><?= htmlspecialchars($user['full_name']) ?></div>
                                <small class="opacity-75"><?= htmlspecialchars(Permissions::allRoles()[$role] ?? $role) ?></small>
                            </h6>
                        </li>
                        <li><a class="dropdown-item <?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/profile') ? 'active' : '' ?>" href="/profile">
                            <i class="bi bi-person-vcard me-2"></i>Личные данные
                        </a></li>
                        <li><hr class="dropdown-divider opacity-25"></li>
                        <li><a class="dropdown-item text-danger" href="/logout">
                            <i class="bi bi-box-arrow-right me-2"></i><?= __('NAV_LOGOUT') ?>
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Контент -->
<main class="container-fluid px-4 py-3">
    <?php if (!empty($flash)): ?>
        <?php foreach ($flash as $flashType => $flashMsg): ?>
        <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars((string)$flashMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?= $content ?? '' ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
<script src="/assets/js/app.js?v=<?= @filemtime(BASE_PATH . "/public/assets/js/app.js") ?: time() ?>"></script>
<script src="/assets/js/notifications.js?v=<?= @filemtime(BASE_PATH . "/public/assets/js/notifications.js") ?: time() ?>"></script>
</body>
</html>
