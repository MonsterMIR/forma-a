<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class ActItemCalculation extends Model
{
    protected string $table = 'act_item_calculations';

    public function getByItem(int $actItemId): array
    {
        $stmt = $this->query(
            "SELECT * FROM `act_item_calculations` WHERE `act_item_id` = ? ORDER BY `id` ASC",
            [$actItemId]
        );
        return $stmt->fetchAll();
    }

    public function deleteByItem(int $actItemId): void
    {
        $this->query(
            "DELETE FROM `act_item_calculations` WHERE `act_item_id` = ?",
            [$actItemId]
        );
    }

    public function insertBulk(int $actItemId, array $calculations): void
    {
        foreach ($calculations as $key => $val) {
            $this->insert([
                'act_item_id' => $actItemId,
                'calc_key'    => $key,
                'calc_value'  => (string)$val['value'],
                'calc_type'   => $val['type'] ?? 'string',
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }
}