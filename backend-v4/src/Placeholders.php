<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Unidades y horario "vacios" de una empresa: la persona sin firma, area, cargo u horario conocido queda en
 * "Sin firma", "Sin área", "Sin cargo" o "Sin horario" (users exige los cinco). Se crean la primera vez que hacen falta,
 * desde el panel o desde una fuente de datos que no trae ese dato, y se reemplazan cuando llega el valor real.
 */
final class Placeholders
{
    private const UNITS = ['firm' => 'Sin firma', 'area' => 'Sin área', 'position' => 'Sin cargo'];

    public static function unit(Database $db, string $tenant, string $kind): string
    {
        $name = self::UNITS[$kind];
        $row = $db->one('SELECT id FROM org_units WHERE tenant_id=? AND kind=? AND active=TRUE AND deleted_at IS NULL AND name=? LIMIT 1', [$tenant, $kind, $name]);
        if ($row) { return $row['id']; }
        $id = Util::bin(Util::uuid());
        $db->run('INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,?,?)', [$tenant, $id, $kind, $name]);
        return $id;
    }

    /** Horario sin dias laborales: la jornada esperada es cero, nunca hay ausencia ni tardanza; la actividad si se cuenta. */
    public static function schedule(Database $db, string $tenant, string $timezone): string
    {
        $row = $db->one("SELECT s.id FROM schedules s WHERE s.tenant_id=? AND s.name='Sin horario' AND NOT EXISTS (SELECT 1 FROM schedule_days d WHERE d.tenant_id=s.tenant_id AND d.schedule_id=s.id) LIMIT 1", [$tenant]);
        if ($row) { return $row['id']; }
        $id = Util::bin(Util::uuid());
        $db->run("INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,'Sin horario',?,'00:00:00','00:00:00')", [$tenant, $id, $timezone]);
        return $id;
    }
}
