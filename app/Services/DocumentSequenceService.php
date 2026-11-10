<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * DocumentSequenceService
 * Генерирует уникальные номера документов вида: ACT-2026-0001
 * Счётчик сбрасывается каждый год.
 */
class DocumentSequenceService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function next(string $key): string
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM `document_sequences` WHERE `seq_key` = ? FOR UPDATE"
            );
            $stmt->execute([$key]);
            $seq = $stmt->fetch();

            if (!$seq) {
                throw new \RuntimeException("Sequence key '{$key}' not found.");
            }

            $currentYear = (int)date('Y');
            $lastYear    = (int)($seq['last_year'] ?? $currentYear);

            $nextNumber = ($seq['reset_yearly'] && $currentYear !== $lastYear)
                ? 1
                : (int)$seq['last_number'] + 1;

            $this->db->prepare(
                "UPDATE `document_sequences`
                 SET `last_number` = ?, `last_year` = ?, `updated_at` = ?
                 WHERE `seq_key` = ?"
            )->execute([$nextNumber, $currentYear, date('Y-m-d H:i:s'), $key]);

            $this->db->commit();

            $padded = str_pad((string)$nextNumber, (int)$seq['pad_length'], '0', STR_PAD_LEFT);
            $prefix = $seq['prefix'];

            return $seq['reset_yearly']
                ? "{$prefix}-{$currentYear}-{$padded}"
                : "{$prefix}-{$padded}";

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function preview(string $key): string
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM `document_sequences` WHERE `seq_key` = ? LIMIT 1"
        );
        $stmt->execute([$key]);
        $seq = $stmt->fetch();
        if (!$seq) return '—';

        $currentYear = (int)date('Y');
        $lastYear    = (int)($seq['last_year'] ?? $currentYear);
        $nextNumber  = ($seq['reset_yearly'] && $currentYear !== $lastYear)
            ? 1
            : (int)$seq['last_number'] + 1;

        $padded = str_pad((string)$nextNumber, (int)$seq['pad_length'], '0', STR_PAD_LEFT);
        return $seq['reset_yearly']
            ? "{$seq['prefix']}-{$currentYear}-{$padded}"
            : "{$seq['prefix']}-{$padded}";
    }
}
