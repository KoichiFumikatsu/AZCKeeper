const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {pathToFileURL}=require('node:url');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/FumiWork/proyectos/portal-azc/node_modules/playwright-core');
const root=path.resolve(__dirname,'..'),styles=['neomorfismo-antislop','minimal-lineas-antislop'];
const pages=['login','index','devices','device','policies','users','settings','states'];
const checks=[],failures=[],shots=[];
async function check(name,fn){try{await fn();checks.push(name);}catch(e){failures.push(name+': '+e.message);}}
function write(blocked){fs.writeFileSync(path.join(root,'VALIDACION-ANTISLOP-NAVEGADOR.md'),`# Recorrido de navegador antislop

Fecha: 16 de septiembre de 2026.

${blocked?'**BLOQUEADO: '+blocked+'**\n\nChromium no arrancó. Cero páginas renderizadas, cero capturas y cero interacciones verificadas en navegador. El Delivery Gate visual no pasa.':''}

Comprobaciones realizadas: ${checks.length}. Fallos durante el recorrido: ${failures.length}.

Se ha preparado lectura de archivos file://, sin servidor. Anchuras previstas: 320, 390, 768, 1024 y 1440 px. Zoom tipográfico 200 %, recorrido de Tab, contraste según tokens, diálogos, navegación, filtros, políticas, personas, identidad e integraciones. La cobertura DOM se registra por separado y no reemplaza este recorrido.

## Evidencia ejecutada

${checks.length?checks.map(c=>'- PASS: '+c).join('\n'):'No hay evidencia visual ejecutada.'}
${failures.length?'\n## Fallos\n\n'+failures.map(c=>'- FAIL: '+c).join('\n'):''}

## Capturas

${shots.length?shots.map(s=>'- '+s).join('\n'):'No generadas.'}

## Repetir en un entorno que permita Chromium

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/antislop-browser.cjs
~~~

Solo después de completar y revisar el recorrido se pueden aprobar R-03, R-32 y R-35. Un resultado nuevo no actualiza automáticamente los README: el gate requiere revisión humana del informe y las capturas.
`);}
(async()=>{
 const browser=await chromium.launch({headless:true});
 for(const style of styles){
  const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage();
  const errors=[],network=[];page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(/^https?:/.test(r.url()))network.push(r.url());});
  const url=(p,q='')=>pathToFileURL(path.join(root,style,p+'.html')).href+q;
  for(const p of pages){
   await page.goto(url(p));
   for(const width of [320,390,768,1024,1440]){
    await page.setViewportSize({width,height:900});
    await check(style+'/'+p+' / ancho '+width,async()=>{assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Overflow horizontal');assert(await page.locator('img').evaluateAll(imgs=>imgs.every(i=>i.complete&&i.naturalWidth)),'Imagen no cargada');});
   }
   if(['index','login','devices'].includes(p))for(const width of [390,1440]){await page.setViewportSize({width,height:1000});const name='preview-'+p+'-'+width+'.png';await page.screenshot({path:path.join(root,style,name),fullPage:true});shots.push(style+'/'+name);}
   await page.setViewportSize({width:390,height:844});
   await check(style+'/'+p+' / objetivos visibles de 44 px',async()=>{const small=await page.locator('a,button,input:not([type=checkbox]),select,textarea,summary').evaluateAll(els=>els.filter(e=>e.getClientRects().length&&!e.disabled).filter(e=>{const b=e.getBoundingClientRect();return b.width<43.9||b.height<43.9;}).map(e=>e.id||e.textContent));assert.deepEqual(small,[]);});
   await check(style+'/'+p+' / texto 200 %',async()=>{await page.evaluate(()=>document.documentElement.style.fontSize='200%');assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Overflow a 200 %');await page.evaluate(()=>document.documentElement.style.fontSize='');});
   await check(style+'/'+p+' / Tab y foco visible',async()=>{await page.keyboard.press('Tab');assert(await page.evaluate(()=>document.activeElement!==document.body));assert(await page.evaluate(()=>getComputedStyle(document.activeElement).outlineStyle!=='none'));});
  }
  await check(style+' / menú móvil y Escape',async()=>{await page.goto(url('devices'));await page.setViewportSize({width:390,height:844});await page.locator('.mobile-menu summary').click();assert(await page.locator('.mobile-menu').getAttribute('open')!==null);await page.keyboard.press('Escape');assert.equal(await page.locator('.mobile-menu').getAttribute('open'),null);});
  await check(style+' / empresa y enlaces',async()=>{await page.goto(url('index'));await page.selectOption('#tenant','atlas');assert.equal(await page.textContent('#total-count'),'56');await page.locator('#priority-list a.hostname').first().click();assert(page.url().includes('tenant=atlas'));assert((await page.textContent('#device-owner')).includes('Atlas'));});
  await check(style+' / filtros y paginación',async()=>{await page.goto(url('devices'));await page.fill('#search','AZC-LT-001');assert.equal(await page.locator('#device-rows tr').count(),1);await page.locator('#filters button[type=reset]').click();await page.locator('#next-page').click();assert((await page.textContent('#page-label')).includes('9 a 16'));await page.fill('#search','INEXISTENTE');assert(await page.locator('#filter-empty').isVisible());await page.locator('#reset-empty').click();});
  for(const verb of ['Bloquear','Apagar','Borrar'])await check(style+' / '+verb+' y cancelación nativa',async()=>{await page.goto(url('device','?id=125&tenant=atlas'));const action=page.locator('[data-command="'+verb+'"]');await action.click();assert(await page.locator('#dialog-cancel').evaluate(e=>e===document.activeElement));await page.keyboard.press('Escape');assert(!await page.locator('#action-dialog').isVisible());assert(await action.evaluate(e=>e===document.activeElement));await action.click();await page.fill('#command-reason','Prueba de muestra');if(verb==='Borrar')await page.fill('#confirm-hostname','ATL-PC-125');await page.locator('#dialog-confirm').click();assert((await page.textContent('#command-feedback')).includes('en cola local'));await page.locator('#dismiss-feedback').click();});
  await check(style+' / publicar política',async()=>{await page.goto(url('policies','?tenant=azc'));await page.selectOption('#policy-scope','global');assert.equal(await page.textContent('#impact-count'),'180');await page.selectOption('#policy-scope','user');assert.equal(await page.textContent('#impact-count'),'2');await page.selectOption('#policy-scope','device');assert.equal(await page.textContent('#impact-count'),'1');await page.fill('#domains','example.org');await page.locator('#policy-form button[type=submit]').click();await page.locator('#dialog-confirm').click();assert((await page.textContent('#draft-status')).includes('Revisión 13 publicada'));});
  await check(style+' / acceso de personas e invitación',async()=>{await page.goto(url('users','?tenant=atlas'));await page.locator('[data-role]').first().click();await page.selectOption('#new-role','Administrador');await page.locator('#dialog-confirm').click();assert((await page.textContent('#user-rows tr')).includes('Administrador'));await page.locator('#dismiss-feedback').click();await page.locator('#invite-user').click();await page.fill('#invite-email','persona@example.com');await page.locator('#dialog-confirm').click();assert((await page.textContent('#feedback')).includes('No se envió correo'));});
  await check(style+' / marca, logo, contraste y claves',async()=>{await page.goto(url('settings','?tenant=azc'));await page.fill('#brand-name','Empresa de muestra');await page.locator('#brand-logo').setInputFiles(path.join(root,'assets','logo.png'));await page.locator('#brand-form button[type=submit]').click();assert.equal(await page.locator('#tenant option:checked').textContent(),'Empresa de muestra');await page.locator('#dismiss-feedback').click();await page.locator('#brand-accent').evaluate(e=>{e.value='#EEEEEE';e.dispatchEvent(new Event('input',{bubbles:true}));});assert(await page.locator('#brand-form button[type=submit]').isDisabled());await page.locator('#brand-reset').click();await page.locator('#dismiss-feedback').click();await page.locator('#create-key').click();await page.fill('#key-name','QA');await page.locator('#dialog-confirm').click();assert.equal(await page.locator('#api-rows tr').count(),2);await page.locator('[data-revoke]').first().click();await page.locator('#dialog-confirm').click();assert.equal(await page.locator('#api-rows tr').count(),1);});
  for(const p of ['index','devices','device','policies','users','settings'])for(const state of ['empty','loading','error'])await check(style+'/'+p+' / estado '+state,async()=>{await page.goto(url(p,'?tenant=atlas&state='+state));assert(await page.locator('#view-state').isVisible());await page.locator('#state-return').click();assert(!page.url().includes('state='));assert(page.url().includes('tenant=atlas'));});
  await check(style+' / login local',async()=>{await page.goto(url('login'));await page.fill('#login-email','persona@example.com');await page.fill('#password','muestra123');await page.locator('#show-password').click();assert.equal(await page.locator('#password').getAttribute('type'),'text');await page.locator('#access-help').click();await page.locator('#dialog-confirm').click();await page.locator('#login-form button[type=submit]').click();assert(page.url().includes('index.html?tenant=azc'));});
  await check(style+' / consola y red',async()=>{assert.deepEqual(errors,[]);assert.deepEqual(network,[]);});
  await context.close();
 }
 await browser.close();write();console.log(JSON.stringify({passed:checks.length,failures},null,2));process.exitCode=failures.length?1:0;
})().catch(error=>{write(error.message.split('\n')[0]);console.error(error.message.split('\n')[0]);process.exitCode=1;});
