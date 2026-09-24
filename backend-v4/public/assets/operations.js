import { api, errorMessage } from './api.js';

export const operationPaths = {
  holidays: '/schedules/holidays', 'dual-job': '/reports/dual-job-alerts',
  'suspicious-apps': '/reports/suspicious-apps', coverage: '/devices/install-coverage',
  'client-logs': '/audit/client-logs', 'server-health': '/tenants/server-health', 'panel-settings': '/tenants/panel-settings',
};
const el = (tag, text) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; return n; };
const names = { missing: 'Sin equipo', pending: 'Pendiente de enrolar', stale: 'Sin conexión reciente', covered: 'Instalado', exempt: 'Exento', low: 'Baja', medium: 'Media', high: 'Alta', remote_desktop: 'Escritorio remoto', foreign_app: 'Aplicación externa', suspicious_idle: 'Inactividad elevada', after_hours_pattern: 'Patrón fuera de horario' };
const columns = {
  holidays: [['Fecha', 'day'], ['Festivo', 'name'], ['Sociedades', r => r.society_ids.join(', ') || 'Toda la empresa']],
  'dual-job': [['Día', 'day'], ['Persona', 'display_name'], ['Señal', 'alert_type'], ['Prioridad', 'severity'], ['Evidencia', 'evidence'], ['Notas', 'notes']],
  'suspicious-apps': [['Día', 'day'], ['Persona', 'display_name'], ['Equipo', 'device_id'], ['Aplicación', 'process_name'], ['Segundos activos', 'active_seconds']],
  coverage: [['Persona', 'display_name'], ['Estado', 'status'], ['Equipos', 'device_count'], ['Enrolados', 'enrolled_count'], ['Última conexión', 'last_seen_at'], ['Nota', 'note_text']],
  'client-logs': [['Fecha', 'at'], ['Equipo', 'hostname'], ['Nivel', 'level'], ['Componente', 'component'], ['Código', 'code'], ['Error', 'error_code']],
};
function input(form, label, name, value = '', type = 'text') {
  const wrap = el('label', label); const field = el('input'); field.name = name; field.type = type;
  if (type === 'checkbox') field.checked = !!value; else field.value = value;
  wrap.append(field); form.append(wrap); return field;
}
function button(parent, text, action) { const b = el('button', text); b.type = 'button'; b.addEventListener('click', action); parent.append(b); return b; }
function form(parent, submit) {
  const f = el('form'); f.className = 'toolbar'; const error = el('p'); error.className = 'error-message'; error.setAttribute('role', 'alert');
  f.addEventListener('submit', async e => { e.preventDefault(); const b = f.querySelector('[type=submit]'); b.disabled = true; error.textContent = ''; try { await submit(f); } catch (err) { error.textContent = errorMessage(err); } finally { b.disabled = false; } });
  parent.append(f, error); return f;
}
function submit(f) { const b = el('button', 'Guardar'); b.type = 'submit'; f.append(b); }
async function catalog(parent, tenant, path, kind, refresh) {
  const isHoliday = kind === 'holidays';
  const panel = el('section'); panel.append(el('h2', isHoliday ? 'Administrar festivos' : 'Catálogo de aplicaciones')); parent.append(panel);
  const f = form(panel, async f => {
    const b = isHoliday ? { name: f.elements.name.value, day: f.elements.day.value, society_ids: [...f.querySelectorAll('[name=society]:checked')].map(x => x.value) }
      : { app_pattern: f.elements.app_pattern.value, category: f.elements.category.value, description: f.elements.description.value, active: f.elements.active.checked };
    await api(f.dataset.id ? 'PUT' : 'POST', path + (f.dataset.id ? `/${f.dataset.id}` : ''), { tenant, body: b }); await refresh();
  });
  if (isHoliday) {
    input(f, 'Nombre', 'name').required = true; input(f, 'Fecha', 'day', '', 'date').required = true;
    const societyRows = []; let next = 0;
    while (next !== null) { const result = await api('GET', `/schedules/holiday-societies?limit=100&offset=${next}`, { tenant }); societyRows.push(...result.data); next = result.next_offset; }
    f.append(el('p', 'Sin sociedades seleccionadas: aplica a toda la empresa.'));
    societyRows.forEach(s => { const field = input(f, s.name, 'society', false, 'checkbox'); field.value = s.id; });
  } else {
    input(f, 'Patrón literal de proceso o título', 'app_pattern').required = true;
    const label = el('label', 'Categoría'); const select = el('select'); select.name = 'category';
    ['remote_desktop', 'foreign_vpn', 'foreign_workspace', 'vm'].forEach(v => { const o = el('option', names[v] || v); o.value = v; select.append(o); }); label.append(select); f.append(label);
    input(f, 'Descripción', 'description'); input(f, 'Activo', 'active', true, 'checkbox');
  }
  submit(f); button(f, 'Nuevo', () => { f.reset(); delete f.dataset.id; });
  let offset = 0; const list = el('div'); panel.append(list);
  async function load() {
    const result = await api('GET', `${path}?limit=50&offset=${offset}`, { tenant }); list.replaceChildren();
    result.data.forEach(row => {
      const line = el('p', isHoliday ? `${row.day} · ${row.name}` : `${row.app_pattern} · ${row.category} · ${row.active ? 'Activo' : 'Inactivo'}`);
      button(line, 'Editar', () => { f.dataset.id = row.id; Object.entries(row).forEach(([k, v]) => { const field = f.elements[k]; if (field?.type === 'checkbox') field.checked = v; else if (field) field.value = v; }); f.querySelectorAll('[name=society]').forEach(x => { x.checked = row.society_ids?.includes(x.value); }); });
      button(line, 'Eliminar', async () => { try { await api('DELETE', `${path}/${row.id}`, { tenant }); await refresh(); } catch (err) { line.append(el('span', errorMessage(err))); } }); list.append(line);
    });
    if (offset) button(list, 'Anterior', () => { offset = Math.max(0, offset - 50); load().catch(e => list.append(el('p', errorMessage(e)))); });
    if (result.next_offset !== null) button(list, 'Siguiente', () => { offset = result.next_offset; load().catch(e => list.append(el('p', errorMessage(e)))); });
  }
  await load();
}
export async function operations({ page, tenant, today, shiftDay }) {
  const parent = document.getElementById('content'); const path = operationPaths[page];
  const refresh = async () => { parent.replaceChildren(); await operations({ page, tenant, today, shiftDay }); };
  if (page === 'server-health' || page === 'panel-settings') {
    const result = await api('GET', path, { tenant });
    if (page === 'panel-settings') {
      const f = form(parent, async f => { await api('PUT', path, { tenant, body: { install_coverage_heartbeat_days: Number(f.elements.days.value) } }); await refresh(); });
      const days = input(f, 'Días sin conexión para marcar instalación sin actividad', 'days', result.install_coverage_heartbeat_days, 'number'); days.min = 1; days.max = 90; days.required = true; submit(f);
    } else {
      const labels = { version: 'Versión del cálculo', database_version: 'Versión de BD', pending_rollup_days: 'Días pendientes de calcular', pending_commands: 'Comandos pendientes', pending_policy_compiles: 'Políticas pendientes', cron_last_run: 'Última ejecución del cron', cron_last_success: 'Último cron correcto', cron_status: 'Estado del cron', last_sync: 'Última sincronización', last_ingest: 'Último ingreso de actividad' };
      const dl = el('dl'); Object.entries(result).forEach(([k, v]) => dl.append(el('dt', labels[k] || k), el('dd', v ?? 'Sin registro'))); parent.append(dl);
    }
    return;
  }
  if (page === 'dual-job') {
    parent.append(el('p', 'Señales para revisión humana. Una alerta no confirma doble empleo.'));
    const signals = await api('GET', '/reports/compliance-signals', { tenant }); signals.data.forEach(s => parent.append(el('p', `${names[s.code] || s.code}: ${s.description}`)));
  }
  if (page === 'holidays') { await catalog(parent, tenant, path, page, refresh); return; }
  const filters = el('form'); filters.className = 'toolbar'; parent.append(filters);
  if (['dual-job', 'suspicious-apps'].includes(page)) { input(filters, 'Desde', 'from', shiftDay(today(), -29), 'date'); input(filters, 'Hasta (excluido)', 'to', shiftDay(today(), 1), 'date'); input(filters, 'ID de persona', 'user_id'); }
  if (page === 'client-logs') { input(filters, 'ID de equipo', 'device_id'); const l = el('label', 'Nivel'); const s = el('select'); s.name = 'level'; ['', 'info', 'warn', 'error'].forEach(v => { const o = el('option', v || 'Todos'); o.value = v; s.append(o); }); l.append(s); filters.append(l); }
  const view = el('div'); parent.append(view); let offset = 0;
  async function load() {
    view.replaceChildren(); const params = new URLSearchParams({ offset, limit: 50 }); new FormData(filters).forEach((v, k) => { if (v) params.set(k, v); });
    const result = await api('GET', `${path}?${params}`, { tenant });
    if (!result.data.length) view.append(el('p', 'No hay registros para esta consulta.'));
    const region = el('div'); region.className = 'table-region'; region.tabIndex = 0; const table = el('table'); const head = el('tr'); columns[page].forEach(([name]) => { const th = el('th', name); th.scope = 'col'; head.append(th); }); table.append(head);
    result.data.forEach(row => {
      const tr = el('tr'); columns[page].forEach(([, field]) => { const value = typeof field === 'function' ? field(row) : row[field]; tr.append(el('td', names[value] || value || '—')); });
      if (page === 'coverage') {
        const td = el('td'); button(td, 'Editar nota', () => {
          td.replaceChildren(); const f = form(td, async f => { await api('PUT', `/users/${row.user_id}/install-coverage`, { tenant, body: { note_text: f.elements.note_text.value, is_exempt: f.elements.is_exempt.checked } }); await load(); });
          input(f, 'Nota', 'note_text', row.note_text).maxLength = 4000; input(f, 'Exento', 'is_exempt', row.is_exempt, 'checkbox'); submit(f);
        }); tr.append(td);
      }
      table.append(tr);
    }); region.append(table); view.append(region);
    if (offset) button(view, 'Anterior', () => { offset = Math.max(0, offset - 50); load().catch(e => view.append(el('p', errorMessage(e)))); });
    if (result.next_offset !== null) button(view, 'Siguiente', () => { offset = result.next_offset; load().catch(e => view.append(el('p', errorMessage(e)))); });
  }
  const search = el('button', 'Consultar'); search.type = 'submit'; filters.append(search); filters.addEventListener('submit', e => { e.preventDefault(); offset = 0; load().catch(err => view.append(el('p', errorMessage(err)))); });
  await load();
  if (page === 'suspicious-apps') { try { await catalog(parent, tenant, '/policies/suspicious-apps', page, refresh); } catch (err) { parent.append(el('p', errorMessage(err))); } }
}
