'use strict';
(() => {
    const $ = id => document.getElementById(id);
    const money = cents => new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}).format(cents / 100);
    const cents = value => Math.round(Number(value || 0) * 100);
    const decimal = value => (value / 100).toFixed(2);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
    const storageKey = `la-elena-pos-${document.body.dataset.staffId}`;
    let catalog = [], cart = [], taxRate = Number(document.body.dataset.tax), busy = false, pending = null, receipt = null, page = 1, pages = 1;
    function message(text, error = false) { $('message').textContent = text; $('message').className = error ? 'error' : ''; $('message').hidden = !text; }
    async function api(query = '', data = null) {
        const response = await fetch(`api.php${query}`, {credentials: 'same-origin', cache: 'no-store', ...(data ? {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(data)} : {})});
        let result;
        try { result = await response.json(); } catch { throw new Error('The server could not respond. Check sales history before starting another sale.'); }
        if (!response.ok) {
            const error = new Error(result.error || 'Unable to complete this request.');
            error.definitive = response.status === 422;
            throw error;
        }
        return result;
    }
    function save() {
        try { sessionStorage.setItem(storageKey, JSON.stringify({cart, pending, fields: Object.fromEntries(['service','table','customer','notes','discount','discount-reason','payment','tendered','reference'].map(id => [id, $(id).value]))})); } catch { /* Blocked storage must not prevent a sale. */ }
    }
    function restore() {
        try {
            const draft = JSON.parse(sessionStorage.getItem(storageKey));
            if (!draft) return;
            cart = Array.isArray(draft.cart) ? draft.cart : [];
            pending = draft.pending || null;
            Object.entries(draft.fields || {}).forEach(([id, value]) => { if ($(id)) $(id).value = value; });
            if (pending) $('confirmed').checked = pending.payment_confirmed;
        } catch { cart = []; pending = null; }
    }
    function totals() {
        const subtotal = cart.reduce((sum, item) => sum + cents(item.price) * item.quantity, 0);
        const discount = Math.max(0, cents($('discount').value));
        const tax = Math.round(Math.max(0, subtotal - discount) * taxRate / 10000);
        return {subtotal, discount, tax, total: Math.max(0, subtotal - discount) + tax};
    }
    function renderTotals() {
        const sum = totals();
        ['subtotal','tax','total'].forEach(key => $(key).textContent = money(sum[key]));
        $('discount-display').textContent = `−${money(sum.discount)}`;
        $('tax-label').textContent = `Tax (${taxRate / 100}%)`;
        const change = cents($('tendered').value) - sum.total;
        $('change-label').textContent = change < 0 ? 'Remaining due' : 'Change';
        $('change').textContent = money(Math.abs(change));
        $('checkout').disabled = busy || !cart.length;
        $('checkout').textContent = busy ? 'Saving sale…' : pending ? 'Retry / recover sale' : `Complete sale · ${money(sum.total)}`;
    }
    function renderCart() {
        $('order-count').textContent = cart.reduce((count, item) => count + Number(item.quantity), 0);
        $('cart').innerHTML = cart.length ? cart.map(item => `<div class="cart-line"><div><strong>${escape(item.item_name)}</strong><small>${money(cents(item.price))} each</small></div><div class="line-total">${money(cents(item.price) * item.quantity)}</div><div class="quantity"><button type="button" data-qty="${Number(item.item_id)}" data-step="-1" aria-label="Decrease ${escape(item.item_name)}">−</button><input aria-label="Quantity for ${escape(item.item_name)}" type="number" min="1" max="999" value="${Number(item.quantity)}" data-input="${Number(item.item_id)}"><button type="button" data-qty="${Number(item.item_id)}" data-step="1" aria-label="Increase ${escape(item.item_name)}">+</button></div><button type="button" class="text-button remove" data-remove="${Number(item.item_id)}">Remove</button></div>`).join('') : '<div class="empty cart-empty"><strong>Your order starts here</strong><span>Select an item from the menu to get started.</span></div>';
        renderTotals(); save();
    }
    function imagePath(path) {
        const clean = String(path || '').replace(/^(\.\.\/)+/, '');
        return /^(images|uploads)\/[a-zA-Z0-9_./ -]+$/.test(clean) && !clean.includes('..') ? `../../${clean}` : '';
    }
    function renderCatalog() {
        const query = $('search').value.trim().toLowerCase(), category = $('category').value;
        const items = catalog.filter(item => (!category || (item.category || 'Other') === category) && `${item.item_name} ${item.category || ''}`.toLowerCase().includes(query));
        $('menu-count').textContent = `${items.length} menu item${items.length === 1 ? '' : 's'} · Stock excludes pending online orders`;
        $('products').innerHTML = items.map(item => {
            const image = imagePath(item.image_url), available = Number(item.available);
            return `<button type="button" class="product" data-add="${Number(item.item_id)}" ${!available || busy || pending ? 'disabled' : ''}><span class="product-art">${image ? `<img src="${escape(image)}" alt="" loading="lazy">` : 'LE'}</span><span class="product-info"><span class="product-category">${escape(item.category || 'Other')}</span><span class="product-name">${escape(item.item_name)}</span><span class="product-bottom"><strong>${money(cents(item.price))}</strong><span class="stock">${available ? `${available} left <span class="add-icon" aria-hidden="true">+</span>` : 'Unavailable'}</span></span></span></button>`;
        }).join('') || '<p class="empty">No menu items match your search.</p>';
        $('products').querySelectorAll('img').forEach(img => img.addEventListener('error', () => { img.parentElement.textContent = 'LE'; }));
    }
    async function refresh() {
        const result = await api(); catalog = result.items; taxRate = result.tax_basis_points;
        const category = $('category').value;
        $('category').innerHTML = '<option value="">All categories</option>' + [...new Set(catalog.map(item => item.category || 'Other'))].map(name => `<option value="${escape(name)}">${escape(name)}</option>`).join('');
        $('category').value = category;
        if (!pending) cart.forEach(item => { const latest = catalog.find(product => Number(product.item_id) === Number(item.item_id)); if (latest) item.price = latest.price; });
        renderCatalog(); renderCart(); lock();
    }
    function lock() {
        // Keep the original payload immutable after an uncertain network response.
        const refreshButton = $('refresh');
        $('sale-fields').disabled = busy;
        $('sale-fields').querySelectorAll('input,select,textarea,button').forEach(el => { if (el.id !== 'checkout') el.disabled = busy || !!pending; });
        $('clear').disabled = busy || !!pending;
        if (refreshButton) refreshButton.disabled = busy || !!pending;
        renderTotals(); renderCatalog();
    }
    function updateFields() {
        const cash = $('payment').value === 'CASH';
        $('cash-fields').hidden = !cash; $('external-fields').hidden = cash;
        $('tendered').required = cash; $('reference').required = !cash; $('confirmed').required = !cash;
        $('table-label').hidden = $('service').value !== 'DINE_IN';
        renderTotals(); save();
    }
    function clearSale() {
        cart = []; pending = null; $('checkout-form').reset(); updateFields(); renderCart(); lock();
    }
    function showReceipt(sale) {
        receipt = sale;
        const row = (label, value) => `<div><span>${escape(label)}</span><strong>${escape(value)}</strong></div>`;
        $('receipt').innerHTML = `<header><h2>La Elena Fishyalan</h2><p>Sinacaban · Counter sale</p><strong>${escape(sale.receipt_number)}</strong><p>${escape(sale.status)}</p></header><div class="receipt-meta">${escape(sale.sold_at)}<br>Cashier: ${escape(sale.cashier_name)}<br>Customer: ${escape(sale.customer_name)}<br>${sale.service_type === 'DINE_IN' ? 'Dine-in' : 'Takeaway'}${sale.table_number ? ` · Table: ${escape(sale.table_number)}` : ''}</div><table><thead><tr><th>Item</th><th>Qty</th><th>Amount</th></tr></thead><tbody>${sale.items.map(item => `<tr><td>${escape(item.item_name)}<br><small>${money(cents(item.unit_price))} each</small></td><td>${Number(item.quantity)}</td><td>${money(cents(item.line_total))}</td></tr>`).join('')}</tbody></table><div class="totals">${row('Subtotal', money(cents(sale.subtotal)))}${row('Discount', money(cents(sale.discount_amount)))}${row(`Tax (${Number(sale.tax_rate)}%)`, money(cents(sale.tax_amount)))}<div class="grand-total"><span>Total</span><strong>${money(cents(sale.total_amount))}</strong></div>${row('Payment', sale.payment_method)}${row('Amount received', money(cents(sale.amount_tendered)))}${row('Change', money(cents(sale.change_amount)))}</div>${sale.payment_reference ? `<p>Reference: ${escape(sale.payment_reference)}</p>` : ''}${sale.discount_reason ? `<p>Discount: ${escape(sale.discount_reason)}</p>` : ''}${sale.notes ? `<p>Notes: ${escape(sale.notes)}</p>` : ''}${sale.status === 'REFUNDED' ? `<p>Refund recorded: ${escape(sale.refunded_at)}<br>Reason: ${escape(sale.refund_reason)}<br>Stock restored: ${Number(sale.stock_restored) ? 'Yes' : 'No'}</p>` : ''}<footer>Thank you for dining with us!<br>Sales receipt</footer>`;
        $('refund-details').hidden = sale.status !== 'COMPLETED'; $('refund-details').open = false; $('refund-form').reset();
        if (!$('receipt-dialog').open) $('receipt-dialog').showModal();
    }
    async function history() {
        const result = await api(`?action=history&date=${encodeURIComponent($('history-date').value)}&page=${page}`);
        page = result.page; pages = result.pages;
        $('summary').innerHTML = [['Receipts', result.summary.sales_count], ['Net sales', money(cents(result.summary.net_sales))], ['Cash sales', money(cents(result.summary.cash_sales))], ['Refunded sales', money(cents(result.summary.refunds))]].map(([label, value]) => `<div class="summary-card"><span>${label}</span><strong>${escape(value)}</strong></div>`).join('');
        $('sales').innerHTML = result.sales.map(sale => `<tr><td>${escape(sale.receipt_number)}<small>${escape(sale.sold_at)}</small></td><td>${escape(sale.customer_name)}</td><td>${escape(sale.payment_method)}</td><td>${money(cents(sale.total_amount))}</td><td><span class="badge ${sale.status === 'REFUNDED' ? 'refunded' : ''}">${escape(sale.status)}</span></td><td><button type="button" data-receipt="${Number(sale.sale_id)}">View</button></td></tr>`).join('') || '<tr><td colspan="6" class="empty">No sales for this date.</td></tr>';
        $('page-label').textContent = `Page ${page} of ${pages}`; $('prev').disabled = page <= 1; $('next').disabled = page >= pages;
    }
    const safely = fn => async (...args) => { try { await fn(...args); } catch (error) { message(error.message, true); } };
    $('products').addEventListener('click', event => {
        const button = event.target.closest('[data-add]'); if (!button || busy || pending) return;
        const product = catalog.find(item => Number(item.item_id) === Number(button.dataset.add));
        const line = cart.find(item => Number(item.item_id) === Number(product.item_id));
        if ((line?.quantity || 0) >= Math.min(999, Number(product.available))) return message('No more available stock for this item.', true);
        if (line) line.quantity++; else cart.push({...product, quantity: 1});
        message(''); renderCart();
    });
    $('cart').addEventListener('click', event => {
        if (busy || pending) return;
        const button = event.target.closest('button'); if (!button) return;
        if (button.dataset.remove) cart = cart.filter(item => Number(item.item_id) !== Number(button.dataset.remove));
        if (button.dataset.qty) {
            const item = cart.find(item => Number(item.item_id) === Number(button.dataset.qty));
            const available = Number(catalog.find(product => Number(product.item_id) === Number(item.item_id))?.available || 0);
            const qty = item.quantity + Number(button.dataset.step);
            if (qty > Math.min(999, available)) return message('No more available stock for this item.', true);
            if (qty <= 0) cart = cart.filter(line => line !== item); else item.quantity = qty;
        }
        renderCart();
    });
    $('cart').addEventListener('change', event => {
        if (!event.target.dataset.input || busy || pending) return;
        const item = cart.find(item => Number(item.item_id) === Number(event.target.dataset.input));
        const available = Number(catalog.find(product => Number(product.item_id) === Number(item.item_id))?.available || 0);
        const qty = Number(event.target.value);
        if (!Number.isInteger(qty) || qty < 1 || qty > Math.min(999, available)) message('Enter a whole quantity within the available stock.', true); else item.quantity = qty;
        renderCart();
    });
    ['search','category'].forEach(id => $(id).addEventListener('input', renderCatalog));
    const refreshButton = $('refresh');
    if (refreshButton) refreshButton.addEventListener('click', safely(async () => { await refresh(); message('Menu prices and stock refreshed. Review your total before collecting payment.'); }));
    $('clear').addEventListener('click', () => { if (!cart.length || confirm('Clear this unpaid order?')) clearSale(); });
    $('checkout-form').addEventListener('input', () => { renderTotals(); save(); });
    $('service').addEventListener('change', updateFields); $('payment').addEventListener('change', () => { $('confirmed').checked = false; updateFields(); });
    $('exact').addEventListener('click', () => { $('tendered').value = decimal(totals().total); renderTotals(); save(); });
    $('checkout-form').addEventListener('submit', safely(async event => {
        event.preventDefault(); if (busy || !cart.length) return;
        if (!pending) {
            if (totals().discount > totals().subtotal) return message('Discount cannot exceed the subtotal.', true);
            const key = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
            pending = {action: 'checkout', request_key: key, items: cart.map(item => ({item_id: Number(item.item_id), quantity: item.quantity, unit_price: String(item.price)})), service_type: $('service').value, table_number: $('table').value, customer_name: $('customer').value, notes: $('notes').value, discount_amount: $('discount').value || '0', discount_reason: $('discount-reason').value, payment_method: $('payment').value, amount_tendered: $('tendered').value, payment_reference: $('reference').value, payment_confirmed: $('confirmed').checked};
        }
        busy = true; save(); lock(); message('Saving your sale…');
        try {
            const sale = await api('', pending);
            busy = false; clearSale(); showReceipt(sale); message(`Sale saved: ${sale.receipt_number}. Change: ${money(cents(sale.change_amount))}.`);
            await refresh();
        } catch (error) {
            if (error.definitive) pending = null;
            message(error.message + (pending ? ' Your checkout is saved in this tab. Use Retry / recover sale to safely retrieve or finish it.' : ''), true);
        } finally { busy = false; lock(); save(); }
    }));
    $('history-tab').addEventListener('click', safely(async () => { $('sale-view').hidden = true; $('history-view').hidden = false; $('history-tab').classList.add('selected'); $('sale-tab').classList.remove('selected'); $('history-tab').setAttribute('aria-pressed','true'); $('sale-tab').setAttribute('aria-pressed','false'); await history(); }));
    $('sale-tab').addEventListener('click', () => { $('sale-view').hidden = false; $('history-view').hidden = true; $('sale-tab').classList.add('selected'); $('history-tab').classList.remove('selected'); $('sale-tab').setAttribute('aria-pressed','true'); $('history-tab').setAttribute('aria-pressed','false'); });
    $('history-date').addEventListener('change', safely(async () => { page = 1; await history(); }));
    $('prev').addEventListener('click', safely(async () => { page--; await history(); })); $('next').addEventListener('click', safely(async () => { page++; await history(); }));
    $('sales').addEventListener('click', safely(async event => { const button = event.target.closest('[data-receipt]'); if (button) showReceipt(await api(`?action=receipt&id=${button.dataset.receipt}`)); }));
    $('print').addEventListener('click', () => window.print()); $('close-receipt').addEventListener('click', () => $('receipt-dialog').close());
    $('refund-form').addEventListener('submit', safely(async event => {
        event.preventDefault(); if (!$('refund-confirmed').checked || !receipt) return;
        $('refund-button').disabled = true;
        try {
            const sale = await api('', {action: 'refund', sale_id: receipt.sale_id, reason: $('refund-reason').value, restock: $('restock').checked, refund_confirmed: true});
            showReceipt(sale); message(`Refund recorded for ${sale.receipt_number}.`); await refresh(); if (!$('history-view').hidden) await history();
        } catch (error) { alert(error.message); } finally { $('refund-button').disabled = false; }
    }));
    restore(); updateFields(); renderCart(); lock();
    if (pending) message('An unfinished checkout was recovered. Use Retry / recover sale to safely retrieve or finish it.', true);
    safely(refresh)();
})();
