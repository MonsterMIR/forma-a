<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Notification;

/**
 * ДОБАВЛЕНО (06.08.2026): колокольчик уведомлений в шапке — список,
 * счётчик непрочитанных (опрашивается JS раз в 30 сек), отметка прочтения.
 */
class NotificationController extends Controller
{
    private Notification $model;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model = new Notification();
    }

    /** Полный список уведомлений — отдельная страница (со ссылки "Все уведомления"). */
    public function index(array $params = []): void
    {
        $userId = Auth::id();
        $notifications = $this->model->getForUser((int)$userId, 100);

        $this->render('notifications/index', [
            'title'         => 'Уведомления',
            'notifications' => $notifications,
        ]);
    }

    /** JSON для колокольчика: непрочитанные + счётчик, опрашивается периодически. */
    public function unreadCount(array $params = []): void
    {
        $userId = Auth::id();
        if (!$userId) {
            $this->json(['count' => 0, 'items' => []]);
            return;
        }
        $this->json([
            'count' => $this->model->unreadCount((int)$userId),
            'items' => $this->model->getForUser((int)$userId, 8),
        ]);
    }

    public function markRead(array $params = []): void
    {
        $userId = Auth::id();
        $id = (int)$params['id'];
        $ok = $userId ? $this->model->markRead($id, (int)$userId) : false;
        $this->json(['success' => $ok]);
    }

    public function markAllRead(array $params = []): void
    {
        $userId = Auth::id();
        $count = $userId ? $this->model->markAllRead((int)$userId) : 0;
        $this->json(['success' => true, 'marked' => $count]);
    }
}
