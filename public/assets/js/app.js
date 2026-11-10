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