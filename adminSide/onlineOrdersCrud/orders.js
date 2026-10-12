(() => {
    'use strict';
    const byId = id => document.getElementById(id);
    const rows = Array.from(document.querySelectorAll('#orders-tbody tr'));
    const cards = Array.from(document.querySelectorAll('.order-card'));
    const search = byId('order-search');
    const status = byId('status-filter');
    const from = byId('date-from');
    const to = byId('date-to');
    let filteredRows = rows;
    let filteredCards = cards;

    function matches(item) {
        const query = search.value.trim().toLocaleLowerCase().replace(/^#/, '');
        return (!query || item.dataset.search.toLocaleLowerCase().includes(query)) &&
            (!status.value || item.dataset.status === status.value) &&
            (!from.value || item.dataset.date >= from.value) &&
            (!to.value || item.dataset.date <= to.value);
    }

    function paginator(mode, allItems, getFiltered, hiddenClass) {
        let page = 1;
        const size = byId(mode + '-per-page');
        const controls = byId(mode + '-pagination-controls');
        const info = byId(mode + '-pagination-info');
        function render() {
            const items = getFiltered();
            const perPage = Number(size.value) === 9999 ? Math.max(items.length, 1) : Number(size.value);
            const pages = Math.max(1, Math.ceil(items.length / perPage));
            page = Math.min(page, pages);
            const start = (page - 1) * perPage;
            allItems.forEach(item => item.classList.add(hiddenClass));
            items.slice(start, start + perPage).forEach(item => item.classList.remove(hiddenClass));
            info.innerHTML = items.length ? `Showing <span>${start + 1}–${Math.min(start + perPage, items.length)}</span> of <span>${items.length}</span> orders` : 'Showing 0 orders';
            controls.replaceChildren();
            if (pages <= 1) return;
            function button(label, target, disabled = false, active = false) {
                const element = document.createElement('button');
                element.type = 'button';
                element.className = 'page-btn' + (active ? ' active' : '');
                element.textContent = label;
                element.disabled = disabled;
                element.setAttribute('aria-label', label === '‹' ? 'Previous page' : label === '›' ? 'Next page' : 'Page ' + target);
                if (active) element.setAttribute('aria-current', 'page');
                element.addEventListener('click', () => { page = target; render(); });
                controls.append(element);
            }
            button('‹', page - 1, page === 1);
            let previous = 0;
            for (let index = 1; index <= pages; index++) {
                if (index !== 1 && index !== pages && Math.abs(index - page) > 1) continue;
                if (previous && index - previous > 1) {
                    const dots = document.createElement('span');
                    dots.className = 'page-btn ellipsis';
                    dots.textContent = '…';
                    dots.setAttribute('aria-hidden', 'true');
                    controls.append(dots);
                }
                button(String(index), index, false, page === index);
                previous = index;
            }
            button('›', page + 1, page === pages);
        }
        size.addEventListener('change', () => { page = 1; render(); });
        return { render, reset() { page = 1; render(); } };
    }
    const desktop = paginator('desktop', rows, () => filteredRows, 'row-hidden');
    const mobile = paginator('mobile', cards, () => filteredCards, 'card-hidden');
    const currency = value => '₱' + value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function applyFilters() {
        const invalid = Boolean(from.value && to.value && from.value > to.value);
        byId('date-error').hidden = !invalid;
        [from, to].forEach(input => input.setAttribute('aria-invalid', String(invalid)));
        filteredRows = rows.filter(matches);
        filteredCards = cards.filter(matches);
        desktop.reset();
        mobile.reset();
        byId('stat-total').textContent = filteredRows.length;
        byId('stat-pending').textContent = filteredRows.filter(row => row.dataset.status === 'PENDING').length;
        const delivered = filteredRows.filter(row => row.dataset.status === 'DELIVERED');
        byId('stat-delivered').textContent = delivered.length;
        byId('stat-revenue').textContent = currency(delivered.reduce((sum, row) => sum + (Number(row.dataset.amount) || 0), 0));
        byId('orders-count').textContent = filteredRows.length;
        const active = Boolean(search.value.trim() || status.value || from.value || to.value);
        byId('filter-summary').textContent = active ? `${filteredRows.length} of ${rows.length} orders match your filters.` : `Showing all ${rows.length} orders.`;
        byId('orders-empty').hidden = filteredRows.length > 0;
        if (rows.length) {
            byId('empty-title').textContent = 'No matching orders';
            byId('empty-description').textContent = 'Try a different search or reset your filters to see all orders.';
        }
        byId('btn-print').disabled = invalid || filteredRows.length === 0;
    }
    search.addEventListener('input', applyFilters);
    [status, from, to].forEach(input => input.addEventListener('change', applyFilters));
    byId('reset-filters').addEventListener('click', () => {
        [search, status, from, to].forEach(input => { input.value = ''; });
        applyFilters();
    });

    function preparePrint() {
        const parts = [];
        if (search.value.trim()) parts.push('Search: ' + search.value.trim());
        if (status.value) parts.push('Status: ' + status.options[status.selectedIndex].text);
        if (from.value) parts.push('From: ' + from.value);
        if (to.value) parts.push('To: ' + to.value);
        if (!parts.length) parts.push('All orders');
        parts.push(filteredRows.length + ' orders');
        parts.push('Generated: ' + new Date().toLocaleString('en-PH'));
        byId('print-meta').textContent = parts.join(' | ');
        rows.forEach(row => row.classList.toggle('row-hidden', !filteredRows.includes(row)));
    }
    window.addEventListener('beforeprint', preparePrint);
    window.addEventListener('afterprint', () => desktop.render());
    byId('btn-print').addEventListener('click', () => {
        preparePrint();
        window.print();
        desktop.render();
    });
    applyFilters();
})();
