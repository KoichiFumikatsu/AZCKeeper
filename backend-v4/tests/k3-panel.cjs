const { chromium } = require(process.env.KEEPER_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { handler, tenant, uid, device, person, unknown, writes } = require('./k3-panel-fixture.cjs').createFixture();
const destination = path.resolve(__dirname, '../../docs/design/2026-10-13-capturas-k3');

(async () => {
  fs.mkdirSync(destination, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.addInitScript(({ tenant }) => {
    sessionStorage.setItem('keeper.presentation.session', JSON.stringify({ tenant_id: tenant, is_platform_admin: true, expires_at: '2099-01-01T00:00:00Z' }));
    sessionStorage.setItem('keeper.presentation.tenant', tenant);
    sessionStorage.setItem('keeper.admin.csrf', 'fixture');
  }, { tenant });
  await page.route('**/v1/**', handler);

  try {
    const screens = (process.env.KEEPER_K3_SCREENS || 'inicio,personas,ficha,productividad,equipos,equipo,versiones,doble,cobertura').split(',');
    for (const screen of screens) {
      const routes = { inicio: '/', personas: '/usuarios.php', ficha: `/miembro.php?id=${uid}&tab=actividad`, productividad: '/reportes.php', equipos: '/equipos.php', equipo: `/equipo.php?id=${device}`, versiones: '/admin/releases.php', doble: '/doble-empleo.php', cobertura: '/cobertura.php' };
      await page.goto(`http://127.0.0.1:8780${routes[screen]}`);
      if (screen === 'inicio') { await page.getByRole('heading', { name: 'Personal activo ahora' }).waitFor(); await page.getByRole('heading', { name: 'Aplicaciones más usadas' }).waitFor(); assert.equal(await page.getByText('AZC-ANA', { exact: true }).count(), 1); }
      else {
        const headings = { personas: 'Tu personal activo', ficha: 'Resumen del mes', productividad: 'Ranking individual', equipos: 'Versiones instaladas', equipo: 'Actualizaciones', versiones: 'Publicar versión por anillo' };
        if (headings[screen]) await page.getByRole('heading', { name: headings[screen], exact: true }).waitFor();
        else await page.getByRole('cell', { name: person.display_name, exact: true }).waitFor();
      }
      if (screen === 'personas') { await page.getByRole('columnheader', { name: 'Primer ingreso hoy' }).waitFor(); await page.getByRole('columnheader', { name: 'Focus /100' }).waitFor(); }
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
