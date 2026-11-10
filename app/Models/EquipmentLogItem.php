<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

class EquipmentLogItem extends Model
{
    protected string $table = 'equipment_log_items';

    public function getByLog(int $logId): array
    {
        return $this->query(
            "SELECT * FROM equipment_log_items WHERE log_id = ? ORDER BY sort_order ASC, id ASC",
            [$logId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }
}
