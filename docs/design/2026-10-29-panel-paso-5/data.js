/* Datos sintéticos reproducibles. No provienen de personas, equipos ni consultas reales. */
window.DEMO = (() => {
  const today = '2026-10-29';
  const yesterday = '2026-10-28';
  const companies = ['AZC Jurídica', 'AZC Gestión', 'AZC Servicios'];
  const areas = ['Litigios', 'Cartera', 'Administración', 'Tecnología'];
  const sites = ['Cali · Centro', 'Bogotá · Norte', 'Medellín · Sur'];
  const firstNames = ['Valeria', 'Tomás', 'Luciana', 'Emilio', 'Mariana', 'Simón', 'Antonia', 'Julián', 'Catalina', 'Nicolás', 'Elena', 'Mateo', 'Isabela', 'Gabriel', 'Paula', 'Samuel', 'Daniela', 'Martín', 'Renata', 'Felipe'];
  const lastNames = ['Montes', 'Arboleda', 'Salcedo', 'Londoño', 'Beltrán', 'Valbuena', 'Castañeda', 'Pineda', 'Ocampo', 'Restrepo', 'Cárdenas', 'Robledo', 'Serrano', 'Palacios', 'Villalba'];
  const clock = minutes => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
  const people = Array.from({ length: 300 }, (_, i) => {
    const state = i % 25 < 19 ? 'connected' : i % 25 < 22 ? 'offline' : 'away';
    return {
      id: `p${String(i + 1).padStart(3, '0')}`, index: i,
      name: `${firstNames[i % 20]} ${lastNames[((i % 20) * 7 + Math.floor(i / 20)) % 15]}`,
      client: 'Grupo AZC', company: companies[Math.floor(i / 100)], area: areas[i % 4], site: sites[Math.floor(i / 4) % 3],
      job: ['Abogada de litigios', 'Analista de cartera', 'Analista administrativa', 'Analista de soporte'][i % 4],
      state, seen: state === 'connected' ? 928 : state === 'away' ? 920 : 840,
      hasDevice: true, partialProtection: i % 17 === 0, late: i % 23 === 4,
    };
  });
  Object.assign(people[1], { state: 'away', seen: 920 });
  Object.assign(people[2], { state: 'offline', seen: 840 });
  Object.assign(people[3], { state: 'offline', seen: 480, absent: true });
  Object.assign(people[4], { late: true });
  Object.assign(people[6], { missing: true });
  Object.assign(people[7], { hasDevice: false, state: 'offline', seen: null });
  function dayFor(person, date) {
    if (![today, yesterday].includes(date)) return null;
    const past = date === yesterday;
    if (person.absent && !past) return { absent: true, arrival: null, active: 0, idle: 0, locked: 0, expected: 0, productive: 0, apps: [], timeline: [] };
    if (person.missing || !person.hasDevice) return { missing: true, arrival: null, active: null, idle: null, locked: null, expected: 390, productive: null, apps: [], timeline: [] };
    const arrival = person.index === 0 ? (past ? 478 : 487) : person.late && !past ? 508 : 477 + person.index % 19;
    const cutoff = past ? 1020 : person.state === 'offline' ? 840 : person.state === 'away' ? 920 : 930;
    const duration = cutoff - arrival;
    const idle = person.index === 0 && !past ? 48 : 25 + person.index % 35;
    const locked = person.index === 0 && !past ? 80 : 60 + person.index % 20;
    const active = duration - idle - locked;
    const nonproductive = person.index >= 4 && person.index <= 24 && person.index % 4 === 0;
    const productive = person.index === 0 && !past ? 276 : nonproductive ? active - 75 : Math.floor(active * (0.70 + (person.index % 5) * 0.04));
    const expected = past ? 480 : 390;
    const a = Math.floor(productive * 0.58), b = productive - a, c = active - productive;
    const apps = [
      { name: 'Microsoft Word', detail: 'Aplicación · Productiva', minutes: a, kind: 'productive' },
      { name: 'Consulta de procesos', detail: 'consultaprocesos.ramajudicial.gov.co · Productivo', minutes: b, kind: 'productive' },
      { name: nonproductive ? 'StreamBox' : 'Explorador de archivos', detail: nonproductive ? 'Sitio ficticio · No productivo' : 'Aplicación · Neutra', minutes: c, kind: nonproductive ? 'nonproductive' : 'neutral' },
    ].sort((x, y) => y.minutes - x.minutes);
    let cursor = arrival;
    const timeline = [
      ['Microsoft Word', a, 'productive'], ['Sin detalle de apps', idle, 'gap'],
      ['Consulta de procesos', b, 'productive'], ['Sin detalle de apps', locked, 'gap'],
      [nonproductive ? 'StreamBox' : 'Explorador de archivos', c, nonproductive ? 'nonproductive' : 'neutral'],
    ].map(([label, minutes, kind]) => { const item = { start: clock(cursor), end: clock(cursor + minutes), label, kind }; cursor += minutes; return item; });
    return { arrival, active, idle, locked, productive, expected, apps, timeline, cutoff };
  }
  function alertsFor(person, date) {
    const day = dayFor(person, date);
    if (!day) return [];
    const alerts = [];
    if (day.arrival > 495) alerts.push({ label: 'Llegada fuera del margen', detail: `Primera actividad a las ${clock(day.arrival)}; límite 08:15. Revisar el contexto de la jornada.`, type: 'arrival', severity: 'media', target: 'day-form' });
    if (date === today) {
      if (!day.absent && devicesFor(person).some(d => d.state === 'offline')) alerts.push({ label: 'Equipo sin conexión en horario', detail: 'Un equipo asignado lleva más de 15 min sin señal durante la jornada. Puede ser un equipo secundario; revisar con la persona.', type: 'offline', severity: 'media', target: 'device-section' });
      if (person.index % 53 === 0) alerts.push({ label: 'Posible doble empleo · señal por revisar', detail: 'Coincidencia con un patrón del catálogo a las 10:20 de hoy. Ejemplo ficticio: título de ventana asociado a otra organización. No confirma otro empleo.', type: 'dual', severity: 'media', target: 'alert-dual' });
      if (person.index % 47 === 0) alerts.push({ label: 'App sospechosa detectada', detail: 'Coincidencia de proceso con el catálogo a las 11:10 de hoy: RemoteLink (ejemplo ficticio). El catálogo requiere revisión humana; no prueba uso indebido.', type: 'suspicious', severity: 'alta', target: 'alert-suspicious' });
    }
    return alerts.sort((a, b) => (a.severity === 'alta' ? 0 : 1) - (b.severity === 'alta' ? 0 : 1) || a.type.localeCompare(b.type));
  }
  function inScope(person, role) { return role === 'superadmin' || (person.company === companies[0] && person.area === areas[0]); }
  function devicesFor(person) {
    if (!person.hasDevice) return [];
    const make = (secondary = false) => ({
      hostname: `DESKTOP-${String(person.index + 1 + (secondary ? 500 : 0)).padStart(4, '0')}`,
      version: secondary ? '4.0.18' : person.index === 0 ? '4.0.30' : '4.0.20', state: secondary ? 'offline' : person.state,
      seen: secondary ? '27 oct, 08:00' : person.seen === null ? 'Sin registro' : `Hoy, ${clock(person.seen)}`,
      observed: secondary ? '27 oct, 07:55' : person.state === 'offline' ? 'Hoy, 13:55' : 'Hoy, 15:20',
      stale: secondary || person.state === 'offline', partial: person.partialProtection && !secondary,
    });
    return person.index === 0 ? [make(), make(true)] : [make()];
  }
  const nonproductiveMinutes = p => dayFor(p, today).apps.filter(a => a.kind === 'nonproductive').reduce((sum, a) => sum + a.minutes, 0);
  function sortPeople(list, order = 'attention') {
    const value = p => {
      const d = dayFor(p, today), alerts = alertsFor(p, today);
      if (order === 'active') return d.active === null ? Infinity : -d.active;
      if (order === 'productivity') return d.missing || !d.expected ? Infinity : -d.productive / d.expected;
      if (alerts.length) return alerts[0].severity === 'alta' ? 0 : 1;
      if (d.arrival > 495) return 2;
      return !d.absent && ['away', 'offline'].includes(p.state) ? 3 : 4;
    };
    return [...list].sort((a, b) => (order === 'name' ? 0 : value(a) - value(b)) || a.name.localeCompare(b.name, 'es') || a.id.localeCompare(b.id));
  }
  function weekFor(p) {
    // Tres jornadas cerradas comparables; no se compara el jueves parcial con días completos.
    if (p.missing || !p.hasDevice) return null;
    const previous = [19, 20, 21].map(n => ({ expected: 480, productive: 340 + (p.index * 13 + n) % 45 }));
    const current = [26, 27, 28].map((n, i) => n === 28 ? dayFor(p, yesterday) : ({ expected: 480, productive: previous[i].productive - 25 - p.index % 15 }));
    const sum = rows => rows.reduce((r, d) => ({ productive: r.productive + d.productive, expected: r.expected + d.expected }), { productive: 0, expected: 0 });
    return { current: sum(current), previous: sum(previous) };
  }
  function summary(list) {
    const days = list.map(p => dayFor(p, today));
    const measured = days.filter(d => !d.missing && d.expected > 0);
    const arrived = days.filter(d => d.arrival !== null && d.expected > 0);
    const productive = measured.reduce((n, d) => n + d.productive, 0), expected = measured.reduce((n, d) => n + d.expected, 0);
    const weeks = list.map(weekFor).filter(Boolean);
    const week = key => {
      const productive = weeks.reduce((n, w) => n + w[key].productive, 0), expected = weeks.reduce((n, w) => n + w[key].expected, 0);
      return { productive, expected, percent: expected ? productive / expected * 100 : null };
    };
    const current = week('current'), previous = week('previous');
    const appTotals = new Map();
    days.forEach(d => d.apps.forEach(a => appTotals.set(a.name, (appTotals.get(a.name) || 0) + a.minutes)));
    const topApp = [...appTotals].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0], 'es'))[0];
    return { total: list.length, measured: measured.length, productive, expected, percent: expected ? productive / expected * 100 : null,
      late: arrived.filter(d => d.arrival > 495).length, arrived: arrived.length,
      alerts: list.filter(p => alertsFor(p, today).length).length, high: list.filter(p => alertsFor(p, today).some(a => a.severity === 'alta')).length,
      current, previous, weekCount: weeks.length, delta: current.percent === null ? null : current.percent - previous.percent,
      topApp, nonproductive: list.filter(p => nonproductiveMinutes(p) > 60) };
  }
  const aiPolicy = 'Redacta en español de Colombia solo hechos de los agregados entregados. No calcules cifras, no identifiques ni califiques personas, no infieras causas. Cita la evidencia de cada observación. Las acciones y los informes los controla la plataforma.';
  const aiContext = 'Equipo de servicios jurídicos. La actividad medida no equivale a resultados laborales. Considera horarios, permisos y cobertura. No incluyas datos de personas ni asuntos de clientes en estas instrucciones.';
  function aiPayload(list, config = {}) {
    const s = summary(list);
    const facts = { corte: '2026-10-29T15:30:00-05:00', zona: 'America/Bogota', alcance: 'grupo_a7f2', personas: s.total,
      productividad_hoy: { productivos_min: s.productive, esperados_min: s.expected, porcentaje: s.percent === null ? null : Number(s.percent.toFixed(1)), personas_medidas: s.measured },
      semana: { actual: '26–28 oct 2026', anterior: '19–21 oct 2026', actual_productivos_min: s.current.productive, actual_esperados_min: s.current.expected, anterior_productivos_min: s.previous.productive, anterior_esperados_min: s.previous.expected, diferencia_pp: s.delta === null ? null : Number(s.delta.toFixed(1)), personas_comparables: s.weekCount },
      uso: { aplicacion_mayor_uso: s.topApp?.[0] || null, minutos: s.topApp?.[1] || 0, personas_mas_60_min_no_productivos: s.nonproductive.length, grupo_resultado: 'grupo_c9e4' } };
    return { model: config.model || 'modelo-de-prueba', messages: [{ role: 'system', content: aiPolicy }, { role: 'system', content: config.context ?? aiContext }, { role: 'user', content: JSON.stringify(facts) }] };
  }
  return { today, yesterday, people, companies, areas, sites, clock, dayFor, alertsFor, devicesFor, inScope, nonproductiveMinutes, sortPeople, weekFor, summary, aiPolicy, aiContext, aiPayload };
})();

/* Motor local de órdenes. El reloj y los resultados avanzan solo con controles de demostración. */
window.ORDERS = (() => {
  const D = window.DEMO;
  const actions = {
    lock: { label: 'Bloquear equipo', effect: 'La persona deja de poder usar el equipo. Sus programas permanecen abiertos hasta que IT lo desbloquee.', feature: 'comandos' },
    unlock: { label: 'Desbloquear', effect: 'Se retira la pantalla de bloqueo de IT y la persona puede continuar trabajando.', feature: 'comandos' },
    restart: { label: 'Reiniciar', effect: 'Windows se reinicia. Se cierran las aplicaciones y se puede perder trabajo sin guardar.', feature: 'comandos', notice: true },
    shutdown: { label: 'Apagar', effect: 'Se cierran las aplicaciones y se puede perder trabajo sin guardar. Alguien deberá encender el equipo a mano.', feature: 'comandos', notice: true },
    refresh_policy: { label: 'Aplicar reglas ahora', effect: 'Se solicita revisar las reglas en la próxima sincronización. No adelanta la conexión ni garantiza corregir el fallo; las restricciones que cambien pueden interrumpir el trabajo.', proposed: true },
    wipe: { label: 'Borrado remoto', effect: 'Se eliminan archivos y perfiles de las personas y se sobrescribe el espacio libre. Se conservan Windows, Keeper y la cuenta de IT. Es irreversible y requiere conexión; no protege un disco retirado.', feature: 'borrado' },
  };
  const devices = D.people.flatMap(person => D.devicesFor(person).map(d => ({ ...d, person,
    features: person.index === 4 ? [] : person.index === 8 ? ['comandos'] : ['comandos', 'borrado'],
    working: !person.absent, lastSignal: d.hostname === 'DESKTOP-0501' ? '2026-10-27' : '2026-10-29', approvedAbsences: [],
  })));
  let supervisorPermission = true;
  const setPermission = value => { supervisorPermission = !!value; };
  const getDevice = id => devices.find(d => d.hostname === id);
  const versionAtLeast = (version, minimum) => {
    const a = version.split('.').map(Number), b = minimum.split('.').map(Number);
    for (let i = 0; i < 3; i++) if (a[i] !== b[i]) return a[i] > b[i];
    return true;
  };
  const allowed = (d, type, role) => {
    if (!d || !D.inScope(d.person, role)) return 'Este equipo está fuera de tu alcance.';
    if (!actions[type]) return 'Orden desconocida.';
    if (role !== 'superadmin' && !(role === 'supervisor-orders' && supervisorPermission && ['lock', 'unlock', 'restart', 'shutdown'].includes(type))) return 'Tu rol no permite mandar esta orden; el superadmin administra el permiso.';
    if (actions[type].feature && !d.features.includes(actions[type].feature)) return `El plan de esta persona no incluye ${actions[type].feature === 'borrado' ? 'borrado remoto' : 'órdenes remotas'}.`;
    return '';
  };
  const start = Date.parse('2026-10-29T15:30:00-05:00');
  let now = start, serial = 7;
  const stamp = offset => new Date(start + offset * 60000).toISOString();
  const orders = [
    { id: 'o1', device: 'DESKTOP-0001', type: 'restart', status: 'delivered', notice: 300, created: stamp(-3), changed: stamp(-1), expires: stamp(1377), actor: 'Laura Pardo · IT', reason: 'Mantenimiento autorizado', detail: 'Cuenta regresiva de 5 minutos iniciada al recibir la orden.' },
    { id: 'o2', device: 'DESKTOP-0501', type: 'lock', status: 'pending', notice: 0, created: stamp(-20), changed: stamp(-20), expires: stamp(1360), actor: 'Laura Pardo · IT', reason: 'Revisión de acceso', detail: '' },
    { id: 'o3', device: 'DESKTOP-0001', type: 'refresh_policy', status: 'failed', notice: 0, created: stamp(-90), changed: stamp(-85), expires: stamp(1290), actor: 'Iván Rojas · IT', reason: 'Revisar bloqueo web', detail: 'El equipo no pudo aplicar el bloqueo web. Revisa el reporte de protección.' },
    { id: 'o4', device: 'DESKTOP-0001', type: 'unlock', status: 'succeeded', notice: 0, created: stamp(-60), changed: stamp(-58), expires: stamp(1320), actor: 'Laura Pardo · IT', reason: 'Acceso restablecido', detail: 'El equipo confirmó el desbloqueo.' },
    { id: 'o5', device: 'DESKTOP-0001', type: 'shutdown', status: 'cancelled', notice: 300, created: stamp(-45), changed: stamp(-44), expires: stamp(1335), actor: 'Iván Rojas · IT', reason: 'Mantenimiento aplazado', detail: 'Cancelada antes de entregarse.' },
    { id: 'o6', device: 'DESKTOP-0501', type: 'restart', status: 'expired', notice: 300, created: stamp(-1500), changed: stamp(-120), expires: stamp(-120), actor: 'Laura Pardo · IT', reason: 'Mantenimiento', detail: 'Venció sin entregarse; el equipo seguía sin conexión.' },
  ];
  const stateText = o => ({ pending: getDevice(o.device).state === 'offline' ? 'En espera porque el equipo está sin conexión' : 'En espera de la próxima sincronización', delivered: 'Entregada', running: 'En curso', succeeded: 'Hecha', failed: 'Falló', cancelled: 'Cancelada', expired: 'Venció' }[o.status]);
  function cancelReason(o, role) {
    if (!D.inScope(getDevice(o.device).person, role)) return 'Este equipo está fuera de tu alcance.';
    if (role !== 'superadmin' && !(role === 'supervisor-orders' && supervisorPermission && ['lock', 'unlock', 'restart', 'shutdown'].includes(o.type))) return 'Tu rol no permite cancelar esta orden.';
    if (!['pending', 'delivered', 'running'].includes(o.status)) return 'La orden ya terminó.';
    if (o.cancelRequested) return 'Cancelación solicitada; falta la respuesta del equipo.';
    if (o.status === 'pending') return Date.parse(o.expires) <= now ? 'La orden venció.' : '';
    if (!['restart', 'shutdown'].includes(o.type) || !o.notice) return 'El equipo la ejecuta al recibirla; ya no se puede detener.';
    if (!versionAtLeast(getDevice(o.device).version, '4.0.27')) return 'Esta versión no permite cancelar una cuenta regresiva ya iniciada; requiere 4.0.27.';
    return '';
  }
  function create(ids, type, role, options = {}) {
    if (!ids.length || new Set(ids).size !== ids.length) return { error: 'Selecciona al menos un equipo, sin duplicados.' };
    if (type === 'wipe' && ids.length !== 1) return { error: 'El borrado remoto se confirma equipo por equipo.' };
    const targets = ids.map(getDevice);
    const denial = targets.map(d => allowed(d, type, role)).find(Boolean);
    if (denial) return { error: denial };
    const notice = Number(options.notice || 0);
    if (![0, 60, 300, 600].includes(notice) || (notice && (!actions[type].notice || targets.some(d => !versionAtLeast(d.version, '4.0.17'))))) return { error: 'El aviso elegido no es compatible con esta orden o versión.' };
    if (type === 'wipe' && (options.confirmation !== ids[0] || !options.reauth)) return { error: 'Escribe el nombre exacto y confirma la autenticación simulada.' };
    if (actions[type].notice && targets.some(d => d.working) && !options.workConfirmed) return { error: 'Confirma que revisaste el impacto durante la jornada laboral.' };
    const reason = (options.reason || '').trim() || `${actions[type].label} desde el panel`;
    if (reason.length > 500) return { error: 'El motivo admite hasta 500 caracteres.' };
    const created = targets.map(d => ({ id: `o${serial++}`, device: d.hostname, type, status: 'pending', notice, created: new Date(now).toISOString(), changed: new Date(now).toISOString(), expires: new Date(now + 23 * 3600000).toISOString(), actor: role === 'superadmin' ? 'Laura Pardo · Superadmin (demostración)' : 'Andrea Vélez · Supervisora (demostración)', reason, detail: '' }));
    orders.unshift(...created);
    return { orders: created };
  }
  function cancel(id, role, reason = '', reauth = false) {
    const o = orders.find(x => x.id === id);
    if (!o) return 'La orden no existe.';
    const denial = cancelReason(o, role);
    if (denial) return denial;
    if (o.type === 'wipe' && !reauth) return 'Confirma la autenticación simulada para cancelar el borrado.';
    o.cancelActor = role === 'superadmin' ? 'Laura Pardo · Superadmin (demostración)' : 'Andrea Vélez · Supervisora (demostración)';
    o.cancelReason = reason.trim(); o.changed = new Date(now).toISOString();
    if (o.status === 'pending') { o.status = 'cancelled'; o.detail = 'Cancelada antes de entregarse. No llegará al equipo.'; }
    else { o.cancelRequested = true; o.detail = 'Cancelación solicitada. Se detiene en la próxima sincronización, si la cuenta no termina antes.'; }
    return '';
  }
  function simulate(id, event) {
    const o = orders.find(x => x.id === id);
    if (!o || !['pending', 'delivered', 'running'].includes(o.status)) return false;
    if (event === 'expire' && o.status === 'pending') { now = Math.max(now, Date.parse(o.expires)); expirePending(); return true; }
    if (event === 'deliver' && o.status === 'pending') {
      if (Date.parse(o.expires) <= now) { expirePending(); return false; }
      o.status = 'delivered';
      o.detail = o.notice ? `El equipo recibió la orden; cuenta regresiva de ${o.notice / 60} minutos.` : 'Recibida por el equipo. Falta su resultado.';
    } else if (event === 'ackCancel' && o.cancelRequested) { o.status = 'cancelled'; o.detail = 'El equipo confirmó que detuvo la cuenta regresiva.'; }
    else if (['success', 'fail'].includes(event) && o.status !== 'pending') {
      o.status = event === 'success' ? 'succeeded' : 'failed';
      o.detail = event === 'success' ? (o.cancelRequested ? 'No se pudo cancelar: la orden ya se ejecutó.' : 'El equipo confirmó la ejecución.') : 'El equipo informó un error de permisos de Windows. Revisa la cuenta de IT.';
    } else return false;
    o.changed = new Date(now).toISOString();
    return true;
  }
  function expirePending() {
    for (const o of orders) if (o.status === 'pending' && Date.parse(o.expires) <= now) { o.status = 'expired'; o.changed = o.expires; o.detail = 'Venció sin entregarse. No se enviará al equipo.'; }
  }
  // Se cuentan fechas laborales terminadas, desde el día de la última señal hasta ayer.
  function businessDaysWithoutSignal(lastSignal, until = D.today, holidays = [], absences = []) {
    let days = 0;
    for (let date = new Date(`${lastSignal}T12:00:00Z`); date.toISOString().slice(0, 10) < until; date.setUTCDate(date.getUTCDate() + 1)) {
      const iso = date.toISOString().slice(0, 10), week = date.getUTCDay();
      if (week !== 0 && week !== 6 && !holidays.includes(iso) && !absences.includes(iso)) days++;
    }
    return days;
  }
  function groupedProblems(list = devices, kind = '') {
    const groups = new Map();
    for (const d of list) for (const p of problems(d).filter(p => !kind || p.kind === kind)) {
      const timestamp = Date.parse(p.since);
      const bucket = Number.isFinite(timestamp) ? Math.floor(timestamp / 900000) : p.since;
      const key = `${p.kind}|${p.label}|${bucket}`;
      if (!groups.has(key)) groups.set(key, { ...p, key, devices: [] });
      groups.get(key).devices.push(d);
    }
    return [...groups.values()];
  }
  function problems(d) {
    const result = [];
    if (d.partial) result.push({ kind: 'protection', label: 'Protección fallida: bloqueo web', since: '2026-10-29T15:20:00-05:00', action: 'refresh_policy' });
    if (d.state === 'offline' && businessDaysWithoutSignal(d.lastSignal, D.today, ['2026-10-12'], d.approvedAbsences) >= 2) result.push({ kind: 'offline', label: 'Sin conexión prolongada', since: '2026-10-27T08:00:00-05:00', detail: 'No reporta desde el martes; su persona trabajó esos días. No sabemos si está apagado, sin internet o con el agente detenido.', action: '' });
    if (!versionAtLeast(d.version, '4.0.20')) result.push({ kind: 'version', label: `Versión atrasada: ${d.version} · referencia 4.0.20`, since: d.observed + ' (último reporte)', action: '' });
    for (const o of orders.filter(o => o.device === d.hostname && ['failed', 'expired'].includes(o.status))) result.push({ kind: 'order', label: o.status === 'failed' && o.type === 'refresh_policy' ? 'No pudo aplicar las reglas' : `${o.status === 'failed' ? 'No se completó' : 'Venció sin ejecutarse'}: ${actions[o.type].label}`, detail: o.detail, since: o.changed, action: o.type === 'restart' ? 'restart' : '' });
    for (const r of (window.RULES?.rules || []).filter(r => r.enabled && r.expires === D.today && window.RULES.peopleFor(r).some(p => p.id === d.person.id))) {
      if (d.state === 'offline') result.push({ kind: 'exception', label: 'Excepción que vence con el equipo sin conexión', since: D.today + 'T23:59:59-05:00', detail: 'Vence hoy; se aplicará cuando el equipo vuelva a conectarse', action: '' });
    }
    return result;
  }
  return { setPermission, businessDaysWithoutSignal, groupedProblems, actions, devices, getDevice, allowed, versionAtLeast, orders, stateText, cancelReason, create, cancel, simulate, problems, now: () => now };
})();

/* Reglas sintéticas: resolución por destino exacto; extensiones propuestas se identifican en la interfaz. */
window.RULES = (() => {
  const D = window.DEMO, O = window.ORDERS;
  const types = { web: 'Sitios web', apps: 'Aplicaciones concretas', usb: 'Memorias USB', download: 'Descargas', installation: 'Instalación de programas', schedule: 'Horario de uso', time: 'Cambio manual de hora', weekly: 'Apagado semanal', location: 'Ubicación' };
  const scopes = { client: 'Cliente', company: 'Empresa', area: 'Área', site: 'Sede', job: 'Cargo', user: 'Personas', device: 'Equipo' };
  const rank = { client: 1, site: 2, area: 3, user: 4, company: 4, job: 4, device: 5 };
  const contracted = { web: true, apps: false, usb: true, download: false, installation: true, schedule: false, time: true, weekly: true, location: true };
  let serial = 20, revision = 12;
  const pending = new Set();
  const rules = [
    { id: 'r1', name: 'Redes y video', kind: 'web', target: 'youtube.com', effect: 'deny', scope: 'area', value: 'Litigios' },
    { id: 'r2', name: 'Protección de archivos', kind: 'usb', target: '*', effect: 'deny', scope: 'client', value: 'Grupo AZC' },
    { id: 'r3', name: 'Presentación de Valeria', kind: 'usb', target: '*', effect: 'allow', scope: 'user', value: 'p001', expires: '2026-11-15' },
    { id: 'r4', name: 'Transferencia autorizada', kind: 'usb', target: '*', effect: 'allow', scope: 'user', value: 'p009', expires: '2026-11-01' },
    { id: 'r5', name: 'Archivo de audiencia', kind: 'usb', target: '*', effect: 'allow', scope: 'user', value: 'p013' },
    { id: 'r6', name: 'Programas autorizados', kind: 'installation', target: '*', effect: 'deny', scope: 'client', value: 'Grupo AZC' },
    { id: 'r7', name: 'Hora confiable', kind: 'time', target: '*', effect: 'deny', scope: 'client', value: 'Grupo AZC' },
    { id: 'r8', name: 'Ubicación laboral', kind: 'location', target: '*', effect: 'allow', scope: 'client', value: 'Grupo AZC' },
    { id: 'r9', name: 'Cierre de oficina', kind: 'weekly', target: '*', effect: 'deny', scope: 'area', value: 'Litigios', days: ['5'], at: '19:00', exclusions: ['p001'], notice: 5, idle: 15, postpone: 60, window: 180 },
    { id: 'r10', name: 'Copia temporal de documentos', kind: 'usb', target: '*', effect: 'allow', scope: 'user', value: 'p003', expires: '2026-10-29' },
  ].map((r, i) => ({ priority: 0, assignmentPriority: i, when: 'always', enabled: true, ...r }));
  function peopleFor(r) {
    if (r.members) return D.people.filter(p => r.members.includes(p.id));
    return D.people.filter(p => r.scope === 'client' || r.scope === 'user' && r.value.split(',').includes(p.id) || r.scope === 'device' && O.getDevice(r.value)?.person.id === p.id || p[r.scope] === r.value);
  }
  function applies(r, p, device, date = D.today) { return r.enabled && (!r.expires || date <= r.expires) && peopleFor(r).some(x => x.id === p.id) && (r.scope !== 'device' || device?.hostname === r.value) && !(r.exclusions || []).includes(p.id); }
  function included(p, kind) { return contracted[kind] && !(p.index === 4 && ['web', 'usb', 'installation', 'location'].includes(kind)); }
  function resolve(p, device = null, list = rules, date = D.today) {
    const entries = [], excluded = [], conflicts = [];
    const candidates = list.filter(r => applies(r, p, device, date));
    const groups = new Map();
    for (const r of candidates) {
      if (!included(p, r.kind)) { excluded.push(r); continue; }
      const key = `${r.kind}|${r.target}`;
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(r);
    }
    for (const values of groups.values()) {
      if (values[0].kind === 'weekly') { for (const r of values) entries.push({ winner: r, others: [] }); continue; }
      const flags = ['time', 'location'].includes(values[0].kind);
      const buckets = new Map();
      for (const r of values) {
        const key = `${rank[r.scope]}|${r.priority}`;
        if (!buckets.has(key)) buckets.set(key, []);
        buckets.get(key).push(r);
      }
      const clashes = [...buckets.values()].filter(v => new Set(v.map(r => r.effect)).size > 1);
      if (!flags && clashes.length) { conflicts.push(...clashes); continue; }
      values.sort((a,b) => rank[b.scope] - rank[a.scope] || (flags ? (b.assignmentPriority || 0) - (a.assignmentPriority || 0) : b.priority - a.priority) || (flags ? Number(b.effect === (b.kind === 'time' ? 'deny' : 'allow')) - Number(a.effect === (a.kind === 'time' ? 'deny' : 'allow')) : 0));
      entries.push({ winner: values[0], others: values.slice(1) });
    }
    return { entries, excluded, conflicts };
  }
  function validate(r) {
    if (['company', 'job'].includes(r.scope)) return 'Empresa y cargo: Planeado (2). El servidor aún no admite estos destinos.';
    if (!r.name.trim() || !r.value) return 'Escribe un nombre y elige a quién aplica.';
    if (!contracted[r.kind]) return r.kind === 'download' ? 'El cliente no contrató Descargas.' : 'Este control todavía no se puede configurar desde las reglas disponibles.';
    if (!peopleFor(r).length) return 'No hay personas en ese alcance.';
    if (r.when !== 'always' && r.kind !== 'weekly' && r.kind !== 'location') return 'El agente actual no admite este control por horario o franja.';
    if (r.kind === 'web' && !/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/.test(r.target)) return 'Escribe un dominio, por ejemplo youtube.com, sin rutas.';
    if (r.kind === 'web' && r.effect === 'deny' && ['keep.azclegal.com', 'azclegal.com', '*.azclegal.com'].includes(r.target)) return 'No puedes bloquear el acceso al panel.';
    if (r.expires && (r.scope !== 'user' || r.effect !== 'allow' || !/^\d{4}-\d{2}-\d{2}$/.test(r.expires) || !Number.isFinite(Date.parse(r.expires)) || new Date(r.expires).toISOString().slice(0,10) !== r.expires || r.expires < D.today)) return 'El vencimiento debe ser futuro y de una excepción por persona.';
    if (!Number.isInteger(r.priority) || r.priority < 0 || r.priority > 10000) return 'Prioridad inválida.';
    if (r.kind === 'weekly' && (!r.days?.length || !r.at || r.until && r.until < D.today)) return 'Elige días, hora y una vigencia válida para el apagado.';
    return '';
  }
  function preserveMembers(r) {
    const existing = rules.find(x => x.id === r.id);
    return existing?.members && existing.scope === r.scope && existing.value === r.value ? { ...r, members: existing.members } : r;
  }
  function preview(r) {
    r = preserveMembers(r);
    const people = peopleFor(r), list = rules.filter(x => x.id !== r.id).concat(r);
    const devices = O.devices.filter(d => people.some(p => p.id === d.person.id) && (r.scope !== 'device' || r.value === d.hostname));
    const details = devices.map(d => ({ device: d, result: resolve(d.person, d, list) }));
    const overridden = people.filter(p => resolve(p, null, list).entries.some(e => e.winner.id !== r.id && e.others.some(x => x.id === r.id)));
    const unavailable = people.filter(p => !included(p, r.kind));
    const conflicts = details.filter(x => x.result.conflicts.length);
    const unsupported = devices.filter(d => r.kind === 'time' && !O.versionAtLeast(d.version, '4.0.28') || ['weekly','location'].includes(r.kind) && !O.versionAtLeast(d.version, '4.0.30'));
    return { people, devices, details, overridden, unavailable, conflicts, unsupported, error: validate(r) };
  }
  function save(r) {
    r = preserveMembers(r);
    const impact = preview(r);
    if (impact.error || impact.conflicts.length) return { error: impact.error || 'Hay reglas opuestas al mismo nivel y prioridad. Edita la regla existente o cambia su prioridad.' };
    const saved = { ...r, assignmentPriority: r.assignmentPriority ?? serial, members: ['company', 'job'].includes(r.scope) ? peopleFor(r).map(p => p.id) : r.members, id: r.id || `r${serial++}` };
    const at = rules.findIndex(x => x.id === saved.id);
    if (at < 0) rules.push(saved); else rules[at] = saved;
    revision++;
    impact.devices.filter(d => included(d.person, r.kind) && !(r.exclusions || []).includes(d.person.id)).forEach(d => pending.add(d.hostname));
    return { rule: saved, impact };
  }
  function expiryText(r, date = D.today) {
    if (!r.expires) return 'Sin vencimiento';
    const days = Math.round((Date.parse(r.expires) - Date.parse(date)) / 86400000);
    return days < 0 ? 'Vencida' : days === 0 ? 'Vence hoy' : `Vence en ${days} día${days === 1 ? '' : 's'}`;
  }
  return { expiryText, pending, types, scopes, rank, contracted, rules, peopleFor, applies, included, resolve, validate, preview, save, revision: () => revision };
})();

(() => {
  if (typeof location === 'undefined') return;
  const text = new URLSearchParams(location.search).get('context'); if (!text) return;
  try {
    const c = JSON.parse(text); if (!c.name || !Array.isArray(c.structure) || !c.structure.length) return;
    const D = window.DEMO; D.client = String(c.name); D.clientPlan = String(c.plan); D.clientControls = Array.isArray(c.controls) ? c.controls : [];
    D.people.length = 0; window.ORDERS.devices.length = 0;
    D.companies.splice(0,D.companies.length,...new Set(c.structure.map(r=>String(r.company))));
    D.sites.splice(0,D.sites.length,...new Set(c.structure.map(r=>String(r.site))));
    D.areas.splice(0,D.areas.length,...new Set(c.structure.map(r=>String(r.area))));
  } catch { /* Un contexto inválido conserva la demostración inicial. */ }
})();
