import { api, errorMessage, PLATFORM_TENANT } from './api.js';
import { confirmBox } from './dialogs.js';
import { withReauth, canceled } from './hardening-settings.js';

// Releases → Mudanzas de servidor (agente 4.0.24+). Registrar una orden firmada (tools/sign-relocation.ps1) y activar o
// quitar el modo mudado. Solo plataforma; registrar y cambiar el modo piden la contraseña del panel (reauth < 5 min).
const el = (tag, text, className) => { const node = document.createElement(tag); if (text) node.textContent = text; if (className) node.className = className; return node; };
const reauthText = 'Para cambiar a qué servidor van los equipos, confirma la contraseña con la que entras al panel.';
const when = value => value ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const call = (method, path, body) => api(method, path, { body, tenant: PLATFORM_TENANT });

export async function mountRelocations(container) {
  const panel = el('section', undefined, 'work-surface'); container.append(panel);
  panel.append(el('h2', 'Mudanzas de servidor'),
    el('p', 'Una mudanza es una orden firmada con la clave de releases que lleva los equipos de este servidor a otro (por ejemplo de devkeep a keep). El agente 4.0.24 o posterior la verifica, prueba que el servidor nuevo lo reconozca y solo entonces se cambia; si no puede, sigue aquí y reintenta cada 15 minutos.', 'help'),
    el('p', 'El modo mudado hace que este servidor deje de aceptar datos de esos equipos y les indique que se vayan. Actívalo solo cuando la base de datos ya esté copiada al servidor nuevo. Los equipos excluidos (pruebas) y los agentes anteriores a 4.0.24 siguen normal.', 'notice'));
  const status = el('p', 'Consultando…', 'help'); status.setAttribute('role', 'status');
  const error = el('p', '', 'error-text'); error.setAttribute('role', 'alert'); error.hidden = true;
  const list = el('div');
  const form = el('form'); form.noValidate = true;
  const label = el('label', 'Orden firmada (contenido del archivo que genera sign-relocation.ps1, o solo el JWS)');
  const input = el('textarea'); input.rows = 4; input.spellcheck = false; input.autocomplete = 'off'; label.append(input);
  const register = el('button', 'Registrar mudanza', 'primary'); register.type = 'submit';
  const actions = el('div', undefined, 'actions'); actions.append(register);
  form.append(label, actions);
  panel.append(status, error, list, form);
  const fail = failure => { error.textContent = typeof failure === 'string' ? failure : errorMessage(failure); error.hidden = false; };

  const render = rows => {
    list.replaceChildren();
    status.textContent = rows.length ? `${rows.length} mudanza(s) registrada(s).` : 'No hay mudanzas registradas en este servidor.';
    for (const r of rows) {
      const card = el('div', undefined, 'surface');
      const now = Date.now(); const active = Date.parse(r.not_before) <= now && now < Date.parse(r.expires_at);
      card.append(el('h3', `${r.from_base} → ${r.to_base}`),
        el('p', `Secuencia ${r.sequence} · vigente de ${when(r.not_before)} a ${when(r.expires_at)}${active ? '' : ' (fuera de vigencia)'}`, 'help'),
        el('p', `${r.tenants.length} empresa(s) · ${r.exclude_devices.length} equipo(s) excluido(s) · registrada ${when(r.registered_at)}`, 'help'),
        el('p', r.enforced_at ? `Modo mudado activo desde ${when(r.enforced_at)}: este servidor no acepta datos de esos equipos.` : 'Modo mudado inactivo: la orden se entrega en cada sincronización, este servidor sigue aceptando datos.', r.enforced_at ? 'notice' : 'help'));
      const toggle = el('button', r.enforced_at ? 'Quitar modo mudado' : 'Activar modo mudado', r.enforced_at ? '' : 'primary'); toggle.type = 'button';
      toggle.addEventListener('click', async () => {
        error.hidden = true;
        const enforce = !r.enforced_at;
        const ok = await confirmBox(enforce ? {
          title: 'Activar modo mudado',
          message: `Los equipos incluidos (agente 4.0.24+) dejarán de enviar datos a este servidor y se irán a ${r.to_base}.`,
          details: ['Confirma antes que la base de datos ya está copiada en el servidor nuevo: si no, los equipos no podrán entrar allí y guardarán sus datos en cola hasta que puedan.'],
          okText: 'Activar modo mudado', danger: true,
        } : { title: 'Quitar modo mudado', message: 'Este servidor vuelve a aceptar datos de esos equipos. Los que ya se mudaron no vuelven.', okText: 'Quitar modo mudado' });
        if (!ok) return;
        toggle.disabled = true;
        try {
          const result = await withReauth(() => call('PATCH', `/relocations/${encodeURIComponent(r.id)}`, { enforced: enforce }), reauthText);
          if (result === canceled) { fail('No se guardó: hay que confirmar tu contraseña.'); toggle.disabled = false; return; }
          await load();
        } catch (failure) { fail(failure); toggle.disabled = false; }
      });
      card.append(toggle); list.append(card);
    }
  };
  const load = async () => { const page = await call('GET', '/relocations?limit=100'); render(page.data); };

  form.addEventListener('submit', async event => {
    event.preventDefault(); error.hidden = true;
    let jws = input.value.trim();
    try { const parsed = JSON.parse(jws); if (parsed && typeof parsed.relocation_jws === 'string') jws = parsed.relocation_jws; } catch { /* JWS pegado tal cual */ }
    if (!/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(jws)) { fail('Pega el contenido del archivo firmado (relocation_jws) o el JWS.'); return; }
    register.disabled = true;
    try {
      const result = await withReauth(() => call('POST', '/relocations', { relocation_jws: jws }), reauthText);
      if (result === canceled) { fail('No se registró: hay que confirmar tu contraseña.'); return; }
      input.value = ''; await load();
    } catch (failure) { fail(failure); }
    finally { register.disabled = false; }
  });
  try { await load(); } catch (failure) { status.textContent = 'Mudanzas no disponibles.'; fail(failure); form.hidden = true; }
}
