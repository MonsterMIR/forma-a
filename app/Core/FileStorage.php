<?php

declare(strict_types=1);

namespace App\Core;

class FileStorage
{
    public static function store(array $uploadedFile, string $category = 'other'): array
    {
        $config = require BASE_PATH . '/config/app.php';

        $originalName = $uploadedFile['name'];
        $tmpPath      = $uploadedFile['tmp_name'];
        $size         = $uploadedFile['size'];
        $error        = $uploadedFile['error'];

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload error code: ' . $error);
        }

        if ($size > $config['upload']['max_size']) {
            throw new \RuntimeException('File exceeds maximum allowed size.');
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $config['upload']['allowed_ext'], true)) {
            throw new \RuntimeException('File extension not allowed: ' . $ext);
        }

        // Detect real mime
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        // Whitelist allowed mime types (strict - only documents and images)
        $allowedMimes = [
            // Documents
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
            // Images
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/tiff',
            'image/heic',
            'image/heif',
            'image/webp',
        ];
        if (!in_array($mimeType, $allowedMimes, true)) {
            throw new \RuntimeException(
                'Тип файла не разрешён / File type not allowed: ' . $mimeType .
                '. Разрешены / Allowed: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TIFF, ZIP.'
            );
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;

        $subDir = match($category) {
            'act_source', 'act_export' => 'acts',
            'import'                   => 'imports',
            default                    => 'attachments',
        };

        $destDir = $config['upload']['storage_path'] . '/' . $subDir;
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $destPath = $destDir . '/' . $storedName;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            throw new \RuntimeException('Failed to move uploaded file.');
        }

        $sha256 = hash_file('sha256', $destPath);

        return [
            'original_name'  => $originalName,
            'stored_name'    => $storedName,
            'mime_type'      => $mimeType,
            'file_extension' => $ext,
            'file_size_bytes' => $size,
            'storage_disk'   => 'local',
            'storage_path'   => $subDir . '/' . $storedName,
            'sha256_hash'    => $sha256,
            'full_path'      => $destPath,
        ];
    }

    public static function getFullPath(string $storagePath): string
    {
        $config = require BASE_PATH . '/config/app.php';
        return $config['upload']['storage_path'] . '/' . $storagePath;
    }

    public static function delete(string $storagePath): bool
    {
        $fullPath = self::getFullPath($storagePath);
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }
}