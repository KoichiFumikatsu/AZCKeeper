// Diálogos y avisos comunes del panel (revisión UX §2.5, M11). Un solo comportamiento para k3.js, app.js, intake.js y
// hardening-settings.js: título asociado al diálogo, error anunciado como alerta, Cancelar a la izquierda y la acción
// principal a la derecha, Esc cierra (modal nativo), foco en el primer campo al abrir y de vuelta en quien lo abrió al
// cerrar. Las confirmaciones (antes confirm/alert/prompt nativos) usan el mismo diálogo, con el verbo de la acción.
const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (className) n.className = className; return n; };
let seq = 0;
const FIELDS = 'input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])';
const visible = n => !!n && n.getClientRects().length > 0;

// Lo que se enfoca al abrir: un campo ya enfocado por quien abrió se respeta; si no, el primer campo visible del
// cuerpo y, si no hay campos, la acción principal.
function focusFirst(dialog) {
  const active = document.activeElement;
  if (active && active !== dialog && dialog.contains(active) && active.matches(FIELDS)) return;
  const body = dialog.querySelector('.dialog-body') || dialog;
  const field = [...body.querySelectorAll(FIELDS)].find(visible);
  const primary = [...dialog.querySelectorAll('.dialog-footer button.primary, .dialog-footer button.danger, .dialog-footer button[type=submit]')].find(b => visible(b) && !b.disabled);
  (field || primary || [...dialog.querySelectorAll('.dialog-footer button')].find(visible))?.focus();
}

// Conecta un <dialog> ya armado: título (aria-labelledby), error (role=alert), foco inicial y retorno del foco.
export function wireDialog(dialog, { title = dialog.querySelector('h2'), error = null } = {}) {
  if (dialog.dataset.wired) return dialog;
  dialog.dataset.wired = '1';
  if (title) { if (!title.id) title.id = `dialog-title-${++seq}`; dialog.setAttribute('aria-labelledby', title.id); }
  if (error) error.setAttribute('role', 'alert');
  const opener = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
  const nativeShow = dialog.showModal.bind(dialog);
  dialog.showModal = () => { nativeShow(); focusFirst(dialog); };
  dialog.addEventListener('close', () => {
    // Si otro diálogo quedó abierto (p. ej. la contraseña temporal tras crear la cuenta), el foco ya está donde debe;
    // salvo que sea el diálogo desde el que se abrió esta confirmación: entonces vuelve a su botón.
    const openNow = document.querySelector('dialog[open]');
    if (openNow && !(opener && openNow.contains(opener))) return;
    const here = document.activeElement;
    if (here && here !== document.body && !dialog.contains(here)) return;
    if (opener?.isConnected && visible(opener) && !opener.disabled) { opener.focus(); return; }
    // Quien abrió desapareció (la tabla se volvió a pintar): el foco vuelve al contenido principal, no al inicio.
    const main = document.getElementById('main') || document.querySelector('main');
    if (main) { if (!main.hasAttribute('tabindex')) main.tabIndex = -1; main.focus({ preventScroll: true }); }
  });
  return dialog;
}

// Confirmación con título, efecto y verbo concreto. Devuelve true (confirmó) o false (Cancelar, Esc). Con `typed`,
// el botón se habilita solo cuando se escribe exactamente ese texto (revocar claves, borrar). danger: acción
// destructiva (botón rojo). `message` es la pregunta principal: también queda en data-message.
export function confirmBox({ title, message, details = [], okText = 'Confirmar', cancelText = 'Cancelar', danger = false, typed = null, typedLabel = null, note = null }) {
  return new Promise(resolve => {
    let answer = false;
    const dialog = el('dialog', undefined, 'confirm-box'); const form = el('form'); form.noValidate = true;
    const head = el('h2', title); form.append(head);
    const body = el('div', undefined, 'dialog-body'); form.append(body);
    body.append(el('p', message, 'confirm-message'));
    details.filter(Boolean).forEach(d => body.append(d instanceof Node ? d : el('p', d, 'help')));
    if (note) body.append(el('p', note, 'notice'));
    let input = null;
    if (typed) {
      const l = el('label', typedLabel || `Escribe ${typed} para confirmar`); input = el('input'); input.autocomplete = 'off'; input.spellcheck = false; l.append(input); body.append(l);
    }
    const error = el('p', '', 'error-text'); error.hidden = true;
    const footer = el('div', undefined, 'dialog-footer');
    const cancel = el('button', cancelText); cancel.type = 'button';
    const ok = el('button', okText, danger ? 'danger' : 'primary'); ok.type = 'submit';
    if (cancelText === null) cancel.hidden = true;
    footer.append(cancel, ok); form.append(error, footer); dialog.append(form);
    dialog.dataset.message = message; if (typed) dialog.dataset.typed = typed;
    document.body.append(dialog);
    wireDialog(dialog, { title: head, error });
    const sync = () => { if (input) ok.disabled = input.value !== typed; };
    input?.addEventListener('input', sync); sync();
    cancel.addEventListener('click', () => dialog.close());
    form.addEventListener('submit', ev => {
      ev.preventDefault();
      if (input && input.value !== typed) { error.textContent = `Escribe ${typed} exactamente para confirmar.`; error.hidden = false; return; }
      answer = true; dialog.close();
    });
    dialog.addEventListener('close', () => { dialog.remove(); resolve(answer); });
    dialog.showModal();
    // Sin texto que escribir, el foco va a Cancelar en las destructivas (Enter no borra por accidente) y a la acción
    // en las demás.
    if (!input) (danger && !cancel.hidden ? cancel : ok).focus();
  });
}
// Aviso que solo se lee y se cierra (reemplaza alert()).
export function noticeBox({ title, message, okText = 'Entendido' }) {
  return confirmBox({ title, message, okText, cancelText: null }).then(() => undefined);
}

// Mensajes de una sección: éxito como estado (role=status) y error como alerta (role=alert), nunca en el mismo
// párrafo. ok() limpia el error y fail() limpia el éxito.
export function feedback(parent, { okClass = 'help', failClass = 'error-text' } = {}) {
  const ok = el('p', '', okClass); ok.setAttribute('role', 'status');
  const bad = el('p', '', failClass); bad.setAttribute('role', 'alert'); bad.hidden = true;
  parent.append(ok, bad);
  return {
    ok: text => { ok.textContent = text; bad.textContent = ''; bad.hidden = true; },
    fail: text => { bad.textContent = text; bad.hidden = false; ok.textContent = ''; },
    clear: () => { ok.textContent = ''; bad.textContent = ''; bad.hidden = true; },
    nodes: { ok, bad },
  };
}
