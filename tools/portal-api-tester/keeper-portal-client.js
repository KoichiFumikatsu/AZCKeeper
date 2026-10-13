// Cliente de ejemplo de la API del Portal de Keeper (/portal/v1).
// Es el patrón que debe copiar el Portal (Lovable): tokens SOLO en memoria, una única renovación a la vez,
// reintento de un pedido una sola vez tras renovar, y nunca filtrar datos en el navegador: Keeper ya
// devuelve solo lo que la persona puede ver.
export class KeeperPortalClient {
  /** @param {string} server  p. ej. "https://devkeep.azclegal.com" (sin barra final) */
  constructor(server) {
    this.base = server.replace(/\/+$/, '') + '/portal/v1';
    this.access = null;      // access_token: 15 min. NO guardarlo en localStorage ni en cookies.
    this.refreshToken = null; // refresh_token: un solo uso. Guardar SIEMPRE el último recibido.
    this.user = null;
    this.refreshing = null;  // promesa compartida: varios pedidos que vencen a la vez esperan UNA renovación.
    this.onLog = () => {};   // gancho para la página de prueba (pedido/respuesta).
  }

  // --- sesión ---------------------------------------------------------------------------------------
  async login(email, password) {
    const r = await this.raw('POST', '/login', { email, password }, { auth: false });
    if (r.ok) this.#keep(r.body);
    return r;
  }
  async refresh() {
    // Una sola renovación en vuelo: si dos pedidos reciben 401 a la vez, ambos esperan esta misma promesa.
    if (!this.refreshing) {
      this.refreshing = (async () => {
        const r = await this.raw('POST', '/refresh', { refresh_token: this.refreshToken }, { auth: false });
        if (r.ok) this.#keep(r.body); else { this.access = null; this.refreshToken = null; }
        return r;
      })().finally(() => { this.refreshing = null; });
    }
    return this.refreshing;
  }
  async logout() {
    const r = await this.raw('POST', '/logout');
    this.access = null; this.refreshToken = null; this.user = null;
    return r;
  }
  async changePassword(current_password, new_password) {
    const r = await this.raw('POST', '/password', { current_password, new_password });
    if (r.ok) this.#keep(r.body);
    return r;
  }
  #keep(body) { this.access = body.access_token; this.refreshToken = body.refresh_token; this.user = body.user; }

  // --- datos ----------------------------------------------------------------------------------------
  /** GET/POST con renovación automática: si responde 401 session_expired, renueva una vez y repite. */
  async call(method, path, body) {
    let r = await this.raw(method, path, body);
    if (r.status === 401 && this.refreshToken) {
      const renewed = await this.refresh();
      if (renewed.ok) r = await this.raw(method, path, body);
    }
    return r;
  }
  /** Recorre todas las páginas de una lista (next_cursor). */
  async all(path, max = 20) {
    const items = []; let cursor = null; let pages = 0; let last;
    do {
      const sep = path.includes('?') ? '&' : '?';
      last = await this.call('GET', cursor ? `${path}${sep}cursor=${encodeURIComponent(cursor)}` : path);
      if (!last.ok) return last;
      items.push(...(last.body.data || []));
      cursor = last.body.next_cursor; pages++;
    } while (cursor && pages < max);
    return { ...last, body: { ...last.body, data: items, next_cursor: null } };
  }

  /** Pedido sin lógica: devuelve {ok, status, body, headers, ms, request}. */
  async raw(method, path, body, { auth = true, token } = {}) {
    const headers = { Accept: 'application/json' };
    const bearer = token ?? (auth ? this.access : null);
    if (bearer) headers.Authorization = `Bearer ${bearer}`;
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const url = this.base + path, started = performance.now();
    const request = { method, url, headers: { ...headers, ...(bearer ? { Authorization: 'Bearer ' + bearer.slice(0, 6) + '…' } : {}) }, body: body && { ...body, ...(body.password ? { password: '••••' } : {}), ...(body.current_password ? { current_password: '••••', new_password: '••••' } : {}), ...(body.refresh_token ? { refresh_token: body.refresh_token.slice(0, 6) + '…' } : {}) } };
    let res;
    try { res = await fetch(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'omit' }); }
    catch (e) {
      const out = { ok: false, status: 0, body: { code: 'network_or_cors', detail: 'El navegador bloqueó el pedido o no hubo red. Si es CORS: falta autorizar esta dirección en Keeper (Plataforma → Portal: direcciones autorizadas).' }, headers: {}, ms: Math.round(performance.now() - started), request };
      this.onLog(out); return out;
    }
    const text = await res.text(); let parsed = null;
    try { parsed = text ? JSON.parse(text) : null; } catch { parsed = { raw: text.slice(0, 500) }; }
    const picked = {}; for (const h of ['x-request-id', 'retry-after', 'ratelimit-remaining', 'ratelimit-reset', 'access-control-allow-origin']) { const v = res.headers.get(h); if (v !== null) picked[h] = v; }
    const out = { ok: res.ok, status: res.status, body: parsed, headers: picked, ms: Math.round(performance.now() - started), request };
    this.onLog(out); return out;
  }
}
