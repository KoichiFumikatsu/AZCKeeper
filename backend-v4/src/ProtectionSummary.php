<?php
declare(strict_types=1);
namespace Keeper;

/**
 * «Seguridad de los equipos → Resumen de protección» (docs/design/2026-10-14-superadmin-por-tareas.md §2.2 punto 3, G4):
 * qué compró el cliente, qué planes y personas reciben cada control y qué reporta cada equipo, en pocas consultas.
 * Cruza funciones del cliente, plan efectivo de cada persona, reglas compiladas del equipo (effective_policies), ultimo
 * reporte de controles del agente (security_reports), endurecimiento (device_hardening_status y ajustes del cliente) y
 * Smart App Control (devices.specs). El estado global se calcula en PHP (evaluate) y la pagina se corta despues.
 */
final class ProtectionSummary
{
    /** Controles por equipo que dependen de una funcion vendible: funcion, parte del agente que lo reporta y tipo de regla. */
    public const CONTROLS = [
        'web' => ['feature' => 'web', 'agent' => 'WebEnforcer', 'rule' => 'web', 'label' => 'Bloqueo web'],
        'usb' => ['feature' => 'usb', 'agent' => 'UsbEnforcer', 'rule' => 'usb', 'label' => 'USB'],
        'downloads' => ['feature' => 'descargas', 'agent' => 'DownloadEnforcer', 'rule' => 'download', 'label' => 'Descargas'],
        'installs' => ['feature' => 'instalaciones', 'agent' => 'InstallEnforcer', 'rule' => 'installation', 'label' => 'Instalación de programas'],
        'hardening' => ['feature' => 'endurecimiento', 'agent' => 'LocalAccountHardening', 'rule' => null, 'label' => 'Endurecimiento'],
    ];
    /** Funciones de seguridad que se muestran en «Qué compró» (las de control de equipos). */
    public const FEATURES = ['web', 'usb', 'descargas', 'instalaciones', 'comandos', 'borrado', 'endurecimiento'];
    /** Antes de 4.0.19 el agente no reporta Smart App Control ni K3: se considera viejo para este resumen. */
    public const MIN_AGENT = '4.0.19';
    /** Un reporte de controles mas viejo que esto ya no dice como esta el equipo hoy (el agente lo reenvia cada hora). */
    public const STALE_DAYS = 7;
    public const STATUSES = ['protected', 'partial', 'unprotected', 'no_data'];

    public function __construct(private Database $db, private array $c, private AdminAccess $access, private Request $r) {}

    public function dispatch(): array
    {
        $tenant = $this->c['tenant_id'];
        $platform = Util::bin(AdminAuth::PLATFORM);
        $site = isset($_GET['site_id']) ? Util::bin($_GET['site_id']) : null;
        if ($site !== null) {
            $unit = $this->db->one('SELECT kind,active FROM org_units WHERE tenant_id=? AND id=? AND deleted_at IS NULL', [$tenant, $site]);
            if (!$unit || $unit['kind'] !== 'site') { throw new ApiError(422, 'validation_failed'); }
            $this->access->requireFilter('site', $site);
        }
        $bought = Features::enabled($this->db, $tenant);
        $plans = [];
        foreach ($this->db->run('SELECT id,name FROM person_plans WHERE tenant_id=? AND active=TRUE ORDER BY name', [$platform])->fetchAll() as $p) { $plans[$p['id']] = ['name' => $p['name'], 'items' => []]; }
        foreach ($this->db->run('SELECT plan_id,feature_code FROM person_plan_items WHERE tenant_id=?', [$platform])->fetchAll() as $i) {
            if (isset($plans[$i['plan_id']])) { $plans[$i['plan_id']]['items'][] = $i['feature_code']; }
        }
        $default = $this->db->one('SELECT p.id,p.name FROM tenants t JOIN person_plans p ON p.tenant_id=t.default_plan_tenant_id AND p.id=t.default_plan_id AND p.active=TRUE WHERE t.tenant_id=?', [$tenant]);
        $it = $this->db->one('SELECT hardening_mode,deny_network_logon,shared_password_enc IS NOT NULL configured FROM tenant_hardening_settings WHERE tenant_id=?', [$tenant]);
        $client = [
            'it_account_configured' => (bool) ($it['configured'] ?? false),
            'hardening_mode' => $it['hardening_mode'] ?? 'panel',
            'deny_network_logon' => (bool) ($it['deny_network_logon'] ?? true),
            'unlock_pin_configured' => (bool) $this->db->one('SELECT tenant_id FROM tenant_unlock_pin WHERE tenant_id=?', [$tenant]),
            'disable_smart_app_control' => (bool) ($this->db->one('SELECT disable_smart_app_control FROM tenant_windows_security WHERE tenant_id=?', [$tenant])['disable_smart_app_control'] ?? false),
        ];

        [$where, $args] = $this->access->usersSql('u');
        $plan = PersonPlans::effectiveSql('u');
        // Una fila por equipo activo del alcance; los dos ultimos (reporte y reglas) son subconsultas con LIMIT 1 sobre sus
        // indices (tenant, equipo, fecha/version): sin LATERAL para seguir corriendo en MariaDB.
        $rows = $this->db->run("SELECT d.id,d.hostname,d.user_id,d.agent_version,d.last_seen_at,d.encryption_state,d.specs,
                u.display_name,u.status user_status,u.site_id,s.name site_name,$plan plan_id,
                h.state h_state,h.last_step h_step,
                (SELECT r.observed_at FROM security_reports r WHERE r.tenant_id=d.tenant_id AND r.device_id=d.id ORDER BY r.observed_at DESC,r.event_id DESC LIMIT 1) report_at,
                (SELECT r.controls FROM security_reports r WHERE r.tenant_id=d.tenant_id AND r.device_id=d.id ORDER BY r.observed_at DESC,r.event_id DESC LIMIT 1) report,
                (SELECT JSON_EXTRACT(e.document,'$.rules[*].kind') FROM effective_policies e WHERE e.tenant_id=d.tenant_id AND e.device_id=d.id ORDER BY e.policy_version DESC LIMIT 1) rule_kinds
            FROM devices d JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id
            LEFT JOIN org_units s ON s.tenant_id=u.tenant_id AND s.id=u.site_id
            LEFT JOIN device_hardening_status h ON h.tenant_id=d.tenant_id AND h.device_id=d.id
            WHERE d.tenant_id=? AND d.status='active' AND $where", [$tenant, ...$args])->fetchAll();
        $now = time();
        $devices = [];
        foreach ($rows as $row) {
            $functions = $row['user_status'] === 'active' && $row['plan_id'] !== null && isset($plans[$row['plan_id']]) ? array_values(array_intersect($plans[$row['plan_id']]['items'], $bought)) : [];
            $report = $row['report'] === null ? null : json_decode($row['report'], true);
            $controls = [];
            foreach ((array) $report as $control) { if (is_array($control) && isset($control['control_id'])) { $controls[$control['control_id']] = $control; } }
            $specs = $row['specs'] === null ? null : json_decode($row['specs'], true);
            $kinds = $row['rule_kinds'] === null ? [] : (array) json_decode($row['rule_kinds'], true);
            $devices[] = ['row' => $row, 'eval' => self::evaluate([
                'bought' => $bought, 'functions' => $functions, 'owner_active' => $row['user_status'] === 'active',
                'agent_version' => $row['agent_version'], 'report_at' => $row['report_at'] === null ? null : strtotime($row['report_at'] . ' UTC'), 'now' => $now,
                'controls' => $controls, 'rule_kinds' => array_count_values(array_filter($kinds, 'is_string')),
                'hardening_row' => $row['h_state'] === null ? null : ['state' => $row['h_state'], 'last_step' => $row['h_step']],
                'smart_app_control' => is_array($specs) && in_array($specs['smart_app_control'] ?? null, ['off', 'on', 'evaluation', 'unknown'], true) ? $specs['smart_app_control'] : null,
                'encryption_state' => $row['encryption_state'],
            ], $client)];
        }

        // «Qué compró» y alcance de cada control sobre todo el alcance de la cuenta (no sobre los filtros de la tabla).
        $people = [];
        // Tabla derivada: agrupar por la subconsulta del plan directamente choca con ONLY_FULL_GROUP_BY (columnas de u).
        foreach ($this->db->run("SELECT x.plan_id,COUNT(*) n FROM (SELECT $plan plan_id FROM users u WHERE u.tenant_id=? AND u.status='active' AND $where) x GROUP BY x.plan_id", [$tenant, ...$args])->fetchAll() as $p) {
            if ($p['plan_id'] !== null && isset($plans[$p['plan_id']])) { $people[$p['plan_id']] = (int) $p['n']; }
        }
        $features = [];
        foreach (self::FEATURES as $code) {
            $key = null; foreach (self::CONTROLS as $k => $def) { if ($def['feature'] === $code) { $key = $k; } }
            $including = array_filter($plans, fn ($p) => in_array($code, $p['items'], true));
            $contracted = in_array($code, $bought, true);
            $features[] = ['code' => $code, 'name' => Features::CATALOG[$code]['name'], 'contracted' => $contracted,
                'plans' => array_values(array_map(fn ($p) => $p['name'], $including)),
                'people' => $contracted ? array_sum(array_intersect_key($people, $including)) : 0,
                'devices_applied' => $key === null ? null : count(array_filter($devices, fn ($d) => $d['eval']['controls'][$key]['state'] === 'applied'))];
        }

        // Filtros de la tabla: sede, busqueda (equipo o persona, sin tildes ni mayusculas) y estado global.
        $term = isset($_GET['q']) ? self::fold($_GET['q']) : '';
        $shown = array_values(array_filter($devices, fn ($d) => ($site === null || $d['row']['site_id'] === $site)
            && ($term === '' || str_contains(self::fold($d['row']['hostname'] . ' ' . $d['row']['display_name']), $term))));
        $totals = array_fill_keys(self::STATUSES, 0);
        foreach ($shown as $d) { $totals[$d['eval']['protection']]++; }
        $totals = ['devices' => count($shown)] + $totals;
        if (isset($_GET['protection'])) { $shown = array_values(array_filter($shown, fn ($d) => $d['eval']['protection'] === $_GET['protection'])); }
        usort($shown, fn ($a, $b) => [strtolower($a['row']['hostname']), $a['row']['id']] <=> [strtolower($b['row']['hostname']), $b['row']['id']]);

        // Pagina por clave (nombre del equipo, id) con el cursor cifrado de Resources, ligado a cuenta, ruta y filtros.
        $filters = $_GET; unset($filters['cursor'], $filters['limit']); ksort($filters);
        $resources = new Resources($this->db, ['cursor_context' => Util::json([Util::id($tenant), Util::id($this->c['principal_id']), $this->c['auth_revision'], $this->r->path, $filters, 'protection'])], new Validator());
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
        if (isset($_GET['cursor'])) {
            $after = $resources->decodeCursor($_GET['cursor']);
            $shown = array_values(array_filter($shown, fn ($d) => [strtolower($d['row']['hostname']), Util::id($d['row']['id'])] > [$after['h'], $after['id']]));
        }
        $more = count($shown) > $limit; $shown = array_slice($shown, 0, $limit);
        $next = null;
        if ($more) { $last = end($shown)['row']; $next = $resources->encodeCursor(['h' => strtolower($last['hostname']), 'id' => Util::id($last['id']), 'exp' => time() + 900]); }

        return AdminApi::response(['tenant_id' => Util::id($tenant), 'generated_at' => Util::now(),
            'client' => $client + ['default_plan' => $default ? ['id' => Util::id($default['id']), 'name' => $default['name']] : null,
                'hardening_active' => in_array('endurecimiento', $bought, true) && $client['it_account_configured'], 'features' => $features],
            'totals' => $totals,
            'data' => array_map(fn ($d) => $this->deviceDto($d['row'], $d['eval'], $plans), $shown), 'next_cursor' => $next]);
    }

    private function deviceDto(array $row, array $eval, array $plans): array
    {
        return ['device_id' => Util::id($row['id']), 'hostname' => $row['hostname'], 'user_id' => Util::id($row['user_id']), 'display_name' => $row['display_name'],
            'site_id' => $row['site_id'] === null ? null : Util::id($row['site_id']), 'site_name' => $row['site_name'],
            'plan' => $row['plan_id'] !== null && isset($plans[$row['plan_id']]) ? ['id' => Util::id($row['plan_id']), 'name' => $plans[$row['plan_id']]['name']] : null,
            'agent_version' => $row['agent_version'], 'agent_outdated' => $eval['agent_outdated'],
            'last_seen_at' => $row['last_seen_at'] === null ? null : Util::time($row['last_seen_at']),
            'report_observed_at' => $row['report_at'] === null ? null : Util::time($row['report_at'])] + $eval;
    }

    /** Minusculas sin tildes ni espacios repetidos (busqueda de la tabla). */
    public static function fold(string $v): string
    {
        $v = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v) ?? ''), 'UTF-8');
        return strtr($v, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    }

    /**
     * Estado de un equipo, sin base de datos (lo prueban tests/protection.php). $d: bought, functions (plan ∩ cliente),
     * owner_active, agent_version, report_at/now (epoch), controls (control_id => {state,error_code}), rule_kinds
     * (tipo => reglas), hardening_row ({state,last_step}|null), smart_app_control, encryption_state. $client: ajustes.
     * Devuelve controls (estado por control), protection y reasons ({code,text,fix}); fix dice donde se arregla.
     */
    public static function evaluate(array $d, array $client): array
    {
        $reasons = [];
        $reason = static function (string $code, string $text, string $fix) use (&$reasons): void { $reasons[] = ['code' => $code, 'text' => $text, 'fix' => $fix]; };
        $hasReport = $d['report_at'] !== null;
        $staleDays = $hasReport ? intdiv(max(0, $d['now'] - $d['report_at']), 86400) : null;
        $stale = $hasReport && $staleDays >= self::STALE_DAYS;
        if (!$hasReport) { $reason('no_report', 'El agente aún no envió el estado de sus controles', 'device'); }
        elseif ($stale) { $reason('stale_report', "Sin reporte del agente hace $staleDays días", 'device'); }
        if (!$d['owner_active']) { $reason('owner_inactive', 'La persona asignada está retirada: el equipo no recibe controles', 'device'); }
        $outdated = $d['agent_version'] === null || version_compare((string) $d['agent_version'], self::MIN_AGENT, '<');
        if ($outdated) { $reason('agent_outdated', 'Agente viejo (' . ($d['agent_version'] ?? 'sin versión') . '): actualízalo', 'device'); }

        $controls = []; $inPlan = 0; $ok = 0; $gaps = [];
        foreach (self::CONTROLS as $key => $def) {
            if (!in_array($def['feature'], $d['bought'], true)) { $controls[$key] = ['state' => 'not_in_plan', 'detail' => 'not_contracted']; continue; }
            if (!in_array($def['feature'], $d['functions'], true)) { $controls[$key] = ['state' => 'not_in_plan', 'detail' => 'not_in_plan']; continue; }
            $inPlan++;
            $agent = $d['controls'][$def['agent']] ?? null;
            $state = $agent === null || !in_array($agent['state'] ?? null, ['applied', 'failed', 'unsupported', 'unknown'], true) ? 'unknown' : $agent['state'];
            $detail = $agent['error_code'] ?? null;
            if ($key === 'hardening') {
                // Estado real del equipo: el del agente (waiting_panel, failed_step_3_panel...); sin el, el que guardo el servidor.
                if ($agent === null && $d['hardening_row'] !== null) {
                    $row = $d['hardening_row'];
                    $state = match ($row['state']) { 'hardened' => 'applied', 'recovery_required' => 'failed', default => 'pending' };
                    $detail = $row['state'] . ($row['last_step'] !== null && $row['last_step'] !== '' ? '_step_' . $row['last_step'] : '');
                } elseif ($state === 'unknown' && $detail !== null) { $state = 'pending'; }
                if ($state !== 'applied' && !$client['it_account_configured']) { $reason('it_account_missing', 'Falta cuenta de IT', 'settings'); $gaps[] = $key; }
                elseif ($state === 'pending') {
                    $reason('hardening_pending', 'Endurecimiento pendiente (' . ($detail ?? 'sin dato') . ')' . ($client['hardening_mode'] === 'panel' ? ': endurécelo desde la ficha' : ''), 'device'); $gaps[] = $key;
                }
            }
            $controls[$key] = ['state' => $state, 'detail' => $detail];
            if ($state === 'applied' && $def['rule'] !== null && ($d['rule_kinds'][$def['rule']] ?? 0) === 0) {
                $controls[$key]['detail'] = 'no_rules';
                $reason("{$key}_no_rules", "Sin reglas de {$def['label']}", 'rules'); $gaps[] = $key;
            } elseif ($state === 'applied') { $ok++; }
            elseif (!in_array($key, $gaps, true) && $hasReport) {
                $text = match ($state) { 'failed' => "{$def['label']} falló", 'unsupported' => "{$def['label']}: no soportado en este equipo", default => "{$def['label']}: el agente no informa su estado" };
                $reason("{$key}_{$state}", $text . ($detail !== null && $state !== 'unknown' ? " ($detail)" : ''), 'device'); $gaps[] = $key;
            }
        }

        // Smart App Control: no se vende; lo que se pide es apagarlo para que no bloquee las actualizaciones de Keeper.
        $sac = $d['smart_app_control'];
        $sacAgent = $d['controls']['SmartAppControl'] ?? null;
        $sacState = match ($sac) { 'off' => 'applied', 'on' => 'failed', 'evaluation' => 'pending', default => 'unknown' };
        if (($sacAgent['state'] ?? null) === 'failed') { $sacState = 'failed'; }
        $controls['smart_app_control'] = ['state' => $sacState, 'detail' => $sac === 'unknown' ? null : $sac];
        if ($sacState === 'failed') {
            $client['disable_smart_app_control']
                ? $reason('sac_still_on', 'Smart App Control sigue activo', 'device')
                : $reason('sac_on', 'Smart App Control activo: puede bloquear las actualizaciones de Keeper', 'settings');
        } elseif ($sacState === 'pending') { $reason('sac_evaluation', 'Smart App Control en evaluación: puede activarse solo', $client['disable_smart_app_control'] ? 'device' : 'settings'); }
        // Cifrado de disco: solo informativo (Keeper no lo gestiona); null si el agente no lo reporto.
        $controls['encryption'] = match ($d['encryption_state']) { 'reported_enabled' => ['state' => 'applied', 'detail' => null], 'reported_disabled' => ['state' => 'failed', 'detail' => null], default => null };

        if (!$hasReport || $stale) { $protection = 'no_data'; }
        elseif ($inPlan === 0) {
            $protection = 'unprotected';
            if ($d['owner_active']) {
                array_intersect(array_column(self::CONTROLS, 'feature'), $d['bought'])
                    ? $reason('plan_without_controls', 'Su plan no incluye controles de seguridad', 'plan')
                    : $reason('not_contracted', 'El cliente no compró controles de seguridad', 'contract');
            }
        } else {
            $hasGap = $gaps || $outdated || in_array($sacState, ['failed', 'pending'], true);
            $protection = !$hasGap ? 'protected' : ($ok > 0 ? 'partial' : 'unprotected');
        }
        return ['controls' => $controls, 'agent_outdated' => $outdated, 'protection' => $protection, 'reason' => $reasons[0]['text'] ?? null, 'reasons' => $reasons];
    }
}
