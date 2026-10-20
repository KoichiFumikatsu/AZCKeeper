<?php
declare(strict_types=1);
namespace Keeper;

/** Pure composition of an already resolved database snapshot. */
final class PolicyComposer
{
    public const VERSION = 'azckeeper-policy-2';
    public const SCOPES = ['global' => 0, 'tenant' => 1, 'site' => 2, 'area' => 3, 'user' => 4, 'device' => 5];

    /** Un tipo de regla nucleo siempre pasa; uno vendible (web, usb, download, installation) necesita su funcion. */
    public static function ruleAllowed(string $kind, array $functions): bool
    {
        $feature = Features::forRuleKind($kind);
        return $feature === null || in_array($feature, $functions, true);
    }

    /**
     * $explain (opcional, solo lectura para el panel): por cada tipo y destino, todas las reglas que lo tocan y cuales ganan,
     * mas las que quedaron fuera porque la persona no tiene la funcion. Es la misma resolucion que produce el documento.
     */
    public static function compose(array $input, ?array &$provenance = null, ?array &$explain = null): object
    {
        $provenance = [];
        $explain = ['candidates' => [], 'entries' => [], 'excluded' => []];
        $validator = new Validator();
        $assignments = $input['assignments'];
        $winners = []; $candidates = []; $hosts = []; $parts = []; $timeLock = null;
        foreach ($assignments as $assignment) {
            $level = $assignment['scope'] === 'global' ? 'platform' : $assignment['scope'];
            foreach ($assignment['management_hosts'] as $host) { $hosts[$host] = true; }
            // Bloqueo del cambio de hora: gana el alcance mas cercano y, en el mismo, la mayor prioridad; si empatan, true.
            if (($assignment['time_change_lock'] ?? null) !== null) {
                $candidate = [self::SCOPES[$assignment['scope']], (int) $assignment['priority'], (bool) $assignment['time_change_lock'], $level, $assignment['id']];
                if ($timeLock === null || array_slice($candidate, 0, 2) > array_slice($timeLock, 0, 2) || (array_slice($candidate, 0, 2) === array_slice($timeLock, 0, 2) && $candidate[2])) { $timeLock = $candidate; }
            }
            foreach ($assignment['rules'] as $rule) {
                $validator->named('Rule', $rule);
                if (!self::ruleAllowed($rule->kind, $input['functions'])) { $explain['excluded'][] = ['rule' => $rule, 'level' => $level, 'assignment' => $assignment['id']]; continue; }
                $rule = clone $rule;
                $rule->targets = self::normalizeTargets($rule->targets);
                $candidates[] = ['rule' => $rule, 'level' => $level, 'rank' => self::SCOPES[$assignment['scope']], 'assignment' => $assignment['id']];
            }
        }
        // Canonical order stabilizes serialization and diagnostics, never resolves opposite effects.
        usort($candidates, static fn ($a, $b) => [$a['rank'], $a['rule']->priority, self::canonical($a)] <=> [$b['rank'], $b['rule']->priority, self::canonical($b)]);
        $seen = []; $touches = [];
        foreach ($candidates as $index => $candidate) {
            $rule = $candidate['rule'];
            foreach ($rule->targets as $target) {
                $key = Util::json([$rule->kind, $target]);
                $touches[$key][] = $index;
                $bucket = Util::json([$candidate['rank'], $rule->priority, $key]);
                $side = $rule->effect === 'deny' ? 'deny' : 'permit';
                $opposite = $side === 'deny' ? 'permit' : 'deny';
                if (isset($seen[$bucket][$opposite])) {
                    throw new PolicyConflict($candidate['level'], $rule->kind, $target, $rule->priority, $candidates[$seen[$bucket][$opposite]], $candidate);
                }
                $seen[$bucket][$side] ??= $index;
                $precedence = [$candidate['rank'], $rule->priority];
                if (!isset($winners[$key]) || $precedence > $winners[$key]['precedence']) {
                    $winners[$key] = ['precedence' => $precedence, 'indices' => [], 'target' => $target];
                }
                if ($precedence === $winners[$key]['precedence']) { $winners[$key]['indices'][] = $index; }
            }
        }
        $explain['candidates'] = $candidates;
        foreach ($winners as $key => $winner) {
            $explain['entries'][] = ['kind' => json_decode($key)[0], 'target' => $winner['target'], 'winners' => $winner['indices'], 'others' => array_values(array_diff($touches[$key], $winner['indices']))];
        }
        $groups = [];
        foreach ($winners as $winner) {
            foreach ($winner['indices'] as $index) { $groups[$index][] = $winner['target']; }
        }
        krsort($groups, SORT_NUMERIC);
        $rules = []; $scheduleKeys = []; $scheduleIds = [];
        // The first schedule is the work schedule; the rest are rule conditions.
        if ($input['work_schedule'] !== null) {
            $schedule = $input['work_schedule'];
            $key = $schedule->tenant_id . '/' . $schedule->id;
            $scheduleKeys[$key] = $schedule;
            $scheduleIds[$schedule->id] = $key;
            $parts['user']['work_schedule'] = $schedule;
        }
        $emitted = [];
        foreach ($groups as $index => $targets) {
            $candidate = $candidates[$index];
            $rule = clone $candidate['rule'];
            $rule->targets = self::normalizeTargets($targets);
            $identity = self::canonical([$candidate['level'], $rule]);
            if (isset($emitted[$identity])) { $provenance[$emitted[$identity]-1]['assignment_ids'][]=$candidate['assignment']; continue; }
            $provenance[]=['rule'=>$rule,'scope'=>$candidate['level']==='platform'?'global':$candidate['level'],'assignment_ids'=>[$candidate['assignment']]];
            $emitted[$identity] = count($provenance);
            // El agente aplica la web como lista de bloqueo. Un "permitir" que gana (p. ej. Facebook para una persona de
            // Mercadeo sobre el bloqueo de la empresa) ya cumplio su funcion: el sitio sale de la lista. Enviarlo hacia
            // que el agente rechazara toda la politica web del equipo. Queda en la procedencia para explicarlo.
            $parts[$candidate['level']]['rules'][] = $rule;
            if ($rule->kind === 'web' && $rule->effect !== 'deny') { continue; }
            $rules[] = $rule;
            if ($rule->schedule_id !== null) {
                $schedule = $input['rule_schedules'][$candidate['level'] === 'platform' ? 'global/' . $rule->schedule_id : 'tenant/' . $rule->schedule_id];
                $key = $schedule->tenant_id . '/' . $schedule->id;
                if (isset($scheduleIds[$schedule->id]) && $scheduleIds[$schedule->id] !== $key) {
                    throw new \RuntimeException('Ambiguous cross-tenant schedule UUID');
                }
                $scheduleIds[$schedule->id] = $key;
                $scheduleKeys[$key] = $schedule;
                $parts[$candidate['level']]['schedules'][$key] = $schedule;
            }
        }
        $explain['time_change_lock'] = $timeLock === null ? null : ['value' => $timeLock[2], 'level' => $timeLock[3], 'assignment' => $timeLock[4]];
        // Solo con true y solo a agentes que lo entienden (el compilador decide): ausente = desbloqueado. Entra al hash.
        $lockOn = ($input['time_change_lock_supported'] ?? false) && $timeLock !== null && $timeLock[2];
        if ($lockOn) { $parts[$timeLock[3]]['time_change_lock'] = true; }
        // Keep the first 30 distinct hosts in lexical order across all assignments.
        $managementHosts = array_slice(self::normalizeTargets(array_keys($hosts)), 0, 30);
        $parts['platform']['compiler'] = self::VERSION;
        $parts['platform']['management_hosts'] = $managementHosts;
        $composition = [];
        foreach (array_keys(self::SCOPES) as $scope) {
            $level = $scope === 'global' ? 'platform' : $scope;
            if (isset($parts[$level])) {
                $composition[] = (object) ['level' => $level, 'revision' => hash('sha256', self::canonical($parts[$level]))];
            }
        }
        $work = $input['work_schedule'];
        if ($work !== null) { unset($scheduleKeys[$work->tenant_id . '/' . $work->id]); }
        ksort($scheduleKeys, SORT_STRING);
        $document = (object) ['tenant_id' => $input['tenant_id'], 'device_id' => $input['device_id'], 'rules' => $rules,
            // Reserve the first slot for work; retain remaining schedules by tenant/UUID order.
            'schedules' => array_slice(array_merge($work === null ? [] : [$work], array_values($scheduleKeys)), 0, 100), 'composition' => $composition, 'management_hosts' => $managementHosts];
        // Solo para agentes que la entienden (el compilador decide); entra al hash de composicion y cambia la version.
        $capture = $input['capture'] ?? null;
        if ($capture !== null) {
            $document->capture = (object) ['windows' => (bool) $capture['windows'], 'window_titles' => (bool) $capture['window_titles'], 'activity' => (bool) $capture['activity']];
        }
        if ($lockOn) { $document->time_change_lock = true; }
        $validator->named('EffectivePolicy', self::versioned($document, 1));
        return $document;
    }

    public static function normalizeTargets(array $targets): array
    {
        $targets = array_values(array_unique($targets));
        sort($targets, SORT_STRING);
        return $targets;
    }

    public static function canonical(mixed $value): string
    {
        return Util::canonical(json_decode(Util::json($value), false, 64, JSON_THROW_ON_ERROR));
    }

    public static function versioned(object $document, int $version): object
    {
        $result = clone $document;
        $result->version = (string) $version;
        $result->etag = '"' . $version . '"';
        return $result;
    }
}
