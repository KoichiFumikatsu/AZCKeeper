import { api, errorMessage } from './api.js';
import { wireDialog, confirmBox } from './dialogs.js';
import { withReauth, canceled } from './hardening-settings.js';

// Integraciones (API) — solo plataforma: credenciales de la API externa de la empresa elegida (GET/POST /integrations,
// POST /integrations/{id}/rotate, DELETE /integrations/{id}). El secreto lo genera el servidor y sale una sola vez, al
// crear o rotar; la lista nunca lo trae. Crear y rotar piden la contraseña del panel (403 reauth_required).
const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (className) n.className = className; return n; };
const when = v => v ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(v)) : '—';

export const SCOPE_LABELS = {
  'dashboard:read': ['Tablero de la empresa', 'Indicadores y gráficas generales.'],
  'team:read': ['Lista del equipo', 'Personas con su rol, plan y productividad resumida.'],
  'members:read': ['Ficha de cada persona', 'Perfil mínimo y tendencia. Hace falta para equipos, actividad, entradas y plan de una persona.'],
  'devices:read': ['Equipos de cada persona', 'Datos técnicos y estado, sin claves ni PIN.'],
  'activity:read': ['Actividad por aplicación', 'Qué apps usó y cuánto tiempo, sin títulos de ventana.'],
  'activity-titles:read': ['Títulos de ventana y sitios', 'Solo junto con «Actividad por aplicación».'],
  'productivity:read': ['Productividad y focus', 'Series por día.'],
  'presence:read': ['Entradas y salidas', 'Registros de llegada y salida, sin ubicación.'],
  'location:read': ['Ubicación de las entradas', 'Coordenadas GPS. Solo junto con «Entradas y salidas».'],
  'plans:read': ['Planes de las personas', 'Catálogo de planes y el plan de cada persona.'],
  'organization:read': ['Estructura de la empresa', 'Firmas, sedes, áreas y cargos.'],
  'notifications:read': ['Avisos', 'Eventos recientes, sin datos privados.'],
  'expected-devices:write': ['Cargar equipos esperados', 'Escritura: el otro sistema envía qué equipos va a instalar.'],
  'check-ins:write': ['Enviar entradas por puerta', 'Escritura: el control de acceso envía las entradas.'],
};
const REQUIRES = { 'activity-titles:read': 'activity:read', 'location:read': 'presence:read' };
const TYPE_LABEL = { api_key: 'API key', oauth2: 'Cliente OAuth' };
const STATUS = { active: ['Activa', 'good'], expired: ['Vencida', 'warn'], revoked: ['Revocada', 'bad'] };
export const BROWSER_WARNING = 'No la pongas en código que corre en el navegador; úsala solo desde un servidor (p. ej. función de backend o secreto).';
const REAUTH_TEXT = 'Para crear o cambiar una credencial de la API, confirma la contraseña con la que entras al panel.';

function shell(title) {
  const dialog = el('dialog'); const form = el('form'); form.noValidate = true; form.append(el('h2', title));
  const body = el('div', undefined, 'dialog-body'); form.append(body);
  const error = el('p', '', 'error-text'); error.hidden = true;
  const footer = el('div', undefined, 'dialog-footer'); const cancel = el('button', 'Cancelar'); cancel.type = 'button'; const ok = el('button', 'Guardar', 'primary'); ok.type = 'submit';
  footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
  cancel.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove());
  wireDialog(dialog, { title: form.querySelector('h2'), error });
  const show = text => { error.textContent = text; error.hidden = !text; };
  return { dialog, form, body, ok, cancel, show };
}
function field(parent, text, input, help) { const l = el('label', text); l.append(input); parent.append(l); if (help) parent.append(el('p', help, 'help')); return input; }
function daysSelect(options, value = 90) {
  const s = el('select'); for (const d of options) s.append(new Option(`${d} días`, String(d)));
  s.value = String(options.includes(value) ? value : options[options.length - 1]); return s;
}
function copyButton(text, value) {
  const b = el('button', text); b.type = 'button';
  b.addEventListener('click', async () => { try { await navigator.clipboard.writeText(value); b.textContent = 'Copiado'; } catch { b.textContent = 'Selecciónalo y cópialo'; } });
  return b;
}
function secretBlock(parent, label, value, copyText) {
  const box = el('div', undefined, 'secret-field'); box.append(el('strong', label));
  const pre = el('pre', value, 'secret long'); box.append(pre, copyButton(copyText, value)); parent.append(box);
}

// Modal del secreto (una sola vez). r = IntegrationSecret.
export function showIntegrationSecret(r, { rotated = false } = {}) {
  const { dialog, body, ok, cancel } = shell(rotated ? `Clave nueva de ${r.integration.name}` : `Credencial creada: ${r.integration.name}`);
  dialog.classList.add('wide-dialog'); dialog.dataset.secret = '1';
  body.append(el('p', 'Guárdala ahora: no se volverá a mostrar.', 'notice'));
  const base = (r.token_url || '').replace(/\/v1\/oauth\/token$/, '') || location.origin;
  if (r.integration.auth_type === 'api_key') {
    secretBlock(body, 'API key', r.api_key, 'Copiar API key');
    body.append(el('p', 'Envíala en cada petición en la cabecera X-API-Key:', 'help'));
    body.append(el('pre', `X-API-Key: ${r.api_key}`, 'code-example'));
    body.append(el('p', 'Ejemplo:', 'help'), el('pre', `curl -H "X-API-Key: ${r.api_key}" ${base}/ext/v1/organization`, 'code-example'));
  } else {
    secretBlock(body, 'client_id', r.credential_id, 'Copiar client_id');
    secretBlock(body, 'client_secret', r.secret, 'Copiar client_secret');
    body.append(el('p', 'Pide un token (dura 15 minutos) con client_credentials y autenticación Basic, y úsalo como Authorization: Bearer <token>.', 'help'));
    body.append(el('p', 'URL del token:', 'help'), el('pre', r.token_url, 'code-example'));
    body.append(el('p', 'Ejemplo:', 'help'), el('pre', `curl -u "${r.credential_id}:${r.secret}" -d grant_type=client_credentials ${r.token_url}`, 'code-example'));
  }
  if (rotated && r.previous_valid_until) body.append(el('p', `La clave anterior sigue funcionando hasta ${when(r.previous_valid_until)}: cámbiala antes en el otro sistema.`, 'help'));
  body.append(el('p', BROWSER_WARNING, 'error-text'));
  cancel.hidden = true; ok.textContent = 'Ya la guardé'; ok.type = 'button'; ok.addEventListener('click', () => dialog.close());
  dialog.showModal();
  return dialog;
}

export async function mountIntegrations({ tenant, content, status, action }) {
  let list = await api('GET', '/integrations', { tenant });
  const say = text => { if (status) status.textContent = text; };
  const reload = async () => { list = await api('GET', '/integrations', { tenant }); render(); };
  const intro = el('section', undefined, 'surface');
  intro.append(el('h2', 'Credenciales de la API'),
    el('p', 'Cada credencial deja que otro sistema (por ejemplo una app hecha en Lovable o el portal de un cliente) lea datos de esta empresa por la API externa, solo con los permisos que marques. La API key se envía en la cabecera X-API-Key; el cliente OAuth pide tokens de 15 minutos.', 'help'),
    el('p', BROWSER_WARNING, 'notice'));
  const wrap = el('section', undefined, 'surface'); wrap.append(el('h2', 'Credenciales'));
  const problem = el('p', '', 'error-text'); problem.setAttribute('role', 'alert'); problem.hidden = true;
  const holder = el('div'); wrap.append(problem, holder);
  content.replaceChildren(intro, wrap);

  const render = () => {
    holder.replaceChildren();
    if (!list.data.length) {
      const box = el('div', undefined, 'empty'); box.append(el('h2', 'Sin credenciales'), el('p', 'Esta empresa todavía no tiene credenciales de la API. Usa «Crear credencial».')); holder.append(box); return;
    }
    const tableWrap = el('div', undefined, 'table-wrap'); tableWrap.tabIndex = 0;
    const t = el('table'); t.append(el('caption', 'Credenciales de la API de esta empresa'));
    const head = el('tr'); ['Nombre', 'Tipo', 'Identificador', 'Permisos', 'Creada', 'Vence', 'Último uso', 'Estado', 'Acciones'].forEach(h => { const th = el('th', h); th.scope = 'col'; head.append(th); });
    const thead = el('thead'); thead.append(head); const tbody = el('tbody'); t.append(thead, tbody);
    for (const i of list.data) {
      const tr = el('tr'); tr.dataset.integration = i.id;
      const name = el('th', i.name); name.scope = 'row';
      const scopes = el('td'); scopes.append(el('span', `${i.scopes.length} ${i.scopes.length === 1 ? 'permiso' : 'permisos'}`)); scopes.append(el('small', i.scopes.map(s => SCOPE_LABELS[s]?.[0] || s).join(', ')));
      const created = el('td', when(i.created_at)); if (i.created_by) created.append(el('small', `por ${i.created_by}`));
      const [label, tone] = STATUS[i.status] || [i.status, ''];
      const state = el('td'); state.append(el('span', label, `badge ${tone}`)); if (i.revoked_at) state.append(el('small', when(i.revoked_at)));
      const actions = el('td'); const box = el('div', undefined, 'actions no-margin'); actions.append(box);
      if (i.status !== 'revoked') {
        const rotate = el('button', 'Rotar clave', 'link-button'); rotate.type = 'button'; rotate.addEventListener('click', () => rotateDialog(i));
        const revoke = el('button', 'Revocar', 'link-button'); revoke.type = 'button'; revoke.addEventListener('click', () => revokeFlow(i));
        box.append(rotate, revoke);
      }
      const id = el('td'); id.append(el('code', i.credential_id || '—'));
      tr.append(name, el('td', TYPE_LABEL[i.auth_type] || i.auth_type), id, scopes, created, el('td', when(i.expires_at)), el('td', i.last_used_at ? when(i.last_used_at) : 'Nunca'), state, actions);
      tbody.append(tr);
    }
    tableWrap.append(t); holder.append(tableWrap);
  };

  const createDialog = () => {
    const { dialog, form, body, ok, show } = shell('Crear credencial');
    dialog.classList.add('wide-dialog'); ok.textContent = 'Crear credencial';
    const name = field(body, 'Nombre', el('input'), 'Para reconocerla después, p. ej. «App Lovable de supervisores». Máximo 100 caracteres.'); name.maxLength = 100; name.autocomplete = 'off';
    const type = field(body, 'Tipo', el('select'), 'API key: una sola clave que el otro sistema envía siempre. Cliente OAuth: client_id y client_secret para pedir tokens de 15 minutos (si el otro sistema lo soporta).');
    type.append(new Option('API key', 'api_key'), new Option('Cliente OAuth', 'oauth2'));
    const expiryLabel = el('label', 'Vence en'); let expiry = daysSelect(list.expiry_days.api_key); expiryLabel.append(expiry); body.append(expiryLabel);
    const expiryHelp = el('p', '', 'help'); body.append(expiryHelp);
    const syncExpiry = () => {
      const next = daysSelect(list.expiry_days[type.value], Number(expiry.value)); expiry.replaceWith(next); expiry = next;
      expiryHelp.textContent = type.value === 'api_key' ? 'Una API key dura como máximo 90 días; antes de que venza, usa «Rotar clave».' : 'Antes de que venza, usa «Rotar clave».';
    };
    type.addEventListener('change', syncExpiry); syncExpiry();
    const set = el('fieldset', undefined, 'perm-group'); set.append(el('legend', 'Qué puede leer o hacer')); body.append(set);
    const grid = el('div', undefined, 'check-list'); set.append(grid);
    const boxes = Object.entries(SCOPE_LABELS).map(([code, [text, note]]) => {
      const l = el('label', undefined, 'check'); const c = el('input'); c.type = 'checkbox'; c.value = code;
      const available = list.available_scopes.includes(code);
      c.disabled = !available; l.append(c, ` ${text}`); l.append(el('small', available ? note : 'Función no contratada por esta empresa.'));
      grid.append(l); return c;
    });
    if (!list.available_scopes.length) set.append(el('p', 'Esta empresa no tiene contratada la función «API para otros sistemas»: actívala en Clientes antes de crear credenciales.', 'error-text'));
    form.addEventListener('submit', async ev => {
      ev.preventDefault(); show('');
      const scopes = boxes.filter(c => c.checked).map(c => c.value);
      if (!name.value.trim()) { show('Escribe un nombre.'); name.focus(); return; }
      if (!scopes.length) { show('Marca al menos un permiso.'); return; }
      const missing = scopes.find(s => REQUIRES[s] && !scopes.includes(REQUIRES[s]));
      if (missing) { show(`«${SCOPE_LABELS[missing][0]}» necesita también «${SCOPE_LABELS[REQUIRES[missing]][0]}».`); return; }
      ok.disabled = true;
      try {
        const r = await withReauth(() => api('POST', '/integrations', { tenant, body: { name: name.value.trim(), auth_type: type.value, scopes, expires_in_days: Number(expiry.value) } }), REAUTH_TEXT);
        if (r === canceled) { show('No se creó: hay que confirmar tu contraseña.'); ok.disabled = false; return; }
        // El secreto se muestra antes de recargar: si la recarga falla, no se pierde (solo se ve esta vez).
        dialog.close(); showIntegrationSecret(r); say(`Credencial «${r.integration.name}» creada.`);
        reload().catch(e => say(errorMessage(e)));
      } catch (e) {
        show(e.problem?.detail === 'name_in_use' ? 'Ya hay una credencial activa con ese nombre.' : e.problem?.detail === 'api_key_max_90_days' ? 'Una API key dura como máximo 90 días.' : errorMessage(e));
        ok.disabled = false;
      }
    });
    dialog.showModal(); name.focus();
  };

  const rotateDialog = i => {
    const { dialog, form, body, ok, show } = shell(`Rotar clave de ${i.name}`);
    ok.textContent = 'Rotar clave';
    body.append(el('p', i.auth_type === 'oauth2'
      ? 'Se crean un client_id y un client_secret nuevos. Los actuales siguen funcionando 24 horas para que alcances a cambiarlos en el otro sistema; después dejan de servir.'
      : 'Se crea una API key nueva. La actual sigue funcionando 24 horas para que alcances a cambiarla en el otro sistema; después deja de servir.'));
    const expiry = field(body, 'La clave nueva vence en', daysSelect(list.expiry_days[i.auth_type]));
    form.addEventListener('submit', async ev => {
      ev.preventDefault(); show(''); ok.disabled = true;
      try {
        const r = await withReauth(() => api('POST', `/integrations/${i.id}/rotate`, { tenant, body: { expires_in_days: Number(expiry.value) } }), REAUTH_TEXT);
        if (r === canceled) { show('No se rotó: hay que confirmar tu contraseña.'); ok.disabled = false; return; }
        dialog.close(); showIntegrationSecret(r, { rotated: true }); say(`Clave de «${i.name}» rotada.`);
        reload().catch(e => say(errorMessage(e)));
      } catch (e) { show(errorMessage(e)); ok.disabled = false; }
    });
    dialog.showModal();
  };

  const revokeFlow = async i => {
    const yes = await confirmBox({ title: 'Revocar credencial', message: `¿Revocar «${i.name}»? Deja de funcionar de inmediato, también los tokens ya emitidos.`,
      details: ['No se puede deshacer: para volver a conectar ese sistema tendrás que crear otra credencial.'], okText: 'Revocar', danger: true,
      typed: i.name, typedLabel: `Escribe ${i.name} para confirmar` });
    if (!yes) return;
    problem.hidden = true;
    try { await api('DELETE', `/integrations/${i.id}`, { tenant }); await reload(); say(`Credencial «${i.name}» revocada.`); }
    catch (e) { problem.textContent = errorMessage(e); problem.hidden = false; }
  };

  if (action) { action.textContent = 'Crear credencial'; action.hidden = false; action.onclick = createDialog; }
  render();
}
