<?php
declare(strict_types=1);
// Carga inicial de la estructura de un grupo: empresas del grupo (si faltan) y calendarios de festivos por pais.
// Uso: php tools/seed-group.php <tenant-uuid> --companies="AZC Legal,BilingueLaw" --countries=CO,US,HN --years=2026,2027
// Es repetible: no duplica empresas (por nombre) ni festivos (por calendario y dia). Luego todo se edita en el panel.
use Keeper\{ActivityCalendar,Database,HolidaySeeds,Util};

require dirname(__DIR__) . '/config/bootstrap.php';

$tenant = $argv[1] ?? '';
if (!preg_match('/^[0-9a-f-]{36}$/i', $tenant)) { fwrite(STDERR, "Uso: php tools/seed-group.php <tenant-uuid> [--companies=A,B] [--countries=CO,US,HN] [--years=2026,2027]\n"); exit(2); }
$opt = getopt('', ['companies::', 'countries::', 'years::']);
$list = static fn (?string $v, array $default) => $v === null || $v === '' ? $default : array_values(array_filter(array_map('trim', explode(',', $v))));
$companies = $list($opt['companies'] ?? null, []);
$countries = $list($opt['countries'] ?? null, array_keys(HolidaySeeds::COUNTRIES));
$years = $list($opt['years'] ?? null, [date('Y'), (string)((int)date('Y') + 1)]);

$db = new Database();
$t = Util::bin($tenant);
if (!$db->one('SELECT tenant_id FROM tenants WHERE tenant_id=?', [$t])) { fwrite(STDERR, "Empresa no encontrada\n"); exit(1); }
$db->transaction(function () use ($db, $t, $companies, $countries, $years): void {
    foreach ($companies as $name) {
        if ($db->one("SELECT id FROM org_units WHERE tenant_id=? AND kind='company' AND name=?", [$t, $name])) { echo "Empresa ya existe: $name\n"; continue; }
        $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'company',?)", [$t, Util::bin(Util::uuid()), $name]);
        echo "Empresa creada: $name\n";
    }
    foreach (HolidaySeeds::load($db, $t, $countries, $years) as $country => $n) { echo "Festivos agregados $country: $n\n"; }
});
// Jornada esperada de los festivos ya pasados (los futuros se calculan al llegar el dia).
$days = array_column($db->run('SELECT DISTINCT day FROM holidays WHERE tenant_id=? AND day<=UTC_DATE() AND day>=UTC_DATE()-INTERVAL 400 DAY', [$t])->fetchAll(), 'day');
ActivityCalendar::refresh($db, $t, $days);
echo 'Dias recalculados: ' . count($days) . "\n";
