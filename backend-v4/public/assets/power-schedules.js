import { api, errorMessage } from './api.js';
import { wireDialog, confirmBox } from './dialogs.js';

// «Apagado programado» (docs/superpowers/specs/2026-10-27-apagado-programado-design.md): lista de programaciones de
// apagado o reinicio, formulario de alta/edición y resultados por equipo. La API (/admin/power-schedules) decide qué se
// ve y qué se puede crear según el permiso por acción (equipos.apagar / equipos.reiniciar) y el alcance de la cuenta.
// El selector de alcance es propio (tipo + elemento) porque el de Reglas vive dentro de k3.js y no se exporta.
const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (className) n.className = className; return n; };
const badge = (text, tone) => el('span', text, `badge ${tone || ''}`.trim());
const button = (text, className, onClick) => { const b = el('button', text, className); b.type = 'button'; if (onClick) b.addEventListener('click', onClick); return b; };

export const ACTION_LABEL = { shutdown: 'Apagar', restart: 'Reiniciar' };
const DAYS = [[1, 'L', 'Lunes'], [2, 'M', 'Martes'], [3, 'X', 'Miércoles'], [4, 'J', 'Jueves'], [5, 'V', 'Viernes'], [6, 'S', 'Sábado'], [7, 'D', 'Domingo']];
const KIND_LABEL = { tenant: 'Toda la empresa', site: 'Sede', area: 'Área', user: 'Persona', device: 'Equipo' };
export const OUTCOME_LABEL = {
  executed_idle: 'Apagado sin uso', executed_after_notice: 'Ejecutado tras aviso', postponed: 'Pospuesto', executed_by_person: 'La persona lo ejecutó',
  cancelled_by_it: 'Cancelado por TI', replaced: 'Reemplazado por orden manual', skipped_suspended: 'No ejecutado: equipo suspendido',
  not_needed: 'No necesario (equipo apagado)', unsupported: 'No soportado: actualizar agente',
};
const DETAIL_TEXT = {
  invalid_days: 'Elige al menos un día de la semana.',
  invalid_run_on: 'Elige la fecha en que se ejecuta.',
  invalid_range: 'La fecha final no puede ser anterior a la inicial.',
  duplicate_target: 'Hay un alcance o una excepción repetida: quita el duplicado.',
};
const NOTICE_TEXT = 'Si el equipo está en uso, la persona verá el aviso y podrá posponer una vez.';

/** Mensaje en español para un error de la API de programaciones. */
export function saveError(error) {
  const detail = error?.problem?.detail || '';
  if (error?.status === 422 && DETAIL_TEXT[detail]) return DETAIL_TEXT[detail];
  if (error?.status === 422) return 'Revisa los datos: algún campo no es válido.';
  if (error?.status === 412 || error?.code === 'version_conflict') return 'Alguien más cambió esta programación mientras la editabas. Cierra, recarga la página y vuelve a intentarlo.';
  if (error?.status === 403 && detail === 'scope_denied') return 'Tu alcance no incluye alguno de los equipos, personas o sedes elegidos.';
  if (error?.status === 403 && !/^feature_not_contracted:/.test(detail)) return 'Tu cuenta no tiene permiso para apagar o reiniciar con esta programación.';
  return errorMessage(error);
}

const pad = n => String(n).padStart(2, '0');
const today = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; };
const shortDate = iso => { const [, m, d] = String(iso || '').split('-'); return m && d ? `${d}/${m}` : '—'; };

/** «Viernes 19:00», «Lun, Mié, Vie 19:00», «Todos los días 19:00», «Una vez 30/10 18:00». */
export function whenText(s) {
  if (s.recurrence === 'once') return `Una vez ${shortDate(s.run_on)} ${s.time_local}`;
  const days = [...s.days].sort((a, b) => a - b);
  if (days.length === 7) return `Todos los días ${s.time_local}`;
  const names = days.map(n => DAYS.find(d => d[0] === n)?.[2] || '');
  return `${names.length === 1 ? names[0] : names.map(n => n.slice(0, 3)).join(', ')} ${s.time_local}`;
}

async function allPages(path) {
  const out = []; let cursor = null;
  do {
    const url = new URL(path, location.origin); url.searchParams.set('limit', '100'); if (cursor) url.searchParams.set('cursor', cursor);
    const r = await api('GET', url.pathname + url.search); out.push(...r.data); cursor = r.next_cursor;
  } while (cursor);
  return out;
}
async function optional(promise) { try { return await promise; } catch (e) { if ([401, 403].includes(e.status)) return null; throw e; } }

// Catálogo de destinos (sedes, áreas, personas, equipos). Lo que la cuenta no puede listar queda vacío: la API valida.
async function loadCatalog(tenant) {
  const [org, users, devices] = await Promise.all([optional(allPages('/organization')), optional(allPages('/users')), optional(allPages(`/tenants/${tenant}/devices`))]);
  const who = new Map((users || []).map(u => [u.id, u.display_name]));
  const items = {
    site: (org || []).filter(o => o.kind === 'site' && o.active).map(o => [o.id, o.name]),
    area: (org || []).filter(o => o.kind === 'area' && o.active).map(o => [o.id, o.name]),
    user: (users || []).filter(u => u.status === 'active').map(u => [u.id, u.display_name]),
    device: (devices || []).filter(d => d.status === 'active').map(d => [d.id, `${d.hostname} (${who.get(d.user_id) || 'sin persona'})`]),
  };
  const names = new Map([...(org || []).map(o => [o.id, o.name]), ...(users || []).map(u => [u.id, u.display_name]), ...(devices || []).map(d => [d.id, d.hostname])]);
  for (const list of Object.values(items)) list.sort((a, b) => a[1].localeCompare(b[1], 'es'));
  return { items, names, tenant };
}

// Lista editable de destinos: tipo + elemento + «Agregar»; cada uno se puede quitar. kinds = tipos permitidos.
function targetPicker(parent, { legend, help, kinds, initial, catalog, preset }) {
  const box = el('fieldset', undefined, 'target-picker'); box.append(el('legend', legend)); if (help) box.append(el('p', help, 'help'));
  const chosen = initial.map(t => ({ kind: t.kind, id: t.id }));
  const list = el('ul', undefined, 'check-list target-list'); list.setAttribute('aria-label', legend);
  const bar = el('div', undefined, 'form-grid');
  const kind = el('select'); kinds.forEach(k => kind.append(new Option(KIND_LABEL[k], k)));
  const kindLabel = el('label', 'Tipo'); kindLabel.append(kind);
  const item = el('select'); const itemLabel = el('label', 'Cuál'); itemLabel.append(item);
  bar.append(kindLabel, itemLabel);
  const add = button('Agregar', undefined);
  const label = t => t.kind === 'tenant' ? KIND_LABEL.tenant : `${KIND_LABEL[t.kind]}: ${catalog.names.get(t.id) || preset?.label?.[t.id] || '(fuera de tu lista)'}`;
  const renderList = () => {
    list.replaceChildren();
    if (!chosen.length) { list.append(el('li', 'Nada elegido.', 'help')); return; }
    chosen.forEach((t, i) => {
      const li = el('li', undefined, 'check'); li.append(el('span', label(t)));
      const remove = button('Quitar', 'link-button', () => { chosen.splice(i, 1); renderList(); fillItems(); }); remove.setAttribute('aria-label', `Quitar ${label(t)}`);
      li.append(remove); list.append(li);
    });
  };
  const fillItems = () => {
    item.replaceChildren();
    const taken = new Set(chosen.map(t => `${t.kind}:${t.id}`));
    if (kind.value === 'tenant') { item.append(new Option(KIND_LABEL.tenant, catalog.tenant)); item.disabled = true; add.disabled = taken.has(`tenant:${catalog.tenant}`); return; }
    item.disabled = false;
    const options = (catalog.items[kind.value] || []).filter(([id]) => !taken.has(`${kind.value}:${id}`));
    if (!options.length) item.append(new Option(`No hay ${KIND_LABEL[kind.value].toLowerCase()}s para agregar`, ''));
    options.forEach(([id, text]) => item.append(new Option(text, id)));
    add.disabled = !options.length;
  };
  kind.addEventListener('change', fillItems);
  add.addEventListener('click', () => { if (!item.value) return; chosen.push({ kind: kind.value, id: item.value }); renderList(); fillItems(); });
  box.append(list, bar, add); parent.append(box); renderList(); fillItems();
  return { get: () => chosen.map(t => ({ kind: t.kind, id: t.id })) };
}

const field = (parent, text, input, help) => { const l = el('label', text); l.append(input); parent.append(l); if (help) parent.append(el('p', help, 'help')); return input; };
const numberInput = (value, min, max) => { const i = el('input'); i.type = 'number'; i.min = min; i.max = max; i.step = 1; i.value = value; return i; };

/**
 * Diálogo de alta o edición. preset: valores iniciales al crear (p. ej. { recurrence: 'once', targets: [{ kind: 'device', id }], label: { id: 'nombre' } }).
 * Con `schedule` edita esa programación. onSaved(programación) se llama al guardar.
 */
export async function openPowerScheduleDialog({ tenant, schedule = null, preset = {}, onSaved = () => {}, catalog = null }) {
  catalog = catalog || await loadCatalog(tenant);
  const s = schedule || {};
  const v = { name: '', action: 'shutdown', recurrence: 'weekly', days: [], time_local: '19:00', run_on: null, valid_from: today(), valid_until: null, targets: [], exclusions: [],
    message: '', idle_minutes: 15, notice_minutes: 10, postpone_minutes: 60, window_minutes: 180, status: 'active', ...preset, ...s };
  const dialog = el('dialog', undefined, 'power-dialog'); const form = el('form'); form.noValidate = true;
  form.append(el('h2', schedule ? 'Editar programación' : 'Nueva programación'));
  const body = el('div', undefined, 'dialog-body'); form.append(body);
  const error = el('p', '', 'error-text'); error.hidden = true;
  const footer = el('div', undefined, 'dialog-footer'); const cancel = button('Cancelar'); const ok = el('button', 'Guardar', 'primary'); ok.type = 'submit';
  footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
  cancel.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove());
  wireDialog(dialog, { title: form.querySelector('h2'), error });
  const show = (text, focus) => { error.textContent = text; error.hidden = false; focus?.focus?.(); };

  const name = field(body, 'Nombre', el('input')); name.maxLength = 80; name.value = v.name; name.placeholder = 'Ej.: Apagado de oficina, viernes noche';
  const action = field(body, 'Acción', el('select')); Object.entries(ACTION_LABEL).forEach(([k, t]) => action.append(new Option(t, k))); action.value = v.action;
  const recurrence = field(body, 'Frecuencia', el('select')); recurrence.append(new Option('Una sola vez', 'once'), new Option('Cada semana', 'weekly')); recurrence.value = v.recurrence;

  const weekly = el('div'); body.append(weekly);
  const dayBox = el('fieldset'); dayBox.append(el('legend', 'Días de la semana'));
  const dayRow = el('div', undefined, 'actions');
  const dayChecks = DAYS.map(([n, letter, full]) => {
    const l = el('label', undefined, 'check'); const c = el('input'); c.type = 'checkbox'; c.value = String(n); c.checked = v.days.includes(n); c.setAttribute('aria-label', full);
    l.append(c, el('span', letter)); l.title = full; dayRow.append(l); return c;
  });
  dayBox.append(dayRow); weekly.append(dayBox);
  const grid = el('div', undefined, 'form-grid'); weekly.append(grid);
  const validFrom = field(grid, 'Vigente desde', el('input')); validFrom.type = 'date'; validFrom.value = v.valid_from;
  const validUntil = field(grid, 'Hasta (opcional)', el('input')); validUntil.type = 'date'; validUntil.value = v.valid_until || '';
  const once = el('div'); body.append(once);
  const runOn = field(once, 'Fecha', el('input')); runOn.type = 'date'; runOn.value = v.run_on || today();
  const time = field(body, 'Hora (zona horaria de la empresa)', el('input')); time.type = 'time'; time.value = v.time_local;
  const syncFrequency = () => { weekly.hidden = recurrence.value !== 'weekly'; once.hidden = recurrence.value !== 'once'; };
  recurrence.addEventListener('change', syncFrequency); syncFrequency();

  const targets = targetPicker(body, { legend: 'Aplica a', help: 'Elige una o más sedes, áreas, personas o equipos, o toda la empresa.', kinds: ['tenant', 'site', 'area', 'user', 'device'], initial: v.targets, catalog, preset });
  const exclusions = targetPicker(body, { legend: 'Excepciones (opcional)', help: 'Equipos, personas o áreas que nunca se apagan aunque estén dentro del alcance.', kinds: ['device', 'user', 'area'], initial: v.exclusions, catalog, preset });

  const message = field(body, 'Mensaje para la persona (opcional)', el('input'), 'Si lo dejas vacío se usa «TI programó el apagado de este equipo.» (o «…el reinicio…»).');
  message.maxLength = 280; message.value = v.message;
  const idle = field(body, 'Minutos sin uso antes de ejecutar', numberInput(v.idle_minutes, 5, 120), 'Si el equipo lleva ese tiempo sin uso, se ejecuta sin avisar (5 a 120).');
  const notice = field(body, 'Minutos de aviso (equipo en uso)', numberInput(v.notice_minutes, 1, 30), 'Cuenta regresiva que ve la persona antes de que se ejecute (1 a 30).');
  const postponeBox = el('label', undefined, 'check'); const postponeOn = el('input'); postponeOn.type = 'checkbox'; postponeOn.checked = v.postpone_minutes !== null;
  postponeBox.append(postponeOn, el('span', 'Permitir que la persona posponga una vez')); body.append(postponeBox);
  const postpone = field(body, 'Posponer por (minutos)', numberInput(v.postpone_minutes ?? 60, 15, 240), '15 a 240 minutos.');
  postponeOn.addEventListener('change', () => { postpone.disabled = !postponeOn.checked; }); postpone.disabled = !postponeOn.checked;
  const windowMin = field(body, 'Ventana para ejecutar (minutos)', numberInput(v.window_minutes, 0, 720), 'Si el equipo estaba apagado a la hora indicada, se ejecuta si se enciende dentro de esta ventana (0 a 720).');
  const status = field(body, 'Estado', el('select')); status.append(new Option('Activa', 'active'), new Option('Pausada', 'paused')); status.value = v.status;
  body.append(el('p', NOTICE_TEXT, 'notice'));

  form.addEventListener('submit', async ev => {
    ev.preventDefault(); error.hidden = true;
    const weeklyOn = recurrence.value === 'weekly';
    const days = dayChecks.filter(c => c.checked).map(c => Number(c.value));
    if (!name.value.trim()) return show('Escribe un nombre para la programación.', name);
    if (!/^([01][0-9]|2[0-3]):[0-5][0-9]$/.test(time.value)) return show('Elige la hora.', time);
    if (weeklyOn && !days.length) return show(DETAIL_TEXT.invalid_days, dayChecks[0]);
    if (!weeklyOn && !runOn.value) return show(DETAIL_TEXT.invalid_run_on, runOn);
    if (weeklyOn && !validFrom.value) return show('Elige desde qué fecha vale.', validFrom);
    if (weeklyOn && validUntil.value && validUntil.value < validFrom.value) return show(DETAIL_TEXT.invalid_range, validUntil);
    const chosen = targets.get();
    if (!chosen.length) return show('Elige al menos un alcance: a quién se aplica.', null);
    const range = (input, min, max, text) => { const n = Number(input.value); if (!Number.isInteger(n) || n < min || n > max) { show(`${text} debe ser un número entero entre ${min} y ${max}.`, input); return null; } return n; };
    const idleMinutes = range(idle, 5, 120, 'Los minutos sin uso'); if (idleMinutes === null) return;
    const noticeMinutes = range(notice, 1, 30, 'Los minutos de aviso'); if (noticeMinutes === null) return;
    const windowMinutes = range(windowMin, 0, 720, 'La ventana'); if (windowMinutes === null) return;
    let postponeMinutes = null;
    if (postponeOn.checked) { postponeMinutes = range(postpone, 15, 240, 'El tiempo para posponer'); if (postponeMinutes === null) return; }
    const payload = {
      name: name.value.trim(), action: action.value, recurrence: recurrence.value, days: weeklyOn ? days : [], time_local: time.value,
      run_on: weeklyOn ? null : runOn.value, valid_from: weeklyOn ? validFrom.value : runOn.value, valid_until: weeklyOn ? (validUntil.value || null) : null,
      targets: chosen, exclusions: exclusions.get(), message: message.value.trim(),
      idle_minutes: idleMinutes, notice_minutes: noticeMinutes, postpone_minutes: postponeMinutes, window_minutes: windowMinutes, status: status.value,
    };
    ok.disabled = true;
    try {
      const saved = schedule ? await api('PATCH', `/admin/power-schedules/${schedule.id}`, { body: payload, ifMatch: schedule.version }) : await api('POST', '/admin/power-schedules', { body: payload });
      dialog.close(); onSaved(saved);
    } catch (failure) { show(saveError(failure)); ok.disabled = false; }
  });
  dialog.showModal();
  return dialog;
}

// Resultados por equipo de una programación.
async function showRuns(schedule, zone) {
  const dialog = el('dialog', undefined, 'power-dialog'); const form = el('form'); form.noValidate = true;
  form.append(el('h2', `Resultados: ${schedule.name}`));
  const body = el('div', undefined, 'dialog-body'); form.append(body);
  const footer = el('div', undefined, 'dialog-footer'); const close = button('Cerrar', 'primary'); footer.append(close); form.append(footer);
  dialog.append(form); document.body.append(dialog);
  close.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove());
  wireDialog(dialog, { title: form.querySelector('h2') });
  body.append(el('p', 'Cargando…', 'help'));
  dialog.showModal();
  try {
    const runs = (await api('GET', `/admin/power-schedules/${schedule.id}/runs`)).data;
    body.replaceChildren();
    if (!runs.length) { body.append(el('p', 'Todavía no hay resultados: aparecen cuando los equipos reportan lo que hicieron en cada ejecución.', 'notice')); return; }
    const fmt = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: zone });
    const t = el('table'); t.append(el('caption', `${runs.length} resultado${runs.length === 1 ? '' : 's'} · hora de la empresa (${zone})`));
    const head = el('tr'); ['Equipo', 'Ocurrencia', 'Resultado'].forEach(h => { const th = el('th', h); th.scope = 'col'; head.append(th); }); const thead = el('thead'); thead.append(head); t.append(thead);
    const tbody = el('tbody');
    for (const r of runs) {
      const tr = el('tr'); const out = el('td', OUTCOME_LABEL[r.outcome] || r.outcome); if (r.detail) out.append(el('small', r.detail));
      tr.append(el('td', r.hostname), el('td', fmt.format(new Date(r.occurrence_at))), out); tbody.append(tr);
    }
    t.append(tbody); const wrap = el('div', undefined, 'table-wrap'); wrap.append(t); body.append(wrap);
  } catch (failure) { body.replaceChildren(el('p', saveError(failure), 'error-text')); body.firstChild.setAttribute('role', 'alert'); }
}

export async function powerSchedulesPage({ tenant, content, status, action }) {
  const setStatus = text => { if (status) status.textContent = text; };
  let zone = 'UTC';
  try { const t = await api('GET', `/tenants/${tenant}`); if (t.timezone) zone = t.timezone; } catch { /* la zona es informativa */ }
  const [list, catalog] = await Promise.all([api('GET', '/admin/power-schedules'), loadCatalog(tenant)]);
  let rows = list.data;
  const fail = failure => { setStatus(''); const p = el('p', saveError(failure), 'error-text'); p.setAttribute('role', 'alert'); content.prepend(p); setTimeout(() => p.remove(), 8000); };
  const open = (opts = {}) => openPowerScheduleDialog({ tenant, catalog, ...opts, onSaved: saved => {
    rows = opts.schedule ? rows.map(r => r.id === saved.id ? saved : r) : [saved, ...rows]; render(); setStatus(opts.schedule ? 'Programación guardada.' : 'Programación creada.');
  } });

  const render = () => {
    content.replaceChildren();
    const surface = el('section', undefined, 'surface'); const head = el('div', undefined, 'section-head'); head.append(el('h2', 'Programaciones')); surface.append(head);
    surface.append(el('p', `${NOTICE_TEXT} Las horas se leen en la zona horaria de la empresa (${zone}).`, 'help'));
    content.append(surface);
    if (!rows.length) {
      const box = el('div', undefined, 'empty'); box.append(el('h2', 'Sin programaciones'), el('p', 'Crea una programación para apagar o reiniciar equipos a una hora fija, una vez o cada semana.'));
      box.append(button('Nueva programación', 'primary', () => open())); surface.append(box); return;
    }
    const t = el('table'); t.append(el('caption', `Programaciones · ${rows.length}`));
    const head2 = el('tr'); ['Nombre', 'Acción', 'Cuándo', 'Aplica a', 'Estado', 'Acciones'].forEach(h => { const th = el('th', h); th.scope = 'col'; head2.append(th); });
    const thead = el('thead'); thead.append(head2); t.append(thead);
    const tbody = el('tbody');
    for (const r of rows) {
      const tr = el('tr'); const acts = el('td'); const bar = el('div', undefined, 'actions');
      bar.append(
        button('Editar', 'link-button', () => open({ schedule: r })),
        button(r.status === 'active' ? 'Pausar' : 'Reanudar', 'link-button', async () => {
          try { const saved = await api('PATCH', `/admin/power-schedules/${r.id}`, { body: { status: r.status === 'active' ? 'paused' : 'active' }, ifMatch: r.version }); rows = rows.map(x => x.id === r.id ? saved : x); render(); setStatus(saved.status === 'active' ? 'Programación reanudada.' : 'Programación pausada.'); } catch (failure) { fail(failure); }
        }),
        button('Ver resultados', 'link-button', () => showRuns(r, zone)),
        button('Borrar', 'link-button', async () => {
          if (!await confirmBox({ title: 'Borrar programación', message: `¿Borrar «${r.name}»? Los equipos dejan de ejecutarla en su próxima sincronización (unos 2 minutos).`, okText: 'Borrar programación', danger: true, typed: r.name, typedLabel: 'Escribe el nombre de la programación para confirmar' })) return;
          try { await api('DELETE', `/admin/power-schedules/${r.id}`, { ifMatch: r.version }); rows = rows.filter(x => x.id !== r.id); render(); setStatus('Programación borrada.'); } catch (failure) { fail(failure); }
        }));
      bar.querySelectorAll('button').forEach((b, i) => b.setAttribute('aria-label', `${b.textContent} ${r.name}`));
      acts.append(bar);
      const reach = `${r.targets.length} alcance${r.targets.length === 1 ? '' : 's'}, ${r.exclusions.length} excepci${r.exclusions.length === 1 ? 'ón' : 'ones'}`;
      tr.append(el('td', r.name), el('td', ACTION_LABEL[r.action] || r.action), el('td', whenText(r)), el('td', reach), (() => { const td = el('td'); td.append(badge(r.status === 'active' ? 'Activa' : 'Pausada', r.status === 'active' ? 'good' : '')); return td; })(), acts);
      tbody.append(tr);
    }
    t.append(tbody); const wrap = el('div', undefined, 'table-wrap'); wrap.tabIndex = 0; wrap.append(t); surface.append(wrap);
  };

  if (action) { action.textContent = 'Nueva programación'; action.hidden = false; action.addEventListener('click', () => open()); }
  render(); setStatus('Programaciones cargadas.');
  // Desde la ficha del equipo (?equipo=<uuid>): formulario de una sola vez, ese equipo y el nombre ya puesto.
  const device = new URLSearchParams(location.search).get('equipo');
  if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(device || '')) {
    let hostname = catalog.names.get(device);
    if (!hostname) { try { hostname = (await api('GET', `/devices/${device}`)).hostname; } catch { /* sin nombre */ } }
    await open({ preset: { recurrence: 'once', targets: [{ kind: 'device', id: device }], label: { [device]: hostname }, name: hostname ? `Apagado de ${hostname}` : '' } });
  }
}
