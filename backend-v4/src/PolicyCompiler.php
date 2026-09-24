<?php
declare(strict_types=1);
namespace Keeper;

final class PolicyCompiler
{
    private const PLATFORM = '00000000-0000-4000-8000-000000000001';
    private Validator $validator;
    public function __construct(private Database $db) { $this->validator = new Validator(); }

    public function validateForTenant(string $tenantId): void
    {
        $tenant=self::uuid($tenantId);
        $at=$this->db->one('SELECT UTC_TIMESTAMP(6) instant')['instant'];
        foreach ($this->db->run("SELECT id FROM devices WHERE tenant_id=? AND status='active' ORDER BY id",[$tenant])->fetchAll() as $row) {
            PolicyComposer::compose($this->snapshot($tenant,$row['id'],$at));
        }
    }

    public function recompile(string $deviceId, ?string $tenantId = null, ?\DateTimeImmutable $at = null): array
    {
        $device = self::uuid($deviceId);
        $args = [$device]; $where = '';
        if ($tenantId !== null) { $where = ' AND tenant_id=?'; $args[] = self::uuid($tenantId); }
        $matches = $this->db->run('SELECT tenant_id FROM devices WHERE id=?' . $where, $args)->fetchAll();
        if (count($matches) !== 1) { throw new \RuntimeException('Device absent or ambiguous; supply its tenant UUID'); }
        $tenant = $matches[0]['tenant_id'];
        if ($this->db->pdo->inTransaction()) { throw new \LogicException('Recompile after committing the source change'); }
        $this->db->run('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $result = $this->db->transaction(function () use ($tenant, $device, $at): array|PolicyConflict {
            $counter = $this->db->one('SELECT policy_version FROM tenants WHERE tenant_id=? FOR UPDATE', [$tenant]);
            $instant = $at === null ? $this->db->one('SELECT UTC_TIMESTAMP(6) instant')['instant'] : Util::sqlTime($at->format('Y-m-d\TH:i:s.uP'));
            $input = $this->snapshot($tenant, $device, $instant);
            try { $composition = PolicyComposer::compose($input); }
            catch (PolicyConflict $conflict) {
                $actor = $this->db->one("SELECT id FROM principals WHERE tenant_id=? AND kind='system' ORDER BY id LIMIT 1", [$tenant]);
                if (!$actor) { throw new \RuntimeException('Provision a tenant system principal before compiling policies'); }
                $this->db->run("INSERT INTO audit_log (tenant_id,id,actor_id,actor_type,action,resource_type,resource_id,request_id,outcome,changed_fields,reason) VALUES (?,?,?,'system','policy.conflict','effective_policy',?,?,'denied',?,?)", [$tenant, Util::bin(Util::uuid()), $actor['id'], $device, Util::bin(Util::uuid()), Util::json([]), $conflict->getMessage()]);
                return $conflict;
            }
            $hash = hash('sha256', PolicyComposer::canonical($composition), true);
            $previous = $this->db->one('SELECT policy_version,composition_hash,document FROM effective_policies WHERE tenant_id=? AND device_id=? ORDER BY policy_version DESC LIMIT 1', [$tenant, $device]);
            if ($previous && hash_equals($previous['composition_hash'], $hash)) {
                return ['tenant_id' => Util::id($tenant), 'device_id' => Util::id($device), 'policy_version' => (int) $previous['policy_version'], 'changed' => false, 'hash' => bin2hex($hash)];
            }
            $maximum = $this->db->one('SELECT MAX(policy_version) version FROM effective_policies WHERE tenant_id=?', [$tenant]);
            $last = max((int) $maximum['version'], (int) $counter['policy_version'], $input['reported_version']);
            if ($last === PHP_INT_MAX) { throw new \OverflowException('Policy version exhausted'); }
            $version = $last + 1;
            $document = PolicyComposer::versioned($composition, $version);
            $this->validator->named('EffectivePolicy', $document);
            $json = PolicyComposer::canonical($document);
            if (strlen($json) > 1048576) { throw new \RuntimeException('Effective policy exceeds 1 MiB'); }
            $actor = $this->db->one("SELECT id FROM principals WHERE tenant_id=? AND kind='system' ORDER BY id LIMIT 1", [$tenant]);
            if (!$actor) { throw new \RuntimeException('Provision a tenant system principal before compiling policies'); }
            $this->db->run('INSERT INTO effective_policies (tenant_id,device_id,policy_version,compiler_version,composition_hash,document,content_hash) VALUES (?,?,?,?,?,?,?)', [$tenant, $device, $version, PolicyComposer::VERSION, $hash, $json, hash('sha256', $json, true)]);
            $this->db->run("INSERT INTO audit_log (tenant_id,id,actor_id,actor_type,action,resource_type,resource_id,request_id,outcome,changed_fields) VALUES (?,?,?,'system','policy.compiled','effective_policy',?,?,'allowed',?)", [$tenant, Util::bin(Util::uuid()), $actor['id'], $device, Util::bin(Util::uuid()), Util::json(['policy_version', 'composition_hash', 'document'])]);
            return ['tenant_id' => Util::id($tenant), 'device_id' => Util::id($device), 'policy_version' => $version, 'changed' => true, 'hash' => bin2hex($hash)];
        });
        // Commit the conflict audit before reporting failure to the caller.
        if ($result instanceof PolicyConflict) { throw $result; }
        return $result;
    }

    public function recompileForTenant(string $tenantId): array
    {
        $tenant = self::uuid($tenantId);
        if (!$this->db->one('SELECT tenant_id FROM tenants WHERE tenant_id=?', [$tenant])) { throw new \RuntimeException('Tenant not found'); }
        $results = [];
        foreach ($this->db->run("SELECT id FROM devices WHERE tenant_id=? AND status='active' ORDER BY id", [$tenant])->fetchAll() as $row) {
            $results[] = $this->recompile(Util::id($row['id']), $tenantId);
        }
        return $results;
    }

    public function recompileAll(): array
    {
        $results = [];
        foreach ($this->db->run('SELECT tenant_id FROM tenants ORDER BY tenant_id')->fetchAll() as $row) {
            array_push($results, ...$this->recompileForTenant(Util::id($row['tenant_id'])));
        }
        return $results;
    }

    private static function uuid(string $value): string
    {
        (new Validator())->check(['type' => 'string', 'format' => 'uuid'], $value);
        return Util::bin($value);
    }

    public function forUser(string $tenant, string $user): array
    {
        $at=$this->db->one('SELECT UTC_TIMESTAMP(6) t')['t'];
        $devices=$this->db->run("SELECT d.id FROM devices d LEFT JOIN device_assignments a ON a.tenant_id=d.tenant_id AND a.device_id=d.id AND a.starts_at<=? AND (a.ends_at IS NULL OR a.ends_at>?) WHERE d.tenant_id=? AND d.status='active' AND COALESCE(a.user_id,d.user_id)=? ORDER BY d.id",[$at,$at,$tenant,$user])->fetchAll();
        $result=[];
        foreach ($devices?:[['id'=>null]] as $device) {
            try { $policy=PolicyComposer::compose($this->snapshot($tenant,$device['id'],$at,$user),$sources); }
            catch (PolicyConflict) { throw new ApiError(409,'invalid_transition'); }
            if ($device['id']===null) { $policy->device_id=null; }
            $result[]=['device_id'=>$device['id']===null?null:Util::id($device['id']),'policy'=>$policy,'rules'=>$sources];
        }
        return ['user_id'=>Util::id($user),'devices'=>$result];
    }

    private function snapshot(string $tenant, ?string $device, string $at, ?string $subject=null): array
    {
        $d = $device===null ? ['user_id'=>$subject,'policy_version'=>0] : $this->db->one('SELECT * FROM devices WHERE tenant_id=? AND id=?', [$tenant, $device]);
        if (!$d) { throw new \RuntimeException('Device disappeared'); }
        $assignment = $device===null ? null : $this->current('device_assignments', 'device_id', $tenant, $device, $at);
        $userId = $assignment['user_id'] ?? $d['user_id'];
        $user = $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $userId]);
        $org = $this->current('user_assignments', 'user_id', $tenant, $userId, $at) ?? $user;
        foreach (['firm', 'site', 'area', 'position'] as $kind) {
            if (!$this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind=? AND active=TRUE', [$tenant, $org[$kind . '_id'], $kind])) {
                throw new \RuntimeException('Inactive organizational assignment');
            }
        }
        $modules = [];
        if ($user['status'] === 'active') {
            $rows = $this->db->run("SELECT tm.module_code FROM subscriptions s JOIN tier t ON t.tenant_id=s.tenant_id AND t.id=s.tier_id AND t.active=TRUE JOIN tier_module tm ON tm.tenant_id=t.tenant_id AND tm.tier_id=t.id LEFT JOIN firma_module_override o ON o.tenant_id=s.tenant_id AND o.firm_id=? AND o.module_code=tm.module_code WHERE s.tenant_id=? AND s.user_id=? AND s.status IN ('active','scheduled') AND s.starts_at<=? AND (s.ends_at IS NULL OR s.ends_at>?) AND (o.enabled IS NULL OR o.enabled=TRUE) ORDER BY tm.module_code", [$org['firm_id'], $tenant, $userId, $at, $at])->fetchAll();
            $modules = array_column($rows, 'module_code');
        }
        $platform = Util::bin(self::PLATFORM);
        $rows = $this->db->run("SELECT a.*,v.document FROM policy_assignments a JOIN policy_documents p ON p.tenant_id=a.tenant_id AND p.id=a.policy_id AND p.enabled=TRUE JOIN policy_versions v ON v.tenant_id=a.tenant_id AND v.policy_id=a.policy_id AND v.policy_version=a.policy_version WHERE a.enabled=TRUE AND ((a.tenant_id=? AND a.scope='global') OR (a.tenant_id=? AND (a.scope='tenant' OR (a.scope='area' AND a.area_id=?) OR (a.scope='site' AND a.site_id=?) OR (a.scope='user' AND a.user_id=?) OR (a.scope='device' AND a.device_id=?))))", [$platform, $tenant, $org['area_id'], $org['site_id'], $userId, $device])->fetchAll();
        $assignments = []; $ruleSchedules = [];
        foreach ($rows as $row) {
            $document = json_decode($row['document'], false, 64, JSON_THROW_ON_ERROR);
            if (!isset($document->rules) || !is_array($document->rules)) { throw new \RuntimeException('Published policy requires rules'); }
            $rules = [];
            foreach ($this->db->run('SELECT * FROM policy_rules WHERE tenant_id=? AND policy_id=? AND policy_version=?', [$row['tenant_id'], $row['policy_id'], $row['policy_version']])->fetchAll() as $r) {
                $rules[] = (object) ['id' => Util::id($r['id']), 'kind' => $r['kind'], 'effect' => $r['effect'], 'targets' => json_decode($r['targets'], false, 64, JSON_THROW_ON_ERROR), 'schedule_id' => $r['schedule_id'] === null ? null : Util::id($r['schedule_id']), 'priority' => (int) $r['priority']];
            }
            if ($this->normalizedRules($rules) !== $this->normalizedRules($document->rules)) { throw new \RuntimeException('Published document and policy_rules disagree'); }
            $hosts = $document->management_hosts ?? [];
            $this->validator->check(['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 255]], $hosts);
            foreach ($rules as $rule) {
                if ($rule->schedule_id !== null && in_array(PolicyComposer::RULE_MODULES[$rule->kind], $modules, true)) {
                    $key = ($row['scope'] === 'global' ? 'global/' : 'tenant/') . $rule->schedule_id;
                    $ruleSchedules[$key] = $this->schedule($row['tenant_id'], Util::bin($rule->schedule_id));
                }
            }
            $assignments[] = ['id' => Util::id($row['id']), 'scope' => $row['scope'], 'priority' => (int) $row['priority'], 'rules' => $rules, 'management_hosts' => $hosts];
        }
        return ['tenant_id' => Util::id($tenant), 'device_id' => Util::id($device??$userId), 'reported_version' => (int) $d['policy_version'], 'modules' => $modules, 'assignments' => $assignments, 'work_schedule' => $this->schedule($tenant, $org['schedule_id']), 'rule_schedules' => $ruleSchedules];
    }

    private function current(string $table, string $owner, string $tenant, string $id, string $at): ?array
    {
        $row = $this->db->one("SELECT * FROM $table WHERE tenant_id=? AND $owner=? AND starts_at<=? AND (ends_at IS NULL OR ends_at>?)", [$tenant, $id, $at, $at]);
        if (!$row && $this->db->one("SELECT id FROM $table WHERE tenant_id=? AND $owner=? LIMIT 1", [$tenant, $id])) {
            throw new \RuntimeException('No assignment covers the compilation instant');
        }
        return $row;
    }

    private function normalizedRules(array $rules): string
    {
        $normalized = [];
        foreach ($rules as $rule) {
            $this->validator->named('Rule', $rule);
            $r = clone $rule; $r->targets = PolicyComposer::normalizeTargets($r->targets);
            $normalized[] = PolicyComposer::canonical($r);
        }
        sort($normalized, SORT_STRING);
        return Util::json($normalized);
    }

    private function schedule(string $tenant, string $id): object
    {
        $s = $this->db->one('SELECT * FROM schedules WHERE tenant_id=? AND id=?', [$tenant, $id]);
        if (!$s) { throw new \RuntimeException('Schedule not found'); }
        if (substr($s['start_local'], 6) !== '00' || substr($s['end_local'], 6) !== '00') { throw new \RuntimeException('OpenAPI schedules require minute precision'); }
        new \DateTimeZone($s['timezone']);
        $days = $this->db->run('SELECT weekday FROM schedule_days WHERE tenant_id=? AND schedule_id=? ORDER BY weekday', [$tenant, $id])->fetchAll();
        return (object) ['id' => Util::id($id), 'tenant_id' => Util::id($tenant), 'name' => $s['name'], 'timezone' => $s['timezone'], 'days' => array_map('intval', array_column($days, 'weekday')), 'start_local' => substr($s['start_local'], 0, 5), 'end_local' => substr($s['end_local'], 0, 5), 'version' => (int) $s['version']];
    }
}
