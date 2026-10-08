const { chromium } = require(process.env.KEEPER_PLAYWRIGHT_MODULE || 'playwright');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../public');
let configured = false, pin = '', revision = 0, authorized = false;
const calls = [];
const tenant = '00000000-0000-4000-8000-000000000002';
const server = http.createServer(async (req, res) => {
  if (req.url.startsWith('/v1/')) {
    let body = ''; for await (const chunk of req) body += chunk;
    const input = body ? JSON.parse(body) : null;
    calls.push({ method: req.method, path: req.url, tenant: req.headers['x-tenant-id'] });
    res.setHeader('Content-Type','application/json');
    if (req.url.endsWith('/auth/reauth')) {
      if (input.password !== 'fixture-password') { res.statusCode = 401; return res.end(JSON.stringify({ code:'invalid_credentials' })); }
      authorized = true; return res.end('{}');
    }
    assert.equal(req.headers['x-tenant-id'], tenant);
    if (req.method !== 'GET' && !authorized) { res.statusCode = 403; return res.end(JSON.stringify({code:'reauth_required'})); }
    if (req.method === 'PUT') { pin = input.pin; configured = true; revision++; authorized = false; }
    if (req.url.endsWith('/reveal')) { authorized = false; return res.end(JSON.stringify({pin})); }
    return res.end(JSON.stringify({configured, revision, updated_at:null}));
  }
  if (req.url === '/') {
    res.setHeader('Content-Type','text/html; charset=utf-8');
    return res.end(`<main id="main"><h1>Ajustes</h1><section id="pin"></section></main><script type="module">import {setTenant,saveCsrf} from '/assets/api.js';import {mountUnlockPin,canConfigureUnlockPin} from '/assets/unlock-pin.js';setTenant('${tenant}');saveCsrf('fixture');window.canConfigure=canConfigureUnlockPin;await mountUnlockPin(document.querySelector('#pin'),'${tenant}');window.ready=true;</script>`);
  }
  const file = path.resolve(root, '.'+req.url);
  if (!file.startsWith(path.resolve(root)+path.sep)) { res.statusCode=404; return res.end(); }
  res.setHeader('Content-Type',req.url.endsWith('.js')?'text/javascript; charset=utf-8':'text/css');
  fs.createReadStream(file).on('error',()=>{res.statusCode=404;res.end();}).pipe(res);
});
(async () => {
  await new Promise(r=>server.listen(0,'127.0.0.1',r));
  const browser = await chromium.launch({headless:true});
  try {
    const page = await browser.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
    await page.goto(`http://127.0.0.1:${server.address().port}`); await page.waitForFunction(()=>window.ready);
    assert.equal(await page.getByRole('button',{name:'Ver PIN',exact:true}).isDisabled(),true);
    assert.equal(await page.evaluate(()=>window.canConfigure({platform:false,features:['comandos'],permissions:[{code:'equipos.desbloquear',scopes:['self']}]})),false);
    await page.getByRole('button',{name:'Configurar PIN',exact:true}).click();
    for (const [invalid, again, expected] of [
      ['123456', '123456', 'El PIN debe tener de 8 a 32 dígitos; tiene 6.'],
      ['1234567', '1234567', 'El PIN debe tener de 8 a 32 dígitos; tiene 7.'],
      ['1234567a', '1234567a', 'El PIN solo puede tener números.'],
      ['12345678', '1234567', 'Los dos campos no coinciden (el primero tiene 8 caracteres y el segundo 7). Bórralos y escríbelos de nuevo.'],
    ]) {
      await page.getByLabel('Nuevo PIN (8 a 32 dígitos)').fill(invalid); await page.getByLabel('Repite el PIN').fill(again);
      await page.getByRole('button',{name:'Guardar PIN',exact:true}).click();
      await page.getByText(expected,{exact:true}).waitFor();
      assert.equal(calls.filter(c=>c.method==='PUT' || c.path.endsWith('/auth/reauth')).length,0);
    }
    await page.getByLabel('Nuevo PIN (8 a 32 dígitos)').fill('0062 5184'); await page.getByLabel('Repite el PIN').fill('00625184');
    await page.getByRole('button',{name:'Guardar PIN',exact:true}).click();
    await page.getByRole('dialog',{name:'Confirma tu contraseña'}).getByRole('button',{name:'Cancelar',exact:true}).click();
    assert.equal(calls.filter(c=>c.method==='PUT').length,0);
    await page.getByRole('button',{name:'Guardar PIN',exact:true}).click();
    await page.getByLabel('Tu contraseña',{exact:true}).fill('wrong'); await page.getByRole('button',{name:'Confirmar',exact:true}).click();
    await page.getByText('Tu contraseña no es correcta.',{exact:true}).waitFor();
    assert.equal(calls.filter(c=>c.method==='PUT').length,0);
    await page.getByLabel('Tu contraseña',{exact:true}).fill('fixture-password'); await page.getByRole('button',{name:'Confirmar',exact:true}).click();
    await page.getByText('PIN guardado. Se enviará a los equipos en su próxima sincronización.',{exact:true}).waitFor();
    assert.equal(pin,'00625184'); assert.equal(revision,1);
    await page.waitForFunction(()=>document.querySelectorAll('dialog').length===0);
    for(let i=0;i<2;i++) {
      await page.getByRole('button',{name:'Ver PIN',exact:true}).click();
      await page.getByLabel('Tu contraseña',{exact:true}).fill('fixture-password'); await page.getByRole('button',{name:'Confirmar',exact:true}).click();
      await page.getByLabel('PIN del cliente',{exact:true}).waitFor();
      assert.equal(await page.getByLabel('PIN del cliente',{exact:true}).inputValue(),pin);
      await page.getByRole('button',{name:'Cerrar',exact:true}).click();
      await page.waitForFunction(()=>document.querySelectorAll('dialog').length===0);
    }
    assert.equal(calls.filter(c=>c.path.endsWith('/reveal')).length,2);
    assert.deepEqual(errors,[]);
    console.log('PASS Chromium panel: configure, cancel reauth, wrong password, save, reveal twice with reauth, clear secret, tenant header, restricted scope.');
  } finally { await browser.close(); await new Promise(r=>server.close(r)); }
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
