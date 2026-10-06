<?php
declare(strict_types=1);
use Keeper\{Database, Util};

// Carga unica de la instantanea de One (tools/import-one-snapshot.php) y su borrado (tools/wipe-import.php), por la
// linea de comandos y con una instantanea sintetica (sin datos reales). Esquema posterior a 0046 (placeholders marcados).
function oneImportTests(Database $db): void
{
    require_once dirname(__DIR__) . '/tools/OneImport.php';
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $tid = Util::id($tenant);
    $tname = $db->one('SELECT name FROM tenants WHERE tenant_id=?', [$tenant])['name'];
    $tools = dirname(__DIR__) . '/tools';
    $cli = function (string $script, array $args) use ($tools): array {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tools/$script") . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' --json 2>&1', $out, $code);
        return [$code, json_decode((string) end($out), true), implode("\n", $out)];
    };
    $n = fn (string $sql, array $a = []) => (int) $db->one($sql, $a)['n'];
    $users = fn () => $n('SELECT COUNT(*) n FROM users WHERE tenant_id=?', [$tenant]);
    $id = fn (string $sql, array $a) => $db->one($sql, $a)['id'] ?? null;

    // Keeper antes: empresa y sede manuales (se emparejan por nombre exacto; One trae otro pais y ciudad), una sede de
    // nombre parecido (no se empareja) y la persona manual con la cedula de One, con rol propio y cuenta de panel.
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'company','Empresa A')", [$tenant, $companyA = Util::bin(Util::uuid())]);
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name,country_code,city) VALUES (?,?,'site','Sede Uno','HN','Tegucigalpa')", [$tenant, $siteUno = Util::bin(Util::uuid())]);
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'site','SEDE NUEVA ')", [$tenant, Util::bin(Util::uuid())]);
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document','7770001')", [$tenant, $u['id']]);
    $db->run('UPDATE users SET panel_login_enabled=TRUE WHERE tenant_id=? AND id=?', [$tenant, $u['id']]);
    $db->run('INSERT INTO admin_accounts (tenant_id,id,user_id,email,password_hash) VALUES (?,?,?,?,?)', [$tenant, Util::bin(Util::uuid()), $u['id'], 'manual-' . bin2hex(random_bytes(4)) . '@example.test', 'x']);
    $snapshotOf = fn () => [
        'user' => $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $u['id']]),
        'grant' => $db->one('SELECT * FROM access_grants WHERE tenant_id=? AND user_id=?', [$tenant, $u['id']]),
        'account' => $db->one('SELECT * FROM admin_accounts WHERE tenant_id=? AND user_id=?', [$tenant, $u['id']]),
        'assignments' => $db->run('SELECT * FROM user_assignments WHERE tenant_id=? AND user_id=? ORDER BY starts_at', [$tenant, $u['id']])->fetchAll(),
        'refs' => $db->run('SELECT * FROM user_external_refs WHERE tenant_id=? AND user_id=? ORDER BY source', [$tenant, $u['id']])->fetchAll(),
        'subs' => $db->run('SELECT * FROM subscriptions WHERE tenant_id=? AND user_id=?', [$tenant, $u['id']])->fetchAll(),
        'site' => $db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$tenant, $siteUno]),
        'company' => $db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$tenant, $companyA]),
    ];
    $manual = $snapshotOf();

    $dir = sys_get_temp_dir() . '/keeper-one-import-' . bin2hex(random_bytes(6));
    $person = fn (string $id, string $doc, string $status, array $refs = []) => $refs + ['external_id' => $id, 'document' => $doc, 'display_name' => "Persona $id", 'email' => "$id@example.test",
        'company_external_id' => 'c-b', 'firm_external_id' => null, 'site_external_id' => 's-nueva', 'area_external_id' => null, 'position_external_id' => null,
        'schedule_external_id' => 'j1', 'reports_to_external_id' => null, 'status' => $status];
    $snapshot = [
        'org-units' => [
            ['external_id' => 'c-a', 'kind' => 'company', 'name' => 'Empresa A', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'c-b', 'kind' => 'company', 'name' => 'Empresa B', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'f1', 'kind' => 'firm', 'name' => 'Firma Uno', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 's-uno', 'kind' => 'site', 'name' => 'Sede Uno', 'parent_external_id' => null, 'active' => true, 'country_code' => 'CO', 'city' => 'Cali'],
            ['external_id' => 's-nueva', 'kind' => 'site', 'name' => 'Sede Nueva', 'parent_external_id' => null, 'active' => true, 'country_code' => 'CO', 'city' => 'Cali'],
            ['external_id' => 'a2', 'kind' => 'area', 'name' => 'Área Hija', 'parent_external_id' => 'a1', 'active' => true],
            ['external_id' => 'a1', 'kind' => 'area', 'name' => 'Área Padre', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'p1', 'kind' => 'position', 'name' => 'Cargo Uno', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'p2', 'kind' => 'position', 'name' => 'Cargo Dos', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'p-old', 'kind' => 'position', 'name' => 'Cargo Viejo', 'parent_external_id' => null, 'active' => false]],
        'schedules' => [['external_id' => 'j1', 'name' => 'L-V 08:00-17:00 (prueba)', 'timezone' => 'America/Bogota', 'active' => true,
            'days' => array_map(fn ($w) => ['weekday' => $w, 'start_local' => '08:00', 'end_local' => '17:00', 'lunch' => ['start_local' => '12:00', 'end_local' => '13:00']], [1, 2, 3, 4, 5])]],
        'people' => [
            $person('e1', '777.000-1', 'active', ['company_external_id' => 'c-a', 'site_external_id' => 's-uno', 'firm_external_id' => 'f1', 'area_external_id' => 'a2', 'position_external_id' => 'p1', 'reports_to_external_id' => 'e2']),
            $person('e2', '7770002', 'active', ['reports_to_external_id' => 'e1']),
            $person('e3', '7770003', 'inactive', ['area_external_id' => 'a1']),
            $person('e4', '7770004', 'active', ['schedule_external_id' => null, 'reports_to_external_id' => 'e9']),
            $person('e5', '7770005', 'active', ['area_external_id' => 'a-no-existe']),
            $person('e6', '7770006', 'active', ['position_external_id' => 'p2']),
            $person('e7', '7770002', 'active'),
            $person('e8', '7770008', 'active', ['reports_to_external_id' => 'e4', 'area_external_id' => 'a2']),
            $person('e9', '7770009', 'active')],
    ];
    $write = function (array $snap, string $company) use ($dir): void {
        foreach (['org-units', 'schedules', 'people'] as $e) { @mkdir("$dir/$e", 0700, true); file_put_contents("$dir/$e/0001.json", json_encode(['items' => $snap[$e]])); }
        file_put_contents("$dir/manifest.json", json_encode(['schema' => 'keeper.roster.v1', 'company' => $company, 'generated_at' => '2026-10-06T11:27:29Z',
            'endpoints' => array_map(fn ($items) => ['pages' => 1, 'items' => count($items)], $snap)]));
    };
    $slug = Keeper\Tools\OneImport::slug($tname);
    $base = ['--tenant', $tid, '--confirm-tenant', $tname, '--dir', $dir];
    try {
        // 0) Guardas del cliente: nombre equivocado, otro cliente o instantanea de otra empresa.
        $write($snapshot, 'otra-empresa');
        $count = $users(); $units = $n('SELECT COUNT(*) n FROM org_units WHERE tenant_id=?', [$tenant]);
        [$code, , $raw] = $cli('import-one-snapshot.php', $base);
        check($code === 1 && str_contains($raw, 'manifest_company_mismatch'), 'snapshot of another company refused');
        $write($snapshot, $slug);
        [$code, , $raw] = $cli('import-one-snapshot.php', ['--tenant', $tid, '--confirm-tenant', 'Grupo AZC', '--dir', $dir, '--apply']);
        check($code === 1 && str_contains($raw, 'tenant_name_mismatch') && $users() === $count, 'wrong tenant name refused');
        [$code, , $raw] = $cli('import-one-snapshot.php', ['--tenant', $tid, '--dir', $dir]);
        check($code === 2, 'confirm-tenant required');

        // 1) Dry-run: conteos exactos (incluye lo que One trae distinto en lo emparejado) y nada escrito.
        [$code, $dry, $raw] = $cli('import-one-snapshot.php', $base);
        check($code === 0 && $dry['mode'] === 'DRY-RUN' && $dry['batch'] === null && $dry['tenant'] === $tname, 'one import dry-run runs ' . $raw);
        $c = $dry['counts'];
        check(($c['empresas']['create'] ?? 0) === 1 && ($c['empresas']['match'] ?? 0) === 1 && ($c['empresas']['codes']['name'] ?? 0) === 1, 'companies matched by exact name');
        check(($c['sedes']['match'] ?? 0) === 1 && ($c['sedes']['create'] ?? 0) === 1 && !isset($c['sedes']['update']) && ($c['sedes']['codes']['similar_name_exists'] ?? 0) === 1
            && ($c['sedes']['codes']['differs_country_code'] ?? 0) === 1 && ($c['sedes']['codes']['differs_city'] ?? 0) === 1, 'matched site only reports differences ' . json_encode($c['sedes']));
        check(($c['areas']['create'] ?? 0) === 2 && ($c['cargos']['create'] ?? 0) === 2 && ($c['cargos']['skip'] ?? 0) === 1 && ($c['horarios']['create'] ?? 0) === 1, 'units and schedule planned, inactive unit skipped');
        $p = $c['personas'];
        check(($p['create'] ?? 0) === 6 && ($p['match'] ?? 0) === 1 && !isset($p['update']) && ($p['codes']['document'] ?? 0) === 1 && ($p['codes']['inactive_created'] ?? 0) === 1
            && ($p['codes']['unknown_reference'] ?? 0) === 1 && ($p['codes']['document_duplicate_in_snapshot'] ?? 0) === 1 && ($p['error'] ?? 0) === 2
            && ($p['codes']['differs_area_id'] ?? 0) === 1 && ($p['codes']['differs_site_id'] ?? 0) === 1, 'people counts ' . json_encode($p));
        check(($c['jerarquia']['update'] ?? 0) === 3 && ($c['jerarquia']['codes']['matched_kept_differs'] ?? 0) === 1, 'hierarchy only for created people ' . json_encode($c['jerarquia']));
        check($users() === $count && $n('SELECT COUNT(*) n FROM org_units WHERE tenant_id=?', [$tenant]) === $units, 'dry-run writes nothing');
        check(!str_contains($raw, 'Persona e') && !str_contains($raw, '7770002') && !str_contains($raw, 'example.test'), 'output without personal data');

        // 2) Con errores, --apply se niega; con --allow-errors carga en una transaccion.
        [$code] = $cli('import-one-snapshot.php', [...$base, '--apply']);
        check($code === 1 && $users() === $count, 'apply refused with item errors');
        [$code, $res] = $cli('import-one-snapshot.php', [...$base, '--apply', '--allow-errors']);
        check($code === 0 && $res['mode'] === 'APLICADO' && is_string($res['batch']), 'apply with allowed errors');
        $batch = $res['batch'];
        check($users() === $count + 6, 'six people created (duplicate document failed alone)');
        $by = fn (string $doc) => $db->one("SELECT u.* FROM users u JOIN user_external_refs r ON r.tenant_id=u.tenant_id AND r.user_id=u.id WHERE u.tenant_id=? AND r.external_ref=?", [$tenant, $doc]);
        $e2 = $by('7770002'); $e3 = $by('7770003'); $e4 = $by('7770004'); $e6 = $by('7770006'); $e8 = $by('7770008'); $e9 = $by('7770009');
        check($snapshotOf() == $manual, 'matched person, account, grant, assignments and matched units untouched by import');
        $unit = fn (?string $i) => $i === null ? null : $db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$tenant, $i]);
        check($e3['status'] === 'inactive' && $unit($e3['area_id'])['name'] === 'Área Padre', 'inactive person created as inactive');
        foreach (['firm_id' => 'Sin firma', 'area_id' => 'Sin área', 'position_id' => 'Sin cargo'] as $col => $name) { $ph = $unit($e2[$col]); check($ph['name'] === $name && (bool) $ph['placeholder'], "placeholder $name flagged"); }
        $sched = $db->one('SELECT * FROM schedules WHERE tenant_id=? AND id=?', [$tenant, $e4['schedule_id']]);
        check($sched['name'] === 'Sin horario' && (bool) $sched['placeholder'], 'no schedule -> Sin horario');
        check($e2['reports_to_user_id'] === $u['id'] && $e4['reports_to_user_id'] === $e9['id'] && $e8['reports_to_user_id'] === $e4['id'], 'created people report to matched or created bosses');
        check($n('SELECT COUNT(*) n FROM entity_links WHERE tenant_id=?', [$tenant]) === 0 && $n('SELECT COUNT(*) n FROM data_domain_owners WHERE tenant_id=?', [$tenant]) === 0, 'no data source links or domain owners');
        $createdItems = $n("SELECT COUNT(*) n FROM import_batch_items WHERE tenant_id=? AND batch_id=? AND action='created'", [$tenant, Util::bin($batch)]);
        check($n("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='import.one_snapshot.create'", [$tenant]) === $createdItems && $createdItems > 6
            && $n("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='import.one_snapshot.applied' AND resource_id=?", [$tenant, Util::bin($batch)]) === 1, 'one audit row per created entity plus the batch row');
        check(!$db->one("SELECT id FROM audit_log WHERE tenant_id=? AND action LIKE 'import.one_snapshot.%' AND (JSON_SEARCH(changed_fields,'one','%7770%') IS NOT NULL OR reason LIKE '%7770%')", [$tenant]), 'audit without personal values');

        // 3) Repetir: no duplica ni crea lote.
        [$code, $again] = $cli('import-one-snapshot.php', [...$base, '--apply', '--allow-errors']);
        check($code === 0 && $again['changed'] === false && $again['batch'] === null && $users() === $count + 6 && ($again['counts']['personas']['codes']['previous_import'] ?? 0) === 7, 'second apply is a no-op ' . json_encode($again['counts']['personas'] ?? null));

        // 4) Ediciones del dueño despues de la carga.
        $db->run('UPDATE users SET site_id=?,version=version+1 WHERE tenant_id=? AND id=?', [$siteUno, $tenant, $e2['id']]);                    // persona importada movida
        $db->run("INSERT INTO org_units (tenant_id,id,kind,name,parent_id) VALUES (?,?,'area','Área del dueño',?)", [$tenant, $ownerArea = Util::bin(Util::uuid()), $unit($e3['area_id'])['id']]);  // hija bajo un area importada
        $device = Util::bin(Util::uuid());
        $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'pending','0.0.0','unknown','unknown',0,'[]')", [$tenant, $device, $e4['id']]);  // equipo
        $db->run('INSERT INTO admin_accounts (tenant_id,id,user_id,email,password_hash) VALUES (?,?,?,?,?)', [$tenant, Util::bin(Util::uuid()), $e6['id'], 'e6-' . bin2hex(random_bytes(4)) . '@example.test', 'x']);  // cuenta de panel
        $cargo2 = $e6['position_id'];
        $sched1 = $e6['schedule_id'];
        $manual2 = Util::bin(Util::uuid());   // persona manual metida en una unidad importada (Cargo Uno)
        $cargo1 = $id("SELECT id FROM org_units WHERE tenant_id=? AND name='Cargo Uno'", [$tenant]);
        $db->run('INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id) VALUES (?,?,\'Manual dos\',?,?,?,?,?)', [$tenant, $manual2, $u['firm_id'], $u['site_id'], $u['area_id'], $cargo1, $u['schedule_id']]);
        $manual2Row = $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $manual2]);

        // 5) Borrado: guardas, dry-run y luego solo lo creado y sin tocar.
        [$code, , $raw] = $cli('wipe-import.php', ['--tenant', $tid, '--confirm-tenant', 'Otro', '--batch', $batch, '--force']);
        check($code === 1 && str_contains($raw, 'tenant_name_mismatch'), 'wipe refuses wrong tenant name');
        [$code, , $raw] = $cli('wipe-import.php', ['--tenant', Util::id($db->one("SELECT tenant_id FROM tenants WHERE tenant_id<>? AND deleted_at IS NULL AND name=? LIMIT 1", [$tenant, $tname])['tenant_id'] ?? Util::bin(Util::uuid())), '--confirm-tenant', $tname, '--batch', $batch, '--force']);
        check($code === 1, 'wipe refuses a batch of another tenant');
        $wbase = ['--tenant', $tid, '--confirm-tenant', $tname, '--batch', $batch];
        $after = $users();
        [$code, $w, $raw] = $cli('wipe-import.php', $wbase);
        check($code === 0 && $w['mode'] === 'DRY-RUN' && $users() === $after && ($w['batch']['items']['user']['created'] ?? 0) === 6, 'wipe dry-run shows batch and writes nothing ' . $raw);
        $wc = $w['counts']['personas'];
        // e3, e8 se borran; e2 editada, e4 con equipo, e6 con cuenta, e9 jefe de e4 (que se queda).
        check(($wc['delete'] ?? 0) === 2 && ($wc['codes']['owner_edited'] ?? 0) === 1 && ($wc['codes']['has_devices'] ?? 0) === 1 && ($wc['codes']['has_panel_account'] ?? 0) === 1
            && ($wc['codes']['boss_of_kept_person'] ?? 0) === 1, 'wipe person decisions ' . json_encode($wc));
        [$code, $w] = $cli('wipe-import.php', [...$wbase, '--force']);
        check($code === 0 && $w['mode'] === 'APLICADO' && $users() === $after - 2 && $w['left'] > 0, 'wipe applied');
        check(!$by('7770003') && !$by('7770008') && $by('7770002') && $by('7770004') && $by('7770006') && $by('7770009'), 'only untouched created people removed');
        check($db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $e2['id']])['site_id'] === $siteUno && $db->one('SELECT status FROM users WHERE tenant_id=? AND id=?', [$tenant, $e4['id']])['status'] === 'active'
            && $db->one('SELECT status FROM devices WHERE tenant_id=? AND id=?', [$tenant, $device])['status'] !== 'revoked', 'owner edits and devices kept, nobody retired');
        check($db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $manual2]) == $manual2Row && $unit($cargo1) !== null && $unit($cargo1)['deleted_at'] === null, 'manual person in an imported unit untouched, unit kept');
        check($unit($ownerArea)['deleted_at'] === null && $unit($e3['area_id'])['deleted_at'] === null, 'imported parent of an owner unit kept');
        check($unit($cargo2) !== null && $db->one('SELECT id FROM schedules WHERE tenant_id=? AND id=?', [$tenant, $sched1]) !== null, 'units used by kept people kept');
        check(!$db->one("SELECT id FROM org_units WHERE tenant_id=? AND name='Área Hija'", [$tenant]), 'unused imported unit deleted');
        check($snapshotOf() == $manual, 'matched person and units untouched by wipe');
        check($n("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='import.one_snapshot.delete' AND resource_type='user'", [$tenant]) === 2
            && $n("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='import.one_snapshot.wiped'", [$tenant]) === 1
            && $n("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='import.one_snapshot.create'", [$tenant]) === $createdItems, 'wipe audited per entity, import audit kept');
        $status = $db->one('SELECT status FROM import_batches WHERE tenant_id=? AND id=?', [$tenant, Util::bin($batch)])['status'];
        check($status === 'partially_wiped', 'batch partially wiped');
        // El dueño deshace sus cambios: un segundo borrado quita lo que ya esta como quedo.
        $db->run('DELETE FROM devices WHERE tenant_id=? AND id=?', [$tenant, $device]);
        [$code, $w] = $cli('wipe-import.php', [...$wbase, '--force']);
        check($code === 0 && !$by('7770004') && !$by('7770009') && $by('7770002') && $by('7770006'), 'second wipe removes what became untouched ' . json_encode($w['counts']['personas'] ?? null));
        [$code] = $cli('wipe-import.php', ['--tenant', $tid, '--confirm-tenant', $tname, '--batch', Util::uuid()]);
        check($code === 1, 'unknown batch rejected');
    } finally {
        foreach (['org-units', 'schedules', 'people'] as $e) { @unlink("$dir/$e/0001.json"); @rmdir("$dir/$e"); }
        @unlink("$dir/manifest.json"); @rmdir($dir);
    }
    echo 'PASS one snapshot import (' . ($GLOBALS['checks'] - $before) . " checks)\n";
}
