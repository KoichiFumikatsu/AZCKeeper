<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Catalogo de funciones vendibles (docs/architecture/v4-clientes-funciones-accesos.md). Unica fuente: cada funcion
 * dice que operaciones y permisos del panel cubre y que tipos de regla del agente gobierna. La base solo guarda que
 * funciones tiene cada cliente (tenant_features) y los paquetes (atajos). Superadmin nunca queda bloqueado.
 */
final class Features
{
    public const CATALOG = [
        'productividad' => ['name' => 'Actividad y productividad', 'group' => 'Monitoreo y productividad', 'level' => 'person', 'ops' => [], 'permissions' => ['reportes.ver', 'actividad.ver'], 'rules' => [], 'pending' => false],
        'sitios' => ['name' => 'Sitios web visitados', 'group' => 'Monitoreo y productividad', 'level' => 'person', 'ops' => [], 'permissions' => ['actividad.titulos_ver'], 'rules' => [], 'pending' => false],
        'clasificacion' => ['name' => 'Clasificación de apps propia', 'group' => 'Monitoreo y productividad', 'level' => 'client', 'ops' => [], 'permissions' => ['actividad.clasificar'], 'rules' => [], 'pending' => false],
        'presencia' => ['name' => 'Presencia y puntualidad', 'group' => 'Presencia', 'level' => 'person', 'ops' => ['getPresenceReport'], 'permissions' => ['presencia.ver'], 'rules' => [], 'pending' => false],
        'festivos' => ['name' => 'Festivos por país', 'group' => 'Presencia', 'level' => 'client', 'ops' => [], 'permissions' => ['festivos.ver', 'festivos.editar'], 'rules' => [], 'pending' => false],
        'ausencias' => ['name' => 'Ausencias de RR. HH.', 'group' => 'Presencia', 'level' => 'client', 'ops' => ['listAbsences', 'createAbsence', 'patchAbsence'], 'permissions' => [], 'rules' => [], 'pending' => false],
        'puertas' => ['name' => 'Entradas por puerta', 'group' => 'Presencia', 'level' => 'person', 'ops' => ['listDoors', 'createDoor', 'patchDoor', 'getDoorQuarantine', 'externalCheckInsBatch'], 'permissions' => [], 'rules' => [], 'pending' => false],
        'ubicacion' => ['name' => 'Ubicación del equipo', 'group' => 'Presencia', 'level' => 'person', 'ops' => [], 'permissions' => ['presencia.ubicacion_ver'], 'rules' => [], 'pending' => false],
        'web' => ['name' => 'Bloqueo de sitios web', 'group' => 'Control de equipos', 'level' => 'person', 'ops' => [], 'permissions' => [], 'rules' => ['web'], 'pending' => false],
        'usb' => ['name' => 'Bloqueo de USB', 'group' => 'Control de equipos', 'level' => 'person', 'ops' => [], 'permissions' => [], 'rules' => ['usb'], 'pending' => false],
        'descargas' => ['name' => 'Control de descargas', 'group' => 'Control de equipos', 'level' => 'person', 'ops' => [], 'permissions' => [], 'rules' => ['download'], 'pending' => false],
        'instalaciones' => ['name' => 'Control de instalación de programas', 'group' => 'Control de equipos', 'level' => 'person', 'ops' => [], 'permissions' => [], 'rules' => ['installation'], 'pending' => false],
        'comandos' => ['name' => 'Comandos remotos (bloquear, reiniciar, apagar)', 'group' => 'Control de equipos', 'level' => 'person', 'ops' => [], 'permissions' => ['equipos.bloquear', 'equipos.desbloquear', 'equipos.reiniciar', 'equipos.apagar'], 'rules' => [], 'pending' => false],
        'borrado' => ['name' => 'Borrado remoto', 'group' => 'Control de equipos', 'level' => 'person', 'ops' => [], 'permissions' => ['equipos.borrar'], 'rules' => [], 'pending' => false],
        'endurecimiento' => ['name' => 'Endurecimiento de Windows', 'group' => 'Control de equipos', 'level' => 'person', 'ops' => [], 'permissions' => ['hardening.gestionar'], 'rules' => [], 'pending' => false],
        'cumplimiento' => ['name' => 'Doble empleo y apps sospechosas', 'group' => 'Cumplimiento', 'level' => 'person', 'ops' => [], 'permissions' => ['cumplimiento.ver', 'cumplimiento.editar'], 'rules' => [], 'pending' => false],
        'cobertura' => ['name' => 'Cobertura de instalación', 'group' => 'Cumplimiento', 'level' => 'client', 'ops' => [], 'permissions' => ['cobertura.ver', 'cobertura.editar'], 'rules' => [], 'pending' => false],
        'autogestion_personas' => ['name' => 'El cliente crea y edita sus personas', 'group' => 'Autogestión del cliente', 'level' => 'client', 'ops' => [], 'permissions' => ['usuarios.crear', 'usuarios.editar'], 'rules' => [], 'pending' => false],
        'autogestion_accesos' => ['name' => 'El cliente gestiona sus roles y cuentas', 'group' => 'Autogestión del cliente', 'level' => 'client', 'ops' => ['listPanelAccounts', 'createPanelAccount', 'patchPanelAccount', 'resetPanelAccountPassword'], 'permissions' => ['roles.gestionar', 'roles.reglas', 'permisos.gestionar'], 'rules' => [], 'pending' => false],
        'auditoria' => ['name' => 'Auditoría visible para el cliente', 'group' => 'Autogestión del cliente', 'level' => 'client', 'ops' => [], 'permissions' => ['auditoria.ver'], 'rules' => [], 'pending' => false],
        'api' => ['name' => 'API para otros sistemas', 'group' => 'Integraciones', 'level' => 'client', 'ops' => [], 'permissions' => [], 'rules' => [], 'pending' => true],
        'one' => ['name' => 'Conexión con AZC One', 'group' => 'Integraciones', 'level' => 'client', 'ops' => [], 'permissions' => ['integraciones.ver', 'integraciones.gestionar', 'integraciones.aprobar'], 'rules' => [], 'pending' => false],
        'webhooks' => ['name' => 'Avisos automáticos (webhooks)', 'group' => 'Integraciones', 'level' => 'client', 'ops' => [], 'permissions' => ['notificaciones.ver', 'notificaciones.gestionar'], 'rules' => [], 'pending' => true],
    ];
    /** Siempre incluido: no se vende aparte. */
    public const CORE_PERMISSIONS = ['empresas.ver', 'empresas.editar', 'equipos.ver', 'equipos.enrolar', 'equipos.asignar', 'equipos.editar', 'equipos.transferir',
        'horarios.ver', 'horarios.editar', 'organizacion.ver', 'organizacion.editar', 'usuarios.ver', 'roles.ver', 'reglas.ver', 'reglas.editar', 'reglas.aplicar',
        'tiers.ver', 'operacion.ajustes_ver', 'operacion.ajustes_editar', 'operacion.logs', 'sesion', 'planes.asignar'];
    /** Solo de la plataforma: nunca se vende (lo protege x-platform-only o el rol de superadmin). */
    public const PLATFORM_PERMISSIONS = ['empresas.gestionar', 'empresas.rbac_habilitar', 'releases.ver', 'releases.publicar', 'releases.desplegar',
        'migracion.ver', 'migracion.gestionar', 'migracion.recuperar', 'operacion.salud', 'tiers.gestionar'];

    /** @return list<string> */
    public static function codes(): array { return array_keys(self::CATALOG); }

    /** Funcion que cubre una operacion: primero por operacion (casos compartidos), luego por permiso; null = nucleo. */
    public static function forOperation(string $operationId, string $permission): ?string
    {
        foreach (self::CATALOG as $code => $f) { if (in_array($operationId, $f['ops'], true)) { return $code; } }
        foreach (self::CATALOG as $code => $f) { if (in_array($permission, $f['permissions'], true)) { return $code; } }
        return null;
    }

    public static function forRuleKind(string $kind): ?string
    {
        foreach (self::CATALOG as $code => $f) { if (in_array($kind, $f['rules'], true)) { return $code; } }
        return null;
    }

    /** @return list<string> funciones encendidas del cliente ($tenant binario) */
    public static function enabled(Database $db, string $tenant): array
    {
        return array_column($db->run('SELECT feature_code FROM tenant_features WHERE tenant_id=? ORDER BY feature_code', [$tenant])->fetchAll(), 'feature_code');
    }

    public static function has(Database $db, string $tenant, string $code): bool
    {
        return (bool) $db->one('SELECT 1 x FROM tenant_features WHERE tenant_id=? AND feature_code=?', [$tenant, $code]);
    }
}
