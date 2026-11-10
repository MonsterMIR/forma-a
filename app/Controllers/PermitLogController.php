<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * ДОБАВЛЕНО (20.10.2026, раунд 103, по прямому указанию пользователя):
 * скрытый журнал исходных текстов разрешений, вставляемых ЦОК на экране
 * "Создание рейсов из разрешения" (FlightController::importParse()).
 * Текст сохраняется ДО любого парсинга/редактирования — то, что реально
 * было вставлено в систему, без возможности задним числом подправить.
 *
 * ДОСТУП СТРОГО ТОЛЬКО АДМИНИСТРАТОР (requireRole(['admin'])) — ни ЦОК,
 * ни любая другая роль не должны ни видеть эти данные, ни даже знать об
 * их существовании: нигде в обычном интерфейсе (карточка рейса, акт,
 * экран импорта) этот журнал не упоминается и на него не ссылается.
 * Пункт меню тоже защищён той же проверкой (Permissions::can('permit_logs.view')
 * -> только admin), поэтому у ЦОК он просто не отображается.
 */
class PermitLogController extends Controller
{
    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin']);

        $db = Database::getInstance();

        $filters = [
            'search'    => trim((string)$this->request->get('search', '')),
            'date_from' => trim((string)$this->request->get('date_from', '')),
            'date_to'   => trim((string)$this->request->get('date_to', '')),
        ];

        $where  = [];
        $params2 = [];

        if ($filters['search'] !== '') {
            $where[] = '(l.raw_text LIKE ? OR u.username LIKE ?)';
            $params2[] = '%' . $filters['search'] . '%';
            $params2[] = '%' . $filters['search'] . '%';
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'l.created_at >= ?';
            $params2[] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'l.created_at <= ?';
            $params2[] = $filters['date_to'] . ' 23:59:59';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM permit_raw_logs l LEFT JOIN users u ON u.id = l.imported_by_user_id {$whereSql}");
        $countStmt->execute($params2);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT l.id, l.raw_text, l.ip_address, l.created_at, u.username
                FROM permit_raw_logs l
                LEFT JOIN users u ON u.id = l.imported_by_user_id
                {$whereSql}
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params2);
        $records = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Для каждой записи — связанные рейсы (если созданы)
        if (!empty($records)) {
            $ids = array_column($records, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $linkStmt = $db->prepare(
                "SELECT lf.permit_raw_log_id, f.id AS flight_id, f.flight_number, f.operation_type, f.flight_date
                 FROM permit_raw_log_flights lf
                 JOIN flights f ON f.id = lf.flight_id
                 WHERE lf.permit_raw_log_id IN ({$in})"
            );
            $linkStmt->execute($ids);
            $byLog = [];
            foreach ($linkStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $byLog[(int)$row['permit_raw_log_id']][] = $row;
            }
            foreach ($records as &$r) {
                $r['flights'] = $byLog[(int)$r['id']] ?? [];
            }
            unset($r);
        }

        $this->render('permit_logs/index', [
            'title'      => 'Журнал разрешений',
            'records'    => $records,
            'filters'    => $filters,
            'pagination' => paginate($total, $perPage, $page),
        ]);
    }

    public function show(array $params = []): void
    {
        $this->requireRole(['admin']);

        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT l.*, u.username
             FROM permit_raw_logs l
             LEFT JOIN users u ON u.id = l.imported_by_user_id
             WHERE l.id = ? LIMIT 1"
        );
        $stmt->execute([(int)$params['id']]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$record) {
            \App\Core\View::setFlash('error', 'Запись не найдена.');
            $this->redirect('/permit-logs');
            return;
        }

        $linkStmt = $db->prepare(
            "SELECT f.id, f.flight_number, f.operation_type, f.flight_date, f.aircraft_registration
             FROM permit_raw_log_flights lf
             JOIN flights f ON f.id = lf.flight_id
             WHERE lf.permit_raw_log_id = ?
             ORDER BY f.id"
        );
        $linkStmt->execute([(int)$params['id']]);
        $flights = $linkStmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->render('permit_logs/show', [
            'title'   => 'Разрешение #' . $record['id'],
            'record'  => $record,
            'flights' => $flights,
        ]);
    }
}
