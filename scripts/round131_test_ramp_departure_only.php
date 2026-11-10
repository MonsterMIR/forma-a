<?php
define('BASE_PATH', dirname(__DIR__));
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
        $_ENV[$k] = $v; putenv("$k=$v");
    }
}
require BASE_PATH . '/vendor/autoload.php';

use App\Models\TariffVersion;

$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    echo ($cond ? "OK   " : "FAIL ") . $label . "\n";
    if (!$cond) $fail++;
}

$tariffModel = new TariffVersion();

foreach (['spo', null] as $role) {
    $label = $role ?? 'без роли';
    $arr = $tariffModel->getServicesWithActiveTariff('international', 'arrival', date('Y-m-d'), $role, 448.0);
    $arrCodes = array_column($arr, 'code');
    check("RAMP_251 НЕ виден на прилёте (int, MTOW 448, роль={$label})", !in_array('RAMP_251', $arrCodes, true));

    $dep = $tariffModel->getServicesWithActiveTariff('international', 'departure', date('Y-m-d'), $role, 448.0);
    $depCodes = array_column($dep, 'code');
    check("RAMP_251 виден на вылете (int, MTOW 448, роль={$label})", in_array('RAMP_251', $depCodes, true));
}

echo "\n" . ($fail === 0 ? "ВСЕ ПРОВЕРКИ ПРОШЛИ" : "ЕСТЬ ОШИБКИ: {$fail}") . "\n";
exit($fail === 0 ? 0 : 1);
