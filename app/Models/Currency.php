<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Currency extends Model
{
    protected string $table = 'currencies';

    public function getAllActive(): array
    {
        return $this->findAll([], 'name ASC');
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->query(
            "SELECT * FROM `currencies` WHERE `code` = ? LIMIT 1",
            [$code]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getNational(): ?array
    {
        $stmt = $this->query(
            "SELECT * FROM `currencies` WHERE `is_national` = 1 LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }
}