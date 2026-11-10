<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\EquipmentType;

class EquipmentTypeController extends Controller
{
    private EquipmentType $model;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model = new EquipmentType();
    }

    public function index(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // справочник спецтехники (подраздел "Спецтехника") отключён для
        // всех, кроме admin — см. Permissions::$matrix, ключ
        // equipment_types.manage и комментарий там же.
        $this->requireRole(['admin']);
        $this->render('equipment_types/index', [
            'title' => 'Справочник спецтехники',
            'items' => $this->model->getAll(),
        ]);
    }

    public function store(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // справочник спецтехники (подраздел "Спецтехника") отключён для
        // всех, кроме admin — см. Permissions::$matrix, ключ
        // equipment_types.manage и комментарий там же.
        $this->requireRole(['admin']);

        $code = trim($this->request->input('code', ''));
        $name = trim($this->request->input('name', ''));
        if (!$code || !$name) {
            View::setFlash('error', 'Заполните код и название.');
            $this->redirect('/equipment-types');
            return;
        }

        $db   = \App\Core\Database::getInstance();
        $stmt = $db->prepare("SELECT id FROM equipment_types WHERE code = ?");
        $stmt->execute([$code]);
        if ($stmt->fetch()) {
            View::setFlash('error', "Код «{$code}» уже существует в справочнике.");
            $this->redirect('/equipment-types');
            return;
        }

        $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM equipment_types");
        $stmt->execute();
        $next = (int)$stmt->fetchColumn();

        $this->model->insert([
            'code'               => $code,
            'name'               => $name,
            'is_active'          => 1,
            'sort_order'         => $next,
            'created_by_user_id' => Auth::id(),
        ]);

        View::setFlash('success', "Тип техники «{$name}» добавлен.");
        $this->redirect('/equipment-types');
    }

    public function toggle(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // справочник спецтехники (подраздел "Спецтехника") отключён для
        // всех, кроме admin — см. Permissions::$matrix, ключ
        // equipment_types.manage и комментарий там же.
        $this->requireRole(['admin']);
        $id   = (int)($params['id'] ?? 0);
        $item = $this->model->find($id);
        if (!$item) { $this->redirect('/equipment-types'); return; }

        $this->model->update($id, ['is_active' => $item['is_active'] ? 0 : 1]);
        View::setFlash('success', "«{$item['name']}» " . ($item['is_active'] ? 'скрыт.' : 'активирован.'));
        $this->redirect('/equipment-types');
    }

    public function destroy(array $params = []): void
    {
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // справочник спецтехники (подраздел "Спецтехника") отключён для
        // всех, кроме admin — см. Permissions::$matrix, ключ
        // equipment_types.manage и комментарий там же.
        $this->requireRole(['admin']);
        $id   = (int)($params['id'] ?? 0);
        $item = $this->model->find($id);
        if (!$item) { $this->redirect('/equipment-types'); return; }

        if ($this->model->isUsed($id)) {
            View::setFlash('error', "Нельзя удалить «{$item['name']}» — используется в журналах. Можно только скрыть.");
            $this->redirect('/equipment-types');
            return;
        }

        $this->model->delete($id);
        View::setFlash('success', "«{$item['name']}» удалён из справочника.");
        $this->redirect('/equipment-types');
    }
}
