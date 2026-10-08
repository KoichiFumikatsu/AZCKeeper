const { chromium } = require(process.env.KEEPER_PLAYWRIGHT_MODULE || 'playwright');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../public');
let disable = false, authorized = false;
const calls = [];
const tenant = '00000000-0000-4000-8000-000000000002';
const server = http.createServer(async (req, res) => {
  if (req.url.startsWith('/v1/')) {
    let body = ''; for await (const chunk of req) body += chunk;
    const input = body ? JSON.parse(body) : null;
    calls.push({ method: req.method, path: req.url, tenant: req.headers['x-tenant-id'], input });
    res.setHeader('Content-Type','application/json');
    if (req.url.endsWith('/auth/reauth')) {
      if (input.password !== 'fixture-password') { res.statusCode = 401; return res.end(JSON.stringify({ code:'invalid_credentials' })); }
      authorized = true; return res.end('{}');
    }
    assert.equal(req.headers['x-tenant-id'], tenant);
    assert.equal(req.url, `/v1/admin/tenants/${tenant}/windows-security`);
    if (req.method === 'PUT') {
      if (!authorized) { res.statusCode = 403; return res.end(JSON.stringify({code:'reauth_required'})); }
      disable = input.disable_smart_app_control; authorized = false;
    }
    return res.end(JSON.stringify({disable_smart_app_control: disable, updated_at: null, updated_by: null}));
  }
  if (req.url === '/') {
    res.setHeader('Content-Type','text/html; charset=utf-8');
    return res.end(`<main id="main"><h1>Ajustes</h1><section id="sac"></section></main><script type="module">import {setTenant,saveCsrf} from '/assets/api.js';import {mountWindowsSecurity,canConfigureWindowsSecurity} from '/assets/windows-security.js';setTenant('${tenant}');saveCsrf('fixture');window.canConfigure=canConfigureWindowsSecurity;await mountWindowsSecurity(document.querySelector('#sac'),'${tenant}');window.ready=true;</script>`);
  }
  const file = path.resolve(root, '.'+req.url);
  if (!file.startsWith(path.resolve(root)+path.sep)) { res.statusCode=404; return res.end(); }
  res.setHeader('Content-Type',req.url.endsWith('.js')?'text/javascript; charset=utf-8':'text/css');
  fs.createReadStream(file).on('error',()=>{res.statusCode=404;res.end();}).pipe(res);
});
const puts = () => calls.filter(c => c.method === 'PUT');
(async () => {
  await new Promise(r=>server.listen(0,'127.0.0.1',r));
  const browser = await chromium.launch({headless:true});
  try {
    const page = await browser.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
    await page.goto(`http://127.0.0.1:${server.address().port}`); await page.waitForFunction(()=>window.ready);
    const box = page.getByLabel('Desactivar Smart App Control en los equipos de este cliente', {exact:true});
    const save = page.getByRole('button',{name:'Guardar',exact:true});
    assert.equal(await box.isChecked(), false);
    assert.equal(await save.isDisabled(), true);
    await page.getByText('No se puede volver a encender', {exact:false}).first().waitFor();
    await page.getByText('Desactivado: Keeper no toca Smart App Control.',{exact:true}).waitFor();
    assert.equal(await page.evaluate(()=>window.canConfigure({platform:false,permissions:[{code:'equipos.desbloquear',scopes:['self']}]})),false);
    assert.equal(await page.evaluate(()=>window.canConfigure({platform:false,permissions:[{code:'equipos.desbloquear',scopes:['tenant']}]})),true);
    assert.equal(await page.evaluate(()=>window.canConfigure(null)),false);

    // Marcar y cancelar la confirmación: no se envía nada.
    await box.check(); assert.equal(await save.isDisabled(), false);
    await save.click();
    const confirm = page.getByRole('dialog',{name:'Desactivar Smart App Control'});
    await confirm.getByText('No se puede deshacer: Windows no permite volver a encender Smart App Control en un equipo donde ya se apagó.',{exact:true}).waitFor();
    await confirm.getByText('Desmarcar la casilla después solo evita que se apague en equipos nuevos.',{exact:true}).waitFor();
    await confirm.getByRole('button',{name:'Cancelar',exact:true}).click();
    assert.equal(puts().length, 0);

    // Confirmar, pero cancelar la contraseña: 403 reauth_required y no se guarda.
    await save.click();
    await page.getByRole('dialog',{name:'Desactivar Smart App Control'}).getByRole('button',{name:'Desactivar Smart App Control',exact:true}).click();
    await page.getByRole('dialog',{name:'Confirma tu contraseña'}).getByRole('button',{name:'Cancelar',exact:true}).click();
    await page.getByText('No se guardó: hay que confirmar tu contraseña.',{exact:true}).waitFor();
    assert.equal(disable, false); assert.equal(puts().length, 1);

    // Contraseña incorrecta y luego correcta: se guarda true.
    await save.click();
    await page.getByRole('dialog',{name:'Desactivar Smart App Control'}).getByRole('button',{name:'Desactivar Smart App Control',exact:true}).click();
    await page.getByLabel('Tu contraseña',{exact:true}).fill('wrong'); await page.getByRole('button',{name:'Confirmar',exact:true}).click();
    await page.getByText('Tu contraseña no es correcta.',{exact:true}).waitFor();
    await page.getByLabel('Tu contraseña',{exact:true}).fill('fixture-password'); await page.getByRole('button',{name:'Confirmar',exact:true}).click();
    await page.getByText('Guardado. Cada equipo lo aplicará al sincronizar y Smart App Control quedará apagado tras su próximo reinicio.',{exact:true}).waitFor();
    assert.equal(disable, true); assert.deepEqual(puts().at(-1).input, {disable_smart_app_control:true});
    await page.getByText('Activado: los equipos apagan Smart App Control.',{exact:true}).waitFor();
    assert.equal(await save.isDisabled(), true);
    await page.waitForFunction(()=>document.querySelectorAll('dialog').length===0);

    // Desmarcar: confirmación que explica que no se reactiva en los equipos ya apagados.
    await box.uncheck(); await save.click();
    const off = page.getByRole('dialog',{name:'Dejar de desactivar Smart App Control'});
    await off.getByText('En los equipos donde ya se apagó seguirá apagado: Windows no permite volver a encenderlo.',{exact:true}).waitFor();
    await off.getByRole('button',{name:'Guardar',exact:true}).click();
    await page.getByLabel('Tu contraseña',{exact:true}).fill('fixture-password'); await page.getByRole('button',{name:'Confirmar',exact:true}).click();
    await page.getByText('Guardado. Los equipos nuevos ya no apagarán Smart App Control.',{exact:true}).waitFor();
    assert.equal(disable, false); assert.deepEqual(puts().at(-1).input, {disable_smart_app_control:false});
    assert.ok(calls.filter(c=>!c.path.endsWith('/auth/reauth')).every(c=>c.tenant===tenant));
    assert.deepEqual(errors,[]);
    console.log('PASS Chromium panel SAC: off by default, cancel confirm, cancel reauth, wrong password, enable with reauth, disable with irreversibility notice, tenant header, restricted scope.');
  } finally { await browser.close(); await new Promise(r=>server.close(r)); }
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
