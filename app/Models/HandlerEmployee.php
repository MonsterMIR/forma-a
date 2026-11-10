<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class HandlerEmployee extends Model
{
    protected string $table = 'handler_employees';

    /**
     * ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): список колонок расписан явно
     * (а не `he.*`), чтобы одноимённые алиасы full_name/position ниже не
     * конфликтовали с реальными колонками he.full_name/he.position при
     * ORDER BY (MySQL иначе ругается на неоднозначность имени колонки).
     * Список — только колонки, без FROM/JOIN, чтобы одинаково подставлять
     * его и в простой запрос, и в запрос с дополнительным JOIN `handlers`.
     *
     * Если у записи есть логин (user_id указывает на users) — ФИО и
     * должность берутся ЖИВЬЁМ из users.full_name/users.position, а не из
     * сохранённых когда-то значений в handler_employees. Это ровно то, что
     * просил пользователь: "Должность представителя подтягивается
     * автоматом из данных пользователя" — если хендлер (через свой кабинет,
     * см. HandlerCabinetController) поменяет должность представителя в его
     * профиле, экран подписи акта (ActSignatureController::
     * saveRepresentativeSignature(), читающий это же поле 'position') сразу
     * увидит новое значение, без отдельной синхронизации. Для записей без
     * логина (user_id IS NULL, как заводил ЦОК раньше, до раунда 51)
     * поведение не меняется — ФИО/должность берутся из самой таблицы, как
     * и было.
     */
    private const COLS_WITH_USER = "
            he.id, he.handler_id, he.user_id,
            COALESCE(u.full_name, he.full_name) AS full_name,
            COALESCE(u.position,  he.position)  AS position,
            he.phone, he.email, he.is_active, he.created_at, he.updated_at,
            u.username  AS login_username,
            u.is_active AS login_is_active
    ";

    private const JOIN_USER = " FROM `handler_employees` he LEFT JOIN `users` u ON u.id = he.user_id ";

    public function getByHandler(int $handlerId, bool $activeOnly = true): array
    {
        $sql = "SELECT " . self::COLS_WITH_USER . self::JOIN_USER . " WHERE he.handler_id = ?";
        if ($activeOnly) {
            $sql .= " AND he.is_active = 1";
        }
        $sql .= " ORDER BY full_name ASC";
        return $this->query($sql, [$handlerId])->fetchAll();
    }

    public function findWithHandler(int $id): ?array
    {
        $sql = "SELECT " . self::COLS_WITH_USER . ", h.name AS handler_name"
             . self::JOIN_USER
             . " JOIN `handlers` h ON h.id = he.handler_id
                WHERE he.id = ? LIMIT 1";
        $row = $this->query($sql, [$id])->fetch();
        return $row ?: null;
    }

    /**
     * ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): найти запись сотрудника по
     * привязанному логину (users.id) — нужно, чтобы HandlerCabinetController
     * мог найти "свою" запись handler_employees при редактировании/
     * деактивации представителя, не гадая её id отдельно.
     */
    public function findByUserId(int $userId): ?array
    {
        $sql = "SELECT " . self::COLS_WITH_USER . self::JOIN_USER . " WHERE he.user_id = ? LIMIT 1";
        $row = $this->query($sql, [$userId])->fetch();
        return $row ?: null;
    }
}
