<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Models\FlightServiceCharge;
use App\Models\AirlineParkingNorm;
use App\Models\TariffVersion;
use App\Models\PassengerManifest;
use App\Models\FlightServiceOrder;
use App\Models\Flight;

/**
 * AutoChargeService — автоматическое формирование формульных сборов по рейсу.
 * Вызывается при подаче акта стоянки (Аэродромная служба) — именно здесь
 * известно фактическое время стоянки ВС.
 *
 * Формульные услуги (коды из справочника services):
 *  TLF       — взлёт-посадка = MTOW (тонн, округлено вверх до целого) × тариф
 *  AV_SEC    — авиабезопасность = % от TLF, каждый рейс
 *  NIGHT     — ночной сбор = % от TLF, если ФАКТИЧЕСКОЕ время рейса (ATA/ATD)
 *              в ночном интервале (сезонное окно — см. isNight()). До раунда
 *              163 (10.11.2026) использовалось плановое время (STD/STA) —
 *              переключено на факт по прямому указанию пользователя, см.
 *              докблок processParkingAct() ниже.
 *  OVER_PARK — сверхнормативная стоянка = % от TLF за сутки сверх нормы
 *              (с учётом 15-минутного буфера — см. getParkingTimingSummary())
 *  MET_50/100/101 — метеообеспечение по диапазону MTOW, за один вылет
 *  PAX_SEC   — сбор безопасности пассажиров = $20 × кол-во пасс. вылета
 *              (раунд 22, берётся из уже введённого PAX_DEP, БЕЗ младенцев —
 *              см. billablePaxQuantity()), только вылет
 *
 * ДОБАВЛЕНО (14.08.2026, раунд 2, п.4): TLF/AV_SEC/MET_* теперь считаются
 * общим методом processFlightMandatoryCharges() при КАЖДОЙ сборке акта
 * ЦОК — не только при подаче акта стоянки (см. её докблок ниже).
 *
 * ДОБАВЛЕНО (25.09.2026, раунд 57, по итогам сверки с Постановлением КМ РУз
 * №218 от 30.04.2026 «Правила формирования и взимания сбора, тарифов за
 * обслуживание ВС...», см. claude/analysis-2026-09-24-round56-
 * postanovlenie-218-compliance.md и подтверждённые пользователем решения):
 * округление MTOW при расчёте TLF (п.15 ч.4 Правил), исключение младенцев
 * из тарифицируемого числа пассажиров (п.18/23/24), сезонные границы
 * ночного сбора (п.16) и 15-минутный буфер в OVER_PARK (п.21 ч.3).
 */
class AutoChargeService
{
    // ИЗМЕНЕНО (25.09.2026, раунд 57, п.16 Правил КМ №218): раньше — фиксированный
    // круглогодичный интервал 23:00–06:00. Правила задают ДВА сезонных окна —
    // см. isNight() и seasonalNightWindow() ниже, где эти константы больше не
    // используются напрямую (оставлены только как исторический ориентир для
    // диапазона, действовавшего в системе до раунда 57).
    private const NIGHT_FROM = '23:00:00';
    private const NIGHT_TO   = '06:00:00';

    // ДОБАВЛЕНО (25.09.2026, раунд 57, п.16 Правил КМ №218): сезонные окна
    // ночного сбора. Зимний период (1 окт — 31 мар): 17:00–08:00. Летний
    // период (1 апр — 30 сен): 20:00–06:00. Месяц определяется по дате рейса
    // (flights.flight_date), а не по текущей дате сервера.
    private const NIGHT_WINDOW_WINTER = ['from' => '17:00:00', 'to' => '08:00:00'];
    private const NIGHT_WINDOW_SUMMER = ['from' => '20:00:00', 'to' => '06:00:00'];
    private const WINTER_MONTHS = [10, 11, 12, 1, 2, 3]; // октябрь–март

    // ДОБАВЛЕНО (25.09.2026, раунд 57, п.21 ч.3 Правил КМ №218): сверхнормативное
    // время стоянки уменьшается на норму авиакомпании И ДОПОЛНИТЕЛЬНО на 15 минут
    // (по прямому указанию пользователя после сверки с Постановлением — буфер
    // добавляется поверх нормы, а не заменяет уже более точный расчёт по
    // времени колодок).
    private const OVER_PARK_BUFFER_HOURS = 0.25;

    // Услуги, для которых quantity — это ставка (0.10/0.20 и т.п.), а не
    // физическое количество: сумма считается как % от TLF при сборке акта.
    private const PERCENT_CODES = ['NIGHT', 'AV_SEC', 'OVER_PARK'];

    /**
     * ИЗМЕНЕНО (14.08.2026, раунд 6): раньше здесь был жёстко прописанный в
     * коде список кодов услуг ("какая услуга физически означает приезд
     * техники с персоналом"). Пользователь справедливо указал, что это
     * бизнес-правило, которое финансовая/коммерческая служба должна уметь
     * менять сама, без каждого раза просить программиста и выкатывать патч —
     * и что оно уже плохо совпадало с реальным владельцем услуги
     * (owner_role): автобусы BUS_PAX/BUS_CREW ошибочно считались тех.
     * персоналом ССТ, хотя они принадлежат СОП.
     *
     * Теперь признак "услуга требует учёта тех.персонала при авторасчёте
     * TECH_STAFF" — это отдельная колонка `services.requires_tech_personnel`
     * (tinyint, по аналогии с уже существующими requires_manual_quantity /
     * requires_dispatch_notice) — редактируется на странице услуги
     * (Справочники → Услуги → Редактировать), миграция и переключатели по
     * умолчанию — см. README, раздел "Раунд 6".
     *
     * Уточнённая с пользователем логика этого флага (14.08.2026, раунд 6):
     *  - Это НЕ то же самое, что "owner_role = sst". Флаг относится к любой
     *    услуге, физическое оказание которой требует выезда персонала/техники
     *    к борту, независимо от того, какая служба ведёт её в справочнике.
     *  - Транспорт ССТ (WATER, TOILET/МА-7, GPU, AMBU, WCHR, AIR_START,
     *    AIRCOND, AIRSTAIRS, OXYGEN, NITROGEN, DEICE_20/100/200/201,
     *    CATERING_TRUCK) — включён.
     *  - Обработка багажа (BAG_ARR_PC/BAG_DEP_PC) формально ведётся службой
     *    СОП (owner_role='sop' — они оформляют документы), НО физическую
     *    погрузку/выгрузку багажа на борт выполняют грузчики (тех.персонал),
     *    поэтому по прямому уточнению пользователя эти две услуги ТОЖЕ
     *    включены, несмотря на owner_role='sop'.
     *    ИЗМЕНЕНО (20.08.2026, раунд 22): флаг снят с BAG_DEP_PC (см.
     *    sql/round22_pax_sec_and_bag_tech.sql) — сверка с ручным расчётом
     *    главного бухгалтера по акту 0002/XX-QQE735 показала, что она эту
     *    услугу как отдельный повод для доначисления TECH_STAFF не считает
     *    (двойное выставление того же самого труда грузчиков — один раз
     *    внутри тарифа самой услуги обработки багажа, второй раз отдельной
     *    строкой техперсонала). BAG_ARR_PC (прилёт) флаг НЕ трогали — по
     *    этому плечу отдельного подтверждения от пользователя ещё не было,
     *    вопрос открыт (см. README, раздел "Раунд 22").
     *  - Автобус для пассажиров/экипажа (BUS_PAX/BUS_CREW) — это услуга СОП
     *    (водитель считается частью услуги, а не отдельным техперсоналом
     *    ССТ) — исключены.
     *  - Рамп-хэндлинг (RAMP_10…251, owner_role='spo') и пожарная машина
     *    (FIRE, owner_role='spasop') — исключены: не транспорт ССТ и не
     *    аналог "грузчиков" как с багажом.
     *  - Также исключены: AMBULANCE (owner_role='spasop') — по аналогии с
     *    FIRE/RAMP_*, чисто "бумажные" услуги без отдельной единицы техники
     *    (центровка, флайт-план, DCS/CUTE, охрана ВС), и сами по себе
     *    жидкости противообледенения DEICE_T1/T2/T4 (это литры, не выезд
     *    техники — сама процедура очистки DEICE_20/100/200/201 включена
     *    отдельно). Коды AUTOLIFT/CRANE/STEPLADDER в справочнике услуг не
     *    существуют — убраны как мёртвый код.
     *  - ИЗМЕНЕНО (14.10.2026, раунд 71, по прямому указанию пользователя):
     *    BRIDGE/PUSHBACK (owner_role='spo') раньше тоже был в списке
     *    исключений — считался "бумажной" услугой без своей единицы техники.
     *    Пользователь уточнил, что буксировка ВС физически требует выезда
     *    техперсонала спецтехники, точно так же как TOILET/МА-7 — решение
     *    пересмотрено: PUSHBACK теперь ВКЛЮЧЁН (requires_tech_personnel=1 в
     *    БД, см. user_activity_log.action='round71_pushback_tech_staff_enabled').
     *    BRIDGE (телетрап) сам по себе отдельного флага не менял, остаётся
     *    вне списка — вопрос был именно про буксировку.
     *  - ИЗМЕНЕНО (09.11.2026, раунд 158, по прямому указанию пользователя):
     *    вопрос поднят снова, уже про сам BRIDGE (пассажирский трап).
     *    Пользователь уточнил: предоставление трапа для пассажиров — тоже
     *    выезд спецтехники ССТ, равноценно буксировке/МА-7/заправке водой —
     *    решение пересмотрено: BRIDGE теперь ВКЛЮЧЁН (requires_tech_personnel=1
     *    в БД, см. user_activity_log.action='round158_bridge_tech_staff_enabled').
     *    Как и в раунде 71 — НЕ ретроактивно: см.
     *    claude/analysis-2026-11-09-round158-bridge-tech-staff.md, список
     *    уже утверждённых актов, где BRIDGE использовался до этой правки.
     *  - ИЗМЕНЕНО (09.11.2026, раунд 159, по прямому указанию пользователя):
     *    обратный случай — СТРЕМЯНКА (STEPLADDER). Несмотря на комментарий
     *    выше ("в справочнике услуг не существуют" — устаревший, код
     *    реально существует с 04.09.2026 и requires_tech_personnel был
     *    молча включён ещё в раунде 5 без отдельного подтверждения
     *    пользователем), пользователь прямо уточнил: предоставление
     *    стремянки — это просто лестница, не отдельная единица спецтехники
     *    с экипажем (услуга и так тарифицируется по 0 во всех тарифах) —
     *    решение пересмотрено: STEPLADDER теперь ИСКЛЮЧЁН
     *    (requires_tech_personnel=0 в БД, см.
     *    user_activity_log.action='round159_stepladder_tech_staff_disabled').
     *    В ОТЛИЧИЕ от раунда 71/158 — здесь СДЕЛАНА ретроактивная правка:
     *    см. claude/analysis-2026-11-09-round159-stepladder-tech-staff.md,
     *    список скорректированных актов, где стремянка завышала TECH_STAFF.
     *
     * См. AutoChargeService::syncTechStaffCount() и
     * AutoChargeService::techPersonnelServiceCodes().
     */
    private ?array $techPersonnelCodesCache = null;

    // ДОБАВЛЕНО (11.09.2026, раунд 38): услуги, количество которых больше не
    // вводит супервайзер вручную на планшете — подтягивается из пассажирского
    // манифеста (см. processFlightMandatoryCharges() ниже и докблок метода
    // manifestQuantityForCode()). По прямому указанию пользователя: "супервайзер
    // не должен считать количество пассажиров и багажа, он просто фиксирует
    // операцию... количество подтягивается с манифеста".
    // ИЗМЕНЕНО (27.10.2026, раунд 130, по прямому указанию пользователя:
    // "пусть СОП указывает обычных пассажиров и пассажиров кортежа отдельно,
    // и пусть пока программа пассажиров кортежа считает что есть такая
    // услуга но пока цена 0"): PAX_CORTEGE_ARR/PAX_CORTEGE_DEP добавлены в
    // этот же список — они подчиняются ТОЧНО тому же механизму, что и
    // PAX_ARR/PAX_DEP (количество из манифеста, начисление только после
    // подтверждения супервайзером/СОП), только читают отдельное поле
    // манифеста (passenger_manifests.pax_cortege) — см.
    // manifestQuantityForCode() ниже. Поскольку обе ветки
    // (processFlightMandatoryCharges()/findMandatoryChargeGaps()) уже
    // обрабатывают MANIFEST_DRIVEN_CODES целиком через generic-цикл по
    // is_mandatory=1 услугам, никаких дополнительных изменений в этих
    // методах не потребовалось — именно это и было целью запроса ("чтобы
    // в дальнейшем не пришлось менять алгоритм при внесении тарифа
    // пассажир на кортеже"): реальный тариф появится через обычную
    // tariff_versions-запись (по аналогии с 4 строками PAX_ARR/PAX_DEP,
    // раунд 25/57), код трогать не придётся.
    private const MANIFEST_DRIVEN_CODES = [
        'PAX_ARR', 'PAX_DEP', 'BAG_ARR_PC', 'BAG_DEP_PC',
        'PAX_CORTEGE_ARR', 'PAX_CORTEGE_DEP',
    ];

    private \PDO                $db;
    private FlightServiceCharge $chargeModel;
    private AirlineParkingNorm  $normModel;
    private TariffVersion       $tariffModel;
    private PassengerManifest   $manifestModel;
    private FlightServiceOrder  $orderModel;
    private Flight               $flightModel;

    public function __construct()
    {
        $this->db            = Database::getInstance();
        $this->chargeModel   = new FlightServiceCharge();
        $this->normModel     = new AirlineParkingNorm();
        $this->tariffModel   = new TariffVersion();
        $this->manifestModel = new PassengerManifest();
        $this->orderModel    = new FlightServiceOrder();
        $this->flightModel   = new Flight();
    }

    /**
     * ДОБАВЛЕНО (14.08.2026, раунд 2, п.4): обязательные основные услуги
     * (взлёт-посадка/TLF, авиабезопасность/AV_SEC, метеообеспечение/MET_*)
     * должны оказываться ПО ЛЮБОМУ рейсу — это диктуется колонками
     * `services.is_mandatory`/`mandatory_for`/`mtow_min`/`mtow_max`, которые
     * в БД были заполнены финансовой службой ещё раньше, но нигде в коде не
     * читались. Раньше TLF/AV_SEC начислялись только внутри
     * processParkingAct() — а он вызывается только когда Аэродромная служба
     * подаёт акт стоянки, что теперь происходит не по каждому рейсу
     * (карточка акта стоянки убрана со страницы рейса в прошлом патче).
     * МЕТЕО (MET_50/100/101) вообще нигде не начислялось.
     *
     * Метод общий: вызывается автоматически при сборке акта ЦОК
     * (FlightController::buildAct()) — не зависит от того, подавала ли
     * Аэродромная служба акт стоянки — а также из processParkingAct() (см.
     * ниже), чтобы не дублировать логику.
     *
     * ВАЖНО: берём только услуги с requires_manual_quantity = 0 — то есть
     * НЕ трогаем PAX_ARR/PAX_DEP (они тоже is_mandatory=1, но их количество
     * вводит супервайзер вручную по факту пассажиров, автоматически
     * подставлять сюда нечего).
     */
    public function processFlightMandatoryCharges(int $flightId): array
    {
        $warnings = [];

        $stmt = $this->db->prepare("SELECT * FROM flights WHERE id = ? LIMIT 1");
        $stmt->execute([$flightId]);
        $flight = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$flight) return ['Рейс не найден.'];

        $mtow          = (float)($flight['aircraft_mtow'] ?? 0);
        $operationType = (string)($flight['operation_type'] ?? '');

        // ИСПРАВЛЕНО (03.09.2026, по итогам аудита): найден реальный случай
        // (акт 0003/SU-1877) — MTOW рейса был корректно указан на момент
        // сборки акта (TLF/AV_SEC верно посчитаны), а ПОТОМ поле MTOW в
        // карточке рейса опустело (случайно затёрто при правке). Раньше
        // здесь БЕЗУСЛОВНО удалялись все старые авто-начисления ДО проверки
        // MTOW — то есть при следующей пересборке акта TLF/AV_SEC сначала
        // стирались, а заново вставить их было уже не на основе чего (MTOW=0
        // → insertAuto для TLF не вызывается, только предупреждение) —
        // итог: уже верно посчитанные суммы тихо пропадали из акта без
        // возможности восстановления, кроме как вручную ввести MTOW заново
        // и надеяться, что никто не заметил пропажи раньше.
        // Теперь: если MTOW сейчас невалиден (<=0), TLF и % от него
        // (AV_SEC/NIGHT/OVER_PARK) исключаются из удаления — уже посчитанные
        // ранее строки остаются как были, вместо того чтобы исчезнуть без
        // возможности пересчитать взамен. Ниже добавлено явное предупреждение,
        // чтобы это не осталось незамеченным.
        $preserveIds = [];
        if ($mtow <= 0) {
            $tlfIdForGuard = $this->serviceIdByCode('TLF');
            if ($tlfIdForGuard) $preserveIds[] = $tlfIdForGuard;
            foreach (self::PERCENT_CODES as $pc) {
                $pid = $this->serviceIdByCode($pc);
                if ($pid) $preserveIds[] = $pid;
            }
        }

        // ДОБАВЛЕНО (11.09.2026, раунд 38, п.4): OVER_PARK всегда защищён от
        // этого блока удаления, независимо от MTOW. До переноса OVER_PARK на
        // акт вылета (см. processParkingAct() и resolveDepartureFlightId()
        // выше) этот метод, вызванный ПЕРВОЙ строкой изнутри
        // processParkingAct(), безопасно чистил старую строку OVER_PARK на
        // ТОМ ЖЕ $flightId прямо перед тем, как чуть ниже в processParkingAct()
        // она пересчитывалась заново — то есть OVER_PARK одного и того же
        // рейса сам себя подчищал. Теперь OVER_PARK почти всегда пишется на
        // ДРУГОЙ рейс (рейс вылета, а не тот $flightId, на который подан акт
        // стоянки прилёта) — и эта же самая безусловная очистка "всех
        // авто-начислений рейса", вызванная НАПРЯМУЮ на рейсе вылета (что
        // происходит при КАЖДОЙ сборке акта вылета — см.
        // FlightController::buildAct() → processFlightMandatoryCharges()),
        // без этой защиты стирала бы уже посчитанный OVER_PARK ДО того, как
        // buildFromFlightCharges() успевал его подхватить — то есть заново
        // считанная сверхнормативная стоянка тихо исчезала бы при каждой
        // пересборке акта вылета. Актуальную очистку/пересчёт OVER_PARK
        // теперь полностью и самостоятельно делает блок OVER_PARK внутри
        // processParkingAct() (свой явный
        // FlightServiceCharge::deleteChargesForFlightService() непосредственно
        // перед insertAuto()) — этому методу трогать OVER_PARK больше не нужно.
        $overParkIdForGuard = $this->serviceIdByCode('OVER_PARK');
        if ($overParkIdForGuard && !in_array($overParkIdForGuard, $preserveIds, true)) {
            $preserveIds[] = $overParkIdForGuard;
        }

        // Пересчитываем — удаляем старые авто-начисления (TLF/AV_SEC/NIGHT/
        // MET_*), чтобы не задваивались при повторной сборке акта. OVER_PARK
        // исключён всегда (см. выше), TLF/% от MTOW=0 исключены дополнительно.
        $this->chargeModel->deleteAutoChargesForFlight($flightId, $preserveIds);

        if ($mtow <= 0 && !empty($preserveIds)) {
            $hasOldTlf = $this->chargeModel->existsChargeForService($flightId, $preserveIds[0]);
            if ($hasOldTlf) {
                $warnings[] = 'ВНИМАНИЕ: MTOW рейса сейчас не указан. TLF и авиабезопасность '
                    . '(AV_SEC) оставлены с прошлого расчёта, НЕ пересчитаны — данные не потеряны, '
                    . 'но и не актуализированы. Укажите MTOW в карточке рейса и заново соберите акт.';
            }
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM services
             WHERE is_active = 1 AND is_mandatory = 1 AND requires_manual_quantity = 0
               AND (mandatory_for = 'both' OR mandatory_for = ?)
               AND (mtow_min IS NULL OR mtow_min <= ?)
               AND (mtow_max IS NULL OR mtow_max >= ?)
             ORDER BY code"
        );
        $stmt->execute([$operationType, $mtow, $mtow]);
        $mandatoryServices = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $tlfFound = false;
        foreach ($mandatoryServices as $svc) {
            $serviceId = (int)$svc['id'];
            $code      = (string)$svc['code'];

            try {
                // ИСПРАВЛЕНО (19.08.2026, раунд 21): см. докблок
                // FlightServiceCharge::existsChargeForService() — если по этой
                // обязательной услуге на рейсе УЖЕ есть запись (например
                // супервайзер сам отметил МЕТЕО до сборки акта), не пытаемся
                // вставить вторую — уважаем то, что уже введено, и просто
                // переходим к следующей услуге. Раньше попытка повторной
                // вставки падала на UNIQUE(flight_id, service_id) необработанным
                // исключением, которое обрывало ВЕСЬ foreach — из-за
                // алфавитного порядка (ORDER BY code) это "съедало" все
                // услуги ПОСЛЕ проблемной, в частности TLF терялась, если до
                // неё по алфавиту стояла проблемная услуга (напр. MET_50).
                if ($this->chargeModel->existsChargeForService($flightId, $serviceId)) {
                    if ($code === 'TLF') $tlfFound = true;
                    continue;
                }

                if ($code === 'TLF') {
                    $tlfFound = true;
                    // ДОБАВЛЕНО (19.08.2026, раунд 21, подтверждено
                    // пользователем): TLF — сбор ЗА РОТАЦИЮ (посадка + взлёт
                    // вместе), начисляется ОДИН РАЗ, на акте ПРИЛЁТА, а не на
                    // каждом плече отдельно. `mandatory_for='both'` в
                    // справочнике означает "услуга применима к рейсам обоих
                    // типов операций" (годится и для прилёта, и для вылета),
                    // а не "начислять дважды за один рейс" — этот нюанс не
                    // был явно закодирован раньше. До раунда 21 TLF на вылете
                    // и так не начислялась, но случайно, из-за независимого
                    // бага (см. существующую проверку existsChargeForService()
                    // выше и её докблок) — теперь это осознанное правило.
                    if ($operationType !== 'arrival') {
                        continue;
                    }
                    if ($mtow > 0) {
                        // ИЗМЕНЕНО (25.09.2026, раунд 57, п.15 ч.4 Правил КМ
                        // №218): "Йиғимни тўлаш миқдорини аниқлашда ... максимал
                        // учиш массаси БУТУН СОНГАЧА КЎП ТОМОНГА ЯХЛИТЛАНАДИ" —
                        // при расчёте суммы сбора MTOW округляется ВВЕРХ до
                        // целой тонны. Округляем только количество, идущее в
                        // саму сумму TLF (и, соответственно, во все %-услуги от
                        // неё — AV_SEC/NIGHT/OVER_PARK, см. billTlfBase()) —
                        // точное паспортное значение в карточке рейса не
                        // трогаем, оно по-прежнему используется для выбора
                        // диапазона MET_*/RAMP_*/DEICE_* (это отдельный вопрос,
                        // см. round56-анализ, п.2 приоритета).
                        $mtowBillable = $this->billTlfBase($mtow);
                        $note = "Авторасчёт: MTOW = {$mtow} т (обязательная услуга)";
                        if ($mtowBillable !== $mtow) {
                            $note = "Авторасчёт: MTOW = {$mtow} т, округлено вверх до {$mtowBillable} т "
                                . 'для расчёта сбора (п.15 Правил КМ РУз №218 от 30.04.2026) (обязательная услуга)';
                        }
                        $this->insertAuto($flightId, $serviceId, $mtowBillable, $note, 'mandatory_auto');
                    } else {
                        $warnings[] = 'MTOW рейса не указан — сбор TLF не рассчитан.';
                    }
                    continue;
                }

                if (in_array($code, self::PERCENT_CODES, true)) {
                    // См. комментарий у TLF выше — AV_SEC (и любая другая
                    // услуга, посчитанная как % от TLF) подчиняется тому же
                    // правилу "один раз за ротацию, на прилёте": раз сама
                    // TLF на вылете не начисляется, вычислять процент от неё
                    // там тоже не от чего. NIGHT/OVER_PARK сейчас в этот
                    // список фактически не попадают (is_mandatory=0 у них —
                    // считаются отдельно, через processParkingAct()).
                    if ($operationType !== 'arrival') {
                        continue;
                    }
                    if ($mtow > 0) {
                        $pct = $this->percentRate($serviceId, $flight);
                        $this->insertAuto($flightId, $serviceId, $pct,
                            'Авторасчёт: обязательная услуга (' . number_format($pct * 100, 0) . '% от TLF)', 'mandatory_auto');
                    }
                    continue;
                }

                if ($code === 'PAX_SEC') {
                    // ДОБАВЛЕНО (20.08.2026, раунд 22, подтверждено пользователем
                    // после разбора акта 0002/XX-QQE735): сбор безопасности
                    // вылетающих пассажиров ($20 за 1 убывающего пассажира от
                    // 2 лет) раньше вносился только вручную ("requires_manual_
                    // quantity=1") и на практике часто забывался — у бухгалтера
                    // в ручном расчёте эта строка была, а в системе услуги не
                    // было вовсе, из-за чего сумма акта расходилась на $60.
                    // Теперь считается автоматически по количеству, которое
                    // супервайзер уже ввёл в PAX_DEP (то же количество, что
                    // печатается в шапке акта DEP-LT), отдельного ввода не
                    // требует. mandatory_for='departure' в справочнике услуг
                    // гарантирует, что этот код сюда попадёт только на вылете.
                    if ($operationType !== 'departure') {
                        continue;
                    }
                    $paxQty = $this->chargeModel->getQuantityByCode($flightId, 'PAX_DEP');
                    if ($paxQty !== null && $paxQty > 0) {
                        $this->insertAuto($flightId, $serviceId, $paxQty,
                            "Авторасчёт: {$paxQty} вылетающих пассажиров (обязательная услуга — сбор безопасности пассажиров)", 'mandatory_auto');
                    } else {
                        $warnings[] = 'Количество пассажиров на вылет (PAX_DEP) ещё не введено — сбор PAX_SEC (безопасность пассажиров) не рассчитан.';
                    }
                    continue;
                }

                // ДОБАВЛЕНО (11.09.2026, раунд 38): PAX_ARR/PAX_DEP/BAG_ARR_PC/
                // BAG_DEP_PC — количество (пассажиры/места багажа) больше НЕ
                // вводит супервайзер вручную, а подтягивается из пассажирского
                // манифеста (см. manifestQuantityForCode()). Супервайзер на
                // планшете по-прежнему подтверждает САМ ФАКТ оказания услуги
                // (кнопка "Начать/Завершить" в flight_service_orders — без
                // этого подтверждения услуга физически не оказывалась, и
                // начислять за неё нечего, даже если манифест уже подан).
                if (in_array($code, self::MANIFEST_DRIVEN_CODES, true)) {
                    $order = $this->orderModel->findOne($flightId, $serviceId);
                    if (!$order || $order['status'] !== 'done') {
                        // Супервайзер ещё не подтвердил услугу — это нормальная
                        // ситуация (не каждая услуга уже оказана к моменту сборки
                        // акта), предупреждение не нужно, просто пропускаем.
                        continue;
                    }
                    $qty = $this->manifestQuantityForCode($flightId, $code);
                    if ($qty === null) {
                        $warnings[] = "Услуга «{$svc['name']}» подтверждена супервайзером, но манифест по рейсу ещё не подан — количество не рассчитано.";
                        continue;
                    }
                    if ($qty <= 0) {
                        // Легитимный случай — например рейс без пассажиров
                        // (только экипаж) или без багажа: в манифесте так и
                        // указано, начислять просто нечего.
                        continue;
                    }
                    $this->insertAuto($flightId, $serviceId, $qty,
                        "Авторасчёт: {$qty} из манифеста по рейсу (обязательная услуга)", 'mandatory_auto');
                    continue;
                }

                // ДОБАВЛЕНО (02.11.2026, раунд 142, по прямому указанию
                // пользователя): «Контроль обслуживания» (SUPERV) начисляется
                // на акт прилёта всегда (как раньше, через фоллбэк ниже), но
                // на акт вылета — только если с момента фактического прилёта
                // до фактического вылета прошло больше 24 часов. Если прилёт
                // и вылет случились в пределах одних суток — это один и тот
                // же период обслуживания борта, повторно не начисляем.
                if ($code === 'SUPERV' && $operationType === 'departure') {
                    $arrFlightIdForSuperv = $this->resolveArrivalFlightId($flight);
                    $arrFlightForSuperv   = $arrFlightIdForSuperv ? $this->flightModel->find($arrFlightIdForSuperv) : null;
                    $arrDtForSuperv       = $arrFlightForSuperv ? $this->resolveActualDateTime($arrFlightForSuperv) : null;
                    $depDtForSuperv       = $this->resolveActualDateTime($flight);

                    if ($arrDtForSuperv && $depDtForSuperv) {
                        $hoursSinceArrivalForSuperv = $this->hoursBetween($arrDtForSuperv, $depDtForSuperv);
                        if ($hoursSinceArrivalForSuperv <= 24) {
                            // В пределах суток с момента прилёта — не начисляем повторно на вылет.
                            continue;
                        }
                        $this->insertAuto($flightId, $serviceId, (float)($svc['default_quantity'] ?? 1),
                            'Авторасчёт: контроль обслуживания на вылет — с фактического прилёта прошло '
                            . number_format($hoursSinceArrivalForSuperv, 1) . ' ч (> 24 ч)', 'mandatory_auto');
                        continue;
                    }
                    $warnings[] = 'Не удалось определить интервал между фактическим прилётом и вылетом '
                        . '(ATA/ATD) — услуга «Контроль обслуживания» на вылет не рассчитана.';
                    continue;
                }

                // Прочие обязательные услуги с фиксированным количеством
                // (напр. МЕТЕО — "за один вылет", количество = default_quantity)
                $qty = (float)($svc['default_quantity'] ?? 1);
                if ($qty <= 0) $qty = 1.0;
                $this->insertAuto($flightId, $serviceId, $qty,
                    'Авторасчёт: обязательная услуга по регламенту (' . $svc['name'] . ')', 'mandatory_auto');
            } catch (\Throwable $e) {
                // ИСПРАВЛЕНО (19.08.2026, раунд 21): изолируем сбой ОДНОЙ
                // услуги от остальных — раньше исключение здесь обрывало весь
                // метод (см. докблок выше), теперь просто фиксируем
                // предупреждение и считаем остальные услуги как ни в чём не бывало.
                if ($code === 'TLF') $tlfFound = true;
                $warnings[] = "Авторасчёт услуги {$code} не выполнен: " . $e->getMessage();
                error_log("processFlightMandatoryCharges: service {$code} (flight {$flightId}) failed: " . $e->getMessage());
            }
        }

        if (!$tlfFound) {
            $warnings[] = 'Услуга TLF не найдена в справочнике услуг (или неактивна/не применима к этому рейсу) — обязательные сборы не рассчитаны.';
        }

        return $warnings;
    }

    /**
     * ДОБАВЛЕНО (28.09.2026, раунд 60): решение главного нерешённого
     * технического риска раунда 54 (подтверждённого раундами 56/58/59) — по
     * прямому указанию пользователя после сверки с реальным дампом боевой БД
     * от 28.09.2026.
     *
     * Проблема: guard в ActService::changeStatus() при утверждении акта
     * (getUnconsumedByFlight) находит только СУЩЕСТВУЮЩИЕ строки
     * flight_service_charges с consumed_in_act_id IS NULL — то есть "услуга
     * посчитана, но забыли внести в акт". Если обязательный сбор вообще
     * НИКОГДА не был посчитан (строки в flight_service_charges просто нет),
     * старый guard не видит проблемы вовсе. Это не гипотетический случай:
     * на реальном дампе боевой БД от 28.09.2026 найдено 6 из 26 утверждённых
     * актов (акты №№0009,0011,0013,0015,0017,0019 — рейсы прилёта без
     * указанного MTOW), где TLF и AV_SEC отсутствуют полностью — акт
     * утверждён с недосчитанной суммой без единого предупреждения (см.
     * claude/analysis-2026-09-28-round60-mandatory-charge-gap.md).
     *
     * Это метод — ЧИСТО ЧИТАЮЩАЯ проверка (ни одного INSERT/UPDATE/DELETE
     * ни при каких условиях). Он повторяет то же самое правило "какие
     * обязательные услуги (services.is_mandatory=1) применимы к этому
     * рейсу", которое реально считает processFlightMandatoryCharges() выше,
     * но НИКОГДА не вызывает insertAuto()/deleteAutoChargesForFlight() —
     * ровно по той же причине, по которой ActService::changeStatus()
     * сознательно не пересчитывает начисления сама при утверждении (см. её
     * докблок): сумма уже показанного/подписанного акта не должна меняться
     * без осознанного "Обновить", нажатого ЦОК.
     *
     * ВАЖНО — синхронизация с бизнес-правилами: ветвления ниже (TLF/%-услуги
     * только на прилёте — правило раунда 21; PAX_SEC только на вылете при
     * уже посчитанном PAX_DEP — раунд 22; манифест-услуги — норма, если
     * супервайзер ещё не подтвердил оказание услуги — раунд 38) СОЗНАТЕЛЬНО
     * скопированы из processFlightMandatoryCharges() построчно, а не
     * выведены заново, чтобы не разойтись с реальным расчётом. Если бизнес-
     * правило в processFlightMandatoryCharges() изменится в будущем раунде —
     * эту копию нужно обновить вместе с ним (см.
     * MandatoryChargeGapConsistencyTest в round60_test_env — прогоняет ОБА
     * метода на одних и тех же синтетических рейсах и проверяет, что
     * findMandatoryChargeGaps() никогда не считает "пробелом" код, который
     * processFlightMandatoryCharges() реально и корректно начислил).
     *
     * @return array<int, array{code:string, service_name:string, reason:string}>
     *         Пусто — пробелов не найдено, можно утверждать. Иначе — список
     *         обязательных сборов, которые должны быть посчитаны, но
     *         реально отсутствуют в flight_service_charges, с человеко-
     *         читаемой причиной на русском.
     */
    public function findMandatoryChargeGaps(int $flightId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM flights WHERE id = ? LIMIT 1");
        $stmt->execute([$flightId]);
        $flight = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$flight) return [];

        $aircraftMtow  = $flight['aircraft_mtow'] !== null && $flight['aircraft_mtow'] !== ''
            ? (float)$flight['aircraft_mtow'] : null;
        // То же самое допущение (MTOW неизвестен → 0.0 для подбора диапазона
        // услуг), что и в processFlightMandatoryCharges() выше — оставлено
        // идентичным, чтобы не разойтись со списком реально применимых услуг.
        $mtowForRange  = $aircraftMtow ?? 0.0;
        $operationType = (string)($flight['operation_type'] ?? '');

        $stmt = $this->db->prepare(
            "SELECT * FROM services
             WHERE is_active = 1 AND is_mandatory = 1 AND requires_manual_quantity = 0
               AND (mandatory_for = 'both' OR mandatory_for = ?)
               AND (mtow_min IS NULL OR mtow_min <= ?)
               AND (mtow_max IS NULL OR mtow_max >= ?)
             ORDER BY code"
        );
        $stmt->execute([$operationType, $mtowForRange, $mtowForRange]);
        $mandatoryServices = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $gaps = [];
        foreach ($mandatoryServices as $svc) {
            $serviceId = (int)$svc['id'];
            $code      = (string)$svc['code'];
            $name      = (string)$svc['name'];

            if ($this->chargeModel->existsChargeForService($flightId, $serviceId)) {
                continue; // уже посчитано (авто или вручную) — не пробел
            }

            if ($code === 'TLF' || in_array($code, self::PERCENT_CODES, true)) {
                // Правило раунда 21: TLF (и %-сборы от неё) начисляются один
                // раз, только на акте ПРИЛЁТА — на вылете отсутствие этих
                // кодов — норма, а не пробел.
                if ($operationType !== 'arrival') {
                    continue;
                }
                if ($aircraftMtow === null || $aircraftMtow <= 0) {
                    $gaps[] = [
                        'code' => $code, 'service_name' => $name,
                        'reason' => 'не указан MTOW рейса — сбор не рассчитан',
                    ];
                } else {
                    $gaps[] = [
                        'code' => $code, 'service_name' => $name,
                        'reason' => 'MTOW указан, но обязательный сбор не рассчитан — нажмите «Обновить» на странице акта',
                    ];
                }
                continue;
            }

            if ($code === 'PAX_SEC') {
                if ($operationType !== 'departure') {
                    continue;
                }
                $paxQty = $this->chargeModel->getQuantityByCode($flightId, 'PAX_DEP');
                if ($paxQty === null) {
                    // ИСПРАВЛЕНО (12.10.2026, раунд 67, жалоба супервайзеров
                    // через главного бухгалтера: "акт надо закрыть вручную
                    // ЦОК, т.к. манифест имеет значение пассажиров 0"):
                    // getQuantityByCode() читает КОЛИЧЕСТВО ИЗ УЖЕ СОЗДАННОЙ
                    // строки начисления PAX_DEP — а processFlightMandatoryCharges()
                    // сознательно НЕ создаёт строку начисления, когда
                    // пассажиров легитимно 0 ("начислять просто нечего", см.
                    // её докблок/код). Поэтому NULL здесь означал ДВА разных
                    // случая, которые раньше не различались: 1) PAX_DEP
                    // реально ещё не подтверждён/манифест не подан (это
                    // пробел), и 2) PAX_DEP уже корректно обработан, манифест
                    // подтвердил 0 пассажиров, строки начисления нет по
                    // замыслу (это НЕ пробел).
                    //
                    // ОБНОВЛЕНО (12.10.2026, тот же раунд, по итогам проверки
                    // реальных актов 0008/XX-2PTXC и 0020/XX-RWZ1115 из дампа
                    // 12.10.2026 — оба подтверждённо застряли ровно по этой
                    // причине): первая версия фикса дополнительно требовала,
                    // чтобы заявка на услугу PAX_DEP была отмечена
                    // супервайзером как 'done' — рассуждение было "не верим
                    // производной записи начисления, смотрим на подтверждение
                    // услуги напрямую". На практике же на рейсах без
                    // пассажиров заявку PAX_DEP часто вообще не оформляют
                    // (нечего физически обслуживать) или отменяют
                    // ('cancelled', не 'done') — именно так застряли оба
                    // реальных акта выше. Поданный и подтверждённый
                    // (status IN submitted/accepted) манифест сам по себе —
                    // авторитетный источник количества пассажиров рейса
                    // (см. PassengerManifest::getEffectiveForBilling()); если
                    // он показывает 0 билетируемых пассажиров — этого
                    // достаточно само по себе, статус заявки PAX_DEP больше
                    // не проверяется.
                    $manifestPaxDep = $this->manifestQuantityForCode($flightId, 'PAX_DEP');
                    $paxDepConfirmedZero = ($manifestPaxDep !== null && $manifestPaxDep <= 0);
                    if (!$paxDepConfirmedZero) {
                        $gaps[] = [
                            'code' => $code, 'service_name' => $name,
                            'reason' => 'количество вылетающих пассажиров (PAX_DEP) ещё не рассчитано — сбор безопасности пассажиров не может быть посчитан',
                        ];
                    }
                }
                // $paxQty <= 0 — легитимный ноль (пассажиров нет), не пробел.
                continue;
            }

            if (in_array($code, self::MANIFEST_DRIVEN_CODES, true)) {
                $order = $this->orderModel->findOne($flightId, $serviceId);
                if (!$order || $order['status'] !== 'done') {
                    // Норма, если услуга физически ещё не оказана/не
                    // подтверждена супервайзером — не пробел авторасчёта.
                    continue;
                }
                $qty = $this->manifestQuantityForCode($flightId, $code);
                if ($qty === null) {
                    $gaps[] = [
                        'code' => $code, 'service_name' => $name,
                        'reason' => 'услуга подтверждена супервайзером, но манифест по рейсу не подан — количество не рассчитано',
                    ];
                } elseif ($qty > 0) {
                    // ИСПРАВЛЕНО (найдено при внутреннем тестировании round60,
                    // см. тест G1/G2 в round60_test_env): услуга подтверждена
                    // (order.status=done), манифест подан с реальным
                    // положительным количеством — то есть processFlightMandatoryCharges()
                    // ДОЛЖЕН был бы её посчитать, — а начисления всё равно нет
                    // (например акт создан вручную в обход авторасчёта, или
                    // insertAuto() упал с исключением при прошлой сборке). Это
                    // такой же реальный пробел, как и отсутствующий манифест —
                    // раньше здесь этот случай молча пропускался.
                    $gaps[] = [
                        'code' => $code, 'service_name' => $name,
                        'reason' => 'услуга подтверждена и данные манифеста есть, но сбор не рассчитан — нажмите «Обновить» на странице акта',
                    ];
                }
                // $qty <= 0 — легитимный ноль (например рейс без багажа), не пробел.
                continue;
            }

            // ДОБАВЛЕНО (02.11.2026, раунд 142): SUPERV на вылете — та же
            // логика 24 часов, что и в processFlightMandatoryCharges() выше.
            // Если с прилёта прошло не больше суток, отсутствие начисления —
            // норма, не пробел.
            if ($code === 'SUPERV' && $operationType === 'departure') {
                $arrFlightIdForSuperv = $this->resolveArrivalFlightId($flight);
                $arrFlightForSuperv   = $arrFlightIdForSuperv ? $this->flightModel->find($arrFlightIdForSuperv) : null;
                $arrDtForSuperv       = $arrFlightForSuperv ? $this->resolveActualDateTime($arrFlightForSuperv) : null;
                $depDtForSuperv       = $this->resolveActualDateTime($flight);

                if ($arrDtForSuperv && $depDtForSuperv) {
                    $hoursSinceArrivalForSuperv = $this->hoursBetween($arrDtForSuperv, $depDtForSuperv);
                    if ($hoursSinceArrivalForSuperv <= 24) {
                        continue; // легитимно не начисляется повторно — не пробел
                    }
                    $gaps[] = [
                        'code' => $code, 'service_name' => $name,
                        'reason' => 'контроль обслуживания на вылет не рассчитан (прилёт был больше 24 ч назад) — нажмите «Обновить»',
                    ];
                    continue;
                }
                // ATA/ATD ещё не указаны — рано считать это пробелом.
                continue;
            }

            // Прочие обязательные услуги с фиксированным количеством (МЕТЕО,
            // SUPERV, MARSHALLING и т.п.) — единственная причина отсутствия
            // здесь — сбор просто не был(а) посчитан(а) авторасчётом.
            $gaps[] = [
                'code' => $code, 'service_name' => $name,
                'reason' => 'обязательная услуга не рассчитана — нажмите «Обновить» на странице акта',
            ];
        }

        // ДОБАВЛЕНО (22.10.2026, раунд 114, по прямому указанию пользователя
        // — реальный случай DXT9686/AIR MANAGEMENT GROUP): цикл выше
        // полностью пропускает OVER_PARK на акте ВЫЛЕТА (он в self::
        // PERCENT_CODES вместе с TLF/NIGHT/AV_SEC, и правило раунда 21
        // "эти коды проверяются только на акте прилёта" — continue на
        // строке выше). Но с раунда 38 OVER_PARK фактически считается и
        // начисляется именно на акте ВЫЛЕТА (см. processParkingAct()) — то
        // есть этот гейт раньше вообще не мог заметить, что сверхнормативная
        // стоянка не посчитана. Проверяем отдельно только одну твёрдо
        // устранимую причину — норму бесплатной стоянки у авиакомпании.
        // С раунда 114 она не должна отсутствовать вообще (проставляется
        // автоматически при создании авиакомпании — см.
        // AirlineController::store() и FlightController::
        // ensureDefaultParkingNorm() — плюс выполнен бэкфилл всех старых
        // записей), но проверка здесь — подстраховка на случай прямого
        // изменения БД в обход кода. Остальные причины непосчитанного
        // OVER_PARK (рейсы не связаны, не зафиксировано время колодок)
        // НАМЕРЕННО не блокируют утверждение здесь — это отдельная, давно
        // известная проблема недостающих данных от диспетчеров, расширять
        // блокировку на неё — отдельное решение пользователя.
        if ($operationType === 'departure' && $aircraftMtow !== null && $aircraftMtow > 0) {
            $overParkId = $this->serviceIdByCode('OVER_PARK');
            if ($overParkId && !$this->chargeModel->existsChargeForService($flightId, $overParkId)) {
                $summary = $this->getParkingTimingSummary($flightId, $flight);
                if ($summary['status'] === 'blocked_no_norm') {
                    $gaps[] = [
                        'code' => 'OVER_PARK',
                        'service_name' => 'Сверхнормативная стоянка / Over-park charge',
                        'reason' => 'норма бесплатной стоянки не задана для авиакомпании — зайдите в '
                            . 'Справочники → Нормы стоянки',
                    ];
                }
            }
        }

        return $gaps;
    }

    /**
     * Точка входа — вызывается при каждой сборке/пересборке акта (раньше —
     * только при submit акта стоянки, см. ниже почему это изменилось).
     * Возвращает массив предупреждений (например "норма стоянки не задана").
     *
     * ИЗМЕНЕНО (14.08.2026, раунд 2, п.4): TLF/AV_SEC/МЕТЕО вынесены в общий
     * processFlightMandatoryCharges() (см. выше) — здесь он просто
     * вызывается первым шагом, а затем поверх досчитываются NIGHT и
     * OVER_PARK.
     *
     * ИЗМЕНЕНО (17.09.2026, раунд 44, по прямому указанию пользователя):
     * раньше время начала/конца стоянки бралось из отдельной формы «Акт
     * готовности стоянки ВС» (parking_acts.aircraft_arrived_at/
     * aircraft_departed_at, Аэродромная служба) — метод принимал их
     * параметрами и вызывался только когда эта форма подана
     * (status='submitted'). На практике эта форма почти никогда не
     * заполняется (см. раунд 42, аудит готовности — 0 записей за всю
     * историю), из-за чего OVER_PARK физически не мог начислиться. Теперь
     * начало/конец стоянки — это момент УСТАНОВКИ колодок на рейсе прилёта и
     * момент УБОРКИ колодок на рейсе вылета (см. resolveChocksOnDateTime()/
     * resolveChocksOffDateTime() ниже) — те же данные, что уже используются
     * для обязательного поля в buildAct() (раунд 38, resolveChocksTime()).
     * Параметры $arrivedAt/$departedAt убраны — метод сам находит обе ноги
     * рейса через flights.linked_flight_id и сам резолвит колодки на каждой.
     * Вызывающий код (FlightController::buildAct(), ActController::resync(),
     * ActSignatureController::signForm()) больше не должен смотреть на
     * parking_acts, чтобы решить, вызывать этот метод или нет — он теперь
     * вызывается всегда, безусловно (как и processFlightMandatoryCharges()).
     * Побочный эффект: NIGHT теперь зависит от фактического времени рейса
     * (ATA/ATD) — см. round163 ниже; не от колодок.
     * теперь тоже считается при каждой сборке акта, а не только когда была
     * подана (почти никогда) форма Аэродромной службы — раньше это было
     * скрытой недоработкой той же природы.
     *
     * Форма «Акт готовности стоянки ВС» (parking_acts/ParkingActController)
     * как таковая не убрана и не трогалась — вопрос о её дальнейшей судьбе
     * поднят отдельно в аудите раунда 42, ждёт решения пользователя.
     */
    public function processParkingAct(int $flightId): array
    {
        $warnings = $this->processFlightMandatoryCharges($flightId);

        $stmt = $this->db->prepare("SELECT * FROM flights WHERE id = ? LIMIT 1");
        $stmt->execute([$flightId]);
        $flight = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$flight) return $warnings;

        $mtow  = (float)($flight['aircraft_mtow'] ?? 0);
        $tlfId = $this->serviceIdByCode('TLF');

        // ─── NIGHT ─────────────────────────────────────────────────
        // ПЕРЕРАБОТАНО (02.11.2026, раунд 142, по прямому указанию
        // пользователя): раньше считалось «один раз за ротацию» (см. раунд
        // 110, round110_analysis) — если хоть одна из двух ног (STA/STD)
        // ночью, сбор начислялся ровно один раз, кто бы из двух актов ни
        // собирался первым. Пользователь уточнил правило точнее: ночь
        // считается по календарным ГРАНИЦАМ ночного окна (см.
        // nightSessionAnchor() ниже — определяет, к какой календарной ночи
        // относится момент времени, с учётом перехода через полночь) — если
        // прилёт и вылет попадают в ОДНУ и ТУ ЖЕ ночь (или только одна из
        // двух ног ночью) — сбор один; но если прилёт — в одну ночь, а
        // вылет — уже в ДРУГУЮ (борт простоял несколько суток) — это две
        // разные ночи, сбор берётся ДВАЖДЫ, по разу за каждую.
        //
        // Привязка к акту теперь прямая и не зависит от порядка сборки
        // актов ротации (идемпотентно, в отличие от прежней версии): каждая
        // нога отвечает САМА ЗА СЕБЯ (прилёт начисляет, только если ночью
        // САМ прилёт; вылет — только если ночью САМ вылет), кроме единственного
        // случая «обе ноги — одна и та же ночь», когда сбор закреплён только
        // за актом ПРИЛЁТА (чтобы одна и та же ночь не была оплачена дважды).
        //
        // ПЕРЕРАБОТАНО (10.11.2026, раунд 163, по прямому указанию
        // пользователя: «все расчеты в актах которые упираются на плановое
        // время прилета и вылета должны быть завязаны на фактическое время.
        // стоянка остаётка как есть, она привязана к установке и снятию
        // колодок.»): источник времени для определения «ночи» сменён с
        // ПЛАНОВОГО (scheduled_time/STD-STA) на ФАКТИЧЕСКОЕ (arr_time/dep_time,
        // ATA/ATD — те же поля, что supervisor теперь может вводить сам, см.
        // раунд 142 п.1) — через уже существующий resolveActualDateTime().
        // Проверено аудитом (claude/analysis-2026-11-10-round163-night-fee-
        // actual-time.md): плановое и фактическое время НЕРЕДКО расходятся
        // на час и более, из-за чего по плану начислялась ночь, хотя рейс
        // по факту выполнялся днём (и наоборот) — теперь начисление строго
        // по факту. Если фактическое время (своей ноги) ещё не введено —
        // сбор НЕ угадывается, выводится предупреждение (тот же принцип
        // «не угадывать», что уже применён к SUPERV 24ч-правилу раунда 142
        // п.4) — ждём, когда супервайзер/ЦОК укажет ATA/ATD.
        // OVER_PARK НЕ затронут этим раундом — он и раньше, и сейчас считается
        // по колодкам (chocks_on/chocks_off), по прямому указанию пользователя
        // этот расчёт остаётся как есть.
        $nightId = $this->serviceIdByCode('NIGHT');
        if ($nightId && $tlfId && $mtow > 0) {
            $operationTypeForNight = (string)($flight['operation_type'] ?? '');

            $ownActualDT = $this->resolveActualDateTime($flight);
            $ownActualDate = $ownActualDT !== null ? substr($ownActualDT, 0, 10) : null;
            $ownActualTime = $ownActualDT !== null ? substr($ownActualDT, 11) : null;
            $ownIsNight = $ownActualTime !== null
                && $this->isNight($ownActualTime, $ownActualDate);
            $ownAnchor = $ownIsNight
                ? $this->nightSessionAnchor($ownActualTime, $ownActualDate)
                : null;

            $siblingId = (int)($flight['linked_flight_id'] ?? 0);
            $sibling = $siblingId > 0 ? $this->flightModel->find($siblingId) : null;
            $siblingActualDT = $sibling ? $this->resolveActualDateTime($sibling) : null;
            $siblingActualDate = $siblingActualDT !== null ? substr($siblingActualDT, 0, 10) : null;
            $siblingActualTime = $siblingActualDT !== null ? substr($siblingActualDT, 11) : null;
            $siblingIsNight = $siblingActualTime !== null
                && $this->isNight($siblingActualTime, $siblingActualDate);
            $siblingAnchor = $siblingIsNight
                ? $this->nightSessionAnchor($siblingActualTime, $siblingActualDate)
                : null;

            $sameNight = $ownIsNight && $siblingIsNight
                && $ownAnchor !== null && $siblingAnchor === $ownAnchor;

            // Если обе ноги — одна и та же ночь, сбор закреплён только за
            // актом прилёта; во всех остальных случаях, где СВОЯ нога ночью,
            // начисляем на ЭТОМ акте (включая случай «две разные ночи» —
            // тогда это условие верно на обоих актах, и получится два сбора).
            $chargeHere = $ownIsNight && (!$sameNight || $operationTypeForNight === 'arrival');

            if ($chargeHere) {
                $label = $operationTypeForNight === 'arrival' ? 'ATA' : 'ATD';
                $nightWindow = $this->seasonalNightWindow($ownActualDate);
                $nightPct = $this->percentRate($nightId, $flight);
                $extraNote = ($siblingIsNight && !$sameNight)
                    ? ' [отдельная ночь от связанного рейса #' . $siblingId . ' — начислено на обоих актах]'
                    : '';
                $this->insertAuto($flightId, $nightId, $nightPct,
                    'Авторасчёт: ' . $label . ' ' . substr($ownActualTime, 0, 5)
                    . ' (факт.) — ночной интервал '
                    . substr($nightWindow['from'], 0, 5) . '–' . substr($nightWindow['to'], 0, 5) . ' ('
                    . number_format($nightPct * 100, 0) . '% от TLF)' . $extraNote);
            } elseif ($ownActualTime === null) {
                $label = $operationTypeForNight === 'arrival' ? 'ATA' : 'ATD';
                $warnings[] = "Фактическое время рейса ({$label}) не указано — ночной сбор не проверен.";
            }
        }

        // ─── OVER_PARK ─────────────────────────────────────────────
        // ИЗМЕНЕНО (11.09.2026, раунд 38, п.4 — корректировка по ответу
        // пользователя на уточняющий вопрос "Перенести OVER_PARK на акт
        // вылета"): раньше OVER_PARK начислялся на ТОТ ЖЕ рейс, на который
        // подан акт стоянки (обычно рейс прилёта, т.к. акт стоянки на
        // прилёте подаётся первым и чаще всего единственный раз на всю
        // стоянку). Проблема: акт прилёта нередко уже утверждён
        // (Act::LOCKED_STATUSES) к тому моменту, когда становится известно
        // ФАКТИЧЕСКОЕ время убытия ВС (оно появляется только после вылета) —
        // а утверждённый акт больше не редактируется, обратного перехода в
        // draft не существует, то есть OVER_PARK физически некуда было бы
        // добавить. Акт ВЫЛЕТА, наоборот, всегда собирается строго ПОСЛЕ
        // фактического вылета — к моменту его утверждения фактическая
        // продолжительность стоянки уже точно известна. Поэтому теперь
        // OVER_PARK начисляется на рейс ВЫЛЕТА, определяемый через привязку
        // прилёт⇄вылет (flights.linked_flight_id, раунд 33) —
        // см. resolveDepartureFlightId() ниже.
        // ДОБАВЛЕНО (23.09.2026, раунд 55, по прямому указанию пользователя):
        // OVER_PARK начисляется на рейс ВЫЛЕТА и физически не может быть
        // посчитан раньше, чем борт улетел (см. getParkingTimingSummary() —
        // ему нужно время УБОРКИ колодок на вылете). Раньше этот блок
        // безусловно пытался посчитать OVER_PARK при формировании ЛЮБОГО
        // акта, включая акт ПРИЛЁТА — то есть при формировании акта прилёта
        // (обычно самое первое, что делает супервайзер после посадки)
        // гарантированно получал "рейсы не связаны" или "не зафиксировано
        // время уборки колодок", хотя ничего на самом деле не сломано —
        // борт просто ещё не улетел. Теперь блок целиком пропускается на
        // акте прилёта: расчёт (и любые предупреждения по нему) происходит
        // только при формировании акта ВЫЛЕТА того же борта, когда у
        // расчёта есть шанс реально сойтись.
        $overParkId = $this->serviceIdByCode('OVER_PARK');
        if ($overParkId && $tlfId && $mtow > 0 && ($flight['operation_type'] ?? '') === 'departure') {
            $summary = $this->getParkingTimingSummary($flightId, $flight);

            if ($summary['status'] === 'blocked_not_linked') {
                $warnings[] = 'Сверхнормативная стоянка не рассчитана: рейс прилёта не связан с рейсом '
                    . 'вылета того же борта. Свяжите рейсы (карточка рейса → «Связать с рейсом '
                    . 'вылета/прилёта») и заново соберите акт.';
            } elseif ($summary['status'] === 'blocked_no_chocks') {
                // ИЗМЕНЕНО (17.09.2026, раунд 44, подтверждено
                // пользователем): без времени колодок (ни отметки
                // супервайзера по услуге CHOCKS, ни ручных полей
                // chocks_on/chocks_off) начало/конец стоянки неизвестны —
                // блокируем расчёт с понятным предупреждением, а не
                // угадываем по ATA/ATD или другому суррогату.
                $missing = [];
                if (empty($summary['chocksOn']))  $missing[] = 'установка колодок (рейс прилёта #' . $summary['arrivalFlightId'] . ')';
                if (empty($summary['chocksOff'])) $missing[] = 'уборка колодок (рейс вылета #' . $summary['departureFlightId'] . ')';
                $warnings[] = 'Сверхнормативная стоянка не рассчитана: не зафиксировано время '
                    . implode(' и ', $missing) . ' — ни услугой CHOCKS у супервайзера, ни вручную в карточке рейса.';
            } elseif ($summary['status'] === 'blocked_no_norm') {
                $warnings[] = 'Норма бесплатной стоянки для авиакомпании не задана в коммерческой службе — '
                    . 'OVER_PARK не рассчитан. Зайдите в Справочники → Нормы стоянки.';
            } elseif ($summary['status'] === 'ok' && $summary['overDays'] > 0) {
                // ДОБАВЛЕНО (17.09.2026, раунд 44, по прямому указанию
                // пользователя): сверхнормативное время округляется вверх до
                // целых суток (24ч) — то есть 1 минута сверх нормы уже целые
                // сутки, а 25 часов сверх — уже 2 суток. quantity здесь —
                // именно ЦЕЛОЕ ЧИСЛО СУТОК: ActService::
                // buildFromFlightCharges() при сборке акта умножит его на
                // ставку тарифа (0.25 = 25%) и на сумму TLF — то есть сутки,
                // а не часы, масштабируют сумму (раньше здесь передавались
                // часы напрямую, из-за чего сумма росла линейно по часам, а
                // не ступенями по суткам — см. README патча).
                $departureFlightId = $summary['departureFlightId'];

                // См. докблок выше: OVER_PARK пишется на рейс ВЫЛЕТА,
                // который может отличаться от $flightId (если сборка
                // запущена с рейса прилёта). Без явной очистки повторная
                // сборка задваивала бы OVER_PARK на рейсе вылета.
                $this->chargeModel->deleteChargesForFlightService($departureFlightId, $overParkId);
                $this->insertAuto($departureFlightId, $overParkId, (float)$summary['overDays'],
                    sprintf('Авторасчёт: колодки установлены %s, убраны %s (факт. стоянка %.2f ч), '
                            . 'норма %.2f ч + буфер %d мин (п.21 Правил КМ №218), сверх нормы %.2f ч → %d сут. '
                            . '— начислено на акт вылета',
                        $summary['chocksOn'], $summary['chocksOff'], $summary['hours'],
                        $summary['freeHours'], (int)round($summary['bufferHours'] * 60),
                        $summary['overHours'], $summary['overDays']));
            }
        }

        return $warnings;
    }

    /**
     * ДОБАВЛЕНО (18.09.2026, раунд 44, дополнение по прямому указанию
     * пользователя): чистый (без побочных эффектов — ничего не начисляет и
     * не удаляет) расчёт времени стоянки и сверхнормативных суток по
     * ротации борта — та же самая логика, что и в блоке OVER_PARK внутри
     * processParkingAct() выше (вынесена сюда, чтобы не дублировать её
     * дважды), плюс используется ActController::printForm() для печати
     * расчёта стоянки на акте вылета вместо голых цифр времени посадки и
     * установки колодок.
     *
     * $flight — необязательный параметр: если уже есть строка рейса под
     * рукой (как в processParkingAct()), передаём её, чтобы не делать
     * лишний SELECT.
     *
     * Возвращает:
     *  status: 'ok' | 'blocked_not_linked' | 'blocked_no_chocks' | 'blocked_no_norm' | 'no_mtow'
     *  arrivalFlightId, departureFlightId — id обеих ног ротации (если найдены)
     *  chocksOn, chocksOff — datetime-строки (если найдены)
     *  hours, freeHours, overHours — float (если статус 'ok')
     *  overDays — int, 0 если стоянка в пределах нормы (если статус 'ok')
     */
    public function getParkingTimingSummary(int $flightId, ?array $flight = null): array
    {
        if ($flight === null) {
            $stmt = $this->db->prepare("SELECT * FROM flights WHERE id = ? LIMIT 1");
            $stmt->execute([$flightId]);
            $flight = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        $result = [
            'status' => 'blocked_not_linked',
            'arrivalFlightId' => null, 'departureFlightId' => null,
            'chocksOn' => null, 'chocksOff' => null,
            'hours' => null, 'freeHours' => null, 'bufferHours' => null, 'overHours' => null, 'overDays' => null,
        ];
        if (!$flight || (float)($flight['aircraft_mtow'] ?? 0) <= 0) {
            $result['status'] = 'no_mtow';
            return $result;
        }

        $arrivalFlightId   = $this->resolveArrivalFlightId($flight);
        $departureFlightId = $this->resolveDepartureFlightId($flight);
        $result['arrivalFlightId']   = $arrivalFlightId;
        $result['departureFlightId'] = $departureFlightId;
        if ($arrivalFlightId === null || $departureFlightId === null) {
            return $result; // status остаётся 'blocked_not_linked'
        }

        $chocksOn  = $this->resolveChocksOnDateTime($arrivalFlightId);
        $chocksOff = $this->resolveChocksOffDateTime($departureFlightId);
        $result['chocksOn']  = $chocksOn;
        $result['chocksOff'] = $chocksOff;
        if ($chocksOn === null || $chocksOff === null) {
            $result['status'] = 'blocked_no_chocks';
            return $result;
        }

        $norm = $this->normModel->getByAirline((int)$flight['airline_id']);
        if (!$norm) {
            $result['status'] = 'blocked_no_norm';
            return $result;
        }

        $hours     = $this->hoursBetween($chocksOn, $chocksOff);
        $freeHours = (float)$norm['free_hours_pax'];
        // ДОБАВЛЕНО (25.09.2026, раунд 57, п.21 ч.3 Правил КМ РУз №218, по
        // прямому указанию пользователя после сверки с Постановлением):
        // "Меъёрдан ортиқ тўхтаб туриш вақти ҳаво кемасининг норматив
        // вақтига ВА ЯНА 15 ДАҚИҚАГА камайтирилган... фарқи сифатида
        // аниқланади" — сверх нормы отсчитывается не только от нормы
        // авиакомпании, но и от дополнительных 15 минут буфера ПОВЕРХ неё.
        // Буфер прибавляется к норме до вычитания (т.е. увеличивает
        // бесплатный период), а не вычитается отдельно из overHours —
        // математически эквивалентно, но так нагляднее в note ниже.
        $bufferHours = self::OVER_PARK_BUFFER_HOURS;
        $overHours   = $hours - $freeHours - $bufferHours;

        $result['status']      = 'ok';
        $result['hours']       = $hours;
        $result['freeHours']   = $freeHours;
        $result['bufferHours'] = $bufferHours;
        $result['overHours']   = $overHours;
        $result['overDays']    = $overHours > 0 ? (int)ceil($overHours / 24) : 0;
        return $result;
    }

    /**
     * ДОБАВЛЕНО (11.09.2026, раунд 38): определяет id рейса ВЫЛЕТА, на
     * который нужно начислить OVER_PARK (см. докблок в processParkingAct()
     * выше) — сам $flight, если он уже вылет, либо связанный с ним рейс
     * вылета через flights.linked_flight_id (см. FlightService::linkFlights(),
     * раунд 33 — привязка симметричная, проставляется на ОБОИХ рейсах сразу).
     * Возвращает null, если связки нет или связанный рейс почему-то не
     * является вылетом (не должно происходить при штатной привязке через
     * linkFlights(), но проверяем явно на случай прямой правки в БД).
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
     * ДОБАВЛЕНО (11.09.2026, раунд 38, используется здесь с раунда 44 для
     * OVER_PARK) — зеркало resolveDepartureFlightId(): определяет id рейса
     * ПРИЛЁТА той же ротации борта (сам $flight, если это уже прилёт, либо
     * связанный рейс через flights.linked_flight_id).
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
     * ДОБАВЛЕНО (02.11.2026, раунд 142, по прямому указанию пользователя):
     * фактическое время рейса (ATA на прилёте / ATD на вылете,
     * flights.arr_time/dep_time — те же поля, которые теперь может
     * заполнять и супервайзер, см. ServiceOrderController::saveActualTime())
     * как единая дата-время строка. Нужно для расчёта интервала между
     * фактическим прилётом и вылетом (услуга SUPERV — см.
     * processFlightMandatoryCharges()/findMandatoryChargeGaps() ниже). Та
     * же вилка приоритета дат, что и везде в проекте (arr_date/dep_date, а
     * при пустом значении — flight_date самого рейса).
     */
    private function resolveActualDateTime(array $flight): ?string
    {
        $operationType = (string)($flight['operation_type'] ?? '');
        if ($operationType === 'arrival') {
            $time = $flight['arr_time'] ?? null;
            $date = $flight['arr_date'] ?? ($flight['flight_date'] ?? null);
        } elseif ($operationType === 'departure') {
            $time = $flight['dep_time'] ?? null;
            $date = $flight['dep_date'] ?? ($flight['flight_date'] ?? null);
        } else {
            return null;
        }
        if (empty($time) || empty($date)) {
            return null;
        }
        return $date . ' ' . $time;
    }

    /**
     * ДОБАВЛЕНО (17.09.2026, раунд 44): момент УСТАНОВКИ колодок на рейсе
     * прилёта — начало фактической стоянки (по прямому указанию пользователя:
     * "Начала установки колодки и есть начала стоянки"). Тот же приоритет
     * источников, что и в FlightController::resolveChocksTime()/
     * ActController::printForm() — сначала подтверждённый супервайзером заказ
     * услуги CHOCKS (started_at), затем ручное поле рейса chocks_on (только
     * время, без даты — комбинируется с flight_date этого же рейса прилёта).
     * Возвращает полную дату-время строкой ('Y-m-d H:i:s') либо null, если
     * ни того, ни другого источника нет.
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

    /**
     * ДОБАВЛЕНО (17.09.2026, раунд 44): момент УБОРКИ колодок на рейсе
     * вылета — конец фактической стоянки ("уборка колодок до вылета это и
     * есть завершение стоянки"). Зеркало resolveChocksOnDateTime() —
     * completed_at заказа CHOCKS, иначе ручное поле chocks_off + flight_date
     * рейса вылета.
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
     * ДОБАВЛЕНО (14.08.2026, раунд 5), ИЗМЕНЕНО (раунд 6): количество
     * тех.персонала спецтехники (TECH_STAFF) считается автоматически —
     * 1 человек на каждую оказанную услугу-строку, отмеченную флагом
     * `services.requires_tech_personnel = 1` (не сумма количеств внутри
     * строки — по решению пользователя). Раньше это была отдельная ручная
     * услуга, которую ССТ должна была сама выбрать и подтвердить количество в
     * своём чек-листе (Журнал спецтехники) — по новому регламенту диспетчеры
     * служб отдельно ничего не подтверждают, вся фиксация идёт через
     * супервайзера на перроне, поэтому берём то, что уже реально оказано и
     * отражено в flight_service_charges по этому рейсу (не важно, какой
     * службой и кем добавлено — вручную супервайзером или другим
     * авторасчётом).
     *
     * ИЗМЕНЕНО (раунд 6): список кодов раньше был жёстко прописан в PHP
     * (TECH_VEHICLE_CODES) — теперь читается из БД через
     * techPersonnelServiceCodes(), см. её докблок и докблок над объявлением
     * $techPersonnelCodesCache выше в этом классе.
     *
     * Вызывается при каждой сборке акта, как и processFlightMandatoryCharges().
     * Если оказанных "технических" услуг нет — TECH_STAFF просто не
     * начисляется (в акте останется прочерк, как и раньше).
     */
    public function syncTechStaffCount(int $flightId): void
    {
        $techStaffId = $this->serviceIdByCode('TECH_STAFF');
        if (!$techStaffId) return; // услуги нет в справочнике — нечего считать

        // Сносим предыдущий расчёт (и ручной, и авто — см. докблок
        // deleteChargesForFlightService) перед пересчётом.
        $this->chargeModel->deleteChargesForFlightService($flightId, $techStaffId);

        $techCodes = $this->techPersonnelServiceCodes();
        $allCharges = $this->chargeModel->getAllByFlight($flightId);
        $count = 0;
        foreach ($allCharges as $charge) {
            $code = strtoupper(trim($charge['service_code'] ?? ''));
            if (!in_array($code, $techCodes, true)) continue;
            if ((float)($charge['quantity'] ?? 0) <= 0) continue;
            $count++; // 1 услуга-строка = 1 человек, независимо от quantity внутри строки
        }

        if ($count <= 0) return;

        $this->insertAuto(
            $flightId, $techStaffId, (float)$count,
            "Авторасчёт: {$count} оказанных услуг спецтехники по рейсу (1 чел. на единицу)",
            'tech_staff_auto'
        );
    }

    /**
     * ДОБАВЛЕНО (14.08.2026, раунд 6): читает из справочника услуг список
     * кодов, отмеченных `requires_tech_personnel = 1` — то есть услуг, по
     * которым при сборке акта нужно учесть 1 единицу тех.персонала за строку
     * (см. syncTechStaffCount()). Заменяет собой прежнюю константу
     * TECH_VEHICLE_CODES: теперь финансовая/коммерческая служба может сама
     * включать/выключать этот признак на странице услуги (Справочники →
     * Услуги → Редактировать), без изменения кода. Результат кэшируется на
     * время жизни объекта (обычно один HTTP-запрос/сборка акта).
     */
    private function techPersonnelServiceCodes(): array
    {
        if ($this->techPersonnelCodesCache !== null) {
            return $this->techPersonnelCodesCache;
        }
        $stmt = $this->db->query(
            "SELECT code FROM services WHERE is_active = 1 AND requires_tech_personnel = 1"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->techPersonnelCodesCache = array_map(
            static fn(string $c) => strtoupper(trim($c)),
            $rows ?: []
        );
        return $this->techPersonnelCodesCache;
    }

    /**
     * Реальная ставка (0.10 / 0.20 / 0.25) для %-услуг (AV_SEC, NIGHT) из
     * действующего тарифа — вместо бессмысленной "1" в flight_service_charges.
     * Если тариф почему-то не найден — используем 1 (старое поведение), сумма
     * в акте от этого не пострадает: buildFromFlightCharges всё равно берёт
     * ставку заново из тарифа при сборке акта, это поле — только для
     * читаемости и будущих выгрузок (1С и т.п.).
     */
    /**
     * ИЗМЕНЕНО (15.10.2026, раунд 77): раньше ключ прайс-листа для подбора
     * тарифа брался из буквального route_type рейса — после развязки
     * валюты акта от route_type (резидентство авиакомпании, см.
     * ActService::resolveSettlementCurrency()/tariffPriceListKey()) это
     * может подобрать тариф в неправильной валюте (см. подробный докблок
     * в ActService.php). Это поле лишь для читаемости/будущих выгрузок
     * (buildFromFlightCharges() пересчитывает ставку заново при сборке
     * акта — см. её докблок выше), но лучше хранить корректное значение.
     */
    private function tariffPriceListKey(array $flight): string
    {
        $stmt = $this->db->prepare("SELECT is_resident FROM airlines WHERE id = ? LIMIT 1");
        $stmt->execute([(int)($flight['airline_id'] ?? 0)]);
        $isResident = (bool)$stmt->fetchColumn();
        return $isResident ? 'domestic' : 'international';
    }

    private function percentRate(int $serviceId, array $flight): float
    {
        $tariff = $this->tariffModel->getActiveForService(
            $serviceId,
            $this->tariffPriceListKey($flight),
            (string)$flight['operation_type'],
            date('Y-m-d')
        );
        return $tariff ? (float)$tariff['price'] : 1.0;
    }

    /**
     * ДОБАВЛЕНО (25.09.2026, раунд 57, п.15 ч.4 Правил КМ РУз №218): округляет
     * MTOW ВВЕРХ до целого числа тонн — именно так, как того требует буква
     * Правил при определении суммы сбора ("...бутун сонгача кўп томонга
     * яхлитланади"). Используется ТОЛЬКО для количества, идущего в саму сумму
     * TLF (и каскадно — в %-услуги AV_SEC/NIGHT/OVER_PARK, считающиеся от
     * суммы TLF, см. ActService::buildFromFlightCharges()). Выбор диапазона
     * MTOW-тарифов (MET_50/100/101, RAMP_*, DEICE_*) продолжает использовать
     * точное паспортное значение — Правила прямо требуют округление только
     * "при определении суммы сбора", а границы диапазонов (например,
     * mtow_min=50.10 у MET_101) явно рассчитаны на сравнение с дробным
     * значением, а не с округлённым.
     */
    private function billTlfBase(float $mtow): float
    {
        return (float)ceil($mtow);
    }

    // ИЗМЕНЕНО (14.08.2026, раунд 2, п.4): source_doc_type раньше был жёстко
    // 'parking_act' даже для начислений, сделанных без акта стоянки (теперь
    // обязательные сборы считаются и при простой сборке акта ЦОК) — просто
    // описательное поле, нигде по значению не фильтруется, но для порядка
    // сделан параметром с тем же значением по умолчанию.
    private function insertAuto(int $flightId, int $serviceId, float $qty, string $note, string $sourceDocType = 'parking_act'): void
    {
        $this->chargeModel->insert([
            'flight_id'       => $flightId,
            'service_id'      => $serviceId,
            'source_role'     => 'system',
            'source_doc_type' => $sourceDocType,
            'source_doc_id'   => null,
            'quantity'        => $qty,
            'is_auto'         => 1,
            'note'            => $note,
        ]);
    }

    /**
     * ДОБАВЛЕНО (11.09.2026, раунд 38): количество для PAX_ARR/PAX_DEP
     * (пассажиры) или BAG_ARR_PC/BAG_DEP_PC (места багажа) из действующего
     * манифеста рейса (см. PassengerManifest::getEffectiveForBilling()).
     * Экипаж (pax_crew) и транзит (pax_transit) в это число НЕ входят, услуга
     * "обслуживание пассажиров" тарифицируется по своим же пассажирам рейса.
     * Возвращает null, если манифеста ещё нет (подан ли — см. getEffectiveForBilling).
     *
     * ИЗМЕНЕНО (25.09.2026, раунд 57, п.18/23/24 Правил КМ РУз №218 —
     * подтверждено сверкой с Постановлением, claude/analysis-2026-09-24-
     * round56-postanovlenie-218-compliance.md): PAX_ARR/PAX_DEP (и, каскадно,
     * PAX_SEC — см. ниже) раньше считались по pax_total (= adults + children
     * + infants), хотя Правила прямо ограничивают базу этих сборов
     * пассажирами ОТ 2 ЛЕТ («икки ва ундан катта ёшдаги ҳар бир йўловчи»).
     * Младенцы (pax_infants) теперь исключаются из тарифицируемого
     * количества — см. billablePaxQuantity(). Поле passenger_manifests.
     * pax_total НЕ переименовано и не тронуто (используется как есть в
     * прочей статистике/печатных формах манифеста).
     */
    private function manifestQuantityForCode(int $flightId, string $code): ?float
    {
        $manifest = $this->manifestModel->getEffectiveForBilling($flightId);
        if (!$manifest) return null;

        if (in_array($code, ['PAX_ARR', 'PAX_DEP'], true)) {
            return $this->billablePaxQuantity($manifest);
        }
        // ДОБАВЛЕНО (27.10.2026, раунд 130): пассажиры кортежа — отдельное
        // поле манифеста (pax_cortege), отдельная услуга/тариф от обычных
        // PAX_ARR/PAX_DEP. В отличие от billablePaxQuantity() (раунд 57,
        // исключает младенцев по Постановлению КМ РУз №218), здесь берётся
        // всё поле целиком без исключений — для кортежа такое разделение
        // по возрасту пока не оговаривалось; если потребуется — отдельное
        // решение пользователя, аналогичное round57.
        if (in_array($code, ['PAX_CORTEGE_ARR', 'PAX_CORTEGE_DEP'], true)) {
            return (float)($manifest['pax_cortege'] ?? 0);
        }
        if (in_array($code, ['BAG_ARR_PC', 'BAG_DEP_PC'], true)) {
            return (float)$manifest['baggage_pieces'];
        }
        return null;
    }

    /**
     * ДОБАВЛЕНО (25.09.2026, раунд 57, п.18/23/24 Правил КМ РУз №218):
     * тарифицируемое число пассажиров = adults + children, БЕЗ младенцев
     * (pax_infants, дети до 2 лет на руках у взрослого) — именно эта база
     * используется для PAX_ARR, PAX_DEP и (каскадно, через getQuantityByCode
     * на уже вставленной строке PAX_DEP) для PAX_SEC. Отдельная от
     * passenger_manifests.pax_total величина, которая продолжает включать
     * младенцев для целей самого манифеста/статистики.
     */
    private function billablePaxQuantity(array $manifest): float
    {
        return (float)($manifest['pax_adults'] ?? 0) + (float)($manifest['pax_children'] ?? 0);
    }

    /**
     * ДОБАВЛЕНО (11.09.2026, раунд 38): публичная проверка "есть ли по рейсу
     * поданный манифест" — используется FlightController::buildAct() как
     * блокирующее условие ("без манифеста ЦОК не должен сформировать акт",
     * по прямому указанию пользователя). Манифест обязателен ВСЕГДА, даже
     * если пассажиров нет (грузовой/технический рейс) — в этом случае в
     * манифесте просто указывается 0 пассажиров/багажа, но экипаж
     * (pax_crew) есть всё равно, манифест как документ подаётся в любом случае.
     */
    public function hasSubmittedManifest(int $flightId): bool
    {
        return $this->manifestModel->getEffectiveForBilling($flightId) !== null;
    }

    /**
     * ДОБАВЛЕНО (23.09.2026, раунд 55): единая точка проверки "можно ли
     * вообще формировать/пересобирать акт по этому рейсу" — манифест +
     * ATA/ATD + время колодок (то же самое условие, что раньше было
     * захардкожено только внутри FlightController::buildAct()). Вынесено
     * сюда, чтобы ActController::resync() ("Обновить" на странице акта)
     * тоже проверял его перед пересчётом обязательных сборов — раньше
     * resync() не проверял вообще ничего, и в теории мог пересчитать
     * TLF/AV_SEC/NIGHT/OVER_PARK на акте, изначально созданном в обход
     * этого гейта (единственный такой путь — ручное "Создать акт"
     * администратором, ActController::create()/store(), которое само по
     * себе НЕ считает авто-сборы, но не мешает следующему "Обновить" это
     * сделать без проверки). Возвращает пустой массив, если формировать
     * можно; иначе — человекочитаемые причины блокировки на русском, в
     * том же виде, в каком их раньше собирал buildAct() у себя внутри.
     */
    public function getActFormationBlockers(array $flight): array
    {
        $flightId = (int)($flight['id'] ?? 0);
        $blockers = [];

        if (!$this->hasSubmittedManifest($flightId)) {
            $blockers[] = 'не подан пассажирский манифест по рейсу (СОП: Манифесты) — даже '
                . 'если пассажиров нет, манифест нужно подать с нулевыми значениями';
        }

        $operationType = (string)($flight['operation_type'] ?? '');
        if ($operationType === 'arrival') {
            if (empty($flight['arr_time'])) {
                $blockers[] = 'не указано время прилёта (ATA) в карточке рейса';
            }
            if ($this->resolveChocksOnDateTime($flightId) === null) {
                $blockers[] = 'не зафиксировано время установки колодок (ни услугой CHOCKS, ни вручную в карточке рейса)';
            }
        } elseif ($operationType === 'departure') {
            if (empty($flight['dep_time'])) {
                $blockers[] = 'не указано время вылета (ATD) в карточке рейса';
            }
            if ($this->resolveChocksOffDateTime($flightId) === null) {
                $blockers[] = 'не зафиксировано время снятия колодок (ни услугой CHOCKS, ни вручную в карточке рейса)';
            }
        }

        return $blockers;
    }

    private function serviceIdByCode(string $code): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM services WHERE code = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * ИЗМЕНЕНО (25.09.2026, раунд 57, п.16 Правил КМ РУз №218): раньше —
     * один фиксированный интервал (23:00–06:00) круглый год. Правила задают
     * ДВА сезонных окна (см. NIGHT_WINDOW_WINTER/NIGHT_WINDOW_SUMMER выше) —
     * сезон определяется по месяцу ДАТЫ РЕЙСА ($flightDate), не по текущей
     * дате сервера. $flightDate — необязателен для обратной совместимости
     * вызовов (тогда используется текущий месяц), но processParkingAct()
     * всегда передаёт flights.flight_date.
     */
    private function isNight(string $time, ?string $flightDate = null): bool
    {
        $t = substr($time, 0, 8);
        if (strlen($t) === 5) $t .= ':00';
        $window = $this->seasonalNightWindow($flightDate);
        return $t >= $window['from'] || $t < $window['to'];
    }

    /**
     * ДОБАВЛЕНО (25.09.2026, раунд 57, п.16 Правил КМ РУз №218): зимнее окно
     * (1 окт — 31 мар) 17:00–08:00, летнее (1 апр — 30 сен) 20:00–06:00.
     */
    private function seasonalNightWindow(?string $flightDate): array
    {
        $month = $flightDate ? (int)date('n', strtotime($flightDate)) : (int)date('n');
        return in_array($month, self::WINTER_MONTHS, true)
            ? self::NIGHT_WINDOW_WINTER
            : self::NIGHT_WINDOW_SUMMER;
    }

    /**
     * ДОБАВЛЕНО (02.11.2026, раунд 142, по прямому указанию пользователя):
     * определяет, к какой календарной «ночи» относится момент времени,
     * чтобы различать «одна и та же ночь» от «две разные ночи» при
     * начислении NIGHT (см. processParkingAct()). Ночное окно пересекает
     * полночь (например, зимой 17:00–08:00) — поэтому у него два «куска»:
     * вечерняя часть (время >= from) принадлежит ночи, начавшейся В ЭТУ
     * календарную дату; утренний «хвост» (время < to) — это ещё та же
     * самая ночь, но она физически НАЧАЛАСЬ накануне, поэтому её якорь —
     * предыдущий календарный день. Возвращает null, если время не ночное
     * (вызывающий код сам проверяет isNight() перед вызовом).
     */
    private function nightSessionAnchor(string $time, ?string $flightDate): ?string
    {
        if (!$flightDate) {
            return null;
        }
        $t = substr($time, 0, 8);
        if (strlen($t) === 5) $t .= ':00';
        $window = $this->seasonalNightWindow($flightDate);

        if ($t >= $window['from']) {
            return $flightDate;
        }
        if ($t < $window['to']) {
            return date('Y-m-d', strtotime($flightDate . ' -1 day'));
        }
        return null;
    }

    private function hoursBetween(string $from, string $to): float
    {
        $f = strtotime($from);
        $t = strtotime($to);
        if (!$f || !$t || $t <= $f) return 0;
        return round(($t - $f) / 3600, 2);
    }
}
