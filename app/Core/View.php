<?php

declare(strict_types=1);

namespace App\Core;

class View
{
    private string $viewsPath;
    private string $layoutsPath;
    private array  $shared = [];

    public function __construct()
    {
        $this->viewsPath   = BASE_PATH . '/resources/views';
        $this->layoutsPath = BASE_PATH . '/resources/views/layouts';
    }

    public function render(string $view, array $data = []): void
    {
        $data = array_merge($this->shared, $data);
        $data['auth_user'] = Auth::user();
        $data['flash']     = $this->getFlash();

        $viewFile = $this->viewsPath . '/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($viewFile)) {
            throw new \RuntimeException("View not found: {$viewFile}");
        }

        $layout = $data['layout'] ?? 'main';
        unset($data['layout']);

        // Extract variables into scope
        extract($data, EXTR_SKIP);

        // Capture content
        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        // Render layout
        $layoutFile = $this->layoutsPath . '/' . $layout . '.php';
        if (file_exists($layoutFile)) {
            require $layoutFile;
        } else {
            echo $content;
        }
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function getFlash(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flash;
    }

    public static function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }
}