<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ActivityLogger;
use App\Core\Auth;
use App\Core\Database;
use App\Models\Act;
use App\Models\ActFile;
use App\Models\ActItem;
use App\Models\ActItemCalculation;
use App\Models\ActStatusHistory;
use App\Models\Service;
use App\Models\TariffVersion;
use App\Models\FlightServiceCharge;
use App\Models\Flight;
use App\Models\HandlerEmployee;

/**
 * CALCULATION RULES:
 * 1. Price ALWAYS from tariff, already stored in the act's own settlement
 *    currency (domestic tariffs are entered directly in UZS, international
 *    in USD — see tariff_versions.currency_id). User enters QUANTITY only.
 * 2. amount_without_vat = quantity × price  (cb_rate is NOT part of this
 *    formula — see addActItem() below). ИСПРАВЛЕНО (17.08.2026, раунд 9):
 *    this docblock previously said "× cb_rate", which was stale/wrong and
 *    contradicted addActItem()'s own inline comment — it described an
 *    earlier design (tariffs always in USD, cb_rate converts to settlement
 *    currency) that the actual data no longer follows. acts.cb_rate /
 *    act_items.cb_rate are kept purely for informational display (the real
 *    CBU exchange rate at act time), never multiplied into the amount.
 * 3. VAT: domestic=12% (if applicable), international=0% — зависит от
 *    буквального route_type рейса (airports.is_local, см. FlightService::
 *    resolveRouteType()). Это правило НЕ менялось в раунде 77.
 * 4. ИЗМЕНЕНО (15.10.2026, раунд 77, по прямому указанию пользователя):
 *    валюта акта больше не определяется route_type — она определяется
 *    РЕЗИДЕНТСТВОМ АВИАКОМПАНИИ (airlines.is_resident): местная → UZS,
 *    иностранная → USD, независимо от того, местный это рейс или
 *    международный (см. resolveSettlementCurrency()). Соответственно и
 *    выбор строки тарифа (какой прайс-лист читать) идёт по этой же
 *    валюте, а не по route_type (см. tariffPriceListKey()) — иначе тариф
 *    в сумах мог быть прочитан как тариф в долларах. route_type и валюта
 *    СОВПАДАЛИ на 100% данных до раунда 77 только потому, что ни одна
 *    местная авиакомпания ещё не выполняла рейсы — поэтому это изменение
 *    не затронуло ни один существующий акт (кроме одной уже утверждённой
 *    записи с "мусорной" авиакомпанией 2PTXC, см. round77 doc).
 */
class ActService
{
    private Act                     $actModel;
    private ActItem                 $actItemModel;
    private ActItemCalculation      $calcModel;
    private ActStatusHistory        $historyModel;
    private ActFile                 $actFileModel;
    private Service                 $serviceModel;
    private TariffVersion           $tariffModel;
    private DocumentSequenceService $seqService;
    private FlightServiceCharge     $chargeModel;
    private Flight                  $flightModel;
    private AutoChargeService       $autoChargeService;
    private \PDO                    $db;

    public function __construct()
    {
        $this->actModel      = new Act();
        $this->actItemModel  = new ActItem();
        $this->calcModel     = new ActItemCalculation();
        $this->historyModel  = new ActStatusHistory();
        $this->actFileModel  = new ActFile();
        $this->serviceModel  = new Service();
        $this->tariffModel   = new TariffVersion();
        $this->seqService    = new DocumentSequenceService();
        $this->chargeModel   = new FlightServiceCharge();
        $this->flightModel   = new Flight();
        // ДОБАВЛЕНО (28.09.2026, раунд 60): нужен только для findMandatoryChargeGaps()
        // (чисто читающая проверка) в changeStatus() ниже — см. её докблок.
        $this->autoChargeService = new AutoChargeService();
        $this->db            = Database::getInstance();
    }

    public function createAct(array $data): array
    {
        $userId    = Auth::id();
        $sessionId = Auth::sessionDbId();
        $now       = date('Y-m-d H:i:s');
        $today     = date('Y-m-d');

        // ИЗМЕНЕНО (10.09.2026, раунд 35): по прямому указанию пользователя —
        // "без подписи от супервайзера и представителя сформировать
        // окончательный вариант акта не должно быть совсем... этот акт не
        // должен иметь статус акта до его подписания". Официальный
        // порядковый номер (DocumentSequenceService::next) БОЛЬШЕ НЕ
        // присваивается здесь, при первой сборке ЦОК — только позже, когда
        // обе подписи уже стоят (см. ensureActNumberFinalized(), вызывается
        // из ActSignatureController после второй подписи) либо прямо перед
        // утверждением по скану (см. changeStatus()). До этого момента у
        // акта — временный, явно нефинальный номер-заглушка; печатная форма
        // показывает вместо него "ПРЕДВАРИТЕЛЬНО" (см. resources/views/acts/print.php).
        // Побочный положительный эффект: номера больше не "сгорают" впустую
        // на черновиках, которые так и не были подписаны/утверждены.
        $actNumber = 'PRELIM-FL' . (int)$data['flight_id'];

        $cbRate = !empty($data['cb_rate']) ? (float)$data['cb_rate'] : null;
        if ($data['route_type'] === 'domestic' && empty($cbRate)) {
            return ['success' => false, 'error' => 'CB Rate is required for domestic acts (USD → UZS conversion).'];
        }

        $settlementCurrencyId = $this->resolveSettlementCurrency(
            (int)$data['flight_id'],
            (int)($data['settlement_currency_id'] ?? 0)
        );

        $insertData = [
            'act_number'               => $actNumber,
            'number_finalized'         => 0,
            'act_date'                 => $today,
            'flight_id'                => (int)$data['flight_id'],
            'route_type'               => $data['route_type'],
            'operation_type'           => $data['operation_type'],
            'status'                   => 'draft',
            'cb_rate'                  => $cbRate,
            'settlement_currency_id'   => $settlementCurrencyId,
            'total_amount_without_vat' => 0.00,
            'total_vat_amount'         => 0.00,
            'total_amount_with_vat'    => 0.00,
            'notes'                    => !empty($data['notes']) ? trim($data['notes']) : null,
            'created_by_user_id'       => $userId,
            'created_in_session_id'    => $sessionId,
        ];

        $id = (int)$this->actModel->insert($insertData);
        $this->historyModel->record($id, null, 'draft', $userId, $sessionId, 'Act created');
        // addDefaultServices убран — услуги теперь берутся исключительно из
        // flight_service_charges (заявки служб + AutoChargeService) через
        // FlightController::buildAct → buildFromFlightCharges.

        ActivityLogger::log('act', $id, 'create_act', 'success', "Created act '{$actNumber}'", null, $insertData);
        return ['success' => true, 'id' => $id, 'act_number' => $actNumber];
    }

    /**
     * @deprecated Не вызывается автоматически начиная с введения workflow
     * flight_service_charges. Услуги в акт добавляются только через
     * buildFromFlightCharges() на основе заявок служб и AutoChargeService.
     * Оставлен для возможного ручного вызова администратором в нештатных ситуациях.
     */
    public function addDefaultServicesManually(int $actId, string $routeType, string $opType, string $date, float $cbRate, int $flightId = 0): void
    {
        // Получаем MTOW из рейса — нужен для TLF и % услуг
        $mtow = 0.0;
        if ($flightId > 0) {
            $stmt = $this->db->prepare("SELECT aircraft_mtow FROM `flights` WHERE id = ? LIMIT 1");
            $stmt->execute([$flightId]);
            $mtow = (float)($stmt->fetchColumn() ?: 0);
        }

        // TLF базовая сумма в USD = MTOW × price_per_ton
        // Используется для расчёта AV_SEC, NIGHT, OVER_PARK
        $tlfAmountUsd = 0.0;

        // % услуги: их price = TLF_USD, qty = процент (0.10, 0.20, 0.25)
        $percentServices = ['AV_SEC', 'NIGHT', 'OVER_PARK'];

        // Первый проход: TLF (нужна для % услуг)
        foreach ($this->serviceModel->getDefaultServices() as $service) {
            if ($service['code'] !== 'TLF') continue;
            $tariff = $this->tariffModel->getActiveForService((int)$service['id'], $routeType, $opType, $date);
            if ($tariff && $mtow > 0) {
                $tlfAmountUsd = $mtow * (float)$tariff['price']; // MTOW × $20
            }
            break;
        }

        // Основной проход: добавляем все default услуги
        foreach ($this->serviceModel->getDefaultServices() as $service) {
            $tariff = $this->tariffModel->getActiveForService((int)$service['id'], $routeType, $opType, $date);
            if (!$tariff) continue;

            $serviceCode = $service['code'];
            $vatRate     = $this->resolveVatRate($service, $routeType, (float)$tariff['vat_rate']);
            $note        = 'Default service';

            if ($serviceCode === 'TLF') {
                // ИЗМЕНЕНО (25.09.2026, раунд 57, п.15 ч.4 Правил КМ РУз №218):
                // MTOW округляется вверх до целой тонны при расчёте суммы сбора
                // — см. AutoChargeService::billTlfBase() (тот же принцип,
                // продублирован здесь для согласованности, т.к. этот метод —
                // отдельный резервный путь ручной сборки администратором).
                $qty      = $mtow > 0 ? (float)ceil($mtow) : (float)($service['default_quantity'] ?? 1.0);
                $priceUsd = (float)$tariff['price']; // $20/т
                $note     = $mtow > 0 ? "MTOW = {$mtow} т, округлено вверх до {$qty} т" : 'Default service';

            } elseif (in_array($serviceCode, $percentServices, true) && $tlfAmountUsd > 0) {
                // AV_SEC, NIGHT, OVER_PARK: % от суммы TLF
                // price = TLF в USD (= MTOW × 20), qty = процент (0.10 / 0.20 / 0.25)
                $pct      = (float)$tariff['price']; // 0.10 / 0.20 / 0.25
                $qty      = $pct;                    // количество = процент
                $priceUsd = $tlfAmountUsd;           // цена = TLF сумма USD
                $pctLabel = number_format($pct * 100, 0) . '%';
                $note     = "{$pctLabel} от TLF = {$pctLabel} × " . number_format($tlfAmountUsd, 2) . ' USD';

            } else {
                // Все остальные услуги — стандартно
                $qty      = (float)($service['default_quantity'] ?? 1.0);
                $priceUsd = (float)$tariff['price'];
            }

            [$withoutVat, $vatAmt, $withVat] = $this->calculate($qty, $priceUsd, $cbRate, $vatRate);
            $lineNo = $this->actItemModel->getNextLineNo($actId);

            $itemData = [
                'act_id'                 => $actId,
                'service_id'             => (int)$service['id'],
                'tariff_version_id'      => (int)$tariff['id'],
                'line_no'                => $lineNo,
                'service_name_snapshot'  => $service['name'],
                'unit_name_snapshot'     => $service['unit_name'],
                'quantity'               => $qty,
                'unit_price'             => $priceUsd,
                'currency_id'            => (int)$tariff['currency_id'],
                'cb_rate'                => $cbRate,
                'amount_without_vat'     => $withoutVat,
                'vat_rate'               => $vatRate,
                'vat_amount'             => $vatAmt,
                'amount_with_vat'        => $withVat,
                'calculation_note'       => $note,
            ];

            $itemId = (int)$this->actItemModel->insert($itemData);
            $this->saveCalcLog($itemId, $qty, $priceUsd, $cbRate, $vatRate, $withoutVat, $vatAmt, $withVat, $routeType);
        }
        $this->recalculateActTotals($actId);
    }

    public function updateAct(int $id, array $data): array
    {
        $existing = $this->actModel->find($id);
        if (!$existing) return ['success' => false, 'error' => 'Act not found.'];
        if (in_array($existing['status'], ['approved', 'cancelled', 'exported'], true)) {
            return ['success' => false, 'error' => 'Cannot edit act with status: ' . $existing['status']];
        }

        $cbRate = !empty($data['cb_rate']) ? (float)$data['cb_rate'] : null;
        $settlementCurrencyId = $this->resolveSettlementCurrency(
            (int)$data['flight_id'], (int)($data['settlement_currency_id'] ?? 0)
        );

        $updateData = [
            'flight_id'              => (int)$data['flight_id'],
            'route_type'             => $data['route_type'],
            'operation_type'         => $data['operation_type'],
            'cb_rate'                => $cbRate,
            'settlement_currency_id' => $settlementCurrencyId,
            'notes'                  => !empty($data['notes']) ? trim($data['notes']) : null,
        ];

        $this->actModel->update($id, $updateData);

        // Recalculate all items if CB rate changed
        if ($cbRate && (float)($existing['cb_rate'] ?? 0) !== $cbRate) {
            $this->recalculateAllItems($id, $cbRate, $data['route_type']);
        }

        ActivityLogger::log('act', $id, 'update_act', 'success', "Updated act '{$existing['act_number']}'", $existing, $updateData);
        return ['success' => true];
    }

    public function addActItem(int $actId, array $data): array
    {
        $act = $this->actModel->find($actId);
        if (!$act) return ['success' => false, 'error' => 'Act not found.'];
        if (in_array($act['status'], ['approved', 'cancelled', 'exported'], true)) {
            return ['success' => false, 'error' => 'Cannot modify act with status: ' . $act['status']];
        }

        $service = $this->serviceModel->findWithDetails((int)$data['service_id']);
        if (!$service) return ['success' => false, 'error' => 'Service not found.'];

        // Get tariff - REQUIRED, price comes ONLY from tariff
        $tariffId = !empty($data['tariff_version_id']) ? (int)$data['tariff_version_id'] : null;
        $tariff   = $tariffId
            ? $this->tariffModel->find($tariffId)
            : $this->tariffModel->getActiveForService(
                (int)$data['service_id'], $this->tariffPriceListKey($act), $act['operation_type'], $act['act_date']
              );

        if (!$tariff) {
            return ['success' => false, 'error' => 'No active tariff found for this service. Please configure a tariff first.'];
        }

        $qty = (float)($data['quantity'] ?? 1);

        // Определяем цену:
        // price_override используется для % услуг (AV_SEC/NIGHT/OVER_PARK) —
        // их база = сумма TLF в расчётной валюте акта.
        $price = isset($data['price_override'])
            ? (float)$data['price_override']
            : (float)$tariff['price'];

        // Логика валют и курса ЦБ:
        // domestic рейс  → расчёт в UZS → тариф в UZS (currency_id=1) → cbRate=1
        // international  → расчёт в USD → тариф в USD (currency_id=2) → cbRate=1
        // Курс ЦБ (cb_rate) в акте хранится для конвертации USD→UZS при отображении,
        // но НЕ используется в формуле расчёта суммы строки.
        // Формула: amount = qty × price (уже в нужной валюте акта)
        $cbRate = 1.0;

        $vatRate = $this->resolveVatRate($service, $act['route_type'], (float)$tariff['vat_rate']);
        $priceUsd = $price; // переименование для совместимости с calculate()

        [$withoutVat, $vatAmt, $withVat] = $this->calculate($qty, $priceUsd, $cbRate, $vatRate);
        $lineNo = $this->actItemModel->getNextLineNo($actId);

        // ДОБАВЛЕНО (14.08.2026, раунд 4): is_default помечает строки,
        // добавленные АВТОМАТИЧЕСКИ (buildFromFlightCharges()), в отличие от
        // строк, которые ЦОК добавляет вручную через "+ Добавить услугу"
        // (ActController::addItem(), $data['is_default'] там не передаётся,
        // поэтому по умолчанию 0 — поведение ручного добавления не менялось).
        // Используется для дедупликации при пересборке акта — см.
        // ActItem::deleteAutoByActAndService().
        $itemData = [
            'act_id' => $actId, 'service_id' => (int)$data['service_id'],
            'tariff_version_id' => (int)$tariff['id'], 'line_no' => $lineNo,
            'service_name_snapshot' => $service['name'], 'unit_name_snapshot' => $service['unit_name'],
            'quantity' => $qty, 'unit_price' => $priceUsd, 'currency_id' => (int)$tariff['currency_id'],
            'cb_rate' => $cbRate, 'amount_without_vat' => $withoutVat,
            'vat_rate' => $vatRate, 'vat_amount' => $vatAmt, 'amount_with_vat' => $withVat,
            'calculation_note' => !empty($data['calculation_note']) ? trim($data['calculation_note']) : null,
            'is_default' => !empty($data['is_default']) ? 1 : 0,
        ];

        try {
            $itemId = (int)$this->actItemModel->insert($itemData);
        } catch (\Throwable $e) {
            $errMsg = 'act_items insert failed: ' . $e->getMessage()
                . ' | data: ' . json_encode($itemData);
            error_log($errMsg);
            return ['success' => false, 'error' => 'DB insert error: ' . $e->getMessage()];
        }

        if (!$itemId) {
            $errMsg = 'act_items insert returned 0 | data: ' . json_encode($itemData);
            error_log($errMsg);
            return ['success' => false, 'error' => 'DB insert returned 0 — проверьте error_log'];
        }

        try {
            $this->saveCalcLog($itemId, $qty, $priceUsd, $cbRate, $vatRate, $withoutVat, $vatAmt, $withVat, $act['route_type']);
        } catch (\Throwable $e) {
            error_log('saveCalcLog failed for item ' . $itemId . ': ' . $e->getMessage());
        }

        $this->recalculateActTotals($actId);
        ActivityLogger::log('act_item', $itemId, 'add_act_item', 'success', "Added item line #{$lineNo} to act_id={$actId}", null, $itemData);
        return ['success' => true, 'id' => $itemId, 'amount_without_vat' => $withoutVat];
    }


    /**
     * Сборка строк акта из накопленных заявок служб (flight_service_charges).
     * Вызывается из FlightController::buildAct — единственной точки создания акта.
     *
     * Обычные услуги: quantity из заявки, price из тарифа.
     * % услуги (NIGHT/AV_SEC/OVER_PARK): price_override = сумма TLF этого акта,
     *   quantity = ставка из тарифа (или часы × ставка для OVER_PARK).
     *
     * @return array{success: bool, added: int, skipped: array, warnings: array}
     */
    /**
     * Собирает строки акта из накопленных заявок служб (flight_service_charges).
     *
     * ВАЖНО: markConsumed вызывается ТОЛЬКО для строк где addActItem успешно
     * выполнил INSERT. Если addActItem упал — строка остаётся unconsumed
     * и будет подхвачена при следующем вызове buildFromFlightCharges.
     *
     * Логика % услуг (AV_SEC/NIGHT/OVER_PARK):
     *   - Для domestic рейсов: тариф в UZS, price_override = сумма TLF в UZS
     *   - Для international: тариф в USD, price_override = сумма TLF в USD
     *   - quantity = ставка из тарифа (0.10, 0.20, 0.25) или ставка×часы для OVER_PARK
     */
    public function buildFromFlightCharges(int $actId, int $flightId): array
    {
        $act = $this->actModel->find($actId);
        if (!$act) return ['success' => false, 'error' => 'Act not found.'];
        if (in_array($act['status'], ['approved', 'cancelled', 'exported'], true)) {
            return ['success' => false, 'error' => 'Cannot modify act with status: ' . $act['status']];
        }

        $charges = $this->chargeModel->getUnconsumedByFlight($flightId);
        if (empty($charges)) {
            return ['success' => true, 'added' => 0, 'skipped' => [],
                    'warnings' => ['Нет новых услуг от служб — все уже включены в акт или ни одна служба ещё не добавляла услуги.']];
        }

        $percentCodes = ['NIGHT', 'AV_SEC', 'OVER_PARK'];
        $normal  = array_filter($charges, fn($c) => !in_array($c['service_code'], $percentCodes, true));
        $percent = array_filter($charges, fn($c) =>  in_array($c['service_code'], $percentCodes, true));

        $added       = [];
        $skipped     = [];
        $warnings    = [];
        $consumedIds = [];  // Только успешно добавленные в act_items
        $tlfAmount   = null;

        // ─── Шаг 1: Обычные услуги (включая TLF) ─────────────────
        // ИСПРАВЛЕНО (14.08.2026, раунд 4): найден и исправлен баг с
        // дублированием строк акта при повторной сборке. addActItem() всегда
        // ДОБАВЛЯЕТ новую строку и никогда не проверяет, нет ли уже строки по
        // этой же услуге — а существующая логика buildAct() при пересборке
        // "расконсервирует" (сбрасывает consumed_in_act_id) уже добавленные
        // авто-начисления, чтобы пересчитать их заново. В сумме: каждая
        // пересборка акта ДОБАВЛЯЛА ЕЩЁ ОДНУ строку по тем же услугам поверх
        // старой, вместо замены — например TLF/AV_SEC от AutoChargeService
        // (см. раунд 2, п.4) удваивались/утраивались при каждой пересборке
        // (сообщено пользователем: TLF показывал 48 вместо MTOW=24 — ровно
        // 2×24, две строки просуммировались в печатной форме). Теперь перед
        // добавлением строки удаляем её же предыдущую АВТОМАТИЧЕСКИ
        // добавленную версию (is_default=1, см. ActItem::deleteAutoByActAndService) —
        // ручные строки ЦОК (is_default=0, "+ Добавить услугу") не трогаем.
        //
        // ИЗМЕНЕНО (25.09.2026, раунд 58, по прямому указанию пользователя —
        // услугу можно оказать несколько раз на одном рейсе, повтор
        // отдельно оплачивается): deleteAutoByActAndService() чистит по
        // (act_id, service_id) — верно, пока у услуги была максимум ОДНА
        // заявка/начисление за рейс. Теперь при services.allow_repeat=1
        // charges от нескольких повторов ОДНОЙ услуги идут в этом же
        // foreach один за другим — вызов "на каждую заявку" удалял бы
        // строку, которую только что вставила предыдущая заявка ТОЙ ЖЕ
        // услуги в этом же проходе (было замечено при тестировании: две
        // заявки, две билинговые записи — но в акте оставалась только
        // последняя строка). Чистка нужна один раз на услугу за проход
        // (чтобы по-прежнему подчищать хвост от пересборки акта после
        // удаления/пересчёта старой записи), а не перед каждой заявкой —
        // отмечаем $serviceId во $cleanedServiceIds после первой чистки.
        // ИЗМЕНЕНО (09.11.2026, раунд 161 — найдено при разборе акта
        // 0020/XX-RWZ1115, см. claude/analysis-2026-11-09-round161-repeat-
        // -service-act-item-loss.md): описанная выше защита "чистим ОДИН
        // раз на услугу за проход" (раунд 58) решала задвоение внутри ОДНОГО
        // прохода, но не учитывала, что buildFromFlightCharges() вызывается
        // ПОВТОРНО, отдельными проходами, по мере того как супервайзер
        // подтверждает рейс (ActController::resync() ("Обновить"),
        // ActSignatureController::signForm() — оба вызывают этот метод
        // НАПРЯМУЮ, без предварительного сброса consumed_in_act_id, в
        // отличие от полной пересборки FlightController::buildAct()).
        // Если повторяемая услуга (services.allow_repeat=1 — BRIDGE,
        // PUSHBACK, GPU, STEPLADDER, AIRCOND, TECH_SVC) получает ВТОРОЙ
        // экземпляр ПОЗЖЕ, отдельным проходом (а не вместе с первым в одном
        // проходе, как в тесте round58) — старая очистка "по услуге"
        // стирала и уже оплаченную строку ПЕРВОГО экземпляра тоже, а
        // вставлялся заново только второй: первый экземпляр молча пропадал
        // из акта (не задваивался — просто терялся). Теперь перед чисткой
        // проверяем: есть ли для этой услуги на этом рейсе хоть одна запись,
        // УЖЕ погашенная именно в этот акт РАНЕЕ (другим, более ранним
        // проходом) — если да, значит стирать нечего заново вставлять, в
        // акте уже законно есть строка по более раннему экземпляру, чистку
        // пропускаем и просто ДОБАВЛЯЕМ строку нового экземпляра рядом.
        // Если же таких записей нет (первая обработка этой услуги по этому
        // акту, либо оба экземпляра пришли вместе в этом же проходе, как и
        // раньше) — чистим как прежде, это по-прежнему страхует от дублей
        // при случайном повторном вызове/зависших "осиротевших" строк.
        $cleanedServiceIds = [];
        foreach ($normal as $charge) {
            try {
                $serviceIdForCharge = (int)$charge['service_id'];
                if (!isset($cleanedServiceIds[$serviceIdForCharge])) {
                    if (!$this->chargeModel->hasConsumedForActAndService($actId, $flightId, $serviceIdForCharge)) {
                        $this->actItemModel->deleteAutoByActAndService($actId, $serviceIdForCharge);
                    }
                    $cleanedServiceIds[$serviceIdForCharge] = true;
                }
                $result = $this->addActItem($actId, [
                    'service_id'       => (int)$charge['service_id'],
                    'quantity'         => (float)$charge['quantity'],
                    'calculation_note' => $charge['note'] ?: null,
                    'is_default'       => 1,
                ]);
                if ($result['success']) {
                    $added[]       = $charge['service_name'];
                    $consumedIds[] = (int)$charge['id'];  // ← только при успехе
                    if ($charge['service_code'] === 'TLF') {
                        $tlfAmount = $result['amount_without_vat'];
                    }
                } else {
                    $skipped[] = $charge['service_name'] . ': ' . ($result['error'] ?? 'неизвестная ошибка');
                    error_log("buildFromFlightCharges: addActItem failed for charge {$charge['id']}: " . ($result['error'] ?? ''));
                }
            } catch (\Throwable $e) {
                $skipped[] = $charge['service_name'] . ': исключение — ' . $e->getMessage();
                error_log("buildFromFlightCharges exception for charge {$charge['id']}: " . $e->getMessage());
            }
        }

        // ─── TLF из уже существующих строк акта (если TLF добавлялся ранее) ──
        if ($tlfAmount === null && !empty($percent)) {
            try {
                $existing = $this->actItemModel->getByAct($actId);
                foreach ($existing as $item) {
                    if (($item['service_code'] ?? '') === 'TLF') {
                        $tlfAmount = (float)$item['amount_without_vat'];
                        break;
                    }
                }
            } catch (\Throwable $e) {
                error_log("buildFromFlightCharges: cannot fetch existing TLF: " . $e->getMessage());
            }
        }

        // ─── TLF из связанного акта ПРИЛЁТА (раунд 38, п.4) ──────
        // ДОБАВЛЕНО (11.09.2026): по ответу пользователя ("Перенести
        // OVER_PARK на акт вылета") OVER_PARK теперь начисляется
        // AutoChargeService'ом на flight_service_charges рейса ВЫЛЕТА (см.
        // AutoChargeService::resolveDepartureFlightId()), хотя сама услуга
        // TLF (ценовая база для % услуг) по правилу раунда 21 всегда и
        // только на акте ПРИЛЁТА — то есть на акте вылета своей строки TLF
        // никогда не будет, оба блока выше (шаг 1 и предыдущий фоллбэк)
        // всегда вернут null для TLF на акте вылета. Ищем сумму TLF в акте
        // СВЯЗАННОГО рейса прилёта (flights.linked_flight_id, раунд 33).
        // Если акта прилёта ещё нет, или он ещё не содержит строку TLF
        // (обычно уже содержит — TLF начисляется при самой первой сборке
        // акта прилёта, задолго до акта стоянки/вылета), OVER_PARK будет
        // пропущен ниже с понятным предупреждением, а не тихой ошибкой.
        if ($tlfAmount === null && !empty($percent) && ($act['operation_type'] ?? '') === 'departure') {
            try {
                $flight = $this->flightModel->find($flightId);
                $linkedFlightId = (int)($flight['linked_flight_id'] ?? 0);
                if ($linkedFlightId > 0) {
                    $linkedAct = $this->actModel->findByFlightId($linkedFlightId);
                    if ($linkedAct) {
                        foreach ($this->actItemModel->getByAct((int)$linkedAct['id']) as $item) {
                            if (($item['service_code'] ?? '') === 'TLF') {
                                $tlfAmount = (float)$item['amount_without_vat'];
                                break;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("buildFromFlightCharges: cannot fetch TLF from linked arrival act: " . $e->getMessage());
            }
        }

        // ─── Шаг 2: % услуги (нужна сумма TLF как ценовая база) ──
        foreach ($percent as $charge) {
            if ($tlfAmount === null) {
                // ИЗМЕНЕНО (11.09.2026, раунд 38, п.4): на акте вылета TLF
                // никогда не бывает своей строкой (см. фоллбэк на связанный
                // акт прилёта чуть выше) — если и он не нашёл сумму, чаще
                // всего причина не техническая ошибка, а рейс прилёта ещё не
                // связан с этим рейсом вылета (flights.linked_flight_id) или
                // у акта прилёта ещё нет собственной строки TLF. Сообщение
                // отличается по типу акта, чтобы ЦОК понимал, что делать.
                $skipped[] = ($act['operation_type'] ?? '') === 'departure'
                    ? $charge['service_name'] . ': не найдена сумма TLF — рейс вылета не связан с рейсом '
                        . 'прилёта (или в акте прилёта ещё нет строки TLF). Свяжите рейсы '
                        . '(карточка рейса → «Связать с рейсом прилёта») и убедитесь, что акт прилёта собран.'
                    : $charge['service_name'] . ': нет строки TLF в акте — невозможно рассчитать % услугу.';
                continue;
            }

            try {
                $tariff = $this->tariffModel->getActiveForService(
                    (int)$charge['service_id'],
                    $this->tariffPriceListKey($act),
                    $act['operation_type'],
                    $act['act_date']
                );
                if (!$tariff) {
                    $skipped[] = $charge['service_name'] . ': нет активного тарифа для '
                        . $this->tariffPriceListKey($act) . '/' . $act['operation_type'] . '.';
                    continue;
                }

                $rate = (float)$tariff['price'];

                // ИЗМЕНЕНО (17.09.2026, раунд 44, уточнение по прямому
                // указанию пользователя): раньше для OVER_PARK в «quantity»
                // писалось ставка×сутки (напр. 0.25 для 1 суток при ставке
                // 25%) — на печатной форме это выглядело как «Day 0.25», что
                // читается как «четверть суток» и вводит в заблуждение,
                // хотя сумма считалась верно. Пользователь сверил акт вручную
                // и подтвердил, что сумма правильная, но попросил показывать
                // количество суток по-человечески — целым числом (округление
                // вверх в AutoChargeService::processParkingAct() и так уже
                // гарантирует, что $charge['quantity'] здесь ВСЕГДА целое
                // число суток, дробным оно быть не может).
                // Теперь для OVER_PARK: quantity = само число суток (как
                // есть, без домножения на ставку), а ставка «переезжает» в
                // price_override — база для расчёта суммы становится не
                // «сумма TLF», а «сумма TLF × ставка» (то есть цена за ОДНИ
                // сутки сверхнормативной стоянки). Итоговая сумма
                // (quantity × price) не меняется, меняется только то, что
                // из этих двух множителей означает «количество», а что
                // «цена» — для AV_SEC/NIGHT ничего не меняется (там
                // quantity была и остаётся самой ставкой, т.к. они не
                // измеряются в сутках).
                $isOverPark    = $charge['service_code'] === 'OVER_PARK';
                $qty           = $isOverPark ? (float)$charge['quantity'] : $rate;
                $priceOverride = $isOverPark ? round($rate * $tlfAmount, 4) : $tlfAmount;

                // См. п.4 раунда 4 выше (Шаг 1) — та же дедупликация для %
                // услуг (AV_SEC/NIGHT/OVER_PARK), иначе они тоже удваивались
                // при пересборке акта (AV_SEC на скриншоте пользователя
                // показал 0.20 вместо 0.10 — ровно 2×0.10).
                $this->actItemModel->deleteAutoByActAndService($actId, (int)$charge['service_id']);
                $result = $this->addActItem($actId, [
                    'service_id'       => (int)$charge['service_id'],
                    'quantity'         => $qty,
                    'price_override'   => $priceOverride,
                    'calculation_note' => $charge['note'] ?: null,
                    'is_default'       => 1,
                ]);
                if ($result['success']) {
                    $added[]       = $charge['service_name'];
                    $consumedIds[] = (int)$charge['id'];  // ← только при успехе
                } else {
                    $skipped[] = $charge['service_name'] . ': ' . ($result['error'] ?? 'неизвестная ошибка');
                    error_log("buildFromFlightCharges: % service addActItem failed for charge {$charge['id']}: " . ($result['error'] ?? ''));
                }
            } catch (\Throwable $e) {
                $skipped[] = $charge['service_name'] . ': исключение — ' . $e->getMessage();
                error_log("buildFromFlightCharges exception for % charge {$charge['id']}: " . $e->getMessage());
            }
        }

        // ─── Шаг 3: помечаем consumed ТОЛЬКО успешно добавленные ──
        if (!empty($consumedIds)) {
            $this->chargeModel->markConsumed($consumedIds, $actId);
        }

        // Формируем детальный отчёт для ЦОК
        $report = [
            'added'   => array_values($added),
            'skipped' => array_values($skipped),
        ];
        error_log('buildFromFlightCharges result: ' . json_encode($report));

        // ДОБАВЛЕНО (10.09.2026, раунд 35): по прямому указанию пользователя —
        // подписи (супервайзер/представитель) должны относиться ИМЕННО к
        // тому составу услуг, который в итоге утверждается, а не к более
        // ранней версии акта. Если состав акта реально изменился (что-то
        // добавилось — $added не пусто) и на акте УЖЕ стояла хотя бы одна
        // подпись (поставленная до этого изменения, по $act, прочитанному в
        // самом начале метода — то есть ДО текущей пересборки), обе подписи
        // аннулируются: акт формально снова становится неподписанным, и
        // нужно подписать заново уже актуальную версию. Иначе supervisor
        // подписал бы одну сумму/состав, а утвердили бы другую — ровно тот
        // класс проблемы, который привёл к пропуску услуг в актах 0006/0007.
        $signaturesReset = false;
        if (!empty($added) && (!empty($act['supervisor_signature_file_id']) || !empty($act['airline_rep_signature_file_id']))) {
            $this->actModel->update($actId, [
                'supervisor_signature_file_id'  => null,
                'airline_rep_signature_file_id' => null,
            ]);
            $signaturesReset = true;
            ActivityLogger::log(
                'act', $actId, 'reset_signatures_on_rebuild', 'success',
                'Подписи сброшены — состав акта изменился после подписания (добавлено: ' . implode(', ', $added) . ')',
                ['supervisor_signature_file_id' => $act['supervisor_signature_file_id'], 'airline_rep_signature_file_id' => $act['airline_rep_signature_file_id']],
                ['supervisor_signature_file_id' => null, 'airline_rep_signature_file_id' => null]
            );
        }

        return [
            'success'  => true,
            'added'    => count($added),
            'added_names' => array_values($added),
            'skipped'  => array_values($skipped),
            'warnings' => array_values($warnings),
            'signatures_reset' => $signaturesReset,
        ];
    }

    public function updateActItem(int $itemId, array $data): array
    {
        $item = $this->actItemModel->find($itemId);
        if (!$item) return ['success' => false, 'error' => 'Act item not found.'];

        $act = $this->actModel->find((int)$item['act_id']);
        if (!$act) return ['success' => false, 'error' => 'Parent act not found.'];
        if (in_array($act['status'], ['approved', 'cancelled', 'exported'], true)) {
            return ['success' => false, 'error' => 'Cannot modify act with status: ' . $act['status']];
        }

        $qty = (float)($data['quantity'] ?? $item['quantity']);

        // ИСПРАВЛЕНО (17.08.2026, раунд 9): раньше здесь брался РЕАЛЬНЫЙ курс
        // ЦБ (acts.cb_rate — десятки тысяч сум за доллар) и подставлялся в
        // calculate() как множитель суммы строки — то же самое нарушение
        // правила из addActItem() (см. её комментарий выше по файлу и в
        // докблоке класса): unit_price уже хранится в валюте акта, курс ЦБ
        // в формуле участвовать не должен. Не проявлялось на текущих данных
        // только потому, что все акты в базе international (cb_rate=NULL) —
        // на первом же domestic-акте с реальным курсом редактирование любой
        // строки (или просто пересохранение курса ЦБ на форме акта, см.
        // recalculateAllItems() ниже) задрало бы сумму в тысячи раз. Теперь
        // приведено к тому же правилу, что и в addActItem() — cbRate = 1.0
        // в формуле, значение сохраняется в cb_rate строки только для
        // информационного отображения (как и при первичной сборке акта).
        $cbRate = 1.0;

        // ВАЖНО: цена берётся из зафиксированного снимка акта (unit_price),
        // а НЕ из текущего тарифа. Это обеспечивает неизменность цен в актах
        // при последующем редактировании тарифов (п. tariff versioning).
        // Тариф используется только для создания НОВЫХ строк акта.
        $priceUsd = (float)$item['unit_price']; // зафиксированная цена

        $service = $this->serviceModel->findWithDetails((int)$item['service_id']);
        $vatRate = $service
            ? $this->resolveVatRate($service, $act['route_type'], (float)$item['vat_rate'])
            : (float)$item['vat_rate'];

        [$withoutVat, $vatAmt, $withVat] = $this->calculate($qty, $priceUsd, $cbRate, $vatRate);

        $updateData = [
            'quantity' => $qty, 'unit_price' => $priceUsd, 'cb_rate' => $cbRate,
            'amount_without_vat' => $withoutVat, 'vat_rate' => $vatRate,
            'vat_amount' => $vatAmt, 'amount_with_vat' => $withVat,
            'calculation_note' => !empty($data['calculation_note']) ? trim($data['calculation_note']) : $item['calculation_note'],
        ];

        $this->actItemModel->update($itemId, $updateData);
        $this->calcModel->deleteByItem($itemId);
        $this->saveCalcLog($itemId, $qty, $priceUsd, $cbRate, $vatRate, $withoutVat, $vatAmt, $withVat, $act['route_type']);
        $this->recalculateActTotals((int)$item['act_id']);

        ActivityLogger::log('act_item', $itemId, 'update_act_item', 'success', "Updated item #{$item['line_no']}", $item, $updateData);
        return ['success' => true];
    }

    public function deleteActItem(int $itemId): array
    {
        $item = $this->actItemModel->find($itemId);
        if (!$item) return ['success' => false, 'error' => 'Act item not found.'];

        $act = $this->actModel->find((int)$item['act_id']);
        if ($act && in_array($act['status'], ['approved', 'cancelled', 'exported'], true)) {
            return ['success' => false, 'error' => 'Cannot modify act with status: ' . $act['status']];
        }

        $this->calcModel->deleteByItem($itemId);
        $this->actItemModel->delete($itemId);
        $this->recalculateActTotals((int)$item['act_id']);

        ActivityLogger::log('act_item', $itemId, 'delete_act_item', 'success', "Deleted item #{$item['line_no']}", $item);
        return ['success' => true];
    }

    /**
     * ДОБАВЛЕНО (аудит 09.07.2026, п.4): при утверждении акта можно зафиксировать
     * ФИО/должность представителя авиакомпании, который подписал акт с их стороны —
     * раньше это существовало только на бумаге (подпись на скане), в системе
     * никак не отражалось.
     */
    public function changeStatus(
        int $id,
        string $newStatus,
        ?string $reason = null,
        ?int $scanFileId = null,
        ?string $airlineRepName = null,
        ?string $airlineRepPosition = null
    ): array {
        $act = $this->actModel->find($id);
        if (!$act) return ['success' => false, 'error' => 'Act not found.'];

        if (!in_array($newStatus, $this->getAllowedTransitions($act['status']), true)) {
            return ['success' => false, 'error' => "Cannot change status from '{$act['status']}' to '{$newStatus}'."];
        }

        $numberResult = null; // заполняется ниже только при переходе в 'approved' (раунд 35)

        // ДОБАВЛЕНО (05.08.2026): подписи с планшета — равноценная замена
        // скана. Если обе подписи (супервайзер + представитель авиакомпании)
        // уже сохранены, скан загружать не обязательно.
        // ИЗМЕНЕНО (21.09.2026, раунд 49, п.4): раньше здесь заканчивалось
        // "ЦОК всё равно нажимает 'Утвердить' сам — подписи не утверждают
        // акт автоматически". С этого раунда ActSignatureController::
        // saveSignature() САМ вызывает changeStatus(..., 'approved') сразу
        // после второй подписи (если нет несинхронизированных расчётов —
        // см. код UNSYNCED_CHARGES ниже), так что этот метод по-прежнему
        // ничего не знает про "кто вызвал" — вызов может прийти как от
        // ручного нажатия ЦОК "Утвердить", так и автоматически из
        // saveSignature(), логика проверок одна и та же в обоих случаях.
        $hasBothSignatures = !empty($act['supervisor_signature_file_id'])
            && !empty($act['airline_rep_signature_file_id']);

        if ($newStatus === 'approved' && !$this->actFileModel->hasScanFile($id) && !$scanFileId && !$hasBothSignatures) {
            return ['success' => false, 'error' => 'Scan required.', 'code' => 'SCAN_REQUIRED'];
        }

        // ДОБАВЛЕНО (10.09.2026, раунд 34): найден реальный разрыв — "Утвердить"
        // (changeStatus → approved) никогда не проверял, остались ли по рейсу
        // уже подтверждённые, но ещё не внесённые в акт услуги
        // (flight_service_charges с consumed_in_act_id IS NULL). Раньше это
        // подтягивало только "Обновить" (resync) — approve() лишь менял
        // статус. В реальном случае (акты 0006/0007 от 09.09.2026) супервайзер
        // подтвердил "Лидирование ВС" на вылет ПОСЛЕ первичной сборки акта —
        // акт утвердили, ни разу не нажав "Обновить" между сборкой и
        // утверждением, и услуга навсегда осталась вне акта (после approved
        // акт уже не редактируется, а обратной транзакции 'approved'→'draft'
        // не существует). Чтобы это не повторялось — блокируем утверждение,
        // если по рейсу остались неучтённые начисления.
        //
        // ВАЖНО — почему здесь НЕ вызывается пересчёт обязательных услуг
        // (AutoChargeService::processFlightMandatoryCharges/processParkingAct),
        // хотя в resync() он есть: проверено тестом (round34_approve_guard_test.php),
        // что FlightServiceCharge::deleteAutoChargesForFlight() при пересчёте
        // удаляет уже ПОГАШЕННЫЕ (consumed_in_act_id заполнен) авто-записи,
        // если акт ещё draft — это осознанное поведение ради корректной
        // пересборки после повторной подачи акта стоянки (см. докблок метода).
        // Но если вызвать пересчёт ЗДЕСЬ, не сопровождая его немедленным
        // buildFromFlightCharges() (мы сознательно этого не делаем — см. ниже),
        // он каждый раз заново "отвязывает" уже добавленные в акт обязательные
        // строки от их charge-записей, создавая новые НЕПОГАШЕННЫЕ дубли —
        // и утверждение блокировалось бы БЕСКОНЕЧНО даже после честного
        // "Обновить". Поэтому здесь — только ЧТЕНИЕ уже существующего
        // состояния, без побочных эффектов; сам пересчёт обязательных услуг
        // выполняется исключительно через явное "Обновить" (resync), как и
        // раньше — эта проверка лишь не даёт утвердить акт, если такое
        // "Обновить" по факту так и не было нажато перед подтверждением.
        // Мы также намеренно не подтягиваем найденные начисления сюда
        // автоматически и не меняем итоговую сумму прямо в момент
        // утверждения: к этому моменту акт уже мог быть распечатан/подписан
        // с уже показанной суммой, и молчаливая смена суммы ПОСЛЕ того как
        // её увидели/подписали — это отдельное решение, которое должен
        // принимать ЦОК осознанно, нажав "Обновить" и увидев новую сумму до
        // утверждения, а не мы за него.
        if ($newStatus === 'approved') {
            $flightId = (int)$act['flight_id'];
            $unconsumed = $this->chargeModel->getUnconsumedByFlight($flightId);
            if (!empty($unconsumed)) {
                $names = array_map(
                    static fn($c) => $c['service_name'] . ' (×' . rtrim(rtrim((string)$c['quantity'], '0'), '.') . ' ' . $c['unit_name'] . ')',
                    $unconsumed
                );
                return [
                    'success' => false,
                    'code'    => 'UNSYNCED_CHARGES',
                    'error'   => 'В акт не внесены уже подтверждённые услуги — сначала нажмите «Обновить» на странице акта. Не внесено: '
                        . implode('; ', $names) . '.',
                ];
            }

            // ДОБАВЛЕНО (28.09.2026, раунд 60): решение главного нерешённого
            // технического риска раунда 54 — проверка выше (getUnconsumedByFlight)
            // находит только УЖЕ СУЩЕСТВУЮЩИЕ, но не внесённые в акт записи.
            // Если обязательный сбор вообще не был посчитан (строки в
            // flight_service_charges просто нет — например TLF/AV_SEC на
            // рейсе прилёта без указанного MTOW), предыдущая проверка её не
            // видит. findMandatoryChargeGaps() — чисто читающая проверка (см.
            // её докблок в AutoChargeService), НЕ пересчитывает и не меняет
            // ничего — только сообщает, если чего-то из обязательного не
            // хватает, и просит явно нажать «Обновить» перед утверждением.
            $mandatoryGaps = $this->autoChargeService->findMandatoryChargeGaps($flightId);
            if (!empty($mandatoryGaps)) {
                $gapNames = array_map(
                    static fn($g) => $g['service_name'] . ' (' . $g['reason'] . ')',
                    $mandatoryGaps
                );
                return [
                    'success' => false,
                    'code'    => 'MANDATORY_CHARGE_GAP',
                    'error'   => 'По рейсу не рассчитаны обязательные сборы — сначала проверьте исходные данные '
                        . '(MTOW, манифест, подтверждение услуг) и нажмите «Обновить» на странице акта. Не рассчитано: '
                        . implode('; ', $gapNames) . '.',
                ];
            }

            // ДОБАВЛЕНО (22.10.2026, раунд 113, по прямому указанию пользователя
            // — найден реальный случай: акт рейса ВЫЛЕТА (0091/XX-DXT9686)
            // был утверждён, пока у связанного рейса ПРИЛЁТА той же ротации
            // (тот же борт, linked_flight_id) акт вообще не существовал —
            // манифест на прилёт не был подан, акт прилёта невозможно было
            // даже сформировать, а утверждение акта вылета это никак не
            // проверяло. Сбор за взлёт-посадку (TLF) начисляется
            // ИСКЛЮЧИТЕЛЬНО на акте прилёта (см. докблок
            // buildFromFlightCharges() выше) — без акта прилёта TLF и уже
            // подтверждённые службами услуги по прилёту остаются полностью
            // невыставленными бесконечно, а акт вылета при этом спокойно
            // проходит весь цикл до утверждения. Пользователь: "надо
            // как-то закрыть эту дырку".
            //
            // Правило: если утверждаемый акт — это акт ВЫЛЕТА, и у его
            // рейса есть связанный рейс ПРИЛЁТА той же ротации — у этого
            // рейса прилёта должен существовать хотя бы ЧЕРНОВОЙ акт (сам
            // факт его существования означает, что манифест был подан и
            // TLF уже посчитан — см. ActController::buildAct() →
            // getActFormationBlockers()/processParkingAct()). Обратное
            // направление (утверждение акта ПРИЛЁТА без акта вылета)
            // намеренно НЕ проверяется — это нормальная ситуация, вылет
            // ротации мог ещё не состояться. Защищает и обычное
            // "Утвердить", и автоматическое утверждение сразу после второй
            // подписи (ActSignatureController::saveSignature(), раунд 49) —
            // оба пути идут через этот же метод.
            if (($act['operation_type'] ?? '') === 'departure') {
                $flightRow = $this->flightModel->find($flightId);
                $linkedId  = (int)($flightRow['linked_flight_id'] ?? 0);
                if ($linkedId > 0) {
                    $linkedFlight = $this->flightModel->find($linkedId);
                    if ($linkedFlight && ($linkedFlight['operation_type'] ?? '') === 'arrival') {
                        $linkedArrivalAct = $this->actModel->findByFlightId($linkedId);
                        if (!$linkedArrivalAct) {
                            return [
                                'success' => false,
                                'code'    => 'LINKED_ARRIVAL_ACT_MISSING',
                                'error'   => 'Нельзя утвердить акт вылета — по связанному рейсу прилёта той же '
                                    . 'ротации (рейс #' . $linkedId . ') акт ещё не сформирован вообще (сбор за '
                                    . 'взлёт-посадку и уже подтверждённые службами услуги прилёта останутся '
                                    . 'невыставленными). Сначала откройте рейс прилёта и нажмите «Сформировать '
                                    . 'Форму А» (для этого может потребоваться сначала подать манифест на прилёт).',
                            ];
                        }
                    }
                }
            }

            // ДОБАВЛЕНО (10.09.2026, раунд 35): обычно окончательный номер
            // акту уже присвоен раньше — в момент, когда собрались обе
            // подписи (см. ActSignatureController::saveSignature() →
            // ensureActNumberFinalized()). Но если утверждают по СКАНУ
            // бумажного акта (без подписей через планшет) — номер до сих
            // пор мог оставаться заглушкой. Присваиваем его здесь как
            // последний рубеж — утверждённый акт обязан иметь настоящий
            // номер. Идемпотентно, если уже присвоен — ничего не делает.
            $numberResult = $this->ensureActNumberFinalized($id);
            if (!$numberResult['success']) {
                return ['success' => false, 'error' => 'Не удалось присвоить номер акта: ' . ($numberResult['error'] ?? 'неизвестная ошибка')];
            }
        }

        $userId     = Auth::id();
        $sessionId  = Auth::sessionDbId();
        $updateData = ['status' => $newStatus];

        if ($newStatus === 'approved') {
            $updateData['approved_by_user_id'] = $userId;
            $updateData['approved_at']         = date('Y-m-d H:i:s');
            if ($airlineRepName !== null && $airlineRepName !== '') {
                $updateData['airline_rep_name'] = trim($airlineRepName);
            }
            if ($airlineRepPosition !== null && $airlineRepPosition !== '') {
                $updateData['airline_rep_position'] = trim($airlineRepPosition);
            }
        }

        $this->actModel->update($id, $updateData);
        $this->historyModel->record($id, $act['status'], $newStatus, $userId, $sessionId, $reason);

        // Раунд 35: номер мог только что смениться с заглушки на окончательный
        // (ensureActNumberFinalized() выше) — берём актуальный для лога.
        $logActNumber = $numberResult['act_number'] ?? null ?: $act['act_number'];
        ActivityLogger::log('act', $id, 'change_act_status', 'success',
            "Act '{$logActNumber}': '{$act['status']}' → '{$newStatus}'",
            ['status' => $act['status']], ['status' => $newStatus]);

        // ДОБАВЛЕНО (18.09.2026, раунд 46, п.2): автоматическая копия акта
        // представителю/сотруднику хендлера по email при утверждении. Мягкий
        // отказ, как и sendPush() у NotificationService — если почта не
        // настроена (config/mail.php) или у подписанта нет email в
        // справочнике хендлеров, просто ничего не отправляем, утверждение
        // акта при этом ничем не рискует.
        if ($newStatus === 'approved') {
            try {
                $this->sendApprovalCopyEmail($id);
            } catch (\Throwable $e) {
                error_log('ActService::changeStatus sendApprovalCopyEmail error: ' . $e->getMessage());
            }
        }

        return ['success' => true];
    }

    /**
     * ДОБАВЛЕНО (18.09.2026, раунд 46, п.2): см. changeStatus() выше.
     * Получатель — сотрудник хендлера, выбранный на планшете как подписант
     * (acts.airline_rep_employee_id), и только если для него указан email
     * в справочнике (HandlerController). Свободный текстовый ввод ФИО без
     * выбора из справочника email не даёт — отправлять в этом случае
     * некому, это ожидаемо, а не ошибка (см. анализ раунда 46).
     */
    private function sendApprovalCopyEmail(int $actId): void
    {
        $mailer = new MailerService();
        if (!$mailer->isConfigured()) {
            return;
        }

        $act = $this->actModel->findWithDetails($actId);
        if (!$act || empty($act['airline_rep_employee_id'])) {
            return;
        }

        $employee = (new HandlerEmployee())->find((int)$act['airline_rep_employee_id']);
        if (!$employee || empty($employee['email'])) {
            return;
        }

        $appUrl     = rtrim($_ENV['APP_URL'] ?? '', '/');
        $verifyCode = function_exists('act_verify_code')
            ? act_verify_code((string)$act['act_number'], (string)$act['act_date'], (float)$act['total_amount_with_vat'])
            : null;
        $verifyUrl  = ($appUrl !== '' && $verifyCode) ? $appUrl . '/verify/' . $verifyCode : null;
        $currency   = ($act['settlement_currency_id'] == 2) ? 'USD' : ($act['currency_code'] ?? '');

        $subject = 'Акт № ' . $act['act_number'] . ' утверждён — рейс ' . $act['flight_number'];
        $scan    = $this->getScanAttachment($actId);

        $html  = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;">';
        $html .= '<p>Здравствуйте' . (!empty($employee['full_name']) ? ', ' . htmlspecialchars($employee['full_name']) : '') . '!</p>';
        $html .= '<p>Акт № <b>' . htmlspecialchars((string)$act['act_number']) . '</b> по рейсу '
            . htmlspecialchars((string)$act['flight_number']) . ' от ' . format_date($act['act_date']) . ' утверждён.</p>';
        $html .= '<p>Авиакомпания: ' . htmlspecialchars((string)$act['airline_name']) . '<br>';
        $html .= 'Сумма: <b>' . number_format((float)$act['total_amount_with_vat'], 2) . ' ' . htmlspecialchars($currency) . '</b></p>';
        if ($verifyUrl) {
            $html .= '<p>Проверить акт: <a href="' . htmlspecialchars($verifyUrl) . '">' . htmlspecialchars($verifyUrl) . '</a></p>';
        }
        $html .= '<p style="color:#777;font-size:12px;">Письмо отправлено автоматически системой Forma A. '
            . ($scan ? 'Скан подписанного акта — во вложении.' : 'Полная печатная копия акта доступна в системе.')
            . '</p></div>';

        $attachments = $scan ? [$scan] : [];

        // ДОБАВЛЕНО (21.09.2026, раунд 49, п.5, по прямому указанию
        // пользователя): "в указанную электронную почту будут уходить
        // электронные формы А в формате пдф". Пока функция целиком под
        // флагом PORTAL_ENABLED (config/portal.php, по умолчанию выключен —
        // "в тестовом режиме будет реализовываться, но пока не действовать"):
        // при выключенном флаге поведение этого метода не меняется ни на
        // бит относительно раунда 46 (см. анализ round46). При включённом —
        // дополнительно генерируем настоящий PDF (ActPdfService, переиспользует
        // ту же вёрстку, что и печатная форма/portal-скачивание) и вкладываем
        // его в то же письмо, наравне со сканом (если скана нет — это
        // единственное вложение).
        $portalConfig = require BASE_PATH . '/config/portal.php';
        $pdfTmpPath   = null;
        if (!empty($portalConfig['enabled'])) {
            $pdf = (new ActPdfService())->generate($actId);
            if ($pdf !== null) {
                $pdfTmpPath = sys_get_temp_dir() . '/act_' . $actId . '_' . bin2hex(random_bytes(4)) . '.pdf';
                if (@file_put_contents($pdfTmpPath, $pdf) !== false) {
                    $attachments[] = [
                        'path' => $pdfTmpPath,
                        'name' => 'act_' . preg_replace('/[^\w.-]+/u', '_', (string)$act['act_number']) . '.pdf',
                        'mime' => 'application/pdf',
                    ];
                } else {
                    $pdfTmpPath = null;
                }
            }
        }

        $mailer->send($employee['email'], (string)$employee['full_name'], $subject, $html, $attachments);

        if ($pdfTmpPath !== null && is_file($pdfTmpPath)) {
            @unlink($pdfTmpPath);
        }
    }

    /** Последний загруженный файл акта (скан/PDF), если есть — для вложения в письмо. */
    private function getScanAttachment(int $actId): ?array
    {
        $files = $this->actFileModel->getByAct($actId);
        foreach ($files as $f) {
            if (($f['upload_status'] ?? '') !== 'uploaded') continue;
            $config = require BASE_PATH . '/config/app.php';
            $path   = $config['upload']['storage_path'] . '/' . $f['storage_path'];
            if (is_file($path)) {
                return [
                    'path' => $path,
                    'name' => $f['original_name'] ?: 'act.pdf',
                    'mime' => $f['mime_type'] ?? 'application/octet-stream',
                ];
            }
        }
        return null;
    }

    public function approveAct(int $id, ?int $scanFileId = null, ?string $reason = null): array
    {
        return $this->changeStatus($id, 'approved', $reason ?? 'Approved', $scanFileId);
    }

    public function cancelAct(int $id, ?string $reason = null): array
    {
        return $this->changeStatus($id, 'cancelled', $reason ?? 'Cancelled');
    }

    // ── Private helpers ─────────────────────────────────────

    /**
     * Формирует номер акта в формате: 0001/HY-0001
     * где 0001 - порядковый номер, HY - IATA авиакомпании, 0001 - номер рейса
     */
    /**
     * ДОБАВЛЕНО (10.09.2026, раунд 35): присваивает окончательный,
     * "сгорающий" порядковый номер акта (DocumentSequenceService::next) —
     * ровно один раз, в момент, когда акт действительно СТАЛ актом (обе
     * подписи стоят, либо утверждают по скану). До этого у акта временный
     * номер-заглушка ('PRELIM-FL{flight_id}', см. createAct()) и
     * number_finalized=0. Идемпотентно — повторный вызов на уже
     * финализированном акте ничего не делает и не тратит номер впустую.
     */
    public function ensureActNumberFinalized(int $actId): array
    {
        $act = $this->actModel->find($actId);
        if (!$act) {
            return ['success' => false, 'error' => 'Act not found.'];
        }
        if ((int)($act['number_finalized'] ?? 0) === 1) {
            return ['success' => true, 'changed' => false, 'act_number' => $act['act_number']];
        }

        try {
            $seqNumber = $this->seqService->next('act');
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Failed to generate act number: ' . $e->getMessage()];
        }

        $actNumber = $this->buildActNumber($seqNumber, (int)$act['flight_id']);
        $this->actModel->update($actId, [
            'act_number'       => $actNumber,
            'number_finalized' => 1,
        ]);

        ActivityLogger::log(
            'act', $actId, 'finalize_act_number', 'success',
            "Акту присвоен окончательный номер '{$actNumber}' (было: '{$act['act_number']}')",
            ['act_number' => $act['act_number'], 'number_finalized' => 0],
            ['act_number' => $actNumber, 'number_finalized' => 1]
        );

        return ['success' => true, 'changed' => true, 'act_number' => $actNumber];
    }

    private function buildActNumber(string $seqNumber, int $flightId): string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT f.flight_number, al.iata_code
                 FROM flights f
                 JOIN airlines al ON al.id = f.airline_id
                 WHERE f.id = ? LIMIT 1"
            );
            $stmt->execute([$flightId]);
            $flight = $stmt->fetch();
            if ($flight) {
                $iata   = strtoupper($flight['iata_code'] ?? 'XX');
                $fnum   = strtoupper($flight['flight_number'] ?? '0000');
                // Берём только порядковую часть из seqNumber (последние 4 цифры)
                preg_match('/(\d{4})$/', $seqNumber, $m);
                $seq = $m[1] ?? $seqNumber;
                return "{$seq}/{$iata}-{$fnum}";
            }
        } catch (\Throwable $e) {
            // fallback to plain sequence number
        }
        return $seqNumber;
    }

    /**
     * Возвращает currency_id национальной валюты (UZS).
     * Кэшируется в статическом свойстве для производительности.
     */
    private static ?int $nationalCurrencyIdCache = null;
    private function getNationalCurrencyId(): int
    {
        if (self::$nationalCurrencyIdCache === null) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT id FROM currencies WHERE is_national = 1 LIMIT 1"
                );
                $stmt->execute();
                self::$nationalCurrencyIdCache = (int)($stmt->fetchColumn() ?: 1);
            } catch (\Throwable $e) {
                self::$nationalCurrencyIdCache = 1; // UZS по умолчанию
            }
        }
        return self::$nationalCurrencyIdCache;
    }

    private function calculate(float $qty, float $priceUsd, float $cbRate, float $vatRate): array
    {
        $withoutVat = round($qty * $priceUsd * $cbRate, 2);
        $vatAmt     = round($withoutVat * ($vatRate / 100), 2);
        $withVat    = round($withoutVat + $vatAmt, 2);
        return [$withoutVat, $vatAmt, $withVat];
    }

    private function resolveVatRate(array $service, string $routeType, float $tariffVatRate): float
    {
        if ($routeType === 'international') return 0.0;
        if (!(bool)$service['vat_applicable_domestic']) return 0.0;
        return $tariffVatRate > 0 ? $tariffVatRate : 12.0;
    }

    /**
     * ИЗМЕНЕНО (15.10.2026, раунд 77, по прямому указанию пользователя):
     * раньше валюта акта жёстко зависела от route_type (domestic → всегда
     * UZS, international → что выбрал ЦОК вручную) — пользователь указал,
     * что это неверно: валюта зависит от РЕЗИДЕНТСТВА АВИАКОМПАНИИ, а не от
     * типа рейса. Иностранная авиакомпания на местном рейсе — всё равно в
     * долларах; местная авиакомпания на международном рейсе — всё равно в
     * сумах. НДС (resolveVatRate() ниже) по-прежнему зависит от route_type —
     * это отдельное, не связанное правило, не трогаем.
     *
     * airlines.is_resident (round 77) — явный флаг, не текстовое поле
     * country (оно у трети авиакомпаний пустое/ненадёжное). Не найдена
     * авиакомпания/рейс — считаем иностранной (безопасный default,
     * подтверждено пользователем), как и раньше для domestic ЦОК не мог
     * выбрать валюту — теперь аналогично нельзя выбрать вручную в обход
     * резидентства авиакомпании: $chosenId используется только как
     * аварийный fallback, если справочник валют не нашёл ни UZS, ни USD.
     */
    private function resolveSettlementCurrency(int $flightId, int $chosenId): int
    {
        $stmt = $this->db->prepare(
            "SELECT al.is_resident FROM flights f JOIN airlines al ON al.id = f.airline_id WHERE f.id = ? LIMIT 1"
        );
        $stmt->execute([$flightId]);
        $isResident = (bool)$stmt->fetchColumn();

        $code = $isResident ? 'UZS' : 'USD';
        $stmt = $this->db->prepare("SELECT id FROM `currencies` WHERE `code`=? LIMIT 1");
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
        return $chosenId;
    }

    /**
     * ДОБАВЛЕНО (15.10.2026, раунд 77, по прямому указанию пользователя —
     * "развязка тарифа"): до этого раунда подбор строки тарифа
     * (TariffVersion::getActiveForService) шёл по буквальному route_type
     * рейса ('domestic'/'international'), и это совпадало с валютой тарифа
     * ТОЛЬКО потому, что currency_id в tariff_versions был жёстко (100%,
     * без единого исключения на 86+86 строках) привязан к route_type.
     * Теперь валюта акта определяется резидентством авиакомпании (см.
     * resolveSettlementCurrency() выше), а не маршрутом — то есть domestic
     * рейс иностранной авиакомпании должен брать ДОЛЛАРОВЫЙ тариф, а не
     * тариф с route_type='domestic' (который в сумах). Если бы мы оставили
     * выбор тарифа по буквальному route_type, возник бы катастрофический
     * баг: суммовое значение тарифа в сумах было бы записано в акт как
     * будто это доллары (или наоборот).
     *
     * Решение: подбираем тариф по ВАЛЮТЕ АКТА (settlement_currency_id,
     * уже посчитанной resolveSettlementCurrency() выше), а не по
     * route_type. Поскольку route_type в tariff_versions 1:1 совпадает с
     * валютой, мы просто переводим валюту акта обратно в тот же "ключ"
     * (UZS → 'domestic', USD → 'international') — tariff_versions не
     * переделываем, меняем только то, ЧТО подставляется в качестве этого
     * ключа при выборке. НДС (resolveVatRate()) по-прежнему зависит от
     * буквального route_type рейса — это отдельное, не связанное правило
     * (подтверждено пользователем), не трогаем.
     *
     * Если валюта акта не распознана (не UZS и не USD) — используем
     * буквальный route_type как безопасный fallback (сохраняет старое
     * поведение, не должно происходить на реальных данных).
     */
    private function tariffPriceListKey(array $act): string
    {
        $stmt = $this->db->prepare("SELECT code FROM `currencies` WHERE id = ? LIMIT 1");
        $stmt->execute([(int)($act['settlement_currency_id'] ?? 0)]);
        $code = $stmt->fetchColumn();
        if ($code === 'UZS') return 'domestic';
        if ($code === 'USD') return 'international';
        return (string)($act['route_type'] ?? 'international');
    }

    // ИСПРАВЛЕНО (17.08.2026, раунд 9): тот же баг, что и в updateActItem() —
    // $newCbRate раньше передавался в calculate() как множитель суммы
    // строки, хотя единственная законная роль курса ЦБ здесь — быть
    // СОХРАНЁННЫМ в cb_rate строки для информационного отображения (см.
    // докблок класса выше и комментарий в addActItem()). Этот метод и так
    // вызывается из updateAct() только когда реально нужен пересчёт (курс ЦБ
    // и/или route_type могли измениться на форме акта) — route_type влияет
    // на VAT строки (resolveVatRate()), поэтому пересчёт VAT здесь законен,
    // а вот домножение суммы на курс — нет.
    private function recalculateAllItems(int $actId, float $newCbRate, string $routeType): void
    {
        foreach ($this->actItemModel->getByAct($actId) as $item) {
            $service = $this->serviceModel->findWithDetails((int)$item['service_id']);
            $vatRate = $service ? $this->resolveVatRate($service, $routeType, (float)$item['vat_rate']) : (float)$item['vat_rate'];
            [$withoutVat, $vatAmt, $withVat] = $this->calculate((float)$item['quantity'], (float)$item['unit_price'], 1.0, $vatRate);
            $this->actItemModel->update((int)$item['id'], [
                'cb_rate' => $newCbRate, 'vat_rate' => $vatRate,
                'amount_without_vat' => $withoutVat, 'vat_amount' => $vatAmt, 'amount_with_vat' => $withVat,
            ]);
        }
        $this->recalculateActTotals($actId);
    }

    private function recalculateActTotals(int $actId): void
    {
        $items = $this->actItemModel->getByAct($actId);
        $tw = $tv = $tt = 0.0;
        foreach ($items as $i) { $tw += (float)$i['amount_without_vat']; $tv += (float)$i['vat_amount']; $tt += (float)$i['amount_with_vat']; }
        $this->actModel->update($actId, [
            'total_amount_without_vat' => round($tw, 2),
            'total_vat_amount'         => round($tv, 2),
            'total_amount_with_vat'    => round($tt, 2),
        ]);
    }

    private function saveCalcLog(int $itemId, float $qty, float $priceUsd, float $cbRate, float $vatRate, float $withoutVat, float $vatAmt, float $withVat, string $routeType): void
    {
        $this->calcModel->insertBulk($itemId, [
            'quantity'          => ['value' => $qty, 'type' => 'decimal'],
            'price_usd'         => ['value' => $priceUsd, 'type' => 'decimal'],
            'cb_rate'           => ['value' => $cbRate, 'type' => 'decimal'],
            'amount_usd'        => ['value' => round($qty * $priceUsd, 4), 'type' => 'decimal'],
            'amount_settlement' => ['value' => $withoutVat, 'type' => 'decimal'],
            'vat_rate_pct'      => ['value' => $vatRate, 'type' => 'decimal'],
            'vat_amount'        => ['value' => $vatAmt, 'type' => 'decimal'],
            'amount_with_vat'   => ['value' => $withVat, 'type' => 'decimal'],
            'route_type'        => ['value' => $routeType, 'type' => 'string'],
        ]);
    }

    private function getAllowedTransitions(string $status): array
    {
        return match($status) {
            'draft'     => ['approved', 'cancelled'],
            'approved'  => ['cancelled', 'exported'],
            default     => [],
        };
    }

    /**
     * ДОБАВЛЕНО (14.10.2026, раунд 71, по прямому указанию пользователя):
     * текстовая расшифровка содержания акта — то же группирование по
     * категориям услуг, что и в таблице "Строки акта" (resources/views/
     * acts/show.php), но БЕЗ колонок "НДС%" и "Итого с НДС" — только
     * название услуги, количество+ед.изм., цена за единицу и сумма без
     * НДС, плюс общий итог без НДС (берётся из уже посчитанного
     * acts.total_amount_without_vat, не пересчитывается заново).
     *
     * Вынесено сюда одним местом (а не продублировано в шаблоне), потому
     * что используется в ДВУХ местах: попап «Расшифровка» на странице
     * самого акта и попап по той же кнопке в списке актов (/acts) —
     * см. ActController::show()/textBreakdown().
     *
     * @param array $act   строка из Act::findWithDetails()
     * @param array $items строки из ActItem::getByAct()
     */
    public function buildTextBreakdown(array $act, array $items): string
    {
        $lines   = [];
        $lines[] = 'АКТ ' . act_display_number($act) . ' — ' . $act['flight_number']
            . ', ' . format_date($act['flight_date'])
            . ' (' . ($act['operation_type'] === 'arrival' ? __('FLIGHTS_ARRIVAL') : __('FLIGHTS_DEPARTURE')) . ')';

        $airlineLine = trim(($act['airline_name'] ?? '') . ($act['aircraft_registration'] ? ', борт ' . $act['aircraft_registration'] : ''));
        if ($airlineLine !== '') {
            $lines[] = $airlineLine;
        }
        $lines[] = '';

        $currentCat = null;
        foreach ($items as $item) {
            $catCode = $item['category_code'] ?? '';
            $catName = $item['category_name'] ?? '';
            if ($catCode !== $currentCat) {
                $currentCat = $catCode;
                if (!empty($lines) && end($lines) !== '') {
                    $lines[] = '';
                }
                $lines[] = $catName;
            }
            $lines[] = '  ' . $item['service_name_snapshot'] . ' — '
                . format_money($item['quantity'], 2) . ' ' . $item['unit_name_snapshot']
                . ' × ' . format_money($item['unit_price'], 4) . ' ' . $item['currency_code']
                . ' = ' . format_money($item['amount_without_vat']) . ' ' . $item['currency_code'];
            if (!empty($item['calculation_note'])) {
                $lines[] = '      ' . $item['calculation_note'];
            }
        }

        $lines[] = '';
        $lines[] = 'ИТОГО (без НДС): ' . format_money($act['total_amount_without_vat']) . ' ' . $act['currency_code'];

        return implode("\n", $lines);
    }

    /**
     * ДОБАВЛЕНО (15.10.2026, раунд 80, по прямому указанию пользователя):
     * "переделай попап расшифровки акта из текстового вида в табличную
     * форму, как в приложенном скрине (реальный акт бухгалтера — колонки
     * Номенклатура/Кол-во/Ед-ца изм/Цена/Сумма)." Та же группировка по
     * категориям и те же поля, что в buildTextBreakdown() выше (НЕ
     * дублирует её — buildTextBreakdown() остаётся источником текста для
     * кнопки «Копировать», этот метод — только для отображения в попапе).
     * Переиспользует существующие CSS-классы `.act-items-table`/
     * `.section-row-header` (app.css) — тот же стиль, что у таблицы
     * "Строки акта" на странице самого акта, без НДС-колонок (копия
     * расшифровки их не показывает, только qty/price/amount без НДС —
     * как и раньше в текстовом варианте).
     *
     * Строит готовый HTML (не Blade/шаблон — метод лежит в Service, не во
     * View) — все динамические значения экранированы через htmlspecialchars.
     */
    public function buildBreakdownHtml(array $act, array $items): string
    {
        $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $opLabel     = $act['operation_type'] === 'arrival' ? __('FLIGHTS_ARRIVAL') : __('FLIGHTS_DEPARTURE');
        $airlineLine = trim(($act['airline_name'] ?? '') . ($act['aircraft_registration'] ? ', борт ' . $act['aircraft_registration'] : ''));
        $currencyCode = (string)($act['currency_code'] ?? '');

        $out  = '<div class="mb-2">';
        $out .= '<strong>АКТ ' . $esc(act_display_number($act)) . '</strong> — ' . $esc($act['flight_number'])
              . ', ' . $esc(format_date($act['flight_date'])) . ' (' . $esc($opLabel) . ')';
        if ($airlineLine !== '') {
            $out .= '<br><span class="text-muted">' . $esc($airlineLine) . '</span>';
        }
        $out .= '</div>';

        $out .= '<div class="table-responsive"><table class="table table-sm table-bordered act-items-table mb-2">';
        $out .= '<thead><tr>'
              . '<th>Номенклатура</th>'
              . '<th class="text-end">Кол-во</th>'
              . '<th>Ед.изм</th>'
              . '<th class="text-end">Цена' . ($currencyCode !== '' ? ', ' . $esc($currencyCode) : '') . '</th>'
              . '<th class="text-end">Сумма' . ($currencyCode !== '' ? ', ' . $esc($currencyCode) : '') . '</th>'
              . '</tr></thead><tbody>';

        $currentCat = null;
        foreach ($items as $item) {
            $catCode = $item['category_code'] ?? '';
            $catName = $item['category_name'] ?? '';
            if ($catCode !== $currentCat) {
                $currentCat = $catCode;
                $out .= '<tr class="section-row-header"><td colspan="5">' . $esc($catName) . '</td></tr>';
            }

            $nameCell = $esc($item['service_name_snapshot']);
            if (!empty($item['calculation_note'])) {
                $nameCell .= '<div class="small text-muted">' . $esc($item['calculation_note']) . '</div>';
            }

            $out .= '<tr>';
            $out .= '<td>' . $nameCell . '</td>';
            $out .= '<td class="text-end">' . $esc(format_money($item['quantity'], 2)) . '</td>';
            $out .= '<td>' . $esc($item['unit_name_snapshot']) . '</td>';
            $out .= '<td class="text-end">' . $esc(format_money($item['unit_price'], 4)) . '</td>';
            $out .= '<td class="text-end">' . $esc(format_money($item['amount_without_vat'])) . '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody><tfoot><tr class="fw-bold">'
              . '<td colspan="4" class="text-end">ИТОГО (без НДС):</td>'
              . '<td class="text-end">' . $esc(format_money($act['total_amount_without_vat'])) . ' ' . $esc($currencyCode) . '</td>'
              . '</tr></tfoot>';
        $out .= '</table></div>';

        return $out;
    }
}
