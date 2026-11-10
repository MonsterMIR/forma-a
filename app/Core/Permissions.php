<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Матрица прав доступа по ролям
 * Основана на Положении о порядке контроля, учёта, сбора,
 * оформления первичной документации при обслуживании ВС
 * ООО «Toshkent-Humo xalqaro aeroporti»
 */
class Permissions
{
    // ─── Роли ────────────────────────────────────────────────
    const ADMIN      = 'admin';
    const COK        = 'cok';        // Центр оперативного контроля
    const SOP        = 'sop';        // Служба организации перевозок
    const SPO        = 'spo';        // Служба перронного обеспечения
    const SST        = 'sst';        // Служба специального транспорта
    const AERO       = 'aero';       // Аэродромная служба
    const COMMERCIAL = 'commercial'; // Коммерческая служба
    const FINANCE    = 'finance';    // Финансовая служба
    const SPASOP     = 'spasop';     // СПАСОП — Служба поискового и аварийно-спасательного обеспечения полётов
    const SUPERVISOR = 'supervisor'; // ДОБАВЛЕНО (аудит 10.07.2026): супервайзер — координация на перроне, планшет

    // ДОБАВЛЕНО (14.09.2026, раунд 41): перераспределение services.owner_role
    // по новой схеме подразделений — 3 новые "службы"-владельцы услуг, пока
    // без личных кабинетов (нет пользователей с такой ролью), но заведены
    // здесь и в roles, чтобы завести логин в будущем не потребовало правок
    // кода — только строку в users. Права ниже — тот же базовый набор
    // "видеть рейсы/справочники", что и у остальных служб-исполнителей
    // (SOP/SPO/SST/AERO/SPASOP), больше пока не нужно.
    const GSM     = 'gsm';     // ГСМ — Служба горюче-смазочных материалов
    const MEDICAL = 'medical'; // Здрав.пункт — Медицинская служба
    const BALANCE = 'balance'; // ОЦ — Отдел центровки

    // ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): внешняя роль — представитель
    // авиакомпании/хендлера, личный кабинет только для просмотра СВОИХ
    // утверждённых актов (см. RepresentativeController). НАМЕРЕННО не
    // добавлена ни в одну строку $matrix ниже — это не внутренний
    // сотрудник, ей не нужны (и не должны быть доступны) flights.view,
    // acts.view и другие внутренние права; доступ к порталу и фильтрация
    // "только акты своего хендлера" сделаны отдельно, напрямую в
    // RepresentativeController, в обход этой общей матрицы. Пока выключена
    // флагом PORTAL_ENABLED (config/portal.php) — см. AuthController::login().
    const REPRESENTATIVE = 'representative';

    // ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): внешняя роль — сам хендлер
    // (компания-агент), личный кабинет только для управления СВОИМИ
    // представителями (создание логинов с автогенерируемым паролем, см.
    // HandlerCabinetController). Как и REPRESENTATIVE выше — НАМЕРЕННО не
    // добавлена ни в одну строку $matrix ниже: это не внутренний сотрудник,
    // ей не нужны flights.view/acts.view и другие внутренние права; доступ
    // и область видимости ("только свои сотрудники") сделаны отдельно,
    // напрямую в HandlerCabinetController, через users.handler_id, в обход
    // этой общей матрицы. В ОТЛИЧИЕ от REPRESENTATIVE — НЕ под флагом
    // PORTAL_ENABLED, работает сразу (см. AuthController::login()).
    const HANDLER_ADMIN = 'handler_admin';

    // ─── Матрица прав ────────────────────────────────────────
    private static array $matrix = [

        // РЕЙСЫ
        'flights.view'   => [self::ADMIN, self::COK, self::SOP, self::SPO, self::SST, self::AERO, self::SPASOP, self::COMMERCIAL, self::FINANCE, self::SUPERVISOR, self::GSM, self::MEDICAL, self::BALANCE],
        'flights.create' => [self::ADMIN, self::COK],
        'flights.edit'   => [self::ADMIN, self::COK],
        'flights.import' => [self::ADMIN, self::COK],

        // АКТЫ (Форма А)
        'acts.view'     => [self::ADMIN, self::COK, self::COMMERCIAL, self::FINANCE],
        'acts.create'   => [self::ADMIN, self::COK],
        'acts.edit'     => [self::ADMIN, self::COK],
        'acts.approve'  => [self::ADMIN, self::COK],
        'acts.cancel'   => [self::ADMIN, self::COK],
        'acts.export'   => [self::ADMIN, self::FINANCE],
        'acts.print'    => [self::ADMIN, self::COK, self::COMMERCIAL, self::FINANCE],

        // ФАЙЛЫ / СКАНЫ
        'files.upload'  => [self::ADMIN, self::COK],
        'files.view'    => [self::ADMIN, self::COK, self::COMMERCIAL, self::FINANCE],
        'files.delete'  => [self::ADMIN],

        // МАНИФЕСТЫ (СОП)
        'manifests.view'   => [self::ADMIN, self::COK, self::SOP, self::COMMERCIAL, self::FINANCE],
        'manifests.create' => [self::ADMIN, self::COK, self::SOP],
        'manifests.edit'   => [self::ADMIN, self::COK, self::SOP],

        // ТЕХНОЛОГИЧЕСКАЯ КАРТА (СПО)
        // ИЗМЕНЕНО (18.09.2026, раунд 45, по прямому указанию пользователя):
        // "эти 4 меню по сути уже не нужны, их можно отключить от всех
        // пользователей кроме админа" — Техкарты/Спецтехника/Стоянки/СПАСОП
        // в верхнем меню. Это то же самое, что было отмечено как открытый
        // вопрос ещё в аудите раунда 41/42: реальные владельцы этих разделов
        // (СПО/ССТ/Аэродромная/СПАСОП) ими фактически не пользуются (0
        // записей в parking_acts за всю историю и т.д.) — теперь по прямому
        // решению пользователя доступ сужен до admin. COK тоже больше не
        // видит и не может открыть эти 4 раздела — пользователь явно сказал
        // "от всех пользователей кроме админа", без исключения для ЦОК.
        // Сами разделы не удалены — просто скрыты и заблокированы для всех,
        // кроме admin, на случай если понадобятся снова.
        'techcards.view'   => [self::ADMIN],
        'techcards.create' => [self::ADMIN],
        'techcards.edit'   => [self::ADMIN],

        // ЖУРНАЛ СПЕЦТЕХНИКИ (ССТ) — см. комментарий у techcards.view выше
        // (раунд 45)
        'equipment.view'   => [self::ADMIN],
        'equipment.create'       => [self::ADMIN],
        'equipment_types.manage' => [self::ADMIN],
        'equipment.edit'   => [self::ADMIN],

        // АКТ СТОЯНКИ (Аэродромная) — см. комментарий у techcards.view выше
        // (раунд 45). Дополнительно: с раунда 44 эта форма и так больше не
        // используется для расчёта OVER_PARK (см. AutoChargeService::
        // processParkingAct() — берёт время колодок напрямую, не отсюда).
        'parking.view'   => [self::ADMIN],
        'parking.create' => [self::ADMIN],
        'parking.edit'   => [self::ADMIN],

        // ЖУРНАЛ СПАСОП — см. комментарий у techcards.view выше (раунд 45)
        'emergency.view'   => [self::ADMIN],
        'emergency.create' => [self::ADMIN],
        'emergency.edit'   => [self::ADMIN],

        // ДОБАВЛЕНО (аудит 10.07.2026): предзаказ услуг/транспорта ЦОК + контроль
        // тайминга супервайзером. Разведено на два права намеренно: ЦОК планирует
        // состав заказа (что нужно), супервайзер фиксирует факт по времени
        // (когда началось/закончилось) — разная ответственность, разные роли.
        'service_orders.plan'      => [self::ADMIN, self::COK],
        'service_orders.supervise' => [self::ADMIN, self::COK, self::SUPERVISOR],

        // КОНТРОЛЬ СРОКОВ / ЖУРНАЛ ОТКЛОНЕНИЙ
        'deviations.view'   => [self::ADMIN, self::COK, self::COMMERCIAL, self::FINANCE],
        'deviations.manage' => [self::ADMIN, self::COK],

        // СПРАВОЧНИКИ (только просмотр для служб)
        'airlines.view'   => [self::ADMIN, self::COK, self::SOP, self::SPO, self::SST, self::AERO, self::SPASOP, self::COMMERCIAL, self::FINANCE, self::GSM, self::MEDICAL, self::BALANCE],
        'airlines.manage' => [self::ADMIN, self::COK],
        'airports.view'   => [self::ADMIN, self::COK, self::SOP, self::SPO, self::SST, self::AERO, self::SPASOP, self::COMMERCIAL, self::FINANCE, self::GSM, self::MEDICAL, self::BALANCE],
        'airports.manage' => [self::ADMIN, self::COK], // ИСПРАВЛЕНО (28.10.2026, round136): интерфейс (airports/index.php) уже показывал кнопки создания/редактирования для cok, а контроллер и эта матрица требовали только admin — 403; синхронизировано
        'services.view'   => [self::ADMIN, self::COK, self::COMMERCIAL, self::FINANCE],
        'services.manage' => [self::ADMIN],
        'tariffs.view'    => [self::ADMIN, self::COK, self::COMMERCIAL, self::FINANCE],
        'tariffs.manage'       => [self::ADMIN, self::COMMERCIAL],
        'parking_norms.manage' => [self::ADMIN, self::COMMERCIAL],
        // ДОБАВЛЕНО (18.08.2026, раунд 16): справочник стоянок (МС№) — только
        // просмотр списка при выборе на форме рейса/у супервайзера доступен
        // всем, кто и так может редактировать эти формы (см. requireRole там же);
        // здесь регулируется только страница управления справочником.
        // ИСПРАВЛЕНО (18.08.2026, раунд 18): изначально был только admin — по
        // образцу справочника аэропортов. Верная аналогия — equipment_types
        // (admin + sst, служба, реально знающая технику); для стоянок такая
        // служба — aero, добавлена сюда же, как sst добавлена в
        // equipment_types.manage выше.
        'parking_stands.manage' => [self::ADMIN, self::AERO],

        // ДОБАВЛЕНО (20.10.2026, раунд 104, по прямому указанию пользователя):
        // супервайзеру нужно ВИДЕТЬ перечень стоянок и занятость (какой борт где
        // стоит), но не управлять справочником (добавление/скрытие/удаление
        // стоянок остаётся за admin/aero, см. parking_stands.manage выше).
        // ДОБАВЛЕНО (04.11.2026, раунд 150, по прямому указанию пользователя):
        // то же самое нужно и сотрудникам ЦОК (Нигматуллина и т.п.) -- видеть,
        // какой борт на какой стоянке стоит, не управляя справочником.
        'parking_stands.view' => [self::ADMIN, self::AERO, self::SUPERVISOR, self::COK],

        // ЭКСПОРТ В 1С
        'export.view'   => [self::ADMIN, self::FINANCE],
        'export.exec'   => [self::ADMIN, self::FINANCE],

        // АУДИТ
        'audit.view'    => [self::ADMIN, self::COK],

        // ПОЛЬЗОВАТЕЛИ И РОЛИ
        'users.manage'    => [self::ADMIN],
        'settings.manage' => [self::ADMIN],

        // ХЕНДЛЕРЫ (раунд 46, п.5/п.6) — компании-агенты авиакомпаний и их
        // сотрудники, из чьего списка супервайзер на планшете выбирает
        // подписанта акта вместо ввода ФИО вручную. Ведут ЦОК и админ — по
        // аналогии с airlines.manage (тот же круг лиц знает, кто с кем
        // работает на перроне).
        'handlers.view'   => [self::ADMIN, self::COK, self::COMMERCIAL],
        // ИЗМЕНЕНО (20.10.2026, раунд 101, по прямому указанию пользователя):
        // создание/подтверждение хендлеров теперь также у коммерческой службы
        // (первичный источник истины — договор); ЦОК не убирался.
        'handlers.manage' => [self::ADMIN, self::COK, self::COMMERCIAL],

        // ДОБАВЛЕНО (20.10.2026, раунд 103, по прямому указанию пользователя):
        // скрытый журнал исходных текстов разрешений, вводимых при импорте
        // рейсов — строго только администратор, см. PermitLogController.
        'permit_logs.view' => [self::ADMIN],
    ];

    /**
     * Проверить право текущего пользователя
     */
    public static function can(string $permission): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        $role = $user['role_code'];

        // Админ имеет все права
        if ($role === self::ADMIN) return true;

        return in_array($role, self::$matrix[$permission] ?? [], true);
    }

    /**
     * Получить все роли имеющие право
     */
    public static function rolesFor(string $permission): array
    {
        return self::$matrix[$permission] ?? [];
    }

    /**
     * Получить все права роли
     */
    public static function permissionsFor(string $role): array
    {
        $perms = [];
        foreach (self::$matrix as $perm => $roles) {
            if (in_array($role, $roles, true)) {
                $perms[] = $perm;
            }
        }
        return $perms;
    }

    /**
     * Список всех ролей с описаниями
     */
    public static function allRoles(): array
    {
        return [
            self::ADMIN      => 'Администратор',
            self::COK        => 'ЦОК — Центр оперативного контроля',
            self::SOP        => 'СОП — Служба организации перевозок',
            self::SPO        => 'СПО — Служба перронного обеспечения',
            self::SST        => 'ССТ — Служба специального транспорта',
            self::AERO       => 'Аэродромная служба',
            self::COMMERCIAL => 'Коммерческая служба',
            self::FINANCE    => 'Финансовая служба',
            self::SPASOP     => 'СПАСОП — Аварийно-спасательная служба',
            self::SUPERVISOR => 'Супервайзер',
            self::GSM        => 'ГСМ — Служба горюче-смазочных материалов',
            self::MEDICAL    => 'Здрав.пункт — Медицинская служба',
            self::BALANCE    => 'ОЦ — Отдел центровки',
            self::REPRESENTATIVE => 'Представитель авиакомпании/хендлера (портал, пока выключен)',
            self::HANDLER_ADMIN  => 'Хендлер — кабинет управления представителями',
        ];
    }

    /**
     * Иконка роли для UI
     */
    public static function roleIcon(string $role): string
    {
        return match($role) {
            self::ADMIN      => 'bi-shield-fill',
            self::COK        => 'bi-display',
            self::SOP        => 'bi-people-fill',
            self::SPO        => 'bi-airplane-fill',
            self::SST        => 'bi-truck',
            self::AERO       => 'bi-geo-alt-fill',
            self::COMMERCIAL => 'bi-graph-up',
            self::FINANCE    => 'bi-cash-stack',
            self::SPASOP     => 'bi-shield-fill-exclamation',
            self::SUPERVISOR => 'bi-tablet',
            self::GSM        => 'bi-fuel-pump-fill',
            self::MEDICAL    => 'bi-heart-pulse-fill',
            self::BALANCE    => 'bi-arrows-angle-contract',
            self::REPRESENTATIVE => 'bi-briefcase-fill',
            self::HANDLER_ADMIN  => 'bi-building-gear',
            default          => 'bi-person',
        };
    }

    /**
     * Цвет роли для badge
     */
    public static function roleBadgeColor(string $role): string
    {
        return match($role) {
            self::ADMIN      => 'danger',
            self::COK        => 'primary',
            self::SOP        => 'info',
            self::SPO        => 'warning',
            self::SST        => 'secondary',
            self::AERO       => 'success',
            self::COMMERCIAL => 'dark',
            self::FINANCE    => 'purple',
            self::SPASOP     => 'danger',
            self::SUPERVISOR => 'teal',
            self::GSM        => 'orange',
            self::MEDICAL    => 'pink',
            self::BALANCE    => 'indigo',
            self::REPRESENTATIVE => 'secondary',
            self::HANDLER_ADMIN  => 'teal',
            default          => 'light',
        };
    }
}
