/* Estado local del recorrido de incorporación; recargar restablece los ejemplos. */
window.ALTAS = (() => {
  'use strict';
  const D = window.DEMO;
  const plans = { cliente: 'Plan del cliente · ' + (D.clientPlan || 'Protección de oficina'), oficina: 'Protección de oficina', sin: 'Sin controles de protección' };
  const schedules = ['08:00–17:00 · lunes a viernes', '09:00–18:00 · lunes a viernes', 'Sin horario asignado'];
  const people = D.people.map(p => ({ ...p, document: String(9900000000 + p.index), email: '', status: 'active', panelAccess: true, schedule: schedules[0], plan: p.id === 'p005' ? 'sin' : 'cliente', createdWeek: ['p003','p005','p008'].includes(p.id), history: [] }));
  const devices = window.ORDERS.devices.map(d => ({ hostname: d.hostname, personId: d.person.id, state: d.state, status: 'active', version: d.version, report: true, reportedAt: d.stale ? '2026-10-27T12:55:00Z' : '2026-10-29T20:20:00Z', seen: d.seen, failed: d.partial, rulesCurrent: true, inventory: false }));
  for (let i = 1; i <= (people.length ? 4 : 0); i++) devices.push({ hostname: `DESKTOP-N00${i}`, personId: 'p300', state: 'connected', status: 'active', version: '4.0.30', report: false, rulesCurrent: false, inventory: true });
  const requests = Array.from({ length: people.length ? 20 : 0 }, (_, i) => ({ id: `s${i + 1}`, hostname: `DESKTOP-P${String(i + 1).padStart(3,'0')}`, match: i < 14 ? 'document' : 'none', personId: i < 14 ? people[i + 9].id : '', document: i < 14 ? people[i + 9].document : '', status: 'pending' }));
  const departures = [];
  let serial = 301;
  const person = id => people.find(p => p.id === id);
  const device = host => devices.find(d => d.hostname === host);
  const owned = id => devices.filter(d => d.personId === id && d.status === 'active' && !d.inventory);
  const available = () => devices.filter(d => d.inventory && d.status === 'active');
  const replacementCandidates = id => [...available(), ...owned(id)];
  const normalize = value => String(value || '').replace(/[\s.-]/g, '');
  const cutoff = Date.parse('2026-10-29T20:30:00Z');
  function deviceProtection(d, p) {
    if ((p.plan === 'sin' || p.plan === 'cliente' && D.clientControls?.length === 0) || d.failed) return { label:'No protegido', ok:false, reason: d.failed ? `${d.hostname}: un control report? fallo.` : 'El plan no incluye controles.' };
    const age = d.reportedAt ? cutoff - Date.parse(d.reportedAt) : Infinity;
    if (d.state === 'offline' || !d.report || !d.rulesCurrent || age > 86400000) return { label:'Sin confirmar', ok:false, reason:`${d.hostname}: ${d.state === 'offline' ? 'sin conexión desde ' + (d.seen || 'fecha no disponible') : 'reporte pendiente o anterior a 24 h'}; último reporte: ${d.reportedAt ? new Date(d.reportedAt).toLocaleString('es-CO', {timeZone:'America/Bogota'}) : 'sin reporte recibido'}.` };
    return { label:'Protegido', ok:true, reason:'Controles confirmados en las últimas 24 h.' };
  }
  function protection(p) {
    const list = owned(p.id);
    if (p.status !== 'active') return { label:'No protegido', ok:false, reason:'Persona en baja o retirada; acceso al panel revocado.' };
    if ((p.plan === 'sin' || p.plan === 'cliente' && D.clientControls?.length === 0) || !list.length) return { label:'No protegido', ok:false, reason:(p.plan === 'sin' || p.plan === 'cliente' && D.clientControls?.length === 0) ? 'El plan no incluye controles.' : 'Falta instalar o asignar un equipo.' };
    const states = list.map(d => deviceProtection(d,p));
    const failed = states.filter(s => s.label === 'No protegido'), waiting = states.filter(s => s.label === 'Sin confirmar');
    return failed.length ? { label:'No protegido', ok:false, reason:failed.map(s => s.reason).join(' ') } : waiting.length ? { label:'Sin confirmar', ok:false, reason:waiting.map(s => s.reason).join(' ') } : { label:'Protegido', ok:true, reason:'Todos sus equipos activos reportaron controles en las últimas 24 h.' };
  }
  const followup = () => people.filter(p => p.createdWeek && p.status === 'active' && !protection(p).ok);
  function validate(draft, checkEquipment = true) {
    const errors = [];
    if (!draft.name?.trim()) errors.push('Falta el nombre.');
    if (draft.name?.length > 120) errors.push('El nombre supera 120 caracteres.');
    const doc = normalize(draft.document);
    if (doc && !/^\d{5,15}$/.test(doc)) errors.push('La cédula debe tener entre 5 y 15 dígitos.');
    if (doc && people.some(p => p.document === doc)) errors.push('La cédula ya existe en este cliente.');
    if (draft.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(draft.email)) errors.push('El correo no es válido.');
    if (!D.companies.includes(draft.company)) errors.push('Selecciona una empresa del cliente.');
    if (!D.sites.includes(draft.site)) errors.push('Selecciona una sede válida.');
    if (!D.areas.includes(draft.area)) errors.push('Selecciona un área válida.');
    if (!draft.job?.trim()) errors.push('Falta el cargo.');
    if (!schedules.includes(draft.schedule)) errors.push('Selecciona un horario.');
    if (!Object.hasOwn(plans, draft.plan)) errors.push('Selecciona un plan.');
    if (checkEquipment) {
      if (!['installed','pending','later'].includes(draft.equipment)) errors.push('Elige cómo vincular el equipo.');
      if (draft.equipment === 'installed' && !available().some(d => d.hostname === draft.equipmentId)) errors.push('El equipo elegido ya no está disponible en reserva de IT.');
      if (draft.equipment === 'pending' && !requests.some(r => r.id === draft.equipmentId && r.status === 'pending' && !r.personId)) errors.push('Elige una solicitud pendiente sin otra persona asignada.');
    }
    return errors;
  }
  function attach(d, p) {
    const previous = person(d.personId);
    if (previous && previous.id !== p.id) previous.history.push(`${d.hostname} dejó de estar asignado a esta persona; historial anterior conservado.`);
    d.personId = p.id; d.inventory = false; d.report = false; d.rulesCurrent = false;
    p.history.push(`${d.hostname} asignado. Reglas de la persona y su organización pendientes de recibir.`);
  }
  function create(draft) {
    const errors = validate(draft);
    if (errors.length) return { errors };
    const p = { ...draft, id: `p${serial++}`, client: D.client || 'Grupo AZC', document: normalize(draft.document), name: draft.name.trim(), status: 'active', createdWeek: true, history: ['Persona creada en esta demostración.'], installationPrepared: !!draft.installationPrepared };
    people.push(p);
    if (draft.equipment === 'installed') attach(device(draft.equipmentId), p);
    if (draft.equipment === 'pending') { const r = requests.find(r => r.id === draft.equipmentId); r.personId = p.id; r.status = 'approved'; p.history.push(`${r.hostname}: solicitud aprobada, esperando conexión.`); }
    return { person: p, errors: [] };
  }
  function approve(items) {
    if (!items.length || new Set(items.map(i => i.id)).size !== items.length) return 'Selecciona solicitudes distintas.';
    for (const item of items) {
      const r = requests.find(r => r.id === item.id), p = person(item.personId);
      if (!r || r.status !== 'pending' || !p || p.status !== 'active' || r.match === 'document' && r.personId !== p.id) return 'La solicitud o su persona cambió. Revisa el lote nuevamente.';
    }
    for (const item of items) { const r = requests.find(r => r.id === item.id); r.personId = item.personId; r.status = 'approved'; person(item.personId).history.push(`${r.hostname}: solicitud aprobada; falta conexión.`); }
    return '';
  }
  function connectRequest(id) {
    const r = requests.find(r => r.id === id);
    if (!r || r.status !== 'approved' || person(r.personId)?.status !== 'active') return false;
    if (!device(r.hostname)) devices.push({ hostname: r.hostname, personId: r.personId, status: 'active', state: 'connected', version: '4.0.30', report: false, rulesCurrent: false, inventory: false });
    r.status = 'registered'; return true;
  }
  function confirmReport(host) {
    const d = device(host);
    if (!d || d.status !== 'active' || person(d.personId)?.status !== 'active' || person(d.personId)?.plan === 'sin') return false;
    d.state = 'connected'; d.report = true; d.rulesCurrent = true; d.failed = false; d.reportedAt = '2026-10-29T20:30:00Z'; d.seen = '29 oct, 15:30';
    person(d.personId).history.push(`${host}: reporte correcto recibido (simulado).`); return true;
  }
  function replace(id, oldHost, newHost, reason) {
    const p = person(id), old = device(oldHost), next = device(newHost);
    if (!p || p.status !== 'active' || !owned(id).includes(old) || !replacementCandidates(id).includes(next) || old === next || !reason?.trim()) return 'Revisa la persona, ambos equipos y el motivo.';
    old.status = 'retiring'; old.retirement = 'pending'; old.report = false;
    attach(next, p); p.history.push(`${oldHost}: bloqueo pendiente antes de retirar; identidad del agente conservada. Reemplazo por ${newHost}: ${reason.trim()}`);
    return '';
  }
  function confirmReplacement(host, success = true) {
    const d = device(host);
    if (!d || d.status !== 'retiring') return false;
    d.retirement = success ? 'confirmed' : 'failed';
    if (success) { d.status = 'retired'; d.state = 'connected'; }
    return true;
  }
  function startDeparture(id, choices, confirmation, reauth) {
    const p = person(id);
    if (!p || p.status !== 'active') return 'Esta persona no admite una nueva baja.';
    if (confirmation !== `RETIRAR ${p.name}`) return 'Escribe la confirmación exactamente como aparece.';
    const list = owned(id);
    if (choices.length !== list.length || new Set(choices.map(c => c.hostname)).size !== list.length) return 'Revisa todos los equipos de la persona.';
    for (const c of choices) {
      if (!list.some(d => d.hostname === c.hostname) || !['lock','wipe','assign'].includes(c.action)) return 'Selecciona una acción válida para cada equipo.';
      if (c.action === 'assign' && (!person(c.to) || c.to === id || person(c.to).status !== 'active')) return 'Elige otra persona activa para la reasignación.';
      if (c.action === 'wipe' && (c.confirmation !== c.hostname || !reauth)) return 'El borrado requiere escribir el equipo y confirmar autenticación de demostración.';
      if (['lock','wipe'].includes(c.action) && ((p.plan === 'sin' || p.plan === 'cliente' && D.clientControls?.length === 0) || c.action === 'wipe' && p.id === 'p009')) return 'El plan no permite órdenes remotas. Reasigna los equipos a IT antes de retirar la persona.';
    }
    p.status = 'departing'; p.panelAccess = false;
    const job = { personId: id, actions: choices.map(c => ({ ...c, state: c.action === 'assign' ? 'done' : 'pending' })), finished: false };
    for (const c of job.actions) if (c.action === 'assign') attach(device(c.hostname), person(c.to));
    departures.push(job); p.history.push('Acceso al panel revocado en el acto. El agente conserva su identidad hasta completar las acciones.');
    return '';
  }
  function completeAction(id, host, success = true) {
    const job = departures.find(j => j.personId === id && !j.finished), action = job?.actions.find(c => c.hostname === host);
    if (!action || action.state === 'done') return false;
    action.state = success ? 'done' : 'failed'; if (success) device(host).state = 'connected'; return true;
  }
  function finishDeparture(id) {
    const job = departures.find(j => j.personId === id && !j.finished);
    if (!job || job.actions.some(c => c.state !== 'done')) return 'Faltan acciones confirmadas. La baja sigue pendiente.';
    for (const d of owned(id)) d.status = 'retired';
    person(id).status = 'inactive'; job.finished = true;
    person(id).history.push('Persona retirada; accesos revocados. Historial conservado según retención del cliente.'); return '';
  }
  function parseCSV(text) {
    text = text.replace(/^\uFEFF/, '');
    const first = text.split(/\r?\n/, 1)[0], delimiter = first.includes(';') ? ';' : ',';
    const rows = []; let row = [], field = '', quoted = false, closed = false, line = 1, start = 1;
    for (let i = 0; i <= text.length; i++) {
      const ch = text[i];
      if (ch === '"') {
        if (quoted && text[i + 1] === '"') { field += '"'; i++; }
        else if (!quoted && field !== '') return { error: `Comillas inesperadas en la fila ${line}.`, rows: [] };
        else { quoted = !quoted; closed = !quoted; }
      } else if ((ch === delimiter || ch === '\n' || ch === undefined) && !quoted) {
        row.push(field.trim()); field = ''; closed = false;
        if (ch !== delimiter) { if (row.some(Boolean)) rows.push({ line: start, cells: row }); row = []; start = line + 1; }
      } else if (ch !== '\r') { if (closed && ch?.trim()) return { error: `Texto después de comillas en la fila ${line}.`, rows: [] }; if (ch !== undefined) field += ch; }
      if (ch === '\n') line++;
    }
    if (quoted) return { error: 'Hay un campo entre comillas sin cerrar.', rows: [] };
    if (rows.length < 2) return { error: 'Incluye encabezado y al menos una persona.', rows: [] };
    const headers = rows.shift().cells.map(h => h.toLocaleLowerCase('es-CO'));
    const columns = ['nombre','cedula','correo','empresa','sede','area','cargo','horario','plan'];
    if (new Set(headers).size !== headers.length || columns.some(c => !headers.includes(c))) return { error: `Encabezados requeridos: ${columns.join(';')}`, rows: [] };
    const seen = new Set();
    return { error: '', rows: rows.map(row => {
      const get = key => row.cells[headers.indexOf(key)] || '';
      const draft = { name: get('nombre'), document: get('cedula'), email: get('correo'), company: get('empresa'), site: get('sede'), area: get('area'), job: get('cargo'), schedule: get('horario'), plan: get('plan'), equipment: 'later' };
      const errors = validate(draft, false), doc = normalize(draft.document);
      if (row.cells.length !== headers.length) errors.push('Cantidad de columnas incorrecta.');
      if (doc && seen.has(doc)) errors.push('Cédula repetida dentro del archivo.');
      if (doc) seen.add(doc);
      return { line: row.line, draft, errors };
    }) };
  }
  function importRows(preview) {
    if (preview.error || !preview.rows.length) return { errors:[preview.error || 'No hay filas.'], people:[], rejected:[] };
    const saved = [], rejected = [];
    for (const row of preview.rows) {
      const errors = row.errors.length ? row.errors : validate(row.draft);
      if (errors.length) rejected.push({ ...row, errors });
      else saved.push(create(row.draft).person);
    }
    return { errors:[], people:saved, rejected };
  }
  function instructions(p) {
    return `SOLO MAQUETA — SIN VALIDEZ PARA INSTALAR\nCliente: ${D.client || 'Grupo AZC'}\nPersona: ${p.name}\nCédula: ${p.document || 'No informada; asignación manual'}\nEmpresa: ${p.company}\nSede: ${p.site}\nÁrea: ${p.area}\n\n1. IT entrega el instalador del cliente por su canal autorizado.\n2. Ejecutar en el equipo Windows con permisos de administrador.\n3. Si se solicita, informar la cédula; sin coincidencia IT revisa la solicitud.\n4. Esperar aprobación y conexión.\n5. IT confirma plan, reglas y reporte de protección.\n\nPaquete previsto: AZCKeeper-paquete-del-cliente.zip\nEsta demostración no contiene instalador ni clave real. No ejecuta ni envía nada.\n`;
  }
  return { plans, schedules, people, devices, requests, departures, person, device, owned, available, replacementCandidates, normalize, protection, deviceProtection, confirmReplacement, followup, validate, create, approve, connectRequest, confirmReport, replace, startDeparture, completeAction, finishDeparture, parseCSV, importRows, instructions };
})();
