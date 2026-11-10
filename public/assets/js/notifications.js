'use strict';

// ДОБАВЛЕНО (06.08.2026): колокольчик уведомлений в шапке + подписка на
// Web Push. Если пользователь не залогинен, notifBell в разметке просто
// не будет — весь код ниже проверяет это на входе.

document.addEventListener('DOMContentLoaded', function () {
    const bell = document.getElementById('notifBell');
    if (!bell) return; // не залогинен — main.php не рендерит колокольчик

    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
    const countBadge = document.getElementById('notifCount');
    const listEl = document.getElementById('notifList');

    function timeAgo(dateStr) {
        // dateStr приходит как "YYYY-MM-DD HH:MM:SS" из PDO — читаем как локальное время
        const d = new Date(dateStr.replace(' ', 'T'));
        const diffMin = Math.round((Date.now() - d.getTime()) / 60000);
        if (diffMin < 1) return 'только что';
        if (diffMin < 60) return diffMin + ' мин назад';
        const diffH = Math.round(diffMin / 60);
        if (diffH < 24) return diffH + ' ч назад';
        return d.toLocaleDateString('ru-RU');
    }

    function render(data) {
        if (data.count > 0) {
            countBadge.textContent = data.count > 99 ? '99+' : data.count;
            countBadge.classList.remove('d-none');
        } else {
            countBadge.classList.add('d-none');
        }

        if (!data.items || data.items.length === 0) {
            listEl.innerHTML = '<li class="px-3 py-4 text-center text-muted small">Уведомлений нет</li>';
            return;
        }

        listEl.innerHTML = data.items.map(function (n) {
            const unread = !n.read_at;
            return '<li>' +
                '<a class="dropdown-item py-2 px-3 notif-item ' + (unread ? 'bg-light' : '') + '" ' +
                     'href="' + (n.url || '/notifications') + '" data-id="' + n.id + '">' +
                    '<div class="d-flex justify-content-between align-items-start gap-2">' +
                        '<div class="fw-semibold small">' + (unread ? '<i class="bi bi-circle-fill text-primary" style="font-size:6px;"></i> ' : '') + escapeHtml(n.title) + '</div>' +
                        '<div class="text-muted flex-shrink-0" style="font-size:0.7rem">' + timeAgo(n.created_at) + '</div>' +
                    '</div>' +
                    (n.body ? '<div class="text-muted small mt-1">' + escapeHtml(n.body) + '</div>' : '') +
                '</a>' +
            '</li>';
        }).join('');
    }

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s || '';
        return div.innerHTML;
    }

    function poll() {
        fetch('/notifications/unread-count', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () { /* тихо игнорируем — не критично */ });
    }

    listEl.addEventListener('click', function (e) {
        const item = e.target.closest('.notif-item');
        if (!item) return;
        const id = item.dataset.id;
        fetch('/notifications/' + id + '/read', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }
        }).catch(function () {});
        // Переход по ссылке — обычное поведение <a>, не отменяем.
    });

    const markAllBtn = document.getElementById('notifMarkAllRead');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.preventDefault();
            fetch('/notifications/mark-all-read', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }
            }).then(poll).catch(function () {});
        });
    }

    poll();
    setInterval(poll, 30000);

    // ── Web Push подписка ──────────────────────────────────────────────
    subscribeToPush(csrf);
});

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function subscribeToPush(csrf) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return; // старый браузер / не поддерживается — колокольчик всё равно работает
    }

    fetch('/push/public-key', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.publicKey) return; // сервер ещё не настроен (нет VAPID-ключей) — молча выходим

            return navigator.serviceWorker.register('/sw.js').then(function (registration) {
                return registration.pushManager.getSubscription().then(function (existing) {
                    if (existing) return existing;

                    // Разрешение запрашиваем только по факту логина, не при каждой загрузке
                    // страницы — Notification.permission проверяем сначала.
                    if (Notification.permission === 'denied') return null;

                    return registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(data.publicKey)
                    });
                });
            });
        })
        .then(function (subscription) {
            if (!subscription) return;
            const json = subscription.toJSON();
            const body = new URLSearchParams();
            body.append('_csrf', csrf);
            body.append('endpoint', json.endpoint);
            body.append('p256dh', json.keys.p256dh);
            body.append('auth', json.keys.auth);

            return fetch('/push/subscribe', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
        })
        .catch(function (err) {
            // Пользователь мог просто отказать в разрешении на уведомления —
            // это нормальный сценарий, не ошибка.
            console.warn('Web Push подписка не оформлена:', err && err.message);
        });
}
