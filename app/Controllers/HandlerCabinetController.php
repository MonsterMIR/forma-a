<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ActivityLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Models\HandlerEmployee;
use App\Models\Role;
use App\Models\User;

/**
 * ДОБАВЛЕНО (22.09.2026, раунд 51, п.3, по прямому указанию пользователя):
 * "Персональный кабинет хендлера (представителя, это не персональный
 * кабинет пользователя) в этом кабинете Хендлер добавляет своих
 * представителей, ему система автоматом генерирует пароль (ввод своего
 * пароля нет). А уже в акте представитель может выбрать себя и потом
 * подписать акт (Должность представителя подтягивается автоматом из данных
 * пользователя)."
 *
 * Роль 'handler_admin' (см. миграцию round51_defaults_and_no_timing.sql) —
 * это учётная запись самого хендлера (компании-агента), НЕ участвует в
 * общей матрице Permissions (как и 'representative', см. Permissions::
 * REPRESENTATIVE) — доступ и область видимости ("только СВОИ сотрудники")
 * сделаны прямо здесь, через users.handler_id текущей сессии — ровно та же
 * схема, что и в RepresentativeController.
 *
 * ВАЖНО (уточнено ответом пользователя на уточняющий вопрос — "Тот же
 * планшет на перроне"): сам представитель НИКУДА не логинится, чтобы
 * подписать акт — подпись по-прежнему ставится на планшете супервайзера,
 * представитель там лишь выбирается из списка (см. sign_flight.php,
 * ActSignatureController::saveRepresentativeSignature()). Логин с
 * автогенерируемым паролем, который создаёт этот кабинет, — задел на
 * будущее (и подтверждение личности), а НЕ обязательное условие подписи:
 * создав здесь представителя, хендлер тут же видит его в списке сотрудников
 * на экране подписи — как обычную запись handler_employees, только теперь
 * привязанную к логину (handler_employees.user_id), из-за чего её ФИО и
 * должность начинают жить синхронно с профилем пользователя (см.
 * HandlerEmployee::COLS_WITH_USER).
 *
 * В ОТЛИЧИЕ от /portal (раунд 49) — этот кабинет НЕ под флагом
 * PORTAL_ENABLED: он не даёт удалённого доступа к актам, только управляет
 * списком тех, кто выбирается на уже существующем и всегда включённом
 * экране подписи, поэтому работает сразу после установки патча.
 */
class HandlerCabinetController extends Controller
{
    private User            $userModel;
    private HandlerEmployee $employeeModel;
    private Role             $roleModel;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->userModel     = new User();
        $this->employeeModel = new HandlerEmployee();
        $this->roleModel     = new Role();
    }

    public function dashboard(array $params = []): void
    {
        $this->requireRole(['handler_admin']);
        $handlerId = $this->ownHandlerId();

        $employees = $handlerId > 0 ? $this->employeeModel->getByHandler($handlerId, false) : [];

        $this->render('handler_cabinet/dashboard', [
            'layout'    => 'handler_cabinet',
            'title'     => 'Мои представители',
            'employees' => $employees,
            'handlerId' => $handlerId,
        ]);
    }

    public function create(array $params = []): void
    {
        $this->requireRole(['handler_admin']);
        if ($this->ownHandlerId() <= 0) {
            View::setFlash('error', 'Ваша учётная запись не привязана к хендлеру. Обратитесь в ЦОК.');
            $this->redirect('/handler-cabinet');
            return;
        }
        $this->render('handler_cabinet/create', [
            'layout' => 'handler_cabinet',
            'title'  => 'Новый представитель',
        ]);
    }

    public function store(array $params = []): void
    {
        $this->requireRole(['handler_admin']);
        $handlerId = $this->ownHandlerId();

        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/handler-cabinet/representatives/create');
            return;
        }
        if ($handlerId <= 0) {
            View::setFlash('error', 'Ваша учётная запись не привязана к хендлеру. Обратитесь в ЦОК.');
            $this->redirect('/handler-cabinet');
            return;
        }

        $data = $this->request->all();
        $validator = new Validator($data);
        $validator->required('full_name', 'ФИО')->maxLength('full_name', 150, 'ФИО');
        if (!empty($data['email'])) {
            $validator->email('email', 'Email');
        }
        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/handler-cabinet/representatives/create');
            return;
        }

        $fullName = trim($data['full_name']);
        $position = !empty($data['position']) ? trim($data['position']) : null;
        $phone    = !empty($data['phone']) ? trim($data['phone']) : null;
        $email    = !empty($data['email']) ? trim($data['email']) : null;

        $roleRow = $this->roleModel->findByCode('representative');
        if (!$roleRow) {
            View::setFlash('error', 'В системе не настроена роль "Представитель" — обратитесь в ЦОК.');
            $this->redirect('/handler-cabinet');
            return;
        }

        $username = $this->generateUniqueUsername($fullName);
        $password = $this->generatePassword();

        $this->userModel->beginTransaction();
        try {
            $userId = (int)$this->userModel->insert([
                'role_id'       => (int)$roleRow['id'],
                'full_name'     => $fullName,
                'username'      => $username,
                'email'         => $email,
                'phone'         => $phone,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'is_active'     => 1,
                'handler_id'    => $handlerId,
                'position'      => $position,
            ]);

            $employeeId = (int)$this->employeeModel->insert([
                'handler_id' => $handlerId,
                'user_id'    => $userId,
                'full_name'  => $fullName,
                'position'   => $position,
                'phone'      => $phone,
                'email'      => $email,
                'is_active'  => 1,
            ]);

            $this->userModel->commit();
        } catch (\Throwable $e) {
            $this->userModel->rollback();
            error_log('HandlerCabinetController::store error: ' . $e->getMessage());
            View::setFlash('error', 'Не удалось создать представителя. Попробуйте ещё раз.');
            $this->redirect('/handler-cabinet/representatives/create');
            return;
        }

        ActivityLogger::log(
            'handler_employee', $employeeId, 'create_handler_representative', 'success',
            "Хендлер создал представителя '{$fullName}' (логин {$username})"
        );

        // ДОБАВЛЕНО (раунд 51, п.3): логин/пароль показываем ОДИН раз, сразу
        // после создания — по прямому указанию пользователя "система
        // автоматом генерирует пароль", ручного ввода пароля нигде нет,
        // поэтому его нужно сообщить хендлеру именно сейчас (при
        // необходимости он всегда может сбросить пароль заново — см.
        // resetPassword()).
        $this->render('handler_cabinet/created', [
            'layout'   => 'handler_cabinet',
            'title'    => 'Представитель создан',
            'fullName' => $fullName,
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function edit(array $params = []): void
    {
        $this->requireRole(['handler_admin']);
        $employee = $this->authorizedEmployee((int)($params['id'] ?? 0));
        if (!$employee) {
            View::setFlash('error', 'Представитель не найден.');
            $this->redirect('/handler-cabinet');
            return;
        }
        $this->render('handler_cabinet/edit', [
            'layout'   => 'handler_cabinet',
            'title'    => 'Представитель: ' . $employee['full_name'],
            'employee' => $employee,
        ]);
    }

    public function update(array $params = []): void
    {
        $this->requireRole(['handler_admin']);
        $employee = $this->authorizedEmployee((int)($params['id'] ?? 0));
        if (!$employee) {
            View::setFlash('error', 'Представитель не найден.');
            $this->redirect('/handler-cabinet');
            return;
        }
        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/handler-cabinet/representatives/' . $employee['id'] . '/edit');
            return;
        }

        $data = $this->request->all();
        $validator = new Validator($data);
        $validator->required('full_name', 'ФИО')->maxLength('full_name', 150, 'ФИО');
        if (!empty($data['email'])) {
            $validator->email('email', 'Email');
        }
        if ($validator->fails()) {
            View::setFlash('error', $validator->firstError());
            $this->redirect('/handler-cabinet/representatives/' . $employee['id'] . '/edit');
            return;
        }

        $fullName = trim($data['full_name']);
        $position = !empty($data['position']) ? trim($data['position']) : null;
        $phone    = !empty($data['phone']) ? trim($data['phone']) : null;
        $email    = !empty($data['email']) ? trim($data['email']) : null;

        // ФИО/должность у записи с логином (наш случай — всегда, эта
        // запись всегда user_id != null) реально хранятся в users.* — сама
        // handler_employees хранится тоже (для записей без логина), но при
        // чтении переопределяется значением из users (см. HandlerEmployee::
        // COLS_WITH_USER). Пишем в обе таблицы одинаковые значения — так
        // handler_employees остаётся корректным резервным значением, если
        // логин когда-либо будет отвязан.
        $this->userModel->update((int)$employee['user_id'], [
            'full_name' => $fullName,
            'email'     => $email,
            'phone'     => $phone,
            'position'  => $position,
        ]);
        $this->employeeModel->update((int)$employee['id'], [
            'full_name' => $fullName,
            'position'  => $position,
            'phone'     => $phone,
            'email'     => $email,
        ]);

        ActivityLogger::log(
            'handler_employee', (int)$employee['id'], 'update_handler_representative', 'success',
            "Хендлер обновил данные представителя '{$fullName}'", $employee,
            ['full_name' => $fullName, 'position' => $position, 'phone' => $phone, 'email' => $email]
        );

        View::setFlash('success', 'Данные представителя обновлены.');
        $this->redirect('/handler-cabinet');
    }

    public function toggle(array $params = []): void
    {
        $this->requireRole(['handler_admin']);
        $employee = $this->authorizedEmployee((int)($params['id'] ?? 0));
        if (!$employee) {
            View::setFlash('error', 'Представитель не найден.');
            $this->redirect('/handler-cabinet');
            return;
        }
        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/handler-cabinet');
            return;
        }

        $newActive = $employee['is_active'] ? 0 : 1;
        $this->employeeModel->update((int)$employee['id'], ['is_active' => $newActive]);
        if (!empty($employee['user_id'])) {
            $this->userModel->update((int)$employee['user_id'], ['is_active' => $newActive]);
        }

        ActivityLogger::log(
            'handler_employee', (int)$employee['id'],
            $newActive ? 'activate_handler_representative' : 'deactivate_handler_representative', 'success',
            "Представитель '{$employee['full_name']}' " . ($newActive ? 'активирован' : 'деактивирован')
        );

        View::setFlash('success', 'Статус представителя обновлён.');
        $this->redirect('/handler-cabinet');
    }

    public function resetPassword(array $params = []): void
    {
        $this->requireRole(['handler_admin']);
        $employee = $this->authorizedEmployee((int)($params['id'] ?? 0));
        if (!$employee || empty($employee['user_id'])) {
            View::setFlash('error', 'Представитель не найден.');
            $this->redirect('/handler-cabinet');
            return;
        }
        if (!Csrf::verifyRequest($this->request)) {
            View::setFlash('error', 'Неверный CSRF-токен.');
            $this->redirect('/handler-cabinet');
            return;
        }

        $password = $this->generatePassword();
        $this->userModel->update((int)$employee['user_id'], [
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);

        ActivityLogger::log(
            'handler_employee', (int)$employee['id'], 'reset_handler_representative_password', 'success',
            "Хендлер сбросил пароль представителя '{$employee['full_name']}'"
        );

        $this->render('handler_cabinet/created', [
            'layout'   => 'handler_cabinet',
            'title'    => 'Пароль обновлён',
            'fullName' => $employee['full_name'],
            'username' => $employee['login_username'],
            'password' => $password,
            'isReset'  => true,
        ]);
    }

    /**
     * users.handler_id текущей учётной записи handler_admin — та же
     * колонка, что связывает и 'representative' с его порталом (раунд 49),
     * переиспользуем без изменений схемы.
     */
    private function ownHandlerId(): int
    {
        return (int)(Auth::user()['handler_id'] ?? 0);
    }

    /**
     * Сотрудник доступен этому кабинету, только если принадлежит СВОЕМУ
     * хендлеру — та же защита "не раскрываем чужие записи", что и в
     * RepresentativeController::authorizedAct().
     */
    private function authorizedEmployee(int $employeeId): ?array
    {
        $handlerId = $this->ownHandlerId();
        if ($handlerId <= 0 || $employeeId <= 0) {
            return null;
        }
        $employee = $this->employeeModel->find($employeeId);
        if (!$employee) return null;
        if ((int)($employee['handler_id'] ?? 0) !== $handlerId) return null;

        // find() отдаёт "сырые" колонки handler_employees, без живой
        // подмены из users — для экранов кабинета дочитываем через
        // findByUserId(), если запись привязана к логину, чтобы форма
        // редактирования показывала актуальные (а не устаревшие) значения.
        if (!empty($employee['user_id'])) {
            $withUser = $this->employeeModel->findByUserId((int)$employee['user_id']);
            if ($withUser) return $withUser;
        }
        return $employee;
    }

    private function generatePassword(int $length = 10): string
    {
        // Без визуально похожих символов (0/O, 1/l/I) — пароль читают и
        // вручную набирают на телефоне/планшете, редкая, но реальная
        // ситуация при первой выдаче.
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $max   = strlen($chars) - 1;
        $out   = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Логин система придумывает сама (пользователь заводит только ФИО) —
     * транслитерация ФИО в латиницу + числовой суффикс при совпадении.
     */
    private function generateUniqueUsername(string $fullName): string
    {
        $base = $this->transliterate($fullName);
        $base = preg_replace('/[^a-z0-9]+/', '.', mb_strtolower($base, 'UTF-8'));
        $base = trim((string)$base, '.');
        if ($base === '') {
            $base = 'rep';
        }
        $base = substr($base, 0, 40);

        $username = $base;
        $suffix   = 1;
        while ($this->userModel->usernameExists($username)) {
            $suffix++;
            $username = $base . $suffix;
        }
        return $username;
    }

    private function transliterate(string $text): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
            'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
            'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts',
            'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu',
            'я'=>'ya',
            'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh',
            'З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O',
            'П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'H','Ц'=>'Ts',
            'Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch','Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu',
            'Я'=>'Ya',
        ];
        return strtr($text, $map);
    }
}
