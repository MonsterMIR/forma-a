<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    private int $status = 200;

    public function setStatus(int $status): void
    {
        $this->status = $status;
        http_response_code($status);
    }

    public function redirect(string $url, int $status = 302): void
    {
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    public function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function download(string $filePath, string $fileName, string $mimeType = 'application/octet-stream'): void
    {
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo '404 Not Found';
            exit;
        }
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($filePath);
        exit;
    }
}