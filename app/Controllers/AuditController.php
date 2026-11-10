<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;
use App\Services\AuditService;

class AuditController extends Controller
{
    private AuditService $auditService;
    private User         $userModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->auditService = new AuditService();
        $this->userModel    = new User();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        $filters = [
            'user_id'       => $this->request->get('user_id', ''),
            'entity_type'   => $this->request->get('entity_type', ''),
            'action'        => $this->request->get('action', ''),
            'action_result' => $this->request->get('action_result', ''),
            'date_from'     => $this->request->get('date_from', ''),
            'date_to'       => $this->request->get('date_to', ''),
            'search'        => $this->request->get('search', ''),
        ];

        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 50;

        $result = $this->auditService->getLog($filters, $page, $perPage);
        $users  = $this->userModel->findAllWithRole([], 200, 0);

        $this->render('audit/index', [
            'title'      => 'Audit Log',
            'records'    => $result['records'],
            'users'      => $users,
            'filters'    => $filters,
            'pagination' => paginate($result['total'], $perPage, $page),
        ]);
    }
}