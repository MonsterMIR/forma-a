<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ActivityLogger;
use App\Core\Auth;
use App\Core\FileStorage;
use App\Models\Act;
use App\Models\ActFile;
use App\Models\FileOperationLog;
use App\Models\UploadedFile;

class FileService
{
    private UploadedFile     $fileModel;
    private ActFile          $actFileModel;
    private FileOperationLog $logModel;
    private Act              $actModel;

    public function __construct()
    {
        $this->fileModel    = new UploadedFile();
        $this->actFileModel = new ActFile();
        $this->logModel     = new FileOperationLog();
        $this->actModel     = new Act();
    }

    // =========================================================
    // UPLOAD
    // $strictPdfVerify = true  → при утверждении (загрузка PDF обязательно
    //                            должна содержать номер ЭТОГО акта)
    // $strictPdfVerify = false → обычная загрузка (мягкая проверка)
    // =========================================================
    public function uploadForAct(
        array  $uploadedFile,
        int    $actId,
        string $relationType    = 'attachment',
        bool   $strictPdfVerify = false
    ): array {
        $userId    = Auth::id();
        $sessionId = Auth::sessionDbId();

        try {
            $stored = FileStorage::store($uploadedFile, 'act_source');
        } catch (\RuntimeException $e) {
            ActivityLogger::log('file', null, 'upload_file', 'failure', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }

        // ── ВЕРИФИКАЦИЯ PDF ──────────────────────────────────
        $verifyStatus  = 'not_checked';
        $verifyMessage = null;

        if ($stored['mime_type'] === 'application/pdf') {

            // Проверяем PDF на принадлежность этому акту (и при утверждении, и при обычной загрузке)
            $act = $this->actModel->find($actId);
            if ($act) {
                $result = $this->verifyPdfActNumber(
                    $stored['full_path'],
                    $act['act_number']
                );
                $verifyStatus  = $result['status'];
                $verifyMessage = $result['message'];

                // Чужой PDF — всегда блокируем
                if ($verifyStatus === 'mismatch') {
                    FileStorage::delete($stored['storage_path']);
                    ActivityLogger::log('file', null, 'upload_file', 'failure',
                        "PDF mismatch act_id={$actId}: {$verifyMessage}");
                    return ['success' => false, 'error' => '🚫 ' . $verifyMessage, 'verify' => 'mismatch'];
                }

                // ИСПРАВЛЕНО (14.08.2026, п.5): при утверждении подтверждением
                // документа должен служить QR-код (ВКД) на бланке акта, а не
                // просто факт, что это PDF. Раньше здесь блокировался только
                // статус 'not_checked' (текст вообще не извлёкся) — но
                // 'no_act_number' и старое "слабое" совпадение по номеру акта
                // (без ВКД/QR) проходили дальше и документ считался принятым.
                // Из-за этого практически любой PDF с текстом внутри
                // (а он есть почти у каждого PDF даже без pdftotext/gs —
                // см. extractPdfText(), Метод 4) проскакивал строгую проверку.
                // Теперь при утверждении принимается ТОЛЬКО status === 'verified'
                // (QR/ВКД реально совпал с этим актом).
                if ($strictPdfVerify && $verifyStatus !== 'verified') {
                    FileStorage::delete($stored['storage_path']);
                    $error = match ($verifyStatus) {
                        'no_qr' => '🚫 В документе найден номер акта, но QR-код подтверждения (ВКД) не считан. '
                                 . 'Загрузите скан/фото подписанного бланка акта с QR-кодом, а не пересозданный файл.',
                        'no_act_number' => '🚫 Документ не относится к этому акту — QR-код (ВКД) не найден.',
                        default => '🚫 Не удалось проверить QR-код в PDF. Установите pdftotext на сервере '
                                 . '(apt-get install poppler-utils) или загрузите скан/фото (JPG, PNG, TIFF) с читаемым QR-кодом.',
                    };
                    ActivityLogger::log('file', null, 'upload_file', 'failure',
                        "PDF rejected at approval act_id={$actId}, verify_status={$verifyStatus}: {$verifyMessage}");
                    return ['success' => false, 'error' => $error, 'verify' => $verifyStatus];
                }
            }
        } else {
            // JPG/PNG/TIFF/HEIC — скан бумаги, принимается всегда
            $verifyStatus = 'scan_image';
        }
        // ─────────────────────────────────────────────────────

        $now    = date('Y-m-d H:i:s');
        $fileId = (int)$this->fileModel->insert([
            'file_category'          => 'act_source',
            'original_name'          => $stored['original_name'],
            'stored_name'            => $stored['stored_name'],
            'mime_type'              => $stored['mime_type'],
            'file_extension'         => $stored['file_extension'],
            'file_size_bytes'        => $stored['file_size_bytes'],
            'storage_disk'           => $stored['storage_disk'],
            'storage_path'           => $stored['storage_path'],
            'sha256_hash'            => $stored['sha256_hash'],
            'upload_status'          => 'uploaded',
            'operation_count'        => 1,
            'uploaded_by_user_id'    => $userId,
            'uploaded_in_session_id' => $sessionId,
            'uploaded_at'            => $now,
        ]);

        $this->actFileModel->insert([
            'act_id'             => $actId,
            'file_id'            => $fileId,
            'relation_type'      => $relationType,
            'created_by_user_id' => $userId,
            'created_at'         => $now,
        ]);

        $note = match($verifyStatus) {
            'verified'      => ' ✅ QR-код (ВКД) подтверждён',
            'no_qr'         => ' ⚠️ номер акта найден, но QR-код не считан (принят)',
            'no_act_number' => ' ⚠️ номер акта не найден (принят)',
            'scan_image'    => ' 📷 скан/фото',
            default         => '',
        };

        $logMsg = "Uploaded '{$stored['original_name']}' for act_id={$actId}{$note}";
        $this->logModel->record($fileId, $userId, $sessionId, 'upload', 'success', $logMsg);
        ActivityLogger::log('file', $fileId, 'upload_file', 'success', $logMsg);

        return ['success' => true, 'file_id' => $fileId, 'verify_status' => $verifyStatus];
    }

    // =========================================================
    // ПРОВЕРКА: номер акта в PDF
    // Использует pdftotext если доступен, иначе raw-анализ
    // =========================================================
    private function verifyPdfActNumber(string $pdfPath, string $actNumber): array
    {
        $text = $this->extractPdfText($pdfPath);

        if (empty(trim($text))) {
            return ['status' => 'not_checked', 'message' => 'Cannot extract text from PDF'];
        }

        $act = $this->actModel->findAll(['act_number' => $actNumber], '', 1);
        $expectedVcd = !empty($act) ? act_verify_code(
            $act[0]['act_number'],
            $act[0]['act_date'],
            (float)$act[0]['total_amount_with_vat']
        ) : null;

        // ИСПРАВЛЕНО (08.09.2026, раунд 31): найден реальный баг, из-за
        // которого строгая проверка при утверждении отклоняла ЛЮБОЙ, даже
        // абсолютно подлинный подписанный акт с корректным QR-кодом.
        //
        // Методы 2 и 3 ниже искали ВКД-код только в виде "/verify/XXXX"
        // (URL) или с меткой "VCD: XXXX". Но resources/views/acts/print.php
        // печатает URL "/verify/{code}" ТОЛЬКО внутри самой картинки QR
        // (через qrencode) — как текст в PDF эта строка не попадает вообще.
        // В PDF рядом с QR печатается только голый 12-символьный код без
        // каких-либо префиксов (см. print.php: значение verifyCode выводится
        // сразу под картинкой QR, подпись "Сканировать для проверки" — отдельной
        // строкой ниже, а не перед кодом). То есть ни один реальный акт
        // никогда не мог пройти строгую проверку — вне зависимости от того,
        // насколько правильно подписан и отсканирован документ. Единственный
        // способ утвердить акт после отказа — обойти проверку вообще без
        // файла (через уже имеющиеся подписи с планшета), что делает саму
        // проверку бессмысленной.
        //
        // Метод 1 (новый, приоритетный) — ищем именно ТОТ голый код, который
        // реально печатается. Допускаем пробелы между символами (pdftotext/
        // gs иногда разбивают моноширинный текст посимвольно).
        if ($expectedVcd !== null) {
            $spacedPattern = implode('\s*', str_split($expectedVcd));
            if (preg_match('/' . $spacedPattern . '/i', $text)) {
                return ['status' => 'verified', 'message' => "ВКД подтверждён: {$expectedVcd}"];
            }
        }

        // Метод 2: VCD в URL (надёжно — без пробелов) — оставлено на случай,
        // если шаблон печати в будущем станет включать сам текст "/verify/
        // XXXX" (сейчас так не печатает — см. пояснение выше).
        //
        // ИСПРАВЛЕНО (14.08.2026, п.5): раньше, если в тексте находился
        // /verify/XXXX, но код не совпадал с ожидаемым (или акт не находился
        // в БД), функция молча проваливалась дальше к более слабым методам
        // проверки — а должна была сразу сообщать о несовпадении, как это и
        // сделано в Методе 3 ниже.
        if (preg_match('~/verify/([A-F0-9]{12})~i', $text, $m)) {
            $foundVcd = strtoupper($m[1]);

            if ($expectedVcd !== null && $foundVcd === $expectedVcd) {
                return ['status' => 'verified', 'message' => "ВКД подтверждён: {$foundVcd}"];
            }
            return [
                'status'  => 'mismatch',
                'message' => "ВКД не совпадает. Документ принадлежит другому акту или подделан. "
                           . "Найден: {$foundVcd}" . ($expectedVcd !== null ? ", ожидается: {$expectedVcd}." : '.'),
            ];
        }

        // Метод 3: VCD с меткой "VCD:" — тоже оставлено на будущее (сейчас
        // print.php такую метку не печатает, см. пояснение выше).
        // "2 1 9 B 1 C 0 6 5 8 1 B" → "219B1C06581B"
        if (preg_match('/VCD[:\s]+((?:[A-F0-9]\s*){12})/i', $text, $m)) {
            $foundVcd = strtoupper(preg_replace('/\s/', '', $m[1]));

            if ($expectedVcd !== null && $foundVcd === $expectedVcd) {
                return ['status' => 'verified', 'message' => "ВКД подтверждён: {$foundVcd}"];
            }
            return [
                'status'  => 'mismatch',
                'message' => "ВКД не совпадает. Документ принадлежит другому акту или подделан. "
                           . "Найден: {$foundVcd}" . ($expectedVcd !== null ? ", ожидается: {$expectedVcd}." : '.'),
            ];
        }

        // Метод 4 (новый, раунд 31): голый 12-символьный hex-код БЕЗ
        // префикса — то, что реально печатается (см. Метод 1). Если Метод 1
        // не нашёл ИМЕННО ожидаемый код, но в тексте всё-таки есть отдельно
        // стоящий 12-символьный hex-токен — значит, это ВКД другого акта
        // (подмена/чужой документ), а не просто "не считалось". Формат
        // специфичный (ровно 12 символов, только 0-9A-F) — случайное
        // совпадение с другими числами бланка (даты, номера рейсов и т.п.)
        // практически исключено.
        if (preg_match('/\b([A-F0-9]{12})\b/i', $text, $m)) {
            $foundVcd = strtoupper($m[1]);

            if ($expectedVcd !== null && $foundVcd === $expectedVcd) {
                return ['status' => 'verified', 'message' => "ВКД подтверждён: {$foundVcd}"];
            }
            return [
                'status'  => 'mismatch',
                'message' => "ВКД не совпадает. Документ принадлежит другому акту или подделан. "
                           . "Найден: {$foundVcd}" . ($expectedVcd !== null ? ", ожидается: {$expectedVcd}." : '.'),
            ];
        }

        // Ищем любой чужой номер акта в тексте (формат NNNN/XX-NNNN)
        if (preg_match('/\d{4}\/[A-Z]{2}-\d{4}/', $text, $m) && $m[0] !== $actNumber) {
            return [
                'status'  => 'mismatch',
                'message' => "Документ принадлежит акту {$m[0]}, а не {$actNumber}.",
            ];
        }

        // ИСПРАВЛЕНО (14.08.2026, п.5): раньше это было Методом 1 и само по себе
        // давало статус 'verified' — а голое совпадение номера акта в тексте
        // ничего не доказывает: этот номер легко напечатать в любом файле
        // (Word → PDF и т.п.), не имея настоящего подписанного бланка с
        // QR-кодом. Единственное настоящее подтверждение — код верификации
        // (ВКД) из QR (проверяется выше). Поэтому теперь такое совпадение —
        // только статус 'no_qr': не блокируется при обычной загрузке
        // вложений, но блокируется при утверждении акта (см. uploadForAct()).
        if (strpos($text, $actNumber) !== false) {
            return [
                'status'  => 'no_qr',
                'message' => "Номер акта {$actNumber} найден в тексте, но QR-код подтверждения (ВКД) не считан.",
            ];
        }

        // Не удалось найти ни номер, ни VCD — внешний документ
        return ['status' => 'no_act_number', 'message' => 'Номер акта и QR-код подтверждения не найдены в PDF'];
    }

    /**
     * Извлекает текст из PDF.
     * Методы по приоритету:
     * 1. pdftotext (poppler-utils) — если установлен
     * 2. gs (Ghostscript) — txtwrite device
     * 3. Fallback: raw ASCII
     */
    private function extractPdfText(string $pdfPath): string
    {
        $safePath = escapeshellarg($pdfPath);

        // Метод 1: pdftotext
        foreach (['pdftotext', '/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $bin) {
            if (!is_executable($bin) && !@shell_exec("which {$bin} 2>/dev/null")) continue;
            $out = @shell_exec("{$bin} -enc UTF-8 {$safePath} - 2>/dev/null");
            if (is_string($out) && strlen(trim($out)) > 5) {
                return $out;
            }
        }

        // Метод 2: Ghostscript txtwrite (есть на сервере: /usr/bin/gs)
        $tmpFile = sys_get_temp_dir() . '/pdf_text_' . md5($pdfPath) . '.txt';
        foreach (['/usr/bin/gs', 'gs'] as $gs) {
            $cmd = sprintf(
                '%s -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -sOutputFile=%s %s 2>/dev/null',
                $gs,
                escapeshellarg($tmpFile),
                $safePath
            );
            @shell_exec($cmd);
            if (file_exists($tmpFile) && filesize($tmpFile) > 0) {
                $text = file_get_contents($tmpFile);
                @unlink($tmpFile);
                return (string)$text;
            }
        }

        // Метод 3: Imagick — конвертируем PDF страницу в изображение не нужен
        // (нам нужен текст, не картинка)

        // Метод 4: raw ASCII grep — последний шанс
        $raw = @file_get_contents($pdfPath);
        if ($raw === false) return '';
        preg_match_all('/[ -~]{4,}/', $raw, $m);
        return implode(' ', $m[0]);
    }

    // =========================================================
    // DOWNLOAD / VIEW / DELETE / REPLACE
    // =========================================================
    public function downloadFile(int $fileId): array
    {
        $userId    = Auth::id();
        $sessionId = Auth::sessionDbId();
        $file = $this->fileModel->find($fileId);
        if (!$file || $file['upload_status'] === 'deleted') {
            return ['success' => false, 'error' => 'File not found.'];
        }
        $fullPath = FileStorage::getFullPath($file['storage_path']);
        if (!file_exists($fullPath)) {
            $this->logModel->record($fileId, $userId, $sessionId, 'download', 'failed', 'Missing');
            return ['success' => false, 'error' => 'File not found on disk.'];
        }
        $this->fileModel->incrementOperationCount($fileId);
        $this->logModel->record($fileId, $userId, $sessionId, 'download', 'success',
            "Downloaded '{$file['original_name']}'");
        ActivityLogger::log('file', $fileId, 'download_file', 'success',
            "Downloaded '{$file['original_name']}'");
        return ['success' => true, 'full_path' => $fullPath,
                'original_name' => $file['original_name'],
                'mime_type' => $file['mime_type'] ?? 'application/octet-stream'];
    }

    public function viewFile(int $fileId): array
    {
        $userId    = Auth::id();
        $sessionId = Auth::sessionDbId();
        $file = $this->fileModel->find($fileId);
        if (!$file || $file['upload_status'] === 'deleted') {
            return ['success' => false, 'error' => 'File not found.'];
        }
        $this->fileModel->incrementOperationCount($fileId);
        $this->logModel->record($fileId, $userId, $sessionId, 'view', 'success',
            "Viewed '{$file['original_name']}'");
        ActivityLogger::log('file', $fileId, 'view_file', 'success',
            "Viewed '{$file['original_name']}'");
        return ['success' => true, 'file' => $file];
    }

    public function deleteFile(int $fileId): array
    {
        $userId    = Auth::id();
        $sessionId = Auth::sessionDbId();
        $file = $this->fileModel->find($fileId);
        if (!$file || $file['upload_status'] === 'deleted') {
            return ['success' => false, 'error' => 'File not found.'];
        }
        FileStorage::delete($file['storage_path']);
        $this->fileModel->markDeleted($fileId);
        $this->fileModel->incrementOperationCount($fileId);
        $this->logModel->record($fileId, $userId, $sessionId, 'delete', 'success',
            "Deleted '{$file['original_name']}'");
        ActivityLogger::log('file', $fileId, 'delete_file', 'success', "Deleted file_id={$fileId}");
        return ['success' => true];
    }

    public function replaceFile(int $fileId, array $uploadedFile, int $actId): array
    {
        $userId    = Auth::id();
        $sessionId = Auth::sessionDbId();
        $oldFile = $this->fileModel->find($fileId);
        if (!$oldFile || $oldFile['upload_status'] === 'deleted') {
            return ['success' => false, 'error' => 'Original file not found.'];
        }
        try {
            $stored = FileStorage::store($uploadedFile, 'act_source');
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        FileStorage::delete($oldFile['storage_path']);
        $now = date('Y-m-d H:i:s');
        $this->fileModel->update($fileId, [
            'original_name' => $stored['original_name'], 'stored_name' => $stored['stored_name'],
            'mime_type' => $stored['mime_type'], 'file_extension' => $stored['file_extension'],
            'file_size_bytes' => $stored['file_size_bytes'], 'storage_path' => $stored['storage_path'],
            'sha256_hash' => $stored['sha256_hash'], 'upload_status' => 'uploaded', 'uploaded_at' => $now,
        ]);
        $this->fileModel->incrementOperationCount($fileId);
        $this->logModel->record($fileId, $userId, $sessionId, 'replace', 'success',
            "Replaced with '{$stored['original_name']}'");
        ActivityLogger::log('file', $fileId, 'replace_file', 'success', "Replaced file_id={$fileId}");
        return ['success' => true];
    }
}
