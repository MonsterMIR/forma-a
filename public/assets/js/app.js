'use strict';

// Auto-dismiss alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert.alert-dismissible');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });
});

// Confirm dangerous actions
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });
});

// Auto uppercase for IATA/ICAO fields
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[style*="text-transform:uppercase"]').forEach(function (el) {
        el.addEventListener('input', function () {
            const pos = el.selectionStart;
            el.value = el.value.toUpperCase();
            el.setSelectionRange(pos, pos);
        });
    });
});

// ДОБАВЛЕНО (10.11.2026, раунд 164, по жалобе СОП: "ввожу конкретную дату
// поиска рейсов — а показывает всё равно другие даты"). Причина была не в
// backend-фильтрации (она честно работает как диапазон "с"/"по"), а в том,
// что для поиска РОВНО одного дня нужно вручную заполнить ОБА поля
// (date_from И date_to) одной и той же датой — пользователь интуитивно
// заполнял только одно и получал открытый с одного конца диапазон. Это
// добавляет третье, необязательное поле "На дату": заполнил его один раз —
// оно само подставляет то же значение в оба поля диапазона. Сами поля
// "с"/"по" никуда не делись и продолжают работать как раньше для тех, кто
// осознанно ищет диапазон.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-exact-date]').forEach(function (exactInput) {
        const form = exactInput.closest('form');
        if (!form) return;
        const fromInput = form.querySelector('[name="' + exactInput.dataset.from + '"]');
        const toInput   = form.querySelector('[name="' + exactInput.dataset.to + '"]');
        if (!fromInput || !toInput) return;

        exactInput.addEventListener('change', function () {
            fromInput.value = exactInput.value;
            toInput.value   = exactInput.value;
        });

        // Если после этого человек вручную поправил "с" или "по" — убираем
        // значение из поля "на дату", чтобы оно не продолжало показывать
        // уже неверный одиночный день.
        [fromInput, toInput].forEach(function (rangeInput) {
            rangeInput.addEventListener('input', function () {
                if (exactInput.value && rangeInput.value !== exactInput.value) {
                    exactInput.value = '';
                }
            });
        });
    });
});
