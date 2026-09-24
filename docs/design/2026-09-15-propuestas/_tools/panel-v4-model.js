/* Modelo de demostración local. La autorización de producción debe vivir en el servidor. */
const PanelModel = (() => {
  const catalog = [
    ['inicio.ver','Inicio','Consultar el estado'],['equipos.ver','Equipos','Ver equipos'],['equipos.accionar','Equipos','Enviar acciones remotas'],['equipos.agregar','Equipos','Agregar equipos'],['equipos.bloquear','Equipos','Bloquear'],['equipos.desbloquear','Equipos','Desbloquear'],['equipos.apagar','Equipos','Apagar'],['equipos.borrar','Equipos','Borrar archivos'],
    ['usuarios.ver','Usuarios','Consultar personas'],['usuarios.gestionar','Usuarios','Gestionar personas'],['usuarios.alta','Usuarios','Dar de alta'],['usuarios.baja','Usuarios','Dar de baja'],['usuarios.asignar','Usuarios','Asignar equipo'],
    ['reglas.editar','Reglas','Editar y aplicar reglas'],['reglas.excepciones','Reglas','Gestionar excepciones'],['reportes.ver','Reportes','Consultar y exportar'],['empresa.ajustes','Ajustes','Personalizar marca y claves'],['roles.gestionar','Roles / meta','Gestionar roles'],['tenants.gestionar','Plataforma','Gestionar empresas'],['tiers.gestionar','Plataforma','Gestionar licencias'],['auditoria.ver','Plataforma','Consultar auditoría'],['releases.gestionar','Plataforma','Gestionar versiones'],['diagnostico.ver','Plataforma','Diagnóstico avanzado']
  ].map(([key,group,label])=>({key,group,label,default:false}));
  const reading=['inicio.ver','equipos.ver','usuarios.ver','reportes.ver'];
  const admin=[...reading,'equipos.accionar','equipos.agregar','equipos.bloquear','equipos.desbloquear','equipos.apagar','equipos.borrar','usuarios.gestionar','usuarios.alta','usuarios.baja','usuarios.asignar','reglas.editar','empresa.ajustes'];
  const seeds=[
    {id:'super',name:'Super admin',permissions:catalog.map(p=>p.key),scope:'empresa'},
    {id:'gerencia',name:'Gerencia',permissions:[...reading,'auditoria.ver'],scope:'empresa'},
    {id:'direccion',name:'Dirección',permissions:reading,scope:'area',areas:['Jurídica']},
    {id:'coordinacion',name:'Coordinación',permissions:['inicio.ver','equipos.ver','reportes.ver'],scope:'area',areas:['Jurídica'],sites:['Cali']},
    {id:'it',name:'IT',permissions:[...admin.filter(p=>!['reportes.ver','empresa.ajustes'].includes(p)),'reglas.excepciones','roles.gestionar','tenants.gestionar','tiers.gestionar','auditoria.ver','releases.gestionar','diagnostico.ver'],scope:'sede',sites:['Cali']},
    {id:'rrhh',name:'RRHH',permissions:['inicio.ver','usuarios.ver','usuarios.gestionar','usuarios.alta','usuarios.baja','usuarios.asignar','reportes.ver'],scope:'empresa'},
    {id:'admin',name:'Admin Empresa',permissions:admin,scope:'empresa'},
    {id:'colaborador',name:'Colaborador',permissions:[],scope:'propio',panel:false}
  ];
  const copy=x=>JSON.parse(JSON.stringify(x));
  function fresh(){
    const tenants=[{id:'azc',name:'Grupo AZC',autonomiaRoles:false,accent:'#245D68',logo:'../assets/logo_main.png'},{id:'atlas',name:'Atlas Consultores',autonomiaRoles:false,accent:'#245D68',logo:'../assets/logo_main.png'},{id:'nueva',name:'Andina Servicios',autonomiaRoles:false,accent:'#245D68',logo:'../assets/logo_main.png'}];
    const names=['Laura Méndez','Andrés Molina','Diana Rojas','Camilo Vélez','Natalia Castro','Sergio Patiño','María Torres','Juan Salazar','Paula Ortiz','David Romero','Ana Vargas','Felipe Ruiz'];
    const users=[],devices=[];
    for(const tenant of ['azc','atlas']) for(let n=1;n<= (tenant==='azc'?24:12);n++){
      const id=tenant+'-'+n,area=n%3===0?'Administración':'Jurídica',site=n%4===0?'Bogotá':'Cali';
      users.push({id,tenant,name:names[(n-1)%12]+(n>12?' Restrepo':''),document:'DEMO-'+(10000+n),email:`persona${n}@${tenant}.example`,area,site,job:area==='Jurídica'?'Abogado/a':'Analista',schedule:'Lun a vie · 08:00 a 17:00',role:tenant+'-colaborador',active:true,extra:{}});
      devices.push({id,tenant,host:(tenant==='azc'?'AZC':'ATL')+'-'+(site==='Cali'?'CLO':'BOG')+'-'+String(n).padStart(3,'0'),area,site,user:id,online:n%5!==0,days:n%5===0?n:0,version:n%7===0?'3.9.8':'4.0.2',state:'Disponible',policy:n%7===0?'Pendiente':'Aplicada',last:n%5===0?`${n} días sin conexión`:'Hoy, 09:42',minutes:300+(n*7)%170,previous:290+(n*5)%170,events:[],pending:null});
    }
    const roles=tenants.flatMap(t=>seeds.filter(s=>s.id!=='super').map(s=>({...copy(s),id:t.id+'-'+s.id,seed:s.id,tenant:t.id,active:true})));
    roles.push({...copy(seeds[0]),tenant:'global',active:true});
    const actors=[{id:'admin',name:'Elena Marín',role:'azc-admin',tenant:'azc',assigned:['azc']},{id:'atlas',name:'Marcos León',role:'atlas-admin',tenant:'atlas',assigned:['atlas']},{id:'super',name:'Koichi Fumikatsu',role:'super',tenant:'azc',assigned:tenants.map(t=>t.id),platform:true},{id:'it',name:'Sara Mejía',role:'azc-it',tenant:'azc',assigned:['azc'],central:true},...['gerencia','direccion','coordinacion','rrhh','colaborador'].map(id=>({id,name:seeds.find(s=>s.id===id).name+' · revisión',role:'azc-'+id,tenant:'azc',assigned:['azc'],person:'azc-1'}))];
    return {tenants,users,devices,roles,seeds:copy(seeds),catalog:copy(catalog),actors,policies:Object.fromEntries(tenants.map(t=>[t.id,{web:false,downloads:false,install:false,hours:false,domains:'',start:'08:00',end:'17:00',days:['Lun','Mar','Mié','Jue','Vie'],revision:1,exceptions:[]} ])),keys:[],audit:[]};
  }
  function context(db,session='admin',tenant){const actor=db.actors.find(a=>a.id===session)||db.actors[0];return {actor,role:db.roles.find(r=>r.id===actor.role),tenant:actor.platform&&db.tenants.some(t=>t.id===tenant)?tenant:actor.tenant};}
  function can(db,c,key){return !!c.role?.active&&c.role.panel!==false&&(c.role.permissions.includes(key)||(c.actor.platform&&db.catalog.some(p=>p.key===key)));}
  function inScope(db,c,row){if(row.tenant!==c.tenant)return false;if(!c.actor.assigned.includes(row.tenant)&&!c.actor.platform)return false;const r=c.role;if(r.scope==='propio'&&row.id!==c.actor.person&&row.user!==c.actor.person)return false;if(r.areas?.length&&!r.areas.includes(row.area))return false;if(r.sites?.length&&!r.sites.includes(row.site))return false;return true;}
  const rows=(db,c,table)=>db[table].filter(r=>inScope(db,c,r));
  function rolesGate(db,c){return can(db,c,'roles.gestionar')&&(c.actor.platform||c.actor.central||db.tenants.find(t=>t.id===c.tenant).autonomiaRoles);}
  function requirePermission(db,c,key,row){if(!can(db,c,key)||(row&&!inScope(db,c,row)))throw Error('No tienes permiso para esta acción en este alcance.');}
  function audit(db,c,what){db.audit.unshift({who:c.actor.name,tenant:c.tenant,what,when:new Date().toLocaleString('es-CO')});}
  function gate(db,c,id,on){if(!c.actor.platform||!can(db,c,'tenants.gestionar'))throw Error('Solo Super admin puede modificar el gate de empresa.');db.tenants.find(t=>t.id===id).autonomiaRoles=!!on;audit(db,{...c,tenant:id},'Autonomía de roles '+(on?'activada':'revocada'));}
  function command(db,c,id,action,typed,reason){const d=db.devices.find(d=>d.id===id);if(!d)throw Error('Equipo no encontrado.');requirePermission(db,c,'equipos.accionar',d);requirePermission(db,c,'equipos.'+action,d);if(typed!==d.host||!reason.trim())throw Error('Escribe el nombre exacto del equipo y un motivo.');if(d.pending||d.state==='Borrado')throw Error('Este equipo no admite otro comando en este estado.');d.pending={action,reason};d.events.unshift('Comando '+action+' en cola. Motivo: '+reason);audit(db,c,action+' solicitado para '+d.host);}
  function confirmAgent(db,c,id){const d=db.devices.find(d=>d.id===id);requirePermission(db,c,'equipos.accionar',d);if(!d.pending)throw Error('No hay comando pendiente.');requirePermission(db,c,'equipos.'+d.pending.action,d);const a=d.pending.action;d.state={bloquear:'Bloqueado',desbloquear:'Disponible',apagar:'Apagado',borrar:'Borrado'}[a];if(['apagar','borrar'].includes(a))d.online=false;d.events.unshift(d.state+' · respuesta simulada del agente');d.pending=null;audit(db,c,d.host+': '+d.state+' (simulado)');}
  function saveRole(db,c,input){
    if(!rolesGate(db,c))throw Error('Se necesitan el gate de empresa y roles.gestionar, o autoridad central AZC.');
    const old=db.roles.find(r=>r.id===input.id);if(old&&old.tenant!==c.tenant&&!(old.tenant==='global'&&c.actor.platform))throw Error('El rol pertenece a otro alcance.');
    if(input.global&&!c.actor.platform)throw Error('El catálogo global pertenece a Super admin.');
    if(old?.id===c.role.id)throw Error('No puedes modificar tu propio rol. Pide a otro administrador que lo revise.');
    if(input.permissions.some(p=>!can(db,c,p)))throw Error('No puedes conceder permisos que no posees.');
    if(!c.actor.platform&&input.permissions.includes('roles.gestionar')&&!old?.permissions.includes('roles.gestionar'))throw Error('Solo Super admin puede conceder roles.gestionar.');
    const narrower={empresa:['empresa','area','sede','propio'],area:['area','propio'],sede:['sede','propio'],propio:['propio']};
    if(!narrower[c.role.scope].includes(input.scope))throw Error('El alcance elegido supera el tuyo.');
    if(c.role.areas?.length&&(!input.areas?.length||input.areas.some(a=>!c.role.areas.includes(a))))throw Error('El área supera tu alcance.');
    if(c.role.sites?.length&&(!input.sites?.length||input.sites.some(a=>!c.role.sites.includes(a))))throw Error('La sede supera tu alcance.');
    if(!input.name.trim()||(input.scope==='area'&&!input.areas?.length)||(input.scope==='sede'&&!input.sites?.length))throw Error('Completa el nombre y la delimitación del alcance.');
    const role={...old,...input,id:old?.id||'rol-'+Date.now(),tenant:old?.tenant||(input.global?'global':c.tenant),active:true};delete role.global;
    if(old)Object.assign(old,role);else db.roles.push(role);audit(db,c,'Rol '+role.name+' guardado. Permisos: '+role.permissions.join(', '));return role;
  }
  function offboard(db,c,id,typed,mode,to){const u=db.users.find(u=>u.id===id);requirePermission(db,c,'usuarios.gestionar',u);requirePermission(db,c,'usuarios.baja',u);if(!u.active||typed!==u.name)throw Error('Escribe el nombre completo de la persona activa.');const assigned=db.devices.filter(d=>d.user===id);if(assigned.some(d=>!inScope(db,c,d)))throw Error('IT debe revisar equipos fuera de tu alcance antes de la baja.');const target=db.users.find(u=>u.id===to);if(mode==='reassign'&&(!target||!target.active||target.id===id||!inScope(db,c,target)))throw Error('Selecciona otra persona activa de tu empresa y alcance.');if(!['release','reassign'].includes(mode))throw Error('Elige qué hacer con el equipo.');assigned.forEach(d=>d.user=mode==='release'?null:target.id);u.active=false;audit(db,c,'Baja de '+u.name+'; equipos '+(mode==='release'?'liberados':'reasignados a '+target.name));}
  function contrast(a,b){const lum=h=>{const n=h.slice(1).match(/../g).map(v=>parseInt(v,16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);return n[0]*.2126+n[1]*.7152+n[2]*.0722;};const x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);}
  return {fresh,context,can,inScope,rows,rolesGate,requirePermission,audit,gate,command,confirmAgent,saveRole,offboard,contrast,copy};
})();
if(typeof module!=='undefined')module.exports=PanelModel;
