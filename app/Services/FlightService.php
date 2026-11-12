<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ActivityLogger;
use App\Models\Act;
use App\Models\Flight;

class FlightService
{
    private Flight $flightModel;
    private \PDO   $db;

    public function __construct()
    {
        $this->flightModel = new Flight();
        $this->db           = \App\Core\Database::getInstance();
    }

    /**
     * ДОБАВЛЕНО (15.10.2026, раунд 77, по прямому указанию пользователя):
     * тип рейса (местный/международный) больше не выбирается вручную ЦОК на
     * форме — вычисляется автоматически по аэропортам вылета/прилёта, чтобы
     * убрать человеческую ошибку (ручной переключатель, который легко
     * перепутать). Правило пользователя: "при прилете и вылете оба аэропорта
     * если местные то рейс местный, если хотя бы один не местный рейс
     * международный" — каждый рейс (прилёт/вылет — это ОТДЕЛЬНЫЕ записи,
     * см. round 33) проверяется сам по себе, без привязки к паре. Поскольку
     * один из двух аэропортов всегда TVT (наш, местный по определению) — на
     * практике это сводится к проверке только второй стороны, но код не
     * завязывается на это жёстко, а честно проверяет оба `airports.is_local`.
     *
     * Если аэропорт не указан или не найден в справочнике — считаем его НЕ
     * местным (безопасный default "иностранный", тот же принцип, что и для
     * резидентства авиакомпании, round 77).
     */
    private function resolveRouteType(?int $originAirportId, ?int $destinationAirportId): string
    {
        $isLocal = function (?int $airportId): bool {
            if (!$airportId) return false;
            $stmt = $this->db->prepare("SELECT is_local FROM airports WHERE id = ? LIMIT 1");
            $stmt->execute([$airportId]);
            return (bool)$stmt->fetchColumn();
        };

        return ($isLocal($originAirportId) && $isLocal($destinationAirportId)) ? 'domestic' : 'international';
    }

    public function createFlight(array $data): array
    {
        $insertData = [
            'airline_id'             => (int)$data['airline_id'],
            'flight_number'          => strtoupper(trim($data['flight_number'])),
            'flight_date'            => $data['flight_date'],
            'route_type'             => $this->resolveRouteType(
                !empty($data['origin_airport_id']) ? (int)$data['origin_airport_id'] : null,
                !empty($data['destination_airport_id']) ? (int)$data['destination_airport_id'] : null
            ),
            'operation_type'         => $data['operation_type'],
            'origin_airport_id'      => !empty($data['origin_airport_id']) ? (int)$data['origin_airport_id'] : null,
            'destination_airport_id' => !empty($data['destination_airport_id']) ? (int)$data['destination_airport_id'] : null,
            'aircraft_type'          => !empty($data['aircraft_type']) ? trim($data['aircraft_type']) : null,
            // ИЗМЕНЕНО (02.11.2026, раунд 142, по требованию бухгалтерии):
            // MTOW округляется ВВЕРХ до целого на этапе сохранения — целая
            // часть остаётся, дробная всегда уходит в большую сторону
            // (12.1 -> 13, 21.8 -> 22). В системе не должно оставаться MTOW
            // со значением после запятой — ни у рейса, ни (см. Aircraft::
            // createIfMissing()/confirm()) в справочнике бортов.
            'aircraft_mtow'          => !empty($data['aircraft_mtow']) ? (float)ceil((float)$data['aircraft_mtow']) : null,
            'aircraft_registration'  => !empty($data['aircraft_registration']) ? strtoupper(trim($data['aircraft_registration'])) : null,
            'scheduled_time'         => !empty($data['scheduled_time']) ? $data['scheduled_time'] : null,
            // УДАЛЕНО (23.09.2026, раунд 52): 'actual_time' (ATD/ATA) больше не
            // собирается — поле оказалось мёртвым дублем arr_time/dep_time
            // (ничего его не читало, кроме read-only строки на flights/show.php),
            // путало пользователя с реальными полями ниже и стало причиной
            // жалобы на дублирование даты/времени в форме рейса. Столбец в БД
            // и старые значения не трогаем — просто больше не пишем в него.
            // Фактические времена прилёта/вылета и данные PAX для Формы А
            'arr_date'               => !empty($data['arr_date'])       ? $data['arr_date']   : null,
            'arr_time'               => !empty($data['arr_time'])       ? $data['arr_time']   : null,
            'dep_date'               => !empty($data['dep_date'])       ? $data['dep_date']   : null,
            'dep_time'               => !empty($data['dep_time'])       ? $data['dep_time']   : null,
            'pax_arrival'            => isset($data['pax_arrival'])     && $data['pax_arrival'] !== '' ? (int)$data['pax_arrival']   : null,
            'pax_departure'          => isset($data['pax_departure'])   && $data['pax_departure'] !== '' ? (int)$data['pax_departure'] : null,
            'infant_arrival'         => isset($data['infant_arrival'])  && $data['infant_arrival'] !== '' ? (int)$data['infant_arrival'] : null,
            'infant_departure'       => isset($data['infant_departure'])&& $data['infant_departure'] !== '' ? (int)$data['infant_departure'] : null,
            // ДОБАВЛЕНО (19.08.2026, раунд 21): DHC (deadhead crew — запасной
            // экипаж/экипаж в качестве пассажиров) — та же подпись уже была
            // в печатной форме акта, но ни поля ввода, ни столбца в БД не
            // существовало вообще (см. sql/round21_dhc_column.sql).
            'dhc_arrival'            => isset($data['dhc_arrival'])     && $data['dhc_arrival'] !== '' ? (int)$data['dhc_arrival']   : null,
            'dhc_departure'          => isset($data['dhc_departure'])   && $data['dhc_departure'] !== '' ? (int)$data['dhc_departure'] : null,
            'mc_number'              => !empty($data['mc_number'])      ? strtoupper(trim($data['mc_number'])) : null,
            'chocks_on'              => !empty($data['chocks_on'])      ? $data['chocks_on']  : null,
            'chocks_off'             => !empty($data['chocks_off'])     ? $data['chocks_off'] : null,
            'notes'                  => !empty($data['notes']) ? trim($data['notes']) : null,
            // ДОБАВЛЕНО (22.09.2026, раунд 51): хендлер, распознанный из
            // строки "ACC AGR WITH ..." заявки (см. FlightController::
            // findOrCreateHandler()) — используется на экране подписи как
            // приоритетный кандидат, см. HandlerService::getCandidatesForFlight().
            // Намеренно НЕ добавлено в updateFlight() ниже — обычная форма
            // редактирования рейса не присылает этот ключ вообще, а там
            // update идёт полным перезаписыванием массива полей, так что
            // добавление того же ключа туда без формы для него тихо стирало
            // бы уже определённого хендлера при любом обычном сохранении
            // рейса. Пока это поле выставляется только один раз, при импорте.
            'resolved_handler_id'    => !empty($data['resolved_handler_id']) ? (int)$data['resolved_handler_id'] : null,
        ];

        // ДОБАВЛЕНО (22.10.2026, раунд 117, по прямому указанию пользователя):
        // справочник бортов (таблица `aircraft`) — MTOW физически принадлежит
        // САМОМУ борту, а не конкретному рейсу (повод: 9 из 45 бортов системы
        // имели разный MTOW на разных своих рейсах, т.к. ЦОК вводил его заново
        // руками на каждом рейсе — см. claude/analysis-2026-10-22-round117...).
        // Если регистрация уже ПОДТВЕРЖДЕНА в справочнике (mtow не NULL) —
        // берём MTOW (и тип ВС, если на форме пусто) оттуда, значение с формы
        // игнорируется — убирает саму возможность опечатки при повторном
        // визите того же борта. Если борт в справочнике "спорный" (mtow=NULL
        // — расхождение в истории, ждёт решения начальника ЦОК через
        // /aircraft-status) или его там вообще нет — ведём себя как раньше,
        // обычный ручной ввод; НОВЫЙ борт заводится в справочник сразу после
        // успешной вставки рейса, см. ниже.
        $aircraftModel = new \App\Models\Aircraft();
        $registryEntry = $insertData['aircraft_registration']
            ? $aircraftModel->findByRegistration($insertData['aircraft_registration'])
            : null;
        if ($registryEntry && $registryEntry['mtow'] !== null) {
            $insertData['aircraft_mtow'] = (float)$registryEntry['mtow'];
            if (empty($insertData['aircraft_type']) && !empty($registryEntry['aircraft_type'])) {
                $insertData['aircraft_type'] = $registryEntry['aircraft_type'];
            }
        }

        // ДОБАВЛЕНО (18.09.2026, раунд 48, по прямому указанию пользователя):
        // "Стоянка после того как на него поставили ВС она не доступна для
        // других ВС пока не будет освобождена" — см. ParkingStandService.
        // Проверяем ДО вставки строки, чтобы конфликтный рейс вообще не
        // создавался с занятой стоянкой.
        // ИЗМЕНЕНО (12.11.2026, раунд 174, по прямому указанию пользователя):
        // "может быть такие моменты когда разрешение на рейс поступают
        // срочно и с запасом... получается стоянка им занята а хотя можем
        // принят и отправить один другой борт" — на этапе планирования
        // (создание рейса) больше НЕ блокируем по старому определению
        // занятости (просто "МС№ уже записан за кем-то"), только
        // предупреждаем. Настоящая блокировка теперь — в момент физической
        // установки колодок, см. ParkingStandService::findConflict() и
        // ServiceOrderController::updateTiming().
        $standWarning = null;
        if (!empty($insertData['mc_number'])) {
            $standService = new ParkingStandService();
            $plannedConflict = $standService->findPlannedConflict($insertData, null);
            if ($plannedConflict) {
                $standWarning = $standService->describeConflict($plannedConflict);
            }
        }

        // ДОБАВЛЕНО (12.10.2026, раунд 69, по прямому указанию пользователя):
        // "нельзя создавать рейс на вылет ... из-за халатности сотрудников
        // ЦОК этот рейс не отработали и потом забыв что есть рейс создали
        // его дубль, без привязки к рейсу на прилет". Блокируем создание,
        // если тот же борт и то же направление (operation_type) в пределах
        // ±5 дней уже есть "забытый" рейс без акта и без начисленных сборов
        // — см. Flight::findIdleDuplicate() и
        // claude/analysis-2026-10-12-round69-flight-pairing-enforcement.md.
        if (!empty($insertData['aircraft_registration'])) {
            $idle = $this->flightModel->findIdleDuplicate(
                $insertData['aircraft_registration'],
                $insertData['operation_type'],
                $insertData['flight_date']
            );
            if ($idle) {
                $dirLabel = $insertData['operation_type'] === 'arrival' ? 'прилёта' : 'вылета';
                return ['success' => false, 'error' =>
                    "Рейс {$dirLabel} борта {$insertData['aircraft_registration']} уже существует в системе: "
                    . "№{$idle['flight_number']} от {$idle['flight_date']} (ID {$idle['id']}) — без акта и без начисленных услуг, "
                    . "похоже, его просто забыли отработать. Создавать дубль нельзя: откройте тот рейс "
                    . "(/flights/{$idle['id']}) и доведите его до конца, либо, если это ДЕЙСТВИТЕЛЬНО другой, "
                    . "отдельный визит того же борта — обратитесь к администратору."
                ];
            }
        }

        $id = (int)$this->flightModel->insert($insertData);

        // РАУНД 117: новый борт (ни разу не встречавшийся в справочнике) —
        // заводим его сразу с тем MTOW, который только что ввёл ЦОК, он
        // становится подтверждённым для ВСЕХ будущих рейсов этого борта.
        // Если борт уже был в справочнике (подтверждён ИЛИ "спорный") —
        // createIfMissing() ничего не делает (решение по спорным — отдельное
        // ручное действие начальника ЦОК через /aircraft-status).
        if (!$registryEntry && !empty($insertData['aircraft_registration']) && !empty($insertData['aircraft_mtow'])) {
            try {
                $aircraftModel->createIfMissing(
                    $insertData['aircraft_registration'],
                    $insertData['aircraft_type'],
                    (float)$insertData['aircraft_mtow']
                );
            } catch (\Throwable $e) {
                error_log('FlightService::createFlight aircraft registry seed failed: ' . $e->getMessage());
            }
        }

        // Автоматически инициализируем контрольные точки сроков
        if (!empty($data['scheduled_time'])) {
            $deadlineService = new DeadlineService();
            $deadlineService->initForFlight($id);
        }

        ActivityLogger::log(
            'flight', $id, 'create_flight', 'success',
            "Created flight '{$insertData['flight_number']}' on {$insertData['flight_date']}",
            null,
            $insertData
        );

        // ДОБАВЛЕНО (14.09.2026, раунд 40): автоматическая привязка к рейсу
        // противоположной операции того же борта — см. autoLinkFlight().
        // Не должно прерывать создание рейса, если подбор пары по какой-то
        // причине упадёт.
        try {
            $this->autoLinkFlight($id);
        } catch (\Throwable $e) {
            error_log('FlightService::createFlight autoLinkFlight failed for flight ' . $id . ': ' . $e->getMessage());
        }

        // ДОБАВЛЕНО (16.10.2026, раунд 91, по прямому указанию пользователя):
        // перенос MTOW/стоянки с рейса прилёта на связанный рейс вылета,
        // если у вылета эти поля ещё не заполнены — см. docblock
        // syncLinkedAircraftData() ниже. Выполняется ПОСЛЕ autoLinkFlight(),
        // чтобы связь уже была установлена, если это первая нога пары.
        try {
            $this->syncLinkedAircraftData($id);
        } catch (\Throwable $e) {
            error_log('FlightService::createFlight syncLinkedAircraftData failed for flight ' . $id . ': ' . $e->getMessage());
        }

        // ДОБАВЛЕНО (22.09.2026, раунд 51, по прямому указанию пользователя):
        // лидирование (LEAD) и колодки (CHOCKS) — услуги "по умолчанию" на
        // КАЖДОМ рейсе, без того чтобы ЦОК отдельно отмечал их в чек-листе
        // при создании рейса. Раньше у них стоял только services.is_blanket
        // (см. раунд 38) — но этот признак нигде в коде, кроме печати акта
        // ("бейдж «По умолчанию»") и формы редактирования услуги, не читается
        // — заявка (flight_service_orders) сама по себе НЕ создавалась, и
        // супервайзер не видел карточку, пока ЦОК вручную не отмечал галочку.
        // Реальный "по умолчанию" даёт только сама заявка — заводим её здесь
        // автоматически, тем же методом createBulk(), которым и обычный
        // чек-лист ЦОК добавляет отмеченные услуги (createBulk идемпотентен —
        // если заявка уже как-то есть, вторую не создаст).
        //
        // Контроль обслуживания (SUPERV) и Прием/выпуск ВС (MARSHALLING) сюда
        // сознательно НЕ включены — они уже полностью автоматические
        // (services.is_mandatory=1, см. AutoChargeService::
        // processFlightMandatoryCharges()) и начисляются в акт сами, без
        // всякой заявки супервайзеру — это и есть максимально надёжная форма
        // "по умолчанию", заводить им ещё и заявку избыточно/задваивало бы
        // работу супервайзера.
        try {
            $this->seedDefaultServiceOrders($id);
        } catch (\Throwable $e) {
            error_log('FlightService::createFlight seedDefaultServiceOrders failed for flight ' . $id . ': ' . $e->getMessage());
        }

        return ['success' => true, 'id' => $id, 'warning' => $standWarning];
    }

    /**
     * ДОБАВЛЕНО (22.09.2026, раунд 51): заводит заявку (flight_service_orders,
     * status='planned') на услуги, которые должны появляться у супервайзера
     * на КАЖДОМ рейсе без отдельного отмечания ЦОК — сейчас это LEAD
     * (Лидирование ВС) и CHOCKS (Установка/уборка колодок). Список кодов
     * сознательно зашит здесь константой, а не читается из БД по какому-то
     * флагу — см. комментарий в createFlight() про то, почему is_blanket для
     * этого не годится: путать один флаг с двумя разными смыслами (бейдж на
     * акте vs авто-заявка) было бы источником будущих ошибок.
     */
    private const DEFAULT_ORDER_CODES = ['LEAD', 'CHOCKS'];

    private function seedDefaultServiceOrders(int $flightId): void
    {
        $db = \App\Core\Database::getInstance();
        $placeholders = implode(',', array_fill(0, count(self::DEFAULT_ORDER_CODES), '?'));
        $stmt = $db->prepare(
            "SELECT id FROM services WHERE is_active = 1 AND code IN ({$placeholders})"
        );
        $stmt->execute(self::DEFAULT_ORDER_CODES);
        $serviceIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if (empty($serviceIds)) return;

        (new \App\Models\FlightServiceOrder())->createBulk($flightId, $serviceIds, null);
    }

    public function updateFlight(int $id, array $data): array
    {
        $existing = $this->flightModel->find($id);
        if (!$existing) {
            return ['success' => false, 'error' => 'Flight not found.'];
        }

        $updateData = [
            'airline_id'             => (int)$data['airline_id'],
            'flight_number'          => strtoupper(trim($data['flight_number'])),
            'flight_date'            => $data['flight_date'],
            'route_type'             => $this->resolveRouteType(
                !empty($data['origin_airport_id']) ? (int)$data['origin_airport_id'] : null,
                !empty($data['destination_airport_id']) ? (int)$data['destination_airport_id'] : null
            ),
            'operation_type'         => $data['operation_type'],
            'origin_airport_id'      => !empty($data['origin_airport_id']) ? (int)$data['origin_airport_id'] : null,
            'destination_airport_id' => !empty($data['destination_airport_id']) ? (int)$data['destination_airport_id'] : null,
            'aircraft_type'          => !empty($data['aircraft_type']) ? trim($data['aircraft_type']) : null,
            // ИЗМЕНЕНО (02.11.2026, раунд 142, по требованию бухгалтерии):
            // MTOW округляется ВВЕРХ до целого на этапе сохранения — целая
            // часть остаётся, дробная всегда уходит в большую сторону
            // (12.1 -> 13, 21.8 -> 22). В системе не должно оставаться MTOW
            // со значением после запятой — ни у рейса, ни (см. Aircraft::
            // createIfMissing()/confirm()) в справочнике бортов.
            'aircraft_mtow'          => !empty($data['aircraft_mtow']) ? (float)ceil((float)$data['aircraft_mtow']) : null,
            'aircraft_registration'  => !empty($data['aircraft_registration']) ? strtoupper(trim($data['aircraft_registration'])) : null,
            'scheduled_time'         => !empty($data['scheduled_time']) ? $data['scheduled_time'] : null,
            // УДАЛЕНО (23.09.2026, раунд 52): см. комментарий в createFlight() —
            // 'actual_time' больше не собирается формой, столбец/старые данные не трогаем.
            'arr_date'               => !empty($data['arr_date'])       ? $data['arr_date']   : null,
            'arr_time'               => !empty($data['arr_time'])       ? $data['arr_time']   : null,
            'dep_date'               => !empty($data['dep_date'])       ? $data['dep_date']   : null,
            'dep_time'               => !empty($data['dep_time'])       ? $data['dep_time']   : null,
            'pax_arrival'            => isset($data['pax_arrival'])     && $data['pax_arrival'] !== '' ? (int)$data['pax_arrival']   : null,
            'pax_departure'          => isset($data['pax_departure'])   && $data['pax_departure'] !== '' ? (int)$data['pax_departure'] : null,
            'infant_arrival'         => isset($data['infant_arrival'])  && $data['infant_arrival'] !== '' ? (int)$data['infant_arrival'] : null,
            'infant_departure'       => isset($data['infant_departure'])&& $data['infant_departure'] !== '' ? (int)$data['infant_departure'] : null,
            // ДОБАВЛЕНО (19.08.2026, раунд 21): DHC (deadhead crew — запасной
            // экипаж/экипаж в качестве пассажиров) — та же подпись уже была
            // в печатной форме акта, но ни поля ввода, ни столбца в БД не
            // существовало вообще (см. sql/round21_dhc_column.sql).
            'dhc_arrival'            => isset($data['dhc_arrival'])     && $data['dhc_arrival'] !== '' ? (int)$data['dhc_arrival']   : null,
            'dhc_departure'          => isset($data['dhc_departure'])   && $data['dhc_departure'] !== '' ? (int)$data['dhc_departure'] : null,
            'mc_number'              => !empty($data['mc_number'])      ? strtoupper(trim($data['mc_number'])) : null,
            'chocks_on'              => !empty($data['chocks_on'])      ? $data['chocks_on']  : null,
            'chocks_off'             => !empty($data['chocks_off'])     ? $data['chocks_off'] : null,
            'notes'                  => !empty($data['notes']) ? trim($data['notes']) : null,
        ];

        // ДОБАВЛЕНО (22.10.2026, раунд 117) — см. комментарий в createFlight()
        // выше. Та же логика на редактировании: иначе дрейф MTOW можно было
        // бы вернуть правкой уже существующего рейса, даже когда борт в
        // справочнике подтверждён.
        $aircraftModel = new \App\Models\Aircraft();
        $registryEntry = $updateData['aircraft_registration']
            ? $aircraftModel->findByRegistration($updateData['aircraft_registration'])
            : null;
        if ($registryEntry && $registryEntry['mtow'] !== null) {
            $updateData['aircraft_mtow'] = (float)$registryEntry['mtow'];
            if (empty($updateData['aircraft_type']) && !empty($registryEntry['aircraft_type'])) {
                $updateData['aircraft_type'] = $registryEntry['aircraft_type'];
            }
        }

        // ДОБАВЛЕНО (27.10.2026, раунд 129, по прямому указанию пользователя):
        // если справочник бортов (см. выше) скорректирован ПОСЛЕ того, как
        // по связанному рейсу этой же ротации уже утверждён акт — старое
        // значение MTOW навсегда "зашито" в утверждённый акт (трогать его
        // нельзя — см. защиту ниже), а подстановка из справочника выше
        // на КАЖДОМ сохранении этого рейса тянет уже другое число. Без этой
        // правки рейс становился НЕРЕДАКТИРУЕМЫМ НАВСЕГДА — жёсткая проверка
        // ниже блокировала сохранение ЛЮБОГО поля формы (не только MTOW),
        // т.к. при каждой попытке подстановка из справочника заново создавала
        // то же расхождение со значением, уже зафиксированным в акте (живой
        // кейс: борт T7FFI, рейсы #103/#104 — справочник скорректирован на
        // 31.8т 23.10.2026, акт рейса #103 утверждён ещё 21.10.2026 с
        // MTOW=32т — см. claude/analysis-2026-10-27-round129-...md).
        // Для уже связанной и частично заблокированной ротации значение,
        // зафиксированное в утверждённом акте, важнее более новой правки
        // справочника — откатываем подстановку назад к заблокированному
        // значению (само поле MTOW всё так же нельзя поменять вручную на
        // ДРУГОЕ число — см. проверку ниже), чтобы рейс вообще можно было
        // сохранить. Правка справочника не теряется: она просто не
        // применяется задним числом к уже начатой ротации, а будет
        // использована для любого НОВОГО визита этого борта.
        if ($registryEntry && !empty($existing['linked_flight_id'])) {
            $linkedIdForLock     = (int)$existing['linked_flight_id'];
            $linkedFlightForLock = $this->flightModel->find($linkedIdForLock);
            if ($linkedFlightForLock) {
                $lockStatusForLock = (new Act())->getLockStatus($linkedIdForLock);
                $linkedMtowForLock = $linkedFlightForLock['aircraft_mtow'] !== null
                    ? (float)$linkedFlightForLock['aircraft_mtow'] : null;
                if ($lockStatusForLock !== null && $linkedMtowForLock !== null
                    && $updateData['aircraft_mtow'] !== null
                    && abs($linkedMtowForLock - (float)$updateData['aircraft_mtow']) > 0.001
                ) {
                    ActivityLogger::log(
                        'flight', $id, 'mtow_registry_conflict_resolved', 'success',
                        "Справочник бортов ({$updateData['aircraft_registration']}) даёт MTOW="
                        . "{$updateData['aircraft_mtow']} т, но по связанному рейсу #{$linkedIdForLock} "
                        . "уже утверждён акт с MTOW={$linkedMtowForLock} т — для этой ротации оставлено "
                        . "значение акта, правка справочника не применена задним числом."
                    );
                    $updateData['aircraft_mtow'] = $linkedMtowForLock;
                }
            }
        }

        // ДОБАВЛЕНО (18.09.2026, раунд 48) — см. комментарий в createFlight()
        // выше. exclude=$id, чтобы сам этот рейс не конфликтовал сам с
        // собой (например, при повторном сохранении без изменения стоянки).
        //
        // ИСПРАВЛЕНО (21.10.2026, раунд 111, по прямому указанию
        // пользователя — "и сейчас не позволяет так как стоянка занята
        // другим бортом" при попытке закрыть старый, уже завершённый рейс):
        // раньше findConflict() вызывался при КАЖДОМ сохранении формы, если
        // в ней вообще указан mc_number — даже если это поле не менялось
        // вовсе, а правился, например, просто фактический ATA у старого,
        // давно закрытого визита. findConflict()/directOccupant() отвечают
        // на вопрос "свободна ли стоянка ПРЯМО СЕЙЧАС" — для старого рейса,
        // чья стоянка сегодня физически занята совершенно другим, не
        // связанным бортом (другая регистрация), это совершенно ожидаемо и
        // не является настоящим конфликтом: исторический рейс не претендует
        // на стоянку "сейчас", он просто записывает факт о прошлом визите.
        // Повторная проверка нужна ТОЛЬКО когда mc_number реально МЕНЯЕТСЯ
        // этим сохранением (это и есть момент новой заявки на стоянку) —
        // если значение совпадает с уже сохранённым, ничего нового не
        // занимается, и false-positive блокировка отпадает сама собой.
        // Настоящий перенос стоянки посреди визита всё так же проходит
        // полную проверку (как здесь при смене значения, так и отдельно —
        // через ParkingStandService::recordMove(), канал супервайзера).
        // ИЗМЕНЕНО (12.11.2026, раунд 174, по прямому указанию пользователя):
        // см. комментарий в createFlight() — больше не блокируем по
        // старому определению занятости при простом редактировании/смене
        // МС№, только предупреждаем. Настоящая блокировка — в момент
        // физической установки колодок.
        $standWarning = null;
        $existingMcNumber = strtoupper(trim((string)($existing['mc_number'] ?? '')));
        if (!empty($updateData['mc_number']) && $updateData['mc_number'] !== $existingMcNumber) {
            $standService = new ParkingStandService();
            $plannedConflict = $standService->findPlannedConflict($updateData, $id);
            if ($plannedConflict) {
                $standWarning = $standService->describeConflict($plannedConflict);
            }
        }

        // ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть, по прямому
        // указанию пользователя): "если один акт уже отвержден то
        // возможность смены мтов на рейсе вылета блокируется" — MTOW
        // теперь единое значение для обеих сторон пары (см. докблок
        // syncLinkedAircraftData() ниже). Если у СВЯЗАННОГО рейса уже
        // заблокированный акт (Act::LOCKED_STATUSES), а новое значение
        // MTOW отличается от того, что там уже зафиксировано — отказываем
        // в сохранении ЗДЕСЬ, до записи в БД (не трогаем вообще ничего).
        // Эта сторона своего акта может быть и не заблокирована — именно
        // для неё и нужна эта проверка (свою собственную блокировку уже
        // проверил контроллер выше, этого метода он при ней не достигнет).
        //
        // ИЗМЕНЕНО (22.10.2026, раунд 117): читаем уже из $updateData, а не
        // напрямую из $data — после возможной подстановки из справочника
        // бортов выше (см.) именно $updateData отражает то значение, которое
        // реально будет записано.
        $newMtow = $updateData['aircraft_mtow'] ?? null;
        if ($newMtow !== null && !empty($existing['linked_flight_id'])) {
            $linkedId     = (int)$existing['linked_flight_id'];
            $linkedFlight = $this->flightModel->find($linkedId);
            if ($linkedFlight) {
                $lockStatus = (new Act())->getLockStatus($linkedId);
                $linkedMtow = $linkedFlight['aircraft_mtow'] !== null ? (float)$linkedFlight['aircraft_mtow'] : null;
                if ($lockStatus !== null && $linkedMtow !== null && abs($linkedMtow - $newMtow) > 0.001) {
                    return ['success' => false, 'error' =>
                        "MTOW нельзя изменить: по связанному рейсу #{$linkedId} уже есть акт со статусом «{$lockStatus}» "
                        . "(там MTOW зафиксирован как {$linkedMtow} т) — для рейсов одной ротации (прилёт+вылет) "
                        . "MTOW должен совпадать, а менять данные уже оформленного акта нельзя."];
                }
            }
        }

        $this->flightModel->update($id, $updateData);

        // РАУНД 117: новый борт, встреченный впервые через редактирование
        // рейса (редкий путь, но для полноты — см. тот же блок в
        // createFlight()).
        if (!$registryEntry && !empty($updateData['aircraft_registration']) && !empty($updateData['aircraft_mtow'])) {
            try {
                $aircraftModel->createIfMissing(
                    $updateData['aircraft_registration'],
                    $updateData['aircraft_type'],
                    (float)$updateData['aircraft_mtow']
                );
            } catch (\Throwable $e) {
                error_log('FlightService::updateFlight aircraft registry seed failed: ' . $e->getMessage());
            }
        }

        // Пересчитываем дедлайны если изменилось scheduled_time
        $timeChanged = ($existing['scheduled_time'] ?? null) !== ($updateData['scheduled_time'] ?? null);
        if ($timeChanged && !empty($updateData['scheduled_time'])) {
            $deadlineService = new DeadlineService();
            if ($existing['documents_initialized'] ?? false) {
                $deadlineService->recalcDeadlines($id);
            } else {
                $deadlineService->initForFlight($id);
            }
        }

        ActivityLogger::log(
            'flight', $id, 'update_flight', 'success',
            "Updated flight '{$updateData['flight_number']}'",
            $existing,
            $updateData
        );

        // ДОБАВЛЕНО (14.09.2026, раунд 40): регистрация и/или тип операции
        // могли только что измениться (например ЦОК исправил опечатку в
        // бортовом номере, изначально введённом неверно) — пересматриваем
        // привязку тем же автоматическим подбором, что и при создании.
        // autoLinkFlight() сам разберётся: если рейс уже корректно связан —
        // ничего не делает; если регистрация разошлась со связанным рейсом —
        // снимает устаревшую связь и ищет новую.
        try {
            $this->autoLinkFlight($id);
        } catch (\Throwable $e) {
            error_log('FlightService::updateFlight autoLinkFlight failed for flight ' . $id . ': ' . $e->getMessage());
        }

        // ДОБАВЛЕНО (16.10.2026, раунд 91, по прямому указанию пользователя) —
        // см. docblock syncLinkedAircraftData() ниже. Это основной случай,
        // из-за которого была обнаружена ошибка: пара уже связана (импорт
        // создаёт и сразу связывает оба рейса, пока поля пустые), а ЦОК
        // заполняет MTOW/стоянку позже, редактируя уже СВЯЗАННЫЙ рейс —
        // autoLinkFlight() в этом случае ничего не делает (already_linked),
        // а старый перенос (раунд 33) происходил только в момент самой
        // связки, поэтому значения оставались только на прилёте.
        try {
            $this->syncLinkedAircraftData($id);
        } catch (\Throwable $e) {
            error_log('FlightService::updateFlight syncLinkedAircraftData failed for flight ' . $id . ': ' . $e->getMessage());
        }

        return ['success' => true, 'warning' => $standWarning];
    }

    /**
     * ДОБАВЛЕНО (27.10.2026, раунд 129, по прямому указанию пользователя):
     * "рейс создан по разрешению и по неким причинам рейс отменили совсем.
     * в таком случае нам надо механизм для отмены рейса в системе" — до
     * этого раунда рейс, который физически не состоялся (разрешение
     * аннулировано, борт не прилетел и т.п.), нельзя было никак пометить —
     * он просто оставался висеть в системе как обычный актуальный рейс,
     * занимая стоянку, продолжая генерировать напоминания по срокам
     * документооборота (DeadlineService) и маяча в рабочем дашборде
     * супервайзера. Удалять такие рейсы физически (DELETE) нельзя — это
     * потеря истории/аудита; вместо этого — мягкая отмена (flights.status).
     *
     * Разрешена ТОЛЬКО пока по рейсу ещё нет ни одного акта (черновика или
     * утверждённого) — если акт уже есть, значит обслуживание уже как-то
     * зафиксировано/возможно выставлено к оплате, и отмена рейса "одной
     * кнопкой" здесь слишком грубый инструмент; такой случай — вручную,
     * через администратора (по аналогии с отменой акта, см. раунд 112).
     * Планы на услуги (flight_service_orders, ещё не выполненные —
     * status IN ('planned','in_progress')) при отмене автоматически
     * переводятся в status='cancelled', чтобы не висеть в рабочих списках
     * служб. Уже ВЫПОЛНЕННЫЕ позиции (status='done') — признак того, что
     * обслуживание фактически началось — блокируют автоматическую отмену
     * по той же причине, что и наличие акта.
     *
     * ИЗМЕНЕНО (27.10.2026, раунд 129, уточнение по прямому указанию
     * пользователя — "если отменяется прилет то плече вылета автоматом
     * отменяется, если отменяется вылет при существующем прилете то
     * отменяется только вылет"): связанный рейс (другое плечо той же
     * ротации) обрабатывается по-разному в зависимости от того, какое
     * плечо отменяют:
     *
     *  - Отмена ПРИЛЁТА → связанный ВЫЛЕТ отменяется автоматически вместе
     *    с ним (каскадом, той же причиной с пометкой). Логика: если борт
     *    физически не прилетел, то и вылетать ему неоткуда — весь визит
     *    целиком не состоялся. Каскад блокируется целиком (прилёт тоже НЕ
     *    отменяется), если у связанного вылета уже есть акт или
     *    выполненные услуги — частично-отменённая ротация хуже, чем явный
     *    отказ с понятной причиной.
     *
     *  - Отмена ВЫЛЕТА (при существующем, НЕ отменяемом автоматически
     *    прилёте) → отменяется ТОЛЬКО сам вылет, прилёт не трогается.
     *
     * ИЗМЕНЕНО ЕЩЁ РАЗ (27.10.2026, тот же раунд 129, пользователь
     * скорректировал первоначальное решение по вылету — по прямому
     * указанию: "не стоит рвать связку, пусть остается связанной, только
     * рейс отменен на не определенное время, как будет точное дата и
     * время рейса то будут внесены корректировки на рейс и он сново
     * появиться у супервайзеров... рейс просто слетает дата и время
     * вылета и появляется кнопка возобновить рейс"): первая версия этого
     * раунда СНИМАЛА связь (unlinkFlights()) при отмене вылета — это
     * заменено. Теперь связь между прилётом и вылетом НИКОГДА не
     * трогается самой отменой (ни при каскаде, ни при одиночной отмене) —
     * вместо этого performFlightCancel() ниже очищает scheduled_time
     * (время вылета/прилёта) у КАЖДОГО отменяемого рейса, сигнализируя
     * "дата/время пока не определены". flight_date НЕ обнуляется (поле
     * NOT NULL в схеме, от него зависит много запросов по датам) —
     * остаётся последним известным значением, но вместе со status=
     * 'cancelled' это не создаёт активной нагрузки (рейс всё равно скрыт
     * из дашборда супервайзера, не держит стоянку и т.п., см. round129).
     * Возобновление — resumeFlight() ниже: supervisor/ЦОК вводит РЕАЛЬНУЮ
     * дату и время, рейс возвращается в status='active', и — поскольку
     * связь так и не снималась — сразу встаёт на место в той же паре
     * прилёт/вылет, без необходимости заново искать пару через
     * autoLinkFlight(). ParkingStandService::isFreed() при этом не
     * менялся и не нуждается в изменениях: для прилёта со связанным, но
     * отменённым (ещё не "улетевшим", chocks_off=NULL) вылетом она и так
     * корректно возвращает false — стоянка остаётся занятой, что было и
     * целью предыдущей версии (unlink), только достигается естественным
     * путём через уже существующую проверку chocks_off связанного вылета,
     * без специального кода.
     */
    public function cancelFlight(int $id, string $reason, int $userId): array
    {
        $flight = $this->flightModel->find($id);
        if (!$flight) {
            return ['success' => false, 'error' => 'Рейс не найден.'];
        }
        if (($flight['status'] ?? 'active') === 'cancelled') {
            return ['success' => false, 'error' => 'Рейс уже отменён.'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'error' => 'Укажите причину отмены рейса.'];
        }

        $blockedReason = $this->checkFlightCancellable($id);
        if ($blockedReason !== null) {
            return ['success' => false, 'error' => $blockedReason];
        }

        $linkedId               = !empty($flight['linked_flight_id']) ? (int)$flight['linked_flight_id'] : null;
        $linkedFlight           = $linkedId ? $this->flightModel->find($linkedId) : null;
        $linkedAlreadyCancelled = $linkedFlight && ($linkedFlight['status'] ?? 'active') === 'cancelled';

        $cascadeTarget = null;
        if ($flight['operation_type'] === 'arrival' && $linkedFlight && !$linkedAlreadyCancelled) {
            $linkedBlockedReason = $this->checkFlightCancellable($linkedId);
            if ($linkedBlockedReason !== null) {
                return ['success' => false, 'error' =>
                    "Нельзя отменить рейс прилёта: по связанному рейсу вылета #{$linkedId} той же ротации — "
                    . $linkedBlockedReason];
            }
            $cascadeTarget = $linkedFlight;
        }

        $this->performFlightCancel($flight, $reason, $userId);

        $cascadeCancelledId = null;

        if ($cascadeTarget !== null) {
            $this->performFlightCancel(
                $cascadeTarget,
                "Автоматически отменён вместе со связанным рейсом прилёта #{$id}: {$reason}",
                $userId
            );
            $cascadeCancelledId = (int)$cascadeTarget['id'];
        }

        return [
            'success'              => true,
            'linked_flight_id'     => $linkedId,
            'cascade_cancelled_id' => $cascadeCancelledId,
        ];
    }

    /**
     * Текст ошибки, если рейс $id НЕЛЬЗЯ отменить (уже есть акт или уже
     * есть фактически оказанные услуги), либо null, если отмена возможна.
     * Вынесено отдельно — используется и для самого отменяемого рейса, и
     * (при отмене прилёта) для проверки связанного вылета ДО того, как
     * что-либо меняется в БД — см. cancelFlight() выше.
     */
    private function checkFlightCancellable(int $id): ?string
    {
        $existingAct = (new Act())->findByFlightId($id);
        if ($existingAct) {
            return "по рейсу #{$id} уже сформирован акт № {$existingAct['act_number']} "
                . "(статус «{$existingAct['status']}») — обслуживание уже как-то зафиксировано, "
                . "обратитесь к администратору.";
        }

        $ordersStmt = $this->db->prepare("SELECT status FROM flight_service_orders WHERE flight_id = ?");
        $ordersStmt->execute([$id]);
        $hasDone = in_array('done', $ordersStmt->fetchAll(\PDO::FETCH_COLUMN), true);
        if ($hasDone) {
            return "по рейсу #{$id} уже есть фактически оказанные услуги — обратитесь к администратору.";
        }

        return null;
    }

    /**
     * Собственно отмена ОДНОГО рейса — статус, очистка времени (см.
     * докблок cancelFlight() выше), гашение открытых заявок на услуги,
     * запись в лог. Каскад связанного рейса — решение вызывающего кода
     * (cancelFlight() выше), сюда не входит намеренно. linked_flight_id
     * НЕ трогается — связь между прилётом и вылетом сохраняется всегда
     * (раунд 129, второе уточнение).
     */
    private function performFlightCancel(array $flight, string $reason, int $userId): void
    {
        $id = (int)$flight['id'];

        $this->flightModel->update($id, [
            'status'               => 'cancelled',
            'cancelled_at'         => date('Y-m-d H:i:s'),
            'cancelled_by_user_id' => $userId,
            'cancellation_reason'  => $reason,
            // ДОБАВЛЕНО (27.10.2026, раунд 129, второе уточнение): "рейс
            // просто слетает дата и время вылета" — время очищается,
            // сигнализируя "пока неизвестно когда". Дата (flight_date) не
            // трогается — поле NOT NULL, от него зависит слишком много
            // запросов/фильтров по датам; остаётся последним известным
            // значением до возобновления (resumeFlight() ниже).
            'scheduled_time'       => null,
        ]);

        $ordersStmt = $this->db->prepare("SELECT id, status FROM flight_service_orders WHERE flight_id = ?");
        $ordersStmt->execute([$id]);
        $orders = $ordersStmt->fetchAll(\PDO::FETCH_ASSOC);

        $openOrderIds = array_map(
            fn($o) => (int)$o['id'],
            array_filter($orders, fn($o) => in_array($o['status'] ?? '', ['planned', 'in_progress'], true))
        );
        if (!empty($openOrderIds)) {
            $placeholders = implode(',', array_fill(0, count($openOrderIds), '?'));
            $this->db->prepare(
                "UPDATE flight_service_orders SET status = 'cancelled' WHERE id IN ({$placeholders})"
            )->execute($openOrderIds);
        }

        ActivityLogger::log(
            'flight', $id, 'cancel_flight', 'success',
            "Рейс '{$flight['flight_number']}' отменён: {$reason}",
            ['status' => $flight['status'] ?? 'active', 'scheduled_time' => $flight['scheduled_time'] ?? null],
            ['status' => 'cancelled', 'reason' => $reason, 'scheduled_time' => null]
        );
    }

    /**
     * ДОБАВЛЕНО (27.10.2026, раунд 129, второе уточнение, по прямому
     * указанию пользователя): "появляется кнопка возобновить рейс, при
     * нажатии которого требуется ввести актуальную дату и время рейса" —
     * обратная операция к cancelFlight()/performFlightCancel(). Рейс
     * возвращается в status='active' с НОВЫМИ датой/временем — и, так как
     * linked_flight_id никогда не снимался при отмене, сразу снова
     * оказывается на своём месте в паре прилёт/вылет (если она была), без
     * необходимости заново искать и привязывать пару.
     *
     * Технически — не дублирует проверки updateFlight() (конфликт стоянки
     * round48, согласование MTOW со связанным актом round129 и т.п.):
     * сначала рейс переводится обратно в status='active' напрямую, затем
     * его собственные (уже существующие) поля прогоняются через
     * updateFlight() вместе с новыми flight_date/scheduled_time — то есть
     * возобновление получает ровно те же проверки, что обычное
     * редактирование рейса. Если updateFlight() отклонит (например,
     * конфликт стоянки на новое время) — статус откатывается обратно на
     * 'cancelled' с прежними учётными полями отмены, рейс не остаётся в
     * подвешенном состоянии.
     */
    public function resumeFlight(int $id, string $newDate, string $newTime, int $userId): array
    {
        $flight = $this->flightModel->find($id);
        if (!$flight) {
            return ['success' => false, 'error' => 'Рейс не найден.'];
        }
        if (($flight['status'] ?? 'active') !== 'cancelled') {
            return ['success' => false, 'error' => 'Рейс не отменён — возобновлять нечего.'];
        }

        $newDate = trim($newDate);
        $newTime = trim($newTime);
        if ($newDate === '' || $newTime === '') {
            return ['success' => false, 'error' => 'Укажите дату и время рейса.'];
        }
        if (!\DateTime::createFromFormat('Y-m-d', $newDate)) {
            return ['success' => false, 'error' => 'Некорректная дата рейса.'];
        }
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newTime)) {
            return ['success' => false, 'error' => 'Некорректное время рейса (формат ЧЧ:ММ).'];
        }

        $this->flightModel->update($id, [
            'status'               => 'active',
            'cancelled_at'         => null,
            'cancelled_by_user_id' => null,
            'cancellation_reason'  => null,
        ]);

        $formData                   = $this->flightModel->find($id);
        $formData['flight_date']    = $newDate;
        $formData['scheduled_time'] = $newTime;

        $result = $this->updateFlight($id, $formData);
        if (!$result['success']) {
            // Откатываем статус обратно — рейс остаётся отменённым с теми
            // же учётными полями, а не повисает "активным без причины
            // отмены, но и без корректной даты".
            $this->flightModel->update($id, [
                'status'               => 'cancelled',
                'cancelled_at'         => $flight['cancelled_at'],
                'cancelled_by_user_id' => $flight['cancelled_by_user_id'],
                'cancellation_reason'  => $flight['cancellation_reason'],
            ]);
            return $result;
        }

        ActivityLogger::log(
            'flight', $id, 'resume_flight', 'success',
            "Рейс '{$flight['flight_number']}' возобновлён: {$newDate} {$newTime}",
            ['status' => 'cancelled', 'flight_date' => $flight['flight_date'], 'scheduled_time' => $flight['scheduled_time']],
            ['status' => 'active', 'flight_date' => $newDate, 'scheduled_time' => $newTime]
        );

        return ['success' => true];
    }

    /**
     * РАУНД 33 (09.09.2026): привязка рейса прилёта и рейса вылета одного и
     * того же борта (одна физическая стоянка/визит ВС в аэропорту) —
     * по прямому указанию пользователя. Связь симметричная: обе строки
     * `flights` получают ссылку друг на друга через linked_flight_id.
     *
     * Правила (согласованы по смыслу с исходным вопросом пользователя —
     * башмаки ставятся на прилёте и снимаются на вылете ОДНОГО борта):
     *  - привязать можно только рейс прилёта с рейсом вылета (разные
     *    operation_type) одного и того же борта (aircraft_registration);
     *  - если у любой из сторон уже есть привязка — старая связь сначала
     *    снимается (unlinkFlights), задваивать связи нельзя;
     *  - если MC№ (стоянка) не указан у вылета, но указан у прилёта —
     *    автоматически подставляем его на вылет (та же стоянка одного
     *    визита); НЕ перезаписываем, если ЦОК уже сам ввёл своё значение.
     */
    public function linkFlights(int $flightAId, int $flightBId): array
    {
        if ($flightAId === $flightBId) {
            return ['success' => false, 'error' => 'Нельзя связать рейс сам с собой.'];
        }

        $a = $this->flightModel->find($flightAId);
        $b = $this->flightModel->find($flightBId);
        if (!$a || !$b) {
            return ['success' => false, 'error' => 'Один из рейсов не найден.'];
        }

        if ($a['operation_type'] === $b['operation_type']) {
            return ['success' => false, 'error' => 'Можно связать только рейс прилёта с рейсом вылета (не два прилёта и не два вылета).'];
        }

        $regA = strtoupper(trim((string)($a['aircraft_registration'] ?? '')));
        $regB = strtoupper(trim((string)($b['aircraft_registration'] ?? '')));
        if ($regA === '' || $regB === '') {
            return ['success' => false, 'error' => 'У обоих рейсов должен быть указан бортовой номер (регистрация) ВС.'];
        }
        if ($regA !== $regB) {
            return ['success' => false, 'error' => "Бортовые номера не совпадают ({$regA} и {$regB}) — это разные ВС, связывать нельзя."];
        }

        // Снимаем прежние привязки, если были (не задваиваем связи).
        if (!empty($a['linked_flight_id'])) {
            $this->unlinkFlights((int)$a['id']);
        }
        if (!empty($b['linked_flight_id'])) {
            $this->unlinkFlights((int)$b['id']);
        }

        $this->flightModel->update($flightAId, ['linked_flight_id' => $flightBId]);
        $this->flightModel->update($flightBId, ['linked_flight_id' => $flightAId]);

        // ИЗМЕНЕНО (16.10.2026, раунд 91): перенос был точечным (только
        // mc_number, только здесь, в момент самой связки) — заменён вызовом
        // общего метода syncLinkedAircraftData(), который переносит также
        // aircraft_mtow и срабатывает на каждое сохранение любой из двух
        // сторон пары (createFlight()/updateFlight()), не только здесь.
        $this->syncLinkedAircraftData($flightAId);

        ActivityLogger::log(
            'flight', $flightAId, 'link_flight', 'success',
            "Связан рейс #{$flightAId} с рейсом #{$flightBId} (борт {$regA}, одна стоянка/визит)",
            null,
            ['linked_flight_id' => $flightBId]
        );

        return ['success' => true];
    }

    public function unlinkFlights(int $flightId): array
    {
        $flight = $this->flightModel->find($flightId);
        if (!$flight) {
            return ['success' => false, 'error' => 'Рейс не найден.'];
        }
        $otherId = $flight['linked_flight_id'] ?? null;
        if (empty($otherId)) {
            return ['success' => true];
        }

        $this->flightModel->update($flightId, ['linked_flight_id' => null]);
        $this->flightModel->update((int)$otherId, ['linked_flight_id' => null]);

        ActivityLogger::log(
            'flight', $flightId, 'unlink_flight', 'success',
            "Снята привязка рейса #{$flightId} и рейса #{$otherId}",
            ['linked_flight_id' => $otherId],
            ['linked_flight_id' => null]
        );

        return ['success' => true];
    }

    /**
     * РАУНД 40 (14.09.2026): автоматический подбор и привязка рейса
     * прилёта/вылета одного и того же борта — по прямому указанию
     * пользователя: "Система должна автоматом связывать один и тот же
     * самолет, рейс на прилет и рейс на вылет. Самолет не может между
     * прилетом и вылетом сменить марку, регистрационный номер или что-то
     * другое касательно борта". Убрана ручная кнопка "Связать"
     * (resources/views/flights/show.php, FlightController::link() —
     * ручной выбор из выпадающего списка кандидатов) — раньше ЦОК должен
     * был сам найти и подтвердить пару, теперь это делает система при
     * каждом создании/редактировании рейса (см. вызовы в createFlight() и
     * updateFlight() выше). Подбор кандидатов —
     * Flight::findLinkCandidates() (раунд 33): тот же борт
     * (aircraft_registration), противоположный operation_type, ещё не
     * привязан ни к чему.
     *
     * ИЗМЕНЕНО (12.10.2026, раунд 69, по прямому указанию пользователя):
     * findLinkCandidates() больше НЕ ограничена окном дат (было ±5 дней).
     * Причина — местные борты могут стоять на стоянке десятки дней между
     * прилётом и вылетом (пример пользователя: T7AAA7), и заявка на вылет
     * местного борта может прийти через пару месяцев после заявки на
     * прилёт — окно в 5 дней искусственно исключало именно такие пары,
     * хотя они самые однозначные по сути (один и тот же борт — других
     * вариантов физически нет, см. её докблок).
     *
     * Правило на случай неоднозначности (не изменилось и не зависит от
     * окна дат — единственная защита от неверной привязки):
     *  - кандидат РОВНО ОДИН — связываем автоматически, без подтверждения
     *    (у одного и того же борта не может быть двух вариантов "той же
     *    стоянки" — на этом и построен весь механизм);
     *  - кандидатов НЕСКОЛЬКО (тот же борт заходил на TVT несколько раз, и
     *    обе ноги каждого захода ещё не привязаны ни к чему) — НЕ гадаем,
     *    какая пара правильная, оставляем как есть; на странице рейса это
     *    видно информационным сообщением со списком кандидатов — можно
     *    разобраться точечно через ручную связку (FlightController::link(),
     *    восстановлена в раунде 69);
     *  - кандидатов НЕТ — ничего не делаем, это нормальная ситуация (вторая
     *    нога ещё не создана в системе, либо это разовый борт без обратного
     *    рейса).
     *
     * Дополнительно: если рейс УЖЕ связан, но регистрация с этого момента
     * отличается от регистрации связанного рейса (ЦОК исправил опечатку в
     * бортовом номере уже ПОСЛЕ того как рейсы были когда-то связаны —
     * до раунда 40 это была ошибка ручного связывания, вручную не
     * отслеживалась) — старая, более не отражающая реальность связь
     * снимается, и подбор запускается заново с учётом новой регистрации.
     *
     * Возвращает диагностический массив (не бросает исключений на "не
     * нашли пару" — это ожидаемое, а не ошибочное состояние); вызывающий
     * код (createFlight/updateFlight) оборачивает вызов в try/catch на
     * случай непредвиденного сбоя БД, чтобы это никогда не прерывало
     * сохранение самого рейса.
     *
     * ИЗМЕНЕНО (21.10.2026, раунд 109, по прямому указанию пользователя,
     * по итогам разбора случая P4VVIP — раунды 106/108/108c): подбор
     * кандидата переведён на Flight::findChronologicalLinkCandidates() —
     * учитывает ХРОНОЛОГИЧЕСКИЙ порядок (прилёт открывает визит, вылет
     * закрывает последний ещё не закрытый визит того же борта), а не
     * просто "любой непривязанный рейс противоположного типа", как было
     * раньше (см. её докблок и ИЗВЕСТНЫЙ ПРОБЕЛ в докблоке
     * findLinkCandidates() — та старая версия больше НЕ используется
     * здесь, но оставлена как есть для ручного экрана связки на странице
     * рейса, где её более широкий список кандидатов — осознанная
     * особенность, а не недостаток). Правило неоднозначности теперь не
     * "кандидат ровно один", а "ближайший по времени кандидат уникален"
     * — см. тело метода ниже.
     */
    public function autoLinkFlight(int $flightId): array
    {
        $flight = $this->flightModel->find($flightId);
        if (!$flight) {
            return ['linked' => false, 'reason' => 'not_found'];
        }

        $registration = strtoupper(trim((string)($flight['aircraft_registration'] ?? '')));

        if (!empty($flight['linked_flight_id'])) {
            $linked    = $this->flightModel->find((int)$flight['linked_flight_id']);
            $linkedReg = $linked ? strtoupper(trim((string)($linked['aircraft_registration'] ?? ''))) : '';
            if ($linked && $registration !== '' && $linkedReg === $registration) {
                // Связь уже есть и всё ещё корректна — трогать нечего.
                return ['linked' => true, 'reason' => 'already_linked', 'linked_flight_id' => (int)$flight['linked_flight_id']];
            }
            // Регистрация разошлась (или связанный рейс исчез) — связь
            // больше не отражает реальность одного борта, снимаем и ищем
            // заново ниже.
            $this->unlinkFlights($flightId);
        }

        if ($registration === '') {
            return ['linked' => false, 'reason' => 'no_registration'];
        }

        // ДОБАВЛЕНО (06.11.2026, раунд 154, по прямому указанию пользователя):
        // для борта, базирующегося в нашем аэропорту (App\Models\Aircraft::
        // is_based), цепочка визита обратная — см. докблок
        // Flight::findChronologicalLinkCandidates().
        $isBased = false;
        if ($registration !== '') {
            $aircraftRow = (new \App\Models\Aircraft())->findByRegistration($registration);
            $isBased     = !empty($aircraftRow['is_based'] ?? false);
        }

        $candidates = $this->flightModel->findChronologicalLinkCandidates(
            $registration,
            (string)$flight['operation_type'],
            (string)$flight['flight_date'],
            $flight['scheduled_time'] ?? null,
            $flightId,
            $isBased
        );

        if (empty($candidates)) {
            return ['linked' => false, 'reason' => 'no_candidate'];
        }

        // РАУНД 109: кандидаты уже отфильтрованы по хронологическому
        // направлению (см. findChronologicalLinkCandidates()) и
        // отсортированы по близости по времени — $candidates[0] самый
        // близкий. Неоднозначность теперь не "кандидатов больше одного"
        // (это нормально для часто летающего борта — у него всегда будет
        // несколько валидных по направлению кандидатов), а "ближайший не
        // определён однозначно" — то есть второй кандидат на ТОЙ ЖЕ
        // дистанции, что и первый (редкий случай одинаковых отметок
        // времени).
        $best        = $candidates[0];
        $bestIsUnique = count($candidates) === 1
            || (int)$candidates[1]['minute_diff'] !== (int)$best['minute_diff'];

        if (!$bestIsUnique) {
            return ['linked' => false, 'reason' => 'ambiguous', 'candidates' => $candidates];
        }

        $result = $this->linkFlights($flightId, (int)$best['id']);
        if ($result['success']) {
            return ['linked' => true, 'reason' => 'auto', 'linked_flight_id' => (int)$best['id']];
        }
        return ['linked' => false, 'reason' => 'link_failed', 'error' => $result['error'] ?? null];
    }

    /**
     * ДОБАВЛЕНО (16.10.2026, раунд 91, по прямому указанию пользователя):
     * "сотрудник ЦОК создал рейс и ввел мтов на рейс прилета и стоянку
     * тоже, а на рейс вылета оно не село ... может реализуешь привязку
     * данных мтов и стоянки в привязанных друг другу рейсах?" — до этого
     * раунда перенос MC№ (стоянки) с прилёта на вылет происходил ТОЛЬКО
     * ОДНОКРАТНО, в момент самой связки (linkFlights(), раунд 33/40), а
     * aircraft_mtow вообще никогда не переносился. На практике связка
     * почти всегда устанавливается сразу при импорте разрешения — когда
     * оба поля у обоих рейсов ещё пусты, — а ЦОК заполняет их позже,
     * редактируя уже СВЯЗАННЫЙ рейс прилёта; в этот момент autoLinkFlight()
     * видит already_linked и ничего не трогает, повторной передачи данных
     * не было. Поэтому значения оставались только на прилёте и никогда не
     * доходили до вылета — это и обнаружил пользователь.
     *
     * Правило (согласовано по смыслу с пользователем — рейс ПРИЛЁТА
     * заполняется первым, рейс вылета "подхватывает" данные с него):
     * ЗАПОЛНЯЕМ пустое поле вылета значением с прилёта. НЕ перезаписываем,
     * если на вылете уже стоит СВОЁ отличающееся значение — он может
     * отражать реальную, уже проверенную человеком информацию (увидено на
     * живых данных уже в момент внедрения: пара A6RTS/F900 — MTOW 33.90т
     * на прилёте против 21.20т на вылете, настоящий MTOW Falcon 900 ближе
     * к 21-22т, то есть справедливо как раз вылетное значение, а не
     * прилётное — автоматическая перезапись в таких случаях была бы сама
     * источником ошибки). Расхождение в этом случае не трогаем, только
     * логируем (action=linked_data_conflict) — решает человек.
     *
     * ИЗМЕНЕНО (20.10.2026, раунд 104, по прямому указанию пользователя):
     * "есть рейсы в которых мтов указан на вылет а на прилет нет, это как?"
     * — обнаружены реальные пары (#20/21, #71/72, #93/94), где MTOW был
     * заполнен ТОЛЬКО на вылете (ЦОК отредактировал/подтвердил именно
     * рейс вылета — там это обязательное поле при ручном сохранении, см.
     * round 53 — а прилёт так и остался нетронутым после импорта). Старое
     * правило "прилёт — всегда источник" такой случай не покрывало вообще:
     * перенос был односторонним (прилёт → вылет), поэтому пустой прилёт
     * никогда не дозаполнялся с вылета. ТОЛЬКО для aircraft_mtow перенос
     * сделан двусторонним: заполняем ЛЮБОЕ пустое поле значением с ДРУГОЙ,
     * непустой стороны пары — не важно, кто из двух заполнен первым. Как
     * и раньше, непустое поле, которое уже отличается от второй стороны,
     * НИКОГДА не перезаписывается автоматически — только логируется как
     * конфликт (та же защита, что уберегла пару A6RTS). Стоянка (mc_number) —
     * см. отдельное ДОБАВЛЕНО ниже: тем же вечером пользователь попросил
     * "сделай как лучше", и двустороннее правило распространено и на неё.
     *
     * ДОБАВЛЕНО (20.10.2026, раунд 104, вторая часть, по прямому указанию
     * пользователя): то же двустороннее правило — для mc_number. Раньше
     * перенос стоянки был односторонним (прилёт → вылет) ещё с раунда
     * 33/40. Конфликт занятости (ParkingStandService::findConflict()) при
     * заполнении ПУСТОГО поля невозможен в обе стороны по той же причине,
     * что и раньше (см. ниже) — проверка идёт только на связку одинаковой
     * регистрации борта.
     *
     * Конфликта занятости стоянки (ParkingStandService::findConflict())
     * при заполнении пустого поля быть не может: прилёт и вылет связываются
     * только при совпадающей aircraft_registration (см. linkFlights() выше),
     * а findConflict() не считает конфликтом стоянку, занятую тем же
     * бортом.
     *
     * Вызывается из createFlight()/updateFlight() (после autoLinkFlight())
     * и из linkFlights() (в момент установления новой связи) — то есть на
     * любое сохранение любой из двух сторон уже связанной пары, не только
     * в момент самой связки.
     *
     * ИЗМЕНЕНО (20.10.2026, раунд 104, третья часть, по прямому указанию
     * пользователя — разбор пары A6RTS подтвердил его): "Думаю самый
     * правильный вариант единый мтов для связанных рейсов. при смене мтов
     * на вылет оно автоматом меняется на прилете, и обратно... но тут
     * напрашивается момент, если один акт уже отвержден то возможность
     * смены мтов на рейсе вылета блокируется." MTOW ротации теперь ВСЕГДА
     * единое значение: сторону, которую только что сохранили, считаем
     * источником — она переносится на другую сторону, ДАЖЕ если там уже
     * стояло другое значение (раньше расхождение только логировалось, см.
     * старый текст выше — "A6RTS" там и был тем самым примером, который
     * привёл к пересмотру решения на практике). "Конфликт, решает
     * человек" для MTOW как состояние больше не существует — есть либо
     * синхронизированное значение, либо отказ в изменении (см. ниже).
     *
     * Защита уже подписанного документа — в ДВА слоя:
     *  1) updateFlight() ДО вызова этого метода отклоняет само сохранение
     *     (сторона вообще не меняется в БД), если новое MTOW отличается от
     *     значения на связанном рейсе, а у СВЯЗАННОГО рейса акт уже
     *     заблокирован (Act::LOCKED_STATUSES) — именно это пользователь
     *     и описал словами "возможность смены мтов... блокируется".
     *  2) Здесь, внутри sync (пути создания рейса/авто-связки, которые
     *     updateFlight() не проходят) — та же защита на всякий случай:
     *     никогда не пишем в сторону с заблокированным актом, только
     *     логируем конфликт, если перенос в неё был бы нужен.
     */
    private function syncLinkedAircraftData(int $flightId): void
    {
        $flight = $this->flightModel->find($flightId);
        if (!$flight || empty($flight['linked_flight_id'])) {
            return;
        }

        $linked = $this->flightModel->find((int)$flight['linked_flight_id']);
        if (!$linked) {
            return;
        }

        $arrival   = $flight['operation_type'] === 'arrival' ? $flight : $linked;
        $departure = $flight['operation_type'] === 'arrival' ? $linked : $flight;
        if ($arrival['operation_type'] !== 'arrival' || $departure['operation_type'] !== 'departure') {
            // Защита от неожиданной конфигурации пары — linkFlights() такого
            // не допускает, но перестраховываемся, не трогаем данные.
            return;
        }

        $patch     = [];
        $conflicts = [];

        // ИЗМЕНЕНО (20.10.2026, раунд 104, третья часть): MTOW ротации
        // теперь ВСЕГДА единое значение — см. докблок выше. Сторону,
        // которую только что сохранили ($flight), считаем источником;
        // если источник пуст (типичный случай при импорте разрешения —
        // только что создали пустой рейс), источником считаем ту сторону,
        // где значение вообще есть. Перенос пропускается (и логируется
        // как конфликт), только если ЦЕЛЕВАЯ сторона уже имеет
        // заблокированный акт — её данные трогать нельзя.
        $arrPatch = [];
        $arrMtow = $arrival['aircraft_mtow']   ?? null;
        $depMtow = $departure['aircraft_mtow'] ?? null;
        $arrHasMtow = $arrMtow !== null && (float)$arrMtow > 0;
        $depHasMtow = $depMtow !== null && (float)$depMtow > 0;
        $mtowMismatch = ($arrHasMtow !== $depHasMtow)
            || ($arrHasMtow && $depHasMtow && abs((float)$depMtow - (float)$arrMtow) > 0.001);

        if ($mtowMismatch) {
            $savedIsArrival = ((int)$flight['id'] === (int)$arrival['id']);
            if ($savedIsArrival && $arrHasMtow) {
                $sourceVal = (float)$arrMtow; $targetIsArrival = false;
            } elseif (!$savedIsArrival && $depHasMtow) {
                $sourceVal = (float)$depMtow; $targetIsArrival = true;
            } elseif ($arrHasMtow) {
                $sourceVal = (float)$arrMtow; $targetIsArrival = false;
            } else {
                $sourceVal = (float)$depMtow; $targetIsArrival = true;
            }

            $targetLocked = $targetIsArrival
                ? ((new Act())->getLockStatus((int)$arrival['id']) !== null)
                : ((new Act())->getLockStatus((int)$departure['id']) !== null);

            if ($targetLocked) {
                $conflicts[] = "MTOW не совпадает между связанными рейсами: прилёт #{$arrival['id']} = "
                    . ($arrMtow ?? 'пусто') . " т, вылет #{$departure['id']} = " . ($depMtow ?? 'пусто')
                    . " т — автоматически не переношу, у " . ($targetIsArrival ? 'прилёта' : 'вылета')
                    . " уже заблокированный акт.";
            } elseif ($targetIsArrival) {
                $arrPatch['aircraft_mtow'] = $sourceVal;
            } else {
                $patch['aircraft_mtow'] = $sourceVal;
            }
        }

        // ИЗМЕНЕНО (20.10.2026, раунд 104, вторая часть): было одностороннее
        // правило (прилёт → вылет); теперь, как и для MTOW выше, заполняем
        // ЛЮБОЕ пустое поле стоянки с другой, непустой стороны пары.
        //
        // ИЗМЕНЕНО (20.10.2026, раунд 104, третья часть, по прямому указанию
        // пользователя — перенос стоянки посреди визита, см.
        // App\Services\ParkingStandService::recordMove()): $arrStand больше
        // НЕ читается напрямую из flights.mc_number рейса прилёта — после
        // переноса это поле намеренно остаётся старым (историческая запись
        // "куда прилетел", печатается в акте прилёта), а ФАКТИЧЕСКАЯ текущая
        // стоянка визита — в flight_stand_moves. getCurrentStandForArrival()
        // возвращает именно её (или raw mc_number, если переносов не было —
        // для всех рейсов без переносов поведение не меняется ни на бит).
        //
        // ИЗМЕНЕНО (23.10.2026, раунд 120, по прямому указанию пользователя):
        // "если меняется стоянка до закрытия акта — она должна меняться
        // синхронно [на обеих ногах]. Если акт уже закрыт — в акте остаётся
        // стоянка, а смена стоянки идёт в историю для расчёта стоимости" —
        // поймано на живом случае (UAG97Y #134/#135): ЦОК поменял МС№ на
        // ещё НЕ прилетевшем рейсе прилёта (15→19) через обычную форму
        // редактирования — до этого раунда при расхождении (обе стороны
        // заполнены, но по-разному) код просто логировал конфликт и НИЧЕГО
        // не менял, вылет остался на старой стоянке, ЦОК пришлось править
        // вручную. Теперь — как и для MTOW выше: сторону, которую только
        // что сохранили ($flight), считаем источником правды, и переносим
        // ЕЁ стоянку на другую сторону, если она отличается — ЕДИНСТВЕННОЕ
        // исключение, как и для MTOW, — у целевой стороны уже заблокированный
        // акт (тогда просто логируем конфликт, как было). Перенос стоянки
        // супервайзером ПОСЛЕ прилёта идёт отдельным каналом (recordMove()
        // выше) и сам зеркалит в вылет с той же защитой по заблокированному
        // акту — тут ничего не меняется. Проверено на всех 14 уже
        // существующих в проде парах с расхождением МС№ (все старые,
        // 01–15.10.2026) — у ВСЕХ обе стороны уже с заблокированным
        // (approved) актом, так что для них поведение не меняется ни на
        // бит, просто продолжат логироваться как конфликт.
        $arrStand = (new \App\Services\ParkingStandService())->getCurrentStandForArrival($arrival) ?? '';
        $depStand = strtoupper(trim((string)($departure['mc_number'] ?? '')));
        $arrHasStand = $arrStand !== '';
        $depHasStand = $depStand !== '';
        $standMismatch = ($arrHasStand !== $depHasStand) || ($arrHasStand && $depHasStand && $depStand !== $arrStand);

        if ($standMismatch) {
            $savedIsArrival = ((int)$flight['id'] === (int)$arrival['id']);
            if ($savedIsArrival && $arrHasStand) {
                $standSourceVal = $arrStand; $standTargetIsArrival = false;
            } elseif (!$savedIsArrival && $depHasStand) {
                $standSourceVal = $depStand; $standTargetIsArrival = true;
            } elseif ($arrHasStand) {
                $standSourceVal = $arrStand; $standTargetIsArrival = false;
            } else {
                $standSourceVal = $depStand; $standTargetIsArrival = true;
            }

            $standTargetLocked = $standTargetIsArrival
                ? ((new Act())->getLockStatus((int)$arrival['id']) !== null)
                : ((new Act())->getLockStatus((int)$departure['id']) !== null);

            if ($standTargetLocked) {
                $conflicts[] = "Стоянка не совпадает между связанными рейсами: прилёт #{$arrival['id']} (текущая стоянка) = {$arrStand}, вылет #{$departure['id']} = {$depStand} — автоматически не перезаписываю, у " . ($standTargetIsArrival ? 'прилёта' : 'вылета') . " уже заблокированный акт.";
            } elseif ($standTargetIsArrival) {
                $arrPatch['mc_number'] = $standSourceVal;
            } else {
                $patch['mc_number'] = $standSourceVal;
            }
        }

        if (!empty($patch)) {
            $this->flightModel->update((int)$departure['id'], $patch);
            ActivityLogger::log(
                'flight', (int)$departure['id'], 'auto_sync_linked_data', 'success',
                'Авто-подстановка MTOW/стоянки с рейса прилёта #' . $arrival['id'] . ' (поле на вылете было не заполнено)',
                null,
                $patch
            );
        }

        // ДОБАВЛЕНО (20.10.2026, раунд 104): обратное направление —
        // дозаполнение MTOW на рейсе ПРИЛЁТА с рейса вылета, если на
        // прилёте поле было пустым (см. докблок выше).
        if (!empty($arrPatch)) {
            $this->flightModel->update((int)$arrival['id'], $arrPatch);
            ActivityLogger::log(
                'flight', (int)$arrival['id'], 'auto_sync_linked_data', 'success',
                'Авто-подстановка MTOW с рейса вылета #' . $departure['id'] . ' (поле на прилёте было не заполнено)',
                null,
                $arrPatch
            );
        }

        foreach ($conflicts as $msg) {
            ActivityLogger::log(
                'flight', (int)$departure['id'], 'linked_data_conflict', 'failure',
                $msg,
                null,
                null
            );
            error_log('FlightService::syncLinkedAircraftData conflict: ' . $msg);
        }
    }
}
