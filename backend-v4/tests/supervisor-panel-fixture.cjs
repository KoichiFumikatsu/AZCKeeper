const tenant = '10000000-0000-4000-8000-000000000001';
const other = '20000000-0000-4000-8000-000000000001';
const uid = '10000000-0000-4000-8000-000000000002';
const available = { status: 'available', reason: null };
function person(i = 0) {
  return { tenant_id: i % 2 ? other : tenant, user_id: i < 2 ? uid : `10000000-0000-4000-8000-${String(i).padStart(12, '0')}`, display_name: i < 2 ? '<img src=x onerror=alert(1)>' : `Persona ${i}`, day: '2026-10-29', timezone: 'America/Bogota', company: { id: uid, name: 'Empresa' }, site: null, area: null, position: null,
    connection: { state: 'connected', last_seen_at: '2026-10-29T14:00:00Z', device_count: 2, availability: available },
    arrival: { first_at: null, scheduled_at: null, deadline_at: null, margin_minutes: 20, late: null, availability: available },
    activity: { active_seconds: 3600, idle_seconds: 60, locked_seconds: null, productivity_percent: 50, workday_productive_seconds: 1800, workday_expected_seconds: 3600, measured: true, availability: available, data_through: null, calculated_at: null },
    alerts: { pending_count: 0, highest_severity: null, primary: null, complete: false } };
}
function overview() {
  return { generated_at: '2026-10-29T14:00:00Z', capabilities: { admin_href: '/admin/', sections: [] }, facets: { tenants: [{ id: tenant, name: 'A', timezone: 'America/Bogota', today: '2026-10-29' }, { id: other, name: 'B', timezone: 'Asia/Tokyo', today: '2026-10-29' }], companies: [], areas: [], sites: [] }, cohort: { people: 300, measured_people: 250, no_measurement_people: 40, no_workday_people: 10, not_authorized_people: 0 }, kpis: { productivity: { percent: 50, workday_productive_seconds: 1800, workday_expected_seconds: 3600 }, arrivals: { late: 2, recorded: 200, margin_minutes: 20, margins: [], complete: true }, alerts: { people: 3, high_people: 1, complete: false }, trend: { availability: { status: 'unavailable', reason: 'no_closed_days' }, periods: [], people: 0, current: { percent: null }, previous: { percent: null }, delta_pp: null } }, counts: { connected: 200, away: 50, offline: 50, availability: available }, total: 300, data: Array.from({ length: 50 }, (_, i) => person(i)), next_cursor: 'page-two' };
}
function summary(protection = false) {
  const p = person();
  const dto = { ...p, generated_at: '2026-10-29T14:00:00Z', sections: Object.fromEntries(['activity', 'presence', 'devices', 'compliance', 'rules'].map(k => [k, available])), raw_detail_available: true,
    schedule: { day: p.day, availability: available, start_at: '2026-10-29T13:00:00Z', end_at: '2026-10-29T22:00:00Z', margin_minutes: 20, work_periods: [], lunch: null, absences: [] },
    alerts: { data: [], total: 0, pending_count: 0, complete: false, unavailable_types: ['offline_in_schedule'], next_cursor: null, calculated_at: null, data_through: null },
    current: { connection: p.connection, devices: { availability: available, data: [{ id: uid, hostname: 'PC <script>', agent_version: '4.0.30', connection: p.connection }], total: 2, next_cursor: 'devices-two' } } };
  if (protection) { dto.sections.protection = available; dto.current.protection = { state: 'unconfirmed', reasons: [{ code: 'rules_pending', device_id: uid, report_at: null, last_seen_at: null, control: null }] }; dto.current.devices.data[0].protection = dto.current.protection; }
  return dto;
}
module.exports = { tenant, other, uid, available, person, overview, summary };
