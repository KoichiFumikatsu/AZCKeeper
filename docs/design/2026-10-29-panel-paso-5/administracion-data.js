/* Estado sintético de administración; nunca llama servicios. */
window.ADMIN5 = (() => {
  'use strict';
  const D = window.DEMO;
  const catalog = [
    ['productividad','Actividad y productividad','person'],['sitios','Sitios visitados','person'],['clasificacion','Clasificación de aplicaciones','client'],
    ['presencia','Presencia y puntualidad','person'],['festivos','Festivos','client'],['ausencias','Ausencias','client'],['puertas','Entradas por puerta','person'],
    ['ubicacion','Ubicación del equipo','person'],['web','Bloqueo web','person'],['usb','Restricción de USB','person'],['descargas','Control de descargas','person'],
    ['instalaciones','Restricción de instalación','person'],['comandos','Órdenes remotas','person'],['borrado','Borrado remoto','person'],['endurecimiento','Endurecimiento de Windows','person'],
    ['cumplimiento','Alertas de cumplimiento','person'],['cobertura','Cobertura de instalación','client'],['autogestion_personas','El cliente administra sus personas','client'],
    ['autogestion_accesos','El cliente administra sus accesos','client'],['auditoria','Auditoría del cliente','client'],['api','API para otros sistemas','client'],
    ['one','Conexión con AZC One','client'],['webhooks','Avisos a otros sistemas','client'],['ia','Asistente de IA','client']
  ].map(([id,name,level]) => ({id,name,level,pending:['api','webhooks'].includes(id)}));
  const plans = [
    {id:'observacion',name:'Observación',features:['productividad','presencia']},
    {id:'oficina',name:'Protección de oficina',features:['productividad','presencia','web','usb','instalaciones','comandos']},
    {id:'movilidad',name:'Protección con ubicación',features:['productividad','presencia','web','usb','instalaciones','comandos','ubicacion']}
  ];
  const controls = ['web','usb','instalaciones','descargas','endurecimiento'];
  const baseFeatures = ['productividad','presencia','web','usb','instalaciones','comandos','borrado','endurecimiento','cobertura','autogestion_personas','autogestion_accesos','auditoria'];
  const state = {
    clients:[{id:'azc',name:'Grupo AZC',zone:'America/Bogota',features:baseFeatures,defaultPlan:'oficina',brand:{name:'Grupo AZC',color:'#003A5D'},
      structure:D.companies.flatMap(company => D.sites.map((site,i) => ({company,site,area:D.areas[i],job:'Analista'}))).concat([{company:D.companies[0],site:D.sites[0],area:'Tecnología',job:'Analista de soporte'}]),
      technical:{account:'SoporteIT',configured:true,pin:true,sac:false,hardening:'manual'},aiEnabled:false,
      people:D.people.map(p => ({...p,ownPlan:p.index >= 158 ? 'movilidad' : p.id === 'p005' ? 'observacion' : null})),
      devices:window.ORDERS.devices.map(d => ({hostname:d.hostname,personId:d.person.id,version:d.version,state:d.state})),
      access:[{id:'andrea',name:'Andrea Vélez',role:'supervisor',company:'AZC Jurídica',site:'',area:'Litigios',orders:true}, {id:'it',name:'Esteban Ríos',role:'it',company:'',site:'',area:'',orders:false}],changes:[]}],revision:0
  };
  let storage = false;
  const key = 'keeper-panel-paso5-v1';
  try { if (typeof sessionStorage !== 'undefined') { const saved = JSON.parse(sessionStorage.getItem(key) || 'null'); if (saved?.clients?.length) Object.assign(state,saved); storage = true; } } catch { /* file:// puede negar almacenamiento local. */ }
  function save() { state.revision++; try { if (storage) sessionStorage.setItem(key,JSON.stringify(state)); } catch { storage = false; } }
  const client = id => state.clients.find(c => c.id === id);
  const plan = id => plans.find(p => p.id === id);
  const names = codes => codes.map(id => catalog.find(f => f.id === id)?.name || id);
  const effective = (c,p,features = c.features,planId = p.ownPlan || c.defaultPlan) => (plan(planId)?.features || []).filter(f => features.includes(f));
  const versionAtLeast = (v,min) => { const a=v.split('.').map(Number),b=min.split('.').map(Number); for(let i=0;i<3;i++) { if(a[i] !== b[i]) return a[i]>b[i]; } return true; };
  function impact(c,features,defaultPlan=c.defaultPlan,area='',newPlan='',includeOwn=false) {
    const people = c.people.filter(p => !area || p.area === area);
    const rows = people.map(p => {
      const before=effective(c,p),target=area ? (p.ownPlan && !includeOwn ? p.ownPlan : newPlan) : p.ownPlan || defaultPlan;
      const after=effective(c,p,features,target);
      return {id:p.id,name:p.name,before,after,add:after.filter(f=>!before.includes(f)),remove:before.filter(f=>!after.includes(f)),own:!!p.ownPlan};
    });
    const changed = rows.filter(r=>r.add.length || r.remove.length);
    const devices = c.devices.filter(d=>changed.some(r=>r.id===d.personId));
    const incompatible = devices.filter(d=>changed.find(r=>r.id===d.personId)?.add.includes('ubicacion') && !versionAtLeast(d.version,'4.0.30'));
    return {revision:state.revision,rows,changed,devices,incompatible,offline:devices.filter(d=>d.state==='offline'),skipped:area && !includeOwn ? people.filter(p=>p.ownPlan).length : 0,
      added:features.filter(f=>!c.features.includes(f)),removed:c.features.filter(f=>!features.includes(f)),controls:[...new Set(changed.flatMap(r=>[...r.add,...r.remove]).filter(f=>controls.includes(f)))]};
  }
  function applyContract(c,features,defaultPlan,preview) {
    if (preview.revision !== state.revision) return 'El cliente cambió: vuelve a revisar el impacto.';
    if (!plan(defaultPlan) || features.some(f=>!catalog.some(x=>x.id===f))) return 'Revisa funciones y plan.';
    c.features=[...new Set(features)]; c.defaultPlan=defaultPlan;
    if(!features.includes('ia')) c.aiEnabled=false;
    c.changes.unshift('Contrato y plan por defecto guardados; entrega a equipos pendiente de sincronización y reporte.');save();return '';
  }
  function applyArea(c,area,planId,includeOwn,preview) {
    if (preview.revision !== state.revision) return 'La selección cambió: revisa nuevamente.';
    if (!plan(planId)) return 'Selecciona un plan válido.';
    c.people.filter(p=>p.area===area && (includeOwn || !p.ownPlan)).forEach(p=>p.ownPlan=planId);
    c.changes.unshift(`Plan ${plan(planId).name} asignado a la selección actual de ${area}; no es una regla para futuras personas.`);save();return '';
  }
  function parseStructure(text) {
    const lines=text.replace(/^\uFEFF/,'').trim().split(/\r?\n/);
    if(lines.shift()?.trim().toLowerCase()!=='empresa;sede;area;cargo') return {rows:[],errors:['Encabezado requerido: empresa;sede;area;cargo']};
    const errors=[],rows=[],seen=new Set();
    lines.forEach((line,i)=>{const cells=line.split(';').map(s=>s.trim()); if(cells.length!==4 || cells.some(s=>!s || s.length>120 || s.includes('"'))) errors.push(`Fila ${i+2}: completa cuatro valores, sin comillas ni punto y coma dentro de nombres.`);else if(!seen.has(cells.join(';'))) {seen.add(cells.join(';'));rows.push(Object.fromEntries(['company','site','area','job'].map((k,n)=>[k,cells[n]])));}});
    if(!rows.length && !errors.length) errors.push('Añade al menos una fila de estructura.');
    return {rows,errors};
  }
  function create(draft) {
    if(!draft.name?.trim() || state.clients.some(c=>c.name.toLowerCase()===draft.name.trim().toLowerCase())) return {error:'El nombre está vacío o ya existe.'};
    if(!draft.structure?.length || !plan(draft.defaultPlan) || !draft.admin?.trim() || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(draft.email||'')) return {error:'Revisa estructura, plan y primer administrador.'};
    const c={id:'cliente-'+(state.clients.length+1),name:draft.name.trim(),zone:'America/Bogota',structure:structuredClone(draft.structure),features:[...draft.features],defaultPlan:draft.defaultPlan,brand:{name:draft.name.trim(),color:'#003A5D'},technical:{account:'',configured:false,pin:false,sac:false,hardening:'manual'},aiEnabled:false,people:[],devices:[],access:[{id:'admin',name:draft.admin,role:'it',company:'',site:'',area:'',orders:false}],changes:['Cliente preparado; aún no tiene personas de trabajo ni equipos. Cuenta del primer administrador simulada.']};
    state.clients.push(c);save();return {client:c};
  }
  const scoped = (c,a) => c.people.filter(p=>(!a.company||p.company===a.company)&&(!a.site||p.site===a.site)&&(!a.area||p.area===a.area));
  function permission(c,a,action,p) {
    if(!a || !scoped(c,a).some(x=>x.id===p.id))return false;
    if(action==='read')return true;
    if(action==='wipe')return false;
    return ['lock','unlock','restart','shutdown'].includes(action) && a.role==='supervisor' && a.orders && effective(c,p).includes('comandos');
  }
  function setAccess(c,id,draft,actor='superadmin') {
    if(actor!=='superadmin')return 'Solo el superadmin cambia este permiso en la demostración.';
    const a=c.access.find(a=>a.id===id);if(!a || !['supervisor','it'].includes(draft.role))return 'Acceso no válido.';
    if(draft.role==='supervisor' && !draft.company && !draft.site && !draft.area && !draft.full)return 'Confirma expresamente el alcance de todo el cliente.';
    Object.assign(a,{role:draft.role,company:draft.company,site:draft.site,area:draft.area,orders:draft.role==='supervisor'&&!!draft.orders});
    c.changes.unshift(`Acceso de ${a.name} actualizado; permiso de órdenes ${a.orders?'activo':'quitado'}.`);save();return '';
  }
  return {catalog,plans,controls,state,client,plan,names,effective,impact,applyContract,applyArea,parseStructure,create,scoped,permission,setAccess,save,get storage(){return storage;}};
})();
