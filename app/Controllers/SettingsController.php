<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\SettingsService;

class SettingsController extends Controller
{
    private SettingsService $settingsService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->settingsService = new SettingsService();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin']);
        $settings = $this->settingsService->getAll();
        $this->render('settings/index', [
            'title'    => 'System Settings',
            'settings' => $settings,
        ]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['admin']);

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Invalid CSRF token.');
            $this->redirect('/settings');
            return;
        }

        $data     = $this->request->all();
        $settings = $data['settings'] ?? [];

        if (!is_array($settings)) {
            View::setFlash('error', 'Invalid settings data.');
            $this->redirect('/settings');
            return;
        }

        $this->settingsService->saveMany($settings);

        View::setFlash('success', 'Settings saved successfully.');
        $this->redirect('/settings');
    }
}