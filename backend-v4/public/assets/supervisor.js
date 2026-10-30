import { api, readSession, refreshCsrf, goToLogin, isUuid, PLATFORM_TENANT } from './api.js';
import { node, button, filtersFrom, returnPath, renderOverview, renderPerson, renderDevices, renderAlerts, renderWindows, renderTimeline, rulesSummary, localDay, shiftDay, validDay } from './supervisor-view.js';

function message(error) {
  if (error.status === 401) return 'Tu sesión venció. Inicia sesión de nuevo.';
  if (error.status === 403) return 'Sin acceso a esta información. Puedes volver al panel actual.';
  if (error.status === 404) return 'La persona o el cliente no existe o está fuera de tu alcance.';
  if (error.status === 422) return 'Revisa la fecha y los filtros. Los datos pueden haber cambiado.';
  if (error.status === 429) return 'Hay demasiadas consultas. Vuelve a intentarlo en unos segundos.';
  return 'No se pudo consultar la información. Vuelve a intentarlo.';
}
function announce(text) { const status = document.getElementById('page-status'); if (status) status.textContent = text; }
function fail(root, error, retry, overview = false) {
  root.replaceChildren(); const p = node('p', message(error), 'error-message'); p.setAttribute('role', 'alert'); root.append(p);
  if (overview) { const k = node('dl', null, 'supervisor-kpis'); for (const title of ['Productividad', 'Llegadas fuera del margen', 'Personas con alertas']) { const m = node('div', null, 'metric'); m.append(node('dt', title), node('dd', '—')); k.append(m); } root.append(k); }
  const b = button('Reintentar', 'retry'); b.addEventListener('click', retry); root.append(b);
  const a = node('a', 'Volver al panel actual'); a.href = '/'; root.append(a); root.setAttribute('aria-busy', 'false'); announce('Consulta no disponible.');
}
function busy(root) { root.setAttribute('aria-busy', 'true'); root.replaceChildren(node('p', 'Cargando…')); announce('Consultando información…'); }
export function overviewController(root, request = api) {
  let filters = filtersFrom(location.search), version = 0, pages = [null], pageIndex = 0, dto;
  async function reload(nextFilters, reset = true, retried = false, notice = '') {
    if (nextFilters) filters = filtersFrom(nextFilters);
    if (reset) { pages = [null]; pageIndex = 0; }
    const current = ++version, query = new URLSearchParams(filters); query.set('limit', '50');
    if (pages[pageIndex]) query.set('cursor', pages[pageIndex]);
    history.replaceState(null, '', returnPath(`/supervisor.php?${filters}`)); busy(root);
    try {
      const result = await request('GET', `/reports/supervisor?${query}`, { tenant: null });
      if (version !== current) return;
      dto = result; renderOverview(root, dto, filters, pageIndex); root.setAttribute('aria-busy', 'false');
      const admin = document.getElementById('administrar'); if (admin) { admin.hidden = dto.capabilities.admin_href !== '/admin/'; }
      announce(notice || `${dto.total} personas. Página ${pageIndex + 1}.`);
    } catch (e) {
      if (version !== current) return;
      if (e.status === 422 && e.code === 'supervisor_cursor_stale' && query.has('cursor') && !retried) { await reload(filters, true, true, 'Los datos cambiaron; lista actualizada.'); return; }
      fail(root, e, () => reload(filters), true);
    }
  }
  function submit(event) {
    if (event.target.id !== 'supervisor-filters') return;
    event.preventDefault(); reload(new URLSearchParams(new FormData(event.target)));
  }
  function change(event) {
    if (event.target.name !== 'tenant_id') return;
    for (const name of ['company_id', 'area_id', 'site_id']) { const field = event.target.form.elements[name]; field.value = ''; field.disabled = !event.target.value; }
    reload(new URLSearchParams(new FormData(event.target.form)));
  }
  function click(event) {
    const target = event.target.closest('[data-action]'); if (!target || target.disabled) return;
    switch (target.dataset.action) {
      case 'clear': reload(new URLSearchParams()); break;
      case 'state': { const f = new URLSearchParams(filters); if (f.get('state') === target.dataset.state) f.delete('state'); else f.set('state', target.dataset.state); reload(f); break; }
      case 'next': if (dto?.next_cursor) { pages[++pageIndex] = dto.next_cursor; reload(null, false); } break;
      case 'previous': if (pageIndex > 0) { pageIndex--; reload(null, false); } break;
    }
  }
  root.addEventListener('submit', submit); root.addEventListener('change', change); root.addEventListener('click', click);
  return { reload, dispose() { version++; root.removeEventListener('submit', submit); root.removeEventListener('change', change); root.removeEventListener('click', click); } };
}
export function personController(root, { request = api, tenant, id, timezone, day, back = '/supervisor.php' }) {
  let version = 0, dto, windows, timeline, selected = day;
  const path = `/users/${encodeURIComponent(id)}`, options = { tenant };
  const get = route => request('GET', `${path}${route}`, options);
  function detailError(target, error, retry) {
    const p = node('p', message(error), 'error-message'); p.setAttribute('role', 'alert'); target.append(p);
    const b = button('Reintentar sección', 'section-retry'); b.addEventListener('click', () => { p.remove(); b.remove(); retry(); }); target.append(b);
  }
  async function detail(kind, current) {
    const target = root.querySelector(`#supervisor-${kind}`);
    try {
      if (kind === 'windows') { const result = await get(`/windows?from=${selected}&to=${shiftDay(selected, 1)}`); if (version !== current) return; windows = result; renderWindows(target, windows); }
      if (kind === 'timeline') { const result = await get(`/timeline?day=${selected}&limit=200&offset=0`); if (version !== current) return; timeline = result; renderTimeline(target, timeline, timezone); }
      if (kind === 'rules') { const result = await get('/rules'); if (version !== current) return; target.replaceChildren(node('h2', 'Qué tiene bloqueado'), node('p', 'Reglas actuales, independientemente de la fecha elegida.', 'help'), node('p', rulesSummary(result))); }
    } catch (e) { if (version === current) { target.querySelectorAll('p').forEach(p => p.remove()); detailError(target, e, () => detail(kind, current)); } }
  }
  async function reload(next = selected) {
    if (!validDay(next) || next > localDay(timezone)) { announce('Selecciona una fecha válida que no sea futura.'); return; }
    selected = next; const current = ++version; windows = null; timeline = null; dto = null;
    const url = new URL(location.href); url.searchParams.set('day', selected); history.replaceState(null, '', url); busy(root);
    try {
      const result = await get(`/supervisor-summary?day=${selected}&devices_limit=50&alerts_limit=50`);
      if (version !== current) return;
      dto = result; timezone = dto.timezone; renderPerson(root, dto, back); root.setAttribute('aria-busy', 'false'); announce(`Información del ${selected}.`);
      const work = [];
      if (dto.raw_detail_available && dto.sections.activity.status === 'available') work.push(detail('windows', current), detail('timeline', current));
      if (dto.sections.protection?.status === 'available' && dto.sections.rules.status === 'available') work.push(detail('rules', current));
      await Promise.allSettled(work);
    } catch (e) { if (version === current) fail(root, e, () => reload()); }
  }
  async function more(kind, control) {
    const current = version; control.disabled = true;
    try {
      if (kind === 'timeline') {
        const result = await get(`/timeline?day=${selected}&limit=200&offset=${timeline.next_offset}`); if (version !== current) return;
        timeline = { ...result, data: [...timeline.data, ...result.data] }; renderTimeline(root.querySelector('#supervisor-timeline'), timeline, timezone);
      } else {
        const page = kind === 'devices' ? dto.current.devices : dto.alerts;
        const q = new URLSearchParams({ day: selected, devices_limit: '50', alerts_limit: '50', [`${kind}_cursor`]: page.next_cursor });
        const result = await get(`/supervisor-summary?${q}`); if (version !== current) return;
        if (kind === 'devices') { dto.current = { ...result.current, devices: { ...result.current.devices, data: [...page.data, ...result.current.devices.data] } }; renderDevices(root.querySelector('#supervisor-devices'), dto.current, timezone); }
        else { dto.alerts = { ...result.alerts, data: [...page.data, ...result.alerts.data] }; renderAlerts(root.querySelector('#supervisor-alerts'), dto.alerts, timezone); }
      }
    } catch (e) {
      if (version !== current) return;
      if (e.status === 422 && e.code === 'supervisor_cursor_stale') { await reload(); announce('Los datos cambiaron; ficha actualizada.'); }
      else detailError(root.querySelector(`#supervisor-${kind}`), e, () => more(kind, control));
    } finally { if (version === current) control.disabled = false; }
  }
  function submit(event) { if (event.target.id !== 'supervisor-date') return; event.preventDefault(); reload(event.target.elements.day.value); }
  function click(event) {
    const target = event.target.closest('[data-action]'); if (!target || target.disabled) return;
    const action = target.dataset.action;
    if (action === 'today') reload(localDay(timezone));
    if (action === 'yesterday') reload(shiftDay(localDay(timezone), -1));
    if (action === 'windows-more') renderWindows(root.querySelector('#supervisor-windows'), windows, true);
    if (['devices-more', 'alerts-more', 'timeline-more'].includes(action)) more(action.split('-')[0], target);
  }
  root.addEventListener('submit', submit); root.addEventListener('click', click);
  return { reload, dispose() { version++; root.removeEventListener('submit', submit); root.removeEventListener('click', click); } };
}
async function start() {
  const root = document.getElementById('content'), session = readSession();
  if (!session) { goToLogin(); return; }
  try {
    await refreshCsrf();
    let controller;
    if (document.body.dataset.page === 'supervisor') controller = overviewController(root);
    else {
      const q = new URLSearchParams(location.search), tenant = q.get('tenant_id'), id = q.get('id');
      if (!isUuid(tenant) || !isUuid(id)) { fail(root, { status: 404 }, start); return; }
      const me = await api('GET', '/me', { tenant });
      const timezone = me.tenant.timezone, day = q.has('day') ? q.get('day') : localDay(timezone);
      if (!validDay(day) || day > localDay(timezone)) { fail(root, { status: 422 }, start); return; }
      controller = personController(root, { tenant, id, timezone, day, back: q.get('return') });
      document.getElementById('administrar').hidden = !me.platform;
    }
    document.getElementById('refresh').onclick = () => controller.reload();
    document.getElementById('logout').onclick = async () => {
      try { await api('POST', '/auth/logout', { body: {}, tenant: session.tenant_id || PLATFORM_TENANT }); goToLogin(); }
      catch (e) { announce(message(e)); }
    };
    await controller.reload();
  } catch (e) { fail(root, e, start); }
}
if (['supervisor', 'supervisor-person'].includes(document.body.dataset.page)) {
  window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
  start();
}
