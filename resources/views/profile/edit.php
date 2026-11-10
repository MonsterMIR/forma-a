<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-person-vcard"></i> Личные данные</h2>
</div>

<div class="card border-0 shadow-sm" style="max-width:560px">
    <div class="card-body">
        <div class="mb-3 pb-3 border-bottom">
            <div class="fw-semibold"><?= e($me['full_name']) ?></div>
            <div class="text-muted small">
                Логин: <code><?= e($me['username']) ?></code> ·
                Роль: <?= e($me['role_name'] ?? $me['role_code']) ?>
            </div>
            <div class="text-muted small">
                Имя, логин и роль меняет администратор — обратитесь к нему,
                если их нужно поправить. Здесь можно указать контактные данные
                для уведомлений (email/телефон/телеграм).
            </div>
        </div>

        <form method="POST" action="/profile">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">Email</label>
                <input type="email" name="email" class="form-control" maxlength="150"
                       value="<?= e($me['email'] ?? '') ?>" placeholder="you@example.com">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Телефон</label>
                <input type="text" name="phone" class="form-control" maxlength="30"
                       value="<?= e($me['phone'] ?? '') ?>" placeholder="+998 90 123-45-67">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Телеграм — юзернейм (справочно)</label>
                <div class="input-group">
                    <span class="input-group-text">@</span>
                    <input type="text" name="telegram_username" class="form-control" maxlength="64"
                           value="<?= e($me['telegram_username'] ?? '') ?>" placeholder="username">
                </div>
            </div>

            <?php
            // ДОБАВЛЕНО (30.10.2026, раунд 139): личный выбор стиля дашборда.
            // Внутри той же формы (не отдельным POST'ом) — иначе пришлось бы
            // либо дублировать email/phone/telegram скрытыми полями, либо
            // переписывать ProfileController::update(), который сейчас всегда
            // перезаписывает все три поля из $data. Один "Сохранить" — все
            // изменения сразу, как и раньше для этой страницы.
            if (!empty($canPickDashboardStyle)):
                $currentStyle = $me['dashboard_style'] ?? 'classic';
                $styleOptions = [
                    ['value' => 'classic', 'label' => 'Классический', 'desc' => 'Нынешний вид — без изменений.', 'icon' => 'bi-layout-text-sidebar-reverse'],
                    ['value' => 'a',       'label' => 'А — Спокойная эволюция', 'desc' => 'Та же структура, доведённая до блеска.', 'icon' => 'bi-grid-3x3-gap'],
                    ['value' => 'b',       'label' => 'Б — Командный центр',   'desc' => 'Таблица рейсов — на весь экран, срочное — баннером.', 'icon' => 'bi-airplane'],
                    ['value' => 'c',       'label' => 'В — Карточки-сводки',   'desc' => 'Утренняя сводка: крупные карточки вместо таблицы.', 'icon' => 'bi-grid-1x2'],
                ];
            ?>
            <div class="mb-3 pt-2 border-top">
                <label class="form-label fw-semibold d-block mt-3">Стиль дашборда</label>
                <div class="text-muted small mb-2">
                    Влияет только на ваш собственный рабочий стол — у коллег остаётся их собственный выбор.
                </div>
                <div class="style-pick-grid">
                    <?php foreach ($styleOptions as $opt): ?>
                    <label class="style-pick-card">
                        <input type="radio" name="dashboard_style" value="<?= e($opt['value']) ?>"
                               class="style-pick-input"
                               <?= $currentStyle === $opt['value'] ? 'checked' : '' ?>>
                        <span class="style-pick-icon"><i class="bi <?= e($opt['icon']) ?>"></i></span>
                        <span class="style-pick-text">
                            <span class="style-pick-title"><?= e($opt['label']) ?></span>
                            <span class="style-pick-desc"><?= e($opt['desc']) ?></span>
                        </span>
                        <span class="style-pick-check"><i class="bi bi-check-circle-fill"></i></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Сохранить</button>
        </form>

        <div class="mt-4 pt-3 border-top">
            <label class="form-label fw-semibold d-block">Уведомления в Telegram</label>
            <?php if (!empty($me['telegram_chat_id'])): ?>
                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Telegram привязан</span>
                <div class="form-text">Уведомления о новых рейсах будут приходить в Telegram.</div>
            <?php elseif (!empty($telegramLinkUrl)): ?>
                <a href="<?= e($telegramLinkUrl) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-telegram me-1"></i>Привязать Telegram
                </a>
                <div class="form-text">
                    Откроется чат с ботом — нажмите Start там, и мы сохраним связь
                    с вашим аккаунтом. Одного юзернейма выше недостаточно — Telegram
                    не позволяет боту писать первым только по имени пользователя.
                </div>
            <?php else: ?>
                <div class="form-text text-muted">
                    Telegram-уведомления пока не настроены администратором.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
