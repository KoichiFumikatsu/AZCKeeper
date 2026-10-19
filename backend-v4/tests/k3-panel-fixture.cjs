const assert = require('node:assert/strict');
exports.createFixture = () => {
const id = n => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const tenant = id(2), uid = id(10), area = id(20), site = id(21), company = id(22), device = id(30);
const now = new Date().toISOString(), day = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(new Date());
const totals = { active_seconds: 14400, idle_seconds: 1800, productive_seconds: 10800, focus_seconds: 7200, focus_score: 66.7, productivity_percent: 75, deep_work_seconds: 7200, distraction_seconds: 1800, context_switches: 12, call_seconds: 900, simulated_seconds: 120, locked_seconds: 600, work_hours_active_seconds: 12600, lunch_active_seconds: 600, after_hours_active_seconds: 1200, constancy_pct: 80, days_with_activity: 1 };
const person = { id: uid, display_name: 'Ana Torres', email: 'ana@example.test', document: '123456', status: 'active', employment_status: 'contratado', source_owners: [], portal_login_enabled: false, area_id: area, site_id: site, company_id: company, firm_id: company, position_id: id(23), schedule_id: id(24), version: '1', panel_login_enabled: false, monitoring: 'normal', plan_id: null };
const settings = { punctuality_margin_minutes: 15, default_schedule_id: id(24) };
const devices = [{ id: device, user_id: uid, tenant_id: tenant, hostname: 'AZC-ANA', status: 'active', agent_version: '4.0.24', last_seen_at: now, created_at: now, version: '1', release_ring: 'stable', release_pause_reason: null, os_edition: 'Windows 11 Pro', cpu: 'Intel Core i5', ram_bytes: 17179869184, capabilities: [], specs: null }];
const units = [{ id: area, kind: 'area', name: 'Jurídica', active: true }, { id: site, kind: 'site', name: 'Cali', country_code: 'CO', city: 'Cali', active: true }, { id: company, kind: 'company', name: 'AZC Legal', active: true }, { id: id(23), kind: 'position', name: 'Abogada', active: true }];
const activity = { day, ...totals, expected_seconds: 28800, first_activity: `${day}T13:00:00Z`, last_activity: now, status: 'a_tiempo', late_seconds: 0, punctuality_minutes: 0 };
const list = data => ({ data, next_cursor: null, generated_at: now, data_through: now });
const alerts = [{ id: id(40), user_id: uid, display_name: person.display_name, day, alert_type: 'foreign_app', severity: 'high', reviewed: false, evidence: 'Aplicación externa', notes: '' }];
const unknown = [], writes = [];
  const handler = async route => {
    const url = new URL(route.request().url()), p = url.pathname.slice(3); let result;
    if (p === '/tenants') result = list([{ id: tenant, name: 'Grupo AZC · Datos de prueba' }]);
    else if (p === `/tenants/${tenant}`) result = { id: tenant, name: 'Grupo AZC · Datos de prueba', timezone: 'America/Bogota', branding: null };
    else if (p === '/me/access') result = { platform: true, permissions: [], features: ['comandos', 'borrado'] };
    else if (p === '/organization') result = list(units);
    else if (p === '/users') result = list([person]);
    else if (p === `/users/${uid}`) { if (route.request().method() === 'PATCH') { const body = route.request().postDataJSON(); writes.push({ path: p, body }); Object.assign(person, body, { version: Number(person.version) + 1 }); } result = person; }
    else if (p === `/users/${uid}/portal-access`) { const body = route.request().postDataJSON(); writes.push({ path: p, body }); Object.assign(person, body); result = body; }
    else if (p === '/panel-accounts' || p === '/company-groups') result = list([]);
    else if (p === '/schedules/settings') { if (route.request().method() === 'PUT') { const body = route.request().postDataJSON(); writes.push({ path: p, body }); Object.assign(settings, body); } result = settings; }
    else if (p === `/users/${uid}/windows`) result = { data: [{ process_name: 'WINWORD', site: null, active_seconds: 7200, idle_seconds: 300, sessions: 4 }, { process_name: 'chrome', site: 'example.test', active_seconds: 3600, idle_seconds: 600, sessions: 7 }] };
    else if (p === `/users/${uid}/timeline`) result = { data: [{ process_name: 'WINWORD', site: null, started_at: `${day}T13:00:00Z`, ended_at: `${day}T14:00:00Z` }], next_offset: null };
    else if (p === `/devices/${device}/commands`) { if (route.request().method() === 'POST') { const body = route.request().postDataJSON(); writes.push({ path: p, body }); return route.fulfill({ status: 202, json: { id: id(70), type: body.type, status: 'pending', created_at: now } }); } result = list([]); }
    else if (p === `/devices/${device}/security`) result = { observed_at: now, controls: [{ control_id: 'WebEnforcer', state: 'applied' }] };
    else if (p === '/dashboard/problems') result = { data: [{ kind: 'legacy_k3', label: 'K3 abierto', user_id: uid, device_id: device, display_name: person.display_name, hostname: 'AZC-ANA' }] };
    else if (p === '/tenants/server-health') result = { cron_status: 'failed', cron_last_run: now, cron_last_success: now, cron_last_error: 'Fallo sintético del cálculo', jobs: [{ job: 'productivity', label: 'Cálculo de productividad', schedule: 'cada hora', stale_after_minutes: 120, status: 'failed', last_started_at: now, last_finished_at: now, last_success_at: null, error_class: 'PDOException', error_message: 'Fallo sintético de trabajo programado' }, { job: 'retention', label: 'Revisión de retención', schedule: 'cada noche', stale_after_minutes: 1560, status: 'ok', last_started_at: now, last_finished_at: now, last_success_at: now, error_class: null, error_message: null }], pending_rollup_days: 3, pending_commands: 0, pending_policy_compiles: 0, last_sync: now, last_ingest: now, calculation_version: 'prueba', database_version: '8.4', version: 'v4' };
    else if (p === `/tenants/${tenant}/devices`) result = list(devices);
    else if (p === `/devices/${device}`) {
      if (route.request().method() === 'PATCH') {
        assert.equal(route.request().headers()['if-match'], `"${devices[0].version}"`);
        const body = route.request().postDataJSON(); assert.ok(body.release_ring !== 'pausado' || body.release_pause_reason.trim()); writes.push({ path: p, body });
        Object.assign(devices[0], body, { version: Number(devices[0].version) + 1 });
      }
      result = devices[0];
    }
    else if (p === '/reports/people') result = { data: [{ user_id: uid, display_name: person.display_name, totals, last_activity: now }] };
    else if (p === '/reports/presence') result = { data: [{ user_id: uid, display_name: person.display_name, ...activity }] };
    else if (p === '/reports/productivity') result = { series: [activity], coverage_percent: 56, timezone: 'America/Bogota', from: url.searchParams.get('from'), to: url.searchParams.get('to') };
    else if (p === '/reports/apps') result = { data: [{ process_name: 'WINWORD', active_seconds: 10800, idle_seconds: 600, total_seconds: 11400, sessions: 8, users_count: 1, percent_total: 100 }] };
    else if (p === '/dashboard') result = { ...totals, active_sites: 1, total_sites: 1, online_members: 1, away_members: 0, offline_members: 0, total_members: 1, weekly_output: [activity], workload_by_area: [{ area_id: area, active_seconds: 14400 }], roster: [{ user_id: uid, display_name: person.display_name, devices: 'AZC-ANA', connection_status: 'online', last_seen_at: now, online: true, utilization_percent: 75 }] };
    else if (p === `/users/${uid}/activity`) result = { data: [activity], totals };
    else if (p === `/users/${uid}/rules` || p === `/devices/${device}/rules`) {
      // Pestaña «Reglas» de las fichas: la empresa bloquea facebook.com y USB; Ana tiene facebook.com como excepción.
      const onDevice = p.startsWith('/devices/');
      const src = (scope, effect, name) => ({ policy_id: id(scope === 'tenant' ? 60 : 61), policy_name: name, scope, target_name: scope === 'user' ? person.display_name : null, effect, priority: 0, own: scope === 'user' && !onDevice });
      result = { subject: { type: onDevice ? 'device' : 'user', user_id: uid, device_id: onDevice ? device : null, display_name: person.display_name, hostname: onDevice ? 'AZC-ANA' : null, active: true },
        entries: [{ kind: 'web', target: 'facebook.com', effect: 'allow', winners: [src('user', 'allow', 'Excepción Ana')], overridden: [src('tenant', 'deny', 'Bloqueos generales')] },
          { kind: 'usb', target: '*', effect: 'deny', winners: [src('tenant', 'deny', 'Bloqueos generales')], overridden: [] }],
        excluded: [], conflict: null, recompile_pending: false,
        kinds: [['web', 'web', 'Bloqueo de sitios web'], ['usb', 'usb', 'Bloqueo de USB'], ['download', 'descargas', 'Control de descargas'], ['installation', 'instalaciones', 'Control de instalación de programas']].map(([kind, feature, feature_name]) => ({ kind, feature, feature_name, contracted: true, in_plan: true })),
        own_policies: onDevice ? [] : [{ id: id(61), tenant_id: tenant, name: 'Excepción Ana', target_type: 'user', target_id: uid, enabled: true, rules: [{ id: id(62), kind: 'web', effect: 'allow', targets: ['facebook.com'], schedule_id: null, priority: 0 }], version: 1 }],
        devices: [{ device_id: device, hostname: 'AZC-ANA', status: 'active', compiled_version: 7, reported_version: 7, up_to_date: true, last_seen_at: now, own_policy_ids: [] }] };
    }
    else if (p === '/schedules') result = list([{ id: id(24), name: 'Oficina', start_local: '08:00', end_local: '17:00', timezone: 'America/Bogota', days: [1, 2, 3, 4, 5] }]);
    else if (p === '/person-plans/available') result = { data: [], features: [], default_plan_id: null };
    else if (p === '/monitoring-exclusions') result = { position_ids: [] };
    else if (p === '/onboarding') return route.fulfill({ status: 403, json: { code: 'permission_denied' } });
    else if (p === '/reports/dual-job-alerts') result = { data: alerts, next_offset: null };
    else if (p === `/reports/dual-job-alerts/${id(40)}`) { alerts[0].reviewed = true; writes.push({ path: p, body: route.request().postDataJSON() }); return route.fulfill({ status: 204 }); }
    else if (p === '/reports/compliance-signals') result = { data: [], next_offset: null };
    else if (p === '/devices/install-coverage') result = { data: [{ user_id: uid, display_name: person.display_name, status: 'covered', device_count: 1, enrolled_count: 1, last_seen_at: now, note_text: '', is_exempt: false }], next_offset: null };
    else if (p === '/releases') result = list([{ id: id(50), version: '4.0.25', architecture: 'x64', channel: 'stable', min_agent_version: '4.0.0', size_bytes: 10000000, published_at: now }]);
    else if (p === '/release-deployments') { assert.ok(route.request().headers()['idempotency-key']); result = route.request().postDataJSON(); writes.push({ path: p, body: result }); }
    else if (['/data-domains', '/data-sources', '/schedules/holiday-calendars', '/person-plans', '/policies', '/roles', '/relocations'].includes(p)) result = list([]);
    else { unknown.push(p); return route.fulfill({ status: 403, json: { code: 'permission_denied' } }); }
    await route.fulfill({ json: result });
  };
return { handler, tenant, uid, device, person, unknown, writes };
};
