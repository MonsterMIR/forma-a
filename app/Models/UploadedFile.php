<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class UploadedFile extends Model
{
    protected string $table = 'uploaded_files';

    public function findWithUploader(int $id): ?array
    {
        $stmt = $this->query(
            "SELECT uf.*, u.username AS uploader_username
             FROM `uploaded_files` uf
             LEFT JOIN `users` u ON u.id = uf.uploaded_by_user_id
             WHERE uf.id = ? LIMIT 1",
            [$id]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function incrementOperationCount(int $id): void
    {
        $this->query(
            "UPDATE `uploaded_files` SET `operation_count` = `operation_count` + 1 WHERE `id` = ?",
            [$id]
        );
    }

    public function markDeleted(int $id): void
    {
        $this->update($id, ['upload_status' => 'deleted']);
    }
}