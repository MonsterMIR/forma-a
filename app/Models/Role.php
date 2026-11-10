<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class Role extends Model
{
    protected string $table = 'roles';

    public function findByCode(string $code): ?array
    {
        $stmt = $this->query(
            "SELECT * FROM `roles` WHERE `code` = ? LIMIT 1",
            [$code]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getAllRoles(): array
    {
        return $this->findAll([], 'name ASC');
    }
}