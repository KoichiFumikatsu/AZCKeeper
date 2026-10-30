const { chromium } = require(process.env.KEEPER_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { spawn } = require('node:child_process');
const fixture = require('./supervisor-panel-fixture.cjs');
const legacy = require('./k3-panel-fixture.cjs').createFixture();
const origin = 'http://127.0.0.1:18086';
const publicDir = path.resolve(__dirname, '../public');
const shots = path.resolve(__dirname, '../.validation/supervisor-browser');
const session = { tenant_id: fixture.tenant, is_platform_admin: false, expires_at: '2099-01-01T00:00:00Z', csrf_token: 'fixture', must_change_password: false };
const dayIn = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(new Date());
async function run() {
  const browser = await chromium.launch({ headless: true });
  const server = spawn('php', ['-S', '127.0.0.1:18086', '-t', publicDir, path.join(publicDir, 'index.php')], { windowsHide: true, stdio: 'ignore', env: { ...process.env, KEEPER_DB_DSN: 'mysql:host=127.0.0.1;port=9;dbname=supervisor_browser_unused', KEEPER_DB_USER: 'unused', KEEPER_DB_PASSWORD: '', KEEPER_DB_PASSWORD_FILE: '', KEEPER_ORIGIN: origin, KEEPER_ALLOW_HTTP_LOCAL: '1' } });
  let role = 'custom', manages = true, temporary = false, restricted = false, error = null;
  const calls = [], errors = [];
  try {
    for (let i = 0; i < 100; i++) { try { if ((await fetch(origin + '/login.php')).ok) break; } catch {} await new Promise(r => setTimeout(r, 50)); }
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message));
    await page.route('**/*', async route => {
      const request = route.request(), u = new URL(request.url());
      if (u.origin !== origin) return route.abort();
      if (!u.pathname.startsWith('/v1/')) return route.continue();
      calls.push({ path: u.pathname, query: u.search, method: request.method(), tenant: request.headers()['x-tenant-id'] });
      let json;
      const admin = role === 'platform';
      if (u.pathname.startsWith('/v1/auth/')) json = u.pathname.endsWith('/csrf') ? { csrf_token: 'fixture' } : { ...session, is_platform_admin: admin, must_change_password: u.pathname.endsWith('/login') && temporary };
      else if (u.pathname === '/v1/me/access') json = { platform: admin, supervisor_home: admin || manages, features: [], permissions: [{ code: 'usuarios.ver', scopes: ['tenant'] }] };
      else if (u.pathname === '/v1/me') json = { platform: admin, tenant: { timezone: 'America/Bogota' } };
      else if (u.pathname === '/v1/reports/supervisor') {
        if (error) return route.fulfill({ status: error, json: { code: error === 403 ? 'permission_denied' : 'unavailable' } });
        json = fixture.overview(); json.capabilities.admin_href = admin ? '/admin/' : null;
        if (u.searchParams.get('q')) { json.data = [fixture.person(118)]; json.total = 1; json.next_cursor = null; }
        if (u.searchParams.has('cursor')) { json.data = [fixture.person(99)]; json.next_cursor = null; }
      } else if (u.pathname.endsWith('/supervisor-summary')) {
        json = fixture.summary(admin || role === 'it'); json.day = u.searchParams.get('day'); json.raw_detail_available = !restricted;
        if (u.searchParams.has('devices_cursor')) { json.current.devices.data[0].hostname = 'Segundo equipo'; json.current.devices.next_cursor = null; }
      } else if (u.pathname.endsWith('/windows')) json = { data: Array.from({ length: 12 }, (_, i) => ({ process_name: `App ${i}`, site: null, active_seconds: 60 })) };
      else if (u.pathname.endsWith('/timeline')) json = { data: [{ process_name: 'App de trabajo', site: null, started_at: '2026-10-29T14:00:00Z', ended_at: '2026-10-29T15:00:00Z' }], next_offset: u.searchParams.get('offset') === '0' ? 200 : null };
      else if (u.pathname.endsWith('/rules')) json = { entries: [{ kind: 'web', effect: 'deny' }], devices: [], conflict: null };
      else return legacy.handler(route);
      return route.fulfill({ json });
    });
    await page.goto(origin + '/login.php');
    await page.getByLabel('Correo electrónico').fill('local@example.test'); await page.getByLabel('Contraseña', { exact: true }).fill('Local fixture password');
    await page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click(); await page.waitForURL('**/supervisor.php');
    await page.getByRole('heading', { name: 'Personas hoy' }).waitFor();
    assert.equal(await page.locator('tbody img, tbody script').count(), 0);
    assert.equal(calls.find(x => x.path === '/v1/reports/supervisor').tenant, undefined);
    await page.goto(origin + '/login.php'); await page.waitForURL('**/supervisor.php');
    await page.getByLabel('Buscar una persona').fill('Persona 118'); await page.getByRole('button', { name: 'Buscar persona' }).click();
    await page.getByRole('link', { name: 'Persona 118' }).click();
    await page.getByRole('heading', { name: 'Sus equipos' }).waitFor();
    assert.equal(await page.getByRole('heading', { name: 'Qué tiene bloqueado' }).count(), 0);
    assert.equal(await page.locator('.supervisor-device.has-protection').count(), 0);
    assert.equal(await page.locator('.supervisor-device').first().evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length), 2);
    await page.getByRole('button', { name: 'Ayer', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('#page-status').textContent.startsWith('Información del'));
    const yesterday = new Date(dayIn() + 'T12:00:00Z'); yesterday.setUTCDate(yesterday.getUTCDate() - 1);
    const expectedDay = yesterday.toISOString().slice(0, 10);
    await page.waitForFunction(d => document.querySelector('input[name=day]')?.value === d, expectedDay);
    assert.ok(calls.some(x => x.path.endsWith('/windows') && x.query.includes('from=' + expectedDay)));
    assert.ok(calls.some(x => x.path.endsWith('/timeline') && x.query.includes('day=' + expectedDay)));
    await page.getByRole('button', { name: 'Cargar más eventos' }).click();
    await page.getByRole('button', { name: 'Cargar más equipos' }).click(); await page.getByText('Segundo equipo', { exact: true }).waitFor();
    assert.ok(!calls.some(x => x.path.endsWith('/rules')));
    await page.getByRole('link', { name: 'Volver a personas' }).click(); assert.ok(page.url().includes('q=Persona+118'));
    await page.getByRole('button', { name: 'Limpiar filtros' }).click();
    fs.mkdirSync(shots, { recursive: true });
    for (const width of [1366, 375]) {
      await page.setViewportSize({ width, height: 768 }); await page.getByRole('heading', { name: 'Personas hoy' }).waitFor();
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      await page.keyboard.press('Tab'); assert.ok(await page.evaluate(() => document.activeElement !== document.body));
      await page.screenshot({ path: path.join(shots, `lista-${width}.png`), fullPage: true });
    }
    restricted = true; const before = calls.length;
    await page.goto(origin + `/supervisor-persona.php?tenant_id=${fixture.tenant}&id=${fixture.uid}&day=${expectedDay}`);
    await page.getByText('Detalle no disponible para este alcance o período conservado.').first().waitFor();
    assert.ok(!calls.slice(before).some(x => /\/(windows|timeline)$/.test(x.path)));
    restricted = false; role = 'it';
    await page.reload(); await page.getByRole('heading', { name: 'Qué tiene bloqueado' }).waitFor();
    await page.getByText('Reglas configuradas para web', { exact: false }).waitFor();
    await page.screenshot({ path: path.join(shots, 'ficha-it-375.png'), fullPage: true });
    for (const status of [403, 503]) { error = status; await page.goto(origin + '/supervisor.php'); await page.getByRole('button', { name: 'Reintentar', exact: true }).waitFor(); assert.equal(await page.locator('tbody').count(), 0); }
    error = null;
    await page.setViewportSize({ width: 1366, height: 768 }); // el menú lateral del panel actual queda oculto a 375 px
    for (const [nextRole, hasReports, expected] of [['it', false, '/'], ['coordinacion', false, '/'], ['rrhh', true, '/supervisor.php'], ['it', true, '/supervisor.php'], ['platform', false, '/supervisor.php']]) {
      role = nextRole; manages = hasReports; await page.evaluate(() => sessionStorage.clear()); await page.goto(origin + '/login.php');
      await page.getByLabel('Correo electrónico').fill('local@example.test'); await page.getByLabel('Contraseña', { exact: true }).fill('Local fixture password');
      await page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click(); await page.waitForURL(origin + expected);
      if (expected === '/') {
        await page.getByRole('link', { name: 'Supervisor', exact: true }).click();
        await page.waitForURL('**/supervisor.php');
      }
      if (nextRole === 'platform') {
        await page.getByRole('link', { name: 'Administrar', exact: true }).click();
        await page.waitForURL(origin + '/admin/');
      }
    }
    role = 'custom'; manages = true; temporary = true; await page.evaluate(() => sessionStorage.clear()); await page.goto(origin + '/login.php');
    await page.getByLabel('Correo electrónico').fill('local@example.test'); await page.getByLabel('Contraseña', { exact: true }).fill('Temporary fixture password');
    await page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click();
    await page.getByLabel('Contraseña nueva', { exact: true }).fill('New fixture password 123!'); await page.getByLabel('Repite la contraseña nueva', { exact: true }).fill('New fixture password 123!');
    await page.getByRole('button', { name: 'Guardar y entrar', exact: true }).click(); await page.waitForURL('**/supervisor.php');
    assert.ok(calls.every(x => x.method === 'GET' || x.path.startsWith('/v1/auth/')));
    assert.deepEqual(errors, []);
    console.log('SUPERVISOR BROWSER PASS: login, filters, dates, scopes, pagination, XSS, 1366/375 and keyboard');
  } finally { server.kill(); await browser.close(); }
}
run().catch(e => { console.error(e); process.exitCode = 1; });
