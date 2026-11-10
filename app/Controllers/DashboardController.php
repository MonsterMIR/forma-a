<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Permissions;

class DashboardController extends Controller
{
    private \PDO   $db;
    private string $today;
    private string $monthStart;

    public function index(array $params = []): void
    {
        $this->requireRole(array_keys(Permissions::allRoles()));

        $user = Auth::user();
        $role = $user['role_code'] ?? '';

        // ДОБАВЛЕНО (аудит 10.07.2026): у супервайзера отдельный, оптимизированный
        // под планшет экран — не нужен ещё один клик через общий дашборд.
        if ($role === 'supervisor') {
            $this->redirect('/supervisor');
            return;
        }

        // ДОБАВЛЕНО (21.09.2026, раунд 49, п.5): представитель не должен
        // попадать во внутренний дашборд ЦОК/служб вообще — у него свой,
        // отдельный /portal (см. RepresentativeController). requireRole()
        // выше проходит для этой роли, т.к. основана на Permissions::
        // allRoles() (общий список всех ролей системы для отображения в UI),
        // а не на правах доступа к этому конкретному экрану.
        if ($role === 'representative') {
            $this->redirect('/portal');
            return;
        }

        // ДОБАВЛЕНО (22.09.2026, раунд 51, п.3): аналогично 'representative'
        // выше — хендлер не должен попадать во внутренний дашборд ЦОК/служб,
        // у него свой отдельный /handler-cabinet (см. HandlerCabinetController).
        if ($role === 'handler_admin') {
            $this->redirect('/handler-cabinet');
            return;
        }

        $this->db         = Database::getInstance();
        $this->today      = date('Y-m-d');
        $this->monthStart = date('Y-m-01');

        $data = match(true) {
            in_array($role, ['admin', 'cok']) => $this->dataCok(),
            $role === 'sop'                   => $this->dataSop(),
            $role === 'spo'                   => $this->dataSpo(),
            $role === 'sst'                   => $this->dataSst(),
            $role === 'aero'                  => $this->dataAero(),
            $role === 'spasop'                => $this->dataSpasop(),
            $role === 'commercial'            => $this->dataCommercial(),
            $role === 'finance'               => $this->dataFinance(),
            default                           => [],
        };

        $this->render('dashboard/index', array_merge($data, [
            'title' => 'Рабочий стол',
            'role'  => $role,
            'user'  => $user,
        ]));
    }

    // ─── ЦОК / Администратор ──────────────────────────────────
    private function dataCok(): array
    {
        $db    = $this->db;
        $today = $this->today;

        // Счётчики
        $stats = [];

        $s = $db->prepare("SELECT COUNT(*) FROM flights WHERE flight_date = ?");
        $s->execute([$today]); $stats['flights_today'] = (int)$s->fetchColumn();

        $s = $db->prepare("SELECT COUNT(*) FROM flights WHERE flight_date = ? AND operation_type='arrival'");
        $s->execute([$today]); $stats['arrivals_today'] = (int)$s->fetchColumn();

        $s = $db->prepare("SELECT COUNT(*) FROM flights WHERE flight_date = ? AND operation_type='departure'");
        $s->execute([$today]); $stats['departures_today'] = (int)$s->fetchColumn();

        $s = $db->prepare("SELECT COUNT(*) FROM acts WHERE status='draft'");
        $s->execute(); $stats['acts_draft'] = (int)$s->fetchColumn();

        $s = $db->prepare("SELECT COUNT(*) FROM acts WHERE status='approved'");
        $s->execute(); $stats['acts_approved'] = (int)$s->fetchColumn();

        // Отклонения сегодня
        try {
            $s = $db->prepare("SELECT COUNT(*) FROM flight_deviations WHERE DATE(created_at)=?");
            $s->execute([$today]); $stats['deviations_today'] = (int)$s->fetchColumn();

            $s = $db->prepare("SELECT COUNT(*) FROM flight_deviations WHERE DATE(created_at)=? AND severity='critical'");
            $s->execute([$today]); $stats['critical_today'] = (int)$s->fetchColumn();
        } catch (\Throwable $e) {
            $stats['deviations_today'] = 0;
            $stats['critical_today']   = 0;
        }

        // Документы не поданы вовремя (просроченные pending)
        try {
            $s = $db->prepare(
                "SELECT COUNT(*) FROM flight_documents
                 WHERE status='pending' AND deadline_at IS NOT NULL AND deadline_at < NOW()"
            );
            $s->execute(); $stats['overdue_docs'] = (int)$s->fetchColumn();
        } catch (\Throwable $e) {
            $stats['overdue_docs'] = 0;
        }

        // Рейсы сегодня с деталями
        $s = $db->prepare(
            "SELECT f.*, al.name AS airline_name, al.iata_code AS airline_iata,
                    (SELECT COUNT(*) FROM acts a WHERE a.flight_id=f.id) AS acts_count,
                    (SELECT COUNT(*) FROM acts a WHERE a.flight_id=f.id AND a.status='draft') AS acts_draft,
                    (SELECT COUNT(*) FROM flight_documents fd WHERE fd.flight_id=f.id AND fd.status='pending' AND fd.deadline_at < NOW()) AS overdue_docs
             FROM flights f
             LEFT JOIN airlines al ON al.id=f.airline_id
             WHERE f.flight_date=?
             ORDER BY f.scheduled_time ASC, f.created_at DESC"
        );
        $s->execute([$today]);
        $todayFlights = $s->fetchAll(\PDO::FETCH_ASSOC);

        // Последние отклонения
        try {
            $s = $db->prepare(
                "SELECT fd.*, f.flight_number, f.flight_date, al.name AS airline_name
                 FROM flight_deviations fd
                 JOIN flights f ON f.id=fd.flight_id
                 JOIN airlines al ON al.id=f.airline_id
                 WHERE DATE(fd.created_at)=?
                 ORDER BY fd.created_at DESC LIMIT 5"
            );
            $s->execute([$today]);
            $recentDeviations = $s->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $recentDeviations = [];
        }

        // Акты требующие внимания
        // ИЗМЕНЕНО (06.08.2026): акты с обеими подписями теперь показаны
        // отдельным списком ниже (pendingConfirmationActs) — здесь их
        // исключаем, чтобы не дублировались в двух местах на дашборде.
        $s = $db->prepare(
            "SELECT a.*, f.flight_number, f.flight_date, al.name AS airline_name
             FROM acts a
             JOIN flights f ON f.id=a.flight_id
             JOIN airlines al ON al.id=f.airline_id
             WHERE a.status='draft'
               AND NOT (a.supervisor_signature_file_id IS NOT NULL AND a.airline_rep_signature_file_id IS NOT NULL)
             ORDER BY a.created_at DESC LIMIT 5"
        );
        $s->execute();
        $draftActs = $s->fetchAll(\PDO::FETCH_ASSOC);

        // ДОБАВЛЕНО (06.08.2026): акты, подписанные на планшете (обе подписи
        // есть), но ещё не утверждённые ЦОК — отдельный список над обычными
        // черновиками, чтобы не потерялись среди актов, которые вообще
        // никто ещё не трогал.
        $s = $db->prepare(
            "SELECT a.*, f.flight_number, f.flight_date, al.name AS airline_name
             FROM acts a
             JOIN flights f ON f.id=a.flight_id
             JOIN airlines al ON al.id=f.airline_id
             WHERE a.status='draft'
               AND a.supervisor_signature_file_id IS NOT NULL
               AND a.airline_rep_signature_file_id IS NOT NULL
             ORDER BY a.updated_at DESC LIMIT 10"
        );
        $s->execute();
        $pendingConfirmationActs = $s->fetchAll(\PDO::FETCH_ASSOC);

        return compact('stats', 'todayFlights', 'recentDeviations', 'draftActs', 'pendingConfirmationActs');
    }

    // ─── СОП — Служба организации перевозок ───────────────────
    private function dataSop(): array
    {
        $db    = $this->db;
        $today = $this->today;

        // Рейсы сегодня с состоянием манифеста.
        // УПРОЩЕНО (21.09.2026, раунд 49, п.2, по прямому указанию
        // пользователя): первичный (предварительный) манифест убран из
        // процесса — остался только один манифест (manifest_type='final'
        // в БД, см. докблок ManifestController::form()).
        $s = $db->prepare(
            "SELECT f.*, al.name AS airline_name, al.iata_code AS airline_iata,
                    pm_fin.status  AS manifest_fin_status,
                    pm_fin.pax_total AS manifest_fin_pax
             FROM flights f
             LEFT JOIN airlines al ON al.id=f.airline_id
             LEFT JOIN passenger_manifests pm_fin
                ON pm_fin.flight_id=f.id AND pm_fin.manifest_type='final'
             WHERE f.flight_date=?
             ORDER BY f.scheduled_time ASC, f.created_at DESC"
        );
        $s->execute([$today]);
        $todayFlights = $s->fetchAll(\PDO::FETCH_ASSOC);

        // Счётчики
        $total    = count($todayFlights);
        $finOk    = count(array_filter($todayFlights, fn($f) => ($f['manifest_fin_status'] ?? '') === 'submitted'));
        $missing  = count(array_filter($todayFlights, fn($f) =>
            empty($f['manifest_fin_status'])
        ));

        $stats = [
            'flights_today'  => $total,
            'manifest_fin'   => $finOk,
            'manifests_missing' => $missing,
        ];

        // Итого пассажиров сегодня
        try {
            $s = $db->prepare(
                "SELECT COALESCE(SUM(pm.pax_total),0)
                 FROM passenger_manifests pm
                 JOIN flights f ON f.id=pm.flight_id
                 WHERE f.flight_date=? AND pm.manifest_type='final' AND pm.status='submitted'"
            );
            $s->execute([$today]);
            $stats['pax_total_today'] = (int)$s->fetchColumn();
        } catch (\Throwable $e) { $stats['pax_total_today'] = 0; }

        // ДОБАВЛЕНО (04.11.2026, раунд 144, по прямому указанию пользователя):
        // виджет "Требует вашего внимания" выше и счётчики построены строго
        // по flight_date=today — рейс, занесённый в систему (в т.ч. задним
        // числом через импорт разрешений) с датой ПРОШЛОГО дня, выпадает и
        // отсюда, и из страницы "Манифесты" (там INNER JOIN на
        // passenger_manifests — рейсу без единой строки манифеста физически
        // не с чем соединиться), то есть становится никому не видимым,
        // пока кто-то случайно не наткнётся на него в общем списке рейсов.
        // Отдельный, не привязанный к "сегодня" список — рейсы прошлых
        // дней без поданного манифеста, чтобы это больше не терялось.
        try {
            $s = $db->prepare(
                "SELECT f.*, al.name AS airline_name, al.iata_code AS airline_iata
                 FROM flights f
                 LEFT JOIN airlines al ON al.id=f.airline_id
                 LEFT JOIN passenger_manifests pm_fin
                    ON pm_fin.flight_id=f.id AND pm_fin.manifest_type='final'
                 WHERE f.status='active'
                   AND f.flight_date < ?
                   AND pm_fin.id IS NULL
                 ORDER BY f.flight_date ASC, f.scheduled_time ASC
                 LIMIT 50"
            );
            $s->execute([$today]);
            $overdueFlights = $s->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $overdueFlights = [];
        }
        $stats['manifests_overdue'] = count($overdueFlights);

        return compact('stats', 'todayFlights', 'overdueFlights');
    }

    // ─── СПО — Служба перронного обеспечения ──────────────────
    private function dataSpo(): array
    {
        $db    = $this->db;
        $today = $this->today;

        $s = $db->prepare(
            "SELECT f.*, al.name AS airline_name, al.iata_code AS airline_iata,
                    tc.status AS techcard_status,
                    tc.aircraft_stand,
                    tc.chocks_on_time,
                    tc.crew_signature
             FROM flights f
             LEFT JOIN airlines al ON al.id=f.airline_id
             LEFT JOIN tech_cards tc ON tc.flight_id=f.id
             WHERE f.flight_date=?
             ORDER BY f.scheduled_time ASC, f.created_at DESC"
        );
        $s->execute([$today]);
        $todayFlights = $s->fetchAll(\PDO::FETCH_ASSOC);

        $total     = count($todayFlights);
        $submitted = count(array_filter($todayFlights, fn($f) => ($f['techcard_status'] ?? '') === 'submitted'));
        $drafts    = count(array_filter($todayFlights, fn($f) => ($f['techcard_status'] ?? '') === 'draft'));
        $missing   = count(array_filter($todayFlights, fn($f) => empty($f['techcard_status'])));

        $stats = [
            'flights_today'     => $total,
            'techcards_submitted'=> $submitted,
            'techcards_draft'   => $drafts,
            'techcards_missing' => $missing,
        ];

        return compact('stats', 'todayFlights');
    }

    // ─── ССТ — Служба специального транспорта ─────────────────
    private function dataSst(): array
    {
        $db    = $this->db;
        $today = $this->today;

        $s = $db->prepare(
            "SELECT f.*, al.name AS airline_name, al.iata_code AS airline_iata,
                    el.status AS equipment_status,
                    (SELECT COUNT(*) FROM equipment_log_items eli WHERE eli.log_id=el.id) AS equipment_count
             FROM flights f
             LEFT JOIN airlines al ON al.id=f.airline_id
             LEFT JOIN equipment_logs el ON el.flight_id=f.id
             WHERE f.flight_date=?
             ORDER BY f.scheduled_time ASC, f.created_at DESC"
        );
        $s->execute([$today]);
        $todayFlights = $s->fetchAll(\PDO::FETCH_ASSOC);

        $total     = count($todayFlights);
        $submitted = count(array_filter($todayFlights, fn($f) => ($f['equipment_status'] ?? '') === 'submitted'));
        $missing   = count(array_filter($todayFlights, fn($f) => empty($f['equipment_status'])));

        $stats = [
            'flights_today'       => $total,
            'equipment_submitted' => $submitted,
            'equipment_missing'   => $missing,
        ];

        return compact('stats', 'todayFlights');
    }

    // ─── Аэродромная служба ────────────────────────────────────
    private function dataAero(): array
    {
        $db    = $this->db;
        $today = $this->today;

        $s = $db->prepare(
            "SELECT f.*, al.name AS airline_name, al.iata_code AS airline_iata,
                    pa.status AS parking_status,
                    pa.stand_number,
                    pa.fod_clear, pa.runway_clear
             FROM flights f
             LEFT JOIN airlines al ON al.id=f.airline_id
             LEFT JOIN parking_acts pa ON pa.flight_id=f.id
             WHERE f.flight_date=?
             ORDER BY f.scheduled_time ASC, f.created_at DESC"
        );
        $s->execute([$today]);
        $todayFlights = $s->fetchAll(\PDO::FETCH_ASSOC);

        $total     = count($todayFlights);
        $submitted = count(array_filter($todayFlights, fn($f) => ($f['parking_status'] ?? '') === 'submitted'));
        $missing   = count(array_filter($todayFlights, fn($f) => empty($f['parking_status'])));

        $stats = [
            'flights_today'    => $total,
            'parking_submitted'=> $submitted,
            'parking_missing'  => $missing,
        ];

        return compact('stats', 'todayFlights');
    }

    // ─── Коммерческая служба ───────────────────────────────────
    private function dataCommercial(): array
    {
        $db         = $this->db;
        $today      = $this->today;
        $monthStart = $this->monthStart;

        $s = $db->prepare(
            "SELECT COUNT(*) FROM acts WHERE act_date >= ? AND status IN ('approved','exported')"
        );
        $s->execute([$monthStart]);
        $actsMonth = (int)$s->fetchColumn();

        $s = $db->prepare(
            "SELECT COALESCE(SUM(ai.amount_without_vat),0)
             FROM act_items ai
             JOIN acts a ON a.id=ai.act_id
             JOIN currencies c ON c.id=ai.currency_id
             WHERE a.act_date>=? AND a.status IN ('approved','exported') AND c.code='USD'"
        );
        $s->execute([$monthStart]);
        $revenueMonth = (float)$s->fetchColumn();

        // Выручка по авиакомпаниям за месяц
        $s = $db->prepare(
            "SELECT al.name AS airline_name, al.iata_code,
                    COUNT(DISTINCT a.id) AS acts_count,
                    SUM(ai.amount_without_vat) AS revenue_usd
             FROM acts a
             JOIN flights f ON f.id=a.flight_id
             JOIN airlines al ON al.id=f.airline_id
             JOIN act_items ai ON ai.act_id=a.id
             JOIN currencies c ON c.id=ai.currency_id
             WHERE a.act_date>=? AND a.status IN ('approved','exported') AND c.code='USD'
             GROUP BY al.id
             ORDER BY revenue_usd DESC LIMIT 10"
        );
        $s->execute([$monthStart]);
        $revenueByAirline = $s->fetchAll(\PDO::FETCH_ASSOC);

        // Акты этой недели
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $s = $db->prepare(
            "SELECT a.*, f.flight_number, f.flight_date, al.name AS airline_name
             FROM acts a
             JOIN flights f ON f.id=a.flight_id
             JOIN airlines al ON al.id=f.airline_id
             WHERE a.act_date >= ?
             ORDER BY a.act_date DESC, a.created_at DESC LIMIT 10"
        );
        $s->execute([$weekStart]);
        $weekActs = $s->fetchAll(\PDO::FETCH_ASSOC);

        // Тарифы — последние изменения
        $s = $db->prepare(
            "SELECT tv.*, s.name AS service_name
             FROM tariff_versions tv
             JOIN services s ON s.id=tv.service_id
             WHERE tv.is_active=1
             ORDER BY tv.created_at DESC LIMIT 5"
        );
        $s->execute();
        $recentTariffs = $s->fetchAll(\PDO::FETCH_ASSOC);

        $stats = [
            'acts_month'    => $actsMonth,
            'revenue_month' => $revenueMonth,
        ];

        return compact('stats', 'revenueByAirline', 'weekActs', 'recentTariffs');
    }

    // ─── Финансовая служба ─────────────────────────────────────
    private function dataFinance(): array
    {
        $db         = $this->db;
        $monthStart = $this->monthStart;

        $s = $db->prepare("SELECT COUNT(*) FROM acts WHERE status='approved'");
        $s->execute(); $actsApproved = (int)$s->fetchColumn();

        $s = $db->prepare("SELECT COUNT(*) FROM acts WHERE status='exported'");
        $s->execute(); $actsExported = (int)$s->fetchColumn();

        // Сумма к учёту (approved, не экспортированные)
        $s = $db->prepare(
            "SELECT COALESCE(SUM(a.total_amount_with_vat),0)
             FROM acts a
             JOIN currencies c ON c.id=a.settlement_currency_id
             WHERE a.status='approved' AND c.code='UZS'"
        );
        $s->execute(); $pendingUzs = (float)$s->fetchColumn();

        $s = $db->prepare(
            "SELECT COALESCE(SUM(a.total_amount_with_vat),0)
             FROM acts a
             JOIN currencies c ON c.id=a.settlement_currency_id
             WHERE a.status='approved' AND c.code='USD'"
        );
        $s->execute(); $pendingUsd = (float)$s->fetchColumn();

        // Акты к экспорту (approved)
        $s = $db->prepare(
            "SELECT a.*, f.flight_number, f.flight_date, al.name AS airline_name,
                    c.code AS currency_code
             FROM acts a
             JOIN flights f ON f.id=a.flight_id
             JOIN airlines al ON al.id=f.airline_id
             JOIN currencies c ON c.id=a.settlement_currency_id
             WHERE a.status='approved'
             ORDER BY a.approved_at DESC LIMIT 15"
        );
        $s->execute();
        $actsToExport = $s->fetchAll(\PDO::FETCH_ASSOC);

        // Последние экспортированные
        $s = $db->prepare(
            "SELECT a.*, f.flight_number, f.flight_date, al.name AS airline_name
             FROM acts a
             JOIN flights f ON f.id=a.flight_id
             JOIN airlines al ON al.id=f.airline_id
             WHERE a.status='exported'
             ORDER BY a.updated_at DESC LIMIT 5"
        );
        $s->execute();
        $recentExported = $s->fetchAll(\PDO::FETCH_ASSOC);

        $stats = [
            'acts_approved' => $actsApproved,
            'acts_exported' => $actsExported,
            'pending_uzs'   => $pendingUzs,
            'pending_usd'   => $pendingUsd,
        ];

        return compact('stats', 'actsToExport', 'recentExported');
    }
    // ─── СПАСОП ────────────────────────────────────────────────
    private function dataSpasop(): array
    {
        $db    = $this->db;
        $today = $this->today;

        // Рейсы сегодня с данными о начислениях СПАСОП
        $s = $db->prepare(
            "SELECT f.*, al.name AS airline_name, al.iata_code AS airline_iata,
                    (SELECT COUNT(*) FROM flight_service_charges fsc
                     WHERE fsc.flight_id = f.id AND fsc.source_role = 'spasop') AS spasop_count
             FROM flights f
             LEFT JOIN airlines al ON al.id = f.airline_id
             WHERE f.flight_date = ?
             ORDER BY f.scheduled_time ASC, f.created_at DESC"
        );
        $s->execute([$today]);
        $todayFlights = $s->fetchAll(\PDO::FETCH_ASSOC);

        $total   = count($todayFlights);
        $filled  = count(array_filter($todayFlights, fn($f) => ($f['spasop_count'] ?? 0) > 0));
        $missing = $total - $filled;

        $stats = [
            'flights_today'   => $total,
            'spasop_filled'   => $filled,
            'spasop_missing'  => $missing,
        ];

        return compact('stats', 'todayFlights');
    }

}
