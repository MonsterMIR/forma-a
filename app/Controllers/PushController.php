<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Models\PushSubscription;

/**
 * ДОБАВЛЕНО (06.08.2026): подписка/отписка браузера на Web Push.
 * JS-сторона — public/assets/js/notifications.js.
 */
class PushController extends Controller
{
    private PushSubscription $model;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model = new PushSubscription();
    }

    /**
     * Публичный VAPID-ключ для pushManager.subscribe() на клиенте.
     * Пустой publicKey означает "push ещё не настроен на сервере" —
     * JS должен тихо не пытаться подписаться (см. notifications.js).
     */
    public function publicKey(array $params = []): void
    {
        $configFile = BASE_PATH . '/config/push.php';
        $publicKey = '';
        if (file_exists($configFile)) {
            $config = require $configFile;
            $publicKey = $config['vapid_public_key'] ?? '';
        }
        $this->json(['publicKey' => $publicKey]);
    }

    public function subscribe(array $params = []): void
    {
        $userId = Auth::id();
        if (!$userId) { $this->json(['success' => false], 401); return; }

        if (!Csrf::verifyRequest($this->request)) {
            $this->json(['success' => false, 'message' => 'CSRF'], 419);
            return;
        }

        $endpoint = (string)$this->request->input('endpoint', '');
        $p256dh   = (string)$this->request->input('p256dh', '');
        $auth     = (string)$this->request->input('auth', '');

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            $this->json(['success' => false, 'message' => 'Неполные данные подписки.']);
            return;
        }

        $this->model->upsert((int)$userId, $endpoint, $p256dh, $auth, $this->request->userAgent ?: null);
        $this->json(['success' => true]);
    }

    public function unsubscribe(array $params = []): void
    {
        $userId = Auth::id();
        if (!$userId) { $this->json(['success' => false], 401); return; }

        if (!Csrf::verifyRequest($this->request)) {
            $this->json(['success' => false, 'message' => 'CSRF'], 419);
            return;
        }

        $endpoint = (string)$this->request->input('endpoint', '');
        if ($endpoint !== '') {
            $this->model->deleteByEndpointValue((int)$userId, $endpoint);
        }
        $this->json(['success' => true]);
    }
}
