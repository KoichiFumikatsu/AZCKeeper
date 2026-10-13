import { KeeperPortalClient } from './keeper-portal-client.js';

const $ = id => document.getElementById(id);
const iso = d => d.toISOString().slice(0, 10);
const today = new Date(); const tomorrow = new Date(Date.now() + 86400000);
$('from').value = iso(today); $('to').value = iso(tomorrow); $('day').value = iso(today); $('origin').textContent = location.origin;

let client = null;
let creds = null; // solo en memoria de esta pestaña, para volver a entrar antes de las pruebas que cierran sesión
const state = { tenant: null, me: null };
const q = () => `from=${$('from').value}&to=${$('to').value}`;
const userId = () => $('user').value || state.me;
const fill = (select, items, label) => {
  const keep = select.value; select.replaceChildren();
  for (const it of items) { const o = document.createElement('option'); o.value = it.id; o.textContent = label(it); select.append(o); }
  if ([...select.options].some(o => o.value === keep)) select.value = keep;
};
// Esperado: estado HTTP exacto y, si aplica, código de error. Un caso negativo que NO falla se marca en rojo (hueco de seguridad).
const expect = (status, code) => r => r.status === status && (!code || r.body?.code === code || r.body?.title === code)
  ? { ok: true } : { ok: false, note: `se esperaba ${status}${code ? ' ' + code : ''}, llegó ${r.status} ${r.body?.code || ''}` };
const ok200 = check => r => {
  if (r.status !== 200 && r.status !== 201) return { ok: false, note: `${r.status} ${r.body?.code || ''} ${r.body?.detail || ''}`.trim() };
  return check ? check(r) : { ok: true };
};
const list = r => Array.isArray(r.body?.data) ? { ok: true, note: `${r.body.data.length} elementos` } : { ok: false, note: 'falta data[]' };

// [grupo, nombre, método, ruta(), qué debe pasar, verificación, opciones]
const TESTS = [
  ['Sesión', 'Contraseña incorrecta', 'POST', () => '/login', '401 invalid_credentials (y nunca dice si el correo existe)', expect(401, 'invalid_credentials'),
    { run: c => c.raw('POST', '/login', { email: $('email').value || 'nadie@example.com', password: 'contraseña-incorrecta-de-prueba' }, { auth: false }), noSession: true }],
  ['Sesión', 'Sin token', 'GET', () => '/me', '401: ninguna ruta de datos responde sin token', expect(401), { run: c => c.raw('GET', '/me', undefined, { auth: false }), noSession: true }],
  ['Sesión', 'Token inventado', 'GET', () => '/me', '401 session_expired', expect(401, 'session_expired'), { run: c => c.raw('GET', '/me', undefined, { token: 'token-falso-de-prueba-000000000000000000000' }), noSession: true }],
  ['Quién soy', 'Quién soy', 'GET', () => '/me', '200 con persona, rol, alcance, empresa y marca', ok200(r => { state.me = r.body?.user?.id || r.body?.id || state.me; return { ok: true, note: r.body?.display_name || r.body?.user?.display_name || '' }; })],
  ['Quién soy', 'Permisos y menú', 'GET', () => '/me/access', '200 con permisos y alcance', ok200()],
  ['Inicio', 'Indicadores', 'GET', () => `/dashboard?${q()}`, '200 con indicadores del período', ok200()],
  ['Inicio', 'Problemas reportados', 'GET', () => '/dashboard/problems', '200 con lista de problemas en tu alcance', ok200(list)],
  ['Personas', 'Personas (todas las páginas)', 'GET', () => '/users?limit=100', '200; solo las personas de tu alcance', ok200(r => { fill($('user'), r.body.data, u => `${u.display_name}${u.id === state.me ? ' (yo)' : ''}`); if (state.me) $('user').value = state.me; return list(r); }), { run: c => c.all('/users?limit=100') }],
  ['Personas', 'Ficha de la persona', 'GET', () => `/users/${userId()}`, '200; 404 si está fuera de tu alcance', ok200(), { needs: userId }],
  ['Personas', 'Actividad por día', 'GET', () => `/users/${userId()}/activity?${q()}`, '200 con días, en uso, inactivo, productividad', ok200(list), { needs: userId }],
  ['Personas', 'Ventanas (app y sitio)', 'GET', () => `/users/${userId()}/windows?${q()}`, '200; nunca títulos de ventana', ok200(r => JSON.stringify(r.body).includes('"title"') ? { ok: false, note: 'trae títulos: no debería' } : list(r)), { needs: userId }],
  ['Personas', 'Línea de tiempo', 'GET', () => `/users/${userId()}/timeline?day=${$('day').value}`, '200 con bloques del día (sin títulos)', ok200(), { needs: userId }],
  ['Reportes', 'Resumen por persona', 'GET', () => `/reports/people?${q()}`, '200', ok200(list)],
  ['Reportes', 'Puntualidad', 'GET', () => `/reports/presence?${q()}`, '200 con llegadas a tiempo / tarde / temprano', ok200()],
  ['Reportes', 'Productividad', 'GET', () => `/reports/productivity?${q()}`, '200', ok200()],
  ['Reportes', 'Aplicaciones', 'GET', () => `/reports/apps?${q()}`, '200', ok200()],
  ['Ausencias', 'Ausencias', 'GET', () => `/absences?${q()}`, '200; solo de personas visibles', ok200(list)],
  ['Ausencias', 'Registrar mi ausencia', 'POST', () => '/absences', '201 (crea una ausencia real de 1 minuto)', ok200(),
    { needs: () => state.me, write: true, run: c => { const s = new Date(Date.now() + 7 * 86400000); s.setUTCHours(23, 58, 0, 0); return c.call('POST', '/absences', { user_id: state.me, kind: 'otro', starts_at: s.toISOString().replace('.000', ''), ends_at: new Date(s.getTime() + 60000).toISOString().replace('.000', ''), all_day: false, note: 'Prueba del probador de la API (borrar)' }); } }],
  ['Ausencias', 'Ausencia para OTRA persona', 'POST', () => '/absences', '403 portal_self_only (el Portal solo registra la propia)', r => r.status === 403 ? { ok: true, note: r.body?.detail || '' } : { ok: false, note: `llegó ${r.status}: el Portal NO debe permitirlo` },
    { needs: () => [...$('user').options].find(o => o.value && o.value !== state.me)?.value, run: c => { const other = [...$('user').options].find(o => o.value && o.value !== state.me).value; return c.call('POST', '/absences', { user_id: other, kind: 'otro', starts_at: '2030-01-01T10:00:00Z', ends_at: '2030-01-01T10:01:00Z', all_day: false, note: 'no debe crearse' }); } }],
  ['Equipos', 'Equipos', 'GET', () => `/tenants/${state.tenant}/devices?limit=100`, '200; equipos de personas visibles', ok200(r => { fill($('device'), r.body.data, d => d.hostname); return list(r); }), { needs: () => state.tenant }],
  ['Equipos', 'Ficha del equipo', 'GET', () => `/devices/${$('device').value}`, '200 con allowed_commands', ok200(), { needs: () => $('device').value }],
  ['Horarios', 'Horario', 'GET', () => '/schedules', '200; sin alcance «todo», solo el propio', ok200(list)],
  ['Seguridad', 'Ruta que NO está en el Portal', 'GET', () => '/policies', '404: aunque exista en el panel', expect(404)],
  ['Seguridad', 'Orden remota desde el Portal', 'POST', () => `/devices/${$('device').value}/commands`, '404: el Portal no envía órdenes', expect(404), { needs: () => $('device').value, run: c => c.call('POST', `/devices/${$('device').value}/commands`, { type: 'lock' }) }],
  ['Seguridad', 'CORS de esta página', 'GET', () => '/me', 'La respuesta trae Access-Control-Allow-Origin igual a esta dirección', r => r.headers['access-control-allow-origin'] === location.origin ? { ok: true } : { ok: false, note: r.status === 0 ? 'bloqueado por CORS' : 'sin cabecera CORS' }],
  ['Sesión', 'Renovar token', 'POST', () => '/refresh', '200 con par nuevo; el access_token anterior deja de servir', null,
    { run: async c => { const old = c.access; const r = await c.refresh(); if (!r.ok) return { ...r, verdict: { ok: false, note: `${r.status} ${r.body?.code || ''}` } }; const again = await c.raw('GET', '/me', undefined, { token: old }); return { ...r, verdict: again.status === 401 ? { ok: true, note: 'el viejo ya no sirve' } : { ok: false, note: `el access_token viejo sigue sirviendo (${again.status})` } }; } }],
  ['Sesión', 'Reusar un refresh_token ya usado', 'POST', () => '/refresh', '401 y cierra TODA la sesión (protección contra robo)', null,
    { destructive: true, run: async c => { const used = c.refreshToken; await c.refresh(); const reuse = await c.raw('POST', '/refresh', { refresh_token: used }, { auth: false }); const after = await c.raw('GET', '/me'); return { ...reuse, verdict: reuse.status === 401 && after.status === 401 ? { ok: true, note: 'sesión revocada: vuelve a iniciar sesión' } : { ok: false, note: `reuse ${reuse.status}, sesión ${after.status}` } }; } }],
  ['Sesión', 'Cerrar sesión', 'POST', () => '/logout', '204 y el token deja de servir', null,
    { destructive: true, run: async c => { const token = c.access; const r = await c.logout(); const after = await c.raw('GET', '/me', undefined, { token }); return { ...r, verdict: r.status === 204 && after.status === 401 ? { ok: true } : { ok: false, note: `logout ${r.status}, después ${after.status}` } }; } }],
];

const rows = [];
let group = '';
for (const [g, name, method, path, expectText, check, opts = {}] of TESTS) {
  if (g !== group) { group = g; const tr = document.createElement('tr'); tr.className = 'group'; tr.innerHTML = `<td colspan="5">${g}</td>`; $('tests').append(tr); }
  const tr = document.createElement('tr');
  tr.innerHTML = `<td>${name}${opts.write ? ' <span class="pill skip">escribe</span>' : ''}${opts.destructive ? ' <span class="pill skip">cierra sesión</span>' : ''}</td><td><code>${method} /portal/v1<span class="p"></span></code></td><td class="muted">${expectText}</td><td><span class="pill idle">sin probar</span><div class="muted note"></div><details hidden><summary>Pedido y respuesta</summary><pre></pre></details></td><td><button>Probar</button></td>`;
  $('tests').append(tr);
  const row = { tr, name, method, path, check, opts, pill: tr.querySelector('.pill:not(.skip)') || tr.querySelectorAll('.pill')[tr.querySelectorAll('.pill').length - 1], note: tr.querySelector('.note'), det: tr.querySelector('details'), pre: tr.querySelector('pre'), btn: tr.querySelector('button'), p: tr.querySelector('.p') };
  row.pill = tr.querySelector('td:nth-child(4) .pill');
  row.btn.onclick = () => run(row);
  rows.push(row);
}
const refreshPaths = () => rows.forEach(r => { try { r.p.textContent = r.path(); } catch { r.p.textContent = ''; } });
refreshPaths(); ['from', 'to', 'day', 'user', 'device'].forEach(id => $(id).addEventListener('change', refreshPaths));

function mark(row, cls, text, note = '') { row.pill.className = `pill ${cls}`; row.pill.textContent = text; row.note.textContent = note; }
async function run(row) {
  if (!client) client = new KeeperPortalClient($('server').value);
  if (!row.opts.noSession && !client.access) return mark(row, 'skip', 'necesita sesión'), false;
  if (row.opts.needs && !row.opts.needs()) return mark(row, 'skip', 'falta dato', 'Corre antes las pruebas que llenan Persona / Equipo, o elige uno.'), false;
  if (row.opts.write && !$('allow-write').checked) return mark(row, 'skip', 'omitida', 'Marca «Permitir la prueba que crea…»'), false;
  if (row.opts.destructive && !$('allow-destructive').checked) return mark(row, 'skip', 'omitida', 'Marca «Incluir pruebas que cierran la sesión»'), false;
  if (row.opts.destructive && creds) { const again = await client.login(creds.email, creds.password); if (!again.ok) return mark(row, 'bad', 'no pudo reingresar', `${again.status} ${again.body?.code || ''}`), false; }
  mark(row, 'wait', 'probando…'); row.btn.disabled = true;
  const r = row.opts.run ? await row.opts.run(client) : await client.call(row.method, row.path());
  row.btn.disabled = false;
  const verdict = r.verdict || (row.check ? row.check(r) : { ok: r.ok });
  if (r.status === 0) $('cors-hint').hidden = false;
  mark(row, verdict.ok ? 'ok' : 'bad', `${verdict.ok ? 'Funciona' : 'Falla'} · ${r.status} · ${r.ms} ms`, verdict.note || '');
  row.det.hidden = false;
  row.pre.textContent = `PEDIDO\n${JSON.stringify(r.request, null, 2)}\n\nRESPUESTA ${r.status}\n${JSON.stringify(r.headers, null, 2)}\n${JSON.stringify(r.body, null, 2)?.slice(0, 6000)}`;
  updateSession(); return verdict.ok;
}
function updateSession() {
  const s = client?.access;
  $('session').textContent = s ? `Sesión activa: ${client.user?.display_name || ''} · empresa ${state.tenant || '?'} · token ${s.slice(0, 6)}… (15 min)` : 'Sin sesión.';
  $('logout').disabled = !s; $('run-all').disabled = !s;
  const done = rows.filter(r => /ok|bad/.test(r.pill.className));
  $('totals').textContent = done.length ? `${done.filter(r => r.pill.classList.contains('ok')).length} funcionan · ${done.filter(r => r.pill.classList.contains('bad')).length} fallan` : '';
}

$('login').onclick = async () => {
  client = new KeeperPortalClient($('server').value);
  creds = { email: $('email').value, password: $('password').value };
  const r = await client.login(creds.email, creds.password);
  $('password').value = '';
  if (r.status === 0) { $('cors-hint').hidden = false; $('session').textContent = 'No se pudo conectar (red o CORS).'; return; }
  if (!r.ok) { creds = null; $('session').textContent = `No se inició sesión: ${r.status} ${r.body?.code || ''}${r.headers['retry-after'] ? ` · espera ${r.headers['retry-after']} s` : ''}`; return; }
  state.tenant = r.body.user.tenant_id; state.me = r.body.user.id;
  if (r.body.user.must_change_password) $('session').textContent = 'Contraseña temporal: cámbiala en el panel antes de probar.';
  refreshPaths(); updateSession();
};
$('logout').onclick = async () => { await client.logout(); creds = null; updateSession(); };
$('run-all').onclick = async () => {
  $('run-all').disabled = true;
  for (const row of rows) { if (row.opts.destructive) continue; await run(row); refreshPaths(); }
  for (const row of rows.filter(r => r.opts.destructive)) await run(row);
  updateSession();
};
