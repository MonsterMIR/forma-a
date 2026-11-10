<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Models\FlightDocument;
use App\Models\FlightDeviation;
use App\Services\DeadlineService;

class DeviationController extends Controller
{
    private FlightDocument  $docModel;
    private FlightDeviation $devModel;
    private DeadlineService $deadlineService;

    public function __construct(
        \App\Core\Request  $request,
        \App\Core\Response $response
    ) {
        parent::__construct($request, $response);
        $this->docModel        = new FlightDocument();
        $this->devModel        = new FlightDeviation();
        $this->deadlineService = new DeadlineService();
    }

    /**
     * Журнал отклонений
     */
    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);

        $perPage = 30;
        $page    = max(1, (int)$this->request->get('page', 1));
        $offset  = ($page - 1) * $perPage;

        $filters = [
            'severity'     => $this->request->get('severity', ''),
            'service_role' => $this->request->get('service_role', ''),
            'date_from'    => $this->request->get('date_from', date('Y-m-01')),
            'date_to'      => $this->request->get('date_to', date('Y-m-d')),
        ];

        $deviations = $this->devModel->getFiltered($filters, $perPage, $offset);
        $total      = $this->devModel->countFiltered($filters);
        $summary    = $this->devModel->getSummaryByRole($filters['date_from'], $filters['date_to']);

        $this->render('deviations/index', [
            'title'      => 'Журнал отклонений',
            'deviations' => $deviations,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'filters'    => $filters,
            'summary'    => $summary,
        ]);
    }

    /**
     * Подать документ (AJAX или форма)
     */
    public function submitDoc(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'sop', 'spo', 'sst', 'aero', 'spasop']);

        $docId  = (int)($params['doc_id'] ?? 0);
        $userId = Auth::id();
        $notes  = $this->request->input('notes', '');

        // Проверяем что роль пользователя соответствует документу
        $doc = $this->docModel->find($docId);
        if (!$doc) {
            $this->json(['success' => false, 'error' => 'Документ не найден'], 404);
            return;
        }

        $user = Auth::user();
        $role = $user['role_code'] ?? '';

        // Проверка принадлежности роли (ЦОК и Админ могут подавать за всех)
        if (!in_array($role, ['admin', 'cok']) && $doc['service_role'] !== $role) {
            $this->json(['success' => false, 'error' => 'Нет доступа к этому документу'], 403);
            return;
        }

        $result = $this->deadlineService->submitDocument($docId, $userId, $notes ?: null);

        if ($this->request->isAjax()) {
            $this->json($result);
        } else {
            $flightId = $doc['flight_id'];
            View::setFlash($result['success'] ? 'success' : 'error',
                $result['message'] ?? ($result['success'] ? 'Документ отправлен' : 'Ошибка'));
            $this->redirect("/flights/{$flightId}#deadlines");
        }
    }

    /**
     * Инициализировать документы сроков для рейса
     */
    public function initFlight(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);
        $flightId = (int)($params['flight_id'] ?? 0);

        $result = $this->deadlineService->initForFlight($flightId);
        $this->json(['success' => $result]);
    }
}
