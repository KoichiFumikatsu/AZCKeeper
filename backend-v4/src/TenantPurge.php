<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Elimina una empresa archivada sin tocar su auditoria (Koichi 2026-10-02). Borra todo lo suyo salvo la auditoria,
 * las identidades a las que esta apunta (quedan desvinculadas) y un registro minimo de la empresa (deleted_at), que
 * el panel nunca muestra. Corre dentro de la transaccion de la peticion: si algo no se puede borrar, no se borra nada.
 */
final class TenantPurge
{
    private const KEEP = ['tenants', 'audit_log', 'principals'];

    public function __construct(private Database $db) {}

    /** Equipos endurecidos de la empresa: su clave de administrador local se pierde al eliminarla. */
    public function hardened(string $tenant): int
    {
        return (int) $this->db->one("SELECT COUNT(*) n FROM device_hardening_status WHERE tenant_id=? AND state<>'none'", [$tenant])['n'];
    }

    /** @return int filas borradas */
    public function purge(string $tenant): int
    {
        $t = $this->db->one('SELECT * FROM tenants WHERE tenant_id=? FOR UPDATE', [$tenant]);
        if (!$t || $t['deleted_at'] !== null) { throw new ApiError(404, 'resource_not_found'); }
        if ($tenant === Util::bin(AdminAuth::PLATFORM)) { throw new ApiError(409, 'invalid_transition', [], 'platform_cannot_delete'); }
        if ($t['archived_at'] === null) { throw new ApiError(409, 'invalid_transition', [], 'archive_first'); }
        $this->db->run("UPDATE tenants SET deleted_at=UTC_TIMESTAMP(6),status='suspended',auth_version=auth_version+1,version=version+1 WHERE tenant_id=?", [$tenant]);
        // Identidades: las de la empresa y las de sus cuentas en otras empresas quedan desvinculadas (la auditoria sigue).
        $this->db->run('UPDATE principals SET detached=TRUE,admin_id=NULL,admin_tenant_id=NULL,device_id=NULL,integration_id=NULL WHERE tenant_id=? OR admin_tenant_id=?', [$tenant, $tenant]);
        $this->db->run('DELETE FROM access_grants WHERE admin_tenant_id=? OR tenant_id=?', [$tenant, $tenant]);
        // Filas de otras empresas que apuntan a las cuentas de esta (admin_tenant_id con FK a admin_accounts) frenarian el borrado.
        // Incluye las tablas viejas congeladas por 0043 (se borran en la fase 4) sin nombrarlas.
        foreach (array_column($this->db->run("SELECT DISTINCT table_name t FROM information_schema.key_column_usage
            WHERE table_schema=DATABASE() AND referenced_table_name='admin_accounts' AND column_name='admin_tenant_id'")->fetchAll(), 't') as $table) {
            if (!in_array($table, self::KEEP, true)) { $this->db->run("DELETE FROM `$table` WHERE admin_tenant_id=?", [$tenant]); }
        }
        $tables = array_values(array_diff(array_column($this->db->run("SELECT c.table_name t FROM information_schema.columns c JOIN information_schema.tables x ON x.table_schema=c.table_schema AND x.table_name=c.table_name AND x.table_type='BASE TABLE'
            WHERE c.table_schema=DATABASE() AND c.column_name='tenant_id'")->fetchAll(), 't'), self::KEEP));
        $count = fn (string $table): int => (int) $this->db->one("SELECT COUNT(*) n FROM `$table` WHERE tenant_id=?", [$tenant])['n'];
        // Autorreferencias anulables (unidad padre, jefe...) se cortan para poder borrar en cualquier orden.
        foreach ($this->db->run("SELECT k.table_name t, k.column_name c FROM information_schema.key_column_usage k JOIN information_schema.columns col ON col.table_schema=k.table_schema AND col.table_name=k.table_name AND col.column_name=k.column_name
            WHERE k.table_schema=DATABASE() AND k.referenced_table_name=k.table_name AND k.column_name<>'tenant_id' AND col.is_nullable='YES'")->fetchAll() as $s) {
            if (in_array($s['t'], $tables, true)) { $this->db->run("UPDATE `{$s['t']}` SET `{$s['c']}`=NULL WHERE tenant_id=?", [$tenant]); }
        }
        $rows = 0; $pending = array_values(array_filter($tables, fn ($x) => $count($x) > 0));
        for ($pass = 0; $pending && $pass < 40; $pass++) {
            $next = [];
            foreach ($pending as $table) {
                try { $rows += $this->db->run("DELETE FROM `$table` WHERE tenant_id=?", [$tenant])->rowCount(); if ($count($table)) { $next[] = $table; } }
                catch (\PDOException) { $next[] = $table; }
            }
            $pending = $next;
        }
        if ($pending) { throw new ApiError(409, 'invalid_transition', [], 'purge_blocked:' . implode(',', array_slice($pending, 0, 5))); }
        return $rows;
    }
}
