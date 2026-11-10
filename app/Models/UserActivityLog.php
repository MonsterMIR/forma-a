<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class UserActivityLog extends Model
{
    protected string $table = 'user_activity_log';

    public function getFiltered(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[]  = "l.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        if (!empty($filters['entity_type'])) {
            $where[]  = "l.entity_type = ?";
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['action'])) {
            $where[]  = "l.action = ?";
            $params[] = $filters['action'];
        }

        if (!empty($filters['action_result'])) {
            $where[]  = "l.action_result = ?";
            $params[] = $filters['action_result'];
        }

        if (!empty($filters['date_from'])) {
            $where[]  = "l.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[]  = "l.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $where[]  = "(l.description LIKE ? OR l.route LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT l.*, u.username, u.full_name
             FROM `user_activity_log` l
             LEFT JOIN `users` u ON u.id = l.user_id
             WHERE {$whereStr}
             ORDER BY l.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return $stmt->fetchAll();
    }

    /**
     * ДОБАВЛЕНО (21.09.2026, раунд 49, п.1): история действий по конкретному
     * объекту (например по рейсу — entity_type='flight', entity_id=ID рейса) —
     * для панели "История по рейсу" на странице супервайзера, чтобы при
     * пересменке новый супервайзер видел, что уже сделано и кем.
     */
    public function getForEntity(string $entityType, int $entityId, int $limit = 100): array
    {
        $stmt = $this->query(
            "SELECT l.*, u.username, u.full_name
             FROM `user_activity_log` l
             LEFT JOIN `users` u ON u.id = l.user_id
             WHERE l.entity_type = ? AND l.entity_id = ?
             ORDER BY l.created_at DESC
             LIMIT {$limit}",
            [$entityType, $entityId]
        );
        return $stmt->fetchAll();
    }

    public function countFiltered(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[]  = "l.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        if (!empty($filters['entity_type'])) {
            $where[]  = "l.entity_type = ?";
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['action'])) {
            $where[]  = "l.action = ?";
            $params[] = $filters['action'];
        }

        if (!empty($filters['action_result'])) {
            $where[]  = "l.action_result = ?";
            $params[] = $filters['action_result'];
        }

        if (!empty($filters['date_from'])) {
            $where[]  = "l.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[]  = "l.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $where[]  = "(l.description LIKE ? OR l.route LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->query(
            "SELECT COUNT(*) FROM `user_activity_log` l
             LEFT JOIN `users` u ON u.id = l.user_id
             WHERE {$whereStr}",
            $params
        );
        return (int)$stmt->fetchColumn();
    }
}