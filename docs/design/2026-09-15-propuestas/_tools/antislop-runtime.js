(() => {
 'use strict';
 const $=s=>document.querySelector(s),all=s=>[...document.querySelectorAll(s)];
 const {devices,theme,page}=CONFIG,tenants={...CONFIG.tenants},originalTenants={...tenants};
 const qs=new URLSearchParams(location.search);
 let tenant=Object.hasOwn(tenants,qs.get('tenant'))?qs.get('tenant'):'all';
 let devicePage=1,userPage=1,dialogAction=null,returnFocus=null,feedbackOrigin=null;
 const brands={},roles={},commandHistory={},keys={azc:[{id:1,name:'Mesa de ayuda (muestra)',scope:'devices:read',expires:'2026-12-15'}],atlas:[],nueva:[]};
 const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const scoped=()=>devices.filter(d=>tenant==='all'||d.tenant===tenant);
 const people=()=>Object.values(devices.reduce((out,d)=>{const key=d.tenant+'|'+d.user;if(!out[key])out[key]={key,name:d.user,tenant:d.tenant,area:d.area,devices:[]};out[key].devices.push(d);return out;},{}));
 const selectedDevice=()=>scoped().find(d=>d.id===Number(qs.get('id')))||scoped()[0];
 function restoreFocus(element){if(element?.isConnected&&!element.closest('[hidden],dialog:not([open])')){element.focus();return;}const role=element?.dataset.role;const replacement=role?all('[data-role]').find(el=>el.dataset.role===role):element?.hasAttribute('data-revoke')?$('#create-key'):$('#tenant');(replacement||$('#main')).focus();}
 const tell=message=>{feedbackOrigin=$('#action-dialog').open?returnFocus:document.activeElement;$('#feedback-message').textContent=message;$('#feedback').hidden=false;};
 $('#dismiss-feedback').onclick=()=>{$('#feedback').hidden=true;restoreFocus(feedbackOrigin);};
 const badge=(type,text)=>`<span class="status ${type}">${type==='critical'?'! ':''}${text}</span>`;
 function links(){all('a[href]').forEach(a=>{const raw=a.getAttribute('href');if(raw.startsWith('#'))return;const u=new URL(raw,location.href);if(!u.pathname.endsWith('.html'))return;if(!a.dataset.fixedTenant)u.searchParams.set('tenant',tenant);a.setAttribute('href',raw.split('?')[0]+'?'+u.searchParams.toString());});}
 function openDialog(title,description,fields,confirm,callback){
  returnFocus=document.activeElement;dialogAction=callback;
  $('#dialog-title').textContent=title;$('#dialog-description').textContent=description;$('#dialog-fields').innerHTML=fields;$('#dialog-confirm').textContent=confirm;
  $('#action-dialog').showModal();$('#dialog-cancel').focus();
 }
 $('#dialog-cancel').addEventListener('click',()=>$('#action-dialog').close());
 $('#action-dialog').addEventListener('close',()=>{dialogAction=null;restoreFocus(returnFocus);});
 $('#dialog-form').addEventListener('submit',event=>{event.preventDefault();if(!$('#dialog-form').reportValidity())return;const action=dialogAction;if(action)action();$('#action-dialog').close();});
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&$('.mobile-menu[open]')&&!$('#action-dialog').open){const menu=$('.mobile-menu');menu.open=false;menu.querySelector('summary').focus();}});
 document.addEventListener('focusin',event=>{if(event.target.matches('input,select,textarea'))event.target.scrollIntoView?.({block:'nearest',behavior:'auto'});});
 function commands(button){
  const d=button.hasAttribute('data-current-device')?selectedDevice():devices.find(item=>item.id===Number(button.dataset.device));if(!d)return;
  const verb=button.dataset.command;
  const effects={Bloquear:'Suspender el acceso a Windows.',Apagar:'Cerrar sesiones y apagar. Puede perderse trabajo sin guardar.',Borrar:'Eliminar los datos del equipo. La operación real no se puede deshacer.'};
  const fields=`<label for="command-reason">Motivo de la solicitud</label><textarea id="command-reason" rows="3" required></textarea>${verb==='Borrar'?`<label for="confirm-hostname">Escribe ${d.name} para confirmar</label><input id="confirm-hostname" autocomplete="off" spellcheck="false" required aria-describedby="hostname-help"><p id="hostname-help" class="help">Debe coincidir exactamente, incluidas las mayúsculas.</p>`:''}`;
  openDialog(verb+' '+d.name,tenants[d.tenant]+'. '+effects[verb]+' Simulación local: no ejecuta el comando.',fields,'Solicitar '+verb.toLowerCase(),()=>{
   const reason=$('#command-reason').value;
   const message=verb+' para '+d.name+': en cola local, pendiente de confirmación del agente.';
   (commandHistory[d.id]||=[]).unshift({verb,reason,message});tell(message);detail();
  });
  if(verb==='Borrar'){const input=$('#confirm-hostname');const validate=()=>input.setCustomValidity(input.value===d.name?'':'Escribe exactamente '+d.name);input.addEventListener('input',validate);validate();}
 }
 document.addEventListener('click',event=>{const command=event.target.closest('[data-command]');if(command)commands(command);});
 function summary(){
  if(!$('#critical-count'))return;const ds=scoped(),critical=ds.filter(d=>d.critical),online=ds.filter(d=>d.online).length;
  $('#critical-count').textContent=critical.length;$('#total-count').textContent=ds.length;$('#online-count').textContent=online;$('#offline-count').textContent=ds.length-online;$('#version-count').textContent=ds.filter(d=>d.version==='4.0.0').length;
  $('#priority-list').innerHTML=critical.length?critical.map(d=>`<article class="priority-row"><div><h3><a class="hostname" href="device.html?id=${d.id}">${d.name}</a></h3><p>${tenants[d.tenant]} · ${esc(d.user)}</p></div><p>${badge('critical','Crítico')}<br>Política pendiente de confirmar<br><small>Último contacto: ${d.seen} COT</small></p><a class="button" href="device.html?id=${d.id}">Revisar equipo</a></article>`).join(''):'<div class="inline-state"><h3>Sin equipos críticos en este corte</h3><p>Puedes revisar las versiones anteriores y los equipos sin conexión en el registro de alcance.</p></div>';
  $('#event-list').innerHTML=ds.slice(0,3).map((d,i)=>`<li><time>${['09:41','09:32','08:04'][i]}</time><div><strong>${d.name}: ${['conexión recibida','política publicada','revisión confirmada'][i]}</strong><p>${tenants[d.tenant]} · ${['Agente '+d.version,'Revisión 13, pendiente de respuesta','Revisión 12 aplicada'][i]}</p></div><a href="device.html?id=${d.id}">Ver ficha</a></li>`).join('');
 }
 function inventory(){
  if(!$('#device-rows'))return;const term=$('#search').value.trim().toLocaleLowerCase('es'),connection=$('#connection').value,priority=$('#severity').value,version=$('#version').value;
  const ds=scoped().filter(d=>(!term||[d.name,d.user,d.site,d.area].join(' ').toLocaleLowerCase('es').includes(term))&&(connection==='all'||d.online===(connection==='online'))&&(priority==='all'||(priority==='critical'?d.critical:d.high))&&(version==='all'||(version==='v4'?d.version==='4.0.0':d.version!=='4.0.0')));
  devicePage=Math.min(devicePage,Math.max(1,Math.ceil(ds.length/8)));
  $('#device-rows').innerHTML=ds.slice((devicePage-1)*8,devicePage*8).map(d=>`<tr><th scope="row"><a href="device.html?id=${d.id}" class="hostname">${d.name}</a><small>${esc(d.user)} · ${d.site}</small></th><td>${d.online?'En línea':'Sin conexión'}<br>${badge(d.critical?'critical':d.high?'high':'ok',d.critical?'Crítico':d.high?'Alto':'OK')}</td><td>${tenants[d.tenant]}</td><td>${d.version}</td><td>${d.seen} COT</td><td><div class="row-actions">${['Bloquear','Apagar','Borrar'].map(verb=>`<button data-command="${verb}" data-device="${d.id}" aria-label="${verb} ${d.name}">${verb}</button>`).join('')}</div></td></tr>`).join('');
  $('#inventory-data').hidden=!ds.length;$('#filter-empty').hidden=!!ds.length;$('#device-pagination').hidden=!ds.length;
  $('#result-count').textContent=ds.length+' equipos de muestra · '+tenants[tenant];$('#page-label').textContent=`${(devicePage-1)*8+1} a ${Math.min(devicePage*8,ds.length)} de ${ds.length}`;
  $('#previous-page').disabled=devicePage===1;$('#next-page').disabled=devicePage*8>=ds.length;links();
 }
 if($('#filters')){
  for(const id of ['search','connection','severity','version'])$('#'+id).addEventListener('input',()=>{devicePage=1;inventory();});
  for(const [key,id]of [['status','connection'],['severity','severity'],['version','version']])if(qs.has(key)&&all('#'+id+' option').some(o=>o.value===qs.get(key)))$('#'+id).value=qs.get(key);
  const reset=()=>{$('#search').value='';for(const id of ['connection','severity','version'])$('#'+id).value='all';devicePage=1;inventory();};
  $('#filters').addEventListener('reset',event=>{event.preventDefault();reset();});$('#filters').addEventListener('submit',event=>{event.preventDefault();inventory();});$('#reset-empty').onclick=reset;
  $('#previous-page').onclick=()=>{devicePage--;inventory();};$('#next-page').onclick=()=>{devicePage++;inventory();};
 }
 function detail(){if(!$('#device-name'))return;const d=selectedDevice();if(!d)return;$('#device-name').textContent=d.name;$('#command-target').textContent=d.name;$('#device-owner').textContent=tenants[d.tenant]+' · '+d.user;$('#device-state').textContent=(d.online?'En línea':'Sin conexión')+' · '+(d.critical?'Prioridad crítica':d.high?'Prioridad alta':'Sin incidencia en el corte');$('#device-version').textContent=d.version;$('#device-seen').textContent=d.seen.startsWith('Ayer')?'14 sep 2026, 17:42 COT':'15 sep 2026, '+d.seen+' COT';const history=commandHistory[d.id]||[];$('#command-feedback').textContent=history[0]?.message||'Sin solicitudes locales para '+d.name+'.';$('#timeline').innerHTML=history.map(item=>`<li><time>09:42</time><div><strong>${esc(item.verb)} solicitado</strong><p>${esc(item.reason)}. En cola local, sin ejecución real.</p></div></li>`).join('')+'<li><time>09:41</time><div>Contacto del agente recibido (muestra).</div></li><li><time>09:32</time><div>Revisión 13 publicada, sin confirmar (muestra).</div></li><li><time>08:04</time><div>Revisión 12 confirmada (muestra).</div></li>';}
 function policyTargets(){
  if(!$('#policy-target'))return;const scope=$('#policy-scope').value,target=$('#policy-target');
  if(scope==='global')target.innerHTML='<option value="all">Todas las empresas</option>';
  else if(scope==='company')target.innerHTML=Object.entries(tenants).filter(([id])=>id!=='all'&&(tenant==='all'||id===tenant)).map(([id,name])=>`<option value="${id}">${esc(name)}</option>`).join('');
  else if(scope==='user')target.innerHTML=people().filter(p=>tenant==='all'||p.tenant===tenant).map(p=>`<option value="${esc(p.key)}">${esc(p.name)} · ${tenants[p.tenant]}</option>`).join('');
  else target.innerHTML=scoped().map(d=>`<option value="${d.id}">${d.name}</option>`).join('');
  impact();
 }
 function impact(){if(!$('#impact-count'))return;const scope=$('#policy-scope').value,target=$('#policy-target').value;const count=scope==='global'?devices.length:scope==='company'?devices.filter(d=>d.tenant===target).length:scope==='user'?people().find(p=>p.key===target)?.devices.length||0:target?1:0;$('#impact-count').textContent=count;$('#scope-summary').textContent='Destino: '+($('#policy-target').selectedOptions[0]?.textContent||'Sin equipos asignados')+'.';}
 if($('#policy-form')){
  $('#policy-scope').onchange=policyTargets;$('#policy-target').onchange=impact;
  $('#policy-form').onsubmit=event=>{event.preventDefault();const invalid=$('#web-block').checked&&(!$('#domains').value.trim()||$('#domains').value.split(/\r?\n/).some(d=>d.trim()&&!/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i.test(d.trim())));$('#domain-error').hidden=!invalid;
   const schedule=!all('input[name=day]:checked').length||$('#end-time').value<=$('#start-time').value;$('#schedule-error').hidden=!schedule;
   if(invalid){$('#domains').focus();return;}if(schedule){$('#end-time').focus();return;}
   openDialog('Publicar revisión 13',$('#scope-summary').textContent+' Afectará a '+$('#impact-count').textContent+' equipos de muestra. Las listas reemplazan la política heredada.','','Publicar revisión',()=>{$('#draft-status').textContent='Revisión 13 publicada localmente. Pendiente de confirmación del agente.';tell('Revisión de muestra publicada; no se enviaron políticas.');});
  };
 }
 function users(){
  if(!$('#user-rows'))return;const term=$('#user-search').value.trim().toLocaleLowerCase('es');const ps=people().filter(p=>(tenant==='all'||p.tenant===tenant)&&[p.name,p.area].join(' ').toLocaleLowerCase('es').includes(term));
  userPage=Math.min(userPage,Math.max(1,Math.ceil(ps.length/10)));
  $('#user-rows').innerHTML=ps.slice((userPage-1)*10,userPage*10).map(p=>`<tr><th scope="row">${esc(p.name)}<small>${p.area}</small></th><td>${tenants[p.tenant]}</td><td>${roles[p.key]||'Consulta'}</td><td>${p.devices.map(d=>`<a class="hostname" href="device.html?id=${d.id}">${d.name}</a>`).join(' ')}</td><td><button data-role="${esc(p.key)}" aria-label="Editar acceso de ${esc(p.name)}">Editar acceso</button></td></tr>`).join('');
  $('#users-data').hidden=!ps.length;$('#user-empty').hidden=!!ps.length;$('#user-pagination').hidden=!ps.length;$('#user-count').textContent=ps.length+' personas de muestra · '+tenants[tenant];$('#user-page-label').textContent=`${(userPage-1)*10+1} a ${Math.min(userPage*10,ps.length)} de ${ps.length}`;$('#user-prev').disabled=userPage===1;$('#user-next').disabled=userPage*10>=ps.length;links();
 }
 if($('#user-search')){
  $('#user-search').oninput=()=>{userPage=1;users();};$('#reset-users').onclick=()=>{$('#user-search').value='';users();};$('#user-prev').onclick=()=>{userPage--;users();};$('#user-next').onclick=()=>{userPage++;users();};
  $('#invite-user').onclick=()=>openDialog('Invitar a una persona','Crea una invitación local. No se envía correo.',`<label for="invite-tenant">Empresa</label><select id="invite-tenant">${Object.entries(tenants).filter(([id])=>id!=='all'&&(tenant==='all'||id===tenant)).map(([id,name])=>`<option value="${id}">${esc(name)}</option>`).join('')}</select><label for="invite-email">Correo corporativo</label><input id="invite-email" type="email" placeholder="persona@example.com" required><label for="invite-role">Rol inicial</label><select id="invite-role"><option>Consulta</option><option>Operador</option><option>Administrador</option></select>`,'Crear invitación',()=>tell('Invitación de muestra creada para '+$('#invite-email').value+'. No se envió correo.'));
 }
 document.addEventListener('click',event=>{const button=event.target.closest('[data-role]');if(!button)return;const p=people().find(p=>p.key===button.dataset.role);openDialog('Cambiar acceso de '+p.name,tenants[p.tenant]+'. El cambio solo afecta al acceso en esta empresa.',`<label for="new-role">Rol en el panel</label><select id="new-role">${['Consulta','Operador','Administrador'].map(r=>`<option${r===(roles[p.key]||'Consulta')?' selected':''}>${r}</option>`).join('')}</select>`,'Guardar acceso',()=>{roles[p.key]=$('#new-role').value;users();tell('Acceso actualizado en esta página de muestra.');});});
 const luminance=hex=>{const c=hex.match(/[a-f\d]{2}/gi).map(v=>parseInt(v,16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);return c[0]*.2126+c[1]*.7152+c[2]*.0722;};
 const contrast=(a,b)=>{const x=luminance(a),y=luminance(b);return(Math.max(x,y)+.05)/(Math.min(x,y)+.05);};
 function brandPreview(){if(!$('#brand-form'))return;const color=$('#brand-accent').value;const ratio=Math.min(contrast(color,'#F8F8F8'),contrast(color,theme.hover));$('#brand-preview').style.setProperty('--accent',color);$('#preview-name').textContent=$('#brand-name').value;$('#contrast-result').textContent='Contraste mínimo: '+ratio.toFixed(2)+':1. '+(ratio>=4.5?'Cumple 4.5:1.':'Elige un acento más oscuro.');$('#brand-form button[type=submit]').disabled=ratio<4.5;}
 function renderKeys(){if(!$('#api-rows'))return;const list=keys[tenant]||[];$('#api-rows').innerHTML=list.map(k=>`<tr><th scope="row">${esc(k.name)}</th><td class="hostname">DEMO_NO_VALIDA_${k.id}</td><td>${k.scope}</td><td>${k.expires}</td><td><button data-revoke="${k.id}" aria-label="Revocar clave ${esc(k.name)}">Revocar</button></td></tr>`).join('');$('#key-empty').hidden=!!list.length||tenant==='all';$('#create-key').disabled=tenant==='all';}
 function settings(){if(!$('#brand-form'))return;$('#brand-fieldset').disabled=tenant==='all';$('#tenant-required').textContent=tenant==='all'?'Selecciona una empresa para editar su marca y sus claves.':'Editando '+tenants[tenant]+'. La muestra no guarda cambios al navegar.';$('#brand-name').value=tenant==='all'?'':tenants[tenant];$('#brand-accent').value=brands[tenant]?.accent||theme.accent;$('#preview-logo').src=brands[tenant]?.logo||'../assets/logo_main.png';brandPreview();renderKeys();}
 if($('#brand-form')){
  $('#brand-name').oninput=brandPreview;$('#brand-accent').oninput=brandPreview;
  $('#brand-logo').onchange=()=>{const file=$('#brand-logo').files[0];if(!file)return;if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>2*1024*1024){tell('Selecciona PNG, JPG o WebP de hasta 2 MB.');$('#brand-logo').value='';return;}const reader=new FileReader();reader.onload=()=>{$('#preview-logo').src=reader.result;};reader.readAsDataURL(file);};
  $('#brand-form').onsubmit=event=>{event.preventDefault();if(tenant==='all'||$('#brand-form button[type=submit]').disabled)return;brands[tenant]={name:$('#brand-name').value,accent:$('#brand-accent').value,logo:$('#preview-logo').src};tenants[tenant]=brands[tenant].name;setTenant();tell('Identidad aplicada a esta empresa en la página actual.');};
  $('#brand-reset').onclick=()=>{delete brands[tenant];tenants[tenant]=originalTenants[tenant];setTenant();tell('Marca original restablecida.');};
  $('#create-key').onclick=()=>openDialog('Crear clave de muestra',tenants[tenant]+'. Identificador ficticio, sin acceso a servicios.',`<label for="key-name">Integración</label><input id="key-name" placeholder="Nombre de la integración" required><label for="key-scope">Permisos</label><select id="key-scope"><option value="devices:read">Equipos: lectura</option><option value="devices:read,users:read">Equipos y personas: lectura</option></select><label for="key-expiry">Vencimiento</label><input id="key-expiry" type="date" min="2026-09-17" value="2026-12-15" required>`,'Crear identificador',()=>{keys[tenant].push({id:Date.now(),name:$('#key-name').value,scope:$('#key-scope').value,expires:$('#key-expiry').value});renderKeys();$('#key-feedback').textContent='Credencial de muestra: DEMO_NO_VALIDA. No sirve para autenticar una API.';});
 }
 document.addEventListener('click',event=>{const button=event.target.closest('[data-revoke]');if(!button)return;const key=keys[tenant].find(k=>k.id===Number(button.dataset.revoke));openDialog('Revocar clave',tenants[tenant]+'. Se eliminará el identificador local de '+key.name+'.','','Revocar clave',()=>{keys[tenant]=keys[tenant].filter(k=>k.id!==key.id);renderKeys();$('#key-feedback').textContent='Clave ficticia revocada en esta página.';});});
 function state(){
  if(!$('#view-state'))return;let mode=qs.get('state');if(!scoped().length&&['index','devices','device','users','policies'].includes(page))mode=mode||'empty';
  const nouns={index:'la flota',devices:'el inventario',device:'la ficha del equipo',policies:'las políticas',users:'las personas',settings:'la configuración'};
  const descriptions={empty:['No hay datos para '+nouns[page],'Este escenario no tiene registros. En una empresa nueva hay que registrar equipos y asignar personas antes de operar.'],loading:['Consultando '+nouns[page],'La consulta está en curso en este escenario. Las acciones se habilitan cuando haya datos; puedes cancelar la espera de muestra.'],error:['No se pudo consultar '+nouns[page],'Falló la consulta de este escenario. No se enviaron cambios ni comandos. Reintenta conservando la empresa seleccionada.']};
  const active=Object.hasOwn(descriptions,mode);$('#view-state').hidden=!active;$('#view-content').hidden=active;
  if(active){$('#state-title').textContent=descriptions[mode][0];$('#state-description').textContent=descriptions[mode][1];$('#view-state').setAttribute('role',mode==='error'?'alert':'status');$('#view-content').setAttribute('aria-busy',String(mode==='loading'));const link=$('#state-return');link.textContent=mode==='error'?'Reintentar consulta':mode==='loading'?'Cancelar espera y ver muestra':'Volver a los datos de muestra';if(!scoped().length){link.dataset.fixedTenant='true';link.href=page+'.html?tenant=azc';link.textContent='Ver ejemplo de Grupo AZC';}}
 }
 function setTenant(){
  if($('#tenant')){$('#tenant').value=tenant;all('#tenant option').forEach(o=>o.textContent=tenants[o.value]);}
  document.documentElement.dataset.tenant=tenant;document.documentElement.style.setProperty('--accent',brands[tenant]?.accent||theme.accent);all('[data-tenant-label]').forEach(e=>e.textContent=tenants[tenant]);all('.brand img').forEach(img=>{img.src=brands[tenant]?.logo||'../assets/logo_main.png';img.alt=brands[tenant]?.name||'AZC Keeper';});
  summary();inventory();detail();policyTargets();users();settings();state();links();
 }
 if($('#tenant'))$('#tenant').onchange=()=>{tenant=$('#tenant').value;devicePage=1;userPage=1;setTenant();tell('Empresa seleccionada: '+tenants[tenant]+'.');};
 if($('#login-form')){
  $('#login-tenant').value=tenant==='atlas'?'atlas':'azc';$('#login-error').hidden=qs.get('state')!=='error';
  $('#show-password').onclick=()=>{const show=$('#password').type==='password';$('#password').type=show?'text':'password';$('#show-password').textContent=show?'Ocultar':'Mostrar';$('#show-password').setAttribute('aria-pressed',String(show));};
  $('#login-form').onsubmit=event=>{event.preventDefault();if(!$('#login-form').reportValidity())return;location.href='index.html?tenant='+$('#login-tenant').value;};
  $('#access-help').onclick=()=>openDialog('Ayuda de acceso','En el producto, contacta al administrador de tu empresa. En esta muestra puedes usar cualquier correo ficticio válido y ocho caracteres.','','Entendido',()=>{});
 }
 setTenant();
})();
