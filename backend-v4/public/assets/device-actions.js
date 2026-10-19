import { api, errorMessage } from './api.js';
import { wireDialog } from './dialogs.js';
import { withReauth, canceled } from './hardening-settings.js';
import { codeText, plainCode } from './result-codes.js';
const node = (tag, text, cls) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
// Acciones remotas (POST /devices/{id}/commands). El 202 solo encola: el agente la recibe en su próxima sincronización.
// Soporte del agente (client-v4 CommandExecutor): lock/unlock/restart/shutdown/wipe desde 4.0.0. refresh_policy no se
// ofrece: cada sincronización ya trae la versión de las reglas y el agente las aplica en ese mismo ciclo, que es el mismo
// en que recibiría la orden, así que no adelantaría nada.
// Bloquear no admite mensaje ni PIN desde el panel: CommandParameters solo tiene computer_name/restart_now/admin_name.
// Reiniciar y Apagar admiten aviso previo (notice_seconds 60/300/600) desde el agente 4.0.17; «Inmediato» no manda
// parámetros, así que sigue funcionando con cualquier agente.
const NOTICE_AGENT = '4.0.17';
const noticeChoices = [[60, '1 minuto'], [300, '5 minutos'], [600, '10 minutos']];
const remoteActions = [
  { type: 'lock', label: 'Bloquear equipo', permission: 'equipos.bloquear', feature: 'comandos', agent: '4.0.0', reason: 'Bloqueo desde el panel', verb: 'Bloquear',
    effect: 'Aparece una pantalla «Equipo bloqueado por IT» sobre todas las pantallas y la persona no puede usar el equipo hasta que lo desbloquees desde aquí. Sus programas siguen abiertos. Si nadie tiene la sesión abierta, el bloqueo aparece al iniciar sesión.' },
  { type: 'unlock', label: 'Desbloquear', permission: 'equipos.desbloquear', feature: 'comandos', agent: '4.0.0', reason: 'Desbloqueo desde el panel', verb: 'Desbloquear',
    effect: 'Se quita la pantalla de bloqueo y la persona puede volver a usar el equipo.' },
  { type: 'restart', label: 'Reiniciar', permission: 'equipos.reiniciar', feature: 'comandos', agent: '4.0.0', reason: 'Reinicio desde el panel', verb: 'Reiniciar',
    effect: 'Windows se reinicia de inmediato, sin aviso ni cuenta regresiva. La persona perderá lo que no haya guardado.',
    noticeEffect: m => `La persona verá en su pantalla «El equipo se reiniciará en ${m} por orden de IT. Guarda tu trabajo.» con una cuenta regresiva, sin opción de posponer ni cancelar por su parte. Al terminar, Windows se reinicia. La cuenta empieza cuando el equipo recibe la orden.` },
  { type: 'shutdown', label: 'Apagar', permission: 'equipos.apagar', feature: 'comandos', agent: '4.0.0', reason: 'Apagado desde el panel', verb: 'Apagar',
    effect: 'El equipo se apaga de inmediato, sin aviso. La persona perderá lo que no haya guardado y alguien tendrá que encenderlo a mano: no se puede encender desde el panel.',
    noticeEffect: m => `La persona verá en su pantalla «El equipo se apagará en ${m} por orden de IT. Guarda tu trabajo.» con una cuenta regresiva, sin opción de posponer ni cancelar por su parte. Al terminar, el equipo se apaga y alguien tendrá que encenderlo a mano: no se puede encender desde el panel. La cuenta empieza cuando el equipo recibe la orden.` },
];
const wipeAction = { type: 'wipe', label: 'Borrado remoto', permission: 'equipos.borrar', feature: 'borrado', agent: '4.0.0', reason: 'Borrado remoto por robo o pérdida', verb: 'Borrar',
  effect: 'Se borran los archivos de todas las personas del equipo (Documentos, Escritorio, Descargas, correo y configuración de sus cuentas de Windows) y se sobrescribe el espacio libre para que no se puedan recuperar. Se conservan Windows, Keeper y la cuenta de IT. Solo funciona si el equipo se enciende y se conecta: no protege un disco que ya sacaron del equipo.' };
const PLAN_REASON = 'No incluido en el plan de esta persona';
const featureNames = { comandos: 'Comandos remotos', borrado: 'Borrado remoto' };
export const commandTypes = { lock: 'Bloquear', unlock: 'Desbloquear', restart: 'Reiniciar', shutdown: 'Apagar', wipe: 'Borrado remoto', refresh_policy: 'Aplicar reglas', rename_computer: 'Cambiar nombre', harden: 'Endurecer', unharden: 'Revertir endurecimiento' };
export const commandStates = { pending: ['Pendiente', 'info'], delivered: ['Entregado', 'info'], running: ['En curso', 'warn'], succeeded: ['Ejecutado', 'good'], failed: ['Falló', 'bad'], expired: ['Vencido', 'warn'], cancelled: ['Cancelada', ''] };
// Cancelar una orden (POST /devices/{id}/commands/{commandId}/cancel, 2026-10-19). Pendiente = el equipo aún no la
// recibió: se cancela y no le llega. Reinicio/apagado con aviso ya entregado = cuenta regresiva en curso: el equipo la
// detiene en su próxima sincronización (agente 4.0.27 o posterior). Mismo permiso y plan que para enviarla.
export const CANCEL_AGENT = '4.0.27';
const OLD_AGENT_CANCEL = 'Este equipo tiene una versión que no permite cancelar una cuenta regresiva ya iniciada.';
const cancelPermissions = { lock: 'equipos.bloquear', unlock: 'equipos.desbloquear', restart: 'equipos.reiniciar', shutdown: 'equipos.apagar', wipe: 'equipos.borrar', refresh_policy: 'reglas.aplicar', rename_computer: 'equipos.editar', harden: 'hardening.gestionar', unharden: 'hardening.gestionar' };
const cancelNouns = { restart: 'el reinicio', shutdown: 'el apagado', lock: 'el bloqueo', unlock: 'el desbloqueo', wipe: 'el borrado remoto', rename_computer: 'el cambio de nombre', refresh_policy: 'la orden', harden: 'el endurecimiento', unharden: 'la reversión del endurecimiento' };
const isOpen = r => ['pending', 'delivered', 'running'].includes(r.status);
const countdown = r => ['restart', 'shutdown'].includes(r.type) && Number(r.parameters?.notice_seconds) > 0;
const hhmm = at => new Intl.DateTimeFormat('es-CO', { timeStyle: 'short' }).format(new Date(at));
const sentence = text => text.endsWith('.') ? text : `${text}.`;
/**
 * Estado de cancelación de una orden del historial:
 * { kind: 'cancellable', countdown } | { kind: 'old_agent', note } | { kind: 'cancelling' } | { kind: 'cancelled' } |
 * { kind: 'too_late' } | null (nada que mostrar). access = GET /me/access (null: decide la API).
 */
export function cancelState(r, d, access, now = Date.now()) {
  if (r.status === 'cancelled' && r.cancelled_at) return { kind: 'cancelled' };
  if (r.cancel_requested_at && isOpen(r)) return { kind: 'cancelling' };
  if (r.cancel_requested_at && r.status === 'succeeded') return { kind: 'too_late' };
  if (!isOpen(r) || Date.parse(r.expires_at) <= now) return null;
  if (r.status !== 'pending' && !countdown(r)) return null;
  const permission = cancelPermissions[r.type];
  if (access && !access.platform && !access.permissions.some(p => p.code === permission)) return null;
  if (Array.isArray(d.allowed_commands) && ['lock', 'unlock', 'restart', 'shutdown', 'wipe'].includes(r.type) && !d.allowed_commands.includes(r.type)) return null;
  if (r.status !== 'pending' && versionBelow(d.agent_version, CANCEL_AGENT)) return { kind: 'old_agent', note: OLD_AGENT_CANCEL };
  return { kind: 'cancellable', countdown: r.status !== 'pending' };
}
export function cancelError(error) {
  const detail = String(error.problem?.detail || '').replace(/\.$/, '');
  const lower = detail ? detail.charAt(0).toLowerCase() + detail.slice(1) : '';
  if (error.code === 'command_already_executed') return new Error(`No se pudo cancelar: ya se ejecutó${/ a las \d/.test(lower) ? lower.replace(/^la orden ya se ejecutó/, '') : ''}.`);
  if (error.code === 'command_executes_on_receipt') return new Error('No se pudo cancelar: el equipo ya recibió la orden y la ejecuta al recibirla.');
  if (error.code === 'command_expired') return new Error('No se pudo cancelar: la orden ya venció.');
  if (error.code === 'command_already_cancelled') return new Error('La orden ya estaba cancelada.');
  if (error.code === 'agent_too_old') return new Error(OLD_AGENT_CANCEL);
  if (error.code === 'feature_not_in_plan') return new Error(`${PLAN_REASON}.`);
  if (error.code === 'reauth_required') return new Error('Confirma tu contraseña para cancelar el borrado.');
  if (error.status === 403 && !error.problem?.detail) return new Error('Tu cuenta no tiene permiso para cancelar esta orden.');
  if (error.status === 404) return new Error('La orden no existe o el equipo está fuera de tu alcance.');
  return error;
}
// Insignia del estado con la cancelación: «Cancelando…», «Cancelada» o «No se pudo cancelar: ya se ejecutó».
export function cancelBadge(r, state) {
  if (state?.kind === 'cancelling') return { text: 'Cancelando…', tone: 'info', note: 'El equipo detiene la cuenta regresiva en su próxima sincronización (unos 2 minutos).' };
  if (state?.kind === 'cancelled') return { text: 'Cancelada', tone: '', note: sentence(`IT la canceló a las ${hhmm(r.cancelled_at)}`) };
  if (state?.kind === 'too_late') return { text: 'No se pudo cancelar: ya se ejecutó', tone: 'bad', note: r.result?.completed_at ? sentence(`Se ejecutó a las ${hhmm(r.result.completed_at)}`) : '' };
  return null;
}
// Confirmación de «Cancelar»: qué pasa en lenguaje simple y motivo opcional (va a la auditoría).
export function openCancelCommand(d, r, { onDone }) {
  const noun = cancelNouns[r.type] || 'la orden';
  const dialog = node('dialog', undefined, 'remote-dialog cancel-dialog'); const form = node('form'); form.noValidate = true;
  form.append(node('h2', `¿Cancelar ${noun} de ${d.hostname}?`));
  const body = node('div', undefined, 'dialog-body'); form.append(body);
  const verb = r.type === 'restart' ? 'reinicia' : 'apaga';
  body.append(node('p', countdown(r) && r.status !== 'pending'
    ? `El equipo ya está mostrando la cuenta regresiva. En su próxima sincronización (unos 2 minutos) la detiene y la persona verá «IT canceló ${r.type === 'restart' ? 'el reinicio' : 'el apagado'}». Si la cuenta termina antes, el equipo se ${verb} igual.`
    : 'El equipo aún no recibió la orden: se cancela ahora y no le llegará.'));
  const reasonLabel = node('label', 'Motivo (opcional, queda en la auditoría)'); const reason = node('input'); reason.name = 'reason'; reason.maxLength = 500; reason.autocomplete = 'off'; reasonLabel.append(reason); body.append(reasonLabel);
  const error = node('p', '', 'error-text'); error.hidden = true; error.setAttribute('role', 'alert');
  const footer = node('div', undefined, 'dialog-footer'); const back = node('button', 'Volver'); back.type = 'button';
  const ok = node('button', 'Cancelar la orden', 'danger'); ok.type = 'submit';
  footer.append(back, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
  wireDialog(dialog, { error });
  back.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => dialog.remove());
  form.addEventListener('submit', async event => {
    event.preventDefault(); error.hidden = true; ok.disabled = true; back.disabled = true;
    const text = reason.value.trim();
    const send = () => api('POST', `/devices/${d.id}/commands/${r.id}/cancel`, { body: text ? { reason: text } : {}, idempotencyKey: crypto.randomUUID() });
    try {
      const result = r.type === 'wipe' ? await withReauth(send, `Para cancelar el borrado de ${d.hostname}, confirma la contraseña con la que entras al panel.`) : await send();
      if (result === canceled) { error.textContent = 'No se canceló: falta confirmar tu contraseña.'; error.hidden = false; return; }
      dialog.close(); onDone(result, null);
    } catch (e) {
      const shown = cancelError(e);
      // Ya ejecutada, vencida o cancelada: el historial cambió; se cierra y el aviso queda junto a la tabla.
      if (e.status === 409) { dialog.close(); onDone(null, shown); return; }
      error.textContent = errorMessage(shown); error.hidden = false;
    } finally { if (dialog.open) { ok.disabled = false; back.disabled = false; } }
  });
  dialog.showModal();
  reason.focus();
}
// Cambio de nombre de Windows (GET /devices → computer_rename). Windows solo aplica el nombre al reiniciar: sin esto el
// panel no mostraba nada y parecía que había fallado. null = no hay cambio en curso o el nombre ya coincide.
export function renameState(d) {
  const r = d?.computer_rename;
  if (!r) return null;
  if (r.state === 'pending_restart') return { kind: r.state, name: r.computer_name, text: `Nombre nuevo: ${r.computer_name} — pendiente de reinicio`, short: 'Pendiente de reinicio', tone: 'warn', code: r.result_code,
    note: r.restart_pending ? 'Ya se envió un reinicio: el nombre se aplica cuando el equipo se reinicie.' : 'Windows aplica el nombre nuevo cuando el equipo se reinicia. Hasta entonces sigue con el nombre actual.' };
  if (r.state === 'renaming') return { kind: r.state, name: r.computer_name, text: 'Cambiando nombre…', short: 'Cambiando nombre…', tone: 'info', code: null,
    note: `A ${r.computer_name}. El equipo recibe la orden en su próxima sincronización (unos 2 minutos) si está encendido.${r.restart_pending ? ' Después se reinicia con aviso para aplicarlo.' : ' El nombre se aplica al reiniciar.'}` };
  const reason = codeText(r.result_code) || r.result_code || 'sin motivo informado';
  return { kind: 'failed', name: r.computer_name, text: `No se pudo cambiar el nombre: ${reason.charAt(0).toLowerCase()}${reason.slice(1)}`, short: 'No se pudo cambiar el nombre', tone: 'bad', code: r.result_code,
    note: `Nombre pedido: ${r.computer_name}. Puedes volver a pedirlo en «Cambiar nombre del equipo».` };
}
// Línea corta para listas (Equipos): insignia + texto, con el código técnico en title.
export function renameNote(d) {
  const s = renameState(d);
  if (!s) return null;
  const box = node('small', undefined, 'rename-note'); box.dataset.rename = s.kind;
  box.append(node('span', s.kind === 'renaming' ? `Cambiando nombre a ${s.name}…` : s.text));
  box.title = [s.note, s.code ? `Código: ${s.code}` : ''].filter(Boolean).join(' ');
  return box;
}
export const versionBelow = (version, min) => {
  const a = String(version || '0').split('.').map(n => parseInt(n, 10) || 0); const b = min.split('.').map(Number);
  for (let i = 0; i < 3; i++) { if ((a[i] || 0) !== b[i]) return (a[i] || 0) < b[i]; }
  return false;
};
// null = se oculta; { reason } = se muestra deshabilitada; {} = disponible. Sin /me/access decide la API.
export function remoteAvailability(action, access, d) {
  if (access && !access.platform && !access.permissions.some(p => p.code === action.permission)) return null;
  if (access && !access.platform && action.feature && !(access.features || []).includes(action.feature)) return null;
  if (access?.platform && action.feature && !(access.features || []).includes(action.feature)) return { reason: `El cliente no tiene contratada la función «${featureNames[action.feature]}».` };
  // Plan del dueño del equipo (GET /devices/{id} → allowed_commands): sin la función, deshabilitada con el motivo.
  if (Array.isArray(d.allowed_commands) && !d.allowed_commands.includes(action.type)) return { reason: PLAN_REASON };
  if (d.status !== 'active') return { reason: 'El equipo no está activo: no recibe órdenes.' };
  if (versionBelow(d.agent_version, action.agent)) return { reason: `El agente de este equipo (${d.agent_version || 'sin versión'}) no ejecuta esta orden: actualízalo a ${action.agent} o posterior.` };
  return {};
}
export function commandError(error, type, notice = 0) {
  if (error.code === 'feature_not_in_plan') return new Error(`${PLAN_REASON}.`);
  if (error.code === 'invalid_transition') return new Error('El equipo no está activo: no se le pueden enviar órdenes.');
  if (error.code === 'agent_too_old' && notice > 0) return new Error(`El agente de este equipo es anterior a ${NOTICE_AGENT} y no puede mostrar el aviso. Actualízalo o envía la orden con «Inmediato».`);
  if (error.code === 'agent_too_old') return new Error('El agente de este equipo es muy antiguo para esta orden: actualízalo primero.');
  if (error.status === 422 && type === 'wipe') return new Error('El nombre escrito no coincide con el del equipo. No se envió el borrado.');
  if (error.status === 422) return new Error('La orden no es válida (revisa el motivo: máximo 500 caracteres).');
  if (error.code === 'reauth_required') return new Error('Confirma tu contraseña para enviar el borrado.');
  if (error.status === 403 && !error.problem?.detail) return new Error('Tu cuenta no tiene permiso para enviar esta orden a este equipo.');
  return error;
}
export const commandExpiry = () => new Date(Date.now() + 23 * 3600 * 1000).toISOString().replace(/\.\d{3}Z$/, 'Z');
export const restartAction = remoteActions.find(a => a.type === 'restart');
// «Reiniciar para aplicar el nombre» (ficha del equipo): la misma orden de reinicio con aviso, con 5 minutos elegidos y
// el motivo listo. Respeta el plan del dueño (allowed_commands), el permiso equipos.reiniciar y la versión del agente.
// null = la cuenta no puede reiniciar este equipo (no se ofrece).
export async function renameRestartButton(d, { onSent }) {
  const access = await api('GET', '/me/access').catch(() => null);
  const state = remoteAvailability(restartAction, access, d);
  if (!state) return null;
  const name = d.computer_rename?.computer_name || '';
  const b = node('button', 'Reiniciar para aplicar el nombre'); b.type = 'button'; b.dataset.command = 'restart'; b.dataset.rename = 'restart';
  if (state.reason) { b.disabled = true; b.title = state.reason; }
  else b.addEventListener('click', () => openRemoteAction(d, restartAction, { onSent, preset: { notice: 300, reason: `Reinicio para aplicar el nombre ${name}`.trim(),
    title: `Reiniciar ${d.hostname} para aplicar el nombre`, note: `Al reiniciar, Windows aplica el nombre nuevo: ${name}.` } }));
  return { button: b, reason: state.reason || null };
}
export async function remoteActionsSection(d, log) {
  const access = await api('GET', '/me/access').catch(() => null);
  const offered = [...remoteActions, wipeAction].map(action => ({ action, state: remoteAvailability(action, access, d) })).filter(x => x.state);
  if (!offered.length) return null;
  const section = node('section', undefined, 'work-surface remote-actions'); section.append(node('h2', 'Acciones remotas'));
  section.append(node('p', 'Cada orden queda en cola: el equipo la recibe en su próxima sincronización (unos 2 minutos) si está encendido y conectado. Su estado y resultado aparecen en «Historial del equipo», al final de esta ficha.', 'help'));
  const status = node('p', undefined, 'help remote-feedback'); status.setAttribute('role', 'status');
  const button = ({ action, state }, className) => {
    const b = node('button', action.label, className); b.type = 'button'; b.dataset.command = action.type;
    if (state.reason) { b.disabled = true; b.title = state.reason; }
    else b.addEventListener('click', () => { status.textContent = ''; openRemoteAction(d, action, { onSent: () => { status.textContent = 'Orden enviada: el equipo la recibe en su próxima sincronización (unos 2 minutos).'; log.load(); } }); });
    return b;
  };
  const reasons = list => {
    const notes = list.filter(x => x.state.reason).map(x => node('li', `${x.action.label}: ${x.state.reason}`));
    if (!notes.length) return null;
    const ul = node('ul', undefined, 'help remote-reasons'); ul.append(...notes); return ul;
  };
  const normal = offered.filter(x => x.action.type !== 'wipe');
  if (normal.length) {
    const bar = node('div', undefined, 'actions'); bar.append(...normal.map(x => button(x)));
    section.append(bar); const r = reasons(normal); if (r) section.append(r);
  }
  const wipe = offered.find(x => x.action.type === 'wipe');
  if (wipe) {
    const zone = node('div', undefined, 'danger-zone'); zone.append(node('h3', 'Zona de peligro'));
    zone.append(node('p', 'El borrado remoto elimina los datos de las personas del equipo y no se puede deshacer. Úsalo solo si el equipo se perdió o lo robaron.'));
    const bar = node('div', undefined, 'actions'); bar.append(button(wipe, 'danger')); zone.append(bar);
    const r = reasons([wipe]); if (r) zone.append(r);
    section.append(zone);
  }
  section.append(status);
  return section;
}
// Diálogo de confirmación: efecto en lenguaje simple, motivo (va a la auditoría) y, en el borrado, el nombre exacto del
// equipo y la contraseña del panel (la API exige reautenticación de menos de 5 minutos: 403 reauth_required).
// preset: aviso ya elegido (segundos), motivo, título y una nota extra (p. ej. «Reiniciar para aplicar el nombre»).
export function openRemoteAction(d, action, { onSent, preset = {} }) {
  const wipe = action.type === 'wipe';
  const dialog = node('dialog', undefined, 'remote-dialog'); const form = node('form'); form.noValidate = true;
  form.append(node('h2', preset.title || `${action.verb} ${d.hostname}`));
  const body = node('div', undefined, 'dialog-body'); form.append(body);
  if (preset.note) body.append(node('p', preset.note, 'remote-preset-note'));
  const effect = node('p', action.effect); body.append(effect);
  // Reiniciar/Apagar: «Inmediato» (por defecto, como siempre) o «Con aviso» de 1, 5 o 10 minutos a la persona.
  let noticeSeconds = () => 0;
  if (action.noticeEffect) {
    const tooOld = versionBelow(d.agent_version, NOTICE_AGENT);
    const box = node('fieldset', undefined, 'remote-notice'); box.append(node('legend', 'Cuándo'));
    const choice = (value, text) => { const l = node('label', undefined, 'check'); const r = node('input'); r.type = 'radio'; r.name = 'notice_mode'; r.value = value; l.append(r, text); box.append(l); return r; };
    const immediate = choice('now', 'Inmediato'); immediate.checked = true;
    const withNotice = choice('notice', 'Con aviso a la persona');
    const delayLabel = node('label', 'Tiempo de aviso'); const delay = node('select'); delay.name = 'notice_seconds';
    noticeChoices.forEach(([value, text]) => { const o = node('option', text); o.value = String(value); delay.append(o); });
    delay.value = '300'; delayLabel.append(delay); delayLabel.hidden = true; box.append(delayLabel);
    if (tooOld) {
      withNotice.disabled = true;
      box.append(node('p', `El agente de este equipo (${d.agent_version || 'sin versión'}) no muestra el aviso: actualízalo a ${NOTICE_AGENT} o posterior. Mientras tanto solo se puede enviar inmediato.`, 'help remote-notice-old'));
    }
    const refresh = () => {
      delayLabel.hidden = !withNotice.checked;
      // Desde 4.0.27 IT puede detener la cuenta ya iniciada (Cancelar en el historial).
      const undo = versionBelow(d.agent_version, CANCEL_AGENT) ? '' : ' Si te equivocas, puedes cancelarla desde «Historial del equipo» mientras corre la cuenta.';
      effect.textContent = withNotice.checked ? action.noticeEffect(delay.selectedOptions[0].textContent) + undo : action.effect;
    };
    [immediate, withNotice, delay].forEach(x => x.addEventListener('change', refresh));
    noticeSeconds = () => withNotice.checked ? Number(delay.value) : 0;
    if (preset.notice && !tooOld && noticeChoices.some(([v]) => v === preset.notice)) { withNotice.checked = true; delay.value = String(preset.notice); refresh(); }
    body.append(box);
  }
  if (wipe) body.append(node('p', 'Esta acción es irreversible: lo borrado no se puede recuperar.', 'error-text'));
  const reasonLabel = node('label', 'Motivo (queda en la auditoría)'); const reason = node('input'); reason.name = 'reason'; reason.maxLength = 500; reason.value = preset.reason || action.reason; reason.autocomplete = 'off'; reasonLabel.append(reason); body.append(reasonLabel);
  let confirmInput = null;
  if (wipe) {
    const l = node('label', `Escribe el nombre del equipo (${d.hostname}) para confirmar`); confirmInput = node('input'); confirmInput.name = 'confirmation'; confirmInput.autocomplete = 'off'; confirmInput.spellcheck = false; l.append(confirmInput); body.append(l);
    body.append(node('p', 'Si no confirmaste tu contraseña del panel en los últimos 5 minutos, te la pediremos antes de enviar el borrado.', 'help'));
  }
  const error = node('p', '', 'error-text'); error.hidden = true; error.setAttribute('role', 'alert');
  const footer = node('div', undefined, 'dialog-footer'); const cancel = node('button', 'Cancelar'); cancel.type = 'button';
  const ok = node('button', wipe ? 'Borrar el equipo' : action.label, wipe ? 'danger' : 'primary'); ok.type = 'submit';
  footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
  wireDialog(dialog, { error });
  const sync = () => { ok.disabled = wipe && confirmInput.value !== d.hostname; };
  if (wipe) { confirmInput.addEventListener('input', sync); sync(); }
  const show = e => { error.textContent = e ? errorMessage(e) : ''; error.hidden = !e; };
  cancel.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => dialog.remove());
  form.addEventListener('submit', async event => {
    event.preventDefault(); show(null);
    if (wipe && confirmInput.value !== d.hostname) { show(new Error('Escribe el nombre exacto del equipo para habilitar el borrado.')); return; }
    const text = reason.value.trim();
    if (!text) { show(new Error('Escribe un motivo: queda en la auditoría.')); reason.focus(); return; }
    ok.disabled = true; cancel.disabled = true;
    // Clave de idempotencia nueva por intento: un 403 reauth_required no encoló nada.
    const notice = noticeSeconds();
    const send = () => api('POST', `/devices/${d.id}/commands`, {
      body: { type: action.type, reason: text, expires_at: commandExpiry(), ...(wipe ? { confirmation: confirmInput.value } : {}), ...(notice > 0 ? { parameters: { notice_seconds: notice } } : {}) },
      idempotencyKey: crypto.randomUUID(),
    });
    try {
      const result = wipe ? await withReauth(send, `Para borrar ${d.hostname} a distancia, confirma la contraseña con la que entras al panel.`) : await send();
      if (result === canceled) { show(new Error('No se envió el borrado: falta confirmar tu contraseña.')); return; }
      dialog.close(); onSent();
    } catch (e) { show(commandError(e, action.type, notice)); }
    finally { if (dialog.open) { cancel.disabled = false; ok.disabled = false; sync(); } }
  });
  dialog.showModal();
  (wipe ? confirmInput : reason).focus();
}
