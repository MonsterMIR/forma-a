<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\View;
use App\Core\Database;
use App\Models\Act;
use App\Models\Flight;
use App\Models\ParkingAct;
use App\Services\NotificationService;
use App\Services\ActService;
use App\Services\AutoChargeService;
use App\Services\HandlerService;

/**
 * ДОБАВЛЕНО (05.08.2026): подпись супервайзера и представителя авиакомпании
 * прямо на планшете (стилус S Pen), вместо бумаги + скана.
 *
 * Решение по архитектуре (обсуждалось с MrTank): подписи с планшета —
 * ЗАМЕНА требования скана при утверждении.
 *
 * ИЗМЕНЕНО (21.09.2026, раунд 49, п.4, по прямому указанию пользователя):
 * раньше здесь заканчивалось "но не автоматическое утверждение — ЦОК
 * всё равно должен зайти и нажать 'Утвердить'". Теперь НЕТ: как только
 * собраны обе подписи (супервайзер + представитель/экипаж ВС), акт
 * утверждается автоматически, ЕСЛИ по рейсу нет несинхронизированных
 * расчётов услуг (та же проверка UNSYNCED_CHARGES, что и раньше блокировала
 * ручное утверждение) — иначе, как и раньше, уведомляем ЦОК и ждём его
 * ручного подтверждения. См. saveSignature() ниже.
 */
class ActSignatureController extends Controller
{
    private Act   $actModel;
    private Flight $flightModel;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->actModel    = new Act();
        $this->flightModel = new Flight();
    }

    /**
     * Экран подписания — доступен с планшета супервайзера. Показывает
     * итог по акту (номер, сумма) и два поля для подписи по очереди:
     * сперва супервайзер, затем представитель авиакомпании.
     */
    public function signForm(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);
        $flightId = (int)$params['id'];

        $flight = $this->flightModel->find($flightId);
        if (!$flight) {
            View::setFlash('error', 'Рейс не найден.');
            $this->redirect('/supervisor');
            return;
        }

        $act = $this->actModel->findByFlightId($flightId);
        if (!$act) {
            View::setFlash('error', 'Акт по этому рейсу ещё не сформирован ЦОК — подписывать пока нечего.');
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        if (in_array($act['status'], ['approved', 'exported'], true)) {
            View::setFlash('info', 'Акт уже утверждён.');
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        // ДОБАВЛЕНО (10.09.2026, раунд 35): по прямому указанию пользователя —
        // подписывать нужно АКТУАЛЬНОЕ состояние акта, а не то, что было на
        // момент последней сборки ЦОК. Экран подписания теперь сам сначала
        // обновляет акт (та же логика, что у кнопки «Обновить»/resync()) —
        // подтягивает обязательные услуги и всё, что супервайзер/службы уже
        // успели подтвердить, — и только после этого показывает итоговую
        // сумму на подпись. Именно отсутствие такого обновления привело к
        // тому, что «Лидирование ВС» не попало в акт 0006 (подтверждено
        // супервайзером уже ПОСЛЕ сборки акта, а к моменту подписания акт
        // так и не пересобрали).
        // ИЗМЕНЕНО (17.09.2026, раунд 44): processParkingAct() больше не
        // принимает параметры и не зависит от parking_acts.status — сам
        // находит время стоянки по колодкам (см. AutoChargeService,
        // докблок processParkingAct()). Вызываем безусловно.
        try {
            $autoCharge = new AutoChargeService();
            $autoCharge->processParkingAct($flightId);
        } catch (\Throwable $e) {
            error_log('signForm: mandatory auto-charges failed: ' . $e->getMessage());
        }

        try {
            $buildResult = (new ActService())->buildFromFlightCharges((int)$act['id'], $flightId);
            if (($buildResult['added'] ?? 0) > 0) {
                View::setFlash('info', 'Акт обновлён перед подписанием — добавлено: ' . implode(', ', $buildResult['added_names']) . '.');
            }
            // Акт мог обновиться (сумма/состав) — перечитываем перед показом.
            $act = $this->actModel->findByFlightId($flightId);
        } catch (\Throwable $e) {
            error_log('signForm: buildFromFlightCharges failed: ' . $e->getMessage());
        }

        // ДОБАВЛЕНО (18.09.2026, раунд 46, п.6): кандидаты-хендлеры для
        // авиакомпании этого рейса + их сотрудники — чтобы на планшете можно
        // было выбрать подписанта из списка, а не печатать ФИО руками
        // (см. HandlerService, анализ раунда 46 п.5/п.6).
        // ИЗМЕНЕНО (22.09.2026, раунд 51): если у рейса есть хендлер,
        // распознанный из заявки (flights.resolved_handler_id) — он теперь
        // приоритетнее общей привязки авиакомпания→хендлер, см.
        // HandlerService::getCandidatesForFlight().
        $handlerCandidates = (new HandlerService())->getCandidatesForFlight($flight);

        $this->render('service_orders/sign_flight', [
            'title'             => 'Подписание акта — рейс ' . $flight['flight_number'],
            'flight'            => $flight,
            'act'               => $act,
            'handlerCandidates' => $handlerCandidates,
        ]);
    }

    /**
     * Приём подписи супервайзера — отдельный запрос от подписи представителя,
     * чтобы одна не зависела от другой (если, например, представитель
     * отошёл и подпишет позже, подпись супервайзера уже сохранена).
     */
    public function saveSupervisorSignature(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);

        // ДОБАВЛЕНО (18.09.2026, раунд 46, п.6): раньше на печатной форме
        // рядом с этой самой подписью показывалось имя ЦОК, утвердившего
        // акт (approved_by_user_id), а не имя того, кто реально расписался
        // на планшете — эти два человека могут быть разными (см. анализ
        // раунда 46). Запоминаем настоящего супервайзера автоматически —
        // из текущей сессии, без единого лишнего поля ввода, ровно как
        // просил пользователь ("при вводе логина и пароля уже автомат
        // садится в поле супервайзера").
        $flightId = (int)$params['id'];
        $act = $this->actModel->findByFlightId($flightId);
        if ($act) {
            $this->actModel->update((int)$act['id'], ['tablet_supervisor_user_id' => Auth::id()]);
        }

        $this->saveSignature($params, 'supervisor_signature_file_id');
    }

    public function saveRepresentativeSignature(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor']);

        $repName     = trim((string)$this->request->input('rep_name', ''));
        $repPosition = trim((string)$this->request->input('rep_position', ''));
        $employeeId  = (int)$this->request->input('handler_employee_id', 0);

        $flightId = (int)$params['id'];
        $act = $this->actModel->findByFlightId($flightId);

        if ($act) {
            $update = [];

            // ДОБАВЛЕНО (18.09.2026, раунд 46, п.5/п.6): если подписанта
            // выбрали из списка сотрудников хендлера — ФИО/должность и
            // сам хендлер берём из справочника (надёжнее ручного набора на
            // планшете), а не из текстовых полей. Ручной ввод остаётся
            // рабочим как раньше — если хендлер/сотрудник не выбран
            // (справочник ещё не заполнен), просто используем то, что
            // ввели руками, как и до этого раунда.
            if ($employeeId > 0) {
                $employee = (new HandlerService())->findEmployee($employeeId);
                if ($employee) {
                    $update['handler_id']               = (int)$employee['handler_id'];
                    $update['airline_rep_employee_id']  = (int)$employee['id'];
                    $update['airline_rep_name']         = $employee['full_name'];
                    $update['airline_rep_position']     = $employee['position'] ?? ($repPosition ?: null);
                }
            }

            if (empty($update['airline_rep_name']) && $repName !== '') {
                $update['airline_rep_name'] = $repName;
            }
            if (empty($update['airline_rep_position']) && $repPosition !== '') {
                $update['airline_rep_position'] = $repPosition;
            }

            if (!empty($update)) {
                $this->actModel->update((int)$act['id'], $update);
            }
        }

        $this->saveSignature($params, 'airline_rep_signature_file_id');
    }

    private function saveSignature(array $params, string $column): void
    {
        $isAjax = $this->request->header('X-Requested-With') === 'XMLHttpRequest';

        if (!Csrf::verifyRequest($this->request)) {
            $msg = 'Неверный CSRF-токен.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor');
            return;
        }

        $flightId = (int)$params['id'];
        $act = $this->actModel->findByFlightId($flightId);

        if (!$act) {
            $msg = 'Акт не найден.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }
        if (in_array($act['status'], ['approved', 'exported'], true)) {
            $msg = 'Акт уже утверждён — подпись изменить нельзя.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $dataUrl = (string)$this->request->input('signature_png', '');
        if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/png;base64,')) {
            $msg = 'Подпись пустая — распишитесь перед сохранением.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        try {
            $fileId = $this->storeSignaturePng($dataUrl);
        } catch (\Throwable $e) {
            error_log('ActSignatureController::saveSignature error: ' . $e->getMessage());
            $msg = 'Не удалось сохранить подпись — попробуйте ещё раз.';
            if ($isAjax) { $this->respondJson(false, $msg); return; }
            View::setFlash('error', $msg);
            $this->redirect('/supervisor/flights/' . $flightId);
            return;
        }

        $this->actModel->update((int)$act['id'], [$column => $fileId]);

        // ИЗМЕНЕНО (21.09.2026, раунд 49, п.4, по прямому указанию
        // пользователя): "После подписания можно ли реализация утверждение
        // акта после подписи представителя? ... Нет смысла утверждать или
        // проверять акты если он подписать представителем или экипажем ВС."
        // Раньше здесь ТОЛЬКО уведомляли ЦОК, что акт ждёт ручного
        // утверждения (ЦОК должен был зайти и нажать «Утвердить» сам).
        // Теперь: как только собраны ОБЕ подписи — пробуем утвердить акт
        // автоматически, тем же ActService::changeStatus(), которым
        // пользуется и сам ЦОК на кнопке «Утвердить» (те же проверки, тот
        // же email с копией акта представителю, та же запись в историю).
        // Автоматически утверждаем, ТОЛЬКО когда всё чисто — если по рейсу
        // остались несинхронизированные расчёты услуг (ActService::
        // changeStatus() код UNSYNCED_CHARGES — та же проверка, что и
        // сегодня блокирует РУЧНОЕ утверждение ЦОК), автоматика не
        // утверждает, а откатывается к прежнему поведению — уведомляет ЦОК,
        // чтобы он сам нажал «Обновить» и проверил акт перед утверждением
        // (выбор пользователя: "авто-утверждать только когда всё чисто").
        $otherColumn = $column === 'supervisor_signature_file_id'
            ? 'airline_rep_signature_file_id'
            : 'supervisor_signature_file_id';
        $autoApproved = false;
        $autoApproveBlockedReason = null;
        if (!empty($act[$otherColumn])) {
            // ДОБАВЛЕНО (10.09.2026, раунд 35): обе подписи собраны — только
            // теперь документ по-настоящему становится актом. Присваиваем
            // окончательный, "сгорающий" номер (до этого — заглушка
            // 'PRELIM-FL...', печатная форма показывала «ПРЕДВАРИТЕЛЬНО»).
            // См. ActService::ensureActNumberFinalized(). Нужен и для
            // автоматического утверждения ниже (changeStatus() тоже
            // вызывает его сам, но идемпотентно — здесь просто раньше по
            // времени, чтобы иметь номер для уведомления в обоих исходах).
            $actNumberForNotify = (string)$act['act_number'];
            try {
                $finalizeResult = (new ActService())->ensureActNumberFinalized((int)$act['id']);
                if (!empty($finalizeResult['act_number'])) {
                    $actNumberForNotify = (string)$finalizeResult['act_number'];
                }
            } catch (\Throwable $e) {
                error_log('ActSignatureController::saveSignature finalize number error: ' . $e->getMessage());
            }

            try {
                $approveResult = (new ActService())->changeStatus((int)$act['id'], 'approved');
                if (!empty($approveResult['success'])) {
                    $autoApproved = true;
                    \App\Core\ActivityLogger::log('flight', $flightId, 'act_auto_approved', 'success',
                        'Акт № ' . $actNumberForNotify . ' автоматически утверждён после подписи представителя (без ручного подтверждения ЦОК).');
                } else {
                    $autoApproveBlockedReason = $approveResult['error'] ?? null;
                }
            } catch (\Throwable $e) {
                error_log('ActSignatureController::saveSignature auto-approve error: ' . $e->getMessage());
            }

            if (!$autoApproved) {
                // Откат к прежнему поведению — ручное утверждение ЦОК.
                try {
                    (new NotificationService())->notifyActReadyForConfirmation(
                        $flightId, (int)$act['id'], $actNumberForNotify
                    );
                } catch (\Throwable $e) {
                    error_log('ActSignatureController::saveSignature notify error: ' . $e->getMessage());
                }
            }
        }

        $msg = 'Подпись сохранена.';
        if ($autoApproved) {
            $msg .= ' Обе подписи собраны — акт автоматически утверждён.';
        } elseif ($autoApproveBlockedReason !== null) {
            $msg .= ' Обе подписи собраны, но акт передан на ручное утверждение ЦОК: ' . $autoApproveBlockedReason;
        }

        if ($isAjax) {
            $this->respondJson(true, $msg, ['file_id' => $fileId, 'auto_approved' => $autoApproved]);
            return;
        }
        View::setFlash('success', $msg);
        $this->redirect('/supervisor/flights/' . $flightId);
    }

    /**
     * Декодирует PNG из data-URL (то, что рисует signature_pad на canvas) и
     * сохраняет как обычную запись uploaded_files — тем же способом, что и
     * при обычной загрузке файлов, но без move_uploaded_file() (тут не
     * настоящая HTTP-загрузка, а декодированные байты из canvas).
     */
    private function storeSignaturePng(string $dataUrl): int
    {
        $base64 = preg_replace('#^data:image/png;base64,#', '', $dataUrl);
        $bytes  = base64_decode($base64, true);
        if ($bytes === false || strlen($bytes) < 100) {
            throw new \RuntimeException('Некорректные данные подписи.');
        }
        // Разумный предел на размер — подпись это лёгкий PNG, не должно быть мегабайт
        if (strlen($bytes) > 2 * 1024 * 1024) {
            throw new \RuntimeException('Файл подписи слишком большой.');
        }

        $config    = require BASE_PATH . '/config/app.php';
        $subDir    = 'signatures';
        $destDir   = $config['upload']['storage_path'] . '/' . $subDir;
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $storedName = bin2hex(random_bytes(16)) . '.png';
        $destPath   = $destDir . '/' . $storedName;

        if (file_put_contents($destPath, $bytes) === false) {
            throw new \RuntimeException('Не удалось записать файл подписи.');
        }
        $sha256 = hash_file('sha256', $destPath);

        $db = Database::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO uploaded_files
                (file_category, original_name, stored_name, mime_type, file_extension,
                 file_size_bytes, storage_disk, storage_path, sha256_hash, upload_status,
                 uploaded_by_user_id, uploaded_at, created_at)
             VALUES ('signature', 'signature.png', ?, 'image/png', 'png', ?, 'local', ?, ?, 'uploaded', ?, NOW(), NOW())"
        );
        $stmt->execute([$storedName, strlen($bytes), $subDir . '/' . $storedName, $sha256, Auth::id()]);

        return (int)$db->lastInsertId();
    }

    /**
     * Отдаёт саму картинку подписи по id файла — для <img> и на экране
     * подписания, и на печатной форме. Отдельный узкий эндпоинт, а не
     * общий /files/{id}/download — тот доступен только admin/cok/
     * commercial/finance, а подписи должны быть видны и супервайзеру,
     * и (со временем) любому, кто может открыть сам акт на печать.
     */
    public function viewSignature(array $params = []): void
    {
        $this->requireRole(['admin', 'cok', 'supervisor', 'commercial', 'finance']);

        $fileId = (int)$params['file_id'];
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT storage_path, mime_type FROM uploaded_files
             WHERE id = ? AND file_category = 'signature' AND upload_status = 'uploaded' LIMIT 1"
        );
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$file) {
            http_response_code(404);
            return;
        }

        $config   = require BASE_PATH . '/config/app.php';
        $fullPath = $config['upload']['storage_path'] . '/' . $file['storage_path'];
        if (!file_exists($fullPath)) {
            http_response_code(404);
            return;
        }

        header('Content-Type: ' . $file['mime_type']);
        header('Cache-Control: private, max-age=86400');
        readfile($fullPath);
    }

    private function respondJson(bool $success, string $message, array $extra = []): void
    {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    }
}
