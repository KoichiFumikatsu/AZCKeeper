import { api } from './api.js';
import { evaluate, count, nextPending, operational, failure, STATUS, STEP_TITLES, FAILURE_TEXT, exactTime } from './onboarding-state.js';

// Puesta en marcha (fase 4, P0): lista de ocho pasos por cliente que lleva a comprobar un primer equipo real. Cada paso
// abre la pantalla existente que administra esos datos (?pm=N muestra «Volver al progreso» allí). Abrir esta pantalla
// solo lee; el avance se recalcula al volver y no se guarda (cierre compartido requiere backend, B1).
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

function stepItem(s, rerender) {
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
  if (actions.childElementCount) li.append(actions);
  return li;
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

  const render = async () => {
    stop(); startPoll = null; content.replaceChildren();
    status.textContent = 'Comprobando la puesta en marcha…';
    const result = await evaluate({ tenant });
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
    const items = result.steps.map(s => stepItem(s, () => render().catch(show)));
    ol.append(...items); content.append(ol);
    if (result.pilot) startPoll = pilotCheck(result, items[7], updateSummary, fn => { stop(); if (!stopped && !document.hidden) timer = setInterval(fn, POLL_MS); }, stop);
    updateSummary();

    const more = el('details', undefined, 'surface onboarding-more');
    more.append(el('summary', 'Otras funciones y límites de esta comprobación'));
    if (result.others.length) more.append(el('p', `Otras funciones contratadas, opcionales al arranque: ${result.others.join(', ')}. Si se usarán, revisa sus dependencias en su pantalla.`));
    if (result.pendingFeatures.length) more.append(el('p', `${result.pendingFeatures.join(' y ')}: contratadas · pendientes de implementación. No cuentan en el avance.`));
    const limits = el('ul', undefined, 'step-lines');
    ['El avance es del primer equipo; no representa toda la flota.',
      'Se calcula con los datos guardados cada vez que abres esta pantalla. No se guarda ni se comparte entre administradores, y no hay cierre registrado.',
      'Descargar el instalador, aprobar una solicitud o guardar una regla no cuentan como instalación ni como comprobación.',
      'Que un dato no esté disponible para tu cuenta no se cuenta como cero ni como hecho.']
      .forEach(t => limits.append(el('li', t)));
    more.append(limits); content.append(more);
  };
  const show = error => { status.textContent = ''; const p = document.getElementById('page-error'); if (p) { p.textContent = FAILURE_TEXT[failure(error).kind] || String(error?.message || error); p.hidden = false; } };
  await render();
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
