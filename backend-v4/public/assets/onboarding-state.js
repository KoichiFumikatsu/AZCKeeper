import { api as realApi, ApiError, PLATFORM_TENANT } from './api.js';
import { allowed, canSeeOnboarding } from './nav-access.js';

// Evaluador de Puesta en marcha (docs/design/2026-10-06-fase4-puesta-en-marcha.md §3). Solo lee (GET): el avance se
// calcula con datos guardados, no se persiste ni se marca por visitas. Un error nunca se convierte en lista vacía:
// cada consulta devuelve { ok, data } o { ok: false, kind } con kind = not_contracted | denied | scope | reauth |
// network | error, y cada paso lo muestra como estado propio. El objetivo es el primer equipo, no la flota completa.

export const STATUS = {
  done: ['Hecho', 'good'], progress: ['En curso', 'info'], pending: ['Pendiente', 'warn'],
  optional: ['Opcional', ''], na: ['No aplica', ''], unavailable: ['No disponible', 'bad'],
};
export const STEP_TITLES = ['Preparar el cliente', 'Organizar el cliente', 'Jornadas y festivos', 'Personas y planes', 'Accesos del panel',
  'Protección inicial', 'Instalar Keeper', 'Comprobar el primer equipo'];
export { canSeeOnboarding };
// Permisos de administración: un rol con alguno cuenta como rol de administración del cliente (paso 5).
const ADMIN_PERMS = ['organizacion.editar', 'usuarios.crear', 'usuarios.editar', 'horarios.editar', 'equipos.enrolar'];
const RULE_FEATURES = ['web', 'usb', 'descargas', 'instalaciones'];
const OTHER_FEATURES = { clasificacion: 'Clasificación de apps propia', ausencias: 'Ausencias de RR. HH.', puertas: 'Entradas por puerta', cumplimiento: 'Doble empleo y apps sospechosas', cobertura: 'Cobertura de instalación' };
const PENDING_FEATURES = { api: 'API para otros sistemas', webhooks: 'Avisos automáticos (webhooks)' };
const DOMAIN_NAMES = { empresas: 'empresas', firmas: 'firmas', sedes: 'sedes', areas: 'áreas', cargos: 'cargos', horarios: 'horarios', festivos: 'festivos', personas: 'personas', jerarquia: 'jerarquía', ausencias: 'ausencias' };
const UNIT_DOMAIN = { company: 'empresas', site: 'sedes', area: 'areas', position: 'cargos' };
const UNIT_NAME = { site: 'una sede', area: 'un área', position: 'un cargo' };
const listY = items => items.length > 1 ? `${items.slice(0, -1).join(', ')} y ${items[items.length - 1]}` : items.join('');

const grant = (access, code) => (access?.permissions || []).find(p => p.code === code);
const tenantWide = (access, code) => !!access?.platform || !!grant(access, code)?.scopes?.includes('tenant');

// Clasifica un error de la API en un estado visible (§3.1): no contratada, permiso, alcance, sesión o red.
export function failure(error) {
  const detail = error?.problem?.detail || '';
  if (error?.status === 403 && /^feature_not_contracted:/.test(detail)) return { kind: 'not_contracted', feature: detail.split(':')[1] || '', error };
  if (error?.status === 403 && detail === 'scope_denied') return { kind: 'scope', error };
  if (error?.status === 401 || (error?.status === 403 && error.code === 'reauth_required')) return { kind: 'reauth', error };
  if (error?.status === 403 || error?.status === 404) return { kind: 'denied', error };
  if (error instanceof TypeError || error?.status >= 500 || error?.status === 429) return { kind: 'network', error };
  return { kind: 'error', error };
}
export const FAILURE_TEXT = {
  not_contracted: 'La función no está contratada por el cliente.',
  denied: 'No disponible para tu cuenta: no tiene permiso para consultar este dato.',
  scope: 'No disponible para tu cuenta: tu alcance no cubre todo el cliente.',
  reauth: 'Tu sesión venció: vuelve a iniciar sesión para comprobarlo.',
  network: 'No se pudo consultar (conexión o servidor). El dato puede estar desactualizado.',
  error: 'La API respondió con un error al consultar este dato.',
  partial: 'Solo se obtuvo una parte de la lista: el total no es fiable.',
};
const retryable = f => f && ['network', 'error', 'reauth'].includes(f.kind);

async function probe(fn) {
  try { return { ok: true, data: await fn() }; } catch (error) { return { ok: false, ...failure(error) }; }
}
// Agota la paginación (cursor u offset) antes de contar. Si se corta por tope de páginas, complete=false (dato parcial).
export async function fetchAll(apiFn, path, { mode = 'cursor', limit = 100, maxPages = 50 } = {}) {
  const rows = []; const seen = new Set(); let next = null; let pages = 0;
  do {
    const url = new URL(path, 'https://keeper.invalid'); url.searchParams.set('limit', String(limit));
    if (next !== null) url.searchParams.set(mode === 'cursor' ? 'cursor' : 'offset', String(next));
    const result = await apiFn('GET', url.pathname + url.search);
    if (!Array.isArray(result?.data)) throw new ApiError(200, { code: 'invalid_response' });
    rows.push(...result.data);
    next = (mode === 'cursor' ? result.next_cursor : result.next_offset) ?? null;
    if (next !== null && seen.has(String(next))) throw new ApiError(200, { code: 'invalid_response' });
    seen.add(String(next)); pages += 1;
    if (next !== null && pages >= maxPages) return { rows, complete: false };
  } while (next !== null);
  return { rows, complete: true };
}

const placeholder = o => /^sin\s/i.test(o.name || '');
const latestBy = (list, field) => [...list].sort((a, b) => String(b[field] || '').localeCompare(String(a[field] || '')))[0] || null;
export const exactTime = v => v ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'medium' }).format(new Date(v)) : 'Sin registro';

// Paso de la lista. action = { label, href, key } (key: permiso de pantalla de nav-access; sin permiso no se ofrece).
function step(n, extra) {
  return { n, title: STEP_TITLES[n - 1], required: true, status: 'pending', summary: '', lines: [], warnings: [], action: null, secondary: [], failure: null, ...extra };
}
const link = (label, href, key = null) => ({ label, href, key });
const at = (href, n) => `${href}${href.includes('?') ? '&' : '?'}pm=${n}`;
function unavailable(n, f, extra = {}) {
  return step(n, { status: f.kind === 'not_contracted' ? 'na' : 'unavailable', summary: FAILURE_TEXT[f.kind] || FAILURE_TEXT.error, failure: f, ...extra });
}

/**
 * Evalúa la puesta en marcha del cliente `tenant`. Devuelve { kind } con:
 * - 'platform_tenant' (el cliente reservado de la plataforma no tiene puesta en marcha),
 * - 'hidden' (la cuenta no administra el cliente: colaboradores y alcances parciales),
 * - 'error' (no se pudo leer el acceso propio; failure),
 * - 'ready' con steps, counts, pilot y finished.
 */
export async function evaluate({ tenant, apiFn = realApi }) {
  if (tenant === PLATFORM_TENANT) return { kind: 'platform_tenant' };
  const get = path => apiFn('GET', path);
  const accessP = await probe(() => get('/me/access'));
  if (!accessP.ok) return { kind: 'error', failure: accessP };
  const access = accessP.data; const platform = !!access.platform;
  if (!canSeeOnboarding(access)) return { kind: 'hidden', access };
  const features = new Set(access.features || []);
  const has = code => features.has(code);
  const ruleFeatures = RULE_FEATURES.filter(has);
  const skip = Promise.resolve(null);
  const [tenantP, sourcesP, domainsP, runsP, orgP, schedP, calP, usersP, accountsP, rolesP, plansP, hardP, policiesP, devicesP, approvedP] = await Promise.all([
    probe(() => get(`/tenants/${tenant}`)),
    probe(() => get('/data-sources')), probe(() => get('/data-domains')), probe(() => get('/sync-runs')),
    probe(() => fetchAll(apiFn, '/organization')), probe(() => fetchAll(apiFn, '/schedules')),
    has('festivos') ? probe(() => fetchAll(apiFn, '/schedules/holiday-calendars', { mode: 'offset' })) : skip,
    probe(() => fetchAll(apiFn, '/users')),
    probe(() => get('/panel-accounts')), probe(() => fetchAll(apiFn, '/roles')),
    probe(() => get('/person-plans/available')),
    has('endurecimiento') ? probe(() => get(`/admin/tenants/${tenant}/hardening`)) : skip,
    ruleFeatures.length ? probe(() => fetchAll(apiFn, '/policies')) : skip,
    probe(() => fetchAll(apiFn, `/tenants/${tenant}/devices`)),
    probe(() => fetchAll(apiFn, '/enrollments/requests?status=approved', { mode: 'offset' })),
  ]);
  const can = key => platform || allowed(access, key);

  // ---- Origen de los datos: qué dominios manda una fuente (AZC One) ------------------------------------------------
  const sources = sourcesP.ok ? sourcesP.data.data || [] : null;
  const owned = new Map();
  if (domainsP.ok) for (const d of domainsP.data.data || []) if (d.source_id) owned.set(d.domain, sources?.find(s => s.id === d.source_id)?.name || 'AZC One');
  const ownerOf = domain => owned.get(domain) || null;
  const usesOne = has('one') || (sources?.length ?? 0) > 0;
  const runs = runsP.ok ? runsP.data.data || [] : [];
  const applied = latestBy(runs.filter(r => r.status === 'applied' && !r.simulate && r.kind === 'full'), 'started_at');
  const reviewOne = (n, href, key) => link('Revisar lo que trae AZC One', at(href, n), key);
  const sourcesLink = n => link('Ver Fuentes de datos', at('/fuentes.php', n), 'sources');

  // ---- 1. Preparar el cliente -----------------------------------------------------------------------------------
  let s1;
  if (!tenantP.ok) s1 = unavailable(1, tenantP);
  else {
    const t = tenantP.data;
    s1 = step(1, {});
    const base = t.status === 'active' && !t.archived && !!t.timezone;
    s1.lines.push(`Cliente ${t.status === 'active' ? 'activo' : 'no activo'}${t.archived ? ' · archivado' : ''} · zona horaria ${t.timezone || 'sin definir'}.`);
    const live = [...features].filter(c => !PENDING_FEATURES[c]);
    s1.lines.push(live.length === 1 ? '1 función contratada.' : `${live.length} funciones contratadas.`);
    const pendingBought = [...features].filter(c => PENDING_FEATURES[c]).map(c => PENDING_FEATURES[c]);
    if (pendingBought.length) s1.lines.push(`${pendingBought.join(' y ')}: contratadas · pendientes de implementación (no cuentan para arrancar).`);
    if (plansP.ok) {
      const p = plansP.data; const def = (p.data || []).find(x => x.id === p.default_plan_id);
      s1.lines.push(def ? `Plan predeterminado: ${def.name}.` : 'Sin plan predeterminado: quien no tenga plan propio solo recibe el núcleo (recomendado definirlo antes de instalar).');
    } else s1.lines.push(`Plan predeterminado: ${FAILURE_TEXT[plansP.kind] || FAILURE_TEXT.error}`);
    // Origen: Keeper (local) o una fuente. Si no se pudo leer, no se supone local.
    let origin;
    if (!usesOne && (sourcesP.ok || sourcesP.kind === 'not_contracted')) origin = { status: 'done', text: 'Origen de los datos: Keeper (se cargan en este panel).' };
    else if (!sourcesP.ok) origin = { status: 'unavailable', text: `Origen de los datos: ${FAILURE_TEXT[sourcesP.kind] || FAILURE_TEXT.error}`, failure: sourcesP };
    else if (!sources.length) origin = { status: 'pending', text: platform ? 'Origen de los datos: el cliente usará AZC One y todavía no hay una fuente conectada.' : 'Conectar AZC One (lo hace AZC Keeper): todavía no hay una fuente conectada.' };
    else if (!domainsP.ok) origin = { status: 'unavailable', text: `Dueño de cada dato: ${FAILURE_TEXT[domainsP.kind] || FAILURE_TEXT.error}`, failure: domainsP };
    else if (!owned.size) origin = { status: 'progress', text: 'AZC One está conectado, pero todavía no manda en ningún dato (se eligen los dueños en Fuentes de datos).' };
    else if (!sources.some(s => s.mode === 'live' && s.active !== false)) origin = { status: 'progress', text: 'AZC One está en simulación: todavía no cambia datos reales. Una simulación no completa la carga.' };
    else if (!applied) origin = { status: 'progress', text: runsP.ok ? 'Aún no hay una carga completa aplicada desde AZC One.' : `Historial de cargas: ${FAILURE_TEXT[runsP.kind] || FAILURE_TEXT.error}` };
    else origin = { status: 'done', text: `Origen de los datos: AZC One manda en ${[...owned.keys()].map(d => DOMAIN_NAMES[d] || d).join(', ')} · última carga completa aplicada: ${exactTime(applied.finished_at || applied.started_at)}` };
    if (usesOne && applied?.pending) s1.warnings.push(`Hay ${applied.pending} decisiones pendientes de la última carga: revísalas en Fuentes de datos.`);
    s1.lines.push(origin.text);
    s1.failure = origin.failure || null;
    s1.status = !base ? 'pending' : origin.status;
    s1.summary = !base ? 'El cliente no está activo o le falta la zona horaria.' : origin.status === 'done' ? 'Cliente, funciones y origen de los datos resueltos.' : origin.status === 'unavailable' ? 'No se pudo comprobar el origen de los datos.' : 'Falta resolver el origen de los datos.';
    if (platform) {
      s1.action = origin.status === 'done' || !usesOne ? link('Revisar funciones y plan', at('/empresas.php', 1)) : link('Preparar origen', at('/fuentes.php', 1), 'sources');
      s1.secondary = usesOne ? [link('Revisar funciones y plan', at('/empresas.php', 1))].filter(l => l.href !== s1.action.href) : [];
    } else {
      s1.action = usesOne ? sourcesLink(1) : link('Revisar ajustes del cliente', at('/ajustes.php', 1), 'panel-settings');
      if (usesOne && origin.status !== 'done') s1.note = 'Conectar y sincronizar fuentes lo hace AZC Keeper.';
    }
  }

  // ---- Personas (se usan en varios pasos) ------------------------------------------------------------------------
  const org = orgP.ok ? orgP.data.rows : null;
  const unit = id => org?.find(o => o.id === id) || null;
  const valid = (id, kind) => { const u = unit(id); return !!u && u.kind === kind && u.active; };
  const users = usersP.ok ? usersP.data.rows : null;
  const activeUsers = users ? users.filter(u => u.status === 'active') : null;
  const activeIds = new Set((activeUsers || []).map(u => u.id));
  const readyPeople = activeUsers && org ? activeUsers.filter(u => valid(u.site_id, 'site') && valid(u.area_id, 'area') && valid(u.position_id, 'position')) : null;
  const devices = devicesP.ok ? devicesP.data.rows : null;
  const assigned = devices ? devices.filter(d => d.status === 'active' && d.user_id && (!users || activeIds.has(d.user_id))) : null;
  const pilotDevice = assigned?.length ? latestBy(assigned, 'last_seen_at') : null;
  const pilotUser = pilotDevice ? users?.find(u => u.id === pilotDevice.user_id) || null : null;

  // ---- 2. Organizar el cliente ----------------------------------------------------------------------------------
  let s2;
  if (!orgP.ok) s2 = unavailable(2, orgP);
  else {
    s2 = step(2, {});
    const real = org.filter(o => o.active && !placeholder(o));
    const count = kind => real.filter(o => o.kind === kind).length;
    const missing = ['site', 'area', 'position'].filter(k => !count(k));
    const fromOne = kind => ownerOf(UNIT_DOMAIN[kind]);
    const tag = kind => fromOne(kind) ? ` · las trae ${fromOne(kind)}` : '';
    const noCountry = real.filter(o => o.kind === 'site' && !o.country_code).length;
    s2.lines.push(`Sedes activas: ${count('site')}${noCountry ? ` (${noCountry} sin país: usan el calendario predeterminado)` : ''}${tag('site')}.`,
      `Áreas activas: ${count('area')}${tag('area')}.`, `Cargos activos: ${count('position')}${tag('position')}.`,
      `Empresas internas: ${count('company')} (opcional; sin empresa = corporativo)${tag('company')}.`);
    const localMissing = missing.filter(k => !fromOne(k));
    s2.status = !missing.length ? 'done' : missing.length < 3 ? 'progress' : 'pending';
    if (!missing.length) s2.summary = 'Hay sede, área y cargo disponibles para la primera persona.';
    else if (!localMissing.length) { s2.summary = `Aún no llegan desde AZC One: ${listY(missing.map(k => UNIT_NAME[k]))}.`; s2.status = 'progress'; }
    else s2.summary = `Crea ${listY(localMissing.map(k => UNIT_NAME[k]))} para registrar a la primera persona.${missing.length > localMissing.length ? ' El resto lo trae AZC One.' : ''}`;
    const allOne = ['site', 'area', 'position'].every(k => fromOne(k));
    if (allOne || (missing.length && !localMissing.length)) { s2.action = reviewOne(2, '/organizacion.php', 'organization'); s2.secondary = [sourcesLink(2)]; }
    else s2.action = link(missing.length ? 'Completar Organización' : 'Revisar Organización', at('/organizacion.php', 2), 'organization');
    if (!orgP.data.complete) { s2.warnings.push(FAILURE_TEXT.partial); if (missing.length) { s2.status = 'unavailable'; s2.failure = { kind: 'partial' }; } }
  }

  // ---- 3. Jornadas y festivos -----------------------------------------------------------------------------------
  let s3;
  const needsSchedule = has('presencia');
  if (!schedP.ok) s3 = unavailable(3, schedP, { required: needsSchedule });
  else {
    s3 = step(3, { required: needsSchedule });
    const withDays = schedP.data.rows.filter(s => Array.isArray(s.days) && s.days.length);
    const fromOne = ownerOf('horarios');
    s3.lines.push(withDays.length ? `Horarios con días laborales: ${withDays.length}${fromOne ? ` · los trae ${fromOne}` : ''}.` : 'Sin horarios con días laborales: no se espera jornada ni se calcula tardanza.');
    let calendarsReady = true;
    if (!has('festivos')) s3.lines.push('Festivos: no aplica (función no contratada); Presencia no descontará festivos.');
    else if (!calP?.ok) { calendarsReady = false; s3.lines.push(`Festivos: ${FAILURE_TEXT[calP?.kind] || FAILURE_TEXT.error}`); s3.failure = calP; }
    else {
      const cals = calP.data.rows.filter(c => c.active !== false);
      const filled = cals.filter(c => c.holidays > 0);
      calendarsReady = filled.length > 0;
      s3.lines.push(!cals.length ? 'Festivos: aún no hay calendarios.' : !filled.length ? 'Festivos: el calendario existe, faltan sus días.' : `Festivos: ${filled.length} calendario(s) con días cargados (revisar que el año esté completo).`);
    }
    const catalogs = withDays.length > 0 && calendarsReady;
    if (pilotUser && needsSchedule) {
      const own = withDays.find(s => s.id === pilotUser.schedule_id);
      s3.lines.push(own ? `${pilotUser.display_name} tiene horario «${own.name}».` : `${pilotUser.display_name} no tiene un horario con días: no se medirá su jornada.`);
    }
    if (!needsSchedule) { s3.status = catalogs ? 'done' : 'optional'; s3.summary = 'Presencia no está contratada: los horarios son opcionales para arrancar.'; }
    else if (catalogs && pilotUser && withDays.some(s => s.id === pilotUser.schedule_id)) { s3.status = 'done'; s3.summary = 'Horario asignado a la persona del primer equipo.'; }
    else if (catalogs) { s3.status = 'progress'; s3.summary = 'Catálogo preparado; falta asignarlo a la persona del primer equipo.'; }
    else if (withDays.length) { s3.status = 'progress'; s3.summary = 'Hay horarios; faltan los festivos.'; }
    else { s3.status = 'pending'; s3.summary = fromOne ? 'Aún no llegan horarios desde AZC One.' : 'Crea el horario de quien tendrá jornada medida.'; }
    if (s3.failure && s3.status !== 'done') s3.status = needsSchedule ? 'unavailable' : s3.status;
    s3.action = fromOne ? reviewOne(3, '/horarios.php', 'schedules') : link(withDays.length ? 'Revisar horarios' : 'Crear horario', at('/horarios.php', 3), 'schedules');
    if (has('festivos')) s3.secondary.push(ownerOf('festivos') ? link('Revisar festivos de AZC One', at('/festivos.php', 3), 'holidays') : link('Preparar calendarios', at('/festivos.php', 3), 'holidays'));
  }

  // ---- 4. Personas y planes -------------------------------------------------------------------------------------
  let s4;
  const peopleOwner = ownerOf('personas');
  if (!usersP.ok) s4 = unavailable(4, usersP);
  else if (!platform && !tenantWide(access, 'usuarios.ver')) s4 = step(4, { status: 'unavailable', summary: FAILURE_TEXT.scope, failure: { kind: 'scope' } });
  else {
    s4 = step(4, {});
    const monitored = (readyPeople || []).filter(u => u.monitoring !== 'excluded');
    const excluded = (readyPeople || []).length - monitored.length;
    s4.lines.push(`Personas activas: ${activeUsers.length}${peopleOwner ? ` · las trae ${peopleOwner}` : ''}.`);
    if (readyPeople) s4.lines.push(`Con sede, área y cargo válidos: ${readyPeople.length}${excluded ? ` (${excluded} excluidas de monitoreo)` : ''}.`);
    else s4.lines.push('No se pudo comprobar sede, área y cargo: Organización no está disponible.');
    if (plansP.ok) {
      const own = activeUsers.filter(u => u.plan_id).length;
      s4.lines.push(plansP.data.default_plan_id ? `Plan: heredado del cliente${own ? ` · ${own} con plan propio` : ''}.` : own ? `${own} personas con plan propio; sin plan predeterminado.` : 'Sin plan: las personas solo reciben el núcleo.');
    } else s4.lines.push(`Planes: ${FAILURE_TEXT[plansP.kind] || FAILURE_TEXT.error}`);
    if (monitored.length) { s4.status = 'done'; s4.summary = 'Hay al menos una persona preparada para el primer equipo.'; }
    else if (activeUsers.length) { s4.status = readyPeople ? 'progress' : 'unavailable'; s4.summary = excluded ? 'Las personas preparadas están excluidas de monitoreo.' : 'Hay personas, pero ninguna con sede, área y cargo válidos.'; }
    else { s4.status = 'pending'; s4.summary = peopleOwner ? `Aún no llegan personas desde ${peopleOwner}.` : 'Falta registrar a quien usará el primer equipo.'; }
    if (!usersP.data.complete && !monitored.length) { s4.status = 'unavailable'; s4.failure = { kind: 'partial' }; s4.warnings.push(FAILURE_TEXT.partial); }
    const canCreate = platform || tenantWide(access, 'usuarios.crear');
    if (peopleOwner) { s4.action = link('Revisar personas sincronizadas', at('/usuarios.php', 4), 'users'); s4.note = `Los datos de estas personas se corrigen en ${peopleOwner}.`; }
    else if (!activeUsers.length && canCreate) s4.action = link('Crear primera persona', at('/miembro.php?nueva=1', 4), 'users');
    else s4.action = link('Revisar personas', at('/usuarios.php', 4), 'users');
    if (!peopleOwner && !canCreate) s4.note = 'Crear personas lo hace AZC Keeper (autogestión de personas no contratada o sin permiso).';
  }

  // ---- 5. Accesos del panel -------------------------------------------------------------------------------------
  let s5;
  if (!platform) {
    // La sesión de un administrador del cliente prueba que el cliente ya tiene administración propia.
    s5 = step(5, { status: 'done', summary: 'Tu cuenta administra este cliente.' });
    if (accountsP.ok) s5.lines.push(`Cuentas del panel del cliente: ${(accountsP.data.data || []).filter(a => a.active).length} activas.`);
    else if (accountsP.kind === 'not_contracted') s5.lines.push('Más cuentas y roles: los prepara AZC Keeper (autogestión de accesos no contratada).');
    s5.action = accountsP.ok ? link('Revisar cuentas', at('/cuentas.php', 5), 'accounts') : null;
  } else if (!accountsP.ok) s5 = unavailable(5, accountsP);
  else {
    s5 = step(5, {});
    const list = (accountsP.data.data || []).filter(a => a.tenant_id === tenant && a.kind === 'company');
    const roles = rolesP.ok ? rolesP.data.rows : null;
    const adminRole = id => { if (!roles) return true; const r = roles.find(x => x.id === id); return !!r && r.active !== false && ADMIN_PERMS.some(c => (r.permissions || []).includes(c)); };
    const good = list.filter(a => a.active && a.user_id && (!users || activeIds.has(a.user_id)) && a.access?.scope?.kind === 'all' && adminRole(a.access.role_id));
    if (good.length) {
      s5.status = 'done';
      const tested = good.find(a => a.last_login_at && !a.must_change_password);
      s5.summary = tested ? `Administrador probado: ${tested.email} ya inició sesión.` : `Administrador preparado (${good[0].email}); aún no ha iniciado sesión.`;
    } else if (list.length) { s5.status = 'progress'; s5.summary = 'Hay cuentas del cliente, pero ninguna activa con rol de administración, persona activa y alcance Todo el cliente.'; }
    else { s5.status = 'pending'; s5.summary = 'Falta la cuenta del administrador del cliente, sobre su persona.'; }
    if (!rolesP.ok) s5.warnings.push(`Roles: ${FAILURE_TEXT[rolesP.kind] || FAILURE_TEXT.error} No se comprobaron sus permisos.`);
    s5.lines.push(`Cuentas del cliente: ${list.length} (${list.filter(a => a.active).length} activas).`, 'Tu sesión de plataforma no cuenta como administrador del cliente.');
    s5.action = good.length ? link('Revisar cuentas', at(`/cuentas.php?empresa=${encodeURIComponent(tenant)}`, 5)) : link('Crear cuenta del administrador', at(`/cuentas.php?empresa=${encodeURIComponent(tenant)}&crear=1`, 5));
  }

  // ---- 6. Protección inicial ------------------------------------------------------------------------------------
  const hardening = has('endurecimiento') ? (hardP?.ok ? { ...hardP.data, configured: hardP.data.shared_password !== null } : null) : null;
  let s6;
  if (!has('endurecimiento') && !ruleFeatures.length) s6 = step(6, { status: 'na', required: false, summary: 'No aplica: el cliente no tiene funciones de protección contratadas.' });
  else {
    s6 = step(6, { required: false });
    let itOk = true;
    if (has('endurecimiento')) {
      if (!hardP.ok) { itOk = false; s6.lines.push(`Cuenta de IT: ${FAILURE_TEXT[hardP.kind] || FAILURE_TEXT.error}`); s6.failure = hardP; }
      else {
        itOk = hardening.configured;
        s6.lines.push(hardening.configured ? `Cuenta de IT configurada (${hardening.admin_name}) · modo ${hardening.hardening_mode === 'auto' ? 'automático al instalar' : 'desde el panel'}.` : 'Cuenta de IT sin contraseña: necesaria antes de endurecer equipos.');
        if (hardening.hardening_mode === 'auto') s6.warnings.push('Endurecimiento automático: cada equipo se endurece solo al instalar Keeper. Para el piloto se recomienda «desde el panel».');
      }
    }
    let rulesOk = true;
    if (ruleFeatures.length) {
      if (!policiesP.ok) { rulesOk = false; s6.lines.push(`Reglas: ${FAILURE_TEXT[policiesP.kind] || FAILURE_TEXT.error}`); s6.failure ??= policiesP; }
      else {
        const enabled = policiesP.data.rows.filter(p => p.enabled).length; rulesOk = enabled > 0;
        s6.lines.push(enabled ? `Reglas activas: ${enabled}.` : 'Sin reglas: puedes empezar a monitorear sin bloqueos y añadirlos después de probar el primer equipo.');
      }
    }
    const auto = hardening?.hardening_mode === 'auto';
    if (auto) { s6.required = true; s6.status = 'pending'; s6.summary = 'Modo automático activo: revísalo antes de instalar.'; }
    else if (itOk && rulesOk) { s6.status = 'done'; s6.summary = 'Protección elegida preparada (configurarla no endurece ni bloquea por sí sola).'; }
    else { s6.status = 'optional'; s6.summary = 'Opcional para empezar a inventariar y monitorear. Configúrala antes de activar los controles elegidos.'; }
    s6.action = has('endurecimiento') ? link(itOk ? 'Revisar cuenta de IT' : 'Configurar cuenta de IT', at('/ajustes.php', 6), 'panel-settings') : link('Preparar regla del piloto', at('/reglas.php', 6), 'policies');
    if (has('endurecimiento') && ruleFeatures.length) s6.secondary.push(link('Preparar regla del piloto', at('/reglas.php', 6), 'policies'));
  }

  // ---- 7. Instalar Keeper ---------------------------------------------------------------------------------------
  let s7;
  if (!devicesP.ok) s7 = unavailable(7, devicesP);
  else {
    s7 = step(7, {});
    const approved = approvedP.ok ? approvedP.data.rows : null;
    if (assigned.length) { s7.status = 'done'; s7.summary = `${assigned.length} equipo(s) registrados y asignados a una persona activa.`; }
    else if (devices.length) { s7.status = 'progress'; s7.summary = 'Hay equipos registrados, pero ninguno activo y asignado a una persona activa.'; }
    else if (approved?.length) { s7.status = 'progress'; s7.summary = `${approved.length} solicitud(es) aprobadas esperando conexión: aprobada no es registrada.`; }
    else { s7.status = 'pending'; s7.summary = 'Descarga el instalador y ejecútalo en el equipo Windows de la persona elegida. Descargarlo no cuenta como instalación.'; }
    if (!approvedP.ok) s7.lines.push(`Solicitudes aprobadas: ${FAILURE_TEXT[approvedP.kind] || FAILURE_TEXT.error}`);
    if (!devicesP.data.complete && !assigned.length) { s7.status = 'unavailable'; s7.failure = { kind: 'partial' }; s7.warnings.push(FAILURE_TEXT.partial); }
    // F8: antes de instalar, advertir del modo automático y de la cuenta de IT sin contraseña.
    if (hardening?.hardening_mode === 'auto') s7.warnings.push('El endurecimiento está en modo automático: al instalar, cada equipo pierde la administración local. Cámbialo a «desde el panel» para el piloto si no es lo que quieres.');
    if (hardening && !hardening.configured) s7.warnings.push('La cuenta de IT no tiene contraseña: podrás instalar y monitorear, pero no endurecer hasta configurarla.');
    s7.action = link(assigned.length ? 'Instalar en más equipos' : 'Instalar Keeper', at('/alta.php', 7), 'intake');
  }

  // ---- 8. Comprobar el primer equipo ----------------------------------------------------------------------------
  let s8;
  if (!devicesP.ok) s8 = unavailable(8, devicesP);
  else if (!pilotDevice) s8 = step(8, { status: 'pending', summary: 'Falta un equipo registrado y asignado a una persona activa.' });
  else {
    s8 = step(8, { status: 'progress', summary: 'Comprobando que el equipo siga reportando.' });
    s8.lines.push(`Equipo ${pilotDevice.hostname} · ${pilotUser?.display_name || 'persona asignada'}.`, `Última conexión: ${exactTime(pilotDevice.last_seen_at)}.`);
    s8.secondary.push(link('Ver ficha del equipo', at(`/equipo.php?id=${encodeURIComponent(pilotDevice.id)}`, 8), 'devices'));
  }

  const steps = [s1, s2, s3, s4, s5, s6, s7, s8];
  for (const s of steps) {
    if (s.action?.key && !can(s.action.key)) { s.note = s.note || 'Esta pantalla no está disponible para tu cuenta: lo resuelve AZC Keeper o un administrador con permiso.'; s.action = null; }
    s.secondary = s.secondary.filter(l => !l.key || can(l.key));
    s.retry = retryable(s.failure);
  }
  const others = Object.entries(OTHER_FEATURES).filter(([c]) => has(c)).map(([, name]) => name);
  const pendingFeatures = Object.entries(PENDING_FEATURES).filter(([c]) => has(c)).map(([, name]) => name);
  return { kind: 'ready', platform, tenant: tenantP.ok ? tenantP.data : null, steps, pilot: pilotDevice ? { device: pilotDevice, user: pilotUser } : null,
    hardening, canHardening: has('endurecimiento') && !!hardP?.ok, others, pendingFeatures, checkedAt: new Date().toISOString(), counts: count(steps) };
}

// «X de Y pasos necesarios»: excluye opcionales y No aplica; el paso 8 solo cuenta con la comprobación hecha.
export function count(steps) {
  const needed = steps.filter(s => s.required && s.status !== 'na');
  return { done: needed.filter(s => s.status === 'done').length, total: needed.length,
    optional: steps.filter(s => !s.required && s.status !== 'na').length, na: steps.filter(s => s.status === 'na').map(s => s.title) };
}
export const nextPending = steps => steps.find(s => s.required && s.status !== 'done' && s.status !== 'na') || null;
// Sin cierre persistido (B1), Inicio da por terminada la puesta en marcha cuando los pasos necesarios 1–7 están hechos
// y el equipo piloto ya reportó alguna vez. La prueba de que last_seen avanza se hace en la pantalla Puesta en marcha.
export function operational(result) {
  if (result?.kind !== 'ready') return false;
  return result.steps.slice(0, 7).every(s => !s.required || s.status === 'done' || s.status === 'na') && !!result.pilot?.device?.last_seen_at;
}
