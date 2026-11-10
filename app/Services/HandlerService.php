<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Handler;
use App\Models\HandlerEmployee;

/**
 * ДОБАВЛЕНО (18.09.2026, раунд 46, п.5/п.6): алгоритм выбора хендлера для
 * рейса и его сотрудников — см. анализ раунда 46 за полное обоснование.
 *
 * Правило: если для авиакомпании явно привязан хотя бы один хендлер
 * (airline_handlers) — предлагаем именно его/их. Если ничего не привязано —
 * не блокируем подписание: предлагаем выбрать из ВСЕХ активных хендлеров
 * (частая ситуация в начале, пока справочник ещё не заполнен полностью),
 * плюс всегда остаётся ручной ввод ФИО как и раньше — новая функция ничего
 * не отбирает, только добавляет более быстрый путь, когда данные заведены.
 */
class HandlerService
{
    private Handler         $handlerModel;
    private HandlerEmployee $employeeModel;

    public function __construct()
    {
        $this->handlerModel  = new Handler();
        $this->employeeModel = new HandlerEmployee();
    }

    /**
     * Хендлеры-кандидаты для рейса данной авиакомпании, с уже подгруженными
     * активными сотрудниками каждого — то, что нужно для двухуровневого
     * выпадающего списка на экране подписания.
     *
     * @return array{handlers: array, matchedByAirline: bool}
     */
    public function getCandidatesForAirline(int $airlineId): array
    {
        $linked = $airlineId > 0 ? $this->handlerModel->getForAirline($airlineId) : [];
        $matchedByAirline = !empty($linked);
        $handlers = $matchedByAirline ? $linked : $this->handlerModel->getAllActive();

        foreach ($handlers as &$h) {
            $h['employees'] = $this->employeeModel->getByHandler((int)$h['id'], true);
        }
        unset($h);

        return ['handlers' => $handlers, 'matchedByAirline' => $matchedByAirline];
    }

    public function findEmployee(int $employeeId): ?array
    {
        return $this->employeeModel->findWithHandler($employeeId);
    }

    /**
     * ДОБАВЛЕНО (22.09.2026, раунд 51, по прямому указанию пользователя):
     * хендлер, распознанный из строки заявки "ACC AGR WITH <хендлер>"
     * (flights.resolved_handler_id, см. PermissionParser/FlightController::
     * findOrCreateHandler()) — приоритетнее общей привязки авиакомпания→
     * хендлер из airline_handlers, потому что относится именно к ЭТОМУ
     * рейсу, а не к авиакомпании вообще (одна а/к может летать через разных
     * хендлеров на разных рейсах — ровно случай из примеров пользователя:
     * два рейса одной и той же авиакомпании через разных хендлеров).
     *
     * Поведение: если у рейса есть resolved_handler_id и такой хендлер
     * активен — он идёт ПЕРВЫМ в списке (и помечается matchedByFlight),
     * остальные привязанные к авиакомпании хендлеры (если есть) — следом,
     * без дублей. Если resolved_handler_id нет/не активен — прежнее
     * поведение без изменений (getCandidatesForAirline()). В любом случае
     * ручной ввод ФИО на экране подписи остаётся рабочим — ничего не
     * отбирается, только предлагается более точная подсказка первой.
     *
     * @return array{handlers: array, matchedByAirline: bool, matchedByFlight: bool, resolvedHandlerId: ?int}
     */
    public function getCandidatesForFlight(array $flight): array
    {
        $resolvedHandlerId = !empty($flight['resolved_handler_id']) ? (int)$flight['resolved_handler_id'] : null;
        $base = $this->getCandidatesForAirline((int)($flight['airline_id'] ?? 0));

        if (!$resolvedHandlerId) {
            return $base + ['matchedByFlight' => false, 'resolvedHandlerId' => null];
        }

        $resolved = $this->handlerModel->find($resolvedHandlerId);
        if (!$resolved || empty($resolved['is_active'])) {
            return $base + ['matchedByFlight' => false, 'resolvedHandlerId' => null];
        }

        $resolved['employees'] = $this->employeeModel->getByHandler($resolvedHandlerId, true);

        $rest = array_values(array_filter(
            $base['handlers'],
            fn($h) => (int)$h['id'] !== $resolvedHandlerId
        ));

        return [
            'handlers'           => array_merge([$resolved], $rest),
            'matchedByAirline'   => true, // тот же UI-сигнал "уверенное совпадение", что и у привязки по а/к
            'matchedByFlight'    => true,
            'resolvedHandlerId'  => $resolvedHandlerId,
        ];
    }
}
