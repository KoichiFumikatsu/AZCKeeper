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

    private function list(): array
    {
        $rows = $this->db->run('SELECT a.*,u.display_name user_name FROM admin_accounts a LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.tenant_id=? AND a.is_platform_admin=FALSE ORDER BY a.email', [$this->tenant])->fetchAll();
        if ($this->c['platform']) {
            $rows = [...$this->db->run('SELECT a.*,NULL user_name FROM admin_accounts a WHERE a.tenant_id=? AND a.is_platform_admin=TRUE ORDER BY a.email', [$this->platformTenant()])->fetchAll(), ...$rows];
        }
        return AdminApi::response(['data' => array_map(fn ($a) => $this->dto($a), $rows)]);
    }

    private function find(?string $id): array
    {
        $a = $this->db->one('SELECT a.*,u.display_name user_name FROM admin_accounts a LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.id=? AND (a.tenant_id=? OR (a.tenant_id=? AND ?)) FOR UPDATE',
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

    // Roles de la empresa que se asignan a la cuenta. Una cuenta de empresa que asigna roles necesita poder
    // delegarlos (mismas reglas que la pantalla de roles); la plataforma puede asignar cualquiera.
    private function validateRoles(array $roleIds): array
    {
        $ids = [];
        foreach (array_unique($roleIds) as $rid) {
            $role = $this->db->one('SELECT * FROM roles WHERE tenant_id=? AND id=? AND active=TRUE', [$this->tenant, Util::bin($rid)]);
            if (!$role) { throw new ApiError(422, 'validation_failed', [], 'role_not_found'); }
            if (!$this->c['platform']) {
                $permissions = array_column($this->db->run('SELECT permission_code FROM role_permissions WHERE tenant_id=? AND role_id=?', [$this->tenant, $role['id']])->fetchAll(), 'permission_code');
                $kind = $role['scope_kind'];
                $scopeIds = in_array($kind, ['area', 'site'], true)
                    ? array_map([Util::class, 'id'], array_column($this->db->run("SELECT {$kind}_id id FROM role_{$kind}_scopes WHERE tenant_id=? AND role_id=?", [$this->tenant, $role['id']])->fetchAll(), 'id')) : [];
                (new AdminAccess($this->db, $this->c, 'roles.gestionar'))->delegate($permissions, (object) ['kind' => $kind, 'resource_ids' => $scopeIds]);
            }
            $ids[] = $role['id'];
        }
        return $ids;
    }

    private function setRoles(string $user, array $roles): void
    {
        $this->db->run('DELETE FROM user_roles WHERE tenant_id=? AND user_id=?', [$this->tenant, $user]);
        foreach ($roles as $role) { $this->db->run('INSERT INTO user_roles (tenant_id,user_id,role_id) VALUES (?,?,?)', [$this->tenant, $user, $role]); }
    }

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
            $roles = $this->validateRoles($b->role_ids);
            $this->db->run('INSERT INTO admin_accounts (tenant_id,id,user_id,email,display_name,password_hash,must_change_password,is_platform_admin) VALUES (?,?,?,?,?,?,TRUE,FALSE)',
                [$this->tenant, $id, $user['id'], $email, $b->display_name, password_hash($temporary, PASSWORD_DEFAULT)]);
            $this->db->run('UPDATE users SET panel_login_enabled=TRUE,version=version+1 WHERE tenant_id=? AND id=?', [$this->tenant, $user['id']]);
            $this->setRoles($user['id'], $roles);
            $tenant = $this->tenant;
        }
        (new Audit($this->db))->record($this->c, $this->r, 'createPanelAccount', 'admin_account', $id, ['kind', 'email', 'user_id', 'role_ids']);
        $a = $this->db->one('SELECT a.*,u.display_name user_name FROM admin_accounts a LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.tenant_id=? AND a.id=?', [$tenant, $id]);
        return AdminApi::response(['account' => $this->dto($a), 'temporary_password' => $temporary], 201, ['Cache-Control' => 'no-store']);
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
            $this->setRoles($a['user_id'], $this->validateRoles($b->role_ids));
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

    private function dto(array $a): array
    {
        $roles = $a['is_platform_admin'] ? [] : array_map([Util::class, 'id'], array_column($this->db->run('SELECT role_id FROM user_roles WHERE tenant_id=? AND user_id=? ORDER BY role_id', [$a['tenant_id'], $a['user_id']])->fetchAll(), 'role_id'));
        return ['id' => Util::id($a['id']), 'kind' => $a['is_platform_admin'] ? 'platform' : 'company', 'email' => $a['email'], 'display_name' => $a['display_name'],
            'user_id' => $a['user_id'] === null ? null : Util::id($a['user_id']), 'user_name' => $a['user_name'] ?? null, 'role_ids' => $roles,
            'active' => (bool) $a['active'], 'must_change_password' => (bool) $a['must_change_password'], 'created_at' => Util::time($a['created_at']),
            'last_login_at' => $a['last_login_at'] === null ? null : Util::time($a['last_login_at'])];
    }
}
