<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Airport extends Model
{
    protected string $table = 'airports';

    public function getFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(name LIKE ? OR iata_code LIKE ? OR city LIKE ? OR country LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT * FROM `airports` WHERE {$whereStr}
             ORDER BY name ASC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return $stmt->fetchAll();
    }

    public function countFiltered(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = "(name LIKE ? OR iata_code LIKE ? OR city LIKE ? OR country LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = "is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT COUNT(*) FROM `airports` WHERE {$whereStr}",
            $params
        );
        return (int)$stmt->fetchColumn();
    }

    public function getAllActive(): array
    {
        return $this->findAll(['is_active' => 1], 'name ASC');
    }

    /**
     * Находит аэропорт по IATA-коду (используется для определения "домашнего"
     * аэропорта TVT — Ташкент-Восточный — при автоподстановке в форме рейса).
     */
    public function findByIata(string $iataCode): ?array
    {
        $stmt = $this->query(
            "SELECT * FROM `airports` WHERE iata_code = ? LIMIT 1",
            [$iataCode]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * ДОБАВЛЕНО (25.11.2026, раунд 209, по жалобе пользователя на 500-ю
     * ошибку при попытке ЦОК отредактировать/дополнить справочник
     * аэропортов): iata_code/icao_code в таблице `airports` -- UNIQUE
     * (uq_airports_iata/uq_airports_icao), а AirportController::store()/
     * update() раньше никак не проверяли совпадение заранее -- просто
     * пытались сохранить, и при дубле MySQL бросал PDOException
     * (Duplicate entry...), который ничем не был пойман -- 500 вместо
     * понятного сообщения. Права на редактирование у ЦОК были и раньше
     * (round136, Permissions::$matrix['airports.manage']) -- проблема
     * была именно в необработанном исключении, а не в доступе. Ищет
     * СУЩЕСТВУЮЩИЙ аэропорт с тем же IATA или ICAO кодом (кроме самого
     * себя при редактировании, $excludeId) -- используется ДО
     * insert()/update(), чтобы отдать понятную ошибку вместо падения.
     */
    public function findConflicting(?string $iataCode, ?string $icaoCode, ?int $excludeId = null): ?array
    {
        if (empty($iataCode) && empty($icaoCode)) {
            return null;
        }

        $where  = [];
        $params = [];
        if (!empty($iataCode)) {
            $where[]  = 'iata_code = ?';
            $params[] = $iataCode;
        }
        if (!empty($icaoCode)) {
            $where[]  = 'icao_code = ?';
            $params[] = $icaoCode;
        }
        $sql = 'SELECT * FROM `airports` WHERE (' . implode(' OR ', $where) . ')';
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }
}