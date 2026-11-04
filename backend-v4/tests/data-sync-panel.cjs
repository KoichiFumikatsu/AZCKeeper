const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { JSDOM } = require(process.env.JSDOM_MODULE || 'C:/Users/FumiWork/proyectos/portal-azc/node_modules/jsdom');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/k3.js'), 'utf8');
const code = source.slice(source.indexOf('const DOMAIN_LABELS ='), source.indexOf('// ---- Tablero del grupo'));
let checks = 0;
const check = (value, message) => { assert.ok(value, message); checks++; };
async function fixture(manage = true) {
  const dom = new JSDOM('<main id="page-content"></main><button id="page-action"></button><p id="page-status"></p><p id="page-error"></p>', { runScripts: 'outside-only' });
  const w = dom.window, doc = w.document;
  const state = { confirmed: false, dialogs: [], writes: [], failure: null, pending: [
    { id: 'unit', reason: 'matched_name', entity_type: 'org_unit', domain: 'sedes', label: 'Sede Centro', local_name: 'SEDE CENTRO' },
    { id: 'schedule', reason: 'matched_name', entity_type: 'schedule', domain: 'horarios', label: 'Jornada habitual', local_name: 'Jornada habitual' },
    { id: 'similar', reason: 'similar_name', entity_type: 'org_unit', domain: 'sedes', label: 'Centro Empresaria', local_name: 'Centro Empresarial' },
    { id: 'orphan', reason: 'orphan', entity_type: 'orphan', domain: 'personas', label: 'Persona local' },
    { id: 'document', reason: 'document_change', entity_type: 'document', domain: 'personas', label: 'Persona vinculada' },
  ] };
  const el = (tag, text, cls) => { const e = doc.createElement(tag); if (text !== undefined) e.textContent = text; if (cls) e.className = cls; return e; };
  Object.assign(w, {
    $: id => doc.getElementById(id), el, readSession: () => ({ is_platform_admin: manage }), optional: p => p,
    surface: title => { const e = el('section'); e.append(el('h2', title)); doc.getElementById('page-content').append(e); return e; },
    empty: (node, title, text) => node.append(el('p', title), el('p', text)),
    table: (node, title, columns, rows) => { const t = el('table'); for (const row of rows) { const tr = el('tr'); tr.dataset.id = row.id; for (const [, render] of columns) { const td = el('td'), value = render(row); td.append(value instanceof w.Node ? value : String(value)); tr.append(td); } t.append(tr); } node.append(t); },
    confirmBox: async options => { state.dialogs.push(options); return state.confirmed; },
    done: () => {}, fail: e => { doc.getElementById('page-error').textContent = e.message; },
    api: async (method, route, options) => {
      if (method === 'GET' && state.reloadFailure && state.writes.length) throw Object.assign(new Error('Reload failed'), { status: 403 });
      if (method === 'GET') return { data: route === '/sync-items/pending' ? state.pending : [] };
      state.writes.push({ route, body: options.body });
      if (state.failure) throw Object.assign(new Error('Conflict'), { status: state.failure });
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
  console.log(`PASS sync panel DOM (${checks} assertions)`);
})().catch(e => { console.error(e); process.exitCode = 1; });
