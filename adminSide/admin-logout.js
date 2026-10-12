/* Shared logout confirmation for every admin page. */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var target = event.target.closest('.logout-link, #logout-btn');
        if (!target) return;

        // This listener runs during capture so older page-specific handlers do
        // not show a second, differently styled confirmation dialog.
        event.preventDefault();
        event.stopImmediatePropagation();

        var logoutUrl = target.matches('a[href]') ? target.href : window.ADMIN_LOGOUT_URL;
        if (!logoutUrl) return;

        if (typeof window.Swal === 'undefined') {
            if (window.confirm('Are you sure you want to log out?')) {
                window.location.assign(logoutUrl);
            }
            return;
        }

        window.Swal.fire({
            title: 'Ready to leave?',
            text: 'Are you sure you want to log out?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#d4af37',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-sign-out-alt me-1"></i> Yes, logout',
            cancelButtonText: 'Stay',
            background: '#1a1a1a',
            color: '#fff',
            reverseButtons: true,
            focusCancel: true
        }).then(function (result) {
            if (result.isConfirmed) {
                window.location.assign(logoutUrl);
            }
        });
    }, true);
}());
