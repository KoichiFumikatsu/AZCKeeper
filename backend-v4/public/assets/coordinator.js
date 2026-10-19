import { api, ApiError, errorMessage, isUuid } from './api.js';
import { deviceUpdates } from './fleet.js';
import { remoteActionsSection, commandTypes, commandStates } from './device-actions.js';

const el = (tag, text, cls) => {
  const n = document.createElement(tag);
  if (text !== undefined) n.textContent = text;
  if (cls) n.className = cls;
  return n;
};
const fmt = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1 });
const numeric = v => typeof v === 'number' && Number.isFinite(v);
const percent = v => numeric(v) ? `${fmt.format(v)} %` : 'Sin dato';
const hours = v => numeric(v) ? `${fmt.format(v / 3600)} h` : 'Sin dato';
const names = { tenant: 'Empresa', global: 'Global', site: 'Sede', area: 'Área', user: 'Persona', device: 'Equipo', active: 'Activo', inactive: 'Inactivo', revoked: 'Revocado', decommissioned: 'Retirado', a_tiempo: 'A tiempo', tarde: 'Tarde', temprano: 'Temprano', actividad_sin_hora: 'Actividad sin hora registrada', sin_actividad: 'Sin actividad', sin_conectarse: 'Sin conectarse hoy', no_laborable: 'No laborable', allow: 'Permitir', deny: 'Denegar', require: 'Exigir', instalado: 'Instalado', enrolado: 'Enrolado', escrow_ok: 'Respaldo verificado', degradado: 'Degradado', pendiente_reinicio: 'Pendiente de reinicio', completo: 'Completo', excepcion: 'Excepción' };
const label = v => names[v] || v || 'Sin dato';
const link = (text, href) => { const a = el('a', text); a.href = href; return a; };
const memberLink = u => link(u.display_name, `/miembro.php?id=${encodeURIComponent(u.id || u.user_id)}`);
const empty = (parent, text = 'No hay datos para este período en tu alcance.') => parent.append(el('p', text, 'empty-state'));
const invalid = () => new ApiError(200, { code: 'invalid_response' });
function rows(data, field = 'data') { if (!Array.isArray(data?.[field])) throw invalid(); return data[field]; }
function error(parent, e) { const p = el('p', errorMessage(e), 'error-message'); p.setAttribute('role', 'alert'); parent.append(p); }
function panel(parent, title) { const p = el('section', undefined, 'work-surface'); p.append(el('h2', title)); parent.append(p); return p; }
function details(parent, fields) {
  const d = el('dl', undefined, 'details');
  fields.forEach(([name, value]) => { const r = el('div'); r.append(el('dt', name), el('dd', value ?? 'Sin dato')); d.append(r); }); parent.append(d);
}
function metrics(parent, fields) {
  const d = el('dl', undefined, 'kpi-grid coordinator-kpis');
  fields.forEach(([name, value, note]) => { const r = el('div', undefined, 'metric'); const v = el('dd'); v.append(value); r.append(el('dt', name), v); if (note) r.append(el('p', note, 'help')); d.append(r); }); parent.append(d);
}
function cellValue(row, field) { return typeof field === 'function' ? field(row) : row[field]; }
function pagedTable(parent, title, columns, records, size = Infinity) {
  if (!records.length) { empty(parent); return; }
  const region = el('div', undefined, 'table-region'); region.tabIndex = 0; region.setAttribute('role', 'region'); region.setAttribute('aria-label', title);
  const table = el('table'); table.append(el('caption', title));
  const head = el('thead'); const tr = el('tr'); columns.forEach(([name]) => { const th = el('th', name); th.scope = 'col'; tr.append(th); }); head.append(tr);
  const body = el('tbody'); table.append(head, body); region.append(table); parent.append(region);
  const bar = el('div', undefined, 'pager'); const prev = el('button', 'Anterior'), next = el('button', 'Siguiente'), status = el('p', undefined, 'help');
  prev.type = next.type = 'button'; status.setAttribute('role', 'status'); bar.append(prev, status, next); if (records.length > size) parent.append(bar);
  let offset = 0;
  function render() {
    body.replaceChildren();
    records.slice(offset, offset + size).forEach(record => {
      const row = el('tr'); columns.forEach(([, field], i) => { const cell = el(i ? 'td' : 'th'); if (!i) cell.scope = 'row'; const v = cellValue(record, field); if (v instanceof Node) cell.append(v); else cell.textContent = v ?? 'Sin dato'; row.append(cell); }); body.append(row);
    });
    status.textContent = `${offset + 1}–${Math.min(offset + size, records.length)} de ${fmt.format(records.length)}`;
    prev.disabled = offset === 0; next.disabled = offset + size >= records.length;
  }
  prev.onclick = () => { offset -= size; render(); }; next.onclick = () => { offset += size; render(); }; render();
}
function csvButton(parent, name, columns, records) {
  const button = el('button', 'Exportar CSV'); button.type = 'button'; button.disabled = !records.length;
  button.onclick = () => {
    const quote = v => { let s = String(v ?? ''); if (/^[\s]*[=+@-]/.test(s)) s = `'${s}`; return `"${s.replaceAll('"', '""')}"`; };
    const content = [columns.map(c => c[0]), ...records.map(r => columns.map(([, f]) => { const v = cellValue(r, f); return v instanceof Node ? v.textContent : v; }))].map(r => r.map(quote).join(';')).join('\r\n');
    const url = URL.createObjectURL(new Blob(['\ufeff', content], { type: 'text/csv;charset=utf-8' })); const a = link('', url); a.download = `${name}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  }; parent.append(button);
}
function chart(parent, series, unit = 'día') {
  if (!series.some(r => numeric(r.productivity_percent) || numeric(r.focus_score))) { empty(parent, 'Sin mediciones de productividad o focus para dibujar la tendencia.'); return; }
  const svgEl = (tag, attrs = {}, text) => { const n = document.createElementNS('http://www.w3.org/2000/svg', tag); Object.entries(attrs).forEach(([k, v]) => n.setAttribute(k, v)); if (text !== undefined) n.textContent = text; return n; };
  const svg = svgEl('svg', { viewBox: '0 0 760 250', role: 'img', 'aria-label': `Productividad y focus por ${unit}. Escala de 0 a 100. Los huecos indican datos ausentes.`, class: 'trend-chart' });
  svg.append(svgEl('title', {}, `Productividad y focus por ${unit}`));
  [0, 25, 50, 75, 100].forEach(v => { const y = 210 - v * 1.8; svg.append(svgEl('line', { x1: 42, x2: 738, y1: y, y2: y, class: 'chart-grid' }), svgEl('text', { x: 4, y: y + 5 }, String(v))); });
  for (const [field, cls, title] of [['productivity_percent', 'productivity-line', 'Productividad'], ['focus_score', 'focus-line', 'Focus']]) {
    let path = '', gap = true;
    series.forEach((r, i) => { if (!numeric(r[field])) { gap = true; return; } const x = 42 + i * 696 / Math.max(1, series.length - 1), y = 210 - r[field] * 1.8; path += `${gap ? 'M' : 'L'}${x},${y} `; gap = false; const dot = svgEl('circle', { cx: x, cy: y, r: 3, class: cls }); dot.append(svgEl('title', {}, `${r.day}: ${title} ${fmt.format(r[field])}`)); svg.append(dot); });
    svg.append(svgEl('path', { d: path, class: cls, fill: 'none', 'stroke-width': 3 }));
  }
  svg.append(svgEl('text', { x: 42, y: 240 }, series[0].day), svgEl('text', { x: 738, y: 240, 'text-anchor': 'end' }, series.at(-1).day));
  const frame = el('div', undefined, 'chart-frame'); frame.tabIndex = 0; frame.setAttribute('role', 'region'); frame.setAttribute('aria-label', 'Gráfico de tendencia; desplazable en pantallas pequeñas'); frame.append(svg);
  parent.append(frame, el('p', `Línea continua índigo oscuro: productividad (%) · Línea discontinua índigo claro: focus (/100). Cada punto representa ${unit === 'día' ? 'un día' : 'una semana'}; los huecos indican ausencia de datos.`, 'help'));
}
const COUNTRY_NAMES = { CO: 'Colombia', US: 'Estados Unidos', HN: 'Honduras', MX: 'México', PE: 'Perú', EC: 'Ecuador', PA: 'Panamá', CR: 'Costa Rica', GT: 'Guatemala', SV: 'El Salvador', ES: 'España', DO: 'República Dominicana', NI: 'Nicaragua', VE: 'Venezuela', BO: 'Bolivia', CL: 'Chile', AR: 'Argentina', UY: 'Uruguay', PY: 'Paraguay', BR: 'Brasil', PR: 'Puerto Rico', CA: 'Canadá' };
// Día ISO (AAAA-MM-DD) en texto: «1 oct 2026». Es un día calendario, no un instante: se formatea en UTC.
const dayText = day => new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${day}T00:00:00Z`));
const dayBefore = day => { const d = new Date(`${day}T00:00:00Z`); d.setUTCDate(d.getUTCDate() - 1); return d.toISOString().slice(0, 10); };
// Pestañas accesibles (flechas, Inicio/Fin). add() devuelve el panel; remove() quita la pestaña y su panel.
function tabSet(parent, name, prefix) {
  const list = el('div', undefined, 'tabs'); list.setAttribute('role', 'tablist'); list.setAttribute('aria-label', name);
  const panels = el('div'); parent.append(list, panels);
  const activate = key => [...list.children].forEach(b => { const on = b.dataset.key === key; b.setAttribute('aria-selected', String(on)); b.tabIndex = on ? 0 : -1; document.getElementById(b.getAttribute('aria-controls')).hidden = !on; });
  list.onkeydown = e => { const buttons = [...list.children]; const i = buttons.indexOf(document.activeElement); let next; if (e.key === 'ArrowRight') next = (i + 1) % buttons.length; if (e.key === 'ArrowLeft') next = (i + buttons.length - 1) % buttons.length; if (e.key === 'Home') next = 0; if (e.key === 'End') next = buttons.length - 1; if (next !== undefined) { e.preventDefault(); buttons[next].click(); buttons[next].focus(); } };
  return {
    list,
    add(key, title) {
      const button = el('button', title); button.type = 'button'; button.id = `${prefix}-tab-${key}`; button.dataset.key = key; button.setAttribute('role', 'tab'); button.setAttribute('aria-controls', `${prefix}-panel-${key}`);
      const p = el('div', undefined, 'tab-panel'); p.id = `${prefix}-panel-${key}`; p.setAttribute('role', 'tabpanel'); p.setAttribute('aria-labelledby', button.id); p.tabIndex = 0;
      button.onclick = () => activate(key); list.append(button); panels.append(p); return p;
    },
    remove(key) { list.querySelector(`[data-key="${key}"]`)?.remove(); document.getElementById(`${prefix}-panel-${key}`)?.remove(); },
    keys: () => [...list.children].map(b => b.dataset.key),
    current: () => list.querySelector('[aria-selected="true"]')?.dataset.key,
    activate,
  };
}
async function pool(items, task, alive = () => true) {
  let index = 0;
  await Promise.all(Array.from({ length: Math.min(4, items.length) }, async () => { while (index < items.length && alive()) { const i = index++; await task(items[i], i); } }));
}

export const connectionLabel = state => ({ online: 'En línea', away: 'Ausente', offline: 'Desconectado' }[state] || 'Sin dato');
export function connectionState(devices) {
  const seen = devices.filter(d => d.status === 'active' && d.last_seen_at).map(d => Date.parse(d.last_seen_at));
  const age = seen.length ? Date.now() - Math.max(...seen) : Infinity;
  return age <= 300000 ? 'online' : age <= 900000 ? 'away' : 'offline';
}
export function periodControls(parent, today, shiftDay) {
  const preset = el('select'); preset.setAttribute('aria-label', 'Período');
  for (const [value, text] of [['today', 'Hoy'], ['week', '7 días'], ['month', 'Mes'], ['custom', 'Rango']]) { const o = el('option', text); o.value = value; preset.append(o); }
  const from = el('input'), to = el('input'); from.type = to.type = 'date'; from.required = to.required = true;
  const start = el('label', 'Desde'), end = el('label', 'Hasta (incluida)'); start.append(from); end.append(to); parent.append(preset, start, end);
  const apply = () => { to.value = today(); from.value = preset.value === 'month' ? today().slice(0, 8) + '01' : preset.value === 'week' ? shiftDay(today(), -6) : today(); };
  preset.onchange = () => { to.setCustomValidity(''); if (preset.value !== 'custom') apply(); };
  from.oninput = to.oninput = () => to.setCustomValidity(''); from.onchange = to.onchange = () => { to.setCustomValidity(''); preset.value = 'custom'; }; apply();
  return { range: () => {
    to.setCustomValidity('');
    if (!from.value || !to.value || from.value > to.value || (Date.parse(to.value) - Date.parse(from.value)) / 86400000 >= 31) { to.setCustomValidity('Elige un período de 1 a 31 días.'); to.reportValidity(); return null; }
    return { from: from.value, to: shiftDay(to.value, 1) };
  } };
}

export async function coordinator(ctx) {
  const { page, tenant, get, allPages, today, shiftDay, query } = ctx;
  const root = ctx.root || document.getElementById('content');
  const host = el('div', undefined, 'coordinator'); root.append(host);
  const alive = () => host.isConnected;
  const time = v => v ? new Intl.DateTimeFormat('es-CO', { timeZone: ctx.timezone, dateStyle: 'medium', timeStyle: 'short' }).format(new Date(v)) : 'Sin registro';
  const week = { from: shiftDay(today(), -6), to: shiftDay(today(), 1) };
  const devicesPath = `/tenants/${tenant}/devices`;
  const reportsDenied = [401, 403].includes(ctx.permissions.get('reports')?.status);
  const report = (kind, range, filters = {}) => get(query(`/reports/${kind}`, { ...range, ...filters }));
  const activity = (id, range) => get(query(`/users/${id}/activity`, range));
  // Totales de todas las personas en UNA consulta: antes era una por persona (~240) y agotaba el limite de la API.
  const people = async (range, filters = {}) => new Map(rows(await report('people', range, filters)).map(r => [r.user_id, r]));
  async function optional(path, paginated = true) {
    try { return { data: await (paginated ? allPages(path) : get(path)) }; }
    catch (e) { return { error: e }; }
  }
  async function section(parent, title, work) {
    const p = panel(parent, title); p.hidden = true;
    try { await work(p); p.hidden = false; }
    catch (e) { if (e.status === 403 || e.status === 401) { p.remove(); return; } p.replaceChildren(el('h2', title)); error(p, e); p.hidden = false; }
    return p;
  }
  function dependency(parent, result, title) { if (result.error && ![401, 403].includes(result.error.status)) { const p = el('div'); p.append(el('p', title)); error(p, result.error); parent.append(p); } }
  function orgName(org, id) { return org.data?.find(o => o.id === id)?.name || (org.error ? 'No disponible' : 'Sin nombre disponible'); }
  function selector(parent, title, key, records, textField = 'name') {
    const box = el('div'); const id = `filter-${key}`; const lbl = el('label', title); lbl.htmlFor = id; const select = el('select'); select.id = id; select.name = key; const any = el('option', 'Todos'); any.value = ''; select.append(any);
    records.forEach(r => { const o = el('option', r[textField]); o.value = r.id; select.append(o); }); box.append(lbl, select); parent.append(box); return select;
  }
  // Filtros del grupo: empresa interna y pais (si existen), luego sede y area. Sin filtro = todo el grupo.
  function orgFilters(parent, org, kinds = ['area', 'site']) {
    if (!org.data) return {};
    const out = {};
    const companies = org.data.filter(o => o.kind === 'company' && o.active);
    if (companies.length) out.company_id = selector(parent, 'Empresa', 'company', companies);
    const countries = [...new Set(org.data.filter(o => o.kind === 'site' && o.country_code).map(o => o.country_code))].sort();
    if (countries.length) out.country = selector(parent, 'País', 'country', countries.map(c => ({ id: c, name: COUNTRY_NAMES[c] || c })));
    const cities = [...new Set(org.data.filter(o => o.kind === 'site' && o.city).map(o => o.city))].sort((a, b) => a.localeCompare(b, 'es'));
    if (cities.length) out.city = selector(parent, 'Ciudad', 'city', cities.map(c => ({ id: c, name: c })));
    for (const kind of kinds) out[`${kind}_id`] = selector(parent, label(kind), kind, org.data.filter(o => o.kind === kind));
    return out;
  }
  // Filtros de Reportes en cadena: empresa → país → ciudad → sede → área → persona. Cada lista se acota con los datos ya
  // cargados (sedes del país/ciudad; sedes, áreas y personas vistas en las personas de la empresa elegida). Si una
  // relación no se puede verificar (sin lista de personas) no se acota: mejor de más que esconder una sede compartida.
  // Solo cambia lo que se ofrece en pantalla; el alcance de seguridad lo sigue aplicando la API.
  function reportFilters(parent, org, users) {
    const controls = {};
    const units = org.data || [], people = users.data || null;
    const sites = units.filter(o => o.kind === 'site'), areas = units.filter(o => o.kind === 'area');
    const companies = units.filter(o => o.kind === 'company' && o.active);
    const records = {};
    if (companies.length) { records.company_id = companies; controls.company_id = selector(parent, 'Empresa interna', 'company', companies); }
    const countries = [...new Set(sites.filter(o => o.country_code).map(o => o.country_code))].sort();
    if (countries.length) { records.country = countries.map(c => ({ id: c, name: COUNTRY_NAMES[c] || c })); controls.country = selector(parent, 'País', 'country', records.country); }
    const cities = [...new Set(sites.filter(o => o.city).map(o => o.city))].sort((a, b) => a.localeCompare(b, 'es'));
    if (cities.length) { records.city = cities.map(c => ({ id: c, name: c })); controls.city = selector(parent, 'Ciudad', 'city', records.city); }
    if (org.data) {
      records.site_id = sites; controls.site_id = selector(parent, 'Sede', 'site', sites);
      records.area_id = areas; controls.area_id = selector(parent, 'Área', 'area', areas);
    }
    if (people) { records.user_id = people; controls.user_id = selector(parent, 'Persona', 'user', people, 'display_name'); }
    const v = key => controls[key]?.value || '';
    const siteOf = id => sites.find(s => s.id === id);
    function fill(key, allowed, textField = 'name') {
      const select = controls[key]; if (!select) return;
      const keep = select.value;
      const list = records[key].filter(allowed);
      select.replaceChildren(Object.assign(el('option', 'Todos'), { value: '' }), ...list.map(r => Object.assign(el('option', r[textField]), { value: r.id })));
      select.value = list.some(r => r.id === keep) ? keep : '';
    }
    function narrow() {
      const company = v('company_id');
      fill('city', c => !v('country') || sites.some(s => s.city === c.id && s.country_code === v('country')));
      // Sedes de la empresa: las que tienen a la empresa como unidad superior o en las que trabaja alguien de ella.
      const companySites = company && people ? new Set([...sites.filter(s => s.parent_id === company).map(s => s.id), ...people.filter(u => u.company_id === company).map(u => u.site_id)]) : null;
      fill('site_id', s => (!v('country') || s.country_code === v('country')) && (!v('city') || s.city === v('city')) && (!companySites || companySites.has(s.id)));
      const placed = u => (!company || u.company_id === company) && (!v('site_id') || u.site_id === v('site_id'))
        && (!v('country') || siteOf(u.site_id)?.country_code === v('country')) && (!v('city') || siteOf(u.site_id)?.city === v('city'));
      const upstream = company || v('site_id') || v('country') || v('city');
      const areaIds = people && upstream ? new Set(people.filter(placed).map(u => u.area_id)) : null;
      fill('area_id', a => !areaIds || areaIds.has(a.id));
      fill('user_id', u => placed(u) && (!v('area_id') || u.area_id === v('area_id')), 'display_name');
    }
    function describe() {
      const names = { company_id: 'Empresa interna', country: 'País', city: 'Ciudad', site_id: 'Sede', area_id: 'Área', user_id: 'Persona' };
      return Object.entries(controls).filter(([, c]) => c.value).map(([k, c]) => `${names[k]}: ${c.selectedOptions[0]?.textContent || c.value}`);
    }
    return { controls, narrow, describe };
  }
  function selected(controls) { return Object.fromEntries(Object.entries(controls).filter(([, v]) => v.value).map(([k, v]) => [k, v.value])); }
  const productColumns = [['Día', 'day'], ['En uso', r => hours(r.active_seconds)], ['Encendido sin uso', r => hours(r.idle_seconds)], ['Bloqueado (h)', r => numeric(r.locked_seconds) ? fmt.format(r.locked_seconds / 3600) : 'Sin dato'], ['Productividad', r => percent(r.productivity_percent)], ['Focus /100', r => numeric(r.focus_score) ? fmt.format(r.focus_score) : 'Sin dato'], ['Entrada simulada (h)', r => numeric(r.simulated_seconds) ? fmt.format(r.simulated_seconds / 3600) : 'Sin dato']];
  // Agente 4.0.22+: entrada que Windows registra sin teclado ni ratón físicos (jiggler, DWService); ya va en "sin uso".
  function simulatedNotice(p, seconds) {
    if (numeric(seconds) && seconds > 1800) p.append(el('p', `Entrada simulada en el período: ${hours(seconds)}. Windows registró actividad sin teclado ni ratón físicos (un "mouse jiggler" o control remoto como DWService); ese tiempo se cuenta como encendido sin uso, no como uso real.`, 'notice'));
  }
  const appColumns = [['Aplicación', r => r.app || r.process_name], ['En uso', r => hours(r.active_seconds)], ['Abierta sin uso', r => hours(r.idle_seconds)], ['Veces al frente', 'sessions'], ['Personas', 'users_count'], ['Del tiempo total', r => percent(r.percent_total)]];
  const presenceColumns = [['Persona', r => memberLink(r)], ['Día', 'day'], ['Primera actividad', r => time(r.first_activity)], ['Última actividad', r => time(r.last_activity)], ['Activo', r => hours(r.active_seconds)], ['Retraso (min)', r => numeric(r.late_seconds) ? fmt.format(r.late_seconds / 60) : 'Sin dato'], ['Estado', r => label(r.status)]];
  async function apps(parent, range, filters = {}, exportable = false, fetch = report) {
    return section(parent, 'Aplicaciones más usadas', async p => { const data = await fetch('apps', range, { ...filters, limit: exportable ? 100 : 10 }); const list = rows(data); p.append(el('p', `"En uso": la ventana estaba al frente y había teclado o ratón. "Abierta sin uso": al frente pero sin tocar el equipo. "Veces al frente": cuántas veces se pasó a esa aplicación. Ordenado por tiempo en uso.`, 'help')); pagedTable(p, exportable ? 'Top 100 aplicaciones' : 'Top 10 aplicaciones', appColumns, list); if (exportable) csvButton(p, `apps-${range.from}-${dayBefore(range.to)}`, appColumns, list); });
  }
  function productivity(p, data) {
    const series = rows(data, 'series');
    // Promedio diario: media simple de los días con dato (no es un ratio del período ponderado por horas).
    const avg = field => { const values = series.filter(r => numeric(r[field])); return values.length ? values.reduce((a, r) => a + r[field], 0) / values.length : null; };
    const daysWith = field => series.filter(r => numeric(r[field])).length;
    // Etiquetas con su formula: "horas activas" se leia como "equipo encendido" y la cobertura no se entendia.
    metrics(p, [['Uso real', series.length ? hours(series.reduce((a, r) => a + r.active_seconds, 0)) : 'Sin datos', `Suma del período: tiempo con teclado o ratón en uso. Equipo encendido y registrando: ${series.length ? hours(series.reduce((a, r) => a + r.active_seconds + r.idle_seconds, 0)) : 'sin datos'}.`],
      ['Promedio diario de productividad', percent(avg('productivity_percent')), `Suma de la productividad de cada día con dato ÷ días con dato (${daysWith('productivity_percent')}). La de cada día = uso en apps productivas ÷ uso total de ese día. Todos los días pesan igual, aunque uno tenga más horas que otro; los días sin dato no cuentan.`],
      ['Promedio diario de distracción', percent(avg('distraction_percent')), `Suma de la distracción de cada día con dato ÷ días con dato (${daysWith('distraction_percent')}). La de cada día = uso en apps poco productivas ÷ uso total de ese día.`],
      ['Focus: promedio diario', numeric(avg('focus_score')) ? `${fmt.format(avg('focus_score'))} /100` : 'Sin dato', `Suma del focus de cada día con dato ÷ días con dato (${daysWith('focus_score')}). El de cada día = tiempo productivo en bloques de 25 min o más sin distraerse ÷ tiempo productivo. Responde: ¿el trabajo fue sostenido o a ratos?`],
      ['Registro vs. jornada', series.length ? percent(data.coverage_percent) : 'Sin datos', 'Tiempo con el equipo encendido y Keeper registrando ÷ horas de jornada esperadas (horario menos festivos y ausencias). Bajo = equipos apagados o sin reportar.']]);
    simulatedNotice(p, series.reduce((a, r) => a + (numeric(r.simulated_seconds) ? r.simulated_seconds : 0), 0));
    const unclassified = avg('unclassified_percent');
    if (numeric(unclassified) && unclassified > 20) { const n = el('p', undefined, 'notice'); n.append(`El ${percent(unclassified)} del uso está en apps sin clasificar: productividad y distracción son poco confiables. `, link('Clasificar aplicaciones', '/clasificacion.php')); p.append(n); }
    // La API responde con el fin excluido; se muestra el último día incluido.
    p.append(el('p', `Del ${dayText(data.from)} al ${dayText(dayBefore(data.to))} (ambos incluidos) · Zona horaria: ${data.timezone || ctx.timezone} · Datos hasta: ${time(data.data_through)}`, 'help')); chart(p, series);
    return series;
  }
  async function migration(parent, devices) {
    if (!devices.length) return;
    const first = await optional(`/devices/${devices[0].id}/migration`, false);
    if ([401, 403].includes(first.error?.status)) return;
    const p = panel(parent, 'Migraciones pendientes');
    const status = el('p', '', 'help'); status.setAttribute('role', 'status'); const list = el('div'); const more = el('button', 'Revisar siguientes 20 equipos'); more.type = 'button';
    p.append(el('p', 'Revisión por lotes de 20 equipos. Solo se cuentan los estados consultados.', 'help'), status, list, more);
    let offset = 0; const pending = []; let failed = 0;
    async function batch() {
      more.disabled = true; const slice = devices.slice(offset, offset + 20);
      await pool(slice, async d => {
        const result = d.id === devices[0].id ? first : await optional(`/devices/${d.id}/migration`, false);
        if (result.error) { failed++; if (![401, 403].includes(result.error.status)) error(p, result.error); }
        else if (result.data.phase !== 'completo') pending.push({ ...d, phase: result.data.phase });
      }, alive);
      offset += slice.length; status.textContent = `${offset} de ${devices.length} consultados · ${pending.length} pendientes · ${failed} sin resultado. No representa el total hasta terminar la revisión.`;
      list.replaceChildren(); pagedTable(list, 'Pendientes entre los equipos revisados', [['Equipo', 'hostname'], ['Fase', r => r.phase ? label(r.phase) : 'Sin iniciar'], ['Revisar', r => link('Ver ficha', `/miembro.php?id=${encodeURIComponent(r.user_id)}#equipos`)]], pending);
      more.disabled = false; more.hidden = offset >= devices.length;
    }
    more.onclick = batch; await batch();
  }
  if (page === 'home') {
    const homeOrg = await optional('/organization');
    const bar = el('form', undefined, 'toolbar'); const controls = orgFilters(bar, homeOrg);
    const period = periodControls(bar, today, shiftDay); const submit = el('button', 'Consultar', 'primary'); submit.type = 'submit'; bar.append(submit);
    const body = el('div'); host.append(bar, body);
    let cycle = 0;
    const draw = async () => {
      const run = ++cycle; const range = period.range(); if (!range) return;
      submit.disabled = true; body.replaceChildren(); const f = selected(controls);
      try {
        const dayRange = { from: today(), to: shiftDay(today(), 1) };
        const dashboard = r => api('GET', query('/dashboard', { ...r, ...f }));
        const main = dashboard(range);
        const [data, current, presence] = await Promise.all([
          main, range.from === dayRange.from && range.to === dayRange.to ? main : dashboard(dayRange), api('GET', query('/reports/presence', { ...dayRange, ...f })).catch(e => { if ([401, 403].includes(e.status)) return null; throw e; }),
        ]);
        if (run !== cycle || !alive()) return;
        const arrivals = el('ul', undefined, 'arrival-counts');
        for (const [state, name] of [['a_tiempo', 'A tiempo'], ['tarde', 'Tarde'], ['temprano', 'Temprano'], ['sin_actividad', 'Sin actividad']]) arrivals.append(el('li', `${name}: ${presence ? rows(presence).filter(r => r.status === state).length : 'No disponible'}`));
        metrics(body, [['Activos / total', `${current.online_members} / ${current.total_members}`], ['Sedes activas', `${current.active_sites ?? '—'} / ${current.total_sites ?? '—'}`, 'Activas / total'], ['Productividad en tiempo real', percent(current.productivity_percent), 'Hoy · consulta cada 60 segundos'], ['Focus en tiempo real', numeric(current.focus_score) ? `${fmt.format(current.focus_score)} /100` : 'Sin dato', 'Hoy · sujeto al cálculo de productividad'], ['Llegadas', arrivals], ['Horas de hoy', hours(current.active_seconds)], ['Primer ingreso', presence ? time(rows(presence).map(r => r.first_activity).filter(Boolean).sort()[0]) : 'No disponible']]);
        await section(body, 'Problemas reportados', async p => {
          const problems = await api('GET', '/dashboard/problems');
          pagedTable(p, 'Problemas en tu alcance', [['Problema', r => link(r.label, r.device_id ? `/equipo.php?id=${r.device_id}` : r.user_id ? `/miembro.php?id=${r.user_id}` : '/admin/salud.php')], ['Persona', r => r.display_name || 'Empresa'], ['Equipo', r => r.hostname || '—']], rows(problems), 20);
        });
        const live = panel(body, 'Personal activo ahora');
        live.append(el('p', 'En línea: señal hasta hace 5 minutos · Ausente: entre 5 y 15 minutos · Desconectado: más de 15 minutos. Es conexión del equipo, no prueba de presencia humana.', 'help'));
        pagedTable(live, 'Personal y equipos', [['Persona', memberLink], ['Equipo', r => r.devices || 'Sin equipo activo'], ['Conexión', r => connectionLabel(r.connection_status)], ['Última señal', r => time(r.last_seen_at)]], data.roster, 20);
        if (data.total_members > data.roster.length) live.append(link(`Ver las ${data.total_members} personas`, '/usuarios.php'));
        const pulse = panel(body, 'Productividad y focus del período'); chart(pulse, data.weekly_output);
        pulse.append(link('Ver detalle de productividad', '/reportes.php'));
        const ranked = rows(await report('people', range, f)).sort((a, b) => b.totals.active_seconds - a.totals.active_seconds).slice(0, 5);
        const top = panel(body, 'Top personas del período');
        pagedTable(top, 'Personas por tiempo en uso', [['Persona', memberLink], ['Tiempo en uso', r => hours(r.totals.active_seconds)], ['Productividad', r => percent(r.totals.productivity_percent)], ['Focus', r => percent(r.totals.focus_score)]], ranked);
        await apps(body, range, f);
      } catch (e) { if (run === cycle) error(body, e); }
      finally { if (run === cycle) submit.disabled = false; }
    };
    bar.onsubmit = e => { e.preventDefault(); draw(); }; await draw();
    const refresh = setInterval(() => { if (!alive()) { clearInterval(refresh); return; } if (!submit.disabled && !document.hidden) draw(); }, 60000);
    window.addEventListener('pagehide', () => clearInterval(refresh), { once: true });
  } else if (page === 'users') {
    const [users, org, devices] = await Promise.all([allPages('/users'), optional('/organization'), optional(devicesPath)]);
    const p = panel(host, 'Personas de la empresa'); dependency(p, org, 'Organización no disponible'); dependency(p, devices, 'Equipos no disponibles');
    const bar = el('form', undefined, 'toolbar'); const controls = orgFilters(bar, org);
    const searchBox = el('div'); const lbl = el('label', 'Buscar nombre o correo'); lbl.htmlFor = 'user-search'; const search = el('input'); search.id = 'user-search'; search.type = 'search'; searchBox.append(lbl, search); bar.append(searchBox); bar.onsubmit = e => e.preventDefault(); p.append(bar);
    p.append(el('p', 'Métricas de los últimos 7 días. La última actividad registrada no equivale a un acceso al portal.', 'help'));
    const view = el('div'); p.append(view); let offset = 0, version = 0;
    async function render() {
      const current = ++version; const f = selected(controls), term = search.value.trim().toLocaleLowerCase('es');
      const siteOf = u => org.data?.find(o => o.id === u.site_id);
      const fold = v => String(v ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
      const matches = users.filter(u => Object.entries(f).every(([k, v]) => k === 'country' ? siteOf(u)?.country_code === v : k === 'city' ? fold(siteOf(u)?.city) === fold(v) : u[k] === v) && `${u.display_name} ${u.email || ''}`.toLocaleLowerCase('es').includes(term));
      view.replaceChildren(); const data = matches.slice(offset, offset + 25); if (!data.length) { empty(view, 'No hay personas que coincidan con los filtros.'); return; }
      const grid = el('div'); view.append(grid); const prev = el('button', 'Anterior'), next = el('button', 'Siguiente'); prev.type = next.type = 'button'; prev.disabled = !offset; next.disabled = offset + 25 >= matches.length;
      const pager = el('div', undefined, 'pager'); const state = el('p', `${offset + 1}–${Math.min(offset + 25, matches.length)} de ${matches.length}`, 'help'); state.setAttribute('role', 'status'); pager.append(prev, state, next); view.append(pager);
      prev.onclick = () => { offset -= 25; render(); }; next.onclick = () => { offset += 25; render(); };
      const enriched = data.map(u => ({ ...u, metric: null, pending: !reportsDenied }));
      const draw = () => {
        grid.replaceChildren(); const visible = enriched.some(u => u.metric || (u.failure && u.failure.status !== 403) || u.pending);
        const failed = enriched.filter(u => u.failure && ![401, 403].includes(u.failure.status));
        if (failed.length) { grid.append(el('p', `${failed.length} personas con métricas no disponibles.`, 'help')); error(grid, failed[0].failure); }
        const cols = [['Persona', memberLink]];
        if (![401, 403].includes(org.error?.status)) cols.push(['Área', u => orgName(org, u.area_id)], ['Sede', u => orgName(org, u.site_id)]);
        if (devices.data) cols.push(['Equipos', u => devices.data.filter(d => d.user_id === u.id && d.status === 'active').map(d => d.hostname).join(', ') || 'Sin equipo activo']);
        if (visible) cols.push(['Productividad', u => u.pending ? 'Consultando…' : u.failure ? (u.failure.status === 403 ? 'No disponible' : `Error: ${errorMessage(u.failure)}`) : percent(u.metric?.totals.productivity_percent)], ['Focus /100', u => u.pending ? 'Consultando…' : numeric(u.metric?.totals.focus_score) ? fmt.format(u.metric.totals.focus_score) : 'Sin dato'], ['Última actividad (7 días)', u => u.metric ? time(u.metric.last_activity) : 'No disponible']);
        pagedTable(grid, 'Directorio de personas', cols, enriched);
      }; draw();
      if (reportsDenied) return;
      let totals = null, failure = null;
      try { totals = await people(week); } catch (e) { failure = e; }
      for (const u of enriched) { u.pending = false; if (failure) u.failure = failure; else u.metric = totals.get(u.id) || { totals: {}, last_activity: null }; }
      if (current === version && alive()) draw();
    }
    Object.values(controls).forEach(c => c.onchange = () => { offset = 0; render(); }); let timer; search.oninput = () => { clearTimeout(timer); timer = setTimeout(() => { offset = 0; render(); }, 250); }; await render();
  } else if (page === 'reports') {
    // Revisión UX M10: fechas inclusivas (la API recibe `to` = día siguiente), pestañas por tipo de reporte, filtros
    // dependientes armados con los datos ya cargados y un estado «sin consultar» hasta que se pulsa Consultar.
    const form = document.getElementById('date-form');
    const fromInput = document.getElementById('from'), toInput = document.getElementById('to');
    const submit = form.querySelector('button[type=submit]');
    form.querySelectorAll('.report-filters, .report-presets').forEach(n => n.remove());
    if (form.dataset.tenant !== tenant) { delete form.dataset.filters; delete form.dataset.queried; }
    form.dataset.tenant = tenant;
    // Enlace del icono de notificaciones (?desde=…&hasta=…&pestana=presencia&estado=tarde): la primera consulta ya va filtrada.
    const params = new URLSearchParams(location.search), isDay = v => /^\d{4}-\d{2}-\d{2}$/.test(v || '');
    let preset = !form.dataset.queried && isDay(params.get('desde')) && isDay(params.get('hasta')) ? { tab: params.get('pestana'), status: params.get('estado') } : null;
    if (preset) { fromInput.value = params.get('desde'); toInput.value = params.get('hasta'); }
    const [org, users] = await Promise.all([optional('/organization'), optional('/users')]);
    const presets = el('div', undefined, 'report-presets'); presets.setAttribute('role', 'group'); presets.setAttribute('aria-label', 'Períodos rápidos');
    const end = today();
    for (const [text, start] of [['Hoy', end], ['Últimos 7 días', shiftDay(end, -6)], ['Últimos 30 días', shiftDay(end, -29)], ['Este mes', `${end.slice(0, 8)}01`]]) {
      const b = el('button', text); b.type = 'button'; b.onclick = () => { fromInput.value = start; toInput.value = end; sync(); }; presets.append(b);
    }
    const filters = el('div', undefined, 'toolbar report-filters');
    const chain = reportFilters(filters, org, users);
    const controls = chain.controls;
    const previous = JSON.parse(form.dataset.filters || '{}');
    for (const [k, c] of Object.entries(controls)) { chain.narrow(); c.value = previous[k] || ''; }
    chain.narrow();
    form.append(presets, filters);
    dependency(host, org, 'Filtros de organización no disponibles'); dependency(host, users, 'Filtro de personas no disponible');
    host.append(el('p', `Las pestañas y el CSV reflejan el período y los filtros consultados. Días y horas en la zona horaria de la empresa: ${ctx.timezone}.`, 'help'));
    const state = el('p', undefined, 'notice report-state'); state.setAttribute('role', 'status');
    const results = el('div', undefined, 'report-results');
    host.append(state, results);
    const snapshot = () => JSON.stringify({ from: fromInput.value, to: toInput.value, filters: selected(controls) });
    let executed = null, runs = 0, tabs = null;
    function showState(text, kind) { state.textContent = text; state.className = `notice report-state${kind ? ` ${kind}` : ''}`; state.hidden = !text; }
    function sync() {
      chain.narrow();
      form.dataset.filters = JSON.stringify(selected(controls));
      if (!executed) showState('Sin consultar: elige el período y los filtros y pulsa «Consultar».', 'pending');
      else if (snapshot() !== executed.key) showState('Filtros modificados: pulsa «Consultar» para actualizar. Los resultados de abajo siguen siendo los de la consulta anterior.', 'stale');
      else showState(`Resultados de la consulta de las ${executed.at}.`, '');
    }
    form.oninput = sync; form.onchange = sync;
    async function run() {
      const from = fromInput.value, last = toInput.value;
      const days = (Date.parse(last) - Date.parse(from)) / 86400000 + 1;
      if (!from || !last || !(days >= 1 && days <= 31)) { showState('Elige un período de 1 a 31 días: «Desde» no puede ser posterior a «Hasta» y las dos fechas se incluyen.', 'invalid'); return; }
      const version = ++runs;
      const range = { from, to: shiftDay(last, 1) }, values = selected(controls);
      executed = { key: snapshot(), at: null }; form.dataset.queried = '1';
      const keep = tabs?.current() || (preset && ['resumen', 'aplicaciones', 'presencia'].includes(preset.tab) ? preset.tab : null);
      const presenceStatus = preset?.status || ''; preset = null;
      submit.disabled = true; showState('Consultando…', '');
      results.replaceChildren();
      const described = chain.describe();
      results.append(el('p', `Período consultado: del ${dayText(from)} al ${dayText(last)} (${days} ${days === 1 ? 'día' : 'días'}, ambos incluidos) · Zona horaria: ${ctx.timezone} · ${described.length ? `Filtros: ${described.join(' · ')}` : 'Sin filtros: todo tu alcance'}`, 'report-query'));
      tabs = tabSet(results, 'Tipo de reporte', 'report');
      const fresh = (kind, r, f) => api('GET', query(`/reports/${kind}`, { ...r, ...f }), { tenant });
      const summary = tabs.add('resumen', 'Resumen'), appsTab = tabs.add('aplicaciones', 'Aplicaciones'), presenceTab = tabs.add('presencia', 'Presencia');
      const overview = el('div'), trendTab = el('div'), rankTab = el('div'); summary.append(overview, trendTab, rankTab);
      tabs.activate(keep || 'resumen');
      try {
        await Promise.all([
          section(overview, 'Productividad', async p => { const data = await fresh('productivity', range, values); const list = productivity(p, data); const daily = el('details'); daily.append(el('summary', 'Ver serie diaria y exportar')); p.append(daily); pagedTable(daily, 'Serie diaria', productColumns, list); csvButton(daily, `productividad-${from}-${last}`, productColumns, list); }),
          section(rankTab, 'Ranking individual', async p => {
            const data = rows(await fresh('people', range, values)).map(r => ({ ...r, ...r.totals, area: org.data?.find(o => o.id === users.data?.find(u => u.id === r.user_id)?.area_id)?.name || 'Sin área disponible' }));
            const average = field => { const measured = data.map(r => r[field]).filter(numeric); return measured.length ? measured.reduce((a, b) => a + b, 0) / measured.length : null; };
            metrics(p, [['Personas con actividad', data.filter(r => r.active_seconds > 0).length], ['Constancia promedio', percent(average('constancy_pct'))], ['Trabajo profundo promedio', hours(average('deep_work_seconds'))], ['Cambios de aplicación promedio', numeric(average('context_switches')) ? fmt.format(average('context_switches')) : 'Sin dato']]);
            const bar = el('div', undefined, 'toolbar'); const order = selector(bar, 'Ordenar por', 'ranking', [
              { id: 'productivity_percent', name: 'Productividad' }, { id: 'focus_score', name: 'Focus' }, { id: 'constancy_pct', name: 'Constancia' },
              { id: 'deep_work_seconds', name: 'Trabajo profundo' }, { id: 'context_switches', name: 'Cambios' }, { id: 'days_with_activity', name: 'Días' }, { id: 'area', name: 'Área' }, { id: 'display_name', name: 'Persona' },
            ]); order.querySelector('option').remove(); order.value = 'productivity_percent';
            const direction = selector(bar, 'Orden', 'ranking-direction', [{ id: 'desc', name: 'Mayor a menor' }, { id: 'asc', name: 'Menor a mayor' }]); direction.querySelector('option').remove(); direction.value = 'desc';
            const view = el('div'); p.append(bar, view);
            const columns = [['Persona', memberLink], ['Área actual', 'area'], ['Productividad', r => percent(r.productivity_percent)], ['Focus', r => percent(r.focus_score)], ['Constancia', r => percent(r.constancy_pct)], ['Trabajo profundo', r => hours(r.deep_work_seconds)], ['Cambios', 'context_switches'], ['Días con actividad', 'days_with_activity']];
            const draw = () => {
              const key = order.value, sign = direction.value === 'asc' ? 1 : -1;
              const sorted = [...data].sort((a, b) => a[key] == null ? (b[key] == null ? 0 : 1) : b[key] == null ? -1 : sign * (typeof a[key] === 'string' ? a[key].localeCompare(b[key], 'es') : a[key] - b[key]));
              view.replaceChildren(); pagedTable(view, 'Personas por productividad', columns, sorted, 25); csvButton(view, `ranking-${from}-${last}`, columns, sorted);
            }; order.onchange = direction.onchange = draw; draw();
            const areas = new Map();
            for (const r of data) { if (!areas.has(r.area)) areas.set(r.area, { area: r.area, people: 0, active: 0, productive: 0, base: 0, focus: 0 }); const a = areas.get(r.area); a.people++; a.active += r.active_seconds; a.productive += r.productive_seconds; a.base += r.window_active_seconds || r.active_seconds; a.focus += r.focus_seconds; }
            const ranked = [...areas.values()].map(a => ({ ...a, productivity: a.base ? Math.min(100, 100 * a.productive / a.base) : null, score: a.productive ? Math.min(100, 100 * a.focus / a.productive) : null })).sort((a, b) => (b.productivity ?? -1) - (a.productivity ?? -1));
            pagedTable(p, 'Ranking por área actual', [['Área', 'area'], ['Personas', 'people'], ['Tiempo en uso', a => hours(a.active)], ['Productividad', a => percent(a.productivity)], ['Focus', a => percent(a.score)]], ranked, 25);
            p.append(el('p', 'El área corresponde a la asignación actual. Constancia solo se muestra cuando existe una medición; no se sustituye por cero.', 'help'));
          }),
          section(trendTab, 'Tendencia de 8 semanas', async p => {
            const start = shiftDay(range.to, -56), middle = shiftDay(range.to, -28);
            const chunks = await Promise.all([fresh('productivity', { from: start, to: middle }, values), fresh('productivity', { from: middle, to: range.to }, values)]);
            const daily = chunks.flatMap(c => c.series), weekly = [];
            for (let i = 0; i < 8; i++) {
              const day = shiftDay(start, i * 7), end = shiftDay(day, 7), days = daily.filter(d => d.day >= day && d.day < end);
              const mean = key => { const values = days.map(d => d[key]).filter(numeric); return values.length ? values.reduce((a, b) => a + b, 0) / values.length : null; };
              weekly.push({ day, productivity_percent: mean('productivity_percent'), focus_score: mean('focus_score') });
            }
            p.append(el('p', `Ocho períodos de siete días hasta ${dayText(last)}. Cada punto es el promedio de los días medidos de esa semana; los huecos indican ausencia de mediciones.`, 'help'));
            chart(p, weekly, 'semana');
            pagedTable(p, 'Promedio semanal', [['Semana desde', 'day'], ['Productividad', r => percent(r.productivity_percent)], ['Focus', r => percent(r.focus_score)]], weekly);
          }),
          apps(appsTab, range, values, true, fresh),
          section(presenceTab, 'Presencia', async p => {
            const list = rows(await fresh('presence', range, values));
            p.append(el('p', `Los estados salen del horario de cada persona y de su calendario de festivos: un festivo o una ausencia aprobada cuentan como «No laborable». Horas en la zona horaria de la empresa (${ctx.timezone}).`, 'help'));
            // Filtro por estado (p. ej. solo «Tarde» al llegar desde el icono de notificaciones).
            const bar = el('div', undefined, 'toolbar'); const status = selector(bar, 'Estado', 'presence-status', ['tarde', 'sin_conectarse', 'a_tiempo', 'temprano', 'sin_actividad', 'actividad_sin_hora', 'no_laborable'].map(id => ({ id, name: label(id) })));
            // «Sin conectarse hoy»: sin actividad y ya pasó su hora límite de llegada (entrada + margen; la calcula la API).
            const matches = (r, v) => v === 'sin_conectarse' ? r.status === 'sin_actividad' && !!r.arrival_deadline_at && Date.parse(r.arrival_deadline_at) <= Date.now() : r.status === v;
            if ([...status.options].some(o => o.value === presenceStatus)) status.value = presenceStatus;
            const view = el('div'); p.append(bar, view);
            const draw = () => { const shown = status.value ? list.filter(r => matches(r, status.value)) : list; view.replaceChildren(); pagedTable(view, status.value ? `Presencia diaria · ${label(status.value)}` : 'Presencia diaria', presenceColumns, shown); csvButton(view, `presencia-${from}-${last}`, presenceColumns, shown); };
            status.onchange = draw; draw();
          }),
        ]);
      } finally { if (version === runs) submit.disabled = false; }
      if (version !== runs || !alive()) return;
      // Sin permiso para un tipo de reporte su sección desaparece: también su pestaña.
      for (const [key, p] of [['resumen', summary], ['aplicaciones', appsTab], ['presencia', presenceTab]]) if (!p.childElementCount) tabs.remove(key);
      if (!tabs.keys().length) empty(results, 'Tu cuenta no tiene acceso a estos reportes.');
      else if (!tabs.current()) tabs.activate(tabs.keys()[0]);
      executed.at = new Intl.DateTimeFormat('es-CO', { timeStyle: 'short' }).format(new Date()).replace(/\.$/, '');
      sync();
    }
    form.onsubmit = event => { event.preventDefault(); if (alive()) run(); };
    await run();
  } else if (page === 'member') {
    // La ficha mantiene una sola navegación; aquí se carga únicamente la vista elegida.
    const id = ctx.user?.id || new URLSearchParams(location.search).get('id'); if (!isUuid(id)) throw new Error('Selecciona una persona desde Personas.');
    const u = ctx.user || await get(`/users/${id}`);
    const activeTab = ctx.subtab || 'resumen';
    const rangeBar = el('form', undefined, 'toolbar'); const period = periodControls(rangeBar, today, shiftDay);
    const submit = el('button', 'Consultar', 'primary'); submit.type = 'submit'; rangeBar.append(submit); if (['resumen', 'ventanas', 'presencia'].includes(activeTab)) host.append(rangeBar);
    const kpis = el('div'); host.append(kpis);
    const panels = el('div'); host.append(panels); let cycle = 0;
    async function renderMember() {
      const range = period.range(); if (!range) return; const version = ++cycle;
      submit.disabled = true; kpis.replaceChildren(); panels.replaceChildren();
      const [act, devices, schedules, month] = await Promise.all([
        ['resumen', 'presencia'].includes(activeTab) ? optional(query(`/users/${id}/activity`, range), false) : {},
        ['resumen', 'controles'].includes(activeTab) ? optional(query(devicesPath, { user_id: id })) : {},
        activeTab === 'resumen' ? optional('/schedules') : {},
        activeTab === 'resumen' ? optional(query(`/users/${id}/activity`, { from: today().slice(0, 8) + '01', to: shiftDay(today(), 1) }), false) : {},
      ]);
      if (!alive() || cycle !== version) return;
      const canActivity = ![401, 403].includes(act.error?.status);
      if (activeTab === 'resumen' && act.data) {
        rows(act.data); const days = act.data.data, t = act.data.totals; if (!t) throw invalid(); const punctual = days.filter(d => ['a_tiempo', 'temprano', 'tarde'].includes(d.status));
        metrics(kpis, [['Primera actividad del período', time(days.map(d => d.first_activity).filter(Boolean).sort()[0])], ['Horas activas', days.length ? hours(t.active_seconds) : 'Sin datos'], ['Productividad', percent(t.productivity_percent)], ['Focus /100', numeric(t.focus_score) ? fmt.format(t.focus_score) : 'Sin dato'], ['Puntualidad', punctual.length ? percent(punctual.filter(d => d.status !== 'tarde').length * 100 / punctual.length) : 'Sin dato', 'Sobre días laborables con actividad']]);
      } else if (activeTab === 'resumen' && canActivity) error(kpis, act.error);
      else if (!canActivity) kpis.append(el('p', 'Tu cuenta no puede consultar la actividad de esta persona (requiere el permiso de ver reportes).', 'notice'));
      function tab(key, title) {
        const p = panel(panels, title); p.id = `panel-${key}`; return p;
      }
      if (activeTab === 'resumen') {
        const summary = tab('resumen', 'Resumen');
        if (devices.data) details(summary, [['Conexión', connectionLabel(connectionState(devices.data))], ['Equipos activos', devices.data.filter(d => d.status === 'active').map(d => `${d.hostname} · v${d.agent_version} · Visto: ${time(d.last_seen_at)}`).join(', ') || 'Sin equipo activo']]); else dependency(summary, devices, 'Equipos');
        if (schedules.data) { const s = schedules.data.find(s => s.id === (u.effective_schedule_id || u.schedule_id)); details(summary, [['Horario', s ? `${s.name} · ${s.start_local}–${s.end_local} · ${s.timezone}` : 'Sin horario disponible'], ['Días', s ? s.days.map(d => ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][d - 1]).join(', ') : 'Sin dato']]); } else dependency(summary, schedules, 'Horario');
        if (act.data) { simulatedNotice(summary, act.data.totals.simulated_seconds); chart(summary, act.data.data); details(summary, [['Trabajo profundo', hours(act.data.totals.deep_work_seconds)], ['Distracción', hours(act.data.totals.distraction_seconds)], ['Cambios de contexto', act.data.totals.context_switches]]); }
        if (act.data) {
          const t = act.data.totals;
          metrics(summary, [['En uso', hours(t.active_seconds)], ['Inactivo', hours(t.idle_seconds)], ['Bloqueado', hours(t.locked_seconds)], ['Entrada simulada', hours(t.simulated_seconds)], ['Constancia', percent(t.constancy_pct)]]);
          const split = panel(summary, 'Desglose de tiempo del período');
          details(split, [['Horario laboral (en uso)', hours(t.work_hours_active_seconds)], ['Almuerzo (en uso)', hours(t.lunch_active_seconds)], ['Fuera de horario (en uso)', hours(t.after_hours_active_seconds)], ['En llamadas', hours(t.call_seconds)]]);
          split.append(el('p', 'Las llamadas ya forman parte del tiempo en uso. La entrada simulada está incluida en inactivo. «Sin dato» indica que el agente no midió la franja completa.', 'help'));
        }
        if (month.data) {
          const t = month.data.totals, days = month.data.data.filter(d => d.active_seconds > 0).length;
          const monthly = panel(summary, 'Resumen del mes');
          metrics(monthly, [['Total en uso', hours(t.active_seconds)], ['En horario laboral', hours(t.work_hours_active_seconds)], ['Promedio por día con actividad', days ? hours(t.active_seconds / days) : 'Sin dato']]);
        } else dependency(summary, month, 'Resumen del mes');
        if (canActivity) await apps(summary, range, { user_id: id });
        if (act.data) {
          const daily = panel(summary, 'Actividad diaria');
          const columns = [...productColumns, ['Horario laboral', r => hours(r.work_hours_active_seconds)], ['Almuerzo', r => hours(r.lunch_active_seconds)], ['Fuera de horario', r => hours(r.after_hours_active_seconds)], ['Llamadas', r => hours(r.call_seconds)], ['Primer ingreso', r => time(r.first_activity)], ['Último evento', r => time(r.last_activity)]];
          pagedTable(daily, 'Actividad diaria', columns, act.data.data);
          csvButton(daily, `actividad-${id}-${range.from}`, columns, act.data.data);
          daily.append(el('p', 'Las llamadas son parte del tiempo activo; no se suman de nuevo.', 'help'));
        }
      }
      if (activeTab === 'ventanas') {
        const windows = tab('ventanas', 'Ventanas visitadas');
        await section(windows, 'Aplicaciones y sitios', async p => {
          const data = rows(await get(query(`/users/${id}/windows`, range)));
          const select = el('select'); select.setAttribute('aria-label', 'Ordenar ventanas');
          const columns = [['App', 'process_name'], ['Sitio', 'site'], ['En uso', 'active_seconds'], ['Abierta sin uso', 'idle_seconds'], ['Veces al frente', 'sessions']];
          columns.forEach(([title, key]) => select.append(new Option(title, key)));
          select.value = 'active_seconds'; const direction = el('button', 'Descendente'); direction.type = 'button'; let asc = false;
          const view = el('div'); p.append(select, direction, view);
          const draw = () => { view.replaceChildren(); const key = select.value; const sorted = [...data].sort((a, b) => (typeof a[key] === 'number' ? a[key] - b[key] : String(a[key] || '').localeCompare(String(b[key] || ''), 'es')) * (asc ? 1 : -1)); pagedTable(view, 'Ventanas · solo app y sitio', columns.map(([title, key]) => [title, r => key.endsWith('seconds') ? hours(r[key]) : r[key] || '—']), sorted, 25); };
          select.onchange = draw; direction.onclick = () => { asc = !asc; direction.textContent = asc ? 'Ascendente' : 'Descendente'; draw(); }; draw();
        });
      }
      if (activeTab === 'linea') {
        const timeline = tab('linea', 'Línea de tiempo');
        const day = el('input'); day.type = 'date'; day.value = today(); const dayLabel = el('label', 'Día'); dayLabel.append(day); const consult = el('button', 'Consultar día'); consult.type = 'button'; const view = el('div'); timeline.append(dayLabel, consult, view);
        const drawTimeline = async () => { if (!day.value) return; consult.disabled = true; view.replaceChildren(); try { let offset = 0; const data = []; do { const page = await get(query(`/users/${id}/timeline`, { day: day.value, offset, limit: 500 })); data.push(...rows(page)); offset = page.next_offset; } while (offset !== null); pagedTable(view, 'Cronología de apps y sitios', [['Inicio', r => time(r.started_at)], ['Fin', r => time(r.ended_at)], ['App', 'process_name'], ['Sitio', r => r.site || '—']], data, 50); } catch (e) { error(view, e); } finally { consult.disabled = false; } };
        consult.onclick = drawTimeline; await drawTimeline();
      }
      if (activeTab === 'controles') {
        const equipment = tab('controles', 'Equipo y controles');
        if (devices.error) error(equipment, devices.error);
        else if (!devices.data.length) {
          empty(equipment, 'Esta persona no tiene equipos asignados.');
          if (ctx.canIntake) equipment.append(link('Registrar equipos', '/alta.php'));
        }
        const access = await api('GET', '/me/access');
        for (const d of devices.data || []) {
          const p = panel(equipment, d.hostname); details(p, [['Versión', d.agent_version], ['Última conexión', time(d.last_seen_at)], ['K3 abierto', d.legacy_k3?.running === true ? 'Sí' : d.legacy_k3?.running === false ? 'No' : 'Sin dato'], ['Smart App Control', { off: 'Desactivado', on: 'Activado', evaluation: 'En evaluación', unknown: 'Sin dato' }[d.smart_app_control] || 'Sin dato']]);
          p.append(link('Ver ficha del equipo', `/equipo.php?id=${encodeURIComponent(d.id)}`));
          details(p, [['Estado', label(d.status)], ['Conexión', connectionLabel(connectionState([d]))], ['Sistema', d.os_edition || 'Sin dato']]);
          await deviceUpdates(p, d, tenant, access.platform);
          const history = el('section'); history.className = 'command-history';
          const log = { load: async () => { history.replaceChildren(el('h3', 'Historial de órdenes')); try { const commands = rows(await api('GET', `/devices/${d.id}/commands?limit=20`)); if (commands.length) pagedTable(history, 'Historial del equipo', [['Orden', r => commandTypes[r.type] || r.type], ['Estado', r => commandStates[r.status]?.[0] || r.status], ['Fecha', r => time(r.created_at)]], commands); else history.append(el('p', 'No hay órdenes registradas.', 'help')); } catch (e) { error(history, e); } } };
          const remote = await remoteActionsSection(d, log); if (remote) p.append(remote);
          p.append(history); if (remote) await log.load();
          try { const security = await api('GET', `/devices/${d.id}/security`); details(p, security.controls.map(c => [c.control_id, { applied: 'Aplicado', failed: 'Fallido', unsupported: 'No compatible', unknown: 'Sin dato' }[c.state] || c.state])); } catch (e) { if (![403, 404].includes(e.status)) error(p, e); }
        }
      }
      if (activeTab === 'presencia' && canActivity) {
        const presence = tab('presencia', 'Presencia');
        if (act.data) { details(presence, [['Días sin actividad', act.data.data.filter(d => d.status === 'sin_actividad').length]]); pagedTable(presence, 'Check-ins observados a partir de la actividad', presenceColumns.slice(1), act.data.data); presence.append(el('p', 'Primera y última actividad observadas; no son registros de login ni marcaciones manuales.', 'help')); } else error(presence, act.error);
      }
      submit.disabled = false;
    }
    rangeBar.onsubmit = e => { e.preventDefault(); renderMember().catch(e => { error(kpis, e); submit.disabled = false; }); }; await renderMember();
  } else if (page === 'policies') {
    const [policies, org, users, devices] = await Promise.all([allPages('/policies'), optional('/organization'), optional('/users'), optional(devicesPath)]);
    const p = panel(host, 'Políticas y alcance'); dependency(p, org, 'Organización'); dependency(p, users, 'Personas'); dependency(p, devices, 'Equipos');
    function target(r) { if (r.target_type === 'tenant') return document.getElementById('tenant-name').textContent || 'Empresa'; const list = ['area', 'site'].includes(r.target_type) ? org.data : r.target_type === 'user' ? users.data : devices.data; const found = list?.find(x => x.id === r.target_id); return found?.name || found?.display_name || found?.hostname || r.target_id; }
    function ruleList(r) { const d = el('details'); d.append(el('summary', `${r.rules.length} reglas`)); const list = el('ul'); r.rules.forEach(rule => list.append(el('li', `${label(rule.effect)} · ${rule.kind} · ${rule.targets.join(', ') || 'Sin destinos'} · Prioridad ${rule.priority}`))); d.append(list); return d; }
    pagedTable(p, 'Asignaciones de políticas', [['Política', 'name'], ['Scope', r => label(r.target_type)], ['Aplica a', target], ['Estado', r => r.enabled ? 'Habilitada' : 'Inactiva'], ['Reglas', ruleList], ['Versión', 'version']], policies);
    p.append(el('p', 'Las asignaciones se combinan según su scope. Consulta la ficha de una persona para ver las reglas efectivas y su origen.', 'help'));
  }
}
