<?php
declare(strict_types=1);

// Prueba local de punta a punta: DataSync de Keeper (backend-v4 del repo) contra serve.php por HTTP, sobre una BD de Keeper
// DESECHABLE ya migrada (nombre que empieza por keeper_e2e). Crea un cliente parecido al Grupo AZC de hoy (3 empresas,
// sedes y 3 personas manuales), una fuente REST con el fetch inyectado (restFetch exige https; aqui el transporte es
// http local, el resto del contrato es el mismo) y corre:
//   1) todo en simulacion (primera impresion), 2) etapa A en vivo + aprobar emparejamientos por nombre,
//   3) etapa B (personas + jerarquia) en simulacion, 4) incremental en simulacion.
// Imprime solo conteos y motivos agregados (sin nombres, cedulas ni correos).
//
// Uso: KEEPER_DB_DSN='mysql:host=127.0.0.1;port=13391;dbname=keeper_e2e;charset=utf8mb4' KEEPER_DB_USER=root
//      KEEPER_RESPONSE_KEY=<base64 de 32 bytes> php check-local.php --base=http://127.0.0.1:18391/serve.php/api/keeper/v1 --token=... [--link-document]

use Keeper\{Database, DataSync, Features, Placeholders, SyncFailure, Util};

$opts = getopt('', ['base:', 'token:', 'company:', 'link-document', 'backend:']);
$backend = $opts['backend'] ?? dirname(__DIR__, 2) . '/backend-v4';
require $backend . '/config/bootstrap.php';
if (!preg_match('/dbname=keeper_e2e[a-z0-9_]*;/', (string) getenv('KEEPER_DB_DSN') . ';')) { fwrite(STDERR, "KEEPER_DB_DSN debe apuntar a una BD desechable keeper_e2e*\n"); exit(1); }
$base = $opts['base'] ?? exit("falta --base\n");
$token = $opts['token'] ?? exit("falta --token\n");
$company = $opts['company'] ?? 'grupo-azc';

$db = new Database();
$http = function (string $endpoint, array $query) use ($base, $token): array {
    $url = rtrim($base, '/') . '/' . $endpoint . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token, 'User-Agent: AZCKeeper-sync/1']]);
    $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$status, $body === false ? null : json_decode((string) $body, true)];
};
// Mismo armado de URL y mismas reglas de estado que DataSync::restFetch.
$fetch = function (array $source, string $endpoint, ?string $since, ?string $cursor) use ($http, $company): array {
    $query = array_filter(['empresa' => $company, 'updated_since' => $since, 'cursor' => $cursor], static fn ($v) => $v !== null && $v !== '');
    [$status, $json] = $http($endpoint, $query);
    if ($status !== 200) { throw new SyncFailure('source_http_' . $status); }
    if (!is_array($json)) { throw new SyncFailure('source_invalid_json'); }
    return $json;
};

// ---- Cliente de prueba parecido al Grupo AZC actual -------------------------------------------------------------------
$tenant = Util::bin(Util::uuid());
$db->run("INSERT INTO tenants (tenant_id,name) VALUES (?,'Grupo AZC (e2e)')", [$tenant]);
$db->run('INSERT INTO retention_settings (tenant_id) VALUES (?)', [$tenant]);
$db->run("INSERT INTO principals (tenant_id,id,kind) VALUES (?,?,'system')", [$tenant, Util::bin(Util::uuid())]);
foreach (Features::codes() as $code) { $db->run('INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,?)', [$tenant, $code]); }
$db->run("UPDATE tenants SET default_plan_tenant_id=0x00000000000040008000000000000001, default_plan_id=(SELECT id FROM person_plans WHERE tenant_id=0x00000000000040008000000000000001 AND name='Completo') WHERE tenant_id=?", [$tenant]);
$unit = function (string $kind, string $name, ?string $country = null) use ($db, $tenant): string {
    $id = Util::bin(Util::uuid());
    $db->run('INSERT INTO org_units (tenant_id,id,kind,name,country_code) VALUES (?,?,?,?,?)', [$tenant, $id, $kind, $name, $country]);
    return $id;
};
foreach (['AZC Legal', 'BilingueLaw', 'LawyerDesk'] as $n) { $companies[$n] = $unit('company', $n); }
foreach (['Tequendama' => 'CO', 'Medellín' => 'CO', 'Tegucigalpa' => 'HN', 'CentroAmerica' => 'HN', 'República Dominicana' => 'DO'] as $n => $c) { $sites[$n] = $unit('site', $n, $c); }
$schedule = Util::bin(Util::uuid());
$db->run("INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,'L-V 8-5','America/Bogota','08:00:00','17:00:00')", [$tenant, $schedule]);
$firm = Placeholders::unit($db, $tenant, 'firm'); $area = Placeholders::unit($db, $tenant, 'area'); $position = Placeholders::unit($db, $tenant, 'position');
$locals = [];
foreach ([1, 2, 3] as $i) {
    $id = Util::bin(Util::uuid()); $locals[] = $id;
    $db->run('INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id,company_id) VALUES (?,?,?,?,?,?,?,?,?)', [$tenant, $id, "Persona manual $i", $firm, $sites['Tequendama'], $area, $position, $schedule, $companies['AZC Legal']]);
    $db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$tenant, Util::bin(Util::uuid()), $id, $firm, $sites['Tequendama'], $area, $position, $schedule, $companies['AZC Legal']]);
}
if (isset($opts['link-document'])) {
    // Simula "cargar la cedula de One en la persona manual": la primera activa de la instantanea (no se imprime).
    [, $page] = $http('people', ['empresa' => $company]);
    foreach ($page['items'] ?? [] as $p) { if ($p['status'] === 'active' && $p['document']) { $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document',?)", [$tenant, $locals[0], $p['document']]); break; } }
}

$source = Util::bin(Util::uuid());
$db->run("INSERT INTO data_sources (tenant_id,id,name,kind,config_ciphertext,interval_minutes,full_reconcile_local,mode) VALUES (?,?,'AZC One (instantanea)','rest',?,10,'02:00:00','simulate')",
    [$tenant, $source, DataSync::seal(['base_url' => $base, 'company' => $company, 'token' => $token])]);
$own = function (array $domains) use ($db, $tenant, $source): void {
    $db->run('DELETE FROM data_domain_owners WHERE tenant_id=?', [$tenant]);
    foreach ($domains as $d) { $db->run('INSERT INTO data_domain_owners (tenant_id,domain,source_id) VALUES (?,?,?)', [$tenant, $d, $source]); }
};
$sync = new DataSync($db, $fetch);
$report = function (string $title, string $runId) use ($db, $tenant): void {
    $r = $db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=?', [$tenant, $runId]);
    $reasons = [];
    foreach ($db->run('SELECT domain,action,COALESCE(reason,\'-\') reason,COUNT(*) n FROM sync_items WHERE tenant_id=? AND run_id=? GROUP BY domain,action,reason ORDER BY domain,action,reason', [$tenant, $runId])->fetchAll() as $x) {
        $reasons[$x['domain']][$x['action'] . ($x['reason'] === '-' ? '' : ':' . $x['reason'])] = (int) $x['n'];
    }
    echo json_encode(['run' => $title, 'kind' => $r['kind'], 'simulate' => (bool) $r['simulate'], 'status' => $r['status'], 'error_code' => $r['error_code'], 'blocked_reason' => $r['blocked_reason'],
        'items' => $reasons], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
};
$catalogs = ['empresas', 'firmas', 'sedes', 'areas', 'cargos', 'horarios'];

// 1) Todo en simulacion.
$own([...$catalogs, 'personas', 'jerarquia']);
$report('1 todo simulado', $db->transaction(fn () => $sync->run($tenant, $source, 'full', 'manual', false, true)));

// 2) Etapa A en vivo + aprobar los emparejamientos por nombre (empresas/sedes locales).
$own($catalogs);
$runA = $db->transaction(fn () => $sync->run($tenant, $source, 'full', 'manual', false, false));
$report('2 etapa A en vivo', $runA);
$approved = 0;
foreach ($db->run("SELECT * FROM sync_items WHERE tenant_id=? AND run_id=? AND action='pending' AND reason='matched_name'", [$tenant, $runA])->fetchAll() as $item) {
    $db->transaction(fn () => $sync->resolve($tenant, $item, true)); $approved++;
}
echo json_encode(['approved_matched_name' => $approved]), "\n";

// 3) Etapa B en simulacion.
$own([...$catalogs, 'personas', 'jerarquia']);
$report('3 etapa B simulada', $db->transaction(fn () => $sync->run($tenant, $source, 'full', 'manual', false, true)));

// 4) Incremental simulada (updated_since = generated_at de la etapa A).
$report('4 incremental simulada', $db->transaction(fn () => $sync->run($tenant, $source, 'incremental', 'manual', false, true)));

// Contrato HTTP del endpoint (codigos esperados).
$checks = [
    'sin token -> 401' => (function () use ($base) { $ch = curl_init(rtrim($base, '/') . '/people?empresa=x'); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_exec($ch); return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); })() === 401,
    'token malo -> 401' => (function () use ($base) { $ch = curl_init(rtrim($base, '/') . '/people?empresa=x'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . str_repeat('x', 40)]]); curl_exec($ch); return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); })() === 401,
    'POST -> 405' => (function () use ($base, $token) { $ch = curl_init(rtrim($base, '/') . '/people'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]); curl_exec($ch); return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); })() === 405,
    'holidays -> 404' => $http('holidays', ['empresa' => $company])[0] === 404,
    'absences -> 404' => $http('absences', ['empresa' => $company])[0] === 404,
    'sin empresa -> 400' => $http('people', [])[0] === 400,
    'empresa ajena -> 404' => $http('people', ['empresa' => 'otra'])[0] === 404,
    'cursor invalido -> 400' => $http('people', ['empresa' => $company, 'cursor' => 'abc'])[0] === 400,
    'parametro extra -> 400' => $http('people', ['empresa' => $company, 'x' => '1'])[0] === 400,
    'ruta desconocida -> 404' => $http('manifest.json', ['empresa' => $company])[0] === 404,
    'incremental -> complete:false' => ($http('people', ['empresa' => $company, 'updated_since' => '2099-01-01T00:00:00Z'])[1]['complete'] ?? null) === false,
    'health -> 200' => $http('health', [])[0] === 200,
];
echo json_encode(['http_contract' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
exit(in_array(false, $checks, true) ? 1 : 0);
