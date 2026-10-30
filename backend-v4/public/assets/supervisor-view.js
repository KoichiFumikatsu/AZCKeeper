import { isUuid } from './api.js';

export const node = (tag, text, className) => {
  const el = document.createElement(tag);
  if (text !== undefined && text !== null) el.textContent = text;
  if (className) el.className = className;
  return el;
};
export const number = value => value == null ? '—' : new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1 }).format(value);
export const percent = value => value == null ? '—' : `${number(value)} %`;
export const duration = value => value == null ? '—' : `${Math.floor(value / 3600)} h ${Math.floor(value % 3600 / 60)} min`;
export const dateTime = (value, zone) => value ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'short', timeStyle: 'short', timeZone: zone }).format(new Date(value)) : 'Sin registro';
export const localDay = (zone, now = new Date()) => new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
export const shiftDay = (day, n) => { const d = new Date(`${day}T12:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
export const validDay = day => /^\d{4}-\d{2}-\d{2}$/.test(day || '') && !Number.isNaN(Date.parse(`${day}T12:00:00Z`)) && new Date(`${day}T12:00:00Z`).toISOString().slice(0, 10) === day;
const statusNames = { available: '', unavailable: 'No disponible', not_authorized: 'Sin acceso', not_contracted: 'Función no contratada', not_retained: 'Datos fuera del período conservado', no_measurement: 'Sin medición', no_workday: 'Sin jornada' };
export const availability = value => statusNames[value?.status] ?? 'No disponible';
const connectionNames = { connected: 'Conectada', away: 'Sin actividad reciente', offline: 'Sin conexión' };
const protectionNames = { protected: 'Protegido', unconfirmed: 'Sin confirmar', unprotected: 'No protegido' };
const alertNames = { late_arrival: 'Llegada fuera del margen', offline_in_schedule: 'Sin conexión en horario', possible_dual_job: 'Posible doble empleo', suspicious_app: 'App sospechosa' };
const severityNames = { high: 'Alta', medium: 'Media', low: 'Baja' };
const reasonNames = { no_devices: 'Sin equipos asignados', plan_without_controls: 'El plan no incluye controles', control_failed: 'Control con fallo', device_offline: 'Equipo sin conexión', no_report: 'Sin reporte de protección', future_report: 'Fecha de reporte inconsistente', stale_report: 'Reporte de hace más de 24 horas', rules_pending: 'Reglas pendientes de confirmar', report_before_assignment: 'Reporte anterior a la asignación actual', controls_unknown: 'Controles sin confirmar', control_unknown: 'Control sin confirmar', control_unsupported: 'Control no compatible', no_effective_rules: 'Sin reglas efectivas', all_confirmed: 'Controles confirmados', excluded_control_failed: 'Fallo informativo en un control fuera del plan actual' };
export function filtersFrom(input) {
  const source = new URLSearchParams(input), out = new URLSearchParams();
  for (const key of ['tenant_id', 'company_id', 'area_id', 'site_id']) if (isUuid(source.get(key))) out.set(key, source.get(key));
  if (!out.has('tenant_id')) for (const key of ['company_id', 'area_id', 'site_id']) out.delete(key);
  const q = source.get('q')?.trim(); if (q) out.set('q', q.slice(0, 120));
  if (['connected', 'away', 'offline', 'with_alerts'].includes(source.get('state'))) out.set('state', source.get('state'));
  if (['attention', 'name', 'productivity', 'active'].includes(source.get('order'))) out.set('order', source.get('order'));
  return out;
}
export function returnPath(value) {
  if (typeof value !== 'string' || !/^\/supervisor\.php(?:\?|$)/.test(value)) return '/supervisor.php';
  const q = filtersFrom(value.split('?')[1]);
  return '/supervisor.php' + (q.size ? `?${q}` : '');
}
export function button(text, action) { const b = node('button', text); b.type = 'button'; b.dataset.action = action; return b; }
function section(title, id) { const s = node('section', null, 'surface'); if (id) s.id = id; s.append(node('h2', title)); return s; }
function metric(title, value, note = '') { const d = node('div', null, 'metric'); d.append(node('dt', title), node('dd', value), node('p', note, 'help')); return d; }
function select(form, label, name, options, value, disabled = false) {
  const l = node('label', label), s = node('select'); s.name = name; s.id = name;
  for (const [id, text] of options) { const o = node('option', text); o.value = id; s.append(o); }
  if (value && !options.some(([id]) => id === value)) { const o = node('option', 'Selección actual'); o.value = value; s.append(o); }
  s.value = value || ''; s.disabled = disabled; l.append(s); form.append(l);
}
function badge(text, state) { return node('span', text, `supervisor-badge ${state || ''}`); }
function connection(c) { return c?.state ? badge(connectionNames[c.state], c.state) : node('span', availability(c?.availability)); }
function explain(root) {
  const d = node('details', null, 'supervisor-explanation'); d.append(node('summary', 'Cómo leer estos datos'));
  d.append(node('p', 'La conexión se basa en la última señal de los equipos: hasta 5 minutos, conectada; más de 5 y hasta 15, sin actividad reciente; más de 15 o sin equipos, sin conexión. No confirma presencia humana ni una ausencia laboral.'));
  d.append(node('p', 'La llegada es la primera actividad o señal registrada, no una marcación. Se utiliza el margen configurado del cliente. Productividad = tiempo productivo / jornada esperada, descontando almuerzo y ausencias aprobadas. Los datos faltantes no se reemplazan por cero.'));
  d.append(node('p', 'Los indicadores siguen la búsqueda y la organización. El estado solo filtra la lista. Atención prioriza alertas altas, cumplimiento de prioridad media, llegadas fuera del margen, conexión, alertas bajas y el resto. La protección no cambia ese orden.'));
  root.append(d);
}
export function renderOverview(root, dto, filters = new URLSearchParams(), pageIndex = 0) {
  root.replaceChildren();
  const heading = node('div', null, 'supervisor-heading');
  const title = node('div'); title.append(node('p', 'Modo Supervisor', 'eyebrow'), node('h1', '¿Cómo va tu gente hoy?'));
  heading.append(title, node('p', `Actualizado: ${dateTime(dto.generated_at, 'UTC')} UTC`, 'help')); root.append(heading);
  const form = node('form', null, 'supervisor-filters'); form.id = 'supervisor-filters'; form.setAttribute('role', 'search');
  const search = node('label', 'Buscar una persona'); search.className = 'supervisor-search';
  const input = node('input'); input.type = 'search'; input.name = 'q'; input.maxLength = 120; input.value = filters.get('q') || ''; search.append(input); form.append(search);
  select(form, 'Cliente', 'tenant_id', [['', 'Todos los clientes'], ...dto.facets.tenants.map(t => [t.id, t.name])], filters.get('tenant_id'));
  for (const [field, list, label] of [['company_id', 'companies', 'Empresa'], ['area_id', 'areas', 'Área'], ['site_id', 'sites', 'Sede']]) {
    select(form, label, field, [['', 'Todas'], ...dto.facets[list].filter(x => x.tenant_id === filters.get('tenant_id')).map(x => [x.id, x.name])], filters.get(field), !filters.has('tenant_id'));
  }
  select(form, 'Estado', 'state', [['', 'Todos los estados'], ['connected', 'Conectadas'], ['away', 'Sin actividad reciente'], ['offline', 'Sin conexión'], ['with_alerts', 'Con alertas']], filters.get('state'));
  select(form, 'Ordenar por', 'order', [['attention', 'Atención'], ['name', 'Nombre · A–Z'], ['productivity', 'Productividad'], ['active', 'Tiempo activo']], filters.get('order') || 'attention');
  const submit = node('button', 'Buscar persona', 'primary'); submit.type = 'submit'; form.append(submit, button('Limpiar filtros', 'clear')); root.append(form);
  const k = dto.kpis, cohort = dto.cohort, grid = node('dl', null, 'supervisor-kpis'); grid.id = 'supervisor-kpis';
  grid.append(metric('Productividad', percent(k.productivity.percent), `${cohort.measured_people} de ${cohort.people} con medición y jornada · ${cohort.no_measurement_people} sin medición · ${cohort.no_workday_people} sin jornada · ${cohort.not_authorized_people} sin acceso o función. Tiempo productivo: ${duration(k.productivity.workday_productive_seconds)} / jornada: ${duration(k.productivity.workday_expected_seconds)}.`),
    metric('Llegadas fuera del margen', number(k.arrivals.late), `${number(k.arrivals.recorded)} primeras actividades registradas. ${k.arrivals.margin_minutes == null ? 'Margen por cliente' : `Margen: ${k.arrivals.margin_minutes} min`}.${k.arrivals.complete ? '' : ' Información parcial.'}`),
    metric('Personas con alertas', number(k.alerts.people), `${number(k.alerts.high_people)} con prioridad alta.${k.alerts.complete ? '' : ' Información parcial; pueden faltar alertas.'}`)); root.append(grid);
  const trend = k.trend;
  root.append(node('p', trend.availability.status === 'available' ? `Tendencia: ${percent(trend.current.percent)} frente a ${percent(trend.previous.percent)} · ${number(trend.delta_pp)} puntos porcentuales · ${trend.people} personas con medición en ambos períodos.` : trend.availability.reason === 'no_closed_days' ? 'Sin días cerrados esta semana.' : 'Tendencia no disponible: faltan mediciones comparables o acceso.', 'help'));
  for (const period of trend.periods) {
    const client = dto.facets.tenants.find(t => t.id === period.tenant_id);
    root.append(node('p', `${client?.name || 'Cliente'}: ${period.current_from} a ${shiftDay(period.current_to, -1)} frente a ${period.previous_from} a ${shiftDay(period.previous_to, -1)}.`, 'help'));
  }
  const counts = node('div', null, 'supervisor-counts');
  for (const [state, label] of [['connected', 'Conectadas'], ['away', 'Sin actividad reciente'], ['offline', 'Sin conexión']]) { const b = button(`${number(dto.counts[state])} ${label}`, 'state'); b.dataset.state = state; b.setAttribute('aria-pressed', String(filters.get('state') === state)); counts.append(b); } root.append(counts);
  if (availability(dto.counts.availability)) root.append(node('p', 'Conexión no disponible para todo el alcance.', 'help'));
  const roster = section('Personas hoy'); roster.append(node('p', `${dto.total} personas · ${dto.data.length} en esta página`, 'help'));
  const wrap = node('div', null, 'supervisor-table'); const table = node('table'); const head = node('thead'), tr = node('tr');
  for (const title of ['Persona', 'Conexión actual', 'Llegada', 'Activo hoy', 'Productividad', 'Alertas']) tr.append(node('th', title)); head.append(tr); table.append(head);
  const body = node('tbody');
  for (const p of dto.data) {
    const row = node('tr'), identity = node('td'), a = node('a', p.display_name);
    const query = new URLSearchParams({ tenant_id: p.tenant_id, id: p.user_id, day: p.day, return: returnPath(`/supervisor.php?${filters}`) });
    a.href = `/supervisor-persona.php?${query}`; identity.append(a, node('small', [dto.facets.tenants.find(t => t.id === p.tenant_id)?.name, p.company?.name, p.area?.name, p.site?.name, p.position?.name].filter(Boolean).join(' · ')), node('small', `${p.day} · ${p.timezone}`)); row.append(identity);
    const conn = node('td'); conn.append(connection(p.connection), node('small', p.connection.device_count === 0 ? 'Sin equipos asignados' : `Última señal: ${dateTime(p.connection.last_seen_at, p.timezone)}`)); row.append(conn);
    const arrival = node('td', p.arrival.first_at ? dateTime(p.arrival.first_at, p.timezone) : availability(p.arrival.availability) || 'Sin registro'); arrival.append(node('small', p.arrival.source === 'door' ? 'Primera señal registrada' : 'Primera actividad')); row.append(arrival);
    row.append(node('td', duration(p.activity.active_seconds)), node('td', percent(p.activity.productivity_percent)));
    const alerts = node('td', p.alerts.pending_count == null ? 'Sin acceso o datos' : p.alerts.primary ? `${alertNames[p.alerts.primary.kind]}${p.alerts.pending_count > 1 ? ` · ${p.alerts.pending_count - 1} más` : ''}` : p.alerts.complete ? 'Sin alertas pendientes' : 'Sin alertas registradas; datos parciales'); row.append(alerts); body.append(row);
  }
  table.append(body); wrap.append(table); roster.append(wrap);
  if (!dto.data.length) roster.append(node('p', 'No hay personas para estos filtros.', 'empty-state'));
  const pager = node('nav', null, 'supervisor-counts'); pager.setAttribute('aria-label', 'Páginas de personas');
  const previous = button('Anterior', 'previous'), next = button('Siguiente', 'next'); previous.disabled = pageIndex === 0; next.disabled = !dto.next_cursor;
  pager.append(previous, node('span', `Página ${pageIndex + 1}`), next); roster.append(pager); root.append(roster); explain(root);
}
function protection(dto, zone, devices = []) {
  const box = node('div'); box.append(badge(protectionNames[dto.state], dto.state));
  for (const r of dto.reasons) {
    const device = devices.find(d => d.id === r.device_id);
    box.append(node('small', [device?.hostname || (r.device_id ? 'Equipo causante fuera de esta página' : ''), reasonNames[r.code] || 'Confirmación pendiente', r.control, r.report_at ? `Reporte: ${dateTime(r.report_at, zone)}` : '', r.last_seen_at ? `Última señal: ${dateTime(r.last_seen_at, zone)}` : ''].filter(Boolean).join(' · ')));
  }
  return box;
}
export function renderDevices(root, current, zone) {
  root.replaceChildren(node('h2', 'Sus equipos'));
  root.append(node('p', `${current.protection ? 'Equipos, conexión y protección actuales' : 'Equipos y conexión actuales'}, independientemente de la fecha elegida. Última señal no indica la hora exacta de desconexión.`, 'help'));
  const page = current.devices;
  if (current.protection) root.append(protection(current.protection, zone, page.data));
  if (page.availability.status !== 'available') { root.append(node('p', availability(page.availability))); return; }
  root.append(node('p', `${page.data.length} de ${page.total} equipos`, 'help'));
  for (const d of page.data) { const row = node('div', null, `supervisor-device${d.protection ? ' has-protection' : ''}`); const info = node('div'); info.append(node('strong', d.hostname), node('small', `Versión ${d.agent_version || 'sin registro'} · Última señal: ${dateTime(d.connection.last_seen_at, zone)}`)); row.append(info, connection(d.connection)); if (d.protection) row.append(protection(d.protection, zone)); root.append(row); }
  if (!page.data.length) root.append(node('p', 'Sin equipos asignados.'));
  if (page.next_cursor) root.append(button('Cargar más equipos', 'devices-more'));
}
export function renderAlerts(root, alerts, zone) {
  root.replaceChildren(node('h2', 'Alertas del día'));
  root.append(node('p', `${number(alerts.pending_count)} pendientes · ${alerts.data.length} de ${number(alerts.total)} registradas`, 'help'));
  if (!alerts.complete) root.append(node('p', 'Información parcial. No se puede concluir que no hubo otras alertas.', 'supervisor-notice'));
  if (alerts.unavailable_types.includes('offline_in_schedule')) root.append(node('p', 'Sin conexión en horario: no disponible; no se conserva historial completo de conexión.', 'help'));
  for (const a of alerts.data) {
    const row = node('div', null, 'supervisor-alert'); row.append(node('strong', alertNames[a.kind]), badge(severityNames[a.severity], a.severity), node('small', a.reviewed ? 'Revisada' : 'Pendiente'));
    const e = a.evidence;
    if (e) row.append(node('p', [e.explanation, e.process_name || e.app_name, e.seconds != null ? duration(e.seconds) : '', e.first_at ? dateTime(e.first_at, zone) : '', e.last_at ? dateTime(e.last_at, zone) : ''].filter(Boolean).join(' · ')));
    root.append(row);
  }
  if (!alerts.data.length && alerts.complete) root.append(node('p', 'Sin alertas registradas.'));
  root.append(node('p', `Cálculo: ${dateTime(alerts.calculated_at, zone)} · Datos hasta: ${dateTime(alerts.data_through, zone)}`, 'help'));
  if (alerts.next_cursor) root.append(button('Cargar más alertas', 'alerts-more'));
}
export function renderPerson(root, dto, back) {
  root.replaceChildren(); const a = node('a', 'Volver a personas'); a.href = returnPath(back); root.append(a);
  const heading = node('div', null, 'supervisor-heading'), identity = node('div'); identity.append(node('p', 'Ficha diaria', 'eyebrow'), node('h1', dto.display_name), node('p', [dto.company?.name, dto.area?.name, dto.site?.name, dto.position?.name].filter(Boolean).join(' · '))); heading.append(identity); root.append(heading);
  const form = node('form', null, 'supervisor-date'); form.id = 'supervisor-date';
  const label = node('label', 'Fecha'), date = node('input'); date.type = 'date'; date.name = 'day'; date.required = true; date.value = dto.day; date.max = localDay(dto.timezone); label.append(date);
  const submit = node('button', 'Consultar'); submit.type = 'submit'; form.append(button('Hoy', 'today'), button('Ayer', 'yesterday'), label, submit); root.append(form);
  root.append(node('p', `${dto.day} · ${dto.timezone} · ${dto.day < localDay(dto.timezone) ? 'Día cerrado' : 'Día en curso'} · Actualizado: ${dateTime(dto.generated_at, dto.timezone)}`, 'help'));
  const grid = node('dl', null, 'supervisor-kpis'); grid.append(metric(dto.arrival.source === 'door' ? 'Primera señal registrada' : 'Llegada', dto.arrival.first_at ? dateTime(dto.arrival.first_at, dto.timezone) : '—', availability(dto.arrival.availability) || `Margen: ${number(dto.arrival.margin_minutes)} min · ${dto.arrival.late ? 'Fuera del margen' : 'Primera actividad, no marcación'}`), metric('Tiempo activo', duration(dto.activity.active_seconds), `Inactivo: ${duration(dto.activity.idle_seconds)} · Bloqueado: ${duration(dto.activity.locked_seconds)} (ya incluido en inactivo)`), metric('Productividad', percent(dto.activity.productivity_percent), availability(dto.activity.availability) || `Tiempo productivo: ${duration(dto.activity.workday_productive_seconds)} / jornada: ${duration(dto.activity.workday_expected_seconds)}`)); root.append(grid);
  root.append(node('p', `Actividad calculada: ${dateTime(dto.activity.calculated_at, dto.timezone)} · Datos hasta: ${dateTime(dto.activity.data_through, dto.timezone)}`, 'help'));
  const schedule = section('Jornada'); schedule.append(node('p', availability(dto.schedule.availability) || `${dto.schedule.day} · ${dateTime(dto.schedule.start_at, dto.timezone)} a ${dateTime(dto.schedule.end_at, dto.timezone)}`));
  if (dto.schedule.lunch) schedule.append(node('p', `Almuerzo: ${dateTime(dto.schedule.lunch.start_at, dto.timezone)} a ${dateTime(dto.schedule.lunch.end_at, dto.timezone)}`));
  for (const p of dto.schedule.work_periods) schedule.append(node('small', `Franja: ${dateTime(p.start_at, dto.timezone)} a ${dateTime(p.end_at, dto.timezone)}`));
  for (const a of dto.schedule.absences) schedule.append(node('p', `Ausencia aprobada: ${dateTime(a.start_at, dto.timezone)} a ${dateTime(a.end_at, dto.timezone)}`)); root.append(schedule);
  const alerts = section('Alertas del día', 'supervisor-alerts'); renderAlerts(alerts, dto.alerts, dto.timezone); root.append(alerts);
  const details = node('div', null, 'supervisor-columns');
  for (const [id, title] of [['supervisor-windows', 'Apps y sitios más usados'], ['supervisor-timeline', 'Cronología']]) { const s = section(title, id); s.append(node('p', dto.raw_detail_available ? 'Cargando…' : availability(dto.sections.activity) || 'Detalle no disponible para este alcance o período conservado.')); details.append(s); } root.append(details);
  const devices = section('Sus equipos', 'supervisor-devices'); renderDevices(devices, dto.current, dto.timezone); root.append(devices);
  if (dto.sections.protection) { const rules = section('Qué tiene bloqueado', 'supervisor-rules'); rules.append(node('p', 'Reglas actuales, independientemente de la fecha elegida.', 'help'), node('p', dto.sections.protection.status !== 'available' ? availability(dto.sections.protection) : availability(dto.sections.rules) || 'Cargando…')); root.append(rules); }
}
export function renderWindows(root, dto, expanded = false) {
  root.replaceChildren(node('h2', 'Apps y sitios más usados'));
  const total = dto.data.reduce((sum, r) => sum + r.active_seconds, 0);
  for (const r of dto.data.slice(0, expanded ? undefined : 10)) { const row = node('div', null, 'supervisor-usage'); row.append(node('span', r.site || r.process_name), node('span', duration(r.active_seconds))); const bar = node('progress'); bar.max = total || 1; bar.value = r.active_seconds; bar.setAttribute('aria-label', r.site || r.process_name); row.append(bar); root.append(row); }
  if (!dto.data.length) root.append(node('p', 'Sin usos registrados para esta fecha.'));
  if (!expanded && dto.data.length > 10) root.append(button(`Ver los ${dto.data.length} usos`, 'windows-more'));
}
export function renderTimeline(root, dto, zone) {
  root.replaceChildren(node('h2', 'Cronología'));
  for (const r of dto.data) { const row = node('div', null, 'supervisor-event'); row.append(node('strong', r.site || r.process_name), node('small', `${dateTime(r.started_at, zone)} a ${dateTime(r.ended_at, zone)}`)); root.append(row); }
  if (!dto.data.length) root.append(node('p', 'Sin eventos registrados para esta fecha.'));
  if (dto.next_offset != null) root.append(button('Cargar más eventos', 'timeline-more'));
}
export function rulesSummary(dto) {
  if (dto.conflict) return 'Hay un conflicto de reglas; no se puede confirmar qué bloqueos quedan configurados.';
  const names = { web: 'web', usb: 'USB', download: 'descargas', installation: 'instalación' };
  const kinds = [...new Set(dto.entries.filter(e => e.effect === 'deny').map(e => names[e.kind]).filter(Boolean))];
  const exceptions = dto.entries.filter(e => e.effect === 'allow').length;
  const different = dto.devices?.some(d => d.own_policy_ids?.length);
  return `${kinds.length ? `Reglas configuradas para ${kinds.join(', ')}` : 'Sin reglas de bloqueo configuradas para la persona'}; ${exceptions} excepciones vigentes.${different ? ' Hay reglas propias de equipos; pueden existir diferencias.' : ''}${dto.recompile_pending ? ' Hay cambios pendientes de aplicar.' : ''} Confirmación por equipo arriba.`;
}
