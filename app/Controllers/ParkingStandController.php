<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\ParkingStand;

/**
 * ДОБАВЛЕНО (18.08.2026, раунд 16): справочник стоянок (МС№) — см. докблок
 * App\Models\ParkingStand.
 * ИСПРАВЛЕНО (18.08.2026, раунд 18): управление изначально было отдано
 * только admin — по образцу справочника аэропортов. Но правильная аналогия
 * — справочник спецтехники equipment_types, которым управляют admin И sst
 * (служба, реально знающая свою технику): см. EquipmentTypeController.
 * Для стоянок такая служба — aero (это они подают "Акт готовности
 * стоянки" и реально знают перрон), поэтому управление открыто и для неё.
 * Сам выбор стоянки на форме рейса и на планшете супервайзера по-прежнему
 * доступен всем, кто и так может править эти формы (проверка роли там уже
 * есть, здесь она не дублируется).
 */
class ParkingStandController extends Controller
{
    private ParkingStand $model;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model = new ParkingStand();
    }

    public function index(array $params = []): void
    {
        // ИЗМЕНЕНО (20.10.2026, раунд 104, по прямому указанию пользователя):
        // просмотр (не управление) открыт и супервайзеру — см. Permissions::
        // parking_stands.view. Методы store()/toggle()/destroy() ниже
        // по-прежнему только admin/aero (parking_stands.manage) — супервайзер
        // не может добавлять/скрывать/удалять стоянки, только смотреть.
        // ДОБАВЛЕНО (04.11.2026, раунд 150, по прямому указанию пользователя):
        // 'cok' добавлена сюда же, синхронно с Permissions::parking_stands.view
        // выше по файлу -- без этого здесь был бы 403 даже после того, как
        // пункт меню стал видимым (та же рассинхронизация, что чинили в round136
        // для airports.manage).
        $this->requireRole(['admin', 'aero', 'supervisor', 'cok']);

        // ДОБАВЛЕНО (15.10.2026, раунд 79, по прямому указанию пользователя):
        // "в справочнике стоянки все отражаются как свободные, хотя занято
        // 3-6 стоянок!" — диагноз: эта страница ("Статус" = Активна/Скрыта)
        // всегда показывала только КОНФИГУРАЦИЮ стоянки (есть ли она в
        // выпадающем списке), а не физическую занятость — такого столбца
        // тут не было вообще, пользователь закономерно принял отсутствие
        // индикатора "занято" за "всё свободно". Реальная занятость уже
        // верно считалась (см. ParkingStandService::getStatusBoard(),
        // round 48) — но отображалась только на /parking-acts, которая с
        // раунда 45 видна ТОЛЬКО admin. Подключаем ту же сводку сюда же,
        // на страницу, которую пользователь действительно называет
        // "справочником стоянок" и которую видят и admin, и aero.
        $occupancyByCode = [];
        try {
            foreach ((new \App\Services\ParkingStandService())->getStatusBoard() as $row) {
                $occupancyByCode[$row['stand']['code']] = $row;
            }
        } catch (\Throwable $e) {
            error_log('ParkingStandController::index getStatusBoard error: ' . $e->getMessage());
        }

        $this->render('parking_stands/index', [
            'title'           => 'Справочник стоянок',
            'items'           => $this->model->getAll(),
            'occupancyByCode' => $occupancyByCode,
        ]);
    }

    public function store(array $params = []): void
    {
        $this->requireRole(['admin', 'aero']);

        $code = strtoupper(trim((string)$this->request->input('code', '')));
        if (!$code) {
            View::setFlash('error', 'Укажите код стоянки.');
            $this->redirect('/parking-stands');
            return;
        }

        if ($this->model->findByCode($code)) {
            View::setFlash('error', "Стоянка «{$code}» уже есть в справочнике.");
            $this->redirect('/parking-stands');
            return;
        }

        $db   = \App\Core\Database::getInstance();
        $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM parking_stands");
        $stmt->execute();
        $next = (int)$stmt->fetchColumn();

        $this->model->insert([
            'code'               => $code,
            'is_active'          => 1,
            'sort_order'         => $next,
            'created_by_user_id' => Auth::id(),
        ]);

        View::setFlash('success', "Стоянка «{$code}» добавлена.");
        $this->redirect('/parking-stands');
    }

    public function toggle(array $params = []): void
    {
        $this->requireRole(['admin', 'aero']);
        $id   = (int)($params['id'] ?? 0);
        $item = $this->model->find($id);
        if (!$item) { $this->redirect('/parking-stands'); return; }

        $this->model->update($id, ['is_active' => $item['is_active'] ? 0 : 1]);
        View::setFlash('success', "«{$item['code']}» " . ($item['is_active'] ? 'скрыта.' : 'активирована.'));
        $this->redirect('/parking-stands');
    }

    public function destroy(array $params = []): void
    {
        $this->requireRole(['admin', 'aero']);
        $id   = (int)($params['id'] ?? 0);
        $item = $this->model->find($id);
        if (!$item) { $this->redirect('/parking-stands'); return; }

        if ($this->model->isUsed($id)) {
            View::setFlash('error', "Нельзя удалить «{$item['code']}» — уже указана хотя бы у одного рейса. Можно только скрыть.");
            $this->redirect('/parking-stands');
            return;
        }

        $this->model->delete($id);
        View::setFlash('success', "«{$item['code']}» удалена из справочника.");
        $this->redirect('/parking-stands');
    }
}
