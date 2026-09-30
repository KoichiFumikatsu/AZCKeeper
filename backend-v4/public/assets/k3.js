import { api, isUuid, setTenant, readSession, refreshCsrf, goToLogin, errorMessage } from './api.js';
import './menu.js';

// Pantallas con el diseño nuevo (orden de K3, estilo v4 índigo): Sedes, Organización y Asignaciones. Datos reales
// de la API; lo que la cuenta no puede ver se explica en vez de mostrarse vacío.
const $ = id => document.getElementById(id);
const page = document.body.dataset.page;
const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (className) n.className = className; return n; };
const when = v => v ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(v)) : 'Sin registro';
const pct = v => v === null || v === undefined || Number.isNaN(v) ? 'Sin dato' : `${new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1 }).format(v)} %`;
const kinds = { firm: 'Firma', site: 'Sede', area: 'Área', position: 'Cargo' };
const online = d => d.status === 'active' && d.last_seen_at && Date.now() - Date.parse(d.last_seen_at) <= 5 * 60 * 1000;
let tenant;

function fail(error) { $('page-error').textContent = error instanceof Error && !(error.status) ? error.message : errorMessage(error); $('page-error').hidden = false; $('page-status').textContent = ''; }
function done() { $('page-status').textContent = `Actualizado a las ${new Intl.DateTimeFormat('es-CO', { timeStyle: 'short' }).format(new Date())}.`; }
function surface(title, parent = $('page-content')) {
  const s = el('section', undefined, 'surface'); const head = el('div', undefined, 'section-head'); head.append(el('h2', title)); s.append(head); parent.append(s); return s;
}
function empty(parent, title, text) { const box = el('div', undefined, 'empty'); box.append(el('h2', title), el('p', text)); parent.append(box); }
function badge(text, tone) { return el('span', text, `badge ${tone || ''}`.trim()); }
function kpis(items) {
  const dl = el('dl', undefined, 'kpis');
  for (const [label, value, note] of items) { const box = el('div', undefined, 'kpi'); box.append(el('dt', label), el('dd', value)); if (note) box.append(el('small', note)); dl.append(box); }
  $('page-content').append(dl);
}
// Tabla con filtro de texto y, opcionalmente, un select por columna.
function table(parent, caption, columns, rows, { filters = [] } = {}) {
  const bar = el('div', undefined, 'toolbar');
  const search = el('input'); search.type = 'search'; search.placeholder = 'Buscar';
  const searchLabel = el('label', 'Buscar'); searchLabel.append(search); bar.append(searchLabel);
  const selects = filters.map(([label, getter]) => {
    const l = el('label', label); const s = el('select'); s.append(new Option('Todos', ''));
    [...new Set(rows.map(getter).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es')).forEach(v => s.append(new Option(v, v)));
    l.append(s); bar.append(l); return [s, getter];
  });
  parent.append(bar);
  const wrap = el('div', undefined, 'table-wrap'); wrap.tabIndex = 0; parent.append(wrap);
  const render = () => {
    const term = search.value.trim().toLocaleLowerCase('es');
    const shown = rows.filter(r => selects.every(([s, g]) => !s.value || g(r) === s.value) &&
      (!term || columns.some(([, get]) => { const v = get(r); return typeof v === 'string' && v.toLocaleLowerCase('es').includes(term); })));
    wrap.replaceChildren();
    if (!shown.length) { empty(wrap, 'Sin resultados', 'No hay filas que coincidan con la búsqueda o los filtros.'); return; }
    const t = el('table'); t.append(el('caption', `${caption} · ${shown.length} de ${rows.length}`));
    const head = el('thead'); const hr = el('tr'); columns.forEach(([name]) => { const th = el('th', name); th.scope = 'col'; hr.append(th); }); head.append(hr);
    const body = el('tbody');
    for (const r of shown) {
      const tr = el('tr');
      for (const [, get] of columns) { const v = get(r); const td = el('td'); if (v instanceof Node) td.append(v); else td.textContent = v ?? '—'; tr.append(td); }
      body.append(tr);
    }
    t.append(head, body); wrap.append(t);
  };
  search.addEventListener('input', render); selects.forEach(([s]) => s.addEventListener('change', render)); render();
}
async function allPages(path) {
  const out = []; let cursor = null;
  do {
    const url = new URL(path, location.origin); url.searchParams.set('limit', '100'); if (cursor) url.searchParams.set('cursor', cursor);
    const r = await api('GET', url.pathname + url.search); out.push(...r.data); cursor = r.next_cursor;
  } while (cursor);
  return out;
}
async function optional(promise) { try { return await promise; } catch (e) { if ([401, 403].includes(e.status)) return null; throw e; } }
function names(org) { return new Map(org.map(o => [o.id, o.name])); }

async function sites() {
  const today = new Date(); const from = new Date(today); from.setDate(today.getDate() - 6); const to = new Date(today); to.setDate(today.getDate() + 1);
  const day = d => d.toISOString().slice(0, 10);
  const [org, users, devices, people] = await Promise.all([allPages('/organization'), allPages('/users'), optional(allPages(`/tenants/${tenant}/devices`)),
    optional(api('GET', `/reports/people?from=${day(from)}&to=${day(to)}`))]);
  const siteList = org.filter(o => o.kind === 'site' && o.active);
  const totals = new Map((people?.data || []).map(p => [p.user_id, p.totals]));
  const byUser = new Map(); (devices || []).filter(d => d.status === 'active').forEach(d => { if (!byUser.has(d.user_id)) byUser.set(d.user_id, []); byUser.get(d.user_id).push(d); });
  const rows = siteList.map(site => {
    const members = users.filter(u => u.site_id === site.id && u.status === 'active');
    const devs = members.flatMap(u => byUser.get(u.id) || []);
    const sum = members.reduce((acc, u) => { const t = totals.get(u.id); if (t) { acc.active += t.active_seconds; acc.productive += t.productive_seconds; } return acc; }, { active: 0, productive: 0 });
    return { site, members: members.length, devices: devs.length, online: devs.filter(online).length, productivity: sum.active ? 100 * sum.productive / sum.active : null, hours: sum.active / 3600 };
  });
  const allDevs = (devices || []).filter(d => d.status === 'active');
  kpis([['Sedes activas', String(siteList.length)], ['Personas', String(users.filter(u => u.status === 'active').length)],
    ['Equipos en línea', devices ? `${allDevs.filter(online).length} / ${allDevs.length}` : 'Sin acceso', 'Con señal en los últimos 5 minutos'],
    ['Productividad (7 días)', people ? pct(rows.reduce((a, r) => a + (r.productivity ?? 0) * r.hours, 0) / (rows.reduce((a, r) => a + (r.productivity === null ? 0 : r.hours), 0) || NaN)) : 'Sin acceso']]);
  if (!siteList.length) { empty(surface('Sedes'), 'Aún no hay sedes', 'Crea las sedes en Organización y asígnalas a las personas.'); return; }
  const cards = el('div', undefined, 'three'); $('page-content').append(cards);
  for (const r of rows) {
    const s = surface(r.site.name, cards); s.append(el('p', 'Operación de sede', 'eyebrow'));
    const big = el('p', devices ? `${r.online} ` : '—', 'kpi-inline'); big.append(el('small', devices ? `en línea / ${r.devices}` : 'sin acceso a equipos')); s.append(big);
    s.append(el('p', `${r.members} personas · productividad ${pct(r.productivity)}`, 'help'));
  }
  table(surface('Detalle por sede'), 'Sedes', [['Sede', r => r.site.name], ['Personas', r => String(r.members)], ['Equipos', r => String(r.devices)],
    ['En línea', r => devices ? String(r.online) : '—'], ['Horas activas (7 días)', r => people ? r.hours.toFixed(1) : '—'], ['Productividad', r => people ? pct(r.productivity) : '—']], rows);
}

async function organization() {
  let org = await allPages('/organization');
  const byId = () => names(org);
  const render = () => {
    $('page-content').replaceChildren();
    const count = k => org.filter(o => o.kind === k && o.active).length;
    kpis([['Firmas', String(count('firm'))], ['Sedes', String(count('site'))], ['Áreas', String(count('area'))], ['Cargos', String(count('position'))]]);
    const s = surface('Unidades de la empresa');
    s.append(el('p', 'Desactivar una unidad solo es posible si ninguna persona, unidad activa, rol o regla depende de ella.', 'help'));
    const n = byId();
    table(s, 'Organización', [['Unidad', o => o.name], ['Tipo', o => kinds[o.kind] || o.kind], ['Depende de', o => o.parent_id ? (n.get(o.parent_id) || '—') : '—'],
      ['Estado', o => badge(o.active ? 'Activa' : 'Inactiva', o.active ? 'good' : '')], ['Acciones', o => actions(o)]], org,
      { filters: [['Tipo', o => kinds[o.kind]], ['Estado', o => o.active ? 'Activa' : 'Inactiva']] });
  };
  const save = async (o, changes) => {
    const body = { kind: o.kind, name: o.name, parent_id: o.parent_id, active: o.active, ...changes };
    const updated = await api('PATCH', `/organization/${o.id}`, { body, ifMatch: o.version });
    org = org.map(x => x.id === o.id ? updated : x); render();
  };
  const actions = o => {
    const box = el('div', undefined, 'actions no-margin');
    const rename = el('button', 'Renombrar', 'link-button'); rename.type = 'button';
    rename.addEventListener('click', async () => { const name = prompt('Nuevo nombre', o.name)?.trim(); if (!name || name === o.name) return; try { await save(o, { name }); } catch (e) { fail(e); } });
    const toggle = el('button', o.active ? 'Desactivar' : 'Activar', 'link-button'); toggle.type = 'button';
    toggle.addEventListener('click', async () => { if (o.active && !confirm(`¿Desactivar ${o.name}?`)) return; try { await save(o, { active: !o.active }); } catch (e) { fail(e); } });
    box.append(rename, toggle); return box;
  };
  const action = $('page-action'); action.textContent = 'Crear unidad'; action.hidden = false;
  action.addEventListener('click', () => {
    const dialog = el('dialog'); const form = el('form'); form.method = 'dialog';
    form.append(el('h2', 'Crear unidad'));
    const kind = el('select'); Object.entries(kinds).forEach(([k, v]) => kind.append(new Option(v, k))); kind.value = 'site';
    const name = el('input'); name.required = true; name.maxLength = 160;
    const parent = el('select'); parent.append(new Option('Sin unidad superior', '')); org.filter(o => o.active).forEach(o => parent.append(new Option(`${o.name} (${kinds[o.kind]})`, o.id)));
    for (const [label, input] of [['Tipo', kind], ['Nombre', name], ['Depende de', parent]]) { const l = el('label', label); l.append(input); form.append(l); }
    const error = el('p', '', 'error-text'); error.hidden = true;
    const footer = el('div', undefined, 'dialog-footer'); const cancel = el('button', 'Cancelar'); cancel.type = 'button'; const ok = el('button', 'Crear', 'primary'); ok.type = 'submit';
    footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
    cancel.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove());
    form.addEventListener('submit', async ev => {
      ev.preventDefault(); if (!name.value.trim()) { error.textContent = 'Escribe el nombre.'; error.hidden = false; return; }
      ok.disabled = true;
      try { const created = await api('POST', '/organization', { body: { kind: kind.value, name: name.value.trim(), parent_id: parent.value || null, active: true } }); org = [...org, created]; dialog.close(); render(); }
      catch (e) { error.textContent = errorMessage(e); error.hidden = false; ok.disabled = false; }
    });
    dialog.showModal();
  });
  render();
}

async function assignments() {
  const [org, users, devices] = await Promise.all([optional(allPages('/organization')), allPages('/users'), allPages(`/tenants/${tenant}/devices`)]);
  const n = names(org || []); const people = new Map(users.map(u => [u.id, u]));
  const active = devices.filter(d => d.status === 'active');
  const withoutDevice = users.filter(u => u.status === 'active' && !active.some(d => d.user_id === u.id)).length;
  kpis([['Equipos activos', String(active.length)], ['Personas con equipo', String(new Set(active.map(d => d.user_id)).size)], ['Personas sin equipo', String(withoutDevice)],
    ['En línea', `${active.filter(online).length} / ${active.length}`, 'Con señal en los últimos 5 minutos']]);
  const s = surface('Equipos y personas');
  s.append(el('p', 'Reasignar cambia a quién pertenece el equipo desde este momento: el agente vuelve a iniciar sesión y aplica las reglas de la nueva persona. El historial anterior queda con la persona anterior.', 'help'));
  const row = d => { const u = people.get(d.user_id); return { d, u, site: u ? n.get(u.site_id) : null, area: u ? n.get(u.area_id) : null, position: u ? n.get(u.position_id) : null }; };
  const link = (text, href) => { const a = el('a', text); a.href = href; return a; };
  table(s, 'Asignaciones', [['Persona', r => r.u ? link(r.u.display_name, `/miembro.php?id=${encodeURIComponent(r.u.id)}`) : 'Fuera de tu alcance'],
    ['Equipo', r => link(r.d.hostname, `/equipo.php?id=${encodeURIComponent(r.d.id)}`)], ['Sede', r => r.site || '—'], ['Área', r => r.area || '—'], ['Cargo', r => r.position || '—'],
    ['Última conexión', r => when(r.d.last_seen_at)], ['Acción', r => reassign(r.d)]], active.map(row),
    { filters: [['Sede', r => r.site], ['Área', r => r.area]] });
  function reassign(d) {
    const b = el('button', 'Reasignar', 'link-button'); b.type = 'button';
    b.addEventListener('click', () => {
      const dialog = el('dialog'); const form = el('form');
      form.append(el('h2', `Reasignar ${d.hostname}`));
      const who = el('select'); users.filter(u => u.status === 'active' && u.id !== d.user_id).sort((a, c) => a.display_name.localeCompare(c.display_name, 'es')).forEach(u => who.append(new Option(u.display_name, u.id)));
      const reason = el('input'); reason.maxLength = 500; reason.value = 'Reasignación desde el panel';
      for (const [label, input] of [['Nueva persona', who], ['Motivo', reason]]) { const l = el('label', label); l.append(input); form.append(l); }
      const error = el('p', '', 'error-text'); error.hidden = true;
      const footer = el('div', undefined, 'dialog-footer'); const cancel = el('button', 'Cancelar'); cancel.type = 'button'; const ok = el('button', 'Reasignar', 'primary'); ok.type = 'submit';
      if (!who.options.length) { ok.disabled = true; error.textContent = 'No hay otra persona activa a quien asignarlo.'; error.hidden = false; }
      footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
      cancel.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove());
      form.addEventListener('submit', async ev => {
        ev.preventDefault(); ok.disabled = true;
        try {
          await api('POST', `/devices/${d.id}/assignment`, { body: { user_id: who.value, reason: reason.value.trim() || 'Reasignación desde el panel' }, ifMatch: d.version, idempotencyKey: crypto.randomUUID() });
          dialog.close(); $('page-content').replaceChildren(); await assignments(); done();
        } catch (e) { error.textContent = errorMessage(e); error.hidden = false; ok.disabled = false; }
      });
      dialog.showModal();
    });
    return b;
  }
}

// ---- Cuentas del panel ------------------------------------------------------------------------------------------
const moduleNames = { usuarios: 'Personas', equipos: 'Equipos', reglas: 'Reglas', actividad: 'Actividad', presencia: 'Presencia', reportes: 'Reportes',
  organizacion: 'Organización', horarios: 'Horarios', festivos: 'Festivos', cumplimiento: 'Doble empleo y apps sospechosas', cobertura: 'Cobertura de instalación',
  hardening: 'Seguridad de cuentas de Windows', roles: 'Roles y permisos', tiers: 'Planes', empresas: 'Empresa', notificaciones: 'Notificaciones',
  integraciones: 'Integraciones', auditoria: 'Auditoría', operacion: 'Operación del panel', releases: 'Versiones del agente', migration: 'Migración desde K3', permisos: 'Catálogo de permisos' };
const scopeText = (role, n) => role.scope.kind === 'tenant' ? 'Toda la empresa' : role.scope.kind === 'self' ? 'Solo lo propio'
  : `${role.scope.kind === 'site' ? 'Sedes' : 'Áreas'}: ${role.scope.resource_ids.map(id => n.get(id) || id).join(', ')}`;
function dialogShell(title) {
  const dialog = el('dialog'); const form = el('form'); form.noValidate = true; form.append(el('h2', title));
  const body = el('div', undefined, 'dialog-body'); form.append(body);
  const error = el('p', '', 'error-text'); error.hidden = true;
  const footer = el('div', undefined, 'dialog-footer'); const cancel = el('button', 'Cancelar'); cancel.type = 'button'; const ok = el('button', 'Guardar', 'primary'); ok.type = 'submit';
  footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
  cancel.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove());
  const show = e => { error.textContent = typeof e === 'string' ? e : errorMessage(e); error.hidden = false; };
  return { dialog, form, body, ok, cancel, show };
}
function labeled(parent, text, input, help) { const l = el('label', text); l.append(input); parent.append(l); if (help) parent.append(el('p', help, 'help')); return input; }
function checks(parent, items, selected = []) {
  const box = el('div', undefined, 'check-list'); parent.append(box);
  return items.map(([value, text, note]) => { const l = el('label', undefined, 'check'); const c = el('input'); c.type = 'checkbox'; c.value = value; c.checked = selected.includes(value); l.append(c, ` ${text}`); if (note) l.append(el('small', note)); box.append(l); return c; });
}
// La reautenticacion protege crear cuentas y restablecer contraseñas: se pide la contraseña de quien opera.
async function reauth(password) { await api('POST', '/auth/reauth', { body: { password } }); }
function showSecret(account, secret) {
  const { dialog, body, ok, cancel } = dialogShell('Contraseña temporal');
  body.append(el('p', `Entrégale esta contraseña a ${account.user_name || account.display_name || account.email}. Solo se muestra ahora: al entrar deberá cambiarla.`));
  const pre = el('pre', secret, 'secret'); body.append(pre);
  body.append(el('p', `Correo para entrar: ${account.email}`, 'help'));
  cancel.hidden = true; ok.textContent = 'Listo'; ok.type = 'button'; ok.addEventListener('click', () => dialog.close());
  const copy = el('button', 'Copiar contraseña'); copy.type = 'button'; copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(secret); copy.textContent = 'Copiada'; } catch { copy.textContent = 'Selecciónala y cópiala'; } });
  body.append(copy); dialog.showModal();
}

async function accounts() {
  const session = readSession();
  const [list, users, roles, org] = await Promise.all([api('GET', '/panel-accounts'), allPages('/users'), optional(allPages('/roles')), optional(allPages('/organization'))]);
  const n = names(org || []); const roleName = new Map((roles || []).map(r => [r.id, r]));
  const render = rows => {
    $('page-content').replaceChildren();
    kpis([['Cuentas', String(rows.length)], ['Superadmin', String(rows.filter(a => a.kind === 'platform').length)], ['De la empresa', String(rows.filter(a => a.kind === 'company').length)],
      ['Pendientes de primer ingreso', String(rows.filter(a => a.must_change_password && a.active).length), 'Con contraseña temporal sin cambiar']]);
    const s = surface('Cuentas con acceso al panel');
    s.append(el('p', 'Superadmin administra todas las empresas. Una cuenta de empresa pertenece a una persona y ve lo que permitan sus roles: toda la empresa, ciertas sedes o áreas, o solo lo propio.', 'help'));
    table(s, 'Cuentas', [['Cuenta', a => { const d = el('div'); d.append(el('strong', a.user_name || a.display_name || a.email), el('small', a.email)); return d; }],
      ['Tipo', a => badge(a.kind === 'platform' ? 'Superadmin' : 'Empresa', a.kind === 'platform' ? 'info' : '')],
      ['Roles y alcance', a => a.kind === 'platform' ? 'Todo' : (a.role_ids.map(id => roleName.get(id)).filter(Boolean).map(r => `${r.name} (${scopeText(r, n)})`).join(' · ') || 'Sin roles: no verá nada')],
      ['Estado', a => badge(!a.active ? 'Desactivada' : a.must_change_password ? 'Debe crear su contraseña' : 'Activa', !a.active ? 'bad' : a.must_change_password ? 'warn' : 'good')],
      ['Último ingreso', a => when(a.last_login_at)], ['Acciones', a => actionsFor(a)]], rows,
      { filters: [['Tipo', a => a.kind === 'platform' ? 'Superadmin' : 'Empresa']] });
  };
  const refresh = async () => { list.data = (await api('GET', '/panel-accounts')).data; render(list.data); done(); };
  const actionsFor = a => {
    const box = el('div', undefined, 'actions no-margin');
    const button = (text, fn) => { const b = el('button', text, 'link-button'); b.type = 'button'; b.addEventListener('click', fn); box.append(b); };
    if (a.kind === 'company') button('Roles', () => editRoles(a));
    button(a.active ? 'Desactivar' : 'Activar', async () => {
      if (a.active && !confirm(`¿Desactivar la cuenta ${a.email}? Se cierran sus sesiones.`)) return;
      try { await api('PATCH', `/panel-accounts/${a.id}`, { body: { active: !a.active, role_ids: a.role_ids } }); await refresh(); } catch (e) { fail(e); }
    });
    button('Restablecer contraseña', () => {
      const { dialog, body, ok, show } = dialogShell(`Restablecer contraseña de ${a.email}`);
      body.append(el('p', 'Se genera una contraseña temporal nueva y se cierran las sesiones de esa cuenta.'));
      const pw = labeled(body, 'Tu contraseña (confirmación)', el('input')); pw.type = 'password'; pw.autocomplete = 'current-password';
      ok.textContent = 'Restablecer';
      dialog.querySelector('form').addEventListener('submit', async ev => {
        ev.preventDefault(); ok.disabled = true;
        try { await reauth(pw.value); const r = await api('POST', `/panel-accounts/${a.id}/reset-password`); dialog.close(); showSecret(r.account, r.temporary_password); await refresh(); }
        catch (e) { show(e.code === 'invalid_credentials' ? 'Tu contraseña no es correcta.' : e); ok.disabled = false; }
      });
      dialog.showModal();
    });
    return box;
  };
  const roleItems = () => (roles || []).filter(r => r.active).map(r => [r.id, r.name, scopeText(r, n)]);
  const editRoles = a => {
    const { dialog, body, ok, show } = dialogShell(`Roles de ${a.user_name || a.email}`);
    if (!roles) { body.append(el('p', 'Tu cuenta no puede ver los roles.')); ok.disabled = true; }
    const boxes = checks(body, roleItems(), a.role_ids);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); ok.disabled = true;
      try { await api('PATCH', `/panel-accounts/${a.id}`, { body: { active: a.active, role_ids: boxes.filter(b => b.checked).map(b => b.value) } }); dialog.close(); await refresh(); }
      catch (e) { show(e.code === 'tenant_rbac_disabled' ? 'La empresa no tiene habilitada la gestión de sus propios roles: pídesela a la plataforma.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const action = $('page-action'); action.textContent = 'Crear cuenta'; action.hidden = false;
  action.addEventListener('click', () => {
    const { dialog, body, ok, show } = dialogShell('Crear cuenta del panel');
    const kind = el('select');
    if (session?.is_platform_admin) kind.append(new Option('Superadmin (todas las empresas)', 'platform'));
    kind.append(new Option('Cuenta de empresa (una persona)', 'company')); kind.value = 'company';
    labeled(body, '¿Qué tipo de cuenta?', kind);
    const email = labeled(body, 'Correo para entrar', el('input')); email.type = 'email'; email.maxLength = 254; email.autocomplete = 'off';
    const nameBox = el('div'); body.append(nameBox);
    const display = labeled(nameBox, 'Nombre', el('input')); display.maxLength = 160;
    const companyBox = el('div'); body.append(companyBox);
    const person = el('select'); person.append(new Option('Elige la persona…', ''));
    const withAccount = new Set(list.data.filter(a => a.user_id).map(a => a.user_id));
    users.filter(u => u.status === 'active' && !withAccount.has(u.id)).sort((x, y) => x.display_name.localeCompare(y.display_name, 'es')).forEach(u => person.append(new Option(`${u.display_name}${u.email ? ' · ' + u.email : ''}`, u.id)));
    labeled(companyBox, '¿A quién pertenece?', person, 'Si la persona no aparece, créala primero en Personas (o ya tiene cuenta).');
    person.addEventListener('change', () => { const u = users.find(x => x.id === person.value); if (u?.email && !email.value) email.value = u.email; });
    companyBox.append(el('p', 'Roles: deciden qué puede ver y hacer, y en qué sedes o áreas.', 'help'));
    const boxes = checks(companyBox, roleItems());
    if (!roleItems().length) companyBox.append(el('p', 'Aún no hay roles: crea uno en Roles y permisos. Sin roles la cuenta no verá nada.', 'help'));
    const sync = () => { const platform = kind.value === 'platform'; nameBox.hidden = !platform; companyBox.hidden = platform; };
    kind.addEventListener('change', sync); sync();
    const pw = labeled(body, 'Tu contraseña (confirmación)', el('input')); pw.type = 'password'; pw.autocomplete = 'current-password';
    ok.textContent = 'Crear cuenta';
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!email.value.includes('@')) { show('Escribe un correo válido.'); return; }
      if (kind.value === 'company' && !person.value) { show('Elige a qué persona pertenece la cuenta.'); return; }
      if (kind.value === 'platform' && !display.value.trim()) { show('Escribe el nombre de la cuenta.'); return; }
      if (!pw.value) { show('Escribe tu contraseña para confirmar.'); return; }
      ok.disabled = true;
      try {
        await reauth(pw.value);
        const body = kind.value === 'platform'
          ? { kind: 'platform', email: email.value.trim(), display_name: display.value.trim(), user_id: null, role_ids: [] }
          : { kind: 'company', email: email.value.trim(), display_name: null, user_id: person.value, role_ids: boxes.filter(b => b.checked).map(b => b.value) };
        const r = await api('POST', '/panel-accounts', { body });
        dialog.close(); showSecret(r.account, r.temporary_password); await refresh();
      } catch (e) {
        const detail = e.problem?.detail;
        show(e.code === 'invalid_credentials' ? 'Tu contraseña no es correcta.' : detail === 'email_in_use' ? 'Ese correo ya tiene una cuenta.' : detail === 'person_has_account' ? 'Esa persona ya tiene cuenta.'
          : e.code === 'tenant_rbac_disabled' ? 'La empresa no tiene habilitada la gestión de sus propios roles.' : e);
        ok.disabled = false;
      }
    });
    dialog.showModal();
  });
  render(list.data);
}

// ---- Roles y permisos -------------------------------------------------------------------------------------------
async function rolesPage() {
  const session = readSession();
  const [roles, permissions, org] = await Promise.all([allPages('/roles'), allPages('/permissions'), optional(allPages('/organization'))]);
  const n = names(org || []); let list = roles;
  const visible = permissions.filter(p => session?.is_platform_admin || !p.platform_only);
  const labelOf = new Map(permissions.map(p => [p.code, p.label]));
  const render = () => {
    $('page-content').replaceChildren();
    const s = surface('Roles de la empresa');
    s.append(el('p', 'Un rol es un conjunto de permisos con un alcance. Asígnalo a una cuenta en Cuentas del panel.', 'help'));
    table(s, 'Roles', [['Rol', r => r.name], ['Alcance', r => scopeText(r, n)],
      ['Permisos', r => { const d = el('details'); d.append(el('summary', `${r.permissions.length} permisos`)); const ul = el('ul'); r.permissions.forEach(c => ul.append(el('li', labelOf.get(c) || c))); d.append(ul); return d; }],
      ['Estado', r => badge(r.active ? 'Activo' : 'Inactivo', r.active ? 'good' : '')], ['Acciones', r => { const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => editor(r)); return b; }]], list);
  };
  const editor = role => {
    const { dialog, body, ok, show } = dialogShell(role ? `Editar ${role.name}` : 'Crear rol');
    dialog.classList.add('wide-dialog');
    const name = labeled(body, 'Nombre del rol', el('input')); name.maxLength = 120; name.value = role?.name || '';
    const kind = el('select'); [['tenant', 'Toda la empresa'], ['site', 'Solo ciertas sedes'], ['area', 'Solo ciertas áreas'], ['self', 'Solo lo propio (su persona y sus equipos)']].forEach(([v, t]) => kind.append(new Option(t, v)));
    kind.value = role?.scope.kind || 'tenant'; labeled(body, '¿Sobre qué puede actuar?', kind);
    const scopeBox = el('div'); body.append(scopeBox);
    const drawScope = () => {
      scopeBox.replaceChildren();
      if (!['site', 'area'].includes(kind.value)) return [];
      const units = (org || []).filter(o => o.kind === kind.value && o.active).map(o => [o.id, o.name]);
      if (!units.length) { scopeBox.append(el('p', `No hay ${kind.value === 'site' ? 'sedes' : 'áreas'} activas: créalas en Organización.`, 'help')); return []; }
      return checks(scopeBox, units, role?.scope.kind === kind.value ? role.scope.resource_ids : []);
    };
    let scopeBoxes = drawScope(); kind.addEventListener('change', () => { scopeBoxes = drawScope(); });
    body.append(el('h3', 'Permisos'));
    const groups = new Map(); visible.forEach(p => { if (!groups.has(p.resource)) groups.set(p.resource, []); groups.get(p.resource).push(p); });
    const all = [];
    [...groups.entries()].sort((a, b) => (moduleNames[a[0]] || a[0]).localeCompare(moduleNames[b[0]] || b[0], 'es')).forEach(([resource, perms]) => {
      const fs = el('fieldset', undefined, 'perm-group'); fs.append(el('legend', moduleNames[resource] || resource)); body.append(fs);
      all.push(...checks(fs, perms.map(p => [p.code, p.label, p.platform_only ? 'Solo plataforma' : null]), role?.permissions || []));
    });
    const active = el('input'); active.type = 'checkbox'; active.checked = role ? role.active : true;
    const al = el('label', undefined, 'check'); al.append(active, ' Rol activo'); body.append(al);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!name.value.trim()) { show('Escribe el nombre del rol.'); return; }
      const ids = scopeBoxes.filter(b => b.checked).map(b => b.value);
      if (['site', 'area'].includes(kind.value) && !ids.length) { show(`Elige al menos una ${kind.value === 'site' ? 'sede' : 'área'}.`); return; }
      ok.disabled = true;
      const payload = { name: name.value.trim(), permissions: all.filter(b => b.checked).map(b => b.value), scope: { kind: kind.value, resource_ids: ids }, active: active.checked };
      try {
        const saved = role ? await api('PATCH', `/roles/${role.id}`, { body: payload, ifMatch: role.version }) : await api('POST', '/roles', { body: payload });
        list = role ? list.map(r => r.id === role.id ? saved : r) : [...list, saved]; dialog.close(); render(); done();
      } catch (e) { show(e.code === 'tenant_rbac_disabled' ? 'La empresa no tiene habilitada la gestión de sus propios roles: pídesela a la plataforma.' : e.status === 403 ? 'No puedes dar permisos que tu cuenta no tiene, o tu cuenta no puede gestionar roles.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const action = $('page-action'); action.textContent = 'Crear rol'; action.hidden = false; action.addEventListener('click', () => editor(null));
  render();
}

async function start() {
  const session = readSession();
  if (!session) { goToLogin(); return; }
  let selected = null; try { selected = sessionStorage.getItem('keeper.presentation.tenant'); } catch { /* sin almacenamiento */ }
  tenant = session.is_platform_admin ? selected : session.tenant_id;
  $('now').textContent = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date());
  $('logout').addEventListener('click', async () => { try { await refreshCsrf(); await api('POST', '/auth/logout'); } finally { goToLogin(); } });
  if ($('platform-group')) $('platform-group').hidden = !session.is_platform_admin;
  if (!isUuid(tenant)) { fail(new Error('Elige primero una empresa desde el panel de plataforma.')); return; }
  setTenant(tenant);
  try { const t = await api('GET', `/tenants/${tenant}`); $('tenant-name').textContent = t.branding?.display_name || t.name; } catch { /* el nombre es decorativo */ }
  try {
    if (page === 'sites') await sites();
    else if (page === 'organization') await organization();
    else if (page === 'assignments') await assignments();
    else if (page === 'accounts') await accounts();
    else if (page === 'roles') await rolesPage();
    done();
  } catch (e) {
    if (e.status === 403) { $('page-content').replaceChildren(); empty($('page-content'), 'Sin permiso', 'Tu cuenta no tiene acceso a esta sección. Pide a un administrador el permiso correspondiente.'); $('page-status').textContent = ''; }
    else fail(e);
  }
}
start();
