import { api, errorMessage } from './api.js';

// Icono de notificaciones de la barra superior (encargo 2026-10-15). El número = alertas sin revisar (doble empleo y apps
// sospechosas) + resúmenes del día (llegadas tarde, ausencias) que esta cuenta no ha visto; lo visto lo guarda la API por
// cuenta (POST /notifications/seen al abrir), no el navegador. Una consulta al cargar la página y otra cada 5 minutos con
// la pestaña visible: la API cachea el resumen del día, así que el contador no recalcula la puntualidad en cada página.
const GROUPS = [['alerts', 'Alertas', 'Pendientes de revisar'], ['today', 'Jornada de hoy', 'Resumen del día']];
const SHORTCUTS = [['dual_job', 'Doble empleo', '/doble-empleo.php'], ['suspicious_apps', 'Apps sospechosas', '/apps-sospechosas.php']];
const REFRESH_MS = 5 * 60 * 1000;
const $ = id => document.getElementById(id);
const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (className) n.className = className; return n; };

// highlight: resúmenes del día que estaban sin ver al abrir; siguen marcados «Nuevo» mientras la lista está abierta.
let state = { tenant: null, data: null, error: null, loadedAt: 0, timer: null, wired: false, request: 0, highlight: new Set() };

function badge(data) {
  const count = data?.badge_count || 0;
  const label = $('notify-count'), button = $('notify-toggle');
  label.hidden = count === 0; label.textContent = count > 99 ? '99+' : String(count);
  button.setAttribute('aria-label', count ? `Notificaciones: ${count} ${count === 1 ? 'pendiente' : 'pendientes'}` : 'Notificaciones: nada pendiente');
}

function render() {
  const body = $('notify-body'); body.replaceChildren();
  if (state.error) {
    const box = el('div', undefined, 'notify-empty');
    box.append(el('p', 'No se pudieron cargar las notificaciones.'), el('p', errorMessage(state.error), 'help'));
    const retry = el('button', 'Reintentar', 'link-button'); retry.type = 'button'; retry.addEventListener('click', () => open(true)); box.append(retry);
    body.append(box); return;
  }
  const data = state.data;
  if (!data) { body.append(el('p', 'Cargando…', 'help')); return; }
  if (!data.items.length) {
    const box = el('div', undefined, 'notify-empty');
    box.append(el('p', 'Todo al día', 'notify-empty-title'));
    box.append(el('p', data.available.length ? 'No hay alertas sin revisar ni novedades de la jornada de hoy.' : 'Tu cuenta no tiene secciones con notificaciones en este cliente.', 'help'));
    body.append(box);
  }
  for (const [key, title, hint] of GROUPS) {
    const items = data.items.filter(i => i.group === key);
    if (!items.length) continue;
    const group = el('section', undefined, 'notify-group'); const id = `notify-group-${key}`;
    const head = el('h3', title); head.id = id; group.setAttribute('aria-labelledby', id);
    const list = el('ul', undefined, 'notify-list');
    for (const item of items) {
      const li = el('li'); const a = el('a', undefined, 'notify-item'); a.href = item.href; a.dataset.type = item.type;
      a.append(el('span', item.title, 'notify-title'));
      const meta = el('span', key === 'alerts' ? 'Revisar' : 'Ver detalle', 'notify-meta');
      if (key === 'today' && (item.unseen || state.highlight.has(item.type))) meta.prepend(el('span', 'Nuevo', 'badge info'), ' ');
      a.append(meta); li.append(a); list.append(li);
    }
    group.append(head, el('p', hint, 'help'), list); body.append(group);
  }
  const shortcuts = SHORTCUTS.filter(([type]) => data.available.includes(type));
  if (shortcuts.length) {
    const nav = el('nav', undefined, 'notify-shortcuts'); nav.setAttribute('aria-label', 'Ir a alertas');
    nav.append(el('span', 'Ir a:', 'help'));
    for (const [, text, href] of shortcuts) { const a = el('a', text); a.href = href; nav.append(a); }
    body.append(nav);
  }
}

async function load() {
  const request = ++state.request;
  try {
    const data = await api('GET', '/notifications', { tenant: state.tenant });
    if (request !== state.request) return;
    state.data = data; state.error = null; state.loadedAt = Date.now();
  } catch (error) {
    if (request !== state.request) return;
    state.error = error;
  }
  badge(state.data);
  if (!$('notify-panel').hidden) render();
}

function isOpen() { return !$('notify-panel').hidden; }
function close(focus = true) {
  if (!isOpen()) return;
  $('notify-panel').hidden = true; $('notify-toggle').setAttribute('aria-expanded', 'false');
  if (focus) $('notify-toggle').focus();
}
async function open(retry = false) {
  const panel = $('notify-panel');
  panel.hidden = false; $('notify-toggle').setAttribute('aria-expanded', 'true');
  if (retry) { state.error = null; state.data = null; }
  state.highlight = new Set((state.data?.items || []).filter(i => i.group === 'today' && i.unseen).map(i => i.type));
  render();
  panel.focus();
  // Abrir la lista = visto (por cuenta). El estado nuevo trae el número ya rebajado.
  const request = ++state.request;
  try {
    const data = await api('POST', '/notifications/seen', { tenant: state.tenant });
    if (request !== state.request) return;
    state.data = data; state.error = null; state.loadedAt = Date.now();
  } catch (error) {
    if (request !== state.request) return;
    if (!state.data) state.error = error;
  }
  badge(state.data);
  if (isOpen()) {
    const inside = $('notifications').contains(document.activeElement);
    render();
    if (inside) panel.focus();
  }
}

function wire() {
  if (state.wired) return; state.wired = true;
  const root = $('notifications'), toggle = $('notify-toggle');
  toggle.addEventListener('click', () => { if (isOpen()) close(); else open(); });
  root.addEventListener('keydown', e => { if (e.key === 'Escape' && isOpen()) { e.preventDefault(); e.stopPropagation(); close(); } });
  // Clic fuera o foco que sale del icono y su lista: se cierra sin robar el foco.
  document.addEventListener('pointerdown', e => { if (isOpen() && !root.contains(e.target)) close(false); });
  root.addEventListener('focusout', e => { if (isOpen() && e.relatedTarget && !root.contains(e.relatedTarget)) close(false); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && state.tenant && Date.now() - state.loadedAt >= REFRESH_MS) load(); });
}

/** Monta (o recarga al cambiar de cliente) el icono. Sin el marcado del layout no hace nada. */
export function mountNotifications(tenant) {
  if (!$('notifications') || !tenant) return;
  wire();
  if (state.tenant !== tenant) { state.data = null; state.error = null; close(false); }
  state.tenant = tenant;
  $('notifications').hidden = false;
  clearInterval(state.timer);
  state.timer = setInterval(() => { if (!document.hidden) load(); }, REFRESH_MS);
  return load();
}
