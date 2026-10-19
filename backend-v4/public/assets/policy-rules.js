import { api, readSession, errorMessage } from './api.js';
import { loadAccess } from './nav-access.js';
import { wireDialog, confirmBox, feedback } from './dialogs.js';

// Reglas de apps, sitios y USB: lo que comparten el editor general (k3.js, Reglas) y la pestaña «Reglas» de la ficha de
// la persona (k3.js) y de la del equipo (app.js). Solo se ofrece lo que el agente sabe aplicar: bloquear sitios web (con
// excepciones), descargas e instalaciones (bloquear todo o permitir como excepción) y USB (bloquear, solo lectura o
// permitir). Gana lo más específico: equipo > persona > área > sede > empresa (PolicyComposer en el servidor).
const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (className) n.className = className; return n; };

export const SCOPE_LABEL = { tenant: 'Toda la empresa', site: 'Sede', area: 'Área', user: 'Persona', device: 'Equipo' };
export const RULE_KIND = { web: 'sitios web', download: 'descargas', installation: 'instalaciones', usb: 'USB', schedule: 'horario', os: 'sistema operativo', encryption: 'cifrado' };
export const CHOICES = {
  download: [['', 'Sin regla aquí (se usa la general)'], ['deny', 'Bloquear'], ['allow', 'Permitir (excepción)']],
  installation: [['', 'Sin regla aquí (se usa la general)'], ['deny', 'Bloquear'], ['allow', 'Permitir (excepción)']],
  usb: [['', 'Sin regla aquí (se usa la general)'], ['deny', 'Bloquear'], ['write', 'Solo lectura (no copiar a la USB)'], ['allow', 'Permitir (excepción)']],
};
export const CHOICE_LABEL = { download: 'Descargas desde el navegador', installation: 'Instalar programas', usb: 'Memorias USB' };
export const NOTHING = 'La regla no hace nada: agrega al menos un bloqueo o permiso.';

// Lo que el editor simplificado no sabe mostrar (F11): horarios, prioridades, varias entradas del mismo tipo, tipos o
// destinos que no tiene. Una regla así no se reescribe al guardar: solo cambian nombre, destino y estado.
export function unsupportedRules(rules) {
  const why = new Set(); const seen = new Set();
  for (const r of rules || []) {
    if (r.schedule_id) why.add('horarios');
    if (r.priority) why.add('prioridades');
    const key = r.kind === 'web' ? `web:${r.effect}` : r.kind;
    if (seen.has(key)) why.add('varias entradas del mismo tipo'); seen.add(key);
    const one = t => r.targets.length === 1 && r.targets[0] === t;
    if (!['web', 'download', 'installation', 'usb'].includes(r.kind)) why.add(`reglas de ${RULE_KIND[r.kind] || r.kind}`);
    else if (!['allow', 'deny'].includes(r.effect)) why.add('efectos que no son bloquear o permitir');
    else if ((r.kind === 'download' || r.kind === 'installation') && !one('*')) why.add(`destinos específicos de ${RULE_KIND[r.kind]}`);
    else if (r.kind === 'usb' && !(one('*') || (r.effect === 'deny' && one('write')))) why.add('destinos específicos de USB');
  }
  return [...why];
}

// Una entrada de regla en palabras («Bloquea facebook.com», «USB solo lectura»).
export function describeRule(r, scheduleName = () => 'un horario') {
  let text;
  if (r.kind === 'web') text = `${r.effect === 'deny' ? 'Bloquea' : r.effect === 'allow' ? 'Permite (excepción)' : 'Exige'} ${r.targets.join(', ')}`;
  else if (r.kind === 'download' && r.targets.length === 1 && r.targets[0] === '*') text = r.effect === 'deny' ? 'Bloquea las descargas' : 'Permite las descargas (excepción)';
  else if (r.kind === 'installation' && r.targets.length === 1 && r.targets[0] === '*') text = r.effect === 'deny' ? 'Bloquea instalar programas' : 'Permite instalar programas (excepción)';
  else if (r.kind === 'usb' && ['*', 'write'].includes(r.targets[0]) && r.targets.length === 1) text = r.effect === 'allow' ? 'Permite USB (excepción)' : r.targets[0] === 'write' ? 'USB solo lectura' : 'Bloquea USB';
  else text = `${{ deny: 'Bloquea', allow: 'Permite', require: 'Exige' }[r.effect] || r.effect} ${RULE_KIND[r.kind] || r.kind}: ${r.targets.join(', ') || '—'}`;
  if (r.schedule_id) text += ` · solo en el horario «${scheduleName(r.schedule_id)}»`;
  if (r.priority) text += ` · prioridad ${r.priority}`;
  return text;
}
// Una línea por sitio o control, para comparar lo guardado con lo nuevo.
export const itemize = (rules, scheduleName) => rules.flatMap(r => r.kind === 'web' && ['deny', 'allow'].includes(r.effect) && !r.schedule_id && !r.priority
  ? r.targets.map(t => `${r.effect === 'deny' ? 'Bloquea' : 'Permite (excepción)'} ${t}`) : [describeRule(r, scheduleName)]);

export const parseDomains = text => [...new Set(text.split(/[\s,;]+/).map(x => x.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '')).filter(Boolean))];

// Lo que dice una regla simple (sin horarios ni prioridades) en los campos del editor.
export function ruleChoices(rules) {
  const rule = kind => (rules || []).find(r => r.kind === kind);
  const usb = rule('usb');
  return {
    block: (rules || []).filter(r => r.kind === 'web' && r.effect === 'deny').flatMap(r => r.targets),
    allow: (rules || []).filter(r => r.kind === 'web' && r.effect === 'allow').flatMap(r => r.targets),
    download: rule('download')?.effect || '', installation: rule('installation')?.effect || '',
    usb: usb ? (usb.effect === 'allow' ? 'allow' : usb.targets[0] === 'write' ? 'write' : 'deny') : '',
  };
}
// Los campos del editor → entradas de la regla, reutilizando los id de las que ya existían (no se reemplazan en cada
// guardado). [problema] o [null, entradas]. allowEmpty: la ficha puede dejar su regla propia sin entradas.
export function buildRules(choices, previous = [], { allowEmpty = false } = {}) {
  const prev = new Map(previous.map(r => [r.kind === 'web' ? `web:${r.effect}` : r.kind, r.id]));
  const idFor = key => prev.get(key) || crypto.randomUUID();
  const out = [];
  const blocked = choices.block, allowed = choices.allow;
  const bad = [...blocked, ...allowed].find(d => !/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/.test(d));
  if (bad) return [`"${bad}" no parece un sitio (escribe solo el dominio, p. ej. facebook.com).`];
  if (blocked.some(d => allowed.includes(d))) return ['Un mismo sitio no puede estar bloqueado y permitido en la misma regla.'];
  if (blocked.length) out.push({ id: idFor('web:deny'), kind: 'web', effect: 'deny', targets: blocked, schedule_id: null, priority: 0 });
  if (allowed.length) out.push({ id: idFor('web:allow'), kind: 'web', effect: 'allow', targets: allowed, schedule_id: null, priority: 0 });
  if (choices.download) out.push({ id: idFor('download'), kind: 'download', effect: choices.download, targets: ['*'], schedule_id: null, priority: 0 });
  if (choices.installation) out.push({ id: idFor('installation'), kind: 'installation', effect: choices.installation, targets: ['*'], schedule_id: null, priority: 0 });
  if (choices.usb) out.push({ id: idFor('usb'), kind: 'usb', effect: choices.usb === 'allow' ? 'allow' : 'deny', targets: [choices.usb === 'write' ? 'write' : '*'], schedule_id: null, priority: 0 });
  if (!out.length && !allowEmpty) return [NOTHING];
  return [null, out];
}
// Error al guardar una regla, en palabras (choque del mismo nivel, valor inválido, bloquear el panel).
export function policySaveError(e) {
  const detail = String(e.problem?.detail || '');
  if (e.code === 'invalid_transition' || e.status === 409) return 'Choca con otra regla del mismo nivel (una bloquea y otra permite lo mismo). Ponlas en niveles distintos o únelas.';
  if (detail.startsWith('invalid_target:')) return `El valor "${detail.split(':').slice(2).join(':')}" no es válido en ${RULE_KIND[detail.split(':')[1]] || detail.split(':')[1]}.`;
  if (detail.startsWith('blocks_panel:')) return `No puedes bloquear ${detail.slice(13)}: dejaría a los equipos sin acceso al panel de Keeper.`;
  if (detail.startsWith('feature_not_contracted:')) return 'El cliente no contrató esta función: no se pueden crear reglas de este tipo.';
  return null;
}

// ---- Pestaña «Reglas» de la ficha -----------------------------------------------------------------------------------
const KIND_TITLE = { web: 'Sitios web', usb: 'Memorias USB', download: 'Descargas', installation: 'Instalación de programas' };
const KIND_ORDER = ['web', 'usb', 'download', 'installation'];
// Resultado de una entrada en palabras: «bloqueado», «permitido», «solo lectura».
function outcome(kind, target, effect) {
  if (effect === 'require') return 'exigido';
  if (kind === 'usb' && target === 'write') return effect === 'deny' ? 'solo lectura' : 'permitido';
  if (kind === 'download') return effect === 'deny' ? 'bloqueadas' : 'permitidas';
  return effect === 'deny' ? 'bloqueado' : 'permitido';
}
function subjectLabel(kind, target) {
  if (kind === 'web') return target;
  if (kind === 'usb') return target === 'write' ? 'Copiar a memorias USB' : target === '*' ? 'Memorias USB' : `USB: ${target}`;
  if (kind === 'download') return target === '*' ? 'Descargas desde el navegador' : `Descargas ${target}`;
  if (kind === 'installation') return target === '*' ? 'Instalar programas' : `Instalar ${target}`;
  return `${RULE_KIND[kind] || kind}: ${target}`;
}
// Quién pone la regla, visto desde la ficha: «esta persona», «su área Jurídica», «la empresa».
function who(source, subject) {
  if (source.own) return subject.type === 'device' ? 'este equipo' : 'esta persona';
  if (source.scope === 'user') return subject.type === 'device' ? `su persona${source.target_name ? ` (${source.target_name})` : ''}` : `la persona ${source.target_name || ''}`.trim();
  if (source.scope === 'device') return `el equipo ${source.target_name || ''}`.trim();
  if (source.scope === 'area') return `el área ${source.target_name || ''}`.trim();
  if (source.scope === 'site') return `la sede ${source.target_name || ''}`.trim();
  if (source.scope === 'global') return 'la plataforma';
  return 'la empresa';
}
const verb = (kind, target, effect) => effect === 'require' ? 'lo exige' : kind === 'usb' && target === 'write' && effect === 'deny' ? 'lo deja en solo lectura' : effect === 'deny' ? (kind === 'download' ? 'las bloquea' : 'lo bloquea') : (kind === 'download' ? 'las permite' : 'lo permite');
// «permitido (excepción de esta persona; la empresa lo bloquea)»
export function explainEntry(entry, subject) {
  const [winner] = entry.winners;
  const exception = entry.effect === 'allow' && entry.overridden.some(o => o.effect === 'deny');
  const parts = [`${exception ? 'excepción' : 'regla'} de ${who(winner, subject)}`, ...entry.overridden.map(o => `${who(o, subject)} ${verb(entry.kind, entry.target, o.effect)}`)];
  return `${outcome(entry.kind, entry.target, entry.effect)} (${parts.join('; ')})`;
}
const policyNames = sources => [...new Set(sources.map(s => s.policy_name || (s.scope === 'global' ? 'Regla de la plataforma' : 'Regla')))].join(' · ');

// Pestaña completa. options: { type: 'user'|'device', id, name, onChange? }. Devuelve una promesa que termina al dibujar.
export async function rulesTab(root, { type, id, name }) {
  const isSuper = !!readSession()?.is_platform_admin;
  const access = isSuper ? null : await loadAccess();
  const grant = code => isSuper || access?.platform ? { scopes: ['tenant'] } : access?.permissions?.find(p => p.code === code) || null;
  const canEdit = !!grant('reglas.editar');
  const canOpenRules = !!grant('reglas.ver')?.scopes?.includes('tenant');
  const box = el('section', undefined, 'surface rules-tab'); box.id = type === 'device' ? 'panel-reglas' : 'panel-politicas'; root.replaceChildren(box);
  const head = el('div', undefined, 'section-head'); head.append(el('h2', 'Lo que le aplica hoy')); box.append(head);
  if (canOpenRules) {
    const link = el('a', 'Ver en Reglas', 'link-button');
    link.href = `/reglas.php?${type === 'device' ? 'equipo' : 'persona'}=${encodeURIComponent(id)}`;
    link.setAttribute('aria-label', `Ver en Reglas las reglas que alcanzan a ${name}`);
    head.append(link);
  }
  const status = feedback(box);
  const body = el('div'); box.append(body);
  let data;
  const load = async () => {
    body.replaceChildren(el('p', 'Consultando las reglas…', 'help'));
    try { data = await api('GET', `/${type === 'device' ? 'devices' : 'users'}/${id}/rules`); }
    catch (e) { body.replaceChildren(); const p = el('p', e.status === 403 ? 'Tu cuenta no puede ver las reglas (se necesita el permiso para ver reglas).' : e.status === 409 ? 'No se pudieron calcular sus reglas: su ubicación laboral o sus reglas tienen un problema que también impide enviárselas al equipo.' : errorMessage(e), 'error-text'); p.setAttribute('role', 'alert'); body.append(p); return; }
    draw();
  };
  const draw = () => {
    body.replaceChildren();
    const subject = data.subject;
    body.append(el('p', subject.type === 'device'
      ? 'Resultado de juntar las reglas de la empresa, la sede y el área de su persona, las de su persona y las de este equipo. Si dos reglas chocan, gana la más específica: equipo, luego persona, área, sede y por último la empresa.'
      : 'Resultado de juntar las reglas de la empresa, su sede, su área y las suyas propias. Si dos reglas chocan, gana la más específica: persona, luego área, sede y por último la empresa. Las reglas propias de un equipo están en la ficha de ese equipo.', 'help'));
    if (!subject.active) body.append(el('p', 'Persona inactiva: sus equipos no reciben ninguna regla mientras siga así.', 'notice'));
    if (data.conflict) {
      const c = data.conflict; const p = el('p', `Hay un choque sin resolver en ${subjectLabel(c.kind, c.target)}: ${c.sources.map(s => `«${s.policy_name || 'Regla'}» (${who(s, subject)}) ${verb(c.kind, c.target, s.effect)}`).join(' y ')}, al mismo nivel. Mientras siga, sus equipos no reciben reglas nuevas: une esas reglas o ponlas en niveles distintos en Reglas.`, 'error-text');
      p.setAttribute('role', 'alert'); body.append(p);
    }
    // Versión aplicada por equipo.
    if (data.devices.length) {
      const ul = el('ul', undefined, 'rules-devices');
      for (const d of data.devices) {
        const li = el('li'); li.append(el('strong', d.hostname), ': ');
        if (d.compiled_version === null) li.append(el('span', 'aún no tiene reglas compiladas: las recibe en su próxima sincronización.'));
        else if (d.up_to_date) li.append(el('span', `tiene aplicada la versión ${d.compiled_version} de sus reglas (al día).`));
        else li.append(el('span', `versión ${d.compiled_version} lista; el agente reporta la ${d.reported_version}. La aplica en su próxima sincronización.`));
        if (subject.type === 'user' && d.own_policy_ids.length) li.append(' ', el('small', 'Este equipo tiene además reglas propias: míralas en su ficha.'));
        ul.append(li);
      }
      body.append(ul);
    } else if (subject.type === 'user') body.append(el('p', 'No tiene equipos activos: estas reglas se aplicarán al equipo que se le asigne.', 'help'));
    if (data.recompile_pending) body.append(el('p', 'Hay cambios recientes del cliente por compilar: los equipos los reciben en unos minutos.', 'help'));
    for (const kind of KIND_ORDER) {
      const info = data.kinds.find(k => k.kind === kind);
      const sec = el('section', undefined, 'rules-kind'); sec.dataset.kind = kind; body.append(sec);
      const h = el('h3', KIND_TITLE[kind]); sec.append(h);
      if (info && !info.contracted) { h.append(' ', badgeEl('No contratado', '')); sec.append(el('p', `No aplica: el cliente no contrató «${info.feature_name}».`, 'help')); }
      else if (info && !info.in_plan) { h.append(' ', badgeEl('No aplica por plan', 'warn')); sec.append(el('p', `No aplica por plan: el plan de ${subject.type === 'device' ? 'su persona' : 'esta persona'} no incluye «${info.feature_name}». Las reglas siguientes existen, pero su equipo no las aplica.`, 'help')); }
      const entries = data.entries.filter(e => e.kind === kind);
      const excluded = data.excluded.filter(e => e.kind === kind);
      if (entries.length) {
        const ul = el('ul', undefined, 'rules-effective');
        for (const e of entries) {
          const li = el('li'); li.dataset.target = e.target;
          const line = el('p'); line.append(el('strong', subjectLabel(e.kind, e.target)), ' — ', badgeEl(outcome(e.kind, e.target, e.effect), e.effect === 'deny' ? 'bad' : e.effect === 'allow' ? 'good' : 'info'), ' ', el('span', explainEntry(e, subject).replace(/^[^(]+\(/, '(')));
          li.append(line, el('small', `Regla: ${policyNames([...e.winners, ...e.overridden])}`));
          ul.append(li);
        }
        sec.append(ul);
      } else if (!excluded.length) sec.append(el('p', kind === 'web' ? 'Ningún sitio bloqueado ni permitido por una regla.' : 'Sin reglas: se deja como está en el equipo.', 'help'));
      if (excluded.length) {
        const ul = el('ul', undefined, 'rules-excluded');
        for (const x of excluded) {
          ul.append(el('li', `${x.targets.map(t => subjectLabel(x.kind, t)).join(', ')} — ${who(x.source, subject)} ${verb(x.kind, x.targets[0], x.effect)} («${x.source.policy_name || 'Regla'}»), pero ${x.reason === 'not_contracted' ? 'el cliente no contrató la función' : 'no aplica por plan'}.`));
        }
        sec.append(ul);
      }
    }
    actions();
  };
  // Botones de la ficha: crean o editan la regla propia (destino esta persona o este equipo).
  const actions = () => {
    const subject = data.subject;
    const sec = el('section', undefined, 'rules-actions'); body.append(sec);
    sec.append(el('h3', subject.type === 'device' ? 'Cambiar las reglas de este equipo' : 'Cambiar las reglas de esta persona'));
    if (!canEdit) { sec.append(el('p', 'Tu cuenta puede ver las reglas, pero no cambiarlas (se necesita el permiso para editar reglas).', 'help')); return; }
    const editable = data.own_policies.find(p => !unsupportedRules(p.rules).length) || null;
    const locked = !editable && data.own_policies.length ? data.own_policies[0] : null;
    if (locked) {
      sec.append(el('p', `Su regla propia «${locked.name}» usa ${unsupportedRules(locked.rules).join(', ')}, que esta ficha no muestra. Cámbiala desde Reglas para no perder nada.`, 'notice'));
      return;
    }
    sec.append(el('p', editable
      ? `Los cambios se guardan en su regla propia «${editable.name}»${editable.enabled ? '' : ' (hoy inactiva: al guardar se activa)'}.`
      : `Aún no tiene regla propia: el primer cambio la crea${subject.type === 'device' ? ' para este equipo' : ' para esta persona'}.`, 'help'));
    const bar = el('div', undefined, 'actions no-margin'); sec.append(bar);
    const contracted = kind => data.kinds.find(k => k.kind === kind)?.contracted !== false;
    const add = (text, mode, kind) => { if (!contracted(kind)) return; const b = el('button', text); b.type = 'button'; b.dataset.mode = mode; b.addEventListener('click', () => quick(mode, editable)); bar.append(b); };
    add('Bloquear un sitio', 'block', 'web'); add('Permitir un sitio (excepción)', 'allow', 'web');
    add('USB…', 'usb', 'usb'); add('Descargas…', 'download', 'download'); add('Instalar programas…', 'installation', 'installation');
    if (!bar.childElementCount) sec.append(el('p', 'El cliente no contrató ninguna función de reglas de equipo.', 'help'));
  };
  const quick = (mode, own) => {
    const subject = data.subject;
    const target = subject.type === 'device' ? subject.device_id : subject.user_id;
    const whoText = subject.type === 'device' ? `el equipo ${subject.hostname}` : subject.display_name || 'esta persona';
    const titles = { block: 'Bloquear un sitio', allow: 'Permitir un sitio (excepción)', usb: 'Memorias USB', download: 'Descargas', installation: 'Instalar programas' };
    const dialog = el('dialog', undefined, 'rules-quick'); const form = el('form'); form.noValidate = true; dialog.append(form);
    const h = el('h2', `${titles[mode]} · ${subject.type === 'device' ? subject.hostname : subject.display_name}`); form.append(h);
    const dbody = el('div', undefined, 'dialog-body'); form.append(dbody);
    const current = ruleChoices(own?.rules || []);
    let field;
    if (mode === 'block' || mode === 'allow') {
      const l = el('label', mode === 'block' ? 'Sitios a bloquear (uno por línea)' : 'Sitios a permitir (uno por línea)'); field = el('textarea'); field.rows = 3; l.append(field); dbody.append(l);
      dbody.append(el('p', mode === 'block' ? 'Ej.: facebook.com. Bloquea también sus subdominios.' : `Ej.: facebook.com. Quita el sitio del bloqueo de la empresa, la sede o el área solo para ${subject.type === 'device' ? 'este equipo' : 'esta persona'}.`, 'help'));
      const already = mode === 'block' ? current.block : current.allow;
      if (already.length) dbody.append(el('p', `Ya ${mode === 'block' ? 'bloqueados' : 'permitidos'} en su regla propia: ${already.join(', ')}.`, 'help'));
    } else {
      const l = el('label', CHOICE_LABEL[mode]); field = el('select'); CHOICES[mode].forEach(([v, t]) => field.append(new Option(t, v))); field.value = current[mode]; l.append(field); dbody.append(l);
      dbody.append(el('p', '«Sin regla aquí» quita la regla propia de este tipo: vuelve a valer la de su área, sede o empresa.', 'help'));
    }
    const error = el('p', '', 'error-text'); error.hidden = true; form.append(error);
    const footer = el('div', undefined, 'dialog-footer'); const cancel = el('button', 'Cancelar'); cancel.type = 'button'; const ok = el('button', 'Revisar', 'primary'); ok.type = 'submit';
    footer.append(cancel, ok); form.append(footer); document.body.append(dialog);
    wireDialog(dialog, { title: h, error });
    cancel.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove());
    const show = text => { error.textContent = text; error.hidden = false; };
    form.addEventListener('submit', async ev => {
      ev.preventDefault(); error.hidden = true;
      const next = { ...current, block: [...current.block], allow: [...current.allow] };
      if (mode === 'block' || mode === 'allow') {
        const sites = parseDomains(field.value);
        if (!sites.length) { show('Escribe al menos un sitio.'); field.focus(); return; }
        const [mine, other] = mode === 'block' ? ['block', 'allow'] : ['allow', 'block'];
        next[mine] = [...new Set([...next[mine], ...sites])]; next[other] = next[other].filter(d => !sites.includes(d));
      } else next[mode] = field.value;
      const [problem, rules] = buildRules(next, own?.rules || [], { allowEmpty: !!own });
      if (problem) { show(problem); field.focus(); return; }
      const before = itemize(own?.rules || []), after = itemize(rules);
      const changes = [...after.filter(x => !before.includes(x)).map(x => `Se agrega: ${x}`), ...before.filter(x => !after.includes(x)).map(x => `Se quita: ${x}`)];
      if (own && !own.enabled) changes.push('Se activa la regla');
      if (!changes.length) { show('No cambia nada: ya estaba así en su regla propia.'); return; }
      const kind = mode === 'block' || mode === 'allow' ? 'web' : mode;
      const info = data.kinds.find(k => k.kind === kind);
      const list = el('ul'); changes.forEach(c => list.append(el('li', c)));
      const policyName = own?.name || (subject.type === 'device' ? `Reglas del equipo ${subject.hostname}` : `Reglas de ${subject.display_name || 'la persona'}`);
      const notes = [info && !info.in_plan ? `Ojo: el plan de ${subject.type === 'device' ? 'su persona' : 'esta persona'} no incluye «${info.feature_name}». La regla queda guardada, pero no se aplica hasta que su plan lo incluya.` : null,
        rules.length ? null : 'Su regla propia queda sin entradas: valen las de su área, sede y empresa.'].filter(Boolean);
      const yes = await confirmBox({ title: own ? 'Confirmar el cambio' : 'Confirmar la regla nueva',
        message: own ? `Se cambia la regla «${own.name}» de ${whoText}:` : `Se crea la regla «${policyName}» para ${whoText}:`,
        details: [list, ...notes], note: `${subject.type === 'device' ? 'El equipo la aplica' : 'Sus equipos la aplican'} en su próxima sincronización (en unos minutos si están encendidos y conectados).`, okText: own ? 'Guardar cambio' : 'Crear regla' });
      if (!yes) { field.focus(); return; }
      ok.disabled = true;
      try {
        const payload = { name: policyName, target_type: subject.type, target_id: target, enabled: true, rules };
        if (own) await api('PATCH', `/policies/${own.id}`, { body: payload, ifMatch: own.version }); else await api('POST', '/policies', { body: payload });
        dialog.close();
        await load();
        status.ok(`Regla ${own ? 'guardada' : 'creada'}: ${changes.join('; ')}. ${subject.type === 'device' ? 'El equipo la aplica' : 'Sus equipos la aplican'} en su próxima sincronización.`);
      } catch (e) {
        show(policySaveError(e) || (e.status === 412 ? 'Otra persona cambió esta regla mientras la editabas. Cierra y vuelve a intentarlo.' : errorMessage(e)));
        ok.disabled = false;
      }
    });
    dialog.showModal();
  };
  await load();
}
const badgeEl = (text, tone) => el('span', text, `badge ${tone || ''}`.trim());
