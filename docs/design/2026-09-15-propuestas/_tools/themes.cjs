const themes = {
 brutalista: {accent:'#B52A19',accentInk:'#F8F8F8',ink:'#303030',muted:'#595959',surface:'#F8F8F8',hover:'#E8E8E8',logo:'../assets/logo_main.png'},
 neomorfismo: {accent:'#245D68',accentInk:'#F8F8F8',ink:'#303030',muted:'#595959',surface:'#E4E4E4',hover:'#D9D9D9',logo:'../assets/logo_main.png'},
 terminal: {accent:'#A7E66E',accentInk:'#303030',ink:'#F8F8F8',muted:'#B8B8B8',surface:'#171717',hover:'#303030',logo:'../assets/Logo%20White.png'},
 editorial: {accent:'#244AB8',accentInk:'#F8F8F8',ink:'#303030',muted:'#595959',surface:'#F8F8F8',hover:'#EAEAEA',logo:'../assets/logo_main.png'},
 industrial: {accent:'#FFD24A',accentInk:'#303030',ink:'#F8F8F8',muted:'#C1C1C1',surface:'#303030',hover:'#404040',logo:'../assets/Logo%20White.png'},
 'flat-corporativo': {light:true,accent:'#285AA8',accentInk:'#F8F8F8',ink:'#303030',muted:'#595959',surface:'#F6F6F6',hover:'#EBEBEB',logo:'../assets/logo_main.png'},
 'material-claro': {light:true,accent:'#675095',accentInk:'#F8F8F8',ink:'#303030',muted:'#595959',surface:'#F3F3F3',hover:'#E8E8E8',logo:'../assets/logo_main.png'},
 'glassmorphism-claro': {light:true,accent:'#306D77',accentInk:'#F8F8F8',ink:'#303030',muted:'#555555',surface:'#EFEFEF',hover:'#E5E5E5',logo:'../assets/logo_main.png'},
 'minimal-lineas': {light:true,accent:'#416A60',accentInk:'#F8F8F8',ink:'#303030',muted:'#595959',surface:'#F8F8F8',hover:'#EEEEEE',logo:'../assets/logo_main.png'},
 'soft-cards': {light:true,accent:'#526A43',accentInk:'#F8F8F8',ink:'#303030',muted:'#595959',surface:'#F1F1F1',hover:'#E6E6E6',logo:'../assets/logo_main.png'},
 'editorial-premium': {art:true,light:true,accent:'#754A63',accentInk:'#F8F8F8',ink:'#303030',muted:'#595959',surface:'#F8F8F8',hover:'#EFEFEF',logo:'../assets/logo_main.png'},
 'bauhaus-geometrico': {art:true,light:true,accent:'#B02E22',accentInk:'#F8F8F8',ink:'#303030',muted:'#555555',surface:'#F8F8F8',hover:'#ECECEC',logo:'../assets/logo_main.png'},
 'blueprint-tecnico': {art:true,light:true,accent:'#275B80',accentInk:'#F8F8F8',ink:'#303030',muted:'#555555',surface:'#F8F8F8',hover:'#ECECEC',logo:'../assets/logo_main.png'},
 'humanista-calido': {art:true,light:true,accent:'#8A4938',accentInk:'#F8F8F8',ink:'#303030',muted:'#5D574F',surface:'#F7F5F0',hover:'#ECE7DF',logo:'../assets/logo_main.png'},
 'swiss-datos': {art:true,light:true,accent:'#B1242A',accentInk:'#F8F8F8',ink:'#303030',muted:'#555555',surface:'#F8F8F8',hover:'#EAEAEA',logo:'../assets/logo_main.png'}
};
const titles = {
 terminal:['estado_flota','Directorio de equipos','Ficha del nodo','Reglas del espacio','Directorio de usuarios','Configuración del espacio','Estados de interfaz'],
 editorial:['La flota, en contexto.','El inventario.','Ficha del equipo.','Criterios de protección.','Personas y permisos.','Una identidad propia.','Cuando cambia el estado.'],
 industrial:['CENTRO DE OPERACIONES','INVENTARIO DE UNIDADES','FICHA DE UNIDAD','CONTROL DE POLÍTICAS','PERSONAL AUTORIZADO','CONFIGURACIÓN DEL SISTEMA','ESTADOS DE OPERACIÓN']
};
const pageOrder=['index','devices','device','policies','users','settings','states'];
function terminalRuntime(){
 const search=document.querySelector('#search, #user-search');
 document.querySelectorAll('nav[aria-label="Navegación principal"] a').forEach((a,i)=>a.setAttribute('aria-keyshortcuts','Alt+'+(i+1)));
 document.addEventListener('keydown',e=>{
  if(document.querySelector('dialog[open]')||e.target.closest('input,textarea,select,[contenteditable=true]'))return;
  if(e.altKey&&!e.ctrlKey&&!e.metaKey&&/^[1-5]$/.test(e.key)){e.preventDefault();document.querySelectorAll('nav[aria-label="Navegación principal"] a')[Number(e.key)-1]?.click();}
  if(e.key==='/'&&!e.ctrlKey&&!e.metaKey&&!e.altKey){e.preventDefault();if(search)search.focus();else{const a=document.querySelector('nav a[href^="devices.html"]');if(a)a.click();}}
  if(e.key==='?'&&!e.ctrlKey&&!e.metaKey){const help=document.querySelector('#terminal-shortcuts');if(help){e.preventDefault();help.open=!help.open;help.querySelector('summary').focus();}}
 });
}
function decorate(html,style,page){
 const theme=themes[style],isNew=!!titles[style];
 const active=['neomorfismo','minimal-lineas',...Object.keys(themes).filter(key=>themes[key].art)];
 const next=active[(active.indexOf(style)+1)%active.length];
 html=html.replace(/(<a href="\.\.\/)(brutalista|neomorfismo)(\/[^" ]+">Comparar estilo)/,`$1${next}$3`);
 if(theme.art)return require('./art-layouts.cjs').decorateArt(html,style,page,theme);
 if(theme.light)return decorateLight(html,style,page);
 if(!isNew)return html;
 const dark=style==='terminal'||style==='industrial';
 html=html.replace('name="color-scheme" content="light"',`name="color-scheme" content="${dark?'dark':'light'}"`);
 html=html.replaceAll('src="../assets/logo_main.png"',`src="${theme.logo}"`);
 const n=pageOrder.indexOf(page),title=titles[style][n];
 if(page==='index')html=html.replace('Tu flota, en perspectiva.',title);
 else if(page!=='login'&&page!=='device')html=html.replace(/<h1>[^<]+<\/h1>/,`<h1>${title}</h1>`);
 if(page==='login'){
  const loginTitles={terminal:'keeper@control<br><span class="prompt-title">$ iniciar_sesión<span class="cursor" aria-hidden="true">▌</span></span>',editorial:'La confianza<br>empieza por<br>el control.',industrial:'ACCESO AL<br>CENTRO DE<br>OPERACIONES'};
  html=html.replace('Todo conectado.<br>Todo en su lugar.',loginTitles[style]);
  html=html.replace('<span>WINDOWS FLEET MANAGEMENT</span>',`<span>${style==='terminal'?'TTY 01 · SESIÓN LOCAL':style==='editorial'?'EL SISTEMA DETRÁS DE CADA EQUIPO':'UNIDAD DE GESTIÓN / WINDOWS'}</span>`);
  if(style==='terminal')html=html.replace('BIENVENIDO A TU ESPACIO','AUTH / ACCESO AL ESPACIO');
 }else{
  let context='';
  if(style==='terminal')context=`<div class="terminal-path"><span class="prompt-user">operador@keeper</span><span class="path">: ~/empresas/<span data-tenant-label>Todas las empresas</span>/${page}</span><span class="cursor" aria-hidden="true">▌</span></div><div class="terminal-command"><span aria-hidden="true">$</span> <span>${{index:'consultar flota --resumen',devices:'listar equipos --paginado',device:'inspeccionar equipo',policies:'editar políticas --revisar',users:'listar usuarios --alcance',settings:'configurar empresa',states:'inspeccionar estados'}[page]}</span><span class="read-only-note">LECTURA · CORTE 09:42 COT</span></div>`;
  if(style==='editorial')context=`<div class="editorial-running"><span>AZC KEEPER / CUADERNO DE OPERACIONES</span><span>15 SEPTIEMBRE 2026</span><span>VOL. 04 — ${String(n+1).padStart(2,'0')}</span></div>`;
  if(style==='industrial')context=`<div class="instrument-rail"><span class="rail-identity">KPR—04 <b>CONTROL DE FLOTA</b></span><span><i class="signal" aria-hidden="true"></i> CORTE VÁLIDO / 09:42 COT</span><span>OPERADOR <b>NH / NIVEL 01</b></span></div><div class="instrument-path">SISTEMA / <span data-tenant-label>Todas las empresas</span> / ${page.toUpperCase()} <span class="plate">MÓDULO ${String(n+1).padStart(2,'0')}</span></div>`;
  html=html.replace('<main id="main" tabindex="-1">','<main id="main" tabindex="-1">'+context);
  if(style==='terminal')html=html.replace('<footer>',`<details id="terminal-shortcuts" class="shortcut-help"><summary>Atajos de teclado [?]</summary><p><kbd>Alt</kbd> + <kbd>1</kbd>–<kbd>5</kbd>: módulos · <kbd>/</kbd>: buscar · <kbd>?</kbd>: esta ayuda · <kbd>Esc</kbd>: cerrar diálogo.</p><p>Los atajos no se activan al escribir en un campo. En móvil usa la navegación y los filtros visibles.</p></details><footer class="terminal-status"><span class="terminal-mode">NORMAL</span>`);
  if(style==='editorial')html=html.replace('<footer>',`<footer class="editorial-colophon"><span class="folio">${String(n+1).padStart(2,'0')} / 07</span>`);
  if(style==='industrial')html=html.replace('<footer>',`<footer class="instrument-footer"><span class="rail-identity">KPR—04 / ${String(n+1).padStart(2,'0')}</span>`);
 }
 if(style==='terminal')html=html.replace('</body>',`<script>(${terminalRuntime.toString()})();</script></body>`);
 return html;
}
module.exports={themes,decorate};

function decorateLight(html,style,page){
 const names={'flat-corporativo':'Administración de flota','material-claro':'Tu espacio de administración','glassmorphism-claro':'Una visión clara de tu flota','minimal-lineas':'Resumen de flota','soft-cards':'Tu flota, en orden'};
 const loginTitles={'flat-corporativo':'Cada equipo.<br>En su lugar.','material-claro':'Organiza.<br>Protege.<br>Administra.','glassmorphism-claro':'Visibilidad para<br>cada decisión.','minimal-lineas':'Lo esencial,<br>bajo control.','soft-cards':'Un espacio claro.<br>Un trabajo<br>más sencillo.'};
 html=html.replace('<html lang="es"',`<html data-family="corporativo-claro" lang="es"`);
 if(page==='index')html=html.replace('Tu flota, en perspectiva.',names[style]);
 html=html.replaceAll('CENTRO DE CONTROL','ADMINISTRACIÓN DE EQUIPOS');
 html=html.replace('<div class="edition"><span>KEEPER / 04</span><span>ADMIN</span></div>','<div class="edition"><span>Administración</span><span>V4</span></div>');
 html=html.replace('TU ESPACIO, CONECTADO','ESPACIO DE TRABAJO');
 if(page==='login'){
  html=html.replace('Todo conectado.<br>Todo en su lugar.',loginTitles[style]);
  html=html.replace('<span>WINDOWS FLEET MANAGEMENT</span>','<span>Administración de equipos Windows</span>');
 }else{
  const pageNames={index:'Vista general',devices:'Equipos',device:'Detalle del equipo',policies:'Políticas',users:'Usuarios',settings:'Configuración',states:'Estados de interfaz'};
  const crumb=`<div class="light-context"><span data-tenant-label>Todas las empresas</span><span aria-hidden="true">/</span><span>${pageNames[page]}</span></div>`;
  html=html.replace('<main id="main" tabindex="-1">','<main id="main" tabindex="-1">'+crumb);
  if(style==='material-claro'&&page==='index')html=html.replace('</main><footer>',`<a class="material-fab" href="devices.html"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/></svg>Buscar equipo</a></main><footer>`);
 }
 return html;
}
