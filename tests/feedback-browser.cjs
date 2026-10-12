// Run: node tests/feedback-browser.cjs
// Tests the actual homepage section and assets with controlled API responses.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'la-elena-feedback-browser-'));
const profile = path.join(fixture, 'profile');
let reviews = [];
let fail = false;
const homepage = fs.readFileSync(path.resolve(__dirname, '../index.html'), 'utf8');
const section = homepage.slice(homepage.indexOf('    <!-- Testimonials Section -->'), homepage.indexOf('    <!-- Footer -->'));
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/api/getFeedback.php') {
        res.setHeader('Content-Type', 'application/json');
        res.statusCode = fail ? 503 : 200;
        res.end(JSON.stringify({success: !fail, data: reviews})); return;
    }
    if (['/style.css','/feedback.js'].includes(url.pathname)) {
        res.setHeader('Content-Type',url.pathname.endsWith('.css')?'text/css':'text/javascript');
        res.end(fs.readFileSync(path.resolve(__dirname,'..',url.pathname.slice(1)))); return;
    }
    res.setHeader('Content-Type','text/html; charset=utf-8');
    res.end('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="style.css"><style>.fade-up{opacity:1!important;transform:none!important}.container{width:90%;max-width:1200px;margin:auto}*{box-sizing:border-box}</style></head><body>'+section+'<script src="feedback.js"></script></body></html>');
});
const delay = ms => new Promise(resolve=>setTimeout(resolve,ms));
let browser, socket, checks=0;
const deadline = setTimeout(()=>{ browser?.kill(); server.close(); process.exitCode=1; console.error('Feedback browser timeout'); },55000);
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
    await call('Page.navigate',{url: 'http://127.0.0.1:'+server.address().port+'/'});
    await until("document.querySelector('#testimonialsStatus')?.textContent.includes('No public reviews')");
    assert.equal(await evaluate("document.querySelector('#testimonialsCarousel').hidden"),true); checks++;
    assert.equal(await evaluate("document.querySelector('#testimonials a').getAttribute('href')"),'customerSide/feedback.php'); checks++;
    const review=(name,rating=3)=>({name,initial:name[0],rating,comment:'A real customer experience.',date:'2026-10-07',category:'General'});
    reviews=[review('Ana S.',1),review('Ben T.',4),review('Clara U.',5)];
    reviews[0].comment='<img src=x onerror="window.reviewXss=true">';
    await evaluate("document.dispatchEvent(new Event('visibilitychange'))");
    await until("document.querySelectorAll('.testimonial-card').length === 3");
    assert.equal(await evaluate("document.querySelector('.testimonial-text').textContent"),reviews[0].comment); checks++;
    assert.equal(await evaluate("Boolean(window.reviewXss || document.querySelector('.testimonial-text img'))"),false); checks++;
    assert.equal(await evaluate("document.querySelector('.testimonial-stars').getAttribute('aria-label')"),'1 out of 5 stars'); checks++;
    for(const width of [1440,768,390,320]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
        await delay(150);
        assert.equal(await evaluate('document.documentElement.scrollWidth <= '+width),true,'Page overflow at '+width); checks++;
        assert.equal(await evaluate("document.querySelectorAll('.testimonial-dot').length"),width>=768?2:3); checks++;
        await evaluate("document.querySelector('#nextTestimonial').click()");
        await delay(550);
        const position=await evaluate("(()=>{const visible=Array.from(document.querySelectorAll('.testimonial-card')).find(c=>c.getAttribute('aria-hidden')==='false');return Math.abs(visible.getBoundingClientRect().left-document.querySelector('#testimonialsCarousel').getBoundingClientRect().left)})()");
        assert.ok(position<2,'Cards align at '+width); checks++;
        await evaluate("document.querySelector('#prevTestimonial').click()");
    }
    fail=true;
    await evaluate("document.dispatchEvent(new Event('visibilitychange'))");
    await until("document.querySelector('#retryTestimonials').hidden === false");
    assert.equal(await evaluate("document.querySelectorAll('.testimonial-card').length"),3); checks++;
    fail=false;reviews=[review('New review')];
    await evaluate("document.querySelector('#retryTestimonials').click()");
    await until("document.querySelectorAll('.testimonial-card').length === 1");
    assert.equal(await evaluate("document.querySelector('.testimonials-controls').hidden"),true); checks++;
    reviews=[];
    await evaluate("document.dispatchEvent(new Event('visibilitychange'))");
    await until("document.querySelector('#testimonialsCarousel').hidden");
    assert.equal(await evaluate("document.querySelectorAll('.testimonial-card').length"),0); checks++;
    fail=true;
    await call('Page.reload');
    await until("document.querySelector('#retryTestimonials')?.hidden === false");
    assert.equal(await evaluate("document.querySelector('#testimonialsStatus').textContent.includes('temporarily unavailable')"),true); checks++;
    assert.deepEqual(errors,[]); checks++;
    console.log('Passed '+checks+' feedback browser checks (empty/error states, privacy-safe text, ratings, refresh, and desktop/mobile carousel).');
    await call('Browser.close');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
    clearTimeout(deadline);socket?.close();if(browser&&browser.exitCode===null)browser.kill();server.close();await delay(500);
    const root=path.resolve(os.tmpdir())+path.sep;
    if(!path.resolve(fixture).startsWith(root)||!path.basename(fixture).startsWith('la-elena-feedback-browser-'))throw new Error('Unexpected fixture path');
    try{fs.rmSync(fixture,{recursive:true,force:true,maxRetries:4,retryDelay:300});}catch{}
});
