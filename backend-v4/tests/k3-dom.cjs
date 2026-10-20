const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { JSDOM, VirtualConsole } = require(process.env.JSDOM_MODULE || 'C:/Users/FumiWork/proyectos/portal-azc/node_modules/jsdom');
const { createFixture } = require('./k3-panel-fixture.cjs');
const fixture = createFixture();
const root = path.resolve(__dirname, '../public/assets');
async function open(route, entry) {
  const url = `${process.env.KEEPER_PANEL_ORIGIN || 'http://127.0.0.1:8780'}${route}`, response = await fetch(url); assert.equal(response.status, 200, route);
  const errors = [], vc = new VirtualConsole(); vc.on('jsdomError', e => { if (!e.message.includes('navigation')) errors.push(e.message); });
  const dom = new JSDOM(await response.text(), { url, runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: vc }); const w = dom.window;
  w.sessionStorage.setItem('keeper.presentation.session', JSON.stringify({ tenant_id: fixture.tenant, is_platform_admin: true, expires_at: '2099-01-01T00:00:00Z' }));
  w.sessionStorage.setItem('keeper.presentation.tenant', fixture.tenant); w.sessionStorage.setItem('keeper.admin.csrf', 'fixture');
  w.HTMLElement.prototype.scrollIntoView = function () {};
  w.HTMLDialogElement.prototype.showModal = function () { this.open = true; }; w.HTMLDialogElement.prototype.close = function () { this.open = false; };
  const blobs = []; w.URL.createObjectURL = blob => { blobs.push(blob); return 'blob:fixture'; }; w.URL.revokeObjectURL = () => {};
  w.HTMLAnchorElement.prototype.click = function () {};
  w.fetch = async (url, options = {}) => {
    let result;
    await fixture.handler({ request: () => ({ url: () => new URL(url, w.location.href).href, method: () => options.method || 'GET', headers: () => Object.fromEntries(Object.entries(options.headers || {}).map(([k, v]) => [k.toLowerCase(), v])), postDataJSON: () => JSON.parse(options.body) }), fulfill: async response => { result = response; } });
    const status = result.status || 200; return new Response(status === 204 ? null : JSON.stringify(result.json), { status, headers: { 'Content-Type': 'application/json' } });
  };
  const modules = new Map(), context = dom.getInternalVMContext();
  function module(file) {
    if (!modules.has(file)) modules.set(file, new vm.SourceTextModule(fs.readFileSync(file, 'utf8'), { context, identifier: file }));
    return modules.get(file);
  }
  const main = module(path.join(root, entry));
  await main.link((specifier, parent) => module(path.resolve(path.dirname(parent.identifier), specifier)));
  await main.evaluate();
  const text = () => w.document.querySelector('#content, #page-content')?.textContent || '';
  const wait = async test => { for (let i = 0; i < 100; i++) { if (test()) return; await new Promise(resolve => setTimeout(resolve, 20)); } throw new Error(`DOM timeout ${route}: ${w.document.querySelector('#page-error')?.textContent} / ${text().slice(0, 500)}`); };
  return { w, text, wait, errors, blobs, close: () => dom.window.close() };
}
(async () => {
  const screens = [
    ['/', 'app.js', 'Personal activo ahora'], ['/usuarios.php', 'k3.js', 'Tu personal activo'],
    [`/miembro.php?id=${fixture.uid}`, 'k3.js', 'Resumen del mes'], ['/reportes.php', 'app.js', 'Ranking individual'],
    ['/equipos.php', 'app.js', 'Versiones instaladas'], [`/equipo.php?id=${fixture.device}`, 'app.js', 'Actualizaciones'],
    ['/admin/releases.php', 'app.js', 'Publicar versión por anillo'], ['/doble-empleo.php', 'app.js', 'Marcar revisada'], ['/cobertura.php', 'app.js', 'Ana Torres'],
    ['/horarios.php', 'k3.js', 'Margen de puntualidad'], ['/cuentas.php', 'k3.js', 'Acceso al Portal'], ['/admin/salud.php', 'k3.js', 'Fallo sintético del cálculo'], ['/admin/salud.php', 'k3.js', 'Fallo sintético de trabajo programado'],
    [`/miembro.php?id=${fixture.uid}&tab=datos`, 'k3.js', 'Estado laboral'],
  ];
  for (const [route, entry, expected] of screens) {
    const c = await open(route, entry);
    try {
      await c.wait(() => c.text().includes(expected));
      assert.deepEqual(c.errors, [], route);
      assert.ok(!c.text().includes('undefined'), route + ': ' + c.text());
      if (route === '/') {
        const blocks = c.w.document.querySelectorAll('.coordinator .kpi-grid'); assert.equal(blocks.length, 1);
        assert.deepEqual([...blocks[0].querySelectorAll('dt')].map(n => n.textContent), ['Activos / total', 'Sedes activas', 'Productividad en tiempo real', 'Focus en tiempo real', 'Llegadas', 'Horas de hoy', 'Primer ingreso']);
        assert.equal(blocks[0].nextElementSibling.querySelector('h2').textContent, 'Problemas reportados');
        const form = c.w.document.querySelector('.coordinator form'), dates = form.querySelectorAll('input[type=date]');
        const valid = dates[1].value; dates[0].value = '2020-01-01'; form.dispatchEvent(new c.w.Event('submit', { cancelable: true }));
        assert.ok(dates[1].validationMessage.includes('31 días'));
        dates[0].value = valid; dates[0].dispatchEvent(new c.w.Event('input')); assert.equal(dates[1].validationMessage, '');
      }
      if (route === '/usuarios.php') {
        const table = c.w.document.querySelector('.people-list table');
        assert.deepEqual([...table.querySelectorAll('thead th')].map(n => n.textContent).filter(Boolean), ['Nombre', 'Acciones', 'Conexión', 'Primer ingreso', 'Última vez en uso', 'Tiempo trabajado', 'Productividad', 'Focus']);
        const actions = table.querySelector('tbody tr').children[2]; assert.deepEqual([...actions.querySelectorAll('a')].map(a => a.textContent), ['Ver', 'Editar']);
      }
      if (route.startsWith('/miembro.php') && !route.includes('tab=datos')) {
        const tabs = c.w.document.querySelectorAll('[role=tablist]'); assert.equal(tabs.length, 1);
        assert.deepEqual([...tabs[0].children].map(b => b.textContent), ['Resumen', 'Ventanas', 'Línea de tiempo', 'Equipo y controles', 'Presencia', 'Reglas', 'Datos y acceso']);
        c.w.document.querySelector('#person-tab-ventanas').click();
        await c.wait(() => c.w.document.querySelector('#panel-ventanas tbody tr'));
        assert.ok(c.w.document.querySelector('#person-panel-ventanas [aria-label="Período"]'));
        const sort = c.w.document.querySelector('[aria-label="Ordenar ventanas"]'); sort.value = 'sessions'; sort.dispatchEvent(new c.w.Event('change'));
        assert.ok(c.w.document.querySelector('#panel-ventanas tbody tr').textContent.includes('chrome'));
        c.w.document.querySelector('#person-tab-linea').click(); await c.wait(() => c.w.document.querySelector('#panel-linea')?.textContent.includes('WINWORD'));
        assert.equal(c.w.document.querySelector('#person-panel-linea [aria-label="Período"]'), null);
        c.w.document.querySelector('#person-tab-controles').click();
        await c.wait(() => c.w.document.querySelector('#panel-controles .command-history')?.textContent.includes('No hay órdenes registradas.'));
        assert.equal(c.w.document.querySelector('#person-panel-controles [aria-label="Período"]'), null);
        assert.ok(!c.w.document.querySelector('#panel-controles').textContent.includes('No hay datos para este período'));
        c.w.document.querySelector('#panel-controles [data-command="wipe"]').click();
        const wipe = c.w.document.querySelector('dialog form'); const confirm = wipe.querySelector('[name="confirmation"]');
        assert.ok(wipe.querySelector('button[type=submit]').disabled); confirm.value = 'incorrecto'; confirm.dispatchEvent(new c.w.Event('input')); assert.ok(wipe.querySelector('button[type=submit]').disabled);
        confirm.value = 'AZC-ANA'; confirm.dispatchEvent(new c.w.Event('input')); assert.ok(!wipe.querySelector('button[type=submit]').disabled); c.w.document.querySelector('dialog').remove();
        c.w.document.querySelector('#panel-controles [data-command="lock"]').click(); c.w.document.querySelector('dialog form').dispatchEvent(new c.w.Event('submit', { cancelable: true }));
        await c.wait(() => fixture.writes.some(w => w.path.endsWith('/commands') && w.body.type === 'lock'));
        c.w.document.querySelector('#person-tab-presencia').click(); await c.wait(() => c.w.document.querySelector('#panel-presencia table'));
        assert.ok(c.w.document.querySelector('#person-panel-presencia [aria-label="Período"]'));
        c.w.document.querySelector('#person-tab-politicas').click(); await c.wait(() => c.w.document.querySelector('#panel-politicas'));
        assert.equal(c.w.document.querySelector('#person-panel-politicas [aria-label="Período"]'), null);
        c.w.document.querySelector('#person-tab-resumen').click();
        [...c.w.document.querySelectorAll('#panel-resumen button')].find(b => b.textContent === 'Exportar CSV').click();
        assert.equal(c.blobs.length, 1);
        const csv = await new Promise(resolve => { const reader = new c.w.FileReader(); reader.onload = () => resolve(reader.result); reader.readAsText(c.blobs[0]); });
        for (const field of ['Horario laboral', 'Primer ingreso', 'Último evento', 'Almuerzo', 'Fuera de horario']) assert.ok(csv.includes(field), field);
      }
      if (route.includes('tab=datos')) {
        assert.equal(c.w.document.querySelectorAll('[role=tablist]').length, 1);
        assert.ok(c.text().includes('Cuenta y acceso al panel'));
        assert.equal(c.w.document.querySelector('#person-panel-datos [aria-label="Período"]'), null);
        const form = c.w.document.querySelector('.person-form'), employment = [...form.querySelectorAll('select')].find(s => [...s.options].some(o => o.value === 'vacaciones'));
        employment.value = 'vacaciones'; form.dispatchEvent(new c.w.Event('submit', { cancelable: true }));
        await c.wait(() => fixture.writes.some(w => w.body.employment_status === 'vacaciones') && c.text().includes('Datos guardados'));
      }
      if (route === '/horarios.php') { const form = c.w.document.querySelector('#page-content form'); form.querySelector('input[type=number]').value = '20'; form.dispatchEvent(new c.w.Event('submit', { cancelable: true })); await c.wait(() => fixture.writes.some(w => w.path === '/schedules/settings' && w.body.punctuality_margin_minutes === 20) && !form.querySelector('button').disabled); }
      if (route === '/cuentas.php') { const toggle = c.w.document.querySelector('[role=switch]'); toggle.checked = true; toggle.dispatchEvent(new c.w.Event('change')); await c.wait(() => fixture.person.portal_login_enabled && !toggle.disabled); }
      if (route === '/equipos.php') {
        const box = c.w.document.querySelector('[aria-label="Seleccionar AZC-ANA"]'); box.checked = true; box.dispatchEvent(new c.w.Event('change'));
        const form = [...c.w.document.forms].find(f => f.textContent.includes('Aplicar a seleccionados'));
        const ring = form.querySelector('select'); ring.value = 'pausado'; ring.dispatchEvent(new c.w.Event('change'));
        form.querySelector('input').value = 'Prueba de compatibilidad'; form.dispatchEvent(new c.w.Event('submit', { cancelable: true }));
        await c.wait(() => c.text().includes('1 de 1 equipos actualizados.'));
        assert.ok(c.text().includes('No recibirán nuevas versiones.'));
      }
      if (route === '/reportes.php') { await c.wait(() => c.text().includes('Ranking por área actual')); assert.ok(c.text().includes('Tendencia de 8 semanas')); }
      if (route === '/admin/releases.php') {
        const form = [...c.w.document.forms].find(f => f.textContent.includes('Publicar para este anillo')); form.dispatchEvent(new c.w.Event('submit', { cancelable: true }));
        await c.wait(() => c.text().includes('Publicado:')); const ring = form.querySelectorAll('select')[2]; ring.value = 'stable'; form.dispatchEvent(new c.w.Event('submit', { cancelable: true }));
        await c.wait(() => fixture.writes.filter(w => w.path === '/release-deployments').length === 2);
      }
      if (route === '/doble-empleo.php') { [...c.w.document.querySelectorAll('button')].find(b => b.textContent === 'Marcar revisada').click(); await c.wait(() => !c.text().includes('Marcar revisada')); }
      console.log('PASS DOM', route);
    } finally { c.close(); }
  }
  assert.deepEqual(fixture.writes.filter(w => w.path === '/release-deployments').map(w => w.body.ring), ['pruebas', 'stable']);
  console.log('PASS panel DOM: 13 vistas, ventanas, cronología, controles, margen, Portal y estado laboral; sin validación visual.');
})().catch(e => { console.error(e); process.exitCode = 1; });
