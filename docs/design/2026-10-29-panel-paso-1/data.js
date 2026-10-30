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
  function inScope(person, role) { return role !== 'supervisor' || (person.company === companies[0] && person.area === areas[0]); }
  function devicesFor(person) {
    if (!person.hasDevice) return [];
    const make = (secondary = false) => ({
      hostname: `DESKTOP-${String(person.index + 1 + (secondary ? 500 : 0)).padStart(4, '0')}`,
      version: secondary ? '4.0.18' : '4.0.20', state: secondary ? 'offline' : person.state,
      seen: secondary ? '28 oct, 17:05' : person.seen === null ? 'Sin registro' : `Hoy, ${clock(person.seen)}`,
      observed: secondary ? '28 oct, 17:00' : person.state === 'offline' ? 'Hoy, 13:55' : 'Hoy, 15:20',
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
