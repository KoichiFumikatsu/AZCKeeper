<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Puesta en marcha por cliente (fase 4, B1/B2; docs/design/2026-10-06-fase4-puesta-en-marcha.md §3 y §7.1 «híbrido»).
 * La evidencia técnica se sigue calculando en el panel; aquí se guarda solo lo que decide una persona: piloto
 * (persona y equipo), responsable, revisión o aplazamiento por paso y el cierre con autor, fecha y versión de criterios.
 * Concurrencia optimista: cada escritura trae la `version` leída; si otro administrador cambió algo, 409 version_conflict.
 * Cerrar vuelve a comprobar en el servidor las dependencias del piloto (no se fía del panel).
 *
 * Acceso: superadmin o una cuenta con algún permiso de administración y alcance «Todo el cliente». Un coordinador
 * (alcance parcial) recibe 403 scope_denied: sus listas parciales no sirven para el avance del cliente. El resumen
 * (B2) contesta lo que el administrador no puede leer por sí mismo sin dar listas, correos ni secretos.
 */
final class Onboarding
{
    /** Sube cuando cambian los criterios de «hecho» o de cierre: las decisiones viejas se marcan para revisar. */
    public const CRITERIA_VERSION = 1;
    public const OPERATIONS = ['getOnboarding', 'patchOnboarding', 'closeOnboarding', 'reopenOnboarding', 'getOnboardingSummary'];
    /** Igual que el panel (nav-access.js, onboarding-state.js): administra el cliente quien tiene alguno con alcance total. */
    public const ADMIN_PERMISSIONS = ['organizacion.editar', 'usuarios.crear', 'usuarios.editar', 'horarios.editar', 'equipos.enrolar'];
    private const PLATFORM_NAME = 'AZC Keeper';
    private const CHECK_TEXT = [
        'tenant_inactive' => 'El cliente no está activo o está archivado.',
        'pilot_missing' => 'Falta elegir la persona y el equipo del piloto.',
        'pilot_person_invalid' => 'La persona del piloto no está activa o le falta sede, área o cargo válidos.',
        'pilot_device_invalid' => 'El equipo del piloto no está activo o ya no está asignado a esa persona.',
        'pilot_not_reporting' => 'El equipo del piloto no ha reportado después de elegirlo como piloto.',
        'pilot_schedule_missing' => 'Presencia está contratada y la persona del piloto no tiene un horario con días (o márcalo «Para después»).',
        'admin_missing' => 'Falta una cuenta del cliente activa, sobre una persona activa, con rol de administración y alcance «Todo el cliente».',
        'hardening_auto_unreviewed' => 'El endurecimiento está en modo automático: marca la Protección inicial como revisada antes de cerrar.',
    ];

    private ?array $held = null;

    public function __construct(private Database $db, private array $c, private AdminAccess $access, private Request $r) {}

    /** Permiso con el que AdminAccess valida la petición: el primero de administración que la cuenta tiene y puede usar. */
    public static function permission(Database $db, array $c): string
    {
        if ($c['platform']) { return self::ADMIN_PERMISSIONS[0]; }
        $grant = AccessGrants::forContext($db, $c);
        $held = $grant === null ? [] : AccessGrants::permissions($db, $c['tenant_id'], $grant['role_id']);
        foreach (self::ADMIN_PERMISSIONS as $code) {
            $feature = Features::forOperation('', $code);
            if (in_array($code, $held, true) && ($feature === null || Features::has($db, $c['tenant_id'], $feature))) { return $code; }
        }
        return self::ADMIN_PERMISSIONS[0];
    }

    public function dispatch(string $op, ?object $b): array
    {
        $this->access->requireFull();
        if ($this->c['tenant_id'] === Util::bin(AdminAuth::PLATFORM)) { throw new ApiError(404, 'resource_not_found', [], 'platform_tenant'); }
        return match ($op) {
            'getOnboarding' => AdminApi::response($this->state()),
            'getOnboardingSummary' => AdminApi::response($this->summary()),
            'patchOnboarding' => $this->patch($b),
            'closeOnboarding' => $this->close($b),
            'reopenOnboarding' => $this->reopen($b),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }

    // ---- Lectura -------------------------------------------------------------------------------------------------

    private function row(bool $lock = false): ?array
    {
        return $this->db->one('SELECT * FROM onboarding_states WHERE tenant_id=?' . ($lock ? ' FOR UPDATE' : ''), [$this->c['tenant_id']]);
    }

    private function can(string $permission): bool
    {
        if ($this->c['platform']) { return true; }
        if ($this->held === null) {
            $grant = AccessGrants::forContext($this->db, $this->c);
            $this->held = $grant === null ? [] : AccessGrants::permissions($this->db, $this->c['tenant_id'], $grant['role_id']);
        }
        return in_array($permission, $this->held, true);
    }

    private function at(?string $value): ?string { return $value === null ? null : Util::time($value); }

    /** Quién hizo algo, sin correo: la plataforma como «AZC Keeper»; una cuenta del cliente por su nombre visible. */
    private function author(?string $principal): ?array
    {
        if ($principal === null) { return null; }
        $p = $this->db->one('SELECT admin_tenant_id,admin_id FROM principals WHERE tenant_id=? AND id=?', [$this->c['tenant_id'], $principal]);
        if (!$p || $p['admin_tenant_id'] === null) { return ['kind' => 'unknown', 'name' => null]; }
        if ($p['admin_tenant_id'] === Util::bin(AdminAuth::PLATFORM)) { return ['kind' => 'platform', 'name' => self::PLATFORM_NAME]; }
        $a = $this->db->one('SELECT a.display_name account_name,u.display_name person_name FROM admin_accounts a LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.tenant_id=? AND a.id=?', [$p['admin_tenant_id'], $p['admin_id']]);
        $name = $a ? ($a['account_name'] ?? $a['person_name']) : null;
        return ['kind' => 'client', 'name' => $name === null ? null : mb_substr($name, 0, 160)];
    }

    /** Nombre de una persona solo para quien puede ver personas (la puesta en marcha no amplía permisos). */
    private function personName(?string $user): ?string
    {
        if ($user === null || !$this->can('usuarios.ver')) { return null; }
        return $this->db->one('SELECT display_name FROM users WHERE tenant_id=? AND id=?', [$this->c['tenant_id'], $user])['display_name'] ?? null;
    }

    private function state(): array
    {
        $s = $this->row();
        $tenant = Util::id($this->c['tenant_id']);
        if ($s === null) {
            return ['tenant_id' => $tenant, 'criteria_version' => self::CRITERIA_VERSION, 'status' => 'open', 'version' => 0, 'pilot' => null,
                'responsible' => null, 'steps' => [], 'closed' => null, 'reopened' => null, 'updated_at' => null, 'updated_by' => null];
        }
        $pilot = null;
        if ($s['pilot_user_id'] !== null) {
            $device = $s['pilot_device_id'] === null ? null : $this->db->one('SELECT hostname FROM devices WHERE tenant_id=? AND id=?', [$this->c['tenant_id'], $s['pilot_device_id']]);
            $pilot = ['user_id' => Util::id($s['pilot_user_id']), 'display_name' => $this->personName($s['pilot_user_id']),
                'device_id' => $s['pilot_device_id'] === null ? null : Util::id($s['pilot_device_id']),
                'hostname' => $device !== null && $this->can('equipos.ver') ? $device['hostname'] : null,
                'selected_at' => $this->at($s['pilot_selected_at']), 'selected_by' => $this->author($s['pilot_selected_by'])];
        }
        $responsible = match ($s['responsible_kind']) {
            'platform' => ['kind' => 'platform', 'user_id' => null, 'display_name' => self::PLATFORM_NAME],
            'person' => ['kind' => 'person', 'user_id' => Util::id($s['responsible_user_id']), 'display_name' => $this->personName($s['responsible_user_id'])],
            default => null,
        };
        $steps = array_map(fn (array $d): array => ['step' => (int) $d['step'], 'decision' => $d['decision'], 'note' => $d['note'],
            'criteria_version' => (int) $d['criteria_version'], 'stale' => (int) $d['criteria_version'] < self::CRITERIA_VERSION,
            'decided_at' => $this->at($d['decided_at']), 'decided_by' => $this->author($d['decided_by'])],
            $this->db->run('SELECT * FROM onboarding_step_decisions WHERE tenant_id=? ORDER BY step', [$this->c['tenant_id']])->fetchAll());
        return ['tenant_id' => $tenant, 'criteria_version' => self::CRITERIA_VERSION, 'status' => $s['status'], 'version' => (int) $s['version'],
            'pilot' => $pilot, 'responsible' => $responsible, 'steps' => $steps,
            'closed' => $s['closed_at'] === null ? null : ['at' => $this->at($s['closed_at']), 'by' => $this->author($s['closed_by']), 'criteria_version' => (int) $s['closed_criteria_version']],
            'reopened' => $s['reopened_at'] === null ? null : ['at' => $this->at($s['reopened_at']), 'by' => $this->author($s['reopened_by']), 'reason' => $s['reopen_reason']],
            'updated_at' => $this->at($s['updated_at']), 'updated_by' => $this->author($s['updated_by'])];
    }

    // ---- Escritura -----------------------------------------------------------------------------------------------

    /** Bloquea el cliente (serializa la primera escritura) y compara la versión leída por el panel. */
    private function locked(int $expected): ?array
    {
        $this->db->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', [$this->c['tenant_id']]);
        $s = $this->row(true);
        if ((int) ($s['version'] ?? 0) !== $expected) { throw new ApiError(409, 'version_conflict', [], 'onboarding_changed'); }
        return $s;
    }

    private function save(?array $current, array $values): void
    {
        $values['updated_by'] = $this->c['principal_id'];
        if ($current === null) {
            $columns = array_keys($values);
            $this->db->run('INSERT INTO onboarding_states (tenant_id,' . implode(',', $columns) . ') VALUES (?' . str_repeat(',?', count($columns)) . ')', [$this->c['tenant_id'], ...array_values($values)]);
            return;
        }
        $set = implode(',', array_map(static fn (string $x): string => "$x=?", array_keys($values)));
        $this->db->run("UPDATE onboarding_states SET $set,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE tenant_id=?", [...array_values($values), $this->c['tenant_id']]);
    }

    private function audit(string $op, array $fields): void
    {
        (new Audit($this->db))->record($this->c, $this->r, $op, 'onboarding', $this->c['tenant_id'], $fields);
    }

    private function activeUser(string $id, string $detail): string
    {
        $user = Util::bin($id);
        if (!$this->db->one("SELECT id FROM users WHERE tenant_id=? AND id=? AND status='active'", [$this->c['tenant_id'], $user])) { throw new ApiError(422, 'validation_failed', [], $detail); }
        return $user;
    }

    private function patch(object $b): array
    {
        $s = $this->locked($b->version);
        if (($s['status'] ?? 'open') === 'closed') { throw new ApiError(409, 'invalid_transition', [], 'onboarding_closed'); }
        $values = []; $fields = [];
        if (property_exists($b, 'pilot')) {
            // Elegir el piloto exige poder ver personas y equipos: es elegir entre esas listas.
            if (!$this->can('usuarios.ver') || !$this->can('equipos.ver')) { throw new ApiError(403, 'permission_denied'); }
            $user = $device = null;
            if ($b->pilot !== null) {
                $user = $this->activeUser($b->pilot->user_id, 'pilot_person_invalid');
                if ($b->pilot->device_id !== null) {
                    $device = Util::bin($b->pilot->device_id);
                    if (!$this->db->one("SELECT id FROM devices WHERE tenant_id=? AND id=? AND user_id=? AND status='active'", [$this->c['tenant_id'], $device, $user])) { throw new ApiError(422, 'validation_failed', [], 'pilot_device_invalid'); }
                }
            }
            $changed = $user !== ($s['pilot_user_id'] ?? null) || $device !== ($s['pilot_device_id'] ?? null);
            if ($changed) {
                // La comprobación de comunicación cuenta desde aquí: un reporte anterior al cambio no prueba el piloto nuevo.
                $values += ['pilot_user_id' => $user, 'pilot_device_id' => $device, 'pilot_selected_at' => $user === null ? null : $this->db->one('SELECT UTC_TIMESTAMP(6) at')['at'],
                    'pilot_selected_by' => $user === null ? null : $this->c['principal_id']];
                $fields[] = 'pilot';
            }
        }
        if (property_exists($b, 'responsible')) {
            $kind = $b->responsible?->kind; $user = null;
            if ($kind === 'person') {
                if (!$this->can('usuarios.ver')) { throw new ApiError(403, 'permission_denied'); }
                if (($b->responsible->user_id ?? null) === null) { throw new ApiError(422, 'validation_failed', [], 'responsible_person_required'); }
                $user = $this->activeUser($b->responsible->user_id, 'responsible_person_invalid');
            } elseif (($b->responsible->user_id ?? null) !== null) { throw new ApiError(422, 'validation_failed', [], 'responsible_person_only'); }
            if ($kind !== ($s['responsible_kind'] ?? null) || $user !== ($s['responsible_user_id'] ?? null)) { $values += ['responsible_kind' => $kind, 'responsible_user_id' => $user]; $fields[] = 'responsible'; }
        }
        $steps = $b->steps ?? [];
        $numbers = array_map(static fn (object $x): int => $x->step, $steps);
        if (count(array_unique($numbers)) !== count($numbers)) { throw new ApiError(422, 'validation_failed', [], 'duplicate_step'); }
        if (!property_exists($b, 'pilot') && !property_exists($b, 'responsible') && !$steps) { throw new ApiError(422, 'validation_failed', [], 'nothing_to_change'); }
        // Sin cambios reales (mismo piloto) no se sube la versión: no invalida lo que otro administrador tiene abierto.
        if (!$values && !$steps) { return AdminApi::response($this->state()); }
        $this->save($s, $values ?: ['status' => 'open']);
        foreach ($steps as $x) {
            if ($x->decision === null) { $this->db->run('DELETE FROM onboarding_step_decisions WHERE tenant_id=? AND step=?', [$this->c['tenant_id'], $x->step]); continue; }
            $note = isset($x->note) && trim($x->note) !== '' ? trim($x->note) : null;
            $this->db->run('INSERT INTO onboarding_step_decisions (tenant_id,step,decision,note,criteria_version,decided_by) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE decision=VALUES(decision),note=VALUES(note),criteria_version=VALUES(criteria_version),decided_by=VALUES(decided_by),decided_at=UTC_TIMESTAMP(6)',
                [$this->c['tenant_id'], $x->step, $x->decision, $note, self::CRITERIA_VERSION, $this->c['principal_id']]);
        }
        if ($steps) { $fields[] = 'steps'; }
        $this->audit('patchOnboarding', $fields);
        return AdminApi::response($this->state());
    }

    private function close(object $b): array
    {
        $s = $this->locked($b->version);
        if ($s !== null && $s['status'] === 'closed') { throw new ApiError(409, 'invalid_transition', [], 'already_closed'); }
        $failed = $this->checks($s);
        if ($failed) {
            throw new ApiError(409, 'invalid_transition', [], 'onboarding_not_ready',
                array_map(static fn (string $code): array => ['pointer' => '/checks/' . $code, 'code' => $code, 'message' => self::CHECK_TEXT[$code]], $failed));
        }
        $this->db->run("UPDATE onboarding_states SET status='closed',closed_at=UTC_TIMESTAMP(6),closed_by=?,closed_criteria_version=?,version=version+1,updated_at=UTC_TIMESTAMP(6),updated_by=? WHERE tenant_id=?",
            [$this->c['principal_id'], self::CRITERIA_VERSION, $this->c['principal_id'], $this->c['tenant_id']]);
        $this->audit('closeOnboarding', ['status']);
        return AdminApi::response($this->state());
    }

    private function reopen(object $b): array
    {
        $s = $this->locked($b->version);
        if ($s === null || $s['status'] !== 'closed') { throw new ApiError(409, 'invalid_transition', [], 'not_closed'); }
        $reason = isset($b->reason) && trim($b->reason) !== '' ? trim($b->reason) : null;
        // Se conserva la fecha del último cierre (historia); reabrir solo permite revisar y volver a cerrar.
        $this->db->run("UPDATE onboarding_states SET status='open',reopened_at=UTC_TIMESTAMP(6),reopened_by=?,reopen_reason=?,version=version+1,updated_at=UTC_TIMESTAMP(6),updated_by=? WHERE tenant_id=?",
            [$this->c['principal_id'], $reason, $this->c['principal_id'], $this->c['tenant_id']]);
        $this->audit('reopenOnboarding', ['status', 'reason']);
        return AdminApi::response($this->state());
    }

    // ---- Cierre: dependencias comprobadas en el servidor ---------------------------------------------------------

    /** @return list<string> códigos de CHECK_TEXT que no se cumplen */
    private function checks(?array $s): array
    {
        $t = $this->c['tenant_id']; $failed = [];
        $tenant = $this->db->one('SELECT status,archived_at,deleted_at FROM tenants WHERE tenant_id=?', [$t]);
        if (!$tenant || $tenant['status'] !== 'active' || $tenant['archived_at'] !== null || $tenant['deleted_at'] !== null) { $failed[] = 'tenant_inactive'; }
        $decision = fn (int $step): ?string => $this->db->one('SELECT decision FROM onboarding_step_decisions WHERE tenant_id=? AND step=?', [$t, $step])['decision'] ?? null;
        $user = $s['pilot_user_id'] ?? null;
        if ($user === null || ($s['pilot_device_id'] ?? null) === null) { $failed[] = 'pilot_missing'; }
        if ($user !== null) {
            $valid = $this->db->one("SELECT u.schedule_id FROM users u
                JOIN org_units s ON s.tenant_id=u.tenant_id AND s.id=u.site_id AND s.kind='site' AND s.active=TRUE
                JOIN org_units a ON a.tenant_id=u.tenant_id AND a.id=u.area_id AND a.kind='area' AND a.active=TRUE
                JOIN org_units p ON p.tenant_id=u.tenant_id AND p.id=u.position_id AND p.kind='position' AND p.active=TRUE
                WHERE u.tenant_id=? AND u.id=? AND u.status='active'", [$t, $user]);
            if (!$valid) { $failed[] = 'pilot_person_invalid'; }
            if (Features::has($this->db, $t, 'presencia') && $decision(3) !== 'deferred'
                && !($valid && $valid['schedule_id'] !== null && $this->db->one('SELECT 1 x FROM schedule_days WHERE tenant_id=? AND schedule_id=? LIMIT 1', [$t, $valid['schedule_id']]))) {
                $failed[] = 'pilot_schedule_missing';
            }
            if (($s['pilot_device_id'] ?? null) !== null) {
                $device = $this->db->one("SELECT last_seen_at FROM devices WHERE tenant_id=? AND id=? AND user_id=? AND status='active'", [$t, $s['pilot_device_id'], $user]);
                if (!$device) { $failed[] = 'pilot_device_invalid'; }
                elseif ($device['last_seen_at'] === null || $device['last_seen_at'] <= $s['pilot_selected_at']) { $failed[] = 'pilot_not_reporting'; }
            }
        }
        if ($this->admins()['ready'] === []) { $failed[] = 'admin_missing'; }
        if (Features::has($this->db, $t, 'endurecimiento') && ($this->db->one('SELECT hardening_mode FROM tenant_hardening_settings WHERE tenant_id=?', [$t])['hardening_mode'] ?? 'panel') === 'auto' && $decision(6) !== 'reviewed') {
            $failed[] = 'hardening_auto_unreviewed';
        }
        return $failed;
    }

    /**
     * Cuentas del cliente que lo administran: activas, sobre una persona activa con ingreso al panel, con un rol activo
     * que tiene algún permiso de administración y alcance «Todo el cliente». Una sesión de plataforma no cuenta.
     * @return array{ready: list<array>, any: bool}
     */
    private function admins(): array
    {
        $t = $this->c['tenant_id']; $ready = [];
        $accounts = $this->db->run("SELECT a.id,a.user_id,a.last_login_at,a.must_change_password FROM admin_accounts a
            JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id AND u.status='active' AND u.panel_login_enabled=TRUE
            WHERE a.tenant_id=? AND a.active=TRUE AND a.is_platform_admin=FALSE ORDER BY a.created_at,a.id", [$t])->fetchAll();
        foreach ($accounts as $a) {
            $grant = AccessGrants::forUser($this->db, $t, $a['user_id']);
            if ($grant === null || $grant['scope']->kind !== 'all') { continue; }
            if (array_intersect(self::ADMIN_PERMISSIONS, AccessGrants::permissions($this->db, $t, $grant['role_id']))) { $ready[] = $a; }
        }
        $any = (bool) $this->db->one('SELECT 1 x FROM admin_accounts WHERE tenant_id=? AND is_platform_admin=FALSE LIMIT 1', [$t]);
        return ['ready' => $ready, 'any' => $any];
    }

    // ---- Resumen autorizado (B2) ---------------------------------------------------------------------------------

    /**
     * Lo que el administrador del cliente necesita saber de tareas que no puede leer por sí mismo (cuentas, plan
     * predeterminado, fuentes, cuenta de IT): solo estados y quién lo gestiona. Sin listas, correos, nombres de
     * cuentas, configuración de fuentes ni contraseñas. No sustituye ni amplía los permisos de esas pantallas.
     */
    private function summary(): array
    {
        $t = $this->c['tenant_id'];
        $features = Features::enabled($this->db, $t);
        $has = static fn (string $code): bool => in_array($code, $features, true);

        $admins = $this->admins();
        $tested = array_filter($admins['ready'], static fn (array $a): bool => $a['last_login_at'] !== null && !$a['must_change_password']);
        $preparedBy = null;
        foreach ($admins['ready'] as $a) {
            $actor = $this->db->one("SELECT p.admin_tenant_id FROM audit_log l JOIN principals p ON p.tenant_id=l.tenant_id AND p.id=l.actor_id
                WHERE l.tenant_id=? AND l.resource_type='admin_account' AND l.resource_id=? AND l.action='createPanelAccount' AND l.outcome='allowed' ORDER BY l.at LIMIT 1", [$t, $a['id']]);
            if (!$actor) { continue; }
            if ($actor['admin_tenant_id'] === Util::bin(AdminAuth::PLATFORM)) { $preparedBy = 'platform'; break; }
            $preparedBy ??= 'client';
        }
        $access = ['status' => $tested ? 'tested' : ($admins['ready'] ? 'prepared' : ($admins['any'] ? 'partial' : 'pending')),
            'prepared_by' => $preparedBy, 'managed_by' => $has('autogestion_accesos') ? 'client' : 'platform'];

        $plan = $this->db->one('SELECT p.name,p.active FROM tenants t JOIN person_plans p ON p.tenant_id=t.default_plan_tenant_id AND p.id=t.default_plan_id WHERE t.tenant_id=?', [$t]);

        $sources = $this->db->run('SELECT mode,active FROM data_sources WHERE tenant_id=?', [$t])->fetchAll();
        $owned = array_column($this->db->run('SELECT domain FROM data_domain_owners WHERE tenant_id=? ORDER BY domain', [$t])->fetchAll(), 'domain');
        $applied = $this->db->one("SELECT MAX(COALESCE(finished_at,started_at)) at FROM sync_runs WHERE tenant_id=? AND status='applied' AND simulate=FALSE AND kind='full'", [$t])['at'] ?? null;
        $pending = (int) $this->db->one("SELECT COUNT(*) n FROM sync_items WHERE tenant_id=? AND action='pending' AND resolution IS NULL", [$t])['n'];
        $origin = ['mode' => $sources || $owned ? 'one' : ($has('one') ? 'one_pending' : 'local'),
            'live' => (bool) array_filter($sources, static fn (array $x): bool => $x['active'] && $x['mode'] === 'live'),
            'owned_domains' => array_map('strval', $owned), 'last_full_applied_at' => $this->at($applied), 'pending_decisions' => $pending, 'managed_by' => 'platform'];

        $hardening = null;
        if ($has('endurecimiento')) {
            $h = $this->db->one('SELECT hardening_mode,shared_password_enc IS NOT NULL configured FROM tenant_hardening_settings WHERE tenant_id=?', [$t]);
            $hardening = ['configured' => (bool) ($h['configured'] ?? false), 'mode' => $h['hardening_mode'] ?? 'panel'];
        }
        return ['tenant_id' => Util::id($t), 'generated_at' => Util::now(), 'access' => $access,
            'plan' => ['default_plan' => $plan === null ? null : ['name' => $plan['name'], 'active' => (bool) $plan['active']], 'managed_by' => 'platform'],
            'origin' => $origin, 'hardening' => $hardening];
    }
}
