<?php
require __DIR__ . '/access.php';
$csrf = posCsrfToken();
$taxBasisPoints = posTaxBasisPoints();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <title>Point of Sale | La Elena Fishyalan</title>
    <link rel="stylesheet" href="pos.css?v=<?= filemtime(__DIR__ . '/pos.css') ?>">
    <script src="pos.js?v=<?= filemtime(__DIR__ . '/pos.js') ?>" defer></script>
</head>
<body data-staff-id="<?= $staffId ?>" data-tax="<?= $taxBasisPoints ?>">
<header class="topbar">
    <div class="brand"><h1>Point of Sale <span>La Elena Fishyalan</span></h1></div>
    <div class="header-actions">
        <div class="operator"><span class="status-dot"></span><?= htmlspecialchars(trim($cashier['first_name'] . ' ' . $cashier['last_name'])) ?><small>Cashier</small></div>
        <a class="back" href="../staff-dashboard.php">&larr; Staff dashboard</a>
    </div>
</header>
<nav class="tabs" aria-label="POS workspace"><button id="sale-tab" class="selected" type="button" aria-pressed="true">New sale</button><button id="history-tab" type="button" aria-pressed="false">My sales &amp; receipts</button></nav>
<div id="message" role="status" aria-live="polite" hidden></div>
<noscript><p class="notice">Enable JavaScript to use the Point of Sale.</p></noscript>
<main id="sale-view" class="workspace">
    <section class="catalog" aria-label="Menu">
        <div class="filters"><label class="search">Search menu<span class="search-field"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg><input id="search" type="search" placeholder="Search by name or category" autocomplete="off"></span></label><label>Category<select id="category"><option value="">All categories</option></select></label></div>
        <p id="menu-count" class="muted" aria-live="polite">Loading menu…</p>
        <div id="products" class="products"></div>
    </section>
    <aside class="order-panel" aria-labelledby="order-heading">
        <div class="section-heading order-heading"><div><p class="eyebrow">NEW SALE</p><h2 id="order-heading">Current order <span id="order-count" class="order-count">0</span></h2></div><button id="clear" type="button" class="text-button">Clear order</button></div>
        <form id="checkout-form">
        <fieldset id="sale-fields">
        <div class="order-scroll">
        <div class="field-grid"><label>Order type<select id="service"><option value="DINE_IN">Dine-in</option><option value="TAKEAWAY">Takeaway</option></select></label><label id="table-label">Table / seat<input id="table" maxlength="30" placeholder="Optional"></label></div>
        <label>Customer name<input id="customer" maxlength="100" placeholder="Walk-in"></label>
        <div id="cart" class="cart" aria-live="polite"><div class="empty cart-empty"><strong>Your order starts here</strong><span>Select an item from the menu to get started.</span></div></div>
        <details class="order-notes"><summary>Add order notes</summary><label>Order notes<textarea id="notes" maxlength="500" rows="2" placeholder="Optional order instructions"></textarea></label></details>
        <details><summary>Apply a discount</summary><div class="field-grid"><label>Discount (₱)<input id="discount" type="number" min="0" step="0.01" value="0"></label><label>Reason<input id="discount-reason" maxlength="200" placeholder="Required for discounts"></label></div></details>
        <div class="payment-fields">
        <label>Payment method<select id="payment"><option value="CASH">Cash</option><option value="GCASH">GCash — received externally</option><option value="CARD">Card — paid on terminal</option></select></label>
        <div id="cash-fields"><div class="cash-entry"><label>Cash received (₱)<input id="tendered" type="number" step="0.01" min="0" inputmode="decimal" placeholder="0.00" required></label><button id="exact" class="secondary" type="button">Exact amount</button></div><div class="change-line"><span id="change-label">Change</span><strong id="change">₱0.00</strong></div></div>
        <div id="external-fields" hidden><p class="muted">Collect payment using your GCash account or card terminal first.</p><label>Transaction reference<input id="reference" maxlength="100"></label><label class="check"><input id="confirmed" type="checkbox">I verified that payment was received.</label></div>
        </div>
        </div>
        <div class="order-footer">
        <div class="totals"><div><span>Subtotal</span><strong id="subtotal">₱0.00</strong></div><div><span>Discount</span><span id="discount-display">₱0.00</span></div><div><span id="tax-label">Tax</span><span id="tax">₱0.00</span></div><div class="grand-total"><span>Total due</span><strong id="total">₱0.00</strong></div></div>
        <button id="checkout" class="primary checkout" type="submit" disabled>Complete sale</button>
        <p class="muted fine-print">Stock and prices verified at checkout.</p>
        </div>
        </fieldset>
        </form>
    </aside>
</main>
<section id="history-view" class="history" hidden>
    <div class="section-heading"><div><p class="eyebrow">YOUR COUNTER ACTIVITY</p><h2>Sales &amp; receipts</h2></div><label>Sale date<input id="history-date" type="date" value="<?= date('Y-m-d') ?>"></label></div>
    <div id="summary" class="summary-cards"></div><p class="muted">Totals reflect the current status of sales made on the selected date.</p>
    <div class="table-wrap"><table><thead><tr><th>Receipt / Time</th><th>Customer</th><th>Payment</th><th>Total</th><th>Status</th><th>Receipt</th></tr></thead><tbody id="sales"></tbody></table></div>
    <div class="pagination"><button id="prev" type="button">Previous</button><span id="page-label"></span><button id="next" type="button">Next</button></div>
</section>
<dialog id="receipt-dialog"><div class="dialog-actions"><button id="print" type="button">Print receipt</button><button id="close-receipt" class="secondary" type="button">Close</button></div><article id="receipt"></article><details id="refund-details"><summary>Record a full refund</summary><p>Return payment to the customer first. This records the refund; it does not send money.</p><form id="refund-form"><label>Refund reason<input id="refund-reason" maxlength="200" required></label><label class="check"><input id="restock" type="checkbox">Return all items to sellable stock (unprepared items only)</label><label class="check"><input id="refund-confirmed" type="checkbox" required>I have returned the full payment to the customer.</label><button id="refund-button" class="danger" type="submit">Record full refund</button></form></details></dialog>
</body>
</html>
