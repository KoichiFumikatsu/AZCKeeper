<?php
declare(strict_types=1);
namespace Keeper;

// Ajustes de Windows por cliente que el agente aplica en cada equipo. Hoy: apagar Smart App Control (SAC), que bloquea
// el bootstrapper sin firma de las actualizaciones. Apagarlo en Windows es irreversible, por eso el cambio exige
// reautenticacion reciente como el PIN. Sin fila = no se toca nada en los equipos.
final class WindowsSecurity
{
    public function __construct(private Database $db, private array $c, private Request $r) {}

    public function clientSettings(): array
    {
        $row = $this->db->one('SELECT disable_smart_app_control FROM tenant_windows_security WHERE tenant_id=?', [$this->c['tenant_id']]);
        return ['disable_smart_app_control' => (bool) ($row['disable_smart_app_control'] ?? false)];
    }

    public function admin(AdminAccess $access): array
    {
        $access->requireFull();
        $tenant = $this->c['tenant_id'];
        if (Util::bin($this->r->resourceId) !== $tenant) { throw new ApiError(404, 'resource_not_found'); }
        if ($this->r->operation['operationId'] === 'setTenantWindowsSecurity') {
            if ($this->c['reauthenticated_at'] === null || strtotime($this->c['reauthenticated_at'] . ' UTC') < time() - 300) { throw new ApiError(403, 'reauth_required'); }
            $this->db->run('INSERT INTO tenant_windows_security (tenant_id,disable_smart_app_control,updated_by,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE disable_smart_app_control=VALUES(disable_smart_app_control),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',
                [$tenant, (int) $this->r->body->disable_smart_app_control, $this->c['principal_id']]);
            (new Audit($this->db))->record($this->c, $this->r, 'windows_security.changed', 'tenant', $tenant, ['disable_smart_app_control']);
        }
        $row = $this->db->one('SELECT disable_smart_app_control,updated_by,updated_at FROM tenant_windows_security WHERE tenant_id=?', [$tenant]);
        return AdminApi::response(['disable_smart_app_control' => (bool) ($row['disable_smart_app_control'] ?? false),
            'updated_at' => $row === null ? null : Util::time($row['updated_at']), 'updated_by' => $row === null ? null : Util::id($row['updated_by'])]);
    }
}
