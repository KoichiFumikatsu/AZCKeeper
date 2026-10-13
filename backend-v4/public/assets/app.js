import { api, ApiError, PLATFORM_TENANT, isUuid, setTenant, saveSession, readSession, refreshCsrf, goToLogin, errorMessage } from './api.js';
import { applyNavAccess } from './nav-access.js';
import { coordinator } from './coordinator.js';
import './tablekit.js';
import { operations, operationPaths } from './operations.js';
import { intake, intakeProbe } from './intake.js';
import { canConfigureHardening, loadHardeningAccess, loadHardeningSettings, hardeningSummary, openHardeningSettings, withReauth, canceled } from './hardening-settings.js';
import './menu.js';
import { wireDialog } from './dialogs.js';
import { onboardingBanner, returnBand } from './onboarding.js';
import { mountRelocations } from './relocations.js';

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
// Error anunciado como alerta (revisión UX M11): no se lee como un mensaje informativo más.
function appendError(target, error) { const p = node('p', errorMessage(error), 'error-message'); p.setAttribute('role', 'alert'); target.append(p); }
function query(path, values) {
  const url = new URL(path, location.origin);
  Object.entries(values).forEach(([key, value]) => { if (value !== null && value !== undefined) url.searchParams.set(key, value); });
  return url.pathname + url.search;
}

// Contraseña temporal: la cuenta la cambia antes de usar el panel (la API rechaza todo lo demás mientras tanto).
function passwordChange(session, current) {
  const box = $('login-form').parentElement;
  $('form-title').textContent = 'Crea tu contraseña';
  $('login-form').hidden = true;
  const form = node('form'); form.noValidate = true;
  form.append(node('p', 'Tu cuenta tiene una contraseña temporal. Elige una propia de al menos 12 caracteres para continuar.', 'help'));
  const field = (text, name, value = '') => { const l = node('label', text); const i = node('input'); i.type = 'password'; i.name = name; i.value = value; i.autocomplete = name === 'current' ? 'current-password' : 'new-password'; l.append(i); form.append(l); return i; };
  const now = field('Contraseña temporal', 'current', current || '');
  const next = field('Contraseña nueva', 'next');
  const again = field('Repite la contraseña nueva', 'again');
  const error = node('p', undefined, 'error-message'); error.hidden = true; error.setAttribute('role', 'alert');
  const submit = node('button', 'Guardar y entrar', 'primary'); submit.type = 'submit';
  form.append(error, submit); box.append(form);
  (current ? next : now).focus();
  form.addEventListener('submit', async event => {
    event.preventDefault(); error.hidden = true;
    if (next.value.length < 12) { showError(error, new Error('La contraseña nueva debe tener al menos 12 caracteres.')); return; }
    if (next.value !== again.value) { showError(error, new Error('Las contraseñas nuevas no coinciden.')); return; }
    submit.disabled = true;
    try {
      const fresh = await api('POST', '/auth/password', { body: { current_password: now.value, new_password: next.value } });
      saveSession(fresh); location.replace('/');
    } catch (e) { showError(error, e.code === 'weak_password' ? new Error('La contraseña nueva debe ser distinta de la temporal y de tu correo.') : e.code === 'invalid_credentials' ? new Error('La contraseña temporal no es correcta.') : e); submit.disabled = false; }
  });
}
async function login() {
  const current = readSession();
  if (current && new URLSearchParams(location.search).has('cambiar')) { passwordChange(current); return; }
  if (current) { location.replace('/'); return; }
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
      const typed = form.elements.password.value;
      form.elements.password.value = '';
      if (session.must_change_password) { $('login-status').textContent = ''; passwordChange(session, typed); return; }
      location.replace('/');
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
  devices: { title: 'Equipos', path: () => `/tenants/${tenant}/devices`, prepare: loadUserNames, columns: [['Equipo', r => { const a = node('a', r.hostname); a.href = `/equipo.php?id=${encodeURIComponent(r.id)}`; return a; }], ['Estado', r => label(r.status)], ['Persona', r => personLink(r.user_id)], ['Sistema operativo', 'os_edition'], ['Agente', 'agent_version'], ['Última conexión', r => dateTime(r.last_seen_at)], ['K3 abierto', r => r.legacy_k3?.running === true ? badge('Sí', 'bad') : r.legacy_k3?.running === false ? 'No' : null]], empty: 'Aún no hay equipos enrolados en tu alcance.' },
  users: { title: 'Usuarios', path: () => '/users', columns: [['Nombre', r => { const a = node('a', r.display_name); a.href = `/miembro.php?id=${encodeURIComponent(r.id)}`; return a; }], ['Correo', 'email'], ['Estado', r => label(r.status)], ['Cuenta del panel', r => r.panel_login_enabled ? 'Habilitada' : 'Sin cuenta'], ['Acceso al panel', r => r.access?.role_name || 'Sin acceso al panel']], empty: 'Aún no hay usuarios en tu alcance.' },
  policies: { title: 'Reglas', path: () => '/policies', columns: [['Política', 'name'], ['Destino', r => label(r.target_type)], ['ID del destino', 'target_id'], ['Estado', r => r.enabled ? 'Habilitada' : 'Inactiva'], ['Reglas', r => r.rules.length], ['Versión', 'version']], empty: 'Aún no hay políticas configuradas en esta empresa.' },
  tenants: { title: 'Tenants', path: () => '/tenants', columns: [['Empresa', 'name'], ['ID', 'id'], ['Estado', r => label(r.status)], ['Zona horaria', 'timezone'], ['Autogestión de roles', r => r.rbac_self_management ? 'Habilitada' : 'Inactiva']], empty: 'No hay tenants accesibles.' },
  roles: { title: 'Roles de la empresa', path: () => '/roles', columns: [['Rol', 'name'], ['Permisos', r => r.permissions.join(', ') || 'Sin permisos'], ['Estado', r => r.active ? 'Activo' : 'Inactivo']], empty: 'Aún no hay roles configurados en esta empresa.' },
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
// Reportes muestra «Hasta» incluido; la API espera el fin excluido, así que se suma un día al consultar.
const inclusiveDates = () => $('date-form')?.dataset.inclusive === 'true';
function dateRange() {
  const from = $('from')?.value || shiftDay(today(), -29);
  const typed = $('to')?.value;
  const to = typed ? (inclusiveDates() ? shiftDay(typed, 1) : typed) : shiftDay(today(), 1);
  const days = (Date.parse(to) - Date.parse(from)) / 86400000;
  if (!(days > 0 && days <= 366)) throw new Error(inclusiveDates() ? 'Selecciona un período de 1 a 366 días; las dos fechas se incluyen.' : 'Selecciona un período de 1 a 366 días; la fecha final es excluyente.');
  return { from, to };
}
function reportPath() { return query('/reports/productivity', dateRange()); }

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
  // El color de la empresa solo marca su logo y nombre: el panel conserva la paleta de Keeper (indigo).
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

// Cada pagina sondeaba ~10 rutas para decidir el menu: con varias paginas seguidas agotaba el limite de la API.
// Se recuerda que secciones estan permitidas (no los datos) 5 minutos por empresa; la API sigue autorizando todo.
const NAV_CACHE = 'keeper.presentation.nav';
function cachedNav() {
  try {
    const saved = JSON.parse(sessionStorage.getItem(NAV_CACHE));
    return saved && saved.tenant === tenant && saved.expires > Date.now() ? saved.allowed : null;
  } catch { return null; }
}
async function navigation() {
  permissions = new Map();
  const links = [...document.querySelectorAll('[data-nav]')];
  const failures = [];
  const cached = cachedNav();
  const results = await Promise.allSettled(links.map(async link => {
    const key = link.dataset.nav;
    link.hidden = key !== 'home';
    if (key === page || (page === 'member' && key === 'users') || (page === 'device' && key === 'devices')) link.setAttribute('aria-current', 'page');
    if (key === 'home') return;
    // La seccion actual siempre se consulta: sus datos se necesitan igual y el error real se muestra.
    const current = key === page || (page === 'member' && key === 'users') || (page === 'device' && key === 'devices');
    if (cached && !current && key in cached) {
      if (cached[key]) { permissions.set(key, true); link.hidden = false; }
      else permissions.set(key, new ApiError(403, { code: 'permission_denied' }));
      return;
    }
    try {
      await (operationPaths[key] ? get(operationPaths[key]) : key === 'intake' ? get(intakeProbe) : key === 'reports' ? get(reportPath()) : getPage(resources[key].path()));
      permissions.set(key, true);
      link.hidden = false;
    } catch (error) {
      permissions.set(key, error);
      if (error.status !== 403 && error.status !== 401) failures.push(`${link.textContent}: ${errorMessage(error)}`);
    }
  }));
  results.forEach(result => { if (result.status === 'rejected') failures.push(errorMessage(result.reason)); });
  if (!failures.length) {
    const allowed = {}; permissions.forEach((value, key) => { if (value === true) allowed[key] = true; else if ([401, 403].includes(value?.status)) allowed[key] = false; });
    try { sessionStorage.setItem(NAV_CACHE, JSON.stringify({ tenant, expires: Date.now() + 300000, allowed })); } catch { /* sin cache */ }
  }
  if (failures.length) showError($('page-error'), new Error(failures.join(' ')));
  if ($('platform-group')) $('platform-group').hidden = !session.is_platform_admin;
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
    ['Permisos del catálogo', () => count('/permissions')],
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
// Smart App Control según el inventario del agente (4.0.19+); sin dato = Desconocido.
// Keeper 3 que sigue en el equipo tras pasar a v4 (inventario legacy_k3). v4 solo lo detecta: no lo cierra ni lo desinstala.
function legacyK3State(k3) {
  if (!k3) return { text: 'Desconocido', tone: '', note: 'El agente aún no lo ha reportado.' };
  if (k3.running === true) return { text: 'Abierto', tone: 'bad', note: 'K3 sigue corriendo en este equipo además de Keeper v4.' };
  if (k3.running === false && k3.autostart === true) return { text: 'Cerrado pero arranca al iniciar sesión', tone: 'warn', note: 'Su autoarranque sigue en Windows.' };
  if (k3.running === false && k3.autostart === false && k3.installed === true) return { text: 'Instalado, no arranca', tone: 'info', note: '' };
  if (k3.running === false && k3.autostart === false && k3.installed === false) return { text: 'No está', tone: 'good', note: '' };
  return { text: 'Desconocido', tone: '', note: 'El agente no pudo leer todos los datos.' };
}
const sacStates = { off: 'Apagado', on: 'Activo', evaluation: 'En evaluación', unknown: 'Desconocido' };
// Códigos del registro del agente con texto propio en la ficha (los demás se muestran tal cual).
const agentLogCodes = {
  smart_app_control_disabled: 'Smart App Control desactivado (aplica al reiniciar)', smart_app_control_disable_failed: 'No se pudo desactivar Smart App Control',
  update_launch_failed: 'Windows no dejó arrancar el instalador de la actualización', update_apply_stalled: 'La actualización no terminó; se reintentará',
  legacy_k3_running: 'Keeper 3 (K3) está abierto en el equipo', legacy_k3_autostart: 'Keeper 3 (K3) arranca al iniciar sesión', legacy_k3_gone: 'Keeper 3 (K3) ya no corre ni arranca',
};
const controlStates = { applied: 'Aplicado', failed: 'Fallido', unsupported: 'No soportado', unknown: 'Desconocido' };
const controlTones = { applied: 'good', failed: 'bad', unsupported: 'warn', unknown: '' };
const badge = (text, tone) => node('span', text, `badge${tone ? ` ${tone}` : ''}`);
const clock = () => new Intl.DateTimeFormat('es-CO', { timeStyle: 'short' }).format(new Date()).replace(/\.$/, '');
function ago(value) {
  if (!value) return null;
  const minutes = Math.max(0, Math.round((Date.now() - Date.parse(value)) / 60000));
  if (minutes < 1) return 'hace menos de un minuto';
  if (minutes < 60) return `hace ${minutes} min`;
  const hours = Math.round(minutes / 60);
  return hours < 48 ? `hace ${hours} h` : `hace ${Math.round(hours / 24)} días`;
}
// Como el Inicio: «en línea» es un equipo activo con señal en los últimos 5 minutos.
function connectionState(d) {
  if (d.status !== 'active') return { text: label(d.status), tone: 'warn', note: 'El equipo no está activo: no recibe órdenes.' };
  if (!d.last_seen_at) return { text: 'Sin conexión', tone: 'warn', note: 'El agente aún no se ha conectado.' };
  const online = Date.now() - Date.parse(d.last_seen_at) <= 300000;
  return { text: online ? 'En línea' : 'Sin conexión', tone: online ? 'good' : 'warn', note: `Última señal ${ago(d.last_seen_at)}.` };
}
function hardeningState(status) {
  if (!status) return { text: 'Desconocido', tone: '', note: 'Tu cuenta no puede consultar el endurecimiento de este equipo.' };
  const text = hardeningStates[status.state] || status.state;
  if (status.state === 'hardened') return { text: 'Endurecido', tone: 'good', note: status.hardened_at ? `Desde ${dateTime(status.hardened_at)}` : '' };
  if (['none', 'unhardened', 'waiting_panel'].includes(status.state)) return { text: 'No endurecido', tone: 'warn', note: text };
  if (status.state === 'running') return { text: 'En curso', tone: 'info', note: 'El agente está aplicando la orden.' };
  return { text, tone: 'bad', note: status.detail || '' };
}
// No hay un campo «bloqueado»: se deduce de la última orden de bloquear/desbloquear ejecutada del historial.
function lockState(rows) {
  if (!rows) return { text: 'Desconocido', tone: '', note: 'Sin acceso al historial de órdenes de este equipo.' };
  const now = Date.now();
  const list = rows.filter(r => r.type === 'lock' || r.type === 'unlock').sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at));
  const done = list.find(r => r.status === 'succeeded');
  const waiting = list.find(r => ['pending', 'delivered', 'running'].includes(r.status) && Date.parse(r.expires_at) > now && (!done || Date.parse(r.created_at) > Date.parse(done.created_at)));
  const state = done ? (done.type === 'lock' ? { text: 'Bloqueado', tone: 'bad' } : { text: 'No bloqueado', tone: 'good' }) : { text: 'Desconocido', tone: '' };
  let note = done ? `Según la última orden ejecutada (${commandTypes[done.type]}, ${dateTime(done.result?.completed_at || done.created_at)}).` : 'El historial no tiene órdenes de bloquear o desbloquear ejecutadas.';
  if (waiting) note += ` Hay una orden de ${waiting.type === 'lock' ? 'bloquear' : 'desbloquear'} sin confirmar.`;
  return { ...state, note };
}
function facts(parent, rows, className = 'details') {
  const list = node('dl', undefined, className);
  const cells = {};
  rows.forEach(([key, value, name]) => {
    const row = node('div'); const dd = node('dd');
    if (value instanceof Node) dd.append(value); else dd.textContent = value === null || value === undefined ? 'Sin dato' : String(value);
    row.append(node('dt', key), dd); list.append(row); if (name) cells[name] = dd;
  });
  parent.append(list);
  return cells;
}
function stateCell(cell, state) {
  cell.replaceChildren(badge(state.text, state.tone));
  if (state.note) cell.append(node('small', state.note, 'state-note'));
}
// Historial de órdenes del equipo: una sola consulta alimenta el resumen (bloqueo) y la tabla del historial.
function commandLog(d) {
  const listeners = []; let loading = null;
  const log = { rows: undefined, error: null, on: f => listeners.push(f) };
  log.load = () => {
    if (loading) return loading;
    loading = (async () => {
      try {
        const rows = []; let cursor = null; const seen = new Set();
        for (let i = 0; i < 20; i++) {
          const result = await api('GET', query(`/devices/${d.id}/commands`, { limit: 100, cursor }));
          rows.push(...result.data); cursor = result.next_cursor;
          if (!cursor || seen.has(cursor)) break; seen.add(cursor);
        }
        log.rows = rows; log.error = null;
      } catch (error) { log.rows = null; log.error = error; }
      finally { loading = null; }
      listeners.forEach(f => f(log));
      return log;
    })();
    return loading;
  };
  return log;
}
async function optionalData(task) { try { return await task(); } catch { return null; } }
async function device() {
  const id = new URLSearchParams(location.search).get('id');
  if (!isUuid(id)) throw new Error('Selecciona un equipo desde Equipos.');
  const d = await get(`/devices/${id}`);
  const log = commandLog(d);
  const [owner, org, hardening] = await Promise.all([
    d.user_id ? optionalData(() => get(`/users/${d.user_id}`)) : null,
    optionalData(() => allPages('/organization')),
    api('GET', `/admin/devices/${d.id}/hardening`).catch(error => { if ([403, 404].includes(error.status)) return null; throw error; }),
    log.load(),
  ]);
  const header = deviceSummary(d, owner, org, hardening, log);
  $('content').append(header.section);
  const remote = await remoteActionsSection(d, log);
  if (remote) $('content').append(remote);
  $('content').append(renameSection(d, log));
  const hardeningBox = await hardeningSection(d, hardening, status => header.hardening(status), log);
  if (hardeningBox) $('content').append(hardeningBox);
  $('content').append(await controlsSection(d));
  $('content').append(await historySection(d, log));
}
// Encabezado de la ficha: quién lo usa, dónde, con qué agente, si está en línea, endurecido o bloqueado.
function deviceSummary(d, owner, org, hardening, log) {
  const section = node('section', undefined, 'work-surface device-summary');
  section.append(node('h2', d.hostname));
  const chips = node('div', undefined, 'device-chips'); chips.setAttribute('aria-label', 'Estado del equipo');
  section.append(chips);
  let person;
  if (!d.user_id) person = 'Sin persona asignada';
  else { person = node('a', owner?.display_name || 'Ver la persona asignada'); person.href = `/miembro.php?id=${encodeURIComponent(d.user_id)}`; }
  const site = !owner ? 'No disponible' : !owner.site_id ? 'Sin sede' : org?.find(o => o.id === owner.site_id)?.name || 'No disponible';
  const connection = connectionState(d);
  const lastSeen = d.last_seen_at ? `${dateTime(d.last_seen_at)} · ${ago(d.last_seen_at)}` : 'Sin registro';
  const cells = facts(section, [['Persona asignada', person], ['Sede', site], ['Versión del agente', d.agent_version || 'Sin versión'],
    ['Última conexión', lastSeen], ['Conexión', null, 'connection'], ['Endurecimiento', null, 'hardening'], ['Bloqueo', null, 'lock'],
    ['Keeper 3 (K3)', null, 'legacyK3']], 'details device-facts');
  stateCell(cells.legacyK3, legacyK3State(d.legacy_k3));
  const more = node('details', undefined, 'device-more'); more.append(node('summary', 'Datos técnicos del equipo'));
  details(more, [['Estado del equipo', label(d.status)], ['Sistema operativo', d.os_edition], ['CPU', d.cpu], ['RAM', d.ram_bytes ? `${number.format(d.ram_bytes / 1073741824)} GB` : null],
    ['Cifrado de disco', d.encryption_state], ['Versión de política aplicada', d.policy_version],
    ['Smart App Control', sacStates[d.smart_app_control] || sacStates.unknown]]);
  section.append(more);
  // Fuera del desplegable para que se vea sin abrirlo.
  if (d.smart_app_control === 'on') section.append(node('p', 'Smart App Control: Activo. Puede bloquear actualizaciones de Keeper.', 'error-text'));
  const states = { connection, hardening: hardeningState(hardening), lock: lockState(log.rows) };
  const draw = () => {
    chips.replaceChildren(badge(states.connection.text, states.connection.tone), badge(`Endurecimiento: ${states.hardening.text.toLowerCase()}`, states.hardening.tone), badge(`Bloqueo: ${states.lock.text.toLowerCase()}`, states.lock.tone));
    Object.entries(states).forEach(([key, state]) => stateCell(cells[key], state));
  };
  log.on(current => { states.lock = lockState(current.rows); draw(); });
  draw();
  return { section, hardening: status => { states.hardening = hardeningState(status); draw(); } };
}
// Estado declarado por el agente en su último reporte; no es una verificación independiente.
async function controlsSection(d) {
  const controls = node('section', undefined, 'work-surface'); controls.append(node('h2', 'Controles del equipo'));
  try {
    const report = await get(`/devices/${d.id}/security`);
    controls.append(node('p', `Último reporte del agente: ${dateTime(report.observed_at)}${report.observed_at ? ` (${ago(report.observed_at)})` : ''}. Es el estado que declara el agente, no una verificación independiente.`, 'help'));
    const columns = [['Control', 'control_id'], ['Estado', r => badge(controlStates[r.state] || r.state, controlTones[r.state] || '')], ['Detalle', r => r.error_code || '—'], ['Observado', r => dateTime(r.observed_at)]];
    const failing = report.controls.filter(c => c.state !== 'applied');
    if (failing.length) controls.append(node('p', `${failing.length} de ${report.controls.length} controles no están aplicados. Aparecen primero.`, 'error-message'));
    else if (report.controls.length) controls.append(node('p', 'Todos los controles reportados están aplicados.', 'help'));
    const ordered = [...failing, ...report.controls.filter(c => c.state === 'applied')];
    appendRows(table(controls, 'Estado por control', columns).body, ordered, columns);
  } catch (error) {
    if (error.status === 403) controls.append(node('p', 'Tu cuenta no tiene acceso al estado de los controles.', 'empty-state'));
    else if (error.status === 404) controls.append(node('p', 'El equipo aún no ha enviado un reporte de controles.', 'empty-state'));
    else appendError(controls, error);
  }
  return controls;
}
// Historial al final de la ficha: órdenes enviadas (con su estado real según la API) y registro del agente.
async function historySection(d, log) {
  const section = node('section', undefined, 'work-surface device-history'); section.append(node('h2', 'Historial del equipo'));
  const head = node('div', undefined, 'section-head remote-history-head'); head.append(node('h3', 'Órdenes enviadas'));
  const refresh = node('button', 'Actualizar historial', 'link-button'); refresh.type = 'button'; head.append(refresh);
  const checked = node('p', undefined, 'help history-checked');
  const history = node('div', undefined, 'remote-history');
  section.append(head, node('p', '«Pendiente» y «Entregado» significan que la orden aún no tiene confirmación del equipo. «Ejecutado» o «Falló» es lo que informó el agente.', 'help'), checked, history);
  const render = current => {
    refresh.disabled = false;
    checked.textContent = `Última consulta: ${clock()}.`;
    history.replaceChildren();
    if (current.error) {
      if (current.error.status === 403) history.append(node('p', 'Tu cuenta no tiene acceso al historial de órdenes de este equipo.', 'empty-state'));
      else appendError(history, current.error);
      return;
    }
    if (!current.rows.length) { history.append(node('p', 'Aún no se han enviado órdenes a este equipo.', 'empty-state')); return; }
    const now = Date.now();
    const recent = [...current.rows].sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at)).slice(0, 20);
    const columns = [['Orden', r => commandTypes[r.type] || r.type], ['Enviada', r => dateTime(r.created_at)],
      ['Estado', r => {
        const stale = ['pending', 'delivered'].includes(r.status) && Date.parse(r.expires_at) <= now;
        const [text, tone] = commandStates[stale ? 'expired' : r.status] || [r.status, ''];
        return badge(text, tone);
      }],
      ['Resultado', r => r.result ? `${commandCodes[r.result.code] || r.result.code} · ${dateTime(r.result.completed_at)}` : '—'],
      ['Vence', r => dateTime(r.expires_at)]];
    appendRows(table(history, `Últimas ${recent.length} órdenes de este equipo`, columns).body, recent, columns);
    history.append(node('p', 'Quién envió cada orden y su motivo quedan en Auditoría.', 'help'));
  };
  log.on(render);
  render(log);
  refresh.addEventListener('click', () => { refresh.disabled = true; log.load(); });

  section.append(node('h3', 'Registro reciente del agente', 'history-subhead'));
  try {
    const result = await get(query('/audit/client-logs', { device_id: d.id, limit: 50 }));
    if (!result.data.length) section.append(node('p', 'No hay registros del agente en el período de retención.', 'empty-state'));
    else {
      const columns = [['Fecha', r => dateTime(r.at)], ['Nivel', 'level'], ['Componente', 'component'], ['Código', r => r.component === 'DeviceLock' && r.code === 'local_unlock' ? 'Desbloqueo con PIN local' : agentLogCodes[r.code] || r.code], ['Error', r => r.error_code || '—']];
      appendRows(table(section, 'Últimos 50 registros', columns).body, result.data, columns);
    }
  } catch (error) {
    if (error.status === 403) section.append(node('p', 'Tu cuenta no tiene acceso al registro del agente.', 'empty-state'));
    else appendError(section, error);
  }
  return section;
}
// Confirmación común de la ficha: título con el equipo, efecto en lenguaje simple, nombre escrito cuando hace falta y
// el error de la API dentro del mismo diálogo. onConfirm devuelve false para dejarlo abierto.
function confirmDialog({ title, paragraphs, warning, typed, okText, okClass = 'primary', onConfirm }) {
  const dialog = node('dialog', undefined, 'remote-dialog confirm-dialog'); const form = node('form'); form.noValidate = true;
  form.append(node('h2', title));
  const body = node('div', undefined, 'dialog-body'); form.append(body);
  paragraphs.forEach(text => body.append(node('p', text)));
  if (warning) body.append(node('p', warning, 'error-text'));
  let typedInput = null;
  if (typed) {
    const l = node('label', `Escribe el nombre del equipo (${typed}) para confirmar`); typedInput = node('input'); typedInput.name = 'confirmation'; typedInput.autocomplete = 'off'; typedInput.spellcheck = false; l.append(typedInput); body.append(l);
  }
  const extra = node('div', undefined, 'dialog-extra'); body.append(extra);
  const error = node('p', '', 'error-text'); error.hidden = true; error.setAttribute('role', 'alert');
  const footer = node('div', undefined, 'dialog-footer'); const cancel = node('button', 'Cancelar'); cancel.type = 'button';
  const ok = node('button', okText, okClass); ok.type = 'submit';
  footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
  wireDialog(dialog, { error });
  const sync = () => { ok.disabled = !!typed && typedInput.value !== typed; };
  if (typed) { typedInput.addEventListener('input', sync); sync(); }
  const show = e => { error.textContent = e ? errorMessage(e) : ''; error.hidden = !e; };
  cancel.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => dialog.remove());
  form.addEventListener('submit', async event => {
    event.preventDefault(); show(null);
    if (typed && typedInput.value !== typed) { show(new Error('Escribe el nombre exacto del equipo para confirmar.')); return; }
    ok.disabled = true; cancel.disabled = true;
    try { if (await onConfirm({ show, extra }) !== false) dialog.close(); }
    catch (e) { show(e); }
    finally { if (dialog.open) { cancel.disabled = false; sync(); } }
  });
  dialog.showModal();
  (typed ? typedInput : ok).focus();
  return dialog;
}
// Endurecimiento desde el panel: el agente lanza el bootstrapper instalado. Al endurecer, las cuentas
// administradoras locales quedan como usuario estandar (salvo la cuenta gestionada de IT) y su sesion se cierra a los
// 2 minutos con aviso, para que el cambio aplique. Revertir restaura exactamente las cuentas degradadas.
const hardeningStates = { none: 'Sin endurecer', hardened: 'Endurecido', unhardened: 'Revertido', failed: 'Falló (revertido)', running: 'En curso', recovery_required: 'Requiere revertir', rollback_failed: 'Reversión incompleta', waiting_panel: 'Sin endurecer' };
async function hardeningSection(d, initial, onStatus, log) {
  if (!initial) return null;
  let status = initial;
  const section = node('section', undefined, 'work-surface device-hardening'); section.append(node('h2', 'Endurecimiento de cuentas'));
  const state = node('div'); const checked = node('p', undefined, 'help history-checked');
  const drawState = () => {
    state.replaceChildren();
    details(state, [['Estado', hardeningStates[status.state] || status.state], ['Último paso', status.last_step], ['Detalle', status.detail],
      ['Endurecido desde', status.hardened_at ? dateTime(status.hardened_at) : null], ['Último reporte', status.reported_at ? dateTime(status.reported_at) : null]]);
    checked.textContent = `Última consulta: ${clock()}.`;
  };
  drawState();
  section.append(state, checked);
  section.append(node('p', 'Endurecer deja a las cuentas administradoras del equipo como usuario estándar y crea la cuenta local de IT en Windows. La persona verá un aviso y su sesión se cerrará a los 2 minutos para aplicar el cambio. Revertir lo deshace.', 'help'));
  const bar = node('div', undefined, 'toolbar');
  const harden = node('button', 'Endurecer', 'primary'); harden.type = 'button';
  const undo = node('button', 'Revertir'); undo.type = 'button';
  const reload = node('button', 'Actualizar estado'); reload.type = 'button';
  const fb = node('p', undefined, 'help'); fb.setAttribute('role', 'status');
  const problem = node('p', undefined, 'error-message'); problem.hidden = true; problem.setAttribute('role', 'alert');
  // El estado real lo informa el agente: tras enviar la orden se vuelve a consultar (GET existente), sin suponerlo.
  const refreshStatus = async () => {
    reload.disabled = true;
    try { status = await api('GET', `/admin/devices/${d.id}/hardening`); drawState(); onStatus(status); showError(problem, null); }
    catch (error) { showError(problem, error); }
    finally { reload.disabled = false; }
  };
  reload.addEventListener('click', refreshStatus);
  // Cuenta de IT de la empresa: sin su contraseña la API niega "Endurecer" (409). Quien puede configurarla (permiso
  // de endurecimiento sobre toda la empresa) ve el estado y el boton; para el resto se omite.
  const itBar = node('div', undefined, 'toolbar'); itBar.hidden = true;
  const itSummary = node('p', undefined, 'help');
  const configure = node('button', 'Configurar cuenta de IT'); configure.type = 'button';
  const canConfigure = canConfigureHardening(await loadHardeningAccess());
  let settings = null;
  if (canConfigure) { try { settings = await loadHardeningSettings(tenant); } catch { settings = null; } }
  const drawIt = () => {
    itSummary.textContent = settings ? hardeningSummary(settings) : '';
    itBar.hidden = !canConfigure || !settings || settings.configured;
  };
  configure.addEventListener('click', () => openHardeningSettings(tenant, { onSaved: saved => {
    settings = saved; drawIt(); showError(problem, null); fb.textContent = 'Cuenta de IT guardada. Ya puedes endurecer el equipo.';
  } }));
  itBar.append(configure); drawIt();
  // Confirmación completa en un solo diálogo: efecto, cierre de sesión a los 2 minutos y nombre del equipo escrito.
  const send = (action, button) => {
    const hardenIt = action === 'harden';
    showError(problem, null); fb.textContent = '';
    confirmDialog({
      title: hardenIt ? `Endurecer ${d.hostname}` : `Revertir el endurecimiento de ${d.hostname}`,
      paragraphs: hardenIt
        ? ['Las cuentas administradoras locales del equipo pasan a usuario estándar, salvo la cuenta local de IT en Windows, que se crea con la clave configurada para la empresa (no es tu cuenta del panel).',
          'La orden se ejecuta en la próxima sincronización del equipo (unos 2 minutos), si está encendido y conectado.']
        : ['Las cuentas administradoras que se degradaron al endurecer vuelven a ser administradoras.',
          'La orden se ejecuta en la próxima sincronización del equipo (unos 2 minutos), si está encendido y conectado.'],
      warning: hardenIt ? 'La persona verá un aviso y su sesión de Windows se cerrará a los 2 minutos de aplicarse el cambio: perderá lo que no haya guardado.' : null,
      typed: d.hostname,
      okText: hardenIt ? 'Enviar orden de endurecimiento' : 'Enviar orden de reversión',
      onConfirm: async ({ extra }) => {
        button.disabled = true;
        try {
          await api('POST', `/admin/devices/${d.id}/hardening/command`, { body: { action }, idempotencyKey: crypto.randomUUID() });
        } catch (error) {
          const missingIt = error.status === 409 && error.code !== 'agent_too_old' && hardenIt && canConfigure && !settings?.configured;
          if (missingIt) {
            itBar.hidden = false;
            // La falta de cuenta de IT se resuelve desde la misma tarea; guardarla no envía la orden.
            const fix = node('button', 'Configurar cuenta de IT'); fix.type = 'button';
            fix.addEventListener('click', () => openHardeningSettings(tenant, { onSaved: saved => {
              settings = saved; drawIt(); extra.replaceChildren(node('p', 'Cuenta de IT guardada. La orden aún no se ha enviado: pulsa «Enviar orden de endurecimiento».', 'help'));
            } }));
            extra.replaceChildren(fix);
          }
          throw error.code === 'agent_too_old' ? new Error('El agente de este equipo es anterior a 4.0.10: actualízalo antes de endurecerlo desde el panel.')
            : error.status === 409 && hardenIt && settings?.configured ? new Error('No se puede endurecer: el equipo requiere revertir primero (o no está activo).')
            : error.status === 409 && hardenIt ? new Error('No se puede endurecer: falta cargar la clave de la cuenta de IT de la empresa, o el equipo requiere revertir primero.') : error;
        } finally { button.disabled = false; }
        fb.textContent = 'Orden enviada; aún sin confirmación del equipo. Lo ejecuta en su próxima sincronización (unos 2 minutos). El estado de arriba cambia cuando el agente informe el resultado: pulsa «Actualizar estado» para volver a consultarlo.';
        await refreshStatus();
        log.load();
        return true;
      },
    });
  };
  harden.addEventListener('click', () => send('harden', harden));
  undo.addEventListener('click', () => send('unharden', undo));
  bar.append(harden, undo, reload); section.append(itSummary, bar, fb, problem, itBar);
  return section;
}
// Cambio del nombre de Windows del equipo (comando rename_computer). La placa de activo ACT_0015 se convierte a
// ACT-0015: el guion bajo no es valido en nombres DNS. El agente lo aplica en el proximo reinicio o al reiniciar ahora.
const computerNamePattern = /^(?![0-9]+$)[A-Za-z0-9-]{1,15}$/;
function renameSection(d, log) {
  const section = node('section', undefined, 'work-surface device-rename'); section.append(node('h2', 'Cambiar nombre del equipo'));
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
    const now = restart.checked;
    confirmDialog({
      title: `Cambiar el nombre de ${d.hostname}`,
      paragraphs: [`El equipo pasará a llamarse ${name} en Windows. La orden llega en su próxima sincronización (unos 2 minutos), si está encendido y conectado.`,
        now ? 'Al recibirla, el equipo se reinicia de inmediato para aplicar el nombre.' : 'El nombre nuevo se aplica en el próximo reinicio del equipo.'],
      warning: now ? 'La persona perderá lo que no haya guardado: el reinicio no tiene aviso ni cuenta regresiva.' : null,
      okText: now ? 'Cambiar nombre y reiniciar' : 'Enviar cambio de nombre',
      onConfirm: async () => {
        submit.disabled = true;
        try {
          await api('POST', `/devices/${d.id}/commands`, {
            body: { type: 'rename_computer', reason: 'Cambio de nombre desde el panel', expires_at: new Date(Date.now() + 23 * 3600 * 1000).toISOString().replace(/\.\d{3}Z$/, 'Z'), parameters: { computer_name: name, restart_now: now } },
            idempotencyKey: crypto.randomUUID(),
          });
        } catch (error) {
          throw error.code === 'agent_too_old' ? new Error('El agente de este equipo es anterior a 4.0.8: actualízalo antes de cambiarle el nombre.') : error;
        } finally { submit.disabled = false; }
        status.textContent = now
          ? `Orden enviada. El equipo la recibe en su próxima sincronización (unos 2 minutos), se renombra a ${name} y se reinicia.`
          : `Orden enviada. El equipo la recibe en su próxima sincronización y pasará a llamarse ${name} en su próximo reinicio.`;
        input.value = '';
        log.load();
        return true;
      },
    });
  });
  form.append(label, restartLabel, submit); section.append(form, status, problem);
  return section;
}
// Acciones remotas (POST /devices/{id}/commands). El 202 solo encola: el agente la recibe en su próxima sincronización.
// Soporte del agente (client-v4 CommandExecutor): lock/unlock/restart/shutdown/wipe desde 4.0.0. refresh_policy no se
// ofrece: cada sincronización ya trae la versión de las reglas y el agente las aplica en ese mismo ciclo, que es el mismo
// en que recibiría la orden, así que no adelantaría nada.
// Bloquear no admite mensaje ni PIN desde el panel: CommandParameters solo tiene computer_name/restart_now/admin_name.
// Reiniciar y Apagar admiten aviso previo (notice_seconds 60/300/600) desde el agente 4.0.17; «Inmediato» no manda
// parámetros, así que sigue funcionando con cualquier agente.
const NOTICE_AGENT = '4.0.17';
const noticeChoices = [[60, '1 minuto'], [300, '5 minutos'], [600, '10 minutos']];
const remoteActions = [
  { type: 'lock', label: 'Bloquear equipo', permission: 'equipos.bloquear', feature: 'comandos', agent: '4.0.0', reason: 'Bloqueo desde el panel', verb: 'Bloquear',
    effect: 'Aparece una pantalla «Equipo bloqueado por IT» sobre todas las pantallas y la persona no puede usar el equipo hasta que lo desbloquees desde aquí. Sus programas siguen abiertos. Si nadie tiene la sesión abierta, el bloqueo aparece al iniciar sesión.' },
  { type: 'unlock', label: 'Desbloquear', permission: 'equipos.desbloquear', feature: 'comandos', agent: '4.0.0', reason: 'Desbloqueo desde el panel', verb: 'Desbloquear',
    effect: 'Se quita la pantalla de bloqueo y la persona puede volver a usar el equipo.' },
  { type: 'restart', label: 'Reiniciar', permission: 'equipos.reiniciar', feature: 'comandos', agent: '4.0.0', reason: 'Reinicio desde el panel', verb: 'Reiniciar',
    effect: 'Windows se reinicia de inmediato, sin aviso ni cuenta regresiva. La persona perderá lo que no haya guardado.',
    noticeEffect: m => `La persona verá en su pantalla «El equipo se reiniciará en ${m} por orden de IT. Guarda tu trabajo.» con una cuenta regresiva, sin opción de posponer ni cancelar. Al terminar, Windows se reinicia. La cuenta empieza cuando el equipo recibe la orden.` },
  { type: 'shutdown', label: 'Apagar', permission: 'equipos.apagar', feature: 'comandos', agent: '4.0.0', reason: 'Apagado desde el panel', verb: 'Apagar',
    effect: 'El equipo se apaga de inmediato, sin aviso. La persona perderá lo que no haya guardado y alguien tendrá que encenderlo a mano: no se puede encender desde el panel.',
    noticeEffect: m => `La persona verá en su pantalla «El equipo se apagará en ${m} por orden de IT. Guarda tu trabajo.» con una cuenta regresiva, sin opción de posponer ni cancelar. Al terminar, el equipo se apaga y alguien tendrá que encenderlo a mano: no se puede encender desde el panel. La cuenta empieza cuando el equipo recibe la orden.` },
];
const wipeAction = { type: 'wipe', label: 'Borrado remoto', permission: 'equipos.borrar', feature: 'borrado', agent: '4.0.0', reason: 'Borrado remoto por robo o pérdida', verb: 'Borrar',
  effect: 'Se borran los archivos de todas las personas del equipo (Documentos, Escritorio, Descargas, correo y configuración de sus cuentas de Windows) y se sobrescribe el espacio libre para que no se puedan recuperar. Se conservan Windows, Keeper y la cuenta de IT. Solo funciona si el equipo se enciende y se conecta: no protege un disco que ya sacaron del equipo.' };
const featureNames = { comandos: 'Comandos remotos', borrado: 'Borrado remoto' };
const commandTypes = { lock: 'Bloquear', unlock: 'Desbloquear', restart: 'Reiniciar', shutdown: 'Apagar', wipe: 'Borrado remoto', refresh_policy: 'Aplicar reglas', rename_computer: 'Cambiar nombre', harden: 'Endurecer', unharden: 'Revertir endurecimiento' };
const commandStates = { pending: ['Pendiente', 'info'], delivered: ['Entregado', 'info'], running: ['En curso', 'warn'], succeeded: ['Ejecutado', 'good'], failed: ['Falló', 'bad'], expired: ['Vencido', 'warn'], cancelled: ['Cancelado', ''] };
const commandCodes = { completed: 'Completado', pending_restart: 'Se aplica al reiniciar', expired: 'Venció antes de ejecutarse', interrupted: 'Se interrumpió (el equipo se apagó o reinició)',
  unsupported_command: 'El agente no ejecuta esta orden', device_actions_dry_run: 'El agente está en modo de prueba: no ejecuta acciones', invalid_command_destination: 'Rechazada por el agente (destino no válido)',
  command_inbox_full: 'El agente tiene demasiadas órdenes pendientes', invalid_computer_name: 'Nombre no válido', lock_store_unavailable: 'El agente no pudo guardar el bloqueo',
  notice_deadline_missed: 'No se ejecutó: el equipo estaba apagado o suspendido cuando terminó el aviso', invalid_notice: 'Tiempo de aviso no válido' };
const versionBelow = (version, min) => {
  const a = String(version || '0').split('.').map(n => parseInt(n, 10) || 0); const b = min.split('.').map(Number);
  for (let i = 0; i < 3; i++) { if ((a[i] || 0) !== b[i]) return (a[i] || 0) < b[i]; }
  return false;
};
// null = se oculta; { reason } = se muestra deshabilitada; {} = disponible. Sin /me/access decide la API.
function remoteAvailability(action, access, d) {
  if (access && !access.platform && !access.permissions.some(p => p.code === action.permission)) return null;
  if (access && !access.platform && action.feature && !(access.features || []).includes(action.feature)) return null;
  if (access?.platform && action.feature && !(access.features || []).includes(action.feature)) return { reason: `El cliente no tiene contratada la función «${featureNames[action.feature]}».` };
  if (d.status !== 'active') return { reason: 'El equipo no está activo: no recibe órdenes.' };
  if (versionBelow(d.agent_version, action.agent)) return { reason: `El agente de este equipo (${d.agent_version || 'sin versión'}) no ejecuta esta orden: actualízalo a ${action.agent} o posterior.` };
  return {};
}
function commandError(error, type, notice = 0) {
  if (error.code === 'invalid_transition') return new Error('El equipo no está activo: no se le pueden enviar órdenes.');
  if (error.code === 'agent_too_old' && notice > 0) return new Error(`El agente de este equipo es anterior a ${NOTICE_AGENT} y no puede mostrar el aviso. Actualízalo o envía la orden con «Inmediato».`);
  if (error.code === 'agent_too_old') return new Error('El agente de este equipo es muy antiguo para esta orden: actualízalo primero.');
  if (error.status === 422 && type === 'wipe') return new Error('El nombre escrito no coincide con el del equipo. No se envió el borrado.');
  if (error.status === 422) return new Error('La orden no es válida (revisa el motivo: máximo 500 caracteres).');
  if (error.code === 'reauth_required') return new Error('Confirma tu contraseña para enviar el borrado.');
  if (error.status === 403 && !error.problem?.detail) return new Error('Tu cuenta no tiene permiso para enviar esta orden a este equipo.');
  return error;
}
const commandExpiry = () => new Date(Date.now() + 23 * 3600 * 1000).toISOString().replace(/\.\d{3}Z$/, 'Z');
async function remoteActionsSection(d, log) {
  const access = await api('GET', '/me/access').catch(() => null);
  const offered = [...remoteActions, wipeAction].map(action => ({ action, state: remoteAvailability(action, access, d) })).filter(x => x.state);
  if (!offered.length) return null;
  const section = node('section', undefined, 'work-surface remote-actions'); section.append(node('h2', 'Acciones remotas'));
  section.append(node('p', 'Cada orden queda en cola: el equipo la recibe en su próxima sincronización (unos 2 minutos) si está encendido y conectado. Su estado y resultado aparecen en «Historial del equipo», al final de esta ficha.', 'help'));
  const status = node('p', undefined, 'help remote-feedback'); status.setAttribute('role', 'status');
  const button = ({ action, state }, className) => {
    const b = node('button', action.label, className); b.type = 'button'; b.dataset.command = action.type;
    if (state.reason) { b.disabled = true; b.title = state.reason; }
    else b.addEventListener('click', () => { status.textContent = ''; openRemoteAction(d, action, { onSent: () => { status.textContent = 'Orden enviada: el equipo la recibe en su próxima sincronización (unos 2 minutos).'; log.load(); } }); });
    return b;
  };
  const reasons = list => {
    const notes = list.filter(x => x.state.reason).map(x => node('li', `${x.action.label}: ${x.state.reason}`));
    if (!notes.length) return null;
    const ul = node('ul', undefined, 'help remote-reasons'); ul.append(...notes); return ul;
  };
  const normal = offered.filter(x => x.action.type !== 'wipe');
  if (normal.length) {
    const bar = node('div', undefined, 'actions'); bar.append(...normal.map(x => button(x)));
    section.append(bar); const r = reasons(normal); if (r) section.append(r);
  }
  const wipe = offered.find(x => x.action.type === 'wipe');
  if (wipe) {
    const zone = node('div', undefined, 'danger-zone'); zone.append(node('h3', 'Zona de peligro'));
    zone.append(node('p', 'El borrado remoto elimina los datos de las personas del equipo y no se puede deshacer. Úsalo solo si el equipo se perdió o lo robaron.'));
    const bar = node('div', undefined, 'actions'); bar.append(button(wipe, 'danger')); zone.append(bar);
    const r = reasons([wipe]); if (r) zone.append(r);
    section.append(zone);
  }
  section.append(status);
  return section;
}
// Diálogo de confirmación: efecto en lenguaje simple, motivo (va a la auditoría) y, en el borrado, el nombre exacto del
// equipo y la contraseña del panel (la API exige reautenticación de menos de 5 minutos: 403 reauth_required).
function openRemoteAction(d, action, { onSent }) {
  const wipe = action.type === 'wipe';
  const dialog = node('dialog', undefined, 'remote-dialog'); const form = node('form'); form.noValidate = true;
  form.append(node('h2', `${action.verb} ${d.hostname}`));
  const body = node('div', undefined, 'dialog-body'); form.append(body);
  const effect = node('p', action.effect); body.append(effect);
  // Reiniciar/Apagar: «Inmediato» (por defecto, como siempre) o «Con aviso» de 1, 5 o 10 minutos a la persona.
  let noticeSeconds = () => 0;
  if (action.noticeEffect) {
    const tooOld = versionBelow(d.agent_version, NOTICE_AGENT);
    const box = node('fieldset', undefined, 'remote-notice'); box.append(node('legend', 'Cuándo'));
    const choice = (value, text) => { const l = node('label', undefined, 'check'); const r = node('input'); r.type = 'radio'; r.name = 'notice_mode'; r.value = value; l.append(r, text); box.append(l); return r; };
    const immediate = choice('now', 'Inmediato'); immediate.checked = true;
    const withNotice = choice('notice', 'Con aviso a la persona');
    const delayLabel = node('label', 'Tiempo de aviso'); const delay = node('select'); delay.name = 'notice_seconds';
    noticeChoices.forEach(([value, text]) => { const o = node('option', text); o.value = String(value); delay.append(o); });
    delay.value = '300'; delayLabel.append(delay); delayLabel.hidden = true; box.append(delayLabel);
    if (tooOld) {
      withNotice.disabled = true;
      box.append(node('p', `El agente de este equipo (${d.agent_version || 'sin versión'}) no muestra el aviso: actualízalo a ${NOTICE_AGENT} o posterior. Mientras tanto solo se puede enviar inmediato.`, 'help remote-notice-old'));
    }
    const refresh = () => {
      delayLabel.hidden = !withNotice.checked;
      effect.textContent = withNotice.checked ? action.noticeEffect(delay.selectedOptions[0].textContent) : action.effect;
    };
    [immediate, withNotice, delay].forEach(x => x.addEventListener('change', refresh));
    noticeSeconds = () => withNotice.checked ? Number(delay.value) : 0;
    body.append(box);
  }
  if (wipe) body.append(node('p', 'Esta acción es irreversible: lo borrado no se puede recuperar.', 'error-text'));
  const reasonLabel = node('label', 'Motivo (queda en la auditoría)'); const reason = node('input'); reason.name = 'reason'; reason.maxLength = 500; reason.value = action.reason; reason.autocomplete = 'off'; reasonLabel.append(reason); body.append(reasonLabel);
  let confirmInput = null;
  if (wipe) {
    const l = node('label', `Escribe el nombre del equipo (${d.hostname}) para confirmar`); confirmInput = node('input'); confirmInput.name = 'confirmation'; confirmInput.autocomplete = 'off'; confirmInput.spellcheck = false; l.append(confirmInput); body.append(l);
    body.append(node('p', 'Si no confirmaste tu contraseña del panel en los últimos 5 minutos, te la pediremos antes de enviar el borrado.', 'help'));
  }
  const error = node('p', '', 'error-text'); error.hidden = true; error.setAttribute('role', 'alert');
  const footer = node('div', undefined, 'dialog-footer'); const cancel = node('button', 'Cancelar'); cancel.type = 'button';
  const ok = node('button', wipe ? 'Borrar el equipo' : action.label, wipe ? 'danger' : 'primary'); ok.type = 'submit';
  footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
  wireDialog(dialog, { error });
  const sync = () => { ok.disabled = wipe && confirmInput.value !== d.hostname; };
  if (wipe) { confirmInput.addEventListener('input', sync); sync(); }
  const show = e => { error.textContent = e ? errorMessage(e) : ''; error.hidden = !e; };
  cancel.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => dialog.remove());
  form.addEventListener('submit', async event => {
    event.preventDefault(); show(null);
    if (wipe && confirmInput.value !== d.hostname) { show(new Error('Escribe el nombre exacto del equipo para habilitar el borrado.')); return; }
    const text = reason.value.trim();
    if (!text) { show(new Error('Escribe un motivo: queda en la auditoría.')); reason.focus(); return; }
    ok.disabled = true; cancel.disabled = true;
    // Clave de idempotencia nueva por intento: un 403 reauth_required no encoló nada.
    const notice = noticeSeconds();
    const send = () => api('POST', `/devices/${d.id}/commands`, {
      body: { type: action.type, reason: text, expires_at: commandExpiry(), ...(wipe ? { confirmation: confirmInput.value } : {}), ...(notice > 0 ? { parameters: { notice_seconds: notice } } : {}) },
      idempotencyKey: crypto.randomUUID(),
    });
    try {
      const result = wipe ? await withReauth(send, `Para borrar ${d.hostname} a distancia, confirma la contraseña con la que entras al panel.`) : await send();
      if (result === canceled) { show(new Error('No se envió el borrado: falta confirmar tu contraseña.')); return; }
      dialog.close(); onSent();
    } catch (e) { show(commandError(e, action.type, notice)); }
    finally { if (dialog.open) { cancel.disabled = false; ok.disabled = false; sync(); } }
  });
  dialog.showModal();
  (wipe ? confirmInput : reason).focus();
}
async function reports() {
  const data = await get(reportPath());
  if (!Array.isArray(data.series)) throw new ApiError(200, { code: 'invalid_response' });
  const panel = node('section', undefined, 'work-surface'); $('content').append(panel);
  details(panel, [['Período', `${data.from} → ${data.to} (fin excluido)`], ['Zona horaria', data.timezone], ['Registro vs. jornada', data.series.length ? `${number.format(data.coverage_percent)} % (tiempo registrado ÷ jornada esperada)` : 'Sin datos'], ['Calculado', dateTime(data.calculated_at)], ['Datos hasta', dateTime(data.data_through)]]);
  if (!data.series.length) { panel.append(node('p', 'No hay datos de productividad para este período en tu alcance.', 'empty-state')); return; }
  const percent = value => value === null ? 'Sin dato' : `${number.format(value)} %`;
  const columns = [['Día', 'day'], ['En uso (h)', r => number.format(r.active_seconds / 3600)], ['Encendido sin uso (h)', r => number.format(r.idle_seconds / 3600)], ['Bloqueado (h)', r => r.locked_seconds == null ? 'Sin dato' : number.format(r.locked_seconds / 3600)], ['Focus / 100', r => r.focus_score === null ? 'Sin dato' : number.format(r.focus_score)], ['Productividad', r => percent(r.productivity_percent)], ['Entrada simulada (h)', r => r.simulated_seconds == null ? 'Sin dato' : number.format(r.simulated_seconds / 3600)]];
  // Agente 4.0.22+: uso que Windows registra sin teclado ni ratón físicos (jiggler, DWService); ya va en "sin uso".
  const simulated = data.series.reduce((a, r) => a + (r.simulated_seconds || 0), 0);
  if (simulated > 1800) panel.append(node('p', `Entrada simulada en el período: ${number.format(simulated / 3600)} h. Windows registró actividad sin teclado ni ratón físicos (un "mouse jiggler" o control remoto como DWService); ese tiempo se cuenta como encendido sin uso.`, 'notice'));
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
    // Sin empresas cliente (todas archivadas o ninguna creada): a Empresas, a crear la primera.
    if (surface === 'portal' && !tenants.some(t => t.id !== PLATFORM_TENANT)) { location.replace('/empresas.php?sin-empresas=1'); return false; }
    const selected = sessionStorage.getItem('keeper.presentation.tenant');
    tenant = tenants.some(t => t.id === selected) ? selected : (surface === 'portal' ? tenants.find(t => t.id !== PLATFORM_TENANT)?.id : PLATFORM_TENANT);
    if (!tenant || !tenants.some(t => t.id === tenant)) throw new Error('No hay una empresa accesible para esta superficie.');
    const select = $('tenant-select'); select.replaceChildren();
    tenants.forEach(company => { const option = node('option', company.name); option.value = company.id; select.append(option); });
    select.value = tenant;
    $('tenant-control').hidden = surface === 'admin' && ['home', 'tenants', 'releases'].includes(page);
  } else if (session.group?.tenants?.length > 1) {
    // Cuenta de grupo: cambia entre las empresas de su grupo donde tiene roles.
    tenants = session.group.tenants;
    const selected = sessionStorage.getItem('keeper.presentation.tenant');
    tenant = tenants.some(t => t.id === selected) ? selected : session.tenant_id;
    const select = $('tenant-select'); select.replaceChildren();
    tenants.forEach(company => { const option = node('option', company.name); option.value = company.id; select.append(option); });
    select.value = tenant;
    $('tenant-control').hidden = false;
  } else {
    tenant = session.tenant_id;
    $('tenant-control').hidden = true;
  }
  if ($('group-group')) $('group-group').hidden = !(session.group?.tenants?.length > 1);
  setTenant(tenant);
  applyNavAccess(); // menu segun los permisos de la cuenta en esta empresa
  try {
    const company = await get(`/tenants/${tenant}`);
    timezone = company.timezone;
    applyBranding(company);
  } catch (error) {
    timezone = 'UTC';
    showError($('page-error'), new Error(`No se pudo consultar la identidad de la empresa. ${errorMessage(error)}`));
  }
  if ($('from') && !$('from').value) { $('from').value = shiftDay(today(), -29); $('to').value = inclusiveDates() ? today() : shiftDay(today(), 1); }
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
    if (page === 'intake') await intake({ tenant, content: $('content') });
    else if (operationPaths[page]) await operations({ page, tenant, today, shiftDay });
    else if (surface === 'portal' && ['home', 'users', 'reports', 'policies'].includes(page)) {
      await coordinator({ page, tenant, timezone, get, allPages, today, shiftDay, query, permissions });
      // Puesta en marcha: aviso en Inicio para administradores mientras no haya primer equipo operativo (no bloquea).
      if (page === 'home') onboardingBanner({ tenant, content: $('content') });
    }
    else if (page === 'home') await dashboard();
    else if (page === 'device') await device();
    else if (page === 'reports') await reports();
    else await listing(page);
    // Releases (plataforma): mudanzas de servidor firmadas, agente 4.0.24+.
    if (page === 'releases' && surface === 'admin') await mountRelocations($('content'));
    // Las pantallas del coordinador fechan en la zona de la empresa; las demás, en la del navegador. Se dice cuál.
    const companyZone = surface === 'portal' && ['home', 'users', 'reports', 'policies'].includes(page);
    const zoneText = companyZone ? `Fechas y horas en la zona horaria de la empresa (${timezone})` : 'Fechas de eventos en la zona horaria del navegador';
    if ($('tz-note')) $('tz-note').textContent = zoneText;
    $('page-status').textContent = `Consulta finalizada a las ${new Intl.DateTimeFormat('es-CO', { timeStyle: 'medium' }).format(new Date()).replace(/\.$/, '')}. ${zoneText}.`;
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
  returnBand(); // llegó desde un paso de Puesta en marcha (?pm=N): «Volver al progreso»
  $('refresh').addEventListener('click', load);
  $('tenant-select').addEventListener('change', () => { sessionStorage.setItem('keeper.presentation.tenant', $('tenant-select').value); load(); });
  // En Reportes la consulta la maneja coordinator.js (estado «sin consultar», filtros dependientes); aquí no se recarga.
  $('date-form')?.addEventListener('submit', event => { event.preventDefault(); if (!(surface === 'portal' && page === 'reports')) load(); });
  await load();
}
window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
if (page === 'login') login();
else if (surface !== 'public') start().catch(error => showError($('page-error'), error));
