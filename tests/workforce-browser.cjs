// Browser checks against synthetic HTML produced by tests/workforce.php.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const os = require('node:os');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const previews = path.join(os.tmpdir(),'la-elena-workforce-preview');
const profile = fs.mkdtempSync(path.join(os.tmpdir(),'la-elena-workforce-browser-'));
const root = path.resolve(__dirname,'..');
const server = http.createServer((req,res) => {
    const name = new URL(req.url,'http://localhost').pathname.split('/').pop();
    const files = {'attendance.html':path.join(previews,'attendance.html'),'workforce.css':path.join(root,'staffSide/workforce.css'),'staff-page-theme.css':path.join(root,'staffSide/staff-page-theme.css'),'attendance.js':path.join(root,'staffSide/attendance.js'),'payroll.js':path.join(root,'adminSide/employeeCrud/payroll.js')};
    if(name==='payrollPreview.php') { res.setHeader('Content-Type','application/json'); res.end(fs.readFileSync(path.join(previews,'payroll-api.json'))); return; }
    for (const page of ['admin-attendance','admin-records','admin-detail','payroll-all','payroll-selected','payroll-detail','payroll-paid','payroll-preview']) files[page+'.html']=path.join(previews,page+'.html');
    files['admin-theme.css']=path.join(root,'adminSide/admin-theme.css');
    files['admin-logout.js']=path.join(root,'adminSide/admin-logout.js');
    if (!files[name]) { res.writeHead(404); res.end(); return; }
    res.setHeader('Content-Type',name.endsWith('.css')?'text/css':name.endsWith('.js')?'text/javascript':'text/html; charset=utf-8');
    let content=fs.readFileSync(files[name]);
    if(name.endsWith('.html')) content=content.toString().replaceAll('http://localhost/La_Elena/',`http://127.0.0.1:${server.address().port}/La_Elena/`);
    res.end(content);
});
let browser, socket, checks=0;
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const deadline=setTimeout(()=>{ console.error('Workforce browser test timed out.'); browser?.kill(); server.close(); process.exit(1); },55000);
(async()=>{
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    browser=spawn(process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
    const portFile=path.join(profile,'DevToolsActivePort');
    let port;
    for(let i=0;i<100&&!port;i++) { try { port=fs.readFileSync(portFile,'utf8').split('\n')[0]; } catch {} if(!port) await delay(100); }
    if(!port) throw new Error('Edge did not start.');
    const targets=await (await fetch(`http://127.0.0.1:${port}/json`)).json();
    socket=new WebSocket(targets.find(target=>target.type==='page').webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
    let seq=0; const waiting=new Map(), errors=[];
    socket.onmessage=event=>{const data=JSON.parse(event.data);if(data.id){const callback=waiting.get(data.id);waiting.delete(data.id);data.error?callback.reject(data.error):callback.resolve(data.result);}if(data.method==='Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text);};
    const call=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq;const timeout=setTimeout(()=>reject(new Error('Timed out: '+method)),12000);waiting.set(id,{resolve:value=>{clearTimeout(timeout);resolve(value);},reject:error=>{clearTimeout(timeout);reject(error);}});socket.send(JSON.stringify({id,method,params}));});
    const evaluate=async expression=>{const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error(JSON.stringify(result.exceptionDetails));return result.result.value;};
    await call('Runtime.enable');await call('Page.enable');
    for(const page of ['attendance','admin-attendance','admin-records','admin-detail','payroll-all','payroll-selected','payroll-detail','payroll-paid','payroll-preview']) {
        await call('Page.navigate',{url:`http://127.0.0.1:${server.address().port}/${page}.html`});
        for(let i=0;i<100;i++){if(await evaluate('!!document.querySelector(".workforce-page") && !!document.querySelector(".session-card")'))break;await delay(50);}
        await delay(700);
        for(const width of [1440,390,320]) {
            await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
            await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
            assert.equal(await evaluate('document.documentElement.scrollWidth<=window.innerWidth'),true,`${page} overflow at ${width}`);checks++;
            if([1440,390].includes(width)) { const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(previews,`${page}-${width}.png`),Buffer.from(shot.data,'base64')); }
        }
        if(!page.startsWith('payroll-')) { assert.equal(await evaluate('!/salary|payslip|earnings/i.test(document.body.textContent)'),true);checks++; }
        if(page==='payroll-selected') {
            assert.equal(await evaluate('document.getElementById("salaryRate").value'),'900.00');checks++;
            assert.equal(await evaluate('document.querySelector("#create-payroll form").checkValidity()'),true);checks++;
            assert.equal(await evaluate('document.getElementById("createdeductions").value="-1"; document.querySelector("#create-payroll form").checkValidity()'),false);checks++;
            await evaluate('document.getElementById("createdeductions").value="0.00"');
            assert.equal(await evaluate('document.querySelector("[data-generate-payroll]").disabled'),true);checks++;
            await evaluate('document.getElementById("period_start").value="2020-03-09"; document.getElementById("period_end").value="2020-03-09"; document.getElementById("period_end").dispatchEvent(new Event("input",{bubbles:true}))');
            for(let i=0;i<50;i++){ if(await evaluate('document.querySelector("#attendance-payroll-preview").textContent.includes("825.00")')) break; await delay(50); }
            assert.equal(await evaluate('document.querySelector("#attendance-payroll-preview").textContent.includes("825.00") && document.querySelector("#attendance-payroll-preview").textContent.includes("08:00:00 - 17:00:00")'),true);checks++;
            assert.equal(await evaluate('document.querySelector("[data-generate-payroll]").disabled'),false);checks++;
        }
        if(page==='payroll-detail') {
            assert.equal(await evaluate('document.getElementById("editdeductions").value'),'25.00');checks++;
            assert.equal(await evaluate('!!document.querySelector("#edit-payroll button") && document.querySelector("#edit-payroll form").checkValidity()'),true);checks++;
            await call('Emulation.setEmulatedMedia',{media:'print'});
            assert.equal(await evaluate('getComputedStyle(document.getElementById("edit-payroll")).display==="none" && getComputedStyle(document.getElementById("payroll-history")).display==="none" && getComputedStyle(document.getElementById("payslip")).display!=="none"'),true);checks++;
            await call('Emulation.setEmulatedMedia',{media:''});
        }
        if(page==='payroll-paid') { assert.equal(await evaluate('document.getElementById("edit-payroll")===null && document.getElementById("payslip").textContent.includes("PAID")'),true);checks++; }
        if(page==='payroll-preview') { assert.equal(await evaluate('document.querySelector(".payroll-preview").textContent.includes("1,325.00")'),true);checks++; }
        if(page==='admin-attendance') {
            assert.deepEqual(await evaluate('Array.from(document.querySelectorAll(".attendance-day")).map(day => day.dataset.attendanceDate)'),['2020-01-08','2020-01-07','2020-01-06']);checks++;
            assert.equal(await evaluate('document.querySelector(\'.attendance-day[data-attendance-date="2020-01-07"]\').textContent.includes("Alex Rivera") && document.querySelector(\'.attendance-day[data-attendance-date="2020-01-07"]\').textContent.includes("Jamie Santos")'),true);checks++;
            assert.equal(await evaluate('document.querySelector(\'.attendance-day[data-attendance-date="2020-01-06"]\').querySelectorAll("tbody tr").length'),2);checks++;
        }
        if(page==='attendance') {
            // With no assigned shift today, the current flexible session permits time-in.
            assert.equal(await evaluate('document.querySelectorAll(".session-actions button:disabled").length'),3);checks++;
            const before=await evaluate('document.getElementById("clockSecond").textContent');await delay(1200);
            assert.notEqual(await evaluate('document.getElementById("clockSecond").textContent'),before);checks++;
        }
    }
    assert.deepEqual(errors,[]);checks++;
    console.log(`Workforce browser tests passed: ${checks} checks. Screenshots: ${previews}`);
    await call('Browser.close');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
    clearTimeout(deadline);socket?.close();browser?.kill();server.close();await delay(500);
    const allowedRoot=path.resolve(os.tmpdir())+path.sep;
    if(!path.resolve(profile).startsWith(allowedRoot)||!path.basename(profile).startsWith('la-elena-workforce-browser-'))throw new Error('Unexpected test profile path');
    try{fs.rmSync(profile,{recursive:true,force:true,maxRetries:4,retryDelay:300});}catch{}
});
