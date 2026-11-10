<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\Act;
use App\Services\ActPdfService;

/**
 * ДОБАВЛЕНО (21.09.2026, раунд 49, п.5, по прямому указанию пользователя):
 * личный кабинет представителя авиакомпании/хендлера — "куда будут акты
 * сливаться, чтобы они тоже видели". Только просмотр СВОИХ утверждённых
 * актов + скачивание PDF, никакого редактирования.
 *
 * Роль 'representative' НЕ участвует в общей матрице Permissions (см.
 * Permissions::REPRESENTATIVE) — доступ и фильтрация "только акты своего
 * хендлера" сделаны прямо здесь, через users.handler_id (см. Auth::user()).
 *
 * Весь контроллер под флагом PORTAL_ENABLED (config/portal.php) — "в
 * тестовом режиме эта функция будет реализовываться но пока не
 * действовать". Пока флаг выключен: (1) учётные записи с этой ролью не
 * могут даже войти (см. AuthController::login()), поэтому requirePortalEnabled()
 * здесь — вторая линия защиты (прямой POST/GET на URL портала уже
 * авторизованной сессией, если она как-то возникла раньше, чем флаг
 * выключили).
 */
class RepresentativeController extends Controller
{
    public function dashboard(array $params = []): void
    {
        $this->requireRole(['representative']);
        $this->requirePortalEnabled();

        $handlerId = (int)(Auth::user()['handler_id'] ?? 0);
        $acts = [];
        if ($handlerId > 0) {
            try {
                $acts = (new Act())->getApprovedByHandler($handlerId);
            } catch (\Throwable $e) {
                error_log('RepresentativeController::dashboard error: ' . $e->getMessage());
            }
        }

        $this->render('portal/dashboard', [
            'layout'    => 'portal',
            'title'     => 'Мои акты',
            'acts'      => $acts,
            'handlerId' => $handlerId,
        ]);
    }

    public function show(array $params = []): void
    {
        $this->requireRole(['representative']);
        $this->requirePortalEnabled();

        $act = $this->authorizedAct((int)($params['id'] ?? 0));
        if (!$act) {
            View::setFlash('error', 'Акт не найден или недоступен.');
            $this->redirect('/portal');
            return;
        }

        $this->render('portal/act', [
            'layout' => 'portal',
            'title'  => 'Акт № ' . $act['act_number'],
            'act'    => $act,
        ]);
    }

    public function downloadPdf(array $params = []): void
    {
        $this->requireRole(['representative']);
        $this->requirePortalEnabled();

        $act = $this->authorizedAct((int)($params['id'] ?? 0));
        if (!$act) {
            View::setFlash('error', 'Акт не найден или недоступен.');
            $this->redirect('/portal');
            return;
        }

        $pdf = (new ActPdfService())->generate((int)$act['id']);
        if ($pdf === null) {
            View::setFlash('error', 'Не удалось сформировать PDF. Обратитесь в ЦОК.');
            $this->redirect('/portal/acts/' . (int)$act['id']);
            return;
        }

        $filename = 'act_' . preg_replace('/[^\w.-]+/u', '_', (string)$act['act_number']) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    private function requirePortalEnabled(): void
    {
        $config = require BASE_PATH . '/config/portal.php';
        if (empty($config['enabled'])) {
            http_response_code(403);
            $this->render('errors/403');
            exit;
        }
    }

    /**
     * Акт доступен представителю, только если утверждён/выгружен И
     * принадлежит хендлеру текущей учётной записи (users.handler_id).
     * Черновики и акты чужих хендлеров возвращают null — так же, как если
     * бы акта не существовало, без различия в сообщении об ошибке
     * (намеренно не раскрываем факт существования чужого акта).
     */
    private function authorizedAct(int $actId): ?array
    {
        $handlerId = (int)(Auth::user()['handler_id'] ?? 0);
        if ($handlerId <= 0 || $actId <= 0) {
            return null;
        }

        $act = (new Act())->findWithDetails($actId);
        if (!$act) return null;
        if ((int)($act['handler_id'] ?? 0) !== $handlerId) return null;
        if (!in_array($act['status'], ['approved', 'exported'], true)) return null;

        return $act;
    }
}
