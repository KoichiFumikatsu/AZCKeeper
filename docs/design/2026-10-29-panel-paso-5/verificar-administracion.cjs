const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const read=f=>fs.readFileSync(path.join(__dirname,f),'utf8');
const {Node}=require('./verificar-altas.cjs');
function model(storage=new Map()) {
  const context=vm.createContext({window:{},structuredClone,sessionStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v)}});
  for(const f of ['data.js','administracion-data.js'])vm.runInContext(read(f),context,{filename:f});
  return {M:context.window.ADMIN5,context,storage};
}
const {M,storage}=model(),c=M.client('azc');
assert.equal(c.people.length,300);assert.equal(c.devices.length,300);assert(!c.features.includes('ia'));assert(!c.aiEnabled);
assert.equal(M.catalog.length,24);
const location=M.impact(c,[...c.features,'ubicacion']);
assert.equal(location.changed.length,142);assert.equal(location.devices.length,142);assert.equal(location.incompatible.length,142);
assert.equal(M.applyContract(c,[...c.features,'ubicacion','ia'],c.defaultPlan,location),'');assert(c.features.includes('ia'));assert(!c.aiEnabled);
assert(M.applyContract(c,c.features,c.defaultPlan,location),'Una vista previa caducada no guarda');
c.aiEnabled=true;const removal=M.impact(c,c.features.filter(f=>f!=='ia'));M.applyContract(c,c.features.filter(f=>f!=='ia'),c.defaultPlan,removal);assert(!c.aiEnabled);
const area=M.impact(c,c.features,c.defaultPlan,'Litigios','observacion',false);
assert.equal(area.controls.length,3);assert(area.skipped>0);const own=c.people.find(p=>p.area==='Litigios'&&p.ownPlan==='movilidad');
assert.equal(M.applyArea(c,'Litigios','observacion',false,area),'');assert.equal(own.ownPlan,'movilidad');
const supervisor=c.access[0],inside=c.people.find(p=>p.company==='AZC Jurídica'&&p.area==='Litigios');
assert(M.permission(c,supervisor,'read',inside));assert(!M.permission(c,supervisor,'wipe',inside));assert(!M.permission(c,supervisor,'lock',inside),'Plan Observación no concede órdenes');
const fresh=model().M,fc=fresh.client('azc'),fa=fc.access[0],fp=fc.people[0];assert(fresh.permission(fc,fa,'lock',fp));
assert(fresh.setAccess(fc,fa.id,{...fa,orders:false},'supervisor'));assert(fa.orders);
assert.equal(fresh.setAccess(fc,fa.id,{...fa,orders:false}),'');assert(!fresh.permission(fc,fa,'lock',fp));
assert.equal(fresh.setAccess(fc,fa.id,{...fa,orders:true,site:'Bogotá · Norte'}),'');assert(!fresh.permission(fc,fa,'read',fp));
assert(fresh.setAccess(fc,fa.id,{...fa,company:'',area:'',site:'',full:false}));
assert(M.parseStructure('empresa;sede;area;cargo\nEmpresa;Sede;Área;Cargo').rows.length===1);
assert(M.parseStructure('empresa;sede;area;cargo\nEmpresa;;Área;Cargo').errors.length);
assert(M.parseStructure('empresa;sede;area;cargo\nEmpresa;Sede;Área;Cargo\nEmpresa;Sede;Área;Cargo').rows.length===1);
const draft={name:'Cliente Ficticio',admin:'Ana Prueba',email:'ana@example.invalid',features:['web','usb','instalaciones'],defaultPlan:'oficina',structure:M.parseStructure('empresa;sede;area;cargo\nEmpresa Ficticia;Cali;Legal;Analista').rows};
const made=M.create(draft);assert(made.client);assert.equal(made.client.people.length,0);assert.equal(made.client.access[0].role,'it');assert(M.create(draft).error);
assert(model(storage).M.client(made.client.id),'Los cambios de administración sobreviven a cambiar de página cuando hay almacenamiento');

function ui(search='',page='clientes') {
  const body=new Node('body',{'data-page':page}),root=new Node('main',{id:'management-content'});body.append(root);
  const listeners={},location={search,hash:''},window={addEventListener:(k,fn)=>listeners[k]=fn};
  const context=vm.createContext({window,location,URLSearchParams,structuredClone,document:{body,getElementById:id=>body.querySelector('#'+id),createElement:tag=>new Node(tag)}});
  for(const f of ['data.js','administracion-data.js','administracion.js'])vm.runInContext(read(f),context,{filename:f});
  const get=id=>{const n=body.querySelector('#'+id);assert(n,'Elemento ausente: '+id);return n;};
  const route=hash=>{location.hash=hash;listeners.hashchange();};
  const submit=id=>get(id).onsubmit({preventDefault(){}});
  const confirm=()=>{const d=body.querySelector('dialog');assert(d);d.querySelector('form').onsubmit({preventDefault(){}});};
  return {body,root,get,route,submit,confirm,M:window.ADMIN5,location,listeners};
}
const U=ui();assert(U.root.innerHTML.includes('Nuevo cliente'));U.route('#ficha');assert(U.root.innerHTML.includes('No contratadas'));U.route('#contrato');
U.root.querySelector('[value="ubicacion"]').checked=true;U.submit('contract-form');assert(U.body.querySelector('dialog').innerHTML.includes('142 equipos'));U.confirm();U.listeners.hashchange();assert(U.M.client('azc').features.includes('ubicacion'));
U.route('#planes');U.get('plan-area').value='Litigios';U.get('area-plan').value='observacion';U.submit('area-plan-form');assert(U.body.querySelector('dialog').innerHTML.includes('3 protecciones'));U.confirm();
U.route('#accesos');U.root.querySelector('[data-access="andrea"]').click();U.get('access-orders').checked=false;U.confirm();assert(!U.M.client('azc').access[0].orders);
U.route('#ver/andrea');assert(U.root.innerHTML.includes('Solo consulta de actividad'));assert(U.root.innerHTML.includes('Borrado remoto: nunca'));
U.route('#nuevo');for(const [k,v] of [['name','Prueba del asistente'],['admin','Ana Prueba'],['email','ana@example.invalid']])U.root.querySelector(`[name="${k}"]`).value=v;
U.submit('new-client-form');U.get('wizard-structure').value='empresa;sede;area;cargo\nEmpresa de ejemplo;Cali · Centro;Litigios;Analista';U.submit('new-client-form');U.submit('new-client-form');U.submit('new-client-form');U.listeners.hashchange();assert(U.root.innerHTML.includes('Listo para inscribir'));
const next=U.root.querySelectorAll('a').find(a=>a.attrs.href.includes('context='));assert(next);const search=new URL(next.attrs.href,'file:///demo/').search;
const cc=vm.createContext({window:{},location:{search},URLSearchParams});for(const f of ['data.js','altas-data.js'])vm.runInContext(read(f),cc);
assert.equal(cc.window.DEMO.client,'Prueba del asistente');assert.equal(cc.window.ALTAS.people.length,0);assert.equal(cc.window.ALTAS.requests.length,0);
const newP=cc.window.ALTAS.create({name:'Persona nueva',document:'555123456',email:'',company:'Empresa de ejemplo',site:'Cali · Centro',area:'Litigios',job:'Analista',schedule:cc.window.ALTAS.schedules[0],plan:'cliente',equipment:'later'});assert(newP.person);assert.equal(newP.person.client,'Prueba del asistente');
const T=ui();T.route('#ajustes');T.get('it-password').value='clave-ficticia-de-prueba';T.get('new-pin').value='12345678';T.get('sac').checked=true;T.submit('technical-form');T.confirm();assert(T.M.client('azc').technical.sac);assert(!JSON.stringify(T.M.state).includes('clave-ficticia-de-prueba'));assert(!JSON.stringify(T.M.state).includes('12345678'));
T.route('#ia');assert(T.root.innerHTML.includes('No contratado'));
assert(ui('?role=supervisor').root.innerHTML.includes('Sin acceso'));assert(ui('?scenario=error').root.innerHTML.includes('No pudimos'));assert(ui('?scenario=empty').root.innerHTML.includes('No hay clientes'));
for(const page of ['planes','accesos','ajustes-tecnicos'])assert(ui('',page).root.innerHTML.length>1000);

let links=0;
for(const f of fs.readdirSync(__dirname).filter(f=>/\.(html|js|css)$/.test(f))) {
  const source=read(f);if(f.endsWith('.js'))new vm.Script(source,{filename:f});
  assert(!/[\p{L}]\?[\p{L}]|\uFFFD/u.test(source.replace(/\.html\?[^\s"'<>]+/g,".html")),'Codificación dañada: '+f);
  for(const m of source.matchAll(/(?:href|src)="([a-z][a-z0-9-]*\.(?:html|js|css))(?:[?#][^"<>]*)?"/g)){assert(fs.existsSync(path.join(__dirname,m[1])),`${f}: enlace ausente ${m[1]}`);links++;}
}
for(const f of ['administracion.js','administracion-data.js'])assert(!/\b(?:fetch|XMLHttpRequest|WebSocket)\s*\(/.test(read(f)));
assert(!/Se diseña en el paso/.test(read('app.js')));
const manifest=JSON.parse(read('ORIGEN-PASO-4.json'));
for(const [f,hash] of Object.entries(manifest.sha256))assert.equal(crypto.createHash('sha256').update(fs.readFileSync(path.resolve(__dirname,manifest.origen,f))).digest('hex'),hash,'El origen cerrado cambió: '+f);
console.log(`OK paso 5: contrato y plan efectivos, impacto calculado, control de concurrencia, alcances y revocación, IA apagada, asistente completo enlazado al paso 4 con contexto, ajustes sin secretos, ${links} enlaces locales y origen SHA-256. Sin navegador ni geometría certificada.`);
