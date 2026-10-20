const { chromium } = require(process.env.KEEPER_PLAYWRIGHT_MODULE || 'playwright');
const http = require('node:http'), fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../public');
const tenant = '00000000-0000-4000-8000-000000000002', id = '00000000-0000-4000-8000-000000000010';
let full = true, lost = false, rule = null;
const writes = [], reads = [];
const point = { id, device_id:id, user_id:id, at:new Date().toISOString(), latitude:null, longitude:null, accuracy_m:null, network_type:'cable', wifi_ssid:null, wifi_bssid:null, result:'sin_dato', site_id:null, site_name:null, matched_by:null, in_work_hours:true };
const settings = {public_ips:[],wifi:[],latitude:null,longitude:null,radius_m:null,version:1,suggested_ips:['8.8.8.8']};
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname.startsWith('/v1/')) {
    res.setHeader('Content-Type','application/json');
    const route = url.pathname.slice(3); let body = '';
    for await (const c of req) body += c;
    const input = body ? JSON.parse(body) : null;
    assert.equal(req.headers['x-tenant-id'], tenant);
    if (req.method !== 'GET') { assert.equal(req.headers['x-csrf-token'], 'fixture'); writes.push({route,input,match:req.headers['if-match']}); }
    else reads.push(req.url);
    if (route === '/me/access') return res.end(JSON.stringify({ platform:false, features:['ubicacion'], permissions:['presencia.ubicacion_ver','equipos.editar','reglas.editar','reglas.ver','organizacion.editar'].map(code => ({code,scopes:[full?'tenant':'area']})) }));
    if (route.endsWith('/locations')) {
      const include = url.searchParams.get('include_out_of_hours') === 'true';
      if (include && !full) { res.statusCode=403; return res.end('{"code":"permission_denied"}'); }
      const extra = {...point,id:'00000000-0000-4000-8000-000000000011',result:'fuera',latitude:4.6,longitude:-74.1,accuracy_m:35,network_type:'wifi',wifi_ssid:'<script>bad()</script>',in_work_hours:false};
      return res.end(JSON.stringify({data:include?[point,extra]:[point],latest:include?extra:point,next_cursor:null,can_include_out_of_hours:full,lost_at:lost?point.at:null,lost_reason:lost?'Prueba':null}));
    }
    if (route.endsWith('/lost')) {
      if (input.confirmation !== (input.lost?'PERDIDO':'CERRAR')) { res.statusCode=422; return res.end('{"code":"validation_failed"}'); }
      lost=input.lost; return res.end(JSON.stringify({lost_at:lost?point.at:null,lost_reason:lost?input.reason:null}));
    }
    if (route.endsWith('/rules')) return res.end(JSON.stringify({own_policies:rule?[rule]:[],location_tracking:rule?.location_tracking||false}));
    if (route === '/policies' || route.startsWith('/policies/')) {
      if (rule) assert.equal(req.headers['if-match'], `"${rule.version}"`);
      rule={...input,id,version:(rule?.version||0)+1}; return res.end(JSON.stringify(rule));
    }
    if (route.endsWith('/location')) { if (input) Object.assign(settings,input); return res.end(JSON.stringify(settings)); }
    res.statusCode=404; return res.end('{"code":"resource_not_found"}');
  }
  if (url.pathname === '/') {
    res.setHeader('Content-Type','text/html; charset=utf-8');
    return res.end(`<main><div id="person"></div><div id="device"></div><div id="rule"></div><div id="site"></div></main><script type="module">
      import {setTenant,saveCsrf} from '/assets/api.js';
      import {mountLocation,mountLocationRule,mountSiteLocation} from '/assets/location.js';
      setTenant('${tenant}');saveCsrf('fixture');
      await mountLocation(document.querySelector('#person'),{type:'user',id:'${id}'});
      await mountLocation(document.querySelector('#device'),{type:'device',id:'${id}'});
      await mountLocationRule(document.querySelector('#rule'),{type:'device',id:'${id}'});
      await mountSiteLocation(document.querySelector('#site'),'${id}');window.ready=true;
    </script>`);
  }
  const file=path.resolve(root,'.'+url.pathname);
  if (!file.startsWith(root+path.sep)) { res.statusCode=404; return res.end(); }
  res.setHeader('Content-Type','text/javascript; charset=utf-8');
  fs.createReadStream(file).on('error',()=>{res.statusCode=404;res.end();}).pipe(res);
});
(async () => {
  await new Promise(r=>server.listen(0,'127.0.0.1',r));
  const browser=await chromium.launch({headless:true});
  try {
    const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto(`http://127.0.0.1:${server.address().port}`);await page.waitForFunction(()=>window.ready);
    assert.equal(await page.getByRole('link',{name:'Ver en mapa'}).count(),0);
    const device=page.locator('#device');
    await device.getByLabel('Incluir fuera de horario',{exact:false}).check();
    await device.getByRole('link',{name:'Ver en mapa'}).first().waitFor();
    assert.ok((await device.getByRole('link',{name:'Ver en mapa'}).first().getAttribute('href')).startsWith('https://www.google.com/maps?q='));
    assert.equal(await device.locator('script').count(),0);
    await device.getByLabel('Motivo').fill('Prueba');
    await device.getByLabel('Escribe PERDIDO',{exact:false}).fill('PERDIDO');
    await device.getByRole('button',{name:'Marcar como perdido/robado',exact:true}).click();
    await device.getByLabel('Escribe CERRAR',{exact:false}).fill('CERRAR');
    await device.getByRole('button',{name:'Cerrar caso',exact:true}).click();
    await device.getByLabel('Escribe PERDIDO',{exact:false}).waitFor();assert.equal(lost,false);
    await page.getByLabel('Activar para este equipo').check();await page.getByRole('button',{name:'Guardar ubicación',exact:true}).click();
    await page.getByRole('status').last().waitFor();assert.equal(rule.location_tracking,true);
    await page.getByLabel('Activar para este equipo').uncheck();await page.getByRole('button',{name:'Guardar ubicación',exact:true}).click();
    await page.waitForFunction(()=>document.querySelectorAll('#rule [role=status]').length===2);assert.equal(rule.location_tracking,false);
    await page.getByText('Cómo reconocer esta sede',{exact:true}).click();
    await page.getByRole('button',{name:'Añadir 8.8.8.8'}).click();
    await page.getByLabel('Redes Wi-Fi',{exact:false}).fill('Oficina | aa:bb:cc:dd:ee:ff');
    await page.getByRole('button',{name:'Guardar reconocimiento'}).click();
    await page.getByText('Sede guardada.',{exact:false}).waitFor();assert.deepEqual(settings.public_ips,['8.8.8.8']);assert.equal(settings.wifi[0].ssid,'Oficina');
    const count=reads.length;
    const date=device.getByLabel('Fecha',{exact:false});
    const yesterday=new Date();yesterday.setDate(yesterday.getDate()-1);
    await date.fill(yesterday.toISOString().slice(0,10));await date.dispatchEvent('change');
    await page.waitForTimeout(100);assert.ok(reads.length>count);
    full=false;await page.reload();await page.waitForFunction(()=>window.ready);
    assert.equal(await page.getByLabel('Incluir fuera de horario',{exact:false}).first().isVisible(),false);
    assert.deepEqual(errors,[]);
    console.log('PASS location panel: historial, mapas, privacidad, perdido/cierre, regla y sede');
  } finally { await browser.close();server.close(); }
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
