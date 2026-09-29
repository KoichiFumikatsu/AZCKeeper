import { api, ApiError, PLATFORM_TENANT, isUuid, setTenant, saveSession, readSession, refreshCsrf, goToLogin, errorMessage } from './api.js';
import { coordinator } from './coordinator.js';
import { operations, operationPaths } from './operations.js';

const page = document.body.dataset.page;
const surface = document.body.dataset.surface;
const $ = id => document.getElementById(id);
const number = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 2 });
const labels = { active: 'Activo', inactive: 'Inactivo', suspended: 'Suspendido', revoked: 'Revocado', decommissioned: 'Retirado', tenant: 'Empresa', site: 'Sede', area: 'Área', user: 'Usuario', device: 'Equipo', self: 'Propio usuario', scheduled: 'Programada', cancelled: 'Cancelada', allowed: 'Permitido', denied: 'Denegado', failed: 'Fallido', admin: 'Administrador', system: 'Sistema', integration: 'Integración' };
const label = value => labels[value] || value;
const dateTime = value => value ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Sin registro';
function node(tag, text, className) {
  const result = document.createElement(tag);
  if (text !== undefined) result.textContent = text;
  if (className) result.className = className;
  return result;
}
function showError(target, error) { target.textContent = error ? errorMessage(error) : ''; target.hidden = !error; }
function appendError(target, error) { target.append(node('p', errorMessage(error), 'error-message')); }
function query(path, values) {
  const url = new URL(path, location.origin);
  Object.entries(values).forEach(([key, value]) => { if (value !== null && value !== undefined) url.searchParams.set(key, value); });
  return url.pathname + url.search;
}

async function login() {
  const current = readSession();
  if (current) { location.replace(current.is_platform_admin ? '/admin/' : '/'); return; }
  const form = $('login-form');
  form.addEventListener('submit', async event => {
    event.preventDefault();
    $('login-submit').disabled = true;
    showError($('login-error'), null);
    $('login-status').textContent = 'Iniciando sesión…';
    try {
      await refreshCsrf();
      const body = { email: form.elements.email.value.trim(), password: form.elements.password.value };
      let session;
      try { session = await api('POST', '/auth/login', { body }); }
      catch (error) {
        if (error.status !== 401 || error.code !== 'invalid_credentials') throw error;
        // A surviving HttpOnly session makes /auth/csrf return a session token, not a prelogin token.
        try { await api('POST', '/auth/logout'); }
        catch (logoutError) { throw logoutError.status === 401 ? error : logoutError; }
        await refreshCsrf();
        session = await api('POST', '/auth/login', { body });
      }
      saveSession(session);
      form.elements.password.value = '';
      location.replace(session.is_platform_admin ? '/admin/' : '/');
    } catch (error) {
      showError($('login-error'), error);
      $('login-status').textContent = '';
      $('login-submit').disabled = false;
    }
  });
}

let tenant;
let tenants = [];
let session;
let timezone = 'UTC';
let busy = false;
let cache = new Map();
let permissions = new Map();
// Nombres de las personas para las listas que solo traen user_id. Sin permiso para ver personas se muestra el ID.
let userNames = new Map();
async function loadUserNames() {
  userNames = new Map();
  try { (await allPages('/users')).forEach(u => userNames.set(u.id, u.display_name)); }
  catch (error) { if (![401, 403].includes(error.status)) throw error; }
}
function personLink(id) {
  const name = userNames.get(id);
  if (!name) return id;
  const a = node('a', name); a.href = `/miembro.php?id=${encodeURIComponent(id)}`; return a;
}
const resources = {
  devices: { title: 'Equipos', path: () => `/tenants/${tenant}/devices`, prepare: loadUserNames, columns: [['Equipo', r => { const a = node('a', r.hostname); a.href = `/equipo.php?id=${encodeURIComponent(r.id)}`; return a; }], ['Estado', r => label(r.status)], ['Persona', r => personLink(r.user_id)], ['Sistema operativo', 'os_edition'], ['Agente', 'agent_version'], ['Última conexión', r => dateTime(r.last_seen_at)]], empty: 'Aún no hay equipos enrolados en tu alcance.' },
  users: { title: 'Usuarios', path: () => '/users', columns: [['Nombre', r => { const a = node('a', r.display_name); a.href = `/miembro.php?id=${encodeURIComponent(r.id)}`; return a; }], ['Correo', 'email'], ['Estado', r => label(r.status)], ['Acceso al portal', r => r.panel_login_enabled ? 'Habilitado' : 'Sin acceso'], ['Roles asignados', r => r.role_ids.length]], empty: 'Aún no hay usuarios en tu alcance.' },
  policies: { title: 'Reglas', path: () => '/policies', columns: [['Política', 'name'], ['Destino', r => label(r.target_type)], ['ID del destino', 'target_id'], ['Estado', r => r.enabled ? 'Habilitada' : 'Inactiva'], ['Reglas', r => r.rules.length], ['Versión', 'version']], empty: 'Aún no hay políticas configuradas en esta empresa.' },
  tenants: { title: 'Tenants', path: () => '/tenants', columns: [['Empresa', 'name'], ['ID', 'id'], ['Estado', r => label(r.status)], ['Zona horaria', 'timezone'], ['Autogestión de roles', r => r.rbac_self_management ? 'Habilitada' : 'Inactiva']], empty: 'No hay tenants accesibles.' },
  roles: { title: 'Roles de la empresa', path: () => '/roles', columns: [['Rol', 'name'], ['Alcance', r => label(r.scope.kind)], ['Recursos del alcance', r => r.scope.resource_ids.join(', ') || 'Sin restricción por ID'], ['Permisos', r => r.permissions.join(', ') || 'Sin permisos'], ['Estado', r => r.active ? 'Activo' : 'Inactivo']], empty: 'Aún no hay roles configurados en esta empresa.' },
  tiers: { title: 'Tiers de la empresa', path: () => '/tiers', columns: [['Tier', 'name'], ['Etiqueta', 'badge_label'], ['Módulos', r => r.entitlements.join(', ') || 'Sin módulos'], ['Estado', r => r.active ? 'Activo' : 'Inactivo']], empty: 'Aún no hay tiers configurados en esta empresa.' },
  releases: { title: 'Releases de plataforma', path: () => '/releases', columns: [['Versión', 'version'], ['Canal', 'channel'], ['Arquitectura', 'architecture'], ['Agente mínimo', 'min_agent_version'], ['Tamaño (MiB)', r => number.format(r.size_bytes / 1048576)], ['Publicación', r => dateTime(r.published_at)]], empty: 'Aún no hay releases publicados.' },
  audit: { title: 'Auditoría de la empresa', path: () => query('/audit', dateRange()), columns: [['Fecha', r => dateTime(r.at)], ['Acción', 'action'], ['Actor', r => `${label(r.actor_type)} · ${r.actor_id}`], ['Recurso', r => `${r.resource_type} · ${r.resource_id}`], ['Resultado', r => label(r.outcome)], ['Campos', r => r.changed_fields.join(', ')], ['Solicitud', 'request_id']], empty: 'No hay eventos de auditoría en este período.' },
};
function get(path, context = tenant) {
  const key = `${context}:${path}`;
  if (!cache.has(key)) cache.set(key, api('GET', path, { tenant: context }).catch(error => { cache.delete(key); throw error; }));
  return cache.get(key);
}
async function getPage(path, cursor = null, context = tenant) {
  const result = await get(query(path, { limit: 100, cursor }), context);
  if (!Array.isArray(result.data) || !(result.next_cursor === null || typeof result.next_cursor === 'string')) throw new ApiError(200, { code: 'invalid_response' });
  return result;
}
async function allPages(path, context = tenant) {
  let cursor = null;
  const seen = new Set();
  const rows = [];
  do {
    const result = await getPage(path, cursor, context);
    rows.push(...result.data);
    cursor = result.next_cursor;
    if (cursor && seen.has(cursor)) throw new ApiError(200, { code: 'invalid_response' });
    seen.add(cursor);
  } while (cursor);
  return rows;
}
function today() {
  const parts = new Intl.DateTimeFormat('en', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
  const part = name => parts.find(p => p.type === name).value;
  return `${part('year')}-${part('month')}-${part('day')}`;
}
function shiftDay(day, days) { const d = new Date(`${day}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + days); return d.toISOString().slice(0, 10); }
function dateRange() {
  const from = $('from')?.value || shiftDay(today(), -29);
  const to = $('to')?.value || shiftDay(today(), 1);
  const days = (Date.parse(to) - Date.parse(from)) / 86400000;
  if (!(days > 0 && days <= 366)) throw new Error('Selecciona un período de 1 a 366 días; la fecha final es excluyente.');
  return { from, to };
}
function reportPath() { return query('/reports/productivity', dateRange()); }

function luminance(color) {
  const values = color.slice(1).match(/../g).map(v => parseInt(v, 16) / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4);
  return values[0] * .2126 + values[1] * .7152 + values[2] * .0722;
}
function applyBranding(company) {
  const root = document.documentElement;
  ['--tenant-primary', '--tenant-accent', '--accent', '--on-accent'].forEach(name => root.style.removeProperty(name));
  $('tenant-brand').hidden = !company;
  $('tenant-logo').hidden = true;
  if (!company) return;
  $('tenant-name').textContent = company.branding?.display_name || company.name;
  const brand = company.branding || {};
  for (const [field, variable] of [['primary_color', '--tenant-primary'], ['accent_color', '--tenant-accent']]) {
    if (/^#[0-9a-f]{6}$/i.test(brand[field])) root.style.setProperty(variable, brand[field]);
  }
  if (/^#[0-9a-f]{6}$/i.test(brand.primary_color)) {
    const l = luminance(brand.primary_color);
    const dark = (l + .05) / (luminance('#303030') + .05);
    const light = (luminance('#F8F8F8') + .05) / (l + .05);
    if (Math.max(dark, light) >= 4.5) {
      root.style.setProperty('--accent', brand.primary_color);
      root.style.setProperty('--on-accent', dark >= light ? '#303030' : '#F8F8F8');
    }
  }
  if (brand.logo_url) {
    try {
      const url = new URL(brand.logo_url, location.origin);
      if (url.protocol === 'https:' || (url.origin === location.origin && url.protocol === 'http:')) {
        // The backend's legacy placeholder is replaced by the supplied real wordmark.
        $('tenant-logo').src = url.origin === location.origin && url.pathname === '/branding.svg' ? '/assets/brand/logo-main.png' : url.href;
        $('tenant-logo').alt = company.name;
        $('tenant-logo').onerror = () => { $('tenant-logo').hidden = true; };
        $('tenant-logo').hidden = false;
      }
    } catch { /* Keep the real AZC brand if a tenant URL is invalid. */ }
  }
}

async function navigation() {
  permissions = new Map();
  const links = [...document.querySelectorAll('[data-nav]')];
  const failures = [];
  const results = await Promise.allSettled(links.map(async link => {
    const key = link.dataset.nav;
    link.hidden = key !== 'home';
    if (key === page || (page === 'member' && key === 'users') || (page === 'device' && key === 'devices')) link.setAttribute('aria-current', 'page');
    if (key === 'home') return;
    try {
      await (operationPaths[key] ? get(operationPaths[key]) : key === 'reports' ? get(reportPath()) : getPage(resources[key].path()));
      permissions.set(key, true);
      link.hidden = false;
    } catch (error) {
      permissions.set(key, error);
      if (error.status !== 403 && error.status !== 401) failures.push(`${link.textContent}: ${errorMessage(error)}`);
    }
  }));
  results.forEach(result => { if (result.status === 'rejected') failures.push(errorMessage(result.reason)); });
  if (failures.length) showError($('page-error'), new Error(failures.join(' ')));
  if ($('platform-link')) $('platform-link').hidden = !session.is_platform_admin;
  $('navigation').hidden = false;
}
function table(parent, title, columns) {
  const region = node('div', undefined, 'table-region');
  region.tabIndex = 0;
  region.setAttribute('role', 'region');
  region.setAttribute('aria-label', title);
  const element = node('table');
  element.append(node('caption', title));
  const head = node('thead'); const header = node('tr');
  columns.forEach(([name]) => { const th = node('th', name); th.scope = 'col'; header.append(th); });
  head.append(header);
  const body = node('tbody');
  element.append(head, body); region.append(element); parent.append(region);
  return { region, body };
}
function appendRows(body, rows, columns) {
  rows.forEach(record => {
    const row = node('tr');
    columns.forEach(([, field], index) => {
      const cell = node(index === 0 ? 'th' : 'td');
      if (index === 0) cell.scope = 'row';
      const value = typeof field === 'function' ? field(record) : record[field];
      if (value instanceof Node) cell.append(value);
      else cell.textContent = value === null || value === undefined ? 'Sin dato' : String(value);
      row.append(cell);
    });
    body.append(row);
  });
}
async function listing(key) {
  const resource = resources[key];
  const panel = node('section', undefined, 'work-surface');
  $('content').append(panel);
  const status = node('p', 'Consultando…', 'help'); status.setAttribute('role', 'status');
  const problem = node('p', undefined, 'error-message'); problem.hidden = true; problem.setAttribute('role', 'alert');
  panel.append(status, problem);
  const { region, body } = table(panel, resource.title, resource.columns);
  region.hidden = true;
  const more = node('button', 'Cargar más'); more.type = 'button'; more.hidden = true;
  panel.append(more);
  let cursor = null; let count = 0; const seen = new Set();
  const path = resource.path(); const context = tenant;
  if (resource.prepare) await resource.prepare();
  async function loadMore() {
    more.disabled = true;
    showError(problem, null);
    try {
      const result = await getPage(path, cursor, context);
      if (result.next_cursor && seen.has(result.next_cursor)) throw new ApiError(200, { code: 'invalid_response' });
      seen.add(result.next_cursor);
      appendRows(body, result.data, resource.columns);
      count += result.data.length;
      cursor = result.next_cursor;
      region.hidden = count === 0;
      status.textContent = count === 0 ? resource.empty : `${number.format(count)} registros${cursor ? ' cargados; hay más resultados' : ''}.`;
      more.hidden = !cursor;
    } catch (error) {
      showError(problem, error);
      status.textContent = count ? `${number.format(count)} registros cargados. No se pudo completar el listado.` : 'Listado no disponible.';
      more.hidden = count === 0;
    } finally { more.disabled = false; }
  }
  more.addEventListener('click', loadMore);
  await loadMore();
}
async function metric(grid, title, task) {
  const card = node('div', undefined, 'work-surface');
  const value = node('dd', '—'); const note = node('dd', 'Consultando…', 'metric-note');
  card.append(node('dt', title), value, note); grid.append(card);
  try {
    value.textContent = number.format(await task());
    note.textContent = 'Registros consultados en tu alcance.';
  } catch (error) {
    if (error.status === 403) card.remove();
    else { note.textContent = errorMessage(error); note.classList.add('metric-error'); }
  }
}
async function dashboard() {
  const platform = surface === 'admin';
  $('content').append(node('p', platform ? 'Totales de los tenants accesibles, incluido el tenant reservado de plataforma.' : 'Datos de tu empresa dentro del alcance de tus permisos.', 'help'));
  const grid = node('dl', undefined, 'kpi-grid'); $('content').append(grid);
  const count = async path => (await allPages(path)).length;
  const total = async name => {
    let result = 0;
    for (const company of tenants) result += (await allPages(name === 'devices' ? `/tenants/${company.id}/devices` : `/${name}`, company.id)).length;
    return result;
  };
  const tasks = platform ? [
    ['Tenants accesibles', async () => tenants.length], ['Equipos enrolados', () => total('devices')],
    ['Usuarios', () => total('users')], ['Roles configurados', () => total('roles')],
    ['Permisos del catálogo', () => count('/permissions')], ['Tiers configurados', () => total('tiers')],
    ['Releases publicados', () => count('/releases')],
  ] : [
    ['Equipos enrolados', () => count(`/tenants/${tenant}/devices`)], ['Usuarios', () => count('/users')], ['Políticas', () => count('/policies')],
  ];
  const results = await Promise.allSettled(tasks.map(([title, task]) => metric(grid, title, task)));
  results.forEach(result => { if (result.status === 'rejected') appendError($('content'), result.reason); });
  if (!grid.childElementCount) $('content').append(node('p', 'Tu cuenta no tiene acceso a estos indicadores.', 'empty-state'));
}
function details(parent, fields) {
  const list = node('dl', undefined, 'details');
  fields.forEach(([key, value]) => { const row = node('div'); row.append(node('dt', key), node('dd', value === null || value === undefined ? 'Sin dato' : String(value))); list.append(row); });
  parent.append(list);
}
async function member() {
  const id = new URLSearchParams(location.search).get('id');
  if (!isUuid(id)) throw new Error('Selecciona un miembro desde Usuarios.');
  const user = await get(`/users/${id}`);
  const panel = node('section', undefined, 'work-surface');
  panel.append(node('h2', user.display_name));
  details(panel, [['Correo', user.email], ['Estado', label(user.status)], ['Acceso al portal', user.panel_login_enabled ? 'Habilitado' : 'Sin acceso'], ['ID de firma', user.firm_id], ['ID de área', user.area_id], ['ID de sede', user.site_id], ['ID de cargo', user.position_id], ['ID de horario', user.schedule_id], ['Roles asignados (ID)', user.role_ids.join(', ') || 'Sin roles']]);
  $('content').append(panel);
  const subscription = node('section', undefined, 'work-surface'); subscription.append(node('h2', 'Suscripción'));
  try {
    const data = await get(`/users/${id}/subscription`);
    details(subscription, [['Tier (ID)', data.tier_id], ['Estado', label(data.status)], ['Inicio', dateTime(data.starts_at)], ['Fin', data.ends_at ? dateTime(data.ends_at) : 'Sin fecha de fin']]);
  } catch (error) {
    if (error.status === 403) return;
    if (error.status === 404) subscription.append(node('p', 'No hay una suscripción disponible para este miembro en tu alcance.', 'empty-state'));
    else appendError(subscription, error);
  }
  $('content').append(subscription);
}
const controlStates = { applied: 'Aplicado', failed: 'Fallido', unsupported: 'No soportado', unknown: 'Desconocido' };
async function device() {
  const id = new URLSearchParams(location.search).get('id');
  if (!isUuid(id)) throw new Error('Selecciona un equipo desde Equipos.');
  const d = await get(`/devices/${id}`);
  const summary = node('section', undefined, 'work-surface');
  summary.append(node('h2', d.hostname));
  details(summary, [['Estado', label(d.status)], ['Versión del agente', d.agent_version], ['Última conexión', dateTime(d.last_seen_at)],
    ['Sistema operativo', d.os_edition], ['CPU', d.cpu], ['RAM', d.ram_bytes ? `${number.format(d.ram_bytes / 1073741824)} GB` : null],
    ['Cifrado de disco', d.encryption_state], ['Versión de política aplicada', d.policy_version]]);
  const owner = node('p'); const ownerLink = node('a', 'Ver la persona asignada'); ownerLink.href = `/miembro.php?id=${encodeURIComponent(d.user_id)}`; owner.append(ownerLink); summary.append(owner);
  $('content').append(summary);
  $('content').append(renameSection(d));

  // Estado declarado por el agente en su último reporte; no es una verificación independiente.
  const controls = node('section', undefined, 'work-surface'); controls.append(node('h2', 'Controles del equipo'));
  try {
    const report = await get(`/devices/${id}/security`);
    controls.append(node('p', `Último reporte del agente: ${dateTime(report.observed_at)}. Es el estado que declara el agente.`));
    const columns = [['Control', 'control_id'], ['Estado', r => controlStates[r.state] || r.state], ['Detalle', r => r.error_code || '—'], ['Observado', r => dateTime(r.observed_at)]];
    const failing = report.controls.filter(c => c.state !== 'applied').length;
    if (failing) controls.append(node('p', `${failing} control(es) no están aplicados.`, 'error-message'));
    appendRows(table(controls, 'Estado por control', columns).body, report.controls, columns);
  } catch (error) {
    if (error.status === 403) controls.append(node('p', 'Tu cuenta no tiene acceso al estado de los controles.', 'empty-state'));
    else if (error.status === 404) controls.append(node('p', 'El equipo aún no ha enviado un reporte de controles.', 'empty-state'));
    else appendError(controls, error);
  }
  $('content').append(controls);

  const logs = node('section', undefined, 'work-surface'); logs.append(node('h2', 'Registro reciente del agente'));
  try {
    const result = await get(query('/audit/client-logs', { device_id: id, limit: 50 }));
    if (!result.data.length) logs.append(node('p', 'No hay registros del agente en el período de retención.', 'empty-state'));
    else {
      const columns = [['Fecha', r => dateTime(r.at)], ['Nivel', 'level'], ['Componente', 'component'], ['Código', 'code'], ['Error', r => r.error_code || '—']];
      appendRows(table(logs, 'Últimos 50 registros', columns).body, result.data, columns);
    }
  } catch (error) {
    if (error.status === 403) logs.append(node('p', 'Tu cuenta no tiene acceso al registro del agente.', 'empty-state'));
    else appendError(logs, error);
  }
  $('content').append(logs);
}
// Cambio del nombre de Windows del equipo (comando rename_computer). La placa de activo ACT_0015 se convierte a
// ACT-0015: el guion bajo no es valido en nombres DNS. El agente lo aplica en el proximo reinicio o al reiniciar ahora.
const computerNamePattern = /^(?![0-9]+$)[A-Za-z0-9-]{1,15}$/;
function renameSection(d) {
  const section = node('section', undefined, 'work-surface'); section.append(node('h2', 'Cambiar nombre del equipo'));
  section.append(node('p', `Nombre actual en Windows: ${d.hostname}. Usa la placa de activo (por ejemplo ACT-0015). Máximo 15 caracteres: letras, números y guion.`, 'help'));
  const form = node('form'); form.className = 'toolbar'; form.noValidate = true;
  const label = node('label', 'Nombre nuevo'); const input = node('input'); input.name = 'computer_name'; input.maxLength = 15; input.autocomplete = 'off'; input.placeholder = 'ACT-0015'; label.append(input);
  const restartLabel = node('label'); const restart = node('input'); restart.type = 'checkbox'; restart.name = 'restart_now'; restartLabel.append(restart, ' Reiniciar el equipo ahora (si no, se aplica en el próximo reinicio)');
  const submit = node('button', 'Cambiar nombre'); submit.type = 'submit'; submit.className = 'primary';
  const status = node('p', undefined, 'help'); status.setAttribute('role', 'status');
  const problem = node('p', undefined, 'error-message'); problem.hidden = true; problem.setAttribute('role', 'alert');
  input.addEventListener('input', () => { input.value = input.value.toUpperCase().replace(/_/g, '-').replace(/\s+/g, ''); });
  form.addEventListener('submit', async event => {
    event.preventDefault(); showError(problem, null); status.textContent = '';
    const name = input.value.trim();
    if (!computerNamePattern.test(name)) { showError(problem, new Error('Nombre no válido: de 1 a 15 caracteres, solo letras, números y guion, y no solo números.')); return; }
    if (restart.checked && !confirm(`El equipo ${d.hostname} se reiniciará de inmediato y pasará a llamarse ${name}. ¿Continuar?`)) return;
    submit.disabled = true;
    try {
      await api('POST', `/devices/${d.id}/commands`, {
        body: { type: 'rename_computer', reason: 'Cambio de nombre desde el panel', expires_at: new Date(Date.now() + 23 * 3600 * 1000).toISOString().replace(/\.\d{3}Z$/, 'Z'), parameters: { computer_name: name, restart_now: restart.checked } },
        idempotencyKey: crypto.randomUUID(),
      });
      status.textContent = restart.checked
        ? `Orden enviada. El equipo la recibe en su próxima sincronización (unos 2 minutos), se renombra a ${name} y se reinicia.`
        : `Orden enviada. El equipo la recibe en su próxima sincronización y pasará a llamarse ${name} en su próximo reinicio.`;
      input.value = '';
    } catch (error) {
      showError(problem, error.code === 'agent_too_old' ? new Error('El agente de este equipo es anterior a 4.0.8: actualízalo antes de cambiarle el nombre.') : error);
    } finally { submit.disabled = false; }
  });
  form.append(label, restartLabel, submit); section.append(form, status, problem);
  return section;
}
async function reports() {
  const data = await get(reportPath());
  if (!Array.isArray(data.series)) throw new ApiError(200, { code: 'invalid_response' });
  const panel = node('section', undefined, 'work-surface'); $('content').append(panel);
  details(panel, [['Período', `${data.from} → ${data.to} (fin excluido)`], ['Zona horaria', data.timezone], ['Cobertura', data.series.length ? `${number.format(data.coverage_percent)} %` : 'Sin datos'], ['Calculado', dateTime(data.calculated_at)], ['Datos hasta', dateTime(data.data_through)]]);
  if (!data.series.length) { panel.append(node('p', 'No hay datos de productividad para este período en tu alcance.', 'empty-state')); return; }
  const percent = value => value === null ? 'Sin dato' : `${number.format(value)} %`;
  const columns = [['Día', 'day'], ['Actividad (h)', r => number.format(r.active_seconds / 3600)], ['Inactividad (h)', r => number.format(r.idle_seconds / 3600)], ['Focus / 100', r => r.focus_score === null ? 'Sin dato' : number.format(r.focus_score)], ['Productividad', r => percent(r.productivity_percent)]];
  appendRows(table(panel, 'Productividad por día', columns).body, data.series, columns);
}

async function context() {
  applyBranding(null);
  if (session.is_platform_admin) {
    // A company account cannot select the reserved platform tenant (AdminAuth::context).
    try { tenants = await allPages('/tenants', PLATFORM_TENANT); }
    catch (error) {
      if ([403, 404].includes(error.status)) { goToLogin(); return false; }
      throw error;
    }
    const selected = sessionStorage.getItem('keeper.presentation.tenant');
    tenant = tenants.some(t => t.id === selected) ? selected : (surface === 'portal' ? tenants.find(t => t.id !== PLATFORM_TENANT)?.id : PLATFORM_TENANT);
    if (!tenant || !tenants.some(t => t.id === tenant)) throw new Error('No hay una empresa accesible para esta superficie.');
    const select = $('tenant-select'); select.replaceChildren();
    tenants.forEach(company => { const option = node('option', company.name); option.value = company.id; select.append(option); });
    select.value = tenant;
    $('tenant-control').hidden = surface === 'admin' && ['home', 'tenants', 'releases'].includes(page);
  } else {
    tenant = session.tenant_id;
    $('tenant-control').hidden = true;
  }
  setTenant(tenant);
  try {
    const company = await get(`/tenants/${tenant}`);
    timezone = company.timezone;
    applyBranding(company);
  } catch (error) {
    timezone = 'UTC';
    showError($('page-error'), new Error(`No se pudo consultar la identidad de la empresa. ${errorMessage(error)}`));
  }
  if ($('from') && !$('from').value) { $('from').value = shiftDay(today(), -29); $('to').value = shiftDay(today(), 1); }
  return true;
}
async function load() {
  if (busy) return;
  busy = true;
  cache = new Map();
  $('refresh').disabled = true; $('tenant-select').disabled = true;
  $('workspace').hidden = true; $('navigation').hidden = true;
  $('content').replaceChildren(); $('content').setAttribute('aria-busy', 'true');
  showError($('page-error'), null); $('page-status').textContent = 'Consultando datos y permisos…';
  try {
    if (!await context()) return;
    await navigation();
    $('workspace').hidden = false;
    const allowed = permissions.get(page === 'member' ? 'users' : page === 'device' ? 'devices' : page);
    if (allowed instanceof Error) {
      if ([401, 403].includes(allowed.status)) $('workspace').hidden = true;
      throw allowed;
    }
    if (operationPaths[page]) await operations({ page, tenant, today, shiftDay });
    else if (surface === 'portal' && ['home', 'users', 'member', 'reports', 'policies'].includes(page)) {
      await coordinator({ page, tenant, timezone, get, allPages, today, shiftDay, query, permissions });
    }
    else if (page === 'home') await dashboard();
    else if (page === 'member') await member();
    else if (page === 'device') await device();
    else if (page === 'reports') await reports();
    else await listing(page);
    $('page-status').textContent = `Consulta finalizada a las ${new Intl.DateTimeFormat('es-CO', { timeStyle: 'medium' }).format(new Date())}. Fechas de eventos en la zona horaria del navegador.`;
  } catch (error) {
    showError($('page-error'), error); $('page-status').textContent = 'No se pudo completar la consulta.';
  } finally {
    busy = false; $('refresh').disabled = false; $('tenant-select').disabled = false;
    $('content').setAttribute('aria-busy', 'false');
  }
}
async function start() {
  session = readSession();
  if (!session) { goToLogin(); return; }
  if (surface === 'admin' && !session.is_platform_admin) { location.replace('/'); return; }
  $('logout').addEventListener('click', async () => {
    $('logout').disabled = true;
    try { await refreshCsrf(); await api('POST', '/auth/logout'); goToLogin(); }
    catch (error) { showError($('page-error'), error); $('logout').disabled = false; }
  });
  $('refresh').addEventListener('click', load);
  $('tenant-select').addEventListener('change', () => { sessionStorage.setItem('keeper.presentation.tenant', $('tenant-select').value); load(); });
  $('date-form')?.addEventListener('submit', event => { event.preventDefault(); load(); });
  await load();
}
window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
if (page === 'login') login();
else if (surface !== 'public') start().catch(error => showError($('page-error'), error));
