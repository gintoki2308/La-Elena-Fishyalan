(() => {
    const clock = document.querySelector('[data-server-time]');
    if (!clock) return;
    const serverTime = Number(clock.dataset.serverTime), started = performance.now();
    const formatter = new Intl.DateTimeFormat('en-US', {timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
    const dateFormatter = new Intl.DateTimeFormat('en-US', {timeZone:'Asia/Manila',weekday:'long',month:'long',day:'numeric',year:'numeric'});
    function tick() {
        const now = new Date(serverTime + performance.now() - started);
        const parts = Object.fromEntries(formatter.formatToParts(now).map(part => [part.type,part.value]));
        for (const [id,type] of [['clockHour','hour'],['clockMinute','minute'],['clockSecond','second'],['clockPeriod','dayPeriod']]) document.getElementById(id).textContent = parts[type];
        document.getElementById('clockDate').textContent = dateFormatter.format(now);
    }
    tick(); setInterval(tick,1000);
    let submitting = false;
    document.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => { submitting = true; }));
    const refreshIfStale = () => {
        if (!submitting && document.visibilityState === 'visible' && performance.now()-started >= 60000) location.reload();
    };
    setInterval(refreshIfStale,15000);
    document.addEventListener('visibilitychange',refreshIfStale);
    window.addEventListener('pageshow',event => { if(event.persisted) location.reload(); });
})();
