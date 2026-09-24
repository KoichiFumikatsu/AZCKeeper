<?php
declare(strict_types=1);
namespace Keeper;

final class AdminAccess
{
    private array $grants = [];
    public function __construct(private Database $db, public readonly array $c, public readonly string $permission)
    {
        if ($c['platform']) {
            if (!$db->one('SELECT code FROM permissions WHERE code=?',[$permission])) { throw new ApiError(403,'permission_denied'); }
            $this->grants = [['scope_kind'=>'tenant']];
        } else {
            $this->grants=$db->run('SELECT r.id,r.scope_kind FROM user_roles u JOIN roles r ON r.tenant_id=u.tenant_id AND r.id=u.role_id AND r.active=TRUE JOIN role_permissions p ON p.tenant_id=r.tenant_id AND p.role_id=r.id JOIN permissions m ON m.code=p.permission_code AND m.platform_only=FALSE WHERE u.tenant_id=? AND u.user_id=? AND p.permission_code=?',[$c['tenant_id'],$c['user_id'],$permission])->fetchAll();
        }
        if (!$this->grants) { throw new ApiError(403,'permission_denied'); }
    }
    public function full(): bool { return in_array('tenant',array_column($this->grants,'scope_kind'),true); }
    public function hasScopeKind(string $kind): bool { return in_array($kind,array_column($this->grants,'scope_kind'),true); }
    public function requireFull(): void { if (!$this->full()) { throw new ApiError(403,'permission_denied'); } }
    public function rolesGate(): void
    {
        if (!$this->c['platform'] && !$this->c['gate']) { throw new ApiError(403,'tenant_rbac_disabled'); }
    }
    public function usersSql(string $alias='u',string $userKey='id'): array
    {
        if ($this->full()) { return ['1=1',[]]; }
        $parts=[]; $args=[];
        foreach ($this->grants as $g) {
            if ($g['scope_kind']==='self') { $parts[]="$alias.$userKey=?"; $args[]=$this->c['user_id']; }
            else { $kind=$g['scope_kind']; $parts[]="$alias.{$kind}_id IN (SELECT {$kind}_id FROM role_{$kind}_scopes WHERE tenant_id=? AND role_id=?)"; array_push($args,$this->c['tenant_id'],$g['id']); }
        }
        return ['(' . implode(' OR ',$parts) . ')',$args];
    }
    public function user(string $id): array
    {
        [$where,$args]=$this->usersSql();
        $u=$this->db->one("SELECT u.* FROM users u WHERE u.tenant_id=? AND u.id=? AND $where",[$this->c['tenant_id'],$id,...$args]);
        if (!$u) { throw new ApiError(404,'resource_not_found'); } return $u;
    }
    public function allowsScope(object $scope): bool
    {
        if ($this->full()) { return true; }
        if ($scope->kind==='tenant' || $scope->kind==='self') { return false; }
        $available=[];
        foreach ($this->grants as $g) {
            if ($g['scope_kind']!==$scope->kind) { continue; }
            $kind=$scope->kind;
            foreach ($this->db->run("SELECT {$kind}_id id FROM role_{$kind}_scopes WHERE tenant_id=? AND role_id=?",[$this->c['tenant_id'],$g['id']])->fetchAll() as $row) { $available[]=Util::id($row['id']); }
        }
        return count(array_diff($scope->resource_ids,$available))===0;
    }
    public function delegate(array $permissions, object $scope): void
    {
        $this->rolesGate();
        if (!$this->allowsScope($scope)) { throw new ApiError(403,'permission_denied'); }
        foreach ($permissions as $code) {
            $p=$this->db->one('SELECT * FROM permissions WHERE code=?',[$code]);
            if (!$p || $p['platform_only']) { throw new ApiError(403,'permission_denied'); }
            if (!$this->c['platform']) {
                if ($code==='roles.gestionar' || !$p['delegable']) { throw new ApiError(403,'permission_denied'); }
                $access=new self($this->db,$this->c,$code);
                if (!$access->allowsScope($scope)) { throw new ApiError(403,'permission_denied'); }
            }
        }
    }
}
