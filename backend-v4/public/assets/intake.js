import { api, errorMessage } from './api.js';

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
  const intro = el('div', undefined, 'notice');
  intro.append(el('p', 'Instala el mismo paquete de Keeper en todos los equipos. Cada equipo pide alta con su número de serie: si coincide con un equipo esperado se asigna solo a su persona y toma la placa como nombre; si no, aparece aquí para que lo apruebes.'));
  content.append(intro);

  let fullScope = true;
  try { await requests(content, tenant, rerender); }
  catch (error) { if (error.status === 403) fullScope = false; else throw error; }
  await expected(content, tenant, rerender);
  if (fullScope) await keysAndSettings(content, tenant, rerender);
}

async function requests(content, tenant, rerender) {
  const box = el('div'); content.append(box);
  const s = section(box, 'Solicitudes de alta', 'Equipos que se instalaron y no cruzaron con un equipo esperado. Aprueba escribiendo la cédula o el correo de la persona; la placa es opcional.');
  const filter = el('div', undefined, 'toolbar'); s.append(filter);
  const status = select(filter, 'Mostrar', { pending: 'Pendientes', approved: 'Aprobadas sin enrolar', enrolled: 'Enroladas', rejected: 'Rechazadas' }, 'pending');
  const holder = el('div'); s.append(holder);
  async function load() {
    const result = await api('GET', `/enrollments/requests?status=${status.value}&limit=100`, { tenant });
    holder.replaceChildren();
    if (!result.data.length) { holder.append(el('p', status.value === 'pending' ? 'No hay equipos esperando aprobación.' : 'No hay solicitudes en este estado.', 'empty-state')); return; }
    const body = table(holder, 'Solicitudes de alta', ['Equipo', 'Serie', 'Coincidencia', 'Persona', 'Última vez', status.value === 'pending' ? 'Decisión' : 'Estado']);
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
            fb.ok('Aprobada. El equipo se enrola en su próximo reintento (unos minutos).'); await load();
          } catch (error) { fb.fail(error); } finally { approve.disabled = false; }
        });
        reject.addEventListener('click', async () => {
          if (!confirm(`¿Rechazar el alta de ${r.hostname}? El equipo no quedará gestionado por Keeper.`)) return;
          reject.disabled = true;
          try { await api('POST', `/enrollments/requests/${r.id}/reject`, { tenant }); await load(); }
          catch (error) { fb.fail(error); reject.disabled = false; }
        });
      } else {
        action.append(el('span', statusNames[r.status] || r.status));
        if (r.asset_code) action.append(el('br'), el('small', `Placa ${r.asset_code}`));
        if (r.device_id) { const a = el('a', 'Ver equipo'); a.href = `/equipo.php?id=${encodeURIComponent(r.device_id)}`; action.append(el('br'), a); }
      }
      tr.append(action); body.append(tr);
    });
    if (result.next_offset !== null) holder.append(el('p', 'Se muestran las 100 más recientes.', 'help'));
  }
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
  async function load() {
    const result = await api('GET', `/enrollments/expected-devices?status=${status.value}&limit=100`, { tenant });
    holder.replaceChildren();
    if (!result.data.length) { holder.append(el('p', 'No hay equipos en este estado.', 'empty-state')); return; }
    const body = table(holder, 'Equipos esperados', ['Persona', 'Placa', 'Serie', 'Origen', 'Registrado', '']);
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
    if (result.next_offset !== null) holder.append(el('p', 'Se muestran los 100 más recientes.', 'help'));
  }
  status.addEventListener('change', () => load().catch(error => { holder.replaceChildren(el('p', message(error), 'error-message')); }));
  await load();
}

async function keysAndSettings(content, tenant, rerender) {
  const s = section(content, 'Clave de alta y reglas', 'La clave de alta va dentro del paquete de instalación de la empresa. Si se filtra, revócala y crea otra: los equipos ya enrolados no se afectan.');
  const settings = await api('GET', '/enrollments/settings', { tenant });
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
    try { await api('PUT', '/enrollments/settings', { tenant, body: { auto_approve_serial_match: auto.checked, self_identify: self.checked, self_identify_auto_confirm: confirmSelf.checked } }); fb.ok('Reglas guardadas.'); }
    catch (error) { fb.fail(error); } finally { save.disabled = false; }
  });

  s.append(el('h3', 'Claves de alta'));
  const keys = await api('GET', '/enrollments/keys?limit=100', { tenant });
  const active = keys.data.filter(k => !k.revoked_at);
  if (!keys.data.length) s.append(el('p', 'Aún no hay clave de alta: créala para generar el paquete genérico de la empresa.', 'empty-state'));
  else {
    const body = table(s, 'Claves de alta', ['Clave', 'Creada', 'Estado', '']);
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
  const create = el('form', undefined, 'toolbar'); create.noValidate = true;
  const password = field(create, 'Tu contraseña (confirmación)', 'password', { type: 'password', required: true });
  password.autocomplete = 'current-password';
  const go = el('button', active.length ? 'Crear otra clave' : 'Crear clave de alta', 'primary'); go.type = 'submit'; create.append(go); s.append(create);
  const keyFb = feedback(s); const reveal = el('div'); s.append(reveal);
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
    } catch (error) { password.value = ''; keyFb.fail(error); } finally { go.disabled = false; }
  });
}
