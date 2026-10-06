<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Unidades y horario "vacios" de una empresa: la persona sin firma, area, cargo u horario conocido queda en
 * "Sin firma", "Sin área", "Sin cargo" o "Sin horario" (users exige los cinco). Se crean la primera vez que hacen falta,
 * desde el panel o desde una fuente de datos que no trae ese dato, y se reemplazan cuando llega el valor real.
 * Se reconocen por la columna `placeholder` (0046), nunca por el nombre: una unidad real llamada "Sin área" que
 * llegue de una fuente es otra unidad.
 */
final class Placeholders
{
    // "Sin sede" solo lo usa una persona retirada que la fuente trae sin sede (una activa sin sede es missing_site).
    private const UNITS = ['firm' => 'Sin firma', 'site' => 'Sin sede', 'area' => 'Sin área', 'position' => 'Sin cargo'];

    public static function unit(Database $db, string $tenant, string $kind): string
    {
        $row = $db->one('SELECT id FROM org_units WHERE tenant_id=? AND kind=? AND placeholder=TRUE AND active=TRUE AND deleted_at IS NULL LIMIT 1', [$tenant, $kind]);
        if ($row) { return $row['id']; }
        $id = Util::bin(Util::uuid());
        $db->run('INSERT INTO org_units (tenant_id,id,kind,name,placeholder) VALUES (?,?,?,?,TRUE)', [$tenant, $id, $kind, self::UNITS[$kind]]);
        return $id;
    }

    /** Horario sin dias laborales: la jornada esperada es cero, nunca hay ausencia ni tardanza; la actividad si se cuenta. */
    public static function schedule(Database $db, string $tenant, string $timezone): string
    {
        $row = $db->one('SELECT id FROM schedules WHERE tenant_id=? AND placeholder=TRUE LIMIT 1', [$tenant]);
        if ($row) { return $row['id']; }
        $id = Util::bin(Util::uuid());
        $db->run("INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local,placeholder) VALUES (?,?,'Sin horario',?,'00:00:00','00:00:00',TRUE)", [$tenant, $id, $timezone]);
        return $id;
    }

    /** ¿Es un placeholder? (unidad u horario; null = no). */
    public static function is(Database $db, string $tenant, ?string $id, string $col): bool
    {
        if ($id === null) { return false; }
        $table = $col === 'schedule_id' ? 'schedules' : 'org_units';
        return (bool) ($db->one("SELECT placeholder FROM $table WHERE tenant_id=? AND id=?", [$tenant, $id])['placeholder'] ?? false);
    }
}
