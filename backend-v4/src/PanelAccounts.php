<?php
declare(strict_types=1);
namespace Keeper;

// Cuentas de acceso al panel. Dos clases:
// - superadmin (plataforma): administra todas las empresas; solo la crea otra cuenta de plataforma.
// - de empresa: ligada a una persona de esa empresa; lo que ve lo deciden sus roles (permisos + alcance: toda la
//   empresa, ciertas sedes o areas, o solo lo propio). Nunca ve otra empresa (AdminAuth::context lo impide).
// La contraseña inicial es temporal: se muestra una vez y la persona debe cambiarla al entrar.
final class PanelAccounts
{
    private string $tenant;
    public function __construct(private Database $db, private array $c, private AdminAccess $access, private Request $r) { $this->tenant = $c['tenant_id']; }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        $this->access->requireFull();
        return match ($op) {
            'listPanelAccounts' => $this->list(),
            'createPanelAccount' => $this->create($b),
            'patchPanelAccount' => $this->patch($this->find($id), $b),
            'resetPanelAccountPassword' => $this->reset($this->find($id)),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }

    private function platformTenant(): string { return Util::bin(AdminAuth::PLATFORM); }

    // La plataforma (empresa duena) ve las cuentas de todas las empresas; una cuenta de empresa, solo las suyas.
    private function list(): array
    {
        $sql = 'SELECT a.*,u.display_name user_name,t.name tenant_name FROM admin_accounts a JOIN tenants t ON t.tenant_id=a.tenant_id LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id';
        $rows = $this->c['platform']
            ? $this->db->run($sql . ' ORDER BY a.is_platform_admin DESC,t.name,a.email')->fetchAll()
            : $this->db->run($sql . ' WHERE a.tenant_id=? AND a.is_platform_admin=FALSE ORDER BY a.email', [$this->tenant])->fetchAll();
        return AdminApi::response(['data' => array_map(fn ($a) => $this->dto($a), $rows)]);
    }

    private function find(?string $id): array
    {
        $a = $this->db->one('SELECT a.*,u.display_name user_name,t.name tenant_name FROM admin_accounts a JOIN tenants t ON t.tenant_id=a.tenant_id LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.id=? AND (a.tenant_id=? OR (a.tenant_id=? AND ?)) FOR UPDATE',
            [$id, $this->tenant, $this->platformTenant(), (int) $this->c['platform']]);
        if (!$a) { throw new ApiError(404, 'resource_not_found'); }
        return $a;
    }

    private function reauth(): void
    {
        if ($this->c['reauthenticated_at'] === null || strtotime($this->c['reauthenticated_at'] . ' UTC') < time() - 300) { throw new ApiError(403, 'reauth_required'); }
    }

    // 16 caracteres sin ambiguos (0/O, 1/l/I): se dicta o copia sin errores.
    private static function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) { $out .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        return $out;
    }

    // Quien asigna el acceso de la cuenta debe poder delegarlo (mismas reglas que la pantalla de roles); la plataforma, cualquiera.
    private function delegator(): AdminAccess { return $this->c['platform'] ? $this->access : new AdminAccess($this->db, $this->c, 'roles.gestionar'); }

    private function create(object $b): array
    {
        $this->reauth();
        $email = strtolower(trim($b->email));
        // El login busca la cuenta solo por correo: un correo repetido en dos empresas dejaria de entrar.
        if ($this->db->one('SELECT id FROM admin_accounts WHERE email=?', [$email])) { throw new ApiError(409, 'invalid_transition', [], 'email_in_use'); }
        $id = Util::bin(Util::uuid()); $temporary = self::temporaryPassword();
        if ($b->kind === 'platform') {
            if (!$this->c['platform']) { throw new ApiError(403, 'permission_denied'); }
            if ($b->role_ids || $b->user_id !== null) { throw new ApiError(422, 'validation_failed'); }
            $this->db->run('INSERT INTO admin_accounts (tenant_id,id,user_id,email,display_name,password_hash,must_change_password,is_platform_admin) VALUES (?,?,NULL,?,?,?,TRUE,TRUE)',
                [$this->platformTenant(), $id, $email, $b->display_name, password_hash($temporary, PASSWORD_DEFAULT)]);
            $tenant = $this->platformTenant();
        } else {
            if ($this->tenant === $this->platformTenant() || $b->user_id === null) { throw new ApiError(422, 'validation_failed'); }
            $user = $this->access->user(Util::bin($b->user_id));
            if ($user['status'] !== 'active') { throw new ApiError(409, 'invalid_transition', [], 'person_inactive'); }
            if ($this->db->one('SELECT id FROM admin_accounts WHERE tenant_id=? AND user_id=?', [$this->tenant, $user['id']])) { throw new ApiError(409, 'invalid_transition', [], 'person_has_account'); }
            $this->db->run('INSERT INTO admin_accounts (tenant_id,id,user_id,email,display_name,password_hash,must_change_password,is_platform_admin) VALUES (?,?,?,?,?,?,TRUE,FALSE)',
                [$this->tenant, $id, $user['id'], $email, $b->display_name, password_hash($temporary, PASSWORD_DEFAULT)]);
            $this->db->run('UPDATE users SET panel_login_enabled=TRUE,version=version+1 WHERE tenant_id=? AND id=?', [$this->tenant, $user['id']]);
            if ($b->role_ids) { AccessGrants::assignLegacy($this->db, $this->delegator(), $this->tenant, $user['id'], $b->role_ids); }
            elseif (AccessGrants::forUser($this->db, $this->tenant, $user['id']) === null) { AccessGrants::grantDefault($this->db, $this->tenant, $user['id']); }
            if (($b->group_id ?? null) !== null) { $this->setGroup($id, $b->group_id, $b->group_roles ?? []); }
            $tenant = $this->tenant;
        }
        (new Audit($this->db))->record($this->c, $this->r, 'createPanelAccount', 'admin_account', $id, ['kind', 'email', 'user_id', 'role_ids']);
        $a = $this->db->one('SELECT a.*,u.display_name user_name,t.name tenant_name FROM admin_accounts a JOIN tenants t ON t.tenant_id=a.tenant_id LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.tenant_id=? AND a.id=?', [$tenant, $id]);
        return AdminApi::response(['account' => $this->dto($a), 'temporary_password' => $temporary], 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Cuenta de grupo (§8.5): la empresa casa debe ser del grupo; en las demas empresas del grupo recibe solo los
     * roles indicados. Solo la plataforma lo asigna. null quita el grupo y todos esos roles.
     */
    private function setGroup(string $account, ?string $group, array $grants): void
    {
        if (!$this->c['platform']) { throw new ApiError(403, 'permission_denied'); }
        $this->db->run('DELETE FROM access_grants WHERE admin_tenant_id=? AND admin_id=?', [$this->tenant, $account]);
        if ($group === null) { $this->db->run('UPDATE admin_accounts SET group_id=NULL,group_tenant_id=NULL WHERE tenant_id=? AND id=?', [$this->tenant, $account]); return; }
        $gid = Util::bin($group);
        $members = array_column($this->db->run('SELECT tenant_id FROM company_group_members WHERE group_id=?', [$gid])->fetchAll(), 'tenant_id');
        if (!in_array($this->tenant, $members, true)) { throw new ApiError(422, 'validation_failed', [], 'home_not_in_group'); }
        foreach ($grants as $g) {
            $t = Util::bin($g->tenant_id);
            if ($t === $this->tenant || !in_array($t, $members, true)) { throw new ApiError(422, 'validation_failed', [], 'tenant_not_in_group'); }
            $ids = array_values(array_unique($g->role_ids));
            if (count($ids) > 1) { throw new ApiError(422, 'validation_failed', [], 'one_role_only'); }
            foreach ($ids as $rid) {
                if (!$this->db->one('SELECT id FROM roles WHERE tenant_id=? AND id=? AND active=TRUE', [$t, Util::bin($rid)])) { throw new ApiError(422, 'validation_failed', [], 'role_not_found'); }
                AccessGrants::setForGroupAccount($this->db, $t, $this->tenant, $account, Util::bin($rid), AccessScope::fromLegacyRole($this->db, $t, Util::bin($rid)));
            }
        }
        $this->db->run('UPDATE admin_accounts SET group_id=?,group_tenant_id=? WHERE tenant_id=? AND id=?', [$gid, Util::bin(AdminAuth::PLATFORM), $this->tenant, $account]);
    }

    private function revokeSessions(array $a): void
    {
        $this->db->run('UPDATE admin_accounts SET auth_version=auth_version+1 WHERE tenant_id=? AND id=?', [$a['tenant_id'], $a['id']]);
        $this->db->run('UPDATE admin_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND admin_id=? AND revoked_at IS NULL', [$a['tenant_id'], $a['id']]);
    }

    private function patch(array $a, object $b): array
    {
        $self = $a['id'] === $this->c['admin_id'] && $a['tenant_id'] === $this->c['admin_tenant_id'];
        if ($self && !$b->active) { throw new ApiError(409, 'invalid_transition', [], 'cannot_disable_self'); }
        if ($a['is_platform_admin']) {
            if ($b->role_ids) { throw new ApiError(422, 'validation_failed'); }
        } else {
            $this->access->user($a['user_id']);
            AccessGrants::assignLegacy($this->db, $this->delegator(), $this->tenant, $a['user_id'], $b->role_ids);
            if (property_exists($b, 'group_id')) { $this->setGroup($a['id'], $b->group_id, $b->group_roles ?? []); }
            $this->db->run('UPDATE users SET panel_login_enabled=?,version=version+1 WHERE tenant_id=? AND id=?', [(int) $b->active, $a['tenant_id'], $a['user_id']]);
        }
        if ((bool) $a['active'] !== $b->active) {
            $this->db->run('UPDATE admin_accounts SET active=? WHERE tenant_id=? AND id=?', [(int) $b->active, $a['tenant_id'], $a['id']]);
            if (!$b->active) { $this->revokeSessions($a); }
        }
        (new Audit($this->db))->record($this->c, $this->r, 'patchPanelAccount', 'admin_account', $a['id'], ['active', 'role_ids']);
        return AdminApi::response($this->dto($this->find($a['id'])));
    }

    private function reset(array $a): array
    {
        $this->reauth();
        $temporary = self::temporaryPassword();
        $this->db->run('UPDATE admin_accounts SET password_hash=?,must_change_password=TRUE WHERE tenant_id=? AND id=?', [password_hash($temporary, PASSWORD_DEFAULT), $a['tenant_id'], $a['id']]);
        $this->revokeSessions($a);
        (new Audit($this->db))->record($this->c, $this->r, 'resetPanelAccountPassword', 'admin_account', $a['id'], ['password']);
        return AdminApi::response(['account' => $this->dto($this->find($a['id'])), 'temporary_password' => $temporary], 200, ['Cache-Control' => 'no-store']);
    }

    private function groupDto(array $a): array
    {
        if (($a['group_id'] ?? null) === null) { return ['group_id' => null, 'group_name' => null, 'group_roles' => []]; }
        $grants = [];
        foreach ($this->db->run('SELECT g.tenant_id,t.name,g.role_id FROM access_grants g JOIN tenants t ON t.tenant_id=g.tenant_id WHERE g.admin_tenant_id=? AND g.admin_id=? ORDER BY t.name,g.role_id', [$a['tenant_id'], $a['id']])->fetchAll() as $r) {
            $k = Util::id($r['tenant_id']);
            $grants[$k] ??= ['tenant_id' => $k, 'tenant_name' => $r['name'], 'role_ids' => []];
            $grants[$k]['role_ids'][] = Util::id($r['role_id']);
        }
        return ['group_id' => Util::id($a['group_id']), 'group_name' => $this->db->one('SELECT name FROM company_groups WHERE id=?', [$a['group_id']])['name'] ?? null, 'group_roles' => array_values($grants)];
    }
    private function dto(array $a): array
    {
        $g = $a['is_platform_admin'] ? null : AccessGrants::forUser($this->db, $a['tenant_id'], $a['user_id']); $roles = $g === null ? [] : [Util::id($g['role_id'])];
        return ['tenant_id' => Util::id($a['tenant_id']), 'tenant_name' => $a['tenant_name'], 'id' => Util::id($a['id']), 'kind' => $a['is_platform_admin'] ? 'platform' : 'company', 'email' => $a['email'], 'display_name' => $a['display_name'],
            'user_id' => $a['user_id'] === null ? null : Util::id($a['user_id']), 'user_name' => $a['user_name'] ?? null, 'role_ids' => $roles,
            'active' => (bool) $a['active'], 'must_change_password' => (bool) $a['must_change_password'], 'created_at' => Util::time($a['created_at']),
            'last_login_at' => $a['last_login_at'] === null ? null : Util::time($a['last_login_at'])] + $this->groupDto($a);
    }
}
