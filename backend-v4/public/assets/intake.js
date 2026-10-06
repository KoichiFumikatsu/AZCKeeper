import { api, errorMessage } from './api.js';
import { loadHardeningSettings } from './hardening-settings.js';

// Alta de equipos a escala (docs/architecture/v4-alta-equipos.md): cola de solicitudes, equipos esperados (manual
// y CSV) y claves de alta/configuracion. Todo lo que cruza por serie se aprueba solo; aqui llega lo que no cruzo.
export const intakeProbe = '/enrollments/expected-devices?limit=1';

const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (className) n.className = className; return n; };
const when = value => value ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const rowErrors = {
  person_not_found: 'No se encontró la persona (revisa cédula o correo).', person_ambiguous: 'La cédula o el correo corresponde a más de una persona.',
  duplicate_asset: 'La placa ya está registrada en otro equipo pendiente.', duplicate_serial: 'La serie ya está registrada en otro equipo pendiente.',
  missing_identifier: 'Falta la placa o la serie (al menos una).', too_long: 'Algún valor es demasiado largo (persona 254, placa 40, serie 120).',
};
const statusNames = { pending: 'Pendiente', approved: 'Aprobada (esperando al equipo)', rejected: 'Rechazada', enrolled: 'Enrolado', cancelled: 'Cancelado' };
const matchNames = { none: 'Sin coincidencia', serial: 'Serie de un equipo esperado', document: 'Cédula escrita en el equipo', manual: 'Asignada por IT' };
const alertNames = {
  serial_already_enrolled: 'Esta serie ya pertenece a un equipo enrolado: puede ser una reinstalación o una suplantación.',
  duplicate_serial: 'Otro equipo ya tomó el equipo esperado con esta serie.',
  document_not_found: 'La cédula escrita no corresponde a ninguna persona.',
  person_excluded: 'La persona no se monitorea (directivo o dueño): apruébalo solo si es una excepción.',
};
const message = error => (error?.problem?.detail && rowErrors[error.problem.detail]) || errorMessage(error);

function section(parent, title, help) {
  const s = el('section', undefined, 'work-surface'); s.append(el('h2', title));
  if (help) s.append(el('p', help, 'help'));
  parent.append(s); return s;
}
function field(form, text, name, { type = 'text', placeholder = '', required = false, max } = {}) {
  const label = el('label', text); const input = el('input'); input.name = name; input.type = type;
  if (placeholder) input.placeholder = placeholder; if (required) input.required = true; if (max) input.maxLength = max;
  label.append(input); form.append(label); return input;
}
function feedback(parent) {
  const ok = el('p', '', 'help'); ok.setAttribute('role', 'status');
  const bad = el('p', '', 'error-message'); bad.setAttribute('role', 'alert'); bad.hidden = true;
  parent.append(ok, bad);
  return { ok: text => { ok.textContent = text; bad.hidden = true; }, fail: error => { bad.textContent = typeof error === 'string' ? error : message(error); bad.hidden = false; ok.textContent = ''; }, clear: () => { ok.textContent = ''; bad.hidden = true; } };
}
function table(parent, caption, headers) {
  const region = el('div', undefined, 'table-region'); region.tabIndex = 0; region.setAttribute('role', 'region'); region.setAttribute('aria-label', caption);
  const t = el('table'); t.append(el('caption', caption));
  const head = el('thead'); const tr = el('tr'); headers.forEach(h => { const th = el('th', h); th.scope = 'col'; tr.append(th); }); head.append(tr);
  const body = el('tbody'); t.append(head, body); region.append(t); parent.append(region); return body;
}
function select(parent, text, options, value) {
  const label = el('label', text); const s = el('select');
  Object.entries(options).forEach(([v, name]) => { const o = el('option', name); o.value = v; s.append(o); }); s.value = value; label.append(s); parent.append(label); return s;
}

// CSV del area de IT: separador , o ;, encabezados flexibles (cedula/documento/correo/persona, placa/activo, serie/serial).
export function parseCsv(text) {
  const lines = text.replace(/^﻿/, '').split(/\r?\n/).filter(line => line.trim() !== '');
  if (!lines.length) return { rows: [], problem: 'El archivo está vacío.' };
  const separator = (lines[0].match(/;/g) || []).length > (lines[0].match(/,/g) || []).length ? ';' : ',';
  const split = line => {
    const cells = []; let cell = ''; let quoted = false;
    for (let i = 0; i < line.length; i++) {
      const c = line[i];
      if (quoted) { if (c === '"' && line[i + 1] === '"') { cell += '"'; i++; } else if (c === '"') quoted = false; else cell += c; }
      else if (c === '"') quoted = true; else if (c === separator) { cells.push(cell); cell = ''; } else cell += c;
    }
    cells.push(cell); return cells.map(x => x.trim());
  };
  const plain = value => value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const header = split(lines[0]).map(plain);
  const find = names => header.findIndex(h => names.some(n => h === n || h.includes(n)));
  const person = find(['cedula', 'documento', 'correo', 'email', 'persona', 'usuario']);
  const asset = find(['placa', 'activo', 'asset']);
  const serial = find(['serie', 'serial']);
  if (person < 0 || (asset < 0 && serial < 0)) return { rows: [], problem: 'El encabezado debe tener una columna de persona (cédula o correo) y al menos una de placa o serie.' };
  const rows = []; const invalid = [];
  lines.slice(1).forEach((line, index) => {
    const cells = split(line);
    const row = { person: cells[person] || '', asset_code: asset >= 0 && cells[asset] ? cells[asset] : null, serial_number: serial >= 0 && cells[serial] ? cells[serial] : null };
    // Un valor fuera de limite haria fallar el lote entero en la API: se informa aqui y la fila no se envia.
    if (row.person.length > 254 || (row.asset_code?.length ?? 0) > 40 || (row.serial_number?.length ?? 0) > 120) invalid.push(index + 2);
    else rows.push({ ...row, line: index + 2 });
  });
  return { rows, invalid, problem: rows.length || invalid.length ? null : 'El archivo solo tiene el encabezado.' };
}

export async function intake({ tenant, content }) {
  const rerender = () => intake({ tenant, content });
  content.replaceChildren();
  // El instalador va primero (revisión UX ALT-1); se llena al final porque depende del alcance y de las reglas.
  const installSlot = el('div'); content.append(installSlot);
  const intro = el('div', undefined, 'notice');
  intro.append(el('strong', '¿Qué es esto?'), el('p', 'Es la puerta de entrada de los computadores a Keeper. Cuando instalas Keeper en un equipo, el equipo pide permiso para empezar a reportar. Según las reglas de aprobación de la empresa se aprueba solo o queda aquí para que decidas a qué persona pertenece.'),
    el('p', 'Para no aprobar uno por uno: carga antes la lista de "equipos esperados" (persona + placa + número de serie). Si un equipo que se instala coincide con uno esperado, se asigna solo. También puedes dejar que la persona escriba su cédula al instalar.'));
  content.append(intro);

  let fullScope = true;
  try { await requests(content, tenant, rerender); }
  catch (error) { if (error.status === 403) fullScope = false; else throw error; }
  await expected(content, tenant, rerender);
  if (fullScope) installer(installSlot, tenant, await keysAndSettings(content, tenant, rerender), await protection(tenant));
}

// Antes de instalar (fase 4, F8): modo de endurecimiento y cuenta de IT. Solo se leen (la contraseña nunca se revela).
// na = endurecimiento no contratado; denied = la cuenta no puede leerlo; error = no se pudo consultar.
async function protection(tenant) {
  let access;
  try { access = await api('GET', '/me/access', { tenant }); } catch (error) { return { kind: 'error', error }; }
  if (!(access.features || []).includes('endurecimiento')) return { kind: 'na' };
  try { const settings = await loadHardeningSettings(tenant); return settings ? { kind: 'ok', settings } : { kind: 'denied' }; }
  catch (error) { return { kind: 'error', error }; }
}
function protectionNotice(parent, state) {
  if (state.kind === 'na') return;
  const settingsLink = text => { const a = el('a', text); a.href = '/ajustes.php'; return a; };
  if (state.kind === 'denied') { parent.append(el('p', 'No se pudo revisar el modo de endurecimiento ni la cuenta de IT: tu cuenta no tiene permiso para verlos.', 'help')); return; }
  if (state.kind === 'error') { parent.append(el('p', `No se pudo revisar el endurecimiento antes de instalar: ${message(state.error)}`, 'error-message')); return; }
  const s = state.settings;
  if (s.hardening_mode === 'auto') {
    const n = el('div', undefined, 'notice install-warning'); n.setAttribute('role', 'note');
    n.append(el('strong', 'Endurecimiento automático activo'),
      el('p', `Al instalar Keeper, cada equipo se endurece solo: sus cuentas administradoras pasan a usuario estándar y solo la cuenta de IT${s.configured ? ` (${s.admin_name})` : ''} conserva la administración. Para el primer equipo se recomienda «desde el panel».`));
    if (!s.configured) n.append(el('p', 'Además la cuenta de IT no tiene contraseña: configúrala antes de instalar.'));
    const p = el('p'); p.append(settingsLink('Revisar en Ajustes › Seguridad de cuentas de Windows')); n.append(p);
    parent.append(n); return;
  }
  if (!s.configured) {
    const n = el('div', undefined, 'notice install-warning'); n.setAttribute('role', 'note');
    n.append(el('strong', 'Cuenta de IT sin configurar'), el('p', 'Puedes instalar y monitorear, pero no endurecer equipos hasta definir la cuenta de IT y su contraseña.'));
    const p = el('p'); p.append(settingsLink('Configurarla en Ajustes')); n.append(p);
    parent.append(n); return;
  }
  parent.append(el('p', `Endurecimiento: desde el panel, equipo por equipo · cuenta de IT ${s.admin_name} configurada.`, 'help install-protection'));
}

// Frase que respeta las reglas de aprobación del cliente (enrollment_settings): no promete «pendiente» si se aprueba solo.
export function approvalNote(rules) {
  const auto = [rules?.auto_approve_serial_match && 'los equipos cuya serie coincide con un equipo esperado', rules?.self_identify_auto_confirm && 'los que se identifican con la cédula de una persona'].filter(Boolean);
  return auto.length
    ? `El registro seguirá las reglas de aprobación de este cliente: ${auto.join(' y ')} se aprueban solos; el resto queda en Solicitudes de alta hasta que lo apruebes.`
    : 'El registro seguirá las reglas de aprobación de este cliente: ningún equipo se aprueba solo, cada uno queda en Solicitudes de alta hasta que lo apruebes.';
}

function installer(parent, tenant, keys, guard = { kind: 'na' }) {
  const s = section(parent, 'Instalar Keeper en los equipos');
  protectionNotice(s, guard);
  const steps = el('ol', undefined, 'next-steps');
  [['Prepara a las personas', 'Registra los equipos esperados (persona + serie) o pide la cédula al instalar: así el alta se asigna sola.'],
    ['Descarga el instalador', 'Es el paquete de esta empresa.'],
    ['Ejecútalo en cada equipo Windows', 'Descargarlo no instala nada: falta ejecutarlo en cada equipo.'],
    ['Comprueba el registro', 'Cuando el equipo se conecta aparece en Solicitudes de alta o, si cruzó con uno esperado, en Equipos esperados como enrolado.']]
    .forEach(([title, help]) => { const li = el('li'); li.append(el('strong', title), el('small', help, 'help')); steps.append(li); });
  s.append(steps);
  const form = el('form', undefined, 'toolbar'); form.noValidate = true;
  const password = field(form, 'Tu contraseña (confirmación)', 'password', { type: 'password', required: true });
  password.autocomplete = 'current-password';
  const download = el('button', 'Descargar instalador', 'primary'); download.type = 'submit'; form.append(download); s.append(form);
  s.append(el('p', 'Cada descarga crea una clave de registro nueva dentro del paquete. Trátalo como secreto: quien lo tenga puede pedir el alta de un equipo. Para invalidarlo, revoca su clave en «Claves de alta».', 'help'));
  const fb = feedback(s);
  const result = el('div'); result.setAttribute('role', 'status'); s.append(result);
  // El servidor crea una clave nueva y la mete en installation.json del paquete publicado.
  form.addEventListener('submit', async event => {
    event.preventDefault(); fb.clear(); result.replaceChildren();
    if (!password.value) { fb.fail('Escribe tu contraseña para confirmar.'); return; }
    if (guard.kind === 'ok' && guard.settings.hardening_mode === 'auto'
      && !confirm('El endurecimiento está en modo automático: los equipos donde se instale este paquete se endurecerán solos. ¿Descargar igualmente?')) return;
    download.disabled = true;
    let keyId = null, delivered = false;
    try {
      await api('POST', '/auth/reauth', { tenant, body: { password: password.value } });
      password.value = '';
      const response = await api('POST', '/enrollments/keys/installer', { tenant, raw: true });
      keyId = response.headers.get('X-Enrollment-Key-Id');
      const name = (response.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/)?.[1] || 'AZCKeeper-instalador.zip';
      const url = URL.createObjectURL(await response.blob());
      const a = el('a'); a.href = url; a.download = name; document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 60000);
      delivered = true;
      let hint = null;
      try { hint = (await keys.refresh()).find(k => k.id === keyId)?.hint || null; } catch { /* la lista se actualiza al recargar */ }
      // Éxito persistente: queda visible hasta la próxima descarga, aunque se actualice la lista de claves.
      const done = el('div', undefined, 'notice');
      done.append(el('strong', `Instalador descargado: ${name}`),
        el('p', `Trae la clave de registro nueva${hint ? ` kek_${hint}…` : ''}, ya visible en «Claves de alta». Siguiente paso: ejecútalo en cada equipo Windows.`),
        el('p', approvalNote(keys.rules())));
      result.append(done);
    } catch (error) {
      password.value = '';
      // La respuesta llegó pero el archivo no: esa clave nunca se entregó, se revoca (mejor esfuerzo).
      if (keyId && !delivered) {
        try { await api('DELETE', `/enrollments/keys/${keyId}`, { tenant }); await keys.refresh(); } catch { /* queda visible en la lista para revocarla a mano */ }
      }
      fb.fail(error?.problem?.detail === 'installer_unavailable' ? 'No hay un instalador publicado en el servidor.' : error);
    } finally { download.disabled = false; }
  });
}

// «Cargar 100 más» (fase 4, F9): las listas de alta pasan de 100 filas sin perder las ya mostradas.
function morePager(parent) {
  const box = el('div', undefined, 'toolbar list-more'); box.hidden = true;
  const info = el('p', '', 'help'); info.setAttribute('role', 'status');
  const more = el('button', 'Cargar 100 más'); more.type = 'button';
  const error = el('p', '', 'error-message'); error.hidden = true; error.setAttribute('role', 'alert');
  box.append(info, more); parent.append(box, error);
  let shown = 0; let handler = null;
  const pager = {
    next: null,
    reset() { shown = 0; pager.next = null; box.hidden = true; error.hidden = true; },
    update(result, noun) {
      shown += result.data.length; pager.next = result.next_offset ?? null;
      box.hidden = shown === 0; more.hidden = pager.next === null;
      info.textContent = pager.next === null ? `${shown} ${noun} en total.` : `Se muestran ${shown} ${noun}; hay más.`;
    },
    onMore(fn) { handler = fn; },
  };
  more.addEventListener('click', async () => {
    more.disabled = true; error.hidden = true;
    try { await handler?.(); } catch (e) { error.textContent = message(e); error.hidden = false; } finally { more.disabled = false; }
  });
  return pager;
}

async function requests(content, tenant, rerender) {
  const box = el('div'); content.append(box);
  const s = section(box, 'Solicitudes de alta', 'Equipos que se instalaron y no cruzaron con un equipo esperado. Aprueba escribiendo la cédula o el correo de la persona; la placa es opcional.');
  const filter = el('div', undefined, 'toolbar'); s.append(filter);
  const status = select(filter, 'Mostrar', { pending: 'Pendientes', approved: 'Aprobadas sin enrolar', enrolled: 'Enroladas', rejected: 'Rechazadas' }, 'pending');
  // Resultado de la última decisión fuera de la tabla: recargarla ya no lo borra.
  const decision = feedback(s);
  const holder = el('div'); s.append(holder);
  const pager = morePager(s);
  let body = null;
  async function load(append = false) {
    const result = await api('GET', `/enrollments/requests?status=${status.value}&limit=100${append ? `&offset=${pager.next}` : ''}`, { tenant });
    if (!append) { holder.replaceChildren(); body = null; pager.reset(); }
    if (!append && !result.data.length) { holder.append(el('p', status.value === 'pending' ? 'No hay equipos esperando aprobación.' : 'No hay solicitudes en este estado.', 'empty-state')); return; }
    body ??= table(holder, 'Solicitudes de alta', ['Equipo', 'Serie', 'Coincidencia', 'Persona', 'Última vez', status.value === 'pending' ? 'Decisión' : 'Estado']);
    result.data.forEach(r => {
      const tr = el('tr');
      const hostCell = el('td'); hostCell.append(el('strong', r.hostname), el('br'), el('small', `Agente ${r.agent_version}`)); tr.append(hostCell);
      tr.append(el('td', r.serial_number || 'Sin serie'));
      const match = el('td', matchNames[r.match_kind] || r.match_kind);
      if (r.alert) { match.append(el('br'), el('small', alertNames[r.alert] || r.alert, 'metric-error')); }
      if (r.claimed_document) { match.append(el('br'), el('small', `Cédula escrita: ${r.claimed_document}`)); }
      tr.append(match);
      tr.append(el('td', r.user_name || (r.suggested_user_name ? `Sugerida: ${r.suggested_user_name}` : '—')));
      tr.append(el('td', when(r.last_seen_at)));
      const action = el('td');
      if (r.status === 'pending') {
        const form = el('form', undefined, 'toolbar'); form.noValidate = true;
        const person = field(form, 'Cédula o correo', 'person', { placeholder: r.suggested_user_name ? 'Vacío = la sugerida' : '1020304050', max: 254 });
        const asset = field(form, 'Placa (opcional)', 'asset', { placeholder: 'ACT_0015', max: 40 });
        if (r.asset_code) asset.value = r.asset_code;
        const approve = el('button', 'Aprobar', 'primary'); approve.type = 'submit';
        const reject = el('button', 'Rechazar'); reject.type = 'button';
        form.append(approve, reject); action.append(form);
        const fb = feedback(action);
        form.addEventListener('submit', async event => {
          event.preventDefault(); fb.clear();
          if (!person.value.trim() && !r.suggested_user_id) { fb.fail('Escribe la cédula o el correo de la persona a la que pertenece el equipo.'); return; }
          approve.disabled = true;
          try {
            await api('POST', `/enrollments/requests/${r.id}/approve`, { tenant, body: { person: person.value.trim() || null, asset_code: asset.value.trim() || null } });
            decision.ok(`${r.hostname}: aprobada, esperando conexión. El equipo se enrola en su próximo reintento (unos minutos); mientras tanto aparece en «Aprobadas sin enrolar».`); await load();
          } catch (error) { fb.fail(error); } finally { approve.disabled = false; }
        });
        reject.addEventListener('click', async () => {
          if (!confirm(`¿Rechazar el alta de ${r.hostname}? El equipo no quedará gestionado por Keeper.`)) return;
          reject.disabled = true;
          try { await api('POST', `/enrollments/requests/${r.id}/reject`, { tenant }); decision.ok(`${r.hostname}: alta rechazada. Aparece en «Rechazadas».`); await load(); }
          catch (error) { fb.fail(error); reject.disabled = false; }
        });
      } else {
        action.append(el('span', statusNames[r.status] || r.status));
        if (r.asset_code) action.append(el('br'), el('small', `Placa ${r.asset_code}`));
        if (r.device_id) { const a = el('a', 'Ver equipo'); a.href = `/equipo.php?id=${encodeURIComponent(r.device_id)}`; action.append(el('br'), a); }
      }
      tr.append(action); body.append(tr);
    });
    pager.update(result, 'solicitudes');
  }
  pager.onMore(() => load(true));
  status.addEventListener('change', () => load().catch(error => { holder.replaceChildren(el('p', message(error), 'error-message')); }));
  await load();
}

async function expected(content, tenant, rerender) {
  const s = section(content, 'Equipos esperados', 'Registra a quién pertenece cada equipo antes de instalarlo. La serie es la del fabricante (etiqueta del equipo o BIOS); con ella el alta es automática.');
  const form = el('form', undefined, 'toolbar'); form.noValidate = true;
  const person = field(form, 'Cédula o correo de la persona', 'person', { required: true, max: 254 });
  const asset = field(form, 'Placa', 'asset', { placeholder: 'ACT_0015', max: 40 });
  const serial = field(form, 'Serie del fabricante', 'serial', { placeholder: 'PF3ABC12', max: 120 });
  const add = el('button', 'Registrar equipo', 'primary'); add.type = 'submit'; form.append(add); s.append(form);
  const fb = feedback(s);
  form.addEventListener('submit', async event => {
    event.preventDefault(); fb.clear();
    if (!person.value.trim()) { fb.fail('Escribe la cédula o el correo de la persona.'); return; }
    if (!asset.value.trim() && !serial.value.trim()) { fb.fail(rowErrors.missing_identifier); return; }
    add.disabled = true;
    try {
      await api('POST', '/enrollments/expected-devices', { tenant, body: { person: person.value.trim(), asset_code: asset.value.trim() || null, serial_number: serial.value.trim() || null } });
      form.reset(); fb.ok('Equipo registrado.'); await load();
    } catch (error) { fb.fail(error); } finally { add.disabled = false; }
  });

  const csv = el('details'); csv.append(el('summary', 'Carga masiva desde CSV'));
  csv.append(el('p', 'Columnas: cédula (o correo), placa, serie. Separador coma o punto y coma. Primera fila = encabezados. Las filas con error se informan y el resto se carga.', 'help'));
  const example = el('pre', 'cedula;placa;serie\n1020304050;ACT_0015;PF3ABC12\n1098765432;ACT_0016;'); csv.append(example);
  const fileLabel = el('label', 'Archivo CSV'); const file = el('input'); file.type = 'file'; file.accept = '.csv,text/csv'; fileLabel.append(file); csv.append(fileLabel);
  const upload = el('button', 'Cargar archivo', 'primary'); upload.type = 'button'; upload.disabled = true; csv.append(upload);
  const csvFb = feedback(csv); const errorsList = el('ul'); csv.append(errorsList); s.append(csv);
  let parsed = null;
  file.addEventListener('change', async () => {
    csvFb.clear(); errorsList.replaceChildren(); parsed = null; upload.disabled = true;
    const f = file.files?.[0]; if (!f) return;
    if (f.size > 2 * 1024 * 1024) { csvFb.fail('El archivo supera 2 MB.'); return; }
    parsed = parseCsv(await f.text());
    if (parsed.problem) { csvFb.fail(parsed.problem); parsed = null; return; }
    csvFb.ok(`${parsed.rows.length} filas listas para cargar${parsed.invalid.length ? `; ${parsed.invalid.length} filas con valores demasiado largos no se enviarán (${parsed.invalid.slice(0, 20).join(', ')})` : ''}.`);
    upload.disabled = !parsed.rows.length;
  });
  upload.addEventListener('click', async () => {
    if (!parsed) return; upload.disabled = true; errorsList.replaceChildren();
    let created = 0; const errors = [];
    try {
      for (let start = 0; start < parsed.rows.length; start += 2000) {
        const slice = parsed.rows.slice(start, start + 2000);
        const chunk = slice.map(r => ({ person: r.person || '-', asset_code: r.asset_code, serial_number: r.serial_number }));
        const result = await api('POST', '/enrollments/expected-devices:import', { tenant, body: { rows: chunk } });
        created += result.created; result.errors.forEach(e => errors.push({ row: slice[e.row - 1].line, code: e.code }));
      }
      parsed.invalid.forEach(line => errors.push({ row: line, code: 'too_long' }));
      csvFb.ok(`${created} equipos cargados${errors.length ? `, ${errors.length} filas con error (la fila 1 es el encabezado):` : '.'}`);
      errors.slice(0, 200).forEach(e => errorsList.append(el('li', `Fila ${e.row}: ${rowErrors[e.code] || e.code}`)));
      await load();
    } catch (error) { csvFb.fail(error); }
    finally { parsed = null; file.value = ''; }
  });

  const filter = el('div', undefined, 'toolbar'); s.append(filter);
  const status = select(filter, 'Mostrar', { pending: 'Pendientes de instalar', enrolled: 'Ya enrolados', cancelled: 'Cancelados' }, 'pending');
  const holder = el('div'); s.append(holder);
  const pager = morePager(s);
  let body = null;
  async function load(append = false) {
    const result = await api('GET', `/enrollments/expected-devices?status=${status.value}&limit=100${append ? `&offset=${pager.next}` : ''}`, { tenant });
    if (!append) { holder.replaceChildren(); body = null; pager.reset(); }
    if (!append && !result.data.length) { holder.append(el('p', 'No hay equipos en este estado.', 'empty-state')); return; }
    body ??= table(holder, 'Equipos esperados', ['Persona', 'Placa', 'Serie', 'Origen', 'Registrado', '']);
    result.data.forEach(e => {
      const tr = el('tr');
      [e.user_name, e.asset_code || '—', e.serial_number || '—', { manual: 'Manual', csv: 'CSV', api: 'API', connector: 'Conector' }[e.source] || e.source, when(e.created_at)].forEach(v => tr.append(el('td', v)));
      const cell = el('td');
      if (e.status === 'pending') {
        const cancel = el('button', 'Cancelar'); cancel.type = 'button';
        cancel.addEventListener('click', async () => {
          if (!confirm(`¿Cancelar el equipo esperado ${e.asset_code || e.serial_number} de ${e.user_name}?`)) return;
          cancel.disabled = true;
          try { await api('DELETE', `/enrollments/expected-devices/${e.id}`, { tenant }); await load(); } catch (error) { cell.append(el('span', message(error), 'error-message')); cancel.disabled = false; }
        });
        cell.append(cancel);
      } else if (e.device_id) { const a = el('a', 'Ver equipo'); a.href = `/equipo.php?id=${encodeURIComponent(e.device_id)}`; cell.append(a); }
      tr.append(cell); body.append(tr);
    });
    pager.update(result, 'equipos');
  }
  pager.onMore(() => load(true));
  status.addEventListener('change', () => load().catch(error => { holder.replaceChildren(el('p', message(error), 'error-message')); }));
  await load();
}

async function keysAndSettings(content, tenant, rerender) {
  const s = section(content, 'Reglas de aprobación y claves de alta', 'La clave de alta va dentro del paquete de instalación de la empresa. Si se filtra, revócala y crea otra: los equipos ya enrolados no se afectan.');
  let settings = await api('GET', '/enrollments/settings', { tenant });
  const form = el('form'); form.noValidate = true;
  const check = (name, text, help) => {
    const label = el('label'); const input = el('input'); input.type = 'checkbox'; input.name = name; input.checked = settings[name];
    label.append(input, ` ${text}`); form.append(label, el('p', help, 'help')); return input;
  };
  const auto = check('auto_approve_serial_match', 'Aprobar solo cuando la serie coincide con un equipo esperado', 'Recomendado. Si lo desactivas, cada coincidencia queda como sugerencia y la apruebas tú.');
  const self = check('self_identify', 'Pedir la cédula a la persona al instalar', 'El equipo pregunta la cédula del primer usuario que inicia sesión y la usa como sugerencia.');
  const confirmSelf = check('self_identify_auto_confirm', 'Aprobar sola la cédula escrita en el equipo', 'No recomendado: quien conozca la cédula de otra persona podría reclamar un equipo a su nombre.');
  const sync = () => { confirmSelf.disabled = !self.checked; if (!self.checked) confirmSelf.checked = false; };
  self.addEventListener('change', sync); sync();
  const save = el('button', 'Guardar reglas', 'primary'); save.type = 'submit'; form.append(save); s.append(form);
  const fb = feedback(s);
  form.addEventListener('submit', async event => {
    event.preventDefault(); save.disabled = true; fb.clear();
    const body = { auto_approve_serial_match: auto.checked, self_identify: self.checked, self_identify_auto_confirm: confirmSelf.checked };
    try { await api('PUT', '/enrollments/settings', { tenant, body }); settings = { ...settings, ...body }; fb.ok('Reglas guardadas.'); }
    catch (error) { fb.fail(error); } finally { save.disabled = false; }
  });

  s.append(el('h3', 'Claves de alta'));
  const list = el('div'); s.append(list);
  // Clave manual: secundaria, para IT que arma su propio paquete. Lo habitual es «Descargar instalador», arriba.
  const manual = el('div'); s.append(manual);
  manual.append(el('p', 'Crear una clave manual solo hace falta si IT arma su propio paquete de instalación: la clave se pega en installation.json. Para instalar Keeper basta «Descargar instalador».', 'help'));
  const create = el('form', undefined, 'toolbar'); create.noValidate = true;
  const password = field(create, 'Tu contraseña (confirmación)', 'password', { type: 'password', required: true });
  password.autocomplete = 'current-password';
  const go = el('button', 'Crear clave manual'); go.type = 'submit';
  create.append(go); manual.append(create);
  const keyFb = feedback(manual); const reveal = el('div'); manual.append(reveal);
  // Se vuelve a dibujar tras descargar el instalador o crear una clave, sin tocar los avisos ni la clave revelada.
  const refresh = async () => {
    const keys = await api('GET', '/enrollments/keys', { tenant });
    list.replaceChildren();
    if (!keys.data.length) list.append(el('p', 'Aún no hay clave de alta: al descargar el instalador se crea una dentro del paquete.', 'empty-state'));
    else {
      const body = table(list, 'Claves de alta', ['Clave', 'Creada', 'Estado', '']);
      keys.data.forEach(k => {
        const tr = el('tr'); tr.append(el('td', `kek_${k.hint}…`), el('td', when(k.created_at)), el('td', k.revoked_at ? `Revocada ${when(k.revoked_at)}` : 'Activa'));
        const cell = el('td');
        if (!k.revoked_at) {
          const revoke = el('button', 'Revocar'); revoke.type = 'button';
          revoke.addEventListener('click', async () => {
            if (prompt(`Los paquetes con la clave kek_${k.hint}… dejarán de poder dar de alta equipos nuevos. Escribe REVOCAR para confirmar.`) !== 'REVOCAR') return;
            revoke.disabled = true;
            try { await api('DELETE', `/enrollments/keys/${k.id}`, { tenant }); await rerender(); } catch (error) { cell.append(el('span', message(error), 'error-message')); revoke.disabled = false; }
          });
          cell.append(revoke);
        }
        tr.append(cell); body.append(tr);
      });
    }
    go.textContent = keys.data.some(k => !k.revoked_at) ? 'Crear otra clave manual' : 'Crear clave manual';
    return keys.data;
  };
  await refresh();
  create.addEventListener('submit', async event => {
    event.preventDefault(); keyFb.clear(); reveal.replaceChildren();
    if (!password.value) { keyFb.fail('Escribe tu contraseña para confirmar.'); return; }
    go.disabled = true;
    try {
      await api('POST', '/auth/reauth', { tenant, body: { password: password.value } });
      password.value = '';
      const key = await api('POST', '/enrollments/keys', { tenant });
      reveal.append(el('p', 'Copia la clave ahora: no se volverá a mostrar. Va en installation.json del paquete genérico:', 'notice'));
      reveal.append(el('pre', JSON.stringify({ api_base: `${location.origin}/v1/`, enrollment_key: key.key }, null, 2)));
      const copy = el('button', 'Copiar clave'); copy.type = 'button';
      copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(key.key); copy.textContent = 'Copiada'; } catch { copy.textContent = 'Selecciónala y cópiala a mano'; } });
      reveal.append(copy);
      keyFb.ok(`Clave kek_${key.hint}… creada y añadida a la lista. ${approvalNote(settings)}`);
      try { await refresh(); } catch { reveal.append(el('p', 'La lista de claves no se pudo actualizar: recarga la página para verla.', 'help')); }
    } catch (error) { password.value = ''; keyFb.fail(error); } finally { go.disabled = false; }
  });
  return { refresh, rules: () => settings };
}
