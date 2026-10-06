import { api, isUuid, setTenant, readSession, refreshCsrf, goToLogin, errorMessage, PLATFORM_TENANT } from './api.js';
import './menu.js';
import './tablekit.js';
import { applyNavAccess, loadAccess, allowed } from './nav-access.js';
import { scopeSummary, sameAccess, scopeConditions, SCOPE_RULE, TEAM_LABEL } from './access-scope.js';
import { coordinator } from './coordinator.js';
import { canConfigureHardening, loadHardeningAccess, loadHardeningSettings, hardeningSummary, openHardeningSettings } from './hardening-settings.js';

// Pantallas con el diseño nuevo (orden de K3, estilo v4 índigo): Sedes, Organización y Asignaciones. Datos reales
// de la API; lo que la cuenta no puede ver se explica en vez de mostrarse vacío.
const $ = id => document.getElementById(id);
const page = document.body.dataset.page;
const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (className) n.className = className; return n; };
const when = v => v ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(v)) : 'Sin registro';
const pct = v => v === null || v === undefined || Number.isNaN(v) ? 'Sin dato' : `${new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1 }).format(v)} %`;
const kinds = { firm: 'Firma', company: 'Empresa del grupo', site: 'Sede', area: 'Área', position: 'Cargo' };
// Paises de las sedes (las remotas son "Remoto - <pais>"): de ahi sale el calendario de festivos de cada persona.
const COUNTRIES = [['CO', 'Colombia'], ['US', 'Estados Unidos'], ['HN', 'Honduras'], ['MX', 'México'], ['PE', 'Perú'], ['EC', 'Ecuador'], ['PA', 'Panamá'], ['CR', 'Costa Rica'], ['GT', 'Guatemala'], ['SV', 'El Salvador'], ['ES', 'España'], ['DO', 'República Dominicana'], ['NI', 'Nicaragua'], ['VE', 'Venezuela'], ['BO', 'Bolivia'], ['CL', 'Chile'], ['AR', 'Argentina'], ['UY', 'Uruguay'], ['PY', 'Paraguay'], ['BR', 'Brasil'], ['PR', 'Puerto Rico'], ['CA', 'Canadá']];
const countryName = code => code ? (COUNTRIES.find(([c]) => c === code)?.[1] || code) : '—';
// Ciudad de la sede: texto con sugerencias de las ya usadas (la base compara sin mayusculas ni tildes).
function cityInput(value, org) {
  const i = document.createElement('input'); i.maxLength = 80; i.value = value || ''; i.placeholder = 'Ej.: Cali';
  const list = document.createElement('datalist'); list.id = `cities-${Math.random().toString(36).slice(2)}`;
  [...new Set((org || []).filter(o => o.kind === 'site' && o.city).map(o => o.city))].sort((a, b) => a.localeCompare(b, 'es')).forEach(c => list.append(new Option(c)));
  i.setAttribute('list', list.id); document.body.append(list);
  return i;
}
// Ayuda común de «País» al crear o editar una sede (Organización y Sedes).
const SITE_COUNTRY_HINT = 'El país define el calendario automático de festivos de las personas de esta sede (salvo quien tenga un calendario propio). Sin país, usan el calendario predeterminado del cliente.';
const placeOf = site => site ? [site.city, site.country_code ? countryName(site.country_code) : null].filter(Boolean).join(', ') || '—' : '—';
function countrySelect(value, empty = 'Sin país') { const s = document.createElement('select'); s.append(new Option(empty, '')); COUNTRIES.forEach(([c, n]) => s.append(new Option(n, c))); s.value = value || ''; return s; }
const online = d => d.status === 'active' && d.last_seen_at && Date.now() - Date.parse(d.last_seen_at) <= 5 * 60 * 1000;
let tenant;

function fail(error) { $('page-error').textContent = error instanceof Error && !(error.status) ? error.message : errorMessage(error); $('page-error').hidden = false; $('page-status').textContent = ''; }
function done() { $('page-status').textContent = `Actualizado a las ${new Intl.DateTimeFormat('es-CO', { timeStyle: 'short' }).format(new Date()).replace(/\.$/, '')}.`; }
function surface(title, parent = $('page-content')) {
  const s = el('section', undefined, 'surface'); const head = el('div', undefined, 'section-head'); head.append(el('h2', title)); s.append(head); parent.append(s); return s;
}
function empty(parent, title, text) { const box = el('div', undefined, 'empty'); box.append(el('h2', title), el('p', text)); parent.append(box); }
function badge(text, tone) { return el('span', text, `badge ${tone || ''}`.trim()); }
function kpis(items) {
  const dl = el('dl', undefined, 'kpis');
  for (const [label, value, note] of items) { const box = el('div', undefined, 'kpi'); box.append(el('dt', label), el('dd', value)); if (note) box.append(el('small', note)); dl.append(box); }
  $('page-content').append(dl);
}
// Texto buscable: sin tildes, sin mayúsculas y con espacios normalizados.
const searchFold = v => String(v ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLocaleLowerCase('es').replace(/\s+/g, ' ').trim();
// Texto visible de una celda (string o nodo), sin botones ni controles de acciones.
function cellText(v) {
  if (v === null || v === undefined) return '';
  if (!(v instanceof Node)) return String(v);
  const c = v.cloneNode(true);
  if (c.querySelectorAll) c.querySelectorAll('button, select, input, textarea, .actions').forEach(x => x.remove());
  return c.matches?.('button, select, input, textarea') ? '' : (c.textContent || '');
}
// Tabla con filtro de texto y, opcionalmente, un select por columna. `search` (fila → textos) agrega
// campos de la entidad que la búsqueda debe encontrar aunque no se vean o se dibujen como nodos.
function table(parent, caption, columns, rows, { filters = [], search: searchFields } = {}) {
  const bar = el('div', undefined, 'toolbar');
  const search = el('input'); search.type = 'search'; search.placeholder = 'Buscar';
  const searchLabel = el('label', 'Buscar'); searchLabel.append(search); bar.append(searchLabel);
  // Un filtro puede traer valor inicial ([etiqueta, getter, inicial]); si no hay filas con ese valor queda en Todos.
  const selects = filters.map(([label, getter, initial]) => {
    const l = el('label', label); const s = el('select'); s.append(new Option('Todos', ''));
    [...new Set(rows.map(getter).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es')).forEach(v => s.append(new Option(v, v)));
    if (initial && [...s.options].some(o => o.value === initial)) s.value = initial;
    l.append(s); bar.append(l); return [s, getter];
  });
  parent.append(bar);
  const wrap = el('div', undefined, 'table-wrap'); wrap.tabIndex = 0; parent.append(wrap);
  const hay = new Map();
  const haystack = r => {
    if (!hay.has(r)) {
      const parts = columns.filter(([name]) => name && name !== 'Acciones').map(([, get]) => cellText(get(r)));
      if (searchFields) parts.push(...[].concat(searchFields(r)).filter(x => x !== null && x !== undefined));
      hay.set(r, searchFold(parts.join(' ')));
    }
    return hay.get(r);
  };
  const render = () => {
    const term = searchFold(search.value);
    const shown = rows.filter(r => selects.every(([s, g]) => !s.value || g(r) === s.value) && (!term || haystack(r).includes(term)));
    wrap.replaceChildren();
    if (!shown.length) { empty(wrap, 'Sin resultados', 'No hay filas que coincidan con la búsqueda o los filtros.'); return; }
    const t = el('table'); t.append(el('caption', `${caption} · ${shown.length} de ${rows.length}`));
    const head = el('thead'); const hr = el('tr'); columns.forEach(([name]) => { const th = el('th', name); th.scope = 'col'; hr.append(th); }); head.append(hr);
    const body = el('tbody');
    for (const r of shown) {
      const tr = el('tr');
      for (const [, get] of columns) { const v = get(r); const td = el('td'); if (v instanceof Node) td.append(v); else td.textContent = v ?? '—'; tr.append(td); }
      body.append(tr);
    }
    t.append(head, body); wrap.append(t);
  };
  search.addEventListener('input', render); selects.forEach(([s]) => s.addEventListener('change', render)); render();
}
async function allPages(path, company) {
  const out = []; let cursor = null;
  do {
    const url = new URL(path, location.origin); url.searchParams.set('limit', '100'); if (cursor) url.searchParams.set('cursor', cursor);
    const r = await api('GET', url.pathname + url.search, company ? { tenant: company } : {}); out.push(...r.data); cursor = r.next_cursor;
  } while (cursor);
  return out;
}
async function optional(promise) { try { return await promise; } catch (e) { if ([401, 403].includes(e.status)) return null; throw e; } }
function names(org) { return new Map(org.map(o => [o.id, o.name])); }

async function sites() {
  const today = new Date(); const from = new Date(today); from.setDate(today.getDate() - 6); const to = new Date(today); to.setDate(today.getDate() + 1);
  const day = d => d.toISOString().slice(0, 10);
  const [org, users, devices, people] = await Promise.all([allPages('/organization'), allPages('/users'), optional(allPages(`/tenants/${tenant}/devices`)),
    optional(api('GET', `/reports/people?from=${day(from)}&to=${day(to)}`))]);
  const siteList = org.filter(o => o.kind === 'site' && o.active);
  const totals = new Map((people?.data || []).map(p => [p.user_id, p.totals]));
  const byUser = new Map(); (devices || []).filter(d => d.status === 'active').forEach(d => { if (!byUser.has(d.user_id)) byUser.set(d.user_id, []); byUser.get(d.user_id).push(d); });
  const rows = siteList.map(site => {
    const members = users.filter(u => u.site_id === site.id && u.status === 'active');
    const devs = members.flatMap(u => byUser.get(u.id) || []);
    const sum = members.reduce((acc, u) => { const t = totals.get(u.id); if (t) { acc.active += t.active_seconds; acc.productive += t.productive_seconds; } return acc; }, { active: 0, productive: 0 });
    return { site, members: members.length, devices: devs.length, online: devs.filter(online).length, productivity: sum.active ? 100 * sum.productive / sum.active : null, hours: sum.active / 3600 };
  });
  const allDevs = (devices || []).filter(d => d.status === 'active');
  kpis([['Sedes activas', String(siteList.length)], ['Personas', String(users.filter(u => u.status === 'active').length)],
    ['Equipos en línea', devices ? `${allDevs.filter(online).length} / ${allDevs.length}` : 'Sin acceso', 'Con señal en los últimos 5 minutos'],
    ['Productividad (7 días)', people ? pct(rows.reduce((a, r) => a + (r.productivity ?? 0) * r.hours, 0) / (rows.reduce((a, r) => a + (r.productivity === null ? 0 : r.hours), 0) || NaN)) : 'Sin acceso']]);
  const action = $('page-action'); action.textContent = 'Crear sede'; action.hidden = false;
  action.addEventListener('click', () => {
    const { dialog, body, ok, show } = dialogShell('Crear sede');
    const name = labeled(body, 'Nombre de la sede', el('input')); name.maxLength = 120;
    // Sin país por defecto (igual que en Organización): elegirlo decide el calendario de festivos.
    const country = labeled(body, 'País', countrySelect(''), `${SITE_COUNTRY_HINT} Para quien trabaja desde casa crea una sede "Remoto – <país>".`);
    const city = labeled(body, 'Ciudad', cityInput('', []), 'Ciudades sugeridas del país elegido; también puedes escribir una nueva. Sirve para ver y filtrar personas por ciudad.');
    const fillCities = () => { const list = $(city.getAttribute('list')); list.replaceChildren(); [...new Set(org.filter(o => o.kind === 'site' && o.city && (o.country_code || '') === country.value).map(o => o.city))].sort((a, b) => a.localeCompare(b, 'es')).forEach(c => list.append(new Option(c))); };
    country.addEventListener('change', fillCities); fillCities();
    body.append(el('p', 'Después la asignas a las personas en Personas (o llega sola desde AZC One si One manda en las sedes).', 'help'));
    ok.textContent = 'Crear sede';
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (!name.value.trim()) { show('Escribe el nombre.'); return; }
      ok.disabled = true;
      try { await api('POST', '/organization', { body: { kind: 'site', name: name.value.trim(), parent_id: null, active: true, country_code: country.value || null, city: city.value.trim() || null } }); dialog.close(); location.reload(); }
      catch (e) { show(e); ok.disabled = false; }
    });
    dialog.showModal();
  });
  if (!siteList.length) { empty(surface('Sedes'), 'Aún no hay sedes', 'Crea la primera con "Crear sede".'); return; }
  const cards = el('div', undefined, 'three'); $('page-content').append(cards);
  for (const r of rows) {
    const s = surface(r.site.name, cards); s.append(el('p', 'Operación de sede', 'eyebrow'));
    const big = el('p', devices ? `${r.online} ` : '—', 'kpi-inline'); big.append(el('small', devices ? `en línea / ${r.devices}` : 'sin acceso a equipos')); s.append(big);
    s.append(el('p', `${placeOf(r.site)} · ${r.members} personas · productividad ${pct(r.productivity)}`, 'help'));
  }
  table(surface('Detalle por sede'), 'Sedes', [['Sede', r => r.site.name], ['Ciudad', r => r.site.city || '—'], ['País', r => countryName(r.site.country_code)], ['Personas', r => String(r.members)], ['Equipos', r => String(r.devices)],
    ['En línea', r => devices ? String(r.online) : '—'], ['Uso real, horas (7 días)', r => people ? r.hours.toFixed(1) : '—'], ['Productividad', r => people ? pct(r.productivity) : '—']], rows);
}

// Organización: una pestaña por tipo, activas por defecto y "+ Nueva …" con el tipo de la pestaña (revisión UX ORG-1/3/4/5).
// Firmas solo aparece mientras existan: no se migran a empresas del grupo. La pestaña elegida se recuerda por cliente.
const ORG_TABS = [
  { kind: 'company', label: 'Empresas del grupo', create: '+ Nueva empresa', title: 'Nueva empresa del grupo', submit: 'Crear empresa', plural: 'empresas del grupo',
    help: 'Las empresas que emplean a las personas (AZC Legal, BilingueLaw…). Pueden compartir sedes. En Inicio y Reportes, sin filtro se ve todo el grupo; con filtro, solo una empresa, un país o una sede.' },
  { kind: 'site', label: 'Sedes', create: '+ Nueva sede', title: 'Nueva sede', submit: 'Crear sede', plural: 'sedes',
    help: 'Las sedes pueden ser compartidas por varias empresas del grupo. El país de la sede define el calendario automático de festivos de sus personas.' },
  { kind: 'area', label: 'Áreas', create: '+ Nueva área', title: 'Nueva área', submit: 'Crear área', plural: 'áreas',
    help: 'Las personas apuntan a la unidad, no a su nombre: si renombras un área, todas las personas de esa área la ven con el nombre nuevo.' },
  { kind: 'position', label: 'Cargos', create: '+ Nuevo cargo', title: 'Nuevo cargo', submit: 'Crear cargo', plural: 'cargos',
    help: 'Las personas apuntan al cargo, no a su nombre: si renombras un cargo, todas las personas con ese cargo lo ven con el nombre nuevo.' },
  { kind: 'firm', label: 'Firmas', create: null, plural: 'firmas',
    help: 'Firmas registradas antes de las empresas del grupo. Se conservan mientras existan; para una unidad nueva que emplea personas usa Empresas del grupo.' },
];
const orgFold = s => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLocaleLowerCase('es').trim();
const NO_COUNTRY = '-';

// Revisión UX M1 (ORG-2/4): «Depende de» con buscador agrupado por tipo y ruta legible; al editar no se ofrecen la unidad
// ni sus descendientes (harían un círculo; la API también lo valida). No hay matriz de tipos: solo se ordenan los grupos.
const PARENT_ORDER = { site: ['company', 'site', 'area', 'position', 'firm'], area: ['site', 'company', 'area', 'position', 'firm'], position: ['area', 'site', 'company', 'position', 'firm'] };
const ORG_TREE_HELP = 'Dependencia organizativa: cada unidad aparece bajo la unidad de la que depende («Depende de»); primero las que no dependen de ninguna. No es un árbol de permisos: cambiar una dependencia no mueve personas ni cambia accesos.';
let orgPickerSeq = 0;

// Ruta legible por parent_id ("Tequendama › Agentes de servicio"); se corta si los datos tuvieran un círculo.
function orgPath(o, map) {
  const parts = []; const seen = new Set();
  for (let x = o; x && !seen.has(x.id); x = x.parent_id ? map.get(x.parent_id) : null) { seen.add(x.id); parts.unshift(x.name); }
  return parts.join(' › ');
}
function orgDescendants(org, id) {
  const kids = new Map();
  for (const o of org) if (o.parent_id) kids.set(o.parent_id, [...(kids.get(o.parent_id) || []), o.id]);
  const out = new Set([id]); const stack = [id];
  while (stack.length) for (const c of kids.get(stack.pop()) || []) if (!out.has(c)) { out.add(c); stack.push(c); }
  return out;
}
const orgSitesIn = (org, country) => org.filter(o => o.kind === 'site' && (!country || (country === NO_COUNTRY ? !o.country_code : o.country_code === country)));
const orgFillCities = (list, org, country) => { list.replaceChildren(); [...new Set(orgSitesIn(org, country).map(o => o.city).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es')).forEach(c => list.append(new Option(c))); };
// Selector de dependencia (combobox con lista agrupada). Ofrece unidades activas; conserva el valor actual aunque
// no esté en la lista (p. ej. una superior inactiva) hasta que se elija otro.
function orgParentPicker(parent, { org, kind, value = '', excludeId = null, help }) {
  const map = new Map(org.map(o => [o.id, o]));
  const banned = excludeId ? orgDescendants(org, excludeId) : new Set();
  const order = PARENT_ORDER[kind] || ORG_TABS.map(t => t.kind);
  const candidates = org.filter(x => x.active && !banned.has(x.id)).map(x => ({ id: x.id, kind: x.kind, name: x.name, path: orgPath(x, map) }));
  const uid = `org-parent-${++orgPickerSeq}`;
  const box = el('div', undefined, 'org-picker');
  const input = el('input'); input.id = uid; input.type = 'text'; input.autocomplete = 'off'; input.placeholder = 'Buscar por nombre o ruta';
  input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false'); input.setAttribute('aria-controls', `${uid}-list`);
  const label = el('label', 'Depende de'); label.append(input);
  const list = el('div', undefined, 'org-picker-list'); list.id = `${uid}-list`; list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', 'Unidades de las que puede depender'); list.hidden = true;
  box.append(label, list); parent.append(box);
  if (help) parent.append(el('p', help, 'help'));
  let selected = value || ''; let typed = false; let opts = []; let at = -1; let swallowEscape = false;
  const textOf = id => !id ? 'Sin dependencia' : (map.get(id) ? orgPath(map.get(id), map) : 'Unidad no disponible');
  input.value = textOf(selected);
  const setActive = i => {
    opts.forEach(o => o.classList.remove('active')); at = i;
    if (i >= 0 && opts[i]) { opts[i].classList.add('active'); input.setAttribute('aria-activedescendant', opts[i].id); opts[i].scrollIntoView?.({ block: 'nearest' }); }
    else input.removeAttribute('aria-activedescendant');
  };
  const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); typed = false; input.value = textOf(selected); };
  const choose = id => { selected = id; close(); };
  const option = (to, id, name, path) => {
    const o = el('div', undefined, 'org-picker-option'); o.id = `${uid}-o${opts.length}`; o.dataset.value = id;
    o.setAttribute('role', 'option'); o.setAttribute('aria-selected', String(id === selected));
    o.append(el('span', name)); if (path && path !== name) o.append(el('small', path));
    o.addEventListener('mousedown', e => e.preventDefault()); o.addEventListener('click', () => choose(id));
    to.append(o); opts.push(o);
  };
  const fill = () => {
    const term = typed ? orgFold(input.value) : '';
    list.replaceChildren(); opts = [];
    if (!term || orgFold('Sin dependencia').includes(term)) option(list, '', 'Sin dependencia', '');
    for (const k of order) {
      const tabLabel = ORG_TABS.find(t => t.kind === k)?.label || kinds[k];
      const items = candidates.filter(c => c.kind === k && (!term || orgFold(`${c.path} ${tabLabel} ${kinds[k]}`).includes(term))).sort((a, b) => a.path.localeCompare(b.path, 'es'));
      if (!items.length) continue;
      const g = el('div', undefined, 'org-picker-group'); g.setAttribute('role', 'group');
      const h = el('div', tabLabel, 'org-picker-head'); h.id = `${uid}-g-${k}`; g.setAttribute('aria-labelledby', h.id); g.append(h);
      items.forEach(c => option(g, c.id, c.name, c.path)); list.append(g);
    }
    if (!opts.length) list.append(el('p', 'Sin coincidencias. Prueba con otro nombre.', 'org-picker-empty'));
    setActive(typed ? (opts.length ? 0 : -1) : opts.findIndex(o => o.dataset.value === selected));
  };
  const open = () => { if (!list.hidden) return; fill(); list.hidden = false; input.setAttribute('aria-expanded', 'true'); };
  input.addEventListener('click', open);
  input.addEventListener('focus', () => input.select());
  input.addEventListener('input', () => { typed = true; fill(); list.hidden = false; input.setAttribute('aria-expanded', 'true'); });
  input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault(); if (list.hidden) { open(); return; }
      if (opts.length) setActive((at + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length);
    } else if (e.key === 'Enter' && !list.hidden) { e.preventDefault(); if (at >= 0) choose(opts[at].dataset.value); }
    else if (e.key === 'Escape' && !list.hidden) { e.preventDefault(); e.stopPropagation(); swallowEscape = true; setTimeout(() => { swallowEscape = false; }); close(); }
  });
  // Escape cierra la lista, no el diálogo.
  input.closest('dialog')?.addEventListener('cancel', e => { if (swallowEscape) e.preventDefault(); });
  box.addEventListener('focusout', e => { if (!box.contains(e.relatedTarget)) close(); });
  return { input, get value() { return selected; } };
}
// Diálogo «Nueva sede/área/cargo/empresa del grupo» con el tipo fijo: lo usan Organización y el alta y la ficha de
// personas («+ Nueva área» sin salir del formulario). onCreated recibe la unidad que devolvió la API.
function openOrgCreate({ kind, org, country: suggested = '', city: suggestedCity = '', onCreated }) {
  const tab = ORG_TABS.find(t => t.kind === kind);
  const { dialog, body, ok, show } = dialogShell(tab.title); ok.textContent = tab.submit;
  const name = labeled(body, 'Nombre', el('input')); name.maxLength = 120;
  let country = null, city = null;
  if (kind === 'site') {
    country = labeled(body, 'País', countrySelect(suggested), SITE_COUNTRY_HINT);
    city = labeled(body, 'Ciudad', cityInput(suggestedCity, orgSitesIn(org, suggested || NO_COUNTRY)), 'Ciudades sugeridas del país elegido; también puedes escribir una nueva.');
    if (suggested) body.append(el('p', 'País y ciudad sugeridos por el filtro actual: revísalos antes de crear.', 'help'));
    country.addEventListener('change', () => orgFillCities($(city.getAttribute('list')), org, country.value || NO_COUNTRY));
  }
  const parent = orgParentPicker(body, { org, kind, help: 'Opcional. Por ejemplo, un área que pertenece a una sede, o un cargo que depende de un área.' });
  dialog.querySelector('form').addEventListener('submit', async ev => {
    ev.preventDefault(); if (!name.value.trim()) { show('Escribe el nombre.'); return; }
    ok.disabled = true;
    try {
      const created = await api('POST', '/organization', { body: { kind, name: name.value.trim(), parent_id: parent.value || null, active: true, ...(country ? { country_code: country.value || null, city: city.value.trim() || null } : {}) } });
      dialog.close(); onCreated(created);
    } catch (e) { show(e); ok.disabled = false; }
  });
  dialog.showModal(); name.focus();
}
async function organization() {
  let org = await allPages('/organization');
  // null = la cuenta no puede consultar Personas: el conteo es "No disponible", nunca cero.
  const users = await optional(allPages('/users'));
  const usedBy = o => users ? users.filter(u => u.status === 'active' && [u.firm_id, u.company_id, u.site_id, u.area_id, u.position_id].includes(o.id)).length : null;
  // Por cliente: pestaña (clave histórica) y vista, agrupación y filtros (JSON). Un dato dañado se ignora.
  const storeKey = `keeper.organization.tab.${tenant}`;
  const viewKey = `keeper.organization.view.${tenant}`;
  const state = { tab: null, view: 'list', group: false, inactive: false, term: '', country: '', city: '' };
  try {
    state.tab = localStorage.getItem(storeKey);
    const saved = JSON.parse(localStorage.getItem(viewKey) || 'null');
    if (saved && typeof saved === 'object') {
      state.view = saved.view === 'tree' ? 'tree' : 'list'; state.group = saved.group === true; state.inactive = saved.inactive === true;
      for (const k of ['term', 'country', 'city']) if (typeof saved[k] === 'string') state[k] = saved[k].slice(0, 120);
    }
  } catch { /* sin almacenamiento o dato dañado */ }
  const persist = () => {
    try {
      if (state.tab) localStorage.setItem(storeKey, state.tab);
      localStorage.setItem(viewKey, JSON.stringify({ view: state.view, group: state.group, inactive: state.inactive, term: state.term, country: state.country, city: state.city }));
    } catch { /* sin almacenamiento */ }
  };
  const collapsed = new Set();
  const tabsShown = () => ORG_TABS.filter(t => t.kind !== 'firm' || org.some(o => o.kind === 'firm'));
  const current = () => tabsShown().find(t => t.kind === state.tab) || tabsShown()[0];
  const action = $('page-action');
  let notice = null;
  const tell = text => { if (!notice) { fail(new Error(text)); return; } notice.textContent = text; notice.hidden = false; notice.scrollIntoView?.({ block: 'nearest' }); };
  const quiet = () => { if (notice) notice.hidden = true; $('page-error').hidden = true; };
  const sitesIn = country => org.filter(o => o.kind === 'site' && (!country || (country === NO_COUNTRY ? !o.country_code : o.country_code === country)));
  const citiesOf = country => [...new Set(sitesIn(country).map(o => o.city).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
  const fillCities = (list, country) => { list.replaceChildren(); citiesOf(country).forEach(c => list.append(new Option(c))); };
  const unitMap = () => new Map(org.map(o => [o.id, o]));
  const pathOf = orgPath;
  const descendantsOf = id => orgDescendants(org, id);
  const kindRank = o => ORG_TABS.findIndex(t => t.kind === o.kind);
  const byKindName = (a, b) => kindRank(a) - kindRank(b) || a.name.localeCompare(b.name, 'es');

  const render = () => {
    const tab = current(); state.tab = tab.kind; const tree = state.view === 'tree';
    persist();
    action.textContent = tab.create || ''; action.hidden = tree || !tab.create;
    const content = $('page-content'); content.replaceChildren();
    const active = org.filter(o => o.active);
    kpis([['Unidades activas', String(active.length), 'Las mismas que listan las pestañas (sin inactivas)'],
      ['Unidades inactivas', String(org.length - active.length), 'Se ven con «Incluir inactivas»'],
      ['Personas activas', users ? String(users.filter(u => u.status === 'active').length) : 'No disponible', users ? 'Visibles para tu cuenta' : 'Tu cuenta no puede consultar Personas'],
      ['Sedes activas sin país', String(active.filter(o => o.kind === 'site' && !o.country_code).length), 'Usan el calendario de festivos predeterminado del cliente']]);
    const views = el('div', undefined, 'view-switch'); views.setAttribute('role', 'group'); views.setAttribute('aria-label', 'Vista de Organización');
    views.append(el('span', 'Vista', 'view-switch-label'));
    for (const [v, text] of [['list', 'Por tipo'], ['tree', 'Dependencias']]) {
      const b = el('button', text); b.type = 'button'; b.id = `org-view-${v}`; b.setAttribute('aria-pressed', String(state.view === v));
      b.addEventListener('click', () => { if (state.view === v) return; state.view = v; quiet(); render(); $(`org-view-${v}`)?.focus(); });
      views.append(b);
    }
    content.append(views);
    if (!tree) {
      const tabs = el('div', undefined, 'tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Tipo de unidad'); content.append(tabs);
      const shownTabs = tabsShown();
      for (const t of shownTabs) {
        const n = active.filter(o => o.kind === t.kind).length;
        const b = el('button', `${t.label} `); b.type = 'button'; b.id = `org-tab-${t.kind}`; b.dataset.kind = t.kind;
        b.append(el('span', String(n), 'tab-count'));
        b.setAttribute('role', 'tab'); b.setAttribute('aria-selected', String(t === tab)); b.setAttribute('aria-controls', 'org-panel'); b.tabIndex = t === tab ? 0 : -1;
        b.setAttribute('aria-label', `${t.label}: ${n} activas`);
        b.addEventListener('click', () => { if (t === current()) return; state.tab = t.kind; state.term = state.country = state.city = ''; quiet(); render(); $(`org-tab-${t.kind}`)?.focus(); });
        b.addEventListener('keydown', e => {
          const i = shownTabs.indexOf(t); const next = e.key === 'ArrowRight' ? shownTabs[(i + 1) % shownTabs.length] : e.key === 'ArrowLeft' ? shownTabs[(i - 1 + shownTabs.length) % shownTabs.length] : null;
          if (next) { e.preventDefault(); $(`org-tab-${next.kind}`).click(); }
        });
        tabs.append(b);
      }
    }
    const s = surface(tree ? 'Dependencias' : tab.label); s.id = 'org-panel';
    if (!tree) { s.setAttribute('role', 'tabpanel'); s.setAttribute('aria-labelledby', `org-tab-${tab.kind}`); }
    s.append(el('p', tree ? ORG_TREE_HELP : tab.help, 'help'));
    notice = el('p', '', 'error-text'); notice.setAttribute('role', 'alert'); notice.hidden = true; s.append(notice);

    const sites = !tree && tab.kind === 'site';
    const bar = el('div', undefined, 'toolbar');
    const search = el('input'); search.type = 'search'; search.value = state.term; search.placeholder = tree ? 'Nombre, tipo o ruta' : 'Nombre o dependencia';
    const searchLabel = el('label', 'Buscar'); searchLabel.append(search); bar.append(searchLabel);
    let country = null, city = null;
    if (sites) {
      country = el('select'); country.append(new Option('Todos', ''), new Option('Sin país', NO_COUNTRY));
      [...new Set(sitesIn('').map(o => o.country_code).filter(Boolean))].map(c => [c, countryName(c)]).sort((a, b) => a[1].localeCompare(b[1], 'es')).forEach(([c, n]) => country.append(new Option(n, c)));
      country.value = state.country; if (country.value !== state.country) state.country = '';
      const cl = el('label', 'País'); cl.append(country); bar.append(cl);
      city = el('input'); city.type = 'search'; city.value = state.city; city.placeholder = 'Cualquiera'; city.maxLength = 80;
      const list = el('datalist'); list.id = 'org-city-filter'; city.setAttribute('list', list.id); fillCities(list, state.country);
      const ci = el('label', 'Ciudad'); ci.append(city, list); bar.append(ci);
      country.addEventListener('change', () => { state.country = country.value; state.city = city.value = ''; fillCities(list, state.country); renderTable(); });
      city.addEventListener('input', () => { state.city = city.value; renderTable(); });
    }
    const inactiveBox = el('input'); inactiveBox.type = 'checkbox'; inactiveBox.checked = state.inactive;
    const il = el('label', undefined, 'check'); il.append(inactiveBox, ' Incluir inactivas'); bar.append(il);
    if (sites) {
      const groupBox = el('input'); groupBox.type = 'checkbox'; groupBox.checked = state.group; groupBox.id = 'org-group-sites';
      const gl = el('label', undefined, 'check'); gl.append(groupBox, ' Agrupar por ubicación'); bar.append(gl);
      groupBox.addEventListener('change', () => { state.group = groupBox.checked; renderTable(); });
    }
    const clear = el('button', 'Limpiar filtros'); clear.type = 'button'; clear.id = 'org-clear'; bar.append(clear);
    s.append(bar);
    const wrap = el('div', undefined, 'table-wrap'); wrap.tabIndex = 0; s.append(wrap);
    if (sites) s.append(el('p', 'Cada sede aparece una sola vez, aunque la compartan varias empresas del grupo. «Sin ubicación»: sedes sin país.', 'help'));
    s.append(el('p', users ? '«Personas activas»: personas activas con esta unidad que tu cuenta puede ver. Desactivar o eliminar revisa además personas inactivas y otros usos.'
      : 'Tu cuenta no puede consultar Personas: el número de personas no está disponible (no significa que la unidad esté libre).', 'help'));
    search.addEventListener('input', () => { state.term = search.value; renderTable(); });
    inactiveBox.addEventListener('change', () => { state.inactive = inactiveBox.checked; renderTable(); });
    clear.addEventListener('click', () => {
      state.term = state.country = state.city = ''; state.inactive = false; search.value = ''; inactiveBox.checked = false;
      if (country) { country.value = city.value = ''; fillCities($('org-city-filter'), ''); }
      renderTable(); search.focus();
    });

    const n = names(org);
    const parentName = o => o.parent_id ? (n.get(o.parent_id) || '—') : 'Sin dependencia';
    const peopleCell = o => users ? String(usedBy(o)) : 'No disponible';
    const columns = [[tab.kind === 'site' ? 'Sede' : 'Nombre', o => o.name], ...(tab.kind === 'site' ? [['Ciudad y país', o => placeOf(o)]] : []),
      ['Depende de', parentName], ['Personas activas', peopleCell], ['Estado', o => badge(o.active ? 'Activa' : 'Inactiva', o.active ? 'good' : '')], ['Acciones', o => actions(o)]];
    const matches = o => {
      if (tab.kind === 'site') {
        if (state.country === NO_COUNTRY ? o.country_code : state.country && o.country_code !== state.country) return false;
        if (orgFold(state.city) && !orgFold(o.city).includes(orgFold(state.city))) return false;
      }
      const term = orgFold(state.term);
      return !term || [o.name, parentName(o), tab.kind === 'site' ? placeOf(o) : ''].some(v => orgFold(v).includes(term));
    };
    const noRows = (ofKind, pool, plural, create) => {
      const box = el('div', undefined, 'empty');
      const inactiveOnly = ofKind - pool;
      if (!ofKind) box.append(el('h2', `Aún no hay ${plural}`), el('p', create ? `Crea la primera para asignarla a las personas con «${create}».` : 'No hay registros de este tipo.'));
      else if (!pool) box.append(el('h2', `No hay ${plural} activas`), el('p', `Hay ${inactiveOnly} inactiva${inactiveOnly === 1 ? '' : 's'}: marca «Incluir inactivas» para verlas.`));
      else {
        box.append(el('h2', 'Sin resultados'), el('p', 'No hay resultados con estos filtros.'));
        const again = el('button', 'Limpiar filtros'); again.type = 'button'; again.addEventListener('click', () => clear.click()); box.append(again);
      }
      wrap.append(box);
    };
    const rowOf = (r, cols) => {
      const tr = el('tr'); tr.dataset.id = r.id;
      for (const [, get] of cols) { const v = get(r); const td = el('td'); if (v instanceof Node) td.append(v); else td.textContent = v ?? '—'; tr.append(td); }
      return tr;
    };
    const headOf = cols => { const head = el('thead'); const hr = el('tr'); cols.forEach(([name]) => { const th = el('th', name); th.scope = 'col'; hr.append(th); }); head.append(hr); return head; };
    // Fila de grupo plegable (País o Ciudad) dentro de la tabla de Sedes agrupada.
    const groupRow = (body, key, label, count, city, note) => {
      const open = !collapsed.has(key);
      const tr = el('tr', undefined, city ? 'group-row city' : 'group-row'); const th = el('th'); th.colSpan = columns.length; tr.append(th);
      const b = el('button', undefined, 'group-toggle'); b.type = 'button'; b.dataset.group = key; b.setAttribute('aria-expanded', String(open));
      const caret = el('span', open ? '▾' : '▸', 'group-caret'); caret.setAttribute('aria-hidden', 'true');
      b.append(caret, el('span', label, 'group-label'), el('span', `${count} ${count === 1 ? 'sede' : 'sedes'}`, 'group-count'));
      b.addEventListener('click', () => {
        if (open) collapsed.add(key); else collapsed.delete(key);
        renderTable(); [...wrap.querySelectorAll('button[data-group]')].find(x => x.dataset.group === key)?.focus();
      });
      th.append(b); if (note) th.append(el('small', note, 'group-note'));
      body.append(tr); return open;
    };
    const renderGrouped = (body, rows) => {
      const groups = new Map();
      for (const r of rows) {
        const gk = r.country_code || NO_COUNTRY;
        if (!groups.has(gk)) groups.set(gk, { key: gk, label: r.country_code ? countryName(r.country_code) : 'Sin ubicación', rows: [], cities: new Map() });
        const g = groups.get(gk); g.rows.push(r);
        const ck = orgFold(r.city);
        if (!g.cities.has(ck)) g.cities.set(ck, { key: `${gk}|${ck}`, label: r.city || 'Sin ciudad', rows: [] });
        const c = g.cities.get(ck); c.rows.push(r);
        // Misma ciudad escrita distinto («cali» / «Cali»): un grupo, rotulado con la forma con mayúscula si existe.
        if (r.city && c.label === c.label.toLocaleLowerCase('es') && r.city !== r.city.toLocaleLowerCase('es')) c.label = r.city;
      }
      const ordered = [...groups.values()].sort((a, b) => (a.key === NO_COUNTRY) - (b.key === NO_COUNTRY) || a.label.localeCompare(b.label, 'es'));
      for (const g of ordered) {
        const pending = g.key === NO_COUNTRY;
        if (!groupRow(body, `c:${g.key}`, g.label, g.rows.length, false, pending ? 'Sin país: usan el calendario de festivos predeterminado del cliente.' : '')) continue;
        if (pending) { g.rows.forEach(r => body.append(rowOf(r, columns))); continue; }
        const cities = [...g.cities.values()].sort((a, b) => (a.key.endsWith('|')) - (b.key.endsWith('|')) || a.label.localeCompare(b.label, 'es'));
        for (const c of cities) if (groupRow(body, `c:${c.key}`, c.label, c.rows.length, true)) c.rows.forEach(r => body.append(rowOf(r, columns)));
      }
    };
    // Vista Dependencias: el parent_id tal cual. Con búsqueda se muestran también las unidades de las que dependen los resultados.
    const renderTree = () => {
      const map = unitMap(); const term = orgFold(state.term);
      const hit = o => (state.inactive || o.active) && (!term || [o.name, pathOf(o, map), kinds[o.kind]].some(v => orgFold(v).includes(term)));
      const pool = org.filter(o => state.inactive || o.active).length;
      const show = new Set(); let hits = 0;
      for (const o of org) {
        if (!hit(o)) continue; hits++;
        for (let x = o; x && !show.has(x.id); x = x.parent_id ? map.get(x.parent_id) : null) show.add(x.id);
      }
      wrap.replaceChildren();
      if (!hits) { noRows(org.length, pool, 'unidades', null); return; }
      const kids = new Map(); const roots = [];
      for (const o of org) {
        if (!show.has(o.id)) continue;
        if (o.parent_id && map.has(o.parent_id)) kids.set(o.parent_id, [...(kids.get(o.parent_id) || []), o]); else roots.push(o);
      }
      const cols = [['Unidad', null], ['Tipo', o => o.kind === 'site' && placeOf(o) !== '—' ? `${kinds[o.kind]} · ${placeOf(o)}` : (kinds[o.kind] || o.kind)],
        ['Personas activas', peopleCell], ['Estado', o => badge(o.active ? 'Activa' : 'Inactiva', o.active ? 'good' : '')], ['Acciones', o => actions(o)]];
      const t = el('table', undefined, 'org-tree');
      t.append(el('caption', `${hits} de ${pool} unidades ${state.inactive ? '(activas e inactivas)' : 'activas'}${show.size > hits ? ' · en gris, las unidades de las que dependen' : ''}`));
      t.append(headOf(cols));
      const body = el('tbody'); const drawn = new Set();
      const add = (o, depth) => {
        if (drawn.has(o.id)) return; drawn.add(o.id);
        const tr = rowOf(o, cols.slice(1)); tr.dataset.depth = String(depth); if (!hit(o)) tr.classList.add('context-row');
        const name = el('div', undefined, 'tree-name'); name.style.setProperty('--depth', String(depth));
        if (depth) { const branch = el('span', '└', 'tree-branch'); branch.setAttribute('aria-hidden', 'true'); name.append(branch); }
        name.append(el('span', o.name));
        if (depth) name.append(el('span', `, depende de ${map.get(o.parent_id).name}`, 'sr-only'));
        else if (o.parent_id && !map.has(o.parent_id)) name.append(el('small', 'Depende de una unidad que tu cuenta no ve.'));
        const td = el('td'); td.append(name); tr.prepend(td); body.append(tr);
        for (const c of (kids.get(o.id) || []).sort(byKindName)) add(c, depth + 1);
      };
      roots.sort((a, b) => Boolean(a.parent_id) - Boolean(b.parent_id) || byKindName(a, b)).forEach(o => add(o, 0));
      for (const o of org) if (show.has(o.id) && !drawn.has(o.id)) add(o, 0);
      t.append(body); wrap.append(t);
    };
    const renderTable = () => {
      clear.disabled = !(state.term || state.inactive || (sites && (state.country || state.city))); persist();
      if (tree) { renderTree(); return; }
      const ofKind = org.filter(o => o.kind === tab.kind);
      const pool = ofKind.filter(o => state.inactive || o.active);
      const rows = pool.filter(matches).sort((a, b) => (b.active - a.active) || a.name.localeCompare(b.name, 'es'));
      wrap.replaceChildren();
      if (!rows.length) { noRows(ofKind.length, pool.length, tab.plural, tab.create); return; }
      const grouped = sites && state.group;
      const t = el('table'); t.append(el('caption', `${rows.length} de ${pool.length} ${tab.plural} ${state.inactive ? '(activas e inactivas)' : 'activas'}${grouped ? ' · agrupadas por país y ciudad' : ''}`));
      const body = el('tbody');
      if (grouped) renderGrouped(body, rows); else rows.forEach(r => body.append(rowOf(r, columns)));
      t.append(headOf(columns), body); wrap.append(t);
    };
    renderTable();
  };
  const save = async (o, changes) => {
    const body = { kind: o.kind, name: o.name, parent_id: o.parent_id, active: o.active, ...changes };
    const updated = await api('PATCH', `/organization/${o.id}`, { body, ifMatch: o.version });
    org = org.map(x => x.id === o.id ? updated : x); render();
  };
  const parentPicker = (parent, kind, value, excludeId, help) => orgParentPicker(parent, { org, kind, value, excludeId, help });
  const countryHint = SITE_COUNTRY_HINT;
  const actions = o => {
    const box = el('div', undefined, 'row-actions');
    const rename = el('button', 'Editar', 'link-button'); rename.type = 'button';
    rename.addEventListener('click', () => {
      const { dialog, body, ok, show } = dialogShell(`Editar ${o.name}`);
      const name = labeled(body, 'Nombre', el('input')); name.maxLength = 120; name.value = o.name;
      const count = usedBy(o);
      if (count) body.append(el('p', `${count} personas activas tienen esta unidad: verán el nombre nuevo.`, 'help'));
      else if (count === null) body.append(el('p', 'No se puede consultar cuántas personas tienen esta unidad: todas las que la tengan verán el nombre nuevo.', 'help'));
      const parent = parentPicker(body, o.kind, o.parent_id, o.id, 'Por ejemplo, un área que pertenece a una sede, o un cargo que depende de un área. No aparecen esta unidad ni las que dependen de ella: harían un círculo.');
      let country = null, city = null;
      if (o.kind === 'site') {
        country = labeled(body, 'País', countrySelect(o.country_code), countryHint);
        const warn = el('p', 'Cambiar el país puede cambiar el calendario automático de festivos de las personas de esta sede.', 'help'); warn.setAttribute('role', 'status'); warn.hidden = true; body.append(warn);
        city = labeled(body, 'Ciudad', cityInput(o.city, sitesIn(o.country_code || NO_COUNTRY)));
        country.addEventListener('change', () => { warn.hidden = country.value === (o.country_code || ''); fillCities($(city.getAttribute('list')), country.value || NO_COUNTRY); });
      }
      dialog.querySelector('form').addEventListener('submit', async ev => {
        ev.preventDefault(); if (!name.value.trim()) { show('Escribe el nombre.'); return; }
        ok.disabled = true;
        try { await save(o, { name: name.value.trim(), parent_id: parent.value || null, ...(country ? { country_code: country.value || null, city: city.value.trim() || null } : {}) }); dialog.close(); }
        catch (e) { show(e.status === 422 && !e.problem?.detail ? 'Esa dependencia no es válida (por ejemplo, haría un círculo o la unidad superior está inactiva).' : e); ok.disabled = false; }
      });
      dialog.showModal();
    });
    // Desactivar y eliminar quedan en "Más": no son la acción frecuente y la API puede negarlas por usos que el panel no ve.
    const more = el('details', undefined, 'row-more'); more.append(el('summary', 'Más'));
    const menu = el('div'); more.append(menu);
    more.addEventListener('toggle', () => { if (more.open) document.querySelectorAll('details.row-more[open]').forEach(d => { if (d !== more) d.open = false; }); });
    const toggle = el('button', o.active ? 'Desactivar' : 'Activar', 'link-button'); toggle.type = 'button';
    toggle.addEventListener('click', async () => {
      if (o.active && !confirm(`¿Desactivar ${o.name}?`)) return;
      try { quiet(); await save(o, { active: !o.active }); }
      catch (e) {
        const count = usedBy(o);
        if (e.code === 'org_unit_in_use') tell(`No se puede desactivar «${o.name}». La API indica que todavía la usan personas (activas o inactivas), unidades activas que dependen de ella, alcances de roles o reglas de los equipos${count ? ` (${count} personas activas visibles la tienen asignada)` : ''}. Cámbialo primero en Personas, Roles o Reglas y vuelve a intentarlo.`);
        else if (e.code === 'inactive_org_ancestor') tell(`No se puede activar «${o.name}»: la unidad de la que depende está inactiva. Actívala primero o cambia la dependencia.`);
        else tell(errorMessage(e));
      }
    });
    // Eliminar: desaparece del panel; si el historial la menciona se conserva como eliminada para ese historial.
    const remove = el('button', 'Eliminar', 'link-button danger'); remove.type = 'button';
    remove.addEventListener('click', async () => {
      if (!confirm(`¿Eliminar "${o.name}"? Desaparece de Organización y de las listas. El historial de quien la tuvo se conserva.`)) return;
      try { quiet(); await api('DELETE', `/organization/${o.id}`, { ifMatch: o.version }); org = org.filter(x => x.id !== o.id); render(); $('page-status').textContent = `«${o.name}» eliminada.`; }
      catch (e) {
        const uses = (e.problem?.detail || '').match(/^org_unit_in_use:(.+)$/)?.[1];
        tell(uses ? `No se puede eliminar «${o.name}»: la usan ${uses}. Cámbialos primero y vuelve a intentar.` : errorMessage(e));
      }
    });
    menu.append(toggle, remove); box.append(rename, more); return box;
  };
  action.addEventListener('click', () => {
    const tab = current(); if (!tab.create || state.view === 'tree') return;
    // Sin país por defecto: solo se sugiere el del filtro actual, y se dice.
    const suggested = state.country && state.country !== NO_COUNTRY ? state.country : '';
    openOrgCreate({ kind: tab.kind, org, country: suggested, city: suggested && state.city.trim() ? state.city.trim() : '', onCreated: created => {
      org = [...org, created]; quiet();
      // Si la sede cae en un grupo plegado, se despliega para poder verla.
      if (created.kind === 'site') { const gk = created.country_code || NO_COUNTRY; collapsed.delete(`c:${gk}`); collapsed.delete(`c:${gk}|${orgFold(created.city)}`); }
      render();
      const visible = document.querySelector(`#org-panel tr[data-id="${created.id}"]`);
      $('page-status').textContent = visible ? `«${created.name}» creada.` : `«${created.name}» creada, pero no coincide con los filtros de esta vista.`;
    } });
  });
  render();
}

async function assignments() {
  const [org, users, devices] = await Promise.all([optional(allPages('/organization')), allPages('/users'), allPages(`/tenants/${tenant}/devices`)]);
  const n = names(org || []); const people = new Map(users.map(u => [u.id, u]));
  const active = devices.filter(d => d.status === 'active');
  const withoutDevice = users.filter(u => u.status === 'active' && !active.some(d => d.user_id === u.id)).length;
  kpis([['Equipos activos', String(active.length)], ['Personas con equipo', String(new Set(active.map(d => d.user_id)).size)], ['Personas sin equipo', String(withoutDevice)],
    ['En línea', `${active.filter(online).length} / ${active.length}`, 'Con señal en los últimos 5 minutos']]);
  const s = surface('Equipos y personas');
  s.append(el('p', 'Reasignar cambia a quién pertenece el equipo desde este momento: el agente vuelve a iniciar sesión y aplica las reglas de la nueva persona. El historial anterior queda con la persona anterior.', 'help'));
  const row = d => { const u = people.get(d.user_id); return { d, u, site: u ? n.get(u.site_id) : null, area: u ? n.get(u.area_id) : null, position: u ? n.get(u.position_id) : null }; };
  const link = (text, href) => { const a = el('a', text); a.href = href; return a; };
  table(s, 'Asignaciones', [['Persona', r => r.u ? link(r.u.display_name, `/miembro.php?id=${encodeURIComponent(r.u.id)}`) : 'Fuera de tu alcance'],
    ['Equipo', r => link(r.d.hostname, `/equipo.php?id=${encodeURIComponent(r.d.id)}`)], ['Sede', r => r.site || '—'], ['Área', r => r.area || '—'], ['Cargo', r => r.position || '—'],
    ['Última conexión', r => when(r.d.last_seen_at)], ['Acción', r => reassign(r.d)]], active.map(row),
    { filters: [['Sede', r => r.site], ['Área', r => r.area]] });
  function reassign(d) {
    const b = el('button', 'Reasignar', 'link-button'); b.type = 'button';
    b.addEventListener('click', () => {
      const dialog = el('dialog'); const form = el('form');
      form.append(el('h2', `Reasignar ${d.hostname}`));
      const who = el('select'); users.filter(u => u.status === 'active' && u.id !== d.user_id).sort((a, c) => a.display_name.localeCompare(c.display_name, 'es')).forEach(u => who.append(new Option(u.display_name, u.id)));
      const reason = el('input'); reason.maxLength = 500; reason.value = 'Reasignación desde el panel';
      for (const [label, input] of [['Nueva persona', who], ['Motivo', reason]]) { const l = el('label', label); l.append(input); form.append(l); }
      const error = el('p', '', 'error-text'); error.hidden = true;
      const footer = el('div', undefined, 'dialog-footer'); const cancel = el('button', 'Cancelar'); cancel.type = 'button'; const ok = el('button', 'Reasignar', 'primary'); ok.type = 'submit';
      if (!who.options.length) { ok.disabled = true; error.textContent = 'No hay otra persona activa a quien asignarlo.'; error.hidden = false; }
      footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
      cancel.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove());
      form.addEventListener('submit', async ev => {
        ev.preventDefault(); ok.disabled = true;
        try {
          await api('POST', `/devices/${d.id}/assignment`, { body: { user_id: who.value, reason: reason.value.trim() || 'Reasignación desde el panel' }, ifMatch: d.version, idempotencyKey: crypto.randomUUID() });
          dialog.close(); $('page-content').replaceChildren(); await assignments(); done();
        } catch (e) { error.textContent = errorMessage(e); error.hidden = false; ok.disabled = false; }
      });
      dialog.showModal();
    });
    return b;
  }
}

// ---- Cuentas del panel ------------------------------------------------------------------------------------------
const moduleNames = { usuarios: 'Personas', equipos: 'Equipos', reglas: 'Reglas', actividad: 'Actividad', presencia: 'Presencia', reportes: 'Reportes',
  organizacion: 'Organización', horarios: 'Horarios', festivos: 'Festivos', cumplimiento: 'Doble empleo y apps sospechosas', cobertura: 'Cobertura de instalación',
  hardening: 'Seguridad de cuentas de Windows', roles: 'Roles y permisos', tiers: 'Planes', empresas: 'Empresa', notificaciones: 'Notificaciones',
  integraciones: 'Integraciones', auditoria: 'Auditoría', operacion: 'Operación del panel', releases: 'Versiones del agente', migration: 'Migración desde K3', permisos: 'Catálogo de permisos' };
// Editor de un acceso: rol (qué puede hacer) + alcance (qué datos ve), con la frase legible al pie (§4 y §5).
// El alcance siempre se muestra y se envía tal cual: cambiar el rol no lo toca, y ampliarlo exige elegirlo.
// groupOnly: cuenta de grupo en otra empresa, solo «todo» o filtros; sin acceso previo no hay alcance por defecto.
function accessEditor(parent, { roles, org, current = null, groupOnly = false }) {
  const box = el('fieldset', undefined, 'perm-group'); box.append(el('legend', 'Acceso al panel')); parent.append(box);
  const n = names(org);
  const role = el('select'); role.append(new Option('Elige el rol…', ''));
  roles.filter(r => r.active || r.id === current?.role_id).sort((a, b) => a.name.localeCompare(b.name, 'es'))
    .forEach(r => role.append(new Option(r.active ? r.name : `${r.name} (inactivo)`, r.id)));
  role.value = current?.role_id || ''; labeled(box, 'Rol (qué puede hacer)', role);
  const kind = el('select');
  if (groupOnly && !current) kind.append(new Option('Elige el alcance…', ''));
  [['all', 'Todo el cliente'], ['filters', 'Solo ciertas empresas, ciudades, sedes o áreas'], ...(groupOnly ? [] : [['team', TEAM_LABEL], ['self', 'Solo lo propio']])]
    .forEach(([v, t]) => kind.append(new Option(t, v)));
  kind.value = current?.scope.kind || (groupOnly ? '' : 'self'); labeled(box, 'Alcance (qué datos ve)', kind);
  const filters = el('div'); box.append(filters);
  // Revisión UX ACC-1: la intersección se explica y se lee con la selección actual, no se deja a la intuición.
  filters.append(el('p', SCOPE_RULE, 'help'));
  const had = key => current?.scope[key] || [];
  // Lo que ya tiene se conserva aunque la sede o el área esté inactiva: guardar no le quita alcance a escondidas.
  const units = (k, key) => org.filter(o => o.kind === k && (o.active || had(key).includes(o.id))).sort((a, b) => a.name.localeCompare(b.name, 'es')).map(o => [o.id, o.name]);
  const cities = [...new Set([...org.filter(o => o.kind === 'site' && o.city).map(o => o.city), ...had('cities')])].sort((a, b) => a.localeCompare(b, 'es')).map(c => [c, c]);
  const picked = {};
  for (const [key, title, items] of [['company_ids', 'Empresas', units('company', 'company_ids')], ['cities', 'Ciudades', cities], ['site_ids', 'Sedes', units('site', 'site_ids')], ['area_ids', 'Áreas', units('area', 'area_ids')]]) {
    const fs = el('fieldset', undefined, 'perm-group'); fs.append(el('legend', `${title} (sin marcar = todas)`)); filters.append(fs);
    if (items.length) picked[key] = checks(fs, items, had(key));
    else { picked[key] = []; fs.append(el('p', `No hay ${title.toLowerCase()} creadas.`, 'help')); }
  }
  const conditions = el('p', '', 'help'); filters.append(conditions);
  const line = el('p', '', 'help'); line.setAttribute('aria-live', 'polite'); box.append(line);
  const read = () => {
    if (!role.value) return null;
    const scope = { kind: kind.value, company_ids: [], cities: [], site_ids: [], area_ids: [] };
    if (kind.value === 'filters') for (const key of Object.keys(picked)) scope[key] = picked[key].filter(c => c.checked).map(c => c.value);
    return { role_id: role.value, scope };
  };
  const problem = () => {
    const a = read(); if (!a) return 'Elige el rol.';
    if (!a.scope.kind) return 'Elige el alcance.';
    return a.scope.kind === 'filters' && !['company_ids', 'cities', 'site_ids', 'area_ids'].some(k => a.scope[k].length) ? SCOPE_INCOMPLETE : null;
  };
  const valid = () => problem() === null;
  const sync = () => {
    filters.hidden = kind.value !== 'filters'; const a = read();
    line.textContent = !a ? (current ? 'Sin rol elegido: conserva su acceso actual.' : 'Sin rol elegido: no tendrá acceso al panel.')
      : !a.scope.kind ? 'Elige el alcance: qué datos verá.' : scopeSummary(role.selectedOptions[0].textContent, a.scope, n);
    // Se calcula aunque falte el rol: la combinación depende solo de las casillas.
    const marked = { kind: 'filters' }; for (const key of Object.keys(picked)) marked[key] = picked[key].filter(c => c.checked).map(c => c.value);
    conditions.textContent = scopeConditions(marked, n) || SCOPE_INCOMPLETE;
  };
  box.addEventListener('change', sync); sync();
  return { read, valid, problem };
}
const SCOPE_INCOMPLETE = 'Marca al menos una empresa, ciudad, sede o área, o elige otro alcance.';
function dialogShell(title) {
  const dialog = el('dialog'); const form = el('form'); form.noValidate = true; form.append(el('h2', title));
  const body = el('div', undefined, 'dialog-body'); form.append(body);
  const error = el('p', '', 'error-text'); error.hidden = true;
  const footer = el('div', undefined, 'dialog-footer'); const cancel = el('button', 'Cancelar'); cancel.type = 'button'; const ok = el('button', 'Guardar', 'primary'); ok.type = 'submit';
  footer.append(cancel, ok); form.append(error, footer); dialog.append(form); document.body.append(dialog);
  cancel.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove());
  const show = e => { error.textContent = typeof e === 'string' ? e : errorMessage(e); error.hidden = false; };
  return { dialog, form, body, ok, cancel, show };
}
function labeled(parent, text, input, help) { const l = el('label', text); l.append(input); parent.append(l); if (help) parent.append(el('p', help, 'help')); return input; }
function checks(parent, items, selected = []) {
  const box = el('div', undefined, 'check-list'); parent.append(box);
  return items.map(([value, text, note]) => { const l = el('label', undefined, 'check'); const c = el('input'); c.type = 'checkbox'; c.value = value; c.checked = selected.includes(value); l.append(c, ` ${text}`); if (note) l.append(el('small', note)); box.append(l); return c; });
}
// La reautenticacion protege crear cuentas y restablecer contraseñas: se pide la contraseña de quien opera.
async function reauth(password) { await api('POST', '/auth/reauth', { body: { password } }); }
function showSecret(account, secret) {
  const { dialog, body, ok, cancel } = dialogShell('Contraseña temporal');
  body.append(el('p', `Entrégale esta contraseña a ${account.user_name || account.display_name || account.email}. Solo se muestra ahora: al entrar deberá cambiarla.`));
  const pre = el('pre', secret, 'secret'); body.append(pre);
  body.append(el('p', `Correo para entrar: ${account.email}`, 'help'));
  cancel.hidden = true; ok.textContent = 'Listo'; ok.type = 'button'; ok.addEventListener('click', () => dialog.close());
  const copy = el('button', 'Copiar contraseña'); copy.type = 'button'; copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(secret); copy.textContent = 'Copiada'; } catch { copy.textContent = 'Selecciónala y cópiala'; } });
  body.append(copy); dialog.showModal();
}

// Enlace a una pantalla de una empresa cliente: fija esa empresa en el selector antes de navegar.
function clientLink(text, tenantId, href, className = 'link-button') {
  const a = el('a', text, className); a.href = href;
  a.addEventListener('click', () => { try { sessionStorage.setItem('keeper.presentation.tenant', tenantId); } catch { /* sin almacenamiento */ } });
  return a;
}
// Empresas visibles para la plataforma (la duena primero). Se carga en start() solo para superadmin.
let companies = [];
const ownerName = () => companies.find(c => c.id === PLATFORM_TENANT)?.branding?.display_name || 'AZC Keeper';
const companyName = id => { const c = companies.find(x => x.id === id); return c ? (c.branding?.display_name || c.name) : 'Empresa'; };

async function accounts() {
  const session = readSession(); const platform = !!session?.is_platform_admin;
  const list = await api('GET', '/panel-accounts');
  const groups = platform ? ((await optional(api('GET', '/company-groups')))?.data || []) : [];
  const groupOf = tenantId => groups.find(g => g.tenant_ids.includes(tenantId)) || null;
  // Roles y nombres de sedes/areas por empresa (una cuenta solo tiene roles de su empresa).
  const perCompany = new Map();
  const companyData = async id => {
    if (!perCompany.has(id)) perCompany.set(id, Promise.all([optional(allPages('/roles', id)), optional(allPages('/organization', id))])
      .then(([roles, org]) => ({ roles: roles || [], org: org || [], n: names(org || []) })));
    return perCompany.get(id);
  };
  const ids = [...new Set(list.data.filter(a => a.kind === 'company').map(a => a.tenant_id))];
  const data = new Map(await Promise.all(ids.map(async id => [id, await companyData(id)])));
  const accessText = a => {
    if (a.kind === 'platform') return 'Todo, en todas las empresas';
    const own = a.access ? scopeSummary(a.access.role_name, a.access.scope, data.get(a.tenant_id)?.n || new Map()) : 'Sin acceso: no verá nada';
    return a.group_id ? `${own} · Grupo ${a.group_name}: también ${a.group_access.map(g => g.tenant_name).join(', ') || 'ninguna otra empresa aún'}` : own;
  };
  // ?empresa=<id> muestra solo las cuentas de esa empresa; con &crear=1 además abre la creación para ella.
  const params = new URLSearchParams(location.search);
  const only = platform && isUuid(params.get('empresa')) && params.get('empresa') !== PLATFORM_TENANT && companies.some(c => c.id === params.get('empresa')) ? params.get('empresa') : null;
  const render = all => {
    const rows = only ? all.filter(a => a.kind === 'company' && a.tenant_id === only) : all;
    $('page-content').replaceChildren();
    if (only) {
      const note = el('p', `Mostrando solo las cuentas de ${companyName(only)}. `, 'notice');
      const allLink = el('a', 'Ver todas las cuentas'); allLink.href = '/cuentas.php';
      const add = el('button', `Crear cuenta para ${companyName(only)}`, 'link-button'); add.type = 'button'; add.addEventListener('click', () => create(only));
      note.append(allLink, ' · ', add); $('page-content').append(note);
    }
    kpis([['Cuentas', String(rows.length)], ['Superadmin', String(rows.filter(a => a.kind === 'platform').length), `De ${ownerName()}`],
      ['De empresas', String(rows.filter(a => a.kind === 'company').length)],
      ['Pendientes de primer ingreso', String(rows.filter(a => a.must_change_password && a.active).length), 'Con contraseña temporal sin cambiar']]);
    const s = surface('Cuentas con acceso al panel');
    s.append(el('p', platform
      ? `Las cuentas de ${ownerName()} son superadmin: administran todas las empresas. Las de una empresa pertenecen a una persona y ven lo que permita su acceso: un rol y un alcance (todo, ciertas empresas, ciudades, sedes o áreas, su equipo o solo lo propio).`
      : 'Cada cuenta pertenece a una persona de tu empresa y ve lo que permita su acceso: un rol y un alcance (todo, ciertas empresas, ciudades, sedes o áreas, su equipo o solo lo propio).', 'help'));
    const columns = [['Cuenta', a => { const d = el('div'); d.append(el('strong', a.user_name || a.display_name || a.email), el('small', a.email)); return d; }]];
    if (platform) columns.push(['Empresa', a => a.kind === 'platform' ? badge(`${ownerName()} · superadmin`, 'info') : a.tenant_name]);
    columns.push(['Acceso', accessText],
      ['Estado', a => badge(!a.active ? 'Desactivada' : a.must_change_password ? 'Debe crear su contraseña' : 'Activa', !a.active ? 'bad' : a.must_change_password ? 'warn' : 'good')],
      ['Último ingreso', a => when(a.last_login_at)], ['Acciones', a => actionsFor(a)]);
    table(s, 'Cuentas', columns, rows, { filters: platform ? [['Empresa', a => a.kind === 'platform' ? ownerName() : a.tenant_name]] : [],
      search: a => [a.user_name, a.display_name, a.email, a.tenant_name] });
  };
  const refresh = async () => {
    list.data = (await api('GET', '/panel-accounts')).data;
    for (const id of new Set(list.data.filter(a => a.kind === 'company').map(a => a.tenant_id))) if (!data.has(id)) data.set(id, await companyData(id));
    render(list.data); done();
  };
  const actionsFor = a => {
    const box = el('div', undefined, 'actions no-margin'); const opts = { tenant: a.tenant_id };
    const button = (text, fn) => { const b = el('button', text, 'link-button'); b.type = 'button'; b.addEventListener('click', fn); box.append(b); };
    if (a.kind === 'company') button('Acceso', () => editAccess(a));
    if (a.kind === 'company' && platform && groupOf(a.tenant_id)) button('Grupo', () => editGroup(a));
    button(a.active ? 'Desactivar' : 'Activar', async () => {
      if (a.active && !confirm(`¿Desactivar la cuenta ${a.email}? Se cierran sus sesiones.`)) return;
      try { await api('PATCH', `/panel-accounts/${a.id}`, { ...opts, body: { active: !a.active } }); await refresh(); } catch (e) { fail(e); }
    });
    button('Restablecer contraseña', () => {
      const { dialog, body, ok, show } = dialogShell(`Restablecer contraseña de ${a.email}`);
      body.append(el('p', 'Se genera una contraseña temporal nueva y se cierran las sesiones de esa cuenta.'));
      const pw = labeled(body, 'Tu contraseña (confirmación)', el('input')); pw.type = 'password'; pw.autocomplete = 'current-password';
      ok.textContent = 'Restablecer';
      dialog.querySelector('form').addEventListener('submit', async ev => {
        ev.preventDefault(); ok.disabled = true;
        try { await reauth(pw.value); const r = await api('POST', `/panel-accounts/${a.id}/reset-password`, opts); dialog.close(); showSecret(r.account, r.temporary_password); await refresh(); }
        catch (e) { show(e.code === 'invalid_credentials' ? 'Tu contraseña no es correcta.' : e); ok.disabled = false; }
      });
      dialog.showModal();
    });
    return box;
  };
  // Cuenta de grupo (dueños y directivos): un acceso (rol + alcance) en cada una de las OTRAS empresas del grupo.
  // Su empresa casa usa "Acceso". Devuelve { read, problem } o null si la empresa no está en un grupo.
  const groupSection = async (parent, home, current) => {
    const g = groupOf(home); if (!g) return null;
    const box = el('fieldset', undefined, 'perm-group'); box.append(el('legend', `Grupo empresarial: ${g.name}`)); parent.append(box);
    const on = el('input'); on.type = 'checkbox'; on.checked = !!current?.group_id;
    const ol = el('label', undefined, 'check'); ol.append(on, ' Cuenta de grupo: entra también a otras empresas del grupo'); box.append(ol);
    box.append(el('p', 'Para dueños y directivos. No es superadmin: solo ve las empresas y lo que le des aquí.', 'help'));
    const inner = el('div'); box.append(inner);
    const picks = [];
    for (const tid of g.tenant_ids.filter(t => t !== home)) {
      const d = await companyData(tid);
      inner.append(el('h3', companyName(tid)));
      const given = current?.group_access?.find(x => x.tenant_id === tid) || null;
      const use = el('input'); use.type = 'checkbox'; use.checked = !!given;
      const ul = el('label', undefined, 'check'); ul.append(use, ' Tiene acceso en esta empresa'); inner.append(ul);
      const holder = el('div'); inner.append(holder);
      const ed = d.roles.length ? accessEditor(holder, { roles: d.roles, org: d.org, current: given, groupOnly: true }) : (holder.append(el('p', 'Esta empresa no tiene roles todavía.', 'help')), null);
      const syncUse = () => { holder.hidden = !use.checked; }; use.addEventListener('change', syncUse); syncUse();
      picks.push([tid, use, ed]);
    }
    const sync = () => { inner.hidden = !on.checked; }; on.addEventListener('change', sync); sync();
    // Una empresa marcada sin rol o sin alcance completo no se descarta en silencio: se pide completarla.
    const problem = () => {
      if (!on.checked) return null;
      for (const [tid, use, ed] of picks) {
        if (!use.checked) continue;
        const p = ed ? ed.problem() : 'Esta empresa no tiene roles todavía.';
        if (p) return `${companyName(tid)}: ${p}`;
      }
      return null;
    };
    const read = () => on.checked
      ? { group_id: g.id, group_access: picks.filter(([, use, ed]) => use.checked && ed?.read()).map(([tid, , ed]) => ({ tenant_id: tid, ...ed.read() })) }
      : { group_id: null, group_access: [] };
    return { read, problem };
  };
  const editGroup = async a => {
    const { dialog, body, ok, show } = dialogShell(`Grupo de ${a.user_name || a.email}`);
    dialog.classList.add('wide-dialog');
    const group = await groupSection(body, a.tenant_id, a);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      const p = group?.problem(); if (p) { show(p); return; }
      ok.disabled = true;
      try { await api('PATCH', `/panel-accounts/${a.id}`, { tenant: a.tenant_id, body: { active: a.active, ...group.read() } }); dialog.close(); await refresh(); }
      catch (e) { show(e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const editAccess = async a => {
    const d = await companyData(a.tenant_id);
    const { dialog, body, ok, show } = dialogShell(`Acceso de ${a.user_name || a.email}`);
    dialog.classList.add('wide-dialog');
    if (!d.roles.length) body.append(el('p', 'Esta empresa aún no tiene roles: créalos en Roles y permisos.', 'help'));
    const ed = accessEditor(body, { roles: d.roles, org: d.org, current: a.access });
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      const p = ed.problem(); if (p) { show(p); return; }
      if (sameAccess(ed.read(), a.access)) { dialog.close(); return; }
      ok.disabled = true;
      try { await api('PATCH', `/panel-accounts/${a.id}`, { tenant: a.tenant_id, body: { active: a.active, access: ed.read() } }); dialog.close(); await refresh(); }
      catch (e) { show(e.code === 'tenant_rbac_disabled' ? 'La empresa no tiene habilitada la gestión de sus propios roles: pídesela a la plataforma.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  // personId: la persona ya elegida (desde su ficha o el alta: «Crear cuenta del panel para esta persona»).
  const create = (preset, personId = null) => {
    const { dialog, body, ok, show } = dialogShell('Crear cuenta del panel');
    // La empresa decide el tipo: la duena da superadmin; cualquier otra, una cuenta de esa empresa ligada a una persona.
    const company = el('select');
    if (platform) {
      company.append(new Option(`${ownerName()} (dueña) · superadmin de todo`, PLATFORM_TENANT));
      companies.filter(c => c.id !== PLATFORM_TENANT && c.status === 'active').forEach(c => company.append(new Option(c.branding?.display_name || c.name, c.id)));
      company.value = companies.some(c => c.id === preset) ? preset : PLATFORM_TENANT;
      labeled(body, '¿De qué empresa es la cuenta?', company);
    } else { company.append(new Option('', session.tenant_id)); company.value = session.tenant_id; }
    const email = labeled(body, 'Correo para entrar', el('input')); email.type = 'email'; email.maxLength = 254; email.autocomplete = 'off';
    const ownerBox = el('div'); body.append(ownerBox);
    const display = labeled(ownerBox, 'Nombre', el('input')); display.maxLength = 160;
    ownerBox.append(el('p', 'Superadmin: entra a todas las empresas, crea empresas y cuentas, y cambia la marca del producto.', 'help'));
    const companyBox = el('div'); body.append(companyBox);
    let person = null, accessEd = null, group = null; let wanted = personId;
    const drawCompany = async id => {
      companyBox.replaceChildren(el('p', 'Cargando personas y roles…', 'help'));
      const [users, d] = await Promise.all([allPages('/users', id), companyData(id)]);
      companyBox.replaceChildren();
      person = el('select'); person.append(new Option('Elige la persona…', ''));
      const withAccount = new Set(list.data.filter(a => a.user_id).map(a => a.user_id));
      users.filter(u => u.status === 'active' && !withAccount.has(u.id)).sort((x, y) => x.display_name.localeCompare(y.display_name, 'es'))
        .forEach(u => person.append(new Option(`${u.display_name}${u.email ? ' · ' + u.email : ''}`, u.id)));
      labeled(companyBox, '¿A quién pertenece?', person, users.length ? 'Si la persona no aparece, créala primero en Personas (o ya tiene cuenta).' : 'Esta empresa aún no tiene personas: créalas primero en Personas.');
      if (person.options.length < 2) {
        // Sin candidatos no hay cuenta posible: toda cuenta de empresa pertenece a una persona, que va primero.
        const need = el('p', users.length ? 'Todas las personas activas de esta empresa ya tienen cuenta. ' : 'Requisito pendiente: esta empresa aún no tiene personas. ', 'notice');
        need.append(clientLink('Crear la persona en Personas', id, '/usuarios.php', ''));
        companyBox.append(need);
      }
      person.addEventListener('change', () => { const u = users.find(x => x.id === person.value); if (u?.email && !email.value) email.value = u.email; });
      if (wanted) {
        if ([...person.options].some(o => o.value === wanted)) { person.value = wanted; person.dispatchEvent(new Event('change')); }
        else companyBox.prepend(el('p', 'Esa persona no está disponible para una cuenta nueva: ya tiene cuenta o está inactiva.', 'notice'));
        wanted = null;
      }
      accessEd = d.roles.length ? accessEditor(companyBox, { roles: d.roles, org: d.org, current: null }) : null;
      if (!d.roles.length) companyBox.append(el('p', 'Aún no hay roles en esta empresa: créalos en Roles y permisos. Sin rol, la cuenta no verá nada.', 'help'));
      group = platform ? await groupSection(companyBox, id, null) : null;
    };
    const sync = () => {
      const owner = company.value === PLATFORM_TENANT; ownerBox.hidden = !owner; companyBox.hidden = owner;
      if (!owner) drawCompany(company.value).catch(e => { companyBox.replaceChildren(); show(e); });
    };
    company.addEventListener('change', sync); sync();
    const pw = labeled(body, 'Tu contraseña (confirmación)', el('input')); pw.type = 'password'; pw.autocomplete = 'current-password';
    ok.textContent = 'Crear cuenta';
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      const owner = company.value === PLATFORM_TENANT;
      if (!email.value.includes('@')) { show('Escribe un correo válido.'); return; }
      if (!owner && !person?.value) { show('Elige a qué persona pertenece la cuenta.'); return; }
      if (owner && !display.value.trim()) { show('Escribe el nombre de la cuenta.'); return; }
      if (!pw.value) { show('Escribe tu contraseña para confirmar.'); return; }
      if (!owner && accessEd?.read() && !accessEd.valid()) { show(accessEd.problem()); return; }
      const groupProblem = owner ? null : group?.problem(); if (groupProblem) { show(groupProblem); return; }
      ok.disabled = true;
      try {
        await reauth(pw.value);
        const payload = owner
          ? { kind: 'platform', email: email.value.trim(), display_name: display.value.trim(), user_id: null }
          : { kind: 'company', email: email.value.trim(), display_name: null, user_id: person.value, ...(accessEd?.read() ? { access: accessEd.read() } : {}), ...(group ? group.read() : {}) };
        const r = await api('POST', '/panel-accounts', { tenant: company.value, body: payload });
        dialog.close(); showSecret(r.account, r.temporary_password); await refresh();
      } catch (e) {
        const detail = e.problem?.detail;
        show(e.code === 'invalid_credentials' ? 'Tu contraseña no es correcta.' : detail === 'email_in_use' ? 'Ese correo ya tiene una cuenta.' : detail === 'person_has_account' ? 'Esa persona ya tiene cuenta.'
          : e.code === 'tenant_rbac_disabled' ? 'La empresa no tiene habilitada la gestión de sus propios roles.' : e);
        ok.disabled = false;
      }
    });
    dialog.showModal();
  };
  const action = $('page-action'); action.textContent = 'Crear cuenta'; action.hidden = false;
  action.addEventListener('click', () => create(null));
  render(list.data);
  // &persona=<id> llega desde la ficha de la persona: la creación se abre con ella elegida.
  const presetPerson = isUuid(params.get('persona')) ? params.get('persona') : null;
  if (params.get('crear') === '1' && (only || (!platform && presetPerson))) create(only, presetPerson);
}

// ---- Empresas (solo plataforma) ---------------------------------------------------------------------------------
const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
const fileBase64 = file => new Promise((resolve, reject) => {
  const reader = new FileReader(); reader.onerror = () => reject(new Error('No se pudo leer el archivo.'));
  reader.onload = () => resolve(String(reader.result).split(',')[1] || ''); reader.readAsDataURL(file);
});
function logoImage(company, className = 'company-logo') {
  const url = company.branding?.logo_url || '';
  const img = el('img', undefined, className); img.alt = '';
  try { const u = new URL(url, location.origin); img.src = u.origin === location.origin && u.pathname === '/branding.svg' ? '/assets/brand/logo-main.png' : u.href; } catch { img.src = '/assets/brand/logo-main.png'; }
  img.onerror = () => { img.hidden = true; };
  return img;
}
// Casillas del catálogo agrupadas. Paquetes y funciones del cliente usan todo el catálogo; los planes por persona,
// solo las funciones de nivel persona.
// Las funciones `pending` se pueden contratar (política comercial) pero todavía no se aplican: se rotulan así en
// cada casilla y un aviso vivo dice cuáles de las marcadas están en ese estado (contratada ≠ disponible para aplicar).
const PENDING_LABEL = 'Pendiente de implementación';
function featureChecks(catalog, parent, selected, { personOnly = false } = {}) {
  const list = personOnly ? catalog.filter(f => f.level === 'person') : catalog;
  const boxes = [];
  const review = el('p', '', 'notice'); review.setAttribute('role', 'status'); review.hidden = true;
  if (list.some(f => f.pending)) parent.append(el('p', `Las funciones marcadas «${PENDING_LABEL}» se pueden contratar, pero todavía no se aplican en los equipos ni en el panel.`, 'help'));
  for (const group of [...new Set(list.map(f => f.group))]) {
    const fs = el('fieldset', undefined, 'perm-group'); fs.append(el('legend', group)); parent.append(fs);
    boxes.push(...checks(fs, list.filter(f => f.group === group).map(f => [f.code, f.name,
      personOnly ? (f.pending ? PENDING_LABEL : '') : `${f.level === 'person' ? 'Por persona' : 'Del cliente'}${f.pending ? ` · ${PENDING_LABEL}` : ''}`]), selected));
  }
  const pendingCodes = new Set(list.filter(f => f.pending).map(f => f.code));
  const sync = () => {
    const marked = boxes.filter(b => b.checked && pendingCodes.has(b.value)).map(b => list.find(f => f.code === b.value).name);
    review.hidden = !marked.length;
    review.textContent = marked.length ? `Contratadas pero pendientes de implementación (aún no se aplican): ${marked.join(', ')}.` : '';
  };
  boxes.forEach(b => b.addEventListener('change', sync)); parent.append(review); sync();
  boxes.refreshReview = sync;
  return boxes;
}
let showArchived = false;
async function tenantsPage() {
  const catalog = (await api('GET', '/features', { tenant: PLATFORM_TENANT })).data;
  const packages = (await api('GET', '/feature-packages', { tenant: PLATFORM_TENANT })).data;
  let personPlans = (await api('GET', '/person-plans', { tenant: PLATFORM_TENANT })).data;
  const render = () => {
    $('page-content').replaceChildren();
    const s = surface('Empresas');
    if (!companies.some(c => c.id !== PLATFORM_TENANT && !c.archived)) s.append(el('p', 'Aún no hay empresas cliente activas. Crea la primera con "Crear empresa" (arriba a la derecha); las demás pantallas (Personas, Organización, Equipos…) trabajan sobre una empresa cliente y se habilitan cuando exista.', 'notice'));
    s.append(el('p', `${ownerName()} es la empresa dueña de Keeper: su nombre y logo son los del producto (login y barra lateral). Las demás son empresas cliente; cada una con su marca, sus personas, equipos y cuentas.`, 'help'));
    const plansLink = el('p', 'En "Funciones" de cada empresa eliges lo que compró y el plan por defecto de sus personas. Los paquetes y los planes por persona se crean en ', 'help');
    const pa = el('a', 'Administrar planes y paquetes'); pa.href = '/planes.php'; plansLink.append(pa, '.'); s.append(plansLink);
    // Archivadas: ocultas por defecto en todo el panel y de solo lectura (nadie las modifica hasta desarchivarlas).
    const toggle = el('label', undefined, 'check'); const box = el('input'); box.type = 'checkbox'; box.checked = showArchived;
    toggle.append(box, ' Mostrar empresas archivadas'); s.append(toggle);
    box.addEventListener('change', async () => { showArchived = box.checked; try { await reload(); } catch (e) { fail(e); } });
    renderTree();
    table(s, 'Empresas', [
      ['Empresa', c => { const d = el('div', undefined, 'company-cell'); const t = el('div'); t.append(el('strong', c.branding?.display_name || c.name), el('small', c.name)); d.append(logoImage(c), t); return d; }],
      ['Tipo', c => badge(c.id === PLATFORM_TENANT ? 'Dueña de la plataforma' : 'Cliente', c.id === PLATFORM_TENANT ? 'info' : '')],
      ['Grupo empresarial', c => c.id === PLATFORM_TENANT ? '—' : groupOf(c.id)?.name || 'Independiente'],
      ['Estado', c => c.archived ? badge('Archivada', '') : badge(c.status === 'active' ? 'Activa' : 'Suspendida', c.status === 'active' ? 'good' : 'bad')],
      ['Zona horaria', c => c.timezone],
      ['Roles propios', c => c.id === PLATFORM_TENANT ? '—' : c.rbac_self_management ? 'Los gestiona la empresa' : 'Los gestiona la plataforma'],
      ['Acciones', c => {
        const box = el('div', undefined, 'actions no-margin');
        if (c.archived) {
          const u = el('button', 'Desarchivar', 'link-button'); u.type = 'button'; u.addEventListener('click', () => archive(c, false));
          const d = el('button', 'Eliminar definitivamente', 'link-button danger'); d.type = 'button'; d.addEventListener('click', () => purge(c));
          box.append(u, d); return box;
        }
        const b = el('button', 'Editar y marca', 'link-button'); b.type = 'button'; b.addEventListener('click', () => editor(c)); box.append(b);
        const a = el('a', 'Ver cuentas', 'link-button'); a.href = c.id === PLATFORM_TENANT ? '/cuentas.php' : `/cuentas.php?empresa=${encodeURIComponent(c.id)}`; box.append(a);
        if (c.id !== PLATFORM_TENANT) { const fb = el('button', 'Funciones', 'link-button'); fb.type = 'button'; fb.addEventListener('click', () => featuresEditor(c)); box.append(fb); }
        if (c.id !== PLATFORM_TENANT) { const x = el('button', 'Archivar', 'link-button'); x.type = 'button'; x.addEventListener('click', () => archive(c, true)); box.append(x); }
        return box;
      }]], companies, { search: c => [c.name, c.branding?.display_name] });
    renderGroups();
  };
  let groups = (await optional(api('GET', '/company-groups', { tenant: PLATFORM_TENANT })))?.data || [];
  const groupOf = id => groups.find(g => g.tenant_ids.includes(id)) || null;
  // Quien va debajo de quien: plataforma > grupos empresariales > empresas. Dentro de cada empresa, sus empresas
  // internas (AZC Legal, BilingueLaw...) se editan en Organizacion de esa empresa.
  const renderTree = () => {
    const t = surface('Estructura');
    t.append(el('p', 'Quién va debajo de quién. Para mover una empresa a otro grupo (o dejarla independiente) usa "Editar y marca". Las empresas internas de cada una (por ejemplo AZC Legal o BilingueLaw dentro de Grupo AZC) se editan en Organización, eligiendo esa empresa arriba.', 'help'));
    const label = c => { const li = el('li'); li.append(el('strong', c.branding?.display_name || c.name)); if (c.archived) li.append(' ', badge('Archivada', '')); else if (c.status !== 'active') li.append(' ', badge('Suspendida', 'bad')); return li; };
    const root = el('ul', undefined, 'tree'); const owner = companies.find(c => c.id === PLATFORM_TENANT);
    const top = owner ? label(owner) : el('li', 'Plataforma'); if (owner) top.append(' ', badge('Dueña de la plataforma', 'info'));
    const level = el('ul'); top.append(level); root.append(top);
    const clients = companies.filter(c => c.id !== PLATFORM_TENANT);
    for (const g of groups) {
      const li = el('li'); li.append(el('strong', g.name), ' ', badge('Grupo empresarial', 'info'));
      const members = clients.filter(c => g.tenant_ids.includes(c.id)); const ul = el('ul');
      if (members.length) members.forEach(c => ul.append(label(c))); else ul.append(el('li', 'Sin empresas todavía', 'help'));
      li.append(ul); level.append(li);
    }
    const alone = clients.filter(c => !groupOf(c.id));
    if (alone.length) { const li = el('li'); li.append(el('strong', 'Empresas independientes')); const ul = el('ul'); alone.forEach(c => ul.append(label(c))); li.append(ul); level.append(li); }
    t.append(root);
  };
  const renderGroups = () => {
    const g = surface('Grupos empresariales');
    g.append(el('p', 'Empresas de un mismo dueño. Una cuenta de grupo (por ejemplo, la del presidente) entra a todas las del grupo con un solo usuario; se configura en Cuentas del panel.', 'help'));
    const add = el('button', 'Crear grupo'); add.type = 'button'; add.addEventListener('click', () => groupEditor(null)); g.append(add);
    if (!groups.length) { g.append(el('p', 'Aún no hay grupos.', 'help')); return; }
    table(g, 'Grupos', [['Grupo', x => x.name], ['Empresas', x => x.tenant_ids.map(companyName).join(', ')],
      ['', x => { const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => groupEditor(x)); return b; }]], groups);
  };
  const groupEditor = x => {
    const { dialog, body, ok, show } = dialogShell(x ? `Editar ${x.name}` : 'Crear grupo empresarial');
    const nm = labeled(body, 'Nombre del grupo', el('input')); nm.maxLength = 120; nm.value = x?.name || '';
    body.append(el('p', 'Empresas del grupo (una empresa solo puede estar en un grupo). Sacar una empresa quita el acceso que las cuentas del grupo tenían allí.', 'help'));
    const taken = new Map(groups.filter(g => g.id !== x?.id).flatMap(g => g.tenant_ids.map(t => [t, g.name])));
    const items = companies.filter(c => c.id !== PLATFORM_TENANT).map(c => [c.id, c.branding?.display_name || c.name, taken.has(c.id) ? `Ya está en ${taken.get(c.id)}` : null]);
    const boxes = checks(body, items, x?.tenant_ids || []);
    boxes.forEach(b => { if (taken.has(b.value)) b.disabled = true; });
    if (x) {
      // Eliminar: sus empresas quedan independientes; con cuentas de grupo atadas se rechaza (reasignarlas en Cuentas).
      const del = el('button', 'Eliminar grupo'); del.type = 'button'; ok.before(del);
      del.addEventListener('click', async () => {
        if (!confirm(`¿Eliminar el grupo "${x.name}"? Sus empresas no se borran: quedan independientes.`)) return;
        del.disabled = ok.disabled = true;
        try { await api('DELETE', `/company-groups/${x.id}`, { tenant: PLATFORM_TENANT, ifMatch: x.version }); groups = (await api('GET', '/company-groups', { tenant: PLATFORM_TENANT })).data; dialog.close(); render(); done(); }
        catch (e) {
          const n = (e.problem?.detail || '').match(/^group_has_accounts:(\d+)$/)?.[1];
          show(n ? `No se puede eliminar: ${n} cuenta(s) de grupo dependen de él. Cámbialas primero en Cuentas (quítales el grupo o asígnales otro).` : e.status === 412 ? 'Alguien más cambió el grupo: recarga la página.' : e);
          del.disabled = ok.disabled = false;
        }
      });
    }
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!nm.value.trim()) { show('Escribe el nombre del grupo.'); return; }
      ok.disabled = true;
      const payload = { name: nm.value.trim(), tenant_ids: boxes.filter(b => b.checked).map(b => b.value) };
      try {
        if (x) await api('PATCH', `/company-groups/${x.id}`, { tenant: PLATFORM_TENANT, body: payload, ifMatch: x.version }); else await api('POST', '/company-groups', { tenant: PLATFORM_TENANT, body: payload });
        groups = (await api('GET', '/company-groups', { tenant: PLATFORM_TENANT })).data; dialog.close(); render(); done();
      } catch (e) { show(e.problem?.detail === 'company_in_other_group' ? 'Una de las empresas ya está en otro grupo.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  // Funciones contratadas del cliente: paquete de origen + ajustes de la venta (docs/architecture/v4-clientes-funciones-accesos.md).
  const featuresEditor = async c => {
    const current = await api('GET', `/tenants/${c.id}/features`, { tenant: c.id });
    const { dialog, body, ok, show } = dialogShell(`Funciones de ${c.branding?.display_name || c.name}`);
    dialog.classList.add('wide-dialog');
    body.append(el('p', 'Lo que el cliente compró. Lo que no esté marcado desaparece de su panel y la API lo rechaza; apagar no borra datos. El núcleo (organización, horarios, personas, equipos) siempre está incluido.', 'help'));
    const pkg = el('select'); pkg.append(new Option('Sin paquete (a medida)', '')); packages.forEach(p => pkg.append(new Option(p.name, p.id))); pkg.value = current.package_id || '';
    labeled(body, 'Paquete de origen', pkg, 'Al elegir un paquete se marcan sus funciones; después puedes ajustar las de esta venta.');
    const boxes = featureChecks(catalog, body, current.features);
    const defPlan = el('select'); defPlan.append(new Option('Sin plan (sus equipos no reciben controles)', '')); personPlans.filter(x => x.active || x.id === current.default_plan_id).forEach(x => defPlan.append(new Option(x.active ? x.name : `${x.name} (inactivo)`, x.id)));
    defPlan.value = current.default_plan_id || '';
    labeled(body, 'Plan por defecto de sus personas', defPlan, 'Las personas sin plan propio usan este. Los planes se crean en Planes y paquetes.');
    const usage = el('p', undefined, 'help'); body.append(usage);
    const warn = el('p', 'Sin plan por defecto, los equipos de este cliente no reciben reglas de funciones vendibles (web, USB, descargas, instalaciones) hasta que se asigne un plan.', 'notice'); body.append(warn);
    const syncWarn = () => { warn.hidden = !!defPlan.value; };
    defPlan.addEventListener('change', syncWarn); syncWarn();
    api('GET', `/tenants/${c.id}/plan-usage`, { tenant: c.id }).then(u => {
      const parts = (u.data || []).filter(x => x.people > 0).map(x => `${x.people} en ${x.name || 'Sin plan'}`);
      usage.textContent = parts.length ? `Planes en uso: ${parts.join(' · ')}` : 'Planes en uso: aún no hay personas.';
    }).catch(() => { usage.hidden = true; });
    pkg.addEventListener('change', () => { const p = packages.find(x => x.id === pkg.value); if (p) { boxes.forEach(b => { b.checked = p.features.includes(b.value); }); boxes.refreshReview(); } });
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); ok.disabled = true;
      try { await api('PUT', `/tenants/${c.id}/features`, { tenant: c.id, body: { package_id: pkg.value || null, features: boxes.filter(b => b.checked).map(b => b.value), ...((defPlan.value || null) === (current.default_plan_id || null) ? {} : { default_plan_id: defPlan.value || null }) } }); personPlans = (await api('GET', '/person-plans', { tenant: PLATFORM_TENANT })).data; dialog.close(); render(); done(); }
      catch (e) { show(e.problem?.detail === 'unknown_plan' ? 'Ese plan está inactivo; actívalo o elige otro.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const reload = async () => { companies = sortCompanies(await allPages(showArchived ? '/tenants?include_archived=true' : '/tenants', PLATFORM_TENANT)); render(); done(); };
  // Eliminar una empresa archivada: se borra todo lo suyo salvo la auditoria. Pide contrasena y el nombre exacto.
  const purge = c => {
    const { dialog, body, ok, show } = dialogShell(`Eliminar ${c.branding?.display_name || c.name}`);
    body.append(el('p', 'Se borran definitivamente sus personas, equipos, actividad, organización, horarios, festivos, reglas, planes y cuentas del panel. No se puede deshacer. Solo se conserva su auditoría (quién hizo qué), que no se muestra en el panel.', 'notice'));
    const password = labeled(body, 'Tu contraseña', el('input')); password.type = 'password'; password.autocomplete = 'current-password';
    const confirmName = labeled(body, `Escribe el nombre exacto de la empresa: ${c.name}`, el('input'));
    let accept = null;
    ok.textContent = 'Eliminar definitivamente';
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (confirmName.value.trim() !== c.name) { show('El nombre no coincide.'); return; }
      if (!password.value) { show('Escribe tu contraseña.'); return; }
      ok.disabled = true;
      try {
        await reauth(password.value);
        await api('POST', `/tenants/${c.id}/purge`, { tenant: c.id, body: { confirm_name: c.name, accept_hardening_loss: !!accept?.checked } });
        dialog.close(); await reload();
      } catch (e) {
        const n = (e.problem?.detail || '').match(/^hardened_devices:(\d+)$/)?.[1];
        if (n && !accept) {
          [accept] = checks(body, [['ok', `Acepto perder la clave de administrador local de ${n} equipo(s) endurecido(s) de esta empresa`]]);
          show(`Tiene ${n} equipo(s) endurecido(s): su administrador local usa una clave que se perderá. Marca la casilla para continuar.`);
        } else show(e.status === 401 ? 'La contraseña no es correcta.' : e);
        ok.disabled = false;
      }
    });
    dialog.showModal();
  };
  const archive = (c, on) => {
    const name = c.branding?.display_name || c.name;
    const { dialog, body, ok, show } = dialogShell(on ? `Archivar ${name}` : `Desarchivar ${name}`);
    body.append(el('p', on
      ? 'La empresa queda suspendida: sus equipos dejan de conectarse y sus cuentas del panel dejan de entrar. Sale de su grupo empresarial, se oculta de las listas y selectores y nadie puede modificarla hasta desarchivarla. Sus datos se conservan.'
      : 'Vuelve a verse en las listas y se puede editar. Sigue suspendida: actívala después en "Editar y marca" si quieres que sus equipos y cuentas vuelvan a entrar, y agrégala de nuevo a su grupo si hace falta.', 'help'));
    const confirmName = labeled(body, `Escribe el nombre de la empresa para confirmar: ${c.name}`, el('input'));
    ok.textContent = on ? 'Archivar empresa' : 'Desarchivar';
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (confirmName.value.trim() !== c.name) { show('El nombre no coincide.'); return; }
      ok.disabled = true;
      try { await api('PATCH', `/tenants/${c.id}`, { tenant: c.id, body: { archived: on }, ifMatch: c.version }); dialog.close(); await reload(); }
      catch (e) { show(e.status === 412 ? 'Alguien más la modificó: recarga la página.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const editor = c => {
    const owner = c.id === PLATFORM_TENANT; const brand = c.branding || {};
    const { dialog, body, ok, show } = dialogShell(owner ? `Marca del producto (${c.name})` : `Editar ${c.branding?.display_name || c.name}`);
    if (owner) body.append(el('p', 'Esta es la empresa dueña: lo que cambies aquí (nombre visible y logo) se ve en el login y en la barra lateral de todas las cuentas.'));
    const name = labeled(body, 'Nombre interno', el('input')); name.maxLength = 120; name.value = c.name;
    const display = labeled(body, owner ? 'Nombre del producto' : 'Nombre visible (marca)', el('input')); display.maxLength = 100; display.value = brand.display_name || c.name;
    const preview = el('div', undefined, 'logo-preview'); preview.append(logoImage(c, 'company-logo big')); body.append(preview);
    const file = labeled(body, 'Logo nuevo (PNG, JPG o WebP, máx. 512 KB)', el('input'), 'Mejor un PNG con fondo transparente y ancho de al menos 300 px.');
    file.type = 'file'; file.accept = LOGO_TYPES.join(',');
    file.addEventListener('change', () => {
      const f = file.files[0]; if (!f) return;
      const img = el('img', undefined, 'company-logo big'); img.alt = ''; img.src = URL.createObjectURL(f); preview.replaceChildren(img);
    });
    const colors = el('div', undefined, 'form-grid'); body.append(colors);
    const primary = labeled(colors, 'Color principal', el('input')); primary.type = 'color'; primary.value = /^#[0-9a-f]{6}$/i.test(brand.primary_color) ? brand.primary_color : '#3C4082';
    const accent = labeled(colors, 'Color de acento', el('input')); accent.type = 'color'; accent.value = /^#[0-9a-f]{6}$/i.test(brand.accent_color) ? brand.accent_color : '#5A61B3';
    const tz = labeled(body, 'Zona horaria', el('input'), 'Ejemplo: America/Bogota'); tz.maxLength = 64; tz.value = c.timezone;
    let status = null, gate = null, group = null;
    if (!owner) {
      status = el('select'); status.append(new Option('Activa', 'active'), new Option('Suspendida (nadie de la empresa puede entrar)', 'suspended')); status.value = c.status;
      labeled(body, 'Estado', status);
      gate = el('input'); gate.type = 'checkbox'; gate.checked = c.rbac_self_management;
      const gl = el('label', undefined, 'check'); gl.append(gate, ' La empresa gestiona sus propios roles y cuentas'); body.append(gl);
      group = el('select'); group.append(new Option('Ninguno (empresa independiente)', '')); groups.forEach(g => group.append(new Option(g.name, g.id)));
      group.value = groupOf(c.id)?.id || ''; labeled(body, 'Pertenece al grupo empresarial', group, 'Las cuentas de grupo (por ejemplo la del presidente) entran a todas las empresas de su grupo. Sacarla de un grupo les quita ese acceso.');
    }
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      const f = file.files[0];
      if (!name.value.trim() || !display.value.trim()) { show('Escribe el nombre interno y el visible.'); return; }
      if (f && (!LOGO_TYPES.includes(f.type) || f.size > 512 * 1024)) { show('El logo debe ser PNG, JPG o WebP de máximo 512 KB.'); return; }
      if (status?.value === 'suspended' && c.status !== 'suspended' && !confirm(`¿Suspender ${c.name}? Nadie de esa empresa podrá entrar al panel.`)) return;
      ok.disabled = true;
      try {
        const patch = { name: name.value.trim(), timezone: tz.value.trim(), branding: { display_name: display.value.trim(), logo_url: brand.logo_url || `${location.origin}/branding.svg`, primary_color: primary.value.toUpperCase(), accent_color: accent.value.toUpperCase() } };
        if (status && status.value !== c.status) patch.status = status.value;
        let saved = await api('PATCH', `/tenants/${c.id}`, { tenant: c.id, body: patch, ifMatch: c.version });
        if (f) saved = await api('POST', `/tenants/${c.id}/logo`, { tenant: c.id, body: { content_type: f.type, data: await fileBase64(f) }, ifMatch: saved.version });
        if (gate && gate.checked !== c.rbac_self_management) await api('PATCH', `/tenants/${c.id}/rbac-gate`, { tenant: c.id, body: { enabled: gate.checked, reason: gate.checked ? 'Activada desde la ficha de la empresa' : 'Revocada desde la ficha de la empresa' }, ifMatch: saved.version });
        const from = groupOf(c.id)?.id || '';
        if (group && group.value !== from) {
          const move = async (g, ids) => api('PATCH', `/company-groups/${g.id}`, { tenant: PLATFORM_TENANT, body: { name: g.name, tenant_ids: ids }, ifMatch: g.version });
          if (from) { const g = groups.find(x => x.id === from); await move(g, g.tenant_ids.filter(id => id !== c.id)); }
          if (group.value) { const g = (await api('GET', '/company-groups', { tenant: PLATFORM_TENANT })).data.find(x => x.id === group.value); await move(g, [...g.tenant_ids, c.id]); }
          groups = (await api('GET', '/company-groups', { tenant: PLATFORM_TENANT })).data;
        }
        dialog.close();
        if (owner) { location.reload(); return; }
        await reload();
      } catch (e) {
        show(e.status === 412 ? 'Alguien más cambió esta empresa: recarga la página.' : e.problem?.detail === 'invalid_image' ? 'El archivo no es una imagen válida del tipo indicado.' : e);
        ok.disabled = false;
      }
    });
    dialog.showModal();
  };
  const action = $('page-action'); action.textContent = 'Crear empresa'; action.hidden = false;
  action.addEventListener('click', () => {
    const { dialog, body, ok, show } = dialogShell('Crear empresa');
    body.append(el('p', 'Después de crearla: configura su organización, crea a su administrador como persona, luego su cuenta del panel y por último prepara la instalación de sus equipos.'));
    const name = labeled(body, 'Nombre de la empresa', el('input')); name.maxLength = 100;
    const tz = labeled(body, 'Zona horaria', el('input'), 'Ejemplo: America/Bogota'); tz.maxLength = 64; tz.value = 'America/Bogota';
    const pkg = el('select'); pkg.append(new Option('Sin paquete (solo el núcleo)', '')); packages.forEach(p => pkg.append(new Option(p.name, p.id)));
    labeled(body, 'Paquete', pkg, 'Define las funciones que compra. Se pueden ajustar después en "Funciones".');
    ok.textContent = 'Crear empresa';
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!name.value.trim()) { show('Escribe el nombre de la empresa.'); return; }
      ok.disabled = true;
      try {
        const created = await api('POST', '/tenants', { tenant: PLATFORM_TENANT, body: { name: name.value.trim(), timezone: tz.value.trim(), package_id: pkg.value || null,
          branding: { display_name: name.value.trim(), logo_url: `${location.origin}/branding.svg`, primary_color: '#3C4082', accent_color: '#5A61B3' } } });
        dialog.close(); await reload(); nextSteps(created);
      } catch (e) { show(e.status === 422 ? 'Revisa el nombre y la zona horaria (ejemplo: America/Bogota).' : e); ok.disabled = false; }
    });
    dialog.showModal();
  });
  render();
}
// Recorrido tras crear un cliente: la cuenta pertenece a una persona, así que la persona va antes que la cuenta.
function nextSteps(created) {
  const { dialog, body, ok, cancel } = dialogShell(`${created.branding?.display_name || created.name} quedó creada`);
  body.append(el('p', 'Siguientes pasos, en este orden:'));
  const ol = el('ol', undefined, 'next-steps'); body.append(ol);
  const step = (text, note, link) => { const li = el('li'); li.append(el('strong', text), ' · ', link); if (note) li.append(el('small', note)); ol.append(li); };
  step('Configurar la organización', 'Sedes (con su país), áreas y cargos.', clientLink('Abrir Organización', created.id, '/organizacion.php'));
  step('Crear al administrador como persona', 'Toda cuenta de empresa pertenece a una persona.', clientLink('Abrir Personas', created.id, '/usuarios.php'));
  const acc = el('a', 'Crear cuenta', 'link-button'); acc.href = `/cuentas.php?empresa=${encodeURIComponent(created.id)}&crear=1`;
  step('Crear su cuenta del panel', 'Requiere la persona del paso anterior.', acc);
  step('Preparar la instalación', 'Registrar los equipos de sus personas.', clientLink('Abrir Registrar equipos', created.id, '/alta.php'));
  cancel.hidden = true; ok.textContent = 'Cerrar'; ok.type = 'button'; ok.addEventListener('click', () => dialog.close());
  dialog.showModal();
}
const sortCompanies = list => [...list].sort((a, b) => (b.id === PLATFORM_TENANT) - (a.id === PLATFORM_TENANT) || (a.branding?.display_name || a.name).localeCompare(b.branding?.display_name || b.name, 'es'));

// ---- Personas ---------------------------------------------------------------------------------------------------
const fold = v => String(v ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase().replace(/\s+/g, ' ');
const ORG_FIELDS = [['firm', 'Firma'], ['site', 'Sede'], ['area', 'Área'], ['position', 'Cargo']];
// Separa una linea CSV (coma o punto y coma, comillas dobles con "" escapado).
function csvRows(text) {
  const lines = text.replace(/^﻿/, '').split(/\r?\n/).filter(l => l.trim());
  if (!lines.length) return [];
  const sep = (lines[0].match(/;/g) || []).length > (lines[0].match(/,/g) || []).length ? ';' : ',';
  return lines.map(line => {
    const out = []; let cur = '', quoted = false;
    for (let i = 0; i < line.length; i++) {
      const ch = line[i];
      if (quoted) { if (ch === '"' && line[i + 1] === '"') { cur += '"'; i++; } else if (ch === '"') quoted = false; else cur += ch; }
      else if (ch === '"') quoted = true; else if (ch === sep) { out.push(cur); cur = ''; } else cur += ch;
    }
    out.push(cur); return out.map(c => c.trim());
  });
}
const sleep = ms => new Promise(r => setTimeout(r, ms));
// Reintenta cuando la API limita la velocidad (importaciones grandes).
async function patient(fn) {
  for (let attempt = 0; ; attempt++) {
    try { return await fn(); } catch (e) { if (e.status !== 429 || attempt > 8) throw e; await sleep(Math.max(1, Number(e.retryAfter) || 2) * 1000); }
  }
}
async function people() {
  const [users, org, schedules, domains, sources, calendarPage] = await Promise.all([allPages('/users'), allPages('/organization'), optional(allPages('/schedules')), optional(api('GET', '/data-domains')), optional(api('GET', '/data-sources')), optional(api('GET', '/schedules/holiday-calendars?limit=100'))]);
  let list = users; const n = names(org);
  const calendars = (calendarPage?.data || []).filter(c => c.active);
  const siteCountry = u => org.find(o => o.id === u.site_id)?.country_code || null;
  // Si una fuente (p. ej. AZC One) manda en "personas", aqui no se crean ni editan: se cambian en la fuente.
  const ownerId = domains?.data?.find(d => d.domain === 'personas')?.source_id || null;
  const owner = ownerId ? (sources?.data?.find(x => x.id === ownerId)?.name || 'una fuente externa') : null;
  const units = kind => org.filter(o => o.kind === kind && o.active).sort((a, b) => a.name.localeCompare(b.name, 'es'));
  const fallback = kind => { const u = units(kind); return u.length === 1 ? u[0] : u.find(o => fold(o.name).startsWith('sin ')) || null; };
  const scheduleList = (schedules || []).sort((a, b) => a.name.localeCompare(b.name, 'es'));
  const defaultSchedule = () => scheduleList.find(s => fold(s.name).includes('por defecto')) || scheduleList[0] || null;
  // Planes asignables en esta empresa (superadmin o permiso planes.asignar; sin acceso: null y no se muestran). Cada
  // plan trae las funciones que da aquí (plan ∩ funciones del cliente). Superadmin además ve el nombre de planes inactivos.
  const isSuper = !!readSession()?.is_platform_admin;
  const [avail, allPlans] = await Promise.all([optional(api('GET', '/person-plans/available')),
    isSuper ? optional(api('GET', '/person-plans', { tenant: PLATFORM_TENANT })).then(r => r?.data || []) : []]);
  // Acceso al panel en la ficha (§5): lo edita quien puede dar accesos (superadmin o roles.gestionar).
  const myAccess = isSuper ? null : await optional(api('GET', '/me/access'));
  const canAssign = isSuper || !!myAccess?.permissions?.some(p => p.code === 'roles.gestionar');
  const canCreatePeople = isSuper || !myAccess || !!myAccess.permissions?.some(p => p.code === 'usuarios.crear');
  const roles = canAssign ? ((await optional(allPages('/roles'))) || []) : [];
  const planName = id => avail?.data.find(p => p.id === id)?.name || allPlans.find(p => p.id === id)?.name || null;
  const defaultLabel = avail ? `Por defecto (${planName(avail.default_plan_id) || 'sin plan'})` : '';
  const ownPlan = u => avail?.data.find(p => p.id === u.plan_id) || null;
  const receives = planId => {
    const plan = avail.data.find(p => p.id === planId) || avail.data.find(p => p.id === avail.default_plan_id);
    const names = plan ? avail.features.filter(f => plan.features.includes(f.code)).map(f => f.name) : [];
    return `Recibe: ${names.length ? names.join(', ') : 'ninguna función de persona'}.`;
  };
  const render = () => {
    $('page-content').replaceChildren();
    kpis([['Personas', String(list.length)], ['Activas', String(list.filter(u => u.status === 'active').length)],
      ['Con cédula', String(list.filter(u => u.document).length), 'La usa el alta de equipos para saber de quién es el PC'],
      ['Con cuenta del panel', String(list.filter(u => u.panel_login_enabled).length)]]);
    const s = surface('Personas de la empresa');
    renderExclusions();
    if (owner) s.append(el('p', `Las personas las gestiona ${owner}: las altas, cambios y retiros se hacen allá y llegan solos. Solo las que no vienen de ${owner} se editan aquí.`, 'notice'));
    else {
      const tools = el('div', undefined, 'actions no-margin');
      const imp = el('button', 'Importar desde CSV'); imp.type = 'button'; imp.addEventListener('click', importer);
      const tpl = el('button', 'Descargar plantilla CSV'); tpl.type = 'button'; tpl.addEventListener('click', template);
      tools.append(imp, tpl); s.append(tools);
    }
    if (!list.length) { empty(s, 'Aún no hay personas', 'Créalas una a una con "Crear persona" o impórtalas desde un CSV (descarga la plantilla).'); return; }
    table(s, 'Personas', [
      ['Nombre', u => { const a = el('a', u.display_name); a.href = `/miembro.php?id=${encodeURIComponent(u.id)}`; const d = el('div'); d.append(a); if (u.email) d.append(el('small', u.email)); return d; }],
      ['Cédula', u => u.document || '—'], ['Empresa', u => n.get(u.company_id) || '—'], ['Sede', u => n.get(u.site_id) || '—'], ['Área', u => n.get(u.area_id) || '—'], ['Cargo', u => n.get(u.position_id) || '—'],
      ['Acceso al panel', u => u.access ? scopeSummary(u.access.role_name, u.access.scope, n) : 'Sin acceso al panel'],
      ['Estado', u => { const d = el('div'); d.append(badge(u.status === 'active' ? 'Activa' : 'Inactiva', u.status === 'active' ? 'good' : '')); if (u.monitoring === 'excluded') d.append(' ', badge('No se monitorea', 'info')); return d; }],
      ...(avail ? [['Plan', u => ownPlan(u)?.name || defaultLabel]] : []),
      ['Acciones', u => { if (u.managed_by) return el('small', `Gestionada por ${u.managed_by}`); const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => editor(u)); return b; }]],
      list, { filters: [['Empresa', u => n.get(u.company_id) || 'Sin empresa'], ['País', u => countryName(siteCountry(u))], ['Ciudad', u => org.find(o => o.id === u.site_id)?.city || '—'], ['Sede', u => n.get(u.site_id) || '—'], ['Área', u => n.get(u.area_id) || '—'], ['Estado', u => u.status === 'active' ? 'Activa' : 'Inactiva']],
        search: u => [u.display_name, u.email, u.document] });
  };
  const editor = u => {
    const { dialog, body, ok, show } = dialogShell(u ? `Editar ${u.display_name}` : 'Crear persona');
    const name = labeled(body, 'Nombre completo', el('input')); name.maxLength = 160; name.value = u?.display_name || '';
    const doc = labeled(body, 'Cédula', el('input'), 'Con ella, al instalar Keeper en su PC, el equipo queda asignado a esta persona.'); doc.maxLength = 40; doc.inputMode = 'numeric'; doc.value = u?.document || '';
    const email = labeled(body, 'Correo (opcional)', el('input')); email.type = 'email'; email.maxLength = 254; email.value = u?.email || '';
    const boss = el('select'); boss.append(new Option('Nadie', ''));
    list.filter(x => x.status === 'active' && x.id !== u?.id).sort((a, b) => a.display_name.localeCompare(b.display_name, 'es')).forEach(x => boss.append(new Option(x.display_name, x.id)));
    boss.value = u?.reports_to_user_id || ''; labeled(body, 'Reporta a (jefe)', boss, 'Con esto el jefe puede ver a su equipo si su acceso tiene el alcance "Su equipo".');
    const mon = el('select'); [['auto', 'Automático (según el cargo)'], ['monitored', 'Siempre monitorear'], ['excluded', 'No monitorear (directivo, dueño)']].forEach(([v, t]) => mon.append(new Option(t, v)));
    mon.value = u?.monitoring_override || 'auto'; labeled(body, 'Monitoreo', mon);
    const grid = el('div', undefined, 'form-grid'); body.append(grid);
    const selects = {};
    for (const [kind, label] of ORG_FIELDS) {
      const sel = el('select'); if (kind === 'firm') sel.append(new Option('Sin firma (se asigna después)', '')); units(kind).forEach(o => sel.append(new Option(o.name, o.id)));
      sel.value = u?.[`${kind}_id`] || fallback(kind)?.id || ''; labeled(grid, label, sel); selects[kind] = sel;
    }
    const sched = el('select'); sched.append(new Option('Sin horario (no se espera jornada)', '')); scheduleList.forEach(x => sched.append(new Option(x.name, x.id))); sched.value = u?.schedule_id || defaultSchedule()?.id || '';
    labeled(grid, 'Horario', sched);
    const company = el('select'); company.append(new Option('Sin empresa (todo el grupo)', '')); units('company').forEach(o => company.append(new Option(o.name, o.id)));
    company.value = u?.company_id || ''; labeled(grid, 'Empresa del grupo', company);
    const holidays = el('select'); holidays.append(new Option('Automático (festivos del país de su sede)', '')); calendars.forEach(c => holidays.append(new Option(c.name, c.id)));
    holidays.value = u?.holiday_calendar_id || ''; labeled(grid, 'Festivos', holidays);
    let status = null;
    if (u) { status = el('select'); status.append(new Option('Activa', 'active'), new Option('Inactiva (retirada)', 'inactive')); status.value = u.status; labeled(body, 'Estado', status); }
    let planSel = null;
    if (avail) {
      planSel = el('select'); planSel.append(new Option(defaultLabel, ''));
      avail.data.forEach(x => planSel.append(new Option(x.name, x.id)));
      // Plan propio inactivo: se conserva en el select, pero la persona ya usa el por defecto.
      if (u?.plan_id && !ownPlan(u)) planSel.append(new Option(`${planName(u.plan_id) || 'Plan'} (inactivo: usa el por defecto)`, u.plan_id));
      planSel.value = u?.plan_id || '';
      labeled(body, 'Plan', planSel, 'Define qué funciones de persona recibe. Sin plan propio usa el del cliente; nunca recibe lo que el cliente no compró.');
      const summary = el('p', '', 'help'); summary.setAttribute('aria-live', 'polite'); body.append(summary);
      const sync = () => { summary.textContent = receives(planSel.value); };
      planSel.addEventListener('change', sync); sync();
    }
    const accessEd = u && canAssign && roles.length ? accessEditor(body, { roles, org, current: u.access }) : null;
    ok.textContent = u ? 'Guardar' : 'Crear persona';
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!name.value.trim()) { show('Escribe el nombre.'); return; }
      // Firma y horario pueden ir vacíos ("Sin firma" / "Sin horario"); sede, área y cargo no.
      const missing = ORG_FIELDS.filter(([k]) => k !== 'firm' && !selects[k].value).map(([, label]) => label.toLowerCase());
      if (missing.length) { show(`Falta ${missing.join(', ')}: créalo primero en Organización.`); return; }
      if (accessEd?.read() && !accessEd.valid()) { show(accessEd.problem()); return; }
      if (status?.value === 'inactive' && u.status === 'active' && !confirm(`¿Marcar a ${u.display_name} como inactiva? Sus equipos quedan revocados y dejan de reportar.`)) return;
      ok.disabled = true;
      const payload = { display_name: name.value.trim(), document: doc.value.trim() || null, email: email.value.trim() || null, schedule_id: sched.value || null, reports_to_user_id: boss.value || null, monitoring_override: mon.value };
      if (!u || (company.value || null) !== (u.company_id || null)) payload.company_id = company.value || null;
      if (calendarPage && (!u || (holidays.value || null) !== (u.holiday_calendar_id || null))) payload.holiday_calendar_id = holidays.value || null;
      for (const [k] of ORG_FIELDS) payload[`${k}_id`] = selects[k].value || null;
      if (status && status.value !== u.status) payload.status = status.value;
      try {
        let saved = u ? await api('PATCH', `/users/${u.id}`, { body: payload, ifMatch: u.version }) : await api('POST', '/users', { body: payload });
        // Desde aquí la persona ya está guardada: el estado local usa la versión nueva, para que un reintento no choque
        // con un 412 ni cree la persona otra vez (el diálogo pasa a editar la recién creada).
        list = u ? list.map(x => x.id === saved.id ? saved : x) : [...list, saved];
        if (!u) ok.textContent = 'Guardar';
        u = saved;
        // Segundo paso: el plan propio (PUT /users/{id}/plan, permiso planes.asignar).
        if (planSel && (planSel.value || null) !== (u.plan_id || null)) {
          try { saved = await api('PUT', `/users/${u.id}/plan`, { body: { plan_id: planSel.value || null }, ifMatch: u.version }); list = list.map(x => x.id === saved.id ? saved : x); u = saved; }
          catch (e) {
            const d = e.problem?.detail;
            show('Los datos se guardaron, pero el plan no: ' + (d === 'unknown_plan' ? 'Ese plan está inactivo; actívalo o elige otro.' : e.status === 412 ? 'Alguien más la modificó: recarga la página.' : errorMessage(e)));
            ok.disabled = false; render(); return;
          }
        }
        // Tercer paso: el acceso al panel (PUT /users/{id}/access). Solo si cambió: rol y alcance van siempre explícitos.
        const wanted = accessEd?.read();
        if (wanted && !sameAccess(wanted, u.access ? { role_id: u.access.role_id, scope: u.access.scope } : null)) {
          try { saved = await api('PUT', `/users/${u.id}/access`, { body: wanted, ifMatch: u.version }); list = list.map(x => x.id === saved.id ? saved : x); u = saved; }
          catch (e) { show('Los datos se guardaron, pero el acceso no: ' + (e.status === 412 ? 'Alguien más la modificó: recarga la página.' : errorMessage(e))); ok.disabled = false; render(); return; }
        }
        dialog.close(); render(); done();
      } catch (e) {
        const d = e.problem?.detail;
        show(d === 'hierarchy_cycle' ? 'Ese jefe reporta (directa o indirectamente) a esta persona: no puede ser su jefe.' : d === 'document_in_use' ? 'Esa cédula ya pertenece a otra persona de la empresa.' : d === 'invalid_document' ? 'La cédula no es válida.' : e.status === 412 ? 'Alguien más la modificó: recarga la página.' : e);
        ok.disabled = false;
      }
    });
    dialog.showModal();
  };
  // Cargos que no se monitorean (directivos y dueños): sus equipos nunca se aprueban solos en el alta.
  let excluded = (await optional(api('GET', '/monitoring-exclusions')))?.position_ids || null;
  const renderExclusions = () => {
    if (excluded === null) return;
    const s = surface('Cargos que no se monitorean');
    s.append(el('p', 'Directivos y dueños. Una persona con estos cargos no se monitorea, salvo que en su ficha se marque "Siempre monitorear".', 'help'));
    const boxes = checks(s, units('position').map(o => [o.id, o.name]), excluded);
    const save = el('button', 'Guardar cargos'); save.type = 'button'; s.append(save);
    save.addEventListener('click', async () => {
      try { excluded = (await api('PUT', '/monitoring-exclusions', { body: { position_ids: boxes.filter(b => b.checked).map(b => b.value) } })).position_ids; list = await allPages('/users'); render(); done(); } catch (e) { fail(e); }
    });
  };
  const template = () => {
    const ex = k => fallback(k)?.name || units(k)[0]?.name || '';
    const csv = 'nombre;cedula;correo;firma;sede;area;cargo;horario;empresa\r\n' + `Ana María Pérez;1234567890;ana.perez@empresa.com;${ex('firm')};${ex('site')};${ex('area')};${ex('position')};${defaultSchedule()?.name || ''};${units('company')[0]?.name || ''}\r\n`;
    const a = el('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })); a.download = 'plantilla-personas.csv'; a.click();
  };
  const importer = () => {
    const { dialog, body, ok, show } = dialogShell('Importar personas desde CSV');
    dialog.classList.add('wide-dialog');
    body.append(el('p', 'Columnas: nombre, cedula, correo, firma, sede, area, cargo, horario (la primera fila son los títulos). Firma, sede, área, cargo y horario se buscan por nombre; si van vacíos se usa "Sin …" o el horario por defecto. Las cédulas que ya existen se omiten.'));
    const file = labeled(body, 'Archivo CSV (exportado de Excel o Google Sheets)', el('input')); file.type = 'file'; file.accept = '.csv,text/csv';
    const preview = el('div'); body.append(preview);
    let ready = [];
    file.addEventListener('change', async () => {
      preview.replaceChildren(); ready = []; ok.disabled = true;
      const f = file.files[0]; if (!f) return;
      const rows = csvRows(await f.text());
      if (rows.length < 2) { show('El archivo no tiene filas de personas.'); return; }
      const head = rows[0].map(fold);
      const col = (...keys) => head.findIndex(h => keys.includes(h));
      const idx = { name: col('nombre', 'name', 'nombre completo'), doc: col('cedula', 'documento', 'cc'), email: col('correo', 'email', 'e-mail'),
        firm: col('firma'), site: col('sede'), area: col('area'), position: col('cargo'), schedule: col('horario'), company: col('empresa', 'empresa del grupo') };
      if (idx.name < 0) { show('Falta la columna "nombre".'); return; }
      const known = new Set(list.map(u => u.document).filter(Boolean));
      const seen = new Set(); const results = [];
      for (const [i, r] of rows.slice(1).entries()) {
        const get = k => idx[k] >= 0 ? (r[idx[k]] || '').trim() : '';
        const problems = []; const payload = { display_name: get('name'), document: get('doc').replace(/[\s.\-]/g, '') || null, email: get('email') || null };
        if (!payload.display_name) problems.push('sin nombre');
        if (payload.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(payload.email)) problems.push('correo inválido');
        for (const [kind, label] of ORG_FIELDS) {
          const v = get(kind); const unit = v ? units(kind).find(o => fold(o.name) === fold(v)) : fallback(kind);
          if (!unit) { if (v || kind !== 'firm') problems.push(v ? `${label} "${v}" no existe` : `${label} vacía y no hay "Sin …"`); } else payload[`${kind}_id`] = unit.id;
        }
        const sv = get('schedule'); const sc = sv ? scheduleList.find(x => fold(x.name) === fold(sv)) : defaultSchedule();
        if (!sc) { if (sv) problems.push(`horario "${sv}" no existe`); } else payload.schedule_id = sc.id;
        const cv = get('company'); if (cv) { const co = units('company').find(o => fold(o.name) === fold(cv)); if (!co) problems.push(`empresa "${cv}" no existe`); else payload.company_id = co.id; }
        let skip = null;
        if (payload.document && known.has(payload.document)) skip = 'ya existe';
        else if (payload.document && seen.has(payload.document)) skip = 'cédula repetida en el archivo';
        if (payload.document) seen.add(payload.document);
        results.push({ line: i + 2, payload, problems, skip });
      }
      ready = results.filter(x => !x.problems.length && !x.skip);
      const bad = results.filter(x => x.problems.length), skipped = results.filter(x => x.skip && !x.problems.length);
      preview.append(el('p', `${ready.length} listas para crear · ${skipped.length} omitidas · ${bad.length} con errores.`, 'help'));
      if (bad.length || skipped.length) {
        const ul = el('ul', undefined, 'import-issues');
        [...bad, ...skipped].slice(0, 60).forEach(x => ul.append(el('li', `Fila ${x.line} (${x.payload.display_name || 'sin nombre'}): ${x.problems.length ? x.problems.join(', ') : x.skip}`)));
        preview.append(ul);
      }
      ok.disabled = !ready.length; ok.textContent = `Crear ${ready.length} personas`;
    });
    ok.disabled = true; ok.textContent = 'Crear personas';
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); ok.disabled = true; file.disabled = true;
      const progress = el('p', '', 'help'); preview.append(progress); const failed = [];
      for (const [i, x] of ready.entries()) {
        progress.textContent = `Creando ${i + 1} de ${ready.length}…`;
        try { list = [...list, await patient(() => api('POST', '/users', { body: x.payload }))]; }
        catch (e) { failed.push(`Fila ${x.line} (${x.payload.display_name}): ${e.problem?.detail === 'document_in_use' ? 'cédula ya usada' : errorMessage(e)}`); }
      }
      render(); done();
      if (!failed.length) { dialog.close(); return; }
      progress.textContent = `Terminado: ${ready.length - failed.length} creadas, ${failed.length} con error.`;
      const ul = el('ul', undefined, 'import-issues'); failed.forEach(t => ul.append(el('li', t))); preview.append(ul);
      ok.hidden = true;
    });
    dialog.showModal();
  };
  // Revisión UX M3: el alta es por bloques en su propia pantalla (miembro.php?nueva=1); «Editar» sigue siendo el atajo.
  const action = $('page-action'); action.textContent = 'Crear persona'; action.hidden = !!owner || !canCreatePeople; action.addEventListener('click', () => { location.href = '/miembro.php?nueva=1'; });
  render();
}

// ---- Ficha única de la persona (revisión UX G1 · PER-4) y alta por bloques (M3 · PER-2/3) ---------------------------
// miembro.php?id=<persona>&tab=datos|acceso|actividad|equipos (los enlaces antiguos «#equipos» siguen sirviendo) y
// miembro.php?nueva=1 para crear. Cada bloque guarda por separado (datos: PATCH; acceso: PUT …/access; plan: PUT …/plan)
// y dice qué quedó guardado. Los bloques que la cuenta no puede editar se muestran en lectura; la API valida igual.
const PERSON_TABS = [['datos', 'Datos'], ['acceso', 'Acceso y plan'], ['actividad', 'Actividad'], ['equipos', 'Equipos']];
const PERSON_HASH = { datos: ['datos'], acceso: ['acceso'], equipos: ['equipos'], actividad: ['actividad', 'actividad'], resumen: ['actividad', 'resumen'], presencia: ['actividad', 'presencia'], politicas: ['actividad', 'politicas'] };
const DEVICE_STATUS = { active: ['Activo', 'good'], inactive: ['Inactivo', ''], revoked: ['Revocado', 'bad'], decommissioned: ['Retirado', ''] };
const UNIT_EMPTY = { company: 'Aún no hay empresas del grupo (es opcional).', site: 'Aún no hay sedes: crea la primera para asignarla a personas.', area: 'Aún no hay áreas: crea la primera para asignarla a personas.', position: 'Aún no hay cargos: crea el primero para asignarlo a personas.' };
const clock = () => new Intl.DateTimeFormat('es-CO', { timeStyle: 'short' }).format(new Date()).replace(/\.$/, '');
// Guardado fallido de una persona, en español (conflictos, validaciones y §7 de v4-clientes-funciones-accesos.md).
function personError(e) {
  const d = e?.problem?.detail;
  if (d === 'hierarchy_cycle') return 'Ese jefe reporta (directa o indirectamente) a esta persona: no puede ser su jefe.';
  if (d === 'document_in_use') return 'Esa cédula ya pertenece a otra persona de la empresa.';
  if (d === 'invalid_document') return 'La cédula no es válida.';
  if (d === 'unknown_plan') return 'Ese plan está inactivo; actívalo o elige otro.';
  if (e?.status === 412) return 'Alguien más la modificó: recarga la página.';
  if (e?.status === 403 && !d) return 'Tu cuenta no tiene permiso para guardar este cambio.';
  return errorMessage(e);
}
// Lo que la ficha y el alta necesitan del cliente y de la cuenta. Los permisos solo deciden qué se muestra editable.
async function personContext() {
  const isSuper = !!readSession()?.is_platform_admin;
  const me = isSuper ? null : await loadAccess();
  const has = code => isSuper || !!me?.permissions?.some(p => p.code === code);
  const canAssign = has('roles.gestionar');
  const [org, users, schedules, calendarPage, avail, allPlans, domains, sources, roles] = await Promise.all([
    optional(allPages('/organization')), optional(allPages('/users')), optional(allPages('/schedules')), optional(api('GET', '/schedules/holiday-calendars?limit=100')),
    optional(api('GET', '/person-plans/available')), isSuper ? optional(api('GET', '/person-plans', { tenant: PLATFORM_TENANT })).then(r => r?.data || []) : [],
    optional(api('GET', '/data-domains')), optional(api('GET', '/data-sources')), canAssign ? optional(allPages('/roles')).then(r => r || []) : []]);
  const ownerId = domains?.data?.find(d => d.domain === 'personas')?.source_id || null;
  const ctx = {
    isSuper, me, has, org: org || [], orgDenied: !org, users: users || [], roles, avail,
    schedules: (schedules || []).sort((a, b) => a.name.localeCompare(b.name, 'es')), calendarPage, calendars: (calendarPage?.data || []).filter(c => c.active),
    owner: ownerId ? (sources?.data?.find(x => x.id === ownerId)?.name || 'una fuente externa') : null,
    canEdit: has('usuarios.editar'), canCreate: has('usuarios.crear'), canAssign: canAssign && roles.length > 0, canUnits: has('organizacion.editar'),
    canAccounts: isSuper || (!!me && allowed(me, 'accounts')), canIntake: isSuper || (!!me && allowed(me, 'intake')),
  };
  ctx.units = kind => ctx.org.filter(o => o.kind === kind && o.active).sort((a, b) => a.name.localeCompare(b.name, 'es'));
  ctx.unitName = id => ctx.org.find(o => o.id === id)?.name || null;
  ctx.planName = id => avail?.data.find(p => p.id === id)?.name || allPlans.find(p => p.id === id)?.name || null;
  ctx.defaultLabel = avail ? `Por defecto (${ctx.planName(avail.default_plan_id) || 'sin plan'})` : '';
  ctx.receives = planId => {
    const plan = avail.data.find(p => p.id === planId) || avail.data.find(p => p.id === avail.default_plan_id);
    const list = plan ? avail.features.filter(f => plan.features.includes(f.code)).map(f => f.name) : [];
    return `Recibe: ${list.length ? list.join(', ') : 'ninguna función de persona'}.`;
  };
  // Plan efectivo (no Tier): el propio si está activo; si no, el por defecto del cliente.
  ctx.effectivePlan = u => {
    if (!avail) return null;
    const own = avail.data.find(p => p.id === u.plan_id);
    if (own) return { name: own.name, origin: 'Propio', planId: own.id };
    return { name: ctx.defaultLabel, origin: u.plan_id ? `Su plan propio (${ctx.planName(u.plan_id) || 'Plan'}) está inactivo` : 'Heredado del cliente', planId: avail.default_plan_id };
  };
  ctx.defaultRole = roles.find(r => r.active && fold(r.name) === 'colaborador') || null;
  ctx.accountsHref = (u, create) => {
    const q = new URLSearchParams(); if (isSuper) q.set('empresa', tenant);
    if (create) { q.set('persona', u.id); q.set('crear', '1'); }
    return `/cuentas.php${q.toString() ? `?${q}` : ''}`;
  };
  return ctx;
}
// Selector de una unidad (sede, área, cargo, empresa del grupo) con «+ Nueva …» si la cuenta puede crearla: el
// catálogo vacío se anticipa con su mensaje y la unidad creada queda elegida sin salir del formulario.
function unitField(parent, ctx, kind, label, value, { optionalText = null, onCreated } = {}) {
  const wrap = el('div', undefined, 'unit-field'); parent.append(wrap);
  const sel = el('select');
  const fill = v => {
    sel.replaceChildren(new Option(optionalText || `Elige ${label.toLowerCase()}…`, ''));
    ctx.units(kind).forEach(o => sel.append(new Option(o.name, o.id)));
    if (v && ![...sel.options].some(o => o.value === v)) sel.append(new Option(`${ctx.unitName(v) || 'Unidad no disponible'} (inactiva)`, v));
    sel.value = v || '';
  };
  fill(value); labeled(wrap, label, sel);
  const hint = el('p', '', 'help'); wrap.append(hint);
  const tab = ORG_TABS.find(t => t.kind === kind);
  const syncHint = () => {
    const none = !ctx.units(kind).length;
    hint.textContent = none ? `${UNIT_EMPTY[kind]}${ctx.canUnits || kind === 'company' ? '' : ' Pide a quien administra Organización que la cree.'}` : '';
    hint.hidden = !none;
  };
  syncHint();
  if (ctx.canUnits && tab?.create) {
    const add = el('button', tab.create, 'link-button'); add.type = 'button'; wrap.append(add);
    add.addEventListener('click', () => openOrgCreate({ kind, org: ctx.org, onCreated: created => {
      ctx.org.push(created); fill(created.id); syncHint(); sel.dispatchEvent(new Event('change', { bubbles: true }));
      onCreated?.(created); sel.focus();
    } }));
  }
  return sel;
}
// Datos básicos de la persona (nombre, cédula, correo) y, aparte, jefe y monitoreo.
function identityFields(parent, ctx, u, extras = parent) {
  const name = labeled(parent, 'Nombre completo', el('input')); name.maxLength = 160; name.value = u?.display_name || ''; name.autocomplete = 'off';
  const doc = labeled(parent, 'Cédula', el('input'), 'Con ella, al instalar Keeper en su PC, el equipo queda asignado a esta persona.'); doc.maxLength = 40; doc.inputMode = 'numeric'; doc.value = u?.document || '';
  const email = labeled(parent, 'Correo (opcional)', el('input')); email.type = 'email'; email.maxLength = 254; email.value = u?.email || '';
  const boss = el('select'); boss.append(new Option('Nadie', ''));
  ctx.users.filter(x => x.status === 'active' && x.id !== u?.id).sort((a, b) => a.display_name.localeCompare(b.display_name, 'es')).forEach(x => boss.append(new Option(x.display_name, x.id)));
  if (u?.reports_to_user_id && ![...boss.options].some(o => o.value === u.reports_to_user_id)) boss.append(new Option('Persona fuera de tu alcance o inactiva', u.reports_to_user_id));
  boss.value = u?.reports_to_user_id || ''; labeled(extras, 'Reporta a (jefe)', boss, 'Con esto el jefe puede ver a su equipo si su acceso tiene el alcance "Su equipo".');
  const mon = el('select'); [['auto', 'Automático (según el cargo)'], ['monitored', 'Siempre monitorear'], ['excluded', 'No monitorear (directivo, dueño)']].forEach(([v, t]) => mon.append(new Option(t, v)));
  mon.value = u?.monitoring_override || 'auto'; labeled(extras, 'Monitoreo', mon);
  return { name, doc, email, boss, mon,
    read: () => ({ display_name: name.value.trim(), document: doc.value.trim() || null, email: email.value.trim() || null, reports_to_user_id: boss.value || null, monitoring_override: mon.value }) };
}
// Ubicación laboral: empresa del grupo, sede, área, cargo (y firma si existen), horario y festivos.
function laborFields(parent, ctx, u, extras = parent) {
  const grid = el('div', undefined, 'form-grid'); parent.append(grid);
  const fallback = kind => { const list = ctx.units(kind); return list.length === 1 ? list[0] : list.find(o => fold(o.name).startsWith('sin ')) || null; };
  const pick = kind => u ? u[`${kind}_id`] : fallback(kind)?.id;
  const company = unitField(grid, ctx, 'company', 'Empresa del grupo', u?.company_id, { optionalText: 'Sin empresa (todo el grupo)' });
  const selects = { site: unitField(grid, ctx, 'site', 'Sede', pick('site')), area: unitField(grid, ctx, 'area', 'Área', pick('area')), position: unitField(grid, ctx, 'position', 'Cargo', pick('position')) };
  // Firmas: solo mientras existan (no se crean nuevas; las reemplazan las empresas del grupo).
  if (ctx.units('firm').length || u?.firm_id) selects.firm = unitField(grid, ctx, 'firm', 'Firma', pick('firm'), { optionalText: 'Sin firma (se asigna después)' });
  const sched = el('select'); sched.append(new Option('Sin horario (no se espera jornada)', '')); ctx.schedules.forEach(x => sched.append(new Option(x.name, x.id)));
  const named = ctx.schedules.find(s => fold(s.name).includes('por defecto'));
  const suggested = u ? null : named || ctx.schedules[0] || null;
  sched.value = u ? (u.schedule_id || '') : (suggested?.id || '');
  const schedWrap = el('div'); grid.append(schedWrap);
  labeled(schedWrap, 'Horario', sched, !ctx.schedules.length ? 'Aún no hay horarios: sin horario no se espera jornada (puedes crearlos en Horarios).'
    : suggested ? `Sugerido: «${suggested.name}» (${named ? 'el horario llamado «por defecto»' : 'el primero de la lista'}). Elige otro o «Sin horario» si no aplica.` : null);
  let holidays = null;
  if (ctx.calendarPage) {
    holidays = el('select'); holidays.append(new Option('Automático (festivos del país de su sede)', '')); ctx.calendars.forEach(c => holidays.append(new Option(c.name, c.id)));
    holidays.value = u?.holiday_calendar_id || ''; labeled(extras, 'Festivos', holidays, 'Solo cambia si esta persona sigue un calendario distinto al del país de su sede.');
  }
  const missing = () => ['site', 'area', 'position'].filter(k => !selects[k].value).map(k => kinds[k].toLowerCase());
  const read = () => {
    const out = { schedule_id: sched.value || null };
    for (const [k] of ORG_FIELDS) out[`${k}_id`] = selects[k] ? (selects[k].value || null) : (u?.[`${k}_id`] ?? null);
    if (!u || (company.value || null) !== (u.company_id || null)) out.company_id = company.value || null;
    if (holidays && (!u || (holidays.value || null) !== (u.holiday_calendar_id || null))) out.holiday_calendar_id = holidays.value || null;
    return out;
  };
  return { missing, read, company, selects, sched, holidays };
}
const formValues = root => JSON.stringify([...root.querySelectorAll('input, select, textarea')].map(i => i.type === 'checkbox' ? i.checked : i.value));
// Pie de un bloque: botón de guardar con su propio estado (éxito u error), independiente de los demás bloques.
function blockFooter(parent, text) {
  const bar = el('div', undefined, 'block-actions'); parent.append(bar);
  const save = el('button', text, 'primary'); save.type = 'submit';
  const status = el('p', '', 'help'); status.setAttribute('role', 'status');
  const error = el('p', '', 'error-text'); error.setAttribute('role', 'alert'); error.hidden = true;
  bar.append(save, status); parent.append(error);
  return { save, ok: t => { status.textContent = t; error.hidden = true; }, fail: t => { error.textContent = t; error.hidden = false; status.textContent = ''; }, busy: on => { save.disabled = on; if (on) { status.textContent = 'Guardando…'; error.hidden = true; } } };
}
function personHeader(ctx, u, title) {
  const name = title || u.display_name;
  const h1 = document.querySelector('.page-head h1'); h1.replaceChildren(name);
  if (u) h1.append(' ', badge(u.status === 'active' ? 'Activa' : 'Inactiva', u.status === 'active' ? 'good' : ''));
  const sub = document.querySelector('.page-head p');
  if (u) {
    const site = ctx.org.find(o => o.id === u.site_id);
    sub.textContent = [['Empresa', ctx.unitName(u.company_id)], ['Sede', site ? `${site.name}${site.city ? ` (${site.city})` : ''}` : null], ['Área', ctx.unitName(u.area_id)], ['Cargo', ctx.unitName(u.position_id)]]
      .filter(([, v]) => v).map(([k, v]) => `${k}: ${v}`).join(' · ') || (u.email || 'Sin datos laborales visibles');
  } else sub.textContent = 'Paso a paso: datos básicos, ubicación laboral y, si quieres, acceso y plan.';
  const crumbs = document.querySelector('.breadcrumb');
  const people = el('a', 'Personas'); people.href = '/usuarios.php';
  crumbs.replaceChildren(crumbs.firstElementChild, el('span', '/'), people, el('span', '/'), el('span', name));
  document.title = `${name} · ${document.title.split(' · ').slice(1).join(' · ') || 'Keeper'}`;
}
// Fechas de la pestaña Actividad en la zona horaria del cliente (igual que el resto de pantallas del coordinador).
let tenantZone = 'UTC';
const zoneToday = () => { const p = new Intl.DateTimeFormat('en', { timeZone: tenantZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()); const g = t => p.find(x => x.type === t).value; return `${g('year')}-${g('month')}-${g('day')}`; };
const shiftDay = (day, days) => { const d = new Date(`${day}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + days); return d.toISOString().slice(0, 10); };
const withQuery = (path, values) => { const url = new URL(path, location.origin); Object.entries(values).forEach(([k, v]) => { if (v !== null && v !== undefined) url.searchParams.set(k, v); }); return url.pathname + url.search; };

async function personPage() {
  const params = new URLSearchParams(location.search);
  const ctx = await personContext();
  if (params.get('nueva') === '1') { personCreate(ctx); return; }
  const id = params.get('id');
  if (!isUuid(id)) { personHeader(ctx, null, 'Persona'); empty($('page-content'), 'Elige una persona', 'Abre la ficha desde Personas.'); return; }
  let u;
  try { u = await api('GET', `/users/${id}`); }
  catch (e) {
    if (e.status !== 404) throw e;
    personHeader(ctx, null, 'Persona no encontrada'); document.querySelector('.page-head p').textContent = '';
    empty($('page-content'), 'Persona no encontrada', 'No existe en este cliente o está fuera de tu alcance. Vuelve a Personas y búscala allí.'); return;
  }
  const hash = PERSON_HASH[location.hash.slice(1)];
  const tab = PERSON_TABS.some(([k]) => k === params.get('tab')) ? params.get('tab') : hash?.[0] || 'datos';
  personProfile(ctx, u, { tab, subtab: hash?.[1] || null });
}

function personProfile(ctx, u, { tab = 'datos', subtab = null, created = null, draft = null } = {}) {
  const content = $('page-content'); content.replaceChildren(); personHeader(ctx, u);
  const devicesReq = optional(allPages(`/tenants/${tenant}/devices?user_id=${encodeURIComponent(u.id)}`));
  if (created) {
    const n = el('div', undefined, 'notice'); n.setAttribute('role', created.pending.length ? 'alert' : 'status');
    if (created.pending.length) {
      const what = created.pending.map(([k, msg]) => `${k === 'plan' ? 'el plan' : 'el acceso'} no se guardó (${msg})`).join(' y ');
      n.append(el('p', `«${u.display_name}» quedó creada, pero ${what}. Lo que elegiste sigue abajo: revísalo y pulsa ${created.pending.map(([k]) => k === 'plan' ? '«Guardar plan»' : '«Guardar acceso»').join(' y ')} para reintentar.`));
    } else n.append(el('p', `«${u.display_name}» quedó creada con ${created.extras || 'el acceso y el plan por defecto'}.`));
    const next = el('div', undefined, 'actions no-margin');
    if (ctx.canAccounts && !u.panel_login_enabled) { const a = el('a', 'Crear cuenta del panel para esta persona', 'button'); a.href = ctx.accountsHref(u, true); next.append(a); }
    if (ctx.canIntake) { const a = el('a', 'Registrar su equipo', 'link-button'); a.href = '/alta.php'; next.append(a); }
    const more = el('a', 'Crear otra persona', 'link-button'); more.href = '/miembro.php?nueva=1'; next.append(more);
    n.append(el('p', u.panel_login_enabled ? '' : 'Todavía no puede iniciar sesión en el panel: necesita una cuenta.', 'help'), next); content.append(n);
  }
  const tabs = el('div', undefined, 'tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Ficha de la persona'); content.append(tabs);
  const views = {};
  for (const [key, label] of PERSON_TABS) {
    const b = el('button', label); b.type = 'button'; b.id = `person-tab-${key}`; b.dataset.key = key; b.setAttribute('role', 'tab'); b.setAttribute('aria-controls', `person-panel-${key}`);
    const p = el('div'); p.id = `person-panel-${key}`; p.setAttribute('role', 'tabpanel'); p.setAttribute('aria-labelledby', b.id); p.tabIndex = 0; p.hidden = true;
    tabs.append(b); content.append(p); views[key] = { button: b, panel: p, drawn: false, dirty: () => false, label };
    b.addEventListener('click', () => go(key));
  }
  let current = null;
  const draw = key => {
    const v = views[key]; v.panel.replaceChildren(); v.dirty = () => false; v.drawn = true;
    if (key === 'datos') v.dirty = dataPanel(v.panel, ctx, () => u, saved => { u = saved; personHeader(ctx, u); });
    else if (key === 'acceso') v.dirty = accessPanel(v.panel, ctx, () => u, saved => { u = saved; personHeader(ctx, u); }, draft);
    else if (key === 'actividad') activityPanel(v.panel, u, subtab);
    else devicesPanel(v.panel, ctx, devicesReq);
    draft = null;
  };
  const go = (key, { force = false } = {}) => {
    if (key === current) return;
    const leaving = current && views[current];
    if (!force && leaving?.dirty() && !confirm(`Hay cambios sin guardar en «${leaving.label}». Si cambias de pestaña se descartan. ¿Cambiar de pestaña?`)) { leaving.button.focus(); return; }
    if (leaving?.dirty()) leaving.drawn = false; // descartados: al volver se dibuja con lo guardado
    current = key;
    for (const [k, v] of Object.entries(views)) { const on = k === key; v.button.setAttribute('aria-selected', String(on)); v.button.tabIndex = on ? 0 : -1; v.panel.hidden = !on; }
    if (!views[key].drawn) draw(key);
    history.replaceState(null, '', `/miembro.php?id=${encodeURIComponent(u.id)}&tab=${key}`);
  };
  tabs.addEventListener('keydown', e => {
    const list = [...tabs.children]; const i = list.indexOf(document.activeElement); let next;
    if (e.key === 'ArrowRight') next = (i + 1) % list.length; if (e.key === 'ArrowLeft') next = (i + list.length - 1) % list.length; if (e.key === 'Home') next = 0; if (e.key === 'End') next = list.length - 1;
    if (next !== undefined) { e.preventDefault(); list[next].click(); if (current === list[next].dataset.key) list[next].focus(); }
  });
  window.addEventListener('beforeunload', e => { if (Object.values(views).some(v => v.drawn && v.dirty())) { e.preventDefault(); e.returnValue = ''; } });
  // Equipos: el conteo va en la pestaña en cuanto se conoce.
  devicesReq.then(list => { if (list) { const c = el('span', String(list.length), 'tab-count'); views.equipos.button.append(' ', c); } }).catch(() => {});
  const action = $('page-action');
  if (ctx.canEdit && !u.managed_by) {
    action.textContent = 'Editar persona'; action.hidden = false;
    action.onclick = () => { go('datos'); views.datos.panel.querySelector('input')?.focus(); };
  }
  go(tab, { force: true });
}

// Pestaña Datos: datos básicos y ubicación laboral, un solo «Guardar datos» (PATCH /users/{id}).
function dataPanel(panel, ctx, getUser, onSaved) {
  const u = getUser();
  const s = surface('Datos de la persona', panel);
  if (!ctx.canEdit || u.managed_by) {
    s.append(el('p', u.managed_by ? `Los datos laborales los gestiona ${u.managed_by}: cámbialos allá y llegarán solos. El acceso y el plan sí se editan aquí.`
      : 'Tu cuenta puede ver estos datos, pero no editarlos (se necesita el permiso para editar personas).', 'notice'));
    const site = ctx.org.find(o => o.id === u.site_id);
    const dl = el('dl', undefined, 'details');
    [['Nombre', u.display_name], ['Cédula', u.document || '—'], ['Correo', u.email || '—'], ['Empresa del grupo', ctx.unitName(u.company_id) || 'Sin empresa (todo el grupo)'],
      ['Sede', site ? `${site.name} · ${placeOf(site)}` : '—'], ['Área', ctx.unitName(u.area_id) || '—'], ['Cargo', ctx.unitName(u.position_id) || '—'],
      ['Jefe', ctx.users.find(x => x.id === u.reports_to_user_id)?.display_name || (u.reports_to_user_id ? 'Fuera de tu alcance' : 'Nadie')],
      ['Horario', ctx.schedules.find(x => x.id === u.schedule_id)?.name || (u.schedule_id ? 'No disponible' : 'Sin horario')],
      ['Festivos', ctx.calendars.find(c => c.id === u.holiday_calendar_id)?.name || 'Automático (país de su sede)'], ['Estado', u.status === 'active' ? 'Activa' : 'Inactiva (retirada)']]
      .forEach(([k, v]) => { const r = el('div'); r.append(el('dt', k), el('dd', v)); dl.append(r); });
    s.append(dl);
    return () => false;
  }
  const form = el('form', undefined, 'person-form'); form.noValidate = true; s.append(form);
  const basic = el('fieldset', undefined, 'perm-group'); basic.append(el('legend', 'Datos básicos')); form.append(basic);
  const id = identityFields(basic, ctx, u);
  const work = el('fieldset', undefined, 'perm-group'); work.append(el('legend', 'Ubicación laboral')); form.append(work);
  const labor = laborFields(work, ctx, u);
  const status = el('select'); status.append(new Option('Activa', 'active'), new Option('Inactiva (retirada)', 'inactive')); status.value = u.status; labeled(work, 'Estado', status);
  const foot = blockFooter(form, 'Guardar datos');
  let baseline = formValues(form);
  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    const cur = getUser(); const basicData = id.read();
    if (!basicData.display_name) { foot.fail('Escribe el nombre.'); id.name.focus(); return; }
    const missing = labor.missing();
    if (missing.length) { foot.fail(`Falta ${missing.join(', ')}: elígela${ctx.canUnits ? ' o créala con «+ Nueva …»' : ''}.`); return; }
    if (status.value === 'inactive' && cur.status === 'active' && !confirm(`¿Marcar a ${cur.display_name} como inactiva? Sus equipos quedan revocados y dejan de reportar.`)) return;
    const payload = { ...basicData, ...labor.read() };
    if (status.value !== cur.status) payload.status = status.value;
    foot.busy(true);
    try {
      const saved = await api('PATCH', `/users/${cur.id}`, { body: payload, ifMatch: cur.version });
      onSaved(saved); baseline = formValues(form); foot.ok(`Datos guardados a las ${clock()}.`);
    } catch (e) { foot.fail(`Los datos no se guardaron: ${personError(e)}`); }
    finally { foot.save.disabled = false; }
  });
  return () => formValues(form) !== baseline;
}

// Pestaña Acceso y plan: cuenta del panel (estado y enlace), acceso (1 rol + 1 alcance) y plan por persona.
function accessPanel(panel, ctx, getUser, onSaved, draft) {
  const u = getUser(); const n = names(ctx.org);
  const summaryOf = x => x.access ? scopeSummary(x.access.role_name, x.access.scope, n) : 'Sin acceso al panel';
  const s = surface('Cuenta y acceso al panel', panel);
  const account = el('div', undefined, 'account-line'); s.append(account);
  const drawAccount = async () => {
    const x = getUser(); account.replaceChildren();
    const p = el('p');
    if (!x.panel_login_enabled) {
      p.append(badge('Sin cuenta', 'warn'), ' ', x.status === 'active' ? 'Todavía no puede iniciar sesión: no tiene cuenta del panel.' : 'La persona está inactiva: no puede iniciar sesión.');
      account.append(p);
      if (ctx.canAccounts && x.status === 'active') { const a = el('a', 'Crear cuenta del panel para esta persona', 'button'); a.href = ctx.accountsHref(x, true); account.append(a); }
      else if (!ctx.canAccounts) account.append(el('p', 'Las cuentas del panel las crea quien administra Cuentas.', 'help'));
      return;
    }
    p.append(badge('Cuenta habilitada', 'good')); account.append(p);
    if (ctx.canAccounts) {
      const list = await optional(api('GET', '/panel-accounts', ctx.isSuper ? { tenant: PLATFORM_TENANT } : {}));
      const acc = list?.data?.find(a => a.user_id === x.id);
      if (acc) p.append(' ', `${acc.email} · ${!acc.active ? 'Cuenta desactivada' : acc.must_change_password ? 'Primer ingreso pendiente (contraseña temporal)' : 'Primer ingreso completado'}`);
      const a = el('a', 'Gestionar en Cuentas', 'link-button'); a.href = ctx.accountsHref(x, false); account.append(a);
    }
  };
  drawAccount().catch(() => {});
  const now = el('p', undefined, 'access-now'); s.append(now);
  const drawNow = () => { now.replaceChildren(el('strong', 'Acceso actual: '), summaryOf(getUser())); };
  drawNow();
  s.append(el('p', 'El rol define qué puede hacer y el alcance qué datos ve. No crean la cuenta: sin cuenta del panel no puede iniciar sesión.', 'help'));
  let accessDirty = () => false;
  if (ctx.canAssign) {
    const form = el('form', undefined, 'person-form'); form.noValidate = true; s.append(form);
    const ed = accessEditor(form, { roles: ctx.roles, org: ctx.org, current: draft?.access || u.access });
    const foot = blockFooter(form, 'Guardar acceso');
    const saved = () => { const x = getUser(); return x.access ? { role_id: x.access.role_id, scope: x.access.scope } : null; };
    accessDirty = () => { const w = ed.read(); return !!w && !sameAccess(w, saved()); };
    form.addEventListener('submit', async ev => {
      ev.preventDefault();
      const p = ed.problem(); if (p) { foot.fail(p); return; }
      const wanted = ed.read();
      if (sameAccess(wanted, saved())) { foot.ok('El acceso no cambió.'); return; }
      foot.busy(true);
      try { const x = await api('PUT', `/users/${getUser().id}/access`, { body: wanted, ifMatch: getUser().version }); onSaved(x); drawNow(); foot.ok(`Acceso guardado a las ${clock()}: ${summaryOf(x)}.`); }
      catch (e) { foot.fail(`El acceso no se guardó: ${personError(e)}`); }
      finally { foot.save.disabled = false; }
    });
  } else s.append(el('p', 'Tu cuenta no puede cambiar accesos: se necesita el permiso para gestionar roles y accesos.', 'notice'));

  const ps = surface('Plan por persona', panel);
  let planDirty = () => false;
  if (!ctx.avail) {
    ps.append(el('p', 'Tu cuenta no puede ver ni asignar planes (se necesita el permiso para asignar planes).', 'notice'));
    return () => accessDirty();
  }
  const eff = el('p', undefined, 'plan-now'); ps.append(eff);
  const drawPlan = () => { const e = ctx.effectivePlan(getUser()); eff.replaceChildren(el('strong', 'Plan vigente: '), e.name, ' ', badge(e.origin, e.origin === 'Propio' ? 'info' : '')); };
  drawPlan();
  const form = el('form', undefined, 'person-form'); form.noValidate = true; ps.append(form);
  const sel = el('select'); sel.append(new Option(ctx.defaultLabel, ''));
  ctx.avail.data.forEach(x => sel.append(new Option(x.name, x.id)));
  if (u.plan_id && !ctx.avail.data.some(p => p.id === u.plan_id)) sel.append(new Option(`${ctx.planName(u.plan_id) || 'Plan'} (inactivo: usa el por defecto)`, u.plan_id));
  sel.value = draft && 'plan' in draft ? (draft.plan || '') : (u.plan_id || '');
  labeled(form, 'Plan', sel, 'Define qué funciones de persona recibe. Sin plan propio usa el del cliente; nunca recibe lo que el cliente no compró.');
  const receives = el('p', '', 'help'); receives.setAttribute('aria-live', 'polite'); form.append(receives);
  const sync = () => { receives.textContent = ctx.receives(sel.value); }; sel.addEventListener('change', sync); sync();
  form.append(el('p', 'El plan no concede permisos para administrar el panel.', 'help'));
  const foot = blockFooter(form, 'Guardar plan');
  planDirty = () => (sel.value || null) !== (getUser().plan_id || null);
  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    if (!planDirty()) { foot.ok('El plan no cambió.'); return; }
    foot.busy(true);
    try { const x = await api('PUT', `/users/${getUser().id}/plan`, { body: { plan_id: sel.value || null }, ifMatch: getUser().version }); onSaved(x); drawPlan(); foot.ok(`Plan guardado a las ${clock()}.`); }
    catch (e) { foot.fail(`El plan no se guardó: ${personError(e)}`); }
    finally { foot.save.disabled = false; }
  });
  return () => accessDirty() || planDirty();
}

// Pestaña Actividad: las vistas del coordinador (productividad, presencia y reglas efectivas) para esta persona.
function activityPanel(panel, u, subtab) {
  const get = path => api('GET', path);
  coordinator({ page: 'member', tenant, timezone: tenantZone, get, allPages: path => allPages(path), today: zoneToday, shiftDay, query: withQuery, permissions: new Map(), root: panel, user: u, subtab })
    .catch(e => { const p = el('p', errorMessage(e), 'error-text'); p.setAttribute('role', 'alert'); panel.append(p); });
}

// Pestaña Equipos: los equipos asignados a la persona, cada uno con su ficha.
async function devicesPanel(panel, ctx, request) {
  const s = surface('Equipos asignados', panel);
  const wait = el('p', 'Consultando equipos…', 'help'); s.append(wait);
  let list;
  try { list = await request; } catch (e) { const p = el('p', errorMessage(e), 'error-text'); p.setAttribute('role', 'alert'); wait.replaceWith(p); return; }
  wait.remove();
  if (!list) { s.append(el('p', 'Tu cuenta no puede consultar equipos.', 'notice')); return; }
  if (!list.length) {
    const box = el('div', undefined, 'empty'); box.append(el('h2', 'Sin equipos'), el('p', 'Esta persona aún no tiene equipos registrados. Al instalar Keeper con su cédula, el equipo queda asignado a ella.'));
    if (ctx.canIntake) { const a = el('a', 'Registrar equipos', 'button'); a.href = '/alta.php'; box.append(a); }
    s.append(box); return;
  }
  const wrap = el('div', undefined, 'table-wrap'); wrap.tabIndex = 0; s.append(wrap);
  const t = el('table'); t.append(el('caption', `Equipos de la persona · ${list.length}`));
  const hr = el('tr'); ['Equipo', 'Estado', 'Última conexión', 'Sistema', 'Agente'].forEach(h => { const th = el('th', h); th.scope = 'col'; hr.append(th); });
  const head = el('thead'); head.append(hr); const body = el('tbody');
  for (const d of list) {
    const tr = el('tr'); const a = el('a', d.hostname || 'Equipo sin nombre'); a.href = `/equipo.php?id=${encodeURIComponent(d.id)}`;
    const [st, tone] = DEVICE_STATUS[d.status] || [d.status || '—', ''];
    const status = el('div'); status.append(badge(st, tone)); if (online(d)) status.append(' ', badge('En línea', 'info'));
    [a, status, when(d.last_seen_at), d.os_edition || '—', d.agent_version || '—'].forEach(v => { const td = el('td'); if (v instanceof Node) td.append(v); else td.textContent = v; tr.append(td); });
    body.append(tr);
  }
  t.append(head, body); wrap.append(t);
}

// Alta por bloques: 1) datos básicos, 2) ubicación laboral (con «+ Nueva …» si falta el catálogo), 3) acceso y plan
// (opcional, con el valor por defecto a la vista). Crear guarda la persona y luego, por separado, plan y acceso: si uno
// falla, se dice qué quedó guardado y se sigue en la ficha de la persona para reintentarlo.
function personCreate(ctx) {
  personHeader(ctx, null, 'Crear persona');
  const content = $('page-content'); content.replaceChildren();
  if (!ctx.canCreate) { empty(content, 'Sin permiso', 'Tu cuenta no puede crear personas (se necesita el permiso para crear personas).'); return; }
  if (ctx.owner) { content.append(el('p', `Las personas las gestiona ${ctx.owner}: las altas se hacen allá y llegan solas.`, 'notice')); return; }
  const steps = [['basicos', 'Datos básicos'], ['ubicacion', 'Ubicación laboral'], ['acceso', 'Acceso y plan (opcional)']];
  const ol = el('ol', undefined, 'person-steps'); ol.setAttribute('aria-label', 'Pasos para crear la persona'); content.append(ol);
  const items = steps.map(([, t], i) => { const li = el('li'); li.append(el('span', String(i + 1), 'step-number'), ` ${t}`); ol.append(li); return li; });
  const form = el('form', undefined, 'person-form'); form.noValidate = true; content.append(form);
  const sections = steps.map(([key, title]) => { const s = surface(title, form); s.dataset.step = key; return s; });
  // 1) Datos básicos
  const extras1 = el('details', undefined, 'more-options'); extras1.append(el('summary', 'Opciones adicionales: jefe y monitoreo'));
  const id = identityFields(sections[0], ctx, null, extras1); sections[0].append(extras1);
  // 2) Ubicación laboral
  sections[1].append(el('p', 'Sede, área y cargo son obligatorios. Si falta alguno, puedes crearlo aquí mismo sin perder lo que ya escribiste.', 'help'));
  const extras2 = el('details', undefined, 'more-options'); extras2.append(el('summary', 'Opciones adicionales: festivos'));
  const labor = laborFields(sections[1], ctx, null, extras2); if (labor.holidays) sections[1].append(extras2);
  // 3) Acceso y plan
  const a = sections[2];
  a.append(el('p', 'Puedes dejarlo como está y cambiarlo después en su ficha.', 'help'));
  const defaultAccess = ctx.defaultRole || !ctx.canAssign ? 'Colaborador · Solo lo propio' : 'Sin acceso al panel (no hay un rol Colaborador activo)';
  const accessNow = el('p'); accessNow.append(el('strong', 'Acceso por defecto: '), defaultAccess); a.append(accessNow);
  a.append(el('p', 'Una regla por cargo puede proponer otro rol. El acceso no crea la cuenta: la cuenta del panel se crea después, como paso aparte.', 'help'));
  let accessEd = null, custom = null;
  if (ctx.canAssign) {
    custom = el('input'); custom.type = 'checkbox';
    const l = el('label', undefined, 'check'); l.append(custom, ' Darle otro acceso ahora'); a.append(l);
    const holder = el('div'); a.append(holder); accessEd = accessEditor(holder, { roles: ctx.roles, org: ctx.org, current: null });
    const sync = () => { holder.hidden = !custom.checked; }; custom.addEventListener('change', sync); sync();
  } else a.append(el('p', 'Tu cuenta no puede asignar accesos: quedará con el acceso por defecto.', 'help'));
  let planSel = null;
  if (ctx.avail) {
    planSel = el('select'); planSel.append(new Option(ctx.defaultLabel, '')); ctx.avail.data.forEach(x => planSel.append(new Option(x.name, x.id)));
    labeled(a, 'Plan', planSel, 'Por defecto usa el plan del cliente. El plan no concede permisos para administrar el panel.');
    const rec = el('p', '', 'help'); rec.setAttribute('aria-live', 'polite'); a.append(rec);
    const sync = () => { rec.textContent = ctx.receives(planSel.value); }; planSel.addEventListener('change', sync); sync();
  } else a.append(el('p', 'Plan: el por defecto del cliente.', 'help'));
  const error = el('p', '', 'error-text'); error.setAttribute('role', 'alert'); error.hidden = true; form.append(error);
  const bar = el('div', undefined, 'dialog-footer step-actions'); form.append(bar);
  const cancel = el('a', 'Cancelar', 'button'); cancel.href = '/usuarios.php';
  const back = el('button', 'Anterior'); back.type = 'button';
  const next = el('button', 'Siguiente', 'primary'); next.type = 'button';
  const submit = el('button', 'Crear persona', 'primary'); submit.type = 'submit';
  bar.append(cancel, back, next, submit);
  let step = 0;
  const show = i => {
    step = i; error.hidden = true;
    sections.forEach((s, k) => { s.hidden = k !== i; });
    items.forEach((li, k) => { li.classList.toggle('done', k < i); if (k === i) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current'); });
    back.hidden = i === 0; next.hidden = i === steps.length - 1; submit.hidden = i !== steps.length - 1;
    sections[i].querySelector('input, select')?.focus();
  };
  const problem = i => {
    if (i >= 0 && !id.name.value.trim()) return [0, 'Escribe el nombre.'];
    if (i >= 1) { const m = labor.missing(); if (m.length) return [1, `Falta ${m.join(', ')}: elígela${ctx.canUnits ? ' o créala con «+ Nueva …»' : ' (pídela a quien administra Organización)'}.`]; }
    if (i >= 2 && custom?.checked && accessEd.read() && !accessEd.valid()) return [2, accessEd.problem()];
    if (i >= 2 && custom?.checked && !accessEd.read()) return [2, 'Elige el rol o desmarca «Darle otro acceso ahora».'];
    return null;
  };
  const fault = ([i, text]) => { if (i !== step) show(i); error.textContent = text; error.hidden = false; };
  next.addEventListener('click', () => { const p = problem(step); if (p) { fault(p); return; } show(step + 1); });
  back.addEventListener('click', () => show(step - 1));
  let baseline = formValues(form);
  window.addEventListener('beforeunload', e => { if (baseline !== null && formValues(form) !== baseline) { e.preventDefault(); e.returnValue = ''; } });
  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    if (step !== steps.length - 1) { next.click(); return; }
    const p = problem(2); if (p) { fault(p); return; }
    submit.disabled = true; error.hidden = true;
    const payload = { ...id.read(), ...labor.read() };
    let u;
    try { u = await api('POST', '/users', { body: payload }); }
    catch (e) {
      const d = e.problem?.detail;
      fault([['document_in_use', 'invalid_document'].includes(d) ? 0 : d === 'hierarchy_cycle' ? 0 : step, `La persona no se creó: ${personError(e)}`]);
      submit.disabled = false; return;
    }
    // Desde aquí la persona existe: plan y acceso se guardan aparte y cada fallo se informa sin repetir el alta.
    baseline = null; ctx.users.push(u);
    const pending = []; const saved = []; const draft = {};
    if (planSel?.value) {
      try { u = await api('PUT', `/users/${u.id}/plan`, { body: { plan_id: planSel.value }, ifMatch: u.version }); saved.push(`plan ${ctx.planName(planSel.value)}`); }
      catch (e) { pending.push(['plan', personError(e)]); draft.plan = planSel.value; }
    }
    const wanted = custom?.checked ? accessEd.read() : null;
    if (wanted && !sameAccess(wanted, u.access ? { role_id: u.access.role_id, scope: u.access.scope } : null)) {
      try { u = await api('PUT', `/users/${u.id}/access`, { body: wanted, ifMatch: u.version }); saved.push(`acceso ${scopeSummary(u.access?.role_name, u.access?.scope, names(ctx.org))}`); }
      catch (e) { pending.push(['acceso', personError(e)]); draft.access = wanted; }
    }
    history.replaceState(null, '', `/miembro.php?id=${encodeURIComponent(u.id)}&tab=acceso`);
    personProfile(ctx, u, { tab: 'acceso', created: { pending, extras: saved.join(' y ') }, draft: pending.length ? draft : null });
    done();
  });
  show(0);
}

// ---- Horarios ---------------------------------------------------------------------------------------------------
const WEEKDAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
const timeInput = (value = '') => { const i = el('input'); i.type = 'time'; i.step = 60; i.value = value || ''; return i; };
function scheduleSummary(s) {
  const own = new Map((s.day_hours || []).map(d => [d.weekday, d]));
  const general = s.days.filter(d => !own.has(d)).map(d => WEEKDAYS[d - 1].slice(0, 3)).join(', ');
  const parts = [];
  if (general) parts.push(`${general}: ${s.start_local}–${s.end_local}`);
  for (const d of s.day_hours || []) parts.push(`${WEEKDAYS[d.weekday - 1].slice(0, 3)}: ${d.start_local}–${d.end_local}`);
  return parts.join(' · ') || 'Sin días laborables';
}
async function schedulesPage() {
  const [list0, users] = await Promise.all([allPages('/schedules'), optional(allPages('/users'))]);
  let list = list0;
  const people = id => (users || []).filter(u => u.schedule_id === id && u.status === 'active').length;
  const render = () => {
    $('page-content').replaceChildren();
    const s = surface('Horarios de la empresa');
    s.append(el('p', 'La jornada de cada persona: con ella se calcula la puntualidad, el tiempo esperado y qué actividad es laboral, almuerzo o fuera de horario. Un día puede tener horas propias (por ejemplo, sábado medio día).', 'help'));
    if (!list.length) { empty(s, 'Aún no hay horarios', 'Crea el primero con "Crear horario".'); return; }
    table(s, 'Horarios', [['Horario', x => x.name], ['Días y horas', scheduleSummary],
      ['Almuerzo', x => x.lunch_start_local ? `${x.lunch_start_local}–${x.lunch_end_local}` : 'Sin almuerzo'], ['Zona horaria', x => x.timezone],
      ['Personas', x => users ? String(people(x.id)) : '—'],
      ['Acciones', x => { const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => editor(x)); return b; }]], list);
  };
  const editor = x => {
    const { dialog, body, ok, show } = dialogShell(x ? `Editar ${x.name}` : 'Crear horario');
    dialog.classList.add('wide-dialog');
    const name = labeled(body, 'Nombre', el('input')); name.maxLength = 120; name.value = x?.name || '';
    const tz = labeled(body, 'Zona horaria', el('input'), 'Ejemplo: America/Bogota'); tz.maxLength = 64; tz.value = x?.timezone || 'America/Bogota';
    const grid = el('div', undefined, 'form-grid'); body.append(grid);
    const start = labeled(grid, 'Entrada', timeInput(x?.start_local || '08:00')); const end = labeled(grid, 'Salida', timeInput(x?.end_local || '17:00'));
    const lunchStart = labeled(grid, 'Inicio de almuerzo (opcional)', timeInput(x?.lunch_start_local)); const lunchEnd = labeled(grid, 'Fin de almuerzo (opcional)', timeInput(x?.lunch_end_local));
    body.append(el('h3', 'Días laborables'));
    body.append(el('p', 'Marca los días que se trabaja. Si un día tiene horas distintas, actívalo en "Horas propias".', 'help'));
    const own = new Map((x?.day_hours || []).map(d => [d.weekday, d]));
    const rows = WEEKDAYS.map((label, i) => {
      const weekday = i + 1; const row = el('div', undefined, 'day-row'); body.append(row);
      const works = el('input'); works.type = 'checkbox'; works.checked = x ? x.days.includes(weekday) : weekday <= 5;
      const wl = el('label', undefined, 'check'); wl.append(works, ` ${label}`); row.append(wl);
      const special = el('input'); special.type = 'checkbox'; special.checked = own.has(weekday);
      const sl = el('label', undefined, 'check'); sl.append(special, ' Horas propias'); row.append(sl);
      const d = own.get(weekday);
      const box = el('div', undefined, 'day-hours'); row.append(box);
      const ds = timeInput(d?.start_local || start.value), de = timeInput(d?.end_local || end.value), dls = timeInput(d?.lunch_start_local), dle = timeInput(d?.lunch_end_local);
      [['Entrada', ds], ['Salida', de], ['Almuerzo desde', dls], ['hasta', dle]].forEach(([t, inp]) => { const l = el('label', t); l.append(inp); box.append(l); });
      const sync = () => { special.disabled = !works.checked; box.hidden = !(works.checked && special.checked); };
      works.addEventListener('change', sync); special.addEventListener('change', sync); sync();
      return { weekday, label, works, special, ds, de, dls, dle };
    });
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      const days = rows.filter(r => r.works.checked).map(r => r.weekday);
      if (!name.value.trim()) { show('Escribe el nombre del horario.'); return; }
      if (!start.value || !end.value) { show('Escribe la hora de entrada y de salida.'); return; }
      if (!!lunchStart.value !== !!lunchEnd.value || (lunchStart.value && lunchStart.value >= lunchEnd.value)) { show('El almuerzo necesita inicio y fin, y el inicio debe ser antes del fin.'); return; }
      const dayHours = [];
      for (const r of rows.filter(r => r.works.checked && r.special.checked)) {
        if (!r.ds.value || !r.de.value) { show(`${r.label}: escribe entrada y salida.`); return; }
        if (!!r.dls.value !== !!r.dle.value || (r.dls.value && r.dls.value >= r.dle.value)) { show(`${r.label}: el almuerzo necesita inicio y fin, en orden.`); return; }
        dayHours.push({ weekday: r.weekday, start_local: r.ds.value, end_local: r.de.value, lunch_start_local: r.dls.value || null, lunch_end_local: r.dle.value || null });
      }
      ok.disabled = true;
      const payload = { name: name.value.trim(), timezone: tz.value.trim(), days, start_local: start.value, end_local: end.value,
        lunch_start_local: lunchStart.value || null, lunch_end_local: lunchEnd.value || null, day_hours: dayHours };
      try {
        const saved = x ? await api('PUT', `/schedules/${x.id}`, { body: payload, ifMatch: x.version }) : await api('POST', '/schedules', { body: payload });
        list = x ? list.map(y => y.id === x.id ? saved : y) : [...list, saved]; dialog.close(); render(); done();
      } catch (e) {
        const d = e.problem?.detail;
        show(d === 'invalid_day_hours' ? 'Las horas propias solo pueden ir en días laborables.' : d === 'invalid_lunch' ? 'Revisa el almuerzo: inicio y fin, en orden.' : e.status === 412 ? 'Alguien más lo cambió: recarga la página.' : e.status === 422 ? 'Revisa la zona horaria (ejemplo: America/Bogota) y las horas.' : e);
        ok.disabled = false;
      }
    });
    dialog.showModal();
  };
  const action = $('page-action'); action.textContent = 'Crear horario'; action.hidden = false; action.addEventListener('click', () => editor(null));
  render();
}

// ---- Fuentes de datos -------------------------------------------------------------------------------------------
const DOMAIN_LABELS = { firmas: 'Firmas', sedes: 'Sedes', areas: 'Áreas', cargos: 'Cargos', horarios: 'Horarios', festivos: 'Festivos', personas: 'Personas',
  jerarquia: 'Jerarquía (a quién reporta)', ausencias: 'Ausencias de RR. HH.' };
const RUN_STATUS = { applied: ['Aplicada', 'good'], simulated: ['Simulación', 'info'], blocked: ['Bloqueada: requiere aprobación', 'warn'], failed: ['Falló', 'bad'], running: ['En curso', ''] };
const BLOCK_REASONS = { too_many_deactivations: 'Iba a retirar a más personas de las permitidas de una sola vez.', list_too_small: 'La fuente devolvió muy pocas personas (o ninguna): parece una lista incompleta.' };
const RUN_ERRORS = { source_unreachable: 'No se pudo conectar con la fuente.', invalid_envelope: 'La fuente respondió con un formato que no es keeper.roster.v1.', incomplete_list: 'La fuente no marcó la lista como completa.',
  inconsistent_pages: 'Las páginas de la lista no eran de la misma fecha.', insecure_url: 'La URL no es https.', source_invalid_json: 'La fuente no devolvió JSON válido.', too_many_pages: 'La lista tenía demasiadas páginas.' };
const PENDING = {
  matched_name: ['Ya existe algo con el mismo nombre en Keeper. ¿Es lo mismo?', 'Sí, es lo mismo (vincular)', 'No, crear aparte'],
  orphan: ['Esta persona está en Keeper pero la fuente no la trae.', 'Retirarla', 'Dejarla como persona local'],
  document_change: ['La fuente cambió la cédula de esta persona.', 'Aceptar la nueva cédula', 'Mantener la actual'],
  deactivate_in_use: ['La fuente la desactivó, pero todavía tiene personas asignadas. Muévelas primero.', 'Desactivar (sin personas)', 'Entendido, dejar activa'],
};
const runCounts = r => {
  const parts = [];
  for (const [domain, c] of Object.entries(r.counts || {})) {
    const bits = [['create', 'nuevas'], ['update', 'cambios'], ['link', 'vinculadas'], ['deactivate', 'retiros'], ['pending', 'pendientes'], ['error', 'errores']].filter(([k]) => c[k]).map(([k, t]) => `${c[k]} ${t}`);
    if (bits.length) parts.push(`${DOMAIN_LABELS[domain] || domain}: ${bits.join(', ')}`);
  }
  return parts.join(' · ') || 'Sin cambios';
};
async function sourcesPage() {
  // Crear, editar, sincronizar, aprobar y elegir dueños es solo de la plataforma (la API responde 403 a los clientes):
  // a una cuenta de cliente se le muestra la lista en solo lectura y se le dice quién lo hace.
  const manage = !!readSession()?.is_platform_admin;
  const READ_ONLY_NOTE = 'Solo AZC Keeper configura y sincroniza fuentes de datos.';
  let [sources, domains, runs, pending] = await Promise.all([api('GET', '/data-sources'), api('GET', '/data-domains'), api('GET', '/sync-runs'), optional(api('GET', '/sync-items/pending'))]);
  const name = id => sources.data.find(s => s.id === id)?.name || 'Fuente';
  const reload = async () => {
    [sources, domains, runs, pending] = await Promise.all([api('GET', '/data-sources'), api('GET', '/data-domains'), api('GET', '/sync-runs'), optional(api('GET', '/sync-items/pending'))]);
    render(); done();
  };
  const runNow = async (s, simulate) => {
    $('page-status').textContent = simulate ? 'Simulando… puede tardar un minuto.' : 'Sincronizando… puede tardar un minuto.';
    try { const r = await api('POST', `/data-sources/${s.id}/runs`, { body: { kind: 'full', simulate } }); await reload(); showRun(r); } catch (e) { fail(e); }
  };
  const showRun = async r => {
    const { dialog, body, ok, cancel } = dialogShell(`Vuelta del ${when(r.started_at)}`);
    dialog.classList.add('wide-dialog'); cancel.hidden = true; ok.textContent = 'Cerrar'; ok.type = 'button'; ok.addEventListener('click', () => dialog.close());
    const [label, tone] = RUN_STATUS[r.status] || [r.status, ''];
    body.append(badge(label, tone), el('p', runCounts(r)));
    if (r.status === 'blocked') body.append(el('p', BLOCK_REASONS[r.blocked_reason] || r.blocked_reason, 'error-text'));
    if (r.status === 'failed') body.append(el('p', RUN_ERRORS[r.error_code] || r.error_code, 'error-text'));
    if (r.simulate) body.append(el('p', 'Es una simulación: muestra lo que haría, sin cambiar nada.', 'help'));
    try {
      const items = (await api('GET', `/sync-runs/${r.id}/items`)).data;
      const t = el('div'); body.append(t);
      const action = { create: 'Nueva', update: 'Cambio', link: 'Vinculada', deactivate: 'Retiro / baja', error: 'Error', pending: 'Pendiente' };
      table(t, 'Detalle', [['Qué', i => action[i.action] || i.action], ['Dominio', i => DOMAIN_LABELS[i.domain] || i.domain], ['Nombre', i => i.label || i.external_id || '—'],
        ['Detalle', i => i.reason || (i.fields ? Object.keys(i.fields).join(', ') : '')]], items, { filters: [['Qué', i => action[i.action] || i.action], ['Dominio', i => DOMAIN_LABELS[i.domain] || i.domain]] });
    } catch (e) { body.append(el('p', errorMessage(e), 'error-text')); }
    if (r.status === 'blocked' && manage) {
      const b = el('button', 'Aplicar igualmente (vuelve a leer la fuente)', 'danger'); b.type = 'button';
      b.addEventListener('click', async () => {
        if (!confirm('Se aplicarán los retiros sin frenos. ¿Seguro?')) return;
        try { const nr = await api('POST', `/sync-runs/${r.id}/approve`); dialog.close(); await reload(); showRun(nr); } catch (e) { fail(e); }
      });
      body.append(b);
    }
    dialog.showModal();
  };
  const editor = s => {
    const { dialog, body, ok, show } = dialogShell(s ? `Editar ${s.name}` : 'Conectar una fuente');
    body.append(el('p', 'Una fuente es otro sistema (por ejemplo AZC One) que entrega la nómina en el formato keeper.roster.v1. Arranca en simulación: no cambia nada hasta que la pases a real.'));
    const nm = labeled(body, 'Nombre', el('input')); nm.maxLength = 120; nm.value = s?.name || 'AZC One';
    const url = labeled(body, 'URL base (https)', el('input'), 'Ejemplo: https://one.azclegal.com/api/keeper/v1'); url.type = 'url'; url.maxLength = 2048; url.value = s?.base_url || '';
    const company = labeled(body, 'Empresa en la fuente (filtro)', el('input'), 'El identificador que la fuente usa para esta empresa, p. ej. grupo-azc.'); company.maxLength = 120; company.value = s?.company || '';
    const token = labeled(body, s?.has_token ? 'Token (déjalo vacío para conservar el actual)' : 'Token de acceso', el('input')); token.type = 'password'; token.autocomplete = 'off'; token.maxLength = 4096;
    const grid = el('div', undefined, 'form-grid'); body.append(grid);
    const every = labeled(grid, 'Revisar cambios cada (minutos)', el('input')); every.type = 'number'; every.min = 5; every.max = 1440; every.value = s?.interval_minutes ?? 10;
    const fullAt = labeled(grid, 'Revisión completa diaria a las', timeInput(s?.full_reconcile_local || '02:00'));
    let maxD, maxP, minL;
    if (s) {
      body.append(el('h3', 'Frenos de seguridad'));
      const g2 = el('div', undefined, 'form-grid'); body.append(g2);
      maxD = labeled(g2, 'Retiros máximos por vuelta', el('input')); maxD.type = 'number'; maxD.min = 0; maxD.value = s.max_deactivations;
      maxP = labeled(g2, '…o este % de las personas', el('input')); maxP.type = 'number'; maxP.min = 1; maxP.max = 100; maxP.value = s.max_deactivation_percent;
      minL = labeled(g2, 'Bloquear si la lista trae menos de este %', el('input')); minL.type = 'number'; minL.min = 0; minL.max = 100; minL.value = s.min_list_percent;
    }
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!url.value.startsWith('https://')) { show('La URL debe empezar por https://'); return; }
      ok.disabled = true;
      const payload = { name: nm.value.trim(), base_url: url.value.trim(), company: company.value.trim() || null, interval_minutes: Number(every.value), full_reconcile_local: fullAt.value || '02:00' };
      if (token.value) payload.token = token.value;
      if (s) Object.assign(payload, { max_deactivations: Number(maxD.value), max_deactivation_percent: Number(maxP.value), min_list_percent: Number(minL.value) });
      try {
        if (s) await api('PATCH', `/data-sources/${s.id}`, { body: payload, ifMatch: s.version }); else await api('POST', '/data-sources', { body: payload });
        dialog.close(); await reload();
      } catch (e) { show(e.problem?.detail === 'https_required' ? 'La URL debe ser https.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const setMode = async (s, mode) => {
    if (mode === 'live' && !confirm(`Desde ahora ${s.name} cambiará datos reales en Keeper (crear, actualizar y retirar personas según la fuente). ¿Continuar?`)) return;
    try { await api('PATCH', `/data-sources/${s.id}`, { body: { mode }, ifMatch: s.version }); await reload(); }
    catch (e) { fail(e.problem?.detail === 'simulation_required' ? new Error('Primero corre y revisa una simulación completa.') : e); }
  };
  const render = () => {
    $('page-content').replaceChildren();
    // Pendientes primero: es lo que requiere a alguien.
    if (pending?.data?.length) {
      const p = surface(`Pendientes de aprobación (${pending.data.length})`);
      p.append(el('p', manage ? 'La sincronización no adivina: estas decisiones las toma una persona.' : `La sincronización no adivina: estas decisiones las toma una persona. ${READ_ONLY_NOTE}`, 'help'));
      table(p, 'Pendientes', [['Qué pasa', i => (PENDING[i.reason] || [i.reason])[0]], ['Dominio', i => DOMAIN_LABELS[i.domain] || i.domain], ['Nombre', i => i.label || '—'],
        ...(!manage ? [] : [['Decisión', i => {
          const box = el('div', undefined, 'actions no-margin'); const [, yes, no] = PENDING[i.reason] || ['', 'Aprobar', 'Rechazar'];
          for (const [text, approve] of [[yes, true], [no, false]]) {
            const b = el('button', text, approve ? 'link-button' : 'link-button'); b.type = 'button';
            b.addEventListener('click', async () => {
              if (approve && i.reason === 'orphan' && !confirm(`¿Retirar a ${i.label}? Sus equipos dejan de reportar.`)) return;
              try { await api('POST', `/sync-items/${i.id}/resolve`, { body: { approve } }); await reload(); }
              catch (e) { fail(e.code === 'org_unit_in_use' ? new Error('Todavía tiene personas o áreas activas: muévelas primero.') : e); }
            });
            box.append(b);
          }
          return box;
        }]])], pending.data);
    }
    const s = surface('Fuentes conectadas');
    if (!manage) s.append(el('p', READ_ONLY_NOTE, 'notice'));
    if (!sources.data.length) empty(s, 'Sin fuentes', manage ? 'Keeper es el dueño de todos los datos de esta empresa (se cargan a mano o por CSV). Conecta una fuente como AZC One para que los mantenga al día.'
      : 'Keeper es el dueño de todos los datos de esta empresa (se cargan a mano o por CSV). Para conectar una fuente como AZC One, pídeselo a AZC Keeper.');
    else table(s, 'Fuentes', [['Fuente', x => { const d = el('div'); d.append(el('strong', x.name), el('small', `${x.base_url || ''}${x.company ? ' · ' + x.company : ''}`)); return d; }],
      ['Modo', x => badge(x.mode === 'live' ? 'Real' : 'Simulación', x.mode === 'live' ? 'good' : 'info')],
      ['Manda en', x => x.domains.map(d => DOMAIN_LABELS[d]).join(', ') || 'Nada aún'],
      ['Última vuelta exitosa', x => when(x.last_success_at)],
      ...(!manage ? [] : [['Acciones', x => {
        const box = el('div', undefined, 'actions no-margin');
        const add = (t, fn) => { const b = el('button', t, 'link-button'); b.type = 'button'; b.addEventListener('click', fn); box.append(b); };
        add('Simular ahora', () => runNow(x, true));
        if (x.mode === 'live') add('Sincronizar ahora', () => runNow(x, false));
        add(x.mode === 'live' ? 'Volver a simulación' : 'Pasar a real', () => setMode(x, x.mode === 'live' ? 'simulate' : 'live'));
        add('Editar', () => editor(x));
        return box;
      }]])], sources.data);
    const d = surface('¿Quién manda en cada dato?');
    d.append(el('p', 'Cada tipo de dato tiene un solo dueño. Lo que manda una fuente no se edita en Keeper; lo que manda Keeper se edita aquí o por CSV.', 'help'));
    if (!manage) {
      const dl = el('dl', undefined, 'facts');
      domains.data.forEach(row => { const box = el('div'); box.append(el('dt', DOMAIN_LABELS[row.domain] || row.domain), el('dd', row.source_id ? name(row.source_id) : 'Keeper (se edita aquí)')); dl.append(box); });
      d.append(dl);
    }
    const selects = !manage ? [] : domains.data.map(row => {
      const sel = el('select'); sel.append(new Option('Keeper (se edita aquí)', ''));
      sources.data.forEach(x => sel.append(new Option(x.name, x.id))); sel.value = row.source_id || '';
      labeled(d, DOMAIN_LABELS[row.domain] || row.domain, sel); return [row, sel];
    });
    if (manage) {
      const save = el('button', 'Guardar dueños', 'primary'); save.type = 'button'; d.append(save);
      save.addEventListener('click', async () => {
        const changed = selects.filter(([row, sel]) => (row.source_id || '') !== sel.value);
        if (!changed.length) return;
        const toKeeper = changed.filter(([, sel]) => !sel.value).map(([row]) => DOMAIN_LABELS[row.domain]);
        if (!confirm(toKeeper.length ? `${toKeeper.join(', ')} volverán a editarse en Keeper y la fuente dejará de actualizarlos. ¿Continuar?` : 'Esos datos pasarán a editarse solo en la fuente. ¿Continuar?')) return;
        try { await api('PUT', '/data-domains', { body: { domains: changed.map(([row, sel]) => ({ domain: row.domain, source_id: sel.value || null })) } }); await reload(); } catch (e) { fail(e); }
      });
    }
    const h = surface('Historial de sincronización');
    if (!runs.data.length) empty(h, 'Aún no hay vueltas', manage ? 'Corre una simulación para ver qué haría la fuente.' : 'Todavía no se ha sincronizado ninguna fuente.');
    else table(h, 'Vueltas', [['Cuándo', r => when(r.started_at)], ['Fuente', r => name(r.source_id)], ['Tipo', r => `${r.kind === 'full' ? 'Completa' : 'Cambios'}${r.trigger === 'manual' ? ' (manual)' : r.trigger === 'approval' ? ' (aprobada)' : ''}`],
      ['Resultado', r => { const [t, tone] = RUN_STATUS[r.status] || [r.status, '']; return badge(t, tone); }], ['Resumen', runCounts],
      ['Detalle', r => { const b = el('button', 'Ver', 'link-button'); b.type = 'button'; b.addEventListener('click', () => showRun(r)); return b; }]], runs.data);
  };
  const action = $('page-action'); action.textContent = 'Conectar fuente'; action.hidden = !manage; if (manage) action.addEventListener('click', () => editor(null));
  render();
}

// ---- Tablero del grupo (cuentas de grupo empresarial) -----------------------------------------------------------
async function groupPage() {
  const session = readSession();
  const companies = session?.group?.tenants || [];
  if (companies.length < 2) { empty($('page-content'), 'Sin grupo', 'Esta cuenta no es de un grupo empresarial.'); return; }
  $('tenant-name').textContent = session.group.name; $('tenant-name').nextElementSibling.textContent = 'Grupo empresarial';
  // Cada empresa se consulta con sus propios permisos: lo que la cuenta no puede ver ahi queda "sin acceso".
  const rows = await Promise.all(companies.map(async c => {
    const [users, devices, alerts] = await Promise.all([optional(allPages('/users', c.id)), optional(allPages(`/tenants/${c.id}/devices`, c.id)), optional(api('GET', '/reports/dual-job-alerts', { tenant: c.id }))]);
    return { ...c, people: users ? users.filter(u => u.status === 'active').length : null, devices: devices ? devices.filter(d => d.status === 'active').length : null,
      online: devices ? devices.filter(online).length : null, alerts: alerts?.data ? alerts.data.length : null };
  }));
  const sum = k => { const v = rows.map(r => r[k]).filter(x => x !== null); return v.length ? String(v.reduce((a, b) => a + b, 0)) : 'Sin acceso'; };
  kpis([['Empresas', String(rows.length)], ['Personas activas', sum('people')], ['Equipos', sum('devices'), `${sum('online')} en línea ahora`], ['Alertas de doble empleo', sum('alerts')]]);
  const s = surface('Por empresa');
  table(s, 'Empresas del grupo', [['Empresa', r => r.name], ['Personas', r => r.people ?? 'Sin acceso'], ['Equipos', r => r.devices ?? 'Sin acceso'], ['En línea', r => r.online ?? 'Sin acceso'],
    ['Alertas', r => r.alerts ?? 'Sin acceso'],
    ['', r => { const b = el('button', 'Abrir', 'link-button'); b.type = 'button'; b.addEventListener('click', () => { try { sessionStorage.setItem('keeper.presentation.tenant', r.id); } catch { /* sin almacenamiento */ } location.href = '/'; }); return b; }]], rows);
}

// ---- Puertas (entradas del sistema de acceso) -----------------------------------------------------------------------
async function doorsPage() {
  const [org] = await Promise.all([allPages('/organization')]);
  let doors = (await api('GET', '/doors')).data, quarantine = await optional(api('GET', '/doors/quarantine'));
  const sites = org.filter(o => o.kind === 'site' && o.active).sort((a, b) => a.name.localeCompare(b.name, 'es'));
  const n = names(org);
  const reload = async () => { doors = (await api('GET', '/doors')).data; quarantine = await optional(api('GET', '/doors/quarantine')); render(); done(); };
  const editor = (d, presetRef) => {
    const { dialog, body, ok, show } = dialogShell(d ? `Editar ${d.name}` : 'Registrar puerta');
    body.append(el('p', 'La referencia es el identificador de la puerta en el sistema de acceso (en uhppoted, su OID, por ejemplo 0.3.1).'));
    const nm = labeled(body, 'Nombre', el('input')); nm.maxLength = 120; nm.value = d?.name || '';
    const ref = labeled(body, 'Referencia en el sistema de acceso', el('input')); ref.maxLength = 160; ref.value = d?.source_ref || presetRef || ''; ref.disabled = !!d;
    const site = el('select'); sites.forEach(s => site.append(new Option(s.name, s.id))); site.value = d?.site_id || site.value; labeled(body, 'Sede', site);
    const counts = el('input'); counts.type = 'checkbox'; counts.checked = d ? d.counts_for_presence : true;
    const cl = el('label', undefined, 'check'); cl.append(counts, ' Es una entrada al edificio (cuenta para la presencia)'); body.append(cl);
    body.append(el('p', 'Desmárcalo para puertas internas (salas, archivo): su paso no marca llegada ni salida.', 'help'));
    let active = null;
    if (d) { active = el('input'); active.type = 'checkbox'; active.checked = d.active; const al = el('label', undefined, 'check'); al.append(active, ' Activa'); body.append(al); }
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!nm.value.trim() || !ref.value.trim() || !site.value) { show('Escribe nombre, referencia y sede.'); return; }
      ok.disabled = true;
      try {
        if (d) await api('PATCH', `/doors/${d.id}`, { body: { name: nm.value.trim(), site_id: site.value, counts_for_presence: counts.checked, active: active.checked } });
        else await api('POST', '/doors', { body: { name: nm.value.trim(), source_ref: ref.value.trim(), site_id: site.value, counts_for_presence: counts.checked } });
        dialog.close(); await reload();
      } catch (e) { show(e.problem?.detail === 'door_exists' ? 'Esa referencia ya está registrada.' : e.problem?.detail === 'door_has_history' ? 'La puerta ya tiene entradas: no se puede cambiar de sede (crea otra).' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const render = () => {
    $('page-content').replaceChildren();
    if (quarantine && (quarantine.unknown_doors.length || quarantine.unknown_people.length)) {
      const q = surface('Entradas esperando');
      q.append(el('p', 'Llegaron del sistema de acceso pero aún no se pueden asignar. Se guardan 30 días y se asignan solas cuando registras la puerta o cuando la persona existe en Keeper.', 'help'));
      if (quarantine.unknown_doors.length) table(q, 'Puertas sin registrar', [['Referencia', x => x.door_ref], ['Entradas', x => String(x.events)], ['Última', x => when(x.last_at)],
        ['', x => { const b = el('button', 'Registrar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => editor(null, x.door_ref)); return b; }]], quarantine.unknown_doors);
      if (quarantine.unknown_people.length) table(q, 'Cédulas que no están en Keeper', [['Cédula', x => x.document_hint], ['Entradas', x => String(x.events)], ['Última', x => when(x.last_at)]], quarantine.unknown_people);
    }
    const s = surface('Puertas registradas');
    s.append(el('p', 'El sistema de acceso envía cada paso de tarjeta con la cédula de la persona. Solo las entradas al edificio cuentan para la presencia.', 'help'));
    if (!doors.length) { empty(s, 'Aún no hay puertas', 'Registra las puertas de acceso, o espera a que lleguen entradas y regístralas desde "Entradas esperando".'); return; }
    table(s, 'Puertas', [['Puerta', d => d.name], ['Referencia', d => d.source_ref], ['Sede', d => n.get(d.site_id) || '—'],
      ['Presencia', d => badge(d.counts_for_presence ? 'Entrada al edificio' : 'Interna', d.counts_for_presence ? 'good' : '')],
      ['Estado', d => badge(d.active ? 'Activa' : 'Inactiva', d.active ? 'good' : '')],
      ['', d => { const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => editor(d)); return b; }]], doors);
  };
  const action = $('page-action'); action.textContent = 'Registrar puerta'; action.hidden = false; action.addEventListener('click', () => editor(null));
  render();
}

// ---- Ausencias de RR. HH. -------------------------------------------------------------------------------------------
const ABSENCE_KINDS = { vacaciones: 'Vacaciones', licencia_remunerada: 'Licencia remunerada', licencia_no_remunerada: 'Licencia no remunerada', incapacidad: 'Incapacidad', permiso: 'Permiso', calamidad: 'Calamidad', otro: 'Otro' };
async function absencesPage() {
  const day = (offset) => new Date(Date.now() + offset * 864e5).toISOString().slice(0, 10);
  const [users, domains, sources] = await Promise.all([allPages('/users'), optional(api('GET', '/data-domains')), optional(api('GET', '/data-sources'))]);
  const ownerId = domains?.data?.find(d => d.domain === 'ausencias')?.source_id || null;
  const owner = ownerId ? (sources?.data?.find(x => x.id === ownerId)?.name || 'una fuente externa') : null;
  const who = new Map(users.map(u => [u.id, u.display_name]));
  let list = (await api('GET', `/absences?from=${day(-60)}&to=${day(180)}`)).data;
  const fmt = v => new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium' }).format(new Date(v));
  const range = a => a.all_day ? `${fmt(a.starts_at)} → ${fmt(new Date(Date.parse(a.ends_at) - 1))}` : `${when(a.starts_at)} → ${when(a.ends_at)}`;
  const render = () => {
    $('page-content').replaceChildren();
    const s = surface('Ausencias (últimos 60 días y próximos 6 meses)');
    s.append(el('p', owner ? `Las ausencias llegan de ${owner}: se registran allá.` : 'Un día cubierto por una ausencia no cuenta como ausencia ni tardanza, y no baja la productividad promedio.', owner ? 'notice' : 'help'));
    if (!list.length) { empty(s, 'Sin ausencias', owner ? 'Aún no han llegado ausencias.' : 'Registra vacaciones, incapacidades o permisos con "Registrar ausencia".'); return; }
    table(s, 'Ausencias', [['Persona', a => who.get(a.user_id) || '—'], ['Tipo', a => ABSENCE_KINDS[a.kind] || a.kind], ['Fechas', range],
      ['Estado', a => badge(a.status === 'approved' ? 'Aprobada' : 'Cancelada', a.status === 'approved' ? 'good' : '')],
      ['', a => {
        if (owner || a.status !== 'approved') return '';
        const b = el('button', 'Cancelar', 'link-button'); b.type = 'button';
        b.addEventListener('click', async () => {
          if (!confirm('¿Cancelar esta ausencia? Esos días vuelven a contar como laborales.')) return;
          try { const r = await api('PATCH', `/absences/${a.id}`, { body: { status: 'cancelled' }, ifMatch: a.version }); list = list.map(x => x.id === a.id ? r : x); render(); done(); } catch (e) { fail(e); }
        });
        return b;
      }]], list, { filters: [['Tipo', a => ABSENCE_KINDS[a.kind] || a.kind], ['Estado', a => a.status === 'approved' ? 'Aprobada' : 'Cancelada']] });
  };
  const editor = () => {
    const { dialog, body, ok, show } = dialogShell('Registrar ausencia');
    const person = el('select'); person.append(new Option('Elige la persona…', ''));
    users.filter(u => u.status === 'active').sort((a, b) => a.display_name.localeCompare(b.display_name, 'es')).forEach(u => person.append(new Option(u.display_name, u.id)));
    labeled(body, 'Persona', person);
    const kind = el('select'); Object.entries(ABSENCE_KINDS).forEach(([v, t]) => kind.append(new Option(t, v))); labeled(body, 'Tipo', kind);
    const grid = el('div', undefined, 'form-grid'); body.append(grid);
    const from = labeled(grid, 'Desde (incluido)', el('input')); from.type = 'date'; from.value = day(0);
    const to = labeled(grid, 'Hasta (incluido)', el('input')); to.type = 'date'; to.value = day(0);
    const note = labeled(body, 'Nota (opcional)', el('input')); note.maxLength = 200;
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!person.value || !from.value || !to.value || to.value < from.value) { show('Elige la persona y unas fechas válidas.'); return; }
      ok.disabled = true;
      // Dias completos en la zona horaria del navegador: del inicio de "desde" al inicio del dia siguiente a "hasta".
      const startsAt = new Date(`${from.value}T00:00:00`).toISOString();
      const end = new Date(`${to.value}T00:00:00`); end.setDate(end.getDate() + 1);
      try {
        const r = await api('POST', '/absences', { body: { user_id: person.value, kind: kind.value, starts_at: startsAt, ends_at: end.toISOString(), all_day: true, note: note.value.trim() || null } });
        list = [r, ...list]; dialog.close(); render(); done();
      } catch (e) { show(e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const action = $('page-action'); action.textContent = 'Registrar ausencia'; action.hidden = !!owner; action.addEventListener('click', editor);
  render();
}

// ---- Clasificacion de aplicaciones y sitios ------------------------------------------------------------------------
const CATEGORY = { productive: ['Productiva', 'good'], neutral: ['Neutra', ''], unproductive: ['Poco productiva', 'bad'], unclassified: ['Sin clasificar', 'warn'] };
async function classificationPage() {
  const org = (await optional(allPages('/organization'))) || [];
  const n = names(org);
  let data = await api('GET', '/app-classification');
  const appName = a => a.label || (a.key.startsWith('site:') ? `${a.key.slice(5)} (sitio web)` : a.key);
  const effective = a => a.company_category || a.base_category || 'unclassified';
  const render = () => {
    $('page-content').replaceChildren();
    const apps = data.apps;
    const used = apps.filter(a => a.active_seconds_30d > 0);
    const totalUse = used.reduce((s, a) => s + a.active_seconds_30d, 0);
    const unclassUse = used.filter(a => effective(a) === 'unclassified').reduce((s, a) => s + a.active_seconds_30d, 0);
    kpis([['Apps y sitios usados (30 días)', String(used.length)], ['Sin clasificar', String(used.filter(a => effective(a) === 'unclassified').length), 'Lo que no está clasificado no cuenta como productivo ni como distracción'],
      ['Tiempo sin clasificar', totalUse ? pct(100 * unclassUse / totalUse) : 'Sin uso', 'Más de 20 % = indicadores poco confiables'], ['Excepciones por área o cargo', String(apps.reduce((s, a) => s + a.overrides.length, 0))]]);
    const s = surface('Clasificación');
    s.append(el('p', 'Productiva suma a productividad y focus; poco productiva suma a distracción; neutra (explorador, configuración) no suma a ninguna. La base la pone Keeper; la empresa la cambia aquí, y un área o cargo puede tener su excepción (por ejemplo, Facebook productivo para Mercadeo). Los cambios recalculan los últimos 30 días.', 'help'));
    table(s, 'Aplicaciones y sitios', [
      ['Aplicación o sitio', a => appName(a)],
      ['Uso (30 días)', a => a.active_seconds_30d ? `${(a.active_seconds_30d / 3600).toFixed(1)} h · ${a.people_30d} personas` : '—'],
      ['Base de Keeper', a => a.base_category ? CATEGORY[a.base_category][0] : '—'],
      ['En la empresa', a => {
        const sel = el('select'); [['', `Usar la base${a.base_category ? ` (${CATEGORY[a.base_category][0]})` : ''}`], ['productive', 'Productiva'], ['neutral', 'Neutra'], ['unproductive', 'Poco productiva']].forEach(([v, t]) => sel.append(new Option(t, v)));
        sel.value = a.company_category && a.company_category !== 'unclassified' ? a.company_category : '';
        sel.addEventListener('change', async () => { sel.disabled = true; try { data = await api('PUT', '/app-classification', { body: { key: a.key, category: sel.value || 'unclassified' } }); render(); done(); } catch (e) { fail(e); sel.disabled = false; } });
        return sel;
      }],
      ['Resultado', a => { const [t, tone] = CATEGORY[effective(a)]; return badge(t, tone); }],
      ['Excepciones', a => {
        const box = el('div');
        a.overrides.forEach(o => {
          const line = el('div', `${o.area_id ? `Área ${n.get(o.area_id) || '—'}` : `Cargo ${n.get(o.position_id) || '—'}`}: ${CATEGORY[o.category][0]} `);
          const rm = el('button', 'quitar', 'link-button'); rm.type = 'button';
          rm.addEventListener('click', async () => { try { data = await api('DELETE', `/app-classification/overrides/${o.id}`); render(); done(); } catch (e) { fail(e); } });
          line.append(rm); box.append(line);
        });
        const add = el('button', 'Agregar excepción', 'link-button'); add.type = 'button'; add.addEventListener('click', () => exception(a)); box.append(add);
        return box;
      }]], apps, { filters: [['Resultado', a => CATEGORY[effective(a)][0]], ['Tipo', a => a.key.startsWith('site:') ? 'Sitio web' : 'Aplicación']] });
    const w = surface('Sitios reconocidos en el navegador');
    w.append(el('p', 'Keeper reconoce el sitio por el título de la ventana del navegador y guarda solo el nombre del sitio, nunca el título. Funciona desde que se agrega; el historial anterior queda como "navegador".', 'help'));
    table(w, 'Sitios', [['Sitio', x => x.name], ['Se reconoce si el título contiene', x => x.pattern], ['Origen', x => x.origin === 'base' ? 'Base de Keeper' : 'Empresa']], data.sites);
    const addSite = el('button', 'Agregar sitio'); addSite.type = 'button'; addSite.addEventListener('click', siteEditor); w.append(addSite);
  };
  const exception = a => {
    const { dialog, body, ok, show } = dialogShell(`Excepción para ${appName(a)}`);
    const kind = el('select'); kind.append(new Option('Para un área', 'area'), new Option('Para un cargo', 'position')); labeled(body, '¿Para quién?', kind);
    const unit = el('select'); const fill = () => { unit.replaceChildren(); org.filter(o => o.kind === kind.value && o.active).sort((x, y) => x.name.localeCompare(y.name, 'es')).forEach(o => unit.append(new Option(o.name, o.id))); };
    fill(); kind.addEventListener('change', fill); labeled(body, 'Área o cargo', unit);
    const cat = el('select'); [['productive', 'Productiva'], ['neutral', 'Neutra'], ['unproductive', 'Poco productiva']].forEach(([v, t]) => cat.append(new Option(t, v))); labeled(body, 'Para ellos es', cat);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (!unit.value) { show('Elige el área o cargo.'); return; }
      ok.disabled = true;
      try { data = await api('POST', '/app-classification/overrides', { body: { key: a.key, category: cat.value, area_id: kind.value === 'area' ? unit.value : null, position_id: kind.value === 'position' ? unit.value : null } }); dialog.close(); render(); done(); }
      catch (e) { show(e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const siteEditor = () => {
    const { dialog, body, ok, show } = dialogShell('Agregar sitio');
    const nm = labeled(body, 'Nombre del sitio', el('input'), 'Ej.: Sistema de casos'); nm.maxLength = 80;
    const pat = labeled(body, 'El título de la ventana contiene', el('input'), 'Ej.: Casos - AZC. Sin distinguir mayúsculas.'); pat.maxLength = 120;
    const cat = el('select'); [['productive', 'Productiva'], ['neutral', 'Neutra'], ['unproductive', 'Poco productiva']].forEach(([v, t]) => cat.append(new Option(t, v))); labeled(body, 'Clasificación', cat);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (nm.value.trim().length < 1 || pat.value.trim().length < 2) { show('Escribe el nombre y el texto a reconocer.'); return; }
      ok.disabled = true;
      try { data = await api('POST', '/app-sites', { body: { name: nm.value.trim(), pattern: pat.value.trim(), category: cat.value } }); dialog.close(); render(); done(); }
      catch (e) { show(e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  render();
}

// ---- Reglas (politicas) ---------------------------------------------------------------------------------------------
// Solo se ofrece lo que el agente sabe aplicar: bloquear sitios web (con excepciones), descargas e instalaciones
// (bloquear todo o permitir como excepcion) y USB (bloquear, solo lectura o permitir). Gana lo mas especifico:
// equipo > persona > area > sede > empresa.
const SCOPE_LABEL = { tenant: 'Toda la empresa', site: 'Sede', area: 'Área', user: 'Persona', device: 'Equipo' };
async function policiesPage() {
  const [org, users, devices] = await Promise.all([optional(allPages('/organization')), optional(allPages('/users')), optional(allPages(`/tenants/${tenant}/devices`))]);
  let list = await allPages('/policies');
  const n = names(org || []); const who = new Map((users || []).map(u => [u.id, u.display_name])); const dev = new Map((devices || []).map(d => [d.id, d.hostname]));
  const targetName = p => p.target_type === 'tenant' ? 'Toda la empresa' : p.target_type === 'user' ? `Persona: ${who.get(p.target_id) || '—'}` : p.target_type === 'device' ? `Equipo: ${dev.get(p.target_id) || '—'}` : `${SCOPE_LABEL[p.target_type]}: ${n.get(p.target_id) || '—'}`;
  const describe = r => {
    if (r.kind === 'web') return `${r.effect === 'deny' ? 'Bloquea' : 'Permite (excepción)'} ${r.targets.join(', ')}`;
    if (r.kind === 'download') return r.effect === 'deny' ? 'Bloquea las descargas' : 'Permite las descargas (excepción)';
    if (r.kind === 'installation') return r.effect === 'deny' ? 'Bloquea instalar programas' : 'Permite instalar programas (excepción)';
    if (r.kind === 'usb') return r.effect === 'allow' ? 'Permite USB (excepción)' : r.targets[0] === 'write' ? 'USB solo lectura' : 'Bloquea USB';
    return `${r.kind} ${r.effect} ${r.targets.join(', ')}`;
  };
  const render = () => {
    $('page-content').replaceChildren();
    const s = surface('Reglas de los equipos');
    s.append(el('p', 'Una regla dice qué se bloquea o se permite y a quién aplica. Si dos reglas chocan, gana la más específica: equipo, luego persona, área, sede y por último la empresa. Así se hacen excepciones: la empresa bloquea Facebook y una persona de Mercadeo lo tiene permitido.', 'help'));
    if (!list.length) { empty(s, 'Aún no hay reglas', 'Crea la primera con "Crear regla".'); return; }
    table(s, 'Reglas', [['Nombre', p => p.name], ['Aplica a', targetName], ['Qué hace', p => { const ul = el('ul'); p.rules.forEach(r => ul.append(el('li', describe(r)))); return ul; }],
      ['Estado', p => badge(p.enabled ? 'Activa' : 'Inactiva', p.enabled ? 'good' : '')],
      ['', p => { const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => editor(p)); return b; }]], list,
      { filters: [['Aplica a', p => SCOPE_LABEL[p.target_type]], ['Estado', p => p.enabled ? 'Activa' : 'Inactiva']] });
  };
  const domains = text => [...new Set(text.split(/[\s,;]+/).map(x => x.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '')).filter(Boolean))];
  const editor = p => {
    const { dialog, body, ok, show } = dialogShell(p ? `Editar ${p.name}` : 'Crear regla');
    dialog.classList.add('wide-dialog');
    const name = labeled(body, 'Nombre', el('input'), 'Ej.: Bloqueos generales, Excepción Mercadeo'); name.maxLength = 120; name.value = p?.name || '';
    const scope = el('select'); Object.entries(SCOPE_LABEL).forEach(([v, t]) => scope.append(new Option(t, v))); scope.value = p?.target_type || 'tenant'; labeled(body, '¿A quién aplica?', scope);
    const target = el('select'); const targetLabel = labeled(body, 'Cuál', target);
    const fill = () => {
      target.replaceChildren(); targetLabel.hidden = scope.value === 'tenant';
      const items = scope.value === 'site' || scope.value === 'area' ? (org || []).filter(o => o.kind === scope.value && o.active).map(o => [o.id, o.name])
        : scope.value === 'user' ? (users || []).filter(u => u.status === 'active').map(u => [u.id, u.display_name])
        : scope.value === 'device' ? (devices || []).filter(d => d.status === 'active').map(d => [d.id, `${d.hostname} (${who.get(d.user_id) || 'sin persona'})`]) : [];
      items.sort((a, b) => a[1].localeCompare(b[1], 'es')).forEach(([v, t]) => target.append(new Option(t, v)));
      if (p && p.target_type === scope.value) target.value = p.target_id;
    };
    fill(); scope.addEventListener('change', fill);
    const rule = kind => p?.rules.find(r => r.kind === kind);
    const rules = p?.rules || [];
    body.append(el('h3', 'Sitios web'));
    const block = labeled(body, 'Bloquear estos sitios (uno por línea)', el('textarea'), 'Ej.: facebook.com, youtube.com. Bloquea también sus subdominios.');
    block.value = rules.filter(r => r.kind === 'web' && r.effect === 'deny').flatMap(r => r.targets).join('\n');
    const allow = labeled(body, 'Permitir estos sitios (excepción a un bloqueo más general)', el('textarea'), 'Úsalo en reglas de persona, área o sede: quita el sitio del bloqueo de la empresa solo para ellos.');
    allow.value = rules.filter(r => r.kind === 'web' && r.effect === 'allow').flatMap(r => r.targets).join('\n');
    body.append(el('h3', 'Equipo'));
    const choice = (label, options, current) => { const sel = el('select'); options.forEach(([v, t]) => sel.append(new Option(t, v))); sel.value = current; labeled(body, label, sel); return sel; };
    const effectOf = kind => rule(kind) ? rule(kind).effect : '';
    const download = choice('Descargas desde el navegador', [['', 'Sin regla aquí (se usa la general)'], ['deny', 'Bloquear'], ['allow', 'Permitir (excepción)']], effectOf('download'));
    const install = choice('Instalar programas', [['', 'Sin regla aquí (se usa la general)'], ['deny', 'Bloquear'], ['allow', 'Permitir (excepción)']], effectOf('installation'));
    const usbRule = rule('usb');
    const usb = choice('Memorias USB', [['', 'Sin regla aquí (se usa la general)'], ['deny', 'Bloquear'], ['write', 'Solo lectura (no copiar a la USB)'], ['allow', 'Permitir (excepción)']], usbRule ? (usbRule.effect === 'allow' ? 'allow' : usbRule.targets[0] === 'write' ? 'write' : 'deny') : '');
    const enabled = el('input'); enabled.type = 'checkbox'; enabled.checked = p ? p.enabled : true;
    const el2 = el('label', undefined, 'check'); el2.append(enabled, ' Regla activa'); body.append(el2);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!name.value.trim()) { show('Escribe el nombre.'); return; }
      if (scope.value !== 'tenant' && !target.value) { show('Elige a quién aplica.'); return; }
      const out = []; const id = () => crypto.randomUUID();
      const blocked = domains(block.value), allowed = domains(allow.value);
      const bad = [...blocked, ...allowed].find(d => !/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/.test(d));
      if (bad) { show(`"${bad}" no parece un sitio (escribe solo el dominio, p. ej. facebook.com).`); return; }
      if (blocked.some(d => allowed.includes(d))) { show('Un mismo sitio no puede estar bloqueado y permitido en la misma regla.'); return; }
      if (blocked.length) out.push({ id: id(), kind: 'web', effect: 'deny', targets: blocked, schedule_id: null, priority: 0 });
      if (allowed.length) out.push({ id: id(), kind: 'web', effect: 'allow', targets: allowed, schedule_id: null, priority: 0 });
      if (download.value) out.push({ id: id(), kind: 'download', effect: download.value, targets: ['*'], schedule_id: null, priority: 0 });
      if (install.value) out.push({ id: id(), kind: 'installation', effect: install.value, targets: ['*'], schedule_id: null, priority: 0 });
      if (usb.value) out.push({ id: id(), kind: 'usb', effect: usb.value === 'allow' ? 'allow' : 'deny', targets: [usb.value === 'write' ? 'write' : '*'], schedule_id: null, priority: 0 });
      if (!out.length) { show('La regla no hace nada: agrega al menos un bloqueo o permiso.'); return; }
      ok.disabled = true;
      const payload = { name: name.value.trim(), target_type: scope.value, target_id: scope.value === 'tenant' ? tenant : target.value, enabled: enabled.checked, rules: out };
      try {
        const saved = p ? await api('PATCH', `/policies/${p.id}`, { body: payload, ifMatch: p.version }) : await api('POST', '/policies', { body: payload });
        list = p ? list.map(x => x.id === p.id ? saved : x) : [...list, saved]; dialog.close(); render(); done();
      } catch (e) {
        const detail = String(e.problem?.detail || ''); const kindName = { web: 'sitios web', download: 'descargas', installation: 'instalaciones', usb: 'USB' };
        show(e.code === 'invalid_transition' || e.status === 409 ? 'Choca con otra regla del mismo nivel (una bloquea y otra permite lo mismo). Ponlas en niveles distintos o únelas.'
          : detail.startsWith('invalid_target:') ? `El valor "${detail.split(':').slice(2).join(':')}" no es válido en ${kindName[detail.split(':')[1]] || detail.split(':')[1]}.`
          : detail.startsWith('blocks_panel:') ? `No puedes bloquear ${detail.slice(13)}: dejaría a los equipos sin acceso al panel de Keeper.` : e);
        ok.disabled = false;
      }
    });
    dialog.showModal();
  };
  const action = $('page-action'); action.textContent = 'Crear regla'; action.hidden = false; action.addEventListener('click', () => editor(null));
  render();
}

// ---- Planes y paquetes (solo plataforma) ----------------------------------------------------------------------------
// Catálogo de funciones, paquetes (plantillas para vender) y planes por persona (docs/architecture/v4-clientes-funciones-accesos.md
// §2-3). Las funciones de cada cliente y su plan por defecto se eligen en Empresas → Funciones.
async function plansPage() {
  const [catalogPage, packagePage, planPage] = await Promise.all([api('GET', '/features', { tenant: PLATFORM_TENANT }),
    api('GET', '/feature-packages', { tenant: PLATFORM_TENANT }), api('GET', '/person-plans', { tenant: PLATFORM_TENANT })]);
  const catalog = catalogPage.data;
  let packages = packagePage.data, personPlans = planPage.data;
  const featureName = code => catalog.find(f => f.code === code)?.name || code;
  const clients = companies.filter(c => c.id !== PLATFORM_TENANT && !c.archived);
  // Uso por cliente: personas de cada empresa en cada plan (plan efectivo: propio activo o el por defecto del cliente).
  const usage = new Map();
  const loadUsage = async () => {
    const results = await Promise.all(clients.map(c => api('GET', `/tenants/${c.id}/plan-usage`, { tenant: c.id }).then(u => [c, u], () => [c, null])));
    usage.clear();
    for (const [c, u] of results) {
      for (const row of u?.data || []) {
        if (!row.plan_id || !row.people) continue;
        if (!usage.has(row.plan_id)) usage.set(row.plan_id, []);
        usage.get(row.plan_id).push(`${c.branding?.display_name || c.name}: ${row.people}`);
      }
    }
  };
  await loadUsage();
  const refresh = async () => { await loadUsage(); render(); done(); };
  const render = () => {
    $('page-content').replaceChildren();
    renderHow(); renderCatalog(); renderPackages(); renderPersonPlans();
  };
  const renderHow = () => {
    const s = surface('Cómo funciona');
    const chain = el('div', undefined, 'portal-path');
    ['Funciones (catálogo)', 'Paquete', 'Funciones del cliente', 'Plan de cada persona', 'Lo que recibe la persona'].forEach((t, i) => { if (i) chain.append('→'); chain.append(el('span', t)); });
    s.append(chain);
    const ul = el('ul');
    ['Funciones: todo lo que Keeper sabe hacer. Unas son por persona (se aplican a cada persona) y otras del cliente (valen para toda la empresa).',
      'Paquete: una plantilla para vender. Al dar de alta o editar un cliente marca sus funciones de una vez; cambiar un paquete no cambia a los clientes que ya lo usan.',
      'Funciones del cliente: lo que compró (paquete + ajustes de la venta). Se eligen en Empresas → Funciones.',
      'Plan de cada persona: qué funciones de persona recibe. Cada cliente tiene un plan por defecto; una persona puede tener uno propio (en su ficha de Personas).',
      'Lo que recibe la persona = funciones del cliente ∩ su plan. Si el cliente no compró una función, ningún plan la da.']
      .forEach(t => ul.append(el('li', t, 'help')));
    s.append(ul);
  };
  const renderCatalog = () => {
    const s = surface('Catálogo de funciones');
    s.append(el('p', `Las ${catalog.length} funciones de Keeper. Las de persona son las que se eligen en los planes; las del cliente solo dependen de lo que compró.`, 'help'));
    table(s, 'Funciones', [
      ['Función', f => { const d = el('div'); d.append(el('strong', f.name)); if (f.pending) d.append(' ', badge(PENDING_LABEL, 'warn'), el('small', 'Se puede contratar, pero todavía no se aplica en los equipos ni en el panel.')); return d; }],
      ['Nivel', f => badge(f.level === 'person' ? 'Persona' : 'Cliente', f.level === 'person' ? 'info' : '')],
      ['Grupo', f => f.group], ['Qué hace', f => f.description || '—']], catalog,
    { filters: [['Nivel', f => f.level === 'person' ? 'Persona' : 'Cliente'], ['Grupo', f => f.group], ['Estado', f => f.pending ? PENDING_LABEL : 'Disponible']] });
  };
  const editButton = fn => { const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', fn); return b; };
  const isPending = code => !!catalog.find(f => f.code === code)?.pending;
  const featureList = codes => {
    const d = el('div'); d.append(el('strong', String(codes.length))); if (codes.length) d.append(el('small', codes.map(c => isPending(c) ? `${featureName(c)} (pendiente de implementación)` : featureName(c)).join(', ')));
    return d;
  };
  const renderPackages = () => {
    const s = surface('Paquetes de funciones');
    s.append(el('p', 'Atajos para vender: un paquete marca funciones de una vez. Cambiar un paquete no cambia a los clientes que ya lo usan.', 'help'));
    const add = el('button', 'Crear paquete'); add.type = 'button'; add.addEventListener('click', () => packageEditor(null)); s.append(add);
    if (!packages.length) { s.append(el('p', 'Aún no hay paquetes.', 'help')); return; }
    table(s, 'Paquetes', [['Paquete', p => p.name], ['Funciones', p => featureList(p.features)], ['Clientes', p => String(p.tenants)], ['', p => editButton(() => packageEditor(p))]], packages);
  };
  const packageEditor = p => {
    const { dialog, body, ok, show } = dialogShell(p ? `Editar ${p.name}` : 'Crear paquete');
    dialog.classList.add('wide-dialog');
    const name = labeled(body, 'Nombre', el('input')); name.maxLength = 80; name.value = p?.name || '';
    const boxes = featureChecks(catalog, body, p?.features || []);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (!name.value.trim()) { show('Escribe el nombre.'); return; }
      ok.disabled = true;
      const payload = { name: name.value.trim(), features: boxes.filter(b => b.checked).map(b => b.value) };
      try {
        if (p) await api('PATCH', `/feature-packages/${p.id}`, { tenant: PLATFORM_TENANT, body: payload, ifMatch: p.version }); else await api('POST', '/feature-packages', { tenant: PLATFORM_TENANT, body: payload });
        packages = (await api('GET', '/feature-packages', { tenant: PLATFORM_TENANT })).data; dialog.close(); render(); done();
      } catch (e) { show(e.problem?.detail === 'duplicate_name' ? 'Ya hay un paquete con ese nombre.' : e.status === 412 ? 'Alguien más cambió el paquete: recarga la página.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const renderPersonPlans = () => {
    const s = surface('Planes por persona');
    s.append(el('p', 'Qué funciones de persona recibe cada persona. Cada cliente elige un plan por defecto y las personas pueden tener uno propio. No hay cupos: el conteo de personas por plan sirve para facturar.', 'help'));
    const add = el('button', 'Crear plan'); add.type = 'button'; add.addEventListener('click', () => personPlanEditor(null)); s.append(add);
    if (!personPlans.length) { s.append(el('p', 'Aún no hay planes por persona.', 'help')); return; }
    table(s, 'Planes por persona', [['Plan', x => { const d = el('div'); d.append(el('strong', x.name)); if (!x.active) d.append(' ', badge('Inactivo', '')); if (x.badge_label) d.append(el('small', x.badge_label)); return d; }],
      ['Funciones', x => featureList(x.features)], ['Personas', x => String(x.people)],
      ['Por cliente', x => (usage.get(x.id) || []).join(' · ') || '—'], ['', x => editButton(() => personPlanEditor(x))]], personPlans);
  };
  const personPlanEditor = p => {
    const { dialog, body, ok, show } = dialogShell(p ? `Editar ${p.name}` : 'Crear plan por persona');
    dialog.classList.add('wide-dialog');
    const name = labeled(body, 'Nombre', el('input')); name.maxLength = 80; name.value = p?.name || '';
    const badgeLabel = labeled(body, 'Etiqueta', el('input'), 'El texto corto que se muestra junto a la persona.'); badgeLabel.maxLength = 40; badgeLabel.value = p?.badge_label || '';
    body.append(el('p', 'Marca las funciones de persona que da este plan. Cada persona recibe solo las que además compró su cliente.', 'help'));
    const boxes = featureChecks(catalog, body, p?.features || [], { personOnly: true });
    const [activeBox] = checks(body, [['active', 'Plan activo']], !p || p.active ? ['active'] : []);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (!name.value.trim()) { show('Escribe el nombre.'); return; }
      const nextFeatures = boxes.filter(b => b.checked).map(b => b.value);
      if (p && p.people > 0 && ((p.active && !activeBox.checked) || p.features.some(f => !nextFeatures.includes(f))) && !confirm(`Este plan lo usan ${p.people} personas. Al guardar, sus equipos dejarán de aplicar lo que quitaste (en la próxima compilación). ¿Continuar?`)) { return; }
      ok.disabled = true;
      const payload = { name: name.value.trim(), badge_label: badgeLabel.value.trim(), features: nextFeatures, active: activeBox.checked };
      try {
        if (p) await api('PATCH', `/person-plans/${p.id}`, { tenant: PLATFORM_TENANT, body: payload, ifMatch: p.version }); else await api('POST', '/person-plans', { tenant: PLATFORM_TENANT, body: payload });
        personPlans = (await api('GET', '/person-plans', { tenant: PLATFORM_TENANT })).data; dialog.close(); await refresh();
      } catch (e) { show(e.problem?.detail === 'duplicate_name' ? 'Ya hay un plan con ese nombre.' : e.status === 412 ? 'Alguien más cambió el plan: recarga la página.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  render();
}

// ---- Auditoria ------------------------------------------------------------------------------------------------------
// Todas las acciones auditables (operaciones del contrato y eventos automaticos), en palabras. auditAction() tiene
// un respaldo que traduce verbo + objeto, para que una accion nueva nunca salga en ingles tecnico.
const AUDIT_ACTIONS = {
  'admin.login': 'Inició sesión en el panel', 'admin.logout': 'Cerró sesión', 'admin.reauthenticated': 'Confirmó su contraseña', 'admin.password_changed': 'Cambió su contraseña',
  adminLogin: 'Inició sesión en el panel', adminLogout: 'Cerró sesión', adminReauth: 'Confirmó su contraseña', changeAdminPassword: 'Cambió su contraseña',
  'device.enrolled': 'Equipo registrado', 'device.created': 'Equipo creado', 'device.login': 'El equipo se conectó', 'device.session_renewed': 'El equipo renovó su sesión',
  'device.status_changed': 'Cambió el estado del equipo', 'device.key_recovered': 'Se recuperó la llave de un equipo',
  'security.reported': 'El equipo reportó su seguridad', 'policy.compiled': 'Se recalculó la política de un equipo', 'policy.changed': 'Cambió la política de un equipo',
  'policy.conflict': 'Conflicto entre reglas', 'command.result': 'El equipo respondió un comando', 'command.completed': 'El equipo terminó un comando',
  'hardening.settings.set': 'Cambió la seguridad de cuentas de Windows', 'hardening.command.harden': 'Endureció un equipo', 'hardening.command.unharden': 'Revirtió el endurecimiento',
  'hardening.password.reveal': 'Vio la contraseña de administrador local',
  'migration.authorization_issued': 'Autorizó una migración desde K3', 'migration.escrow_persisted': 'Guardó la llave de migración', 'migration.escrow_recovered': 'Recuperó la llave de migración',
  'migration.escrow_verified': 'Verificó la llave de migración', 'migration.phase_reported': 'El equipo reportó su avance de migración',
  approveEnrollmentRequest: 'Aprobó un equipo nuevo', rejectEnrollmentRequest: 'Rechazó un equipo nuevo', requestEnrollment: 'Un equipo pidió registrarse', createEnrollment: 'Autorizó un equipo',
  createEnrollmentKey: 'Creó una clave de registro de equipos', downloadInstaller: 'Descargó el instalador con clave nueva', revokeEnrollmentKey: 'Revocó una clave de registro', putEnrollmentSettings: 'Cambió los ajustes de registro de equipos',
  createExpectedDevice: 'Agregó un equipo esperado', importExpectedDevices: 'Importó equipos esperados', externalImportExpectedDevices: 'Otro sistema cargó equipos esperados', cancelExpectedDevice: 'Canceló un equipo esperado',
  assignDevice: 'Asignó un equipo a una persona', transferDevice: 'Transfirió un equipo', patchDevice: 'Editó un equipo', createDeviceCommand: 'Envió un comando a un equipo',
  createHardeningCommand: 'Envió un endurecimiento a un equipo', setTenantHardening: 'Cambió la seguridad de cuentas de Windows', revealTenantHardening: 'Vio la contraseña de administrador local',
  reportHardening: 'El equipo reportó su endurecimiento', reportSecurity: 'El equipo reportó su seguridad', reportCommandResult: 'El equipo respondió un comando',
  deviceLogin: 'El equipo se conectó', deviceChallenge: 'El equipo pidió conectarse', syncDevice: 'El equipo se sincronizó', ingestEpisodes: 'El equipo envió actividad', ingestLogs: 'El equipo envió registros',
  createCheckIn: 'Se registró una entrada', externalCheckInsBatch: 'El sistema de puertas envió entradas',
  createUser: 'Creó una persona', patchUser: 'Editó una persona', setUserAccess: 'Cambió el acceso de una persona', setSubscription: 'Cambió el plan de una persona',
  createPanelAccount: 'Creó una cuenta del panel', patchPanelAccount: 'Cambió una cuenta del panel', resetPanelAccountPassword: 'Restableció una contraseña',
  createRole: 'Creó un rol', patchRole: 'Editó un rol', patchPermission: 'Editó un permiso', setRbacGate: 'Cambió quién gestiona los roles de la empresa',
  createRoleRule: 'Creó una asignación automática', patchRoleRule: 'Editó una asignación automática', putMonitoringExclusions: 'Cambió los cargos que no se monitorean',
  createOrgUnit: 'Creó una unidad', patchOrgUnit: 'Editó una unidad', deleteOrgUnit: 'Eliminó una unidad', createSchedule: 'Creó un horario', putSchedule: 'Editó un horario',
  createHoliday: 'Agregó un festivo', createHolidayCalendar: 'Creó un calendario de festivos', putHolidayCalendar: 'Editó un calendario de festivos', assignHolidayCalendar: 'Asignó un calendario de festivos a personas', putHoliday: 'Editó un festivo', deleteHoliday: 'Quitó un festivo', createHolidaySociety: 'Creó una sociedad', putHolidaySociety: 'Editó una sociedad', deleteHolidaySociety: 'Quitó una sociedad',
  createAbsence: 'Registró una ausencia', patchAbsence: 'Editó una ausencia', createDoor: 'Registró una puerta', patchDoor: 'Editó una puerta',
  createPolicy: 'Creó una regla de equipos', patchPolicy: 'Editó una regla de equipos', createTier: 'Creó un plan', patchTier: 'Editó un plan',
  createSuspiciousApp: 'Agregó una app a vigilar', putSuspiciousApp: 'Editó una app vigilada', deleteSuspiciousApp: 'Dejó de vigilar una app', putInstallCoverage: 'Editó la cobertura de una persona',
  putAppClassification: 'Clasificó una aplicación', createAppClassificationOverride: 'Creó una excepción de clasificación', deleteAppClassificationOverride: 'Quitó una excepción de clasificación', createAppSite: 'Agregó un sitio reconocible',
  createTenant: 'Creó una empresa', createFeaturePackage: 'Creó un paquete de funciones', patchFeaturePackage: 'Editó un paquete de funciones', createPersonPlan: 'Creó un plan por persona', patchPersonPlan: 'Editó un plan por persona', setUserPlan: 'Cambió el plan de una persona', putTenantFeatures: 'Cambió las funciones contratadas de un cliente',patchTenant: 'Editó una empresa', purgeTenant: 'Eliminó una empresa definitivamente', uploadTenantLogo: 'Cambió el logo de la empresa', putPanelSettings: 'Cambió los ajustes del panel',
  createCompanyGroup: 'Creó un grupo empresarial', patchCompanyGroup: 'Editó un grupo empresarial', deleteCompanyGroup: 'Eliminó un grupo empresarial',
  createDataSource: 'Conectó una fuente de datos', patchDataSource: 'Editó una fuente de datos', putDataDomains: 'Cambió quién manda en cada dato', runDataSource: 'Sincronizó una fuente',
  approveSyncRun: 'Aprobó una sincronización bloqueada', resolveSyncItem: 'Resolvió un pendiente de sincronización',
  createIntegration: 'Creó una integración', revokeIntegration: 'Revocó una integración', rotateIntegration: 'Cambió la clave de una integración', issueExternalToken: 'Una integración pidió acceso',
  createWebhook: 'Creó un aviso automático (webhook)', deleteWebhook: 'Quitó un aviso automático (webhook)',
  registerRelease: 'Registró una versión del agente', deployRelease: 'Desplegó una versión del agente',
  issueMigrationAuthorization: 'Autorizó una migración desde K3', validateMigrationAuthorization: 'Validó una autorización de migración', setDeviceMigration: 'Cambió la migración de un equipo',
  persistDeviceEscrow: 'Guardó la llave de migración', recoverDeviceEscrow: 'Recuperó la llave de migración', verifyDeviceEscrow: 'Verificó la llave de migración',
};
const AUDIT_VERBS = { create: 'Creó', put: 'Editó', patch: 'Editó', delete: 'Quitó', set: 'Cambió', approve: 'Aprobó', reject: 'Rechazó', revoke: 'Revocó', register: 'Registró', deploy: 'Desplegó', upload: 'Subió', run: 'Ejecutó', resolve: 'Resolvió', import: 'Importó' };
const auditAction = a => AUDIT_ACTIONS[a] || (() => {
  const m = String(a).match(/^([a-z]+)([A-Z].*)$/);
  return m && AUDIT_VERBS[m[1]] ? `${AUDIT_VERBS[m[1]]} ${m[2].replace(/([A-Z])/g, ' $1').trim().toLowerCase()}` : String(a).replace(/[._]/g, ' ');
})();
const AUDIT_FIELDS = {
  name: 'nombre', display_name: 'nombre', email: 'correo', document: 'cédula', timezone: 'zona horaria', branding: 'marca', permissions: 'permisos', scope: 'alcance', active: 'activo',
  enabled: 'activa', status: 'estado', days: 'días', start_local: 'hora de entrada', end_local: 'hora de salida', lunch_start_local: 'inicio de almuerzo', lunch_end_local: 'fin de almuerzo',
  day_hours: 'horas por día', firm_id: 'firma', site_id: 'sede', area_id: 'área', position_id: 'cargo', schedule_id: 'horario', reports_to_user_id: 'jefe', monitoring_override: 'monitoreo',
  access: 'acceso', kind: 'tipo', user_id: 'persona', group_id: 'grupo', group_access: 'accesos en el grupo', password: 'contraseña', session: 'sesión', reauthentication: 'confirmación de contraseña',
  rules: 'reglas', target_type: 'a quién aplica', target_id: 'a quién aplica', entitlements: 'módulos', badge_label: 'descripción', category: 'clasificación', key: 'aplicación', label: 'etiqueta',
  pattern: 'texto a reconocer', app_pattern: 'texto a vigilar', description: 'descripción', day: 'fecha', society_ids: 'sociedades', counts_for_presence: 'cuenta para presencia', source_ref: 'referencia',
  base_url: 'dirección', company: 'empresa en la fuente', token: 'clave', mode: 'modo', interval_minutes: 'frecuencia', domains: 'dominios', approve: 'decisión', rows: 'filas', parent_id: 'depende de',
  note: 'nota', starts_at: 'desde', ends_at: 'hasta', all_day: 'día completo', install_coverage_heartbeat_days: 'días sin conexión', raw_days: 'retención del detalle', aggregate_months: 'retención de resúmenes',
  log_days: 'retención de registros', archived: 'archivada', country_code: 'país', company_id: 'empresa del grupo', holiday_calendar_id: 'calendario de festivos', calendar_id: 'calendario', is_default: 'por defecto', user_ids: 'personas', tenant_ids: 'empresas', position_ids: 'cargos', condition_kind: 'condición', confirm_sensitive: 'confirmación', assignable_by_rule: 'asignable por regla',
  shared_password: 'contraseña compartida', admin_name: 'usuario administrador', action: 'acción', reason: 'motivo', ring: 'anillo', percentage: 'porcentaje', version: 'versión',
};
const auditFields = list => (list || []).map(f => AUDIT_FIELDS[f] || String(f).replace(/^branding\./, 'marca: ').replace(/_/g, ' ')).filter((v, i, a) => a.indexOf(v) === i).join(', ');
const ACTOR = { admin: 'Persona', device: 'Equipo', integration: 'Integración', system: 'Keeper' };
async function auditPage() {
  const day = o => new Date(Date.now() + o * 864e5).toISOString().slice(0, 10);
  const bar = el('form', undefined, 'toolbar'); $('page-content').append(bar);
  const from = labeled(bar, 'Desde', el('input')); from.type = 'date'; from.value = day(-7);
  const to = labeled(bar, 'Hasta (incluido)', el('input')); to.type = 'date'; to.value = day(0);
  const who = el('select'); [['admin', 'Solo acciones de personas'], ['', 'Todo (incluye lo automático de los equipos)']].forEach(([v, t]) => who.append(new Option(t, v))); labeled(bar, 'Mostrar', who);
  const go = el('button', 'Consultar', 'primary'); go.type = 'submit'; bar.append(go);
  const out = el('div'); $('page-content').append(out);
  const load = async () => {
    out.replaceChildren(el('p', 'Consultando…', 'help'));
    const next = new Date(`${to.value}T00:00:00`); next.setDate(next.getDate() + 1);
    const qs = new URLSearchParams({ from: from.value, to: next.toISOString().slice(0, 10) }); if (who.value) qs.set('actor_type', who.value);
    const rows = await allPages(`/audit?${qs}`);
    out.replaceChildren();
    const s = surface(`Qué pasó (${rows.length})`, out);
    if (!rows.length) { empty(s, 'Sin registros', 'No hay acciones en ese período.'); return; }
    table(s, 'Auditoría', [['Cuándo', r => when(r.at)], ['Quién', r => { const d = el('div'); d.append(el('strong', r.actor_name || '—'), el('small', ACTOR[r.actor_type] || r.actor_type)); return d; }],
      ['Qué hizo', r => auditAction(r.action)], ['Sobre qué', r => r.resource_name || '—'],
      ['Resultado', r => badge(r.outcome === 'allowed' ? 'Hecho' : r.outcome === 'denied' ? 'Denegado' : 'Falló', r.outcome === 'allowed' ? 'good' : 'bad')],
      ['Qué cambió', r => auditFields(r.changed_fields) || '—']], rows,
      { filters: [['Quién', r => r.actor_name || '—'], ['Qué hizo', r => auditAction(r.action)]] });
  };
  bar.addEventListener('submit', ev => { ev.preventDefault(); load().then(done, fail); });
  await load();
}

// ---- Logs de los equipos --------------------------------------------------------------------------------------------
const LOG_CODES = {
  capture_gap: 'El equipo estuvo suspendido o sin medir unos segundos (normal al dormir o bloquear)', applied: 'Regla aplicada en el equipo', degraded: 'Algún control no está funcionando del todo',
  package_verified: 'Versión nueva descargada y verificada', update_applying: 'Instalando versión nueva', first_login: 'Primer inicio de sesión del día',
  restore_point_created: 'Punto de restauración creado', failed: 'Falló', unsupported_rule: 'El equipo no soporta una regla', conflicting_rules: 'Reglas en conflicto',
};
const LOG_COMPONENTS = { ActivityTracker: 'Medición de actividad', WebEnforcer: 'Bloqueo web', UsbEnforcer: 'USB', InstallEnforcer: 'Instalaciones', DownloadEnforcer: 'Descargas',
  UpdateManager: 'Actualizaciones', LocalAccountHardening: 'Cuentas de Windows', RestorePoint: 'Puntos de restauración', AgentDiagnostics: 'Diagnóstico', Presence: 'Presencia' };
async function logsPage() {
  const devices = (await optional(allPages(`/tenants/${tenant}/devices`))) || [];
  const day = o => new Date(Date.now() + o * 864e5).toISOString().slice(0, 10);
  const bar = el('form', undefined, 'toolbar'); $('page-content').append(bar);
  const dev = el('select'); dev.append(new Option('Todos los equipos', '')); devices.sort((a, b) => a.hostname.localeCompare(b.hostname)).forEach(d => dev.append(new Option(d.hostname, d.id))); labeled(bar, 'Equipo', dev);
  const level = el('select'); [['', 'Todos'], ['error', 'Errores'], ['warn', 'Advertencias'], ['info', 'Información']].forEach(([v, t]) => level.append(new Option(t, v))); labeled(bar, 'Nivel', level);
  const comp = el('select'); comp.append(new Option('Todos', '')); Object.entries(LOG_COMPONENTS).forEach(([v, t]) => comp.append(new Option(t, v))); labeled(bar, 'Parte del agente', comp);
  const since = labeled(bar, 'Desde', el('input')); since.type = 'date'; since.value = day(-7);
  const go = el('button', 'Consultar', 'primary'); go.type = 'submit'; bar.append(go);
  const out = el('div'); $('page-content').append(out);
  let rows = [], offset = 0, more = false;
  const fetchPage = async () => {
    const qs = new URLSearchParams({ limit: 100, offset }); if (dev.value) qs.set('device_id', dev.value); if (level.value) qs.set('level', level.value); if (comp.value) qs.set('component', comp.value); if (since.value) qs.set('since', since.value);
    const r = await api('GET', `/audit/client-logs?${qs}`); rows = [...rows, ...r.data]; more = r.next_offset !== null; offset = r.next_offset ?? offset;
  };
  const render = () => {
    out.replaceChildren();
    const s = surface(`Registros técnicos de los equipos (${rows.length}${more ? '+' : ''})`, out);
    s.append(el('p', 'Lo que reporta el agente de cada equipo. Los errores y advertencias son lo que conviene revisar; lo informativo es el día a día normal.', 'help'));
    if (!rows.length) { empty(s, 'Sin registros', 'No hay registros con esos filtros.'); return; }
    table(s, 'Logs', [['Cuándo', r => when(r.at)], ['Equipo', r => r.hostname], ['Nivel', r => badge(r.level === 'error' ? 'Error' : r.level === 'warn' ? 'Advertencia' : 'Info', r.level === 'error' ? 'bad' : r.level === 'warn' ? 'warn' : '')],
      ['Parte', r => LOG_COMPONENTS[r.component] || r.component], ['Qué pasó', r => LOG_CODES[r.code] || r.code], ['Detalle', r => r.error_code || '—']], rows,
      { filters: [['Equipo', r => r.hostname], ['Parte', r => LOG_COMPONENTS[r.component] || r.component]] });
    if (more) { const b = el('button', 'Cargar 100 más'); b.type = 'button'; b.addEventListener('click', async () => { b.disabled = true; try { await fetchPage(); render(); } catch (e) { fail(e); } }); s.append(b); }
  };
  bar.addEventListener('submit', async ev => { ev.preventDefault(); rows = []; offset = 0; try { await fetchPage(); render(); done(); } catch (e) { fail(e); } });
  await fetchPage(); render();
}

// ---- Salud del servidor ---------------------------------------------------------------------------------------------
async function healthPage() {
  const h = await api('GET', '/tenants/server-health');
  const age = v => v ? (Date.now() - Date.parse(v)) / 60000 : Infinity;
  const checksList = [
    ['Cálculo de productividad', h.cron_status === 'ok' && age(h.cron_last_success) < 30, h.cron_last_success ? `Último cálculo correcto: ${when(h.cron_last_success)}` : 'Nunca se ha ejecutado', 'Corre cada 10 minutos. Si se detiene, los reportes dejan de actualizarse.'],
    ['Días por recalcular', h.pending_rollup_days < 40, `${h.pending_rollup_days} días en cola`, 'Se vacía sola; si crece sin parar, el cálculo no da abasto.'],
    ['Equipos reportando', age(h.last_sync) < 15, h.last_sync ? `Último contacto: ${when(h.last_sync)}` : 'Ningún equipo ha reportado', 'Si pasan más de 15 minutos sin contacto de ningún equipo, revisa la red o el servidor.'],
    ['Datos de actividad', age(h.last_ingest) < 60, h.last_ingest ? `Últimos datos: ${when(h.last_ingest)}` : 'Sin datos', 'Ventanas y aplicaciones que llegan de los equipos.'],
    ['Comandos pendientes', h.pending_commands < 50, `${h.pending_commands} comandos esperando a su equipo`, 'Bloquear, reiniciar o endurecer: se entregan cuando el equipo se conecta.'],
    ['Reglas por recalcular', h.pending_policy_compiles === 0, h.pending_policy_compiles ? 'Hay cambios de reglas sin aplicar' : 'Todo aplicado', 'Se aplican en segundos tras cada cambio.'],
  ];
  const ok = checksList.filter(c => c[1]).length;
  kpis([['Estado general', ok === checksList.length ? 'Todo bien' : `${checksList.length - ok} por revisar`], ['Versión del cálculo', h.calculation_version], ['Base de datos', h.database_version], ['Plataforma', h.version]]);
  const s = surface('Revisión');
  const grid = el('div', undefined, 'three'); s.append(grid);
  for (const [name, good, detail, help] of checksList) {
    const card = surface(name, grid); card.append(badge(good ? 'Bien' : 'Revisar', good ? 'good' : 'warn'), el('p', detail), el('p', help, 'help'));
  }
}

// ---- Ajustes del panel ----------------------------------------------------------------------------------------------
async function settingsPage() {
  const cfg = await api('GET', '/tenants/panel-settings');
  const s = surface('Ajustes de la empresa');
  const form = el('form'); s.append(form);
  const grid = el('div', undefined, 'form-grid'); form.append(grid);
  const num = (label, value, min, max, help) => { const i = labeled(grid, label, el('input'), help); i.type = 'number'; i.min = min; i.max = max; i.value = value ?? ''; return i; };
  const heartbeat = num('Días sin conexión para alertar en Cobertura', cfg.install_coverage_heartbeat_days, 1, 90, 'Un equipo que no reporta en estos días aparece como "sin conexión reciente".');
  const raw = num('Días que se guarda el detalle (ventanas, apps)', cfg.raw_days, 30, 3650, 'Después se borra el detalle y quedan solo los resúmenes.');
  const agg = num('Meses que se guardan los resúmenes', cfg.aggregate_months, 1, 120, 'Productividad, presencia y reportes por día.');
  const logs = num('Días que se guardan los registros técnicos', cfg.log_days, 1, 365, 'Lo que se ve en Logs de los equipos.');
  const save = el('button', 'Guardar ajustes', 'primary'); save.type = 'submit'; form.append(save);
  // Éxito como estado y error como alerta: un fallo no se lee como mensaje informativo (revisión UX AJU-2).
  const msg = el('p', '', 'help'); msg.setAttribute('role', 'status');
  const bad = el('p', '', 'error-text'); bad.setAttribute('role', 'alert'); bad.hidden = true; form.append(msg, bad);
  form.addEventListener('submit', async ev => {
    ev.preventDefault(); save.disabled = true; msg.textContent = ''; bad.hidden = true;
    try { await api('PUT', '/tenants/panel-settings', { body: { install_coverage_heartbeat_days: Number(heartbeat.value), raw_days: Number(raw.value), aggregate_months: Number(agg.value), log_days: Number(logs.value) } }); msg.textContent = 'Ajustes guardados.'; done(); }
    catch (e) { bad.textContent = e.problem?.detail === 'raw_days_below_late_arrival' ? 'El detalle no puede guardarse menos días que el plazo en que llegan datos atrasados de los equipos (30).' : errorMessage(e); bad.hidden = false; }
    finally { save.disabled = false; }
  });
  // Cuenta de IT para endurecer equipos: ajuste de toda la empresa (permiso de endurecimiento con alcance empresa y
  // funcion contratada). Sin el, la seccion no aparece.
  if (canConfigureHardening(await loadHardeningAccess())) {
    const settings = await loadHardeningSettings(tenant);
    if (settings) {
      const hs = surface('Seguridad de cuentas de Windows');
      const state = el('p'); hs.append(badge(settings.configured ? 'Configurada' : 'Sin configurar', settings.configured ? 'good' : 'warn'), state);
      state.textContent = hardeningSummary(settings);
      hs.append(el('p', 'La cuenta de IT es la administradora que queda en cada equipo endurecido. Sin su contraseña no se puede endurecer ningún equipo.', 'help'));
      const hmsg = el('p', '', 'help'); hmsg.setAttribute('role', 'status');
      const open = el('button', settings.configured ? 'Cambiar cuenta de IT' : 'Configurar cuenta de IT', settings.configured ? undefined : 'primary'); open.type = 'button';
      open.addEventListener('click', () => openHardeningSettings(tenant, { onSaved: saved => { state.textContent = hardeningSummary(saved); hs.querySelector('.badge').replaceWith(badge('Configurada', 'good')); open.textContent = 'Cambiar cuenta de IT'; open.className = ''; hmsg.textContent = 'Guardado.'; done(); } }));
      const actions = el('div', undefined, 'actions'); actions.append(open); hs.append(actions, hmsg);
    }
  }
  // Atajos según el acceso de la cuenta (misma regla que el menú, nav-access.js): no se ofrece lo que daría «Sin permiso».
  // Empresas (marca) y Planes son de la plataforma. Si el acceso no se pudo leer se muestran y decide la API.
  const platform = !!readSession()?.is_platform_admin;
  const access = await loadAccess();
  const shortcuts = [['Horarios', '/horarios.php', 'schedules'], ['Festivos', '/festivos.php', 'holidays'], ['Clasificación de apps', '/clasificacion.php', 'classification'],
    ['Fuentes de datos (AZC One)', '/fuentes.php', 'sources']].filter(([, , key]) => !access || allowed(access, key));
  if (platform) { shortcuts.unshift(['Marca, logo y colores', '/empresas.php']); shortcuts.push(['Planes y paquetes', '/planes.php']); }
  const more = surface('Otros ajustes');
  if (shortcuts.length) {
    const ul = el('ul');
    shortcuts.forEach(([t, href]) => { const li = el('li'); const a = el('a', t); a.href = href; li.append(a); ul.append(li); });
    more.append(ul);
  } else more.append(el('p', 'Tu cuenta no tiene acceso a otras pantallas de ajustes.', 'help'));
  if (!platform) more.append(el('p', 'Marca, logo y colores: los gestiona la plataforma. Pide el cambio al administrador de AZC Keeper.', 'help'));
}

// ---- Festivos -------------------------------------------------------------------------------------------------------
// Festivos por calendario (uno por país o los que se quieran). Cada persona usa el calendario que se le asigne; si no
// tiene, el del país de su sede; si su sede no tiene país, el calendario por defecto (mismo orden que el servidor).
async function holidaysPage() {
  const [domains, sources] = await Promise.all([optional(api('GET', '/data-domains')), optional(api('GET', '/data-sources'))]);
  const ownerId = domains?.data?.find(d => d.domain === 'festivos')?.source_id || null;
  const owner = ownerId ? (sources?.data?.find(x => x.id === ownerId)?.name || 'una fuente externa') : null;
  const pageAll = async path => { const out = []; let next = 0; while (next !== null) { const r = await api('GET', `${path}${path.includes('?') ? '&' : '?'}limit=100&offset=${next}`); out.push(...r.data); next = r.next_offset; } return out; };
  let [calendars, list, users, org] = await Promise.all([pageAll('/schedules/holiday-calendars'), pageAll('/schedules/holidays'), optional(allPages('/users')), optional(allPages('/organization'))]);
  const n = names(org || []);
  const cname = id => calendars.find(c => c.id === id)?.name || 'Sin calendario';
  const fmt = d => new Intl.DateTimeFormat('es-CO', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${d}T00:00:00Z`));
  const today = new Date().toISOString().slice(0, 10);
  const siteCountry = u => org?.find(o => o.id === u.site_id)?.country_code || null;
  const effective = u => {
    const active = calendars.filter(c => c.active);
    const own = active.find(c => c.id === u.holiday_calendar_id); if (own) return [own, 'asignado'];
    const country = siteCountry(u);
    const byCountry = country && active.filter(c => c.country_code === country).sort((a, b) => (b.is_default - a.is_default) || a.name.localeCompare(b.name, 'es'))[0];
    if (byCountry) return [byCountry, 'por el país de su sede'];
    const fallback = active.find(c => c.is_default); return fallback ? [fallback, 'por defecto'] : [null, 'sin festivos'];
  };
  const reload = async () => { [calendars, list, users] = await Promise.all([pageAll('/schedules/holiday-calendars'), pageAll('/schedules/holidays'), optional(allPages('/users'))]); render(); done(); };
  const link = (text, fn) => { const b = el('button', text, 'link-button'); b.type = 'button'; b.addEventListener('click', fn); return b; };
  const render = () => {
    $('page-content').replaceChildren();
    $('page-action').textContent = calendars.length ? 'Agregar festivo' : 'Crear calendario';
    const upcoming = list.filter(h => h.day >= today).sort((a, b) => a.day.localeCompare(b.day));
    kpis([['Calendarios', String(calendars.filter(c => c.active).length)], ['Festivos este año', String(list.filter(h => h.day.startsWith(today.slice(0, 4))).length)],
      ['Próximo festivo', upcoming[0] ? upcoming[0].name : 'Ninguno', upcoming[0] ? `${fmt(upcoming[0].day)} · ${cname(upcoming[0].calendar_id)}` : '']]);
    const c = surface('Calendarios');
    c.append(el('p', 'Cada persona usa el calendario que se le asigne aquí (o en su ficha de Personas). Si no tiene uno, usa el del país de su sede; si su sede no tiene país, el calendario por defecto. En un festivo la persona no tiene jornada: no cuenta como ausencia ni tardanza.', 'help'));
    if (owner) c.append(el('p', `Los festivos llegan de ${owner}.`, 'notice'));
    else if (calendars.length) { const tools = el('div', undefined, 'actions no-margin'); const add = el('button', 'Crear calendario'); add.type = 'button'; add.addEventListener('click', () => calendarEditor(null)); tools.append(add); c.append(tools); }
    if (!calendars.length) {
      empty(c, 'Sin calendarios', owner ? `Los calendarios llegan de ${owner}.` : 'Crea un calendario y después agrega sus festivos. Lo normal es uno por país (Colombia, Estados Unidos, Honduras…).');
      if (!owner) { const add = el('button', 'Crear calendario', 'primary'); add.type = 'button'; add.addEventListener('click', () => calendarEditor(null)); c.querySelector('.empty').append(add); }
    }
    else table(c, 'Calendarios', [['Calendario', x => x.name], ['País', x => countryName(x.country_code)], ['Festivos', x => String(x.holidays)],
      ['Personas que lo usan', x => users ? String(users.filter(u => u.status === 'active' && effective(u)[0]?.id === x.id).length) : '—'], ['Asignadas a mano', x => String(x.people)],
      ['Estado', x => { const d = el('div'); d.append(badge(x.active ? 'Activo' : 'Inactivo', x.active ? 'good' : '')); if (x.is_default) d.append(' ', badge('Por defecto', 'info')); return d; }],
      ['Acciones', x => { if (owner) return ''; const box = el('div', undefined, 'actions no-margin'); box.append(link('Editar', () => calendarEditor(x))); if (x.active) box.append(link('Agregar festivo', () => editor(null, x.id))); if (users && x.active) box.append(link('Asignar a personas', () => assign(x))); return box; }]], calendars);
    const s = surface('Festivos');
    if (!calendars.length) { empty(s, 'Sin festivos', 'Los festivos se agregan a un calendario: primero crea uno con «Crear calendario».'); return; }
    if (!list.length) { empty(s, 'Sin festivos', 'Agrega los festivos con "Agregar festivo".'); return; }
    table(s, 'Festivos', [['Fecha', h => h.day], ['Día', h => fmt(h.day)], ['Festivo', h => h.name], ['Calendario', h => cname(h.calendar_id)],
      ['', h => {
        if (owner) return '';
        const box = el('div', undefined, 'actions no-margin');
        box.append(link('Editar', () => editor(h)), link('Quitar', async () => { if (!confirm(`¿Quitar ${h.name} (${cname(h.calendar_id)})?`)) return; try { await api('DELETE', `/schedules/holidays/${h.id}`); list = list.filter(x => x.id !== h.id); render(); done(); } catch (er) { fail(er); } }));
        return box;
      }]], list, { filters: [['Calendario', h => cname(h.calendar_id)], ['Año', h => h.day.slice(0, 4), today.slice(0, 4)]] });
  };
  const editor = (h, presetCalendar) => {
    if (!calendars.length) { calendarEditor(null); return; }
    const { dialog, body, ok, show } = dialogShell(h ? `Editar ${h.name}` : 'Agregar festivo');
    const name = labeled(body, 'Nombre', el('input')); name.maxLength = 160; name.value = h?.name || '';
    const dayIn = labeled(body, 'Fecha', el('input')); dayIn.type = 'date'; dayIn.value = h?.day || '';
    const cal = el('select'); calendars.filter(x => x.active || x.id === h?.calendar_id).forEach(x => cal.append(new Option(x.name, x.id)));
    cal.value = h?.calendar_id || presetCalendar || calendars.find(x => x.is_default && x.active)?.id || cal.options[0]?.value || ''; labeled(body, 'Calendario', cal, 'El festivo aplica a las personas que usan este calendario.');
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (!name.value.trim() || !dayIn.value) { show('Escribe el nombre y la fecha.'); return; }
      ok.disabled = true;
      const payload = { name: name.value.trim(), day: dayIn.value, calendar_id: cal.value, society_ids: [] };
      try { h ? await api('PUT', `/schedules/holidays/${h.id}`, { body: payload }) : await api('POST', '/schedules/holidays', { body: payload }); dialog.close(); await reload(); }
      catch (e) { show(e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const calendarEditor = x => {
    const { dialog, body, ok, show } = dialogShell(x ? `Editar ${x.name}` : 'Crear calendario');
    const name = labeled(body, 'Nombre', el('input')); name.maxLength = 120; name.value = x?.name || '';
    const country = labeled(body, 'País', countrySelect(x ? x.country_code : 'CO', 'Ninguno (calendario especial)'), 'Las personas sin calendario propio usan el del país de su sede.');
    const [isDefault] = checks(body, [['default', 'Calendario por defecto', 'Lo usan quienes no tienen calendario propio ni sede con país.']], x?.is_default ? ['default'] : []);
    const state = el('select'); state.append(new Option('Activo', '1'), new Option('Inactivo (no se aplica)', '0')); state.value = x && !x.active ? '0' : '1'; labeled(body, 'Estado', state);
    ok.textContent = x ? 'Guardar' : 'Crear calendario';
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (!name.value.trim()) { show('Escribe el nombre.'); return; }
      if (isDefault.checked && state.value === '0') { show('El calendario por defecto debe estar activo.'); return; }
      ok.disabled = true;
      const payload = { name: name.value.trim(), country_code: country.value || null, is_default: isDefault.checked, active: state.value === '1' };
      try { x ? await api('PUT', `/schedules/holiday-calendars/${x.id}`, { body: payload }) : await api('POST', '/schedules/holiday-calendars', { body: payload }); dialog.close(); await reload(); }
      catch (e) { show(e.problem?.detail === 'duplicate_name' ? 'Ya hay un calendario con ese nombre.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  // Asignar en grupo, como los horarios: se filtra por empresa, país, sede o área y se marcan las personas.
  const assign = x => {
    const { dialog, body, ok, show } = dialogShell(`Asignar "${x.name}" a personas`);
    dialog.classList.add('wide-dialog');
    body.append(el('p', 'Filtra y marca a quiénes aplica este calendario. Reemplaza el que tenían asignado. "Volver a automático" les quita el calendario propio y vuelven a usar el del país de su sede.', 'help'));
    const bar = el('div', undefined, 'form-grid'); body.append(bar);
    const active = (users || []).filter(u => u.status === 'active').sort((a, b) => a.display_name.localeCompare(b.display_name, 'es'));
    const pick = (label, values) => { const sel = el('select'); sel.append(new Option('Todas', '')); values.forEach(([v, t]) => sel.append(new Option(t, v))); labeled(bar, label, sel); return sel; };
    const unitsOf = kind => (org || []).filter(o => o.kind === kind && o.active).sort((a, b) => a.name.localeCompare(b.name, 'es')).map(o => [o.id, o.name]);
    const fCompany = pick('Empresa', unitsOf('company'));
    const fCountry = pick('País', [...new Set(active.map(siteCountry).filter(Boolean))].map(c => [c, countryName(c)]));
    const siteCity = u => org?.find(o => o.id === u.site_id)?.city || null;
    const fCity = pick('Ciudad', [...new Set(active.map(siteCity).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es')).map(c => [c, c]));
    const fSite = pick('Sede', unitsOf('site')); const fArea = pick('Área', unitsOf('area'));
    const search = labeled(bar, 'Buscar nombre', el('input')); search.type = 'search';
    const marks = el('div', undefined, 'actions no-margin'); const all = el('button', 'Marcar las mostradas'); all.type = 'button'; const none = el('button', 'Desmarcar todas'); none.type = 'button'; marks.append(all, none); body.append(marks);
    const status = el('p', '', 'help'); status.setAttribute('role', 'status'); body.append(status);
    const box = el('div', undefined, 'check-list'); body.append(box);
    const chosen = new Set(active.filter(u => u.holiday_calendar_id === x.id).map(u => u.id));
    const shown = () => active.filter(u => (!fCompany.value || u.company_id === fCompany.value) && (!fCountry.value || siteCountry(u) === fCountry.value) && (!fCity.value || siteCity(u) === fCity.value) && (!fSite.value || u.site_id === fSite.value) && (!fArea.value || u.area_id === fArea.value)
      && (!search.value.trim() || fold(u.display_name).includes(fold(search.value))));
    const draw = () => {
      box.replaceChildren(); const rows = shown();
      for (const u of rows) {
        const l = el('label', undefined, 'check'); const c = el('input'); c.type = 'checkbox'; c.checked = chosen.has(u.id);
        c.addEventListener('change', () => { c.checked ? chosen.add(u.id) : chosen.delete(u.id); count(); });
        const [cal, why] = effective(u);
        l.append(c, ` ${u.display_name}`, el('small', `${[n.get(u.company_id), n.get(u.site_id)].filter(Boolean).join(' · ') || 'Sin empresa ni sede'} — hoy: ${cal ? cal.name : 'sin festivos'} (${why})`)); box.append(l);
      }
      if (!rows.length) box.append(el('p', 'Nadie coincide con los filtros.', 'help'));
      count();
    };
    const count = () => { status.textContent = `${chosen.size} personas marcadas · ${shown().length} mostradas.`; ok.disabled = !chosen.size; };
    [fCompany, fCountry, fCity, fSite, fArea].forEach(f => f.addEventListener('change', draw)); search.addEventListener('input', draw);
    all.addEventListener('click', () => { shown().forEach(u => chosen.add(u.id)); draw(); });
    none.addEventListener('click', () => { chosen.clear(); draw(); });
    const reset = el('button', 'Volver a automático'); reset.type = 'button'; ok.before(reset);
    reset.addEventListener('click', async () => {
      const target = active.filter(u => chosen.has(u.id) && u.holiday_calendar_id);
      if (!target.length) { show('Ninguna de las marcadas tiene calendario propio.'); return; }
      if (!confirm(`¿Quitar el calendario propio a ${target.length} personas? Usarán el del país de su sede.`)) return;
      reset.disabled = ok.disabled = true;
      try { for (const u of target) await patient(() => api('PATCH', `/users/${u.id}`, { body: { holiday_calendar_id: null }, ifMatch: u.version })); dialog.close(); await reload(); }
      catch (e) { show(e.status === 412 ? 'Alguien modificó a una de estas personas: recarga la página.' : e); reset.disabled = ok.disabled = false; }
    });
    ok.textContent = 'Asignar calendario';
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (!chosen.size) return;
      ok.disabled = true;
      try { await api('POST', `/schedules/holiday-calendars/${x.id}/people`, { body: { user_ids: [...chosen] } }); dialog.close(); await reload(); }
      catch (e) { show(e); ok.disabled = false; }
    });
    draw(); dialog.showModal();
  };
  // Sin calendarios la acción principal es crear uno (un festivo siempre pertenece a un calendario).
  const action = $('page-action'); action.hidden = !!owner; action.addEventListener('click', () => calendars.length ? editor(null) : calendarEditor(null));
  render();
}

// ---- Apps sospechosas -----------------------------------------------------------------------------------------------
const SUSPICIOUS_CATEGORY = { remote_desktop: ['Escritorio remoto', 'Controlar otro computador (AnyDesk, TeamViewer…)'], foreign_vpn: ['VPN externa', 'Conectarse a la red de otra empresa'], foreign_workspace: ['Espacio de trabajo externo', 'Herramientas de trabajo de otra empresa'], vm: ['Máquina virtual', 'Otro computador dentro del equipo'] };
async function suspiciousPage() {
  const day = o => new Date(Date.now() + o * 864e5).toISOString().slice(0, 10);
  const pageAll = async path => { const out = []; let next = 0; while (next !== null) { const r = await api('GET', `${path}${path.includes('?') ? '&' : '?'}limit=100&offset=${next}`); out.push(...r.data); next = r.next_offset; } return out; };
  const devices = (await optional(allPages(`/tenants/${tenant}/devices`))) || []; const host = new Map(devices.map(d => [d.id, d.hostname]));
  let catalog = await pageAll('/policies/suspicious-apps');
  let detections = await pageAll(`/reports/suspicious-apps?from=${day(-29)}&to=${day(1)}`);
  const render = () => {
    $('page-content').replaceChildren();
    const people = new Set(detections.map(d => d.user_id));
    kpis([['Detecciones (30 días)', String(detections.length)], ['Personas', String(people.size)], ['Horas en estas apps', (detections.reduce((s, d) => s + d.active_seconds, 0) / 3600).toFixed(1)], ['Apps vigiladas', String(catalog.filter(c => c.active).length)]]);
    const s = surface('Detecciones de los últimos 30 días');
    s.append(el('p', 'Uso de aplicaciones que pueden indicar trabajo para otra empresa o acceso a otro computador. Una detección es una señal para revisar, no una prueba.', 'help'));
    if (!detections.length) empty(s, 'Sin detecciones', 'Nadie usó apps de la lista en los últimos 30 días.');
    else table(s, 'Detecciones', [['Día', d => d.day], ['Persona', d => d.display_name], ['Equipo', d => host.get(d.device_id) || '—'], ['Aplicación', d => d.process_name], ['Tiempo', d => `${Math.round(d.active_seconds / 60)} min`],
      ['Horario', d => d.first_activity ? `${new Date(d.first_activity).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })} – ${new Date(d.last_activity).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })}` : '—']], detections,
      { filters: [['Persona', d => d.display_name], ['Aplicación', d => d.process_name]] });
    const c = surface('Apps que se vigilan');
    c.append(el('p', 'Si el nombre del programa o el título de su ventana contiene el texto, se registra como detección.', 'help'));
    table(c, 'Catálogo', [['Contiene', a => a.app_pattern], ['Tipo', a => SUSPICIOUS_CATEGORY[a.category]?.[0] || a.category], ['Descripción', a => a.description || '—'], ['Estado', a => badge(a.active ? 'Vigilada' : 'Pausada', a.active ? 'good' : '')],
      ['', a => { const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => editor(a)); return b; }]], catalog);
    const add = el('button', 'Agregar app a vigilar'); add.type = 'button'; add.addEventListener('click', () => editor(null)); c.append(add);
  };
  const editor = a => {
    const { dialog, body, ok, show } = dialogShell(a ? `Editar ${a.app_pattern}` : 'Agregar app a vigilar');
    const pat = labeled(body, 'El programa o título contiene', el('input'), 'Ej.: anydesk, teamviewer, VirtualBox'); pat.maxLength = 160; pat.value = a?.app_pattern || '';
    const cat = el('select'); Object.entries(SUSPICIOUS_CATEGORY).forEach(([v, [t, d]]) => cat.append(new Option(`${t} — ${d}`, v))); cat.value = a?.category || 'remote_desktop'; labeled(body, 'Tipo', cat);
    const desc = labeled(body, 'Descripción (opcional)', el('input')); desc.maxLength = 255; desc.value = a?.description || '';
    const act = el('input'); act.type = 'checkbox'; act.checked = a ? a.active : true; const al = el('label', undefined, 'check'); al.append(act, ' Vigilar'); body.append(al);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (!pat.value.trim()) { show('Escribe el texto a vigilar.'); return; }
      ok.disabled = true;
      const b = { app_pattern: pat.value.trim(), category: cat.value, description: desc.value.trim(), active: act.checked };
      try { const saved = a ? await api('PUT', `/policies/suspicious-apps/${a.id}`, { body: b }) : await api('POST', '/policies/suspicious-apps', { body: b }); catalog = a ? catalog.map(x => x.id === a.id ? saved : x) : [...catalog, saved]; dialog.close(); render(); done(); }
      catch (e) { show(e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  render();
}

// ---- Roles y permisos -------------------------------------------------------------------------------------------
async function rolesPage() {
  const session = readSession();
  const [roles, permissions, org] = await Promise.all([allPages('/roles'), allPages('/permissions'), optional(allPages('/organization'))]);
  const n = names(org || []); let list = roles;
  const visible = permissions.filter(p => session?.is_platform_admin || !p.platform_only);
  const labelOf = new Map(permissions.map(p => [p.code, p.label]));
  const render = () => {
    $('page-content').replaceChildren();
    const s = surface('Roles de la empresa');
    s.append(el('p', 'Un rol es un conjunto de permisos. Qué datos ve cada persona (su alcance) se elige en su ficha de Personas o en Cuentas del panel.', 'help'));
    table(s, 'Roles', [['Rol', r => r.assignable_by_rule ? `${r.name} · automático` : r.name],
      ['Permisos', r => { const d = el('details'); d.append(el('summary', `${r.permissions.length} permisos`)); const ul = el('ul'); r.permissions.forEach(c => ul.append(el('li', labelOf.get(c) || c))); d.append(ul); return d; }],
      ['Estado', r => badge(r.active ? 'Activo' : 'Inactivo', r.active ? 'good' : '')], ['Acciones', r => { const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => editor(r)); return b; }]], list);
    renderRules();
  };
  // ---- Asignacion automatica (reglas cargo/jefatura -> rol) ----
  let rules = (await optional(api('GET', '/role-rules')))?.data ?? null;
  const CONDITIONS = [['position', 'Tiene el cargo…'], ['manages', 'Es jefe (alguien le reporta)'], ['position_in_area', 'Tiene el cargo… en el área…']];
  const unitName = id => n.get(id) || '—';
  const ruleText = x => x.condition_kind === 'manages' ? 'Es jefe de alguien' : x.condition_kind === 'position' ? `Cargo: ${unitName(x.position_id)}` : `Cargo: ${unitName(x.position_id)} en ${unitName(x.area_id)}`;
  const renderRules = () => {
    if (rules === null) return;
    const s = surface('Asignación automática');
    s.append(el('p', 'Cuando el cargo o la jefatura de una persona cambia (aquí o desde AZC One), estas reglas le dan o le quitan roles solas. Solo usan roles marcados como "se puede dar automáticamente" y solo quitan lo que ellas pusieron. La regla propone el rol; el alcance de cada persona no cambia.', 'help'));
    const add = el('button', 'Crear regla'); add.type = 'button'; add.addEventListener('click', () => ruleEditor(null)); s.append(add);
    if (!rules.length) { s.append(el('p', 'Aún no hay reglas.', 'help')); return; }
    table(s, 'Reglas', [['Regla', x => x.name], ['Cuándo', ruleText], ['Da el rol', x => list.find(r => r.id === x.role_id)?.name || '—'], ['Personas hoy', x => String(x.matches)],
      ['Estado', x => badge(x.active ? 'Activa' : 'Inactiva', x.active ? 'good' : '')],
      ['', x => { const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => ruleEditor(x)); return b; }]], rules);
  };
  const ruleEditor = x => {
    const { dialog, body, ok, show } = dialogShell(x ? `Editar ${x.name}` : 'Crear regla de asignación');
    const nm = labeled(body, 'Nombre', el('input')); nm.maxLength = 120; nm.value = x?.name || '';
    const cond = el('select'); CONDITIONS.forEach(([v, t]) => cond.append(new Option(t, v))); cond.value = x?.condition_kind || 'position'; labeled(body, 'Cuándo aplica', cond);
    const pos = el('select'); (org || []).filter(o => o.kind === 'position' && o.active).sort((a, b) => a.name.localeCompare(b.name, 'es')).forEach(o => pos.append(new Option(o.name, o.id)));
    const posL = labeled(body, 'Cargo', pos); pos.value = x?.position_id || pos.value;
    const area = el('select'); (org || []).filter(o => o.kind === 'area' && o.active).sort((a, b) => a.name.localeCompare(b.name, 'es')).forEach(o => area.append(new Option(o.name, o.id)));
    const areaL = labeled(body, 'Área', area); area.value = x?.area_id || area.value;
    const role = el('select'); list.filter(r => r.active && r.assignable_by_rule).forEach(r => role.append(new Option(r.name, r.id))); role.value = x?.role_id || role.value;
    labeled(body, 'Da el rol', role, list.some(r => r.assignable_by_rule) ? 'Solo aparecen los roles marcados "se puede dar automáticamente".' : 'Primero marca un rol como "se puede dar automáticamente".');
    const act = el('input'); act.type = 'checkbox'; act.checked = x ? x.active : true; const actL = el('label', undefined, 'check'); actL.append(act, ' Regla activa'); body.append(actL);
    const sync = () => { posL.hidden = cond.value === 'manages'; areaL.hidden = cond.value !== 'position_in_area'; };
    cond.addEventListener('change', sync); sync();
    const send = async confirmSensitive => {
      const payload = { name: nm.value.trim(), condition_kind: cond.value, position_id: cond.value === 'manages' ? null : pos.value || null, area_id: cond.value === 'position_in_area' ? area.value || null : null, role_id: role.value, active: act.checked };
      if (confirmSensitive) payload.confirm_sensitive = true;
      return x ? api('PATCH', `/role-rules/${x.id}`, { body: payload, ifMatch: x.version }) : api('POST', '/role-rules', { body: payload });
    };
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!nm.value.trim() || !role.value) { show('Escribe el nombre y elige el rol.'); return; }
      ok.disabled = true;
      try { await send(false); }
      catch (e) {
        if (e.problem?.detail === 'confirm_sensitive' && confirm('Esta regla da permisos de administración a más de 10 personas. ¿Seguro?')) {
          try { await send(true); } catch (e2) { show(e2); ok.disabled = false; return; }
        } else { show(e.problem?.detail === 'role_not_assignable' ? 'Ese rol no está marcado como "se puede dar automáticamente".' : e.problem?.detail === 'condition_incomplete' ? 'Elige el cargo (y el área, si aplica).' : e); ok.disabled = false; return; }
      }
      rules = (await api('GET', '/role-rules')).data; dialog.close(); render(); done();
    });
    dialog.showModal();
  };
  const editor = role => {
    const { dialog, body, ok, show } = dialogShell(role ? `Editar ${role.name}` : 'Crear rol');
    dialog.classList.add('wide-dialog');
    const name = labeled(body, 'Nombre del rol', el('input')); name.maxLength = 120; name.value = role?.name || '';
    body.append(el('h3', 'Permisos'));
    const groups = new Map(); visible.forEach(p => { if (!groups.has(p.resource)) groups.set(p.resource, []); groups.get(p.resource).push(p); });
    const all = [];
    [...groups.entries()].sort((a, b) => (moduleNames[a[0]] || a[0]).localeCompare(moduleNames[b[0]] || b[0], 'es')).forEach(([resource, perms]) => {
      const fs = el('fieldset', undefined, 'perm-group'); fs.append(el('legend', moduleNames[resource] || resource)); body.append(fs);
      all.push(...checks(fs, perms.map(p => [p.code, p.label, p.platform_only ? 'Solo plataforma' : null]), role?.permissions || []));
    });
    const active = el('input'); active.type = 'checkbox'; active.checked = role ? role.active : true;
    const al = el('label', undefined, 'check'); al.append(active, ' Rol activo'); body.append(al);
    const auto = el('input'); auto.type = 'checkbox'; auto.checked = !!role?.assignable_by_rule;
    const autol = el('label', undefined, 'check'); autol.append(auto, ' Se puede dar automáticamente (por cargo o jefatura, en "Asignación automática")'); body.append(autol);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!name.value.trim()) { show('Escribe el nombre del rol.'); return; }
      ok.disabled = true;
      const payload = { name: name.value.trim(), permissions: all.filter(b => b.checked).map(b => b.value), active: active.checked, assignable_by_rule: auto.checked };
      try {
        const saved = role ? await api('PATCH', `/roles/${role.id}`, { body: payload, ifMatch: role.version }) : await api('POST', '/roles', { body: payload });
        list = role ? list.map(r => r.id === role.id ? saved : r) : [...list, saved]; dialog.close(); render(); done();
      } catch (e) {
        // errorMessage ya traduce delegation_exceeds_own_access, scope_denied y feature_not_contracted (§7).
        show(e.code === 'tenant_rbac_disabled' ? 'La empresa no tiene habilitada la gestión de sus propios roles: pídesela a la plataforma.' : e);
        ok.disabled = false;
      }
    });
    dialog.showModal();
  };
  const action = $('page-action'); action.textContent = 'Crear rol'; action.hidden = false; action.addEventListener('click', () => editor(null));
  render();
}

async function start() {
  const session = readSession();
  if (!session) { goToLogin(); return; }
  $('now').textContent = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date());
  $('logout').addEventListener('click', async () => { try { await refreshCsrf(); await api('POST', '/auth/logout'); } finally { goToLogin(); } });
  if ($('platform-group')) $('platform-group').hidden = !session.is_platform_admin;
  // Una cuenta de empresa siempre trabaja en su empresa. Superadmin: Cuentas, Empresas y Planes son globales (empresa duena);
  // las pantallas de una empresa usan la elegida en el selector (por defecto la primera empresa cliente).
  const globalPage = ['accounts', 'tenants', 'plans'].includes(page);
  try {
    if (session.is_platform_admin) {
      companies = sortCompanies(await allPages('/tenants', PLATFORM_TENANT));
      // Sin empresas cliente, las pantallas de empresa caerian en la de plataforma (y se crearian personas ahi): a Empresas.
      if (!globalPage && !companies.some(c => c.id !== PLATFORM_TENANT)) { location.replace('/empresas.php?sin-empresas=1'); return; }
      let selected = null; try { selected = sessionStorage.getItem('keeper.presentation.tenant'); } catch { /* sin almacenamiento */ }
      tenant = globalPage ? PLATFORM_TENANT : (companies.some(c => c.id === selected) ? selected : companies.find(c => c.id !== PLATFORM_TENANT)?.id || PLATFORM_TENANT);
      if (!globalPage) {
        const select = $('tenant-select');
        companies.forEach(c => select.append(new Option(c.id === PLATFORM_TENANT ? `${c.branding?.display_name || c.name} (dueña)` : (c.branding?.display_name || c.name), c.id)));
        select.value = tenant; $('tenant-control').hidden = false;
        select.addEventListener('change', () => { try { sessionStorage.setItem('keeper.presentation.tenant', select.value); } catch { /* sin almacenamiento */ } location.reload(); });
      }
    } else if (session.group?.tenants?.length > 1) {
      // Cuenta de grupo: selector con las empresas de su grupo (la API valida cada una).
      let selected = null; try { selected = sessionStorage.getItem('keeper.presentation.tenant'); } catch { /* sin almacenamiento */ }
      tenant = session.group.tenants.some(c => c.id === selected) ? selected : session.tenant_id;
      if (page !== 'group') {
        const select = $('tenant-select');
        session.group.tenants.forEach(c => select.append(new Option(c.name, c.id)));
        select.value = tenant; $('tenant-control').hidden = false;
        select.addEventListener('change', () => { try { sessionStorage.setItem('keeper.presentation.tenant', select.value); } catch { /* sin almacenamiento */ } location.reload(); });
      }
    } else tenant = session.tenant_id;
    if ($('group-group')) $('group-group').hidden = !(session.group?.tenants?.length > 1);
  } catch (e) { fail(e); return; }
  if (!isUuid(tenant)) { fail(new Error('La sesión no tiene una empresa válida: vuelve a iniciar sesión.')); return; }
  setTenant(tenant);
  applyNavAccess(); // menu segun los permisos de la cuenta en esta empresa
  if (globalPage && session.is_platform_admin) { $('tenant-name').textContent = ownerName(); $('tenant-name').nextElementSibling.textContent = 'Administración de la plataforma'; }
  else { try { const t = await api('GET', `/tenants/${tenant}`); $('tenant-name').textContent = t.branding?.display_name || t.name; if (t.timezone) tenantZone = t.timezone; } catch { /* el nombre es decorativo */ } }
  try {
    if (page === 'sites') await sites();
    else if (page === 'organization') await organization();
    else if (page === 'assignments') await assignments();
    else if (page === 'accounts') await accounts();
    else if (page === 'roles') await rolesPage();
    else if (page === 'users') await people();
    else if (page === 'member') await personPage();
    else if (page === 'schedules') await schedulesPage();
    else if (page === 'sources') await sourcesPage();
    else if (page === 'group') await groupPage();
    else if (page === 'doors') await doorsPage();
    else if (page === 'absences') await absencesPage();
    else if (page === 'classification') await classificationPage();
    else if (page === 'policies') await policiesPage();
    else if (page === 'audit') await auditPage();
    else if (page === 'client-logs') await logsPage();
    else if (page === 'server-health') await healthPage();
    else if (page === 'panel-settings') await settingsPage();
    else if (page === 'holidays') await holidaysPage();
    else if (page === 'suspicious-apps') await suspiciousPage();
    else if (page === 'tenants') { if (!session.is_platform_admin) throw Object.assign(new Error('Solo plataforma'), { status: 403 }); await tenantsPage(); }
    else if (page === 'plans') { if (!session.is_platform_admin) throw Object.assign(new Error('Solo plataforma'), { status: 403 }); await plansPage(); }
    done();
  } catch (e) {
    if (e.status === 403) { $('page-content').replaceChildren(); empty($('page-content'), 'Sin permiso', 'Tu cuenta no tiene acceso a esta sección. Pide a un administrador el permiso correspondiente.'); $('page-status').textContent = ''; }
    else fail(e);
  }
}
start();
