(() => {
  'use strict';
  const D = window.DEMO;
  const params = new URLSearchParams(location.search);
  const page = document.body.dataset.page;
  const role = ['supervisor', 'supervisor-orders'].includes(params.get('role')) ? params.get('role') : 'superadmin';
  const scenario = ['empty', 'error'].includes(params.get('scenario')) ? params.get('scenario') : 'normal';
  window.ORDERS.setPermission(params.get('ordersPermission') !== 'off');
  const $ = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const duration = value => value === null || value === undefined ? 'Sin datos' : `${Math.floor(value / 60)} h ${String(value % 60).padStart(2, '0')} min`;
  const percent = day => !day || day.missing ? 'Sin datos' : day.expected === 0 ? 'Sin jornada' : `${Math.min(100, Math.round(day.productive / day.expected * 100))} %`;
  const dateLabel = date => new Intl.DateTimeFormat('es-CO', { timeZone: 'America/Bogota', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${date}T12:00:00-05:00`));
  const url = (file, changes = {}) => {
    const query = new URLSearchParams(params);
    query.set('role', role);
    for (const [key, value] of Object.entries(changes)) {
      if (value === null || value === '') query.delete(key); else query.set(key, value);
    }
    return `${file}?${query.toString()}`;
  };
  const badge = (text, tone = '') => `<span class="badge ${tone}">${escape(text)}</span>`;
  const stateBadge = state => badge({ connected: 'Conectada', away: 'Sin actividad reciente', offline: 'Sin conexión' }[state], { connected: 'good', away: 'warn' }[state]);
  const arrivalText = day => !day || day.missing ? 'Sin registro' : day.absent ? 'Ausencia registrada' : `${D.clock(day.arrival)} · ${day.arrival > 495 ? 'Después del margen' : day.arrival < 480 ? 'Antes del horario' : 'A tiempo'}`;
  const empty = (title, detail, action = '', isError = false) => `<div class="empty-state ${isError ? 'error-state' : ''}" ${isError ? 'role="alert"' : 'role="status"'}><h2>${escape(title)}</h2><p>${escape(detail)}</p>${action}</div>`;

  function header() {
    $('header').innerHTML = `<div class="topbar"><div class="topbar-left"><a class="brand" href="${escape(url('index.html'))}" aria-label="AZC Keeper · Inicio supervisor"><img src="brand/logo-mark.png" alt="" width="32" height="40"><span><strong>AZC Keeper</strong><small>Personas y su día</small></span></a><span class="mode-label">Modo ${['administrar', 'asistente-ia', 'problemas', 'programacion'].includes(page) && role === 'superadmin' ? 'Administrar' : 'Supervisor'}</span></div>${role === 'superadmin' ? `<a class="button" href="${escape(url(['administrar', 'asistente-ia', 'problemas', 'programacion'].includes(page) ? 'index.html' : 'administrar.html'))}">${['administrar', 'asistente-ia', 'problemas', 'programacion'].includes(page) ? 'Volver a supervisar' : 'Administrar'}</a>` : '<span class="badge">Supervisor de área</span>'}</div>
      <div class="demo-bar"><strong class="demo-tag">Solo maqueta</strong><label for="demo-role">Ver como:<select id="demo-role"><option value="superadmin">Superadmin</option><option value="supervisor">Supervisor de área</option><option value="supervisor-orders">Supervisor con órdenes</option></select></label>${!['administrar', 'asistente-ia', 'programacion'].includes(page) ? '<label for="demo-state">Escenario:<select id="demo-state"><option value="normal">Con datos</option><option value="empty">Vacío</option><option value="error">Error de carga</option></select></label>' : ''}<span class="demo-note">Datos ficticios · Sin conexión a producción</span></div>`;
    $('demo-role').value = role;
    $('demo-role').addEventListener('change', e => {
      location.href = url(page === 'persona' ? 'persona.html' : 'index.html', { role: e.target.value, client: null, company: null, area: null, site: null, state: null, q: null, page: null, cohort: null });
    });
    if ($('demo-state')) {
      $('demo-state').value = scenario;
      $('demo-state').addEventListener('change', e => { location.href = url(page === 'supervisor' ? 'index.html' : `${page}.html`, { scenario: e.target.value }); });
    }
  }

  function supervisor() {
    const scoped = D.people.filter(p => D.inScope(p, role));
    $('scope').textContent = role === 'superadmin' ? 'Todos los clientes · 1 cliente de demostración · 300 personas' : `Grupo AZC → AZC Jurídica → Litigios · ${scoped.length} personas a tu cargo`;
    const keys = ['client', 'company', 'area', 'site', 'state'];
    const selectors = Object.fromEntries(keys.map(key => [key, $(key)]));
    for (const key of ['company', 'area', 'site']) {
      [...new Set(scoped.map(p => p[key]))].forEach(value => selectors[key].add(new Option(value, value)));
    }
    for (const key of keys) {
      const candidate = params.get(key) || '';
      if ([...selectors[key].options].some(o => o.value === candidate)) selectors[key].value = candidate;
    }
    if (role !== 'superadmin') {
      for (const [key, value] of [['client', 'Grupo AZC'], ['company', D.companies[0]], ['area', D.areas[0]]]) {
        selectors[key].value = value;
        selectors[key].disabled = true;
      }
    }
    $('search').value = params.get('q') || '';
    $('order').value = ['attention', 'name', 'productivity', 'active'].includes(params.get('order')) ? params.get('order') : 'attention';
    let cohort = ['nonproductive', 'weekly', 'topapp'].includes(params.get('cohort')) ? params.get('cohort') : '';
    const dismissed = new Set();
    let assistantScope = '';
    let currentPage = Math.max(1, Number.parseInt(params.get('page'), 10) || 1);
    const pageSize = 10;
    const fold = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    function sync() {
      keys.forEach(key => selectors[key].value ? params.set(key, selectors[key].value) : params.delete(key));
      $('search').value ? params.set('q', $('search').value) : params.delete('q');
      params.set('page', currentPage);
      params.set('order', $('order').value);
      cohort ? params.set('cohort', cohort) : params.delete('cohort');
      // Los enlaces llevan el contexto: file:// no requiere almacenamiento ni History API.
      $('header').querySelector('.brand').href = url('index.html');
      const modeLink = $('header').querySelector('.topbar > .button');
      if (modeLink) modeLink.href = url('administrar.html');
    }
    function render() {
      const search = fold($('search').value);
      const base = scenario !== 'normal' ? [] : scoped.filter(p => fold(p.name).includes(search) && ['client', 'company', 'area', 'site'].every(key => !selectors[key].value || selectors[key].value === p[key]));
      const counts = [
        ['connected', 'Conectadas', base.filter(p => p.state === 'connected').length, 'Señal hace hasta 5 min'],
        ['offline', 'Sin conexión', base.filter(p => p.state === 'offline').length, 'Más de 15 min o sin equipo'],
        ['away', 'Sin actividad reciente', base.filter(p => p.state === 'away').length, 'Señal hace 5–15 min'],
      ];
      $('counters').innerHTML = counts.map(([key, label, count, note]) => `<button class="counter" type="button" data-state="${key}" aria-pressed="${selectors.state.value === key}" ${scenario === 'error' ? 'disabled' : ''}><span class="counter-label"><span class="dot ${key}" aria-hidden="true"></span>${label}</span><strong>${scenario === 'error' ? '—' : count}</strong><small>${note}</small></button>`).join('');
      $('counters').querySelectorAll('button').forEach(button => button.addEventListener('click', () => {
        selectors.state.value = selectors.state.value === button.dataset.state ? '' : button.dataset.state;
        currentPage = 1; render();
      }));
      const scopeKey = base.map(p => p.id).join(',');
      if (scopeKey !== assistantScope) { dismissed.clear(); assistantScope = scopeKey; }
      const s = D.summary(base);
      renderOverview(base, s);
      const cohortPeople = cohort === 'nonproductive' ? s.nonproductive : cohort === 'weekly' ? base.filter(p => D.weekFor(p)) : cohort === 'topapp' ? base.filter(p => D.dayFor(p, D.today).apps.some(a => a.name === s.topApp?.[0] && a.minutes > 0)) : base;
      const filtered = D.sortPeople(cohortPeople.filter(p => !selectors.state.value || (selectors.state.value === 'alerts' ? D.alertsFor(p, D.today).length : p.state === selectors.state.value)), $('order').value);
      $('cohort-note').hidden = !cohort;
      $('cohort-note').textContent = cohort ? `Selección del asistente: ${cohort === 'nonproductive' ? 'más de 1 h en sitios no productivos hoy' : cohort === 'weekly' ? 'personas con datos en ambas semanas' : 'personas con uso de la aplicación destacada'}. ${cohortPeople.length} personas antes del filtro de estado. Limpiar filtros quita esta selección.` : '';
      const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
      currentPage = Math.min(currentPage, totalPages);
      sync();
      $('result-count').textContent = scenario === 'error' ? 'Datos no disponibles · Última consulta fallida' : `${filtered.length} de ${scenario === 'empty' ? 0 : scoped.length} personas · Hoy hasta las 15:30`;
      $('pagination').hidden = !filtered.length;
      if (scenario === 'error') {
        $('roster-content').innerHTML = empty('No pudimos cargar las personas', 'No se pudo consultar la información. No sabemos cuántas personas están conectadas; los guiones no significan cero.', '<button id="retry" class="primary" type="button">Reintentar</button>', true);
        $('retry').onclick = () => { location.href = url('index.html', { scenario: 'normal' }); };
        return;
      }
      if (!filtered.length) {
        $('roster-content').innerHTML = empty(scenario === 'empty' ? 'Aún no hay personas en este alcance' : 'No encontramos personas con estos filtros', scenario === 'empty' ? 'Cuando haya personas asignadas, aparecerán aquí. Consulta con quien administra el cliente si esperabas ver tu equipo.' : 'Prueba con otro nombre o limpia los filtros para volver a ver tu alcance.', scenario === 'empty' ? '<button id="restore" type="button">Volver a los datos de demostración</button>' : '<button id="clear-empty" type="button">Limpiar búsqueda y filtros</button>');
        if ($('clear-empty')) $('clear-empty').onclick = clear;
        if ($('restore')) $('restore').onclick = () => { location.href = url('index.html', { scenario: 'normal' }); };
        return;
      }
      const items = filtered.slice((currentPage - 1) * pageSize, currentPage * pageSize);
      $('roster-content').innerHTML = `<table class="people-table"><thead><tr>${['Persona', 'Estado ahora', 'Llegada / horario', 'Activo hoy', 'Productividad', 'Alerta'].map(t => `<th scope="col">${t}</th>`).join('')}</tr></thead><tbody>${items.map(p => {
        const day = D.dayFor(p, D.today), alerts = D.alertsFor(p, D.today);
        return `<tr><td data-label="Persona"><a href="${escape(url('persona.html', { id: p.id, day: D.today }))}">${escape(p.name)}</a><small>${escape(p.company)} · ${escape(p.area)}</small><small>${escape(p.client)} · ${escape(p.site)}</small></td><td data-label="Estado ahora"><div>${stateBadge(p.state)}<small>${p.hasDevice ? `Última señal ${D.clock(p.seen)}` : 'Sin equipo asignado'}</small></div></td><td data-label="Llegada"><div>${escape(arrivalText(day))}<small>${day.absent ? 'Permiso de día completo' : 'Entrada 08:00 · margen hasta 08:15'}</small></div></td><td data-label="Activo hoy"><div>${duration(day.active)}</div></td><td data-label="Productividad"><div><strong>${percent(day)}</strong>${!day.missing && day.expected ? `<div class="meter" aria-hidden="true"><span style="width:${Math.min(100, day.productive / day.expected * 100)}%"></span></div>` : ''}</div></td><td data-label="Alerta"><div>${alerts.length ? `${badge(alerts[0].severity === 'alta' ? 'Alta' : 'Media', alerts[0].severity === 'alta' ? 'bad' : 'warn')} <a class="alert-text" href="${escape(url('persona.html', { id: p.id, day: D.today }))}#${alerts[0].target}">${escape(alerts[0].label)}</a>${alerts.length > 1 ? `<small>y ${alerts.length - 1} más</small>` : ''}` : '<span>Sin alertas</span>'}</div></td></tr>`;
      }).join('')}</tbody></table>`;
      $('pagination').innerHTML = `<span>${(currentPage - 1) * pageSize + 1}–${Math.min(currentPage * pageSize, filtered.length)} de ${filtered.length} personas · Página ${currentPage} de ${totalPages}</span><button id="previous" type="button" ${currentPage === 1 ? 'disabled' : ''}>Anterior</button><button id="next" type="button" ${currentPage === totalPages ? 'disabled' : ''}>Siguiente</button>`;
      $('previous').onclick = () => { currentPage--; render(); $('roster-title').scrollIntoView({ block: 'start' }); };
      $('next').onclick = () => { currentPage++; render(); $('roster-title').scrollIntoView({ block: 'start' }); };
    }
    function clear() {
      $('search').value = '';
      cohort = '';
      keys.forEach(key => { if (!selectors[key].disabled) selectors[key].value = ''; });
      currentPage = 1; render(); $('search').focus();
    }
    $('clear').onclick = clear;
    $('search-form').onsubmit = e => { e.preventDefault(); currentPage = 1; render(); };
    $('search').addEventListener('input', () => { currentPage = 1; render(); });
    keys.forEach(key => selectors[key].addEventListener('change', () => { currentPage = 1; render(); }));
    $('order').addEventListener('change', () => { currentPage = 1; render(); });
    function renderOverview(base, s) {
      const fmt = n => n === null ? 'Sin datos' : `${n.toFixed(1).replace('.', ',')} %`;
      const delta = s.delta === null ? 'Sin comparación' : `${s.delta > 0 ? '+' : ''}${s.delta.toFixed(1).replace('.', ',')} pp`;
      const unavailable = scenario === 'error';
      $('dashboard-kpis').innerHTML = `<article class="surface"><h2>Productividad hoy</h2><strong>${unavailable ? '—' : fmt(s.percent)}</strong><p>${unavailable ? 'Consulta fallida' : `${s.measured} de ${s.total} personas con medición y jornada`}</p><small>Semana: ${unavailable ? '—' : delta} · 26–28 vs. 19–21 oct</small></article><article class="surface"><h2>Llegadas fuera del margen</h2><strong>${unavailable ? '—' : s.late}</strong><p>${unavailable ? 'Consulta fallida' : `De ${s.arrived} primeras actividades registradas`}</p><small>Después de 08:15 · no es una marcación</small></article><article class="surface"><h2>Personas con alertas</h2><strong>${unavailable ? '—' : s.alerts}</strong><p>${unavailable ? 'Consulta fallida' : `${s.high} con al menos una alerta alta`}</p><small>Revisar evidencia y contexto en la ficha</small></article>`;
      if (!base.length || unavailable) {
        $('assistant-content').innerHTML = `<p>${unavailable ? 'Sugerencias no disponibles: no se pudo consultar la evidencia.' : 'Sin personas para construir sugerencias en este alcance.'}</p>`;
        return;
      }
      const weekly = base.filter(p => D.weekFor(p));
      const appPeople = base.filter(p => D.dayFor(p, D.today).apps.some(a => a.name === s.topApp?.[0] && a.minutes > 0));
      const suggestions = [
        { id: 'weekly', title: `La productividad de las jornadas comparables ${s.delta < 0 ? 'bajó' : 'subió'} ${Math.abs(s.delta || 0).toFixed(1).replace('.', ',')} pp.`, evidence: `${fmt(s.current.percent)} (${s.current.productive} / ${s.current.expected} min), 26–28 oct; ${fmt(s.previous.percent)} (${s.previous.productive} / ${s.previous.expected} min), 19–21 oct. ${s.weekCount} personas comparables.`, people: weekly, show: s.delta !== null },
        { id: 'topapp', title: `La aplicación con más tiempo activo hoy es ${s.topApp?.[0] || 'desconocida'}.`, evidence: `${s.topApp?.[1] || 0} min activos sumados de ${appPeople.length} personas. Corte: hoy 15:30.`, people: appPeople, show: !!s.topApp },
        { id: 'nonproductive', title: `${s.nonproductive.length} personas registran más de 1 h en sitios no productivos hoy.`, evidence: `Tiempo activo clasificado como no productivo > 60 min por persona; ${s.nonproductive.length} de ${s.total}. Clasificación de ejemplo, no valoración de desempeño.`, people: s.nonproductive, show: s.nonproductive.length > 0 },
      ].filter(item => item.show && !dismissed.has(item.id));
      $('assistant-content').innerHTML = `<p class="panel-note">Ejemplos de texto fijo; no hay IA conectada. El envío está apagado. Cifras y grupos calculados sobre tu alcance filtrado; en producción los calcula el servidor.</p>${suggestions.map(item => `<article class="suggestion"><h3>${escape(item.title)}</h3><p><strong>Evidencia:</strong> ${escape(item.evidence)}</p><div class="actions"><button type="button" data-view="${item.id}">Ver personas</button><button type="button" data-report="${item.id}">Generar informe</button><button type="button" data-dismiss="${item.id}">Descartar</button></div></article>`).join('') || '<p>No quedan sugerencias para revisar en esta vista.</p>'}`;
      $('assistant-content').querySelectorAll('[data-view]').forEach(button => { button.onclick = () => { cohort = button.dataset.view; selectors.state.value = ''; currentPage = 1; render(); $('roster-title').scrollIntoView({ block: 'start' }); $('order').focus({ preventScroll: true }); }; });
      $('assistant-content').querySelectorAll('[data-dismiss]').forEach(button => { button.onclick = () => { dismissed.add(button.dataset.dismiss); render(); $('assistant').querySelector('summary').focus(); }; });
      $('assistant-content').querySelectorAll('[data-report]').forEach(button => { button.onclick = () => {
        const item = suggestions.find(suggestion => suggestion.id === button.dataset.report);
        const scope = ['client', 'company', 'area', 'site'].map(key => selectors[key].value || `Todas: ${key === 'client' ? 'clientes' : key === 'company' ? 'empresas' : key === 'area' ? 'áreas' : 'sedes'}`).join(' · ');
        const body = `<p>Informe de demostración · Borrador para revisión humana · 29 oct 2026, 15:30 · America/Bogota</p><p>${escape(scope)} · Búsqueda: ${escape($('search').value || 'ninguna')} · ${base.length} personas en el alcance.</p><h3>${escape(item.title)}</h3><p>${escape(item.evidence)}</p><p>Los nombres se incorporan localmente después del cálculo. No se envían al proveedor. Estado y selección previa de la lista no restringen este informe.</p><ul>${item.people.map(p => `<li>${escape(p.name)} · ${escape(p.company)} · ${escape(p.area)}${item.id === 'nonproductive' ? ` · ${D.nonproductiveMinutes(p)} min no productivos` : ''}</li>`).join('')}</ul><p>Fuente: datos sintéticos de la maqueta. Sin conclusiones laborales ni envío externo.</p>`;
        $('report-content').innerHTML = body;
        $('report-dialog').showModal();
        $('download-report').onclick = () => {
          const content = `<!doctype html><html lang="es-CO"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Informe de revisión</title><style>body{font:16px/1.6 system-ui;max-width:900px;margin:30px auto;padding:20px;color:#1f2937;background:white;overflow-wrap:anywhere}h1{color:#3c4082}</style><h1>Informe de revisión</h1>${body}</html>`;
          const blob = new Blob([content], { type: 'text/html;charset=utf-8' });
          const objectURL = URL.createObjectURL(blob), link = document.createElement('a');
          link.href = objectURL; link.download = `informe-${item.id}-2026-10-29.html`; link.click();
          setTimeout(() => URL.revokeObjectURL(objectURL), 1000);
        };
      }; });
    }
    $('close-report').onclick = () => $('report-dialog').close();
    render();
  }

  function person() {
    $('back').href = url('index.html', { id: null, day: null });
    const p = D.people.find(item => item.id === (params.get('id') || 'p001'));
    if (!p || !D.inScope(p, role)) {
      $('person-content').innerHTML = empty('Persona no disponible en tu alcance', 'Vuelve a Personas para consultar a quienes tienes a cargo.');
      return;
    }
    if (scenario === 'error') {
      $('person-content').innerHTML = empty('No pudimos cargar la ficha', 'La consulta falló. No se puede concluir que la persona esté desconectada ni que no tenga actividad.', `<a class="button primary" href="${escape(url('persona.html', { scenario: 'normal' }))}">Reintentar</a>`, true);
      return;
    }
    let date = /^2026-10-(0[1-9]|[12][0-9])$/.test(params.get('day') || '') ? params.get('day') : D.today;
    document.title = `${p.name} · Su día · AZC Keeper`;
    const initials = p.name.split(' ').map(s => s[0]).join('');
    $('person-content').innerHTML = `<div class="page-heading person-heading"><div class="identity"><span class="avatar" aria-hidden="true">${escape(initials)}</span><div><p class="eyebrow">Modo Supervisor / Persona</p><h1>${escape(p.name)}</h1><p>${escape(p.client)} → ${escape(p.company)} → ${escape(p.area)}</p><p>${escape(p.job)} · ${escape(p.site)}</p></div></div><div class="connection-now">${stateBadge(p.state)}<span>${p.hasDevice ? `Última señal de equipo: hoy, ${D.clock(p.seen)}` : 'Sin equipo asignado'}</span><span>Estado actual · 29 oct, 15:30</span></div></div>
      <form class="day-bar" id="day-form"><div class="day-context"><h2 id="day-title" aria-live="polite"></h2><p>Horas de Bogotá · America/Bogota</p></div><div class="day-toggle"><button id="today" type="button">Hoy</button><button id="yesterday" type="button">Ayer</button></div><label for="day">Elegir fecha<input type="date" id="day" min="2026-10-01" max="2026-10-29" required value="${date}"></label><button class="primary" type="submit">Consultar día</button></form><div id="day-content"></div><section id="device-section" class="surface" aria-labelledby="devices-title"></section>`;
    function renderDay() {
      params.set('day', date);
      $('day').value = date;
      $('day-title').textContent = `${date === D.today ? 'Hoy' : date === D.yesterday ? 'Ayer' : 'Día consultado'} · ${dateLabel(date)}`;
      $('today').setAttribute('aria-pressed', String(date === D.today));
      $('yesterday').setAttribute('aria-pressed', String(date === D.yesterday));
      const day = scenario === 'empty' ? null : D.dayFor(p, date);
      if (!day) {
        $('day-content').innerHTML = `<div class="day-empty">${empty('No hay actividad disponible para este día', 'La demostración incluye el 28 y el 29 de octubre. Un día sin datos no debe mostrarse como 0 % de productividad. El estado de los equipos de abajo es el actual.')}</div>`;
        return;
      }
      const alerts = D.alertsFor(p, date);
      const arrivalTone = day.arrival > 495 ? 'warn' : 'good';
      $('day-content').innerHTML = `${date !== D.today ? '<p class="notice">Estás consultando un día cerrado. La conexión y la protección de los equipos se muestran con su último reporte disponible, no como un estado histórico.</p>' : ''}${day.absent ? '<p class="notice">Ausencia laboral registrada: permiso de día completo. Hoy no hay jornada esperada; la productividad no aplica.</p>' : ''}${day.missing ? '<p class="notice">La actividad de este día no está disponible. La conexión del equipo y la medición de actividad son datos diferentes.</p>' : ''}
        <section class="metric-grid" aria-label="Resumen del día"><article class="surface metric"><h2>Llegada / horario</h2><strong class="value">${day.arrival === null ? 'Sin registro' : D.clock(day.arrival)}</strong>${day.arrival === null ? badge(day.absent ? 'Ausencia registrada' : 'Sin datos') : badge(day.arrival > 495 ? 'Después del margen' : 'A tiempo', arrivalTone)}<p>Entrada 08:00 · margen 15 min<br>Límite 08:15 · salida 17:00</p><p>Primera actividad; no es una marcación.</p></article>
        <article class="surface metric"><h2>Tiempo activo</h2><strong class="value">${duration(day.active)}</strong><p>Uso medido en apps y sitios.</p><p>${date === D.today ? 'Acumulado de hoy' : 'Total del día'} · Uso observado; no son horas de asistencia.</p></article>
        <article class="surface metric"><h2>Tiempo inactivo</h2><strong class="value">${duration(day.idle)}</strong><p>Sin interacción medida.</p><p>Equipo bloqueado: <strong>${duration(day.locked)}</strong>, separado del tiempo inactivo.</p></article>
        <article class="surface metric"><h2>Productividad</h2><strong class="value">${percent(day)}</strong><p>${day.missing ? 'Medición no disponible' : day.expected ? `${duration(day.productive)} productivas / ${duration(day.expected)} de jornada esperada` : 'Sin jornada esperada'}</p><p>Descuenta almuerzo y ausencias. Hoy cuenta solo la jornada transcurrida.</p></article></section>
        <div class="person-grid"><div class="stack"><section class="surface"><h2>Apps y sitios más usados</h2><p class="panel-note">Ordenados por tiempo activo. Sitios y aplicaciones en una sola lista.</p>${day.apps.length ? `<ol class="usage-list">${day.apps.map(app => `<li><div><strong>${escape(app.name)}</strong><p>${escape(app.detail)}</p><div class="meter" aria-hidden="true"><span style="width:${app.minutes / day.active * 100}%"></span></div></div><span class="minutes">${duration(app.minutes)}</span></li>`).join('')}</ol><p class="panel-note">Productiva = clasificada como trabajo para esta persona. La clasificación no prueba resultados laborales.</p>` : '<p>No hay usos medidos para mostrar.</p>'}</section>
        <section class="surface"><h2>Línea de tiempo del día</h2><p class="panel-note">${dateLabel(date)} · Apps y sitios observados</p>${day.timeline.length ? `<ol class="timeline">${day.timeline.map(event => `<li class="${event.kind}"><time>${event.start}–${event.end}</time><div class="event"><strong>${escape(event.label)}</strong><small>${event.kind === 'gap' ? 'Sin detalle en esta franja; no permite inferir ausencia.' : event.kind === 'productive' ? 'Uso productivo' : event.kind === 'nonproductive' ? 'Uso no productivo' : 'Uso neutro'}</small></div></li>`).join('')}</ol>` : '<p>No hay eventos disponibles para reconstruir este día.</p>'}<p class="panel-note">Los huecos no equivalen a tiempo inactivo. La jornada futura no se dibuja como actividad.</p></section></div>
        <div class="stack"><section class="surface" id="alerts-section"><div class="section-heading"><h2>Alertas ${date === D.today ? 'de hoy' : 'del día'}</h2>${badge(`${alerts.length} por revisar`, alerts.length ? 'warn' : 'good')}</div>${alerts.length ? `<ul class="alert-list">${alerts.map(a => `<li id="alert-${a.type}">${badge(a.severity === 'alta' ? 'Alta' : 'Media', a.severity === 'alta' ? 'bad' : 'warn')} <strong>${escape(a.label)}</strong><p>${escape(a.detail)}</p>${['arrival', 'offline'].includes(a.type) ? `<a href="#${a.target}">Ver ${a.type === 'arrival' ? 'jornada' : 'equipos'}</a>` : '<small>Evidencia de catálogo simulada · Pendiente de revisión humana</small>'}</li>`).join('')}</ul>` : '<p>No hay alertas en los datos disponibles de este día.</p>'}<p class="panel-note">Una alerta pide revisar el contexto; no confirma una falta.</p></section><section class="surface"><h2>Su jornada</h2><dl class="controls"><div><dt>Horario</dt><dd>08:00–17:00</dd></div><div><dt>Almuerzo</dt><dd>12:00–13:00</dd></div><div><dt>Margen de llegada</dt><dd>15 minutos</dd></div><div><dt>Ausencia aprobada</dt><dd>${day.absent ? 'Todo el día' : 'Ninguna'}</dd></div></dl><p class="panel-note">La conexión de un equipo no confirma que la persona esté frente a él.</p></section></div></div>`;
    }
    $('day-form').onsubmit = e => { e.preventDefault(); if (!$('day-form').reportValidity()) return; date = $('day').value; renderDay(); };
    $('today').onclick = () => { date = D.today; renderDay(); };
    $('yesterday').onclick = () => { date = D.yesterday; renderDay(); };
    renderDay();
    const devices = D.devicesFor(p);
    $('device-section').innerHTML = `<h2 id="devices-title">Sus equipos · estado actual</h2><p class="panel-note">${devices.length} equipo${devices.length === 1 ? '' : 's'} asignado${devices.length === 1 ? '' : 's'} · Corte de demostración: 29 oct, 15:30. La protección es el estado reportado, no una garantía.</p>${devices.length ? devices.map(device => `<article class="device"><div class="device-title"><h3><a href="${escape(url('equipo.html', { device: device.hostname }))}">${device.hostname}</a></h3>${badge(device.state === 'connected' ? 'Conectado' : device.state === 'away' ? 'Señal hace 10 min' : 'Sin conexión', device.state === 'connected' ? 'good' : '')}</div><p>Versión del agente ${device.version} · Última señal: ${device.seen}</p><p><strong>${device.stale ? 'Protección sin confirmar ahora' : device.partial ? 'Protección parcial reportada' : 'Controles aplicados según el equipo'}</strong> · Reporte: ${device.observed}</p><dl class="controls"><div><dt>Bloqueo web</dt><dd class="${device.partial ? 'warn-text' : 'good-text'}">${device.partial ? 'Requiere revisión' : 'Aplicado en último reporte'}</dd></div><div><dt>Restricción de USB</dt><dd>Aplicada en último reporte</dd></div><div><dt>Instalación de programas</dt><dd>Restringida en último reporte</dd></div></dl><div data-order-device="${device.hostname}"></div></article>`).join('') : '<p>Sin equipos asignados. El alta de persona y equipo se diseña en el paso 4.</p>'}<p class="panel-note">Las órdenes de demostración solo cambian esta página; se restablecen al recargar o navegar. No se envían a ningún equipo.</p>`;
  }

  function administer() {
    if (role !== 'superadmin') {
      $('admin-content').innerHTML = empty('Esta vista no está disponible para tu rol', 'El supervisor de área consulta a las personas de su alcance.', `<a class="button primary" href="${escape(url('index.html'))}">Volver a supervisar</a>`);
      return;
    }
    const tasks = [
      [2, 'Mandar órdenes a equipos', 'Bloquear, reiniciar o actualizar reglas, con el resultado de cada orden.'],
      [3, 'Qué se bloquea', 'Definir las reglas de sitios, aplicaciones, USB e instalación de programas.'],
      [4, 'Alta de persona y equipo', 'Dar de alta a una persona, asignar su equipo y comprobar que quedó conectado.'],
      [5, 'Clientes, funciones y planes', 'Organizar clientes y empresas, revisar qué tiene contratado cada cliente y a quién aplica.'],
    ];
    $('admin-content').innerHTML = `<p class="eyebrow">Modo Administrar</p><h1>¿Qué necesitas configurar?</h1><p class="admin-intro">Aquí se prepara cómo funciona Keeper. Para revisar personas y su día, vuelve al modo Supervisor.</p><p class="notice">Alcance: todos los clientes. Cada tarea pedirá elegir el cliente sobre el que vas a trabajar. En esta demostración solo existe Grupo AZC.</p><div class="tools-grid">${tasks.map(([step, title, detail]) => `<article class="surface task-card"><span class="step" aria-hidden="true">${step}</span><div><h2>${title}</h2><p>${detail}</p>${step === 2 ? `<a class="button primary" href="${escape(url('problemas.html'))}">Equipos con problemas</a>` : `<span class="future-tag">Se diseña en el paso ${step}</span>`}</div></article>`).join('')}<article class="surface task-card"><div><h2>Asistente de IA</h2><p>Configurar proveedor, instrucciones y activación por cliente.</p><a class="button" href="${escape(url('asistente-ia.html'))}">Configurar asistente</a></div></article></div><section class="surface orders-surface" id="permisos"><h2>Órdenes de supervisores</h2><p>Esbozo del paso 5 · Andrea Vélez · AZC Jurídica → Litigios · 25 personas.</p><label class="switch-label"><input id="supervisor-permission" type="checkbox" ${params.get('ordersPermission') !== 'off' ? 'checked' : ''}>Permitir bloquear, desbloquear, reiniciar y apagar a su gente</label><p>Nunca permite borrado remoto ni administrar reglas. Se simula en esta navegación; al quitarlo se revoca también la cancelación de órdenes.</p><p id="permission-status" role="status"></p></section><details class="surface more-tools"><summary>Más herramientas</summary><p>Herramientas técnicas y de seguimiento. Su flujo no se diseña en este paso.</p><ul><li>Versiones del agente<small>Consultar versiones y organizar actualizaciones.</small></li><li>Salud del servidor<small>Revisar el funcionamiento del servicio.</small></li><li>Auditoría<small>Consultar quién cambió qué y cuándo.</small></li><li>Conexiones y registros técnicos<small>Revisar integraciones y diagnósticos.</small></li></ul></details>`;
    $('supervisor-permission').onchange = e => { location.href = url('administrar.html', { ordersPermission: e.target.checked ? 'on' : 'off' }) + '#permisos'; };
    $('permission-status').textContent = params.get('ordersPermission') === 'off' ? 'Permiso quitado. Supervisor con órdenes queda sin envío.' : 'Permiso activo. Prueba «Supervisor con órdenes» en el conmutador.';
  }
  function aiSettings() {
    $('admin-content').innerHTML = `<p class="eyebrow">Modo Administrar</p><h1>Asistente de IA</h1><p class="admin-intro"><a href="${escape(url('administrar.html'))}">Volver a las tareas de administración</a></p><section class="surface ai-settings" aria-labelledby="ai-title"><div class="section-heading"><div><h2 id="ai-title">Asistente de IA</h2><p>Configuración de plataforma · Solo maqueta</p></div>${badge('Apagado por defecto')}</div><p>Define el proveedor y las instrucciones comunes. Actívalo por cliente únicamente después de revisar qué se enviará.</p><form id="ai-form"><div class="settings-grid"><label>Proveedor<select id="ai-provider"><option value="groq">Groq</option><option value="compatible">Otro compatible con OpenAI</option></select></label><label>Modelo<input id="ai-model" value="modelo-de-prueba" required maxlength="120"><small>Identificador de ejemplo; validar disponibilidad antes de integrar.</small></label><label id="ai-endpoint-label" hidden>Dirección base del proveedor<input id="ai-endpoint" type="url" placeholder="https://proveedor.example/v1"></label><label>Escribir o reemplazar clave<input id="ai-key" type="password" autocomplete="new-password" placeholder="Solo una clave ficticia" maxlength="250"><small>Solo escritura, sin revelar. No ingreses una clave real en la maqueta.</small></label></div><p id="key-state" class="panel-note">Sin clave configurada en esta demostración.</p><label for="ai-context">Instrucciones y contexto guardados en la plataforma<textarea id="ai-context" rows="4" maxlength="2000">${escape(D.aiContext)}</textarea></label><p class="panel-note">En producción se guardan en el servidor. Aquí solo se conservan en memoria hasta recargar. No escribas nombres, documentos ni información de asuntos jurídicos.</p><details class="explanation"><summary>Reglas fijas de redacción</summary><p>${escape(D.aiPolicy)}</p></details><fieldset><legend>Activación por cliente</legend><label class="switch-label"><input id="ai-enabled" type="checkbox" role="switch">Grupo AZC · Habilitar asistente</label><p class="panel-note">Apagado inicialmente. Cambiar el interruptor es un borrador hasta guardar; esta maqueta nunca envía datos.</p></fieldset><section class="payload-details" aria-labelledby="preview-title"><h3 id="preview-title">Qué se enviaría al proveedor</h3><p>Alcance: Grupo AZC completo, 300 personas ficticias. Datos: cantidades de personas, minutos de actividad, productividad, comparación semanal, aplicaciones agregadas y grupos sin nombres, además del modelo, instrucciones y contexto que escribas.</p><p>No se envían nombres, documentos, nombres de equipos ni registros individuales de ventanas o asuntos jurídicos. Revisa el contexto: cualquier dato que escribas allí sí formaría parte de la solicitud. Esta demostración no envía nada.</p><details><summary>Ver detalle técnico</summary><p>Grupo AZC completo · ${D.people.length} personas ficticias · sin filtros personales. Incluye las reglas fijas, el contexto escrito y todos los agregados del ejemplo. En producción se genera por cliente y por alcance autorizado.</p><p>La clave viaja en la autenticación del servidor y nunca se muestra aquí. Los identificadores de grupo se resuelven dentro de Keeper; no se envían nombres, cédulas ni equipos.</p><p id="preview-target"></p><pre id="ai-preview" tabindex="0"></pre></details></section><div class="actions"><button class="primary" type="submit">Guardar configuración de demostración</button><button id="ai-reset" type="button">Restablecer ejemplo</button></div><p id="ai-save-status" role="status" aria-live="polite">Configuración sin guardar · Asistente apagado.</p></form></section>`;
    const preview = () => {
      const other = $('ai-provider').value === 'compatible';
      $('ai-endpoint-label').hidden = !other;
      $('ai-endpoint').required = other;
      $('preview-target').textContent = `Destino de ejemplo: ${other ? $('ai-endpoint').value || '(pendiente de definir)' : 'https://api.groq.com/openai/v1'} · Método POST · Recurso chat/completions. Activación propuesta: ${$('ai-enabled').checked ? 'encendida' : 'apagada'}; no se envía.`;
      $('ai-preview').textContent = JSON.stringify(D.aiPayload(D.people, { model: $('ai-model').value, context: $('ai-context').value }), null, 2);
    };
    let keyConfigured = false;
    $('ai-form').addEventListener('input', () => { preview(); $('ai-save-status').textContent = 'Cambios sin guardar. Pulsa Guardar para confirmar este borrador de demostración.'; });
    $('ai-form').onsubmit = e => {
      e.preventDefault();
      if (!$('ai-form').reportValidity()) return;
      if ($('ai-provider').value === 'compatible' && !/^https:\/\//i.test($('ai-endpoint').value)) { $('ai-save-status').textContent = 'La dirección del proveedor debe usar HTTPS.'; $('ai-endpoint').focus(); return; }
      if ($('ai-enabled').checked && !keyConfigured && !$('ai-key').value) { $('ai-save-status').textContent = 'Para simular la activación, escribe una clave ficticia.'; $('ai-key').focus(); return; }
      keyConfigured = keyConfigured || !!$('ai-key').value;
      $('ai-key').value = '';
      $('key-state').textContent = keyConfigured ? 'Clave recibida y descartada por la maqueta. Solo se conserva el indicador de configuración.' : 'Sin clave configurada.';
      $('ai-save-status').textContent = `Configuración guardada solo para esta página · Grupo AZC: ${$('ai-enabled').checked ? 'encendido (simulado)' : 'apagado'}. Al recargar se restablece. Ningún dato fue enviado.`;
      preview();
    };
    $('ai-reset').onclick = () => { $('ai-form').reset(); keyConfigured = false; $('ai-key').value = ''; $('key-state').textContent = 'Sin clave configurada.'; $('ai-save-status').textContent = 'Ejemplo restablecido · Asistente apagado.'; preview(); };
    preview();
  }
  header();
  if (page === 'supervisor') supervisor();
  if (page === 'persona') person();
  if (page === 'administrar') administer();
  if (page === 'asistente-ia') { if (role === 'superadmin') aiSettings(); else administer(); }
  if (location.hash) document.getElementById(location.hash.slice(1))?.scrollIntoView({ block: 'start' });
})();

(() => {
  'use strict';
  const O = window.ORDERS, D = window.DEMO;
  const params = new URLSearchParams(location.search), page = document.body.dataset.page;
  const role = ['supervisor', 'supervisor-orders'].includes(params.get('role')) ? params.get('role') : 'superadmin';
  window.ORDERS.setPermission(params.get('ordersPermission') !== 'off');
  const $ = id => document.getElementById(id);
  const esc = text => String(text ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const link = (file, values = {}) => { const q = new URLSearchParams(params); for (const [k, v] of Object.entries(values)) q.set(k, v); q.set('role', role); q.delete('scenario'); return `${file}?${q}`; };
  const time = value => /T/.test(value) ? new Intl.DateTimeFormat('es-CO', { timeZone: 'America/Bogota', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(value)) : value;
  let redraw = () => {};
  const demoNote = '<p class="panel-note">Solo maqueta · Órdenes ficticias en memoria: al recargar o navegar se restablecen. El reloj y los resultados cambian únicamente con los controles de simulación. Horas de Bogotá.</p>';
  function dialog(title, body, submitLabel, onSubmit) {
    const el = document.createElement('dialog');
    el.className = 'order-dialog'; el.setAttribute('aria-labelledby', 'order-dialog-title');
    el.innerHTML = `<form id="order-form"><h2 id="order-dialog-title">${esc(title)}</h2>${body}<p id="order-error" class="warn-text" role="alert"></p><div class="actions"><button class="primary" type="submit" id="order-submit">${esc(submitLabel)}</button><button type="button" id="order-back">Volver sin enviar</button></div></form>`;
    const opener = document.activeElement;
    document.body.append(el);
    $('order-back').onclick = () => el.close();
    el.addEventListener('close', () => { el.remove(); if (opener?.isConnected) opener.focus(); else $('main').focus(); });
    $('order-form').onsubmit = e => { e.preventDefault(); if (!$('order-form').reportValidity()) return; onSubmit(el); };
    el.showModal(); $('order-back').focus();
    return el;
  }
  function openOrder(ids, type) {
    const action = O.actions[type], targets = ids.map(O.getDevice);
    if (!action || targets.some(d => !d)) return;
    const working = targets.filter(d => d.working).length, offline = targets.filter(d => d.state === 'offline').length;
    const denials = targets.filter(d => O.allowed(d, type, role));
    const noticeSupported = targets.every(d => O.versionAtLeast(d.version, '4.0.17'));
    const wipe = type === 'wipe';
    const body = `<p class="order-summary">${targets.length} equipo(s) · ${working} en jornada · ${offline} sin conexión.</p><div class="compact-order-grid"><div><p>${esc(action.effect)}</p>${targets.length === 1 ? `<p><strong>${targets[0].hostname}</strong> · ${esc(targets[0].person.name)}</p>` : `<details><summary>Ver los ${targets.length} destinatarios</summary><ul class="target-list">${targets.map(d => `<li>${d.hostname} · ${esc(d.person.name)}</li>`).join('')}</ul></details>`}${action.proposed ? '<p class="notice">Solicitud de revisión; depende del agente. No garantiza corregir el fallo.</p>' : ''}${action.notice && working ? '<label class="switch-label notice"><input type="checkbox" id="work-confirm" required>Entiendo que puede perderse trabajo sin guardar durante la jornada.</label>' : ''}${wipe ? `<p class="notice">Borrado irreversible; confirma solo este equipo.</p><label>Escribe ${esc(ids[0])}<input id="wipe-name" required autocomplete="off" spellcheck="false"></label><label class="switch-label"><input type="checkbox" id="reauth" required>Simular autenticación reciente</label><small>En producción se confirma la contraseña; aquí no se solicita.</small>` : ''}</div><div>${action.notice ? `<label>Aviso a la persona<select id="order-notice"><option value="300" ${noticeSupported ? 'selected' : 'disabled'}>Sí · 5 minutos</option><option value="60" ${noticeSupported ? '' : 'disabled'}>Sí · 1 minuto</option><option value="600" ${noticeSupported ? '' : 'disabled'}>Sí · 10 minutos</option><option value="0" ${noticeSupported ? '' : 'selected'}>Sin aviso · al recibir</option></select></label><p id="notice-effect"></p>${noticeSupported ? '' : '<small>Versión anterior a 4.0.17: sin aviso.</small>'}` : '<p>Sin cuenta regresiva: se ejecuta al recibirla.</p>'}<label>Motivo (opcional)<textarea id="order-reason" rows="2" maxlength="500"></textarea></label><small>Vacío: nombre de la acción y «desde el panel».</small><p>En cola hasta conectar. Vence ${time(new Date(O.now() + 23 * 3600000).toISOString())} (23 h). El aviso empieza al recibirla.</p></div></div>${denials.length ? `<p class="warn-text">No se enviará: ${esc(O.allowed(denials[0], type, role))} Quita los equipos no autorizados.</p>` : ''}`;
    dialog(`${action.label} · revisar antes de enviar`, body, `Enviar ${ids.length === 1 ? 'orden' : `${ids.length} órdenes`} de demostración`, el => {
      const result = O.create(ids, type, role, { notice: $('order-notice')?.value || 0, reason: $('order-reason').value, confirmation: $('wipe-name')?.value, reauth: !!$('reauth')?.checked, workConfirmed: !!$('work-confirm')?.checked });
      if (result.error) { $('order-error').textContent = result.error; return; }
      el.close(); redraw();
      const feedback = $('order-feedback'); if (feedback) feedback.textContent = `${result.orders.length} orden(es) en cola. Revisa su seguimiento debajo; aún no se han ejecutado.`;
      document.querySelector('[data-history]')?.scrollIntoView({ block: 'nearest' });
    });
    const sync = () => {
      $('order-submit').disabled = !!denials.length || (wipe && ($('wipe-name').value !== ids[0] || !$('reauth').checked));
      if ($('notice-effect')) $('notice-effect').textContent = Number($('order-notice').value) ? `La persona verá una cuenta de ${Number($('order-notice').value) / 60} minutos; no podrá aplazarla. IT solo puede detenerla después de entregada si el agente es 4.0.27 o posterior.` : 'Sin aviso: se ejecuta al recibirla y después no puede cancelarse.';
    };
    $('order-form').addEventListener('input', sync); $('order-form').addEventListener('change', sync); sync();
  }
  function openCancel(id) {
    const o = O.orders.find(x => x.id === id);
    if (!o || O.cancelReason(o, role)) return;
    dialog(`Cancelar ${O.actions[o.type].label.toLowerCase()} · ${o.device}`, `<p>${o.status === 'pending' ? 'El equipo todavía no la recibió. Al cancelar no se le entregará.' : 'Se pedirá detener la cuenta en la próxima sincronización (aproximadamente 2 minutos). Si termina antes, la orden se ejecutará igualmente.'}</p><label>Motivo de cancelación (opcional)<textarea id="cancel-reason" maxlength="500" rows="2"></textarea></label>${o.type === 'wipe' ? '<label class="switch-label"><input id="cancel-reauth" type="checkbox" required>Simular autenticación reciente para cancelar el borrado</label>' : ''}`, 'Confirmar cancelación', el => {
      const error = O.cancel(id, role, $('cancel-reason').value, !!$('cancel-reauth')?.checked);
      if (error) { $('order-error').textContent = error; return; }
      el.close(); redraw();
    });
  }
  function actionButtons(d) {
    if (role === 'supervisor' || params.get('ordersPermission') === 'off' && role !== 'superadmin') return '<p class="notice">Tu rol no permite mandar ni cancelar órdenes. El superadmin activa o quita el permiso en Administrar → Órdenes de supervisores.</p>';
    if (role === 'supervisor-orders') return `<p>Solo tu gente. Borrado remoto no permitido.</p><div class="order-actions">${['lock', 'unlock', 'restart', 'shutdown'].map(type => `<div><button type="button" data-send="${type}" data-device="${d.hostname}" ${O.allowed(d, type, role) ? 'disabled' : ''}>${O.actions[type].label}</button><small>${esc(O.allowed(d, type, role))}</small></div>`).join('')}</div>`;
    return `<div class="order-actions">${Object.entries(O.actions).filter(([type]) => type !== 'wipe').map(([type, a]) => `<div><button type="button" data-send="${type}" data-device="${d.hostname}" ${O.allowed(d, type, role) ? 'disabled' : ''}>${a.label}</button>${O.allowed(d, type, role) ? `<small>${esc(O.allowed(d, type, role))}</small>` : ''}</div>`).join('')}</div><details class="danger-zone"><summary>Borrado remoto · irreversible</summary><p>Solo para un equipo y con confirmación escrita.</p><button type="button" data-send="wipe" data-device="${d.hostname}" ${O.allowed(d, 'wipe', role) ? 'disabled' : ''}>Borrado remoto</button>${O.allowed(d, 'wipe', role) ? `<p>${esc(O.allowed(d, 'wipe', role))}</p>` : ''}</details><p class="order-links"><a href="${esc(link('programacion.html', { device: d.hostname }))}">Programar apagado</a> · <button class="text-button" type="button" data-pin="${d.hostname}">Desbloqueo con PIN</button></p>`;
  }
  function history(ids) {
    const list = O.orders.filter(o => ids.includes(o.device));
    return `<section class="order-history" data-history aria-label="Seguimiento de órdenes"><h3>Seguimiento de órdenes</h3><p class="panel-note">Corte simulado: ${time(new Date(O.now()).toISOString())}. Entregada no significa hecha.</p>${!list.length ? '<p>No hay órdenes enviadas a estos equipos.</p>' : list.map(o => `<article class="order-entry"><div class="section-heading"><strong>${O.actions[o.type].label} · ${o.device}</strong><span class="badge ${o.status === 'failed' ? 'bad' : o.status === 'succeeded' ? 'good' : ''}">${esc(o.cancelRequested && ['delivered', 'running'].includes(o.status) ? 'Cancelación solicitada' : O.stateText(o))}</span></div><p>Enviada ${time(o.created)} por ${esc(o.actor)}.</p><p>Último estado: ${time(o.changed)} · Vencimiento: ${time(o.expires)}</p><p>Motivo: ${esc(o.reason)}</p>${o.detail ? `<p class="${o.status === 'failed' ? 'warn-text' : ''}">${esc(o.detail)}</p>` : ''}${o.cancelActor ? `<p>Cancelación pedida por ${esc(o.cancelActor)}${o.cancelReason ? ` · ${esc(o.cancelReason)}` : ''}.</p>` : ''}${!O.cancelReason(o, role) ? `<button type="button" data-cancel="${o.id}">Cancelar orden</button>` : ['pending', 'delivered', 'running'].includes(o.status) ? `<p class="panel-note">${esc(O.cancelReason(o, role))}</p>` : ''}${role === 'superadmin' && ['pending', 'delivered', 'running'].includes(o.status) ? `<details class="simulation"><summary>Solo maqueta · Simular respuesta</summary><p>Estos controles cambian el resultado ficticio; no envían nada.</p><div class="actions">${o.status === 'pending' ? `<button type="button" data-sim="deliver" data-order="${o.id}">Simular recepción del equipo</button><button type="button" data-sim="expire" data-order="${o.id}">Avanzar hasta vencimiento</button>` : `<button type="button" data-sim="success" data-order="${o.id}">${o.cancelRequested ? 'Simular que se ejecutó antes de cancelar' : 'Simular hecha'}</button><button type="button" data-sim="fail" data-order="${o.id}">Simular fallo</button>${o.cancelRequested ? `<button type="button" data-sim="ackCancel" data-order="${o.id}">Simular cancelación confirmada</button>` : ''}`}</div></details>` : ''}</article>`).join('')}</section>`;
  }
  function wire(root = document) {
    root.querySelectorAll('[data-send]').forEach(b => { b.onclick = () => openOrder([b.dataset.device], b.dataset.send); });
    root.querySelectorAll('[data-cancel]').forEach(b => { b.onclick = () => openCancel(b.dataset.cancel); });
    root.querySelectorAll('[data-sim]').forEach(b => { b.onclick = () => { O.simulate(b.dataset.order, b.dataset.sim); redraw(); }; });
    root.querySelectorAll('[data-pin]').forEach(b => { b.onclick = () => {
      dialog('Desbloqueo local con PIN', '<p>La persona escribe el PIN en la pantalla de bloqueo del equipo. Funciona sin conexión si el PIN ya se sincronizó y el agente es 4.0.17 o posterior.</p><p>Es un PIN compartido del cliente. Cambiarlo exige alcance de todo el cliente y autenticación reciente; los equipos desconectados conservan el anterior hasta sincronizar.</p><p>En esta demostración se puede simular la consulta, sin revelar un PIN real. La configuración se diseña con las reglas en el paso 3.</p><label class="switch-label"><input type="checkbox" required>Simular autenticación reciente del administrador</label><p id="demo-pin" role="status"></p>', 'Mostrar PIN ficticio', () => { $('demo-pin').textContent = 'PIN ficticio: 00000000 · Sin validez en equipos reales.'; $('order-submit').disabled = true; $('order-back').textContent = 'Cerrar'; });
    }; });
  }
  function renderPersonOrders() {
    document.querySelectorAll('[data-order-device]').forEach(mount => {
      const d = O.getDevice(mount.dataset.orderDevice);
      if (!d || !D.inScope(d.person, role)) return;
      mount.innerHTML = actionButtons(d) + history([d.hostname]);
    });
    wire();
  }
  function renderDevice() {
    const d = O.getDevice(params.get('device') || 'DESKTOP-0001');
    if (!d || !D.inScope(d.person, role)) { $('operations-content').innerHTML = '<h1>Equipo no disponible</h1><p>No existe en esta demostración o está fuera de tu alcance.</p>'; return; }
    if (['empty', 'error'].includes(params.get('scenario'))) { renderUnavailable('equipo.html'); return; }
    $('operations-content').innerHTML = `<p class="eyebrow">Modo Supervisor · Equipo de una persona</p><div class="page-heading"><div><h1>${d.hostname}</h1><p><a href="${esc(link('persona.html', { id: d.person.id }))}">${esc(d.person.name)}</a> · ${esc(d.person.client)} → ${esc(d.person.company)} · ${esc(d.person.area)}</p></div><a class="button" href="${esc(link(role === 'superadmin' ? 'problemas.html' : 'index.html'))}">${role === 'superadmin' ? 'Equipos con problemas' : 'Volver a personas'}</a></div><section class="surface"><h2>Estado actual</h2><dl class="controls"><div><dt>Conexión</dt><dd>${d.state === 'offline' ? 'Sin conexión' : d.state === 'away' ? 'Señal hace 10 min' : 'Conectado'} · ${d.seen}</dd></div><div><dt>Versión del agente</dt><dd>${d.version}</dd></div><div><dt>Protección</dt><dd>${d.stale ? 'Sin confirmar ahora' : d.partial ? 'Bloqueo web requiere revisión' : 'Controles aplicados según último reporte'} · ${d.observed}</dd></div><div><dt>Jornada</dt><dd>${d.working ? '08:00–17:00 · En horario laboral al corte inicial' : 'Ausencia aprobada · Sin jornada hoy'}</dd></div><div><dt>Funciones del plan de la persona</dt><dd>${d.features.length ? d.features.map(f => f === 'comandos' ? 'Órdenes remotas' : 'Borrado remoto').join(' y ') : 'No incluye órdenes remotas ni borrado'}</dd></div></dl></section><section class="surface orders-surface"><h2>Mandar una orden</h2><p>Elige la acción y revisa su efecto antes de enviarla.</p>${actionButtons(d)}<p id="order-feedback" role="status" aria-live="polite"></p>${history([d.hostname])}${demoNote}</section>`;
    wire();
  }
  function renderUnavailable(file) {
    const error = params.get('scenario') === 'error';
    $('operations-content').innerHTML = `<div class="empty-state" role="${error ? 'alert' : 'status'}"><h1>${error ? 'No pudimos cargar los equipos' : 'No hay equipos en esta vista'}</h1><p>${error ? 'No se conoce el estado actual. No se puede mandar una orden con esta consulta fallida.' : 'No hay problemas para revisar en este escenario de demostración.'}</p><a class="button primary" href="${esc(link(file))}">${error ? 'Reintentar' : 'Volver al ejemplo con datos'}</a></div>`;
  }
  function inbox() {
    if (role !== 'superadmin') { $('operations-content').innerHTML = '<h1>Esta vista no está disponible para tu rol</h1><p>La bandeja de equipos con problemas pertenece al modo Administrar.</p>'; return; }
    if (['empty', 'error'].includes(params.get('scenario'))) { renderUnavailable('problemas.html'); return; }
    $('operations-content').innerHTML = `<p class="eyebrow">Modo Administrar · Grupo AZC</p><h1>Equipos con problemas</h1><p class="admin-intro">Revisa lo que falla y sigue el resultado de cada orden. La protección fallida se atiende aquí.</p><p class="notice">Sin conexión prolongada: 2 días hábiles sin señal. No cuentan fines de semana, festivos ni ausencias aprobadas. No se distingue apagado, sin internet o agente detenido.</p><p><a href="${esc(link('administrar.html'))}">Volver a las tareas de administración</a></p><div class="inbox-filters surface"><label>Buscar equipo o persona<input id="equipment-search" type="search" placeholder="Nombre o DESKTOP-0001"></label><label>Empresa<select id="equipment-company"><option value="">Todas las empresas</option>${D.companies.map(x => `<option>${esc(x)}</option>`).join('')}</select></label><label>Problema<select id="equipment-kind"><option value="">Todos los problemas</option><option value="protection">Protección fallida</option><option value="offline">Sin conexión largo tiempo</option><option value="version">Versión atrasada</option><option value="order">Órdenes fallidas o vencidas</option></select></label></div><div class="bulk-bar surface"><p id="selection-count" role="status"></p><label>Orden para la selección<select id="bulk-type">${Object.entries(O.actions).filter(([type]) => type !== 'wipe').map(([type, a]) => `<option value="${type}" ${type === 'restart' ? 'selected' : ''}>${a.label}</option>`).join('')}</select></label><button class="primary" type="button" id="bulk-review">Revisar orden para selección</button><button type="button" id="clear-selection">Quitar selección</button><small>El borrado remoto se confirma por equipo.</small></div><p id="order-feedback" role="status" aria-live="polite"></p><section class="surface"><div class="section-heading"><h2>Equipos por revisar</h2><span id="equipment-count"></span></div><div id="equipment-table"></div><div id="equipment-pages" class="pagination"></div></section><div id="bulk-history" class="surface orders-surface"></div>${demoNote}`;
    let currentPage = 1; const selected = new Set(); let lastTargets = [];
    function render() {
      const term = $('equipment-search').value.toLocaleLowerCase('es-CO');
      const filtered = O.devices.filter(d => (!term || `${d.hostname} ${d.person.name}`.toLocaleLowerCase('es-CO').includes(term)) && (!$('equipment-company').value || d.person.company === $('equipment-company').value));
      const rows = O.groupedProblems(filtered, $('equipment-kind').value);
      $('equipment-count').textContent = `${rows.length} problemas agrupados · ${new Set(rows.flatMap(g => g.devices.map(d => d.hostname))).size} equipos`;
      $('selection-count').textContent = `${selected.size} equipos seleccionados`;
      $('bulk-review').disabled = !selected.size; $('clear-selection').disabled = !selected.size;
      $('equipment-table').innerHTML = !rows.length ? '<p class="notice">No hay problemas con esos filtros.</p>' : rows.map((g, i) => `<article class="problem-group"><div class="section-heading"><div><h3>${esc(g.label)} · ${g.devices.length} equipo(s)</h3><p>Desde ${esc(time(g.since))} · Ventana de agrupación: 15 minutos.</p>${g.detail ? `<p>${esc(g.detail)}</p>` : ''}</div>${g.action ? `<button type="button" data-group-action="${i}">${O.actions[g.action].label} a ${g.devices.length}</button>` : ''}</div><details><summary>Ver equipos / seleccionar algunos</summary><label class="switch-label"><input type="checkbox" data-group-select="${i}" ${g.devices.every(d => selected.has(d.hostname)) ? 'checked' : ''}>Seleccionar todo el grupo</label><ul class="group-devices">${g.devices.map(d => `<li><label class="switch-label"><input type="checkbox" data-select="${d.hostname}" ${selected.has(d.hostname) ? 'checked' : ''}>${d.hostname} · ${esc(d.person.name)}</label><a href="${esc(link('equipo.html', { device: d.hostname }))}">Ver equipo</a></li>`).join('')}</ul></details></article>`).join('');
      $('equipment-pages').innerHTML = '';
      const updateSelection = () => {
        document.querySelectorAll('[data-select]').forEach(b => { b.checked = selected.has(b.dataset.select); });
        document.querySelectorAll('[data-group-select]').forEach(b => { const devices = rows[Number(b.dataset.groupSelect)].devices; b.checked = devices.every(d => selected.has(d.hostname)); b.indeterminate = !b.checked && devices.some(d => selected.has(d.hostname)); });
        $('selection-count').textContent = `${selected.size} equipos seleccionados`; $('bulk-review').disabled = !selected.size; $('clear-selection').disabled = !selected.size;
      };
      document.querySelectorAll('[data-group-action]').forEach(b => { b.onclick = () => { const g = rows[Number(b.dataset.groupAction)]; openOrder(g.devices.map(d => d.hostname), g.action); }; });
      document.querySelectorAll('[data-group-select]').forEach(b => { b.onchange = () => { rows[Number(b.dataset.groupSelect)].devices.forEach(d => b.checked ? selected.add(d.hostname) : selected.delete(d.hostname)); updateSelection(); }; });
      document.querySelectorAll('[data-select]').forEach(b => { b.onchange = () => { b.checked ? selected.add(b.dataset.select) : selected.delete(b.dataset.select); updateSelection(); }; });
      const ids = [...new Set(O.orders.map(o => o.device))];
      $('bulk-history').innerHTML = history(ids);
      wire();
    }
    for (const id of ['equipment-search', 'equipment-company', 'equipment-kind']) $(id).addEventListener(id === 'equipment-search' ? 'input' : 'change', () => { currentPage = 1; selected.clear(); render(); });
    $('bulk-review').onclick = () => { lastTargets = [...selected]; openOrder(lastTargets, $('bulk-type').value); };
    $('clear-selection').onclick = () => { selected.clear(); render(); };
    redraw = render; render();
  }
  function programming() {
    if (role !== 'superadmin') { $('operations-content').innerHTML = '<h1>Tu rol no permite programar apagados</h1>'; return; }
    const preset = O.getDevice(params.get('device')) || O.devices[0];
    $('operations-content').innerHTML = `<p class="eyebrow">Modo Administrar · Grupo AZC</p><h1>Programar un apagado</h1><p class="admin-intro">Una sola fecha · Requiere agente 4.0.30 o posterior y la función de órdenes remotas del cliente. La repetición semanal se diseña en el paso 3.</p><p><a href="${esc(link('equipo.html', { device: preset.hostname }))}">Volver al equipo</a></p><section class="surface orders-surface"><h2>Una sola vez</h2><p>Esta programación es distinta de una orden en cola. El equipo la recibe al sincronizar y la evalúa a la hora indicada.</p><form id="schedule-form" class="schedule-form"><label>Equipo<select id="schedule-device">${O.devices.map(d => `<option ${d.hostname === preset.hostname ? 'selected' : ''} value="${d.hostname}">${d.hostname} · ${esc(d.person.name)}</option>`).join('')}</select></label><div class="settings-grid"><label>Fecha<input id="schedule-day" type="date" min="2026-10-29" max="2026-10-31" value="2026-10-30" required></label><label>Hora de Bogotá<input id="schedule-time" type="time" value="19:00" required></label></div><p>Aviso: 5 minutos. Si lleva 5 minutos sin uso, se apaga sin aviso; si está en uso, muestra el aviso. Ventana de ejecución: 60 minutos. Si no puede ejecutarse dentro de esa ventana, se omite; no se apaga al día siguiente por esta programación.</p><p class="notice">La persona podrá aplazar una vez 15 minutos. Si coincide con su jornada, puede interrumpir trabajo.</p><label class="switch-label"><input id="schedule-confirm" type="checkbox" required>Revisé la fecha, el equipo y el impacto en la persona.</label><button class="primary" type="submit">Revisar programación</button></form><div id="schedule-result" role="status" aria-live="polite"></div></section><section class="surface orders-surface" id="periodic"><h2>Repetir cada semana</h2><span class="future-tag">Se diseña en el paso 3</span><p>Las reglas periódicas y su configuración completa se diseñan junto a las demás reglas.</p></section>${demoNote}`;
    $('schedule-form').onsubmit = e => {
      e.preventDefault(); const d = O.getDevice($('schedule-device').value), date = $('schedule-day').value, at = $('schedule-time').value;
      const denial = !O.versionAtLeast(d.version, '4.0.30') ? `El agente ${d.version} no ejecuta programaciones; requiere 4.0.30 o posterior.` : '';
      if (denial) { $('schedule-result').textContent = denial; return; }
      if (Date.parse(`${date}T${at}:00-05:00`) <= O.now()) { $('schedule-result').textContent = 'Elige una fecha y hora posteriores al reloj de demostración.'; return; }
      dialog('Revisar apagado de una sola vez', `<p>${d.hostname} · ${esc(d.person.name)} · ${date} a las ${at}, Bogotá.</p><p>Aviso de 5 minutos; si lleva 5 minutos sin uso se ejecuta sin aviso; una postergación de 15 minutos y ventana máxima de 60 minutos.</p><p>Si el equipo no recibe la programación antes de su ventana, no la ejecuta. Esta pantalla simula guardarla, no confirma un apagado.</p>`, 'Guardar programación de demostración', el => { el.close(); $('schedule-result').innerHTML = `<p class="notice">Programación guardada solo en esta página · ${d.hostname} · ${date}, ${at}. Pendiente de recibir y ejecutar. Creada por Laura Pardo, ${time(new Date(O.now()).toISOString())}.</p><button type="button" id="remove-schedule">Eliminar programación de demostración</button>`; $('remove-schedule').onclick = () => { $('schedule-result').textContent = 'Programación eliminada de la demostración.'; }; });
    };
  }
  if (page === 'persona') { redraw = renderPersonOrders; redraw(); }
  if (page === 'equipo') { redraw = renderDevice; redraw(); }
  if (page === 'problemas') inbox();
  if (page === 'programacion') programming();
})();
