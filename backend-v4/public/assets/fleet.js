import { api, errorMessage, PLATFORM_TENANT } from './api.js';
import { renameNote } from './device-actions.js';

const el = (tag, text, cls) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
export const ringLabel = ring => ({ stable: 'Automáticas', pruebas: 'Primero (pruebas)', pausado: 'Pausadas' }[ring] || ring);
const state = d => d.status !== 'active' ? 'Dados de baja' : !d.last_seen_at || Date.now() - Date.parse(d.last_seen_at) > 30 * 86400000 ? 'Obsoletos' : 'Activos';
const field = (form, title, input) => { const label = el('label', title); label.append(input); form.append(label); return input; };
const select = options => { const input = el('select'); for (const [value, text] of options) { const o = el('option', text); o.value = value; input.append(o); } return input; };
const section = (parent, title) => { const s = el('section', undefined, 'work-surface'); s.append(el('h2', title)); parent.append(s); return s; };
function table(parent, headers, rows) {
  const wrap = el('div', undefined, 'table-region'); wrap.tabIndex = 0;
  const t = el('table'), head = el('thead'), tr = el('tr');
  headers.forEach(text => { const th = el('th', text); th.scope = 'col'; tr.append(th); }); head.append(tr); t.append(head);
  const body = el('tbody'); rows.forEach(cells => { const tr = el('tr'); cells.forEach(value => { const td = el('td'); if (value instanceof Node) td.append(value); else td.textContent = value ?? 'Sin dato'; tr.append(td); }); body.append(tr); });
  t.append(body); wrap.append(t); parent.append(wrap);
}
function ringForm(parent, selected, tenant, applied) {
  const form = el('form', undefined, 'toolbar');
  const ring = field(form, 'Actualizaciones', select([['stable', 'Automáticas'], ['pruebas', 'Primero (pruebas)'], ['pausado', 'Pausadas']]));
  const reason = field(form, 'Motivo de la pausa', el('input')); reason.maxLength = 500; reason.disabled = true;
  ring.onchange = () => { reason.disabled = ring.value !== 'pausado'; reason.required = !reason.disabled; };
  const submit = el('button', 'Aplicar a seleccionados', 'primary'); submit.type = 'submit';
  const result = el('p'); result.setAttribute('role', 'status'); form.append(submit); parent.append(form, result);
  form.onsubmit = async e => {
    e.preventDefault(); const devices = selected();
    if (!devices.length) { result.textContent = 'Selecciona al menos un equipo.'; return; }
    if (ring.value === 'pausado' && !reason.value.trim()) { reason.setCustomValidity('Escribe el motivo de la pausa.'); reason.reportValidity(); return; }
    reason.setCustomValidity(''); const body = { release_ring: ring.value, release_pause_reason: ring.value === 'pausado' ? reason.value.trim() : null };
    submit.disabled = ring.disabled = reason.disabled = true; const failed = []; let success = 0;
    for (const d of devices) {
      try { Object.assign(d, await api('PATCH', `/devices/${d.id}`, { tenant, ifMatch: d.version, body })); success++; }
      catch (e) { failed.push(`${d.hostname}: ${errorMessage(e)}`); }
    }
    applied(); result.textContent = `${success} de ${devices.length} equipos actualizados.${failed.length ? ` Pendientes: ${failed.join(' · ')}. Actualiza la lista antes de reintentar un conflicto de versión.` : ''}`;
    submit.disabled = ring.disabled = false; ring.onchange();
  };
  reason.oninput = () => reason.setCustomValidity('');
  return { ring, reason, submit };
}
export async function fleet({ root, tenant, allPages, platform }) {
  const [devices, people] = await Promise.all([allPages(`/tenants/${tenant}/devices`), allPages('/users').catch(e => { if (e.status === 403) return null; throw e; })]);
  const names = new Map((people || []).map(p => [p.id, p.display_name]));
  const kpis = el('dl', undefined, 'kpi-grid'); root.append(kpis);
  const paused = el('p', undefined, 'notice'); paused.setAttribute('role', 'alert'); root.append(paused);
  const versions = section(root, 'Versiones instaladas'); versions.classList.add('fleet-versions');
  const list = section(root, 'Equipos');
  list.append(el('p', 'Activos: reportaron durante los últimos 30 días. Obsoletos: más de 30 días sin señal o sin registro. Las versiones cuentan solo equipos activos recientes.', 'help'));
  const bar = el('div', undefined, 'toolbar');
  const search = field(bar, 'Buscar equipo o persona', el('input')); search.type = 'search';
  const status = field(bar, 'Estado', select([['', 'Todos'], ['Activos', 'Activos'], ['Obsoletos', 'Obsoletos'], ['Dados de baja', 'Dados de baja']]));
  const ring = field(bar, 'Actualizaciones', select([['', 'Todas'], ['stable', 'Automáticas'], ['pruebas', 'Primero (pruebas)'], ['pausado', 'Pausadas']])); list.append(bar);
  const chosen = new Set(); let matches = [], offset = 0;
  if (platform) {
    const pick = el('button', 'Seleccionar filtrados'); pick.type = 'button'; bar.append(pick);
    pick.onclick = () => { matches.forEach(d => chosen.add(d.id)); draw(); };
    const clear = el('button', 'Quitar selección'); clear.type = 'button'; bar.append(clear); clear.onclick = () => { chosen.clear(); draw(); };
    const bulk = section(list, 'Actualizar equipos seleccionados');
    bulk.append(el('p', 'Pausar impide recibir versiones, incluida una versión necesaria para mudarse a producción. Cada equipo se guarda y audita por separado.', 'help'));
    ringForm(bulk, () => devices.filter(d => chosen.has(d.id)), tenant, draw);
  }
  const selectedCount = el('p', '', 'help'); selectedCount.setAttribute('role', 'status'); list.append(selectedCount);
  const view = el('div'); list.append(view);
  const pager = el('div', undefined, 'pager'), previous = el('button', 'Anterior'), next = el('button', 'Siguiente'), counter = el('p'); previous.type = next.type = 'button'; pager.append(previous, counter, next); list.append(pager);
  function draw() {
    kpis.replaceChildren(); for (const [title, count] of [['Total', devices.length], ...['Activos', 'Obsoletos', 'Dados de baja'].map(s => [s, devices.filter(d => state(d) === s).length])]) { const box = el('div', undefined, 'metric'); box.append(el('dt', title), el('dd', String(count))); kpis.append(box); }
    const stopped = devices.filter(d => d.release_ring === 'pausado'); paused.hidden = !stopped.length;
    paused.textContent = `${stopped.length} equipos con actualizaciones pausadas: ${stopped.map(d => `${d.hostname} (${d.release_pause_reason})`).join(' · ')}. No recibirán nuevas versiones.`;
    versions.replaceChildren(el('h2', 'Versiones instaladas')); const counts = new Map(), fresh = devices.filter(d => state(d) === 'Activos');
    fresh.forEach(d => counts.set(d.agent_version, (counts.get(d.agent_version) || 0) + 1));
    if (!fresh.length) versions.append(el('p', 'No hay equipos activos recientes para comparar versiones.', 'empty-state'));
    for (const [version, count] of [...counts].sort((a, b) => b[1] - a[1])) { const label = el('label', `v${version} · ${count} de ${fresh.length}`); const meter = el('meter'); meter.min = 0; meter.max = fresh.length; meter.value = count; label.append(meter); versions.append(label); }
    const term = search.value.trim().toLocaleLowerCase('es'); matches = devices.filter(d => (!status.value || state(d) === status.value) && (!ring.value || d.release_ring === ring.value) && `${d.hostname} ${d.computer_rename?.computer_name || ''} ${names.get(d.user_id) || ''} ${d.agent_version}`.toLocaleLowerCase('es').includes(term));
    offset = Math.min(offset, Math.max(0, Math.ceil(matches.length / 25) - 1) * 25); view.replaceChildren(); selectedCount.textContent = platform ? `${chosen.size} equipos seleccionados (incluye otras páginas o filtros).` : 'Solo plataforma puede cambiar las actualizaciones.';
    if (!matches.length) view.append(el('p', 'No hay equipos que coincidan con los filtros.', 'empty-state'));
    else table(view, [...(platform ? ['Seleccionar'] : []), 'Equipo', 'Persona', 'Estado', 'Versión', 'Última conexión', 'Actualizaciones', 'Motivo de pausa'], matches.slice(offset, offset + 25).map(d => {
      // Debajo del nombre, el cambio de nombre de Windows en curso (pendiente de reinicio, en curso o fallido).
      const link = el('a', d.hostname); link.href = `/equipo.php?id=${encodeURIComponent(d.id)}`;
      const note = renameNote(d); const name = note ? el('div') : link; if (note) name.append(link, note);
      const box = el('input'); box.type = 'checkbox'; box.checked = chosen.has(d.id); box.setAttribute('aria-label', `Seleccionar ${d.hostname}`); box.onchange = () => { if (box.checked) chosen.add(d.id); else chosen.delete(d.id); selectedCount.textContent = `${chosen.size} equipos seleccionados (incluye otras páginas o filtros).`; };
      return [...(platform ? [box] : []), name, names.get(d.user_id) || 'No disponible', state(d), d.agent_version, d.last_seen_at ? new Date(d.last_seen_at).toLocaleString('es-CO') : 'Sin registro', ringLabel(d.release_ring), d.release_pause_reason || '—'];
    }));
    counter.textContent = matches.length ? `${offset + 1}–${Math.min(offset + 25, matches.length)} de ${matches.length}` : '0 equipos'; previous.disabled = !offset; next.disabled = offset + 25 >= matches.length;
  }
  previous.onclick = () => { offset -= 25; draw(); }; next.onclick = () => { offset += 25; draw(); };
  search.oninput = status.onchange = ring.onchange = () => { offset = 0; draw(); }; draw();
}
export function deviceUpdates(root, device, tenant, platform) {
  const box = section(root, 'Actualizaciones'); const summary = el('p'); box.append(summary);
  const draw = () => { summary.textContent = `${ringLabel(device.release_ring)}${device.release_pause_reason ? ` · Motivo: ${device.release_pause_reason}. No recibe nuevas versiones.` : ''}`; }; draw();
  if (platform) { const controls = ringForm(box, () => [device], tenant, draw); controls.ring.value = device.release_ring; controls.reason.value = device.release_pause_reason || ''; controls.ring.onchange(); controls.submit.textContent = 'Guardar actualizaciones'; }
}
export async function releaseDeployments(root, allPages) {
  const [releases, companies] = await Promise.all([allPages('/releases', PLATFORM_TENANT), allPages('/tenants', PLATFORM_TENANT)]);
  const box = section(root, 'Publicar versión por anillo');
  box.append(el('p', 'Publica una versión registrada en Pruebas. Después selecciona la misma versión y Automáticas para ampliarla al resto del cliente. Los equipos pausados quedan excluidos.', 'help'));
  if (!releases.length || !companies.some(c => c.id !== PLATFORM_TENANT)) { box.append(el('p', 'Se necesita una versión registrada y un cliente.', 'empty-state')); return; }
  const form = el('form', undefined, 'toolbar');
  const release = field(form, 'Versión registrada', select(releases.map(r => [r.id, `${r.version} · ${r.architecture}`])));
  const company = field(form, 'Cliente', select(companies.filter(c => c.id !== PLATFORM_TENANT).map(c => [c.id, c.name])));
  const ring = field(form, 'Publicar para', select([['pruebas', 'Primero (pruebas)'], ['stable', 'Automáticas']]));
  const percentage = field(form, 'Porcentaje de equipos del anillo', el('input')); percentage.type = 'number'; percentage.min = 1; percentage.max = 100; percentage.value = 100; percentage.required = true;
  const submit = el('button', 'Publicar para este anillo', 'primary'); submit.type = 'submit'; form.append(submit); const status = el('p'); status.setAttribute('role', 'status'); box.append(form, status);
  form.onsubmit = async e => { e.preventDefault(); submit.disabled = true; const label = `${release.selectedOptions[0].textContent} · ${company.selectedOptions[0].textContent} · ${ringLabel(ring.value)} · ${percentage.value} %`;
    try { await api('POST', '/release-deployments', { tenant: company.value, idempotencyKey: crypto.randomUUID(), body: { release_id: release.value, ring: ring.value, percentage: Number(percentage.value), enabled: true } }); status.textContent = `Publicado: ${label}.`; }
    catch (e) { status.textContent = errorMessage(e); } finally { submit.disabled = false; }
  };
}
