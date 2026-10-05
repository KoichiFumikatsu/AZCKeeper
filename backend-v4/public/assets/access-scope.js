// Frase legible de un acceso (docs/architecture/v4-clientes-funciones-accesos.md §5):
// «Coordinador · Área: Agentes de servicio · todas las sedes». Puro (sin DOM): lo prueba tests/access-summary.test.mjs.
export function scopeSummary(roleName, scope, names) {
  if (!scope) return 'Sin acceso';
  const role = roleName || 'Sin rol';
  if (scope.kind === 'all') return `${role} · Todo el cliente`;
  if (scope.kind === 'team') return `${role} · Su equipo (quienes le reportan)`;
  if (scope.kind === 'self') return `${role} · Solo lo propio`;
  // En el servidor un `filters` sin valores no ve a nadie (1=0): nunca se lee como «todas las sedes».
  if (!LISTS.some(k => scope[k]?.length)) return `${role} · Filtros sin unidades: no ve a nadie`;
  const label = ids => ids.map(id => names.get(id) || id).join(', ');
  const parts = [role];
  if (scope.company_ids.length) parts.push(`Empresa: ${label(scope.company_ids)}`);
  if (scope.cities.length) parts.push(`Ciudad: ${scope.cities.join(', ')}`);
  if (scope.site_ids.length) parts.push(`Sede: ${label(scope.site_ids)}`);
  if (scope.area_ids.length) parts.push(`Área: ${label(scope.area_ids)}`);
  if (!scope.site_ids.length && !scope.cities.length) parts.push('todas las sedes');
  else if (!scope.area_ids.length) parts.push('todas las áreas');
  return parts.join(' · ');
}

const LISTS = ['company_ids', 'cities', 'site_ids', 'area_ids'];
// Mismo rol y mismo alcance, sin importar el orden de las listas: así el panel solo envía el acceso si cambió.
export function sameAccess(a, b) {
  if (!a || !b) return !a && !b;
  const key = x => JSON.stringify([x.role_id, x.scope.kind, ...LISTS.map(k => [...(x.scope[k] || [])].sort())]);
  return key(a) === key(b);
}
