const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const vm = require('node:vm');
if (!vm.SourceTextModule) {
  const run = require('node:child_process').spawnSync(process.execPath, ['--experimental-vm-modules', __filename], { stdio: 'inherit' });
  process.exit(run.status ?? 1);
}
const { JSDOM } = require(process.env.JSDOM_MODULE || 'C:/Users/FumiWork/proyectos/portal-azc/node_modules/jsdom');
const publicDir = path.resolve(__dirname, '../public');
const read = file => fs.readFileSync(path.join(publicDir, file), 'utf8');
async function modules(html = '<body><main id="content"></main></body>', url = 'https://keeper.test/supervisor.php', fetcher, transform = (_, source) => source) {
  const dom = new JSDOM(html, { url, runScripts: 'outside-only' });
  const w = dom.window, cache = new Map();
  w.fetch = fetcher || (() => { throw Error('Unexpected request'); });
  const mod = file => {
    if (!cache.has(file)) cache.set(file, new vm.SourceTextModule(transform(file, read(`assets/${file}`)), { context: dom.getInternalVMContext(), identifier: file }));
    return cache.get(file);
  };
  const get = async file => { const m = mod(file); if (m.status === 'unlinked') await m.link(s => mod(path.basename(s))); if (m.status !== 'evaluated') await m.evaluate(); return m.namespace; };
  return { w, get, close: () => w.close() };
}
async function test() {
  for (const file of ['supervisor.php', 'supervisor-persona.php']) assert.match(read(file), /layout-supervisor.php/);
  const layout = read('partials/layout-supervisor.php');
  assert.match(layout, /Content-Security-Policy/); assert.match(layout, /filemtime/);
  assert.match(layout, /k3.css/); assert.match(layout, /supervisor.js/);
  assert.doesNotMatch(layout, /head.php|assets\/app.js|data.js|role-select/);
  assert.match(read('index.php'), /'\/supervisor.php'/);
  assert.match(read('partials/sidebar.php'), /'supervisor', 'Supervisor', '\/supervisor.php'/);
  assert.match(read('partials/sidebar.php'), /'supervisor', 'Supervisor', '\/supervisor.php', false/);
  const app = read('assets/app.js');
  assert.equal((app.match(/location.replace\(await loginDestination\(/g) || []).length, 3);
  const c = await modules();
  const nav = await c.get('nav-access.js');
  assert.equal(nav.allowed({ permissions: [] }, 'supervisor'), false);
  assert.equal(nav.allowed({ permissions: [{ code: 'usuarios.ver', scopes: ['self'] }] }, 'supervisor'), true);
  assert.equal(await nav.loginDestination({ is_platform_admin: true }), '/supervisor.php');
  const v = await c.get('supervisor-view.js');
  const fixture = require('./supervisor-panel-fixture.cjs');
  const root = c.w.document.getElementById('content');
  v.renderOverview(root, fixture.overview(), new URLSearchParams('q=Ana'));
  assert.equal(root.querySelectorAll('tbody tr').length, 50);
  assert.match(root.textContent, /300 personas/);
  const links = [...root.querySelectorAll('tbody a')];
  assert.notEqual(links[0].href, links[1].href);
  assert.equal(root.querySelectorAll('img, script').length, 0);
  assert.match(links[0].href, /return/);
  v.renderOverview(root, { ...fixture.overview(), data: [], total: 0, next_cursor: null }, new URLSearchParams());
  assert.match(root.textContent, /No hay personas para estos filtros/); assert.equal(root.querySelectorAll('tbody tr').length, 0);
  assert.equal(v.returnPath('https://evil.test/'), '/supervisor.php');
  assert.equal(v.returnPath('/supervisor.php?q=Ana&role=admin'), '/supervisor.php?q=Ana');
  v.renderPerson(root, fixture.summary(), '/supervisor.php');
  assert.doesNotMatch(root.textContent, /Qué tiene bloqueado|Sin confirmar|protección/i);
  assert.equal(root.querySelector('#supervisor-rules'), null);
  assert.equal(root.querySelector('.supervisor-device').children.length, 2);
  assert.equal(root.querySelector('.supervisor-device.has-protection'), null);
  assert.match(root.textContent, /PC <script>/);
  assert.match(root.textContent, /historial completo de conexión/);
  v.renderPerson(root, fixture.summary(true), '/supervisor.php');
  assert.match(root.textContent, /Sin confirmar/);
  assert.ok(root.querySelector('.supervisor-device.has-protection'));
  assert.match(root.textContent, /actuales/);
  const noDevices = fixture.summary(true); noDevices.current.devices = { data: [], total: 0, next_cursor: null, availability: fixture.available };
  noDevices.current.protection = { state: 'unprotected', reasons: [{ code: 'no_devices', device_id: null, report_at: null, last_seen_at: null, control: null }] };
  v.renderPerson(root, noDevices, '/supervisor.php'); assert.match(root.textContent, /No protegido/); assert.match(root.textContent, /Sin equipos asignados/);
  delete noDevices.current.protection; noDevices.current.devices.availability = { status: 'not_authorized' }; noDevices.current.devices.total = null;
  v.renderPerson(root, noDevices, '/supervisor.php'); assert.doesNotMatch(root.textContent, /No protegido/); assert.match(root.textContent, /Sin acceso/);
  assert.match(v.rulesSummary({ entries: [{ kind: 'web', effect: 'deny' }], excluded: [{ kind: 'usb' }], devices: [], conflict: null }), /web/);
  assert.doesNotMatch(v.rulesSummary({ entries: [], excluded: [{ kind: 'usb' }], devices: [], conflict: null }), /USB/);
  assert.match(v.rulesSummary({ entries: [{ kind: 'web', effect: 'deny' }], conflict: {} }), /conflicto/);
  assert.match(v.rulesSummary({ entries: [{ kind: 'web', effect: 'allow' }], devices: [], conflict: null }), /1 excepciones/);
  const withEvidence = fixture.summary(true);
  withEvidence.alerts.data = [{ kind: 'possible_dual_job', severity: 'low', reviewed: true, evidence: { explanation: 'No confirma doble empleo', process_name: '<script>hostil</script>', seconds: 90, first_at: null, last_at: null } }];
  withEvidence.schedule.absences = [{ start_at: '2026-10-29T15:00:00Z', end_at: '2026-10-29T16:00:00Z' }];
  v.renderPerson(root, withEvidence, '/supervisor.php');
  assert.match(root.textContent, /No confirma doble empleo/); assert.match(root.textContent, /Ausencia aprobada/); assert.equal(root.querySelector('script'), null);
  const controller = await c.get('supervisor.js');
  const calls = [];
  const requests = async (method, route, options) => {
    calls.push({ method, route, options });
    if (route.startsWith('/reports/supervisor')) return fixture.overview();
    if (route.endsWith('/rules')) return { entries: [], devices: [], conflict: null };
    if (route.includes('/windows?')) return { data: [] };
    if (route.includes('/timeline?')) return { data: [], next_offset: null };
    return fixture.summary();
  };
  const list = controller.overviewController(root, requests);
  await list.reload();
  assert.equal(calls.length, 1); assert.equal(calls[0].options.tenant, null);
  root.querySelector('[data-action=next]').click(); await tick();
  assert.match(calls.at(-1).route, /cursor=page-two/);
  root.querySelector('[data-action=previous]').click(); await tick();
  assert.doesNotMatch(calls.at(-1).route, /cursor=/);
  root.querySelector('[data-action=clear]').click(); await tick();
  assert.equal(calls.at(-1).route, '/reports/supervisor?limit=50');
  list.dispose();
  let resolveOld;
  const raced = controller.overviewController(root, (_, route) => route.includes('q=old') ? new Promise(resolve => { resolveOld = resolve; }) : Promise.resolve({ ...fixture.overview(), total: 1, data: [fixture.person(8)] }));
  const old = raced.reload(new URLSearchParams('q=old'));
  await raced.reload(new URLSearchParams('q=new')); resolveOld(fixture.overview()); await old;
  assert.match(root.textContent, /1 personas/); assert.doesNotMatch(root.textContent, /300 personas/); raced.dispose();
  let attempts = 0;
  const stale = controller.overviewController(root, async () => { if (++attempts === 2) throw { status: 422, code: 'supervisor_cursor_stale' }; return fixture.overview(); });
  await stale.reload(); root.querySelector('[data-action=next]').click(); await tick();
  assert.equal(attempts, 3); stale.dispose();
  for (const status of [403, 503]) {
    const failed = controller.overviewController(root, async () => { throw { status }; }); await failed.reload();
    assert.equal(root.querySelector('tbody'), null); assert.match(root.textContent, /—/); assert.doesNotMatch(root.textContent, /0 personas/); failed.dispose();
  }
  calls.length = 0;
  const person = controller.personController(root, { request: requests, tenant: fixture.tenant, id: fixture.uid, timezone: 'America/Bogota', day: '2026-10-29', back: '/supervisor.php?q=Ana' });
  await person.reload();
  assert.equal(calls.length, 3); assert.ok(calls.every(x => x.method === 'GET' && x.options.tenant === fixture.tenant));
  assert.ok(!calls.some(x => x.route.endsWith('/rules')));
  await person.reload('2026-10-28'); assert.ok(calls.slice(-3).every(x => x.route.includes('2026-10-28')));
  const before = calls.length; await person.reload('2099-01-01'); assert.equal(calls.length, before);
  person.dispose();
  let rawCalls = 0;
  const restricted = controller.personController(root, { request: async () => { rawCalls++; return { ...fixture.summary(), raw_detail_available: false }; }, tenant: fixture.tenant, id: fixture.uid, timezone: 'America/Bogota', day: '2026-10-29' });
  await restricted.reload(); assert.equal(rawCalls, 1); assert.match(root.textContent, /Detalle no disponible/); restricted.dispose();
  const denied = controller.personController(root, { request: async () => { throw { status: 404 }; }, tenant: fixture.tenant, id: fixture.uid, timezone: 'America/Bogota', day: '2026-10-29' });
  await denied.reload(); assert.equal(root.querySelector('#supervisor-devices'), null); denied.dispose();
  let finishOld;
  const dateRace = controller.personController(root, { tenant: fixture.tenant, id: fixture.uid, timezone: 'America/Bogota', day: '2026-10-29', request: async (_, route) => {
    if (route.includes('supervisor-summary')) return { ...fixture.summary(), day: new URL('https://test' + route).searchParams.get('day') };
    if (route.includes('/windows') && route.includes('from=2026-10-29')) return new Promise(resolve => { finishOld = resolve; });
    if (route.includes('/windows')) return { data: [{ process_name: 'Usos de ayer', active_seconds: 60 }] };
    return { data: [], next_offset: null };
  } });
  const pending = dateRace.reload(); await tick(); await dateRace.reload('2026-10-28');
  finishOld({ data: [{ process_name: 'Resultado viejo', active_seconds: 99 }] }); await pending;
  assert.match(root.textContent, /Usos de ayer/); assert.doesNotMatch(root.textContent, /Resultado viejo/); dateRace.dispose();
  let ruleCalls = 0;
  const paged = controller.personController(root, { tenant: fixture.tenant, id: fixture.uid, timezone: 'America/Bogota', day: '2026-10-29', request: async (_, route) => {
    if (route.includes('supervisor-summary')) { const dto = fixture.summary(true); dto.raw_detail_available = false;
      if (route.includes('devices_cursor=')) { dto.current.devices.data[0].hostname = 'Equipo dos'; dto.current.devices.next_cursor = null; }
      return dto;
    }
    ruleCalls++; return { entries: [], devices: [], conflict: null };
  } });
  await paged.reload(); assert.equal(ruleCalls, 1); root.querySelector('[data-action=devices-more]').click(); await tick();
  assert.match(root.textContent, /Equipo dos/); assert.match(root.textContent, /PC <script>/); assert.match(root.textContent, /2 de 2 equipos/); paged.dispose();
  let restrictedRuleCalls = 0;
  const noRules = controller.personController(root, { tenant: fixture.tenant, id: fixture.uid, timezone: 'America/Bogota', day: '2026-10-29', request: async () => {
    restrictedRuleCalls++; const dto = fixture.summary(true); dto.sections.rules = { status: 'not_authorized' }; dto.raw_detail_available = false; return dto;
  } });
  await noRules.reload(); assert.equal(restrictedRuleCalls, 1); assert.match(root.querySelector('#supervisor-rules').textContent, /Sin acceso/); noRules.dispose();
  c.close();
  const transportCalls = [];
  const transport = await modules(undefined, undefined, async (route, options) => {
    transportCalls.push({ route, options });
    return new Response(JSON.stringify(route === '/v1/me/access' ? { platform: false, supervisor_home: options.headers['X-Tenant-ID'] === fixture.tenant, permissions: [{ code: 'usuarios.ver', scopes: ['self'] }] } : fixture.overview()), { headers: { 'Content-Type': 'application/json' } });
  });
  const api = await transport.get('api.js');
  await api.api('GET', '/reports/supervisor?limit=50', { tenant: null });
  assert.equal(transportCalls[0].options.headers['X-Tenant-ID'], undefined);
  await assert.rejects(() => api.api('GET', '/users', { tenant: null }), /empresa válida/);
  await assert.rejects(() => api.api('POST', '/reports/supervisor', { tenant: null }), /empresa válida/);
  const access = await transport.get('nav-access.js');
  assert.equal(await access.loginDestination({ tenant_id: fixture.tenant, is_platform_admin: false }), '/supervisor.php');
  assert.equal(await access.loginDestination({ tenant_id: fixture.other, is_platform_admin: false }), '/');
  api.setTenant(fixture.other); assert.equal((await access.loadAccess()).supervisor_home, false);
  await access.loadAccess(fixture.tenant); assert.equal(transportCalls.filter(c => c.route === '/v1/me/access').length, 2);
  transport.close();
  for (const entry of ['manager', 'staff', 'platform', 'manager-no-permission']) for (const mode of ['existing', 'new', 'password']) {
    const html = '<body data-page="login" data-surface="public"><section><h2 id="form-title"></h2><form id="login-form"><input name="email"><input name="password"><p id="login-error" hidden></p><button id="login-submit"></button><p id="login-status"></p></form></section></body>';
    const session = { tenant_id: fixture.tenant, is_platform_admin: entry === 'platform', expires_at: '2099-01-01T00:00:00Z', csrf_token: 'test' };
    const screen = await modules(html, 'https://keeper.test/login.php', async route => {
      const json = route === '/v1/auth/csrf' ? { csrf_token: 'test' } : route === '/v1/me/access' ? { platform: session.is_platform_admin, supervisor_home: entry !== 'staff', permissions: entry === 'manager-no-permission' ? [] : [{ code: 'usuarios.ver', scopes: ['tenant'] }] } : { ...session, must_change_password: mode === 'password' && route.endsWith('/login') };
      return new Response(JSON.stringify(json), { headers: { 'Content-Type': 'application/json' } });
    }, (file, source) => file === 'app.js' ? source.replaceAll('location.replace(', 'window.recordNavigation(') : source);
    const destinations = []; screen.w.recordNavigation = path => destinations.push(path);
    if (mode === 'existing') screen.w.sessionStorage.setItem('keeper.presentation.session', JSON.stringify(session));
    await screen.get('app.js'); await tick();
    if (mode !== 'existing') {
      const form = screen.w.document.getElementById('login-form'); form.elements.email.value = 'test@example.test'; form.elements.password.value = 'temporary password';
      form.dispatchEvent(new screen.w.Event('submit', { cancelable: true })); await tick();
      if (mode === 'password') {
        const next = screen.w.document.querySelector('input[name=next]'); assert.ok(next); next.value = 'new password 1234'; next.form.elements.again.value = next.value;
        next.form.dispatchEvent(new screen.w.Event('submit', { cancelable: true })); await tick();
      }
    }
    assert.deepEqual(destinations, [entry === 'staff' ? '/' : '/supervisor.php'], `real login path ${entry}/${mode}`); screen.close();
  }
  console.log('SUPERVISOR PANEL PASS');
}
const tick = () => new Promise(resolve => setTimeout(resolve, 30));
test().catch(e => { console.error(e); process.exitCode = 1; });
