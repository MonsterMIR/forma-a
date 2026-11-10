<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\FlightDocument;
use App\Models\FlightDeviation;

/**
 * DeadlineService — расчёт и контроль сроков документооборота
 *
 * Основан на Положении, разделы 8.3 и 8.4:
 * T(STD) — плановое время вылета
 * T(STA) — плановое время прилёта
 * Отрицательные минуты = ДО вылета/прилёта
 * Положительные минуты = ПОСЛЕ вылета/прилёта
 */
class DeadlineService
{
    private FlightDocument  $docModel;
    private FlightDeviation $devModel;
    private \PDO            $db;

    // ─── Матрица сроков из Положения (раздел 8) ───────────────
    // Формат: doc_type => [service_role, departure_minutes, arrival_minutes, label]
    //
    // ИЗМЕНЕНО (14.08.2026, п.4-5): убраны из контроля сроков документы служб
    // СПО (техкарта), ССТ (журнал спецтехники) и Аэродромной службы (акт
    // стоянки) — эти службы фактически не подают документы через систему,
    // карточки по ним в "Документах служб" тоже убраны (см. _service_docs.php),
    // поэтому не должны создавать записи и в контроле сроков/отклонениях.
    // Также убран 'forma_a_pre' — ЦОК не готовит предварительный акт, только
    // окончательный. И убран 'transfer_commercial' — окончательный акт уходит
    // только в финансовую службу, передача в коммерцию не предусмотрена.
    // ИЗМЕНЕНО (14.08.2026, второй раунд): по уточнению убран и 'emergency_log'
    // (СПАСОП) — та же логика, что и СПО/ССТ/Аэро: служба не подаёт документ
    // через систему, значит не должна фигурировать в контроле сроков.
    // ИЗМЕНЕНО (21.09.2026, раунд 49, п.2, по прямому указанию пользователя):
    // убран 'manifest_pre' — первичный (предварительный) манифест больше не
    // существует как отдельный шаг, остался только один манифест на рейс.
    private const DEADLINES = [
        // СОП — Служба организации перевозок (8.4.1)
        'manifest_final' => ['sop', -20,  +20,  'Манифест'],

        // ЦОК — Форма А (8.5.1). Только окончательный акт, только в финансовую службу.
        'forma_a_final'  => ['cok', +60,  +60,  'Форма А (окончательная)'],
        'transfer_finance'    => ['cok', +90, +90, 'Передача в финансовую службу'],
    ];

    // Классификация нарушений (раздел 9)
    const MINOR       = 'minor';       // до 15 минут
    const SIGNIFICANT = 'significant'; // 15-60 минут
    const CRITICAL    = 'critical';    // более 60 минут

    public function __construct()
    {
        $this->docModel = new FlightDocument();
        $this->devModel = new FlightDeviation();
        $this->db       = Database::getInstance();
    }

    /**
     * Инициализировать записи сроков для рейса
     * Вызывается при создании рейса или установке scheduled_time
     */
    public function initForFlight(int $flightId): bool
    {
        $flight = $this->db->prepare(
            "SELECT * FROM flights WHERE id = ? LIMIT 1"
        );
        $flight->execute([$flightId]);
        $f = $flight->fetch(\PDO::FETCH_ASSOC);
        if (!$f) return false;

        // Если нет scheduled_time — создаём записи без дедлайна
        $scheduledTime = $f['scheduled_time'] ?? null;
        $opType        = $f['operation_type']; // arrival | departure
        $flightDate    = $f['flight_date'];

        try {
        foreach (self::DEADLINES as $docType => $config) {
            [$role, $depMin, $arrMin, $label] = $config;

            // Выбираем нужный offset
            $offsetMin = ($opType === 'departure') ? $depMin : $arrMin;
            if ($offsetMin === null) continue; // не применимо к этому типу

            // Считаем deadline_at
            $deadlineAt = null;
            if ($scheduledTime) {
                $base = strtotime("{$flightDate} {$scheduledTime}");
                $deadlineAt = date('Y-m-d H:i:s', $base + $offsetMin * 60);
            }

            // Не создаём дубли
            $exists = $this->docModel->getByFlightAndType($flightId, $docType);
            if ($exists) continue;

            $this->docModel->insert([
                'flight_id'        => $flightId,
                'doc_type'         => $docType,
                'service_role'     => $role,
                'status'           => 'pending',
                'deadline_minutes' => $offsetMin,
                'deadline_at'      => $deadlineAt,
                'notes'            => $label,
            ]);
        }

        // Помечаем рейс как инициализированный
        $upd = $this->db->prepare(
            "UPDATE flights SET documents_initialized = 1 WHERE id = ?"
        );
        $upd->execute([$flightId]);

        } catch (\Throwable $e) {
            error_log('DeadlineService::initForFlight error: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Обновить дедлайны при изменении scheduled_time рейса
     */
    public function recalcDeadlines(int $flightId): void
    {
        $flight = $this->db->prepare("SELECT * FROM flights WHERE id = ? LIMIT 1");
        $flight->execute([$flightId]);
        $f = $flight->fetch(\PDO::FETCH_ASSOC);
        if (!$f || !$f['scheduled_time']) return;

        $base    = strtotime("{$f['flight_date']} {$f['scheduled_time']}");
        $opType  = $f['operation_type'];
        $docs    = $this->docModel->getByFlight($flightId);

        foreach ($docs as $doc) {
            $config = self::DEADLINES[$doc['doc_type']] ?? null;
            if (!$config) continue;

            [, $depMin, $arrMin] = $config;
            $offsetMin = ($opType === 'departure') ? $depMin : $arrMin;
            if ($offsetMin === null) continue;

            $deadlineAt = date('Y-m-d H:i:s', $base + $offsetMin * 60);
            $this->docModel->update($doc['id'], [
                'deadline_at'      => $deadlineAt,
                'deadline_minutes' => $offsetMin,
            ]);
        }
    }

    /**
     * Отметить документ как поданный
     * Автоматически создаёт отклонение если опоздание
     */
    public function submitDocument(int $docId, int $userId, ?string $notes = null): array
    {
        $doc = $this->docModel->find($docId);
        if (!$doc) return ['success' => false, 'error' => 'Документ не найден'];

        $now = new \DateTimeImmutable();
        $updateData = [
            'status'               => 'submitted',
            'submitted_at'         => $now->format('Y-m-d H:i:s'),
            'submitted_by_user_id' => $userId,
        ];
        if ($notes) $updateData['notes'] = $notes;

        // Проверяем опоздание
        $deviation = null;
        if ($doc['deadline_at']) {
            $deadline    = new \DateTimeImmutable($doc['deadline_at']);
            $diffSeconds = $now->getTimestamp() - $deadline->getTimestamp();

            if ($diffSeconds > 0) {
                // Опоздание!
                $delayMin  = (int)ceil($diffSeconds / 60);
                $severity  = $this->classifySeverity($delayMin);
                $updateData['status'] = 'late';

                // ИСПРАВЛЕНО (14.08.2026, п.4): если документ уже был просрочен до
                // фактической подачи, updateOverdueStatuses() (вызывается из
                // getFlightStatus() при каждом открытии страницы рейса) уже мог
                // создать запись об отклонении. Раньше здесь всегда вставлялась
                // НОВАЯ запись — по одному опоздавшему документу в журнале
                // отклонений оказывалось два дубля. Теперь обновляем уже
                // существующую запись фактическими данными подачи, а не создаём вторую.
                $existingDev = $this->devModel->findByDocId($docId);
                $devData = [
                    'flight_id'              => $doc['flight_id'],
                    'flight_doc_id'          => $docId,
                    'service_role'           => $doc['service_role'],
                    'severity'               => $severity,
                    'delay_minutes'          => $delayMin,
                    'doc_type'               => $doc['doc_type'],
                    'description'            => "Документ «{$doc['notes']}» подан с опозданием на {$delayMin} мин.",
                    'registered_by_user_id'  => $userId,
                ];
                if ($existingDev) {
                    $this->devModel->update((int)$existingDev['id'], $devData);
                    $devId = (int)$existingDev['id'];
                } else {
                    $devId = $this->devModel->insert($devData);
                }
                $deviation = ['severity' => $severity, 'delay_minutes' => $delayMin, 'id' => $devId];
            }
        }

        $this->docModel->update($docId, $updateData);

        return [
            'success'   => true,
            'deviation' => $deviation,
        ];
    }

    /**
     * Обновить статусы просроченных документов (cron-like)
     * Вызывать периодически или при открытии страницы рейса
     */
    public function updateOverdueStatuses(): int
    {
        $overdue = $this->docModel->getPendingOverdue();
        $count   = 0;

        foreach ($overdue as $doc) {
            $now       = new \DateTimeImmutable();
            $deadline  = new \DateTimeImmutable($doc['deadline_at']);
            $delayMin  = (int)ceil(($now->getTimestamp() - $deadline->getTimestamp()) / 60);
            $severity  = $this->classifySeverity($delayMin);

            // Создаём отклонение если ещё нет
            $existsDev = $this->db->prepare(
                "SELECT id FROM flight_deviations WHERE flight_doc_id = ? LIMIT 1"
            );
            $existsDev->execute([$doc['id']]);
            if (!$existsDev->fetch()) {
                $this->devModel->insert([
                    'flight_id'             => $doc['flight_id'],
                    'flight_doc_id'         => $doc['id'],
                    'service_role'          => $doc['service_role'],
                    'severity'              => $severity,
                    'delay_minutes'         => $delayMin,
                    'doc_type'              => $doc['doc_type'],
                    'description'           => "Документ «{$doc['notes']}» не подан. Просрочка: {$delayMin} мин.",
                    'registered_by_user_id' => null,
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Получить статус документов рейса с расчётом цвета
     */
    public function getFlightStatus(int $flightId): array
    {
        try {
            $this->updateOverdueStatuses();
        } catch (\Throwable $e) {
            error_log('DeadlineService::updateOverdueStatuses error: ' . $e->getMessage());
        }
        try {
            $docs = $this->docModel->getByFlight($flightId);
        } catch (\Throwable $e) {
            return [];
        }

        // ДОБАВЛЕНО (14.08.2026, п.4): отфильтровываем типы документов, убранные
        // из текущей матрицы DEADLINES (techcard/equipment_log/parking_act/
        // forma_a_pre/transfer_commercial) — если по старым рейсам для них уже
        // были созданы записи, они больше не должны отображаться в контроле
        // сроков.
        $docs = array_values(array_filter($docs, fn($d) => isset(self::DEADLINES[$d['doc_type']])));

        $now  = time();

        return array_map(function($doc) use ($now) {
            $doc['color']      = $this->getStatusColor($doc, $now);
            $doc['label']      = self::DEADLINES[$doc['doc_type']][3] ?? $doc['doc_type'];
            $doc['time_left']  = null;
            $doc['delay_min']  = null;

            if ($doc['deadline_at']) {
                $deadlineTs = strtotime($doc['deadline_at']);
                $diff       = $deadlineTs - $now;

                if ($doc['status'] === 'pending') {
                    $doc['time_left'] = $diff; // секунды (отриц = просрочено)
                } elseif (in_array($doc['status'], ['submitted', 'late']) && $doc['submitted_at']) {
                    $submitTs = strtotime($doc['submitted_at']);
                    $delay    = $submitTs - $deadlineTs;
                    $doc['delay_min'] = $delay > 0 ? (int)ceil($delay / 60) : 0;
                }
            }
            return $doc;
        }, $docs);
    }

    /**
     * Классификация нарушения по разделу 9 Положения
     */
    public function classifySeverity(int $delayMinutes): string
    {
        if ($delayMinutes <= 15)  return self::MINOR;
        if ($delayMinutes <= 60)  return self::SIGNIFICANT;
        return self::CRITICAL;
    }

    /**
     * Цветовой статус для UI
     */
    private function getStatusColor(array $doc, int $now): string
    {
        return match($doc['status']) {
            'submitted' => 'success',
            'late'      => 'warning',
            'accepted'  => 'success',
            'pending'   => $this->getPendingColor($doc, $now),
            default     => 'secondary',
        };
    }

    private function getPendingColor(array $doc, int $now): string
    {
        if (!$doc['deadline_at']) return 'secondary';
        $deadlineTs = strtotime($doc['deadline_at']);
        $diff       = $deadlineTs - $now;

        if ($diff < 0)            return 'danger';   // просрочено
        if ($diff < 15 * 60)      return 'warning';  // менее 15 мин
        return 'info';                               // в норме
    }

    /**
     * Описание типа документа
     */
    public static function docTypeLabel(string $docType): string
    {
        return self::DEADLINES[$docType][3] ?? $docType;
    }

    /**
     * Отслеживается ли этот тип документа контролем сроков в текущей
     * конфигурации (см. комментарий к DEADLINES выше).
     */
    public static function isTrackedDocType(string $docType): bool
    {
        return isset(self::DEADLINES[$docType]);
    }

    /**
     * Описание роли на русском
     */
    public static function roleLabel(string $role): string
    {
        return match($role) {
            'sop'    => 'СОП',
            'spo'    => 'СПО',
            'sst'    => 'ССТ',
            'aero'   => 'Аэродромная',
            'spasop' => 'СПАСОП',
            'cok'    => 'ЦОК',
            default  => $role,
        };
    }

    /**
     * Описание тяжести нарушения
     */
    public static function severityLabel(string $severity): string
    {
        return match($severity) {
            'minor'       => 'Незначительное',
            'significant' => 'Существенное',
            'critical'    => 'Критическое',
            default       => $severity,
        };
    }

    public static function severityColor(string $severity): string
    {
        return match($severity) {
            'minor'       => 'warning',
            'significant' => 'orange',
            'critical'    => 'danger',
            default       => 'secondary',
        };
    }
}