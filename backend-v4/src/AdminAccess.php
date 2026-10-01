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
        } elseif (($c['group_id'] ?? null) !== null && $c['tenant_id'] !== $c['admin_tenant_id']) {
            // Cuenta de grupo en otra empresa del grupo: solo los roles que se le dieron en esa empresa.
            $this->grants=$db->run('SELECT r.id,r.scope_kind FROM admin_account_roles g JOIN roles r ON r.tenant_id=g.tenant_id AND r.id=g.role_id AND r.active=TRUE JOIN role_permissions p ON p.tenant_id=r.tenant_id AND p.role_id=r.id JOIN permissions m ON m.code=p.permission_code AND m.platform_only=FALSE WHERE g.admin_tenant_id=? AND g.admin_id=? AND g.tenant_id=? AND p.permission_code=?',[$c['admin_tenant_id'],$c['admin_id'],$c['tenant_id'],$permission])->fetchAll();
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
            elseif ($g['scope_kind']==='team') { $team=$this->team(); $parts[]=$team?"$alias.$userKey IN (".implode(',',array_fill(0,count($team),'?')).')':'1=0'; array_push($args,...$team); }
            else { $kind=$g['scope_kind']; $parts[]="$alias.{$kind}_id IN (SELECT {$kind}_id FROM role_{$kind}_scopes WHERE tenant_id=? AND role_id=?)"; array_push($args,$this->c['tenant_id'],$g['id']); }
        }
        return ['(' . implode(' OR ',$parts) . ')',$args];
    }
    private ?array $teamIds=null;
    /** La persona de la cuenta y quienes le reportan, directa o indirectamente (alcance "team", §8.4). */
    public function team(): array
    {
        if ($this->teamIds!==null) { return $this->teamIds; }
        if (($this->c['user_id']??null)===null) { return $this->teamIds=[]; }
        $seen=[bin2hex($this->c['user_id'])=>$this->c['user_id']]; $level=[$this->c['user_id']];
        for ($depth=0; $level && $depth<20; $depth++) {
            $next=[];
            foreach ($this->db->run('SELECT id FROM users WHERE tenant_id=? AND reports_to_user_id IN ('.implode(',',array_fill(0,count($level),'?')).')',[$this->c['tenant_id'],...$level])->fetchAll() as $row) {
                if (!isset($seen[bin2hex($row['id'])])) { $seen[bin2hex($row['id'])]=$row['id']; $next[]=$row['id']; }
            }
            $level=$next;
        }
        return $this->teamIds=array_values($seen);
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
        if (in_array($scope->kind,['tenant','self','team'],true)) { return false; }
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
