<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\ActFile;
use App\Models\UploadedFile;
use App\Models\FileOperationLog;
use App\Services\FileService;

class FileController extends Controller
{
    private FileService      $fileService;
    private ActFile          $actFileModel;
    private UploadedFile     $fileModel;
    private FileOperationLog $logModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->fileService  = new FileService();
        $this->actFileModel = new ActFile();
        $this->fileModel    = new UploadedFile();
        $this->logModel     = new FileOperationLog();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);

        $actId = (int)$this->request->get('act_id', 0);
        $files = $actId ? $this->actFileModel->getByAct($actId) : [];

        $this->render('files/index', [
            'title' => 'Files',
            'files' => $files,
            'actId' => $actId,
        ]);
    }

    public function upload(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/acts/' . $params['act_id']);
            return;
        }

        if (!$this->request->hasFile('file')) {
            View::setFlash('error', 'No file selected.');
            $this->redirect('/acts/' . $params['act_id']);
            return;
        }

        $relationType = $this->request->input('relation_type', 'attachment');
        $result = $this->fileService->uploadForAct(
            $this->request->files['file'],
            (int)$params['act_id'],
            $relationType
        );

        if (!$result['success']) {
            View::setFlash('error', $result['error']);
        } else {
            // Show verify status to user
            $verifyStatus = $result['verify_status'] ?? 'not_checked';
            $msg = match($verifyStatus) {
                'verified'  => '✅ ' . __('FILE_UPLOADED_VERIFIED'),
                'no_code'   => '✅ ' . __('FILE_UPLOADED_NO_CODE'),
                'not_pdf'   => '✅ ' . __('FILE_UPLOADED_IMAGE'),
                default     => '✅ ' . __('FILE_UPLOADED_OK'),
            };
            View::setFlash('success', $msg);
        }

        $this->redirect('/acts/' . $params['act_id']);
    }

    public function download(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'commercial', 'finance']);

        $result = $this->fileService->downloadFile((int)$params['id']);
        if (!$result['success']) {
            View::setFlash('error', $result['error']);
            $this->redirect('/dashboard');
            return;
        }

        $this->response->download(
            $result['full_path'],
            $result['original_name'],
            $result['mime_type']
        );
    }

    public function delete(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/dashboard');
            return;
        }

        $actId  = (int)$this->request->input('act_id', 0);
        $result = $this->fileService->deleteFile((int)$params['id']);

        if (!$result['success']) {
            View::setFlash('error', $result['error']);
        } else {
            View::setFlash('success', 'File deleted.');
        }

        if ($actId) {
            $this->redirect('/acts/' . $actId);
        } else {
            $this->redirect('/dashboard');
        }
    }

    public function log(array $params = []): void
    {
        $this->requireRole(['admin', 'cok']);

        $file = $this->fileModel->findWithUploader((int)$params['id']);
        if (!$file) {
            View::setFlash('error', 'File not found.');
            $this->redirect('/dashboard');
            return;
        }

        $logs = $this->logModel->getByFile((int)$params['id']);

        $this->render('files/log', [
            'title' => 'File Log: ' . $file['original_name'],
            'file'  => $file,
            'logs'  => $logs,
        ]);
    }
}