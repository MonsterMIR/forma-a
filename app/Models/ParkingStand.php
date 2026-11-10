<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

/**
 * ДОБАВЛЕНО (18.08.2026, раунд 16): справочник стоянок — номер стоянки
 * (МС№) выбирается из списка, а не набирается вручную (неудобно на
 * планшете, легко опечататься и получить одну и ту же стоянку под разными
 * написаниями в разных актах). Тот же паттерн, что `equipment_types`
 * (справочник спецтехники ССТ) — код + активен/скрыт, без физического
 * удаления значений, которые уже где-то сохранены (см. isUsed()).
 */
class ParkingStand extends Model
{
    protected string $table = 'parking_stands';

    public function getAllActive(): array
    {
        return $this->query(
            "SELECT * FROM parking_stands WHERE is_active = 1 ORDER BY sort_order ASC, code ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getAll(): array
    {
        return $this->query(
            "SELECT ps.*, u.full_name AS created_by_name
             FROM parking_stands ps
             LEFT JOIN users u ON u.id = ps.created_by_user_id
             ORDER BY ps.sort_order ASC, ps.code ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->query("SELECT * FROM parking_stands WHERE code = ? LIMIT 1", [$code]);
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function isUsed(int $id): bool
    {
        $item = $this->find($id);
        if (!$item) return false;
        $stmt = $this->query("SELECT COUNT(*) FROM flights WHERE mc_number = ?", [$item['code']]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
