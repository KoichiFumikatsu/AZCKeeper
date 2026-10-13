import { api, errorMessage } from './api.js';
import { wireDialog } from './dialogs.js';
import { withReauth, canceled } from './hardening-settings.js';
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
    noticeEffect: m => `La persona verá en su pantalla «El equipo se reiniciará en ${m} por orden de IT. Guarda tu trabajo.» con una cuenta regresiva, sin opción de posponer ni cancelar. Al terminar, Windows se reinicia. La cuenta empieza cuando el equipo recibe la orden.` },
  { type: 'shutdown', label: 'Apagar', permission: 'equipos.apagar', feature: 'comandos', agent: '4.0.0', reason: 'Apagado desde el panel', verb: 'Apagar',
    effect: 'El equipo se apaga de inmediato, sin aviso. La persona perderá lo que no haya guardado y alguien tendrá que encenderlo a mano: no se puede encender desde el panel.',
    noticeEffect: m => `La persona verá en su pantalla «El equipo se apagará en ${m} por orden de IT. Guarda tu trabajo.» con una cuenta regresiva, sin opción de posponer ni cancelar. Al terminar, el equipo se apaga y alguien tendrá que encenderlo a mano: no se puede encender desde el panel. La cuenta empieza cuando el equipo recibe la orden.` },
];
const wipeAction = { type: 'wipe', label: 'Borrado remoto', permission: 'equipos.borrar', feature: 'borrado', agent: '4.0.0', reason: 'Borrado remoto por robo o pérdida', verb: 'Borrar',
  effect: 'Se borran los archivos de todas las personas del equipo (Documentos, Escritorio, Descargas, correo y configuración de sus cuentas de Windows) y se sobrescribe el espacio libre para que no se puedan recuperar. Se conservan Windows, Keeper y la cuenta de IT. Solo funciona si el equipo se enciende y se conecta: no protege un disco que ya sacaron del equipo.' };
const PLAN_REASON = 'No incluido en el plan de esta persona';
const featureNames = { comandos: 'Comandos remotos', borrado: 'Borrado remoto' };
export const commandTypes = { lock: 'Bloquear', unlock: 'Desbloquear', restart: 'Reiniciar', shutdown: 'Apagar', wipe: 'Borrado remoto', refresh_policy: 'Aplicar reglas', rename_computer: 'Cambiar nombre', harden: 'Endurecer', unharden: 'Revertir endurecimiento' };
export const commandStates = { pending: ['Pendiente', 'info'], delivered: ['Entregado', 'info'], running: ['En curso', 'warn'], succeeded: ['Ejecutado', 'good'], failed: ['Falló', 'bad'], expired: ['Vencido', 'warn'], cancelled: ['Cancelado', ''] };
export const commandCodes = { completed: 'Completado', pending_restart: 'Se aplica al reiniciar', expired: 'Venció antes de ejecutarse', interrupted: 'Se interrumpió (el equipo se apagó o reinició)',
  unsupported_command: 'El agente no ejecuta esta orden', device_actions_dry_run: 'El agente está en modo de prueba: no ejecuta acciones', invalid_command_destination: 'Rechazada por el agente (destino no válido)',
  command_inbox_full: 'El agente tiene demasiadas órdenes pendientes', invalid_computer_name: 'Nombre no válido', lock_store_unavailable: 'El agente no pudo guardar el bloqueo',
  notice_deadline_missed: 'No se ejecutó: el equipo estaba apagado o suspendido cuando terminó el aviso', invalid_notice: 'Tiempo de aviso no válido' };
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
function openRemoteAction(d, action, { onSent }) {
  const wipe = action.type === 'wipe';
  const dialog = node('dialog', undefined, 'remote-dialog'); const form = node('form'); form.noValidate = true;
  form.append(node('h2', `${action.verb} ${d.hostname}`));
  const body = node('div', undefined, 'dialog-body'); form.append(body);
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
      effect.textContent = withNotice.checked ? action.noticeEffect(delay.selectedOptions[0].textContent) : action.effect;
    };
    [immediate, withNotice, delay].forEach(x => x.addEventListener('change', refresh));
    noticeSeconds = () => withNotice.checked ? Number(delay.value) : 0;
    body.append(box);
  }
  if (wipe) body.append(node('p', 'Esta acción es irreversible: lo borrado no se puede recuperar.', 'error-text'));
  const reasonLabel = node('label', 'Motivo (queda en la auditoría)'); const reason = node('input'); reason.name = 'reason'; reason.maxLength = 500; reason.value = action.reason; reason.autocomplete = 'off'; reasonLabel.append(reason); body.append(reasonLabel);
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
