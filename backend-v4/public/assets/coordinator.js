import { ApiError, errorMessage, isUuid } from './api.js';

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
function pagedTable(parent, title, columns, records, size = 25) {
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
  parent.append(frame, el('p', 'Línea continua azul: productividad (%) · Línea discontinua roja: focus (/100).', 'help'));
}
async function pool(items, task, alive = () => true) {
  let index = 0;
  await Promise.all(Array.from({ length: Math.min(4, items.length) }, async () => { while (index < items.length && alive()) { const i = index++; await task(items[i], i); } }));
}

export async function coordinator(ctx) {
  const { page, tenant, get, allPages, today, shiftDay, query } = ctx;
  const root = document.getElementById('content');
  const host = el('div', undefined, 'coordinator'); root.append(host);
  const alive = () => host.isConnected;
  const time = v => v ? new Intl.DateTimeFormat('es-CO', { timeZone: ctx.timezone, dateStyle: 'medium', timeStyle: 'short' }).format(new Date(v)) : 'Sin registro';
  const week = { from: shiftDay(today(), -6), to: shiftDay(today(), 1) };
  const devicesPath = `/tenants/${tenant}/devices`;
  const reportsDenied = [401, 403].includes(ctx.permissions.get('reports')?.status);
  const report = (kind, range, filters = {}) => get(query(`/reports/${kind}`, { ...range, ...filters }));
  const activity = (id, range) => get(query(`/users/${id}/activity`, range));
  // Totales de todas las personas en UNA consulta: antes era una por persona (~240) y agotaba el limite de la API.
  const people = async range => new Map(rows(await report('people', range)).map(r => [r.user_id, r]));
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
  function orgFilters(parent, org) {
    if (!org.data) return {};
    return Object.fromEntries(['area', 'site'].map(kind => [`${kind}_id`, selector(parent, label(kind), kind, org.data.filter(o => o.kind === kind))]));
  }
  function selected(controls) { return Object.fromEntries(Object.entries(controls).filter(([, v]) => v.value).map(([k, v]) => [k, v.value])); }
  const productColumns = [['Día', 'day'], ['Activo', r => hours(r.active_seconds)], ['Inactivo', r => hours(r.idle_seconds)], ['Productividad', r => percent(r.productivity_percent)], ['Focus /100', r => numeric(r.focus_score) ? fmt.format(r.focus_score) : 'Sin dato']];
  const appColumns = [['Aplicación', r => r.app || r.process_name], ['Proceso', 'process_name'], ['Tiempo', r => hours(r.total_seconds)], ['Sesiones', 'sessions'], ['Personas', 'users_count'], ['Del total', r => percent(r.percent_total)]];
  const presenceColumns = [['Persona', r => memberLink(r)], ['Día', 'day'], ['Primera actividad', r => time(r.first_activity)], ['Última actividad', r => time(r.last_activity)], ['Activo', r => hours(r.active_seconds)], ['Retraso (min)', r => numeric(r.late_seconds) ? fmt.format(r.late_seconds / 60) : 'Sin dato'], ['Estado', r => label(r.status)]];
  async function apps(parent, range, filters = {}, exportable = false) {
    return section(parent, 'Aplicaciones más usadas', async p => { const data = await report('apps', range, { ...filters, limit: exportable ? 100 : 10 }); const list = rows(data); p.append(el('p', `Tiempo total: ${hours(data.total_seconds)}. Incluye actividad e inactividad; porcentajes sobre todas las aplicaciones.`, 'help')); pagedTable(p, exportable ? 'Top 100 aplicaciones' : 'Top 10 aplicaciones', appColumns, list); if (exportable) csvButton(p, `apps-${range.from}-${range.to}`, appColumns, list); });
  }
  function productivity(p, data) {
    const series = rows(data, 'series');
    const avg = field => { const values = series.filter(r => numeric(r[field])); return values.length ? values.reduce((a, r) => a + r[field], 0) / values.length : null; };
    metrics(p, [['Horas activas', series.length ? hours(series.reduce((a, r) => a + r.active_seconds, 0)) : 'Sin datos'], ['Productividad media', percent(avg('productivity_percent')), 'Media de los días con medición'], ['Focus promedio', numeric(avg('focus_score')) ? `${fmt.format(avg('focus_score'))} /100` : 'Sin dato'], ['Cobertura', series.length ? percent(data.coverage_percent) : 'Sin datos']]);
    p.append(el('p', `${data.from} → ${data.to} (fin excluido) · ${data.timezone} · Datos hasta: ${time(data.data_through)}`, 'help')); chart(p, series);
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
    host.append(el('p', 'Últimos 7 días · Información disponible en tu alcance.', 'help'));
    await Promise.all([
      section(host, 'Pulso de la semana', async p => { const data = await report('productivity', week); productivity(p, data); if (data.series.length && data.coverage_percent < 80) { const a = el('p', undefined, 'notice'); a.append(link(`Cobertura baja (${percent(data.coverage_percent)}). Revisar reportes`, '/reportes.php')); p.append(a); } }),
      section(host, 'Equipos y conectividad', async p => {
        const devices = await allPages(devicesPath); const now = Date.now();
        metrics(p, [['En línea / total', `${devices.filter(d => d.status === 'active' && d.last_seen_at && now - Date.parse(d.last_seen_at) <= 300000).length} / ${devices.length}`, 'En línea: equipo activo con señal en los últimos 5 minutos.']]);
        const stale = devices.filter(d => d.status === 'active' && (!d.last_seen_at || now - Date.parse(d.last_seen_at) >= 3 * 86400000));
        pagedTable(p, 'Alerta: sin conectar durante 3 días o sin registro', [['Equipo', 'hostname'], ['Última conexión', d => time(d.last_seen_at)], ['Acción', d => link('Revisar ficha', `/miembro.php?id=${encodeURIComponent(d.user_id)}#equipos`)]], stale);
        await migration(host, devices.filter(d => d.status === 'active'));
      }),
      apps(host, week),
      section(host, 'Ranking de personas', async p => {
        if (reportsDenied) throw ctx.permissions.get('reports');
        const users = await allPages('/users');
        if (!users.length) { empty(p, 'No hay personas en tu alcance.'); return; }
        const status = el('p', 'Preparando ranking…', 'help'); status.setAttribute('role', 'status'); p.append(status);
        p.hidden = false;
        let totals;
        try { totals = await people(week); }
        catch (e) { if (e.status === 403) { p.remove(); return; } throw e; }
        const ranked = users.filter(u => totals.has(u.id)).map(u => ({ ...u, ...totals.get(u.id).totals }));
        const failures = 0, denied = 0;
        const measured = ranked.filter(r => numeric(r.productivity_percent)).sort((a, b) => b.productivity_percent - a.productivity_percent || a.display_name.localeCompare(b.display_name));
        status.textContent = `${measured.length} con medición · ${ranked.length - measured.length} sin medición · ${failures} consultas fallidas${denied ? ` · ${denied} fuera de alcance` : ''}. ${failures || denied ? 'Ranking parcial.' : 'Últimos 7 días; totales ponderados por persona.'}`;
        pagedTable(p, 'Personas por productividad', [['Persona', memberLink], ['Productividad', r => percent(r.productivity_percent)], ['Focus /100', r => numeric(r.focus_score) ? fmt.format(r.focus_score) : 'Sin dato'], ['Activo', r => hours(r.active_seconds)]], measured, 10);
      }),
    ]);
  } else if (page === 'users') {
    const [users, org, devices] = await Promise.all([allPages('/users'), optional('/organization'), optional(devicesPath)]);
    const p = panel(host, 'Personas de la empresa'); dependency(p, org, 'Organización no disponible'); dependency(p, devices, 'Equipos no disponibles');
    const bar = el('form', undefined, 'toolbar'); const controls = orgFilters(bar, org);
    const searchBox = el('div'); const lbl = el('label', 'Buscar nombre o correo'); lbl.htmlFor = 'user-search'; const search = el('input'); search.id = 'user-search'; search.type = 'search'; searchBox.append(lbl, search); bar.append(searchBox); bar.onsubmit = e => e.preventDefault(); p.append(bar);
    p.append(el('p', 'Métricas de los últimos 7 días. La última actividad registrada no equivale a un acceso al portal.', 'help'));
    const view = el('div'); p.append(view); let offset = 0, version = 0;
    async function render() {
      const current = ++version; const f = selected(controls), term = search.value.trim().toLocaleLowerCase('es');
      const matches = users.filter(u => Object.entries(f).every(([k, v]) => u[k] === v) && `${u.display_name} ${u.email || ''}`.toLocaleLowerCase('es').includes(term));
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
    const dateForm = document.getElementById('date-form');
    let from = document.getElementById('from').value, to = document.getElementById('to').value;
    if ((Date.parse(to) - Date.parse(from)) / 86400000 > 31) throw new Error('Apps y presencia admiten como máximo 31 días. Ajusta el rango. Hasta es excluyente.');
    dateForm.querySelector('.report-filters')?.remove();
    const [org, users] = await Promise.all([optional('/organization'), optional('/users')]);
    const filters = el('div', undefined, 'toolbar report-filters'); const controls = orgFilters(filters, org);
    if (users.data) controls.user_id = selector(filters, 'Persona', 'user', users.data, 'display_name');
    const previous = dateForm.dataset.tenant === tenant ? JSON.parse(dateForm.dataset.filters || '{}') : {};
    Object.entries(controls).forEach(([k, c]) => { c.value = previous[k] || ''; c.onchange = () => { dateForm.dataset.filters = JSON.stringify(selected(controls)); }; });
    dateForm.dataset.tenant = tenant; dateForm.append(filters); dependency(host, org, 'Filtros de organización no disponibles'); dependency(host, users, 'Filtro de personas no disponible');
    host.append(el('p', 'Máximo 31 días. Las tablas y el CSV reflejan el rango y los filtros consultados. Hasta es excluyente.', 'help'));
    const range = { from, to }, values = selected(controls);
    await Promise.all([
      section(host, 'Productividad', async p => { const data = await report('productivity', range, values); const list = productivity(p, data); pagedTable(p, 'Serie diaria', productColumns, list); csvButton(p, `productividad-${from}-${to}`, productColumns, list); }),
      apps(host, range, values, true),
      section(host, 'Presencia', async p => { const list = rows(await report('presence', range, values)); p.append(el('p', 'Los estados proceden del horario; no incluyen calendario de festivos.', 'help')); pagedTable(p, 'Presencia diaria', presenceColumns, list); csvButton(p, `presencia-${from}-${to}`, presenceColumns, list); }),
    ]);
  } else if (page === 'member') {
    const id = new URLSearchParams(location.search).get('id'); if (!isUuid(id)) throw new Error('Selecciona una persona desde Usuarios.');
    const [u, org, tiers, subscription] = await Promise.all([get(`/users/${id}`), optional('/organization'), optional('/tiers'), optional(`/users/${id}/subscription`, false)]);
    const header = panel(host, u.display_name); header.append(el('p', u.email || 'Sin correo', 'help'));
    details(header, [['Cargo', orgName(org, u.position_id)], ['Área / sede', `${orgName(org, u.area_id)} / ${orgName(org, u.site_id)}`], ['Estado', label(u.status)], ['Tier', tiers.data?.find(t => t.id === (subscription.data?.tier_id || u.tier_id))?.name || (u.tier_id ? 'Nombre no disponible' : 'Sin tier')]]);
    dependency(header, org, 'Organización'); dependency(header, tiers, 'Tiers'); if (subscription.error?.status !== 404) dependency(header, subscription, 'Suscripción');
    const rangeBar = el('div', undefined, 'toolbar'); const rangeLabel = el('label', 'Período'); rangeLabel.htmlFor = 'member-range'; const rangeSelect = el('select'); rangeSelect.id = 'member-range'; for (const n of [7, 30]) { const o = el('option', `${n} días`); o.value = n; rangeSelect.append(o); } rangeBar.append(rangeLabel, rangeSelect); host.append(rangeBar);
    const kpis = el('div'); host.append(kpis);
    const tabs = el('div', undefined, 'tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Ficha de la persona'); host.append(tabs);
    const panels = el('div'); host.append(panels); let cycle = 0;
    async function renderMember() {
      const version = ++cycle, range = { from: shiftDay(today(), 1 - Number(rangeSelect.value)), to: shiftDay(today(), 1) };
      const activeTab = tabs.querySelector('[aria-selected="true"]')?.dataset.key || location.hash.slice(1) || 'resumen';
      rangeSelect.disabled = true; kpis.replaceChildren(); tabs.replaceChildren(); panels.replaceChildren();
      const [act, devices, schedules, effective] = await Promise.all([optional(query(`/users/${id}/activity`, range), false), optional(query(devicesPath, { user_id: id })), optional('/schedules'), optional(`/users/${id}/policies`, false)]);
      if (!alive() || cycle !== version) return;
      const canActivity = ![401, 403].includes(act.error?.status);
      if (act.data) {
        rows(act.data); const days = act.data.data, t = act.data.totals; if (!t) throw invalid(); const punctual = days.filter(d => ['a_tiempo', 'tarde'].includes(d.status));
        metrics(kpis, [['Primera actividad del período', time(days.map(d => d.first_activity).filter(Boolean).sort()[0])], ['Horas activas', days.length ? hours(t.active_seconds) : 'Sin datos'], ['Productividad', percent(t.productivity_percent)], ['Focus /100', numeric(t.focus_score) ? fmt.format(t.focus_score) : 'Sin dato'], ['Puntualidad', punctual.length ? percent(punctual.filter(d => d.status === 'a_tiempo').length * 100 / punctual.length) : 'Sin dato', 'Sobre días laborables con actividad']]);
      } else if (canActivity) error(kpis, act.error);
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
      if (![401, 403].includes(devices.error?.status)) {
        const equipment = tab('equipos', 'Equipos');
        if (devices.error) error(equipment, devices.error);
        else {
          pagedTable(equipment, 'Equipos asignados', [['Equipo', 'hostname'], ['Estado', r => label(r.status)], ['Última conexión', r => time(r.last_seen_at)], ['Sistema', 'os_edition'], ['Agente', 'agent_version']], devices.data);
          await Promise.all(devices.data.map(d => section(equipment, `Comandos · ${d.hostname}`, async p => { const commands = await allPages(`/devices/${d.id}/commands`); pagedTable(p, 'Historial de acciones remotas', [['Acción', 'type'], ['Estado', 'status'], ['Creado', r => time(r.created_at)]], commands); })));
          await migration(equipment, devices.data);
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
