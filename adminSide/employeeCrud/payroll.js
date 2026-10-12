(() => {
    const form = document.querySelector('[data-payroll-preview-url]');
    if (!form) return;
    const panel = document.getElementById('attendance-payroll-preview');
    const status = document.querySelector('[data-payroll-refresh-status]');
    const generate = form.querySelector('[data-generate-payroll]');
    let timer, pending, version = 0, submitting = false;
    async function refresh() {
        if (submitting || document.visibilityState !== 'visible') return;
        const requestVersion = ++version;
        pending?.abort();
        if (!form.checkValidity()) {
            generate.disabled = true;
            panel.textContent = 'Enter valid salary amounts and dates to calculate attendance.';
            return;
        }
        const values = new FormData(form);
        const signature = new URLSearchParams(values).toString();
        const controller = new AbortController(); pending = controller;
        const timeout = setTimeout(() => controller.abort(), 10000);
        status.textContent = 'Calculating completed attendance…';
        generate.disabled = true;
        try {
            const response = await fetch(form.dataset.payrollPreviewUrl, {method:'POST', body:values, signal:controller.signal, credentials:'same-origin', cache:'no-store'});
            const data = await response.json();
            if (requestVersion !== version || signature !== new URLSearchParams(new FormData(form)).toString()) return;
            if (!response.ok || !data.html) throw new Error(data.error || 'Attendance salary could not be calculated.');
            panel.innerHTML = data.html;
            generate.disabled = !data.can_generate;
            status.textContent = 'Attendance updated. Checks every 30 seconds and after payroll field changes.';
        } catch (error) {
            if (requestVersion !== version || submitting) return;
            status.textContent = error.name === 'AbortError' ? 'Attendance refresh interrupted. Retrying on the next check.' : error.message;
        } finally { clearTimeout(timeout); if (pending === controller) pending = null; }
    }
    form.addEventListener('input', () => {
        version++; pending?.abort(); generate.disabled = true;
        status.textContent = 'Payroll fields changed. Updating salary estimate…';
        clearTimeout(timer); timer = setTimeout(refresh, 400);
    });
    form.addEventListener('submit', () => { submitting = true; clearTimeout(timer); pending?.abort(); });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refresh(); });
    setInterval(refresh, 30000);
})();
