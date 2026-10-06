<?php
declare(strict_types=1);
use Keeper\{Database, Util};

// Carga unica de la instantanea de One (tools/import-one-snapshot.php) y su borrado (tools/wipe-import.php), por la
// linea de comandos y con una instantanea sintetica (sin datos reales). Esquema posterior a 0046 (placeholders marcados).
function oneImportTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $tid = Util::id($tenant);
    $tools = dirname(__DIR__) . '/tools';
    $cli = function (string $script, array $args) use ($tools): array {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tools/$script") . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' --json 2>&1', $out, $code);
        return [$code, json_decode((string) end($out), true), implode("\n", $out)];
    };
    $n = fn (string $sql, array $a = []) => (int) $db->one($sql, $a)['n'];
    $users = fn () => $n('SELECT COUNT(*) n FROM users WHERE tenant_id=?', [$tenant]);

    // Keeper antes: empresa y sede manuales (se emparejan por nombre exacto), una sede de nombre parecido (no se empareja)
    // y la persona manual con la cedula de One, con su rol y su cuenta de panel.
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'company','Empresa A')", [$tenant, $companyA = Util::bin(Util::uuid())]);
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'site','Sede Uno')", [$tenant, $siteUno = Util::bin(Util::uuid())]);
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'site','SEDE NUEVA ')", [$tenant, Util::bin(Util::uuid())]);
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document','7770001')", [$tenant, $u['id']]);
    $db->run("UPDATE users SET panel_login_enabled=TRUE WHERE tenant_id=? AND id=?", [$tenant, $u['id']]);
    $grant = fn () => $db->one('SELECT role_id,scope_kind,source,version FROM access_grants WHERE tenant_id=? AND user_id=?', [$tenant, $u['id']]);
    $grantBefore = $grant();

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
            ['external_id' => 'p-old', 'kind' => 'position', 'name' => 'Cargo Viejo', 'parent_external_id' => null, 'active' => false]],
        'schedules' => [['external_id' => 'j1', 'name' => 'L-V 08:00-17:00 (prueba)', 'timezone' => 'America/Bogota', 'active' => true,
            'days' => array_map(fn ($w) => ['weekday' => $w, 'start_local' => '08:00', 'end_local' => '17:00', 'lunch' => ['start_local' => '12:00', 'end_local' => '13:00']], [1, 2, 3, 4, 5])]],
        'people' => [
            $person('e1', '777.000-1', 'active', ['company_external_id' => 'c-a', 'site_external_id' => 's-uno', 'firm_external_id' => 'f1', 'area_external_id' => 'a2', 'position_external_id' => 'p1', 'reports_to_external_id' => 'e2']),
            $person('e2', '7770002', 'active'),
            $person('e3', '7770003', 'inactive', ['area_external_id' => 'a1']),
            $person('e4', '7770004', 'active', ['schedule_external_id' => null, 'reports_to_external_id' => 'e2']),
            $person('e5', '7770005', 'active', ['area_external_id' => 'a-no-existe'])],
    ];
    $write = function (array $snap) use ($dir): void {
        foreach (['org-units', 'schedules', 'people'] as $e) { @mkdir("$dir/$e", 0700, true); file_put_contents("$dir/$e/0001.json", json_encode(['items' => $snap[$e]])); }
        file_put_contents("$dir/manifest.json", json_encode(['schema' => 'keeper.roster.v1', 'company' => 'grupo-azc', 'generated_at' => '2026-10-06T11:27:29Z',
            'endpoints' => array_map(fn ($items) => ['pages' => 1, 'items' => count($items)], $snap)]));
    };
    $write($snapshot);
    try {
        // 1) Dry-run: conteos exactos y nada escrito.
        $count = $users(); $units = $n('SELECT COUNT(*) n FROM org_units WHERE tenant_id=?', [$tenant]);
        [$code, $dry, $raw] = $cli('import-one-snapshot.php', ['--tenant', $tid, '--dir', $dir]);
        check($code === 0 && $dry['mode'] === 'DRY-RUN' && $dry['batch'] === null, 'one import dry-run runs ' . $raw);
        $c = $dry['counts'];
        check(($c['empresas']['create'] ?? 0) === 1 && ($c['empresas']['match'] ?? 0) === 1 && ($c['empresas']['codes']['name'] ?? 0) === 1, 'companies matched by exact name');
        check(($c['sedes']['update'] ?? 0) === 1 && ($c['sedes']['create'] ?? 0) === 1 && ($c['sedes']['codes']['similar_name_exists'] ?? 0) === 1, 'site matched (country filled) and similar name not matched');
        check(($c['areas']['create'] ?? 0) === 2 && ($c['cargos']['create'] ?? 0) === 1 && ($c['cargos']['skip'] ?? 0) === 1 && ($c['horarios']['create'] ?? 0) === 1, 'units and schedule planned, inactive unit skipped');
        check(($c['personas']['create'] ?? 0) === 3 && ($c['personas']['update'] ?? 0) === 1 && ($c['personas']['codes']['match_document'] ?? 0) === 1
            && ($c['personas']['codes']['inactive_created'] ?? 0) === 1 && ($c['personas']['error'] ?? 0) === 1 && ($c['personas']['codes']['unknown_reference'] ?? 0) === 1, 'people counts ' . json_encode($c['personas']));
        check(($c['jerarquia']['update'] ?? 0) === 2, 'hierarchy planned');
        check($users() === $count && $n('SELECT COUNT(*) n FROM org_units WHERE tenant_id=?', [$tenant]) === $units, 'dry-run writes nothing');
        check(!str_contains($raw, 'Persona e') && !str_contains($raw, '7770002') && !str_contains($raw, 'example.test'), 'output without personal data');

        // 2) Con errores, --apply se niega; con --allow-errors carga en una transaccion.
        [$code] = $cli('import-one-snapshot.php', ['--tenant', $tid, '--dir', $dir, '--apply']);
        check($code === 1 && $users() === $count, 'apply refused with item errors');
        [$code, $res] = $cli('import-one-snapshot.php', ['--tenant', $tid, '--dir', $dir, '--apply', '--allow-errors']);
        check($code === 0 && $res['mode'] === 'APLICADO' && is_string($res['batch']), 'apply with allowed errors');
        $batch = $res['batch'];
        check($users() === $count + 3, 'three people created');
        $by = fn (string $doc) => $db->one("SELECT u.* FROM users u JOIN user_external_refs r ON r.tenant_id=u.tenant_id AND r.user_id=u.id WHERE u.tenant_id=? AND r.external_ref=?", [$tenant, $doc]);
        $e1 = $by('7770001'); $e2 = $by('7770002'); $e3 = $by('7770003'); $e4 = $by('7770004');
        check($e1['id'] === $u['id'] && $e1['display_name'] === $u['display_name'] && $e1['status'] === 'active' && (bool) $e1['panel_login_enabled'], 'matched person not duplicated, name and status kept');
        check($grant() == $grantBefore, 'matched person access grant untouched');
        $unit = fn (?string $id) => $id === null ? null : $db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$tenant, $id]);
        check($unit($e1['area_id'])['name'] === 'Área Hija' && $unit($unit($e1['area_id'])['parent_id'])['name'] === 'Área Padre' && $e1['site_id'] === $siteUno && $e1['company_id'] === $companyA && $e1['reports_to_user_id'] === $e2['id'], 'matched person placed and reports to');
        check($n('SELECT COUNT(*) n FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL AND area_id=?', [$tenant, $u['id'], $e1['area_id']]) === 1, 'matched person assignment reopened');
        check($e3['status'] === 'inactive' && $unit($e3['area_id'])['name'] === 'Área Padre', 'inactive person created as inactive');
        foreach (['firm_id' => 'Sin firma', 'area_id' => 'Sin área', 'position_id' => 'Sin cargo'] as $col => $name) {
            $p = $unit($e2[$col]); check($p['name'] === $name && (bool) $p['placeholder'], "placeholder $name flagged");
        }
        $sched = $db->one('SELECT * FROM schedules WHERE tenant_id=? AND id=?', [$tenant, $e4['schedule_id']]);
        check($sched['name'] === 'Sin horario' && (bool) $sched['placeholder'] && $e4['reports_to_user_id'] === $e2['id'], 'no schedule -> Sin horario');
        check($db->one('SELECT start_local,lunch_start_local FROM schedules WHERE tenant_id=? AND id=?', [$tenant, $e2['schedule_id']])['lunch_start_local'] === '12:00:00', 'schedule created with lunch');
        check($unit($siteUno)['country_code'] === 'CO' && $unit($siteUno)['city'] === 'Cali', 'matched site country and city filled');
        check($n('SELECT COUNT(*) n FROM entity_links WHERE tenant_id=?', [$tenant]) === 0 && $n('SELECT COUNT(*) n FROM data_domain_owners WHERE tenant_id=?', [$tenant]) === 0, 'no data source links or domain owners');
        check($n("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='import.one_snapshot.applied' AND resource_id=?", [$tenant, Util::bin($batch)]) === 1, 'import audited');
        check($n("SELECT COUNT(*) n FROM import_batch_items WHERE tenant_id=? AND batch_id=? AND action='created' AND entity_type='user'", [$tenant, Util::bin($batch)]) === 3, 'manifest records created people');
        check($n('SELECT COUNT(*) n FROM subscriptions WHERE tenant_id=? AND user_id=?', [$tenant, $e3['id']]) === 0 && $n('SELECT COUNT(*) n FROM access_grants WHERE tenant_id=? AND user_id=?', [$tenant, $e2['id']]) <= 1, 'retired person without plan');

        // 3) Editable en el panel: crear una persona por el panel no choca con ningun dominio; renombrar no duplica al repetir.
        $db->run("UPDATE org_units SET name='Área Hija (editada)' WHERE tenant_id=? AND id=?", [$tenant, $e1['area_id']]);
        $snapshot['people'][4]['area_external_id'] = 'a1';  // corrige el error
        $write($snapshot);
        [$code, $again] = $cli('import-one-snapshot.php', ['--tenant', $tid, '--dir', $dir, '--apply']);
        check($code === 0 && $again['mode'] === 'APLICADO' && ($again['counts']['personas']['create'] ?? 0) === 1 && ($again['counts']['areas']['create'] ?? 0) === 0
            && ($again['counts']['areas']['codes']['previous_import'] ?? 0) === 2 && ($again['counts']['personas']['codes']['previous_import'] ?? 0) === 3 && ($again['counts']['personas']['codes']['previous_import_inactive_in_source_kept'] ?? 0) === 1, 'second run only adds the fixed person ' . json_encode($again['counts']));
        $batch2 = $again['batch'];
        [$code, $third] = $cli('import-one-snapshot.php', ['--tenant', $tid, '--dir', $dir, '--apply']);
        check($code === 0 && $third['changed'] === false && $third['batch'] === null && $users() === $count + 4, 'third run is a no-op');

        // 4) Borrado: dry-run, luego solo lo creado. Lo emparejado queda; lo que usa la persona manual se salta.
        $after = $users();
        [$code, $w] = $cli('wipe-import.php', ['--batch', $batch]);
        check($code === 0 && $w['mode'] === 'DRY-RUN' && ($w['counts']['personas']['delete'] ?? 0) === 2 && ($w['counts']['personas']['codes']['boss_of_existing_person'] ?? 0) === 1 && $users() === $after, 'wipe dry-run counts, writes nothing ' . json_encode($w['counts']));
        [$code, $w] = $cli('wipe-import.php', ['--batch', $batch, '--apply']);
        check($code === 0 && $w['mode'] === 'APLICADO' && $users() === $after - 2 && $w['left'] > 0, 'wipe applied: created people removed, boss of a kept person skipped');
        check((bool) $db->one('SELECT id FROM users WHERE tenant_id=? AND id=?', [$tenant, $u['id']]) && $db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND deleted_at IS NULL', [$tenant, $companyA])
            && $db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND deleted_at IS NULL', [$tenant, $siteUno]), 'matched records kept');
        check($db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND deleted_at IS NULL', [$tenant, $e1['area_id']]) !== null, 'unit used by a kept person is skipped');
        check($db->one("SELECT id FROM org_units WHERE tenant_id=? AND name='Empresa B' AND deleted_at IS NULL", [$tenant]) !== null, 'company used by second-batch person kept');
        check($by('7770005') !== null, 'second batch untouched by first wipe');
        check($n("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='import.one_snapshot.wiped'", [$tenant]) === 1 && $n("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='import.one_snapshot.applied'", [$tenant]) === 2, 'audit kept and wipe audited');
        // Segundo lote y luego --force-unassign: la persona manual vuelve a su ubicacion anterior y todo lo creado se va.
        [$code] = $cli('wipe-import.php', ['--batch', $batch2, '--apply']);
        check($code === 0, 'second batch wiped');
        [$code, $w] = $cli('wipe-import.php', ['--batch', $batch, '--apply', '--force-unassign']);
        $me = $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $u['id']]);
        check($code === 0 && $me['area_id'] === $u['area_id'] && $me['firm_id'] === $u['firm_id'] && $me['position_id'] === $u['position_id'] && $me['site_id'] === $siteUno && $me['reports_to_user_id'] === null, 'force-unassign restores the matched person ' . json_encode($w['counts']));
        check($users() === $count && !$db->one("SELECT id FROM org_units WHERE tenant_id=? AND name IN ('Área Padre','Firma Uno','Cargo Uno','Sede Nueva','Empresa B') AND deleted_at IS NULL", [$tenant]), 'everything created is gone');
        check($db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND deleted_at IS NULL', [$tenant, $u['area_id']]) !== null && $grant() == $grantBefore, 'pre-existing units and grant intact');
        [$code, $w] = $cli('wipe-import.php', ['--batch', Util::uuid()]);
        check($code === 1, 'unknown batch rejected');
    } finally {
        foreach (['org-units', 'schedules', 'people'] as $e) { @unlink("$dir/$e/0001.json"); @rmdir("$dir/$e"); }
        @unlink("$dir/manifest.json"); @rmdir($dir);
    }
    echo 'PASS one snapshot import (' . ($GLOBALS['checks'] - $before) . " checks)\n";
}
