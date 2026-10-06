import { test } from 'node:test';
import assert from 'node:assert/strict';
import { scopeSummary, sameAccess, scopeConditions, TEAM_LABEL } from '../public/assets/access-scope.js';

const names = new Map([['a1', 'Agentes de servicio'], ['s1', 'Tequendama'], ['s2', 'Medellín 808'], ['c1', 'BilingueLaw']]);
const scope = (kind, lists = {}) => ({ kind, company_ids: [], cities: [], site_ids: [], area_ids: [], ...lists });

test('coordinador de un área en todas las sedes (ejemplo del diseño)', () => {
  assert.equal(scopeSummary('Coordinador', scope('filters', { area_ids: ['a1'] }), names), 'Coordinador · Área: Agentes de servicio · todas las sedes');
});
test('coordinador de dos sedes', () => {
  assert.equal(scopeSummary('Coordinador', scope('filters', { site_ids: ['s1', 's2'], area_ids: ['a1'] }), names), 'Coordinador · Sede: Tequendama, Medellín 808 · Área: Agentes de servicio');
});
test('gerente de una empresa', () => {
  assert.equal(scopeSummary('Gerente', scope('filters', { company_ids: ['c1'] }), names), 'Gerente · Empresa: BilingueLaw · todas las sedes');
});
test('ciudad sin área', () => {
  assert.equal(scopeSummary('IT', scope('filters', { cities: ['Medellín'] }), names), 'IT · Ciudad: Medellín · todas las áreas');
});
test('todo, equipo, propio y sin acceso', () => {
  assert.equal(scopeSummary('Corporativo', scope('all'), names), 'Corporativo · Todo el cliente');
  assert.equal(scopeSummary('Jefe', scope('team'), names), 'Jefe · Su equipo (la persona y quienes le reportan)');
  assert.equal(scopeSummary('Colaborador', scope('self'), names), 'Colaborador · Solo lo propio');
  assert.equal(scopeSummary(null, null, names), 'Sin acceso');
});
// Comparar accesos sin depender del orden de llaves ni de listas: evita un PUT de más (que podría chocar con la delegación).
test('mismo acceso aunque cambie el orden', () => {
  const a = { role_id: 'r1', scope: scope('filters', { site_ids: ['s1', 's2'] }) };
  const b = { scope: { area_ids: [], site_ids: ['s2', 's1'], cities: [], company_ids: [], kind: 'filters' }, role_id: 'r1' };
  assert.equal(sameAccess(a, b), true);
  assert.equal(sameAccess(a, { ...a, role_id: 'r2' }), false);
  assert.equal(sameAccess(a, { role_id: 'r1', scope: scope('filters', { site_ids: ['s1'] }) }), false);
  assert.equal(sameAccess(a, { role_id: 'r1', scope: scope('all') }), false);
  assert.equal(sameAccess(null, null), true);
  assert.equal(sameAccess(a, null), false);
});
// En el servidor un `filters` sin valores no ve a nadie (1=0): el resumen nunca dice «todas las sedes».
test('filtros sin unidades: no ve a nadie', () => {
  assert.equal(scopeSummary('Coordinador', scope('filters'), names), 'Coordinador · Filtros sin unidades: no ve a nadie');
});
// Revisión UX ACC-1: la frase del editor dice cómo cruza el servidor (Y entre grupos, cualquiera dentro de cada uno).
test('condiciones: Y entre dimensiones y cualquiera dentro de la selección', () => {
  assert.equal(scopeConditions(scope('filters', { company_ids: ['c1'], site_ids: ['s1', 's2'] }), names),
    'Verá a las personas que cumplan todas estas condiciones: la empresa BilingueLaw Y cualquiera de estas sedes: Tequendama, Medellín 808.');
  assert.equal(scopeConditions(scope('filters', { cities: ['Cali'], area_ids: ['a1'] }), names),
    'Verá a las personas que cumplan todas estas condiciones: la ciudad Cali Y el área Agentes de servicio.');
  assert.equal(scopeConditions(scope('filters'), names), null);
  assert.equal(scopeConditions(scope('all'), names), null);
});
test('Su equipo se nombra igual en el editor y en el resumen', () => {
  assert.equal(scopeSummary('Jefe', scope('team'), names), `Jefe · ${TEAM_LABEL}`);
});
