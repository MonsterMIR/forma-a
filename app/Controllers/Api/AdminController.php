<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Act;
use App\Models\ActItem;
use App\Models\Aircraft;
use App\Models\Flight;
use App\Services\ParkingStandService;

/**
 * ДОБАВЛЕНО (07.11.2026, раунд 156, по прямому указанию пользователя):
 * read-only JSON-API для пилотной версии Android-приложения — роль admin
 * ("просмотр для руководства"). Ничего не пишет в БД (кроме служебного
 * обновления last_activity_at сессии, см. App\Core\ApiAuth), только
 * переиспользует уже существующие read-only модели/сервисы — никакой
 * бизнес-логики здесь заново не считается, чтобы не разойтись с веб-
 * версией (см. docblock App\Core\ApiAuth и round154/round108a про "единый
 * источник правды").
 *
 * ДОБАВЛЕНО (07.11.2026, раунд 157, по прямому указанию пользователя):
 * summary() теперь даёт разбивку рейсов сегодня/завтра по типу операции
 * (прилёт/вылет) и актов сегодня по статусу (утверждён/не утверждён) —
 * раньше были только общие числа. Также добавлен actDetail() — детальный
 * просмотр одного акта (шапка + строки услуг), переиспользует
 * Act::findWithDetails() и ActItem::getByAct() — те же методы, что уже
 * использует веб-страница /acts/{id} (см. ActController::show()).
 */
class AdminController extends ApiController
{
    public function summary(array $params = []): void
    {
        $this->requireApiRole(['admin']);

        $db = Database::getInstance();
        $today    = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $monthStart = date('Y-m-01');

        // Рейсы — сегодня/завтра (не отменённые), разбивка по прилёт/вылет
        // (раунд 157), и сколько сейчас "в работе" (нет акта, либо акт ещё
        // не approved/exported) — то же базовое условие, что и в
        // ServiceOrderController::dashboard().
        $flightsStmt = $db->prepare(
            "SELECT
                SUM(f.flight_date = ? AND f.status != 'cancelled') AS today,
                SUM(f.flight_date = ? AND f.status != 'cancelled' AND f.operation_type = 'arrival') AS today_arrivals,
                SUM(f.flight_date = ? AND f.status != 'cancelled' AND f.operation_type = 'departure') AS today_departures,
                SUM(f.flight_date = ? AND f.status != 'cancelled') AS tomorrow,
                SUM(f.flight_date = ? AND f.status != 'cancelled' AND f.operation_type = 'arrival') AS tomorrow_arrivals,
                SUM(f.flight_date = ? AND f.status != 'cancelled' AND f.operation_type = 'departure') AS tomorrow_departures,
                SUM(f.status != 'cancelled' AND (a.id IS NULL OR a.status NOT IN ('approved','exported'))) AS in_progress
             FROM `flights` f
             LEFT JOIN `acts` a ON a.flight_id = f.id"
        );
        $flightsStmt->execute([$today, $today, $today, $tomorrow, $tomorrow, $tomorrow]);
        $flightCounts = $flightsStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Акты — черновики (ещё не утверждены, за всё время) и
        // утверждённые/экспортированные в текущем календарном месяце, с
        // суммой по каждой валюте (как и раньше) + отдельно разбивка актов
        // именно СЕГОДНЯШНЕЙ датой акта по статусу (раунд 157).
        $actsDraftStmt = $db->prepare("SELECT COUNT(*) FROM `acts` WHERE status = 'draft'");
        $actsDraftStmt->execute();
        $actsDraftCount = (int)$actsDraftStmt->fetchColumn();

        $actsTodayStmt = $db->prepare(
            "SELECT
                COUNT(*) AS today_total,
                SUM(status IN ('approved', 'exported')) AS today_approved,
                SUM(status NOT IN ('approved', 'exported')) AS today_not_approved
             FROM `acts`
             WHERE act_date = ?"
        );
        $actsTodayStmt->execute([$today]);
        $actsToday = $actsTodayStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $actsMonthStmt = $db->prepare(
            "SELECT c.code AS currency_code,
                    COUNT(*) AS acts_count,
                    SUM(a.total_amount_with_vat) AS total_with_vat
             FROM `acts` a
             JOIN `currencies` c ON c.id = a.settlement_currency_id
             WHERE a.status IN ('approved', 'exported') AND a.act_date >= ?
             GROUP BY c.code"
        );
        $actsMonthStmt->execute([$monthStart]);
        $actsMonthByCurrency = $actsMonthStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Борта — всего в справочнике, на земле сейчас, базирующиеся
        // (round154) — переиспользуем готовый метод, не пересчитываем сами.
        $aircraftRows = (new Aircraft())->getAllWithFleetStatus();
        $aircraftTotal   = count($aircraftRows);
        $aircraftOnGround = count(array_filter($aircraftRows, fn($r) => !empty($r['on_ground'])));
        $aircraftBased    = count(array_filter($aircraftRows, fn($r) => !empty($r['is_based'])));

        // Стоянки — занято/всего, тот же сервис, что и /parking-stands.
        $standsOccupied = 0;
        $standsTotal    = 0;
        try {
            foreach ((new ParkingStandService())->getStatusBoard() as $row) {
                $standsTotal++;
                if (!empty($row['occupied'])) {
                    $standsOccupied++;
                }
            }
        } catch (\Throwable $e) {
            error_log('Api\\AdminController::summary getStatusBoard error: ' . $e->getMessage());
        }

        $this->json([
            'success' => true,
            'data'    => [
                'flights' => [
                    'today'               => (int)($flightCounts['today'] ?? 0),
                    'today_arrivals'      => (int)($flightCounts['today_arrivals'] ?? 0),
                    'today_departures'    => (int)($flightCounts['today_departures'] ?? 0),
                    'tomorrow'            => (int)($flightCounts['tomorrow'] ?? 0),
                    'tomorrow_arrivals'   => (int)($flightCounts['tomorrow_arrivals'] ?? 0),
                    'tomorrow_departures' => (int)($flightCounts['tomorrow_departures'] ?? 0),
                    'in_progress'         => (int)($flightCounts['in_progress'] ?? 0),
                ],
                'acts' => [
                    'draft_count'         => $actsDraftCount,
                    'today_total'         => (int)($actsToday['today_total'] ?? 0),
                    'today_approved'      => (int)($actsToday['today_approved'] ?? 0),
                    'today_not_approved'  => (int)($actsToday['today_not_approved'] ?? 0),
                    'month_by_currency'   => array_map(static fn($r) => [
                        'currency_code'  => $r['currency_code'],
                        'acts_count'     => (int)$r['acts_count'],
                        'total_with_vat' => (float)$r['total_with_vat'],
                    ], $actsMonthByCurrency),
                ],
                'aircraft' => [
                    'total'     => $aircraftTotal,
                    'on_ground' => $aircraftOnGround,
                    'based'     => $aircraftBased,
                ],
                'parking_stands' => [
                    'occupied' => $standsOccupied,
                    'total'    => $standsTotal,
                ],
            ],
        ]);
    }

    public function flights(array $params = []): void
    {
        $this->requireApiRole(['admin']);

        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = min(50, max(1, (int)$this->request->get('per_page', 20)));
        $offset  = ($page - 1) * $perPage;

        $filters = [
            'search'         => $this->request->get('search', ''),
            'route_type'     => $this->request->get('route_type', ''),
            'operation_type' => $this->request->get('operation_type', ''),
            'date_from'      => $this->request->get('date_from', ''),
            'date_to'        => $this->request->get('date_to', ''),
        ];

        $model = new Flight();
        $rows  = $model->getFiltered($filters, $perPage, $offset);
        $total = $model->countFiltered($filters);

        $this->json([
            'success'    => true,
            'data'       => array_map([$this, 'formatFlight'], $rows),
            'pagination' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'last_page' => (int)max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    public function acts(array $params = []): void
    {
        $this->requireApiRole(['admin']);

        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = min(50, max(1, (int)$this->request->get('per_page', 20)));
        $offset  = ($page - 1) * $perPage;

        $filters = [
            'search'     => $this->request->get('search', ''),
            'status'     => $this->request->get('status', ''),
            'route_type' => $this->request->get('route_type', ''),
            'date_from'  => $this->request->get('date_from', ''),
            'date_to'    => $this->request->get('date_to', ''),
        ];

        $model = new Act();
        $rows  = $model->getFiltered($filters, $perPage, $offset);
        $total = $model->countFiltered($filters);

        $this->json([
            'success'    => true,
            'data'       => array_map([$this, 'formatAct'], $rows),
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int)max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * ДОБАВЛЕНО (07.11.2026, раунд 157, по прямому указанию пользователя):
     * детальный просмотр одного акта (шапка + строки услуг) — то же, что
     * видно на веб-странице /acts/{id}, только в JSON и без истории/файлов
     * (это read-only просмотр для руководства, не полноценная карточка акта).
     */
    public function actDetail(array $params = []): void
    {
        $this->requireApiRole(['admin']);

        $id = (int)($params['id'] ?? 0);
        $act = (new Act())->findWithDetails($id);
        if (!$act) {
            $this->json(['success' => false, 'error' => 'not_found'], 404);
            return;
        }

        $items = (new ActItem())->getByAct($id);

        $this->json([
            'success' => true,
            'data'    => [
                'act' => [
                    'id'                    => (int)$act['id'],
                    'act_number'            => (!empty($act['number_finalized'])) ? $act['act_number'] : null,
                    'status'                => $act['status'],
                    'act_date'              => $act['act_date'],
                    'flight_number'         => $act['flight_number'],
                    'flight_date'           => $act['flight_date'],
                    'aircraft_type'         => $act['aircraft_type'] ?? null,
                    'aircraft_registration' => $act['aircraft_registration'] ?? null,
                    'mtow'                  => $act['mtow'] !== null ? (float)$act['mtow'] : null,
                    'mc_number'             => $act['mc_number'] ?? null,
                    'airline_name'          => $act['airline_name'],
                    'airline_iata'          => $act['airline_iata'] ?? null,
                    'origin_name'           => $act['origin_name'] ?? null,
                    'origin_iata'           => $act['origin_iata'] ?? null,
                    'destination_name'      => $act['destination_name'] ?? null,
                    'destination_iata'      => $act['destination_iata'] ?? null,
                    'currency_code'         => $act['currency_code'],
                    'currency_symbol'       => $act['currency_symbol'] ?? null,
                    'created_by_username'   => $act['created_by_username'] ?? null,
                    'created_by_fullname'   => $act['created_by_fullname'] ?? null,
                    'approved_by_username'  => $act['approved_by_username'] ?? null,
                    'approved_by_fullname'  => $act['approved_by_fullname'] ?? null,
                    'total_amount_without_vat' => (float)$act['total_amount_without_vat'],
                    'total_vat_amount'         => (float)$act['total_vat_amount'],
                    'total_amount_with_vat'    => (float)$act['total_amount_with_vat'],
                ],
                'items' => array_map(static fn($i) => [
                    'line_no'            => (int)$i['line_no'],
                    'service_name'       => $i['service_name_snapshot'],
                    'category_name'      => $i['category_name'] ?? null,
                    'unit_name'          => $i['unit_name_snapshot'],
                    'quantity'           => (float)$i['quantity'],
                    'unit_price'         => (float)$i['unit_price'],
                    'currency_code'      => $i['currency_code'],
                    'amount_without_vat' => (float)$i['amount_without_vat'],
                    'vat_rate'           => (float)$i['vat_rate'],
                    'vat_amount'         => (float)$i['vat_amount'],
                    'amount_with_vat'    => (float)$i['amount_with_vat'],
                ], $items),
            ],
        ]);
    }

    public function aircraft(array $params = []): void
    {
        $this->requireApiRole(['admin']);

        $rows = (new Aircraft())->getAllWithFleetStatus();
        $this->json([
            'success' => true,
            'data'    => array_map([$this, 'formatAircraft'], $rows),
        ]);
    }

    public function parkingStands(array $params = []): void
    {
        $this->requireApiRole(['admin']);

        $board = (new ParkingStandService())->getStatusBoard();
        $this->json([
            'success' => true,
            'data'    => array_map(static function (array $row): array {
                $flight = $row['flight'] ?? null;
                return [
                    'code'           => $row['stand']['code'],
                    'is_wide'        => !empty($row['stand']['is_wide']),
                    'occupied'       => !empty($row['occupied']),
                    'occupied_via'   => $row['occupied_via'] ?? null,
                    'occupied_since' => $row['occupied_since'] ?? null,
                    'flight_number'  => $flight['flight_number'] ?? null,
                    'airline_name'   => $flight['airline_name'] ?? null,
                ];
            }, $board),
        ]);
    }

    private function formatFlight(array $f): array
    {
        return [
            'id'              => (int)$f['id'],
            'flight_number'   => $f['flight_number'],
            'airline_name'    => $f['airline_name'] ?? null,
            'airline_iata'    => $f['airline_iata'] ?? null,
            'flight_date'     => $f['flight_date'],
            'scheduled_time'  => $f['scheduled_time'],
            'operation_type'  => $f['operation_type'],
            'route_type'      => $f['route_type'],
            'status'          => $f['status'] ?? 'active',
            'origin_iata'      => $f['origin_iata'] ?? $f['origin_name'] ?? null,
            'destination_iata' => $f['destination_iata'] ?? $f['destination_name'] ?? null,
            'aircraft_registration' => $f['aircraft_registration'] ?? null,
            'aircraft_mtow'   => $f['aircraft_mtow'] !== null ? (float)$f['aircraft_mtow'] : null,
            'mc_number'       => $f['mc_number'] ?? null,
            'act_id'          => $f['act_id'] !== null ? (int)$f['act_id'] : null,
            'act_status'      => $f['act_status'] ?? null,
            'act_number'      => (!empty($f['act_number_finalized'])) ? $f['act_number'] : null,
            'manifest_status' => $f['manifest_status'] ?? null,
        ];
    }

    private function formatAct(array $a): array
    {
        return [
            'id'                   => (int)$a['id'],
            'act_number'           => (!empty($a['number_finalized'])) ? $a['act_number'] : null,
            'status'               => $a['status'],
            'act_date'             => $a['act_date'],
            'flight_number'        => $a['flight_number'],
            'flight_date'          => $a['flight_date'],
            'airline_name'         => $a['airline_name'],
            'currency_code'        => $a['currency_code'],
            'total_amount_without_vat' => (float)$a['total_amount_without_vat'],
            'total_vat_amount'         => (float)$a['total_vat_amount'],
            'total_amount_with_vat'    => (float)$a['total_amount_with_vat'],
            'created_by_username'  => $a['created_by_username'] ?? null,
            'approved_by_username' => $a['approved_by_username'] ?? null,
        ];
    }

    private function formatAircraft(array $r): array
    {
        return [
            'registration'   => $r['registration'],
            'aircraft_type'  => $r['reg_aircraft_type'] ?? $r['flight_aircraft_type'] ?? null,
            'mtow'           => $r['mtow'] !== null ? (float)$r['mtow'] : null,
            'is_based'       => !empty($r['is_based']),
            'based_note'     => $r['based_note'] ?? null,
            'on_ground'      => !empty($r['on_ground']),
            'airline_name'   => $r['airline_name'] ?? null,
            'mc_number'      => $r['mc_number'] ?? null,
            'last_flight_number' => $r['flight_number'] ?? null,
            'last_flight_date'   => $r['flight_date'] ?? null,
        ];
    }
}
