const CSRF_KEY = 'keeper.admin.csrf';
const SESSION_KEY = 'keeper.presentation.session';
export const PLATFORM_TENANT = '00000000-0000-4000-8000-000000000001'; // AdminAuth::PLATFORM.
export const isUuid = value => typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
let csrf = '';
let tenantId = null;
try { csrf = sessionStorage.getItem(CSRF_KEY) || ''; } catch { /* Storage may be unavailable. */ }

export class ApiError extends Error {
  constructor(status, problem = {}, retryAfter = '') {
    super(problem.detail || problem.title || problem.code || 'Error de consulta');
    this.status = status;
    this.code = problem.code || '';
    this.problem = problem;
    this.retryAfter = retryAfter;
  }
}
export function setTenant(tenant) {
  if (!isUuid(tenant)) throw new Error('La sesión no contiene una empresa válida.');
  tenantId = tenant;
}
export function saveCsrf(token) {
  if (typeof token !== 'string' || !token) throw new Error('La API no devolvió el token CSRF.');
  csrf = token;
  try { sessionStorage.setItem(CSRF_KEY, token); } catch { /* Keep the token in memory. */ }
}
export function saveSession(session) {
  if (typeof session.is_platform_admin !== 'boolean' || (!session.is_platform_admin && !isUuid(session.tenant_id))) {
    throw new Error('La API no devolvió un contexto de sesión válido.');
  }
  saveCsrf(session.csrf_token);
  // These fields are navigation hints; authorization always belongs to the API.
  sessionStorage.setItem(SESSION_KEY, JSON.stringify({
    tenant_id: session.tenant_id, is_platform_admin: session.is_platform_admin, expires_at: session.expires_at,
    // Cuenta de grupo empresarial: empresas a las que puede cambiar con el selector (la API autoriza cada una).
    group: session.group || null,
  }));
}
export function readSession() {
  try {
    const session = JSON.parse(sessionStorage.getItem(SESSION_KEY));
    if (!session || typeof session.is_platform_admin !== 'boolean' || !(Date.parse(session.expires_at) > Date.now())) return null;
    if (!session.is_platform_admin && !isUuid(session.tenant_id)) return null;
    return session;
  } catch { return null; }
}
export function goToLogin() {
  csrf = '';
  try {
    sessionStorage.removeItem(CSRF_KEY);
    sessionStorage.removeItem(SESSION_KEY);
    sessionStorage.removeItem('keeper.presentation.tenant');
  } catch { /* No persisted state. */ }
  location.replace('/login.php');
}
// raw: descargas binarias (instalador). Si responde bien y no es JSON devuelve la Response; los errores siguen igual.
export async function api(method, path, { body, tenant = tenantId, idempotencyKey, ifMatch, raw = false } = {}) {
  method = method.toUpperCase();
  if (!path.startsWith('/') || path.startsWith('//') || path.includes('://')) throw new Error('Ruta de API inválida.');
  const headers = { Accept: raw ? 'application/zip, application/json, application/problem+json' : 'application/json, application/problem+json' };
  if (!path.startsWith('/auth/')) {
    if (!isUuid(tenant)) throw new Error('Selecciona una empresa válida para consultar los datos.');
    headers['X-Tenant-ID'] = tenant;
  } else if (isUuid(tenant)) headers['X-Tenant-ID'] = tenant;
  if (!['GET', 'HEAD'].includes(method)) {
    if (!csrf) throw new Error('No hay token CSRF. Vuelve a iniciar sesión.');
    headers['X-CSRF-Token'] = csrf;
  }
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
  if (ifMatch !== undefined) headers['If-Match'] = `"${ifMatch}"`;
  const response = await fetch(`/v1${path}`, {
    method, headers, credentials: 'include', mode: 'same-origin', cache: 'no-store', redirect: 'error',
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  // Una contraseña mal escrita al confirmar (reautenticación o cambio de contraseña) devuelve 401 pero la sesión sigue
  // viva: solo se muestra el error. Cualquier otro 401 es sesión vencida y vuelve al login.
  const confirming = path === '/auth/reauth' || path === '/auth/password';
  if (response.status === 401 && !confirming && document.body.dataset.page !== 'login') goToLogin();
  if (response.status === 403 && document.body.dataset.page !== 'login') {
    // Contraseña temporal sin cambiar: se manda a crearla (la API rechaza todo lo demás).
    const peek = await response.clone().json().catch(() => ({}));
    if (peek.code === 'password_change_required') { location.replace('/login.php?cambiar=1'); return new Promise(() => {}); }
  }
  if (response.status === 204 && response.ok) return null;
  const type = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
  if (raw && response.ok && !['application/json', 'application/problem+json'].includes(type)) return response;
  let data;
  if (['application/json', 'application/problem+json'].includes(type)) {
    try { data = await response.json(); } catch { throw new ApiError(response.status, { code: 'invalid_response' }); }
  }
  if (!response.ok || type === 'application/problem+json') throw new ApiError(response.status, data || {}, response.headers.get('Retry-After') || '');
  if (!data || type !== 'application/json') throw new ApiError(response.status, { code: 'invalid_response' });
  return data;
}
export async function refreshCsrf() { saveCsrf((await api('GET', '/auth/csrf')).csrf_token); }
export function errorMessage(error) {
  const messages = {
    invalid_credentials: 'Correo o contraseña incorrectos.',
    csrf_invalid: 'La validación de seguridad venció. Vuelve a intentarlo.',
    invalid_response: 'La API devolvió una respuesta inválida.',
  };
  let message = messages[error.code];
  // Dato gestionado por una fuente externa (One, BD de cliente): se cambia en la fuente, no en Keeper.
  if (error.problem?.detail === 'managed_by_source') message = 'Esto lo gestiona una fuente externa (ver Fuentes de datos): cámbialo en la fuente y se actualizará aquí.';
  // Errores de funciones y accesos (docs/architecture/v4-clientes-funciones-accesos.md §7): 403 con detail = código.
  const detail = error?.problem?.detail || '';
  if (/^feature_not_contracted:/.test(detail)) message = 'La función no está contratada por el cliente.';
  if (detail === 'scope_denied') message = 'Tu alcance no incluye esa empresa, sede o área.';
  // Una regla por cargo que daría un rol mayor: el servidor dice a quién y qué rol (Problem.errors).
  if (detail === 'delegation_exceeds_own_access') message = error.problem.errors?.find?.(e => e?.code === detail)?.message || 'No puedes dar un acceso más amplio que el tuyo.';
  if (!message) {
    if (error.status === 401) message = 'Tu sesión venció. Inicia sesión de nuevo.';
    else if (error.status === 403) message = 'No tienes permiso para consultar esta información.';
    else if (error.status === 404) message = 'El recurso no existe o está fuera de tu alcance.';
    else if (error.status === 429) message = `Demasiadas consultas. ${/^\d+$/.test(error.retryAfter) ? `Espera ${error.retryAfter} segundos.` : 'Intenta de nuevo más tarde.'}`;
    else if (error.status >= 500) message = 'La API no está disponible. Vuelve a intentarlo.';
    else if (error instanceof ApiError) message = error.message;
    else if (error instanceof TypeError) message = 'No se pudo conectar con la API.';
    else message = error.message;
  }
  return `${message}${error.problem?.request_id ? ` Referencia: ${error.problem.request_id}` : ''}`;
}
