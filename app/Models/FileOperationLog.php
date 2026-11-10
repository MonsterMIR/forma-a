<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class FileOperationLog extends Model
{
    protected string $table = 'file_operation_logs';

    public function getByFile(int $fileId): array
    {
        $stmt = $this->query(
            "SELECT fol.*, u.username
             FROM `file_operation_logs` fol
             LEFT JOIN `users` u ON u.id = fol.user_id
             WHERE fol.file_id = ?
             ORDER BY fol.created_at DESC",
            [$fileId]
        );
        return $stmt->fetchAll();
    }

    public function record(int $fileId, ?int $userId, ?int $sessionId, string $operationType, string $result = 'success', ?string $details = null): void
    {
        $this->insert([
            'file_id'          => $fileId,
            'user_id'          => $userId,
            'session_id'       => $sessionId,
            'operation_type'   => $operationType,
            'operation_result' => $result,
            'details'          => $details,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
    }
}