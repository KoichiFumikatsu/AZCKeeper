(() => {
  'use strict';
  const D = window.DEMO;
  const params = new URLSearchParams(location.search);
  const page = document.body.dataset.page;
  const role = params.get('role') === 'supervisor' ? 'supervisor' : 'superadmin';
  const scenario = ['empty', 'error'].includes(params.get('scenario')) ? params.get('scenario') : 'normal';
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
    $('header').innerHTML = `<div class="topbar"><div class="topbar-left"><a class="brand" href="${escape(url('index.html'))}" aria-label="AZC Keeper · Inicio supervisor"><img src="brand/logo-mark.png" alt="" width="32" height="40"><span><strong>AZC Keeper</strong><small>Personas y su día</small></span></a><span class="mode-label">Modo ${['administrar', 'asistente-ia'].includes(page) && role === 'superadmin' ? 'Administrar' : 'Supervisor'}</span></div>${role === 'superadmin' ? `<a class="button" href="${escape(url(['administrar', 'asistente-ia'].includes(page) ? 'index.html' : 'administrar.html'))}">${['administrar', 'asistente-ia'].includes(page) ? 'Volver a supervisar' : 'Administrar'}</a>` : '<span class="badge">Supervisor de área</span>'}</div>
      <div class="demo-bar"><strong class="demo-tag">Solo maqueta</strong><label for="demo-role">Ver como:<select id="demo-role"><option value="superadmin">Superadmin</option><option value="supervisor">Supervisor de área</option></select></label>${!['administrar', 'asistente-ia'].includes(page) ? '<label for="demo-state">Escenario:<select id="demo-state"><option value="normal">Con datos</option><option value="empty">Vacío</option><option value="error">Error de carga</option></select></label>' : ''}<span class="demo-note">Datos ficticios · Sin conexión a producción</span></div>`;
    $('demo-role').value = role;
    $('demo-role').addEventListener('change', e => {
      location.href = url(page === 'persona' ? 'persona.html' : 'index.html', { role: e.target.value, client: null, company: null, area: null, site: null, state: null, q: null, page: null, cohort: null });
    });
    if ($('demo-state')) {
      $('demo-state').value = scenario;
      $('demo-state').addEventListener('change', e => { location.href = url(page === 'persona' ? 'persona.html' : 'index.html', { scenario: e.target.value }); });
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
    if (role === 'supervisor') {
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
    $('device-section').innerHTML = `<h2 id="devices-title">Sus equipos · estado actual</h2><p class="panel-note">${devices.length} equipo${devices.length === 1 ? '' : 's'} asignado${devices.length === 1 ? '' : 's'} · Corte de demostración: 29 oct, 15:30. La protección es el estado reportado, no una garantía.</p>${devices.length ? devices.map(device => `<article class="device"><div class="device-title"><h3>${device.hostname}</h3>${badge(device.state === 'connected' ? 'Conectado' : device.state === 'away' ? 'Señal hace 10 min' : 'Sin conexión', device.state === 'connected' ? 'good' : '')}</div><p>Versión del agente ${device.version} · Última señal: ${device.seen}</p><p><strong>${device.stale ? 'Protección sin confirmar ahora' : device.partial ? 'Protección parcial reportada' : 'Controles aplicados según el equipo'}</strong> · Reporte: ${device.observed}</p><dl class="controls"><div><dt>Bloqueo web</dt><dd class="${device.partial ? 'warn-text' : 'good-text'}">${device.partial ? 'Requiere revisión' : 'Aplicado en último reporte'}</dd></div><div><dt>Restricción de USB</dt><dd>Aplicada en último reporte</dd></div><div><dt>Instalación de programas</dt><dd>Restringida en último reporte</dd></div></dl><div class="actions"><button disabled type="button">Bloquear equipo · paso 2</button><button disabled type="button">Reiniciar equipo · paso 2</button><button disabled type="button">Actualizar reglas · paso 2</button></div></article>`).join('') : '<p>Sin equipos asignados. El alta de persona y equipo se diseña en el paso 4.</p>'}<p class="panel-note">Las acciones están fuera de este paso y no envían órdenes.</p>`;
  }

  function administer() {
    if (role === 'supervisor') {
      $('admin-content').innerHTML = empty('Esta vista no está disponible para tu rol', 'El supervisor de área consulta a las personas de su alcance.', `<a class="button primary" href="${escape(url('index.html'))}">Volver a supervisar</a>`);
      return;
    }
    const tasks = [
      [2, 'Mandar órdenes a equipos', 'Bloquear, reiniciar o actualizar reglas, con el resultado de cada orden.'],
      [3, 'Qué se bloquea', 'Definir las reglas de sitios, aplicaciones, USB e instalación de programas.'],
      [4, 'Alta de persona y equipo', 'Dar de alta a una persona, asignar su equipo y comprobar que quedó conectado.'],
      [5, 'Clientes, funciones y planes', 'Organizar clientes y empresas, revisar qué tiene contratado cada cliente y a quién aplica.'],
    ];
    $('admin-content').innerHTML = `<p class="eyebrow">Modo Administrar</p><h1>¿Qué necesitas configurar?</h1><p class="admin-intro">Aquí se prepara cómo funciona Keeper. Para revisar personas y su día, vuelve al modo Supervisor.</p><p class="notice">Alcance: todos los clientes. Cada tarea pedirá elegir el cliente sobre el que vas a trabajar. En esta demostración solo existe Grupo AZC.</p><div class="tools-grid">${tasks.map(([step, title, detail]) => `<article class="surface task-card"><span class="step" aria-hidden="true">${step}</span><div><h2>${title}</h2><p>${detail}</p><span class="future-tag">Se diseña en el paso ${step}</span></div></article>`).join('')}<article class="surface task-card"><div><h2>Asistente de IA</h2><p>Configurar proveedor, instrucciones y activación por cliente.</p><a class="button" href="${escape(url('asistente-ia.html'))}">Configurar asistente</a></div></article></div><details class="surface more-tools"><summary>Más herramientas</summary><p>Herramientas técnicas y de seguimiento. Su flujo no se diseña en este paso.</p><ul><li>Versiones del agente<small>Consultar versiones y organizar actualizaciones.</small></li><li>Salud del servidor<small>Revisar el funcionamiento del servicio.</small></li><li>Auditoría<small>Consultar quién cambió qué y cuándo.</small></li><li>Conexiones y registros técnicos<small>Revisar integraciones y diagnósticos.</small></li></ul></details>`;
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
