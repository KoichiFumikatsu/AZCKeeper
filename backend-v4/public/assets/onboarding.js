import { api } from './api.js';
import { evaluate, count, nextPending, operational, calculatedOperational, failure, retryable, STATUS, STEP_TITLES, FAILURE_TEXT, exactTime } from './onboarding-state.js';

// Puesta en marcha (fase 4): lista de ocho pasos por cliente que lleva a comprobar un primer equipo real. Cada paso
// abre la pantalla existente que administra esos datos (?pm=N muestra «Volver al progreso» allí). Abrir esta pantalla
// solo lee; el avance se recalcula siempre. P1 (F10): lo que decide una persona (piloto, responsable, revisión o
// «Para después» por paso, cierre) se guarda en el servidor (B1) con versión: si otro administrador guardó antes, se
// avisa y se recarga sin pisar su cambio. Si lo guardado no se puede leer, solo se muestra el avance calculado y no se
// ofrece finalizar: nunca se finge un cierre (ni en el navegador).
const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (className) n.className = className; return n; };
const clock = () => new Intl.DateTimeFormat('es-CO', { timeStyle: 'medium' }).format(new Date()).replace(/\.$/, '');
const BANNER_CACHE = 'keeper.onboarding.banner';
const BANNER_HIDDEN = 'keeper.onboarding.hidden';
const BANNER_TTL = 5 * 60 * 1000;
export const POLL_MS = 15000;
const store = {
  get(key) { try { return JSON.parse(sessionStorage.getItem(key)); } catch { return null; } },
  set(key, value) { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch { /* preferencia de sesión: sin almacenamiento no se recuerda */ } },
};

// Banda «Puesta en marcha / Paso N» en la pantalla a la que llevó un paso. pm solo elige el texto; el enlace es fijo.
export function returnBand() {
  const n = Number(new URLSearchParams(location.search).get('pm'));
  const anchor = document.getElementById('page-status');
  if (!Number.isInteger(n) || n < 1 || n > 8 || !anchor || document.querySelector('.onboarding-band')) return;
  const band = el('nav', undefined, 'onboarding-band'); band.setAttribute('aria-label', 'Puesta en marcha');
  band.append(el('span', `Puesta en marcha · Paso ${n}: ${STEP_TITLES[n - 1]}`));
  const back = el('a', 'Volver al progreso', 'button'); back.href = '/puesta-en-marcha.php';
  band.append(back);
  anchor.before(band);
}

function chip(status) { const [text, tone] = STATUS[status] || STATUS.pending; const b = el('span', text, `badge ${tone}`.trim()); b.dataset.status = status; return b; }
function actionLink(a, primary) { const x = el('a', a.label, primary ? 'button primary' : 'link-button'); x.href = a.href; return x; }
function rememberBanner(tenant, result) {
  const next = result.kind === 'ready' ? nextPending(result.steps) : null;
  store.set(BANNER_CACHE, { tenant, at: Date.now(), hidden: result.kind !== 'ready', finished: operational(result),
    next: next ? { n: next.n, title: next.title, summary: next.summary } : null });
}

const AUTHOR = a => a?.kind === 'platform' ? 'AZC Keeper' : a?.name || (a?.kind === 'client' ? 'un administrador del cliente' : 'una cuenta que ya no existe');
const DECISION_TEXT = { reviewed: 'Revisado', deferred: 'Para después' };
// «Para después» solo tiene sentido en lo opcional o aplazable para arrancar (jornada/festivos y protección).
const deferrable = s => s.status !== 'na' && (!s.required || s.n === 3 || s.n === 6);

function stepItem(s, rerender, decide) {
  const li = el('li', undefined, 'onboarding-step'); li.dataset.step = String(s.n); li.dataset.status = s.status;
  const head = el('div', undefined, 'step-head');
  head.append(el('span', String(s.n), 'step-number'), el('h3', s.title), chip(s.status), el('small', s.required ? 'Necesario' : 'No necesario para arrancar', 'help'));
  li.append(head);
  if (s.summary) li.append(el('p', s.summary, 'step-summary'));
  if (s.lines.length) { const ul = el('ul', undefined, 'step-lines'); s.lines.forEach(t => ul.append(el('li', t))); li.append(ul); }
  s.warnings.forEach(w => li.append(el('p', w, 'notice step-warning')));
  const failText = s.failure ? FAILURE_TEXT[s.failure.kind] || FAILURE_TEXT.error : '';
  if (failText && !['not_contracted', 'partial'].includes(s.failure.kind) && s.summary !== failText && !s.lines.some(t => t.endsWith(failText))) li.append(el('p', failText, 'error-text step-failure'));
  if (s.note) li.append(el('p', s.note, 'help'));
  const actions = el('div', undefined, 'step-actions');
  if (s.action) actions.append(actionLink(s.action, true));
  s.secondary.forEach(a => actions.append(actionLink(a, false)));
  if (s.retry) { const r = el('button', 'Reintentar'); r.type = 'button'; r.addEventListener('click', rerender); actions.append(r); }
  if (decide) decisionControls(li, actions, s, decide);
  if (actions.childElementCount) li.append(actions);
  return li;
}

// Decisión humana guardada del paso (B1). No cambia el estado calculado: «Revisado» no es «Hecho» y «Para después» no cuenta.
function decisionControls(li, actions, s, { decision, open, save }) {
  if (decision) {
    const line = el('p', `${DECISION_TEXT[decision.decision]} · ${AUTHOR(decision.decided_by)}, ${exactTime(decision.decided_at)}${decision.note ? ` · «${decision.note}»` : ''}`, 'help step-decision');
    line.dataset.decision = decision.decision;
    li.append(line);
    if (decision.stale) li.append(el('p', 'Se decidió con criterios anteriores a los vigentes: revísalo de nuevo.', 'notice step-warning'));
  }
  if (!open) return;
  const button = (label, value) => { const b = el('button', label); b.type = 'button'; b.dataset.decide = value ?? 'none'; b.addEventListener('click', () => save(s.n, value, b)); actions.append(b); };
  if (decision?.decision === 'reviewed') button('Quitar revisión', null);
  else button('Marcar revisado', 'reviewed');
  if (deferrable(s)) { if (decision?.decision === 'deferred') button('Quitar «Para después»', null); else button('Para después', 'deferred'); }
}

export async function onboardingPage({ tenant, content, status }) {
  let timer = null; let stopped = false;
  const stop = () => { if (timer) clearInterval(timer); timer = null; };
  // Seguimiento del piloto solo mientras la pantalla está visible; se cancela al salir o al cambiar de cliente.
  const onVisibility = () => { if (document.hidden) stop(); else if (!stopped) startPoll?.(); };
  let startPoll = null;
  document.addEventListener('visibilitychange', onVisibility);
  window.addEventListener('pagehide', () => { stopped = true; stop(); });
  window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });

  let flash = null; let busy = false;
  // Escritura de lo guardado: siempre con la versión leída; un 409 de versión recarga sin sobrescribir al otro administrador.
  const write = async (method, path, body, button) => {
    if (busy) return; busy = true; if (button) button.disabled = true;
    try {
      await api(method, path, { body: { version: current.saved.version, ...body } });
      flash = { tone: 'good', text: method === 'POST' && path.endsWith('/close') ? 'Puesta en marcha finalizada y guardada.' : method === 'POST' ? 'Puesta en marcha reabierta.' : 'Guardado.' };
    } catch (e) {
      const detail = e?.problem?.detail || '';
      if (e?.status === 409 && e.code === 'version_conflict') flash = { tone: 'warn', text: 'Otro administrador guardó cambios en la puesta en marcha mientras la tenías abierta. Se recargó lo guardado: revisa y repite si hace falta.' };
      else if (e?.status === 409 && detail === 'onboarding_not_ready') flash = { tone: 'error', text: 'Todavía no se puede finalizar. El servidor comprobó que falta:', items: (e.problem.errors || []).map(x => x.message) };
      else if (e?.status === 409 && detail === 'onboarding_closed') flash = { tone: 'warn', text: 'La puesta en marcha ya está finalizada: reábrela para cambiarla.' };
      else if (e?.status === 409 && detail === 'already_closed') flash = { tone: 'warn', text: 'Otro administrador ya la finalizó.' };
      else if (e?.status === 422 && /^pilot_/.test(detail)) flash = { tone: 'error', text: 'Ese equipo ya no está activo o cambió de persona: elige otro piloto.' };
      else flash = { tone: 'error', text: `No se guardó: ${FAILURE_TEXT[failure(e).kind] || FAILURE_TEXT.error}` };
    } finally { busy = false; }
    await render().catch(show);
  };
  let current = null;
  // Al volver a dibujar (Reintentar, guardar) la línea de estado vuelve a decir cuándo se comprobó, como al abrir.
  const render = async () => { await draw(); if (status.textContent.startsWith('Comprobando')) status.textContent = `Actualizado a las ${new Intl.DateTimeFormat('es-CO', { timeStyle: 'short' }).format(new Date()).replace(/\.$/, '')}.`; };
  const draw = async () => {
    stop(); startPoll = null; content.replaceChildren();
    status.textContent = 'Comprobando la puesta en marcha…';
    const result = await evaluate({ tenant }); current = result;
    rememberBanner(tenant, result);
    if (result.kind === 'platform_tenant') { empty(content, 'Elige un cliente', 'La puesta en marcha es de cada cliente. Elige un cliente en el selector de arriba; la empresa de la plataforma no tiene puesta en marcha.'); return; }
    if (result.kind === 'hidden') { empty(content, 'Sin acceso a la puesta en marcha', 'La preparan los administradores del cliente. Tu panel habitual sigue disponible en el menú.'); return; }
    if (result.kind === 'error') {
      const box = el('div', undefined, 'empty'); box.append(el('h2', 'No se pudo comprobar la puesta en marcha'), el('p', FAILURE_TEXT[result.failure.kind] || FAILURE_TEXT.error));
      const r = el('button', 'Reintentar', 'primary'); r.type = 'button'; r.addEventListener('click', () => render().catch(show)); box.append(r); content.append(box); return;
    }
    const top = el('section', undefined, 'surface onboarding-head');
    top.append(el('h2', 'Prepara tu primer equipo'));
    if (result.tenant) top.append(el('p', `Cliente: ${result.tenant.branding?.display_name || result.tenant.name}`, 'onboarding-client'));
    const counter = el('p', '', 'onboarding-count'); counter.setAttribute('role', 'status'); top.append(counter);
    const naNote = el('p', '', 'help'); top.append(naNote);
    top.append(el('p', `Comprobado con los datos guardados a las ${clock()}. Es un orden orientador: puedes salir, trabajar desde el menú y volver; el avance se vuelve a calcular.`, 'help'));
    const finished = el('div', undefined, 'notice onboarding-finished'); finished.hidden = true; top.append(finished);
    content.append(top);
    const sharedBox = sharedPanel(result, { write, flash, retry: () => render().catch(show) });
    flash = null;
    content.append(sharedBox);
    const updateSummary = () => {
      const c = count(result.steps);
      counter.textContent = `${c.done} de ${c.total} pasos necesarios para el primer equipo listos${c.optional ? ` · ${c.optional} no necesario${c.optional > 1 ? 's' : ''} para arrancar` : ''}.`;
      naNote.textContent = c.na.length ? `No aplican (funciones no contratadas): ${c.na.join(', ')}.` : '';
      naNote.hidden = !c.na.length;
      const done = c.done === c.total && result.steps[7].status === 'done';
      finished.replaceChildren();
      finished.hidden = !done;
      if (done) {
        finished.append(el('strong', 'Primer equipo operativo'), el('p', 'El primer equipo reporta con su persona y los pasos necesarios están hechos. Esto no certifica la flota completa: sigue instalando por sede.'));
        const more = el('a', 'Instalar en más equipos', 'button primary'); more.href = '/alta.php';
        const list = el('a', 'Ver Equipos', 'link-button'); list.href = '/equipos.php';
        const row = el('div', undefined, 'step-actions'); row.append(more, list); finished.append(row);
      }
    };
    const ol = el('ol', undefined, 'onboarding-steps');
    const saved = result.saved;
    const decisions = new Map((saved?.steps || []).map(d => [d.step, d]));
    const decide = saved ? n => ({ decision: decisions.get(n) || null, open: saved.status === 'open',
      save: (step, value, button) => write('PATCH', '/onboarding', { steps: [{ step, decision: value }] }, button) }) : () => null;
    const items = result.steps.map(s => stepItem(s, () => render().catch(show), decide(s.n)));
    ol.append(...items); content.append(ol);
    if (result.pilot) startPoll = pilotCheck(result, items[7], updateSummary, fn => { stop(); if (!stopped && !document.hidden) timer = setInterval(fn, POLL_MS); }, stop);
    updateSummary();

    const more = el('details', undefined, 'surface onboarding-more');
    more.append(el('summary', 'Otras funciones y límites de esta comprobación'));
    if (result.others.length) more.append(el('p', `Otras funciones contratadas, opcionales al arranque: ${result.others.join(', ')}. Si se usarán, revisa sus dependencias en su pantalla.`));
    if (result.pendingFeatures.length) more.append(el('p', `${result.pendingFeatures.join(' y ')}: contratadas · pendientes de implementación. No cuentan en el avance.`));
    const limits = el('ul', undefined, 'step-lines');
    ['El avance es del primer equipo; no representa toda la flota.',
      result.saved ? 'El avance se calcula con los datos actuales cada vez que abres esta pantalla. Solo se guardan, compartidos con los demás administradores, el piloto, el responsable, las revisiones por paso y el cierre.'
        : 'Se calcula con los datos guardados cada vez que abres esta pantalla. Lo que decide una persona (piloto, revisiones, cierre) no se pudo leer ahora: no hay cierre registrado desde esta pantalla.',
      '«Revisado» y «Para después» son decisiones de una persona: no convierten un paso en Hecho.',
      'Descargar el instalador, aprobar una solicitud o guardar una regla no cuentan como instalación ni como comprobación.',
      'Que un dato no esté disponible para tu cuenta no se cuenta como cero ni como hecho.']
      .forEach(t => limits.append(el('li', t)));
    more.append(limits); content.append(more);
  };
  const show = error => { status.textContent = ''; const p = document.getElementById('page-error'); if (p) { p.textContent = FAILURE_TEXT[failure(error).kind] || String(error?.message || error); p.hidden = false; } };
  await render();
}

// Lo guardado (B1): piloto, responsable, cierre. Sin poder leerlo, solo el avance calculado y sin «Finalizar».
function sharedPanel(result, { write, flash, retry }) {
  const box = el('section', undefined, 'surface onboarding-shared'); box.setAttribute('aria-labelledby', 'onboarding-shared-title');
  const h = el('h2', 'Lo que se guarda'); h.id = 'onboarding-shared-title'; box.append(h);
  if (flash) {
    const n = el('div', undefined, `notice onboarding-flash ${flash.tone}`); n.setAttribute('role', flash.tone === 'good' ? 'status' : 'alert');
    n.append(el('p', flash.text));
    if (flash.items?.length) { const ul = el('ul'); flash.items.forEach(t => ul.append(el('li', t))); n.append(ul); }
    box.append(n);
  }
  const saved = result.saved;
  if (!saved) {
    const f = result.savedFailure;
    box.append(el('p', f?.kind === 'denied' || f?.kind === 'scope' ? 'El piloto elegido, las revisiones y el cierre compartido no están disponibles para tu cuenta.'
      : `No se pudo leer lo guardado (piloto, revisiones y cierre): ${FAILURE_TEXT[f?.kind] || FAILURE_TEXT.error}`, 'notice onboarding-unsaved'));
    box.append(el('p', 'Se muestra solo el avance calculado con los datos actuales. No se puede finalizar la puesta en marcha ni guardar decisiones hasta poder leerlo.', 'help'));
    if (retryable(f)) { const r = el('button', 'Reintentar'); r.type = 'button'; r.addEventListener('click', retry); box.append(r); }
    return box;
  }
  const sp = saved.pilot;
  if (saved.status === 'closed') {
    const done = el('div', undefined, 'onboarding-closed');
    done.append(el('strong', 'Puesta en marcha finalizada'),
      el('p', `Primer equipo operativo registrado el ${exactTime(saved.closed.at)} por ${AUTHOR(saved.closed.by)}. No certifica la flota completa: sigue instalando por sede.`));
    if (saved.closed.criteria_version < saved.criteria_version) done.append(el('p', 'Se finalizó con criterios anteriores a los vigentes: conviene revisarla.', 'notice'));
    if (!calculatedOperational(result)) done.append(el('p', 'La evidencia calculada cambió desde el cierre: revisa los pasos que ya no están hechos. La fecha del cierre se conserva.', 'notice step-warning'));
    box.append(done);
  } else if (saved.reopened) {
    box.append(el('p', `Reabierta el ${exactTime(saved.reopened.at)} por ${AUTHOR(saved.reopened.by)}${saved.reopened.reason ? ` · «${saved.reopened.reason}»` : ''}.${saved.closed ? ` El cierre anterior (${exactTime(saved.closed.at)}) se conserva.` : ''}`, 'help'));
  }
  const open = saved.status === 'open';

  // Piloto
  const pilot = el('div', undefined, 'onboarding-field onboarding-pilot');
  pilot.append(el('h3', 'Piloto'));
  pilot.append(el('p', sp ? `${sp.display_name || 'Persona del piloto'}${sp.device_id ? ` · ${sp.hostname || 'equipo elegido'}` : ' · sin equipo todavía'} — elegido por ${AUTHOR(sp.selected_by)}, ${exactTime(sp.selected_at)} · la comprobación de conexión cuenta desde esa hora.`
    : 'Sin piloto guardado: el paso 8 usa el equipo asignado que reportó más recientemente hasta que elijas uno.'));
  if (result.pilotLost) pilot.append(el('p', 'El equipo del piloto guardado ya no está activo o cambió de persona.', 'notice step-warning'));
  if (open) {
    if (!result.canChoosePilot) pilot.append(el('p', 'Elegir el piloto requiere ver personas y equipos de todo el cliente.', 'help'));
    else if (!result.candidates.length) pilot.append(el('p', 'Aún no hay equipos activos asignados a una persona activa: instala Keeper (paso 7) para elegir el piloto.', 'help'));
    else {
      const row = el('div', undefined, 'onboarding-row');
      const label = el('label', 'Equipo del piloto'); const select = el('select'); select.id = 'onboarding-pilot-select'; label.htmlFor = select.id;
      const chosen = sp?.device_id || result.pilot?.device?.id || '';
      for (const c of result.candidates) { const o = el('option', `${c.hostname} · ${c.user_name || 'persona asignada'}`); o.value = c.id; o.selected = c.id === chosen; select.append(o); }
      const save = el('button', sp?.device_id ? 'Cambiar piloto' : 'Guardar piloto', sp?.device_id ? '' : 'primary'); save.type = 'button';
      save.addEventListener('click', () => {
        const c = result.candidates.find(x => x.id === select.value); if (!c) return;
        if (sp?.device_id === c.id && sp.user_id === c.user_id) return;
        write('PATCH', '/onboarding', { pilot: { user_id: c.user_id, device_id: c.id } }, save);
      });
      row.append(label, select, save); pilot.append(row);
    }
  }
  box.append(pilot);

  // Responsable
  const resp = el('div', undefined, 'onboarding-field onboarding-responsible');
  resp.append(el('h3', 'Responsable'));
  const r = saved.responsible;
  resp.append(el('p', r ? (r.kind === 'platform' ? 'AZC Keeper (plataforma).' : `${r.display_name || 'Una persona del cliente'}.`) : 'Sin definir.'));
  if (open && result.canChooseResponsible) {
    const row = el('div', undefined, 'onboarding-row');
    const label = el('label', 'Quién la lleva'); const select = el('select'); select.id = 'onboarding-responsible-select'; label.htmlFor = select.id;
    const opt = (text, value) => { const o = el('option', text); o.value = value; select.append(o); return o; };
    opt('Sin definir', ''); opt('AZC Keeper (plataforma)', 'platform');
    const people = result.people.slice(0, 500);
    for (const p of people) opt(p.name, p.id);
    select.value = r ? (r.kind === 'platform' ? 'platform' : r.user_id) : '';
    const save = el('button', 'Guardar responsable'); save.type = 'button';
    save.addEventListener('click', () => {
      const v = select.value;
      write('PATCH', '/onboarding', { responsible: v === '' ? null : v === 'platform' ? { kind: 'platform' } : { kind: 'person', user_id: v } }, save);
    });
    row.append(label, select, save); resp.append(row);
    if (result.people.length > people.length) resp.append(el('p', `Se listan las primeras ${people.length} personas activas.`, 'help'));
  }
  box.append(resp);

  // Finalizar / reabrir
  const end = el('div', undefined, 'onboarding-field onboarding-close');
  if (open) {
    end.append(el('h3', 'Finalizar puesta en marcha'));
    end.append(el('p', 'Al finalizar, el servidor vuelve a comprobar: piloto guardado con persona activa y su equipo, que reportó después de elegirlo; un administrador del cliente habilitado; jornada si Presencia está contratada (o «Para después») y Protección revisada si el endurecimiento es automático. Declara «Primer equipo operativo», no la flota completa.', 'help'));
    if (!sp?.device_id) end.append(el('p', 'Primero guarda el piloto con su equipo.', 'help'));
    const fin = el('button', 'Finalizar puesta en marcha', calculatedOperational(result) && sp?.device_id ? 'primary' : ''); fin.type = 'button';
    fin.addEventListener('click', () => write('POST', '/onboarding/close', {}, fin));
    end.append(fin);
  } else {
    end.append(el('h3', 'Revisar después del cierre'));
    end.append(el('p', 'Nuevas personas o sedes no reinician la puesta en marcha. Reábrela solo para revisar o cambiar el piloto, y vuelve a finalizarla.', 'help'));
    const row = el('div', undefined, 'onboarding-row');
    const label = el('label', 'Motivo (opcional)'); const reason = el('input'); reason.id = 'onboarding-reopen-reason'; reason.maxLength = 300; label.htmlFor = reason.id;
    const again = el('button', 'Reabrir para revisar'); again.type = 'button';
    again.addEventListener('click', () => write('POST', '/onboarding/reopen', { reason: reason.value.trim() || null }, again));
    row.append(label, reason, again); end.append(row);
    const more = el('a', 'Instalar en más equipos', 'button'); more.href = '/alta.php';
    const list = el('a', 'Ver Equipos', 'link-button'); list.href = '/equipos.php';
    const links = el('div', undefined, 'step-actions'); links.append(more, list); end.append(links);
  }
  box.append(end);
  return box;
}

function empty(parent, title, text) { const box = el('div', undefined, 'empty'); box.append(el('h2', title), el('p', text)); parent.append(box); }

// Paso 8: el equipo debe estar enrolado (no solo aprobado), asignado a la persona y con last_seen_at que avanza entre la
// lectura inicial y una posterior. Endurecido solo si el equipo lo reportó.
function pilotCheck(result, li, updateSummary, schedule, stop) {
  const pilot = result.pilot; const s8 = result.steps[7];
  let baseline = pilot.device.last_seen_at || null; let proven = false;
  const box = el('div', undefined, 'pilot-check');
  const reading = el('p', `Lectura inicial: ${exactTime(baseline)}. Se vuelve a consultar mientras esta pantalla esté abierta.`, 'help'); reading.setAttribute('role', 'status');
  const hardened = el('p', '', 'help pilot-hardening'); hardened.hidden = true;
  const now = el('button', 'Comprobar conexión ahora', 'primary'); now.type = 'button';
  box.append(reading, hardened, now);
  const actions = li.querySelector('.step-actions');
  if (actions) li.insertBefore(box, actions); else li.append(box);
  const setStatus = (status, summary) => {
    s8.status = status; li.dataset.status = status;
    li.querySelector('.step-head .badge').replaceWith(chip(status));
    li.querySelector('.step-summary').textContent = summary;
    updateSummary();
  };
  const readHardening = async () => {
    if (!result.canHardening) return;
    hardened.hidden = false;
    try {
      const h = await api('GET', `/admin/devices/${pilot.device.id}/hardening`);
      hardened.textContent = h.state === 'hardened' && h.reported_at ? `Endurecido · reportado por el equipo ${exactTime(h.reported_at)}.`
        : `Endurecimiento: ${h.state === 'none' ? 'sin endurecer' : h.state}${h.reported_at ? ` (último reporte ${exactTime(h.reported_at)})` : ' (sin reporte del equipo)'}. Una orden aceptada no cuenta como endurecido.`;
    } catch (e) { hardened.textContent = `Endurecimiento: ${FAILURE_TEXT[failure(e).kind] || FAILURE_TEXT.error}`; }
  };
  const read = async () => {
    try {
      const d = await api('GET', `/devices/${pilot.device.id}`);
      if (d.status !== 'active' || d.user_id !== pilot.device.user_id) {
        stop(); setStatus('pending', 'El equipo ya no está activo o cambió de persona: vuelve a comprobar la instalación.'); return;
      }
      const advanced = d.last_seen_at && (!baseline || Date.parse(d.last_seen_at) > Date.parse(baseline));
      if (advanced) {
        proven = true; stop(); now.hidden = true;
        reading.textContent = `Lectura inicial: ${exactTime(baseline)} · nueva conexión: ${exactTime(d.last_seen_at)}.`;
        setStatus('done', `Comunicación comprobada: el equipo reportó a las ${exactTime(d.last_seen_at)}, después de iniciar la comprobación.`);
        await readHardening();
        return;
      }
      reading.textContent = `Lectura inicial: ${exactTime(baseline)} · última lectura a las ${clock()}: sin conexión nueva todavía.`;
      if (!baseline && d.last_seen_at) baseline = d.last_seen_at;
    } catch (e) {
      reading.textContent = `Última lectura a las ${clock()}: ${FAILURE_TEXT[failure(e).kind] || FAILURE_TEXT.error} Se reintentará.`;
    }
  };
  now.addEventListener('click', async () => { now.disabled = true; try { await read(); } finally { now.disabled = false; } });
  readHardening();
  const start = () => { if (!proven) schedule(read); };
  start();
  return start;
}

// Aviso de Inicio para administradores mientras no haya un primer equipo operativo. No redirige ni bloquea nada.
export async function onboardingBanner({ tenant, content }) {
  const hidden = store.get(BANNER_HIDDEN);
  if (hidden?.[tenant]) return;
  let info = store.get(BANNER_CACHE);
  if (!info || info.tenant !== tenant || !(Date.now() - info.at < BANNER_TTL)) {
    let result;
    // Es una ayuda: si no se puede evaluar no se muestra (la pantalla Puesta en marcha explica cada error).
    try { result = await evaluate({ tenant }); } catch { return; }
    if (result.kind === 'error') return;
    rememberBanner(tenant, result); info = store.get(BANNER_CACHE) || { hidden: result.kind !== 'ready', finished: operational(result), next: null };
  }
  if (info.hidden || info.finished || !content.isConnected || content.querySelector('.onboarding-banner')) return;
  const box = el('section', undefined, 'notice onboarding-banner'); box.setAttribute('aria-label', 'Puesta en marcha');
  box.append(el('strong', 'Prepara tu primer equipo'));
  box.append(el('p', info.next ? `Siguiente: paso ${info.next.n} · ${info.next.title}. ${info.next.summary}` : 'Falta comprobar el primer equipo.'));
  const row = el('div', undefined, 'step-actions');
  const go = el('a', 'Continuar puesta en marcha', 'button primary'); go.href = '/puesta-en-marcha.php';
  const hide = el('button', 'Ocultar por ahora'); hide.type = 'button';
  hide.addEventListener('click', () => { store.set(BANNER_HIDDEN, { ...(store.get(BANNER_HIDDEN) || {}), [tenant]: true }); box.remove(); });
  row.append(go, hide); box.append(row);
  content.prepend(box);
}

// Etiquetas de Auditoría de la puesta en marcha (k3.js las suma a AUDIT_ACTIONS; la guarda de ops.php las exige).
export const AUDIT_LABELS = { patchOnboarding: 'Editó la puesta en marcha', closeOnboarding: 'Finalizó la puesta en marcha', reopenOnboarding: 'Reabrió la puesta en marcha' };
