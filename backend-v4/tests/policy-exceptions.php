<?php
declare(strict_types=1);
use Keeper\{PolicyComposer,Util};

// Excepcion por persona (2026-10-01): la empresa bloquea Facebook, una persona de Mercadeo lo tiene permitido.
// El "permitir" de la persona gana y el sitio sale de su lista; a los demas les sigue bloqueado. El agente solo acepta
// bloqueos web, asi que el permiso que gana no se envia.
function policyExceptionTests(): void
{
    $before = $GLOBALS['checks'];
    $rule = fn (string $effect, array $targets) => (object) ['id' => Util::uuid(), 'kind' => 'web', 'effect' => $effect, 'targets' => $targets, 'schedule_id' => null, 'priority' => 0];
    $tenant = ['id' => Util::uuid(), 'scope' => 'tenant', 'priority' => 0, 'rules' => [$rule('deny', ['facebook.com', 'youtube.com'])], 'management_hosts' => []];
    $person = ['id' => Util::uuid(), 'scope' => 'user', 'priority' => 0, 'rules' => [$rule('allow', ['facebook.com'])], 'management_hosts' => []];
    $input = fn (array $assignments) => ['tenant_id' => Util::uuid(), 'device_id' => Util::uuid(), 'reported_version' => 0, 'modules' => ['policies'], 'assignments' => $assignments, 'work_schedule' => null, 'rule_schedules' => []];
    $blocked = fn (object $policy) => array_merge(...array_map(fn ($r) => $r->effect === 'deny' ? $r->targets : [], array_filter($policy->rules, fn ($r) => $r->kind === 'web')));
    $others = PolicyComposer::compose($input([$tenant]));
    check($blocked($others) === ['facebook.com', 'youtube.com'], 'company blocks both sites');
    $marketing = PolicyComposer::compose($input([$tenant, $person]), $prov);
    check($blocked($marketing) === ['youtube.com'], 'person exception removes facebook from her block list');
    check(!array_filter($marketing->rules, fn ($r) => $r->kind === 'web' && $r->effect !== 'deny'), 'winning web allow is not sent to the agent');
    check((bool) array_filter($prov, fn ($p) => $p['rule']->effect === 'allow' && $p['scope'] === 'user'), 'exception still explained in provenance');
    echo 'PASS policy exceptions (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
