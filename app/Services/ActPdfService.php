<?php
declare(strict_types=1);
namespace App\Services;

use App\Controllers\ActController;
use App\Core\Request;
use App\Core\Response;

/**
 * ДОБАВЛЕНО (21.09.2026, раунд 49, п.5, по прямому указанию пользователя):
 * генерация PDF-версии акта Формы А — для личного кабинета представителя
 * (скачивание, см. RepresentativeController) и для автоматической рассылки
 * по email при утверждении акта (см. ActService::sendApprovalCopyEmail()).
 *
 * До этого раунда в системе PDF вообще не генерировался — только HTML
 * "печатная форма" (ActController::printForm(), кнопка Ctrl+P/"Сохранить
 * как PDF" в браузере) — это был открытый вопрос ("dompdf for PDF acts")
 * ещё с раундов 40-46.
 *
 * Требует Composer-пакет dompdf/dompdf (`composer require dompdf/dompdf`
 * на сервере — см. README патча этого раунда, в этой песочнице нет доступа
 * к packagist.org, поэтому сама библиотека сюда не вендорилась, только
 * объявлена в composer.json). Если библиотека не установлена, generate()
 * возвращает null и не ломает вызывающий код — тот же мягкий паттерн
 * "мягкого отказа", что и у NotificationService::sendPush() для
 * minishlink/web-push, или у MailerService при не настроенном SMTP.
 *
 * Вся эта возможность в целом активна только когда включён личный кабинет
 * представителя (config/portal.php, PORTAL_ENABLED) — по прямому указанию
 * пользователя ("в тестовом режиме это функция будет реализовываться но
 * пока не действовать"). Сам ActPdfService флаг не проверяет — это
 * ответственность вызывающего кода (RepresentativeController,
 * ActService::sendApprovalCopyEmail()), сам сервис — просто механизм
 * "акт → PDF", применимый и на будущее к другим задачам, не только к
 * порталу представителя.
 */
class ActPdfService
{
    /**
     * Рендерит печатную форму акта и конвертирует в PDF.
     * Возвращает байты PDF-файла или null, если dompdf не установлен,
     * либо акт не найден.
     */
    public function generate(int $actId): ?string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            error_log('ActPdfService::generate: dompdf/dompdf не установлен — выполните "composer require dompdf/dompdf" на сервере.');
            return null;
        }

        $html = $this->renderHtml($actId);
        if ($html === null) {
            return null;
        }

        // ИСПРАВЛЕНО (21.11.2026, раунд 195, по жалобе пользователя: PDF во
        // вложении письма пришёл со сплошными "?" вместо кириллицы): CSS
        // самой печатной формы (print.php) явно задаёт
        // font-family: Arial, Helvetica, sans-serif -- dompdf резолвит это
        // в свой встроенный core-шрифт Helvetica (WinAnsi, без кириллических
        // глифов). $options->set('defaultFont', ...) ниже срабатывает ТОЛЬКО
        // когда font-family вообще не указан в CSS -- раз он указан явно,
        // до сих пор не применялся к этой форме вовсе. Принудительно
        // переопределяем шрифт на кириллический DejaVu Sans прямо в HTML
        // (!important, добавлено в конец документа -- побеждает по порядку
        // каскада при равной специфичности) -- только для PDF-рендера; то,
        // что пользователь видит в браузере по кнопке "Печать"
        // (ActController::printForm()/renderPrintHtml()), не затронуто --
        // там этот блок не добавляется.
        $html .= '<style>*, *::before, *::after { font-family: "DejaVu Sans", sans-serif !important; }</style>';

        try {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans'); // кириллица без доп. настройки шрифтов

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return $dompdf->output();
        } catch (\Throwable $e) {
            error_log('ActPdfService::generate error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * HTML печатной формы акта — та же вёрстка/данные, что видит
     * пользователь по кнопке "Печать" (ActController::printForm()), просто
     * вызвано напрямую без HTTP-роутинга и без requireRole() (см.
     * ActController::renderPrintHtml() — авторизация на совести
     * вызывающего кода этого сервиса).
     */
    public function renderHtml(int $actId): ?string
    {
        $controller = new ActController(new Request(), new Response());
        return $controller->renderPrintHtml($actId);
    }
}
