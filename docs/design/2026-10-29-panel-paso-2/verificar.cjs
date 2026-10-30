/* Verificación local sin paquetes ni navegador. Ejecutar: node verificar.cjs */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = __dirname;
let links = 0;
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
function checkLink(source, href) {
  assert(!/^(https?:)?\/\//i.test(href), `${source}: dependencia externa ${href}`);
  assert(!href.startsWith('/'), `${source}: ruta absoluta incompatible con file://`);
  const [file, hash] = href.split('#');
  const target = file.split('?')[0] || source;
  const resolved = path.resolve(root, target);
  assert(resolved.startsWith(root + path.sep), `Ruta fuera del entregable: ${href}`);
  assert(fs.existsSync(resolved), `${source}: enlace roto ${href}`);
  if (hash) assert(new RegExp(`id=["']${hash}["']`).test(read(target)), `${source}: ancla ausente ${href}`);
  links++;
}
for (const file of ['index.html', 'persona.html', 'administrar.html', 'asistente-ia.html', 'equipo.html', 'problemas.html', 'programacion.html']) {
  const html = read(file);
  assert(html.includes('lang="es-CO"'));
  assert(html.includes('name="viewport"'));
  assert(!/type="module"/.test(html), 'file:// debe funcionar con scripts clásicos');
  const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map(m => m[1]);
  assert.equal(new Set(ids).size, ids.length, `IDs duplicados en ${file}`);
  for (const match of html.matchAll(/\b(?:href|src)="([^"]+)"/g)) checkLink(file, match[1]);
}
for (const match of read('styles.css').matchAll(/url\(['"]?([^)'" ]+)/g)) checkLink('styles.css', match[1]);
for (const file of ['data.js', 'app.js']) {
  new vm.Script(read(file), { filename: file });
  assert(!/\b(?:fetch|XMLHttpRequest|WebSocket)\s*\(/.test(read(file)), 'La maqueta no debe consultar servicios');
}
const app = read('app.js');
for (const match of app.matchAll(/['"]((?:index|persona|administrar|asistente-ia|equipo|problemas|programacion)\.html)['"]/g)) checkLink('app.js', match[1]);
for (const match of app.matchAll(/src="([^"$]+)"/g)) checkLink('app.js', match[1]);
assert(!/\b(?:tenant|enforcer|release|enrolado)\b/i.test(app + read('index.html') + read('persona.html') + read('administrar.html')), 'Jerga fuera del vocabulario visible');
const context = { window: {} };
vm.createContext(context);
vm.runInContext(read('data.js'), context);
const D = context.window.DEMO;
assert.equal(D.people.length, 300);
assert.equal(new Set(D.people.map(p => p.id)).size, 300);
assert.equal(new Set(D.people.map(p => p.name)).size, 300);
assert.equal(new Set(D.people.map(p => p.client)).size, 1);
assert.equal(D.people.filter(p => D.inScope(p, 'supervisor')).length, 25);
assert.equal(D.inScope(D.people[100], 'supervisor'), false);
assert.equal(D.dayFor(D.people[0], '2026-10-20'), null);
const counts = Object.fromEntries(['connected', 'offline', 'away'].map(state => [state, D.people.filter(p => p.state === state).length]));
assert.equal(Object.values(counts).reduce((a, b) => a + b, 0), 300);
for (const p of D.people) {
  for (const day of [D.today, D.yesterday]) {
    const d = D.dayFor(p, day);
    if (d.missing || d.absent) continue;
    assert(d.active >= 0 && d.idle >= 0 && d.locked >= 0);
    assert.equal(d.apps.reduce((n, a) => n + a.minutes, 0), d.active, `${p.id}: apps y total activo`);
    assert(d.productive <= d.active && d.productive <= d.expected);
    assert.equal(d.active + d.idle + d.locked, d.cutoff - d.arrival);
    const toMin = time => Number(time.slice(0, 2)) * 60 + Number(time.slice(3));
    assert.equal(toMin(d.timeline[0].start), d.arrival);
    assert.equal(toMin(d.timeline.at(-1).end), d.cutoff);
    d.timeline.forEach((event, i) => { if (i) assert.equal(event.start, d.timeline[i - 1].end); });
  }
  for (const device of D.devicesFor(p)) assert(/^DESKTOP-[A-Z0-9]{4}$/.test(device.hostname));
}
const example = D.dayFor(D.people[0], D.today);
assert.equal(example.active, 315);
assert.equal(example.productive, 276);
assert.equal(Math.round(example.productive / example.expected * 100), 71);
assert.equal(D.dayFor(D.people[3], D.today).expected, 0);
assert.equal(D.dayFor(D.people[6], D.today).active, null);
console.log(`OK: ${links} referencias locales; HTML y JS estáticos; 300 personas únicas; alcance de supervisor de 25 personas.`);
console.log(`Contadores iniciales: ${counts.connected} conectadas, ${counts.offline} sin conexión, ${counts.away} sin actividad reciente, ${D.people.filter(p => D.alertsFor(p, D.today).length).length} con alertas.`);
console.log('OK: tiempos, apps, cronología y fórmula coherentes para hoy y ayer. Sin solicitudes de red.');
console.log('Pendiente: validación visual y de interacción en navegador a 1366×768 y 375 px.');
const alertTypes = new Set(D.people.flatMap(p => D.alertsFor(p, D.today).map(a => a.type)));
assert.deepEqual([...alertTypes].sort(), ['arrival', 'dual', 'offline', 'suspicious']);
assert(D.alertsFor(D.people[0], D.today).length > 1, 'Debe existir una persona con varias alertas');
assert.equal(D.alertsFor(D.people[3], D.today).some(a => a.type === 'offline'), false, 'Ausencia aprobada no es desconexión en horario');
assert.equal(D.alertsFor(D.people[7], D.today).some(a => a.type === 'offline'), false, 'Sin equipo no es equipo desconectado');
for (const p of D.people) for (const a of D.alertsFor(p, D.today)) {
  assert.equal(a.severity, ['suspicious'].includes(a.type) ? 'alta' : 'media');
  assert(app.includes(`id="${a.target}"`) || app.includes('id="alert-${a.type}"'), `Destino de alerta ausente: ${a.target}`);
}
const ordered = D.sortPeople(D.people);
const ranks = ordered.map(p => { const a = D.alertsFor(p, D.today); return a.length ? a[0].severity === 'alta' ? 0 : 1 : 2; });
assert(ranks.every((rank, i) => i === 0 || rank >= ranks[i - 1]), 'Alertas altas antes de medias y resto');
for (const order of ['attention', 'name', 'productivity', 'active']) {
  const sorted = D.sortPeople(D.people, order);
  assert.equal(new Set(sorted.map(p => p.id)).size, 300);
  assert(new Set(sorted.slice(0, 10).map(p => p.name.split(' ').at(-1))).size >= 5, 'Apellidos diversos en primera página');
}
assert.equal(D.sortPeople(D.people, 'active').at(-1).missing || !D.sortPeople(D.people, 'active').at(-1).hasDevice, true);
const s = D.summary(D.people);
assert.equal(s.nonproductive.length, 6);
assert.equal(s.alerts, D.people.filter(p => D.alertsFor(p, D.today).length).length);
assert.equal(s.measured, 297);
assert.equal(s.weekCount, 298);
assert.equal(s.percent, s.productive / s.expected * 100);
assert.equal(s.delta, s.current.percent - s.previous.percent);
assert.equal(D.summary([]).percent, null);
assert.equal(D.summary([]).delta, null);
assert.equal(D.summary([D.people[6]]).percent, null);
const scoped = D.people.filter(p => D.inScope(p, 'supervisor'));
assert(D.summary(scoped).nonproductive.every(p => D.inScope(p, 'supervisor')));
assert.equal(D.summary(scoped).total, 25);
for (const list of [D.people, scoped, []]) {
  const payload = D.aiPayload(list);
  const serialized = JSON.stringify(payload);
  assert(!/DESKTOP-|Grupo AZC|AZC Jurídica|p001|api[_-]?key/i.test(serialized));
  for (const p of D.people) assert(!serialized.includes(p.name), 'Identidad filtrada al proveedor');
  assert.equal(JSON.parse(payload.messages[2].content).personas, list.length);
}
assert(!/\bAusentes?\b/.test(app + read('index.html')), 'Etiqueta obsoleta en interfaz');
assert(!/localStorage|sessionStorage/.test(app), 'No persistir clave ni contexto en navegador');
const contract = fs.readFileSync(path.resolve(root, '../../architecture/openapi-v4.yaml'), 'utf8');
for (const route of ['/reports/people', '/reports/presence', '/users/{id}/activity', '/users/{id}/windows', '/reports/dual-job-alerts', '/reports/suspicious-apps', '/devices/{id}/security']) {
  assert(contract.includes(`  ${route}:`), `Ruta documentada inexistente: ${route}`);
}
console.log(`OK vuelta 3: cuatro alertas, severidad/orden, apellidos variados, KPI con cobertura, comparación ponderada, seis personas >60 min, payload sin identidades y rutas existentes.`);
console.log(JSON.stringify({ productividad: Number(s.percent.toFixed(1)), tardanzas: s.late, alertas: s.alerts, altas: s.high, semanaActual: Number(s.current.percent.toFixed(1)), semanaAnterior: Number(s.previous.percent.toFixed(1)), diferenciaPp: Number(s.delta.toFixed(1)) }));

assert(!app.includes("['alerts', 'Con alertas'"), 'Sin contador duplicado');
assert(!read('data.js').includes("type: 'protection'"));
assert(app.includes('Ver detalle técnico'));
assert(app.includes("if (page === 'administrar') administer();"));
for (const p of D.people) {
  const original = JSON.stringify(D.alertsFor(p, D.today));
  assert.equal(JSON.stringify(D.alertsFor({ ...p, partialProtection: !p.partialProtection }, D.today)), original);
}
console.log('OK: protección no altera alertas, KPI, orden ni agregados del asistente; configuración en página propia.');

const O = context.window.ORDERS;
assert.equal(O.devices.length, 300);
assert.equal(new Set(O.devices.map(d => d.hostname)).size, 300);
assert.deepEqual([...new Set(O.orders.map(o => o.status))].sort(), ['cancelled', 'delivered', 'expired', 'failed', 'pending', 'succeeded']);
assert.deepEqual([...new Set(O.devices.flatMap(d => O.problems(d).map(p => p.kind)))].sort(), ['offline', 'order', 'protection', 'version']);
assert(O.devices.filter(d => O.problems(d).length).length > 10, 'Paginación ejercitable');
for (const d of O.devices) {
  assert(D.people.some(p => p.id === d.person.id));
  for (const type of Object.keys(O.actions)) assert(O.allowed(d, type, 'supervisor'), 'El supervisor de ejemplo es de lectura');
}
assert.match(O.allowed(O.getDevice('DESKTOP-0005'), 'restart', 'superadmin'), /plan/);
assert.match(O.allowed(O.getDevice('DESKTOP-0009'), 'wipe', 'superadmin'), /plan/);
assert.equal(O.allowed(O.getDevice('DESKTOP-0005'), 'refresh_policy', 'superadmin'), '');
const originalCount = O.orders.length;
assert(O.create(['DESKTOP-0001', 'DESKTOP-0005'], 'restart', 'superadmin', { notice: 300, workConfirmed: true }).error);
assert.equal(O.orders.length, originalCount, 'Un lote inválido no envía parcialmente');
assert(O.create(['DESKTOP-0001'], 'wipe', 'superadmin', { confirmation: 'DESKTOP-OTRO', reauth: true }).error);
assert(O.create(['DESKTOP-0001'], 'wipe', 'superadmin', { confirmation: 'DESKTOP-0001' }).error);
assert(O.create(['DESKTOP-0001', 'DESKTOP-0501'], 'wipe', 'superadmin').error);
assert(O.create(['DESKTOP-0001'], 'restart', 'superadmin', { notice: 300 }).error, 'Requiere advertencia laboral');
assert(O.create(['DESKTOP-0001'], 'lock', 'superadmin', { notice: 300 }).error, 'Bloqueo no admite cuenta regresiva');
assert(O.create(['DESKTOP-0001'], 'shutdown', 'superadmin', { notice: 120, workConfirmed: true }).error);
assert(O.create(['DESKTOP-0001'], 'lock', 'supervisor').error);
assert(O.create(['NO-EXISTE'], 'lock', 'superadmin').error);
assert(O.create([], 'lock', 'superadmin').error);
const locked = O.create(['DESKTOP-0501'], 'lock', 'superadmin', { reason: '' }).orders[0];
assert.match(locked.reason, /desde el panel/);
assert.match(O.stateText(locked), /sin conexión/);
assert.equal(Date.parse(locked.expires) - Date.parse(locked.created), 23 * 3600000);
assert.equal(O.cancel(locked.id, 'supervisor'), 'Tu rol no permite cancelar esta orden.');
assert.equal(O.cancel(locked.id, 'superadmin', 'Error de selección'), '');
assert.equal(locked.status, 'cancelled');
assert.equal(O.simulate(locked.id, 'deliver'), false);
const wiped = O.create(['DESKTOP-0001'], 'wipe', 'superadmin', { confirmation: 'DESKTOP-0001', reauth: true }).orders[0];
assert.match(O.cancel(wiped.id, 'superadmin'), /autenticación/);
assert.equal(O.cancel(wiped.id, 'superadmin', '', true), '');
const restarted = O.create(['DESKTOP-0001'], 'restart', 'superadmin', { notice: 300, workConfirmed: true }).orders[0];
assert.equal(O.simulate(restarted.id, 'deliver'), true);
assert.equal(O.cancel(restarted.id, 'superadmin'), '');
assert.equal(restarted.status, 'delivered', 'Pedir cancelar no equivale a cancelada');
assert.equal(O.simulate(restarted.id, 'ackCancel'), true);
assert.equal(restarted.status, 'cancelled');
const race = O.create(['DESKTOP-0001'], 'shutdown', 'superadmin', { notice: 60, workConfirmed: true }).orders[0];
O.simulate(race.id, 'deliver'); O.cancel(race.id, 'superadmin'); O.simulate(race.id, 'success');
assert.equal(race.status, 'succeeded'); assert.match(race.detail, /ya se ejecutó/);
const immediate = O.create(['DESKTOP-0001'], 'restart', 'superadmin', { workConfirmed: true }).orders[0];
O.simulate(immediate.id, 'deliver');
assert.match(O.cancelReason(immediate, 'superadmin'), /al recibirla/);
O.simulate(immediate.id, 'fail'); assert.equal(immediate.status, 'failed'); assert(immediate.detail);
const old = O.create(['DESKTOP-0002'], 'restart', 'superadmin', { notice: 300, workConfirmed: true }).orders[0];
O.simulate(old.id, 'deliver'); assert.match(O.cancelReason(old, 'superadmin'), /4\.0\.27/);
const planDevice = O.getDevice('DESKTOP-0009');
const reduced = O.create([planDevice.hostname], 'lock', 'superadmin').orders[0];
planDevice.features = [];
assert.equal(O.cancel(reduced.id, 'superadmin'), '', 'Cancelar no depende del plan según backend');
const batch = O.create(['DESKTOP-0001', 'DESKTOP-0501', 'DESKTOP-0002'], 'refresh_policy', 'superadmin').orders;
assert.equal(batch.length, 3); assert.equal(new Set(batch.map(o => o.id)).size, 3);
O.simulate(batch[1].id, 'expire');
assert.equal(batch[1].status, 'expired'); assert.equal(O.simulate(batch[1].id, 'deliver'), false);
assert(O.cancelReason(batch[1], 'superadmin'));
for (const route of ['/me/access', '/devices/{id}', '/devices/{id}/commands', '/devices/{id}/commands/{commandId}/cancel', '/auth/reauth', '/admin/tenants/{id}/unlock-pin', '/admin/tenants/{id}/unlock-pin/reveal', '/admin/power-schedules', '/admin/power-schedules/{id}', '/admin/power-schedules/{id}/runs', '/tenants/{id}/protection', '/audit']) assert(contract.includes(`  ${route}:`), `Ruta inexistente: ${route}`);
assert(!app.includes('Bloquear equipo · paso 2'));
assert(app.includes('Revisar orden para selección'));
assert(app.includes('Ver detalle técnico'));
for (const file of fs.readdirSync(root).filter(f => /\.(html|js|css)$/.test(f))) {
  const source = read(file);
  assert(!source.includes('\uFFFD'), `${file}: caracteres dañados`);
  assert(!/\b[\p{L}]+\?[\p{L}]+\b/u.test(source), `${file}: acento convertido a signo de pregunta`);
}
console.log('OK paso 2: roles/planes, cuatro problemas, seis estados, confirmación escrita, reautenticación, lote sin envío parcial, aviso, cancelación pendiente/entregada/tardía, versión mínima y vencimiento.');

assert.equal(D.people.filter(p => D.inScope(p, 'supervisor-orders')).length, 25);
const allowedDevice = O.getDevice('DESKTOP-0001');
for (const type of ['lock', 'unlock', 'restart', 'shutdown']) assert.equal(O.allowed(allowedDevice, type, 'supervisor-orders'), '');
for (const type of ['wipe', 'refresh_policy']) assert(O.allowed(allowedDevice, type, 'supervisor-orders'));
assert(O.allowed(O.getDevice('DESKTOP-0002'), 'lock', 'supervisor-orders'));
const supervisorOrder = O.create(['DESKTOP-0001'], 'lock', 'supervisor-orders').orders[0];
assert.match(supervisorOrder.actor, /Supervisora/);
O.setPermission(false);
assert(O.create(['DESKTOP-0001'], 'lock', 'supervisor-orders').error);
assert(O.cancelReason(supervisorOrder, 'supervisor-orders'));
O.setPermission(true);
assert.equal(O.cancel(supervisorOrder.id, 'supervisor-orders'), '');
assert.equal(O.businessDaysWithoutSignal('2026-10-27'), 2);
assert.equal(O.businessDaysWithoutSignal('2026-10-28'), 1);
assert.equal(O.businessDaysWithoutSignal('2026-10-23', '2026-10-27'), 2);
assert.equal(O.businessDaysWithoutSignal('2026-10-09', '2026-10-13', ['2026-10-12']), 1);
assert.equal(O.businessDaysWithoutSignal('2026-10-27', '2026-10-29', [], ['2026-10-28']), 1);
const missing = O.getDevice('DESKTOP-0501');
missing.approvedAbsences = ['2026-10-28'];
assert(!O.problems(missing).some(p => p.kind === 'offline'));
missing.approvedAbsences = [];
assert(O.problems(missing).some(p => p.kind === 'offline'));
const groups = O.groupedProblems();
assert.equal(groups.filter(g => g.kind === 'protection').length, 1);
assert(groups.find(g => g.kind === 'protection').devices.length > 1);
assert(groups.some(g => g.devices.length === 1));
for (const g of groups) assert.equal(new Set(g.devices.map(d => d.hostname)).size, g.devices.length);
assert(O.groupedProblems(O.devices, 'offline').every(g => g.kind === 'offline'));
assert(!app.includes('Una fila por equipo'));
assert(app.includes('supervisor-permission') && app.includes('Supervisor con órdenes'));
assert(read('styles.css').includes('.compact-order-grid'));
console.log('OK cierre paso 2: agrupación, selección única, tercer rol, alcance, revocación y 2 días hábiles con exclusiones.');
