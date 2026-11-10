<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ActivityLogger;
use App\Core\Auth;
use App\Models\TariffVersion;

class TariffService
{
    private TariffVersion $tariffModel;

    public function __construct()
    {
        $this->tariffModel = new TariffVersion();
    }

    public function createTariff(array $data): array
    {
        $userId = Auth::id();

        $insertData = [
            'service_id'          => (int)$data['service_id'],
            'tariff_name'         => trim($data['tariff_name']),
            'route_type'          => $data['route_type'],
            'operation_type'      => $data['operation_type'],
            'currency_id'         => (int)$data['currency_id'],
            'price'               => (float)$data['price'],
            'vat_rate'            => (float)($data['vat_rate'] ?? 0),
            'valid_from'          => $data['valid_from'],
            'valid_to'            => !empty($data['valid_to']) ? $data['valid_to'] : null,
            'is_active'           => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            'created_by_user_id'  => $userId,
        ];

        $id = (int)$this->tariffModel->insert($insertData);

        ActivityLogger::log(
            'tariff', $id, 'create_tariff', 'success',
            "Created tariff '{$insertData['tariff_name']}' for service_id={$insertData['service_id']}",
            null,
            $insertData
        );

        return ['success' => true, 'id' => $id];
    }

    public function updateTariff(int $id, array $data): array
    {
        $existing = $this->tariffModel->find($id);
        if (!$existing) {
            return ['success' => false, 'error' => 'Tariff not found.'];
        }

        $newPrice    = (float)$data['price'];
        $newValidFrom = $data['valid_from'];
        $priceChanged = abs($newPrice - (float)$existing['price']) > 0.0001
                     || $newValidFrom !== $existing['valid_from'];

        // Если цена или дата начала изменились — закрываем старую версию
        // и создаём новую. Это обеспечивает историчность тарифов:
        // старые акты (draft и approved) сохраняют цену момента создания.
        if ($priceChanged) {
            $yesterday = date('Y-m-d', strtotime($newValidFrom . ' -1 day'));

            // Закрываем старую версию
            $this->tariffModel->update($id, [
                'valid_to'  => $yesterday,
                'is_active' => 0,
            ]);

            // Создаём новую версию
            $newId = (int)$this->tariffModel->insert([
                'service_id'         => (int)$existing['service_id'],
                'tariff_name'        => trim($data['tariff_name']),
                'route_type'         => $data['route_type'],
                'operation_type'     => $data['operation_type'],
                'currency_id'        => (int)$data['currency_id'],
                'price'              => $newPrice,
                'vat_rate'           => (float)($data['vat_rate'] ?? 0),
                'valid_from'         => $newValidFrom,
                'valid_to'           => !empty($data['valid_to']) ? $data['valid_to'] : null,
                'is_active'          => 1,
                'created_by_user_id' => Auth::id(),
            ]);

            ActivityLogger::log(
                'tariff', $newId, 'new_tariff_version', 'success',
                "New version created (price changed from {$existing['price']} to {$newPrice}). Old id={$id} closed.",
                $existing,
                ['new_id' => $newId, 'price' => $newPrice]
            );

            return ['success' => true, 'id' => $newId, 'new_version' => true];
        }

        // Если цена не менялась — просто обновляем мета-данные
        $updateData = [
            'tariff_name'    => trim($data['tariff_name']),
            'route_type'     => $data['route_type'],
            'operation_type' => $data['operation_type'],
            'vat_rate'       => (float)($data['vat_rate'] ?? 0),
            'valid_to'       => !empty($data['valid_to']) ? $data['valid_to'] : null,
            'is_active'      => (int)$data['is_active'],
        ];

        $this->tariffModel->update($id, $updateData);

        ActivityLogger::log(
            'tariff', $id, 'update_tariff', 'success',
            "Updated tariff metadata (price unchanged) '{$updateData['tariff_name']}'",
            $existing,
            $updateData
        );

        return ['success' => true, 'id' => $id, 'new_version' => false];
    }

    public function getActiveTariffForService(int $serviceId, string $routeType, string $operationType, string $date): ?array
    {
        return $this->tariffModel->getActiveForService($serviceId, $routeType, $operationType, $date);
    }
}