<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * MtowValidator — проверяет соответствие услуги MTOW рейса.
 *
 * Используется при сохранении начислений в любом контроллере (СПО, ССТ, СПАСОП и т.д.)
 * чтобы исключить внесение услуг несоответствующего диапазона MTOW.
 */
class MtowValidator
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Проверить что услуга подходит для данного MTOW.
     *
     * @param int   $serviceId  ID услуги
     * @param float $mtow       MTOW рейса в тоннах
     * @return array ['valid' => bool, 'message' => string]
     */
    public function validate(int $serviceId, float $mtow): array
    {
        $stmt = $this->db->prepare(
            "SELECT code, name, unit_name, mtow_min, mtow_max
             FROM services WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$serviceId]);
        $svc = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$svc) {
            return ['valid' => false, 'message' => 'Услуга не найдена.'];
        }

        // Услуга без ограничений по MTOW — всегда допустима
        if ($svc['mtow_min'] === null && $svc['mtow_max'] === null) {
            return ['valid' => true, 'message' => ''];
        }

        $min = (float)($svc['mtow_min'] ?? 0);
        $max = $svc['mtow_max'] !== null ? (float)$svc['mtow_max'] : PHP_FLOAT_MAX;

        if ($mtow < $min || $mtow > $max) {
            $range = $svc['mtow_max'] !== null
                ? "от {$min}т до {$max}т"
                : "от {$min}т и выше";
            return [
                'valid'   => false,
                'message' => "Услуга «{$svc['name']}» применяется только для ВС с MTOW {$range}. "
                           . "MTOW данного рейса: {$mtow}т.",
            ];
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * Проверить список услуг — вернуть только подходящие.
     *
     * @param int[] $serviceIds
     * @param float $mtow
     * @return array ['valid' => int[], 'invalid' => array[]]
     */
    public function filterServices(array $serviceIds, float $mtow): array
    {
        $valid   = [];
        $invalid = [];
        foreach ($serviceIds as $id) {
            $result = $this->validate((int)$id, $mtow);
            if ($result['valid']) {
                $valid[] = $id;
            } else {
                $invalid[] = ['service_id' => $id, 'message' => $result['message']];
            }
        }
        return compact('valid', 'invalid');
    }
}
