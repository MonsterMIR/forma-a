<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Validator;
use App\Core\Auth;
use App\Core\ActivityLogger;
use App\Core\Database;
use App\Models\SystemSetting;

/**
 * InspectionController — журнал осмотров ВПП и перрона
 *
 * Доступ: aero (аэродромная служба), cok, admin
 * Осмотр ВПП/перрона — обязательное условие для любого рейса.
 * Срок действия осмотра: 3 часа (настраивается в system_settings).
 */
class InspectionController extends Controller
{
    private \PDO $db;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->db = Database::getInstance();
    }

    /** Список осмотров */
    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'aero']);

        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $type    = $this->request->get('type', '');
        $date    = $this->request->get('date', '');

        $where  = ['1=1'];
        $binds  = [];

        if ($type) {
            $where[] = 'i.inspection_type = ?';
            $binds[] = $type;
        }
        if ($date) {
            $where[] = 'DATE(i.inspected_at) = ?';
            $binds[] = $date;
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->db->prepare(
            "SELECT i.*, u.full_name AS created_by_name,
                    CASE WHEN i.valid_until >= NOW() THEN 1 ELSE 0 END AS is_active_now
             FROM periodic_inspections i
             LEFT JOIN users u ON u.id = i.created_by_user_id
             WHERE {$whereStr}
             ORDER BY i.inspected_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($binds);
        $inspections = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmtCount = $this->db->prepare(
            "SELECT COUNT(*) FROM periodic_inspections i WHERE {$whereStr}"
        );
        $stmtCount->execute($binds);
        $total = (int)$stmtCount->fetchColumn();

        // Текущий статус ВПП и перрона
        $currentStatus = $this->getCurrentStatus();

        $this->render('inspections/index', [
            'title'         => 'Журнал осмотров ВПП и перрона',
            'inspections'   => $inspections,
            'current'       => $currentStatus,
            'filters'       => compact('type', 'date'),
            'pagination'    => paginate($total, $perPage, $page),
        ]);
    }

    /** Форма нового осмотра */
    public function create(array $params = []): void
    {
        $this->requireRole(['admin', 'aero']);
        $this->render('inspections/form', [
            'title'       => 'Зафиксировать осмотр ВПП / перрона',
            'inspection'  => null,
            'validHours'  => $this->getValidHours(),
        ]);
    }

    /** Сохранить осмотр */
    public function store(array $params = []): void
    {
        $this->requireRole(['admin', 'aero']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/inspections/create');
            return;
        }

        $data = $this->request->all();

        $v = new Validator($data);
        $v->required('inspection_type', 'Тип осмотра')
          ->required('inspected_at',    'Дата и время осмотра')
          ->required('result',          'Результат')
          ->inList('inspection_type', ['runway', 'apron', 'both'], 'Тип осмотра')
          ->inList('result', ['clear', 'issues_found', 'restricted', 'closed'], 'Результат');

        if ($v->fails()) {
            View::setFlash('error', $v->firstError());
            $this->redirect('/inspections/create');
            return;
        }

        $validHours  = $this->getValidHours($data['inspection_type']);
        $inspectedAt = $data['inspected_at'];
        $validUntil  = date('Y-m-d H:i:s',
            strtotime($inspectedAt) + $validHours * 3600
        );

        $stmt = $this->db->prepare(
            "INSERT INTO periodic_inspections
             (inspection_type, inspected_at, valid_until, result,
              issues_notes, inspector_name, inspector_user_id, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['inspection_type'],
            $inspectedAt,
            $validUntil,
            $data['result'],
            !empty($data['issues_notes']) ? trim($data['issues_notes']) : null,
            !empty($data['inspector_name']) ? trim($data['inspector_name']) : null,
            Auth::id(),
            Auth::id(),
        ]);

        $id = (int)$this->db->lastInsertId();

        ActivityLogger::log('inspection', $id, 'create_inspection', 'success',
            "Осмотр {$data['inspection_type']}: {$data['result']}, действителен до {$validUntil}"
        );

        View::setFlash('success',
            "Осмотр зафиксирован. Действителен до {$validUntil} ({$validHours}ч)."
        );
        $this->redirect('/inspections');
    }

    /**
     * API: найти действующий осмотр на указанное время
     * GET /inspections/check?type=runway&at=2026-07-06 14:30:00
     * Используется при buildAct в FlightController
     */
    public function check(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'aero']);

        $type = $this->request->get('type', 'both');
        $at   = $this->request->get('at', date('Y-m-d H:i:s'));

        $typeCondition = $type === 'both'
            ? "inspection_type IN ('runway','apron','both')"
            : "inspection_type IN ('{$type}','both')";

        $stmt = $this->db->prepare(
            "SELECT * FROM periodic_inspections
             WHERE {$typeCondition}
               AND inspected_at <= ?
               AND valid_until  >= ?
               AND result IN ('clear','restricted')
             ORDER BY inspected_at DESC
             LIMIT 1"
        );
        $stmt->execute([$at, $at]);
        $inspection = $stmt->fetch(\PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode([
            'found'      => (bool)$inspection,
            'inspection' => $inspection ?: null,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Текущий статус ВПП и перрона (для дашборда) */
    private function getCurrentStatus(): array
    {
        $now = date('Y-m-d H:i:s');
        $result = [];
        foreach (['runway', 'apron'] as $type) {
            $stmt = $this->db->prepare(
                "SELECT * FROM periodic_inspections
                 WHERE inspection_type IN (?, 'both')
                   AND inspected_at <= ?
                   AND valid_until  >= ?
                   AND result != 'closed'
                 ORDER BY inspected_at DESC LIMIT 1"
            );
            $stmt->execute([$type, $now, $now]);
            $result[$type] = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        return $result;
    }

    /** Срок действия осмотра в часах из system_settings */
    private function getValidHours(string $type = 'both'): int
    {
        $key = match($type) {
            'runway' => 'runway_inspection_valid_hours',
            'apron'  => 'apron_inspection_valid_hours',
            default  => 'runway_inspection_valid_hours',
        };
        $stmt = $this->db->prepare(
            "SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1"
        );
        $stmt->execute([$key]);
        return max(1, (int)($stmt->fetchColumn() ?: 3));
    }
}
