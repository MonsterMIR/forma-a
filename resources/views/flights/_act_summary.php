<?php
use App\Core\Permissions;

$allCharges  = $allCharges ?? [];
$existingAct = $existingAct ?? null;
$flight      = $flight ?? [];

$pending  = array_filter($allCharges, fn($c) => empty($c['consumed_in_act_id']));
$consumed = array_filter($allCharges, fn($c) => !empty($c['consumed_in_act_id']));
$isDomestic = ($flight['route_type'] ?? '') === 'domestic';
$cbRate = (float)($existingAct['cb_rate'] ?? 0);
$needCbRate = $isDomestic && $cbRate <= 1.0;
?>

<div class="card border-0 shadow-sm mt-4" id="act-summary">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="bi bi-file-earmark-check me-2"></i>Форма А (акт) по рейсу
        </span>
        <?php if ($existingAct): ?>
        <a href="/acts/<?= (int)$existingAct['id'] ?>"
           class="badge text-decoration-none bg-<?=
               match($existingAct['status']) {
                   'approved' => 'success',
                   'exported' => 'primary',
                   'cancelled'=> 'danger',
                   default    => 'warning text-dark'
               }
           ?>">
            <?= e(act_display_number($existingAct)) ?> —
            <?= match($existingAct['status']) {
                'draft'    => 'Черновик',
                'approved' => 'Утверждён',
                'exported' => 'Экспортирован',
                'cancelled'=> 'Отменён',
                default    => e($existingAct['status'])
            } ?>
        </a>
        <?php endif; ?>
    </div>

    <div class="card-body">

        <?php if (empty($allCharges)): ?>
        <div class="text-center text-muted py-3">
            <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
            Услуги по рейсу ещё не добавлены ни одной службой.<br>
            <small>Аэродромная служба должна подать акт стоянки — тогда TLF/AV_SEC рассчитаются автоматически.</small>
        </div>
        <?php else: ?>

        <div class="table-responsive mb-3">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Услуга</th>
                        <th>Служба</th>
                        <th>Кол-во</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allCharges as $c): ?>
                <tr class="<?= empty($c['consumed_in_act_id']) ? '' : 'text-muted' ?>">
                    <td>
                        <?= e($c['service_name']) ?>
                        <?php if ($c['is_auto']): ?>
                        <span class="badge bg-info text-dark ms-1" style="font-size:0.65rem">авто</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-secondary" style="font-size:0.7rem">
                            <?= match($c['source_role']) {
                                'sop'     => 'СОП',
                                'spo'     => 'СПО',
                                'sst'     => 'ССТ',
                                'aero'    => 'Аэродр.',
                                'spasop'  => 'СПАСОП',
                                'system'  => 'Система',
                                'cok'     => 'ЦОК',
                                // ДОБАВЛЕНО (14.09.2026, раунд 41):
                                'gsm'     => 'ГСМ',
                                'medical' => 'Здрав.пункт',
                                'balance' => 'ОЦ',
                                default   => e($c['source_role'])
                            } ?>
                        </span>
                    </td>
                    <td>
                        <?php if (in_array($c['service_code'] ?? '', ['AV_SEC', 'NIGHT'], true)): ?>
                            <span class="text-muted" title="Процент считается от суммы TLF при сборке акта — это не количество единиц">
                                % от TLF
                            </span>
                        <?php else: ?>
                            <?= rtrim(rtrim(number_format((float)$c['quantity'], 4, '.', ''), '0'), '.') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($c['consumed_in_act_id'])): ?>
                        <span class="badge bg-warning text-dark">Ожидает</span>
                        <?php else: ?>
                        <span class="badge bg-success"><i class="bi bi-check"></i> В акте</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (Permissions::can('acts.create')): ?>

            <?php if ($existingAct && in_array($existingAct['status'], ['approved','exported','cancelled'], true)): ?>
            <div class="alert alert-secondary small mb-0">
                <i class="bi bi-lock me-1"></i>
                Акт <?= match($existingAct['status']) {
                    'approved' => 'утверждён',
                    'exported' => 'экспортирован',
                    default    => 'отменён'
                } ?> — формирование недоступно.
                <a href="/acts/<?= (int)$existingAct['id'] ?>">Открыть акт →</a>
            </div>

            <?php else: ?>
            <form method="POST" action="/flights/<?= (int)$flight['id'] ?>/build-act"
                  class="d-flex flex-wrap gap-2 align-items-end">
                <?= csrf_field() ?>

                <?php if ($isDomestic): ?>
                <div>
                    <label class="form-label small fw-semibold mb-1">
                        Курс ЦБ (USD/UZS)
                        <?php if ($existingAct && $cbRate > 1): ?>
                        <span class="text-muted fw-normal">сейчас: <?= number_format($cbRate, 2) ?></span>
                        <?php endif; ?>
                    </label>
                    <input type="number" name="cb_rate" class="form-control form-control-sm"
                           style="width:160px" step="0.01" min="1"
                           placeholder="Например: 12750"
                           value="<?= $cbRate > 1 ? number_format($cbRate, 2, '.', '') : '' ?>"
                           <?= ($existingAct && $cbRate > 1) ? '' : 'required' ?>>
                </div>
                <?php endif; ?>

                <div>
                    <?php if ($existingAct && empty($pending) && !empty($consumed)): ?>
                    <div class="text-success small mb-1">
                        <i class="bi bi-check-circle me-1"></i>Все услуги включены в акт
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-success"
                            onclick="return confirm('<?= $existingAct
                                ? 'Обновить Форму А новыми услугами?'
                                : 'Сформировать Форму А по всем оказанным услугам рейса?' ?>')">
                        <i class="bi bi-<?= $existingAct ? 'arrow-repeat' : 'file-earmark-plus' ?> me-1"></i>
                        <?php if (!$existingAct): ?>
                            Сформировать Форму А
                        <?php elseif (!empty($pending)): ?>
                            Обновить Форму А
                            <span class="badge bg-light text-dark ms-1"><?= count($pending) ?> новых</span>
                        <?php else: ?>
                            Пересобрать Форму А
                        <?php endif; ?>
                    </button>
                </div>
            </form>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
