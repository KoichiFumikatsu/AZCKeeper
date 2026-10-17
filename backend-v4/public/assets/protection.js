import { api, readSession, errorMessage } from './api.js';
import { loadAccess, allowed } from './nav-access.js';
import { codeText, plainReason, reasonCodes } from './result-codes.js';

// «Seguridad de los equipos → Resumen de protección» (docs/design/2026-10-14-superadmin-por-tareas.md §2.2 punto 3, G4).
// Responde «¿qué tan protegidos están los equipos de este cliente?» con un solo endpoint (GET /tenants/{id}/protection):
// qué compró y sus ajustes arriba, y una tabla por equipo con el estado de cada control y un estado global con su motivo.
// Cada problema enlaza a donde se arregla. La API aplica el alcance de la cuenta: un coordinador ve solo sus equipos.
const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (className) n.className = className; return n; };
const badge = (text, tone) => el('span', text, `badge ${tone || ''}`.trim());
const when = v => v ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(v)) : 'Sin registro';
const link = (text, href, className) => { const a = el('a', text, className); a.href = href; return a; };
const number = n => new Intl.NumberFormat('es-CO').format(n);
// Tiempo relativo corto («hace 2 h») con la fecha completa en el title: la columna Agente queda angosta.
function ago(value) {
  if (!value) return null;
  const minutes = Math.max(0, Math.round((Date.now() - Date.parse(value)) / 60000));
  if (minutes < 1) return 'hace menos de 1 min';
  if (minutes < 60) return `hace ${minutes} min`;
  const hours = Math.round(minutes / 60);
  return hours < 48 ? `hace ${hours} h` : `hace ${Math.round(hours / 24)} días`;
}
const relative = (label, value, none) => { const s = el('small', value ? `${label} ${ago(value)}` : none); if (value) s.title = when(value); return s; };

export const PROTECTION = { protected: ['Protegido', 'good'], partial: ['Parcial', 'warn'], unprotected: ['Sin proteger', 'bad'], no_data: ['Sin datos', ''] };
const STATE = { applied: ['Aplicado', 'good'], failed: ['Falló', 'bad'], unsupported: ['No soportado', 'warn'], pending: ['Pendiente', 'warn'], not_in_plan: ['No aplica por plan', ''], unknown: ['Desconocido', ''] };
// Columnas de controles: clave de la API y nombre en palabras (el mismo de Funciones del cliente).
// Guion suave en «Endurecimiento»: la palabra larga parte en dos líneas y la tabla cabe en 1366 px.
const CONTROLS = [['web', 'Bloqueo web'], ['usb', 'USB'], ['downloads', 'Descargas'], ['installs', 'Instalación'], ['hardening', 'Endure­cimiento'], ['smart_app_control', 'Smart App Control'], ['encryption', 'Cifrado']];
const DETAIL = { not_contracted: 'El cliente no la compró', not_in_plan: 'Su plan no la incluye', no_rules: 'Sin reglas', on: 'Activo', off: 'Apagado', evaluation: 'En evaluación' };
const FIX = { device: 'Abrir la ficha del equipo', settings: 'Ir a Ajustes del cliente', rules: 'Ir a Reglas', plan: 'Cambiar el plan de la persona', contract: 'Revisar funciones del cliente' };

/** Dónde se arregla un motivo; null si la cuenta no puede llegar allí (se muestra solo el texto). */
export function fixHref(fix, row, ctx) {
  if (fix === 'device') return `/equipo.php?id=${encodeURIComponent(row.device_id)}`;
  if (fix === 'settings') return ctx.can('panel-settings') ? '/ajustes.php#seguridad-de-los-equipos' : null;
  if (fix === 'rules') return ctx.can('policies') ? '/reglas.php' : null;
  if (fix === 'plan') return `/miembro.php?id=${encodeURIComponent(row.user_id)}&tab=acceso`;
  if (fix === 'contract') return ctx.platform ? `/empresas.php?cliente=${encodeURIComponent(ctx.tenant)}` : null;
  return null;
}

function controlCell(control) {
  if (!control) return el('span', 'Sin dato', 'help');
  const [text, tone] = STATE[control.state] || [control.state, ''];
  const box = el('div'); box.append(badge(control.detail === 'no_rules' ? 'Sin reglas' : text, control.detail === 'no_rules' ? 'warn' : tone));
  // Detalle en palabras (mapa compartido result-codes.js); el código técnico queda en title.
  const detail = DETAIL[control.detail] ?? codeText(control.detail) ?? control.detail;
  if (detail && control.detail !== 'no_rules') { const s = el('small', detail); s.title = control.detail; box.append(s); }
  return box;
}

// Motivo con los códigos entre paréntesis traducidos («Endurecimiento pendiente (Esperando la orden desde el panel)»).
function reasonText(r, make) {
  const n = make(plainReason(r.text)); const codes = reasonCodes(r.text);
  if (codes.length) n.title = [n.title, `Código: ${codes.join(', ')}`].filter(Boolean).join(' · ');
  return n;
}
function statusCell(row, ctx) {
  const [text, tone] = PROTECTION[row.protection];
  const box = el('div', undefined, 'protection-status'); box.append(badge(text, tone));
  const [first, ...rest] = row.reasons;
  if (first) {
    const href = fixHref(first.fix, row, ctx);
    const line = reasonText(first, text => { const n = href ? link(text, href) : el('span', text); if (href) n.title = FIX[first.fix]; return n; });
    const p = el('small'); p.append(line); box.append(p);
  }
  if (rest.length) {
    const more = el('details', undefined, 'protection-more'); more.append(el('summary', `${rest.length} motivo${rest.length > 1 ? 's' : ''} más`));
    const list = el('ul');
    rest.forEach(r => { const li = el('li'); const href = fixHref(r.fix, row, ctx); li.append(reasonText(r, text => href ? link(text, href) : el('span', text))); list.append(li); });
    more.append(list); box.append(more);
  }
  return box;
}

function boughtSection(parent, client, ctx) {
  const s = el('section', undefined, 'surface protection-bought'); s.id = 'que-compro'; s.dataset.kit = 'own'; // tablekit: solo orden, sin buscador
  const head = el('div', undefined, 'section-head'); head.append(el('h2', 'Qué compró el cliente')); s.append(head);
  s.append(el('p', 'Cada control de seguridad: si el cliente lo compró, qué planes lo incluyen, a cuántas personas llega y cuántos equipos lo reportan aplicado.', 'help'));
  const wrap = el('div', undefined, 'table-wrap protection-table'); const t = el('table');
  t.append(el('caption', 'Controles de seguridad del cliente'));
  const hr = el('tr'); ['Control', '¿Lo compró?', 'Planes que lo incluyen', 'Personas a las que llega', 'Equipos que lo reportan aplicado'].forEach(h => { const th = el('th', h); th.scope = 'col'; hr.append(th); });
  const thead = el('thead'); thead.append(hr); const body = el('tbody');
  for (const f of client.features) {
    const tr = el('tr'); const cells = [el('span', f.name), badge(f.contracted ? 'Sí' : 'No', f.contracted ? 'good' : ''),
      el('span', f.plans.length ? f.plans.join(', ') : 'Ningún plan'), el('span', f.contracted ? number(f.people) : '—'),
      el('span', f.devices_applied === null ? 'No lo reporta el agente' : f.contracted ? number(f.devices_applied) : '—')];
    if (f.devices_applied === null) cells[4].className = 'help';
    cells.forEach(c => { const td = el('td'); td.append(c); tr.append(td); });
    tr.dataset.code = f.code; body.append(tr);
  }
  // Smart App Control y PIN no se venden aparte: los trae todo cliente.
  for (const [name, on, yes, no] of [['Smart App Control apagado', client.disable_smart_app_control, 'Se pide apagarlo en todos los equipos', 'No se toca'], ['PIN de desbloqueo local', client.unlock_pin_configured, 'Configurado', 'Sin configurar']]) {
    const tr = el('tr'); const cells = [el('span', name), badge('Incluido', 'info'), el('span', 'Todos (no se vende aparte)'), el('span', on ? yes : no), el('span', '—')];
    cells.forEach(c => { const td = el('td'); td.append(c); tr.append(td); }); body.append(tr);
  }
  t.append(thead, body); wrap.append(t); s.append(wrap);
  if (!client.features.some(f => f.contracted)) {
    const p = el('p', 'Este cliente no compró ningún control de seguridad: sus equipos quedan sin proteger. ', 'notice');
    if (ctx.platform) p.append(link('Revisar funciones del cliente', fixHref('contract', {}, ctx)));
    s.append(p);
  }
  parent.append(s);
}

function settingsSection(parent, client, ctx) {
  const s = el('section', undefined, 'surface protection-settings'); s.id = 'ajustes-de-seguridad';
  const head = el('div', undefined, 'section-head'); head.append(el('h2', 'Ajustes de seguridad del cliente')); s.append(head);
  const hardeningBought = client.features.find(f => f.code === 'endurecimiento')?.contracted;
  const hardening = !hardeningBought ? ['No comprado', ''] : !client.it_account_configured ? ['Inactivo: falta cuenta de IT', 'bad']
    : ['Activo', 'good'];
  const mode = client.hardening_mode === 'auto' ? 'automático (cada equipo se endurece solo)' : 'desde el panel (se endurece equipo por equipo en su ficha)';
  const rows = [['Plan por defecto', el('span', client.default_plan?.name || 'Sin plan por defecto')],
    ['Cuenta de IT', badge(client.it_account_configured ? 'Configurada' : 'Falta', client.it_account_configured ? 'good' : 'bad')],
    ['Endurecimiento', (() => { const b = el('div'); b.append(badge(hardening[0], hardening[1])); if (hardeningBought) b.append(el('small', `Modo ${mode}`)); return b; })()],
    ['PIN de desbloqueo local', badge(client.unlock_pin_configured ? 'Configurado' : 'Sin configurar', client.unlock_pin_configured ? 'good' : 'warn')],
    ['Smart App Control', el('span', client.disable_smart_app_control ? 'Se pide apagarlo en todos los equipos' : 'No se toca')]];
  const dl = el('dl', undefined, 'details protection-facts');
  rows.forEach(([k, v]) => { const d = el('div'); const dd = el('dd'); dd.append(v); d.append(el('dt', k), dd); dl.append(d); });
  s.append(dl);
  const actions = el('div', undefined, 'actions');
  if (ctx.can('panel-settings')) actions.append(link('Cambiar en Ajustes del cliente', '/ajustes.php#seguridad-de-los-equipos', 'button'));
  if (ctx.can('policies')) actions.append(link('Ver reglas de apps, sitios y USB', '/reglas.php', 'button'));
  if (ctx.platform) actions.append(link('Cambiar funciones y plan por defecto', `/empresas.php?cliente=${encodeURIComponent(ctx.tenant)}`, 'button'));
  if (actions.childElementCount) s.append(actions);
  parent.append(s);
}

function kpiBar(parent, totals, onPick) {
  const dl = el('dl', undefined, 'kpis protection-kpis');
  for (const key of ['protected', 'partial', 'unprotected', 'no_data']) {
    const box = el('div', undefined, `kpi protection-kpi ${key}`); const btn = el('button', undefined, 'link-button'); btn.type = 'button';
    btn.textContent = { protected: 'Ver protegidos', partial: 'Ver parciales', unprotected: 'Ver sin proteger', no_data: 'Ver sin datos' }[key]; btn.addEventListener('click', () => onPick(key));
    box.append(el('dt', PROTECTION[key][0]), el('dd', number(totals[key])), el('small', `de ${number(totals.devices)} equipos`), btn); dl.append(box);
  }
  parent.append(dl);
  return dl;
}

export async function protectionPage({ tenant, content, status }) {
  const access = await loadAccess();
  const ctx = { tenant, platform: !!readSession()?.is_platform_admin, can: key => !!access && allowed(access, key) };
  const state = { site: '', protection: '', q: '' };
  try { const v = new URLSearchParams(location.search); state.site = v.get('sede') || ''; state.protection = PROTECTION[v.get('estado')] ? v.get('estado') : ''; state.q = v.get('buscar') || ''; } catch { /* sin filtros en la URL */ }
  const query = (cursor = null) => {
    const u = new URLSearchParams({ limit: '50' });
    if (state.site) u.set('site_id', state.site); if (state.protection) u.set('protection', state.protection); if (state.q.trim()) u.set('q', state.q.trim().slice(0, 100));
    if (cursor) u.set('cursor', cursor);
    return `/tenants/${tenant}/protection?${u}`;
  };
  const first = await api('GET', query());
  content.replaceChildren();
  const kpiSlot = el('div'); content.append(kpiSlot);
  let kpis = kpiBar(kpiSlot, first.totals, pick);
  const top = el('div', undefined, 'protection-top'); content.append(top);
  boughtSection(top, first.client, ctx);
  settingsSection(top, first.client, ctx);

  const s = el('section', undefined, 'surface protection-devices'); s.id = 'equipos';
  const head = el('div', undefined, 'section-head'); head.append(el('h2', 'Equipos')); s.append(head);
  s.append(el('p', 'Estado que declara cada agente en su último reporte, cruzado con el plan de la persona y las reglas que recibe. «No aplica por plan» no cuenta en contra del equipo.', 'help'));
  const bar = el('div', undefined, 'toolbar');
  const search = el('input'); search.type = 'search'; search.placeholder = 'Equipo o persona'; search.maxLength = 100; search.value = state.q;
  const sLabel = el('label', 'Buscar'); sLabel.append(search);
  const site = el('select'); site.append(new Option('Todas', ''));
  const siteLabel = el('label', 'Sede'); siteLabel.append(site);
  const prot = el('select'); prot.append(new Option('Todos', '')); Object.entries(PROTECTION).forEach(([k, [t]]) => prot.append(new Option(t, k))); prot.value = state.protection;
  const pLabel = el('label', 'Estado'); pLabel.append(prot);
  const clear = el('button', 'Limpiar filtros', 'link-button table-clear'); clear.type = 'button';
  bar.append(sLabel, siteLabel, pLabel, clear); s.append(bar);
  // Sedes: las del cliente si la cuenta puede leerlas; si no, las de sus propios equipos.
  const sites = new Map();
  try { const org = await api('GET', '/organization?limit=100'); org.data.filter(o => o.kind === 'site' && o.active).forEach(o => sites.set(o.id, o.name)); } catch { /* sin permiso de organización */ }
  first.data.forEach(r => { if (r.site_id && !sites.has(r.site_id)) sites.set(r.site_id, r.site_name || 'Sede'); });
  [...sites].sort((a, b) => a[1].localeCompare(b[1], 'es')).forEach(([id, name]) => site.append(new Option(name, id)));
  if (state.site && !sites.has(state.site)) site.append(new Option('Sede elegida', state.site));
  site.value = state.site;
  const summary = el('p', '', 'help protection-count'); summary.setAttribute('role', 'status');
  const wrap = el('div', undefined, 'table-wrap protection-table'); wrap.tabIndex = 0;
  const more = el('button', 'Cargar más equipos'); more.type = 'button'; more.hidden = true;
  const error = el('p', '', 'error-text'); error.setAttribute('role', 'alert'); error.hidden = true;
  s.append(summary, wrap, more, error); content.append(s);

  let tbody = null, shown = 0, next = null, seq = 0;
  // Equipo y persona juntos (con sede y plan), y agente con su última conexión: la tabla cabe en 1366 px sin perder datos.
  const columns = [
    ['Equipo y persona', r => { const b = el('div'); b.append(link(r.hostname, `/equipo.php?id=${encodeURIComponent(r.device_id)}`));
      const who = el('small'); who.append(link(r.display_name, `/miembro.php?id=${encodeURIComponent(r.user_id)}&tab=acceso`)); b.append(who);
      b.append(el('small', [r.site_name || 'Sin sede', r.plan ? `Plan ${r.plan.name}` : 'Sin plan'].join(' · '))); return b; }],
    ['Agente', r => { const b = el('div'); b.append(r.agent_outdated ? badge(r.agent_version || 'Sin versión', 'warn') : el('span', r.agent_version || 'Sin versión'));
      if (r.agent_outdated) b.append(el('small', 'Agente viejo'));
      b.append(relative('Conexión', r.last_seen_at, 'Nunca se conectó'), relative('Reporte', r.report_observed_at, 'Sin reporte de controles')); return b; }],
    ...CONTROLS.map(([key, name]) => [name, r => controlCell(r.controls[key])]),
    ['Estado', r => statusCell(r, ctx)]];
  const render = (page, append) => {
    if (!append) {
      wrap.replaceChildren(); tbody = null; shown = 0;
      if (!page.data.length) {
        const box = el('div', undefined, 'empty'); box.append(el('h2', filtered() ? 'Sin resultados' : 'Sin equipos'), el('p', filtered() ? 'Ningún equipo cumple estos filtros.' : 'No hay equipos activos en tu alcance.'));
        if (filtered()) { const again = el('button', 'Limpiar filtros'); again.type = 'button'; again.addEventListener('click', reset); box.append(again); }
        wrap.append(box);
      } else {
        // Paginada en la API: tablekit no la reordena ni la pagina (mezclaría una página parcial).
        const t = el('table'); t.dataset.kit = 'done'; t.append(el('caption', 'Protección por equipo'));
        const hr = el('tr'); columns.forEach(([name]) => { const th = el('th', name); th.scope = 'col'; hr.append(th); });
        const thead = el('thead'); thead.append(hr); tbody = el('tbody'); t.append(thead, tbody); wrap.append(t);
      }
    }
    for (const r of page.data) {
      const tr = el('tr'); tr.dataset.protection = r.protection; tr.dataset.device = r.device_id;
      columns.forEach(([, get], i) => { const td = el('td', undefined, i >= 2 && i < 2 + CONTROLS.length ? 'control' : undefined); td.append(get(r)); tr.append(td); });
      tbody.append(tr);
    }
    shown += page.data.length; next = page.next_cursor; more.hidden = !next;
    const total = state.protection ? page.totals[state.protection] : page.totals.devices;
    summary.textContent = `${number(shown)} de ${number(total)} equipos${state.protection ? ` con estado «${PROTECTION[state.protection][0]}»` : ''}.`;
    clear.disabled = !filtered();
    status.textContent = `Actualizado a las ${new Intl.DateTimeFormat('es-CO', { timeStyle: 'short' }).format(new Date()).replace(/\.$/, '')}.`;
  };
  const filtered = () => !!(state.site || state.protection || state.q.trim());
  const remember = () => {
    try {
      const u = new URL(location.href); [['sede', state.site], ['estado', state.protection], ['buscar', state.q.trim()]].forEach(([k, v]) => { if (v) u.searchParams.set(k, v); else u.searchParams.delete(k); });
      history.replaceState(null, '', u.pathname + u.search + u.hash);
    } catch { /* sin historial */ }
  };
  const reload = async () => {
    const mine = ++seq; error.hidden = true; remember();
    try {
      const page = await api('GET', query());
      if (mine !== seq) return;
      const fresh = kpiBar(el('div'), page.totals, pick); kpis.replaceWith(fresh); kpis = fresh;
      render(page, false);
    } catch (e) { if (mine === seq) { error.textContent = errorMessage(e); error.hidden = false; } }
  };
  function pick(key) { state.protection = key; prot.value = key; reload(); s.scrollIntoView({ block: 'start' }); }
  function reset() { state.site = ''; state.protection = ''; state.q = ''; site.value = ''; prot.value = ''; search.value = ''; reload(); search.focus(); }
  let timer = null;
  search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { state.q = search.value; reload(); }, 300); });
  site.addEventListener('change', () => { state.site = site.value; reload(); });
  prot.addEventListener('change', () => { state.protection = prot.value; reload(); });
  clear.addEventListener('click', reset);
  more.addEventListener('click', async () => {
    more.disabled = true; error.hidden = true;
    try { render(await api('GET', query(next)), true); } catch (e) { error.textContent = errorMessage(e); error.hidden = false; } finally { more.disabled = false; }
  });
  render(first, false);
}
