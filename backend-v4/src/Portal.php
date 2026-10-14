<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Puerta del Portal (/portal/v1), decision del dueno 2026-10-13: una sola implementacion por dato y dos puertas. El
 * Portal no tiene endpoints propios de datos: /portal/v1/<ruta> atiende la MISMA operacion que /v1/<ruta> del panel,
 * con el MISMO contexto de acceso (AdminAccess: rol + alcance), pero autenticado con un token de persona
 * (Authorization: Bearer, sin cookies ni CSRF) y solo para la lista blanca ROUTES. Todo lo demas: 404.
 *
 * Entra quien tiene cuenta del panel activa (misma contrasena) Y el interruptor «Puede entrar al Portal»
 * (users.portal_login_enabled, 0059). Los tokens se guardan con hash (portal_tokens, 0060) y se validan en cada
 * peticion contra la cuenta: cambiar la contrasena, desactivar a la persona, apagar el interruptor o subir la
 * auth_version de la cuenta los invalida. CORS solo para los origenes de portal_origins (los gestiona superadmin).
 *
 * Superadmin de plataforma (decision 2026-10-14): tambien entra, sin persona ni interruptor; ve lo mismo que en el
 * panel, de la empresa que elija con X-Tenant-ID (lista en GET /tenants). Sin X-Tenant-ID trabaja sobre la plataforma.
 * Bloqueo: 10 contrasenas fallidas seguidas para un correo (en 15 min) lo bloquean 15 min, exista o no la cuenta.
 */
final class Portal
{
    public const PREFIX = '/portal/v1';
    public const ACCESS_TTL = 900;
    /** Vida maxima de una sesion (familia de tokens) desde el login, como la sesion absoluta del panel (8 h). */
    public const SESSION_TTL = 28800;
    public const LOCK_FAILURES = 10;
    public const LOCK_SECONDS = 900;
    /**
     * Lista blanca (metodo + plantilla del contrato). Lecturas de Inicio, Personas, ficha, ventanas, linea de tiempo,
     * productividad, puntualidad, ausencias, equipos y horario; escrituras solo propias: registrar ausencia (solo para
     * la propia persona, aunque su rol permita mas) y cambiar la contrasena (/portal/v1/password).
     */
    public const ROUTES = [
        'GET /me', 'GET /me/access', 'GET /dashboard', 'GET /dashboard/problems',
        'GET /users', 'GET /users/{id}', 'GET /users/{id}/activity', 'GET /users/{id}/windows', 'GET /users/{id}/timeline',
        'GET /reports/people', 'GET /reports/presence', 'GET /reports/productivity', 'GET /reports/apps',
        'GET /absences', 'POST /absences', 'GET /tenants/{id}/devices', 'GET /devices/{id}', 'GET /schedules', 'GET /tenants',
    ];
    /** Sesion propia del Portal: en el contrato con su ruta completa. */
    public const SESSION = ['POST /portal/v1/login', 'POST /portal/v1/refresh', 'POST /portal/v1/logout', 'POST /portal/v1/password'];
    /** Hash de relleno: el login tarda lo mismo exista o no la cuenta. */
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct(private Database $db, private RateLimiter $limiter) {}

    public static function allowed(string $method, string $route): bool
    {
        return in_array($method . ' ' . $route, self::ROUTES, true) || in_array($method . ' ' . $route, self::SESSION, true);
    }

    /** Cabeceras CORS para la Origin de la peticion: solo un origen autorizado, exacto. Nunca para /v1 del panel. */
    public static function cors(Database $db, string $origin): array
    {
        $headers = ['Vary' => 'Origin'];
        if ($origin === '' || strlen($origin) > 255 || !$db->one('SELECT id FROM portal_origins WHERE origin=?', [$origin])) { return $headers; }
        return $headers + ['Access-Control-Allow-Origin' => $origin, 'Access-Control-Allow-Methods' => 'GET, POST', 'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Tenant-ID',
            'Access-Control-Expose-Headers' => 'X-Request-ID, ETag, Retry-After, RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset', 'Access-Control-Max-Age' => '600'];
    }

    /** Origen valido para la lista: https (http solo para localhost), sin ruta, consulta ni comodines. */
    public static function normalizeOrigin(string $origin): ?string
    {
        $origin = strtolower(trim($origin));
        if (!preg_match('~^(https://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+|http://(localhost|127\.0\.0\.1))(:[0-9]{1,5})?$~D', $origin)) { return null; }
        return $origin;
    }

    /** IP del cliente: REMOTE_ADDR, o el ultimo salto de X-Forwarded-For si la peticion llega por un proxy de confianza. */
    private static function ip(Request $r): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $proxies = array_filter(array_map('trim', explode(',', Config::get('TRUSTED_PROXY_IPS'))));
        if (in_array($remote, $proxies, true) && $r->header('x-forwarded-for') !== '') {
            $hops = array_map('trim', explode(',', $r->header('x-forwarded-for')));
            return end($hops) ?: $remote;
        }
        return $remote;
    }

    private static function bearer(Request $r): string
    {
        if (!preg_match('~^Bearer ([A-Za-z0-9_-]{43})$~D', $r->header('authorization'), $m)) { throw new ApiError(401, 'session_expired'); }
        return $m[1];
    }

    /** Fila del token con su cuenta, persona y empresa; null si no existe. */
    private function token(string $token, string $kind, bool $lock = false): ?array
    {
        return $this->db->one("SELECT p.*,a.active account_active,a.auth_version account_version,a.is_platform_admin,a.must_change_password,a.email,a.password_hash,
                u.status user_status,u.panel_login_enabled,u.portal_login_enabled,u.display_name,t.status tenant_status,t.deleted_at,t.archived_at
            FROM portal_tokens p JOIN admin_accounts a ON a.tenant_id=p.tenant_id AND a.id=p.admin_id
            LEFT JOIN users u ON u.tenant_id=p.tenant_id AND u.id=p.user_id AND u.id=a.user_id JOIN tenants t ON t.tenant_id=p.tenant_id
            WHERE p.token_hash=? AND p.kind=?" . ($lock ? ' FOR UPDATE' : ''), [hash('sha256', $token, true), $kind]);
    }

    /** La cuenta sigue pudiendo entrar al Portal con este token (misma version; persona activa con interruptor, o superadmin). */
    private static function valid(array $s): bool
    {
        $person = $s['is_platform_admin'] ? $s['user_id'] === null
            : $s['user_id'] !== null && $s['user_status'] === 'active' && $s['panel_login_enabled'] && $s['portal_login_enabled'];
        return (bool) $s['account_active'] && $person && (int) $s['auth_version'] === (int) $s['account_version']
            && $s['tenant_status'] === 'active' && $s['deleted_at'] === null && $s['archived_at'] === null;
    }

    private function auditContext(string $tenant, string $admin): array
    {
        $c = ['tenant_id' => $tenant, 'admin_tenant_id' => $tenant, 'admin_id' => $admin, 'actor_type' => 'admin', 'channel' => 'portal'];
        $c['principal_id'] = (new AdminAuth($this->db, $this->limiter))->principal($c);
        return $c;
    }

    /** Contexto de una peticion de datos: el mismo que arma AdminAuth::context para la cookie del panel. */
    public function context(Request $r): array
    {
        $s = $this->token(self::bearer($r), 'access');
        if (!$s || $s['revoked_at'] !== null || strtotime($s['expires_at'] . ' UTC') <= time() || !self::valid($s)) { throw new ApiError(401, 'session_expired'); }
        if ($s['must_change_password']) { throw new ApiError(403, 'password_change_required'); }
        $platform = (bool) $s['is_platform_admin'];
        $routeTenant = str_starts_with($r->route, '/tenants/{id}') ? Util::bin($r->resourceId) : null;
        $headerTenant = $r->header('x-tenant-id') !== '' ? Util::bin($r->header('x-tenant-id')) : null;
        if ($routeTenant !== null && $headerTenant !== null && $routeTenant !== $headerTenant) { throw new ApiError(404, 'resource_not_found'); }
        // Persona: el token es de su empresa; otra en la ruta o en X-Tenant-ID no existe. Superadmin: la que elija.
        $tenant = $platform ? ($routeTenant ?? $headerTenant ?? $s['tenant_id']) : $s['tenant_id'];
        if (!$platform && ($routeTenant ?? $headerTenant ?? $tenant) !== $tenant) { throw new ApiError(404, 'resource_not_found'); }
        // Escrituras del Portal: solo para la propia persona, aunque su rol permita mas (superadmin no tiene persona).
        if ($r->operation['operationId'] === 'createAbsence' && ($s['user_id'] === null || Util::bin($r->body->user_id) !== $s['user_id'])) { throw new ApiError(403, 'permission_denied', [], 'portal_self_only'); }
        $t = $this->db->one('SELECT * FROM tenants WHERE tenant_id=?' . ($r->method === 'GET' ? '' : ' FOR UPDATE'), [$tenant]);
        // Empresa eliminada (o inexistente): no existe. Archivada o inactiva solo la alcanza superadmin, como en el panel.
        if (!$t || $t['deleted_at'] !== null) { throw new ApiError(404, 'resource_not_found'); }
        if ($t['archived_at'] !== null && $r->method !== 'GET') { throw new ApiError(409, 'invalid_transition', [], 'tenant_archived'); }
        $this->limiter->take([['admin', bin2hex($s['tenant_id'] . $s['admin_id']), Config::number('ADMIN_RATE', 120), Config::number('ADMIN_BURST', 30)], ['tenant', 'admin:' . bin2hex($tenant), Config::number('ADMIN_TENANT_RATE', 600), Config::number('ADMIN_TENANT_BURST', 100)]]);
        $c = ['tenant_id' => $tenant, 'admin_tenant_id' => $s['tenant_id'], 'admin_id' => $s['admin_id'], 'user_id' => $platform ? null : $s['user_id'], 'group_id' => null, 'platform' => $platform, 'actor_type' => 'admin',
            'timezone' => $t['timezone'], 'gate' => (bool) $t['rbac_self_management'], 'auth_revision' => (int) $t['auth_version'], 'session_id' => $s['family_id'], 'reauthenticated_at' => null, 'channel' => 'portal'];
        $c['principal_id'] = (new AdminAuth($this->db, $this->limiter))->principal($c);
        return $c;
    }

    /** login, refresh, logout y password del Portal (dentro de la transaccion de la peticion). */
    public function session(Request $r): array
    {
        return match ($r->route) {
            self::PREFIX . '/login' => $this->login($r),
            self::PREFIX . '/refresh' => $this->refresh($r),
            self::PREFIX . '/logout' => $this->logout($r),
            self::PREFIX . '/password' => $this->password($r),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }

    private function login(Request $r): array
    {
        // Mismo limitador que el login del panel, por IP + correo: tras la rafaga, 429 con Retry-After (bloqueo temporal).
        $this->limiter->take([
            ['bootstrap', 'portal-auth:' . hash('sha256', self::ip($r) . '|' . strtolower($r->body->email)), Config::number('PORTAL_LOGIN_RATE', 5), Config::number('PORTAL_LOGIN_BURST', 5)],
            ['bootstrap', 'portal-auth-ip:' . hash('sha256', self::ip($r)), Config::number('PORTAL_IP_RATE', 60), Config::number('PORTAL_IP_BURST', 100)],
        ]);
        // Bloqueo por correo: mientras dure, ni la contrasena correcta entra (se responde igual exista o no la cuenta).
        $emailHash = hash('sha256', strtolower($r->body->email), true);
        $lock = $this->db->one('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(6),locked_until) wait FROM portal_login_lockouts WHERE tenant_id=? AND email_hash=? AND locked_until>UTC_TIMESTAMP(6)', [Util::bin(AdminAuth::PLATFORM), $emailHash]);
        if ($lock) { throw new ApiError(429, 'rate_limited', ['Retry-After' => (string) max(1, (int) $lock['wait'] + 1)], 'account_locked'); }
        $accounts = $this->db->run("SELECT a.*,u.portal_login_enabled,u.display_name FROM admin_accounts a JOIN tenants t ON t.tenant_id=a.tenant_id
            LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id
            WHERE a.email=? AND a.active=TRUE AND t.status='active' AND t.deleted_at IS NULL AND t.archived_at IS NULL
              AND (a.is_platform_admin=TRUE OR (u.status='active' AND u.panel_login_enabled=TRUE)) LIMIT 2 FOR UPDATE", [$r->body->email])->fetchAll();
        $valid = password_verify($r->body->password, $accounts[0]['password_hash'] ?? self::DUMMY_HASH);
        if (count($accounts) !== 1 || !$valid) {
            $e = new ApiError(401, 'invalid_credentials');
            $e->afterRollback = fn () => $this->failed($emailHash);
            throw $e;
        }
        $a = $accounts[0];
        $this->db->run('DELETE FROM portal_login_lockouts WHERE tenant_id=? AND email_hash=?', [Util::bin(AdminAuth::PLATFORM), $emailHash]);
        if (!$a['is_platform_admin'] && !$a['portal_login_enabled']) { throw new ApiError(403, 'portal_not_enabled'); }
        $this->db->run('DELETE FROM portal_tokens WHERE tenant_id=? AND admin_id=? AND expires_at<UTC_TIMESTAMP(6)-INTERVAL 1 DAY', [$a['tenant_id'], $a['id']]);
        $this->db->run('UPDATE admin_accounts SET last_login_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$a['tenant_id'], $a['id']]);
        $family = Util::bin(Util::uuid());
        (new Audit($this->db))->record($this->auditContext($a['tenant_id'], $a['id']), $r, 'portal.login', 'portal_session', $family, ['session']);
        return AdminApi::response($this->issue($a['tenant_id'], $a['id'], $a['is_platform_admin'] ? null : $a['user_id'], (int) $a['auth_version'], $family, null), 200, ['Cache-Control' => 'no-store']);
    }

    /** Suma un fallo al correo (en su propia transaccion, tras el rollback del 401); al llegar a LOCK_FAILURES lo bloquea. */
    private function failed(string $emailHash): void
    {
        $p = Util::bin(AdminAuth::PLATFORM);
        // Ventana vencida sin bloqueo, o bloqueo ya cumplido: se empieza de cero.
        $this->db->run('DELETE FROM portal_login_lockouts WHERE tenant_id=? AND email_hash=? AND ((locked_until IS NULL AND window_started_at<UTC_TIMESTAMP(6)-INTERVAL ? SECOND) OR locked_until<=UTC_TIMESTAMP(6))', [$p, $emailHash, self::LOCK_SECONDS]);
        $this->db->run('INSERT INTO portal_login_lockouts (tenant_id,email_hash,failures,window_started_at) VALUES (?,?,1,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE failures=failures+1', [$p, $emailHash]);
        $this->db->run('UPDATE portal_login_lockouts SET locked_until=UTC_TIMESTAMP(6)+INTERVAL ? SECOND WHERE tenant_id=? AND email_hash=? AND failures>=? AND locked_until IS NULL', [self::LOCK_SECONDS, $p, $emailHash, self::LOCK_FAILURES]);
        // Limpieza oportunista de filas viejas de otros correos.
        $this->db->run('DELETE FROM portal_login_lockouts WHERE tenant_id=? AND window_started_at<UTC_TIMESTAMP(6)-INTERVAL 1 DAY AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP(6)) LIMIT 100', [$p]);
    }

    /** Par nuevo de la familia: access de 15 min y refresh hasta el fin de la sesion (8 h desde el login). */
    private function issue(string $tenant, string $admin, ?string $user, int $version, string $family, ?string $sessionEnd): array
    {
        $now = $this->db->one('SELECT UTC_TIMESTAMP(6) now')['now'];
        $sessionEnd ??= gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + self::SESSION_TTL) . substr($now, 19);
        $accessEnd = gmdate('Y-m-d H:i:s', min(strtotime($now . ' UTC') + self::ACCESS_TTL, strtotime($sessionEnd . ' UTC'))) . substr($now, 19);
        if ($accessEnd <= $now) { throw new ApiError(401, 'session_expired'); }
        $tokens = [];
        foreach (['access' => $accessEnd, 'refresh' => $sessionEnd] as $kind => $end) {
            $tokens[$kind] = Util::b64(random_bytes(32));
            $this->db->run('INSERT INTO portal_tokens (tenant_id,id,family_id,kind,token_hash,admin_id,user_id,auth_version,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$tenant, Util::bin(Util::uuid()), $family, $kind, hash('sha256', $tokens[$kind], true), $admin, $user, $version, $now, $end]);
        }
        $u = $this->db->one('SELECT u.display_name,a.email,a.must_change_password,a.is_platform_admin FROM admin_accounts a LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=? WHERE a.tenant_id=? AND a.id=?', [$user, $tenant, $admin]);
        $platform = (bool) $u['is_platform_admin'];
        return ['access_token' => $tokens['access'], 'token_type' => 'Bearer', 'expires_in' => strtotime($accessEnd . ' UTC') - strtotime($now . ' UTC'),
            'refresh_token' => $tokens['refresh'], 'refresh_expires_at' => Util::time($sessionEnd),
            'user' => ['id' => $user === null ? null : Util::id($user), 'admin_id' => Util::id($admin), 'tenant_id' => $platform ? null : Util::id($tenant), 'platform' => $platform,
                'display_name' => $u['display_name'], 'email' => $u['email'], 'must_change_password' => (bool) $u['must_change_password']]];
    }

    private function refresh(Request $r): array
    {
        $this->limiter->take([['bootstrap', 'portal-refresh:' . hash('sha256', self::ip($r)), Config::number('PORTAL_IP_RATE', 60), Config::number('PORTAL_IP_BURST', 100)]]);
        if (!preg_match('~^[A-Za-z0-9_-]{43}$~D', $r->body->refresh_token)) { throw new ApiError(401, 'session_expired'); }
        $s = $this->token($r->body->refresh_token, 'refresh', true);
        if (!$s) { throw new ApiError(401, 'session_expired'); }
        // Reutilizar un refresh ya usado: alguien mas lo tiene. Se revoca toda la familia (queda guardado aunque la peticion falle).
        if ($s['used_at'] !== null) {
            $e = new ApiError(401, 'session_expired');
            $e->afterRollback = function () use ($s, $r): void {
                $this->db->run('UPDATE portal_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND family_id=? AND revoked_at IS NULL', [$s['tenant_id'], $s['family_id']]);
                (new Audit($this->db))->record($this->auditContext($s['tenant_id'], $s['admin_id']), $r, 'portal.refresh_reused', 'portal_session', $s['family_id'], ['session'], 'denied');
            };
            throw $e;
        }
        if ($s['revoked_at'] !== null || strtotime($s['expires_at'] . ' UTC') <= time() || !self::valid($s)) { throw new ApiError(401, 'session_expired'); }
        $this->db->run('UPDATE portal_tokens SET used_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$s['tenant_id'], $s['id']]);
        // Un solo access vivo por sesion: el anterior deja de servir al renovar.
        $this->db->run("UPDATE portal_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND family_id=? AND kind='access' AND revoked_at IS NULL", [$s['tenant_id'], $s['family_id']]);
        return AdminApi::response($this->issue($s['tenant_id'], $s['admin_id'], $s['user_id'], (int) $s['account_version'], $s['family_id'], $s['expires_at']), 200, ['Cache-Control' => 'no-store']);
    }

    /** Cierra la sesion del token (toda su familia). Idempotente: un token ya invalido responde 401. */
    private function logout(Request $r): array
    {
        $s = $this->token(self::bearer($r), 'access', true);
        if (!$s || $s['revoked_at'] !== null || strtotime($s['expires_at'] . ' UTC') <= time()) { throw new ApiError(401, 'session_expired'); }
        $this->db->run('UPDATE portal_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND family_id=? AND revoked_at IS NULL', [$s['tenant_id'], $s['family_id']]);
        (new Audit($this->db))->record($this->auditContext($s['tenant_id'], $s['admin_id']), $r, 'portal.logout', 'portal_session', $s['family_id'], ['session']);
        return ['status' => 204, 'json' => '', 'headers' => []];
    }

    /** Cambia la contrasena propia (la misma del panel): invalida todos los tokens y sesiones y devuelve un par nuevo. */
    private function password(Request $r): array
    {
        $s = $this->token(self::bearer($r), 'access', true);
        if (!$s || $s['revoked_at'] !== null || strtotime($s['expires_at'] . ' UTC') <= time() || !self::valid($s)) { throw new ApiError(401, 'session_expired'); }
        $this->limiter->take([['bootstrap', 'portal-password:' . bin2hex($s['tenant_id'] . $s['admin_id']), Config::number('PORTAL_LOGIN_RATE', 5), Config::number('PORTAL_LOGIN_BURST', 5)]]);
        if (!password_verify($r->body->current_password, $s['password_hash'])) { throw new ApiError(401, 'invalid_credentials'); }
        $new = $r->body->new_password;
        if (password_verify($new, $s['password_hash']) || strcasecmp($new, $s['email']) === 0) { throw new ApiError(422, 'weak_password', [], 'La contraseña nueva debe ser distinta de la actual y del correo.'); }
        $this->db->run('UPDATE admin_accounts SET password_hash=?,must_change_password=FALSE,auth_version=auth_version+1 WHERE tenant_id=? AND id=?', [password_hash($new, PASSWORD_DEFAULT), $s['tenant_id'], $s['admin_id']]);
        $this->db->run('UPDATE admin_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND admin_id=? AND revoked_at IS NULL', [$s['tenant_id'], $s['admin_id']]);
        $this->db->run('UPDATE portal_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND admin_id=? AND revoked_at IS NULL', [$s['tenant_id'], $s['admin_id']]);
        (new Audit($this->db))->record($this->auditContext($s['tenant_id'], $s['admin_id']), $r, 'portal.password_changed', 'admin_account', $s['admin_id'], ['password']);
        $version = (int) $this->db->one('SELECT auth_version FROM admin_accounts WHERE tenant_id=? AND id=?', [$s['tenant_id'], $s['admin_id']])['auth_version'];
        return AdminApi::response($this->issue($s['tenant_id'], $s['admin_id'], $s['user_id'], $version, Util::bin(Util::uuid()), null), 200, ['Cache-Control' => 'no-store']);
    }
}
