const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const read = f => fs.readFileSync(path.join(__dirname,f),'utf8');
function fresh() {
  const c = vm.createContext({ window:{} });
  vm.runInContext(read('data.js'),c); vm.runInContext(read('altas-data.js'),c);
  return c.window.ALTAS;
}
const A = fresh();
const draft = (n = 1) => ({ name:`Nueva Persona ${n}`, document:String(7700000000 + n), email:'prueba@example.invalid', company:'AZC Jurídica', site:'Cali · Centro', area:'Litigios', job:'Analista', schedule:A.schedules[0], plan:'cliente', equipment:'later' });
assert.equal(A.followup().length,3);
assert.equal(A.requests.filter(r => r.match === 'document').length,14);
assert.equal(A.requests.filter(r => r.match === 'none').length,6);
const before = A.people.length;
assert(A.create({ ...draft(), name:'' }).errors.length); assert.equal(A.people.length,before);
const noEquipment = A.create(draft()).person;
assert(!A.protection(noEquipment).ok);
assert(A.create(draft()).errors.some(e => e.includes('existe')));
const installed = A.create({ ...draft(2), equipment:'installed', equipmentId:'DESKTOP-N001' }).person;
assert.equal(A.owned(installed.id).length,1); assert(!A.protection(installed).ok);
assert(A.confirmReport('DESKTOP-N001')); assert(A.protection(installed).ok);
assert(A.create({ ...draft(3), equipment:'installed', equipmentId:'DESKTOP-N001' }).errors.length);
const pending = A.create({ ...draft(3), equipment:'pending', equipmentId:'s15' }).person;
assert.equal(A.owned(pending.id).length,0); assert(!A.protection(pending).ok);
assert(A.connectRequest('s15')); assert(!A.connectRequest('s15'));
assert.equal(A.owned(pending.id).length,1); assert(!A.protection(pending).ok);
assert(A.confirmReport('DESKTOP-P015')); assert(A.protection(pending).ok);
const matches = A.requests.filter(r => r.match === 'document').map(r => ({ id:r.id, personId:r.personId }));
assert(A.approve([...matches,{ id:'missing', personId:'p001' }]));
assert(A.requests.filter(r => r.match === 'document').every(r => r.status === 'pending'));
assert.equal(A.approve(matches),''); assert.equal(A.requests.filter(r => r.status === 'approved').length,14);
assert(A.approve(matches));
assert(A.approve([{ id:'s16', personId:'missing' }]));
assert.equal(A.approve([{ id:'s16', personId:'p001' }]),'');
assert(A.replace(installed.id,'DESKTOP-N001','DESKTOP-N002',''));
assert.equal(A.replace(installed.id,'DESKTOP-N001','DESKTOP-N002','Renovación'),'');
assert.equal(A.device('DESKTOP-N001').status,'retiring'); A.confirmReplacement('DESKTOP-N001'); assert.equal(A.device('DESKTOP-N001').status,'retired'); assert.equal(A.device('DESKTOP-N002').personId,installed.id);
assert(!A.protection(installed).ok); assert(A.confirmReport('DESKTOP-N002')); assert(A.protection(installed).ok);
assert(A.startDeparture(installed.id,[{ hostname:'DESKTOP-N002', action:'lock' }],'RETIRAR OTRA',false));
assert.equal(A.startDeparture(installed.id,[{ hostname:'DESKTOP-N002', action:'lock' }],`RETIRAR ${installed.name}`,false),'');
assert(A.finishDeparture(installed.id)); assert.equal(installed.status,'departing');
A.completeAction(installed.id,'DESKTOP-N002',false); assert(A.finishDeparture(installed.id));
A.completeAction(installed.id,'DESKTOP-N002'); assert.equal(A.finishDeparture(installed.id),'');
assert.equal(installed.status,'inactive'); assert.equal(A.device('DESKTOP-N002').status,'retired'); assert(installed.history.length);
assert(!A.confirmReport('DESKTOP-N002'));
const wipe = { hostname:'DESKTOP-P015', action:'wipe', confirmation:'DESKTOP-P015' };
assert(A.startDeparture(pending.id,[wipe],`RETIRAR ${pending.name}`,false));
assert(A.startDeparture(pending.id,[{ ...wipe, confirmation:'OTRO' }],`RETIRAR ${pending.name}`,true));
assert.equal(A.startDeparture(pending.id,[wipe],`RETIRAR ${pending.name}`,true),'');
A.completeAction(pending.id,wipe.hostname); assert.equal(A.finishDeparture(pending.id),'');
const B = fresh(), from = B.person('p001');
const choices = B.owned(from.id).map(d => ({ hostname:d.hostname, action:'assign', to:'p002' }));
assert(B.startDeparture(from.id,choices.slice(1),`RETIRAR ${from.name}`,false));
assert.equal(B.startDeparture(from.id,choices,`RETIRAR ${from.name}`,false),'');
assert.equal(B.finishDeparture(from.id),'');
assert(choices.every(c => B.device(c.hostname).personId === 'p002' && B.device(c.hostname).status === 'active'));
assert(!B.protection(B.person('p002')).ok);
assert.equal(B.approve([{ id:'s20', personId:'p002' }]),'');
assert(B.connectRequest('s20'));
assert(B.replace('p002','DESKTOP-P020','DESKTOP-P020','No debe permitirse'));
assert.equal(B.replace('p002','DESKTOP-0002','DESKTOP-P020','Nuevo equipo desde solicitud'),'');
assert.equal(B.device('DESKTOP-0002').status,'retiring'); B.confirmReplacement('DESKTOP-0002'); assert.equal(B.device('DESKTOP-0002').status,'retired');
assert(!B.device('DESKTOP-P020').report);
const restricted = B.person('p009');
assert(B.startDeparture(restricted.id,[{ hostname:'DESKTOP-0009', action:'wipe', confirmation:'DESKTOP-0009' }],`RETIRAR ${restricted.name}`,true));
const empty = B.person('p008'); assert.equal(B.startDeparture(empty.id,[],`RETIRAR ${empty.name}`,false),''); assert.equal(B.finishDeparture(empty.id),'');
const C = fresh(), header = 'nombre;cedula;correo;empresa;sede;area;cargo;horario;plan';
const row = i => `Importada ${i};${6600000000 + i};;AZC Jurídica;Cali · Centro;Litigios;Analista;${C.schedules[0]};cliente`;
const bad = C.parseCSV(header + '\n' + row(1) + '\n' + row(1) + '\n' + row(2).replace('Litigios','Otra área'));
assert.equal(bad.rows[1].line,3); assert(bad.rows[1].errors.length); assert(bad.rows[2].errors.length);
const partial = C.importRows(bad); assert.equal(partial.people.length,1); assert.equal(partial.rejected.length,2); assert.equal(C.people.length,301);
assert(C.parseCSV('nombre\nAna').error);
assert(C.parseCSV(header + '\n"Sin cerrar').error);
assert.equal(C.parseCSV('\uFEFF' + header + '\r\n' + row(3)).rows.length,1);
const quoted = C.parseCSV(header + '\n' + row(4).replace('Importada 4','"María; Ana ""Prueba"""'));
assert.equal(quoted.rows[0].draft.name,'María; Ana "Prueba"'); assert.equal(quoted.rows[0].errors.length,0);
const commas = C.parseCSV((header + '\n' + row(5)).replaceAll(';',',')); assert.equal(commas.rows[0].errors.length,0);
const bulk = C.parseCSV(header + '\n' + Array.from({length:300},(_,i) => row(i + 101)).join('\n'));
assert.equal(bulk.rows.length,300); assert(bulk.rows.every(r => !r.errors.length));
const saved = C.importRows(bulk); assert.equal(saved.people.length,300); assert.equal(C.people.length,601);
assert(saved.people.every(p => !C.protection(p).ok));
assert.equal(C.importRows(bulk).rejected.length,300); assert.equal(C.people.length,601);
assert.match(C.instructions(saved.people[0]),/SIN VALIDEZ PARA INSTALAR/);
console.log('OK paso 4 modelo: tres caminos de alta, aprobación 14 + asignación, protección confirmada, reemplazo, baja con fallo/reintento/borrado/reasignación y CSV de 300 sin duplicados.');

// DOM mínimo de pruebas, sin motor de navegador ni cálculo de estilos.
const decode = s => s.replace(/&quot;/g,'"').replace(/&#39;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
class Node {
  constructor(tag = 'div', attrs = {}) { this.tag = tag; this.attrs = attrs; this.children = []; this.dataset = {}; this.hidden = 'hidden' in attrs; this.disabled = 'disabled' in attrs; this.checked = 'checked' in attrs; this.textContent = ''; for (const [k,v] of Object.entries(attrs)) if (k.startsWith('data-')) this.dataset[k.slice(5).replace(/-([a-z])/g,(_,c) => c.toUpperCase())] = v; this._value = attrs.value; }
  set innerHTML(html) {
    this.html = html; this.children = []; const stack = [this];
    for (const m of html.matchAll(/<(\/?)([\w-]+)([^>]*)>/g)) {
      const tag = m[2].toLowerCase();
      if (m[1]) { const index = stack.findLastIndex(n => n.tag === tag); if (index > 0) stack.length = index; continue; }
      const attrs = {}; for (const a of m[3].matchAll(/([\w-]+)(?:="([^"]*)")?/g)) attrs[a[1]] = decode(a[2] ?? '');
      const n = new Node(tag,attrs); stack.at(-1).append(n);
      if (!['input','br','img','meta','link','hr'].includes(tag)) stack.push(n);
    }
  }
  get innerHTML() { return this.html || ''; }
  append(n) { n.parent = this; this.children.push(n); }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(n => n !== this); }
  get value() { if (this._value !== undefined) return this._value; if (this.tag === 'select') { const options = this.querySelectorAll('option'); return (options.find(n => 'selected' in n.attrs) || options[0])?.attrs.value || ''; } return ''; }
  set value(value) { this._value = value; }
  querySelectorAll(selector) {
    const match = n => selector.startsWith('#') ? n.attrs.id === selector.slice(1) : selector.startsWith('[') ? (() => { const m = selector.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/); return m && m[1] in n.attrs && (m[2] === undefined || n.attrs[m[1]] === m[2]); })() : n.tag === selector;
    return this.children.flatMap(n => [...(match(n) ? [n] : []), ...n.querySelectorAll(selector)]);
  }
  querySelector(s) { return this.querySelectorAll(s)[0] || null; }
  reportValidity() { return true; }
  focus() {}
  addEventListener(name,fn) { this['on' + name] = fn; }
  showModal() {}
  close() { this.onclose?.(); }
  click() { this.onclick?.(); }
}
function ui(search = '') {
  const body = new Node('body', { 'data-page':'personas-equipos' }), root = new Node('main',{ id:'altas-content' }); body.append(new Node('header',{ id:'header' })); body.append(root);
  const listeners = {}, location = { search, hash:'' };
  const window = { addEventListener:(name,fn) => { listeners[name] = fn; } };
  const context = vm.createContext({ window, location, URLSearchParams, URL, Blob, setTimeout:fn => fn(), FormData:class { constructor(form) { this.items = form.querySelectorAll('[name]').filter(n => !n.disabled && (n.attrs.type !== 'checkbox' || n.checked)).map(n => [n.attrs.name,n.value]); } [Symbol.iterator]() { return this.items[Symbol.iterator](); } }, document:{ body, getElementById:id => body.querySelector('#' + id), createElement:tag => new Node(tag) } });
  for (const f of ['data.js','altas-data.js','app.js','altas.js']) vm.runInContext(read(f),context,{ filename:f });
  const route = hash => { location.hash = hash; listeners.hashchange(); };
  const get = id => { const n = body.querySelector('#' + id); assert(n,`Elemento ausente: ${id}`); return n; };
  const submit = id => get(id).onsubmit({ preventDefault(){}, target:get(id) });
  return { body,root,route,get,submit,A:window.ALTAS,location,listeners };
}
const U = ui(); assert(U.root.innerHTML.includes('aún sin equipo protegido'));
U.route('#nueva');
for (const [name,value] of [['name','Persona UI'],['document','5500000001']]) U.root.querySelector(`[name="${name}"]`).value = value;
U.submit('new-person-form'); U.root.querySelector('[name="job"]').value = 'Analista'; U.submit('new-person-form');
assert(U.get('equipment-choice')); U.submit('new-person-form'); U.get('new-confirm').checked = true; U.submit('new-person-form');
assert.equal(U.A.people.length,301); U.listeners.hashchange(); assert(U.root.innerHTML.includes('No protegido'));
U.route('#solicitudes'); U.get('review-matches').click(); assert(U.root.innerHTML.includes('Aprobar los 14')); U.submit('approve-form'); assert.equal(U.A.requests.filter(r => r.status === 'approved').length,14);
U.route('#reemplazar/p001'); U.get('old-device').value = 'DESKTOP-0001'; U.get('new-device').value = 'DESKTOP-N001'; U.get('replace-reason').value = 'Cambio UI'; U.submit('replace-form');
let modal = U.body.querySelector('dialog'); assert(modal); modal.querySelector('form').onsubmit({ preventDefault(){} }); U.listeners.hashchange(); assert.equal(U.A.device('DESKTOP-0001').status,'retiring'); U.A.confirmReplacement('DESKTOP-0001');
U.route('#baja/p001'); U.get('departure-confirmation').value = 'RETIRAR ' + U.A.person('p001').name; U.get('departure-reason').value = 'Salida'; U.submit('offboard-form');
modal = U.body.querySelector('dialog'); modal.querySelector('form').onsubmit({ preventDefault(){} }); U.listeners.hashchange(); assert(U.get('finish-departure').disabled);
for (const n of [...U.root.querySelectorAll('[data-complete]')]) n.click(); assert(!U.get('finish-departure').disabled); U.get('finish-departure').click(); assert.equal(U.A.person('p001').status,'inactive');
U.route('#importar'); U.get('csv-example').click(); assert(U.root.querySelector('#csv-preview').innerHTML.includes('con errores'));
U.get('csv-300').click(); U.submit('import-confirm'); assert.equal(U.A.people.length,588);
assert(ui('?role=supervisor').root.innerHTML.includes('requiere administrar'));
assert(ui('?role=supervisor-orders').root.innerHTML.includes('requiere administrar'));
assert(ui('?scenario=error').root.innerHTML.includes('No pudimos cargar'));
assert(ui('?scenario=empty').root.innerHTML.includes('No hay personas'));
console.log('OK paso 4 interfaz sin navegador: eventos del asistente, lote, reemplazo, baja, CSV, roles y estados vacío/error. DOM mínimo; no certifica geometría, foco nativo ni validación del navegador.');

const T = fresh();
assert(T.people.filter(p => T.protection(p).ok).length > 240);
assert.equal(T.protection(T.person('p003')).label,'Sin confirmar');
assert.equal(T.protection(T.person('p005')).label,'No protegido');
assert.equal(T.protection(T.person('p008')).label,'No protegido');
const td = T.owned('p002')[0]; td.state = 'connected'; td.reportedAt = '2026-10-28T20:29:00Z'; assert.equal(T.protection(T.person('p002')).label,'Sin confirmar');
td.reportedAt = '2026-10-28T20:30:00Z'; assert.equal(T.protection(T.person('p002')).label,'Protegido');
assert.equal(T.replace('p003','DESKTOP-0003','DESKTOP-N001','Renovación'), '');
assert.equal(T.device('DESKTOP-0003').status,'retiring'); T.confirmReplacement('DESKTOP-0003',false); assert.equal(T.device('DESKTOP-0003').status,'retiring'); T.confirmReplacement('DESKTOP-0003'); assert.equal(T.device('DESKTOP-0003').status,'retired');
const retiring = T.person('p002'); T.startDeparture(retiring.id,[{hostname:td.hostname,action:'lock'}],`RETIRAR ${retiring.name}`,false); assert.equal(retiring.panelAccess,false); assert.equal(td.status,'active');
console.log('OK cierre 4: tres estados, umbral 24 h, mayoría protegida, CSV parcial, bloqueo antes de retiro y revocación inmediata.');

module.exports = { Node };
