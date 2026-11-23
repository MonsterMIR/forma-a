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
 *
 * ИЗМЕНЕНО (23.11.2026, раунд 201, по итогам разбора очередной жалобы
 * пользователя "всё равно неправильно отражается"): пользователь прислал
 * для сравнения файл, который назвал "оригиналом в системе" — при
 * разборе выяснилось, что отдельного хранимого "оригинала" в системе НЕТ
 * (нет ни такой колонки в acts, ни генерации PDF в Android-приложении
 * супервайзера) — это тот же самый HTML (resources/views/acts/print.php,
 * та же фраза "продолжение / continued"), просто открытый пользователем
 * через кнопку "Печать" и сохранённый НАСТОЯЩИМ браузером, а не dompdf.
 * Вместо очередного точечного патча под конкретную особенность dompdf,
 * теперь по умолчанию используется headless Chromium (см.
 * pdf-renderer/render.js, папка внутри public_html, рядом с app/) — тот же движок,
 * что и у пользователя в браузере, воспроизводящий печать 1-в-1
 * (правильные разрывы страниц, нет нужды в defaultMediaType/шрифтовых
 * обходах). dompdf остаётся ниже как запасной вариант на случай, если
 * Chromium на сервере недоступен или рендер не удался.
 */
class ActPdfService
{
    /**
     * Рендерит печатную форму акта и конвертирует в PDF.
     * Возвращает байты PDF-файла или null, если акт не найден либо не
     * удалось сгенерировать PDF ни одним из способов (Chromium и dompdf).
     */
    public function generate(int $actId): ?string
    {
        $html = $this->renderHtml($actId);
        if ($html === null) {
            return null;
        }

        // ДОБАВЛЕНО (23.11.2026, раунд 200, по жалобе пользователя: в PDF,
        // присланном по почте, подписи супервайзера и представителя
        // авиакомпании -- пустые ячейки, хотя в системе реально сохранены
        // (acts.supervisor_signature_file_id / airline_rep_signature_file_id,
        // те же PNG, что показывает сама печатная форма в браузере).
        // Причина: вёрстка ссылается на них через
        // /acts/signature/{id} -- маршрут за AuthMiddleware. У dompdf нет
        // ни сессии пользователя, ни куки, да и isRemoteEnabled=false
        // (осознанно, по соображениям безопасности) запрещает ЛЮБУЮ
        // загрузку по HTTP(S), даже на свой же домен -- поэтому тег <img>
        // молча не загружался. Инлайним эти две конкретные картинки как
        // data:-URI, читая файл напрямую с диска по той же логике, что и
        // ActSignatureController::viewSignature() -- никакого сетевого
        // доступа при этом так и не открываем, isRemoteEnabled остаётся
        // false. Нужно это и для Chromium-пути ниже тоже: render.js
        // получает уже самодостаточный HTML (page.setContent, без
        // навигации и без сессии) -- так что инлайнинг картинок здесь
        // общий для обоих способов рендера.
        $html = $this->inlineSignatureImages($html);

        // ИЗМЕНЕНО (23.11.2026, раунд 201): основной путь -- headless
        // Chromium, см. комментарий класса выше. При любой проблеме
        // (скрипт отсутствует, Chromium не смог запуститься, таймаут и
        // т.п.) generateViaChromium() возвращает null и ниже используется
        // прежний dompdf-путь -- без изменений в поведении на этот случай.
        $pdf = $this->generateViaChromium($html);
        if ($pdf !== null) {
            return $pdf;
        }
        error_log('ActPdfService::generate: Chromium-рендер недоступен или не удался, используется запасной вариант dompdf для act ' . $actId);

        if (!class_exists(\Dompdf\Dompdf::class)) {
            error_log('ActPdfService::generate: dompdf/dompdf не установлен — выполните "composer require dompdf/dompdf" на сервере.');
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
        $dompdfHtml = $html . '<style>*, *::before, *::after { font-family: "DejaVu Sans", sans-serif !important; }</style>';

        try {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans'); // кириллица без доп. настройки шрифтов

            // ИСПРАВЛЕНО (21.11.2026, раунд 199, по жалобе пользователя: в PDF,
            // присланном на почту, видны кнопки "← Назад"/"Печать / PDF", а
            // таблица бланка слегка обрезана по правому краю страницы --
            // "форма чуть-чуть убежала, таблица смещена"): у dompdf
            // defaultMediaType по умолчанию равен 'screen', а НЕ 'print'.
            // Это значит, что блок @media print в print.php (который прячет
            // .no-print и выставляет правильные поля/ширину страницы под A4)
            // при генерации PDF просто никогда не применялся -- вступали в
            // силу только экранные правила (.page{width:210mm;margin:0 auto}
            // без сброса padding, .no-print без display:none). Тот же HTML,
            // что видит пользователь по кнопке "Печать" в браузере, браузер
            // сам подставляет print-правила при реальной печати/"Сохранить
            // как PDF" -- а dompdf это делает только если явно попросить.
            $options->set('defaultMediaType', 'print');

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($dompdfHtml, 'UTF-8');
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

    /**
     * ДОБАВЛЕНО (23.11.2026, раунд 200): заменяет <img src="/acts/signature/{id}">
     * на data:-URI с содержимым файла, прочитанным напрямую с диска --
     * см. комментарий в generate() выше. Тот же запрос, что и в
     * ActSignatureController::viewSignature(), просто без HTTP.
     */
    private function inlineSignatureImages(string $html): string
    {
        return preg_replace_callback(
            '#src="/acts/signature/(\d+)"#',
            function (array $m): string {
                $fileId = (int)$m[1];
                $stmt = \App\Core\Database::getInstance()->prepare(
                    "SELECT storage_path, mime_type FROM uploaded_files
                     WHERE id = ? AND file_category = 'signature' AND upload_status = 'uploaded' LIMIT 1"
                );
                $stmt->execute([$fileId]);
                $file = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$file) {
                    return $m[0];
                }
                $config   = require BASE_PATH . '/config/app.php';
                $fullPath = $config['upload']['storage_path'] . '/' . $file['storage_path'];
                if (!is_file($fullPath)) {
                    return $m[0];
                }
                $data = base64_encode((string)file_get_contents($fullPath));
                return 'src="data:' . $file['mime_type'] . ';base64,' . $data . '"';
            },
            $html
        ) ?? $html;
    }

    /**
     * ДОБАВЛЕНО (23.11.2026, раунд 201): рендерит уже готовый самодостаточный
     * HTML (подписи инлайнены вызывающим кодом в generate()) в PDF через
     * headless Chromium -- см. комментарий класса и pdf-renderer/render.js.
     * Возвращает null при любой проблеме (скрипт не найден, Chromium не
     * смог запуститься, таймаут, пустой результат) -- вызывающий код
     * (generate()) в этом случае откатывается на dompdf, поведение не
     * ломается даже если на каком-то окружении Chromium недоступен.
     */
    private function generateViaChromium(string $html): ?string
    {
        $rendererDir = BASE_PATH . '/pdf-renderer';
        $script = $rendererDir . '/render.js';
        if (!is_file($script)) {
            return null;
        }

        $tmpHtml = tempnam(sys_get_temp_dir(), 'formaa_pdf_html_');
        $tmpPdf  = tempnam(sys_get_temp_dir(), 'formaa_pdf_out_');
        if ($tmpHtml === false || $tmpPdf === false) {
            return null;
        }

        try {
            file_put_contents($tmpHtml, $html);

            $cmd = sprintf(
                'cd %s && timeout 30 node render.js %s %s 2>&1',
                escapeshellarg($rendererDir),
                escapeshellarg($tmpHtml),
                escapeshellarg($tmpPdf)
            );
            exec($cmd, $outputLines, $exitCode);

            if ($exitCode !== 0) {
                error_log('ActPdfService::generateViaChromium: render.js завершился с кодом ' . $exitCode . ': ' . implode(' | ', $outputLines));
                return null;
            }

            if (!is_file($tmpPdf) || filesize($tmpPdf) === 0) {
                error_log('ActPdfService::generateViaChromium: выходной PDF пуст или не создан.');
                return null;
            }

            $pdf = file_get_contents($tmpPdf);
            return $pdf === false ? null : $pdf;
        } catch (\Throwable $e) {
            error_log('ActPdfService::generateViaChromium error: ' . $e->getMessage());
            return null;
        } finally {
            @unlink($tmpHtml);
            @unlink($tmpPdf);
        }
    }
}
