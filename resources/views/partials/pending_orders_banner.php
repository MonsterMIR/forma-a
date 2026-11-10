<?php
/**
 * ДОБАВЛЕНО (06.08.2026): вынесено из 5 идентичных копий в manifests/
 * tech_cards/equipment_logs/parking_acts/emergency_logs — там был
 * дословно один и тот же блок. Заодно добавлена кнопка "Техника выехала"
 * (только для позиций с services.requires_dispatch_notice=1) — уведомляет
 * супервайзеров, что транспорт выдвинулся к борту.
 *
 * Ожидает переменную $pendingOrders — результат
 * FlightServiceOrder::getPendingForRole() (id, service_name,
 * dispatched_at, requires_dispatch_notice и т.д.).
 */
?>
<?php if (!empty($pendingOrders)): ?>
<div class="alert alert-info d-flex align-items-start gap-2 mb-4">
    <i class="bi bi-clipboard2-check fs-5"></i>
    <div class="flex-grow-1">
        <strong>Заказано ЦОК, ожидает подтверждения супервайзером на месте:</strong>
        <ul class="list-unstyled mb-1 mt-2">
        <?php foreach ($pendingOrders as $o): ?>
            <li class="d-flex justify-content-between align-items-center py-1 pending-order-row"
                data-order-row="<?= (int)$o['id'] ?>">
                <span><?= e($o['service_name']) ?></span>
                <?php if (!empty($o['requires_dispatch_notice'])): ?>
                    <?php if (!empty($o['dispatched_at'])): ?>
                        <span class="badge bg-success">
                            <i class="bi bi-truck"></i> Выехала <?= e(format_datetime($o['dispatched_at'], 'H:i')) ?>
                        </span>
                    <?php else: ?>
                        <button type="button"
                                class="btn btn-sm btn-outline-primary dispatch-btn"
                                data-order-id="<?= (int)$o['id'] ?>">
                            <i class="bi bi-truck"></i> Техника выехала
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
        <div class="small text-muted mt-1">Вносить самим не обязательно — супервайзер подтвердит по факту оказания. Если по какой-то причине нужно внести самим — форма ниже всё ещё доступна.</div>
    </div>
</div>
<script>
(function() {
    document.querySelectorAll('.dispatch-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var orderId = this.dataset.orderId;
            var b = this;
            b.disabled = true;
            var csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
            var body = new URLSearchParams();
            body.append('_csrf', csrf);
            fetch('/service-orders/' + orderId + '/dispatch', {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    b.outerHTML = '<span class="badge bg-success"><i class="bi bi-truck"></i> Выехала</span>';
                } else {
                    alert(data.message || 'Не удалось отметить.');
                    b.disabled = false;
                }
            })
            .catch(function() {
                alert('Ошибка сети — попробуйте ещё раз.');
                b.disabled = false;
            });
        });
    });
})();
</script>
<?php endif; ?>
