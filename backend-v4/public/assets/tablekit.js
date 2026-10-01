// Mejora todas las tablas del panel sin tocar cada pantalla: busqueda, orden por columna (clic en el encabezado),
// paginacion de 50 filas y, si la tabla tiene fechas, la mas reciente primero. Trabaja sobre el DOM ya dibujado, asi
// sirve a las cuatro formas de dibujar tablas que tiene el panel. Las tablas con buscador propio (data-kit="own")
// solo reciben orden y paginacion.
const PAGE = 50;
const MONTHS = { ene: 0, feb: 1, mar: 2, abr: 3, may: 4, jun: 5, jul: 6, ago: 7, sep: 8, sept: 8, oct: 9, nov: 10, dic: 11 };
const DATE_HEADER = /fecha|^d[ií]a|cu[aá]ndo|[uú]ltim|registrad|cread|publicad|inicio|desde|ocurri|hora|vez|actualiz/i;

// Convierte el texto de una celda en algo ordenable: fecha (ms), numero o texto.
function key(text) {
  const t = text.trim();
  let m = t.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (m) return { kind: 'date', v: Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)) };
  m = t.toLowerCase().match(/^(\d{1,2})\s+(?:de\s+)?([a-zñ]+)\.?\s+(?:de\s+)?(\d{4})(?:,?\s+(\d{1,2}):(\d{2})\s*([ap])?\.?\s*m?\.?)?/);
  if (m && m[2].slice(0, 4) in MONTHS || (m && m[2].slice(0, 3) in MONTHS)) {
    const month = MONTHS[m[2].slice(0, 4)] ?? MONTHS[m[2].slice(0, 3)];
    let h = +(m[4] || 0); if (m[6] === 'p' && h < 12) h += 12; if (m[6] === 'a' && h === 12) h = 0;
    return { kind: 'date', v: new Date(+m[3], month, +m[1], h, +(m[5] || 0)).getTime() };
  }
  const n = t.replace(/\s|%|h$|\/100$/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
  if (/^-?\d+(\.\d+)?$/.test(n)) return { kind: 'num', v: parseFloat(n) };
  return { kind: 'text', v: t.toLocaleLowerCase('es') };
}
function compare(a, b) {
  if (a.kind !== b.kind) return a.kind === 'text' ? 1 : b.kind === 'text' ? -1 : 0;
  return a.kind === 'text' ? a.v.localeCompare(b.v, 'es') : a.v - b.v;
}

function enhance(table) {
  const body = table.tBodies[0]; const head = table.tHead;
  if (!body || !head || table.dataset.kit === 'done') return;
  const rows = [...body.rows];
  if (rows.length < 2 || rows.some(r => r.cells.length !== head.rows[0].cells.length)) return;
  table.dataset.kit = 'done';
  const state = { col: -1, dir: -1, page: 0, term: '' };
  const headers = [...head.rows[0].cells];
  // Orden por defecto: la primera columna de fecha, mas reciente primero.
  const dateCol = headers.findIndex((th, i) => DATE_HEADER.test(th.textContent) && rows.slice(0, 5).every(r => key(r.cells[i]?.textContent || '').kind === 'date' || !r.cells[i]?.textContent.trim()));
  if (dateCol >= 0) { state.col = dateCol; state.dir = -1; }
  const own = table.closest('[data-kit="own"]') || table.parentElement?.previousElementSibling?.classList?.contains('toolbar');
  const tools = document.createElement('div'); tools.className = 'tablekit';
  let search = null;
  if (!own && rows.length > 5) {
    search = document.createElement('input'); search.type = 'search'; search.placeholder = 'Buscar en la tabla'; search.setAttribute('aria-label', 'Buscar en la tabla');
    tools.append(search);
  }
  const pager = document.createElement('div'); pager.className = 'tablekit-pager';
  const prev = document.createElement('button'), next = document.createElement('button'), status = document.createElement('span');
  prev.type = next.type = 'button'; prev.textContent = 'Anterior'; next.textContent = 'Siguiente'; status.setAttribute('role', 'status');
  pager.append(prev, status, next);
  const region = table.closest('.table-region, .table-wrap') || table;
  if (tools.childElementCount) region.before(tools);
  region.after(pager);
  const keys = new Map(rows.map(r => [r, [...r.cells].map(c => key(c.textContent || ''))]));
  const render = () => {
    let shown = rows;
    if (state.term) shown = rows.filter(r => r.textContent.toLocaleLowerCase('es').includes(state.term));
    if (state.col >= 0) shown = [...shown].sort((a, b) => state.dir * compare(keys.get(a)[state.col], keys.get(b)[state.col]));
    const pages = Math.max(1, Math.ceil(shown.length / PAGE)); state.page = Math.min(state.page, pages - 1);
    const slice = shown.slice(state.page * PAGE, state.page * PAGE + PAGE);
    table.dataset.kitBusy = '1';
    body.replaceChildren(...slice);
    delete table.dataset.kitBusy;
    pager.hidden = shown.length <= PAGE;
    status.textContent = shown.length ? `${state.page * PAGE + 1}–${state.page * PAGE + slice.length} de ${shown.length}` : 'Sin resultados';
    prev.disabled = state.page === 0; next.disabled = state.page >= pages - 1;
    headers.forEach((th, i) => th.setAttribute('aria-sort', i === state.col ? (state.dir > 0 ? 'ascending' : 'descending') : 'none'));
  };
  headers.forEach((th, i) => {
    if (!th.textContent.trim()) return;
    th.classList.add('sortable'); th.tabIndex = 0; th.title = 'Ordenar';
    const sort = () => { state.dir = state.col === i ? -state.dir : (DATE_HEADER.test(th.textContent) ? -1 : 1); state.col = i; state.page = 0; render(); };
    th.addEventListener('click', sort); th.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); } });
  });
  search?.addEventListener('input', () => { state.term = search.value.trim().toLocaleLowerCase('es'); state.page = 0; render(); });
  prev.addEventListener('click', () => { state.page--; render(); });
  next.addEventListener('click', () => { state.page++; render(); });
  render();
}

// Las pantallas agregan filas despues de crear la tabla: se espera a que el DOM se calme antes de mejorarla.
let timer = null;
const scan = () => { timer = null; document.querySelectorAll('table:not([data-kit="done"])').forEach(enhance); };
new MutationObserver(() => { if (!timer) timer = setTimeout(scan, 120); }).observe(document.documentElement, { childList: true, subtree: true });
