const { chromium } = require(process.env.KEEPER_PLAYWRIGHT_MODULE || 'playwright');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../public');
const tenant = '00000000-0000-4000-8000-000000000002';
const deviceId = '00000000-0000-4000-8000-0000000000d1';
const scheduleId = '00000000-0000-4000-8000-0000000000a1';
const calls = [];
let rejectFirstPost = true;
let rows = [{ id: scheduleId, name: 'Cierre viernes', action: 'shutdown', recurrence: 'weekly', days: [5], time_local: '19:00', run_on: null, valid_from: '2026-10-01', valid_until: null,
  targets: [{ kind: 'tenant', id: tenant }], exclusions: [], message: '', idle_minutes: 15, notice_minutes: 10, postpone_minutes: 60, window_minutes: 180, status: 'active', version: 1, created_at: '2026-10-01T00:00:00Z', updated_at: '2026-10-01T00:00:00Z' }];
const json = (res, status, body) => { res.statusCode = status; res.setHeader('Content-Type', status >= 400 ? 'application/problem+json' : 'application/json'); res.end(body === undefined ? '' : JSON.stringify(body)); };
const server = http.createServer(async (req, res) => {
  if (req.url.startsWith('/v1/')) {
    let raw = ''; for await (const chunk of req) raw += chunk;
    const input = raw ? JSON.parse(raw) : null;
    const url = new URL(req.url, 'http://x'); const p = url.pathname.slice(3);
    calls.push({ method: req.method, path: p, tenant: req.headers['x-tenant-id'], ifMatch: req.headers['if-match'], input });
    if (p === `/tenants/${tenant}`) return json(res, 200, { id: tenant, name: 'Demo', timezone: 'America/Bogota' });
    if (p === '/organization') return json(res, 200, { data: [{ id: '00000000-0000-4000-8000-0000000000b1', kind: 'site', name: 'Sede Cali', active: true }], next_cursor: null });
    if (p === '/users') return json(res, 200, { data: [{ id: '00000000-0000-4000-8000-0000000000c1', display_name: 'Ana Pérez', status: 'active' }], next_cursor: null });
    if (p === `/tenants/${tenant}/devices`) return json(res, 200, { data: [{ id: deviceId, hostname: 'PC-01', status: 'active', user_id: '00000000-0000-4000-8000-0000000000c1' }], next_cursor: null });
    if (p === '/admin/power-schedules' && req.method === 'GET') return json(res, 200, { data: rows });
    if (p === '/admin/power-schedules' && req.method === 'POST') {
      if (rejectFirstPost) { rejectFirstPost = false; return json(res, 422, { code: 'validation_failed', detail: 'duplicate_target' }); }
      const created = { ...input, id: '00000000-0000-4000-8000-0000000000a2', version: 1, created_at: '2026-10-27T00:00:00Z', updated_at: '2026-10-27T00:00:00Z' };
      rows = [created, ...rows]; return json(res, 201, created);
    }
    if (p === `/admin/power-schedules/${scheduleId}` && req.method === 'PATCH') {
      rows = rows.map(r => r.id === scheduleId ? { ...r, ...input, version: r.version + 1 } : r); return json(res, 200, rows.find(r => r.id === scheduleId));
    }
    if (p === `/admin/power-schedules/${scheduleId}` && req.method === 'DELETE') { rows = rows.filter(r => r.id !== scheduleId); res.statusCode = 204; return res.end(); }
    if (p === `/admin/power-schedules/${scheduleId}/runs`) return json(res, 200, { data: [{ device_id: deviceId, hostname: 'PC-01', occurrence_at: '2026-10-23T00:00:00Z', outcome: 'executed_idle', detail: null, reported_at: '2026-10-23T00:01:00Z' }] });
    return json(res, 404, { code: 'not_found' });
  }
  if (req.url.split('?')[0] === '/') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.end(`<main id="main"><h1>Apagado programado</h1><button id="page-action" hidden></button><p id="page-status" role="status"></p><div id="page-content"></div></main><script type="module">import {setTenant,saveCsrf} from '/assets/api.js';import {powerSchedulesPage} from '/assets/power-schedules.js';setTenant('${tenant}');saveCsrf('fixture');try{await powerSchedulesPage({tenant:'${tenant}',content:document.getElementById('page-content'),status:document.getElementById('page-status'),action:document.getElementById('page-action')});}catch(e){window.loadError=e.message}window.ready=true;</script>`);
  }
  const file = path.resolve(root, '.' + req.url.split('?')[0]);
  if (!file.startsWith(path.resolve(root) + path.sep)) { res.statusCode = 404; return res.end(); }
  res.setHeader('Content-Type', req.url.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'text/css');
  fs.createReadStream(file).on('error', () => { res.statusCode = 404; res.end(); }).pipe(res);
});
const sent = method => calls.filter(c => c.method === method && c.path.startsWith('/admin/power-schedules'));
(async () => {
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  const browser = await chromium.launch({ headless: true });
  const base = `http://127.0.0.1:${server.address().port}`;
  try {
    const page = await browser.newPage(); const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.goto(base); await page.waitForFunction(() => window.ready);
    assert.equal(await page.evaluate(() => window.loadError), undefined);
    const row = page.getByRole('row').filter({ hasText: 'Cierre viernes' });
    await row.getByText('Viernes 19:00', { exact: true }).waitFor();
    await row.getByText('Apagar', { exact: true }).waitFor();
    await row.getByText('Activa', { exact: true }).waitFor();
    await row.getByText('1 alcance, 0 excepciones', { exact: true }).waitFor();
    assert.ok(calls.filter(c => !c.path.startsWith('/auth')).every(c => c.tenant === tenant));

    // Nueva programación: sin días -> mensaje; con un 422 de la API -> mensaje en español; luego se guarda con el cuerpo correcto.
    await page.getByRole('button', { name: 'Nueva programación', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Nueva programación' });
    await dialog.getByText('Si el equipo está en uso, la persona verá el aviso y podrá posponer una vez.', { exact: true }).waitFor();
    await dialog.getByLabel('Nombre', { exact: true }).fill('Reinicio de lunes');
    await dialog.getByLabel(/^Acción/).selectOption('restart');
    await dialog.getByLabel('Hora (zona horaria de la empresa)', { exact: true }).fill('07:30');
    await dialog.getByRole('button', { name: 'Guardar', exact: true }).click();
    await dialog.getByText('Elige al menos un día de la semana.', { exact: true }).waitFor();
    await dialog.getByLabel('Lunes', { exact: true }).check();
    await dialog.getByRole('button', { name: 'Guardar', exact: true }).click();
    await dialog.getByText('Elige al menos un alcance: a quién se aplica.', { exact: true }).waitFor();
    const targets = dialog.getByRole('group', { name: 'Aplica a' });
    await targets.getByLabel(/^Tipo/).selectOption('device');
    await targets.getByLabel(/^Cuál/).selectOption({ label: 'PC-01 (Ana Pérez)' });
    await targets.getByRole('button', { name: 'Agregar', exact: true }).click();
    await targets.getByText('Equipo: PC-01', { exact: true }).waitFor();
    assert.equal(sent('POST').length, 0);
    await dialog.getByRole('button', { name: 'Guardar', exact: true }).click();
    await dialog.getByText('Hay un alcance o una excepción repetida: quita el duplicado.', { exact: true }).waitFor();
    assert.equal(sent('POST').length, 1);
    await dialog.getByRole('button', { name: 'Guardar', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('dialog').length === 0);
    const body = sent('POST').at(-1).input;
    assert.deepEqual(body, { name: 'Reinicio de lunes', action: 'restart', recurrence: 'weekly', days: [1], time_local: '07:30', run_on: null, valid_from: body.valid_from, valid_until: null,
      targets: [{ kind: 'device', id: deviceId }], exclusions: [], message: '', idle_minutes: 15, notice_minutes: 10, postpone_minutes: 60, window_minutes: 180, status: 'active' });
    assert.match(body.valid_from, /^\d{4}-\d{2}-\d{2}$/);
    await page.getByRole('row').filter({ hasText: 'Reinicio de lunes' }).getByText('Lunes 07:30', { exact: true }).waitFor();

    // Pausar: PATCH solo con status y If-Match con la versión.
    await page.getByRole('button', { name: 'Pausar Cierre viernes', exact: true }).click();
    await page.getByRole('row').filter({ hasText: 'Cierre viernes' }).getByText('Pausada', { exact: true }).waitFor();
    assert.deepEqual(sent('PATCH').at(-1).input, { status: 'paused' }); assert.equal(sent('PATCH').at(-1).ifMatch, '"1"');
    await page.getByRole('button', { name: 'Reanudar Cierre viernes', exact: true }).waitFor();

    // Resultados.
    await page.getByRole('button', { name: 'Ver resultados Cierre viernes', exact: true }).click();
    const runs = page.getByRole('dialog', { name: 'Resultados: Cierre viernes' });
    await runs.getByText('PC-01', { exact: true }).waitFor(); await runs.getByText('Apagado sin uso', { exact: true }).waitFor();
    await runs.getByRole('button', { name: 'Cerrar', exact: true }).click();

    // Borrar pide escribir el nombre.
    await page.getByRole('button', { name: 'Borrar Cierre viernes', exact: true }).click();
    const confirm = page.getByRole('dialog', { name: 'Borrar programación' });
    const ok = confirm.getByRole('button', { name: 'Borrar programación', exact: true });
    assert.equal(await ok.isDisabled(), true);
    await confirm.getByLabel('Escribe el nombre de la programación para confirmar').fill('Cierre viernes');
    assert.equal(await ok.isDisabled(), false);
    assert.equal(sent('DELETE').length, 0);
    await ok.click();
    await page.waitForFunction(() => !document.body.textContent.includes('Cierre viernes'));
    assert.equal(sent('DELETE').length, 1); assert.equal(sent('DELETE')[0].ifMatch, '"2"');

    // Desde la ficha del equipo: formulario prellenado (una sola vez, ese equipo).
    const second = await browser.newPage(); second.on('pageerror', e => errors.push(e.message));
    await second.goto(`${base}/?equipo=${deviceId}`); await second.waitForFunction(() => window.ready);
    const pre = second.getByRole('dialog', { name: 'Nueva programación' });
    await pre.getByLabel(/^Frecuencia/).waitFor();
    assert.equal(await pre.getByLabel(/^Frecuencia/).inputValue(), 'once');
    assert.equal(await pre.getByLabel('Nombre', { exact: true }).inputValue(), 'Apagado de PC-01');
    await pre.getByText('Equipo: PC-01', { exact: true }).waitFor();
    assert.deepEqual(errors, []);
    console.log('PASS Chromium panel apagado programado: list, validation, 422 detail, POST body, pause with If-Match, runs, typed delete, ?equipo= prefill, tenant header.');
  } finally { await browser.close(); await new Promise(r => server.close(r)); }
})().catch(e => { console.error(e); server.close(); process.exitCode = 1; });
