<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

class AirlineParkingNorm extends Model
{
    protected string $table = 'airline_parking_norms';

    public function getByAirline(int $airlineId): ?array
    {
        $row = $this->query(
            "SELECT * FROM airline_parking_norms WHERE airline_id = ? LIMIT 1",
            [$airlineId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAllWithAirlines(): array
    {
        return $this->query(
            "SELECT al.id AS airline_id, al.name AS airline_name, al.iata_code,
                    apn.id AS norm_id, apn.free_hours_pax, apn.free_hours_cargo,
                    apn.notes, apn.updated_at
             FROM airlines al
             LEFT JOIN airline_parking_norms apn ON apn.airline_id = al.id
             ORDER BY al.name ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function upsert(int $airlineId, float $hoursPax, float $hoursCargo, ?string $notes, int $userId): void
    {
        $existing = $this->getByAirline($airlineId);
        $data = [
            'airline_id'         => $airlineId,
            'free_hours_pax'     => $hoursPax,
            'free_hours_cargo'   => $hoursCargo,
            'notes'              => $notes,
            'updated_by_user_id' => $userId,
        ];
        if ($existing) {
            $this->update((int)$existing['id'], $data);
        } else {
            $this->insert($data);
        }
    }
}
