// Run: node tests/recipe-inventory-browser.cjs
// Renders actual PHP pages using the isolated integration-test database, then
// checks their recipe controls and responsive layouts in headless Edge.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const {spawn, spawnSync} = require('node:child_process');
const assert = require('node:assert/strict');
const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'la-elena-recipe-browser-'));
const profile = path.join(fixture, 'profile');
const php = spawnSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', ['tests/recipe-inventory.php', fixture], {cwd:path.resolve(__dirname,'..'), encoding:'utf8', windowsHide:true});
if (php.status !== 0) { console.error(php.stdout, php.stderr); process.exit(1); }
console.log(php.stdout.trim());
const server = http.createServer((req,res) => {
    const url = new URL(req.url,'http://localhost');
    const name = url.pathname.slice(1);
    const asset = name.replace(/^La_Elena\//, '');
    if (['config/inventory-layout.css','staffSide/staff-page-theme.css','ingredient-modal.css','adminSide/admin-theme.css','adminSide/admin-logout.js'].includes(asset)) {
        const file = asset === 'ingredient-modal.css' ? 'staffSide/inventoryCrud/ingredient-modal.css' : asset;
        res.setHeader('Content-Type',asset.endsWith('.css') ? 'text/css' : 'text/javascript');
        res.end(fs.readFileSync(path.resolve(__dirname,'..',file))); return;
    }
    // Rendered staff pages reference their theme relative to the inventory route.
    if (name === 'staff-page-theme.css') { res.setHeader('Content-Type','text/css'); res.end(fs.readFileSync(path.resolve(__dirname,'../staffSide/staff-page-theme.css'))); return; }
    if (!/^(admin|staff)-(ingredients|recipes|stock_in|stock_out|total_stock)\.html$/.test(name)) { res.statusCode=404; res.end(); return; }
    const itemId = url.searchParams.get('item_id');
    const file = name.endsWith('-recipes.html') && ['2','3'].includes(itemId) ? name.replace('.html',`-${itemId}.html`) : name;
    res.setHeader('Content-Type','text/html; charset=utf-8'); res.end(fs.readFileSync(path.join(fixture,file)));
});
const delay = ms => new Promise(resolve=>setTimeout(resolve,ms));
let browser, socket, checks=0;
const deadline = setTimeout(()=>{ browser?.kill(); server.close(); process.exitCode=1; console.error('Recipe browser timeout'); },55000);
(async()=>{
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    browser = spawn(process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
    const portFile=path.join(profile,'DevToolsActivePort');
    for(let i=0;i<100&&!fs.existsSync(portFile);i++) await delay(100);
    const port=fs.readFileSync(portFile,'utf8').split('\n')[0];
    const targets=await(await fetch(`http://127.0.0.1:${port}/json`)).json();
    socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
    let sequence=0;const waiting=new Map(),errors=[];
    socket.onmessage=event=>{const data=JSON.parse(event.data);if(data.id){const callback=waiting.get(data.id);waiting.delete(data.id);data.error?callback.reject(data.error):callback.resolve(data.result);}if(data.method==='Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text);};
    const call=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;const timeout=setTimeout(()=>reject(new Error(`CDP timeout: ${method}`)),10000);waiting.set(id,{resolve:value=>{clearTimeout(timeout);resolve(value);},reject:error=>{clearTimeout(timeout);reject(error);}});socket.send(JSON.stringify({id,method,params}));});
    const evaluate=async expression=>{const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error(JSON.stringify(result.exceptionDetails));return result.result.value;};
    const until=async expression=>{for(let i=0;i<300;i++){if(await evaluate(expression))return;await delay(50);}throw new Error(`Timed out: ${expression}; state=${await evaluate('JSON.stringify({url:location.href,ready:document.readyState,title:document.title,text:document.body?.innerText.slice(0,200)})')}`);};
    await call('Runtime.enable');await call('Page.enable');
    for(const role of ['admin','staff']) {
        for(const tab of ['ingredients','recipes','stock_in','stock_out','total_stock']) {
            await call('Page.navigate',{url:`http://127.0.0.1:${server.address().port}/${role}-${tab}.html`});
            await until(`document.readyState === 'complete' && document.querySelector('h1')?.textContent === 'Inventory Management'`);
            for(const [width,height] of [[1440,900],[768,1024],[390,844],[320,700]]) {
                await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<600});
                assert.equal(await evaluate(`document.documentElement.scrollWidth <= ${width}`),true,`${role} ${tab} overflow at ${width}`);checks++;
                if(process.env.INVENTORY_SCREENSHOT_DIR && [1440,390].includes(width)) {
                    fs.mkdirSync(process.env.INVENTORY_SCREENSHOT_DIR,{recursive:true});
                    const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
                    fs.writeFileSync(path.join(process.env.INVENTORY_SCREENSHOT_DIR,`${role}-${tab}-${width}.png`),Buffer.from(shot.data,'base64'));
                }
            }
            if(tab==='recipes') {
                assert.equal(await evaluate('document.querySelectorAll("#recipe-rows .recipe-row").length'),2);checks++;
                await evaluate('document.getElementById("add-recipe-ingredient").click()');
                assert.equal(await evaluate('document.querySelectorAll("#recipe-rows .recipe-row").length'),3);checks++;
                await evaluate('const last=document.querySelector("#recipe-rows .recipe-row:last-child"); last.querySelector("select").value="1"; last.querySelector("select").dispatchEvent(new Event("change")); last.querySelector("input").value="10.50";');
                assert.equal(await evaluate('document.querySelector("#recipe-rows .recipe-row:last-child .recipe-unit").textContent'),'g');checks++;
                assert.equal(await evaluate('new FormData(document.getElementById("recipe-form")).get("recipe[2][quantity]")'),'10.50');checks++;
                await evaluate('document.querySelector("#recipe-rows .recipe-row:last-child button").click()');
                assert.equal(await evaluate('document.getElementById("recipe-form").checkValidity()'),true);checks++;
                await evaluate('document.querySelectorAll("#recipe-rows .remove-recipe").forEach(button=>button.click())');
                assert.equal(await evaluate('document.getElementById("recipe-form").checkValidity()'),true);checks++;
                // Selecting a different food must replace the old rows without a Load click.
                for (const [itemId, expected] of [['2',[['1','100.00']]], ['3',[['','']]], ['1',[['1','200.00'],['2','0.25']]]]) {
                    await evaluate(`document.getElementById('menu-food').value=${JSON.stringify(itemId)}; document.getElementById('menu-food').dispatchEvent(new Event('change'));`);
                    await until(`document.readyState === 'complete' && new URL(location.href).searchParams.get('item_id') === '${itemId}' && document.getElementById('recipe-form')?.elements.namedItem('item_id').value === '${itemId}'`);
                    assert.deepEqual(await evaluate(`Array.from(document.querySelectorAll('#recipe-rows .recipe-row'), row => [row.querySelector('select').value, row.querySelector('input').value])`),expected,`${role}: food ${itemId} loads only its own recipe`);checks++;
                    assert.equal(await evaluate(`document.getElementById('menu-food').value`),itemId);checks++;
                    assert.equal(await evaluate(`document.getElementById('recipe-editor').disabled || document.getElementById('recipe-editor').hidden`),false);checks++;
                }
            } else if (tab === 'total_stock') {
                assert.deepEqual(await evaluate(`Array.from(Array.from(document.querySelectorAll('#total-stock-table tbody tr')).find(row => row.querySelector('th')?.textContent === 'Stock total test').children, cell => cell.textContent.trim())`), ['Stock total test','pieces','100.00','10.00','90.00','In stock']);checks++;
            } else if (tab !== 'ingredients') {
                assert.equal(await evaluate('document.getElementById("movement-date").value.length'),10);checks++;
                assert.equal(await evaluate('document.getElementById("movement-quantity").min'),'0.01');checks++;
            }
        }
    }
    assert.deepEqual(errors,[]);checks++;
    console.log(`Passed ${checks} recipe browser checks (both roles, desktop/mobile layouts, add/remove ingredients, units, form values, switching foods).`);
    await call('Browser.close');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
    clearTimeout(deadline);socket?.close();if(browser&&browser.exitCode===null)browser.kill();server.close();await delay(500);
    const root=path.resolve(os.tmpdir())+path.sep;
    if(!path.resolve(fixture).startsWith(root)||!path.basename(fixture).startsWith('la-elena-recipe-browser-'))throw new Error('Unexpected fixture path');
    try{fs.rmSync(fixture,{recursive:true,force:true,maxRetries:4,retryDelay:300});}catch{}
});
