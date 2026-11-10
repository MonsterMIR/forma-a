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

    /**
     * ДОБАВЛЕНО (10.11.2026, раунд 165, по жалобе пользователя — живой случай
     * рейса #311, см. claude/analysis-2026-11-10-round165-flight-manual-add-
     * stand-registration-deadlock.md): "Не стыковка в логике работы ручного
     * добавления рейсов" — при ошибке сохранения формы рейса (FlightController
     * store()/update()) контроллер всегда делает redirect() на ту же форму;
     * это новый GET-запрос, и $_POST с данными, которые человек только что
     * ввёл (включая борт), теряется полностью. Форма создания рейса
     * (resources/views/flights/create.php) уже давно написана в расчёте на
     * то, что значения можно будет перечитать из $_POST после неудачной
     * попытки (почти каждое поле там — `$_POST['поле'] ?? ''`), но это
     * никогда не работало по описанной выше причине.
     *
     * Эта пара методов — session-backed аналог $_POST, переживающий один
     * redirect, по тому же принципу "записать — один раз прочитать", что и
     * flash-сообщения выше. setOldInput() вызывается в store()/update() перед
     * redirect(); getOldInput() — в create()/edit() при повторном показе
     * формы. См. использование в FlightController.
     */
    public static function setOldInput(array $data): void
    {
        $_SESSION['old_input'] = $data;
    }

    public static function getOldInput(): array
    {
        $old = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);
        return $old;
    }
}