(() => {
    const toggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!toggle || !sidebar || !overlay) return;

    const desktop = window.matchMedia('(min-width: 992px)');
    const closeButton = sidebar.querySelector('.sidebar-close');

    function setOpen(open, restoreFocus = false) {
        open = open && !desktop.matches;
        // Move focus before making a closed drawer inert.
        if (!open && restoreFocus) toggle.focus();
        sidebar.classList.toggle('show', open);
        overlay.classList.toggle('show', open);
        document.body.classList.toggle('staff-sidebar-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        sidebar.inert = !desktop.matches && !open;
        if (open) (closeButton || sidebar.querySelector('.nav-link'))?.focus();
    }

    toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('show')));
    closeButton?.addEventListener('click', () => setOpen(false, true));
    overlay.addEventListener('click', () => setOpen(false, true));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && sidebar.classList.contains('show')) {
            event.preventDefault();
            setOpen(false, true);
        }
    });
    sidebar.querySelectorAll('a.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (!desktop.matches) setOpen(false, true);
        });
    });
    desktop.addEventListener('change', () => {
        const focused = document.activeElement;
        if (desktop.matches && (focused === toggle || focused === closeButton)) {
            sidebar.querySelector('.nav-link.active, .nav-link')?.focus();
        }
        setOpen(false, !desktop.matches && sidebar.contains(document.activeElement));
    });
    setOpen(false);
})();
