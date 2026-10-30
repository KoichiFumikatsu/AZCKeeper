import { api, readSession, currentTenant, PLATFORM_TENANT } from './api.js';

// Menu segun permisos (Koichi 2026-10-01): un modulo que la cuenta no puede abrir no se muestra. Cada pantalla pide
// un permiso y, varias, alcance "toda la empresa" (la API exige requireFull). La API sigue validando todo: esto solo
// evita mostrar lo que daria "sin permiso".
const NEEDS = {
  supervisor: ['usuarios.ver', false], 'supervisor-person': ['usuarios.ver', false],
  home: ['reportes.ver', false],
  sites: ['organizacion.ver', true], users: ['usuarios.ver', false], devices: ['equipos.ver', false], reports: ['reportes.ver', false],
  intake: ['equipos.enrolar', true], organization: ['organizacion.ver', true], schedules: ['horarios.ver', true], holidays: ['festivos.ver', true],
  absences: ['presencia.ver', false], doors: ['organizacion.ver', true], assignments: ['equipos.asignar', false], policies: ['reglas.ver', true], protection: ['equipos.ver', false], 'power-schedules': ['equipos.ver', false],
  classification: ['reportes.ver', true], 'suspicious-apps': ['cumplimiento.ver', false], 'dual-job': ['cumplimiento.ver', false],
  coverage: ['cobertura.ver', false], accounts: ['usuarios.editar', true], roles: ['roles.ver', true], sources: ['integraciones.ver', true], integrations: ['integraciones.gestionar', true],
  audit: ['auditoria.ver', true], 'client-logs': ['operacion.logs', false], 'panel-settings': ['operacion.ajustes_ver', true],
  // Puesta en marcha: la decide canSeeOnboarding (allowed); esta entrada solo cumple la guarda de menú del smoke.
  onboarding: ['organizacion.ver', true],
};

// Pantallas cuya funcion contratada es distinta a la de su permiso (la API las resuelve por operacion): sin la funcion se ocultan.
const FEATURE_OF = { doors: 'puertas', absences: 'ausencias', classification: 'clasificacion', accounts: 'autogestion_accesos', roles: 'autogestion_accesos' };

// Puesta en marcha: solo cuentas con algún permiso de administración sobre todo el cliente (nunca por nombre de rol).
const ONBOARDING_PERMS = ['organizacion.editar', 'usuarios.crear', 'usuarios.editar', 'horarios.editar', 'equipos.enrolar'];
export function canSeeOnboarding(access) {
  if (!access) return false;
  return !!access.platform || ONBOARDING_PERMS.some(code => !!(access.permissions || []).find(p => p.code === code)?.scopes?.includes('tenant'));
}

export function allowed(access, key) {
  if (['platform-home', 'tenants', 'plans', 'releases', 'server-health', 'sources', 'portal-origins', 'integrations'].includes(key)) return !!access.platform;
  if (key === 'onboarding') return canSeeOnboarding(access);
  const need = NEEDS[key];
  if (!need || access.platform) return true;
  if (FEATURE_OF[key] && !(access.features || []).includes(FEATURE_OF[key])) return false;
  const grant = access.permissions.find(p => p.code === need[0]);
  return !!grant && (!need[1] || grant.scopes.includes('tenant'));
}

// Una sola consulta por página: el menú y los atajos de Ajustes leen el mismo acceso (null si no se pudo leer).
const accessRequests = new Map();
export function loadAccess(tenant) {
  if (tenant === undefined) {
    const session = readSession();
    tenant = currentTenant() || (session?.is_platform_admin ? sessionStorage.getItem('keeper.presentation.tenant') || PLATFORM_TENANT : session?.tenant_id);
  }
  if (!accessRequests.has(tenant)) accessRequests.set(tenant, api('GET', '/me/access', { tenant }).catch(() => { accessRequests.delete(tenant); return null; }));
  return accessRequests.get(tenant);
}

export async function loginDestination(session) {
  if (session.is_platform_admin) return '/supervisor.php';
  const access = await loadAccess(session.tenant_id);
  return access?.supervisor_home ? '/supervisor.php' : '/';
}

export async function applyNavAccess() {
  const access = await loadAccess();
  if (!access) return null;
  for (const link of document.querySelectorAll('#navigation a[data-key]')) {
    if (link.closest('#group-group')) continue;
    link.hidden = !allowed(access, link.dataset.key);
  }
  for (const group of document.querySelectorAll('#navigation .nav-group')) {
    if (group.id === 'group-group') continue;
    group.hidden = ![...group.querySelectorAll('a[data-key]')].some(a => !a.hidden);
  }
  return access;
}
