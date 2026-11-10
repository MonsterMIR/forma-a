<?php

namespace App\Services;

class PermissionParser
{
    const UZTP = 'UZTP';
    // Альтернативные обозначения аэропорта в разрешениях
    private const UZTP_ALIASES = ['UZTP', 'TVT'];

    // Маппинг кодов из разрешений ЦАА Узбекистана (UZ-серия) → официальные ICAO (UT-серия в БД).
    // ЦАА использует UZ-префикс в своих документах, тогда как ICAO регистр узбекских
    // аэропортов — UT-серия. UZTP — исключение (Хумо/TVT — отдельная запись в БД).
    private const UZ_TO_UT = [
        'UZTT' => 'UTTT', // Ташкент (Каримов)
        'UZSS' => 'UTSS', // Самарканд
        'UZSB' => 'UTSB', // Бухара
        'UZSA' => 'UTSA', // Навои
        'UZKF' => 'UTKF', // Фергана
        'UZNU' => 'UTNU', // Ургенч
        'UZST' => 'UTST', // Термез
        'UZNN' => 'UTNN', // Нукус
        'UZKA' => 'UTKA', // Андижан
        'UZSK' => 'UTSK', // Карши
    ];

    /** Нормализует ICAO-код: UZ-серия → UT-серия (кроме UZTP) */
    public function normalizeIcao(string $icao): string
    {
        $icao = strtoupper(trim($icao));
        return self::UZ_TO_UT[$icao] ?? $icao;
    }

    private function isUztp(string $icao): bool
    {
        return in_array(strtoupper($icao), self::UZTP_ALIASES, true);
    }

    // Месяцы для конвертации дат
    private array $months = [
        'JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,
        'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12
    ];

    /**
     * Главный метод: парсит текст разрешения и возвращает только UZTP-рейсы
     */
    public function parse(string $text): array
    {
        $text = $this->normalize($text);
        $flights = [];

        // Формат 1 — REVISION / Tarmac Ops
        if (stripos($text, 'REVISION') !== false || stripos($text, 'TARMAC OPS') !== false) {
            $flights = array_merge($flights, $this->parseRevisionFormat($text));
        }

        // Форматы 2/3/4 — CAA UZB standard
        if (stripos($text, 'CAA UZB') !== false || stripos($text, 'PERM NUM') !== false) {
            $flights = array_merge($flights, $this->parseCAAFormat($text));
        }

        // Формат 5 — внутреннее уведомление ЦОК о планировании (НЕ
        // разрешение ЦАУ) — ДОБАВЛЕНО (23.10.2026, раунд 124). Маркер —
        // характерная шапка такого документа; не пересекается с
        // маркерами REVISION/CAA UZB выше.
        if (stripos($text, 'PLANIROWANIJA') !== false || stripos($text, 'SOOBQAJU') !== false) {
            $flights = array_merge($flights, $this->parsePlanningNoticeFormat($text));
        }

        // Fallback: если ни один маркер не найден — пробуем оба парсера
        // (на случай если формат документа отличается от ожидаемого)
        if (empty($flights)) {
            $flights = array_merge($flights, $this->parseCAAFormat($text));
        }
        if (empty($flights)) {
            $flights = array_merge($flights, $this->parseRevisionFormat($text));
        }

        return array_values($flights);
    }

    // ─────────────────────────────────────────────────────────────────
    // ФОРМАТ 1 — REVISION (Tarmac Ops)
    // Пример: 15MAY26 NWC9033 UUWW 1630 – UZTP 1950 PAX04
    // ─────────────────────────────────────────────────────────────────
    private function parseRevisionFormat(string $text): array
    {
        $flights = [];

        preg_match('/OPERATOR\s*:\s*(.+)/i', $text, $m); $operator = trim($m[1] ?? '');
        preg_match('/CALL\s*SIGN\s*:\s*(.+)/i', $text, $m); $callSignRaw = trim($m[1] ?? '');
        preg_match('/REG\s*:\s*(\S+)/i', $text, $m); $reg = trim($m[1] ?? '');
        preg_match('/TYPE\s*:\s*(.+)/i', $text, $m); $aircraftType = trim($m[1] ?? '');
        preg_match('/MTOW\s*:\s*(\S+)/i', $text, $m); $mtow = trim($m[1] ?? '');
        preg_match('/TYPE\s*OF\s*FLIGHT\s*:\s*(.+)/i', $text, $m); $flightType = trim($m[1] ?? 'COMMERCIAL');

        // Разбираем несколько callsign-ов: NWC9033 / NWC9034
        $callSigns = array_map('trim', preg_split('/[\s\/]+/', $callSignRaw));

        // Строка расписания: 15MAY26 NWC9033 UUWW 1630 – UZTP 1950 PAX04
        $pattern = '/(\d{2}[A-Z]{3}\d{2})\s+(\S+)\s+([A-Z]{4})\s+(\d{4})\s*[-]\s*([A-Z]{4})\s+(\d{4})(?:\s+PAX(\d+))?/';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $leg) {
            $date     = $this->parseShortDate($leg[1]);
            $cs       = $leg[2];
            $fromIcao = strtoupper($leg[3]);
            $depTime  = substr($leg[4], 0, 2) . ':' . substr($leg[4], 2, 2);
            $toIcao   = strtoupper($leg[5]);
            $arrTime  = substr($leg[6], 0, 2) . ':' . substr($leg[6], 2, 2);
            $pax      = isset($leg[7]) && $leg[7] !== '' ? (int)$leg[7] : null;

            if ($this->isUztp($fromIcao)) {
                $flights[] = $this->buildFlight($cs, $reg, $aircraftType, $operator, null,
                    'DEP', $this->normalizeIcao($toIcao), $date, $depTime, $pax, $flightType, null, $mtow);
            } elseif ($this->isUztp($toIcao)) {
                $flights[] = $this->buildFlight($cs, $reg, $aircraftType, $operator, null,
                    'ARR', $this->normalizeIcao($fromIcao), $date, $arrTime, $pax, $flightType, null, $mtow);
            }
        }

        return $flights;
    }

    // ─────────────────────────────────────────────────────────────────
    // ФОРМАТЫ 2/3/4 — CAA UZB standard
    // Поддерживает несколько разрешений в одном тексте
    // ─────────────────────────────────────────────────────────────────
    private function parseCAAFormat(string $text): array
    {
        $flights = [];

        // Разбиваем на блоки по НАЧАЛУ каждого отдельного разрешения.
        // ИСПРАВЛЕНО (17.08.2026): раньше резали по /(?=CAA UZB \d)/i — по
        // ЛЮБОМУ вхождению "CAA UZB <цифра>" в тексте, без привязки к началу
        // строки. Это совпадало с упрощённым примером-плейсхолдером на
        // странице импорта (там "CAA UZB .../.../... TVT" — буквально первая
        // строка блока), но НЕ с реальными разрешениями ЦАА Узбекистана —
        // в них строка "PERM NUM: CAA UZB NNN/MM/YYYY ..." стоит НЕ в начале
        // документа, а ПОСРЕДИ него, после шапки ATTN/APPL NUMBER/FOR FLT
        // (оператор, тип ВС, рег. номер). Из-за этого один-единственный
        // документ ошибочно резался на ДВА блока прямо посреди себя: блок 1 —
        // только шапка (оператор/тип ВС/рег.номер), БЕЗ ног рейса; блок 2 —
        // только ноги рейса (даты/аэропорты/время), БЕЗ шапки. Каждый блок
        // парсится независимо (см. цикл ниже) — в итоге рейсы строились по
        // блоку 2 (даты и маршрут распознавались верно), а оператор/тип ВС/
        // рег.номер терялись, потому что были только в блоке 1, где не было
        // ни одной "ноги" рейса для их использования.
        // Теперь режем по НАЧАЛУ СТРОКИ (^, многострочный режим): либо
        // "CAA UZB <цифра>" (плейсхолдер-формат — совпадает, только когда
        // это первая строка блока, не встречается посреди строки "PERM NUM:
        // CAA UZB ..."), либо "ATTN:" — именно с неё реально начинается
        // каждое разрешение ЦАА (проверено на документе, присланном
        // пользователем: "CORRECTION/2" в начале — не маркер, а служебная
        // пометка письма, дальше сразу идёт "ATTN: ...").
        $blocks = preg_split('/(?=^(?:CAA UZB \d|ATTN:))/im', $text);

        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) continue;

            // Оператор и ICAO
            // ИСПРАВЛЕНО (23.10.2026, раунд 118): нашли в логах апача
            // "findOrCreateAirline INSERT error: ... Data too long for
            // column 'icao_code'" (airlines.icao_code — char(3), ИКАО-код
            // всегда ровно 3 буквы). Причина — старое \w* после "ICAO:" не
            // ограничено длиной, а в разрешении формат "ICAO:   IATA:"
            // (ICAO-поле у этого оператора пустое, сразу следом идёт
            // следующий лейбл IATA:). Без пробела-разделителя между пустым
            // значением и следующим лейблом \w* жадно захватывал само
            // слово "IATA" (4 буквы) как будто это ICAO-код оператора —
            // подтверждено на реальном документе (ALPHA WINGS, LLC,
            // разр. 280/10/2026, рейсы #136/#137 — оператор в итоге вообще
            // потерялся, авиакомпания завелась как "Unknown Operator").
            // Теперь ICAO явно ограничен ровно 3 буквами и привязан к
            // следующему литералу "IATA:" — если поле пустое, группа не
            // совпадёт вообще (а не захватит часть соседнего лейбла).
            preg_match('/ATTN:\s*(.+?)\s+ICAO:\s*([A-Z]{3})?\s*IATA:/i', $block, $m);
            $operator     = trim($m[1] ?? '');
            $operatorIcao = !empty(trim($m[2] ?? '')) ? strtoupper(trim($m[2])) : null;

            // Воздушное судно
            // FOR FLT: T7AAA7/T7AAA7 TYPE: GL7T REG: T7AAA7
            // ИСПРАВЛЕНО (21.08.2026): точный тип/борт иногда ещё не выбран
            // на момент выдачи разрешения — ЦАУ пишет обе альтернативы через
            // " OR " (напр. "TYPE: FA6X OR FA7X REG: TCMRK OR TCSSA"). Старое
            // регулярное выражение ожидало ровно одно слово в каждом поле —
            // при "OR" оно не совпадало вообще, из-за чего FOR FLT целиком не
            // распознавался, и рейс создавался без типа ВС, без рег. номера
            // И БЕЗ ПОЗЫВНОГО (т.к. callsign — тоже часть этого же regex).
            // Теперь берём первую альтернативу как основное значение, а
            // вторую (если есть) — отдельно, для примечания (см. ниже, где
            // формируются $notes в FlightController::importConfirm()) —
            // ЦОК увидит обе и при необходимости поправит вручную, когда
            // борт будет подтверждён.
            preg_match('/FOR\s+FLT:\s*(\S+(?:\/\S+)?)\s+TYPE:\s*(\w+)(?:\s+OR\s+(\w+))?\s+REG:\s*(\S+)(?:\s+OR\s+(\S+))?/i', $block, $m);
            $callSignRaw   = trim($m[1] ?? '');
            $aircraftType  = strtoupper(trim($m[2] ?? ''));
            $aircraftType2 = strtoupper(trim($m[3] ?? ''));
            $reg           = strtoupper(trim($m[4] ?? ''));
            $reg2          = strtoupper(trim($m[5] ?? ''));

            // Если callsign повторяется через / берём уникальные.
            // array_values() — ИСПРАВЛЕНО (17.08.2026): array_unique() сохраняет
            // исходные ключи массива, а не переиндексирует с 0 — без этого
            // сопоставление "нога рейса N → позывной N" ниже могло получить
            // "дыру" в индексах и не найти позывной там, где он реально есть.
            $callSignParts = array_values(array_unique(array_map('trim', explode('/', $callSignRaw))));
            $baseCallSign  = $callSignParts[0] ?? $reg;

            // Номер разрешения
            preg_match('/PERM\s*NUM:\s*(.+?)(?:\n|DATED)/i', $block, $m);
            $permNum = trim($m[1] ?? '');

            // Цель
            preg_match('/PURPOSE:\s*(.+)/i', $block, $m);
            $purpose = trim($m[1] ?? 'BUSINESS');

            // ДОБАВЛЕНО (22.09.2026, раунд 51, по прямому указанию пользователя):
            // строка "PAYMENT IN A/P <аэропорт> ACC AGR WITH <хендлер>" в
            // разрешении ЦАУ прямо называет хендлера, который фактически
            // обслуживает рейс на перроне — это НЕ обязательно тот же
            // хендлер, что привязан к авиакомпании в справочнике "Хендлеры"
            // по умолчанию (одна авиакомпания может летать через разных
            // хендлеров на разных рейсах — как раз пример из двух реальных
            // разрешений: SAMARKAND AIRWAYS и TARMAC-OPS для разных бортов).
            // Пример строки: "PAYMENT IN A/P TASHKENT-VOSTOCHNY ACC AGR WITH
            // SAMARKAND AIRWAYS". Название хендлера — остаток строки после
            // "ACC AGR WITH", обрезаем от лишних пробелов/финальной точки.
            preg_match('/ACC\s+AGR\s+WITH\s+(.+)/i', $block, $m);
            $handlerName = trim($m[1] ?? '');
            $handlerName = $handlerName !== '' ? rtrim($handlerName, ". \t") : null;

            // Ноги: REG ETD ICAO DDMONYYYY HH:MMZ ETA ICAO DDMONYYYY HH:MMZ
            // С поддержкой "+++" маркеров
            $legPattern = '/(\S+)\s+ETD\s+([A-Z]{4})\s+(\d{2}[A-Z]{3}\d{4})\s+(\d{2}:\d{2})Z\+*\s+ETA\s+([A-Z]{4})\+*\s+(\d{2}[A-Z]{3}\d{4})\s+(\d{2}:\d{2})Z/';
            preg_match_all($legPattern, $block, $legs, PREG_SET_ORDER);

            foreach ($legs as $legIndex => $leg) {
                $fromIcao = strtoupper($leg[2]);
                $depDate  = $this->parseLongDate($leg[3]);
                $depTime  = $leg[4];
                $toIcao   = strtoupper($leg[5]);
                $arrDate  = $this->parseLongDate($leg[6]);
                $arrTime  = $leg[7];

                // Позывной. ИСПРАВЛЕНО (17.08.2026): раньше при НЕСКОЛЬКИХ
                // РАЗНЫХ позывных в FOR FLT (напр. "QQE510/QQE735" — прилёт
                // одним номером рейса, вылет другим, обычная практика для
                // рейсовых бортов, не только для бизнес-джетов туда-обратно
                // одним номером) код всегда брал ПЕРВЫЙ позывной для ВСЕХ
                // ног — оба рейса (прилёт и вылет) получали ОДИНАКОВЫЙ номер.
                // Из-за этого второй рейс на шаге проверки дублей
                // (FlightController::importParse(), совпадение по
                // flight_number + flight_date) ошибочно определялся как
                // дубль первого и по умолчанию не выбирался для создания.
                // Теперь: если позывных объявлено НЕСКОЛЬКО — сопоставляем
                // с ногой ПО ПОРЯДКУ объявления (нога 0 → позывной 0, нога 1
                // → позывной 1 и т.д.); если позывной один — используем его
                // для всех ног, как и раньше (это верно для случая
                // туда-обратно одним номером); если FOR FLT вообще пуст —
                // берём позывной из самой строки ноги (часто это рег.номер).
                if (count($callSignParts) > 1 && !empty($callSignParts[$legIndex])) {
                    $cs = strtoupper($callSignParts[$legIndex]);
                } elseif (!empty($baseCallSign)) {
                    $cs = $baseCallSign;
                } else {
                    $cs = strtoupper($leg[1]);
                }

                // Если ЦАУ указал 2 альтернативы типа/борта ("... OR ...") —
                // складываем это в отдельную заметку, а не теряем молча:
                // основные aircraft_type/reg уже содержат первую альтернативу.
                $altParts = [];
                if (!empty($aircraftType2)) $altParts[] = "тип: {$aircraftType} или {$aircraftType2}";
                if (!empty($reg2))          $altParts[] = "борт: {$reg} или {$reg2}";
                $altNote = $altParts ? ('Уточнить у авиакомпании — ' . implode(', ', $altParts)) : null;

                if ($this->isUztp($fromIcao)) {
                    $flights[] = $this->buildFlight($cs, $reg, $aircraftType, $operator, $operatorIcao,
                        'DEP', $toIcao, $depDate, $depTime, null, $purpose, $permNum, null, $altNote, $handlerName);
                } elseif ($this->isUztp($toIcao)) {
                    $flights[] = $this->buildFlight($cs, $reg, $aircraftType, $operator, $operatorIcao,
                        'ARR', $fromIcao, $arrDate, $arrTime, null, $purpose, $permNum, null, $altNote, $handlerName);
                }
            }
        }

        return $flights;
    }


    // ─────────────────────────────────────────────────────────────────
    // ФОРМАТ 5 — внутреннее уведомление ЦОК о планировании (НЕ разрешение
    // ЦАУ Узбекистана — это другой документ: служебная записка о
    // предстоящих рейсах, которую ЦОК составляет сам/получает от
    // хендлера/заказчика, ДО или НЕЗАВИСИМО от получения самого
    // разрешения ЦАУ). ДОБАВЛЕНО (23.10.2026, раунд 124, по прямому
    // указанию пользователя, пример — MFX-801/802, MY FREIGHTER/CENTRUM
    // AIR, 24.10.2026): до этого раунда такой документ не распознавался
    // НИКАКИМ из парсеров (ни REVISION, ни CAA UZB) — не содержит ни
    // "REVISION"/"TARMAC OPS", ни "CAA UZB"/"PERM NUM", поэтому попадал
    // только в fallback-ветку parse() (пробуем оба — оба дают 0 рейсов).
    //
    // Пример документа:
    //   SUBJ:1.SOOBQAJU DLJA PLANIROWANIJA I OBESPECHENIJA
    //   ZAJAWLENNYH NA     24OCT-26   PAX PRIVATE FLTS  MFX-801/802
    //   TAS - TVT - SKD - TVT - TAS    A319NEO  / UK888
    //   PEREWOZKA PAX
    //   ZAKAZCHIK – MY FREIGHTER(CENTRUM AIR)
    //   WSE RASKHODY W A/P TAS,TVT - ZA SCHET MY FREIGHTER(CENTRUM AIR)
    //   ZAJAWLENNYJ GRAFIK DWIVENIJA UTC:
    //   24OCT2026  MFX801  TAS 0230 - TVT 0300     FERRY
    //   24OCT2026  MFX801  TVT 0500 - SKD 0600    10PAX
    //   24OCT2026  MFX802  SKD 1130 - TVT 1230    10PAX
    //   24OCT2026  MFX802  TVT 1330 - TAS 1400     FERRY
    //   OSNOVANIE: UKAZANIE RUKOWODSTWA MY FREIGHTER
    //
    // Отличия от CAA UZB, важные для разбора:
    // - Аэропорты даны IATA-кодами (3 буквы: TAS/TVT/SKD), а не ICAO
    //   (4 буквы) — findOrCreateAirport() в FlightController уже умеет
    //   резолвить 3-буквенный код через iata_code как запасной вариант,
    //   доп. маппинг в парсере не нужен; TVT и так в UZTP_ALIASES.
    // - Нет PERM NUM (номера разрешения ЦАУ) — это другой документ,
    //   свой номер не присваивается; вместо него поле "OSNOVANIE:"
    //   (основание) — передаём его в alt_note, а не в perm_num, чтобы в
    //   примечаниях рейса не появлялась обманчивая надпись "Разр.: ...".
    // - Каждая "нога" уже содержит СВОЙ позывной рейса (MFX801/MFX802 —
    //   туда и обратно разными номерами), в отличие от CAA-формата, где
    //   номер мог быть общим на весь документ — здесь берём позывной
    //   прямо из строки ноги, доп. сопоставление по индексу не нужно.
    private function parsePlanningNoticeFormat(string $text): array
    {
        $flights = [];

        preg_match('/ZAKAZCHIK\s*[-–—]\s*(.+)/i', $text, $m);
        $operatorRaw = trim($m[1] ?? '');
        // Отрезаем хвост в скобках только для поиска/создания авиакомпании
        // (напр. "MY FREIGHTER(CENTRUM AIR)" → "MY FREIGHTER") — иначе
        // findOrCreateAirline() не найдёт уже существующую запись
        // справочника (id=151, "My Freighter / Centum Air", раунд 95) по
        // LIKE-поиску и заведёт ВТОРУЮ, дублирующую запись оператора с
        // другой пунктуацией. Полный текст с уточнением в скобках не
        // теряем — уходит в alt_note, ЦОК увидит обе формы.
        $operator = trim(preg_replace('/\s*\(.*?\)\s*$/', '', $operatorRaw));
        $operatorNote = ($operatorRaw !== '' && $operatorRaw !== $operator)
            ? "Заказчик по документу: {$operatorRaw}" : null;

        preg_match('/OSNOVANIE:\s*(.+)/i', $text, $m);
        $osnovanie = trim($m[1] ?? '');

        // Тип ВС и регистрация: строка маршрута вида
        // "TAS - TVT - SKD - TVT - TAS    A319NEO  / UK888" — несколько
        // 3-4-буквенных кодов аэропортов через дефис, затем тип ВС и
        // "/ РЕГИСТРАЦИЯ" в конце строки.
        preg_match('/^\s*(?:[A-Z]{3,4}\s*-\s*)+[A-Z]{3,4}\s+([A-Z0-9]{3,8})\s*\/\s*([A-Z0-9\-]{2,8})\s*$/im', $text, $m);
        $aircraftType = strtoupper(trim($m[1] ?? ''));
        $reg          = strtoupper(trim($m[2] ?? ''));

        // Ноги рейса: ДАТА  ПОЗЫВНОЙ  ОТКУДА ЧЧММ - КУДА ЧЧММ  ПАКС/FERRY
        // Пример: "24OCT2026  MFX801  TAS 0230 - TVT 0300     FERRY"
        $legPattern = '/(\d{2}[A-Z]{3}\d{4})\s+(\S+)\s+([A-Z]{3,4})\s*(\d{4})\s*-\s*([A-Z]{3,4})\s*(\d{4})\s+(\d*PAX|FERRY)/i';
        preg_match_all($legPattern, $text, $legs, PREG_SET_ORDER);

        foreach ($legs as $leg) {
            $date     = $this->parseLongDate($leg[1]);
            $cs       = strtoupper($leg[2]);
            $fromIcao = strtoupper($leg[3]);
            $depTime  = substr($leg[4], 0, 2) . ':' . substr($leg[4], 2, 2);
            $toIcao   = strtoupper($leg[5]);
            $arrTime  = substr($leg[6], 0, 2) . ':' . substr($leg[6], 2, 2);
            $paxRaw   = strtoupper($leg[7]);
            $pax      = ($paxRaw === 'FERRY') ? 0 : (int)preg_replace('/[^0-9]/', '', $paxRaw);

            if ($this->isUztp($fromIcao)) {
                $flights[] = $this->buildFlight($cs, $reg, $aircraftType, $operator, null,
                    'DEP', $toIcao, $date, $depTime, $pax, 'PAX PRIVATE', null, null,
                    $this->joinNotes($operatorNote, $osnovanie));
            } elseif ($this->isUztp($toIcao)) {
                $flights[] = $this->buildFlight($cs, $reg, $aircraftType, $operator, null,
                    'ARR', $fromIcao, $date, $arrTime, $pax, 'PAX PRIVATE', null, null,
                    $this->joinNotes($operatorNote, $osnovanie));
            }
        }

        return $flights;
    }

    /** Склеивает непустые примечания через " | " — помощник для parsePlanningNoticeFormat() */
    private function joinNotes(?string ...$parts): ?string
    {
        $parts = array_filter(array_map('trim', array_filter($parts, fn($p) => $p !== null)));
        return $parts ? implode(' | ', $parts) : null;
    }
    // ─────────────────────────────────────────────────────────────────
    // Вспомогательные методы
    // ─────────────────────────────────────────────────────────────────

    private function buildFlight(
        string $callSign, string $reg, string $aircraftType,
        string $operator, ?string $operatorIcao,
        string $direction, string $otherAirport,
        string $date, string $timeUtc,
        ?int $pax, string $purpose, ?string $permNum, ?string $mtow,
        ?string $altNote = null,
        ?string $handlerName = null
    ): array {
        return [
            'call_sign'      => strtoupper($callSign),
            'reg'            => strtoupper($reg),
            'aircraft_type'  => $aircraftType,
            'operator'       => $operator,
            'operator_icao'  => $operatorIcao,
            'direction'      => $direction,
            'other_airport'  => strtoupper($otherAirport),
            'date'           => $date,
            'time_utc'       => $timeUtc,
            'pax'            => $pax,
            'purpose'        => strtoupper($purpose),
            'perm_num'       => $permNum,
            'mtow'           => $mtow,
            'alt_note'       => $altNote,
            // ДОБАВЛЕНО (22.09.2026, раунд 51): хендлер, распознанный из
            // строки "... ACC AGR WITH <хендлер>" — только для CAA UZB
            // формата (parseCAAFormat), в REVISION-формате такой строки нет,
            // поэтому там всегда null (вызовы buildFlight() из
            // parseRevisionFormat() не передают этот аргумент).
            'handler_name'   => $handlerName,
        ];
    }

    /** 15MAY26 → 2026-05-15 */
    private function parseShortDate(string $d): string
    {
        preg_match('/(\d{2})([A-Z]{3})(\d{2})/i', $d, $m);
        $year  = 2000 + (int)($m[3] ?? 0);
        $month = $this->months[strtoupper($m[2] ?? '')] ?? 1;
        $day   = (int)($m[1] ?? 1);
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** 13MAY2026 → 2026-05-13 */
    private function parseLongDate(string $d): string
    {
        preg_match('/(\d{2})([A-Z]{3})(\d{4})/i', $d, $m);
        $year  = (int)($m[3] ?? date('Y'));
        $month = $this->months[strtoupper($m[2] ?? '')] ?? 1;
        $day   = (int)($m[1] ?? 1);
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** Нормализует текст: убирает +++ и лишние пробелы */
    private function normalize(string $text): string
    {
        $text = preg_replace('/[\+\*]{2,}/', '', $text);  // убираем *** и +++ маркеры
        $text = str_replace(["\xe2\x80\x93", "\xe2\x80\x94", "\xe2\x80\x92"], "-", $text);
        $text = preg_replace('/[^\S\n]+/', ' ', $text);
        return $text;
    }
}