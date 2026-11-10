<?php

declare(strict_types=1);

namespace App\Services;

/**
 * CbuExchangeRateService — получение официального курса USD/UZS
 * с сайта Центрального банка Республики Узбекистан (cbu.uz).
 *
 * Публичный JSON API, без авторизации:
 *   https://cbu.uz/uz/arkhiv-kursov-valyut/json/USD/{YYYY-MM-DD}/
 *   (возвращает массив с одним объектом за указанную дату)
 *
 * Если ЦБ ещё не опубликовал курс на сегодня (публикуется обычно утром)
 * или сайт временно недоступен — используется курс за предыдущий
 * ближайший рабочий день, либо метод возвращает null и вызывающий код
 * должен попросить пользователя ввести курс вручную.
 */
class CbuExchangeRateService
{
    private const BASE_URL  = 'https://cbu.uz/uz/arkhiv-kursov-valyut/json/USD/';
    private const TIMEOUT_S = 5;
    private const MAX_LOOKBACK_DAYS = 5; // на случай выходных/праздников

    /**
     * Получить курс USD→UZS на конкретную дату (или ближайшую предыдущую
     * опубликованную, если на эту дату курса ещё нет).
     *
     * @return array{rate: float, date: string}|null
     */
    public function getRateForDate(string $date): ?array
    {
        for ($i = 0; $i <= self::MAX_LOOKBACK_DAYS; $i++) {
            $tryDate = date('Y-m-d', strtotime($date . " -{$i} day"));
            $rate    = $this->fetchRate($tryDate);
            if ($rate !== null) {
                return ['rate' => $rate, 'date' => $tryDate];
            }
        }
        return null;
    }

    /**
     * Курс на сегодня (для удобного вызова из контроллеров)
     */
    public function getTodayRate(): ?array
    {
        return $this->getRateForDate(date('Y-m-d'));
    }

    private function fetchRate(string $date): ?float
    {
        $url = self::BASE_URL . $date . '/';

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT_S,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error || $httpCode !== 200 || !$response) {
                return null;
            }

            $data = json_decode($response, true);
            if (!is_array($data) || empty($data[0]['Rate'])) {
                return null;
            }

            // Формат ЦБ использует запятую как десятичный разделитель
            $rateRaw = str_replace(',', '.', (string)$data[0]['Rate']);
            $rate    = (float)$rateRaw;

            return $rate > 0 ? $rate : null;

        } catch (\Throwable $e) {
            error_log('CbuExchangeRateService error: ' . $e->getMessage());
            return null;
        }
    }
}
