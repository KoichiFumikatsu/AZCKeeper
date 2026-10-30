(() => {
  'use strict';
  const A = window.ALTAS, D = window.DEMO, root = document.getElementById('altas-content');
  if (!root) return;
  const params = new URLSearchParams(location.search), role = params.get('role') || 'superadmin';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch]));
  const $ = id => document.getElementById(id);
  const href = (view, id = '') => `#${view}${id ? '/' + encodeURIComponent(id) : ''}`;
  const go = (view, id = '') => { location.hash = href(view,id); };
  const opts = (values, selected = '') => values.map(v => `<option value="${esc(v)}" ${v === selected ? 'selected' : ''}>${esc(v)}</option>`).join('');
  const planOptions = selected => Object.entries(A.plans).map(([id,label]) => `<option value="${id}" ${id === selected ? 'selected' : ''}>${label}</option>`).join('');
  const activeOptions = (exclude = '') => '<option value="">Seleccionar persona</option>' + A.people.filter(p => p.status === 'active' && p.id !== exclude).map(p => `<option value="${p.id}">${esc(p.name)} · ${esc(p.company)}</option>`).join('');
  const feedback = text => { $('intake-feedback').textContent = text; };
  let message = '', draft = null, step = 0, bulkReview = null, csvPreview = null, csvText = '', csvResult = null, search = '';
  function shell(title, detail, content, current = '') {
    root.innerHTML = `<div class="intake-context"><p>Modo Administrar · Cliente: <strong>${esc(D.client || 'Grupo AZC')}</strong> · 29 oct 2026, 15:30</p><a href="administrar.html?role=superadmin">Volver a Administrar</a></div><div class="intake-lead"><div><h1 tabindex="-1">${esc(title)}</h1><p>${esc(detail)}</p></div>${current === 'inicio' ? `<a class="button primary" href="#nueva">Nueva persona</a>` : ''}</div><nav class="intake-nav" aria-label="Personas y equipos">${[['inicio','Seguimiento'],['solicitudes','Solicitudes pendientes'],['importar','Alta masiva'],['problemas','Problemas de incorporación']].map(([view,label]) => `<a href="#${view}" ${current === view ? 'aria-current="page"' : ''}>${label}</a>`).join('')}</nav><p class="panel-note">Solo maqueta. Los cambios se conservan al moverte por esta tarea; recargar o salir de Personas y equipos restablece los ejemplos.</p><p id="intake-feedback" class="intake-feedback" role="status" aria-live="polite">${esc(message)}</p>${content}`;
    message = ''; root.querySelector('h1')?.focus();
  }
  function download(text, name) {
    const url = URL.createObjectURL(new Blob([text], { type:'text/plain;charset=utf-8' }));
    const anchor = document.createElement('a'); anchor.href = url; anchor.download = name; anchor.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  function home() {
    const pending = A.requests.filter(r => r.status === 'pending'), follow = A.followup();
    shell('Personas y equipos', 'Incorpora a alguien, prepara su computador y comprueba que sus protecciones llegaron.', `<div class="intake-totals"><article class="surface"><strong>${follow.length}</strong><p>personas dadas de alta esta semana aún sin equipo protegido</p></article><article class="surface"><strong>${pending.length}</strong><p>solicitudes pendientes de revisión</p></article><article class="surface"><strong>${A.departures.filter(j => !j.finished).length}</strong><p>bajas esperando completar acciones de equipos</p></article></div><section class="surface"><h2>Altas que necesitan seguimiento</h2>${follow.length ? `<ul class="intake-list">${follow.map(p => `<li><div><strong>${esc(p.name)}</strong><p>${A.protection(p).label} · ${esc(A.protection(p).reason)}</p></div><a class="button" href="${href('persona',p.id)}">Completar incorporación</a></li>`).join('')}</ul>` : '<p>Todas las altas de esta semana tienen protección confirmada.</p>'}</section><section class="surface"><h2>Buscar una persona</h2><label class="intake-search">Nombre, cédula o empresa<input type="search" id="people-search" value="${esc(search)}" placeholder="Ej.: Valeria Montes"></label><div id="people-results"></div></section>`, 'inicio');
    const list = () => {
      const filtered = A.people.filter(p => `${p.name} ${p.document} ${p.company}`.toLocaleLowerCase('es-CO').includes(search.toLocaleLowerCase('es-CO')));
      $('people-results').innerHTML = `<p>${filtered.length} personas · se muestran hasta 15; escribe para acotar.</p><ul class="intake-list">${filtered.slice(0,15).map(p => `<li><div><a href="${href('persona',p.id)}"><strong>${esc(p.name)}</strong></a><p>${esc(p.company)} → ${esc(p.area)} · ${p.status === 'active' ? 'Activa' : p.status === 'departing' ? 'Baja pendiente' : 'Retirada'}</p></div><span class="badge ${A.protection(p).ok ? 'good' : 'warn'}">${A.protection(p).label}</span></li>`).join('')}</ul>${filtered.length ? '' : '<p>No hay personas con esa búsqueda.</p>'}`;
    };
    $('people-search').oninput = e => { search = e.target.value; list(); }; list();
  }
  function installation(p, next = false) {
    return `<div class="notice"><strong>Preparar la instalación</strong><ol class="intake-checklist"><li>Entregar el paquete de ${esc(D.client || 'Grupo AZC')} por el canal autorizado de IT.</li><li>Ejecutarlo en Windows con permisos de administrador.</li><li>Informar la cédula si se solicita; IT asigna los equipos sin coincidencia.</li><li>Esperar aprobación, conexión y reporte de protección.</li></ol><p>Una coincidencia de cédula sugiere la persona; no prueba quién usa el equipo. La aprobación automática depende de las reglas del cliente.</p><div class="actions"><button type="button" id="prepare-installer">Preparar instalador · simulación</button><button type="button" id="download-instructions">Descargar instrucciones</button></div><p id="installer-status" role="status">${p.installationPrepared ? 'Paquete preparado (simulado): AZCKeeper-paquete-del-cliente.zip. Sin instalador ejecutable ni clave real en esta maqueta.' : 'Paquete aún no preparado. La demostración permite revisar las instrucciones; no descarga un ejecutable.'}</p>${next ? '<p>Podrás completar la persona ahora y seguir la instalación desde su ficha.</p>' : ''}</div>`;
  }
  function wireInstallation(p) {
    $('prepare-installer').onclick = () => { p.installationPrepared = true; $('installer-status').textContent = 'Paquete preparado (simulado): AZCKeeper-paquete-del-cliente.zip. En producción incluirá la clave del cliente. Aquí no se genera una clave ni un ejecutable.'; };
    $('download-instructions').onclick = () => download(A.instructions(p), 'instrucciones-keeper-demostracion.txt');
  }
  function newPerson() {
    if (!draft) { draft = { name:'', document:'', email:'', company:D.companies[0], site:D.sites[0], area:D.areas[0], job:'', schedule:A.schedules[0], plan:'cliente', equipment:'later', equipmentId:'' }; step = 0; }
    const parts = [
      `<fieldset><legend>1. Datos de la persona</legend><label>Nombre completo<input name="name" maxlength="120" required value="${esc(draft.name)}" autocomplete="off"></label><div class="settings-grid"><label>Cédula (opcional)<input name="document" inputmode="numeric" value="${esc(draft.document)}"><small>Permite sugerir la persona cuando se instale el equipo.</small></label><label>Correo (opcional)<input name="email" type="email" value="${esc(draft.email)}"></label></div><p>Sin cédula, la asignación del equipo se revisa manualmente.</p></fieldset>`,
      `<fieldset><legend>2. Dónde trabaja</legend><p>Cliente: ${esc(D.client || 'Grupo AZC')}. Los valores iniciales son sugerencias: revísalos.</p><div class="settings-grid"><label>Empresa<select name="company">${opts(D.companies,draft.company)}</select></label><label>Sede<select name="site">${opts(D.sites,draft.site)}</select></label><label>Área<select name="area">${opts(D.areas,draft.area)}</select></label><label>Cargo<input name="job" required value="${esc(draft.job)}"></label><label>Horario · Bogotá<select name="schedule">${opts(A.schedules,draft.schedule)}</select></label><label>Plan<select name="plan">${planOptions(draft.plan)}</select></label></div><p>Plan del cliente: ${esc(A.plans.cliente)}. Controles: ${esc(D.clientControls ? D.clientControls.join(', ') || 'ninguno' : 'bloqueo web, USB e instalación')}. Descargas no está contratado. El plan no crea una cuenta de acceso al panel.</p></fieldset>`,
      `<fieldset><legend>3. Su equipo</legend><label>Situación del computador<select name="equipment" id="equipment-choice"><option value="later">Todavía no está instalado</option><option value="installed">Ya inscrito · reserva de IT</option><option value="pending">Solicitud pendiente · sin persona</option></select></label><div id="equipment-options"></div></fieldset>`,
      `<section><h2>4. Revisar antes de crear</h2>${reviewPerson(draft)}<p class="notice">Se crea la persona y se prepara el vínculo elegido. ${draft.plan === 'sin' || draft.equipment !== 'installed' ? 'No protegido: falta equipo o plan con controles.' : 'Sin confirmar: falta recibir el reporte del equipo.'}${draft.plan === 'sin' ? ' El plan elegido no aplica controles.' : ''}</p><label class="switch-label"><input type="checkbox" id="new-confirm" required>Revisé los datos, la ubicación, el plan y el equipo.</label></section>`
    ];
    shell('Nueva persona', 'Datos → dónde trabaja → su equipo → revisar.', `<div class="intake-flow"><ol class="intake-steps">${['Datos','Dónde trabaja','Su equipo','Revisar'].map((label,i) => `<li ${i === step ? 'aria-current="step"' : ''}>${i + 1}. ${label}</li>`).join('')}</ol><form id="new-person-form" class="surface intake-form">${parts[step]}<p id="new-error" role="alert" class="warn-text"></p><div class="actions"><button type="button" id="cancel-new">Cancelar</button>${step ? '<button type="button" id="previous-step">Anterior</button>' : ''}<button type="submit" class="primary">${step === 3 ? 'Crear persona en maqueta' : 'Continuar'}</button></div></form></div>`);
    const form = $('new-person-form');
    const collect = () => { for (const [key,value] of new FormData(form)) draft[key] = String(value).trim(); };
    if (step === 2) {
      $('equipment-choice').value = draft.equipment;
      const draw = () => {
        draft.equipment = $('equipment-choice').value;
        if (draft.equipment === 'later') { $('equipment-options').innerHTML = installation(draft,true); wireInstallation(draft); }
        else {
          const pool = draft.equipment === 'installed' ? A.available().map(d => [d.hostname,`${d.hostname} · reserva de IT`]) : A.requests.filter(r => r.status === 'pending' && !r.personId).map(r => [r.id,`${r.hostname} · sin coincidencia`]);
          $('equipment-options').innerHTML = `<label>${draft.equipment === 'installed' ? 'Equipo disponible' : 'Solicitud por revisar'}<select name="equipmentId" required><option value="">Seleccionar</option>${pool.map(([value,label]) => `<option value="${value}" ${draft.equipmentId === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label><p>${draft.equipment === 'installed' ? 'Actualmente asignado a IT. Se reasigna desde este momento; el historial anterior permanece con IT. Debe recibir las reglas de la nueva persona.' : 'Al confirmar el alta se aprueba esta solicitud para la nueva persona. Aún deberá conectarse y reportar los controles.'}</p>`;
        }
      };
      $('equipment-choice').onchange = () => { draft.equipmentId = ''; draw(); }; draw();
    }
    $('cancel-new').onclick = () => {
      modal('Descartar esta alta', '<p>Los datos del asistente no se han guardado.</p>', 'Descartar borrador', () => { draft = null; go('inicio'); return ''; });
    };
    if (step) $('previous-step').onclick = () => { collect(); step--; newPerson(); };
    form.onsubmit = event => {
      event.preventDefault(); if (!form.reportValidity()) return; collect();
      let errors = A.validate(draft);
      if (step === 0) errors = errors.filter(e => /nombre|cédula|correo/.test(e));
      if (step === 1) errors = errors.filter(e => !/equipo|solicitud|vincular/.test(e));
      if (errors.length) { $('new-error').textContent = errors.join(' '); return; }
      if (step < 3) { step++; newPerson(); return; }
      const result = A.create(draft);
      if (result.errors.length) { $('new-error').textContent = result.errors.join(' '); return; }
      draft = null; message = 'Persona creada en la maqueta. Sigue lo que falta para dejar su equipo protegido.'; go('persona',result.person.id);
    };
  }
  function reviewPerson(p) {
    const equipment = p.equipment === 'installed' ? p.equipmentId : p.equipment === 'pending' ? A.requests.find(r => r.id === p.equipmentId)?.hostname : 'Pendiente de instalar';
    return `<div class="intake-review"><dl><dt>Nombre</dt><dd>${esc(p.name)}</dd><dt>Cédula / correo</dt><dd>${esc(p.document || 'Sin cédula')} · ${esc(p.email || 'Sin correo')}</dd><dt>Cliente → Empresa</dt><dd>${esc(D.client || 'Grupo AZC')} → ${esc(p.company)}</dd><dt>Sede / área</dt><dd>${esc(p.site)} / ${esc(p.area)}</dd></dl><dl><dt>Cargo y horario</dt><dd>${esc(p.job)} · ${esc(p.schedule)}</dd><dt>Plan</dt><dd>${esc(A.plans[p.plan])}</dd><dt>Equipo</dt><dd>${esc(equipment)}</dd></dl></div>`;
  }
  function profile(id) {
    const p = A.person(id); if (!p) return missing();
    const status = A.protection(p), list = A.owned(id), waiting = A.requests.filter(r => r.personId === id && r.status === 'approved'), job = A.departures.find(j => j.personId === id && !j.finished);
    shell(p.name, `${p.client} → ${p.company} → ${p.area} · ${p.site}`, `<section class="intake-status ${status.ok ? 'complete' : 'pending'}"><h2>${status.label}</h2><p>${esc(status.reason)}</p><p>Plan: ${esc(A.plans[p.plan])} · ${p.status === 'active' ? 'Persona activa' : p.status === 'departing' ? 'Baja pendiente' : 'Persona retirada'}</p></section>${p.status === 'active' ? `<section class="surface"><h2>Hoy</h2><p>${D.people.some(x => x.id === p.id) ? `La ficha de actividad se conserva en el modo Supervisor. <a href="persona.html?role=superadmin&amp;id=${p.id}">Ver su día</a> (salir restablece los cambios de esta tarea).` : 'Sin actividad recibida. El alta de una persona no genera horas de trabajo ni productividad.'}</p><p>Horario: ${esc(p.schedule)} · Bogotá.</p></section>` : ''}<section class="surface"><div class="section-heading"><h2>Sus equipos</h2>${p.status === 'active' && list.length ? `<a class="button" href="${href('reemplazar',id)}">Reemplazar equipo</a>` : ''}</div>${list.length ? list.map(d => `<article class="intake-row"><div><strong>${d.hostname}</strong><p>${d.state === 'connected' ? 'Conectado' : 'Sin confirmación actual de conexión'} · agente ${d.version}</p><p>${A.deviceProtection(d,p).label} · Última señal: ${esc(d.seen || 'Sin señal recibida')}</p></div>${p.status === 'active' ? `<button type="button" data-report="${d.hostname}" ${p.plan === 'sin' ? 'disabled' : ''}>Simular reporte correcto</button>` : ''}</article>`).join('') : '<p>No tiene equipos activos asignados.</p>'}${waiting.map(r => `<article class="intake-row"><div><strong>${r.hostname}</strong><p>Solicitud aprobada. Falta conexión, inscripción y reporte.</p></div>${p.status === 'active' ? `<button type="button" data-connect="${r.id}">Simular conexión</button>` : ''}</article>`).join('')}${p.status === 'active' && !list.length && !waiting.length ? `<form id="attach-form" class="intake-form"><label>Asignar equipo de reserva de IT<select id="attach-device" required><option value="">Seleccionar equipo</option>${opts(A.available().map(d => d.hostname))}</select></label><button type="submit">Revisar asignación</button></form>${installation(p)}` : ''}</section>${p.status === 'active' ? `<section class="surface"><h2>Qué recibirá y qué falta</h2><p>${p.plan === 'sin' ? 'Sin controles: cambia el plan para solicitar protección.' : 'Reglas de cliente, empresa cuando esté disponible, área y persona. Las excepciones personales se conservan. Una regla propia del equipo puede prevalecer.'}</p><p>Protección requiere reglas recibidas y controles aplicados; conexión o aprobación por sí solas no bastan.</p>${p.plan === 'sin' ? '<button type="button" id="assign-plan">Revisar plan de protección</button>' : ''}</section>` : ''}${retirements(p)}${job ? departureStatus(p,job) : ''}<details class="surface"><summary>Datos e historial de cambios</summary>${reviewPerson(p)}<ul>${p.history.map(h => `<li>${esc(h)}</li>`).join('') || '<li>Sin cambios en esta demostración.</li>'}</ul><p>La información previa se conserva según la retención del cliente.</p></details>${p.status === 'active' ? `<details class="surface danger-zone"><summary>Baja de persona</summary><p>El acceso al panel se quita al confirmar la baja. Se conserva el historial y la identidad del agente hasta completar sus acciones.</p><a class="button" href="${href('baja',id)}">Preparar baja de persona</a></details>` : ''}`);
    root.querySelectorAll('[data-report]').forEach(b => b.onclick = () => { A.confirmReport(b.dataset.report); message = 'Reporte de protección simulado. No se consultó ningún equipo real.'; profile(id); });
    root.querySelectorAll('[data-connect]').forEach(b => b.onclick = () => { A.connectRequest(b.dataset.connect); message = 'Conexión simulada; aún falta confirmar las protecciones.'; profile(id); });
    if ($('attach-form')) {
      wireInstallation(p);
      $('attach-form').onsubmit = event => { event.preventDefault(); const host = $('attach-device').value; modal('Revisar asignación', `<p>${host} pasará de reserva de IT a ${esc(p.name)}. Su historial anterior se conserva; recibirá las reglas de esta persona.</p>`, 'Asignar en maqueta', () => {
        const d = A.device(host); if (!A.available().includes(d)) return 'El equipo ya no está disponible.';
        d.personId = p.id; d.inventory = false; d.rulesCurrent = false; d.report = false; p.history.push(`${host} asignado desde reserva de IT.`); profile(id); return '';
      }); };
    }
    if ($('assign-plan')) $('assign-plan').onclick = () => modal('Revisar plan', `<p>${esc(p.name)} usará Protección de oficina: bloqueo web, USB e instalación. Requiere reporte posterior del equipo.</p>`, 'Asignar plan en maqueta', () => { p.plan = 'oficina'; A.owned(id).forEach(d => { d.report = false; d.rulesCurrent = false; }); p.history.push('Plan cambiado a Protección de oficina.'); profile(id); return ''; });
    root.querySelectorAll('[data-retire]').forEach(b => b.onclick = () => { A.confirmReplacement(b.dataset.retire); profile(id); });
    if (job) wireDeparture(p,job);
  }
  function retirements(p) {
    const pending = A.devices.filter(d => d.personId === p.id && d.status === 'retiring');
    return pending.length ? `<section class="surface"><h2>Retiros pendientes</h2><ul>${pending.map(d => `<li>${d.hostname} · ${d.state === 'offline' ? 'Retiro pendiente: se bloqueará cuando vuelva a conectarse' : 'Bloqueo pendiente de confirmación'} <button data-retire="${d.hostname}">Simular bloqueo confirmado y retirar</button></li>`).join('')}</ul><p>También aparecen en la bandeja de problemas de esta tarea.</p><a href="#problemas">Ver problemas de incorporación</a></section>` : '';
  }
  function intakeProblems() {
    shell('Problemas de incorporación', 'Bloqueos pendientes antes de retirar equipos.', A.people.map(retirements).join('') || '<p>No hay retiros pendientes.</p>');
    root.querySelectorAll('[data-retire]').forEach(b => b.onclick = () => { A.confirmReplacement(b.dataset.retire); intakeProblems(); });
  }
  function modal(title, content, label, submit) {
    const el = document.createElement('dialog'); el.className = 'order-dialog';
    el.innerHTML = `<form class="intake-form"><h2>${esc(title)}</h2>${content}<p role="alert" class="warn-text" id="modal-error"></p><div class="actions"><button type="button" id="modal-cancel">Volver</button><button class="primary" type="submit">${esc(label)}</button></div></form>`;
    document.body.append(el); el.addEventListener('close',() => el.remove()); el.querySelector('#modal-cancel').onclick = () => el.close();
    el.querySelector('form').onsubmit = event => { event.preventDefault(); if (!el.querySelector('form').reportValidity()) return; const error = submit(el); if (error) el.querySelector('#modal-error').textContent = error; else el.close(); };
    el.showModal();
  }
  function inbox() {
    const matching = A.requests.filter(r => r.status === 'pending' && r.match === 'document'), unmatched = A.requests.filter(r => r.status === 'pending' && r.match === 'none'), approved = A.requests.filter(r => r.status === 'approved');
    shell('Solicitudes pendientes', 'Revisa a quién corresponde cada computador antes de aprobarlo.', `<p class="notice">La cédula declarada no verifica la identidad. En este ejemplo, la aprobación por cédula es manual; las coincidencias de serie esperada pueden aprobarse automáticamente.</p><section class="surface"><div class="section-heading"><div><h2>${matching.length} equipos coinciden con una persona por cédula</h2><p>Antes de aprobar verás equipo, persona y cliente de todo el lote.</p></div><button id="review-matches" class="primary" ${!matching.length ? 'disabled' : ''}>Revisar los ${matching.length}</button></div><details><summary>Ver coincidencias</summary><ul class="intake-list">${matching.map(r => `<li><div><strong>${r.hostname}</strong><p>${esc(A.person(r.personId).name)} · ${r.document}</p></div></li>`).join('')}</ul></details></section><section class="surface"><h2>${unmatched.length} sin coincidencia → asignar</h2><form id="unmatched-form" class="intake-form">${unmatched.map(r => `<div class="intake-row"><div><strong>${r.hostname}</strong><p>Sin cédula reconocida. Confirma con IT a quién pertenece.</p></div><label>Persona<select name="${r.id}">${activeOptions()}</select></label></div>`).join('')}<p>Puedes revisar solo las solicitudes que ya tienen persona elegida.</p><button type="submit" ${!unmatched.length ? 'disabled' : ''}>Revisar asignaciones elegidas</button></form></section><section class="surface"><h2>Aprobadas, esperando conexión (${approved.length})</h2><p>Una aprobación no significa que el equipo ya esté inscrito o protegido.</p><ul class="intake-list">${approved.map(r => `<li><div><strong>${r.hostname}</strong><p>${esc(A.person(r.personId).name)}</p><a href="${href('persona',r.personId)}">Ver ficha</a></div><button type="button" data-connect="${r.id}">Simular conexión</button></li>`).join('') || '<li>No hay solicitudes aprobadas esperando conexión.</li>'}</ul></section>`, 'solicitudes');
    $('review-matches').onclick = () => { bulkReview = matching.map(r => ({ id:r.id, personId:r.personId })); reviewRequests(); };
    $('unmatched-form').onsubmit = event => { event.preventDefault(); bulkReview = [...new FormData(event.target)].filter(([,value]) => value).map(([id,personId]) => ({ id,personId })); if (!bulkReview.length) { feedback('Asigna al menos una solicitud antes de revisar.'); return; } reviewRequests(); };
    root.querySelectorAll('[data-connect]').forEach(b => b.onclick = () => { A.connectRequest(b.dataset.connect); message = 'Conexión simulada. Revisa la ficha para confirmar protección.'; inbox(); });
  }
  function reviewRequests() {
    const items = bulkReview;
    shell('Revisar aprobación en lote', `${items.length} solicitudes · Cliente Grupo AZC`, `<section class="surface"><ul class="intake-list">${items.map(item => { const r = A.requests.find(r => r.id === item.id), p = A.person(item.personId); return `<li><div><strong>${r.hostname} → ${esc(p.name)}</strong><p>${esc(p.company)} · cédula ${esc(p.document || 'sin dato')} · ${r.match === 'document' ? 'Coincidencia de cédula' : 'Asignación manual'}</p></div></li>`; }).join('')}</ul><p class="notice">Se aprobarán para estas personas. No se reiniciarán los computadores. La conexión y el reporte de protección se comprueban después.</p><form id="approve-form" class="intake-form"><label class="switch-label"><input type="checkbox" required>Revisé cada correspondencia equipo–persona.</label><div class="actions"><button type="button" id="back-requests">Volver sin aprobar</button><button class="primary" type="submit">Aprobar los ${items.length} en maqueta</button></div></form></section>`, 'solicitudes');
    $('back-requests').onclick = inbox;
    $('approve-form').onsubmit = event => { event.preventDefault(); const error = A.approve(items); if (error) { feedback(error); return; } message = `${items.length} solicitudes aprobadas; esperando conexión. Ninguna se marca protegida por aprobarla.`; bulkReview = null; inbox(); };
  }
  function replacement(id) {
    const p = A.person(id); if (!p || p.status !== 'active') return missing();
    shell('Reemplazar equipo', p.name, `<form id="replace-form" class="surface intake-form intake-flow"><div class="settings-grid"><label>Equipo que se retira<select id="old-device" required><option value="">Seleccionar</option>${opts(A.owned(id).map(d => d.hostname))}</select></label><label>Nuevo equipo · reserva de IT o ya asignado a esta persona<select id="new-device" required><option value="">Seleccionar</option>${opts(A.replacementCandidates(id).map(d => d.hostname))}</select></label></div><label>Motivo<input id="replace-reason" required maxlength="300" placeholder="Ej.: renovación del portátil"></label><p>Si el nuevo equipo aún no está instalado, prepara el instalador y aprueba su solicitud antes de reemplazar. <a href="#solicitudes">Ver solicitudes</a>.</p><div class="notice"><p>El viejo primero se bloquea y solo se retira al confirmar el bloqueo. Retiro pendiente: se bloqueará cuando vuelva a conectarse. El agente conserva su identidad hasta completar el bloqueo.</p><p>El nuevo recibe la persona, su plan y sus reglas de organización y excepciones. No se copian reglas exclusivas del viejo. Se revisan las reglas propias del nuevo y su reporte antes de declararlo protegido.</p><p>La actividad anterior permanece con su persona y equipo de origen.</p></div><div class="actions"><a class="button" href="${href('persona',id)}">Volver a la ficha</a><button class="primary" type="submit">Revisar reemplazo</button></div></form>`);
    $('replace-form').onsubmit = event => { event.preventDefault(); const old = $('old-device').value, next = $('new-device').value, reason = $('replace-reason').value;
      modal('Confirmar reemplazo', `<p><strong>${old} → ${next}</strong></p><p>${esc(p.name)} · ${esc(reason)}</p><p>El viejo queda pendiente de bloqueo y solo se retira después de confirmarlo, sin borrado. El nuevo quedará pendiente de recibir y confirmar sus reglas.</p>`, 'Reemplazar en maqueta', () => { const error = A.replace(id,old,next,reason); if (error) return error; message = 'Reemplazo preparado. Falta confirmar la protección del nuevo equipo.'; go('persona',id); return ''; });
    };
  }
  function offboard(id) {
    const p = A.person(id); if (!p || p.status !== 'active') return missing();
    const list = A.owned(id);
    shell('Baja de persona', p.name, `<form id="offboard-form" class="surface intake-form"><div class="intake-warning"><p>El acceso al panel se quita en el acto al confirmar la baja. La identidad del agente se conserva hasta completar las acciones. Mientras haya acciones pendientes no se finaliza la baja.</p><p>Bloquear impide usar Windows; no borra archivos. Borrar elimina archivos y perfiles de usuarios de forma irreversible y conserva Windows, Keeper y la cuenta de IT. Reasignar mantiene el equipo activo con otra persona y sus reglas.</p><p>El historial del servidor no se borra con estas acciones: queda sujeto a la retención del cliente. Un equipo sin conexión no confirma bloqueo ni borrado.</p></div>${list.map(d => `<fieldset><legend>${d.hostname} · ${d.state === 'connected' ? 'conectado' : 'sin conexión actual'}</legend><label>Qué hacer<select data-depart-action="${d.hostname}"><option value="lock">Bloquear y después retirar</option><option value="wipe">Borrar datos del equipo y después retirar</option><option value="assign">Reasignar a otra persona activa</option></select></label><div data-depart-fields="${d.hostname}"></div></fieldset>`).join('') || '<p>No tiene equipos activos: podrás finalizar la baja después de confirmar.</p>'}<label>Motivo de la baja<input id="departure-reason" required maxlength="300" placeholder="Ej.: fin de vinculación"></label><label>Escribe RETIRAR ${esc(p.name)}<input id="departure-confirmation" required autocomplete="off"></label><label class="switch-label"><input type="checkbox" id="departure-reauth">Solo maqueta · autenticación reciente para borrado</label><div class="actions"><a class="button" href="${href('persona',id)}">Cancelar</a><button class="primary" type="submit">Revisar baja y equipos</button></div></form>`);
    const draw = (changed = null) => root.querySelectorAll('[data-depart-action]').forEach(select => {
      if (changed && changed !== select) return;
      const holder = [...root.querySelectorAll('[data-depart-fields]')].find(el => el.dataset.departFields === select.dataset.departAction);
      holder.innerHTML = select.value === 'assign' ? `<label>Persona que recibe el equipo<select data-recipient="${select.dataset.departAction}" required>${activeOptions(id)}</select></label>` : select.value === 'wipe' ? `<label>Escribe ${select.dataset.departAction} para autorizar borrado<input data-wipe-confirm="${select.dataset.departAction}" required autocomplete="off"></label><p class="warn-text">Irreversible. El borrado requiere función contratada y confirmación de ejecución antes de retirar.</p>` : '<p>El bloqueo debe quedar confirmado antes de retirar. Si no conecta, queda pendiente.</p>';
    });
    root.querySelectorAll('[data-depart-action]').forEach(s => s.onchange = () => draw(s)); draw();
    $('offboard-form').onsubmit = event => {
      event.preventDefault(); const confirmation = $('departure-confirmation').value, reauth = $('departure-reauth').checked, reason = $('departure-reason').value;
      const choices = [...root.querySelectorAll('[data-depart-action]')].map(s => ({ hostname:s.dataset.departAction, action:s.value, to:root.querySelector(`[data-recipient="${s.dataset.departAction}"]`)?.value || '', confirmation:root.querySelector(`[data-wipe-confirm="${s.dataset.departAction}"]`)?.value || '' }));
      modal('Revisar baja', `<p>${esc(p.name)} · ${esc(reason)}</p><ul>${choices.map(c => `<li>${c.hostname}: ${c.action === 'assign' ? 'reasignar a ' + esc(A.person(c.to)?.name) : c.action === 'wipe' ? 'borrar datos y retirar' : 'bloquear y retirar'}</li>`).join('')}</ul><p>Se revoca el acceso al panel en el acto. El agente conserva su identidad y las acciones siguen pendientes hasta confirmarlas. Su historial se conserva.</p>`, 'Preparar baja en maqueta', () => { const error = A.startDeparture(id,choices,confirmation,reauth); if (error) return error; p.history.push(`Motivo de baja: ${reason}`); message = 'Baja preparada; confirma cada acción antes de finalizar.'; go('persona',id); return ''; });
    };
  }
  function departureStatus(p,job) {
    return `<section class="surface"><h2>Baja pendiente · seguimiento de equipos</h2><ul class="intake-list">${job.actions.map(c => `<li><div><strong>${c.hostname}</strong><p>${c.action === 'assign' ? 'Reasignación' : c.action === 'wipe' ? 'Borrado' : 'Bloqueo'} · ${c.state === 'done' ? 'Confirmado en maqueta' : c.state === 'failed' ? 'Falló: revisar y reintentar' : A.device(c.hostname).state === 'connected' ? 'Pendiente de confirmación' : 'Pendiente; equipo sin conexión'}</p></div>${c.state !== 'done' ? `<div class="actions"><button data-complete="${c.hostname}">Simular ejecución confirmada</button><button data-fail="${c.hostname}">Simular fallo</button></div>` : ''}</li>`).join('') || '<li>Sin equipos por resolver.</li>'}</ul><p>El acceso al panel ya está revocado. Finalizar retira los equipos que no fueron reasignados. No se puede finalizar con una acción fallida o pendiente.</p><button class="primary" id="finish-departure" ${job.actions.some(c => c.state !== 'done') ? 'disabled' : ''}>Finalizar baja en maqueta</button></section>`;
  }
  function wireDeparture(p) {
    root.querySelectorAll('[data-complete]').forEach(b => b.onclick = () => { A.completeAction(p.id,b.dataset.complete); profile(p.id); });
    root.querySelectorAll('[data-fail]').forEach(b => b.onclick = () => { A.completeAction(p.id,b.dataset.fail,false); profile(p.id); });
    $('finish-departure').onclick = () => { const error = A.finishDeparture(p.id); if (error) feedback(error); else { message = 'Persona retirada en la demostración. Historial conservado.'; profile(p.id); } };
  }
  const csvHeader = 'nombre;cedula;correo;empresa;sede;area;cargo;horario;plan';
  const sampleRow = i => `Persona de prueba ${i};${8800000000 + i};persona${i}@example.invalid;AZC Jurídica;Cali · Centro;Litigios;Analista;08:00–17:00 · lunes a viernes;cliente`;
  function importer() {
    shell('Alta masiva de personas', 'Revisa cada fila antes de guardar. La importación crea personas pendientes de instalar; no marca equipos protegidos.', `<section class="surface"><div class="actions"><button id="csv-template">Descargar plantilla CSV</button><button id="csv-example">Cargar ejemplo con errores</button><button id="csv-300">Cargar ejemplo de 300 personas</button></div><form id="csv-form" class="intake-form"><label>Archivo CSV<input type="file" id="csv-file" accept=".csv,text/csv"></label><label>Contenido del CSV · también puedes corregirlo aquí<textarea id="csv-text" spellcheck="false">${esc(csvText)}</textarea></label><p>Separador coma o punto y coma; admite campos entre comillas. Cédula y correo pueden quedar vacíos; empresa, sede, área, cargo, horario y plan deben ser válidos. Usa la plantilla para los nombres de columnas.</p><button class="primary" type="submit">Validar y ver vista previa</button></form></section><section id="csv-preview" class="surface" ${csvPreview || csvResult ? '' : 'hidden'}></section>`, 'importar');
    $('csv-template').onclick = () => download('\uFEFF' + csvHeader + '\r\n' + sampleRow(1) + '\r\n','plantilla-personas-demostracion.csv');
    $('csv-example').onclick = () => { csvText = csvHeader + '\n' + sampleRow(1) + '\n' + sampleRow(2).replace('Cali · Centro','Sede inexistente') + '\n' + sampleRow(1); csvPreview = A.parseCSV(csvText); csvResult = null; importer(); };
    $('csv-300').onclick = () => { csvText = csvHeader + '\n' + Array.from({length:300},(_,i) => (i < 13 ? sampleRow(i + 1).replace('Cali · Centro','Sede por corregir') : sampleRow(i + 1))).join('\n'); csvPreview = A.parseCSV(csvText); csvResult = null; importer(); };
    $('csv-file').onchange = async event => { const file = event.target.files[0]; if (!file) return; csvPreview = null; csvResult = null; $('csv-preview').hidden = true; if (file.size > 2 * 1024 * 1024) { feedback('El archivo supera 2 MB.'); return; } csvText = await file.text(); $('csv-text').value = csvText; feedback('Archivo leído. Valida su contenido antes de guardar.'); };
    $('csv-text').oninput = event => { csvText = event.target.value; csvPreview = null; csvResult = null; $('csv-preview').hidden = true; };
    $('csv-form').onsubmit = event => { event.preventDefault(); csvText = $('csv-text').value; csvPreview = A.parseCSV(csvText); csvResult = null; drawCSV(); };
    drawCSV();
  }
  function drawCSV() {
    const holder = $('csv-preview');
    if (csvResult) {
      holder.hidden = false;
      holder.innerHTML = `<h2>${csvResult.length} personas creadas en maqueta</h2><p>${csvPreview.rows.length} filas quedan para corregir. Las creadas no se reenvían.</p><ul>${csvPreview.rows.map(r => `<li>Fila ${r.line} · ${esc(r.draft.name)}: ${r.errors.map(esc).join(' ')}</li>`).join('')}</ul>${csvPreview.rows.length ? '<button id="retry-csv">Corregir solo filas pendientes</button>' : ''}<ul>${csvResult.map(p => `<li><a href="${href('persona',p.id)}">${esc(p.name)}</a> · No protegido: sin equipo</li>`).join('')}</ul>`;
      if ($('retry-csv')) $('retry-csv').onclick = () => {
        const cell = v => '"' + String(v || '').replaceAll('"','""') + '"';
        csvText = csvHeader + '\n' + csvPreview.rows.map(r => ['name','document','email','company','site','area','job','schedule','plan'].map(k => cell(r.draft[k])).join(';')).join('\n');
        csvResult = null; csvPreview = null; importer();
      };
      return;
    }
    if (!csvPreview) return;
    holder.hidden = false;
    const bad = csvPreview.rows.filter(r => r.errors.length).length;
    holder.innerHTML = `<h2>Vista previa · ninguna fila guardada</h2>${csvPreview.error ? `<p role="alert">${esc(csvPreview.error)}</p>` : `<p>${csvPreview.rows.length} filas · ${bad} con errores · ${csvPreview.rows.length - bad} válidas.</p><p>Se guardan las filas válidas; las inválidas quedan listadas para corregir y reintentar. Si editas el contenido, debes validarlo otra vez.</p><table class="intake-table"><thead><tr><th>Fila</th><th>Persona / cédula</th><th>Ubicación</th><th>Resultado</th></tr></thead><tbody>${csvPreview.rows.map(r => `<tr class="${r.errors.length ? 'bad-row' : ''}"><td data-label="Fila">${r.line}</td><td data-label="Persona">${esc(r.draft.name)}<br>${esc(r.draft.document || 'Sin cédula')}</td><td data-label="Ubicación">${esc(r.draft.company)}<br>${esc(r.draft.site)} / ${esc(r.draft.area)}</td><td data-label="Resultado">${r.errors.length ? r.errors.map(esc).join(' ') : 'Lista para crear · pendiente de equipo'}</td></tr>`).join('')}</tbody></table><form id="import-confirm" class="intake-form"><label class="switch-label"><input type="checkbox" required>Revisé todas las filas y sus asignaciones.</label><button class="primary" type="submit" ${csvPreview.rows.length === bad ? 'disabled' : ''}>Se crearán ${csvPreview.rows.length - bad} personas; ${bad} filas quedan para corregir</button></form>`}`;
    if ($('import-confirm')) $('import-confirm').onsubmit = event => { event.preventDefault(); const result = A.importRows(csvPreview); if (result.errors.length) { feedback(result.errors.join(' ')); return; } csvResult = result.people; csvPreview = { error:'', rows:result.rejected }; drawCSV(); };
  }
  function missing() { shell('Persona no disponible', 'Vuelve al seguimiento para seleccionar una persona activa.', '<a class="button" href="#inicio">Volver al seguimiento</a>'); }
  function render() {
    if (role !== 'superadmin') { root.innerHTML = '<h1>Esta tarea requiere administrar personas y equipos</h1><p>El permiso de órdenes del supervisor no permite dar altas, reasignar ni retirar personas.</p><a href="index.html?role=supervisor">Volver a supervisar</a>'; return; }
    if (params.get('scenario') === 'error') { root.innerHTML = '<h1>No pudimos cargar Personas y equipos</h1><p role="alert">No se guardó ningún cambio. Reintenta para conocer el estado.</p><a class="button" href="personas-equipos.html?role=superadmin">Reintentar</a>'; return; }
    if (params.get('scenario') === 'empty') { root.innerHTML = '<h1>Personas y equipos</h1><p>No hay personas ni solicitudes en este escenario de demostración.</p><a class="button primary" href="personas-equipos.html?role=superadmin#nueva">Probar Nueva persona</a>'; return; }
    const [view = 'inicio', encoded = ''] = location.hash.slice(1).split('/');
    let id; try { id = decodeURIComponent(encoded); } catch { missing(); return; }
    ({ inicio:home, problemas:intakeProblems, nueva:newPerson, solicitudes:inbox, importar:importer, persona:() => profile(id), reemplazar:() => replacement(id), baja:() => offboard(id) }[view || 'inicio'] || home)();
  }
  window.addEventListener('hashchange',render); render();
})();
