<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class ActItem extends Model
{
    protected string $table = 'act_items';

    /**
     * ИЗМЕНЕНО (14.08.2026, раунд 7): добавлена колонка `s.is_blanket` — см.
     * докблок над её объявлением в миграции round7 и в ActService.php. Нужна
     * для бейджа "По умолчанию" в resources/views/acts/edit.php — раньше он
     * был завязан на ai.is_default (см. ниже), что было неверно.
     */
    public function getByAct(int $actId): array
    {
        $stmt = $this->query(
            "SELECT ai.*,
                    s.code AS service_code,
                    s.is_blanket AS service_is_blanket,
                    sc.code AS category_code,
                    sc.name AS category_name,
                    c.code AS currency_code, c.symbol AS currency_symbol
             FROM `act_items` ai
             JOIN `services` s ON s.id = ai.service_id
             JOIN `service_categories` sc ON sc.id = s.category_id
             JOIN `currencies` c ON c.id = ai.currency_id
             WHERE ai.act_id = ?
             ORDER BY sc.sort_order ASC, ai.line_no ASC",
            [$actId]
        );
        return $stmt->fetchAll();
    }

    public function getNextLineNo(int $actId): int
    {
        $stmt = $this->query(
            "SELECT COALESCE(MAX(line_no), 0) + 1 FROM `act_items` WHERE act_id = ?",
            [$actId]
        );
        return (int)$stmt->fetchColumn();
    }

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->query(
            "SELECT ai.*,
                    s.name AS service_name_current, s.code AS service_code,
                    sc.code AS category_code,
                    c.code AS currency_code, c.symbol AS currency_symbol
             FROM `act_items` ai
             JOIN `services` s ON s.id = ai.service_id
             JOIN `service_categories` sc ON sc.id = s.category_id
             JOIN `currencies` c ON c.id = ai.currency_id
             WHERE ai.id = ? LIMIT 1",
            [$id]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function deleteByAct(int $actId): void
    {
        $this->query("DELETE FROM `act_items` WHERE `act_id` = ?", [$actId]);
    }

    /**
     * ДОБАВЛЕНО (14.08.2026, раунд 4): удаляет строку(и) акта по конкретной
     * услуге, но ТОЛЬКО если строка была добавлена автоматически
     * (`is_default = 1`, см. ActService::buildFromFlightCharges()). Ручные
     * строки, добавленные ЦОК через "+ Добавить услугу" (`is_default = 0`),
     * не трогает — используется перед повторным авто-добавлением услуги при
     * пересборке акта, чтобы не плодить дубли строк по одной и той же
     * услуге (см. addActItem()/buildFromFlightCharges()).
     */
    public function deleteAutoByActAndService(int $actId, int $serviceId): void
    {
        $this->query(
            "DELETE FROM `act_items` WHERE `act_id` = ? AND `service_id` = ? AND `is_default` = 1",
            [$actId, $serviceId]
        );
    }
}
