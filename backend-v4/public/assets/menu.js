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

// Grupos plegables del menú (<details data-group>): el grupo de la página actual siempre se abre; los demás recuerdan lo
// que eligió la persona en este navegador. Sin almacenamiento disponible, cada página arranca con lo que trae el HTML.
const NAV_GROUPS = 'keeper.nav.groups';
let savedGroups = {};
try { savedGroups = JSON.parse(localStorage.getItem(NAV_GROUPS)) || {}; } catch { savedGroups = {}; }
for (const group of document.querySelectorAll('#navigation details[data-group]')) {
  const key = group.dataset.group;
  if (group.querySelector('[aria-current="page"]')) group.open = true;
  else if (typeof savedGroups[key] === 'boolean') group.open = savedGroups[key];
  group.querySelector('summary')?.addEventListener('click', () => {
    savedGroups[key] = !group.open;
    try { localStorage.setItem(NAV_GROUPS, JSON.stringify(savedGroups)); } catch { /* sin almacenamiento */ }
  });
}
