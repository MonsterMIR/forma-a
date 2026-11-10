<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class ActFile extends Model
{
    protected string $table = 'act_files';

    public function getByAct(int $actId): array
    {
        $stmt = $this->query(
            "SELECT af.*, uf.original_name, uf.stored_name, uf.mime_type,
                    uf.file_size_bytes, uf.storage_path, uf.upload_status,
                    uf.sha256_hash, uf.uploaded_at, uf.operation_count,
                    u.username AS created_by_username
             FROM `act_files` af
             JOIN `uploaded_files` uf ON uf.id = af.file_id
             LEFT JOIN `users` u ON u.id = af.created_by_user_id
             WHERE af.act_id = ? AND uf.upload_status != 'deleted'
             ORDER BY af.created_at DESC",
            [$actId]
        );
        return $stmt->fetchAll();
    }

    public function findByActAndFile(int $actId, int $fileId): ?array
    {
        $stmt = $this->query(
            "SELECT * FROM `act_files` WHERE `act_id` = ? AND `file_id` = ? LIMIT 1",
            [$actId, $fileId]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Проверяет наличие скана (signed_copy или source) для акта.
     * Используется при утверждении — без скана утвердить нельзя.
     */
    public function hasScanFile(int $actId): bool
    {
        // Принимается любой загруженный файл — PDF с ВКД или скан/фото
        $stmt = $this->query(
            "SELECT COUNT(*) FROM `act_files` af
             JOIN `uploaded_files` uf ON uf.id = af.file_id
             WHERE af.act_id = ?
               AND uf.upload_status = 'uploaded'",
            [$actId]
        );
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Получить список сканов для акта (для отображения в форме утверждения).
     */
    public function getScansForAct(int $actId): array
    {
        $stmt = $this->query(
            "SELECT af.*, uf.original_name, uf.file_size_bytes, uf.uploaded_at
             FROM `act_files` af
             JOIN `uploaded_files` uf ON uf.id = af.file_id
             WHERE af.act_id = ?
               AND af.relation_type IN ('signed_copy', 'source')
               AND uf.upload_status = 'uploaded'
             ORDER BY af.created_at DESC",
            [$actId]
        );
        return $stmt->fetchAll();
    }
}
