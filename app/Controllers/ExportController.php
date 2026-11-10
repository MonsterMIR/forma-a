<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Act;
use App\Models\ActItem;

/**
 * ExportController — экспорт актов в 1С:Бухгалтерия (Узбекистан) 3.0
 *
 * Формат: V8Exchange XML 2.1.8
 * Документ в 1С: "Реализация услуг: Акт"
 * Загрузка: Администрирование → Универсальный обмен данными → Загрузка данных
 */
class ExportController extends Controller
{
    private Act     $actModel;
    private ActItem $itemModel;

    // UID номенклатуры в 1С: "код|тип_маршрута" → UUID
    // Получены из реальной базы 1С (экспорт справочников)
    private const NOM = [
        'TLF|international'      => 'd6456b42-2f2d-11f1-ba42-00155d139105',
        'TLF|domestic'           => '044bf3ae-2f66-11f1-ba42-00155d139105',
        'AV_SEC|international'   => 'd6509539-2f2e-11f1-ba42-00155d139105',
        'AV_SEC|domestic'        => 'e480f0ba-3000-11f1-ba42-00155d139105',
        'NIGHT|international'    => 'c462d679-2f32-11f1-ba42-00155d139105',
        'NIGHT|domestic'         => '337953df-3003-11f1-ba42-00155d139105',
        'OVER_PARK|international'=> 'c462d679-2f32-11f1-ba42-00155d139105',
        'OVER_PARK|domestic'     => '337953df-3003-11f1-ba42-00155d139105',
        'MET_50|international'   => 'c032f96e-2f33-11f1-ba42-00155d139105',
        'MET_50|domestic'        => 'eb41a294-3021-11f1-ba42-00155d139105',
        'MET_100|international'  => 'da1b582d-2f62-11f1-ba42-00155d139105',
        'MET_100|domestic'       => '72547e8c-2f67-11f1-ba42-00155d139105',
        'MET_101|international'  => 'da1b582d-2f62-11f1-ba42-00155d139105',
        'MET_101|domestic'       => '72547e8c-2f67-11f1-ba42-00155d139105',
        'PAX_DEP|international'  => 'f2b2b5af-2f37-11f1-ba42-00155d139105',
        'PAX_DEP|domestic'       => '011a06cf-2f67-11f1-ba42-00155d139105',
        'PAX_ARR|international'  => 'b4274e92-2f38-11f1-ba42-00155d139105',
        'PAX_ARR|domestic'       => 'a63665a6-3004-11f1-ba42-00155d139105',
        'PAX_SEC|international'  => 'fb23f656-2f38-11f1-ba42-00155d139105',
        'PAX_SEC|domestic'       => '0a184981-2f68-11f1-ba42-00155d139105',
        'GPU|international'      => 'b538b567-3339-11f1-ba42-00155d139105',
        'GPU|domestic'           => 'f51075f5-3370-11f1-ba42-00155d139105',
        'PUSHBACK|international' => '6994829b-3344-11f1-ba42-00155d139105',
        'PUSHBACK|domestic'      => 'f92e273a-336f-11f1-ba42-00155d139105',
        'BRIDGE|international'   => '5a318962-335d-11f1-ba42-00155d139105',
        'BRIDGE|domestic'        => 'dbd60ad4-336f-11f1-ba42-00155d139105',
        'BUS_PAX|international'  => '195e4176-2f40-11f1-ba42-00155d139105',
        'BUS_PAX|domestic'       => '8d728a33-2f68-11f1-ba42-00155d139105',
    ];

    // UID контрагентов (авиакомпании): IATA → UUID в 1С
    private const AIRLINES = [
        'HY' => '6a66eb9e-6139-11ef-ba42-00155d139105', // Uzbekistan Airways
        'ZR' => 'da2f423d-16ff-11f1-ba42-00155d139105', // Samarqand Airways
    ];

    public function __construct(
        \App\Core\Request  $request,
        \App\Core\Response $response
    ) {
        parent::__construct($request, $response);
        $this->actModel  = new Act();
        $this->itemModel = new ActItem();
    }

    // ── СТРАНИЦА ЭКСПОРТА ─────────────────────────────────
    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'finance']);
        $this->render('export/index', [
            'title'        => 'Экспорт в 1С',
            'approvedActs' => $this->actModel->getApprovedByPeriod(date('Y-m-01'), date('Y-m-d')),
        ]);
    }

    // ── ОДИН АКТ ──────────────────────────────────────────
    public function exportXml(array $params = []): void
    {
        $this->requireRole(['admin', 'finance', 'cok']);
        $actId = (int)($params['id'] ?? 0);
        $act   = $this->actModel->findWithDetails($actId);

        if (!$act) { http_response_code(404); echo 'Акт не найден'; return; }
        if ($act['status'] !== 'approved') {
            http_response_code(400); echo 'Только утверждённые акты'; return;
        }

        $xml = $this->buildXml([$act], [$actId => $this->itemModel->getByAct($actId)]);
        $fn  = 'FormaA_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $act['act_number']) . '.xml';
        $this->sendXml($xml, $fn);
    }

    // ── ПАКЕТ ЗА ПЕРИОД ──────────────────────────────────
    public function exportXmlBatch(array $params = []): void
    {
        $this->requireRole(['admin', 'finance']);
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');
        $acts = $this->actModel->getApprovedByPeriod($from, $to);

        if (empty($acts)) { http_response_code(404); echo 'Нет актов за период'; return; }

        $items = [];
        foreach ($acts as $a) $items[$a['id']] = $this->itemModel->getByAct((int)$a['id']);

        $xml = $this->buildXml($acts, $items);
        $fn  = 'FormaA_' . date('Ymd', strtotime($from)) . '_' . date('Ymd', strtotime($to)) . '.xml';
        $this->sendXml($xml, $fn);
    }

    // ── ГЕНЕРАЦИЯ XML (формат ФайлОбмена для УОД 2.1.8) ────
    private function buildXml(array $acts, array $allItems): string
    {
        $d = new \DOMDocument('1.0', 'UTF-8');
        $d->formatOutput = true;

        $root = $d->createElement('ФайлОбмена');
        $root->setAttribute('ВерсияФормата',                '2.1.8');
        $root->setAttribute('ДатаВыгрузки',                date('Y-m-d\TH:i:s'));
        $root->setAttribute('ИмяКонфигурацииИсточника',    'FormaA');
        $root->setAttribute('ИмяКонфигурацииПриемника',    'БухгалтерияПредприятияУзбекистан');
        $root->setAttribute('УникальныйИдентификатор',     $this->newGuid());
        $root->setAttribute('Комментарий',                 'Forma A — аэропортовые услуги TVT');
        $d->appendChild($root);

        foreach ($acts as $act) {
            $items = $allItems[$act['id']] ?? [];
            $this->buildDoc($d, $root, $act, $items);
        }

        return $d->saveXML();
    }

    private function newGuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function buildDoc(\DOMDocument $d, \DOMElement $root, array $act, array $items): void
    {
        $isIntl = ($act['route_type'] ?? '') === 'international';
        $iata   = $act['airline_iata'] ?? '';

        $doc = $d->createElement('Объект');
        $doc->setAttribute('Тип',              'Документ.РеализацияТоваровУслуг');
        $doc->setAttribute('ИмяПредставления', 'Реализация услуг: Акт ' . $act['act_number']);
        $doc->setAttribute('НаименованиеТипа', 'РеализацияТоваровУслуг');
        $root->appendChild($doc);

        // Основные реквизиты
        $this->r($d, $doc, 'Номер',        $act['act_number']);
        $this->r($d, $doc, 'АктНомер',     $act['act_number']);
        $this->r($d, $doc, 'Дата',         date('Y-m-d\TH:i:s', strtotime($act['act_date'])));
        $this->r($d, $doc, 'Проведен',     'true');
        $this->r($d, $doc, 'ВидОперации',  'ОказаниеУслуг');
        $this->r($d, $doc, 'СуммаВключаетНДС', 'false');

        // Контрагент (авиакомпания)
        $uid = self::AIRLINES[$iata] ?? '';
        $this->ref($d, $doc, 'Контрагент',
            'Справочник.uzbled_Контрагенты', $uid,
            ($act['airline_name'] ?? '') . ' (' . $iata . ')');

        // Валюта и курс
        $this->ref($d, $doc, 'ВалютаДокумента', 'Справочник.Валюты', '',
            $act['currency_code'] ?? 'USD');
        $this->r($d, $doc, 'КурсВалютыДокумента',
            number_format((float)$act['cb_rate'], 6, '.', ''));

        // Доп. реквизиты рейса
        $extras = $d->createElement('ДополнительныеРеквизиты');
        $doc->appendChild($extras);
        $this->prop($d, $extras, 'НомерРейса',  $act['flight_number'] ?? '');
        $this->prop($d, $extras, 'ДатаРейса',   date('Y-m-d', strtotime($act['flight_date'] ?? 'now')));
        $this->prop($d, $extras, 'Маршрут',     ($act['origin_iata'] ?? '') . '→' . ($act['destination_iata'] ?? ''));
        $this->prop($d, $extras, 'ТипМаршрута', $isIntl ? 'Международный' : 'Внутренний');
        $this->prop($d, $extras, 'ТипОперации', ($act['operation_type'] ?? '') === 'arrival' ? 'Прилёт' : 'Вылет');
        $this->prop($d, $extras, 'ТипВС',       $act['aircraft_type'] ?? '');
        $this->prop($d, $extras, 'MTOW',        (string)($act['aircraft_mtow'] ?? ''));

        // Табличная часть Услуги
        $ts = $d->createElement('ТабличнаяЧасть');
        $ts->setAttribute('Имя', 'Услуги');
        $doc->appendChild($ts);

        $line = 0;
        foreach ($items as $item) {
            $line++;
            $code   = $item['service_code'] ?? '';
            $nomUid = self::NOM[$code . '|' . ($act['route_type'] ?? '')] ?? '';
            $cbRate = (float)$act['cb_rate'];

            $row = $d->createElement('Запись');
            $ts->appendChild($row);

            $this->r($d, $row, 'НомерСтроки', (string)$line);
            $this->ref($d, $row, 'Номенклатура', 'Справочник.Номенклатура',
                $nomUid, $item['service_name_snapshot']);
            $this->r($d, $row, 'Количество', number_format((float)$item['quantity'], 4, '.', ''));
            // Цена в валюте расчёта (USD * курс = UZS/RUB/EUR)
            $price = (float)$item['unit_price'] * ($cbRate > 0 ? $cbRate : 1);
            $this->r($d, $row, 'Цена',    number_format($price, 2, '.', ''));
            $this->r($d, $row, 'Сумма',   number_format((float)$item['amount_without_vat'], 2, '.', ''));
            $this->r($d, $row, 'СтавкаНДС', $isIntl ? '0%' : '12%');
            $this->r($d, $row, 'СуммаНДС', number_format((float)$item['vat_amount'], 2, '.', ''));
            $this->r($d, $row, 'Всего',   number_format((float)$item['amount_with_vat'], 2, '.', ''));
            $this->r($d, $row, 'ВидНоменклатуры', 'ОказаниеУслуг');
            if ($item['calculation_note']) {
                $this->r($d, $row, 'Комментарий', $item['calculation_note']);
            }
            // Если нет UUID — пишем имя для ручного сопоставления в 1С
            if (!$nomUid) {
                $this->r($d, $row, 'ПоискПоНаименованию', $item['service_name_snapshot']);
            }
        }

        // Итоги
        $this->r($d, $doc, 'СуммаДокумента', number_format((float)$act['total_amount_with_vat'], 2, '.', ''));
        $this->r($d, $doc, 'СуммаНДС',       number_format((float)$act['total_vat_amount'], 2, '.', ''));
    }

    // ── ЭКСПОРТ В EXCEL ──────────────────────────────────
    // GET /acts/{id}/export/excel — скачать Excel для загрузки в 1С
    // ─────────────────────────────────────────────────────
    public function exportExcel(array $params = []): void
    {
        $this->requireRole(['admin', 'finance', 'cok']);
        $actId = (int)($params['id'] ?? 0);
        $act   = $this->actModel->findWithDetails($actId);

        if (!$act) { http_response_code(404); echo 'Акт не найден'; return; }
        if ($act['status'] !== 'approved') {
            http_response_code(400); echo 'Только утверждённые акты'; return;
        }

        $items = $this->itemModel->getByAct($actId);
        $csv   = $this->buildCsv($act, $items);

        $filename = 'FormaA_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $act['act_number']) . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');
        // BOM для Excel (чтобы кириллица отображалась правильно)
        echo "ï»¿" . $csv;
    }

    public function exportExcelBatch(array $params = []): void
    {
        $this->requireRole(['admin', 'finance']);
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');
        $acts = $this->actModel->getApprovedByPeriod($from, $to);

        if (empty($acts)) { http_response_code(404); echo 'Нет актов'; return; }

        $rows = [];
        // Заголовок
        $rows[] = implode(';', [
            'Акт №', 'Дата акта', 'Контрагент', 'Валюта', 'Курс ЦБ',
            'Номенклатура', 'ИКПУ', 'Количество', 'Цена',
            'Сумма', '% НДС', 'НДС', 'Всего',
            'Вид операции', 'Комментарий'
        ]);

        foreach ($acts as $act) {
            $items = $this->itemModel->getByAct((int)$act['id']);
            foreach ($items as $item) {
                $rows[] = $this->buildCsvRow($act, $item);
            }
        }

        $filename = 'FormaA_' . date('Ymd', strtotime($from)) . '_' . date('Ymd', strtotime($to)) . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "ï»¿" . implode("\n", $rows);
    }

    private function buildCsv(array $act, array $items): string
    {
        $rows = [];
        $rows[] = implode(';', [
            'Акт №', 'Дата акта', 'Контрагент', 'Валюта', 'Курс ЦБ',
            'Номенклатура', 'ИКПУ', 'Количество', 'Цена',
            'Сумма', '% НДС', 'НДС', 'Всего',
            'Вид операции', 'Комментарий'
        ]);
        foreach ($items as $item) {
            $rows[] = $this->buildCsvRow($act, $item);
        }
        return implode("\n", $rows);
    }

    private function buildCsvRow(array $act, array $item): string
    {
        $isIntl  = ($act['route_type'] ?? '') === 'international';
        $cbRate  = (float)$act['cb_rate'];
        $vatRate = (float)$item['vat_rate'];

        // Количество и цена в валюте расчёта (как в 1С документе)
        $qty   = (float)$item['quantity'];
        // Цена в валюте расчёта = unit_price_usd * cb_rate
        $price = (float)$item['unit_price'] * ($cbRate > 0 ? $cbRate : 1);
        $sum   = round($qty * $price, 2);

        // Комментарий с данными рейса (как в 1С: "BC FA-8X MTOW 34t маршрут OOMC-UZTP")
        $comment = ($act['aircraft_type'] ?? '') . ' MTOW ' . ($act['aircraft_mtow'] ?? '') . 'т'
                 . ' ' . ($act['origin_iata'] ?? '') . '-' . ($act['destination_iata'] ?? '')
                 . ' ' . date('d.m.Y', strtotime($act['flight_date']));

        $fields = [
            // Шапка документа
            $act['act_number'],                                           // Акт №
            date('d.m.Y', strtotime($act['act_date'])),                  // Дата акта
            $act['airline_name'] . ' (' . ($act['airline_iata'] ?? '') . ')', // Контрагент
            $act['currency_code'],                                        // Валюта
            number_format($cbRate, 2, '.', ''),                          // Курс ЦБ
            // Табличная часть — точно как в 1С
            $item['service_name_snapshot'],                               // Номенклатура
            '',                                                           // ИКПУ (пусто — 1С подставит)
            number_format($qty, 4, '.', ''),                             // Количество
            number_format($price, 2, '.', ''),                           // Цена (в валюте)
            number_format($sum, 2, '.', ''),                             // Сумма
            number_format($vatRate, 0) . '%',                            // % НДС
            number_format((float)$item['vat_amount'], 2, '.', ''),      // НДС
            number_format((float)$item['amount_with_vat'], 2, '.', ''), // Всего
            'Оказание услуг',                                             // Вид операции
            $comment,                                                     // Комментарий
        ];

        return implode(';', array_map(fn($f) => '"' . str_replace('"', '""', $f) . '"', $fields));
    }

    // ── Хелперы ──────────────────────────────────────────
    private function r(\DOMDocument $d, \DOMElement $p, string $name, string $val): void
    {
        $el = $d->createElement('Реквизит');
        $el->setAttribute('Имя', $name);
        $el->appendChild($d->createTextNode($val));
        $p->appendChild($el);
    }

    private function ref(\DOMDocument $d, \DOMElement $p, string $name,
                          string $type, string $uid, string $label): void
    {
        $el  = $d->createElement('Реквизит');
        $el->setAttribute('Имя', $name);
        $ref = $d->createElement('Ссылка');
        $ref->setAttribute('ТипОбъекта', $type);
        if ($uid) $ref->setAttribute('УникальныйИдентификатор', $uid);
        $ref->setAttribute('НаименованиеОбъекта', $label);
        $el->appendChild($ref);
        $p->appendChild($el);
    }

    private function prop(\DOMDocument $d, \DOMElement $p, string $name, string $val): void
    {
        $el = $d->createElement('ДополнительныйРеквизит');
        $el->setAttribute('Наименование', $name);
        $el->appendChild($d->createTextNode($val));
        $p->appendChild($el);
    }

    private function sendXml(string $xml, string $filename): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');
        echo $xml;
    }

    // =====================================================
    // JSON API для интеграции с внешними системами (1С и др.)
    // GET /export/api?from=2026-01-01&to=2026-04-30
    // GET /export/api/{act_number}
    // =====================================================
    public function apiActs(array $params = []): void
    {
        $this->requireRole(['admin', 'finance', 'cok']);

        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');
        $acts = $this->actModel->getApprovedByPeriod($from, $to);

        $result = [];
        foreach ($acts as $act) {
            $result[] = [
                'id'                     => $act['id'],
                'act_number'             => $act['act_number'],
                'act_date'               => $act['act_date'],
                'status'                 => $act['status'],
                'airline_name'           => $act['airline_name'],
                'airline_iata'           => $act['airline_iata'],
                'flight_number'          => $act['flight_number'],
                'flight_date'            => $act['flight_date'],
                'origin_iata'            => $act['origin_iata'] ?? '',
                'destination_iata'       => $act['destination_iata'] ?? '',
                'route_type'             => $act['route_type'],
                'operation_type'         => $act['operation_type'],
                'currency_code'          => $act['currency_code'],
                'cb_rate'                => (float)$act['cb_rate'],
                'aircraft_type'          => $act['aircraft_type'] ?? '',
                'aircraft_mtow'          => (float)($act['aircraft_mtow'] ?? 0),
                'total_amount_without_vat' => (float)$act['total_amount_without_vat'],
                'total_vat_amount'       => (float)$act['total_vat_amount'],
                'total_amount_with_vat'  => (float)$act['total_amount_with_vat'],
                'approved_by'            => $act['approved_by_fullname'] ?? '',
                'approved_at'            => $act['approved_at'] ?? '',
            ];
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function apiActDetail(array $params = []): void
    {
        $this->requireRole(['admin', 'finance', 'cok']);

        // Поиск по act_number (например 0001/HY-0001)
        $actNumber = urldecode($params['act_number'] ?? '');
        $act = null;

        // Ищем по номеру
        $allActs = $this->actModel->getApprovedByPeriod('2020-01-01', date('Y-m-d'));
        foreach ($allActs as $a) {
            if ($a['act_number'] === $actNumber) {
                $act = $a;
                break;
            }
        }

        if (!$act) {
            http_response_code(404);
            echo json_encode(['error' => 'Act not found: ' . $actNumber]);
            return;
        }

        $items = $this->itemModel->getByAct((int)$act['id']);
        $itemsData = [];
        foreach ($items as $item) {
            $itemsData[] = [
                'line_no'               => $item['line_no'],
                'service_code'          => $item['service_code'] ?? '',
                'service_name'          => $item['service_name_snapshot'],
                'unit'                  => $item['unit_name_snapshot'] ?? '',
                'quantity'              => (float)$item['quantity'],
                'unit_price'            => (float)$item['unit_price'],
                'amount_without_vat'    => (float)$item['amount_without_vat'],
                'vat_rate'              => (float)$item['vat_rate'],
                'vat_amount'            => (float)$item['vat_amount'],
                'amount_with_vat'       => (float)$item['amount_with_vat'],
                'calculation_note'      => $item['calculation_note'] ?? '',
            ];
        }

        $result = array_merge($act, ['items' => $itemsData]);

        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}