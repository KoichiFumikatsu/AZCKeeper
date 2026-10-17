<?php
declare(strict_types=1);
use Keeper\{AccessScope,Database,ProtectionSummary,Util};

// «Seguridad de los equipos → Resumen de protección» (G4): estado global y motivos (evaluate, sin base), y el endpoint
// GET /tenants/{id}/protection con alcance (coordinador de una sede), funciones no compradas, filtros y paginas.
function protectionEvaluateTests(): void
{
    $before = $GLOBALS['checks'];
    $now = 1_800_000_000;
    $all = ['web', 'usb', 'descargas', 'instalaciones', 'comandos', 'borrado', 'endurecimiento', 'productividad'];
    $applied = fn (string ...$ids) => array_combine($ids, array_map(fn ($id) => ['control_id' => $id, 'state' => 'applied'], $ids));
    $agents = $applied('WebEnforcer', 'UsbEnforcer', 'DownloadEnforcer', 'InstallEnforcer', 'LocalAccountHardening');
    $base = ['bought' => $all, 'functions' => $all, 'owner_active' => true, 'agent_version' => '4.0.26', 'report_at' => $now - 3600, 'now' => $now,
        'controls' => $agents, 'rule_kinds' => ['web' => 3, 'usb' => 1, 'download' => 1, 'installation' => 2], 'hardening_row' => null,
        'smart_app_control' => 'off', 'encryption_state' => 'reported_enabled'];
    $client = ['it_account_configured' => true, 'hardening_mode' => 'panel', 'deny_network_logon' => true, 'unlock_pin_configured' => true, 'disable_smart_app_control' => true];
    $eval = fn (array $over = [], array $clientOver = []) => ProtectionSummary::evaluate(array_replace($base, $over), array_replace($client, $clientOver));
    $codes = fn (array $e) => array_column($e['reasons'], 'code');

    $e = $eval();
    check($e['protection'] === 'protected' && $e['reasons'] === [] && $e['reason'] === null, 'everything applied with rules = Protegido, sin motivos');
    check($e['controls']['usb'] === ['state' => 'applied', 'detail' => null] && $e['controls']['smart_app_control']['state'] === 'applied' && $e['controls']['encryption']['state'] === 'applied', 'per control states');

    $e = $eval(['controls' => ['UsbEnforcer' => ['control_id' => 'UsbEnforcer', 'state' => 'failed', 'error_code' => 'driver_blocked']] + $agents]);
    check($e['protection'] === 'partial' && $e['reason'] === 'USB falló (driver_blocked)' && $e['reasons'][0]['fix'] === 'device', 'USB falló = Parcial, se arregla en la ficha');

    $e = $eval(['controls' => ['LocalAccountHardening' => ['control_id' => 'LocalAccountHardening', 'state' => 'unknown', 'error_code' => 'waiting_panel']] + $agents], ['it_account_configured' => false]);
    check($e['protection'] === 'partial' && $e['reason'] === 'Falta cuenta de IT' && $e['reasons'][0]['fix'] === 'settings', 'Falta cuenta de IT = Parcial, se arregla en Ajustes');
    check($e['controls']['hardening'] === ['state' => 'pending', 'detail' => 'waiting_panel'], 'hardening shows the real device state');

    $e = $eval(['controls' => ['LocalAccountHardening' => ['control_id' => 'LocalAccountHardening', 'state' => 'unknown', 'error_code' => 'waiting_panel']] + $agents]);
    check($codes($e) === ['hardening_pending'] && str_contains($e['reason'], 'waiting_panel') && str_contains($e['reason'], 'desde la ficha') && $e['protection'] === 'partial', 'panel mode: hardening pending, harden from the device page');
    $noHardReport = $agents; unset($noHardReport['LocalAccountHardening']);
    $e = $eval(['controls' => $noHardReport, 'hardening_row' => ['state' => 'panel_wait', 'last_step' => '0']]);
    check($e['controls']['hardening'] === ['state' => 'pending', 'detail' => 'panel_wait_step_0'], 'without agent control: server hardening row (panel_wait_step_0)');
    $e = $eval(['controls' => $noHardReport, 'hardening_row' => ['state' => 'hardened', 'last_step' => null]]);
    check($e['controls']['hardening']['state'] === 'applied' && $e['protection'] === 'protected', 'hardened row = applied');

    $e = $eval(['agent_version' => '4.0.12']);
    check($e['protection'] === 'partial' && $e['agent_outdated'] && $codes($e) === ['agent_outdated'] && str_starts_with($e['reason'], 'Agente viejo'), 'Agente viejo = Parcial');

    $e = $eval(['report_at' => null, 'controls' => []]);
    check($e['protection'] === 'no_data' && $codes($e)[0] === 'no_report', 'never reported = Sin datos');
    $e = $eval(['report_at' => $now - 9 * 86400]);
    check($e['protection'] === 'no_data' && $e['reason'] === 'Sin reporte del agente hace 9 días', 'stale report = Sin datos');

    $e = $eval(['bought' => ['productividad'], 'functions' => ['productividad']]);
    check($e['protection'] === 'unprotected' && $codes($e) === ['not_contracted'] && $e['reasons'][0]['fix'] === 'contract', 'nothing bought = Sin proteger (funciones del cliente)');
    check($e['controls']['web'] === ['state' => 'not_in_plan', 'detail' => 'not_contracted'] && $e['controls']['hardening']['detail'] === 'not_contracted', 'not bought = not_in_plan / not_contracted');
    $e = $eval(['functions' => ['productividad']]);
    check($e['protection'] === 'unprotected' && $codes($e) === ['plan_without_controls'] && $e['reasons'][0]['fix'] === 'plan' && $e['controls']['usb']['detail'] === 'not_in_plan', 'plan without controls = Sin proteger (plan de la persona)');
    $e = $eval(['functions' => ['web', 'productividad']]);
    check($e['protection'] === 'protected' && $e['controls']['usb']['state'] === 'not_in_plan', 'a control the plan excludes does not count against the device');

    $e = $eval(['rule_kinds' => ['web' => 3, 'download' => 1, 'installation' => 2]]);
    check($e['protection'] === 'partial' && $codes($e) === ['usb_no_rules'] && $e['reasons'][0]['fix'] === 'rules' && $e['controls']['usb'] === ['state' => 'applied', 'detail' => 'no_rules'], 'in plan without rules = Parcial, se arregla en Reglas');

    $e = $eval(['smart_app_control' => 'on'], ['disable_smart_app_control' => false]);
    check($e['protection'] === 'partial' && $codes($e) === ['sac_on'] && $e['reasons'][0]['fix'] === 'settings', 'Smart App Control on, not requested off: fix in Ajustes');
    $e = $eval(['smart_app_control' => 'on']);
    check($codes($e) === ['sac_still_on'] && $e['reasons'][0]['fix'] === 'device', 'Smart App Control still on after asking: fix on the device');
    $e = $eval(['smart_app_control' => null, 'encryption_state' => 'unknown']);
    check($e['protection'] === 'protected' && $e['controls']['smart_app_control'] === ['state' => 'unknown', 'detail' => null] && $e['controls']['encryption'] === null, 'unknown SAC / encryption without data do not block');
    $e = $eval(['encryption_state' => 'reported_disabled']);
    check($e['protection'] === 'protected' && $e['controls']['encryption']['state'] === 'failed', 'encryption is informative only');

    $failed = array_map(fn ($c) => ['state' => 'failed'] + $c, $agents);
    $e = $eval(['controls' => $failed]);
    check($e['protection'] === 'unprotected' && count($e['reasons']) === 5, 'every control failed = Sin proteger');
    $e = $eval(['owner_active' => false, 'functions' => []]);
    check($e['protection'] === 'unprotected' && $codes($e) === ['owner_inactive'], 'retired owner: no controls, one reason');
    check(ProtectionSummary::fold('  Medellín   ÑANDÚ ') === 'medellin nandu', 'search folding');
    echo 'PASS protection evaluate (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

function protectionApiTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $t = $o['tenant'];
    $platformTenant = Util::bin('00000000-0000-4000-8000-000000000001');
    $device = fn (string $key) => $db->one('SELECT id FROM devices WHERE tenant_id=? AND user_id=?', [$t, $o[$key]['id']])['id'];
    $db->run("UPDATE devices SET agent_version='4.0.26',specs='{\"smart_app_control\":\"off\"}' WHERE tenant_id=?", [$t]);
    $report = function (string $key, array $states, string $ago = '1 HOUR') use ($db, $t, $device): void {
        $controls = []; foreach ($states as $id => $state) { $controls[] = is_array($state) ? ['control_id' => $id] + $state + ['observed_at' => Util::now()] : ['control_id' => $id, 'state' => $state, 'observed_at' => Util::now()]; }
        $db->run("INSERT INTO security_reports (tenant_id,device_id,event_id,observed_at,report_hash,controls) VALUES (?,?,?,UTC_TIMESTAMP(6)-INTERVAL $ago,?,?)", [$t, $device($key), Util::bin(Util::uuid()), random_bytes(32), Util::json($controls)]);
    };
    $rules = function (string $key, array $kinds) use ($db, $t, $device): void {
        $doc = Util::json(['rules' => array_map(fn ($k) => ['kind' => $k], $kinds)]);
        $db->run("INSERT INTO effective_policies (tenant_id,device_id,policy_version,compiler_version,composition_hash,document,content_hash) VALUES (?,?,(SELECT COALESCE(MAX(p.policy_version),0)+1 FROM effective_policies p WHERE p.tenant_id=? AND p.device_id=?),'test',?,?,?)",
            [$t, $device($key), $t, $device($key), random_bytes(32), $doc, random_bytes(32)]);
    };
    $ok = ['WebEnforcer' => 'applied', 'UsbEnforcer' => 'applied', 'DownloadEnforcer' => 'applied', 'InstallEnforcer' => 'applied', 'LocalAccountHardening' => 'applied'];
    $every = ['web', 'usb', 'download', 'installation'];
    // Tequendama: juan protegido, bl1 con USB fallido. Medellin: ag808 sin cuenta de IT (endurecimiento esperando el panel).
    $report('juan', ['WebEnforcer' => 'unknown'] + $ok, '3 HOUR'); $report('juan', $ok); $rules('juan', ['usb']); $rules('juan', $every);
    $report('bl1', ['UsbEnforcer' => ['state' => 'failed', 'error_code' => 'driver_blocked']] + $ok); $rules('bl1', $every);
    $report('ag808', ['LocalAccountHardening' => ['state' => 'unknown', 'error_code' => 'waiting_panel']] + $ok); $rules('ag808', $every);
    $report('zafra', $ok, '10 DAY'); $rules('zafra', $every);
    $platform = sharedPlatform($db); $platform['tenant'] = $t;
    $path = '/tenants/' . Util::id($t) . '/protection';
    $byHost = fn (array $r) => array_column($r['data'], null, 'hostname');

    $r = expect(adminRequest($platform, 'GET', $path), 200, 'protection summary (platform)');
    $rows = $byHost($r);
    check($r['totals'] === ['devices' => 8, 'protected' => 1, 'partial' => 2, 'unprotected' => 0, 'no_data' => 5], 'totals without IT account: ' . Util::json($r['totals']));
    check($r['client']['it_account_configured'] === false && $r['client']['hardening_active'] === false && $r['client']['hardening_mode'] === 'panel' && $r['client']['unlock_pin_configured'] === false, 'client settings without IT account nor PIN');
    check($rows['PC-juan']['protection'] === 'protected' && $rows['PC-juan']['reasons'] === [], 'latest report wins; an already hardened device needs nothing');
    check($rows['PC-ag808']['reason'] === 'Falta cuenta de IT' && $rows['PC-ag808']['reasons'][0]['fix'] === 'settings' && $rows['PC-ag808']['protection'] === 'partial', 'pending hardening without IT account: Falta cuenta de IT');
    check($rows['PC-zafra']['protection'] === 'no_data' && str_starts_with($rows['PC-zafra']['reason'], 'Sin reporte del agente hace 10'), 'stale device = Sin datos');
    check($rows['PC-agTorre']['protection'] === 'no_data' && $rows['PC-agTorre']['reason'] === 'El agente aún no envió el estado de sus controles', 'never reported = Sin datos');
    check($rows['PC-juan']['plan']['name'] === 'Completo' && $rows['PC-juan']['site_name'] === 'Tequendama' && $rows['PC-juan']['display_name'] === 'juan' && $rows['PC-juan']['agent_outdated'] === false, 'person, site, plan and agent');
    $features = array_column($r['client']['features'], null, 'code');
    check(array_keys($features) === ProtectionSummary::FEATURES && $features['usb']['contracted'] && in_array('Completo', $features['usb']['plans'], true), 'qué compró: control features with plans');
    check($features['usb']['people'] === 9 && $features['usb']['devices_applied'] === 3 && $features['comandos']['devices_applied'] === null, 'people reached and devices reporting it applied');
    check($r['client']['default_plan']['name'] === 'Completo' && $r['next_cursor'] === null, 'default plan, single page');

    // Con cuenta de IT, PIN y Smart App Control apagado pedido: ag808 pasa a «endurecimiento pendiente».
    $db->run('INSERT INTO tenant_hardening_settings (tenant_id,admin_name,shared_password_enc,hardening_mode) VALUES (?,?,?,?)', [$t, 'azcadmin', random_bytes(48), 'auto']);
    $principal = $db->one("SELECT id FROM principals WHERE tenant_id=? AND kind='system'", [$t])['id'];
    $db->run('INSERT INTO tenant_unlock_pin (tenant_id,pin_enc,salt,verifier,updated_by,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))', [$t, random_bytes(40), random_bytes(32), random_bytes(32), $principal]);
    $db->run('INSERT INTO tenant_windows_security (tenant_id,disable_smart_app_control,updated_by,updated_at) VALUES (?,TRUE,?,UTC_TIMESTAMP(6))', [$t, $principal]);
    $r = expect(adminRequest($platform, 'GET', $path), 200, 'protection with IT account');
    $rows = $byHost($r);
    check($rows['PC-juan']['protection'] === 'protected' && $rows['PC-juan']['reasons'] === [], 'juan Protegido');
    check($rows['PC-bl1']['protection'] === 'partial' && $rows['PC-bl1']['reason'] === 'USB falló (driver_blocked)' && $rows['PC-bl1']['controls']['usb']['state'] === 'failed', 'bl1 Parcial: USB falló');
    check($rows['PC-ag808']['controls']['hardening'] === ['state' => 'pending', 'detail' => 'waiting_panel'] && str_starts_with($rows['PC-ag808']['reason'], 'Endurecimiento pendiente (waiting_panel)'), 'ag808: hardening real state');
    check($r['client']['hardening_active'] && $r['client']['hardening_mode'] === 'auto' && $r['client']['unlock_pin_configured'] && $r['client']['disable_smart_app_control'], 'client settings configured');

    // Filtros: estado, busqueda sin tildes ni mayusculas, sede, paginas.
    $r = expect(adminRequest($platform, 'GET', "$path?protection=partial"), 200, 'filter partial');
    check(array_column($r['data'], 'hostname') === ['PC-ag808', 'PC-bl1'] && $r['totals']['devices'] === 8, 'protection filter keeps totals');
    check(array_column(expect(adminRequest($platform, 'GET', "$path?q=JUAN"), 200, 'search')['data'], 'hostname') === ['PC-juan'], 'search is case-insensitive');
    $r = expect(adminRequest($platform, 'GET', "$path?site_id=" . Util::id($o['med'])), 200, 'site filter');
    check(array_column($r['data'], 'hostname') === ['PC-ag808', 'PC-coord2', 'PC-jur808'] && $r['totals']['devices'] === 3, 'site filter');
    check(adminRequest($platform, 'GET', "$path?site_id=" . Util::id($o['agentes']))[0] === 422, 'site filter must be a site');
    check(adminRequest($platform, 'GET', "$path?protection=bad")[0] === 422 && adminRequest($platform, 'GET', "$path?foo=1")[0] === 422, 'unknown filter rejected');
    $seen = []; $cursor = null; $pages = 0;
    do {
        $r = expect(adminRequest($platform, 'GET', "$path?limit=3" . ($cursor ? '&cursor=' . rawurlencode($cursor) : '')), 200, 'page');
        $seen = array_merge($seen, array_column($r['data'], 'hostname')); $cursor = $r['next_cursor']; $pages++;
    } while ($cursor !== null && $pages < 10);
    $sorted = $seen; usort($sorted, fn ($a, $b) => strtolower($a) <=> strtolower($b));
    check($pages === 3 && count($seen) === 8 && count(array_unique($seen)) === 8 && $seen === $sorted, 'pages by hostname without repeats');
    check(adminRequest($platform, 'GET', "$path?limit=3&protection=partial&cursor=" . rawurlencode(expect(adminRequest($platform, 'GET', "$path?limit=3"), 200, 'p1')['next_cursor']))[0] === 404, 'cursor bound to its filters');

    // Alcance: el coordinador de Tequendama solo ve sus dos equipos; otra sede, 403; sin permiso, 403.
    accessResetPreloginQuota();
    $coord = accessAccount($db, $o, 'juan', ['equipos.ver'], new AccessScope('filters', [], [], [$o['teq']]));
    $r = expect(adminRequest($coord, 'GET', $path), 200, 'coordinator protection');
    check(array_column($r['data'], 'hostname') === ['PC-bl1', 'PC-juan'] && $r['totals']['devices'] === 2, 'coordinator sees only his site devices');
    check(array_column($r['client']['features'], 'people', 'code')['usb'] === 2 && array_column($r['client']['features'], 'devices_applied', 'code')['usb'] === 1, 'people and devices counted within scope');
    check(adminRequest($coord, 'GET', "$path?site_id=" . Util::id($o['med']))[0] === 403, 'site outside scope: 403');
    $noPerm = accessAccount($db, $o, 'zafra', ['reportes.ver'], AccessScope::all());
    check(adminRequest($noPerm, 'GET', $path)[0] === 403, 'without equipos.ver: 403');
    check(adminRequest($coord, 'GET', '/tenants/' . Util::id(secondTenant($db)['tenant_id']) . '/protection')[0] === 404, 'another client: 404');

    // Funciones no compradas: USB fuera del cliente. Plan sin controles: Sin proteger con el plan como arreglo.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='usb'", [$t]);
    $r = expect(adminRequest($platform, 'GET', $path), 200, 'usb not contracted');
    $rows = $byHost($r); $features = array_column($r['client']['features'], null, 'code');
    check($features['usb']['contracted'] === false && $features['usb']['people'] === 0 && $features['usb']['devices_applied'] === 0, 'usb not contracted: nobody reached');
    check($rows['PC-bl1']['controls']['usb'] === ['state' => 'not_in_plan', 'detail' => 'not_contracted'] && $rows['PC-bl1']['protection'] === 'protected', 'usb not contracted no longer counts against bl1');
    $basic = Util::bin(Util::uuid());
    $db->run("INSERT INTO person_plans (tenant_id,id,name,badge_label) VALUES (?,?,'Solo medición','M')", [$platformTenant, $basic]);
    $db->run("INSERT INTO person_plan_items (tenant_id,plan_id,feature_code) VALUES (?,?,'productividad')", [$platformTenant, $basic]);
    $db->run('UPDATE users SET plan_tenant_id=?,plan_id=? WHERE tenant_id=? AND id=?', [$platformTenant, $basic, $t, $o['juan']['id']]);
    $rows = $byHost(expect(adminRequest($platform, 'GET', $path), 200, 'measurement-only plan'));
    check($rows['PC-juan']['protection'] === 'unprotected' && $rows['PC-juan']['reasons'][0]['code'] === 'plan_without_controls' && $rows['PC-juan']['reasons'][0]['fix'] === 'plan' && $rows['PC-juan']['plan']['name'] === 'Solo medición', 'plan without controls: Sin proteger, fix in the person plan');
    $db->run("UPDATE person_plans SET active=FALSE WHERE tenant_id=? AND id=?", [$platformTenant, $basic]);
    echo 'PASS protection api (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
