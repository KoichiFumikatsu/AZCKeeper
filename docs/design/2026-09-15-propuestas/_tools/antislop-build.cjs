const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const {pageBody}=require('./antislop-pages.cjs');
const original=fs.readFileSync(path.join(root,'neomorfismo','index.html'),'utf8');
const devices=JSON.parse(original.match(/const DEVICES=(\[.*?\]);const TENANTS=/s)[1]);
const tenants={all:'Todas las empresas',azc:'Grupo AZC',atlas:'Atlas Consultores',nueva:'Nueva empresa'};
const themes={
 'neomorfismo-antislop':{original:'neomorfismo',accent:'#245D68',surface:'#E4E4E4',hover:'#D9D9D9',name:'Neomorfismo antislop',voice:'Equipos bajo cuidado'},
 'minimal-lineas-antislop':{original:'minimal-lineas',accent:'#416A60',surface:'#F8F8F8',hover:'#EEEEEE',name:'Minimal líneas antislop',voice:'Registro de equipos'}
};
const titles={login:'Acceso',index:'Revisión de flota',devices:'Inventario de equipos',device:'Ficha del equipo',policies:'Política de protección',users:'Personas y acceso',settings:'Identidad e integraciones',states:'Escenarios de uso'};
const sections={index:'Revisar',devices:'Equipos',policies:'Políticas',users:'Personas',settings:'Configuración'};
const context={index:'Atiende incidencias antes de enviar comandos.',devices:'Confirma conexión, empresa y destinatario antes de actuar.',device:'Distingue lo solicitado de lo confirmado por el agente.',policies:'Revisa el alcance antes de publicar una revisión.',users:'El acceso al panel pertenece a una empresa. Una persona puede tener varios equipos.',settings:'Selecciona una empresa para editar su marca y sus claves.',states:'Prueba cómo se recupera cada pantalla cuando faltan datos o falla la consulta.'};
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const stateRegion=p=>`<section id="view-state" class="state-sheet" hidden aria-live="polite"><p class="record-label">${titles[p]}</p><h2 id="state-title"></h2><p id="state-description"></p><a id="state-return" class="button" href="${p}.html">Volver a los datos de muestra</a></section>`;
const dialog=`<dialog id="action-dialog" aria-labelledby="dialog-title" aria-describedby="dialog-description"><form id="dialog-form"><h2 id="dialog-title">Revisar acción</h2><p id="dialog-description"></p><div id="dialog-fields"></div><div class="dialog-actions"><button type="button" id="dialog-cancel">Cancelar</button><button type="submit" id="dialog-confirm" class="primary">Confirmar</button></div></form></dialog><aside id="feedback" class="feedback" hidden aria-label="Resultado de la acción"><p id="feedback-message" role="status" aria-live="polite"></p><button id="dismiss-feedback" type="button">Cerrar aviso</button></aside>`;
for(const [slug,theme]of Object.entries(themes)){
 const dir=path.join(root,slug);fs.mkdirSync(dir,{recursive:true});
 fs.writeFileSync(path.join(dir,'styles.css'),fs.readFileSync(path.join(__dirname,'antislop-base.css'),'utf8')+'\n'+fs.readFileSync(path.join(__dirname,slug+'.css'),'utf8'));
 for(const page of Object.keys(titles)){
  const nav=Object.entries(sections).map(([key,label])=>`<a href="${key}.html"${key===page||(page==='device'&&key==='devices')?' aria-current="page"':''}>${label}</a>`).join('');
  const brand='<a class="brand" href="index.html"><img src="../assets/logo_main.png" alt="AZC Keeper" width="168" height="65"></a>';
  const header=page==='login'?`<header class="access-header">${brand}<p>${theme.voice}</p></header>`:`<header class="site-header">${brand}<nav class="desktop-nav" aria-label="Principal">${nav}</nav><details class="mobile-menu"><summary>Menú de navegación</summary><nav aria-label="Principal móvil">${nav}<a href="states.html">Escenarios</a><a href="login.html">Salir de la muestra</a></nav></details><div class="tenant-field"><label for="tenant">Empresa</label><select id="tenant">${Object.entries(tenants).map(([id,name])=>`<option value="${id}">${name}</option>`).join('')}</select></div></header>`;
  const heading=page==='login'?'':`<header class="page-head"><div><h1>${titles[page]}</h1><p>${context[page]}</p></div><div class="page-context"><span data-tenant-label>Todas las empresas</span><span>Corte de muestra: 15 sep 2026, 09:42 COT</span></div></header>`;
  const view=pageBody(page,{devices,tenants,theme,esc});
  const body=page==='login'?view:`${heading}${page==='states'?view:`${stateRegion(page)}<div id="view-content">${view}</div>`}`;
  const other=slug==='neomorfismo-antislop'?'minimal-lineas-antislop':'neomorfismo-antislop';
  const config={page,theme,devices,tenants};
  fs.writeFileSync(path.join(dir,page+'.html'),`<!doctype html>
<html lang="es" data-style="${slug}" data-page="${page}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>${titles[page]} | AZC Keeper</title><link rel="icon" href="../assets/favicon.ico"><link rel="stylesheet" href="styles.css"></head><body><a class="skip-link" href="#main">Saltar al contenido</a><div class="canvas">${header}<p class="sample-notice"><strong>Muestra de diseño.</strong> Equipos, personas y eventos ficticios. Las acciones solo se simulan.</p><main id="main" tabindex="-1">${body}</main><footer class="site-footer"><span>AZC Keeper · ${theme.name}</span><nav aria-label="Comparación y escenarios"><a href="states.html">Probar estados</a><a href="../${theme.original}/${page}.html">Comparar con el original</a><a href="../${other}/${page}.html">Ver la otra variante</a>${page==='login'?'':'<a href="login.html">Salir de la muestra</a>'}</nav></footer></div>${dialog}<noscript><p class="sample-notice">La navegación funciona sin JavaScript. Actívalo para simular filtros, empresa, formularios y comandos.</p></noscript><script>const CONFIG=${JSON.stringify(config)};\n${fs.readFileSync(path.join(__dirname,'antislop-runtime.js'),'utf8')}</script></body></html>`);
 }
}
console.log('16 HTML y 2 CSS generados exclusivamente en las variantes antislop.');
module.exports={themes,titles};
