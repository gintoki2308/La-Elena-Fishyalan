// Dependency-free browser smoke test using local Edge and isolated mock API data.
// Run: node tests/pos-browser.cjs. No application sessions or sales are created.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const os = require('node:os');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../staffSide/pos');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'la-elena-pos-browser-'));
const product = {item_id: 1, item_name: 'Test meal', category: 'Meals', price: '100.05', available: 10};
let lastRequest, checkoutRequests = [], firstAttempt = true;
const sale = {sale_id: 1, receipt_number: 'POS-TEST-001', sold_at: '2026-10-04 12:00:00', cashier_name: 'Test Cashier', customer_name: 'Walk-in', service_type: 'DINE_IN', subtotal: '100.05', discount_amount: '0.00', tax_rate: '10.00', tax_amount: '10.01', total_amount: '110.06', payment_method: 'CASH', amount_tendered: '110.06', change_amount: '0.00', status: 'COMPLETED', items: [{item_name: 'Test meal', quantity: 1, unit_price: '100.05', line_total: '100.05'}]};
const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/api.php') {
        res.setHeader('Content-Type','application/json');
        if (req.method === 'POST') {
            let body = ''; for await (const chunk of req) body += chunk;
            lastRequest = JSON.parse(body);
            if (lastRequest.action === 'checkout') {
                checkoutRequests.push(lastRequest);
                if (firstAttempt) { firstAttempt = false; res.statusCode = 503; res.end(JSON.stringify({error: 'Simulated uncertain response'})); return; }
                res.end(JSON.stringify(sale)); return;
            }
            Object.assign(sale, {status: 'REFUNDED', refunded_at: '2026-10-04 12:10:00', refund_reason: lastRequest.reason, stock_restored: 0});
            res.end(JSON.stringify(sale)); return;
        }
        if (url.searchParams.get('action') === 'history') {
            res.end(JSON.stringify({sales: [sale], summary: {sales_count: 1, net_sales: '110.06', refunds: '0', cash_sales: '110.06'}, page: 1, pages: 1})); return;
        }
        if (url.searchParams.get('action') === 'receipt') { res.end(JSON.stringify(sale)); return; }
        res.end(JSON.stringify({items: [product], tax_basis_points: 1000})); return;
    }
    if (url.pathname === '/' || url.pathname === '/index.php') {
        let html = fs.readFileSync(path.join(root,'index.php'), 'utf8').replace(/^<\?php[\s\S]*?\?>/, '');
        html = html.replace(/<\?=\s*\$taxBasisPoints\s*\?>/g,'1000').replace(/<\?=\s*\$staffId\s*\?>/g,'1').replace(/<\?= date\('Y-m-d'\) \?>/g,'2026-10-04').replace(/<\?=[\s\S]*?\?>/g,'Test');
        res.setHeader('Content-Type','text/html; charset=utf-8'); res.end(html); return;
    }
    if (['/pos.js','/pos.css'].includes(url.pathname)) {
        res.setHeader('Content-Type',url.pathname.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'text/css');
        res.end(fs.readFileSync(path.join(root,url.pathname.slice(1)))); return;
    }
    res.statusCode = 404; res.end();
});
let browser, socket, checks = 0;
const deadline = setTimeout(() => { console.error('Browser test timed out.'); browser?.kill(); server.close(); process.exit(1); }, 45000);
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    browser = spawn(process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', ['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'], {windowsHide: true, stdio: 'ignore'});
    browser.on('error', error => { console.error(error.message); });
    const portFile = path.join(profile,'DevToolsActivePort');
    for (let i=0; i<100 && !fs.existsSync(portFile); i++) await delay(100);
    if (!fs.existsSync(portFile)) throw new Error('Edge did not start its debugging endpoint.');
    const port = fs.readFileSync(portFile,'utf8').split('\n')[0];
    const targets = await (await fetch(`http://127.0.0.1:${port}/json`, {signal: AbortSignal.timeout(10000)})).json();
    socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
    await new Promise((resolve,reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let sequence = 0; const waiting = new Map(), errors = [];
    socket.onmessage = event => { const data = JSON.parse(event.data); if (data.id) { const callback = waiting.get(data.id); waiting.delete(data.id); data.error ? callback.reject(data.error) : callback.resolve(data.result); } if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text); };
    const call = (method,params={}) => new Promise((resolve,reject) => { const id = ++sequence; const timeout = setTimeout(() => reject(new Error(`CDP timeout: ${method}`)),10000); waiting.set(id,{resolve: value => { clearTimeout(timeout); resolve(value); },reject: error => { clearTimeout(timeout); reject(error); }}); socket.send(JSON.stringify({id,method,params})); });
    const evaluate = async expression => { const result = await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true}); if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails)); return result.result.value; };
    const until = async expression => { for (let i=0;i<100;i++) { if (await evaluate(expression)) return; await delay(50); } throw new Error(`Timed out: ${expression}`); };
    await call('Runtime.enable'); await call('Page.enable');
    await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await call('Page.navigate',{url:`http://127.0.0.1:${server.address().port}/`});
    await until('document.querySelectorAll(".product").length === 1'); checks++;
    // The checkout must remain visible on desktop, including after a stock notice.
    await evaluate('document.getElementById("refresh").click()');
    await until('!document.getElementById("message").hidden');
    for (const [width, height] of [[1440,900], [1366,768], [1093,724], [900,1024], [390,844], [320,700]]) {
        await call('Emulation.setDeviceMetricsOverride', {width, height, deviceScaleFactor: 1, mobile: width < 600});
        assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true, `Horizontal overflow at ${width}px`); checks++;
        if (width > 900) {
            assert.equal(await evaluate('document.getElementById("checkout").getBoundingClientRect().bottom <= window.innerHeight'), true, `Checkout below viewport at ${width}px`); checks++;
        }
        if (process.env.POS_SCREENSHOT_DIR && [1440,390].includes(width)) {
            fs.mkdirSync(process.env.POS_SCREENSHOT_DIR, {recursive: true});
            const shot = await call('Page.captureScreenshot', {format: 'png', captureBeyondViewport: true});
            fs.writeFileSync(path.join(process.env.POS_SCREENSHOT_DIR, `pos-${width}.png`), Buffer.from(shot.data, 'base64'));
        }
    }
    await call('Emulation.setDeviceMetricsOverride', {width: 1440, height: 900, deviceScaleFactor: 1, mobile: false});
    await evaluate('document.querySelector(".product").click()');
    assert.equal(await evaluate('document.getElementById("order-count").textContent'), '1'); checks++;
    assert.match(await evaluate('document.getElementById("total").textContent'),/110\.06/); checks++;
    await evaluate('document.getElementById("exact").click(); document.getElementById("checkout").click()');
    await until('document.getElementById("checkout").textContent.includes("Retry")');
    assert.equal(await evaluate('document.getElementById("clear").disabled'),true); checks++;
    await call('Page.reload');
    await until('document.getElementById("checkout")?.textContent.includes("Retry")');
    await evaluate('document.getElementById("checkout").click()');
    await until('document.getElementById("receipt-dialog").open');
    assert.deepEqual(checkoutRequests[0],checkoutRequests[1]); checks++;
    assert.match(await evaluate('document.getElementById("receipt").textContent'),/POS-TEST-001/); checks++;
    await evaluate('document.getElementById("close-receipt").click(); document.getElementById("history-tab").click()');
    await until('document.querySelector("[data-receipt]") !== null');
    await evaluate('document.querySelector("[data-receipt]").click()');
    await until('document.getElementById("receipt-dialog").open'); checks++;
    await evaluate('document.getElementById("refund-details").open = true; document.getElementById("refund-reason").value = "Prepared meal refund"; document.getElementById("refund-confirmed").checked = true; document.getElementById("refund-button").click()');
    await until('document.getElementById("receipt").textContent.includes("REFUNDED")');
    assert.equal(lastRequest.restock,false); checks++;
    await evaluate('document.getElementById("close-receipt").click(); document.getElementById("sale-tab").click()');
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'),true); checks++;
    await evaluate('document.getElementById("payment").value = "GCASH"; document.getElementById("payment").dispatchEvent(new Event("change"))');
    assert.equal(await evaluate('document.getElementById("reference").required && document.getElementById("cash-fields").hidden'),true); checks++;
    assert.deepEqual(errors,[]); checks++;
    console.log(`POS browser tests passed: ${checks} checks (desktop, mobile, retry recovery, receipt, history, refund).`);
    await call('Browser.close');
})().catch(error => {console.error(error); process.exitCode = 1;}).finally(async () => {
    clearTimeout(deadline);
    socket?.close(); if (browser && browser.exitCode === null) browser.kill(); server.close();
    // Only remove the uniquely allocated test profile under the OS temporary directory.
    await delay(500);
    const allowedRoot = path.resolve(os.tmpdir()) + path.sep;
    if (!path.resolve(profile).startsWith(allowedRoot) || !path.basename(profile).startsWith('la-elena-pos-browser-')) throw new Error('Unexpected test profile path.');
    try { fs.rmSync(profile,{recursive:true,force:true,maxRetries:4,retryDelay:300}); } catch { /* Edge may still be releasing its test profile. */ }
});
