<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ActivityLogger;
use App\Core\Auth;
use App\Models\SystemSetting;

class SettingsService
{
    private SystemSetting $settingModel;

    public function __construct()
    {
        $this->settingModel = new SystemSetting();
    }

    public function getAll(): array
    {
        return $this->settingModel->getAllSettings();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settingModel->getValue($key, $default);
    }

    public function saveMany(array $settings): void
    {
        $userId = Auth::id();

        foreach ($settings as $key => $value) {
            $old = $this->settingModel->getValue($key);
            $this->settingModel->setValue($key, $value === '' ? null : (string)$value, $userId);

            ActivityLogger::log(
                'setting', null, 'update_setting', 'success',
                "Updated setting '{$key}'",
                [$key => $old],
                [$key => $value]
            );
        }
    }
}