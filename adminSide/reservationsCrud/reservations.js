'use strict';

document.querySelector('#reservation-sort')?.addEventListener('change', event => {
    event.target.form.requestSubmit();
});

document.querySelectorAll('.reservation-update').forEach(form => {
    const select = form.querySelector('[name="new_status"]');
    const button = form.querySelector('button[type="submit"]');
    const original = form.querySelector('[name="expected_status"]').value;
    const sync = () => { button.disabled = select.value === original; };
    select.addEventListener('change', sync);
    sync();
    form.addEventListener('submit', event => {
        if (select.value === original || (select.value === 'CANCELLED' && !window.confirm(`Cancel reservation #${form.dataset.reservationId}? The customer will be notified by email.`))) {
            event.preventDefault();
            return;
        }
        button.disabled = true;
        button.textContent = 'Saving…';
        form.setAttribute('aria-busy', 'true');
    });
    window.addEventListener('pageshow', () => {
        button.textContent = 'Save';
        form.removeAttribute('aria-busy');
        sync();
    });
});
