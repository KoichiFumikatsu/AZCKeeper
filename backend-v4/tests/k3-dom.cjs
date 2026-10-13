const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { JSDOM, VirtualConsole } = require(process.env.JSDOM_MODULE || 'C:/Users/FumiWork/proyectos/portal-azc/node_modules/jsdom');
const { createFixture } = require('./k3-panel-fixture.cjs');
const fixture = createFixture();
const root = path.resolve(__dirname, '../public/assets');
async function open(route, entry) {
  const url = `http://127.0.0.1:8780${route}`, response = await fetch(url); assert.equal(response.status, 200, route);
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
  ];
  for (const [route, entry, expected] of screens) {
    const c = await open(route, entry);
    try {
      await c.wait(() => c.text().includes(expected));
      assert.deepEqual(c.errors, [], route);
      assert.ok(!c.text().includes('undefined'), route);
      if (route === '/') {
        const form = c.w.document.querySelector('.coordinator form'), dates = form.querySelectorAll('input[type=date]');
        const valid = dates[1].value; dates[0].value = '2020-01-01'; form.dispatchEvent(new c.w.Event('submit', { cancelable: true }));
        assert.ok(dates[1].validationMessage.includes('31 días'));
        dates[0].value = valid; dates[0].dispatchEvent(new c.w.Event('input')); assert.equal(dates[1].validationMessage, '');
      }
      if (route.startsWith('/miembro.php')) {
        c.w.document.querySelector('#tab-actividad').click();
        [...c.w.document.querySelectorAll('#panel-actividad button')].find(b => b.textContent === 'Exportar CSV').click();
        assert.equal(c.blobs.length, 1);
        const csv = await new Promise(resolve => { const reader = new c.w.FileReader(); reader.onload = () => resolve(reader.result); reader.readAsText(c.blobs[0]); });
        for (const field of ['Horario laboral', 'Primer ingreso', 'Último evento', 'Almuerzo', 'Fuera de horario']) assert.ok(csv.includes(field), field);
      }
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
  console.log('PASS K3 DOM: 9 pantallas, pausa y publicación por anillos; sin validación visual.');
})().catch(e => { console.error(e); process.exitCode = 1; });
