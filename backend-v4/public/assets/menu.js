// Menu lateral en pantallas angostas (compartido por app.js y k3.js).
const side = document.getElementById('sidebar');
const open = document.getElementById('open-menu');
const close = document.getElementById('close-menu');
const backdrop = document.getElementById('menu-backdrop');
function toggle(state) {
  if (!side) return;
  side.classList.toggle('open', state);
  if (backdrop) backdrop.hidden = !state;
  open?.setAttribute('aria-expanded', String(state));
  if (state) close?.focus(); else open?.focus();
}
open?.addEventListener('click', () => toggle(true));
close?.addEventListener('click', () => toggle(false));
backdrop?.addEventListener('click', () => toggle(false));
document.addEventListener('keydown', e => { if (e.key === 'Escape' && side?.classList.contains('open')) toggle(false); });
