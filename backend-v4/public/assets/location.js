import { api, errorMessage } from './api.js';
import { loadAccess } from './nav-access.js';

const el = (tag, text) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; return n; };
const permitted = (a, p) => !!a?.platform || !!a?.permissions?.some(x => x.code === p);
const contracted = a => !!a?.platform || a?.features?.includes('ubicacion');
const when = at => new Intl.DateTimeFormat('es-CO', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(at));
const state = p => p.result === 'en_sede' ? `En sede ${p.site_name || '(red compartida)'}` : p.result === 'fuera' ? 'Fuera de sede' : 'Sin dato';
const date = d => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota', year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
const field = (root, text, input) => { const label = el('label', text); label.append(input); root.append(label); return input; };
function failure(root, error) { const p = el('p', errorMessage(error)); p.className = 'error-text'; p.setAttribute('role', 'alert'); root.append(p); }

export async function mountLocation(root, { type, id }) {
  const access = await loadAccess();
  if (!contracted(access) || !permitted(access, 'presencia.ubicacion_ver')) return;
  const box = el('section'); box.className = 'surface'; box.dataset.location = type; root.append(box);
  box.append(el('h2', 'Ubicación del equipo'));
  const controls = el('div'); controls.className = 'actions'; box.append(controls);
  const day = field(controls, 'Fecha ', el('input')); day.type = 'date'; day.value = date(new Date()); day.max = day.value;
  const oldest = new Date(); oldest.setUTCDate(oldest.getUTCDate() - 89); day.min = date(oldest);
  const include = el('input'); include.type = 'checkbox';
  const includeLabel = field(controls, 'Incluir fuera de horario (esta consulta se audita) ', include).parentElement;
  includeLabel.hidden = true;
  const content = el('div'); box.append(content);
  let generation = 0;
  function point(p) {
    const row = el('li', `${when(p.at)} · ${state(p)} · ${p.matched_by === 'red' ? 'Por red' : p.matched_by === 'geocerca' ? 'Por geocerca' : 'Sin coincidencia'}`);
    if (p.wifi_ssid) row.append(` · Wi-Fi ${p.wifi_ssid}`);
    if (p.accuracy_m !== null) row.append(` · precisión ${Math.round(p.accuracy_m)} m`);
    if (p.latitude !== null && p.longitude !== null) {
      const link = el('a', 'Ver en mapa'); link.href = `https://www.google.com/maps?q=${encodeURIComponent(`${p.latitude},${p.longitude}`)}`;
      link.target = '_blank'; link.rel = 'noopener noreferrer'; row.append(' · ', link);
    }
    return row;
  }
  async function load(cursor = null, list = null) {
    const version = cursor ? generation : ++generation;
    if (!cursor) content.replaceChildren(el('p', 'Cargando ubicación…'));
    try {
      const query = new URLSearchParams({ day: day.value, include_out_of_hours: String(include.checked) });
      if (cursor) query.set('cursor', cursor);
      const data = await api('GET', `/${type === 'device' ? 'devices' : 'users'}/${id}/locations?${query}`);
      if (generation !== version || !box.isConnected) return;
      if (!cursor) {
        content.replaceChildren();
        includeLabel.hidden = !data.can_include_out_of_hours;
        if (data.lost_at) content.append(el('p', `Equipo marcado perdido/robado desde ${when(data.lost_at)}. ${data.lost_reason || ''}`));
        if (data.latest) { content.append(el('h3', 'Último punto disponible')); const latest = el('ul'); latest.append(point(data.latest)); content.append(latest); }
        else content.append(el('p', 'Sin puntos visibles para tu acceso.'));
        content.append(el('h3', 'Historial del día'));
        list = el('ol'); content.append(list);
        if (!data.data.length) content.append(el('p', 'Sin puntos en esta fecha.'));
        if (type === 'device' && data.can_mark_lost) {
          const form = el('form'); const open = !data.lost_at;
          form.append(el('h3', open ? 'Marcar como perdido/robado' : 'Cerrar caso'));
          form.append(el('p', open ? 'La ubicación se tomará también fuera de horario hasta cerrar el caso.' : 'Al cerrar el caso la ubicación vuelve a tomarse solo en horario laboral.'));
          const reason = field(form, 'Motivo ', el('input')); reason.maxLength = 500; reason.required = open; reason.hidden = !open;
          const confirmation = field(form, `Escribe ${open ? 'PERDIDO' : 'CERRAR'} para confirmar `, el('input')); confirmation.required = true;
          const save = el('button', open ? 'Marcar como perdido/robado' : 'Cerrar caso'); save.type = 'submit'; form.append(save); content.append(form);
          form.addEventListener('submit', async e => {
            e.preventDefault(); save.disabled = true;
            try { await api('PUT', `/devices/${id}/lost`, { body: { lost: open, reason: reason.value, confirmation: confirmation.value } }); await load(); }
            catch (error) { failure(form, error); save.disabled = false; }
          });
        }
      }
      for (const p of data.data) list.append(point(p));
      if (data.next_cursor) {
        const more = el('button', 'Cargar más puntos'); content.append(more);
        more.addEventListener('click', async () => { more.remove(); await load(data.next_cursor, list); });
      }
    } catch (error) { if (generation === version) { if (!cursor) content.replaceChildren(); failure(content, error); } }
  }
  day.addEventListener('change', () => load()); include.addEventListener('change', () => load());
  await load();
}

export async function mountLocationRule(root, { type, id }) {
  const access = await loadAccess();
  if (!contracted(access) || !permitted(access, 'reglas.editar') || !permitted(access, 'reglas.ver')) return;
  const box = el('section'); box.className = 'surface'; box.append(el('h3', 'Ubicación del equipo')); root.append(box);
  try {
    let policies;
    if (type === 'tenant') {
      policies = []; let cursor;
      do { const page = await api('GET', `/policies${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`); policies.push(...page.data); cursor = page.next_cursor; } while (cursor);
      policies = policies.filter(p => p.target_type === 'tenant' && p.target_id === id);
    } else {
      const rules = await api('GET', `/${type === 'device' ? 'devices' : 'users'}/${id}/rules`);
      policies = rules.own_policies;
      if (rules.location_tracking !== undefined) box.append(el('p', `Estado efectivo: ${rules.location_tracking ? 'activada' : 'desactivada'}.`));
    }
    let own = policies.find(p => Object.hasOwn(p, 'location_tracking'));
    const input = el('input'); input.type = 'checkbox'; input.checked = own?.enabled && own?.location_tracking;
    field(box, `Activar para ${type === 'tenant' ? 'toda la empresa' : type === 'device' ? 'este equipo' : 'esta persona'} `, input);
    box.append(el('p', 'Registra un punto cada 15 minutos y al cambiar de red, solo en el horario laboral de la persona (± 30 min). Fuera de horario solo si el equipo está marcado como perdido/robado. Requiere agente compatible y ubicación disponible en Windows.'));
    const save = el('button', 'Guardar ubicación'); box.append(save);
    save.addEventListener('click', async () => {
      save.disabled = true;
      try {
        const body = { name: own?.name || 'Ubicación del equipo', target_type: type, target_id: id, enabled: true, rules: own?.rules || [], location_tracking: input.checked };
        own = await api(own ? 'PATCH' : 'POST', own ? `/policies/${own.id}` : '/policies', { body, ifMatch: own?.version });
        const status = el('p', 'Regla guardada. Se aplicará en la próxima sincronización.'); status.setAttribute('role', 'status'); box.append(status);
      } catch (error) { failure(box, error); }
      finally { save.disabled = false; }
    });
  } catch (error) { failure(box, error); }
}

export async function mountSiteLocation(root, id) {
  const access = await loadAccess();
  if (!contracted(access) || !permitted(access, 'organizacion.editar')) return;
  const details = el('details'); details.append(el('summary', 'Cómo reconocer esta sede')); root.append(details);
  let loaded = false;
  details.addEventListener('toggle', async () => {
    if (!details.open || loaded) return;
    loaded = true;
    try {
      const data = await api('GET', `/organization/${id}/location`);
      const form = el('form'); details.append(form);
      const ips = field(form, 'IPs públicas de salida (una por línea)', el('textarea')); ips.value = data.public_ips.join('\n');
      for (const ip of data.suggested_ips) {
        const b = el('button', `Añadir ${ip}`); b.type = 'button'; form.append(b);
        b.addEventListener('click', () => { ips.value = [...new Set([...ips.value.split(/\s+/).filter(Boolean), ip])].join('\n'); });
      }
      const wifi = field(form, 'Redes Wi-Fi (una por línea: SSID | BSSID; BSSID opcional)', el('textarea'));
      wifi.value = data.wifi.map(w => `${w.ssid}${w.bssid ? ` | ${w.bssid}` : ''}`).join('\n');
      const numeric = (label, value) => { const f = field(form, label, el('input')); f.type = 'number'; f.step = 'any'; f.value = value ?? ''; return f; };
      const lat = numeric('Latitud', data.latitude), lon = numeric('Longitud', data.longitude), radius = numeric('Radio en metros', data.radius_m);
      lat.min = -90; lat.max = 90; lon.min = -180; lon.max = 180; radius.min = 1; radius.max = 100000;
      const save = el('button', 'Guardar reconocimiento'); save.type = 'submit'; form.append(save);
      form.addEventListener('submit', async e => {
        e.preventDefault(); save.disabled = true;
        try {
          await api('PUT', `/organization/${id}/location`, { body: {
            public_ips: ips.value.split(/\s+/).filter(Boolean), wifi: wifi.value.split('\n').filter(l => l.trim()).map(l => { const [ssid, bssid] = l.split('|'); return { ssid: ssid.trim(), bssid: bssid?.trim() || null }; }),
            latitude: lat.value === '' ? null : Number(lat.value), longitude: lon.value === '' ? null : Number(lon.value), radius_m: radius.value === '' ? null : Number(radius.value),
          } });
          const status = el('p', 'Sede guardada. Se actualizarán los últimos 7 días; puede tardar unos minutos.'); status.setAttribute('role', 'status'); form.append(status);
        } catch (error) { failure(form, error); }
        finally { save.disabled = false; }
      });
    } catch (error) { failure(details, error); loaded = false; }
  });
}
