import { api, ApiError, errorMessage, isUuid } from './api.js';

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
const names = { tenant: 'Empresa', global: 'Global', site: 'Sede', area: 'Área', user: 'Persona', device: 'Equipo', active: 'Activo', inactive: 'Inactivo', revoked: 'Revocado', decommissioned: 'Retirado', a_tiempo: 'A tiempo', tarde: 'Tarde', actividad_sin_hora: 'Actividad sin hora registrada', sin_actividad: 'Sin actividad', no_laborable: 'No laborable', allow: 'Permitir', deny: 'Denegar', require: 'Exigir', instalado: 'Instalado', enrolado: 'Enrolado', escrow_ok: 'Respaldo verificado', degradado: 'Degradado', pendiente_reinicio: 'Pendiente de reinicio', completo: 'Completo', excepcion: 'Excepción' };
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
  fields.forEach(([name, value, note]) => { const r = el('div', undefined, 'metric'); r.append(el('dt', name), el('dd', value)); if (note) r.append(el('p', note, 'help')); d.append(r); }); parent.append(d);
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
function chart(parent, series) {
  if (!series.some(r => numeric(r.productivity_percent) || numeric(r.focus_score))) { empty(parent, 'Sin mediciones de productividad o focus para dibujar la tendencia.'); return; }
  const svgEl = (tag, attrs = {}, text) => { const n = document.createElementNS('http://www.w3.org/2000/svg', tag); Object.entries(attrs).forEach(([k, v]) => n.setAttribute(k, v)); if (text !== undefined) n.textContent = text; return n; };
  const svg = svgEl('svg', { viewBox: '0 0 760 250', role: 'img', 'aria-label': 'Tendencia diaria de productividad y focus. Escala de 0 a 100. Los huecos indican datos ausentes.', class: 'trend-chart' });
  svg.append(svgEl('title', {}, 'Productividad y focus por día'));
  [0, 25, 50, 75, 100].forEach(v => { const y = 210 - v * 1.8; svg.append(svgEl('line', { x1: 42, x2: 738, y1: y, y2: y, class: 'chart-grid' }), svgEl('text', { x: 4, y: y + 5 }, String(v))); });
  for (const [field, cls, title] of [['productivity_percent', 'productivity-line', 'Productividad'], ['focus_score', 'focus-line', 'Focus']]) {
    let path = '', gap = true;
    series.forEach((r, i) => { if (!numeric(r[field])) { gap = true; return; } const x = 42 + i * 696 / Math.max(1, series.length - 1), y = 210 - r[field] * 1.8; path += `${gap ? 'M' : 'L'}${x},${y} `; gap = false; const dot = svgEl('circle', { cx: x, cy: y, r: 3, class: cls }); dot.append(svgEl('title', {}, `${r.day}: ${title} ${fmt.format(r[field])}`)); svg.append(dot); });
    svg.append(svgEl('path', { d: path, class: cls, fill: 'none', 'stroke-width': 3 }));
  }
  svg.append(svgEl('text', { x: 42, y: 240 }, series[0].day), svgEl('text', { x: 738, y: 240, 'text-anchor': 'end' }, series.at(-1).day));
  const frame = el('div', undefined, 'chart-frame'); frame.tabIndex = 0; frame.setAttribute('role', 'region'); frame.setAttribute('aria-label', 'Gráfico de tendencia; desplazable en pantallas pequeñas'); frame.append(svg);
  parent.append(frame, el('p', 'Línea continua índigo oscuro: productividad (%) · Línea discontinua índigo claro: focus (/100). Cada punto es un día; los huecos son días sin dato.', 'help'));
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
  // Filtros del grupo: empresa del grupo y pais (si existen), luego sede y area. Sin filtro = todo el grupo.
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
    if (companies.length) { records.company_id = companies; controls.company_id = selector(parent, 'Empresa del grupo', 'company', companies); }
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
      const names = { company_id: 'Empresa del grupo', country: 'País', city: 'Ciudad', site_id: 'Sede', area_id: 'Área', user_id: 'Persona' };
      return Object.entries(controls).filter(([, c]) => c.value).map(([k, c]) => `${names[k]}: ${c.selectedOptions[0]?.textContent || c.value}`);
    }
    return { controls, narrow, describe };
  }
  function selected(controls) { return Object.fromEntries(Object.entries(controls).filter(([, v]) => v.value).map(([k, v]) => [k, v.value])); }
  const productColumns = [['Día', 'day'], ['En uso', r => hours(r.active_seconds)], ['Encendido sin uso', r => hours(r.idle_seconds)], ['Productividad', r => percent(r.productivity_percent)], ['Focus /100', r => numeric(r.focus_score) ? fmt.format(r.focus_score) : 'Sin dato']];
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
    host.append(el('p', 'Últimos 7 días · Información disponible en tu alcance. Sin filtros ves todo el grupo; elige una empresa, un país o una sede para ver solo esa parte.', 'help'));
    const homeOrg = await optional('/organization');
    const bar = el('form', undefined, 'toolbar'); bar.onsubmit = e => e.preventDefault(); const controls = orgFilters(bar, homeOrg, ['site']);
    const body = el('div'); if (Object.keys(controls).length) host.append(bar); host.append(body);
    const draw = async () => { body.replaceChildren(); const f = selected(controls); await Promise.all([
      section(body, 'Pulso de la semana', async p => { const data = await report('productivity', week, f); productivity(p, data); if (data.series.length && data.coverage_percent < 80) { const a = el('p', undefined, 'notice'); a.append(link(`Solo se registró el ${percent(data.coverage_percent)} de la jornada esperada (equipos apagados o sin reportar). Revisar reportes`, '/reportes.php')); p.append(a); } }),
      section(body, 'Equipos y conectividad', async p => {
        const devices = await allPages(query(devicesPath, f)); const now = Date.now();
        metrics(p, [['En línea / total', `${devices.filter(d => d.status === 'active' && d.last_seen_at && now - Date.parse(d.last_seen_at) <= 300000).length} / ${devices.length}`, 'En línea: equipo activo con señal en los últimos 5 minutos.']]);
        const stale = devices.filter(d => d.status === 'active' && (!d.last_seen_at || now - Date.parse(d.last_seen_at) >= 3 * 86400000));
        pagedTable(p, 'Alerta: sin conectar durante 3 días o sin registro', [['Equipo', 'hostname'], ['Última conexión', d => time(d.last_seen_at)], ['Acción', d => link('Revisar ficha', `/miembro.php?id=${encodeURIComponent(d.user_id)}#equipos`)]], stale);
        await migration(body, devices.filter(d => d.status === 'active'));
      }),
      apps(body, week, f),
      section(body, 'Ranking de personas', async p => {
        if (reportsDenied) throw ctx.permissions.get('reports');
        const users = await allPages(query('/users', f));
        if (!users.length) { empty(p, 'No hay personas en tu alcance.'); return; }
        const status = el('p', 'Preparando ranking…', 'help'); status.setAttribute('role', 'status'); p.append(status);
        p.hidden = false;
        let totals;
        try { totals = await people(week, f); }
        catch (e) { if (e.status === 403) { p.remove(); return; } throw e; }
        const ranked = users.filter(u => totals.has(u.id)).map(u => ({ ...u, ...totals.get(u.id).totals }));
        const failures = 0, denied = 0;
        const measured = ranked.filter(r => numeric(r.productivity_percent)).sort((a, b) => b.productivity_percent - a.productivity_percent || a.display_name.localeCompare(b.display_name));
        status.textContent = `${measured.length} con medición · ${ranked.length - measured.length} sin medición · ${failures} consultas fallidas${denied ? ` · ${denied} fuera de alcance` : ''}. ${failures || denied ? 'Ranking parcial.' : 'Últimos 7 días; totales ponderados por persona.'}`;
        pagedTable(p, 'Personas por productividad', [['Persona', memberLink], ['Productividad', r => percent(r.productivity_percent)], ['Focus /100', r => numeric(r.focus_score) ? fmt.format(r.focus_score) : 'Sin dato'], ['Activo', r => hours(r.active_seconds)]], measured, 10);
      }),
    ]); };
    Object.values(controls).forEach(c => { c.onchange = () => draw(); }); await draw();
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
    const [org, users] = await Promise.all([optional('/organization'), optional('/users')]);
    const presets = el('div', undefined, 'report-presets'); presets.setAttribute('role', 'group'); presets.setAttribute('aria-label', 'Períodos rápidos');
    const end = today();
    for (const [text, start] of [['Últimos 7 días', shiftDay(end, -6)], ['Últimos 30 días', shiftDay(end, -29)], ['Este mes', `${end.slice(0, 8)}01`]]) {
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
      const keep = tabs?.current();
      submit.disabled = true; showState('Consultando…', '');
      results.replaceChildren();
      const described = chain.describe();
      results.append(el('p', `Período consultado: del ${dayText(from)} al ${dayText(last)} (${days} ${days === 1 ? 'día' : 'días'}, ambos incluidos) · Zona horaria: ${ctx.timezone} · ${described.length ? `Filtros: ${described.join(' · ')}` : 'Sin filtros: todo tu alcance'}`, 'report-query'));
      tabs = tabSet(results, 'Tipo de reporte', 'report');
      const fresh = (kind, r, f) => api('GET', query(`/reports/${kind}`, { ...r, ...f }), { tenant });
      const summary = tabs.add('resumen', 'Resumen'), appsTab = tabs.add('aplicaciones', 'Aplicaciones'), presenceTab = tabs.add('presencia', 'Presencia');
      tabs.activate(keep || 'resumen');
      try {
        await Promise.all([
          section(summary, 'Productividad', async p => { const data = await fresh('productivity', range, values); const list = productivity(p, data); pagedTable(p, 'Serie diaria', productColumns, list); csvButton(p, `productividad-${from}-${last}`, productColumns, list); }),
          apps(appsTab, range, values, true, fresh),
          section(presenceTab, 'Presencia', async p => { const list = rows(await fresh('presence', range, values)); p.append(el('p', `Los estados salen del horario de cada persona y de su calendario de festivos: un festivo o una ausencia aprobada cuentan como «No laborable». Horas en la zona horaria de la empresa (${ctx.timezone}).`, 'help')); pagedTable(p, 'Presencia diaria', presenceColumns, list); csvButton(p, `presencia-${from}-${last}`, presenceColumns, list); }),
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
    if (form.dataset.queried) await run();
    else { results.append(el('p', 'Aún no hay resultados. Elige el período (las dos fechas se incluyen) y, si quieres, filtra por empresa, sede, área o persona. Luego pulsa «Consultar».', 'empty-state report-empty')); sync(); }
  } else if (page === 'member') {
    // Pestaña «Actividad» de la ficha única de la persona (k3.js, revisión UX G1): la ficha ya muestra datos, plan y
    // equipos; aquí solo productividad, presencia y reglas efectivas. El plan vigente está en «Acceso y plan».
    const id = ctx.user?.id || new URLSearchParams(location.search).get('id'); if (!isUuid(id)) throw new Error('Selecciona una persona desde Personas.');
    const u = ctx.user || await get(`/users/${id}`);
    const rangeBar = el('div', undefined, 'toolbar'); const rangeLabel = el('label', 'Período'); rangeLabel.htmlFor = 'member-range'; const rangeSelect = el('select'); rangeSelect.id = 'member-range'; for (const n of [7, 30]) { const o = el('option', `${n} días`); o.value = n; rangeSelect.append(o); } rangeBar.append(rangeLabel, rangeSelect); host.append(rangeBar);
    const kpis = el('div'); host.append(kpis);
    const tabs = el('div', undefined, 'tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Actividad de la persona'); host.append(tabs);
    const panels = el('div'); host.append(panels); let cycle = 0;
    async function renderMember() {
      const version = ++cycle, range = { from: shiftDay(today(), 1 - Number(rangeSelect.value)), to: shiftDay(today(), 1) };
      const activeTab = tabs.querySelector('[aria-selected="true"]')?.dataset.key || ctx.subtab || 'resumen';
      rangeSelect.disabled = true; kpis.replaceChildren(); tabs.replaceChildren(); panels.replaceChildren();
      const [act, devices, schedules, effective] = await Promise.all([optional(query(`/users/${id}/activity`, range), false), optional(query(devicesPath, { user_id: id })), optional('/schedules'), optional(`/users/${id}/policies`, false)]);
      if (!alive() || cycle !== version) return;
      const canActivity = ![401, 403].includes(act.error?.status);
      if (act.data) {
        rows(act.data); const days = act.data.data, t = act.data.totals; if (!t) throw invalid(); const punctual = days.filter(d => ['a_tiempo', 'tarde'].includes(d.status));
        metrics(kpis, [['Primera actividad del período', time(days.map(d => d.first_activity).filter(Boolean).sort()[0])], ['Horas activas', days.length ? hours(t.active_seconds) : 'Sin datos'], ['Productividad', percent(t.productivity_percent)], ['Focus /100', numeric(t.focus_score) ? fmt.format(t.focus_score) : 'Sin dato'], ['Puntualidad', punctual.length ? percent(punctual.filter(d => d.status === 'a_tiempo').length * 100 / punctual.length) : 'Sin dato', 'Sobre días laborables con actividad']]);
      } else if (canActivity) error(kpis, act.error);
      else kpis.append(el('p', 'Tu cuenta no puede consultar la actividad de esta persona (requiere el permiso de ver reportes).', 'notice'));
      function tab(key, title) {
        const button = el('button', title); button.type = 'button'; button.id = `tab-${key}`; button.dataset.key = key; button.setAttribute('role', 'tab'); button.setAttribute('aria-controls', `panel-${key}`);
        const p = panel(panels, title); p.id = `panel-${key}`; p.setAttribute('role', 'tabpanel'); p.setAttribute('aria-labelledby', button.id); p.tabIndex = 0;
        button.onclick = () => activate(key); tabs.append(button); return p;
      }
      function activate(key) {
        [...tabs.children].forEach(b => { const on = b.dataset.key === key; b.setAttribute('aria-selected', String(on)); b.tabIndex = on ? 0 : -1; document.getElementById(b.getAttribute('aria-controls')).hidden = !on; });
      }
      tabs.onkeydown = e => { const buttons = [...tabs.children]; const i = buttons.indexOf(document.activeElement); let next; if (e.key === 'ArrowRight') next = (i + 1) % buttons.length; if (e.key === 'ArrowLeft') next = (i + buttons.length - 1) % buttons.length; if (e.key === 'Home') next = 0; if (e.key === 'End') next = buttons.length - 1; if (next !== undefined) { e.preventDefault(); buttons[next].click(); buttons[next].focus(); } };
      const summary = tab('resumen', 'Resumen');
      if (devices.data) details(summary, [['Equipos activos', devices.data.filter(d => d.status === 'active').map(d => d.hostname).join(', ') || 'Sin equipo activo']]); else dependency(summary, devices, 'Equipos');
      if (schedules.data) { const s = schedules.data.find(s => s.id === u.schedule_id); details(summary, [['Horario', s ? `${s.name} · ${s.start_local}–${s.end_local} · ${s.timezone}` : 'Sin horario disponible'], ['Días', s ? s.days.map(d => ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][d - 1]).join(', ') : 'Sin dato']]); } else dependency(summary, schedules, 'Horario');
      if (act.data) { chart(summary, act.data.data); details(summary, [['Trabajo profundo', hours(act.data.totals.deep_work_seconds)], ['Distracción', hours(act.data.totals.distraction_seconds)], ['Cambios de contexto', act.data.totals.context_switches]]); }
      if (canActivity) {
        const activityPanel = tab('actividad', 'Actividad');
        if (act.data) { pagedTable(activityPanel, 'Actividad diaria', [...productColumns, ['Llamadas', r => hours(r.call_seconds)]], act.data.data); activityPanel.append(el('p', 'Las llamadas son parte del tiempo activo; no se suman de nuevo.', 'help')); } else error(activityPanel, act.error);
        await apps(activityPanel, range, { user_id: id });
        const presence = tab('presencia', 'Presencia');
        if (act.data) { details(presence, [['Días sin actividad', act.data.data.filter(d => d.status === 'sin_actividad').length]]); pagedTable(presence, 'Check-ins observados a partir de la actividad', presenceColumns.slice(1), act.data.data); presence.append(el('p', 'Primera y última actividad observadas; no son registros de login ni marcaciones manuales.', 'help')); } else error(presence, act.error);
      }
      if (![401, 403].includes(effective.error?.status)) {
        const policies = tab('politicas', 'Políticas');
        if (effective.error) error(policies, effective.error);
        else {
          const sources = rows(effective.data, 'devices').flatMap(d => d.rules.map(r => ({ ...r, device: devices.data?.find(x => x.id === d.device_id)?.hostname || d.device_id || 'Sin equipo' })));
          pagedTable(policies, 'Reglas efectivas y origen de cada regla', [['Equipo', 'device'], ['Tipo', r => r.rule.kind], ['Efecto', r => label(r.rule.effect)], ['Destinos', r => r.rule.targets.join(', ') || 'Sin destinos'], ['Scope de origen', r => label(r.scope)], ['Asignaciones de origen', r => r.assignment_ids.join(', ') || 'Sin asignación'], ['Prioridad', r => r.rule.priority]], sources);
        }
      }
      activate([...tabs.children].some(b => b.dataset.key === activeTab) ? activeTab : 'resumen'); rangeSelect.disabled = false;
    }
    rangeSelect.onchange = () => renderMember().catch(e => { error(kpis, e); rangeSelect.disabled = false; }); await renderMember();
  } else if (page === 'policies') {
    const [policies, org, users, devices] = await Promise.all([allPages('/policies'), optional('/organization'), optional('/users'), optional(devicesPath)]);
    const p = panel(host, 'Políticas y alcance'); dependency(p, org, 'Organización'); dependency(p, users, 'Personas'); dependency(p, devices, 'Equipos');
    function target(r) { if (r.target_type === 'tenant') return document.getElementById('tenant-name').textContent || 'Empresa'; const list = ['area', 'site'].includes(r.target_type) ? org.data : r.target_type === 'user' ? users.data : devices.data; const found = list?.find(x => x.id === r.target_id); return found?.name || found?.display_name || found?.hostname || r.target_id; }
    function ruleList(r) { const d = el('details'); d.append(el('summary', `${r.rules.length} reglas`)); const list = el('ul'); r.rules.forEach(rule => list.append(el('li', `${label(rule.effect)} · ${rule.kind} · ${rule.targets.join(', ') || 'Sin destinos'} · Prioridad ${rule.priority}`))); d.append(list); return d; }
    pagedTable(p, 'Asignaciones de políticas', [['Política', 'name'], ['Scope', r => label(r.target_type)], ['Aplica a', target], ['Estado', r => r.enabled ? 'Habilitada' : 'Inactiva'], ['Reglas', ruleList], ['Versión', 'version']], policies);
    p.append(el('p', 'Las asignaciones se combinan según su scope. Consulta la ficha de una persona para ver las reglas efectivas y su origen.', 'help'));
  }
}
