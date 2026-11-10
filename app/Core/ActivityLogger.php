<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\UserActivityLog;

class ActivityLogger
{
    public static function log(
        string  $entityType,
        ?int    $entityId,
        string  $action,
        string  $result = 'success',
        ?string $description = null,
        ?array  $oldValues = null,
        ?array  $newValues = null
    ): void {
        try {
            $user      = Auth::user();
            $userId    = $user ? (int)$user['id'] : null;
            $sessionId = $user ? (int)($user['session_db_id'] ?? null) : null;

            $request = new Request();

            $model = new UserActivityLog();
            $model->insert([
                'user_id'       => $userId,
                'session_id'    => $sessionId,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'action'        => $action,
                'action_result' => $result,
                'route'         => $request->path,
                'method'        => $request->method,
                'ip_address'    => $request->ip,
                'description'   => $description,
                'old_values'    => $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                'new_values'    => $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Do not break the main flow on logging failure
            $logFile = BASE_PATH . '/storage/logs/error-' . date('Y-m-d') . '.log';
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ActivityLogger error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }
}