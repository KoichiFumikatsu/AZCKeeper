const { chromium } = require(process.env.KEEPER_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { handler, tenant, uid, device, person, unknown, writes } = require('./k3-panel-fixture.cjs').createFixture();
const destination = path.resolve(__dirname, '../../docs/design/2026-10-13-capturas-arquitectura');

(async () => {
  fs.mkdirSync(destination, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1366, height: 1000 } });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.addInitScript(({ tenant }) => {
    sessionStorage.setItem('keeper.presentation.session', JSON.stringify({ tenant_id: tenant, is_platform_admin: true, expires_at: '2099-01-01T00:00:00Z' }));
    sessionStorage.setItem('keeper.presentation.tenant', tenant);
    sessionStorage.setItem('keeper.admin.csrf', 'fixture');
  }, { tenant });
  await page.route('**/v1/**', handler);

  try {
    const screens = (process.env.KEEPER_K3_SCREENS || 'inicio,personas,ficha,ventanas,linea,controles,editar,horarios,administradores,salud,productividad,equipos,equipo,versiones,doble,cobertura').split(',');
    for (const screen of screens) {
      const routes = { inicio: '/', personas: '/usuarios.php', ficha: `/miembro.php?id=${uid}&tab=resumen`, productividad: '/reportes.php', equipos: '/equipos.php', equipo: `/equipo.php?id=${device}`, versiones: '/admin/releases.php', doble: '/doble-empleo.php', cobertura: '/cobertura.php' };
      Object.assign(routes, { ventanas: `/miembro.php?id=${uid}&tab=ventanas`, linea: `/miembro.php?id=${uid}&tab=linea`, controles: `/miembro.php?id=${uid}&tab=controles`, editar: `/miembro.php?id=${uid}&tab=datos`, horarios: '/horarios.php', administradores: '/cuentas.php', salud: '/admin/salud.php' });
      await page.goto(`http://127.0.0.1:8780${routes[screen]}`);
      if (screen === 'inicio') { await page.getByRole('heading', { name: 'Personal activo ahora' }).waitFor(); await page.getByRole('heading', { name: 'Problemas reportados' }).waitFor(); }
      else {
        const headings = { personas: 'Tu personal activo', ficha: 'Resumen del mes', productividad: 'Ranking individual', equipos: 'Versiones instaladas', equipo: 'Actualizaciones', versiones: 'Publicar versión por anillo' };
        Object.assign(headings, { ventanas: 'Aplicaciones y sitios', linea: 'Línea de tiempo', controles: 'Historial de órdenes', editar: 'Datos de la persona', horarios: 'Margen de puntualidad y horario predeterminado', administradores: 'Acceso al Portal', salud: 'Estado del cálculo' });
        if (headings[screen]) await page.getByRole('heading', { name: headings[screen], exact: true }).waitFor();
        else await page.getByRole('cell', { name: person.display_name, exact: true }).waitFor();
      }
      // Menú por tareas (2026-10-15): la entrada marcada se llama igual que el título; Plataforma es el primer grupo.
      if (!['ficha', 'ventanas', 'linea', 'controles', 'editar', 'equipo'].includes(screen)) {
        const h1 = (await page.locator('main h1').first().textContent()).trim();
        assert.deepEqual(await page.locator('#navigation a[aria-current="page"] .nav-label').allTextContents(), [h1], `${screen}: menú = título`);
      }
      assert.equal(await page.locator('#navigation > details.nav-group').first().getAttribute('id'), 'platform-group', `${screen}: Plataforma antes del bloque del cliente`);
      assert.ok(await page.locator('#platform-group').isVisible(), `${screen}: Plataforma visible para superadmin`);
      if (screen === 'ventanas') await page.getByLabel('Ordenar ventanas').selectOption('sessions');
      if (screen === 'linea') await page.locator('#panel-linea tbody tr').first().waitFor();
      if (screen === 'controles') {
        await page.getByText('No hay órdenes registradas.', { exact: true }).waitFor();
        assert.equal(await page.locator('#panel-controles').getByText(/No hay datos para este período/).count(), 0);
      }
      if (['ficha', 'ventanas', 'linea', 'controles', 'editar'].includes(screen)) {
        assert.equal(await page.getByRole('tablist').count(), 1);
        assert.deepEqual(await page.getByRole('tab').allTextContents(), ['Resumen', 'Ventanas', 'Línea de tiempo', 'Equipo y controles', 'Presencia', 'Reglas', 'Datos y acceso']);
        const tops = await page.getByRole('tab').evaluateAll(tabs => tabs.map(t => Math.round(t.getBoundingClientRect().top)));
        assert.equal(new Set(tops).size, 1, 'Una sola fila de pestañas a 1366 px');
        assert.equal(await page.getByLabel('Período', { exact: true }).count(), ['ficha', 'ventanas'].includes(screen) ? 1 : 0);
      }
      if (screen === 'inicio') {
        assert.equal(await page.locator('.coordinator .kpi-grid').count(), 1);
        assert.deepEqual(await page.locator('.coordinator .kpi-grid dt').allTextContents(), ['Activos / total', 'Sedes activas', 'Productividad en tiempo real', 'Focus en tiempo real', 'Llegadas', 'Horas de hoy', 'Primer ingreso']);
      }
      if (screen === 'personas') {
        await page.getByRole('columnheader', { name: 'Primer ingreso', exact: true }).waitFor();
        assert.deepEqual((await page.locator('.people-list thead th').allTextContents()).filter(Boolean), ['Nombre', 'Acciones', 'Conexión', 'Primer ingreso', 'Última vez en uso', 'Tiempo trabajado', 'Productividad', 'Focus /100']);
        assert.ok(await page.locator('.people-list .table-wrap').evaluate(e => e.scrollWidth <= e.clientWidth + 1), 'Personas sin desplazamiento horizontal a 1366 px');
        for (const action of ['Ver', 'Editar']) {
          const box = await page.locator('.people-list tbody').getByRole('link', { name: action, exact: true }).first().boundingBox();
          assert.ok(box && box.x >= 0 && box.x + box.width <= 1366, `${action} visible a 1366 px`);
        }
      }
      if (screen === 'equipo') {
        const control = page.locator('th span[title="WebEnforcer"]'); await control.waitFor();
        assert.equal(await control.textContent(), 'Bloqueo web', 'el control se muestra con nombre legible y el técnico queda como tooltip');
        assert.equal(await page.getByRole('rowheader', { name: 'WebEnforcer', exact: true }).count(), 0);
      }
      if (screen === 'productividad') { await page.getByRole('heading', { name: 'Tendencia de 8 semanas' }).waitFor(); await page.getByLabel('Ordenar por').selectOption('area'); }
      if (screen === 'equipos') {
        await page.getByLabel('Seleccionar AZC-ANA', { exact: true }).check();
        const bulk = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Actualizar equipos seleccionados', exact: true }) }).last();
        await bulk.getByRole('combobox', { name: /^Actualizaciones/ }).selectOption('pausado'); await bulk.getByLabel('Motivo de la pausa').fill('Validación de compatibilidad');
        await bulk.getByRole('button', { name: 'Aplicar a seleccionados' }).click(); await page.getByText('1 de 1 equipos actualizados.', { exact: true }).waitFor();
        await page.getByRole('alert').filter({ hasText: 'AZC-ANA' }).waitFor();
      }
      if (screen === 'versiones') {
        await page.getByRole('button', { name: 'Publicar para este anillo' }).click(); await page.getByText(/Publicado:.*pruebas/).waitFor();
        await page.getByLabel('Publicar para').selectOption('stable'); await page.getByRole('button', { name: 'Publicar para este anillo' }).click(); await page.getByText(/Publicado:.*Automáticas/).waitFor();
      }
      if (screen === 'doble') { await page.getByRole('button', { name: 'Marcar revisada' }).click(); await page.getByRole('cell', { name: 'Revisada', exact: true }).first().waitFor(); }
      await page.screenshot({ path: path.join(destination, `${screen}.png`), fullPage: true });
    }
    if (screens.includes('versiones')) assert.deepEqual(writes.filter(w => w.path === '/release-deployments').map(w => w.body.ring), ['pruebas', 'stable']);
    assert.deepEqual(errors, []); console.log('PASS K3 panel:', screens.join(', ')); console.log('Optional denied routes:', [...new Set(unknown)].join(', '));
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
