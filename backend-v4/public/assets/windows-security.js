import { api, errorMessage } from './api.js';
import { confirmBox } from './dialogs.js';
import { withReauth, canceled } from './hardening-settings.js';

// Ajustes → Smart App Control: interruptor por cliente (agente 4.0.19+). Apagar Smart App Control es irreversible en
// Windows, por eso guardar pide confirmar el efecto y la contraseña del panel (la API exige reauth de menos de 5 min).
const el = (tag, text, className) => { const node = document.createElement(tag); if (text) node.textContent = text; if (className) node.className = className; return node; };
const reauthText = 'Para cambiar Smart App Control en los equipos del cliente, confirma la contraseña con la que entras al panel.';

export function canConfigureWindowsSecurity(access) {
  if (!access) return false;
  if (access.platform) return true;
  return !!access.permissions?.find(p => p.code === 'equipos.desbloquear')?.scopes?.includes('tenant');
}

export async function mountWindowsSecurity(container, tenant) {
  const path = `/admin/tenants/${tenant}/windows-security`;
  container.append(
    el('p', 'Smart App Control es una protección de Windows 11 que bloquea programas sin firma digital. También puede bloquear a Keeper: en un equipo con Smart App Control activo, Windows puede impedir que se instalen las actualizaciones del agente.', 'help'),
    el('p', 'Si activas esta opción, cada equipo de este cliente con Keeper 4.0.19 o posterior apaga Smart App Control y el cambio se aplica en su próximo reinicio (Keeper no reinicia el equipo).', 'help'),
    el('p', 'No se puede volver a encender: Windows no permite reactivar Smart App Control en un equipo donde ya se apagó. Desmarcar la casilla solo evita que se apague en equipos nuevos.', 'notice'));
  const label = el('label', undefined, 'check');
  const box = el('input'); box.type = 'checkbox';
  label.append(box, document.createTextNode(' Desactivar Smart App Control en los equipos de este cliente'));
  const state = el('p', '', 'help');
  const save = el('button', 'Guardar', 'primary'); save.type = 'button';
  const actions = el('div', undefined, 'actions'); actions.append(save);
  const message = el('p', '', 'help'); message.setAttribute('role', 'status');
  const error = el('p', '', 'error-text'); error.setAttribute('role', 'alert'); error.hidden = true;
  container.append(label, state, actions, message, error);
  let saved = false;
  const show = settings => {
    saved = settings.disable_smart_app_control; box.checked = saved; save.disabled = true;
    state.textContent = saved ? 'Activado: los equipos apagan Smart App Control.' : 'Desactivado: Keeper no toca Smart App Control.';
  };
  try { show(await api('GET', path)); }
  catch (failure) { state.textContent = errorMessage(failure); box.disabled = save.disabled = true; return; }
  box.addEventListener('change', () => { save.disabled = box.checked === saved; message.textContent = ''; error.hidden = true; });

  save.addEventListener('click', async () => {
    const disable = box.checked;
    message.textContent = ''; error.hidden = true;
    const ok = await confirmBox(disable ? {
      title: 'Desactivar Smart App Control',
      message: 'Cada equipo de este cliente con Keeper 4.0.19 o posterior apagará Smart App Control en su próxima sincronización, y el cambio se aplicará cuando el equipo se reinicie.',
      details: ['No se puede deshacer: Windows no permite volver a encender Smart App Control en un equipo donde ya se apagó.', 'Desmarcar la casilla después solo evita que se apague en equipos nuevos.'],
      okText: 'Desactivar Smart App Control', danger: true,
    } : {
      title: 'Dejar de desactivar Smart App Control',
      message: 'Los equipos nuevos ya no apagarán Smart App Control.',
      details: ['En los equipos donde ya se apagó seguirá apagado: Windows no permite volver a encenderlo.'],
      okText: 'Guardar',
    });
    if (!ok) return;
    save.disabled = true;
    try {
      const result = await withReauth(() => api('PUT', path, { body: { disable_smart_app_control: disable } }), reauthText);
      if (result === canceled) { error.textContent = 'No se guardó: hay que confirmar tu contraseña.'; error.hidden = false; save.disabled = false; return; }
      show(result);
      message.textContent = disable ? 'Guardado. Cada equipo lo aplicará al sincronizar y Smart App Control quedará apagado tras su próximo reinicio.'
        : 'Guardado. Los equipos nuevos ya no apagarán Smart App Control.';
    } catch (failure) { error.textContent = errorMessage(failure); error.hidden = false; save.disabled = false; }
  });
}
