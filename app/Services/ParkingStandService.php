<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Flight;
use App\Models\FlightServiceOrder;
use App\Models\ParkingStand;
use App\Models\ParkingStandMove;
use App\Models\Act;

/**
 * ДОБАВЛЕНО (18.09.2026, раунд 48) — по прямому указанию пользователя:
 * "Это недочет в программе, надо устранить это дело. Стоянка после того
 * как на него поставили ВС она не доступна для других ВС пока не будет
 * освобождена."
 *
 * До этого раунда `flights.mc_number` (номер стоянки/МС№) записывался в
 * трёх местах (форма рейса ЦОК — FlightService::createFlight()/
 * updateFlight(), планшет супервайзера — ServiceOrderController::
 * saveStandNumber(), синхронизация из акта готовности стоянки —
 * ParkingActController::save()) вообще без проверки — ту же стоянку можно
 * было спокойно назначить второму борту, пока первый ещё стоит на ней
 * (см. анализ раунда 47, claude/analysis-2026-09-18-round47-...).
 *
 * Этот сервис — единая точка проверки занятости стоянки, используется во
 * всех трёх местах записи mc_number.
 *
 * Определение "занята/свободна" (согласовано по смыслу с раундом 44 —
 * башмаки/колодки уже используются там как индикатор начала/конца
 * фактической стоянки):
 *  - стоянка считается занятой рейсом с момента, когда ему назначен
 *    mc_number, и остаётся занятой, пока не найдено время УБОРКИ КОЛОДОК
 *    на рейсе ВЫЛЕТА той же ротации (сначала — подтверждённая супервайзером
 *    услуга CHOCKS, иначе — ручное поле flights.chocks_off);
 *  - если у рейса-прилёта ещё нет связанного рейса-вылета
 *    (linked_flight_id) — считаем стоянку всё ещё занятой (нет данных об
 *    освобождении);
 *  - рейсы ОДНОГО И ТОГО ЖЕ борта (совпадает aircraft_registration) никогда
 *    не считаются конфликтующими друг с другом — это ожидаемое повторное
 *    использование своей же стоянки в рамках одного визита (прилёт+вылет),
 *    даже если formal-привязка (linked_flight_id) ещё не успела
 *    выставиться автоматической связкой (FlightService::autoLinkFlight() —
 *    выполняется ПОСЛЕ вставки новой строки рейса, см. round 40). Если у
 *    проверяемого рейса регистрация вообще не указана — считать это
 *    неизвестным бортом и не делать поблажки (иначе можно было бы обойти
 *    проверку, просто не указав борт).
 *
 * ДОБАВЛЕНО (20.10.2026, раунд 104, по прямому указанию пользователя):
 * "есть стоянки левые и правые... а так же есть стоянки B-22, оно
 * вмещает в себя B-22R и B-22L, получается если прилетел большой борт и
 * занял B-22 то автоматом их пара B-22R и B-22L становится занятой" —
 * широкие стоянки (справочник parking_stands.is_wide=1: B-20, B-22,
 * B-23, B-24) физически перекрывают оба узких места своей пары
 * (parking_stands.pair_group, напр. B-22L и B-22R у группы 'B-22').
 * Выбор узкой стоянки саму по себе занятость другой узкой стоянки из
 * той же пары НЕ блокирует (подтверждено пользователем: "Выбор отдельно
 * B-22L или B-22R друг на друга не влияет"), а вот обратное направление
 * (узкая занята -> широкая для этой же пары недоступна) добавлено по
 * условию физической непротиворечивости — иначе два борта оказались бы
 * на одном и том же месте перрона. См. getBlockingSiblingCodes() ниже.
 *
 * ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть, по прямому указанию
 * пользователя): "стоянка привязывается в начале на акт прилета, на
 * акте вылета может быть другая стоянка, так как во время стоянки борт
 * могут перенести на другую стоянку... получается мы должны вести учет
 * стоянки с учетом того что может быть смена стоянки. естественно при
 * смене стоянки. освобожденная стоянка должна стать доступной для
 * других ВС." — перенос стоянки посреди визита, записывается ИСКЛЮЧИТЕЛЬНО
 * супервайзером (единственный канал — см. ServiceOrderController::
 * moveStand()), история — таблица flight_stand_moves (App\Models\
 * ParkingStandMove), ключ — id рейса ПРИЛЁТА (якорь визита). См.
 * recordMove() и directOccupant() ниже. Биллинг стоянки НЕ меняется —
 * считается от прилёта до вылета независимо от переносов (так сказал
 * пользователь), см. AutoChargeService — эта таблица только для
 * занятости/освобождения в реальном времени.
 */
class ParkingStandService
{
    private Flight $flightModel;
    private FlightServiceOrder $orderModel;
    private ParkingStand $standModel;
    private ParkingStandMove $moveModel;

    public function __construct()
    {
        $this->flightModel = new Flight();
        $this->orderModel  = new FlightServiceOrder();
        $this->standModel  = new ParkingStand();
        $this->moveModel   = new ParkingStandMove();
    }

    /**
     * ДОБАВЛЕНО (18.09.2026, раунд 48, доработка по прямому указанию
     * пользователя): "Такое решение не подходит, это лишние операции.
     * Реализуй так, чтобы занятые стоянки просто пропали из списка для
     * выбора стоянки." — вместо того, чтобы пользователь выбирал занятую
     * стоянку и получал отказ при сохранении (первая версия этого раунда),
     * теперь занятые стоянки вообще не предлагаются в выпадающем списке
     * (`<select name="mc_number">` / `<select name="stand_number">` на
     * формах ЦОК/супервайзера/Аэродромной службы). Использует тот же
     * findConflict(), что и серверная проверка при сохранении — они не
     * могут разойтись, потому что это один и тот же метод. Серверная
     * проверка при сохранении (createFlight/updateFlight/saveStandNumber/
     * ParkingActController::save) НЕ убрана — остаётся подстраховкой на
     * случай гонки (кто-то ещё успел занять стоянку, пока форма была
     * открыта) или прямого POST в обход формы.
     *
     * $allStands — полный справочник (ParkingStand::getAllActive()).
     * $flightData — данные текущего рейса (нужен 'aircraft_registration',
     * чтобы своя же стоянка текущей ротации не пропадала из списка) —
     * пусто, если рейс ещё не существует (форма создания, регистрация
     * борта ещё не введена — тогда исключаются ВСЕ занятые стоянки, так
     * как неизвестно, чужие они или свои; штатный сценарий переиспользования
     * своей же стоянки при создании рейса вылета — оставить поле пустым,
     * FlightService::autoLinkFlight()/linkFlights() сам подставит стоянку
     * с рейса прилёта после связывания).
     */
    public function getAvailableStands(array $allStands, array $flightData = [], ?int $excludeFlightId = null): array
    {
        return array_values(array_filter($allStands, function (array $stand) use ($flightData, $excludeFlightId) {
            $conflict = $this->findConflict(
                ['mc_number' => $stand['code'], 'aircraft_registration' => $flightData['aircraft_registration'] ?? null],
                $excludeFlightId
            );
            return $conflict === null;
        }));
    }

    /**
     * ДОБАВЛЕНО (18.09.2026, раунд 48): кто СЕЙЧАС занимает данную стоянку
     * (без поблажки "тот же борт" — в отличие от findConflict(), здесь не
     * с чем сравнивать, просто "кто на ней стоит"). null — стоянка
     * свободна. Используется на /parking-acts для сводки по всем стоянкам.
     */
    public function getOccupyingFlight(string $standNumber): ?array
    {
        $standNumber = strtoupper(trim($standNumber));
        if ($standNumber === '') {
            return null;
        }

        // ИСПРАВЛЕНО (20.10.2026, раунд 104, третья часть): раньше этот
        // метод не вызывал directOccupant() и повторял его СТАРУЮ (до
        // переносов) логику напрямую через flightModel->findByStandNumber()
        // — из-за этого после переноса стоянки старое место продолжало
        // считаться занятым именно тут (findConflict() уже был чинен
        // правильно, а getOccupyingFlight() — тот самый метод, которым
        // пользуются getStatusBoard()/supervisorFlight() для отображения
        // — оставался со старой ошибкой). Обнаружено живым тестом переноса
        // (round104, часть 3). Теперь оба метода используют один и тот же
        // move-aware directOccupant().
        $direct = $this->directOccupant($standNumber, '', null);
        if ($direct !== null) {
            return $direct;
        }

        // Если сама стоянка свободна, но её парная широкая/узкая стоянка
        // занята — для целей отображения (сводка /parking-acts,
        // /parking-stands) и выбора в форме это тоже "занято", см. докблок
        // класса.
        foreach ($this->getBlockingSiblingCodes($standNumber) as $siblingCode) {
            $viaSibling = $this->directOccupant($siblingCode, '', null);
            if ($viaSibling !== null) {
                $viaSibling['_occupies_via_stand'] = $siblingCode;
                return $viaSibling;
            }
        }

        return null;
    }

    /**
     * ДОБАВЛЕНО (18.09.2026, раунд 48, по прямому указанию пользователя):
     * "https://formb.tvtairport.uz/parking-acts должен отражать актуальную
     * информацию о состоянии стоянок." — сводка по всем стоянкам
     * справочника: свободна/занята, и если занята — каким рейсом/бортом и
     * с какого момента (время установки колодок на рейсе прилёта той же
     * ротации, если оно известно).
     */
    public function getStatusBoard(): array
    {
        $stands = $this->standModel->getAllActive();
        $board  = [];
        foreach ($stands as $stand) {
            $occupant = $this->getOccupyingFlight((string)$stand['code']);
            $since    = null;
            if ($occupant) {
                $arrivalId = $this->resolveArrivalFlightId($occupant);
                if ($arrivalId !== null) {
                    $since = $this->resolveChocksOnDateTime($arrivalId);
                }
            }
            $board[] = [
                'stand'          => $stand,
                'occupied'       => $occupant !== null,
                'flight'         => $occupant,
                'occupied_since' => $since,
                // ДОБАВЛЕНО (20.10.2026, раунд 104): код парной стоянки,
                // через которую реально стоит борт, если сама $stand занята
                // не напрямую, а через широкую/узкую пару (см.
                // getOccupyingFlight()).
                'occupied_via'   => $occupant['_occupies_via_stand'] ?? null,
            ];
        }
        return $board;
    }

    /**
     * Проверяет, свободна ли стоянка $standNumber для рейса с данными
     * $flightData (нужны ключи 'mc_number' и 'aircraft_registration' —
     * то есть можно передать как ещё не сохранённые данные формы, так и
     * уже загруженную строку flights). $excludeFlightId — id самого
     * проверяемого рейса, чтобы не конфликтовать сам с собой при
     * редактировании (при создании — null, рейса ещё не существует).
     *
     * Возвращает null, если конфликта нет (стоянку можно назначать), либо
     * строку рейса, который сейчас занимает эту стоянку (с добавленным
     * airline_name) — вызывающий код формирует сообщение об ошибке.
     */
    public function findConflict(array $flightData, ?int $excludeFlightId = null): ?array
    {
        $standNumber = strtoupper(trim((string)($flightData['mc_number'] ?? '')));
        if ($standNumber === '') {
            return null;
        }

        $registration = strtoupper(trim((string)($flightData['aircraft_registration'] ?? '')));

        $direct = $this->directOccupant($standNumber, $registration, $excludeFlightId);
        if ($direct !== null) {
            return $direct;
        }

        // ДОБАВЛЕНО (20.10.2026, раунд 104): парные широкие/узкие стоянки —
        // см. докблок класса и getBlockingSiblingCodes(). Помечаем результат
        // '_conflict_via_stand', чтобы describeConflict() ниже могла дать
        // человеку понятное объяснение, а не просто "стоянка X занята",
        // когда реально занята её пара.
        foreach ($this->getBlockingSiblingCodes($standNumber) as $siblingCode) {
            $viaSibling = $this->directOccupant($siblingCode, $registration, $excludeFlightId);
            if ($viaSibling !== null) {
                $viaSibling['_conflict_via_stand'] = $siblingCode;
                return $viaSibling;
            }
        }

        return null;
    }

    /**
     * ДОБАВЛЕНО (20.10.2026, раунд 104): прямая (без учёта парных
     * широких/узких стоянок) проверка занятости ОДНОЙ конкретной стоянки —
     * общая часть, вынесенная из findConflict()/getOccupyingFlight(), чтобы
     * обе проверяли занятость идентично и для себя, и для парной стоянки.
     */
    private function directOccupant(string $standNumber, string $registration, ?int $excludeFlightId): ?array
    {
        // ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть): визиты, у
        // которых была хотя бы одна запись переноса (flight_stand_moves) —
        // для них единственный источник истины теперь эта история, а не
        // статичные flights.mc_number (см. докблок класса и recordMove()).
        // $excludeFlightId может быть как прилётом, так и вылетом той же
        // ротации — приводим его к якорю-прилёту, чтобы правильно
        // исключить "самого себя" из проверки конфликта.
        $excludeArrivalId = null;
        if ($excludeFlightId !== null) {
            $excludeFlight    = $this->flightModel->find($excludeFlightId);
            $excludeArrivalId = $excludeFlight
                ? ($this->resolveArrivalFlightId($excludeFlight) ?? (int)$excludeFlight['id'])
                : $excludeFlightId;
        }

        foreach ($this->moveModel->findActiveArrivalIdsByStand($standNumber, $excludeArrivalId) as $arrivalId) {
            $arrival = $this->flightModel->find($arrivalId);
            if (!$arrival) {
                continue;
            }
            $holderReg = strtoupper(trim((string)($arrival['aircraft_registration'] ?? '')));
            if ($registration !== '' && $holderReg === $registration) {
                continue;
            }
            if ($this->isFreed($arrival)) {
                continue;
            }
            // Показываем ФАКТИЧЕСКУЮ текущую стоянку (= проверяемую
            // $standNumber), а не то, что исторически осталось в
            // flights.mc_number у рейса прилёта — для визитов с переносами
            // это поле больше не источник истины.
            $arrival['mc_number'] = $standNumber;
            if (empty($arrival['airline_name'])) {
                $arrival['airline_name'] = $this->airlineNameFor($arrival);
            }
            return $arrival;
        }

        // Рейсы БЕЗ истории переносов (т.е. все рейсы до этого раунда и
        // все новые, где супервайзер переносом не воспользовался) — как и
        // раньше, по статичному flights.mc_number. Визиты с историей
        // переносов сюда не допускаем — выше для них уже дан окончательный
        // ответ (нашли или корректно не нашли), а их возможно устаревшее
        // flights.mc_number не должно повторно "находиться" тут.
        $holders = $this->flightModel->findByStandNumber($standNumber, $excludeFlightId);

        foreach ($holders as $holder) {
            $holderArrivalId = $this->resolveArrivalFlightId($holder) ?? (int)$holder['id'];
            if ($this->moveModel->hasAnyMoveForArrival($holderArrivalId)) {
                continue;
            }
            $holderReg = strtoupper(trim((string)($holder['aircraft_registration'] ?? '')));
            if ($registration !== '' && $holderReg === $registration) {
                // Тот же борт — не конфликт (повторное использование своей
                // же стоянки в рамках одного визита).
                continue;
            }
            if ($this->isFreed($holder)) {
                continue;
            }
            return $holder;
        }

        return null;
    }

    /**
     * ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть): название
     * авиакомпании для строки, найденной через таблицу переносов (которая
     * хранит только flights.* без JOIN на airlines, в отличие от
     * Flight::findByStandNumber()) — для текста ошибки/сводки.
     */
    private function airlineNameFor(array $flight): string
    {
        if (empty($flight['airline_id'])) {
            return '';
        }
        $stmt = \App\Core\Database::getInstance()->prepare("SELECT name FROM airlines WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$flight['airline_id']]);
        $name = $stmt->fetchColumn();
        return $name !== false ? (string)$name : '';
    }

    /**
     * ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть): фактическая ТЕКУЩАЯ
     * стоянка визита, которому принадлежит рейс прилёта $arrivalFlight —
     * последняя запись в flight_stand_moves, если переносы были, иначе
     * просто flights.mc_number (для визитов без переносов — поведение не
     * меняется). Публичный метод — используется и здесь, и в
     * App\Services\FlightService::syncLinkedAircraftData() при переносе
     * значения на рейс вылета (см. там).
     */
    public function getCurrentStandForArrival(array $flight): ?string
    {
        // ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть): принимает и
        // рейс прилёта, и рейс вылета той же ротации (планшету супервайзера
        // всё равно, какую из двух страниц он сейчас смотрит) — приводим к
        // якорю-прилёту сами, а не полагаемся на то, что вызывающий код
        // уже это сделал. Если $flight сам оказался вылетом — для fallback
        // (нет переносов) берём mc_number именно С ПРИЛЁТА, а не с вылета:
        // это и есть "изначальная" стоянка визита до первого переноса.
        $arrivalId = $this->resolveArrivalFlightId($flight);
        $arrivalRow = $flight;
        if ($arrivalId !== null && $arrivalId !== (int)($flight['id'] ?? 0)) {
            $arrivalRow = $this->flightModel->find($arrivalId) ?? $flight;
        }
        $resolvedId = $arrivalId ?? (int)($flight['id'] ?? 0);
        if ($resolvedId > 0) {
            $latest = $this->moveModel->getLatestForArrival($resolvedId);
            if ($latest) {
                return strtoupper(trim((string)$latest['stand_code']));
            }
        }
        $raw = strtoupper(trim((string)($arrivalRow['mc_number'] ?? '')));
        return $raw !== '' ? $raw : null;
    }

    /**
     * ДОБАВЛЕНО (20.10.2026, раунд 104, третья часть, по прямому указанию
     * пользователя): перенос борта на другую стоянку посреди визита,
     * вызывается ТОЛЬКО из ServiceOrderController::moveStand() (планшет
     * супервайзера — единственный канал, по словам пользователя: "сейчас
     * по идеи без ведома супервайзеров на перроне ничего не происходить,
     * все возможные действия с ВС только через супервайзеров").
     *
     * $viewedFlight — рейс, со страницы которого супервайзер инициировал
     * перенос (может быть и прилётом, и вылетом одной ротации — приводим
     * к якорю-прилёту внутри). Возвращает ['success'=>bool, 'error'=>?string,
     * 'old_stand'=>?string, 'new_stand'=>string] — контроллер сам решает,
     * как показать результат (flash-сообщение + запись в историю рейса).
     */
    public function recordMove(array $viewedFlight, string $newStandCode, ?string $note, ?int $byUserId): array
    {
        $newStandCode = strtoupper(trim($newStandCode));
        if ($newStandCode === '') {
            return ['success' => false, 'error' => 'Не указана новая стоянка.'];
        }

        $arrivalId = $this->resolveArrivalFlightId($viewedFlight);
        $arrival   = $arrivalId !== null ? $this->flightModel->find($arrivalId) : null;
        if (!$arrival) {
            return ['success' => false, 'error' =>
                'Не удалось определить рейс прилёта этой ротации — перенос стоянки доступен только для рейсов, связанных в пару прилёт+вылет.'];
        }

        $currentStand = $this->getCurrentStandForArrival($arrival);
        if ($currentStand === null) {
            return ['success' => false, 'error' =>
                'Стоянка пока не назначена — сначала укажите её через «МС№» выше, затем переносом можно будет менять.'];
        }
        if ($currentStand === $newStandCode) {
            return ['success' => false, 'error' => "Борт уже на стоянке {$newStandCode}."];
        }

        $registration = strtoupper(trim((string)($arrival['aircraft_registration'] ?? '')));
        $conflict = $this->findConflict(
            ['mc_number' => $newStandCode, 'aircraft_registration' => $registration],
            (int)$arrival['id']
        );
        if ($conflict) {
            return ['success' => false, 'error' => $this->describeConflict($conflict)];
        }

        // Если у визита ЕЩЁ не было ни одной записи переноса — сначала
        // фиксируем задним числом "первичное назначение" (старое значение,
        // которое до сих пор лежало просто в flights.mc_number), чтобы
        // история не начиналась "из ниоткуда", а directOccupant() сразу и
        // полностью перешёл на эту таблицу как источник истины для визита.
        if (!$this->moveModel->hasAnyMoveForArrival((int)$arrival['id'])) {
            $this->moveModel->create([
                'arrival_flight_id' => (int)$arrival['id'],
                'stand_code'        => $currentStand,
                'moved_at'          => $arrival['chocks_on'] && $arrival['flight_date']
                    ? ($arrival['flight_date'] . ' ' . $arrival['chocks_on'])
                    : date('Y-m-d H:i:s'),
                'moved_by_user_id'  => null,
                'note'              => 'Первичное назначение (перенесено автоматически из flights.mc_number при первом переносе).',
            ]);
        }

        $this->moveModel->create([
            'arrival_flight_id' => (int)$arrival['id'],
            'stand_code'        => $newStandCode,
            'moved_at'          => date('Y-m-d H:i:s'),
            'moved_by_user_id'  => $byUserId,
            'note'              => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);

        // Зеркалим новую стоянку в flights.mc_number связанного рейса
        // ВЫЛЕТА (если он уже существует в системе) — именно это поле
        // печатается в акте вылета ("на акте вылета может быть другая
        // стоянка", по словам пользователя). Рейс ПРИЛЁТА не трогаем —
        // его mc_number остаётся историческим "куда прилетел". Не пишем,
        // если у вылета акт уже заблокирован (та же защита, что и у MTOW,
        // см. FlightService::updateFlight()) — перенос всё равно
        // зафиксирован выше в истории, просто не отражается в уже
        // оформленном документе.
        $departureNote = null;
        if (!empty($arrival['linked_flight_id'])) {
            $departure = $this->flightModel->find((int)$arrival['linked_flight_id']);
            if ($departure && ($departure['operation_type'] ?? '') === 'departure') {
                if ((new Act())->getLockStatus((int)$departure['id']) !== null) {
                    $departureNote = "у рейса вылета #{$departure['id']} уже заблокированный акт — "
                        . "его МС№ не обновлён автоматически, перенос зафиксирован только в истории.";
                } else {
                    $this->flightModel->update((int)$departure['id'], ['mc_number' => $newStandCode]);
                }
            }
        }

        return [
            'success'   => true,
            'error'     => null,
            'old_stand' => $currentStand,
            'new_stand' => $newStandCode,
            'note'      => $departureNote,
        ];
    }

    /**
     * ДОБАВЛЕНО (20.10.2026, раунд 104): коды стоянок, занятость которых
     * физически блокирует $standCode (и наоборот) — см. докблок класса.
     * Узкая стоянка (is_wide=0) блокируется своей широкой парой
     * (is_wide=1, тот же pair_group); широкая стоянка блокируется любой из
     * своих узких половин. Узкие половины друг на друга не влияют — они
     * не входят в результат друг для друга (is_wide фильтр однонаправленный).
     */
    private function getBlockingSiblingCodes(string $standCode): array
    {
        $stand = $this->standModel->findByCode($standCode);
        if (!$stand || empty($stand['pair_group'])) {
            return [];
        }

        $wantWide = empty($stand['is_wide']) ? 1 : 0;

        $siblings = array_filter(
            $this->standModel->getAllActive(),
            fn (array $s) => $s['pair_group'] === $stand['pair_group']
                && strtoupper((string)$s['code']) !== strtoupper($standCode)
                && (int)$s['is_wide'] === $wantWide
        );

        return array_values(array_map(fn (array $s) => strtoupper((string)$s['code']), $siblings));
    }

    /**
     * Готовое сообщение об ошибке на русском для отказа в сохранении —
     * используется во всех трёх местах записи mc_number, чтобы текст не
     * расходился.
     */
    public function describeConflict(array $conflict): string
    {
        $stand   = (string)($conflict['mc_number'] ?? '');
        $reg     = trim((string)($conflict['aircraft_registration'] ?? '')) ?: 'неизвестный борт';
        $flightN = (string)($conflict['flight_number'] ?? '');
        $date    = (string)($conflict['flight_date'] ?? '');
        $airline = !empty($conflict['airline_name']) ? ", {$conflict['airline_name']}" : '';
        $via     = (string)($conflict['_conflict_via_stand'] ?? '');

        // ДОБАВЛЕНО (20.10.2026, раунд 104): если это конфликт через
        // парную широкую/узкую стоянку (см. findConflict()), объясняем это
        // прямо, а не просто "стоянка X занята" — $stand в этом случае
        // физически свободна, занята её пара.
        if ($via !== '') {
            return "Эта стоянка недоступна — связанное с ней место {$via} уже занято бортом {$reg} "
                . "(рейс {$flightN} от {$date}{$airline}), а широкая и узкие стоянки пары — одно "
                . "физическое место — освободится автоматически после уборки колодок на вылете этого борта.";
        }

        return "Стоянка {$stand} уже занята бортом {$reg} (рейс {$flightN} от {$date}{$airline}) "
            . "— освободится автоматически после уборки колодок на вылете этого борта.";
    }

    /**
     * Свободна ли стоянка, которую занимает $flight (строка flights).
     * true — если найдено время уборки колодок на рейсе ВЫЛЕТА той же
     * ротации; false — если рейс вылета неизвестен или колодки ещё не
     * убраны (стоянка по-прежнему считается занятой).
     */
    private function isFreed(array $flight): bool
    {
        // ДОБАВЛЕНО (27.10.2026, раунд 129, по прямому указанию пользователя):
        // отменённый рейс (flights.status='cancelled' — см. FlightService::
        // cancelFlight()) стоянку не держит, даже если по нему ещё нет
        // отметки о фактическом убытии (chocks_off) — он физически не
        // прилетал/не состоялся, так что проверять его "занятость" в
        // принципе бессмысленно, а раньше такой рейс мог навсегда
        // блокировать стоянку для всех последующих бортов.
        if (($flight['status'] ?? 'active') === 'cancelled') {
            return true;
        }

        $departureFlightId = $this->resolveDepartureFlightId($flight);
        if ($departureFlightId === null) {
            return false;
        }

        return $this->resolveChocksOffDateTime($departureFlightId) !== null;
    }

    /**
     * Зеркало (независимая копия — см. комментарий в шапке класса о том,
     * почему не переиспользуется AutoChargeService напрямую)
     * AutoChargeService::resolveDepartureFlightId(): id рейса ВЫЛЕТА той же
     * ротации борта — сам $flight, если это уже вылет, либо связанный
     * рейс через flights.linked_flight_id.
     */
    private function resolveDepartureFlightId(array $flight): ?int
    {
        $operationType = (string)($flight['operation_type'] ?? '');
        if ($operationType === 'departure') {
            return (int)$flight['id'];
        }
        if ($operationType === 'arrival') {
            $linkedId = (int)($flight['linked_flight_id'] ?? 0);
            if ($linkedId <= 0) {
                return null;
            }
            $linked = $this->flightModel->find($linkedId);
            if ($linked && ($linked['operation_type'] ?? '') === 'departure') {
                return (int)$linked['id'];
            }
            return null;
        }
        return null;
    }

    /**
     * Зеркало AutoChargeService::resolveChocksOffDateTime(): момент уборки
     * колодок на рейсе вылета — сначала подтверждённая супервайзером услуга
     * CHOCKS (completed_at), иначе ручное поле flights.chocks_off +
     * flight_date того же рейса вылета.
     */
    private function resolveChocksOffDateTime(int $departureFlightId): ?string
    {
        $order = $this->orderModel->findByFlightAndServiceCode($departureFlightId, 'CHOCKS');
        if ($order && !empty($order['completed_at'])) {
            return (string)$order['completed_at'];
        }

        $flight = $this->flightModel->find($departureFlightId);
        if ($flight && !empty($flight['chocks_off']) && !empty($flight['flight_date'])) {
            return $flight['flight_date'] . ' ' . $flight['chocks_off'];
        }

        return null;
    }

    /**
     * ДОБАВЛЕНО (18.09.2026, раунд 48): зеркало resolveDepartureFlightId()
     * выше — id рейса ПРИЛЁТА той же ротации борта (используется только для
     * сводки getStatusBoard(), чтобы показать "занята с ...").
     */
    private function resolveArrivalFlightId(array $flight): ?int
    {
        $operationType = (string)($flight['operation_type'] ?? '');
        if ($operationType === 'arrival') {
            return (int)$flight['id'];
        }
        if ($operationType === 'departure') {
            $linkedId = (int)($flight['linked_flight_id'] ?? 0);
            if ($linkedId <= 0) {
                return null;
            }
            $linked = $this->flightModel->find($linkedId);
            if ($linked && ($linked['operation_type'] ?? '') === 'arrival') {
                return (int)$linked['id'];
            }
            return null;
        }
        return null;
    }

    /**
     * ДОБАВЛЕНО (18.09.2026, раунд 48): зеркало resolveChocksOffDateTime()
     * выше — момент УСТАНОВКИ колодок на рейсе прилёта (используется только
     * для сводки getStatusBoard()).
     */
    private function resolveChocksOnDateTime(int $arrivalFlightId): ?string
    {
        $order = $this->orderModel->findByFlightAndServiceCode($arrivalFlightId, 'CHOCKS');
        if ($order && !empty($order['started_at'])) {
            return (string)$order['started_at'];
        }

        $flight = $this->flightModel->find($arrivalFlightId);
        if ($flight && !empty($flight['chocks_on']) && !empty($flight['flight_date'])) {
            return $flight['flight_date'] . ' ' . $flight['chocks_on'];
        }

        return null;
    }
}
