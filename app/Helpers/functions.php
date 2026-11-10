<?php

declare(strict_types=1);

if (!function_exists('__')) {
    /**
     * Translate using INI key (uppercase, underscores).
     *
     * Examples:
     *   __('NAV_DASHBOARD')
     *   __('COMMON_SHOWING', [':from' => 1, ':to' => 20, ':total' => 100])
     *   __('ACTS_STATUS_' . strtoupper($status))
     */
    function __(string $key, array $params = []): string
    {
        return \App\Core\Lang::translate($key, $params);
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        \App\Core\View::setFlash($type, $message);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return \App\Core\Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return \App\Core\Csrf::token();
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?array
    {
        return \App\Core\Auth::user();
    }
}

if (!function_exists('has_role')) {
    function has_role(string|array $roles): bool
    {
        return \App\Core\Auth::hasRole($roles);
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        $config = require BASE_PATH . '/config/app.php';
        return rtrim($config['url'], '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $config = require BASE_PATH . '/config/app.php';
        return rtrim($config['url'], '/') . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('paginate')) {
    function paginate(int $total, int $perPage, int $currentPage): array
    {
        $lastPage = max(1, (int)ceil($total / $perPage));
        return [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $currentPage,
            'last_page'    => $lastPage,
            'from'         => $total > 0 ? ($currentPage - 1) * $perPage + 1 : 0,
            'to'           => min($currentPage * $perPage, $total),
        ];
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'd.m.Y'): string
    {
        if (!$date) return '—';
        $d = \DateTime::createFromFormat('Y-m-d', $date)
          ?: \DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return $d ? $d->format($format) : $date;
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?string $datetime, string $format = 'd.m.Y H:i'): string
    {
        if (!$datetime) return '—';
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        return $d ? $d->format($format) : $datetime;
    }
}

if (!function_exists('format_money')) {
    function format_money(float|string|null $amount, int $decimals = 2): string
    {
        if ($amount === null) return '0.00';
        return number_format((float)$amount, $decimals, '.', ' ');
    }
}

if (!function_exists('status_badge')) {
    /**
     * Render a coloured status badge.
     * Uses INI key ACTS_STATUS_{STATUS} automatically.
     */
    function status_badge(string $status): string
    {
        $label = __('ACTS_STATUS_' . strtoupper($status));
        $map   = [
            'draft'     => 'badge-status-draft',
            'approved'  => 'badge-status-approved',
            'cancelled' => 'badge-status-cancelled',
            'exported'  => 'badge-status-exported',
        ];
        $cls = $map[$status] ?? 'badge bg-secondary';
        return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
    }
}

if (!function_exists('current_lang')) {
    function current_lang(): string
    {
        return \App\Core\Lang::get();
    }
}

if (!function_exists('act_verify_code')) {
    /**
     * Генерирует уникальный верификационный код для акта.
     * Код встраивается в PDF при печати.
     * При загрузке файла система проверяет наличие кода в PDF.
     *
     * @param string $actNumber  Номер акта, например ACT-2026-0004
     * @param string $actDate    Дата акта YYYY-MM-DD
     * @param float  $total      Итоговая сумма с НДС
     * @return string            12-символьный код верхнего регистра
     */
    function act_verify_code(string $actNumber, string $actDate, float $total): string
    {
        $secret = $_ENV['VERIFY_SECRET'] ?? 'forma-a-default-secret-change-me';
        $data   = $actNumber . '|' . $actDate . '|' . number_format($total, 2, '.', '');
        $hash   = hash_hmac('sha256', $data, $secret);
        return strtoupper(substr($hash, 0, 12));
    }
}

if (!function_exists('act_display_number')) {
    /**
     * ДОБАВЛЕНО (10.09.2026, раунд 35): показывает настоящий номер акта
     * только если он уже финализирован (acts.number_finalized=1 — обе
     * подписи собраны, либо утверждён по скану, см.
     * ActService::ensureActNumberFinalized()). До этого у акта — временная
     * заглушка ('PRELIM-FL{flight_id}'), которую никому показывать не
     * нужно — вместо неё везде в интерфейсе (списки, карточки, экран
     * подписания) показываем явную пометку "ПРЕДВАРИТЕЛЬНО".
     *
     * @param array $act Строка из `acts` (нужны ключи act_number, number_finalized)
     */
    function act_display_number(array $act): string
    {
        return !empty($act['number_finalized']) ? (string)($act['act_number'] ?? '') : 'ПРЕДВАРИТЕЛЬНО';
    }
}

if (!function_exists('format_flight_label')) {
    /**
     * Форматирует отображение рейса: HY-0001 | TAS→FRA | 17.04.2026 | Прилёт
     */
    function format_flight_label(array $flight): string
    {
        $iata = $flight['airline_iata'] ?? '';
        $num  = $flight['flight_number'] ?? '';
        $from = $flight['origin_iata'] ?? $flight['origin_name'] ?? '?';
        $to   = $flight['destination_iata'] ?? $flight['destination_name'] ?? '?';
        $date = format_date($flight['flight_date'] ?? '', 'd.m.Y');
        $op   = ucfirst($flight['operation_type'] ?? '');

        $code = $iata ? "{$iata}-{$num}" : $num;
        return "{$code} | {$from}→{$to} | {$date} | {$op}";
    }
}

if (!function_exists('format_act_label')) {
    /**
     * Форматирует заголовок акта: 0001/HY-0001 | TAS→FRA | 17.04.2026
     */
    function format_act_label(array $act): string
    {
        $num  = $act['act_number'] ?? '';
        $from = $act['origin_iata'] ?? '?';
        $to   = $act['destination_iata'] ?? '?';
        $date = format_date($act['act_date'] ?? '', 'd.m.Y');
        return "{$num} | {$from}→{$to} | {$date}";
    }
}
