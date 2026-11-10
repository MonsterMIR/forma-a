<?php
/**
 * print.php — Форма А / Form A
 * v7: слово-в-слово по Образец_форма_А.xlsx
 * Единая таблица 10 колонок (B-K), table-layout:fixed
 * B=29% C=14% D=6% E=7% F=9% G=7% H=7% I=7% J=7% K=7%
 */

// ИЗМЕНЕНО (18.09.2026, раунд 46, п.6): раньше здесь всегда показывалось
// имя того, кто нажал "Утвердить" в ЦОК (approved_by), даже если реально на
// планшете расписался другой человек — супервайзер. Теперь, если акт
// подписывали через планшет (см. ActSignatureController::
// saveSupervisorSignature(), acts.tablet_supervisor_user_id), показываем
// настоящее имя супервайзера рядом с его же подписью. Для старых актов
// (до раунда 46) или актов, утверждённых по скану без подписей через
// планшет, — прежнее поведение как запасной вариант, без изменений.
$supervisorName = $act['tablet_supervisor_fullname']
               ?? $act['approved_by_fullname'] ?? $act['approved_by_username']
               ?? $act['created_by_fullname']  ?? $act['created_by_username'] ?? '';
$supervisorDate = !empty($act['approved_at'])
    ? format_date($act['approved_at'], 'd.m.Y')
    : format_date($act['act_date'] ?? $act['created_at'], 'd.m.Y');

// ДОБАВЛЕНО (10.09.2026, раунд 35): по прямому указанию пользователя — без
// обеих подписей (супервайзер + представитель) документ не является актом.
// До финализации номера (см. ActService::ensureActNumberFinalized(),
// acts.number_finalized) вместо настоящего номера печатаем явную пометку,
// а QR/код проверки вообще не генерируем — проверять пока нечего, и
// избегаем ложного ощущения, что это уже официальный, проверяемый документ.
$isFinalized = !empty($act['number_finalized']);
$actTitle    = $isFinalized ? e($act['act_number']) : 'ПРЕДВАРИТЕЛЬНО — НЕ ЯВЛЯЕТСЯ АКТОМ';
$isIntl   = ($act['route_type'] === 'international');
$cur      = e($act['currency_code'] ?? 'USD');
$isArr    = in_array($act['operation_type'] ?? '', ['arrival',  'both']);
$isDep    = in_array($act['operation_type'] ?? '', ['departure','both']);

// QR
$verifyCode = null;
$qrBase64   = null;
if ($isFinalized) {
    $verifyCode = act_verify_code($act['act_number'], $act['act_date'], (float)$act['total_amount_with_vat']);
    $qrPath     = sys_get_temp_dir() . '/qr_' . $verifyCode . '.png';
    if (!file_exists($qrPath)) {
        $verifyUrl = rtrim($_ENV['APP_URL'] ?? 'https://forma.tvtairport.uz', '/') . '/verify/' . $verifyCode;
        @shell_exec(sprintf('qrencode -o %s -s 3 -m 1 %s 2>/dev/null', escapeshellarg($qrPath), escapeshellarg($verifyUrl)));
    }
    if (file_exists($qrPath)) {
        $qrBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($qrPath));
    }
}

// ДОБАВЛЕНО (аудит 03.08.2026, round 6): данные заправки от супервайзера —
// если внесены, печатаем вместо пустых линий под ручную запись.
// ИЗМЕНЕНО (30.10.2026, раунд 141, по прямому указанию пользователя): на
// рейсе может быть несколько заправок (основная + дозаправка(и), см.
// round126) — у каждой свои литры/плотность/температура, они не должны
// схлопываться в одну запись. $fuelDetails ниже — ПЕРВАЯ (основная)
// заправка, печатается на прежнем месте без каких-либо изменений в
// разметке (если заправка была только одна — на бланке буквально ничего
// не меняется). $fuelDetailsExtra — все остальные (дозаправки), для них
// дальше в бланке динамически добавляются дополнительные строки — только
// когда они есть.
$fuelDetails      = null;
$fuelDetailsExtra = [];
try {
    $fuelDetailsAll   = (new \App\Models\FlightFuelDetail())->findAllByFlightId((int)$act['flight_id']);
    $fuelDetails      = $fuelDetailsAll[0] ?? null;
    $fuelDetailsExtra = array_slice($fuelDetailsAll, 1);
} catch (\Throwable $e) {}

// ── Слоты ──────────────────────────────────────────────────────────────────
$AM   = ['TLF'=>null,'NIGHT'=>null,'AV_SEC'=>null,'METEO'=>null,'FPL'=>null,
         'BRIEF'=>null,'PAX_TAX'=>null,'FUEL_SVC'=>null,'DEICE_LQ'=>null];
$AA   = ['CHARTER'=>null,'AC_GUARD'=>null,'FOLLOW_ME'=>null,'SEC_PAX_DEP'=>null,
         'OVER_PARK'=>null,'TECH_SVC'=>null,'FIRE_TRUCK'=>null];
$HT_L = ['STAIRS_PAX'=>null,'STAIRS_TECH'=>null];
$HT_R = ['GPU'=>null,'ACU'=>null];
$HM   = ['PAX_ARR'=>null,'PAX_DEP'=>null,'BAGGAGE'=>null,'BUS_PAX'=>null,
         'BUS_CREW'=>null,'AMBULIFT'=>null,'AMBULANCE'=>null,'PUSHBACK'=>null,
         'W_B'=>null,'LOAD_CTL'=>null,'SUPERV'=>null,'WATER'=>null,'TOILET'=>null,'WCHR'=>null,
         // ДОБАВЛЕНО (04.09.2026, раунд 27): багажный транспортёр — отдельная от
         // BAGGAGE услуга (см. докблок у $hmRows ниже и BAG_ARR_PC/requires_tech_personnel).
         'BAG_BELT'=>null];
$HA   = ['STEPLADDER'=>null,'GSE_TECH'=>null,'MARSHALLING'=>null,'FLIGHT_DOC'=>null,
         'UM_SVC'=>null,'DCS'=>null,'CUTE'=>null,'AIR_START'=>null,'O2_SVC'=>null,
         'AIR_COMP'=>null,'W_B_ADD'=>null,'DEICE_SNW'=>null,'DEICE_CTRL'=>null,
         'AUTOLIFT'=>null,'CRANE'=>null];

// ── ДИНАМИЧЕСКИЙ МАППИНГ ИЗ БД ─────────────────────────────────────────────
// Больше не нужно менять код при добавлении новых услуг.
// Финансовая служба назначает form_a_slot при создании/редактировании услуги.
$_db = \App\Core\Database::getInstance();
$_slotRows = $_db->query(
    "SELECT code, form_a_slot FROM services
     WHERE form_a_slot IS NOT NULL AND is_active = 1"
)->fetchAll(\PDO::FETCH_ASSOC);

// Маппинг слотов формы А → PHP-переменные
$_slotMap = [
    // Airport main
    'am_tlf'      => [&$AM, 'TLF'],
    'am_av_sec'   => [&$AM, 'AV_SEC'],
    'am_night'    => [&$AM, 'NIGHT'],
    'am_meteo'    => [&$AM, 'METEO'],
    'am_fpl'      => [&$AM, 'FPL'],
    'am_brief'    => [&$AM, 'BRIEF'],
    'am_pax_tax'  => [&$AM, 'PAX_TAX'],
    'am_fuel'     => [&$AM, 'FUEL_SVC'],
    'am_deice_lq' => [&$AM, 'DEICE_LQ'],
    // Airport additional
    'aa_charter'   => [&$AA, 'CHARTER'],
    'aa_ac_guard'  => [&$AA, 'AC_GUARD'],
    'aa_follow_me' => [&$AA, 'FOLLOW_ME'],
    'aa_sec_pax'   => [&$AA, 'SEC_PAX_DEP'],
    'aa_over_park' => [&$AA, 'OVER_PARK'],
    'aa_tech_svc'  => [&$AA, 'TECH_SVC'],
    'aa_fire'      => [&$AA, 'FIRE_TRUCK'],
    // Handling time
    'ht_stairs_pax'  => [&$HT_L, 'STAIRS_PAX'],
    'ht_stairs_tech' => [&$HT_L, 'STAIRS_TECH'],
    'ht_gpu'         => [&$HT_R, 'GPU'],
    'ht_acu'         => [&$HT_R, 'ACU'],
    // Handling main
    'hm_pax_arr'  => [&$HM, 'PAX_ARR'],
    'hm_pax_dep'  => [&$HM, 'PAX_DEP'],
    'hm_baggage'  => [&$HM, 'BAGGAGE'],
    'hm_bag_belt' => [&$HM, 'BAG_BELT'],
    'hm_bus_pax'  => [&$HM, 'BUS_PAX'],
    'hm_bus_crew' => [&$HM, 'BUS_CREW'],
    'hm_ambulift' => [&$HM, 'AMBULIFT'],
    'hm_ambulance'=> [&$HM, 'AMBULANCE'],
    'hm_pushback' => [&$HM, 'PUSHBACK'],
    'hm_wb'       => [&$HM, 'W_B'],
    'hm_load_ctl' => [&$HM, 'LOAD_CTL'],
    'hm_superv'   => [&$HM, 'SUPERV'],
    'hm_water'    => [&$HM, 'WATER'],
    'hm_toilet'   => [&$HM, 'TOILET'],
    'hm_wchr'     => [&$HM, 'WCHR'],
    // Handling additional
    'ha_stepladder' => [&$HA, 'STEPLADDER'],
    'ha_gse_tech'   => [&$HA, 'GSE_TECH'],
    'ha_marshalling'=> [&$HA, 'MARSHALLING'],
    'ha_flight_doc' => [&$HA, 'FLIGHT_DOC'],
    'ha_um_svc'     => [&$HA, 'UM_SVC'],
    'ha_dcs'        => [&$HA, 'DCS'],
    'ha_cute'       => [&$HA, 'CUTE'],
    'ha_air_start'  => [&$HA, 'AIR_START'],
    'ha_o2'         => [&$HA, 'O2_SVC'],
    'ha_air_comp'   => [&$HA, 'AIR_COMP'],
    'ha_wb_add'     => [&$HA, 'W_B_ADD'],
    'ha_deice_snw'  => [&$HA, 'DEICE_SNW'],
    'ha_deice_ctrl' => [&$HA, 'DEICE_CTRL'],
    'ha_autolift'   => [&$HA, 'AUTOLIFT'],
    'ha_crane'      => [&$HA, 'CRANE'],
];

// Строим $codeMap динамически из данных БД
$codeMap = [];
foreach ($_slotRows as $_row) {
    $slot = $_row['form_a_slot'];
    if (isset($_slotMap[$slot])) {
        $codeMap[$_row['code']] = $_slotMap[$slot];
    }
}

// ИСПРАВЛЕНО (аудит 10.07.2026): если 2+ услуги делят одну ячейку бланка
// (напр. WB_CTL и WB_CALC → hm_wb — в самом шаблоне "Handling main" нет
// отдельной колонки "Сумма", строка фиксирована текстом бланка, развести
// физически некуда), раньше вторая тихо затирала первую в $codeMap.
// Теперь количества складываются, ни одна услуга не теряется молча.
$other = [];
foreach ($items as $item) {
    $code = strtoupper(trim($item['service_code'] ?? ''));
    if (isset($codeMap[$code])) {
        $arrRef = &$codeMap[$code][0];
        $key    = $codeMap[$code][1];
        if ($arrRef[$key] === null) {
            $arrRef[$key] = $item;
        } else {
            $arrRef[$key] = _merge_slot_items($arrRef[$key], $item);
        }
        unset($arrRef);
    } else {
        $other[] = $item;
    }
}

// ИСПРАВЛЕНО (14.08.2026, раунд 2, п.3): PAX в шапке ARR-LT/DEP-LT — берём
// фактически оказанное количество услуги PAX_ARR/PAX_DEP (супервайзер вводит
// его при обслуживании рейса), а не flights.pax_arrival/pax_departure,
// которые нигде в интерфейсе не заполняются напрямую.
$paxArrivalDisplay   = $HM['PAX_ARR'] !== null
    ? (float)($HM['PAX_ARR']['quantity'] ?? 0)
    : ($act['pax_arrival'] ?? $act['pax_count'] ?? '—');
$paxDepartureDisplay = $HM['PAX_DEP'] !== null
    ? (float)($HM['PAX_DEP']['quantity'] ?? 0)
    : ($act['pax_departure'] ?? $act['pax_count'] ?? '—');
if (is_float($paxArrivalDisplay)) {
    $paxArrivalDisplay = fmod($paxArrivalDisplay, 1) == 0 ? (string)(int)$paxArrivalDisplay : number_format($paxArrivalDisplay, 2, '.', ' ');
}
if (is_float($paxDepartureDisplay)) {
    $paxDepartureDisplay = fmod($paxDepartureDisplay, 1) == 0 ? (string)(int)$paxDepartureDisplay : number_format($paxDepartureDisplay, 2, '.', ' ');
}

// ИСПРАВЛЕНО (19.08.2026, раунд 21): INFANT печатался жёстко как "—" на
// обеих строках (ARR/DEP), хотя flights.infant_arrival/infant_departure
// реально заполняются на форме рейса (ЦОК) — просто никогда не читались
// здесь. DHC — рядом была подпись-метка в бланке, но НИ поля ввода, НИ
// столбца в БД не было вообще (см. sql/round21_dhc_column.sql и поля
// на форме рейса /flights/create,/flights/{id}/edit) — обе метки печатали
// пустую ячейку без данных. Оба — просто введённое ЦОК число, без связи с
// биллингом (в отличие от PAX выше), поэтому просто читаем поле рейса.
$infantArrivalDisplay   = $act['infant_arrival']   ?? null;
$infantDepartureDisplay = $act['infant_departure'] ?? null;
$dhcArrivalDisplay      = $act['dhc_arrival']       ?? null;
$dhcDepartureDisplay    = $act['dhc_departure']     ?? null;

function _merge_slot_items(array $a, array $b): array {
    $merged = $a;
    $merged['quantity']           = (float)($a['quantity'] ?? 0)           + (float)($b['quantity'] ?? 0);
    $merged['amount_without_vat'] = (float)($a['amount_without_vat'] ?? 0) + (float)($b['amount_without_vat'] ?? 0);
    $merged['amount_with_vat']    = (float)($a['amount_with_vat'] ?? 0)    + (float)($b['amount_with_vat'] ?? 0);
    return $merged;
}

function _q($item): string {
    if ($item === null) return '&mdash;';           // не оказана → —
    $q = (float)($item['quantity'] ?? 0);
    if ($q == 0) return '&#10003;';                 // оказана без числа → ✓
    return fmod($q, 1) == 0                         // оказана с числом
        ? number_format($q, 0, '.', ' ')
        : number_format($q, 2, '.', ' ');
}
function _qc($item): string {
    if ($item === null) return 'qn';  // прочерк — серый фон
    return (float)($item['quantity'] ?? 0) == 0 ? 'qv' : 'qc';  // галочка / число
}
function _a($item, string $cur = ''): string {
    if ($item === null) return '';
    $v = (float)($item['amount_with_vat'] ?? 0);
    if ($v == 0) return '';
    return number_format($v, 2, '.', ' ') . ($cur ? " $cur" : '');
}
function chk(bool $on): string {
    if ($on) return '<span style="display:inline-block;width:9px;height:9px;border:0.7pt solid #000;background:#000;color:#fff;font-size:7.5pt;line-height:9px;text-align:center;vertical-align:middle;">&#10003;</span>';
    return '<span style="display:inline-block;width:9px;height:9px;border:0.7pt solid #000;background:#fff;vertical-align:middle;"></span>';
}

// ИСПРАВЛЕНО (14.08.2026, раунд 8): раньше дата ARR/DEP при пустом
// arr_date/dep_date ВСЕГДА подставляла flight_date — даже для операции,
// которой в этом акте вообще нет (напр. dep_date в акте прилёта). $isArr/
// $isDep (см. выше) как раз и означают "этот акт покрывает эту операцию" —
// запасной flight_date теперь подставляется только для СВОЕЙ операции
// акта, а для чужой остаётся пусто (ActController::printForm() и так уже
// явно обнуляет chужие arr_*/dep_* поля — это второй, независимый слой
// защиты прямо в шаблоне печати, на случай другого источника данных).
$arrDate = !empty($act['arr_date']) ? format_date($act['arr_date'], 'd.m.Y') : ($isArr ? format_date($act['flight_date'], 'd.m.Y') : '');
$depDate = !empty($act['dep_date']) ? format_date($act['dep_date'], 'd.m.Y') : ($isDep ? format_date($act['flight_date'], 'd.m.Y') : '');
$arrTime = !empty($act['arr_time']) ? ' '.substr($act['arr_time'], 0, 5) : '';
$depTime = !empty($act['dep_time']) ? ' '.substr($act['dep_time'], 0, 5) : '';
$ptype   = $act['payment_type'] ?? '';

// ДОБАВЛЕНО (17.08.2026, раунд 9): время установки/уборки колодок — акт
// прилёта показывает только время УСТАНОВКИ, акт вылета — только время
// УБОРКИ (ActController::printForm() уже обнуляет "чужое" поле, это —
// второй слой защиты, тот же принцип, что и у $arrDate/$depDate выше).
$chocksTimeDisplay = $isArr ? ($act['chocks_on'] ?? '') : ($isDep ? ($act['chocks_off'] ?? '') : '');

// ИЗМЕНЕНО (18.09.2026, раунд 44, по прямому указанию пользователя —
// продолжение исключения из правила "акт вылета — только данные вылета",
// см. комментарий у $arrDate/$depDate выше и в ActController::printForm()):
// изначально (17.09.2026) сюда с рейса прилёта подставлялись дата/время
// посадки и время установки колодок отдельной подписью в ячейке колодок,
// а $arrDate/$arrTime акта вылета оставались пустыми. Пользователь попросил
// (a) дату/время посадки печатать прямо в штатной строке "Дата и время
// посадки" (ARR-LT) — контроллер теперь сам подставляет туда значения со
// связанного рейса прилёта, отдельная переменная здесь больше не нужна;
// (b) вместо голых цифр времени колодок в ячейке колодок печатать готовый
// РАСЧЁТ времени стоянки (те же числа, что расчитывает AutoChargeService::
// processParkingAct() для начисления OVER_PARK) — контроллер собирает эту
// строку заранее в $act['parking_calc_display'].
$parkingCalcDisplay = $isDep ? (string)($act['parking_calc_display'] ?? '') : '';

// ИСПРАВЛЕНО (14.08.2026, раунд 2, п.3): PAX в шапке (строки ARR-LT/DEP-LT)
// раньше брался из flights.pax_arrival/pax_departure — эти поля супервайзер
// нигде не заполняет напрямую. Фактическое количество пассажиров вводится
// супервайзером НИЖЕ, в самом обслуживании — как количество услуги
// PAX_ARR/PAX_DEP (слот hm_pax_arr/hm_pax_dep, см. $HM ниже). Показываем
// эти фактические цифры в шапке, а на flights.pax_* остаёмся только как
// запасной вариант, если по каким-то причинам PAX_ARR/PAX_DEP не оказывались.
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Форма А — <?= $actTitle ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 8.5pt;
    color: #000;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.no-print {
    position:fixed; top:8px; right:8px; display:flex; gap:6px; z-index:999;
}
.no-print a, .no-print button {
    padding:6px 12px; font-size:11px; cursor:pointer; border-radius:4px;
    border:1px solid #555; text-decoration:none; display:inline-block;
}
.bp { background:#1a3a6b; color:#fff; font-weight:bold; border-color:#1a3a6b; }
.bb { background:#f0f0f0; color:#333; }
.page { width:210mm; margin:0 auto; padding:5mm 7mm; }

/* ── ЕДИНАЯ ТАБЛИЦА 10 КОЛОНОК ── */
table.F {
    width:100%; border-collapse:collapse; table-layout:fixed;
    border: 0.7pt solid #000;
}
table.F col.cB { width:29%; }
table.F col.cC { width:14%; }
table.F col.cD { width:6%;  }
table.F col.cE { width:7%;  }
table.F col.cF { width:9%;  }
table.F col.cG { width:7%;  }
table.F col.cH { width:7%;  }
table.F col.cI { width:7%;  }
table.F col.cJ { width:7%;  }
table.F col.cK { width:7%;  }

table.F td {
    border: 0.5pt solid #000;
    padding: 2px 3px;
    vertical-align: middle;
    line-height: 1.3;
    overflow: hidden;
}
/* Ячейки без нижней / верхней границы (для rowspan шапки) */
.nb { border-bottom: 0 !important; }
.nt { border-top:    0 !important; }

/* Цвета */
.hB  { background:#1a3a6b; color:#fff; font-weight:bold; font-size:9pt; text-align:center; padding:2px 4px; }
.hL  { background:#d4ddf0; font-weight:bold; font-size:7.5pt; text-align:center; padding:2px 2px; }
.lbl { background:#edf0f8; font-size:8pt; }
.val { font-weight:bold; }
.sn  { font-size:8pt; }
.uc  { font-size:7.5pt; color:#333; text-align:center; }
.qc  { font-size:8.5pt; font-weight:bold; text-align:center; background:#fffbe6; }  /* число */
.qv  { font-size:9.5pt; font-weight:bold; text-align:center; background:#e8f5e9; color:#1b6b2f; } /* ✓ галочка */
.qn  { font-size:9.5pt; text-align:center; color:#aaa; background:#f5f5f5; }                      /* — не оказана */
.qe  { font-size:8.5pt; text-align:center; }
.sl  { border-bottom:0.5pt solid #000; display:inline-block; width:100%; min-height:9px; }

@media print {
    .no-print { display:none !important; }
    @page {
        size: A4 portrait;
        margin: 6mm 7mm;
    }
    html, body { width:210mm; height:297mm; }
    .page {
        padding: 0;
        width: 100%;
        /* принудительно вписываем в страницу */
        transform-origin: top left;
    }
    table.F { font-size: 8pt; }
    .hB  { background:#1a3a6b !important; color:#fff !important; }
    .hL  { background:#d4ddf0 !important; }
    .lbl { background:#edf0f8 !important; }
    .qc  { background:#fffbe6 !important; }
    .qv  { background:#e8f5e9 !important; }
    .qn  { background:#f5f5f5 !important; }
}
</style>
</head>
<body>
<div class="no-print">
    <a class="bb" href="/acts/<?= (int)$act['id'] ?>">← Назад</a>
    <button class="bp" onclick="window.print()">🖨 Печать / PDF</button>
</div>
<div class="page">
<table class="F">
<colgroup>
  <col class="cB"><col class="cC"><col class="cD"><col class="cE"><col class="cF">
  <col class="cG"><col class="cH"><col class="cI"><col class="cJ"><col class="cK">
</colgroup>
<tbody>

<!--
  ШАПКА — точно по образцу:
  B2: HUMO (rowspan 2)  |  C2: ШТАМП ЦОК (colspan 3, rowspan 2)
  E2: INVOICE  |  J2: Прилет  |  K: пусто
  E3: Счет №   |  J3: Вылет
-->
<tr>
  <!-- B2: логотип, rowspan=2 -->
  <td rowspan="2" style="text-align:center; vertical-align:middle; padding:4px 3px;">
    <div style="font-size:11pt; font-weight:bold; line-height:1.1;">HUMO</div>
    <div style="font-size:8.5pt;  font-weight:bold; letter-spacing:1px;">AIRPORTS</div>
  </td>
  <!-- C2-E2: штамп, colspan=3, rowspan=2 -->
  <td colspan="3" rowspan="2" style="text-align:center; vertical-align:middle; padding:4px;">
    <?php if ($act['status'] === 'approved'): ?>
      <div style="border:0.7pt dashed #999; padding:3px 8px; display:inline-block; min-width:28mm; min-height:11mm; text-align:center;">
        <div style="font-weight:bold; font-size:9.5pt; color:#006400;">✓ ПРОВЕРЕНО</div>
        <div style="font-size:7.5pt; color:#555; margin-top:1px;">ЦОК &nbsp; <?= $supervisorDate ?></div>
      </div>
    <?php else: ?>
      <div style="border:0.7pt dashed #ccc; padding:3px 8px; display:inline-block; min-width:28mm; min-height:11mm; text-align:center; color:#bbb; font-size:8pt; font-style:italic; vertical-align:middle;">
        ШТАМП ЦОК «ПРОВЕРЕННО»
      </div>
    <?php endif; ?>
  </td>
  <!-- E2: INVOICE -->
  <td colspan="4" style="font-size:11pt; font-weight:bold; padding:3px 5px; border-bottom:0;">
    INVOICE
  </td>
  <!-- J2: Прилет -->
  <td style="font-size:8pt; padding:2px 3px; border-bottom:0;">
    <?= chk($isArr) ?> Прилет
  </td>
  <!-- K2: пусто -->
  <td style="border-bottom:0;">&nbsp;</td>
</tr>
<tr>
  <!-- E3: Счет № (colspan=4) -->
  <td colspan="4" style="font-size:8.5pt; padding:2px 5px; border-top:0;">
    Счет № <?= $actTitle ?>
  </td>
  <!-- J3: Вылет -->
  <td style="font-size:8pt; padding:2px 3px; border-top:0;">
    <?= chk($isDep) ?> Вылет
  </td>
  <!-- K3: ОРИГИНАЛ -->
  <td style="font-size:7pt; color:#666; border-top:0; text-align:center;">
    ОРИГИНАЛ
  </td>
</tr>

<!--
  B4: "Информация по рейсу/Flight detalis" (italic, colspan=2 фактически B-C)
  C4-G4: Авиакомпания / Air compani  (D4:G4 merged → colspan 4 от C)
  H4: MTOW
  I4-K4: Регистр Борт №
-->
<tr>
  <td class="lbl" style="font-style:italic; font-size:7.5pt;">Информация по рейсу/Flight detalis</td>
  <td class="lbl" style="font-size:7.5pt;">Авиакомпания / Air compani</td>
  <td colspan="4" class="val" style="font-size:9pt;">
    <?= e($act['airline_name']) ?>
    <span style="font-weight:normal; font-size:7.5pt;">(<?= e($act['airline_iata'] ?? '') ?>)</span>
  </td>
  <td class="lbl" style="text-align:center; font-size:7.5pt;">MTOW</td>
  <td class="val" style="text-align:center;"><?= e($act['mtow'] ?? '—') ?></td>
  <td class="lbl" style="font-size:7pt;" colspan="2">Регистр Борт № <strong><?= e($act['aircraft_registration'] ?? '—') ?></strong></td>
</tr>

<!--
  B5-B6: "Flight №, purpose / №рейса, цель полета" rowspan=2
  C5: значение рейса (colspan=2, rowspan=2 → C-D merged B5:B6 / C5:D6?)
  D5: Pax Charter | E5: Cargo Charter | F5: Pax Business | G5: Tex Stop
  H5-H6: (пусто, rowspan=2)
  I5-K5: Регист № рейса (colspan=3)
  строка 6: значения чекбоксов
-->
<tr>
  <td rowspan="2" class="lbl" style="font-size:7.5pt; vertical-align:middle;">
    Flight №,purpose/ №рейса, цель полета
  </td>
  <td rowspan="2" colspan="2" class="val" style="font-size:9.5pt; vertical-align:middle;">
    <?= e($act['flight_number']) ?>
  </td>
  <td class="hL">Pax Charter</td>
  <td class="hL">Cargo Charter</td>
  <td class="hL">Pax Business</td>
  <td class="hL">Tex Stop</td>
  <?php /* ИЗМЕНЕНО (21.08.2026, раунд 23): раньше пустая подпись без
           значения — по ответу пользователя сюда печатается регистрационный
           номер ВС (борт), тот же `aircraft_registration`, что уже выводится
           выше как "Регистр Борт №". */ ?>
  <td rowspan="2" colspan="3" class="lbl" style="font-size:7pt; vertical-align:top; padding-top:2px;">
    Регист № рейса<br>
    <span class="val" style="font-size:9pt; font-weight:bold;"><?= e($act['aircraft_registration'] ?? '—') ?></span>
  </td>
</tr>
<tr>
  <?php /* ДОБАВЛЕНО (21.08.2026, раунд 23): "Цель полёта" — галочка под
           выбранным супервайзером вариантом (см. flights.flight_purpose,
           ServiceOrderController::savePurpose()). До сих пор эти 4 ячейки
           были просто пустой заглушкой без данных. */
    $flightPurpose = $act['flight_purpose'] ?? null;
  ?>
  <td style="height:9px; text-align:center;"><?= $flightPurpose === 'pax_charter'   ? chk(true) : '&nbsp;' ?></td>
  <td style="text-align:center;"><?= $flightPurpose === 'cargo_charter' ? chk(true) : '&nbsp;' ?></td>
  <td style="text-align:center;"><?= $flightPurpose === 'pax_business'  ? chk(true) : '&nbsp;' ?></td>
  <td style="text-align:center;"><?= $flightPurpose === 'tech_stop'     ? chk(true) : '&nbsp;' ?></td>
</tr>

<!--
  B7: Type of aircraft, reg number/ Тип ВС
  C7: значение типа ВС
  D7-F7: Route-Маршрут (label)
  G7: UZTP (фиксировано)  → в нашем случае значение маршрута
  H7: → (стрелка маршрута)
  J7-K7: Payment
-->
<tr>
  <td class="lbl" style="font-size:7.5pt;">Type of aircraft, reg number/ Тип ВС</td>
  <td colspan="2" class="val"><?= e($act['aircraft_type'] ?? '—') ?></td>
  <td colspan="2" class="lbl" style="font-size:7.5pt; text-align:center;">Route-Маршрут</td>
  <td colspan="2" class="val" style="text-align:center; font-size:9pt; letter-spacing:0.5px;"><?= e($act['origin_iata'] ?? 'UZTP') ?> → <?= e($act['destination_iata'] ?? '—') ?></td>
  <td class="lbl" style="font-size:7pt; text-align:center;"><?= $isIntl ? 'Межд.' : 'Внутр.' ?></td>
  <td colspan="2" style="font-size:8pt; padding:2px 4px; font-weight:bold;">Payment</td>
</tr>

<!--
  B8: Data and time of ARR-LT / Дата и время посадки
  C8-D8: значение даты/времени
  D8(label): PAX  E8: пусто F8: INFANT  G8: пусто H8: DHC  I8-?: пусто
  J8: Cash/ наличные  K8: чекбокс
-->
<tr>
  <td class="lbl" style="font-size:7.5pt;">Data and time of ARR-LT /Дата и время посадки</td>
  <td colspan="2" class="val"><?= $arrDate . $arrTime ?></td>
  <td class="lbl" style="text-align:center; font-size:7.5pt;">PAX</td>
  <td class="val" style="text-align:center;"><?= e($paxArrivalDisplay) ?></td>
  <td class="lbl" style="text-align:center; font-size:7.5pt;">INFANT</td>
  <td class="val" style="text-align:center;"><?= $infantArrivalDisplay !== null ? e($infantArrivalDisplay) : '—' ?></td>
  <?php /* ИСПРАВЛЕНО (19.08.2026, раунд 21; ИЗМЕНЕНО 21.08.2026, раунд 23):
           у DHC нет своей ячейки значения в фиксированной 10-колоночной
           сетке бланка (B-K) — все 10 колонок этой строки уже заняты (см.
           докблок выше и комментарий над таблицей). В раунде 21 печатали
           число ("DHC: 2") прямо в подписи. Пользователь уточнил: тут
           нужна ОТМЕТКА (чекбокс) — есть ли на рейсе, кроме основного
           экипажа, ещё один экипаж, летящий среди пассажиров — тем же
           визуальным стилем чекбокса, что и Cash/Credit рядом. Отмечен,
           если dhc_arrival введён и больше 0; само число сохраняем рядом
           мелким шрифтом — данные супервайзера не теряются. */
    $dhcArrivalOn = $dhcArrivalDisplay !== null && $dhcArrivalDisplay !== '' && (float)$dhcArrivalDisplay > 0;
  ?>
  <td class="lbl" style="text-align:center; font-size:7.5pt;">
    DHC <?= chk($dhcArrivalOn) ?><?= $dhcArrivalOn ? ' <span style="font-weight:normal;">(' . e($dhcArrivalDisplay) . ')</span>' : '' ?>
  </td>
  <td style="font-size:8pt; padding:2px 3px;">Cash/ наличные</td>
  <td style="text-align:center;"><?= chk($ptype === 'cash') ?></td>
</tr>

<!--
  B9: DEP
-->
<tr>
  <td class="lbl" style="font-size:7.5pt;">Data and time of DEP-LT/Дата и время вылета</td>
  <td colspan="2" class="val"><?= $depDate . $depTime ?></td>
  <td class="lbl" style="text-align:center; font-size:7.5pt;">PAX</td>
  <td class="val" style="text-align:center;"><?= e($paxDepartureDisplay) ?></td>
  <td class="lbl" style="text-align:center; font-size:7.5pt;">INFANT</td>
  <td class="val" style="text-align:center;"><?= $infantDepartureDisplay !== null ? e($infantDepartureDisplay) : '—' ?></td>
  <?php /* См. комментарий у DHC-ячейки строки ARR выше (раунд 23) — та же
           логика для вылета. */
    $dhcDepartureOn = $dhcDepartureDisplay !== null && $dhcDepartureDisplay !== '' && (float)$dhcDepartureDisplay > 0;
  ?>
  <td class="lbl" style="text-align:center; font-size:7.5pt;">
    DHC <?= chk($dhcDepartureOn) ?><?= $dhcDepartureOn ? ' <span style="font-weight:normal;">(' . e($dhcDepartureDisplay) . ')</span>' : '' ?>
  </td>
  <td style="font-size:8pt; padding:2px 3px;">Credit/ кредит</td>
  <td style="text-align:center;"><?= chk($ptype !== 'cash') ?></td>
</tr>

<!--
  B10: Время установки/уборки колодки
  C10-G10: значение (D10:G10 merged)
  H10: пусто
  I10-K10: MC№ (label + значение)
-->
<tr>
  <td class="lbl" style="font-size:7.5pt;">Time of setting/removing chocks-LT / Время установки/уборки колодки</td>
  <td class="val"><?= e($chocksTimeDisplay) ?></td>
  <?php /* ИЗМЕНЕНО (18.09.2026, раунд 44) — см. докблок $parkingCalcDisplay
           выше: только на акте вылета, в ранее пустой ячейке, печатается
           итоговый расчёт времени стоянки (факт. время, норма, сверх нормы
           → сутки) — основа суммы OVER_PARK (Стоянка ВС) на этом акте. */ ?>
  <?php if ($isDep && $parkingCalcDisplay !== ''): ?>
  <td colspan="5" class="val" style="font-size:7pt; line-height:1.3;"><?= e($parkingCalcDisplay) ?></td>
  <?php else: ?>
  <td colspan="5" class="val">&nbsp;</td>
  <?php endif; ?>
  <td class="lbl" style="font-size:7.5pt; text-align:center;">MC№</td>
  <td colspan="2" class="val"><?= e($act['mc_number'] ?? '—') ?></td>
</tr>

<!-- ══ AIRPORT SERVICES ═══════════════════════════════════════════════════ -->
<!-- B11:K11 — заголовок, вся строка -->
<tr>
  <td colspan="10" class="hB">Airport services/Аэропортовые услуги</td>
</tr>

<!-- B12: Main services | C12: Единица измерения | D12: кол-во | E12-I12: Additional | J12: ед-ца изм | K12: кол-во -->
<tr>
  <td class="hL">Main services/ основные услуги</td>
  <td class="hL">Единица измерения</td>
  <td class="hL">кол-во</td>
  <td colspan="5" class="hL">Additional services/Дополнительные услуги</td>
  <td class="hL">ед-ца изм</td>
  <td class="hL">кол-во</td>
</tr>

<?php
// B13-B22 / E13-E19 — строки Airport services (слово в слово из образца)
$amRows = [
  ['TLF',      'Взлет/посадка Take off / Landing',              'MTOW'],
  ['NIGHT',    'Ночное время /Night time',                      'DAY'],
  ['AV_SEC',   'Авиационная безопасность/ Aviation Sec',        'MTOW'],
  ['METEO',    'Метео/Meteo',                                   'MTOW'],
  ['FPL',      'Флайт план/ Flight plan',                       'Unit'],
  ['BRIEF',    'Брифинг/ Brifing services',                     'Рейс/Flight'],
  // B20: отдельная строка — Аэропортовые сборы
  ['PAX_TAX',  'Аэропортовые сборы/ Airport taxes for passenger','Пас/PAX'],
  // B21
  ['FUEL_SVC', 'Обеспечение ГСМ / Fuel supply',                 'Tonn'],
  // B22
  ['DEICE_LQ', 'Спец жидкости ПО/Ж  / Liquid fordeicing',      'Tonn'],
];
$aaRows = [
  ['CHARTER',     'Сбор за чартер / Charter',           'рейс'],
  ['AC_GUARD',    'Охрана ВС / Aircraft security',       'Hours/час'],
  ['FOLLOW_ME',   'Сопровождение ВС / Follow me',        'Unit'],
  ['SEC_PAX_DEP', 'Сбор за безопасность PAX вылет',     'PAX'],
  ['OVER_PARK',   'Стоянка ВС / Parking',                'Day'],
  ['TECH_SVC',    'Тех обслуживание / Technical service','Unit'],
  ['FIRE_TRUCK',  'Пожарная машина',                     'Unit'],
  // ИЗМЕНЕНО (20.08.2026, раунд 22): эти 2 строки раньше были пустыми
  // "заглушками" справа (Additional services), а блок "Топливо/Fuel марка"
  // печатался НИЖЕ отдельными двумя строками на всю ширину таблицы — по
  // просьбе пользователя перенесено сюда же (см. спецкейсы $i===7/$i===8 в
  // цикле ниже), чтобы не тратить 2 лишние строки бланка впустую. Левая
  // часть этих строк (Обеспечение ГСМ / Спец жидкости) не меняется.
  [null, '', ''],
  [null, '', ''],
];
$maxAS = max(count($amRows), count($aaRows));
for ($i = 0; $i < $maxAS; $i++):
    $lk = $amRows[$i][0] ?? null;
    $li = ($lk && isset($AM[$lk])) ? $AM[$lk] : null;
    $rk = $aaRows[$i][0] ?? null;
    $ri = ($rk && isset($AA[$rk])) ? $AA[$rk] : null;
?>
<tr>
  <td class="sn"><?= isset($amRows[$i]) ? $amRows[$i][1] : '' ?></td>
  <td class="uc"><?= isset($amRows[$i]) ? $amRows[$i][2] : '' ?></td>
  <td class="<?= _qc($li) ?>"><?= _q($li) ?></td>
<?php if ($i === 7): // Топливо/Fuel — заголовок (было отдельной строкой) ?>
  <td colspan="2" class="sn" style="background:#edf0f8; font-size:7.5pt; font-style:italic;">
    <strong>Топливо / Fuel марка:</strong>
  </td>
  <td class="uc" style="font-weight:bold; font-size:7pt;">литр</td>
  <td class="uc" style="font-weight:bold; font-size:7pt;">кг</td>
  <td colspan="3" class="uc" style="font-size:6.5pt;">плотность и температура</td>
<?php elseif ($i === 8): // Топливо/Fuel — значения (было отдельной строкой) ?>
  <td colspan="2" style="border-bottom: 0.7pt solid #000; padding-bottom:2px; height:14px; font-size:8pt; text-align:center;">
    <?= $fuelDetails && !empty($fuelDetails['fuel_grade']) ? e($fuelDetails['fuel_grade']) : '&nbsp;' ?>
  </td>
  <td style="border-bottom: 0.7pt solid #000; padding-bottom:2px; height:14px; font-size:8pt; text-align:center;">
    <?= $fuelDetails ? number_format((float)$fuelDetails['liters'], 2) : '&nbsp;' ?>
  </td>
  <td style="border-bottom: 0.7pt solid #000; padding-bottom:2px; height:14px; font-size:8pt; text-align:center;">
    <?= $fuelDetails ? number_format((float)$fuelDetails['liters'] * (float)$fuelDetails['density'], 1) : '&nbsp;' ?>
  </td>
  <td colspan="3" style="border-bottom: 0.7pt solid #000; padding-bottom:2px; height:14px; font-size:8pt; text-align:center;">
    <?php if ($fuelDetails): ?>
        ρ=<?= number_format((float)$fuelDetails['density'], 4) ?> кг/л
        <?php if ($fuelDetails['temperature'] !== null): ?>
            , t°=<?= number_format((float)$fuelDetails['temperature'], 1) ?>°C
        <?php endif; ?>
    <?php else: ?>&nbsp;<?php endif; ?>
  </td>
<?php else: ?>
  <td colspan="5" class="sn"><?= isset($aaRows[$i]) ? $aaRows[$i][1] : '' ?></td>
  <td class="uc"><?= isset($aaRows[$i]) ? $aaRows[$i][2] : '' ?></td>
  <td class="<?= ($rk !== null) ? _qc($ri) : 'qe' ?>"><?= ($rk !== null) ? _q($ri) : '&nbsp;' ?></td>
<?php endif; ?>
</tr>
<?php endfor; ?>

<?php
// ДОБАВЛЕНО (30.10.2026, раунд 141, по прямому указанию пользователя):
// динамические строки для ДОЗАПРАВКИ(ЕК) — появляются ТОЛЬКО если на рейсе
// была хотя бы одна дозаправка (round126), иначе этого блока вообще нет в
// печатаемом бланке (как и было до этого раунда для единственной
// заправки — там ничего не меняется). Каждая дозаправка — своя пара строк
// (как и основная заправка выше, i===7/i===8), со своими собственными
// литрами/плотностью/температурой — суммы и усреднений нет, только
// отдельная строка на каждую попытку.
foreach ($fuelDetailsExtra as $_fdIdx => $_fd):
    $_attemptNo = $_fdIdx + 2; // 0 = основная (уже напечатана выше), эта — вторая и далее
?>
<tr>
  <td colspan="3" class="sn">&nbsp;</td>
  <td colspan="2" class="sn" style="background:#edf0f8; font-size:7.5pt; font-style:italic;">
    <strong>Дозаправка <?= $_attemptNo ?> / Refuel #<?= $_attemptNo ?> марка:</strong>
  </td>
  <td class="uc" style="font-weight:bold; font-size:7pt;">литр</td>
  <td class="uc" style="font-weight:bold; font-size:7pt;">кг</td>
  <td colspan="3" class="uc" style="font-size:6.5pt;">плотность и температура</td>
</tr>
<tr>
  <td colspan="3" class="sn">&nbsp;</td>
  <td colspan="2" style="border-bottom: 0.7pt solid #000; padding-bottom:2px; height:14px; font-size:8pt; text-align:center;">
    <?= !empty($_fd['fuel_grade']) ? e($_fd['fuel_grade']) : '&nbsp;' ?>
  </td>
  <td style="border-bottom: 0.7pt solid #000; padding-bottom:2px; height:14px; font-size:8pt; text-align:center;">
    <?= number_format((float)$_fd['liters'], 2) ?>
  </td>
  <td style="border-bottom: 0.7pt solid #000; padding-bottom:2px; height:14px; font-size:8pt; text-align:center;">
    <?= number_format((float)$_fd['liters'] * (float)$_fd['density'], 1) ?>
  </td>
  <td colspan="3" style="border-bottom: 0.7pt solid #000; padding-bottom:2px; height:14px; font-size:8pt; text-align:center;">
    ρ=<?= number_format((float)$_fd['density'], 4) ?> кг/л
    <?php if ($_fd['temperature'] !== null): ?>, t°=<?= number_format((float)$_fd['temperature'], 1) ?>°C<?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>

<!-- ══ HANDLING SERVICES ══════════════════════════════════════════════════ -->
<!-- B23:K23 -->
<tr>
  <td colspan="10" class="hB">Handling services/ Услуги Хэндлинг</td>
</tr>

<!--
  B24: Main services/ основные услуги | C24: Start time | D24: Finish time | E24: Hours/час
  F24-K24: Additional services/Дополнительные услуги
-->
<tr>
  <td class="hL">Main services/ основные услуги</td>
  <td class="hL">Start time</td>
  <td class="hL">Finish time</td>
  <td class="hL">Hours/час</td>
  <td colspan="4" class="hL">Additional services/Дополнительные услуги</td>
  <td class="hL">Start time / Finish time</td>
  <td class="hL">Hours/час</td>
</tr>

<!--
  B25: Предоставление трапа пассаж-го | (B26: Трап для тех обслуж ВС rowspan?)
  F25: Утроник GPU | I25: Start | J25: Finish | K25: Hours
  B26-E26: Трап тех (C26: Start, D26: Finish, E26: Hours)
  F27: Конд/AC.U | I27: Start | J27: Finish | K27: Hours
-->
<?php $li=$HT_L['STAIRS_PAX']; $ri=$HT_R['GPU']; ?>
<tr>
  <!-- B25: Предоставление трапа пассаж-го — rowspan=2 по образцу (B26 объединён с B25) -->
  <td rowspan="2" class="sn">Предоставление трапа пассаж-го</td>
  <td class="qe">&nbsp;</td><!-- C25: Start -->
  <td class="qe">&nbsp;</td><!-- D25: Finish -->
  <td class="<?= _qc($li) ?>"><?= _q($li) ?></td><!-- E25: Hours -->
  <td colspan="4" class="sn">Утроник GPU</td>
  <td class="qe">&nbsp;</td><!-- I25: Start/Finish -->
  <td class="<?= _qc($ri) ?>"><?= _q($ri) ?></td><!-- K25: Hours -->
</tr>
<?php $li=$HT_L['STAIRS_TECH']; $ri=$HT_R['ACU']; ?>
<tr>
  <!-- B26 объединён с B25 (rowspan выше), C26-E26 — строка трапа тех -->
  <td class="hL" style="font-size:7pt;">Start time</td>
  <td class="hL" style="font-size:7pt;">Finish time</td>
  <td class="hL" style="font-size:7pt;">Hours/час</td>
  <td colspan="4" class="sn">Конд /AC.U</td>
  <td class="qe">&nbsp;</td><!-- Start/Finish -->
  <td class="<?= _qc($ri) ?>"><?= _q($ri) ?></td><!-- Hours -->
</tr>
<!-- Строка значений для "Трап для тех обслуж ВС" -->
<tr>
  <td class="sn">Трап для тех обслуж ВС</td>
  <td class="qe">&nbsp;</td>
  <td class="qe">&nbsp;</td>
  <td class="<?= _qc($li) ?>"><?= _q($li) ?></td>
  <td colspan="6" class="qe">&nbsp;</td>
</tr>

<!--
  B28: Main services/ основные услуги | C28-D28: (C пусто) D28: Ед-ца измер. | E28: кол-во
  Правая часть (F28-K28): заголовок доп. услуг (по образцу нет отдельного заголовка справа на этой строке)
-->
<tr>
  <td colspan="2" class="hL" style="text-align:left; padding-left:4px;">Main services/ основные услуги</td>
  <td class="hL">Ед-ца измер.</td>
  <td class="hL">кол-во</td>
  <td colspan="4" class="hL">&nbsp;</td><!-- доп. услуги — нет отдельного заголовка -->
  <td class="hL">Ед-ца измер.</td>
  <td class="hL">кол-во</td>
</tr>

<?php
// Строки Handling main (B29-B42) + Additional (F29-F46)
// Точные названия из образца
$hmRows = [
  ['PAX_ARR',   'Обслуживание PAX на прилет/ PAX Service on ARR',   'Пас/PAX'],
  ['PAX_DEP',   'Обслуживание PAX на вылет/ PAX Service on Depart',  'Пас/PAX'],
  ['BAGGAGE',   'Обработка багажа / Baggage Handling (мест,кг)',      'мест'],
  ['BUS_PAX',   'Автобус для пассажира/ Bus for Pax',                'unit'],
  ['BUS_CREW',  'Автобус для экипажа / Crew Bus',                    'unit'],
  ['AMBULIFT',  'Амбулифт / PRM Truck',                              'unit'],
  ['AMBULANCE', 'Машина скорой помощи /Ambulance car',               'unit'],
  ['PUSHBACK',  'Буксировка ВС / Push back (Towing)',                'unit'],
  ['W_B',       'Центровка / Weightt&Balance',                       'unit'],
  ['LOAD_CTL',  'Контроль загрузки /Load control',                   'unit'],
  ['SUPERV',    'Контроль обслуживания/ Supervision',                'unit'],
  ['WATER',     'Заправка водой /Water service',                     'unit'],
  ['TOILET',    'MA 7/ Toilet service',                              'unit'],
  ['WCHR',      'Инвалидное кресло / WCHR service',                  'unit'],
  // ПРИМЕЧАНИЕ (21.08.2026, раунд 24): в раунде 22 сюда временно
  // добавлялись 2 переставленные строки (DEICE_CTRL/AUTOLIFT), но по
  // сверке с эталонным образцом бухгалтера (раунд 24) эта перестановка
  // отменена — см. комментарий у $haRows ниже. Здесь была 1 пустая
  // строка-заглушка (под CRANE, ей естественной пары в Main services нет).
  //
  // ЗАМЕНЕНО (04.09.2026, раунд 27): эта пустая строка-заглушка занята
  // новой услугой BAG_BELT (Использование багажного транспортёра) —
  // по просьбе пользователя (аудит 03.09.2026, п.6.2): использование
  // багажного транспортёра при погрузке на ВС — САМОСТОЯТЕЛЬНАЯ услуга,
  // отдельная от BAGGAGE (обработка багажа с терминала), со своей ценой
  // и своим тех.персоналом. См. также requires_tech_personnel: снят с
  // BAG_ARR_PC и перенесён на BAG_BELT — см. миграцию
  // round27_new_services.sql. Ширина таблицы не меняется — строка была
  // и остаётся пустой заглушкой под CRANE справа, просто теперь печатает
  // содержимое вместо пустоты.
  ['BAG_BELT', 'Использование багажного транспортёра / Baggage conveyor belt', 'ед.'],
];
// Правая колонка (F29-F46) — точно по образцу
// F29: Стремянка/Stepladders (I29: Start, J29: Finish, K29: Hours)
// F31: Тех персонал для обслуж. (I31: за 1 ед-цу спец техники Unit/USD)
// F33: Прием выпуск ВС / Marshalling and etc. (J33: Ед-ца измер., K33: кол-во — отдельный заголовок)
// F35: Рейсовая документация
// F36: Обслуживание UM/UM Service (I36: кол-во J36: Час)
// F37: За систему DSC (J37: Pax)
// F38: CUTE system (J38: Pax)
// F39-F46: прочие
// ИЗМЕНЕНО (20.08.2026, раунд 22): DEICE_CTRL и AUTOLIFT были перенесены
// с конца списка на позиции 3 и 5, чтобы не пустовали строки напротив
// BAGGAGE/BUS_CREW слева.
// ОТМЕНЕНО (21.08.2026, раунд 24): сверка с эталонным Excel-образцом
// бухгалтера (скриншот, рейс VJT683) показала, что в каноничном шаблоне
// DEICE_CTRL и AUTOLIFT идут в исходном порядке, ПОДРЯД в конце списка
// вместе с CRANE (...Дополнительная центровка → Очистка от снега и
// льда → Контроль за удаление льда → Предоставление автолифта →
// Автокран/лестница) — по решению пользователя порядок вернул как в
// образце, приоритет отдан соответствию бланку бухгалтера, а не
// компактности пустых ячеек.
$haRows = [
  ['STEPLADDER',  'Стремянка/Stepladders',                                       'Start time'],
  ['GSE_TECH',    'Тех персоналдля обслуж./ Tech pers for handling with GSE',   'за 1 ед-цу спец техники Unit/USD'],
  ['MARSHALLING', 'Прием выпуск ВС / Marshalling and etc.',                     'рейс'],
  ['FLIGHT_DOC',  'Рейсовая документация / Flght Doc.',                         'рейс'],
  ['UM_SVC',      'Обслуживание UM/ UM Service',                                'кол-во / Час'],
  ['DCS',         'За систему DSC',                                             'Pax'],
  ['CUTE',        'CUTE system /За систему CUTE',                               'Pax'],
  ['AIR_START',   'Воздушный запуск двигателей.',                               'unit'],
  ['O2_SVC',      'Заправка кислородом',                                        'unit'],
  ['AIR_COMP',    'Заправка сжатым воздухом',                                   'unit'],
  ['W_B_ADD',     'Дополнительная центровка',                                   'рейс'],
  ['DEICE_SNW',   'Очистка от снега и льда',                                    'MTOW'],
  ['DEICE_CTRL',  'Контроль за удаление льда',                                  'unit'],
  ['AUTOLIFT',    'Предоставление автолифта',                                   'unit'],
  ['CRANE',       'Автокран/лестница',                                          'unit'],
];
$maxHM = max(count($hmRows), count($haRows));
for ($i = 0; $i < $maxHM; $i++):
    $lr = $hmRows[$i] ?? [null, '', ''];
    $rr = $haRows[$i] ?? [null, '', ''];
    $li = ($lr[0] && isset($HM[$lr[0]])) ? $HM[$lr[0]] : null;
    $ri = ($rr[0] && isset($HA[$rr[0]])) ? $HA[$rr[0]] : null;
?>
<tr>
  <td colspan="2" class="sn"><?= $lr[1] ?></td>
  <td class="uc"><?= $lr[2] ?></td>
  <td class="<?= ($lr[0] !== null) ? _qc($li) : 'qe' ?>"><?= ($lr[0] !== null) ? _q($li) : '&nbsp;' ?></td>
  <td colspan="4" class="sn" style="word-break:break-word;"><?= $rr[1] ?></td>
  <td class="uc" style="font-size:5pt;"><?= $rr[2] ?></td>
  <td class="<?= ($rr[0] !== null) ? _qc($ri) : 'qe' ?>"><?= ($rr[0] !== null) ? _q($ri) : '&nbsp;' ?></td>
</tr>
<?php endfor; ?>

</tbody>
</table>

<?php /* ИСПРАВЛЕНО (21.08.2026, раунд 23): найден реальный баг шаблона —
         открывающие <table class="F">/<tbody> для этого второго блока
         (Прочие услуги + ИТОГО + подписи) стояли ВНУТРИ `if (!empty($other))`,
         а закрывающие </tbody></table> в самом низу файла — БЕЗ условия.
         Пока в акте не было "прочих" несопоставленных услуг (обычный
         случай — у всех проверенных актов их не было), таблица вообще не
         открывалась, и строки ИТОГО/QR/подписей печатались КАК ОТДЕЛЬНЫЕ
         <tr> вне какой-либо <table> — раньше это визуально проскакивало
         (браузер молча "чинит" разметку через неявную таблицу), но при
         более сложной раскладке (раунд 23, подписи рядом с QR) поломка
         стала заметна — пропадали фон/границы у ячеек. Теперь
         <table>/<colgroup>/<tbody> открываются ВСЕГДА, а условным
         (`if (!empty($other))`) остаётся только сам блок "Прочие" (разрыв
         страницы, повтор шапки, строки прочих услуг) — как и было
         задумано изначально. */ ?>
<table class="F">
<colgroup>
  <col class="cB"><col class="cC"><col class="cD"><col class="cE"><col class="cF">
  <col class="cG"><col class="cH"><col class="cI"><col class="cJ"><col class="cK">
</colgroup>
<tbody>
<?php if (!empty($other)): ?>
<!-- ══ ПРОЧИЕ УСЛУГИ — вторая страница ══ -->
<div style="page-break-before:always;"></div>
<!-- Повторяем шапку на второй странице: номер акта + авиакомпания -->
<tr>
  <td colspan="3" style="font-size:8pt; padding:2px 5px; background:#edf0f8;">
    <strong>Форма А — <?= $actTitle ?></strong> &nbsp;|&nbsp;
    <?= e($act['airline_name']) ?> &nbsp;|&nbsp;
    Рейс: <?= e($act['flight_number']) ?>
  </td>
  <td colspan="7" style="font-size:8pt; padding:2px 5px; background:#edf0f8; text-align:right;">
    <?= e($act['origin_iata'] ?? '') ?> → <?= e($act['destination_iata'] ?? '') ?> &nbsp;|&nbsp;
    <?= format_date($act['flight_date'], 'd.m.Y') ?> &nbsp;|&nbsp;
    <em>продолжение / continued</em>
  </td>
</tr>
<!-- Заголовок блока Прочие -->
<tr>
  <td colspan="3" class="hB" style="font-size:8.5pt;">Прочие / Other services:</td>
  <td class="hL" style="text-align:center;">Ед. изм.</td>
  <td colspan="3" class="hL" style="text-align:center;">Кол-во</td>
  <td colspan="3" colspan="2" class="hL" style="text-align:right;">Сумма / Amount</td>
</tr>
<?php foreach ($other as $o): ?>
<tr>
  <td colspan="3" class="sn"><?= e($o['service_name_snapshot']) ?></td>
  <td class="uc"><?= e($o['unit_name_snapshot'] ?? '') ?></td>
  <td colspan="3" class="<?= _qc($o) ?>" style="text-align:center;"><?= _q($o) ?></td>
  <td colspan="3" colspan="2" style="text-align:right; font-weight:bold; font-size:8pt; padding-right:6px; white-space:nowrap;">
    <?= _a($o, $cur) ?>
  </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>

<!-- ══ ИТОГО (своя строка, во всю ширину) ═══════════════════════════════ -->
<?php /* ИЗМЕНЕНО (21.08.2026, раунд 23): раньше ИТОГО делило строку с QR
         (colspan 3+7) — по просьбе пользователя подписи (супервайзер +
         представитель авиакомпании) теперь стоят прямо по бокам от QR-кода
         в одной строке, а для этого ИТОГО вынесено в отдельную компактную
         строку над ними (см. блок ПОДПИСИ+QR ниже). Сама сумма и способ
         оплаты не изменились, только расположение. */ ?>
<tr>
  <td colspan="10" style="padding:4px 7px; vertical-align:middle;">
    <div style="display:flex; align-items:baseline; gap:14px; flex-wrap:wrap;">
      <div style="font-size:8pt; color:#444;">
        ИТОГО / TOTAL (<?= $cur ?>) &nbsp; <?= $isIntl ? 'НДС 0%' : 'НДС 12%' ?>
      </div>
      <div style="font-size:15pt; font-weight:bold; color:#1a3a6b; line-height:1.2;">
        <?= number_format((float)$act['total_amount_with_vat'], 2, '.', ' ') ?>
      </div>
      <div style="font-size:8pt;">
        Payment: <?= chk($ptype === 'cash') ?> Cash / наличные &nbsp;&nbsp;
                 <?= chk($ptype !== 'cash') ?> Credit/ кредит
      </div>
    </div>
  </td>
</tr>

<!-- ══ ПОДПИСИ + QR — супервайзер слева от кода, представитель справа ═══ -->
<tr>
  <!-- Аэропорт: colspan=4 -->
  <td colspan="4" style="font-weight:bold; font-size:8pt; padding:2px 5px;
      border-right:1.5pt solid #000; background:#edf0f8;">
    СУПЕРВАЙЗЕР ЦОК / COK Supervisor —
    «Toshkent-Humo xalqaro aeroporti» MChJ
  </td>
  <!-- QR: colspan=2, без подписи-заголовка -->
  <td colspan="2" style="border-right:1.5pt solid #000; background:#edf0f8;">&nbsp;</td>
  <!-- Авиакомпания: colspan=4 -->
  <td colspan="4" style="font-weight:bold; font-size:8pt; padding:2px 5px;
      background:#edf0f8;">
    ПРЕДСТАВИТЕЛЬ АВИАКОМПАНИИ / Airline Representative —
    «<?= e($act['airline_name']) ?>»
    <?php if (!empty($act['handler_name'])): ?>
    <div style="font-weight:normal; font-size:6.5pt; color:#555;">хендлер: <?= e($act['handler_name']) ?></div>
    <?php endif; ?>
  </td>
</tr>
<tr>
  <!-- ── Сторона аэропорта ── -->
  <td colspan="4" style="padding:4px 5px; border-right:1.5pt solid #000; vertical-align:top; height:28mm;">
    <table style="width:100%; border-collapse:collapse; font-size:8pt;">
      <tr>
        <td style="border:0; padding:0 0 1px 0; white-space:nowrap; color:#555; width:32px;">ФИО:</td>
        <td style="border:0; border-bottom:0.5pt solid #000; padding:0 2px 1px 2px; font-weight:bold;">
          <?= e($supervisorName) ?>
        </td>
      </tr>
      <tr><td colspan="2" style="border:0; height:4px;"></td></tr>
      <tr>
        <td style="border:0; padding:0 0 1px 0; white-space:nowrap; color:#555;">Должность:</td>
        <td style="border:0; border-bottom:0.5pt solid #000; padding:0 2px 1px 2px; font-size:7.5pt; color:#777;">
          Сотрудник ЦОК
        </td>
      </tr>
      <tr><td colspan="2" style="border:0; height:8px;"></td></tr>
      <tr>
        <td style="border:0; padding:0 0 1px 0; white-space:nowrap; color:#555;">Подпись:</td>
        <td style="border:0; border-bottom:0.5pt solid #000; padding:0 2px 1px 2px; height:22px; vertical-align:bottom;">
          <?php if (!empty($act['supervisor_signature_file_id'])): ?>
          <img src="/acts/signature/<?= (int)$act['supervisor_signature_file_id'] ?>" style="max-height:20px; max-width:140px;" alt="">
          <?php else: ?>&nbsp;<?php endif; ?>
        </td>
      </tr>
      <tr><td colspan="2" style="border:0; height:4px;"></td></tr>
      <tr>
        <td style="border:0; padding:0 0 1px 0; white-space:nowrap; color:#555;">Дата:</td>
        <td style="border:0; border-bottom:0.5pt solid #000; padding:0 2px 1px 2px; font-weight:bold;">
          <?= $supervisorDate ?>
        </td>
      </tr>
    </table>
  </td>
  <!-- ── QR-код ── -->
  <td colspan="2" style="text-align:center; padding:3px; vertical-align:middle; border-right:1.5pt solid #000;">
    <?php if ($qrBase64): ?>
      <img src="<?= $qrBase64 ?>" style="width:19mm; height:19mm; display:block; margin:0 auto;">
      <div style="font-size:6pt; font-family:monospace; margin-top:1px;"><?= $verifyCode ?></div>
      <div style="font-size:6pt; color:#777;">Сканировать для проверки</div>
    <?php elseif (!$isFinalized): ?>
      <div style="font-size:7pt; color:#b00; font-style:italic;">ПРЕДВАРИТЕЛЬНО<br>без подписей — не акт</div>
    <?php endif; ?>
  </td>
  <!-- ── Сторона авиакомпании ── -->
  <td colspan="4" style="padding:4px 5px; vertical-align:top; height:28mm;">
    <table style="width:100%; border-collapse:collapse; font-size:8pt;">
      <tr>
        <td style="border:0; padding:0 0 1px 0; white-space:nowrap; color:#555; width:32px;">ФИО:</td>
        <td style="border:0; border-bottom:0.5pt solid #000; padding:0 2px 1px 2px; font-weight:bold;">
          <?= !empty($act['airline_rep_name']) ? e($act['airline_rep_name']) : '&nbsp;' ?>
        </td>
      </tr>
      <tr><td colspan="2" style="border:0; height:4px;"></td></tr>
      <tr>
        <td style="border:0; padding:0 0 1px 0; white-space:nowrap; color:#555;">Должность:</td>
        <td style="border:0; border-bottom:0.5pt solid #000; padding:0 2px 1px 2px; font-size:7.5pt; color:#777;">
          <?= !empty($act['airline_rep_position']) ? e($act['airline_rep_position']) : '&nbsp;' ?>
        </td>
      </tr>
      <tr><td colspan="2" style="border:0; height:8px;"></td></tr>
      <tr>
        <td style="border:0; padding:0 0 1px 0; white-space:nowrap; color:#555;">Подпись:</td>
        <td style="border:0; border-bottom:0.5pt solid #000; padding:0 2px 1px 2px; height:22px; vertical-align:bottom;">
          <?php if (!empty($act['airline_rep_signature_file_id'])): ?>
          <img src="/acts/signature/<?= (int)$act['airline_rep_signature_file_id'] ?>" style="max-height:20px; max-width:140px;" alt="">
          <?php else: ?>&nbsp;<?php endif; ?>
        </td>
      </tr>
      <tr><td colspan="2" style="border:0; height:4px;"></td></tr>
      <tr>
        <td style="border:0; padding:0 0 1px 0; white-space:nowrap; color:#555;">Дата:</td>
        <td style="border:0; border-bottom:0.5pt solid #000; padding:0 2px 1px 2px;">
          <?= !empty($act['airline_rep_name']) ? $supervisorDate : '&nbsp;' ?>
        </td>
      </tr>
    </table>
  </td>
</tr>

</tbody>
</table>
</div>
</body>
</html>