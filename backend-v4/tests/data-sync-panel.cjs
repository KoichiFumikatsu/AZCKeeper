const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { JSDOM } = require(process.env.JSDOM_MODULE || 'C:/Users/FumiWork/proyectos/portal-azc/node_modules/jsdom');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/k3.js'), 'utf8');
const code = source.slice(source.indexOf('const DOMAIN_LABELS ='), source.indexOf('// ---- Tablero del grupo'));
let checks = 0;
const check = (value, message) => { assert.ok(value, message); checks++; };
async function fixture(manage = true, sources = []) {
  const dom = new JSDOM('<main id="page-content"></main><button id="page-action"></button><p id="page-status"></p><p id="page-error"></p>', { runScripts: 'outside-only' });
  const w = dom.window, doc = w.document;
  const state = { sources, confirmed: false, dialogs: [], writes: [], failure: null, pending: [
    { id: 'unit', reason: 'matched_name', entity_type: 'org_unit', domain: 'sedes', label: 'Sede Centro', local_name: 'SEDE CENTRO' },
    { id: 'schedule', reason: 'matched_name', entity_type: 'schedule', domain: 'horarios', label: 'Jornada habitual', local_name: 'Jornada habitual' },
    { id: 'similar', reason: 'similar_name', entity_type: 'org_unit', domain: 'sedes', label: 'Centro Empresaria', local_name: 'Centro Empresarial' },
    { id: 'orphan', reason: 'orphan', entity_type: 'orphan', domain: 'personas', label: 'Persona local' },
    { id: 'document', reason: 'document_change', entity_type: 'document', domain: 'personas', label: 'Persona vinculada' },
  ] };
  const el = (tag, text, cls) => { const e = doc.createElement(tag); if (text !== undefined) e.textContent = text; if (cls) e.className = cls; return e; };
  Object.assign(w, {
    $: id => doc.getElementById(id), el, readSession: () => ({ is_platform_admin: manage }), optional: p => p,
    badge: text => el('span', text), when: value => value || '—',
    surface: title => { const e = el('section'); e.append(el('h2', title)); doc.getElementById('page-content').append(e); return e; },
    empty: (node, title, text) => node.append(el('p', title), el('p', text)),
    table: (node, title, columns, rows) => { const t = el('table'); for (const row of rows) { const tr = el('tr'); tr.dataset.id = row.id; for (const [, render] of columns) { const td = el('td'), value = render(row); td.append(value instanceof w.Node ? value : String(value)); tr.append(td); } t.append(tr); } node.append(t); },
    confirmBox: async options => { state.dialogs.push(options); return state.confirmed; },
    done: () => {}, fail: e => { doc.getElementById('page-error').textContent = e.message; },
    api: async (method, route, options) => {
      if (method === 'GET' && state.reloadFailure && state.writes.length) throw Object.assign(new Error('Reload failed'), { status: 403 });
      if (method === 'GET') return { data: route === '/sync-items/pending' ? state.pending : route === '/data-sources' ? state.sources : [] };
      state.writes.push({ method, route, body: options?.body });
      if (state.writeWait) await state.writeWait;
      if (state.failure) throw Object.assign(new Error('Conflict'), { status: state.failure, problem: { detail: state.failureDetail } });
      if (method === 'DELETE') { state.sources = state.sources.filter(s => route !== `/data-sources/${s.id}`); return null; }
      if (route === '/sync-items/resolve-matched') { state.pending = state.pending.filter(i => !options.body.ids.includes(i.id)); return { resolved: options.body.ids.length }; }
      state.pending = state.pending.filter(i => route !== `/sync-items/${i.id}/resolve`); return {};
    },
  });
  vm.runInContext(code, dom.getInternalVMContext());
  await w.sourcesPage();
  const button = text => [...doc.querySelectorAll('button')].find(b => b.textContent === text);
  const settle = async () => { await new Promise(resolve => setImmediate(resolve)); };
  return { state, doc, button, settle, close: () => w.close() };
}
(async () => {
  const c = await fixture();
  try {
    check(!!c.button('Vincular todas las de mismo nombre (2)'), 'batch includes only exact unit and schedule');
    check(c.doc.body.textContent.includes('Centro Empresaria') && c.doc.body.textContent.includes('Centro Empresarial'), 'both similar names visible');
    check(c.doc.body.textContent.includes('La fuente trae un nombre parecido a uno de Keeper. ¿Es lo mismo?'), 'plain Spanish similar prompt');
    check(c.doc.querySelector('[data-id="orphan"]').children[2].textContent === '—' && c.doc.querySelector('[data-id="orphan"]').children[3].textContent === 'Persona local', 'orphan name is correctly shown as local');
    c.button('Vincular todas las de mismo nombre (2)').click(); await c.settle();
    check(c.state.dialogs[0].message.includes('2 unidades y horarios'), 'confirmation shows count');
    check(c.state.writes.length === 0, 'cancelled batch sends nothing');
    c.state.confirmed = true; c.state.failure = 409;
    c.button('Vincular todas las de mismo nombre (2)').click(); await c.settle();
    check(c.doc.getElementById('page-error').textContent.includes('No se vinculó ningún elemento'), 'atomic conflict explained');
    check(!c.button('Vincular todas las de mismo nombre (2)').disabled, 'failed batch allows retry');
    c.state.failure = null;
    c.button('Vincular todas las de mismo nombre (2)').click(); await c.settle();
    check(JSON.stringify(c.state.writes.at(-1).body.ids) === JSON.stringify(['unit', 'schedule']), 'only confirmed eligible IDs sent');
    check(c.state.pending.length === 3, 'similar, orphan and document remain individual');
    check(c.doc.getElementById('page-status').textContent === 'Se vincularon 2 unidades y horarios.', 'real count displayed');
    c.doc.querySelector('[data-id="similar"] button').click(); await c.settle();
    check(c.state.writes.at(-1).route === '/sync-items/similar/resolve' && c.state.writes.at(-1).body.approve, 'similar approval reuses individual endpoint');
    c.state.confirmed = false; const writes = c.state.writes.length;
    c.button('Retirarla').click(); await c.settle();
    check(c.state.writes.length === writes && c.state.dialogs.at(-1).title === 'Retirar persona', 'orphan retirement still requires own confirmation');
    c.state.confirmed = true; c.button('Retirarla').click(); await c.settle();
    check(c.state.writes.at(-1).route === '/sync-items/orphan/resolve', 'orphan retired individually');
  } finally { c.close(); }
  const readOnly = await fixture(false);
  try { check(!readOnly.button('Vincular todas las de mismo nombre (2)') && !readOnly.button('Retirarla'), 'read-only user has no decision buttons'); }
  finally { readOnly.close(); }
  const reloadFailure = await fixture();
  try {
    reloadFailure.state.confirmed = true; reloadFailure.state.reloadFailure = true;
    reloadFailure.button('Vincular todas las de mismo nombre (2)').click(); await reloadFailure.settle();
    check(reloadFailure.doc.getElementById('page-error').textContent.includes('Se vincularon 2'), 'successful mutation remains reported when refresh fails');
    check(reloadFailure.button('Vincular todas las de mismo nombre (2)').disabled, 'already applied batch is not retried after refresh failure');
  } finally { reloadFailure.close(); }
  const discarded = { id: 'discarded', name: 'AZC One v1 (puente)', domains: [], mode: 'simulate', last_success_at: null };
  const sources = [discarded,
    { ...discarded, id: 'owner', domains: ['sedes'] },
    { ...discarded, id: 'live', mode: 'live' },
    { ...discarded, id: 'successful', last_success_at: '2026-11-04T12:00:00Z' },
  ];
  const deletion = await fixture(true, sources);
  try {
    const hasDelete = id => [...deletion.doc.querySelectorAll(`[data-id="${id}"] button`)].some(b => b.textContent === 'Eliminar');
    check(hasDelete('discarded'), 'discarded simulated source can be deleted');
    for (const id of ['owner', 'live', 'successful']) check(!hasDelete(id), `delete hidden for ${id} source`);
    deletion.button('Eliminar').click(); await deletion.settle();
    const dialog = deletion.state.dialogs.at(-1);
    check(dialog.title === 'Eliminar fuente' && dialog.danger && dialog.okText === 'Eliminar', 'danger confirmation for deletion');
    check(dialog.message.includes(discarded.name) && dialog.message.includes('historial de vueltas') && dialog.message.includes('No se borrarán personas'), 'confirmation names source and explains history and preserved data');
    check(deletion.state.writes.length === 0, 'cancelled deletion sends nothing');
    deletion.state.confirmed = true; deletion.state.failure = 409; deletion.state.failureDetail = 'source_in_use';
    deletion.button('Eliminar').click(); await deletion.settle();
    check(deletion.doc.getElementById('page-error').textContent.includes('No se puede eliminar la fuente'), 'source_in_use clearly explained');
    check(!deletion.button('Eliminar').disabled && hasDelete('discarded'), 'failed deletion restores button and preserves row');
    deletion.state.failure = null;
    let finish;
    deletion.state.writeWait = new Promise(resolve => { finish = resolve; });
    deletion.button('Eliminar').click(); await deletion.settle();
    const busyButton = deletion.button('Eliminando…');
    check(busyButton?.getAttribute('aria-busy') === 'true' && [...busyButton.parentElement.querySelectorAll('button')].every(b => b.disabled), 'delete uses busy text and disables action group');
    finish(); await deletion.settle();
    check(deletion.state.writes.at(-1).method === 'DELETE' && deletion.state.writes.at(-1).route === '/data-sources/discarded', 'delete targets selected source');
    check(!deletion.doc.querySelector('[data-id="discarded"]') && deletion.state.sources.length === 3, 'deleted source removed after refresh');
    check(deletion.doc.getElementById('page-status').textContent.includes('Se eliminó la fuente'), 'successful deletion displayed');
  } finally { deletion.close(); }
  const readOnlySource = await fixture(false, [discarded]);
  try { check(!readOnlySource.button('Eliminar'), 'read-only user cannot delete eligible source'); }
  finally { readOnlySource.close(); }
  const deleteReloadFailure = await fixture(true, [discarded]);
  try {
    deleteReloadFailure.state.confirmed = true; deleteReloadFailure.state.reloadFailure = true;
    deleteReloadFailure.button('Eliminar').click(); await deleteReloadFailure.settle();
    check(deleteReloadFailure.doc.getElementById('page-error').textContent.includes('La fuente se eliminó'), 'deletion remains reported when refresh fails');
  } finally { deleteReloadFailure.close(); }
  console.log(`PASS sync panel DOM (${checks} assertions)`);
})().catch(e => { console.error(e); process.exitCode = 1; });
