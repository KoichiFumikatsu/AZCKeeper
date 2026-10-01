<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Festivos oficiales por pais, calculados por año (base para cargar calendarios; luego se editan en el panel).
 * - CO: Ley 51 de 1983 (Emiliani: varios pasan al lunes siguiente) y los que dependen de la Pascua.
 * - US: el conjunto que observa la oficina (federales + dia despues de Accion de Gracias, 24 y 31 de diciembre);
 *   si caen en sabado se observan el viernes y en domingo el lunes.
 * - HN: Codigo de Trabajo art. 339. Los del 3, 12 y 21 de octubre (Semana Morazanica) se mueven cada año por decreto:
 *   se cargan en su fecha nominal y marcados para revisar.
 */
final class HolidaySeeds
{
    public const COUNTRIES = ['CO' => 'Colombia', 'US' => 'Estados Unidos', 'HN' => 'Honduras'];

    /** @return array<string,string> fecha => nombre */
    public static function year(string $country, int $year): array
    {
        $d = static fn (int $m, int $day) => new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $m, $day));
        $easter = self::easter($year);
        $out = [];
        $add = static function (\DateTimeImmutable $day, string $name) use (&$out): void { $out[$day->format('Y-m-d')] ??= $name; };
        if ($country === 'CO') {
            $monday = static fn (\DateTimeImmutable $x) => $x->format('N') === '1' ? $x : $x->modify('next monday');
            $add($d(1, 1), 'Año Nuevo');
            $add($monday($d(1, 6)), 'Día de los Reyes Magos');
            $add($monday($d(3, 19)), 'Día de San José');
            $add($easter->modify('-3 days'), 'Jueves Santo');
            $add($easter->modify('-2 days'), 'Viernes Santo');
            $add($d(5, 1), 'Día del Trabajo');
            $add($easter->modify('+43 days'), 'Ascensión del Señor');
            $add($easter->modify('+64 days'), 'Corpus Christi');
            $add($easter->modify('+71 days'), 'Sagrado Corazón');
            $add($monday($d(6, 29)), 'San Pedro y San Pablo');
            $add($d(7, 20), 'Día de la Independencia');
            $add($d(8, 7), 'Batalla de Boyacá');
            $add($monday($d(8, 15)), 'Asunción de la Virgen');
            $add($monday($d(10, 12)), 'Día de la Raza');
            $add($monday($d(11, 1)), 'Todos los Santos');
            $add($monday($d(11, 11)), 'Independencia de Cartagena');
            $add($d(12, 8), 'Inmaculada Concepción');
            $add($d(12, 25), 'Navidad');
        } elseif ($country === 'US') {
            $observed = static fn (\DateTimeImmutable $x) => match ($x->format('N')) { '6' => $x->modify('-1 day'), '7' => $x->modify('+1 day'), default => $x };
            $nth = static fn (int $m, int $n, string $dayName) => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m)))->modify(['first', 'second', 'third', 'fourth'][$n - 1] . " $dayName of this month");
            $add($observed($d(1, 1)), "New Year's Day");
            $add($nth(1, 3, 'monday'), 'Martin Luther King Jr. Day');
            $add($nth(2, 3, 'monday'), "Presidents' Day");
            $add((new \DateTimeImmutable(sprintf('%04d-05-01', $year)))->modify('last monday of this month'), 'Memorial Day');
            $add($observed($d(6, 19)), 'Juneteenth');
            $add($observed($d(7, 4)), 'Independence Day');
            $add($nth(9, 1, 'monday'), 'Labor Day');
            $thanks = $nth(11, 4, 'thursday');
            $add($thanks, 'Thanksgiving Day');
            $add($thanks->modify('+1 day'), 'Day after Thanksgiving');
            $add($observed($d(12, 25)), 'Christmas Day');
            $add($d(12, 24), 'Christmas Eve');
            $add($d(12, 31), "New Year's Eve");
        } elseif ($country === 'HN') {
            $add($d(1, 1), 'Año Nuevo');
            $add($d(4, 14), 'Día de las Américas');
            $add($easter->modify('-3 days'), 'Jueves Santo');
            $add($easter->modify('-2 days'), 'Viernes Santo');
            $add($easter->modify('-1 day'), 'Sábado Santo');
            $add($d(5, 1), 'Día del Trabajo');
            $add($d(9, 15), 'Día de la Independencia');
            $add($d(10, 3), 'Día de Morazán (revisar decreto Semana Morazánica)');
            $add($d(10, 12), 'Día de la Raza (revisar decreto Semana Morazánica)');
            $add($d(10, 21), 'Día de las Fuerzas Armadas (revisar decreto Semana Morazánica)');
            $add($d(12, 25), 'Navidad');
        } else {
            throw new \InvalidArgumentException("unknown_country:$country");
        }
        ksort($out);
        return $out;
    }

    /** Domingo de Pascua (computo gregoriano anonimo). */
    public static function easter(int $y): \DateTimeImmutable
    {
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
        $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $month, $day));
    }

    /**
     * Crea (si faltan) los calendarios de los paises y agrega los festivos que falten en esos años. No toca ni borra lo
     * que ya este cargado ese dia en el calendario. Devuelve [pais => festivos agregados].
     */
    public static function load(Database $db, string $tenant, array $countries, array $years): array
    {
        $added = [];
        foreach ($countries as $country) {
            $cal = $db->one('SELECT id FROM holiday_calendars WHERE tenant_id=? AND country_code=? ORDER BY is_default DESC,name LIMIT 1', [$tenant, $country]);
            if (!$cal) {
                $id = Util::bin(Util::uuid());
                $default = !$db->one('SELECT id FROM holiday_calendars WHERE tenant_id=? AND is_default=TRUE', [$tenant]) && $country === 'CO';
                $db->run('INSERT INTO holiday_calendars (tenant_id,id,name,country_code,is_default) VALUES (?,?,?,?,?)', [$tenant, $id, self::COUNTRIES[$country], $country, (int)$default]);
                $cal = ['id' => $id];
            }
            $added[$country] = 0;
            foreach ($years as $year) {
                foreach (self::year($country, (int)$year) as $day => $name) {
                    if ($db->one('SELECT id FROM holidays WHERE tenant_id=? AND calendar_id=? AND day=?', [$tenant, $cal['id'], $day])) { continue; }
                    $db->run('INSERT INTO holidays (tenant_id,id,day,name,calendar_id) VALUES (?,?,?,?,?)', [$tenant, Util::bin(Util::uuid()), $day, $name, $cal['id']]);
                    $added[$country]++;
                }
            }
        }
        return $added;
    }
}
