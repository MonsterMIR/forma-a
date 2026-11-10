<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Model;

/**
 * ДОБАВЛЕНО (аудит 10.07.2026): предзаказ услуг/транспорта по рейсу.
 * Это ПЛАН, который формирует ЦОК при создании/ведении рейса — отдельно
 * от flight_service_charges (факт оказания). Супервайзер фиксирует по
 * каждому пункту факт по времени (started_at/completed_at).
 *
 * ДОБАВЛЕНО (06.08.2026): dispatched_at/dispatched_by_user_id — служба
 * отмечает выдвижение техники к борту (отдельно от start/complete, которые
 * супервайзер жмёт уже У БОРТА). Плюс createBulk()/syncPlan() теперь
 * возвращают ID реально добавленных позиций — нужно NotificationService,
 * чтобы уведомить только по-настоящему новые заказы, а не всё отмеченное.
 */
class FlightServiceOrder extends Model
{
    protected string $table = 'flight_service_orders';

    /**
     * Весь план по рейсу, с данными услуги и (если есть) фактическим
     * оказанием.
     *
     * ИЗМЕНЕНО (25.09.2026, раунд 58, по прямому указанию пользователя —
     * "услугу можно оказать несколько раз на одном рейсе, повтор не
     * бесплатный"): раньше JOIN на flight_service_charges шёл по
     * (flight_id, service_id) — это было однозначно, пока на обеих таблицах
     * стоял UNIQUE(flight_id, service_id), т.е. у одной услуги на рейсе
     * могла быть только ОДНА запись плана и ОДНА биллинговая запись. Теперь,
     * когда services.allow_repeat=1 разрешает несколько повторов одной
     * услуги на рейсе (несколько строк flight_service_orders и
     * flight_service_charges с одинаковыми flight_id+service_id), старый
     * JOIN дал бы декартово произведение — каждая позиция плана
     * "склеилась" бы со ВСЕМИ биллинговыми записями той же услуги на этом
     * рейсе, а не только со своей. Теперь JOIN идёт по новому
     * flight_service_charges.order_id — связи "эта заявка → эта конкретная
     * биллинговая запись" (см. миграцию round58 и
     * ServiceOrderController::confirmServiceCharge()). s.allow_repeat нужен
     * экрану супервайзера — показывать кнопку "Заказать ещё раз" только для
     * помеченных услуг.
     */
    public function getByFlightWithFact(int $flightId): array
    {
        // ДОБАВЛЕНО (19.08.2026, раунд 21): s.no_timing — см.
        // sql/round21_no_timing_column.sql и докблок в
        // _supervisor_order_card.php. Нужно на экране супервайзера, чтобы
        // решить, показывать пару "Начать/Завершить" или одну кнопку
        // "Оказано" (для услуг вроде МЕТЕО, у которых физически нет
        // процесса с началом и концом — просто оказана или нет).
        // ДОБАВЛЕНО (29.09.2026, раунд 64): s.quantity_from_timing — экран
        // супервайзера должен знать, что у этой услуги количество считается
        // сервером из started_at/completed_at (см. _supervisor_order_card.php
        // и ServiceOrderController::calcQuantityFromTiming()), а не введено
        // вручную — иначе чип количества после round64 просто не появится
        // (requires_manual_quantity у таких услуг теперь 0).
        return $this->query(
            "SELECT fso.*, s.code AS service_code, s.name AS service_name,
                    s.owner_role, s.unit_name, s.requires_manual_quantity, s.default_quantity,
                    s.no_timing, s.allow_repeat, s.quantity_from_timing,
                    fsc.id AS fact_charge_id, fsc.quantity AS fact_quantity,
                    fsc.source_role AS fact_source_role
             FROM flight_service_orders fso
             JOIN services s ON s.id = fso.service_id
             LEFT JOIN flight_service_charges fsc
                    ON fsc.order_id = fso.id
             WHERE fso.flight_id = ?
             ORDER BY s.owner_role, s.name, fso.id",
            [$flightId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Любая (в т.ч. уже завершённая/отменённая) позиция плана по этой
     * услуге на этом рейсе — сохранено для обратной совместимости с
     * "одноразовыми" услугами (services.allow_repeat=0): ЦОК-чек-лист
     * (createBulk()/syncPlan()) и addUnplanned()/addUnplannedBulk() для не
     * помеченных услуг по-прежнему блокируют повторное добавление по ЛЮБОЙ
     * существующей записи, независимо от статуса — как и до раунда 58.
     */
    public function findOne(int $flightId, int $serviceId): ?array
    {
        $row = $this->query(
            "SELECT * FROM flight_service_orders WHERE flight_id = ? AND service_id = ? LIMIT 1",
            [$flightId, $serviceId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * ДОБАВЛЕНО (25.09.2026, раунд 58): только ОТКРЫТАЯ (planned/
     * in_progress) позиция плана по этой услуге на этом рейсе — используется
     * вместо findOne() для услуг с allow_repeat=1: повторно заказать услугу
     * можно, если предыдущий раз уже закрыт (done/cancelled), но нельзя,
     * пока есть незакрытый (открытый) экземпляр — во избежание двух
     * параллельных "Начать/Завершить" на одну и ту же услугу одновременно.
     */
    public function findOpen(int $flightId, int $serviceId): ?array
    {
        $row = $this->query(
            "SELECT * FROM flight_service_orders
             WHERE flight_id = ? AND service_id = ? AND status IN ('planned', 'in_progress')
             LIMIT 1",
            [$flightId, $serviceId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Заказанные (planned/in_progress), но ещё не оказанные фактически услуги
     * КОНКРЕТНОЙ службы по рейсу — для отражения на странице этой службы.
     *
     * ИЗМЕНЕНО (06.08.2026): добавлены id/dispatched_at/requires_dispatch_notice —
     * баннер "Заказано ЦОК" теперь может показать кнопку "Техника выехала".
     *
     * ИЗМЕНЕНО (25.09.2026, раунд 58): проверка "ещё не оказано" была
     * NOT EXISTS по (flight_id, service_id) — верно, пока услуга могла быть
     * оказана на рейсе не более одного раза. При allow_repeat=1 после
     * первого оказанного экземпляра эта проверка стала бы скрывать и
     * ВТОРОЙ, ещё не оказанный экземпляр (найдя чужую, уже закрытую запись
     * по той же паре flight+service) — теперь проверяем отсутствие
     * биллинговой записи именно у ЭТОЙ позиции плана, через новый
     * flight_service_charges.order_id.
     */
    public function getPendingForRole(int $flightId, string $ownerRole): array
    {
        return $this->query(
            "SELECT fso.id, fso.flight_id, fso.service_id, fso.dispatched_at,
                    s.code AS service_code, s.name AS service_name, s.unit_name,
                    s.owner_role, s.requires_dispatch_notice
             FROM flight_service_orders fso
             JOIN services s ON s.id = fso.service_id
             WHERE fso.flight_id = ? AND s.owner_role = ? AND fso.status != 'cancelled'
               AND NOT EXISTS (
                   SELECT 1 FROM flight_service_charges fsc
                   WHERE fsc.order_id = fso.id
               )
             ORDER BY s.name",
            [$flightId, $ownerRole]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * ДОБАВЛЕНО (03.08.2026, round 8): массовое создание плана сразу при
     * создании рейса — чек-лист теперь прямо на форме создания. Для новой
     * (только что созданной) записи дублей быть не может, но проверяем
     * на всякий случай — дешевле, чем потом разбираться.
     *
     * ИЗМЕНЕНО (06.08.2026): возвращает не только count, но и сами ID —
     * нужно для уведомлений службам. Возврат count сохранён отдельным
     * методом createBulkCount() для обратной совместимости старых вызовов,
     * если где-то остались — но текущий код использует именно этот метод.
     */
    public function createBulk(int $flightId, array $serviceIds, ?int $orderedByUserId): array
    {
        $addedIds = [];
        foreach (array_unique($serviceIds) as $serviceId) {
            $serviceId = (int)$serviceId;
            if ($serviceId <= 0) continue;
            if ($this->findOne($flightId, $serviceId)) continue; // уже есть — пропускаем
            $this->query(
                "INSERT INTO flight_service_orders (flight_id, service_id, ordered_by_user_id, status, created_at)
                 VALUES (?, ?, ?, 'planned', NOW())",
                [$flightId, $serviceId, $orderedByUserId]
            );
            $addedIds[] = $serviceId;
        }
        return $addedIds;
    }

    /**
     * ДОБАВЛЕНО (03.08.2026, round 9): общая логика "привести план к
     * отмеченному списку" — используется и на форме редактирования рейса
     * (когда MTOW/маршрут поменялись и список нужно пересобрать), и в
     * ServiceOrderController::save() (чек-лист на странице рейса). Вынесена
     * сюда, чтобы не дублировать в двух контроллерах.
     *
     * Логика: то, что отмечено, но ещё не в плане — добавляется. То, что
     * было в плане, но сняли галочку — удаляется, ЕСЛИ ещё в статусе
     * 'planned'. Позиции, которые супервайзер уже начал/завершил, трогать
     * нельзя — их не удаляем, даже если галочку сняли, и считаем отдельно.
     *
     * ИЗМЕНЕНО (06.08.2026): в возврат добавлен added_service_ids —
     * NotificationService уведомляет службы только по реально новым
     * позициям, а не по всему отмеченному списку заново при каждом сохранении.
     */
    public function syncPlan(int $flightId, array $checkedServiceIds, ?int $userId): array
    {
        $checkedServiceIds = array_map('intval', $checkedServiceIds);
        $existing = $this->getByFlightWithFact($flightId);
        $existingByServiceId = [];
        foreach ($existing as $row) {
            $existingByServiceId[(int)$row['service_id']] = $row;
        }

        $added = 0; $removed = 0; $skipped = 0; $restored = 0;
        $addedServiceIds = [];

        foreach ($checkedServiceIds as $sid) {
            if (isset($existingByServiceId[$sid])) {
                $row = $existingByServiceId[$sid];
                /*
                 * ДОБАВЛЕНО (07.09.2026): восстановление услуги, ранее
                 * убранной супервайзером (status='cancelled', см.
                 * ServiceOrderController::removePlanned()). ДО этого места
                 * строка ниже была просто `continue` для ЛЮБОЙ уже
                 * существующей записи независимо от статуса — то есть если
                 * ЦОК на форме редактирования рейса отмечал галочкой ранее
                 * снятую супервайзером услугу и сохранял чек-лист, ничего
                 * не происходило: checkbox в интерфейсе выглядел рабочим
                 * (не задизейблен, отмечался), но результат молча
                 * игнорировался — услугу нельзя было вернуть никак, кроме
                 * прямого вмешательства в БД. Баг нашёл пользователь.
                 *
                 * Историю не затираем (тот же принцип, что и у самого
                 * removePlanned() — см. его докблок): дописываем в
                 * timing_note, кто и когда вернул, вместо перезаписи
                 * причины отмены.
                 */
                if ($row['status'] === 'cancelled') {
                    $note = trim((string)($row['timing_note'] ?? ''));
                    $note = $note !== ''
                        ? $note . ' | Восстановлено ЦОК ' . date('d.m.Y H:i')
                        : 'Восстановлено ЦОК ' . date('d.m.Y H:i');
                    $this->query(
                        "UPDATE flight_service_orders
                         SET status = 'planned', ordered_by_user_id = ?, timing_note = ?
                         WHERE id = ?",
                        [$userId, $note, $row['id']]
                    );
                    $restored++;
                    $addedServiceIds[] = $sid; // служба должна получить уведомление заново
                }
                continue; // уже в плане (planned/in_progress/done) или только что восстановили
            }
            $this->query(
                "INSERT INTO flight_service_orders (flight_id, service_id, ordered_by_user_id, status, created_at)
                 VALUES (?, ?, ?, 'planned', NOW())",
                [$flightId, $sid, $userId]
            );
            $added++;
            $addedServiceIds[] = $sid;
        }

        foreach ($existingByServiceId as $sid => $row) {
            if (in_array($sid, $checkedServiceIds, true)) continue; // осталось отмеченным
            if ($row['status'] === 'planned') {
                $this->query("DELETE FROM flight_service_orders WHERE id = ?", [$row['id']]);
                $removed++;
            } else {
                $skipped++; // уже в работе/оказано — не трогаем
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'skipped' => $skipped,
            'restored' => $restored,
            'added_service_ids' => $addedServiceIds,
        ];
    }

    /**
     * ДОБАВЛЕНО (14.08.2026, раунд 2, п.1): найти завершённый ('done') заказ
     * конкретной услуги по рейсу — нужно ActController::printForm(), чтобы
     * взять время начала/завершения "Лидирования ВС" (LEAD) как официальное
     * время прилёта/вылета борта для печатной Формы А (так по регламенту
     * заказчика — время прилёта считается стартом сопровождения ВС, время
     * вылета — завершением сопровождения).
     */
    public function findByFlightAndServiceCode(int $flightId, string $serviceCode): ?array
    {
        $row = $this->query(
            "SELECT fso.* FROM flight_service_orders fso
             JOIN services s ON s.id = fso.service_id
             WHERE fso.flight_id = ? AND s.code = ? AND fso.status = 'done'
             LIMIT 1",
            [$flightId, $serviceCode]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * ДОБАВЛЕНО (06.08.2026): служба отмечает, что техника/автобус выехали
     * к борту — отдельное событие от "Начать" супервайзера (то фиксируется
     * уже У БОРТА). Возвращает данные позиции для уведомления супервайзеру,
     * либо null, если позиция не найдена / уже отменена.
     */
    public function markDispatched(int $orderId, int $userId): ?array
    {
        $row = $this->query(
            "SELECT fso.*, s.name AS service_name, s.owner_role, s.requires_dispatch_notice
             FROM flight_service_orders fso
             JOIN services s ON s.id = fso.service_id
             WHERE fso.id = ? LIMIT 1",
            [$orderId]
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row || $row['status'] === 'cancelled') return null;
        if (!empty($row['dispatched_at'])) return $row; // уже отмечено — не дублируем уведомление

        $this->query(
            "UPDATE flight_service_orders SET dispatched_at = NOW(), dispatched_by_user_id = ? WHERE id = ?",
            [$userId, $orderId]
        );
        $row['dispatched_at'] = date('Y-m-d H:i:s');
        $row['dispatched_by_user_id'] = $userId;
        return $row;
    }
}
