import { api, errorMessage } from './api.js';

// Seguridad de cuentas de Windows de la empresa (GET/PUT /admin/tenants/{id}/hardening): la cuenta de IT que el
// endurecimiento crea en cada equipo y su contraseña compartida. Sin contraseña cargada la API niega "Endurecer" (409).
// La contraseña nunca se precarga ni se registra: el GET solo dice si existe ("********").
const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (className) n.className = className; return n; };
// Mismo patron que HardeningSettingsInput.admin_name (nombre local de Windows).
const adminNamePattern = /^[^\x00-\x1f\x7f"/\\[\]:;|=,+*?<>@]{1,20}$/;
const controlChars = /[\x00-\x1f\x7f]/;

// Configurar exige hardening.gestionar con alcance de toda la empresa (Hardening::admin -> requireFull) y la funcion
// contratada "endurecimiento". La API valida todo; esto solo evita ofrecer lo que daria "sin permiso".
export function canConfigureHardening(access) {
  if (!access || !(access.features || []).includes('endurecimiento')) return false;
  if (access.platform) return true;
  const grant = (access.permissions || []).find(p => p.code === 'hardening.gestionar');
  return !!grant && grant.scopes.includes('tenant');
}
export async function loadHardeningAccess() {
  try { return await api('GET', '/me/access'); } catch { return null; }
}
// null = la cuenta no puede leerlos (403/404); si no, la configuracion con configured=true/false.
export async function loadHardeningSettings(tenant) {
  try {
    const s = await api('GET', `/admin/tenants/${tenant}/hardening`);
    return { ...s, configured: s.shared_password !== null };
  } catch (error) {
    if ([403, 404].includes(error.status)) return null;
    throw error;
  }
}
export const hardeningSummary = s => !s?.configured ? 'Sin configurar: falta la contraseña de la cuenta de IT.'
  : `Cuenta de IT: ${s.admin_name} · ${s.hardening_mode === 'auto' ? 'automático al instalar' : 'desde el panel, equipo por equipo'}${s.deny_network_logon ? ' · inicio de sesión por red bloqueado a cuentas locales' : ''}.`;

export async function openHardeningSettings(tenant, { onSaved } = {}) {
  let current;
  try { current = await loadHardeningSettings(tenant); } catch (error) { alert(errorMessage(error)); return; }
  if (!current) { alert('Tu cuenta no puede configurar la seguridad de cuentas de Windows de esta empresa (requiere el permiso de endurecimiento sobre toda la empresa).'); return; }
  const dialog = el('dialog'); const form = el('form'); form.noValidate = true; form.append(el('h2', 'Seguridad de cuentas de Windows'));
  const body = el('div', undefined, 'dialog-body'); form.append(body);
  const error = el('p', '', 'error-text'); error.hidden = true; error.setAttribute('role', 'alert');
  const footer = el('div', undefined, 'dialog-footer'); const cancel = el('button', 'Cancelar'); cancel.type = 'button'; const ok = el('button', 'Guardar', 'primary'); ok.type = 'submit';
  footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
  cancel.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove());
  const show = e => { error.textContent = typeof e === 'string' ? e : errorMessage(e); error.hidden = !e; };
  const labeled = (text, input, help) => { const l = el('label', text); l.append(input); body.append(l); if (help) body.append(el('p', help, 'help')); return input; };

  body.append(el('p', current.configured
    ? 'Al endurecer un equipo, sus cuentas administradoras quedan como usuario estándar y solo esta cuenta de IT conserva la administración.'
    : 'Sin configurar. Para poder endurecer equipos, define la cuenta de IT que quedará como administradora en cada equipo y su contraseña.'));
  // El backend devuelve su nombre por defecto (azcadmin, el mismo que usa el agente) aun sin configuracion guardada.
  const name = labeled('Usuario de la cuenta de IT', el('input'), 'Cuenta local de Windows que se crea en cada equipo endurecido. Hasta 20 caracteres, sin " / \\ [ ] : ; | = , + * ? < > @ y sin terminar en punto.');
  name.maxLength = 20; name.autocomplete = 'off'; name.value = current.admin_name || 'azcadmin';
  const password = labeled('Contraseña', el('input')); password.type = 'password'; password.autocomplete = 'new-password'; password.maxLength = 128;
  const again = labeled('Repite la contraseña', el('input'), 'Entre 12 y 128 caracteres. Es la misma en todos los equipos de la empresa: guárdala en el gestor de contraseñas de IT.');
  again.type = 'password'; again.autocomplete = 'new-password'; again.maxLength = 128;
  if (current.configured) { password.placeholder = 'Configurada — deja vacío para conservarla'; again.placeholder = 'Configurada — deja vacío para conservarla'; }
  else { password.required = true; again.required = true; }
  const mode = labeled('Modo', el('select'));
  mode.append(new Option('Desde el panel (equipo por equipo)', 'panel'), new Option('Automático al instalar', 'auto'));
  mode.value = current.hardening_mode === 'auto' ? 'auto' : 'panel';
  const autoWarning = el('p', 'Automático: cada equipo se endurece solo al instalar el agente, sin confirmación equipo por equipo.', 'notice');
  body.append(autoWarning);
  const syncMode = () => { autoWarning.hidden = mode.value !== 'auto'; };
  mode.addEventListener('change', syncMode); syncMode();
  const denyLabel = el('label', undefined, 'check'); const deny = el('input'); deny.type = 'checkbox'; deny.checked = current.deny_network_logon !== false;
  denyLabel.append(deny, ' Bloquear inicio de sesión por red a cuentas locales'); body.append(denyLabel);
  body.append(el('p', 'Las cuentas locales (incluida la de IT) no podrán entrar a los equipos por la red (carpetas compartidas, escritorio remoto con cuenta local); solo frente al equipo. Las cuentas de dominio o Microsoft no se afectan.', 'help'));

  if (current.configured) {
    const reveal = el('button', 'Ver contraseña actual'); reveal.type = 'button';
    const secret = el('pre', '', 'secret'); secret.hidden = true;
    reveal.addEventListener('click', async () => {
      if (!confirm('La consulta de la contraseña queda registrada en la auditoría. ¿Mostrarla?')) return;
      reveal.disabled = true; show(null);
      try { secret.textContent = (await api('POST', `/admin/tenants/${tenant}/hardening/reveal`)).shared_password; secret.hidden = false; reveal.hidden = true; }
      catch (e) { show(e); reveal.disabled = false; }
    });
    body.append(reveal, secret);
  }

  form.addEventListener('submit', async ev => {
    ev.preventDefault(); show(null);
    const adminName = name.value.trim();
    if (!adminNamePattern.test(adminName) || adminName.endsWith('.')) { show('Usuario no válido: de 1 a 20 caracteres, sin " / \\ [ ] : ; | = , + * ? < > @ y sin terminar en punto.'); return; }
    const payload = { admin_name: adminName, hardening_mode: mode.value, deny_network_logon: deny.checked };
    if (password.value || again.value || !current.configured) {
      if (password.value.length < 12 || password.value.length > 128) { show('La contraseña debe tener entre 12 y 128 caracteres.'); return; }
      if (controlChars.test(password.value) || password.value === '********') { show('La contraseña contiene caracteres no válidos.'); return; }
      if (password.value !== again.value) { show('Las contraseñas no coinciden.'); return; }
      payload.shared_password = password.value;
    }
    if (mode.value === 'auto' && current.hardening_mode !== 'auto' && !confirm('En modo automático los equipos se endurecen solos al instalar el agente. ¿Continuar?')) return;
    ok.disabled = true;
    try {
      const saved = await api('PUT', `/admin/tenants/${tenant}/hardening`, { body: payload });
      password.value = ''; again.value = '';
      dialog.close();
      onSaved?.({ ...saved, configured: saved.shared_password !== null });
    } catch (e) {
      show(e.status === 409 ? 'Falta la contraseña: es obligatoria la primera vez que se configura.'
        : e.status === 422 ? 'Revisa los datos: el usuario o la contraseña no cumplen el formato de Windows.' : e);
      ok.disabled = false;
    }
  });
  dialog.showModal();
  name.focus();
}
