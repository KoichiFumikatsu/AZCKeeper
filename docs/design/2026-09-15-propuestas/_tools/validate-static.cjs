const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const {pathToFileURL,fileURLToPath}=require('node:url');
const {JSDOM,VirtualConsole}=require(process.env.JSDOM_MODULE||'C:/Users/FumiWork/proyectos/portal-azc/node_modules/jsdom');
const root=path.resolve(__dirname,'..');
const {themes}=require('./themes.cjs');
const results=[],failures=[],contrasts=[];
function check(name,fn){try{fn();results.push(name);}catch(e){failures.push(name+': '+e.message);}}
function ratio(a,b){const lum=h=>{const c=h.match(/[a-f\d]{2}/gi).map(v=>parseInt(v,16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);return .2126*c[0]+.7152*c[1]+.0722*c[2];};const x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);}
function open(style,p,q=''){
 const file=path.join(root,style,p+'.html'),html=fs.readFileSync(file,'utf8'),errors=[];
 const console=new VirtualConsole();console.on('jsdomError',e=>errors.push(e.message));
 const dom=new JSDOM(html.replace('<link rel="stylesheet" href="styles.css">','<style>'+fs.readFileSync(path.join(root,style,'styles.css'),'utf8')+'</style>'),{url:pathToFileURL(file).href+q,runScripts:'dangerously',virtualConsole:console,beforeParse(w){
  w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
  w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
 }});
 const d=dom.window.document,$=s=>d.querySelector(s),all=s=>[...d.querySelectorAll(s)];
 const input=(s,value)=>{$(s).value=value;$(s).dispatchEvent(new dom.window.Event('input',{bubbles:true}));};
 const change=(s,value)=>{$(s).value=value;$(s).dispatchEvent(new dom.window.Event('change',{bubbles:true}));};
 const submit=s=>$(s).dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));
 return {dom,d,$,all,input,change,submit,errors,file,html};
}
for(const style of Object.keys(themes)){
 check(style+' — conjunto exacto de páginas',()=>assert.deepEqual(fs.readdirSync(path.join(root,style)).filter(p=>p.endsWith('.html')).sort(),['device.html','devices.html','index.html','login.html','policies.html','settings.html','states.html','users.html']));
 for(const p of ['login','index','devices','device','policies','users','settings','states']){
  const c=open(style,p);
  check(style+'/'+p+' — HTML y JavaScript',()=>{assert.equal(c.all('h1').length,1);assert.equal(c.d.documentElement.lang,'es');assert.equal(c.all('main').length,1);assert(c.$('meta[name=viewport]'));assert.equal(new Set(c.all('[id]').map(e=>e.id)).size,c.all('[id]').length,'IDs duplicados');c.all('script').forEach(s=>new vm.Script(s.textContent));assert.deepEqual(c.errors,[]);});
  check(style+'/'+p+' — recursos locales y enlaces',()=>{c.all('[href],[src]').forEach(el=>{const ref=el.getAttribute('href')||el.getAttribute('src');assert(!/^(https?:|\/\/|data:|\/)/i.test(ref),'Referencia externa: '+ref);const u=new URL(ref,pathToFileURL(c.file));assert.equal(u.protocol,'file:');assert(fs.existsSync(fileURLToPath(u)),'Enlace ausente: '+ref);if(ref.startsWith('#'))assert(c.d.getElementById(ref.slice(1)),'Ancla ausente');});});
  check(style+'/'+p+' — nombres de controles',()=>{c.all('input:not([type=hidden]),select,textarea').forEach(el=>assert(el.labels.length||el.getAttribute('aria-label'),'Control sin etiqueta: '+el.id));c.all('button').filter(b=>!b.hidden).forEach(b=>assert(b.textContent.trim()||b.getAttribute('aria-label'),'Botón sin nombre'));c.all('img').forEach(i=>assert(i.hasAttribute('alt'),'Imagen sin alt'));});
  if(themes[style].light)check(style+'/'+p+' — familia corporativa clara',()=>{assert.equal(c.d.documentElement.dataset.family,'corporativo-claro');assert.equal(c.$('meta[name=color-scheme]').content,'light');assert.equal(c.all('.terminal-path,.instrument-rail,.editorial-running,.cursor').length,0);const css=fs.readFileSync(path.join(root,style,'styles.css'),'utf8');assert(css.includes('color-scheme:light'));for(const color of [themes[style].surface,themes[style].hover])assert(color.match(/[a-f0-9]{2}/gi).every(v=>parseInt(v,16)>=220),'Superficie demasiado oscura');});
  c.dom.window.close();
 }
 check(style+' — tenant, KPIs y navegación',()=>{const c=open(style,'index');assert.equal(c.$('[data-kpi=total]').textContent,'180');c.change('#tenant','atlas');assert.equal(c.$('[data-kpi=total]').textContent,'56');assert(!c.$('#alerts').textContent.includes('Grupo AZC'));assert(c.$('nav a[href^="devices"]').getAttribute('href').includes('tenant=atlas'));c.change('#tenant','nueva');assert.equal(c.$('[data-kpi=total]').textContent,'0');assert(c.$('#alerts').textContent.includes('Sin alertas'));c.dom.window.close();});
 check(style+' — filtros, paginación, vacío, carga y error',()=>{const c=open(style,'devices');c.input('#search','AZC-LT-001');assert.equal(c.all('#device-rows tr').length,1);c.$('[data-reset-filters]').click();c.$('#next').click();assert(c.$('#page-label').textContent.startsWith('9–16'));c.input('#status-filter','offline');assert(c.$('#device-rows').textContent.includes('Sin conexión'));c.input('#search','INEXISTENTE');assert(!c.$('#empty-state').hidden);c.dom.window.close();for(const s of ['loading','error']){const d=open(style,'devices','?state='+s);assert(!d.$('#'+s+'-state').hidden);assert(d.$('#table-region').hidden);d.dom.window.close();}});
 check(style+' — detalle del equipo y confirmación de borrado',()=>{const c=open(style,'device','?id=125&tenant=atlas');assert.equal(c.$('#device-name').textContent,'ATL-PC-125');c.$('[data-command=Borrar]').click();assert(c.$('#action-dialog').open);assert.equal(c.d.activeElement.id,'dialog-cancel');assert(c.$('#dialog-description').textContent.includes('Atlas Consultores'));c.input('#action-reason','Retiro de activo');c.input('#confirm-name','incorrecto');assert(!c.$('#confirm-name').checkValidity());c.input('#confirm-name','ATL-PC-125');assert(c.$('#dialog-form').checkValidity());c.submit('#dialog-form');assert(!c.$('#action-dialog').open);assert(c.$('#command-feedback').textContent.includes('En cola'));assert(c.$('#timeline').textContent.includes('Retiro de activo'));c.dom.window.close();});
 check(style+' — política: ámbitos, dominio, horario y revisión',()=>{const c=open(style,'policies','?tenant=azc');assert.equal(c.$('#impact-count').textContent,'124');c.change('#policy-scope','global');assert.equal(c.$('#impact-count').textContent,'180');c.change('#policy-scope','user');assert.equal(c.$('#impact-count').textContent,'2');c.change('#policy-scope','device');assert.equal(c.$('#impact-count').textContent,'1');c.input('#domains','https://dominio.com/ruta');c.submit('#policy-form');assert(!c.$('#domain-error').hidden);c.input('#domains','ejemplo.com');c.input('#end-time','07:00');c.submit('#policy-form');assert(!c.$('#schedule-error').hidden);c.input('#end-time','18:00');c.submit('#policy-form');assert(c.$('#action-dialog').open);c.submit('#dialog-form');assert(c.$('#draft-status').textContent.includes('Revisión 13'));c.dom.window.close();});
 check(style+' — usuarios: búsqueda, paginación y edición de acceso',()=>{const c=open(style,'users','?tenant=atlas');assert.equal(c.all('#user-rows tr').length,12);c.$('#user-next').click();assert(c.$('#user-count').textContent.includes('13–24'));c.input('#user-search','Laura Méndez');assert(c.all('#user-rows tr').length>0);c.$('[data-edit-user]').click();c.change('#new-role','Administrador');c.submit('#dialog-form');assert(c.$('#user-rows tr').textContent.includes('Administrador'));c.dom.window.close();});
 check(style+' — rebrand y contraste insuficiente',()=>{const c=open(style,'settings','?tenant=azc');assert(!c.$('#brand-fieldset').disabled);assert(!c.$('#brand-form button[type=submit]').disabled);c.input('#brand-name','AZC Legal');c.submit('#brand-form');assert.equal(c.$('#tenant').selectedOptions[0].textContent,'AZC Legal');c.input('#brand-accent',themes[style].surface);assert(c.$('#brand-form button[type=submit]').disabled);c.$('#brand-reset').click();assert(!c.$('#brand-form button[type=submit]').disabled);assert.equal(c.$('#tenant').selectedOptions[0].textContent,'Grupo AZC');c.change('#tenant','all');assert(c.$('#brand-fieldset').disabled);assert(c.$('#create-key').disabled);assert.deepEqual(c.errors,[]);c.dom.window.close();});
 check(style+' — API keys: crear y revocar',()=>{const c=open(style,'settings','?tenant=azc');c.$('#create-key').click();c.input('#key-name','Mesa QA');c.submit('#dialog-form');assert.equal(c.all('#api-rows tr').length,2);c.$('[data-revoke-key="1"]').click();c.submit('#dialog-form');assert.equal(c.all('#api-rows tr').length,1);assert(c.$('#key-feedback').textContent.includes('DEMO_NO_VALIDA'));c.dom.window.close();});
 check(style+' — login: validación de entrada y mostrar contraseña',()=>{const c=open(style,'login');assert(!c.$('#login-form').checkValidity());c.input('input[type=email]','persona@example.com');c.input('#password','demo1234');assert(c.$('#login-form').checkValidity());c.$('#show-password').click();assert.equal(c.$('#password').type,'text');c.$('#show-password').click();assert.equal(c.$('#password').type,'password');c.dom.window.close();});
 if(style==='terminal')check('terminal — atajos, contexto, ayuda y campos de texto',()=>{const c=open(style,'devices','?tenant=atlas');assert.equal(c.all('nav a[aria-keyshortcuts]').length,5);assert(c.$('.terminal-path').textContent.includes('Atlas Consultores'));c.d.body.dispatchEvent(new c.dom.window.KeyboardEvent('keydown',{key:'/',bubbles:true,cancelable:true}));assert.equal(c.d.activeElement.id,'search');const typing=new c.dom.window.KeyboardEvent('keydown',{key:'?',bubbles:true,cancelable:true});c.$('#search').dispatchEvent(typing);assert(!typing.defaultPrevented);assert(!c.$('#terminal-shortcuts').open);c.d.body.dispatchEvent(new c.dom.window.KeyboardEvent('keydown',{key:'?',bubbles:true,cancelable:true}));assert(c.$('#terminal-shortcuts').open);let selected='';c.$('nav').addEventListener('click',e=>{e.preventDefault();selected=e.target.closest('a')?.getAttribute('href')||'';});c.d.body.dispatchEvent(new c.dom.window.KeyboardEvent('keydown',{key:'2',altKey:true,bubbles:true,cancelable:true}));assert.equal(selected,'devices.html?tenant=atlas');c.$('[data-command=Borrar]').click();const protectedKey=new c.dom.window.KeyboardEvent('keydown',{key:'/',bubbles:true,cancelable:true});c.d.body.dispatchEvent(protectedKey);assert(!protectedKey.defaultPrevented);assert.deepEqual(c.errors,[]);c.dom.window.close();});
 check(style+' — CSS local, adaptable y foco visible',()=>{const css=fs.readFileSync(path.join(root,style,'styles.css'),'utf8');assert(!/@import|url\(\s*["']?(?:https?:|\/\/)/i.test(css));assert(css.includes('@media(max-width:600px)'));assert(css.includes(':focus-visible'));assert(css.includes('@media(forced-colors:active)'));assert(css.includes('@media(prefers-reduced-motion:reduce)'));if(style==='brutalista')assert(!/(?:linear|radial|conic)-gradient\(/i.test(css));else if(style==='neomorfismo')assert(css.includes('inset 4px 4px 8px'));});
 const t=themes[style];
 if(t.art)check(style+' — invitar usuario conserva el contexto de empresa',()=>{const c=open(style,'users','?tenant=atlas');c.$('#invite-user').click();assert(c.$('#action-dialog').open);assert.equal(c.all('#invite-tenant option').length,1);assert.equal(c.$('#invite-tenant').value,'atlas');c.input('#invite-email','persona@example.com');c.submit('#dialog-form');assert(!c.$('#action-dialog').open);assert(c.$('#toast').textContent.includes('No se envía correo'));assert.deepEqual(c.errors,[]);c.dom.window.close();});
 if(t.light){const r=ratio(t.accent,t.hover);check(style+' — contraste de acento sobre hover / fondo mínimo',()=>assert(r>=4.5,r.toFixed(2)+':1'));contrasts.push(`| ${style} | Acento sobre hover / mínimo | ${t.accent} | ${t.hover} | ${r.toFixed(2)}:1 |`);}
 check(style+' — tokens CSS coinciden con los defaults del tema',()=>{const c=open(style,'index');const computed=c.dom.window.getComputedStyle(c.d.documentElement);for(const [token,value]of [['--ink',t.ink],['--muted',t.muted],['--surface',t.surface],['--accent',t.accent],['--accent-ink',t.accentInk]])assert.equal(computed.getPropertyValue(token).trim().toUpperCase(),value.toUpperCase());c.dom.window.close();});
 for(const [label,fg,bg]of [['Principal',t.ink,t.surface],['Secundario',t.muted,t.surface],['Sobre acento',t.accentInk,t.accent],['Acento como texto',t.accent,t.surface],['Secundario en hover',t.muted,t.hover]]){
  const r=ratio(fg,bg);check(style+' — contraste '+label,()=>assert(r>=4.5,r.toFixed(2)+':1'));contrasts.push(`| ${style} | ${label} | ${fg} | ${bg} | ${r.toFixed(2)}:1 |`);
 }
}
const report=`# Validación de las propuestas

Fecha: 15 de septiembre de 2026.

## Resultado comprobado

- ${results.length} comprobaciones aprobadas; ${failures.length} fallos.
- ${Object.keys(themes).length*8} HTML parseados y ejecutados en JSDOM; JavaScript inline comprobado con el parser de Node.
- Recursos y navegación: todos los atributos src/href inspeccionados resuelven a archivos locales existentes; CSS sin imports ni recursos remotos.
- Semántica: idioma, viewport, un h1/main por página, IDs únicos, imágenes con alt y nombres de controles.
- Interacción: tenant, KPIs, filtros, paginación, estados, destinatario de comandos, confirmación escrita, políticas, usuarios, branding y claves ficticias.
- Contrastes calculados desde los tokens sRGB. El editor impide aplicar un acento que incumpla 4.5:1 en texto sobre el botón o en texto de acento sobre la superficie.

## Límite de la comprobación

**No se completó una inspección visual en navegador.** El pipeline de Playwright no pudo iniciar Chromium por «spawn EPERM». El intento más reciente está documentado en VALIDACION-NAVEGADOR.md. No se capturaron pantallas ni se midió el layout móvil real.

JSDOM verifica DOM e interacciones, pero no renderiza. Sus diálogos están emulados para probar callbacks; no acredita confinamiento de foco, Escape, lector de pantalla, navegación nativa ni comportamiento visual. El CSS contempla 600/700/900/1150 px, scroll local de tablas, foco visible, movimiento reducido y colores forzados; falta contrastarlo visualmente en 390 px, 1440 px y zoom 200 %.

La validación de archivos locales confirma ausencia de dependencias externas; no sustituye abrirlos manualmente en el navegador. El archivo _tools/validate.cjs deja preparada esa comprobación con Playwright, incluyendo capturas e interacciones.

## Contraste de texto

| Estilo | Uso | Texto | Fondo | Relación |
|---|---|---|---|---|
${contrasts.join('\n')}

El gris del escudo #9A9A9A se reserva a marca y gráficos, no a texto informativo pequeño.

En todas las variantes corporativas claras se verifica fondo claro y contraste del acento sobre hover. El umbral de superficie admite papel cálido (canales ≥ 220); cada par de texto se comprueba además por luminancia WCAG, con mínimo 4.5:1. Las cinco direcciones de arte agregan composiciones HTML propias y mantienen los mismos flujos. La revisión visual sigue pendiente de navegador.

## Comprobaciones

${results.map(r=>'- OK: '+r).join('\n')}
${failures.length?'\n## Fallos\n\n'+failures.map(f=>'- '+f).join('\n'):''}
`;
fs.writeFileSync(path.join(root,'VALIDACION.md'),report);
console.log(JSON.stringify({passed:results.length,failures,contrasts},null,2));process.exitCode=failures.length?1:0;
