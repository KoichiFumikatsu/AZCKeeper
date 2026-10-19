<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Pestaña «Reglas» de la ficha de la persona y de la del equipo: lo que le aplica hoy y de dónde viene cada regla
 * (GET /users/{id}/rules y GET /devices/{id}/rules). Solo lectura. La resolución es la del compilador
 * (PolicyCompiler::explain → PolicyComposer::compose): aquí solo se ponen nombres a las asignaciones, se dice qué
 * quedó fuera por el plan o por no estar contratado y qué versión tiene el equipo. Alcance: el de AdminAccess (404 fuera).
 */
final class EffectiveRules
{
    /** Tipos de regla que el panel ofrece desde la ficha, en el orden en que se muestran. */
    public const KINDS = ['web', 'usb', 'download', 'installation'];
    private const PLATFORM = '00000000-0000-4000-8000-000000000001';
    private array $sources = [];

    public function __construct(private Database $db, private array $c, private AdminAccess $access) {}

    public function dispatch(string $op, string $id): array
    {
        $tenant = $this->c['tenant_id'];
        if ($op === 'getDeviceRules') {
            $d = $this->db->one('SELECT * FROM devices WHERE tenant_id=? AND id=?', [$tenant, $id]);
            if (!$d) { throw new ApiError(404, 'resource_not_found'); }
            $this->access->user($d['user_id']);
            $device = $id;
        } else { $this->access->user($id); $device = null; }
        try { $result = (new PolicyCompiler($this->db))->explain($tenant, $device, $device === null ? $id : ''); }
        catch (ApiError $e) { throw $e; }
        // Instantanea imposible de resolver (sin asignacion vigente, unidad inactiva): lo mismo que impide compilar.
        catch (\RuntimeException) { throw new ApiError(409, 'invalid_transition', [], 'policy_unresolvable'); }
        $input = $result['input']; $explain = $result['explain'];
        $subjectUser = $device === null ? $id : (($this->db->one('SELECT user_id FROM device_assignments WHERE tenant_id=? AND device_id=? AND starts_at<=UTC_TIMESTAMP(6) AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6))', [$tenant, $device]) ?: $d)['user_id']);
        $user = $this->db->one('SELECT id,display_name,status FROM users WHERE tenant_id=? AND id=?', [$tenant, $subjectUser]);
        $own = ['type' => $device === null ? 'user' : 'device', 'id' => $device ?? $id];
        $candidates = $explain['candidates'];
        $entries = [];
        foreach ($explain['entries'] as $entry) {
            $winners = array_map(fn ($i) => $this->source($candidates[$i], $own), $entry['winners']);
            $others = array_map(fn ($i) => $this->source($candidates[$i], $own), $entry['others']);
            // Lo que pierde se lista de lo mas cercano a lo mas general (el orden del compilador es el inverso).
            usort($others, fn ($a, $b) => [PolicyComposer::SCOPES[$b['scope']], $b['priority']] <=> [PolicyComposer::SCOPES[$a['scope']], $a['priority']]);
            $entries[] = ['kind' => $entry['kind'], 'target' => $entry['target'], 'effect' => $candidates[$entry['winners'][0]]['rule']->effect, 'winners' => $winners, 'overridden' => $others];
        }
        $order = array_flip(self::KINDS);
        usort($entries, fn ($a, $b) => [$order[$a['kind']] ?? 99, $a['kind'], $a['target']] <=> [$order[$b['kind']] ?? 99, $b['kind'], $b['target']]);
        $contracted = array_flip(Features::enabled($this->db, $tenant));
        $excluded = [];
        foreach ($explain['excluded'] as $x) {
            $feature = Features::forRuleKind($x['rule']->kind);
            $excluded[] = ['kind' => $x['rule']->kind, 'effect' => $x['rule']->effect, 'targets' => PolicyComposer::normalizeTargets($x['rule']->targets),
                'reason' => isset($contracted[$feature]) ? 'not_in_plan' : 'not_contracted', 'source' => $this->source($x, $own)];
        }
        $kinds = array_map(function (string $kind) use ($input, $contracted): array {
            $feature = Features::forRuleKind($kind);
            return ['kind' => $kind, 'feature' => $feature, 'feature_name' => Features::CATALOG[$feature]['name'], 'contracted' => isset($contracted[$feature]), 'in_plan' => in_array($feature, $input['functions'], true)];
        }, self::KINDS);
        $conflict = null;
        if ($result['conflict'] !== null) {
            $c = $result['conflict'];
            $conflict = ['kind' => $c->kind, 'target' => $c->target, 'sources' => [$this->source($c->first, $own), $this->source($c->second, $own)]];
        }
        return AdminApi::response([
            'subject' => ['type' => $own['type'], 'user_id' => Util::id($subjectUser), 'device_id' => $device === null ? null : Util::id($device),
                'display_name' => $user['display_name'] ?? null, 'hostname' => $device === null ? null : $d['hostname'], 'active' => ($user['status'] ?? null) === 'active'],
            'entries' => $entries, 'excluded' => $excluded, 'kinds' => $kinds, 'conflict' => $conflict,
            'own_policies' => $this->ownPolicies($own['type'], $own['id']),
            'devices' => $this->devices($device, $subjectUser),
            'recompile_pending' => (bool) $this->db->one('SELECT 1 x FROM admin_policy_recompiles WHERE tenant_id=?', [$tenant]),
        ]);
    }

    /** Una regla candidata con el nombre de su politica y de su destino. */
    private function source(array $candidate, array $own): array
    {
        $assignment = $candidate['assignment'];
        $a = $this->sources[$assignment] ??= $this->db->one('SELECT a.tenant_id,a.scope,a.scope_target,p.id policy_id,p.name FROM policy_assignments a
            JOIN policy_documents p ON p.tenant_id=a.tenant_id AND p.id=a.policy_id WHERE a.tenant_id IN (?,?) AND a.id=?', [$this->c['tenant_id'], Util::bin(self::PLATFORM), Util::bin($assignment)]) ?: null;
        $scope = $a['scope'] ?? ($candidate['level'] === 'platform' ? 'global' : $candidate['level']);
        $target = $a === null ? null : $a['scope_target'];
        $name = match ($scope) {
            'site', 'area' => $this->db->one('SELECT name FROM org_units WHERE tenant_id=? AND id=?', [$this->c['tenant_id'], $target])['name'] ?? null,
            'user' => $this->db->one('SELECT display_name FROM users WHERE tenant_id=? AND id=?', [$this->c['tenant_id'], $target])['display_name'] ?? null,
            'device' => $this->db->one('SELECT hostname FROM devices WHERE tenant_id=? AND id=?', [$this->c['tenant_id'], $target])['hostname'] ?? null,
            default => null,
        };
        return ['policy_id' => $a === null || $scope === 'global' ? null : Util::id($a['policy_id']), 'policy_name' => $a['name'] ?? null, 'scope' => $scope, 'target_name' => $name,
            'effect' => $candidate['rule']->effect, 'priority' => (int) $candidate['rule']->priority, 'own' => $scope === $own['type'] && $target !== null && $target === $own['id']];
    }

    /** Politicas cuyo destino es esta persona o este equipo (activas o no): la ficha edita una de ellas en vez de crear otra. */
    private function ownPolicies(string $type, string $id): array
    {
        $rows = $this->db->run("SELECT p.* FROM policy_documents p JOIN policy_assignments a ON a.tenant_id=p.tenant_id AND a.policy_id=p.id
            WHERE p.tenant_id=? AND a.scope=? AND a.scope_target=? ORDER BY p.enabled DESC,p.created_at,p.id", [$this->c['tenant_id'], $type, $id])->fetchAll();
        return array_map(fn ($r) => AdminApi::policyResource($this->db, $this->c['tenant_id'], $r), $rows);
    }

    /** Equipo(s) y la version de reglas compilada frente a la que el agente dice tener aplicada. */
    private function devices(?string $device, string $user): array
    {
        $tenant = $this->c['tenant_id'];
        $rows = $device !== null ? $this->db->run('SELECT * FROM devices WHERE tenant_id=? AND id=?', [$tenant, $device])->fetchAll()
            : $this->db->run("SELECT d.* FROM devices d LEFT JOIN device_assignments a ON a.tenant_id=d.tenant_id AND a.device_id=d.id AND a.starts_at<=UTC_TIMESTAMP(6) AND (a.ends_at IS NULL OR a.ends_at>UTC_TIMESTAMP(6))
                WHERE d.tenant_id=? AND d.status='active' AND COALESCE(a.user_id,d.user_id)=? ORDER BY d.hostname,d.id", [$tenant, $user])->fetchAll();
        return array_map(function ($d) use ($tenant): array {
            $compiled = $this->db->one('SELECT MAX(policy_version) v FROM effective_policies WHERE tenant_id=? AND device_id=?', [$tenant, $d['id']])['v'];
            $own = array_column($this->db->run("SELECT a.policy_id FROM policy_assignments a JOIN policy_documents p ON p.tenant_id=a.tenant_id AND p.id=a.policy_id AND p.enabled=TRUE
                WHERE a.tenant_id=? AND a.scope='device' AND a.device_id=? AND a.enabled=TRUE ORDER BY a.policy_id", [$tenant, $d['id']])->fetchAll(), 'policy_id');
            return ['device_id' => Util::id($d['id']), 'hostname' => $d['hostname'], 'status' => $d['status'], 'compiled_version' => $compiled === null ? null : (int) $compiled,
                'reported_version' => (int) $d['policy_version'], 'up_to_date' => $compiled !== null && (int) $d['policy_version'] >= (int) $compiled,
                'last_seen_at' => $d['last_seen_at'] === null ? null : Util::time($d['last_seen_at']), 'own_policy_ids' => array_map([Util::class, 'id'], $own)];
        }, $rows);
    }
}
