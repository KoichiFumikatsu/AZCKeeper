// Pantalla «Integraciones (API)» (assets/integrations.js) contra una API simulada: crear con reautenticación, secreto
// mostrado una sola vez con su ejemplo y la advertencia, y revocar escribiendo el nombre.
// node backend-v4/tests/integrations-panel.cjs (KEEPER_PLAYWRIGHT_MODULE apunta a Playwright si no está instalado aquí).
const { chromium } = require(process.env.KEEPER_PLAYWRIGHT_MODULE || 'playwright');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../public');
const tenant = '00000000-0000-4000-8000-000000000002';
const SECRET = 'S'.repeat(43);
const base = { tenant_id: tenant, auth_type: 'api_key', created_at: '2026-10-20T10:00:00.000000Z', expires_at: '2027-01-18T10:00:00.000000Z', revoked_at: null, last_used_at: null, created_by: 'koichi@example.test', active: true, status: 'active', credentials: [] };
let integrations = [{ ...base, id: '11111111-1111-4111-8111-111111111111', name: 'Existente', scopes: ['plans:read'], credential_id: 'kpk_existing' }];
let reauthed = false;
const calls = [];
const server = http.createServer(async (req, res) => {
  if (req.url.startsWith('/v1/')) {
    let body = ''; for await (const chunk of req) body += chunk;
    const input = body ? JSON.parse(body) : null;
    calls.push({ method: req.method, path: req.url, input });
    res.setHeader('Content-Type', 'application/json');
    if (req.url === '/v1/auth/reauth') {
      if (input.password !== 'fixture-password') { res.statusCode = 401; return res.end(JSON.stringify({ code: 'invalid_credentials' })); }
      reauthed = true; return res.end('{}');
    }
    assert.equal(req.headers['x-tenant-id'], tenant);
    if (req.method === 'GET' && req.url === '/v1/integrations') {
      return res.end(JSON.stringify({ data: integrations, available_scopes: ['organization:read', 'plans:read', 'team:read', 'activity:read', 'activity-titles:read'], expiry_days: { api_key: [30, 90], oauth2: [30, 90, 180, 365] }, token_url: 'https://keeper.example/v1/oauth/token' }));
    }
    if (req.method === 'POST' && req.url === '/v1/integrations') {
      if (!reauthed) { res.statusCode = 403; res.setHeader('Content-Type', 'application/problem+json'); return res.end(JSON.stringify({ code: 'reauth_required', status: 403 })); }
      const created = { ...base, id: '22222222-2222-4222-8222-222222222222', name: input.name, scopes: input.scopes, credential_id: 'kpk_new' };
      integrations = [created, ...integrations]; res.statusCode = 201;
      return res.end(JSON.stringify({ integration: created, credential_id: 'kpk_new', secret: SECRET, api_key: `kpk_new.${SECRET}`, token_url: null, previous_valid_until: null }));
    }
    if (req.method === 'DELETE' && req.url.startsWith('/v1/integrations/')) {
      const id = req.url.split('/').pop(); integrations = integrations.map(i => i.id === id ? { ...i, status: 'revoked', active: false, revoked_at: '2026-10-20T11:00:00.000000Z' } : i);
      res.statusCode = 204; return res.end();
    }
    res.statusCode = 404; return res.end('{}');
  }
  if (req.url === '/') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.end(`<link rel="stylesheet" href="/assets/k3.css"><main id="main"><button id="page-action" hidden></button><p id="page-status" role="status"></p><div id="page-content"></div></main><script type="module">import {setTenant,saveCsrf} from '/assets/api.js';import {mountIntegrations} from '/assets/integrations.js';setTenant('${tenant}');saveCsrf('fixture');await mountIntegrations({tenant:'${tenant}',content:document.querySelector('#page-content'),status:document.querySelector('#page-status'),action:document.querySelector('#page-action')});window.ready=true;</script>`);
  }
  const file = path.resolve(root, '.' + req.url.split('?')[0]);
  if (!file.startsWith(path.resolve(root) + path.sep)) { res.statusCode = 404; return res.end(); }
  res.setHeader('Content-Type', req.url.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'text/css');
  fs.createReadStream(file).on('error', () => { res.statusCode = 404; res.end(); }).pipe(res);
});
(async () => {
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage(); const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.goto(`http://127.0.0.1:${server.address().port}`); await page.waitForFunction(() => window.ready);
    await page.getByRole('cell', { name: 'kpk_existing' }).waitFor();
    await page.getByText('No la pongas en código que corre en el navegador; úsala solo desde un servidor (p. ej. función de backend o secreto).', { exact: true }).first().waitFor();

    // Crear: valida en el navegador, pide la contraseña (403 reauth_required) y reintenta una vez.
    await page.getByRole('button', { name: 'Crear credencial', exact: true }).click();
    const form = page.getByRole('dialog', { name: 'Crear credencial' });
    await form.getByLabel('Nombre').fill('App Lovable');
    assert.equal(await form.getByLabel('Ubicación de las entradas').isDisabled(), true, 'uncontracted scope disabled');
    await form.getByLabel('Títulos de ventana y sitios').check();
    await form.getByRole('button', { name: 'Crear credencial', exact: true }).click();
    await form.getByText('«Títulos de ventana y sitios» necesita también «Actividad por aplicación».', { exact: true }).waitFor();
    await form.getByLabel('Títulos de ventana y sitios').uncheck();
    await form.getByLabel('Estructura de la empresa').check();
    assert.deepEqual(await form.getByLabel('Vence en').locator('option').allTextContents(), ['30 días', '90 días']);
    await form.getByLabel('Tipo').selectOption('oauth2');
    assert.deepEqual(await form.getByLabel('Vence en').locator('option').allTextContents(), ['30 días', '90 días', '180 días', '365 días']);
    await form.getByLabel('Tipo').selectOption('api_key');
    await form.getByRole('button', { name: 'Crear credencial', exact: true }).click();
    const reauth = page.getByRole('dialog', { name: 'Confirma tu contraseña' });
    await reauth.getByLabel('Tu contraseña').fill('fixture-password');
    await reauth.getByRole('button', { name: 'Confirmar', exact: true }).click();
    const secret = page.getByRole('dialog', { name: 'Credencial creada: App Lovable' });
    await secret.getByText('Guárdala ahora: no se volverá a mostrar.', { exact: true }).waitFor();
    await secret.getByText(`X-API-Key: kpk_new.${SECRET}`, { exact: true }).waitFor();
    await secret.getByText('No la pongas en código que corre en el navegador; úsala solo desde un servidor (p. ej. función de backend o secreto).', { exact: true }).waitFor();
    await secret.getByRole('button', { name: 'Copiar API key', exact: true }).waitFor();
    const posts = calls.filter(c => c.method === 'POST' && c.path === '/v1/integrations');
    assert.equal(posts.length, 2, 'create retried once after reauth');
    assert.deepEqual(posts[1].input, { name: 'App Lovable', auth_type: 'api_key', scopes: ['organization:read'], expires_in_days: 90 });
    await secret.getByRole('button', { name: 'Ya la guardé', exact: true }).click();
    await page.locator('dialog[data-secret]').waitFor({ state: 'detached' });
    assert.equal((await page.content()).includes(SECRET), false, 'secret not left in the page');

    // Revocar exige escribir el nombre exacto.
    await page.locator('tr[data-integration="22222222-2222-4222-8222-222222222222"]').getByRole('button', { name: 'Revocar', exact: true }).click();
    const confirm = page.getByRole('dialog', { name: 'Revocar credencial' });
    const revoke = confirm.getByRole('button', { name: 'Revocar', exact: true });
    assert.equal(await revoke.isDisabled(), true);
    await confirm.getByLabel('Escribe App Lovable para confirmar').fill('App');
    assert.equal(await revoke.isDisabled(), true);
    await confirm.getByLabel('Escribe App Lovable para confirmar').fill('App Lovable');
    await revoke.click();
    await page.locator('tr[data-integration="22222222-2222-4222-8222-222222222222"]').getByText('Revocada', { exact: true }).waitFor();
    assert.equal(calls.filter(c => c.method === 'DELETE').length, 1);
    assert.deepEqual(errors, []);
    console.log('PASS integrations panel');
  } finally { await browser.close(); server.close(); }
})().catch(e => { console.error(e); process.exit(1); });
