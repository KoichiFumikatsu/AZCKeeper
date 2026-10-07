import { api, errorMessage } from './api.js';
import { wireDialog, noticeBox } from './dialogs.js';
import { askReauth } from './hardening-settings.js';

const el = (tag, text) => { const node = document.createElement(tag); if (text) node.textContent = text; return node; };
const reauthText = 'Para ver o cambiar el PIN de desbloqueo del cliente, confirma tu contraseña del panel.';

export function canConfigureUnlockPin(access) {
  if (!access) return false;
  if (access.platform) return true;
  return (access.features || []).includes('comandos')
    && !!access.permissions?.find(p => p.code === 'equipos.desbloquear')?.scopes?.includes('tenant');
}

export async function mountUnlockPin(container, tenant) {
  const path = `/admin/tenants/${tenant}/unlock-pin`;
  const state = el('p'); const actions = el('div'); actions.className = 'actions';
  const view = el('button', 'Ver PIN'); const change = el('button', 'Configurar PIN');
  view.type = change.type = 'button';
  const message = el('p'); message.setAttribute('role', 'status');
  const showState = settings => {
    state.textContent = settings.configured ? 'PIN configurado para este cliente.' : 'Este cliente todavía no tiene PIN de desbloqueo.';
    view.disabled = !settings.configured;
    change.textContent = settings.configured ? 'Cambiar PIN' : 'Configurar PIN';
  };
  actions.append(view, change);
  container.append(state, el('p', 'Requiere Keeper 4.0.17 o posterior. Funciona sin conexión después de sincronizar el equipo. Tras 5 intentos incorrectos hay que esperar 15 minutos. Al cambiarlo, los equipos sin conexión conservan el PIN anterior hasta su próxima sincronización.'), actions, message);
  try { showState(await api('GET', path)); }
  catch (error) { state.textContent = errorMessage(error); view.disabled = change.disabled = true; return; }

  view.addEventListener('click', async () => {
    view.disabled = true;
    try {
      if (!await askReauth(reauthText)) return;
      const result = await api('POST', `${path}/reveal`);
      const dialog = el('dialog'); const body = el('div'); body.className = 'dialog-body';
      const value = el('input'); value.readOnly = true; value.value = result.pin; value.setAttribute('aria-label', 'PIN del cliente');
      result.pin = '';
      const close = el('button', 'Cerrar'); close.type = 'button'; const footer = el('div'); footer.className = 'dialog-footer';
      body.append(value); footer.append(close); dialog.append(el('h2', 'PIN de desbloqueo'), body, footer);
      close.addEventListener('click', () => dialog.close());
      dialog.addEventListener('close', () => { value.value = ''; dialog.remove(); });
      document.body.append(dialog); wireDialog(dialog); dialog.showModal(); close.focus();
    } catch (error) { await noticeBox({ title: 'PIN de desbloqueo', message: errorMessage(error) }); }
    finally { view.disabled = false; }
  });

  change.addEventListener('click', () => {
    const dialog = el('dialog'); const form = el('form'); const body = el('div'); body.className = 'dialog-body';
    const pin = el('input'); pin.type = 'password'; pin.inputMode = 'numeric'; pin.autocomplete = 'new-password'; pin.maxLength = 32;
    const repeat = pin.cloneNode();
    const label = el('label', 'Nuevo PIN (6 a 32 dígitos)'); label.append(pin);
    const repeatLabel = el('label', 'Repite el PIN'); repeatLabel.append(repeat);
    const error = el('p'); error.className = 'error-text'; error.setAttribute('role', 'alert'); error.hidden = true;
    const save = el('button', 'Guardar PIN'); save.type = 'submit'; save.className = 'primary';
    const cancel = el('button', 'Cancelar'); cancel.type = 'button'; const footer = el('div'); footer.className = 'dialog-footer';
    body.append(label, repeatLabel); footer.append(cancel, save); form.append(el('h2', 'PIN de desbloqueo'), body, error, footer); dialog.append(form);
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { pin.value = repeat.value = ''; dialog.remove(); });
    form.addEventListener('submit', async event => {
      event.preventDefault(); error.hidden = true;
      if (!/^[0-9]{6,32}$/.test(pin.value) || pin.value !== repeat.value) {
        error.textContent = 'Escribe el mismo PIN de 6 a 32 dígitos en ambos campos.'; error.hidden = false; return;
      }
      save.disabled = true;
      try {
        if (!await askReauth(reauthText) || !dialog.open) return;
        showState(await api('PUT', path, { body: { pin: pin.value } }));
        message.textContent = 'PIN guardado. Se enviará a los equipos en su próxima sincronización.';
        dialog.close();
      } catch (failure) { error.textContent = errorMessage(failure); error.hidden = false; }
      finally { save.disabled = false; }
    });
    document.body.append(dialog); wireDialog(dialog, { error }); dialog.showModal(); pin.focus();
  });
}
