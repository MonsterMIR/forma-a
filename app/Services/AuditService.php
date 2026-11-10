<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ActivityLogger;
use App\Models\UserActivityLog;

class AuditService
{
    private UserActivityLog $logModel;

    public function __construct()
    {
        $this->logModel = new UserActivityLog();
    }

    public function getLog(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $offset  = ($page - 1) * $perPage;
        $records = $this->logModel->getFiltered($filters, $perPage, $offset);
        $total   = $this->logModel->countFiltered($filters);

        ActivityLogger::log(
            'audit', null, 'view_audit_log', 'success',
            'Viewed audit log'
        );

        return [
            'records'  => $records,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }
}