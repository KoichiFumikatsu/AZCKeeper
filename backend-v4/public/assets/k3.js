import { api, isUuid, setTenant, readSession, refreshCsrf, goToLogin, errorMessage } from './api.js';

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

async function start() {
  const session = readSession();
  if (!session) { goToLogin(); return; }
  let selected = null; try { selected = sessionStorage.getItem('keeper.presentation.tenant'); } catch { /* sin almacenamiento */ }
  tenant = session.is_platform_admin ? selected : session.tenant_id;
  $('now').textContent = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date());
  $('open-menu').addEventListener('click', () => { $('sidebar').classList.add('open'); $('open-menu').setAttribute('aria-expanded', 'true'); });
  $('close-menu').addEventListener('click', () => { $('sidebar').classList.remove('open'); $('open-menu').setAttribute('aria-expanded', 'false'); });
  $('logout').addEventListener('click', async () => { try { await refreshCsrf(); await api('POST', '/auth/logout'); } finally { goToLogin(); } });
  if (!isUuid(tenant)) { fail(new Error('Elige primero una empresa desde el panel de plataforma.')); return; }
  setTenant(tenant);
  try { const t = await api('GET', `/tenants/${tenant}`); $('tenant-name').textContent = t.branding?.display_name || t.name; } catch { /* el nombre es decorativo */ }
  try {
    if (page === 'sites') await sites();
    else if (page === 'organization') await organization();
    else if (page === 'assignments') await assignments();
    done();
  } catch (e) {
    if (e.status === 403) { $('page-content').replaceChildren(); empty($('page-content'), 'Sin permiso', 'Tu cuenta no tiene acceso a esta sección. Pide a un administrador el permiso correspondiente.'); $('page-status').textContent = ''; }
    else fail(e);
  }
}
start();
