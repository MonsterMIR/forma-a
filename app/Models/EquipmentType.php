<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

class EquipmentType extends Model
{
    protected string $table = 'equipment_types';

    public function getAllActive(): array
    {
        return $this->query(
            "SELECT * FROM equipment_types WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getAll(): array
    {
        return $this->query(
            "SELECT et.*, u.full_name AS created_by_name
             FROM equipment_types et
             LEFT JOIN users u ON u.id = et.created_by_user_id
             ORDER BY et.sort_order ASC, et.name ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function isUsed(int $id): bool
    {
        $item = $this->find($id);
        if (!$item) return false;
        $stmt = $this->query(
            "SELECT COUNT(*) FROM equipment_log_items WHERE equipment_type = ?",
            [$item['code']]
        );
        return (int)$stmt->fetchColumn() > 0;
    }
}
