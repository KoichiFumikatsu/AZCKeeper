# Funciones por cliente (fase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Un catálogo de 23 funciones vendibles definido en código, paquetes y funciones encendidas por cliente, aplicado en la API (403 `feature_not_contracted:<código>`) y en el menú, con la pantalla de superadmin para administrarlo.

**Architecture:** `src/Features.php` es la única fuente del catálogo: funciones, su nivel, qué operaciones y permisos cubren, y qué tipos de regla del agente gobiernan. La base guarda solo elecciones: `feature_packages`, `feature_package_items`, `tenant_features` y el paquete de origen en `tenants`. `AdminAccess` rechaza la operación si su función no está activa en el cliente (los superadmin pasan siempre). `GET /me/access` devuelve solo los permisos cuyas funciones están activas, así el menú existente las oculta sin más cambios.

**Tech Stack:** PHP 8.3 sin framework (backend-v4), MySQL 8.4 local / MariaDB 11.4 en producción, contrato OpenAPI `docs/architecture/openapi-v4.yaml` exportado a `backend-v4/config/contract.json`, JS de módulos en `backend-v4/public/assets/`.

## Global Constraints

- Diseño fuente: `docs/architecture/v4-clientes-funciones-accesos.md` (aprobado 2026-10-02). Esta fase cubre sus secciones 2, 3 (API y menú; **no** el agente), 5 (solo ficha de funciones y paquetes) y 6 (AZCLegal con todo encendido).
- Interfaz y mensajes en español; código y nombres de base en el estilo existente (snake_case en SQL, camelCase en PHP).
- Toda tabla nueva tiene `tenant_id` obligatorio y toda FK incluye `tenant_id` (lo exige `migrations/verify.php`). Las tablas de plataforma usan `tenant_id = 0x00000000000040008000000000000001` con CHECK.
- Superadmin (`$c['platform']`) nunca queda bloqueado por funciones.
- Apagar una función no borra datos.
- Contrato: tras editar `openapi-v4.yaml`, correr `python tests/export-contract.py` desde `backend-v4`. Una ruta con raíz nueva va en `admin_roots` de `tests/export-contract.py`.
- Toda operación de escritura nueva lleva su texto en `AUDIT_ACTIONS` de `public/assets/k3.js` (lo vigila `tests/ops.php`).
- Suite completa: desde `backend-v4`, con PATH de laragon:
  `export PATH="$(ls -d /c/laragon/bin/mysql/mysql-8.4.3*)/bin:$(ls -d /c/laragon/bin/php/php-8.3.30*):$PATH" && powershell -NoProfile -File tests/mysql-smoke.ps1 -DebugResponses > "$SCRATCH/smoke.log" 2>&1; grep -n "FAIL\|SMOKE PASS" "$SCRATCH/smoke.log"` (debe terminar en `SMOKE PASS`; hoy son 2106 aserciones).
- Commits terminan con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `backend-v4/src/Features.php` (nuevo) | Catálogo y resolución: qué función cubre una operación, permiso o tipo de regla; qué funciones tiene un cliente. |
| `backend-v4/src/FeatureAdmin.php` (nuevo) | Endpoints de plataforma: catálogo, paquetes, funciones de un cliente. |
| `backend-v4/migrations/0040_features.sql` (nuevo) | Tablas y siembra (todos los clientes existentes con todo encendido). |
| `backend-v4/src/AdminAccess.php` | Rechazo por función no contratada. |
| `backend-v4/src/AdminApi.php` | Pasa la operación a AdminAccess, enruta FeatureAdmin, valida tipos de regla, `myAccess` con funciones, `createTenant` con paquete. |
| `backend-v4/src/ExternalAuth.php` | La API externa exige la función `api` (las entradas por puerta, `puertas`). |
| `docs/architecture/openapi-v4.yaml` | Rutas `/features`, `/feature-packages`, `/feature-packages/{id}`, `/tenants/{id}/features`; `MyAccess.features`; `TenantCreate.package_id`. |
| `backend-v4/tests/export-contract.py` | Raíces `features`, `feature-packages`. |
| `backend-v4/tests/features.php` (nuevo) | Guardias del catálogo y casos. |
| `backend-v4/tests/smoke.php` | Registra `featureTests`; `secondTenant()` enciende todas las funciones. |
| `backend-v4/public/assets/k3.js` | Empresas: botón «Funciones», sección «Paquetes», textos de auditoría. |
| `backend-v4/public/assets/api.js` | Mensaje en español para `feature_not_contracted`. |

---

### Task 1: Catálogo de funciones en código

**Files:**
- Create: `backend-v4/src/Features.php`
- Create: `backend-v4/tests/features.php`
- Modify: `backend-v4/tests/smoke.php` (require + llamada)

**Interfaces:**
- Produces:
  - `Features::CATALOG: array<string, array{name:string, group:string, level:'client'|'person', ops:list<string>, permissions:list<string>, rules:list<string>, pending:bool}>`
  - `Features::CORE_PERMISSIONS: list<string>`
  - `Features::PLATFORM_PERMISSIONS: list<string>`
  - `Features::codes(): list<string>`
  - `Features::forOperation(string $operationId, string $permission): ?string`
  - `Features::forRuleKind(string $kind): ?string`
  - `Features::enabled(Database $db, string $tenant): list<string>`, con `$tenant` binario
  - `Features::has(Database $db, string $tenant, string $code): bool`

- [ ] **Step 1: Write the failing test**

Crear `backend-v4/tests/features.php`:

```php
<?php
declare(strict_types=1);
use Keeper\{Database,Features,Util};

// Fase 1 de v4-clientes-funciones-accesos: catalogo de funciones vendibles (2026-10-02).
function featureCatalogTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    check(count(Features::CATALOG) === 23, 'catalog has 23 functions');
    // Todo permiso existente pertenece a una funcion, al nucleo o a la plataforma (nunca a dos).
    $byFeature = array_merge(...array_values(array_map(fn ($f) => $f['permissions'], Features::CATALOG)));
    foreach ($db->run('SELECT code FROM permissions')->fetchAll() as $p) {
        $code = $p['code'];
        $places = (int) in_array($code, $byFeature, true) + (int) in_array($code, Features::CORE_PERMISSIONS, true) + (int) in_array($code, Features::PLATFORM_PERMISSIONS, true);
        check($places === 1, "permission $code classified once");
    }
    // Toda operacion nombrada en el catalogo existe en el contrato.
    $contract = json_decode(file_get_contents(dirname(__DIR__) . '/config/contract.json'), true);
    $ops = [];
    foreach ($contract['paths'] as $path) { foreach ($path as $op) { if (is_array($op) && isset($op['operationId'])) { $ops[] = $op['operationId']; } } }
    foreach (Features::CATALOG as $code => $f) {
        foreach ($f['ops'] as $op) { check(in_array($op, $ops, true), "$code covers existing operation $op"); }
        // Ninguna funcion sin uso, salvo las marcadas pendientes (sin endpoints todavia).
        check($f['pending'] || $f['ops'] || $f['permissions'] || $f['rules'], "$code is used somewhere");
        check(in_array($f['level'], ['client', 'person'], true), "$code has a level");
    }
    // Resolucion: la operacion manda sobre el permiso; el permiso sobre nada.
    check(Features::forOperation('listDoors', 'organizacion.ver') === 'puertas', 'doors by operation');
    check(Features::forOperation('listOrganization', 'organizacion.ver') === null, 'organization is core');
    check(Features::forOperation('listHolidays', 'festivos.ver') === 'festivos', 'holidays by permission');
    check(Features::forOperation('getPresenceReport', 'reportes.ver') === 'presencia', 'presence report by operation');
    check(Features::forOperation('getProductivity', 'reportes.ver') === 'productividad', 'productivity by permission');
    check(Features::forOperation('listPanelAccounts', 'usuarios.editar') === 'autogestion_accesos', 'panel accounts are self-managed access');
    check(Features::forOperation('patchUser', 'usuarios.editar') === 'autogestion_personas', 'editing people is self-managed people');
    check(Features::forRuleKind('usb') === 'usb' && Features::forRuleKind('download') === 'descargas' && Features::forRuleKind('installation') === 'instalaciones' && Features::forRuleKind('web') === 'web', 'rule kinds');
    echo 'PASS feature catalog (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `backend-v4/tests/smoke.php` agregar `require __DIR__ . '/features.php';` después de `require __DIR__ . '/group-structure.php';`, y `featureCatalogTests($db);` después de `siteCityTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: la suite completa (Global Constraints).
Expected: FAIL con `Class "Keeper\Features" not found`.

- [ ] **Step 3: Write the implementation**

Crear `backend-v4/src/Features.php`:

```php
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
        'titulos' => ['name' => 'Títulos de ventana', 'group' => 'Monitoreo y productividad', 'level' => 'person', 'ops' => [], 'permissions' => ['actividad.titulos_ver'], 'rules' => [], 'pending' => false],
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
        'tiers.ver', 'operacion.ajustes_ver', 'operacion.ajustes_editar', 'operacion.logs', 'sesion'];
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: la suite completa.
Expected: aparece `PASS feature catalog (...)` y `SMOKE PASS`. Si una aserción `permission X classified once` falla, el permiso X falta o está repetido en las listas: corregir `Features.php` (no la prueba).

- [ ] **Step 5: Commit**

```bash
git add backend-v4/src/Features.php backend-v4/tests/features.php backend-v4/tests/smoke.php
git commit -m "Funciones: catálogo de 23 funciones vendibles en código con guardias

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Tablas de funciones por cliente y paquetes

**Files:**
- Create: `backend-v4/migrations/0040_features.sql`
- Modify: `backend-v4/tests/smoke.php` (`secondTenant()` enciende todo)
- Modify: `backend-v4/tests/features.php`

**Interfaces:**
- Produces:
  - tablas `feature_packages(tenant_id, id, name, version, created_at)`, `feature_package_items(tenant_id, package_id, feature_code)`, `tenant_features(tenant_id, feature_code, enabled_at)`;
  - columnas `tenants.feature_package_tenant_id` y `tenants.feature_package_id`.

- [ ] **Step 1: Write the failing test**

Agregar a `backend-v4/tests/features.php`:

```php
function featureStorageTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    // Los clientes de las pruebas (como los existentes al migrar) tienen todas las funciones.
    check(Features::enabled($db, $tenant) === (function () { $c = Features::codes(); sort($c); return $c; })(), 'fixture tenant has every function');
    check(Features::has($db, $tenant, 'usb') && !Features::has($db, $tenant, 'inexistente'), 'has()');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='usb'", [$tenant]);
    check(!Features::has($db, $tenant, 'usb'), 'function can be turned off');
    echo 'PASS feature storage (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `tests/smoke.php` agregar `featureStorageTests($db);` después de `featureCatalogTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: la suite completa.
Expected: FAIL con `Table '...tenant_features' doesn't exist` (SQLSTATE 42S02).

- [ ] **Step 3: Write the migration**

Crear `backend-v4/migrations/0040_features.sql`:

```sql
-- Funciones vendibles por cliente (docs/architecture/v4-clientes-funciones-accesos.md, fase 1). El catalogo vive en
-- src/Features.php; aqui solo las elecciones. Paquetes = atajos de la plataforma. Apagar una funcion no borra datos.

CREATE TABLE IF NOT EXISTS feature_packages (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(80) NOT NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY feature_packages_name (tenant_id, name),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT feature_packages_platform CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS feature_package_items (
  tenant_id BINARY(16) NOT NULL,
  package_id BINARY(16) NOT NULL,
  feature_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, package_id, feature_code),
  FOREIGN KEY (tenant_id, package_id) REFERENCES feature_packages (tenant_id, id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT feature_package_items_platform CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS tenant_features (
  tenant_id BINARY(16) NOT NULL,
  feature_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  enabled_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, feature_code),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE tenants
  ADD COLUMN feature_package_tenant_id BINARY(16) NULL,
  ADD COLUMN feature_package_id BINARY(16) NULL,
  ADD CONSTRAINT tenants_feature_package FOREIGN KEY (feature_package_tenant_id, feature_package_id) REFERENCES feature_packages (tenant_id, id),
  ADD CONSTRAINT tenants_feature_package_pair CHECK ((feature_package_id IS NULL) = (feature_package_tenant_id IS NULL));

-- Clientes existentes (Grupo AZC y demas): todas las funciones encendidas, para no apagarles nada sin querer.
INSERT IGNORE INTO tenant_features (tenant_id, feature_code)
  SELECT t.tenant_id, f.code FROM tenants t
  CROSS JOIN (SELECT 'productividad' code UNION ALL SELECT 'titulos' UNION ALL SELECT 'clasificacion' UNION ALL SELECT 'presencia'
    UNION ALL SELECT 'festivos' UNION ALL SELECT 'ausencias' UNION ALL SELECT 'puertas' UNION ALL SELECT 'ubicacion'
    UNION ALL SELECT 'web' UNION ALL SELECT 'usb' UNION ALL SELECT 'descargas' UNION ALL SELECT 'instalaciones'
    UNION ALL SELECT 'comandos' UNION ALL SELECT 'borrado' UNION ALL SELECT 'endurecimiento' UNION ALL SELECT 'cumplimiento'
    UNION ALL SELECT 'cobertura' UNION ALL SELECT 'autogestion_personas' UNION ALL SELECT 'autogestion_accesos' UNION ALL SELECT 'auditoria'
    UNION ALL SELECT 'api' UNION ALL SELECT 'one' UNION ALL SELECT 'webhooks') f
  WHERE t.tenant_id <> 0x00000000000040008000000000000001 AND t.deleted_at IS NULL;
```

En `tests/smoke.php`, dentro de `secondTenant()`, justo antes de `return $db->one('SELECT * FROM users ...`, agregar:

```php
    // Clientes de prueba con todas las funciones (como los clientes existentes al migrar 0040).
    foreach (Keeper\Features::codes() as $code) { $db->run('INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,?)', [$tenant, $code]); }
```

- [ ] **Step 4: Run test to verify it passes**

Run: la suite completa.
Expected: `PASS feature storage (...)` y `SMOKE PASS`. `migrations/verify.php` (corre dentro de la suite) también pasa: tenant y FKs con tenant.

- [ ] **Step 5: Commit**

```bash
git add backend-v4/migrations/0040_features.sql backend-v4/tests/smoke.php backend-v4/tests/features.php
git commit -m "Funciones: tablas de paquetes y funciones por cliente; clientes existentes con todo encendido

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: La API respeta las funciones del cliente

**Files:**
- Modify: `backend-v4/src/AdminAccess.php:8-19` (constructor)
- Modify: `backend-v4/src/AdminApi.php:21` (pasa la operación) y `validateRules()` (tipos de regla)
- Modify: `backend-v4/src/ExternalAuth.php` (`context()`, antes de `return $c;`)
- Modify: `backend-v4/public/assets/api.js` (mensaje)
- Modify: `backend-v4/tests/features.php`

**Interfaces:**
- Consumes: `Features::forOperation`, `Features::forRuleKind`, `Features::has` (Task 1). Tabla `tenant_features` (Task 2).
- Produces:
  - `new AdminAccess(Database $db, array $c, string $permission, ?string $operation = null)`;
  - error 403 `permission_denied` con detail `feature_not_contracted:<código>`.

- [ ] **Step 1: Write the failing test**

Agregar a `backend-v4/tests/features.php`:

```php
function featureEnforcementTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $admin = adminFixture($db, $u, ['festivos.ver', 'organizacion.ver', 'reglas.editar', 'reglas.ver'], false);
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    check(adminRequest($admin, 'GET', '/schedules/holidays')[0] === 200, 'contracted function works');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code IN ('festivos','puertas','usb')", [$tenant]);
    $off = adminRequest($admin, 'GET', '/schedules/holidays');
    check($off[0] === 403 && $off[1]['detail'] === 'feature_not_contracted:festivos', 'function off is refused even with permission');
    check(adminRequest($admin, 'GET', '/doors')[1]['detail'] === 'feature_not_contracted:puertas', 'shared permission resolved by operation');
    check(adminRequest($admin, 'GET', '/organization')[0] === 200, 'core keeps working');
    check(adminRequest($platform, 'GET', '/schedules/holidays')[0] === 200, 'superadmin never blocked');
    $rule = ['id' => Util::uuid(), 'kind' => 'usb', 'effect' => 'deny', 'targets' => ['*'], 'schedule_id' => null, 'priority' => 0];
    $usb = adminRequest($admin, 'POST', '/policies', ['name' => 'USB', 'target_type' => 'tenant', 'target_id' => Util::id($tenant), 'enabled' => true, 'rules' => [$rule]]);
    check($usb[0] === 403 && $usb[1]['detail'] === 'feature_not_contracted:usb', 'rule kind needs its function');
    $web = ['id' => Util::uuid(), 'kind' => 'web', 'effect' => 'deny', 'targets' => ['facebook.com'], 'schedule_id' => null, 'priority' => 0];
    check(adminRequest($admin, 'POST', '/policies', ['name' => 'Web', 'target_type' => 'tenant', 'target_id' => Util::id($tenant), 'enabled' => true, 'rules' => [$web]])[0] === 201, 'other rule kinds still allowed');
    echo 'PASS feature enforcement (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `tests/smoke.php` agregar `featureEnforcementTests($db);` después de `featureStorageTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: la suite completa.
Expected: FAIL `function off is refused even with permission` (hoy responde 200).

- [ ] **Step 3: Write the implementation**

En `backend-v4/src/AdminAccess.php` cambiar la firma y agregar la verificación al final del constructor:

```php
    public function __construct(private Database $db, public readonly array $c, public readonly string $permission, ?string $operation = null)
```

y, justo después de `if (!$this->grants) { throw new ApiError(403,'permission_denied'); }`:

```php
        // Funcion no contratada por el cliente: aunque el rol tenga el permiso (superadmin nunca queda bloqueado).
        if (!$c['platform']) {
            $feature=Features::forOperation($operation??'',$permission);
            if ($feature!==null && !Features::has($db,$c['tenant_id'],$feature)) { throw new ApiError(403,'permission_denied',[],"feature_not_contracted:$feature"); }
        }
```

En `backend-v4/src/AdminApi.php` línea 21:

```php
        $this->access=new AdminAccess($db,$c,$permission,$r->operation['operationId']);
```

En `validateRules()` de `AdminApi.php`, como primera línea dentro de `foreach ($rules as $rule) {`:

```php
            // Cada tipo de regla del agente es una funcion vendible (web, usb, descargas, instalaciones).
            $feature=Features::forRuleKind($rule->kind);
            if ($feature!==null && !$this->c['platform'] && !Features::has($this->db,$this->tenant,$feature)) { throw new ApiError(403,'permission_denied',[],"feature_not_contracted:$feature"); }
```

En `backend-v4/src/ExternalAuth.php`, antes del `return $c;` final de `context()`:

```php
        // API externa: funcion "api"; las entradas por puerta, funcion "puertas".
        $feature=($r->operation['operationId']??'')==='externalCheckInsBatch'?'puertas':'api';
        if (!Features::has($this->db,$c['tenant_id'],$feature)) { throw new ApiError(403,'insufficient_scope',[],"feature_not_contracted:$feature"); }
```

En `backend-v4/public/assets/api.js`, dentro de `errorMessage()`, antes del retorno genérico, agregar:

```js
  const feature = (error?.problem?.detail || '').match(/^feature_not_contracted:(.+)$/)?.[1];
  if (feature) return `La empresa no tiene contratada esta función (${feature}). Pídela a AZC Keeper.`;
```

(Leer primero `errorMessage` en `api.js` y colocarlo donde se inspecciona `error.problem`.)

- [ ] **Step 4: Run test to verify it passes**

Run: la suite completa.
Expected: `PASS feature enforcement (...)` y `SMOKE PASS`. Las pruebas existentes siguen pasando porque `secondTenant()` enciende todo. Si una prueba existente crea su cliente con `POST /tenants` y luego usa una cuenta de empresa, fallará con `feature_not_contracted`: en esa prueba, tras crear el cliente, agregar `foreach (Keeper\Features::codes() as $f) { $db->run('INSERT IGNORE INTO tenant_features (tenant_id,feature_code) VALUES (?,?)', [Util::bin($newTenant['id']), $f]); }`.

- [ ] **Step 5: Commit**

```bash
git add backend-v4/src/AdminAccess.php backend-v4/src/AdminApi.php backend-v4/src/ExternalAuth.php backend-v4/public/assets/api.js backend-v4/tests/features.php backend-v4/tests/smoke.php
git commit -m "Funciones: la API rechaza lo no contratado (operaciones, tipos de regla y API externa)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Administración de paquetes y funciones por cliente (API de plataforma)

**Files:**
- Create: `backend-v4/src/FeatureAdmin.php`
- Modify: `docs/architecture/openapi-v4.yaml`, `backend-v4/tests/export-contract.py`, `backend-v4/config/contract.json` (generado)
- Modify: `backend-v4/src/AdminApi.php` (enrutar; `createTenant` con `package_id`)
- Modify: `backend-v4/public/assets/k3.js` (`AUDIT_ACTIONS`)
- Modify: `backend-v4/tests/features.php`

**Interfaces:**
- Consumes: `Features::CATALOG`, `Features::codes()`, `Features::enabled()`.
- Produces endpoints (todos `x-platform-only: true`, `x-permission: empresas.gestionar`):
  - `GET /features` → `listFeatures` → `{data:[{code,name,group,level,pending}]}`
  - `GET /feature-packages` → `listFeaturePackages` → `{data:[FeaturePackage]}`
  - `POST /feature-packages` → `createFeaturePackage`, body `{name, features[]}` → 201 `FeaturePackage`
  - `PATCH /feature-packages/{id}` → `patchFeaturePackage`, If-Match, body `{name, features[]}` → 200 `FeaturePackage`
  - `GET /tenants/{id}/features` → `getTenantFeatures` → `{package_id, package_name, features[]}`
  - `PUT /tenants/{id}/features` → `putTenantFeatures`, body `{package_id|null, features[]}` → 200 igual
  - `FeaturePackage = {id, name, features[], tenants:int, version}`
  - `TenantCreate.package_id` opcional: al crear, enciende las funciones del paquete.

- [ ] **Step 1: Write the failing test**

Agregar a `backend-v4/tests/features.php`:

```php
function featureAdminTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tid = Util::id($u['tenant_id']);
    $platform = sharedPlatform($db); $platform['tenant'] = $u['tenant_id'];
    $catalog = expect(adminRequest($platform, 'GET', '/features'), 200, 'catalog')['data'];
    check(count($catalog) === 23 && $catalog[0]['code'] === 'productividad', 'catalog listed in order');
    $basic = expect(adminRequest($platform, 'POST', '/feature-packages', ['name' => 'Básico ' . substr(Util::uuid(), 0, 6), 'features' => ['productividad', 'presencia']]), 201, 'create package');
    check($basic['features'] === ['presencia', 'productividad'] && $basic['tenants'] === 0, 'package stored');
    check(adminRequest($platform, 'POST', '/feature-packages', ['name' => 'Malo', 'features' => ['inventada']])[1]['detail'] === 'unknown_feature:inventada', 'unknown function rejected');
    $set = expect(adminRequest($platform, 'PUT', '/tenants/' . $tid . '/features', ['package_id' => $basic['id'], 'features' => ['productividad', 'presencia', 'usb']]), 200, 'set client functions');
    check($set['package_id'] === $basic['id'] && $set['features'] === ['presencia', 'productividad', 'usb'], 'package plus sale adjustments');
    check(Features::enabled($db, $u['tenant_id']) === ['presencia', 'productividad', 'usb'], 'stored for the client');
    $pk = expect(adminRequest($platform, 'GET', '/feature-packages'), 200, 'list packages')['data'];
    check(array_values(array_filter($pk, fn ($p) => $p['id'] === $basic['id']))[0]['tenants'] === 1, 'package usage counted');
    $edited = expect(adminRequest($platform, 'PATCH', '/feature-packages/' . $basic['id'], ['name' => $basic['name'], 'features' => ['productividad']], ['if-match' => '"' . $basic['version'] . '"']), 200, 'edit package');
    check($edited['features'] === ['productividad'] && Features::enabled($db, $u['tenant_id']) === ['presencia', 'productividad', 'usb'], 'editing a package does not change existing clients');
    $created = expect(adminRequest($platform, 'POST', '/tenants', ['name' => 'Cliente X ' . substr(Util::uuid(), 0, 6), 'timezone' => 'America/Bogota', 'package_id' => $basic['id'],
        'branding' => ['display_name' => 'Cliente X', 'logo_url' => 'https://example.test/logo.svg', 'primary_color' => '#3C4082', 'accent_color' => '#5A61B3']]), 201, 'create client from package');
    check(Features::enabled($db, Util::bin($created['id'])) === ['productividad'], 'new client gets package functions');
    $admin = adminFixture($db, $u, ['festivos.ver'], false);
    check(adminRequest($admin, 'GET', '/features')[0] === 403, 'only platform manages functions');
    echo 'PASS feature admin (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `tests/smoke.php` agregar `featureAdminTests($db);` después de `featureEnforcementTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: la suite completa.
Expected: FAIL `catalog` (404: la ruta no existe).

- [ ] **Step 3a: Contract**

En `docs/architecture/openapi-v4.yaml`:
1. Antes de `  /organization:` agregar las rutas `/features`, `/feature-packages` y `/feature-packages/{id}`.
2. Antes de `  /tenants/{id}/logo:` agregar `/tenants/{id}/features`.

Cada operación copia la forma de `deleteCompanyGroup` (seguridad `adminSession` + `csrfToken` en escrituras, `x-tenant-boundary: Solo plataforma autenticada.`, respuestas 400/401/403/404/409/412/422/429/500/503, `x-permission: empresas.gestionar`, `x-platform-only: true`, `parameters: [Tenant]` más `Id` e `IfMatch` donde corresponda) con estas respuestas de éxito y cuerpos:

```yaml
    # GET /features -> 200 FeatureList ; GET /feature-packages -> 200 FeaturePackageList
    # POST /feature-packages (requestBody FeaturePackageInput) -> 201 FeaturePackage
    # PATCH /feature-packages/{id} (IfMatch, requestBody FeaturePackageInput) -> 200 FeaturePackage
    # GET /tenants/{id}/features -> 200 TenantFeatures ; PUT /tenants/{id}/features (requestBody TenantFeaturesInput) -> 200 TenantFeatures
```

Esquemas, antes de `    OrgUnit:`:

```yaml
    Feature:
      type: object
      additionalProperties: false
      required: [code, name, group, level, pending]
      properties:
        code: {type: string, maxLength: 40}
        name: {type: string, maxLength: 120}
        group: {type: string, maxLength: 80}
        level: {type: string, enum: [client, person]}
        pending: {type: boolean, description: Sin endpoints todavia; se puede vender pero aun no se aplica.}
    FeatureList:
      type: object
      additionalProperties: false
      required: [data]
      properties:
        data: {type: array, maxItems: 100, items: {$ref: '#/components/schemas/Feature'}}
    FeaturePackageInput:
      type: object
      additionalProperties: false
      required: [name, features]
      properties:
        name: {type: string, minLength: 1, maxLength: 80}
        features: {type: array, maxItems: 100, uniqueItems: true, items: {type: string, maxLength: 40}}
    FeaturePackage:
      type: object
      additionalProperties: false
      required: [id, name, features, tenants, version]
      properties:
        id: {type: string, format: uuid}
        name: {type: string, maxLength: 80}
        features: {type: array, maxItems: 100, items: {type: string, maxLength: 40}}
        tenants: {type: integer, minimum: 0}
        version: {type: integer, minimum: 1}
    FeaturePackageList:
      type: object
      additionalProperties: false
      required: [data]
      properties:
        data: {type: array, maxItems: 500, items: {$ref: '#/components/schemas/FeaturePackage'}}
    TenantFeaturesInput:
      type: object
      additionalProperties: false
      required: [package_id, features]
      properties:
        package_id: {anyOf: [{type: string, format: uuid}, {type: 'null'}]}
        features: {type: array, maxItems: 100, uniqueItems: true, items: {type: string, maxLength: 40}}
    TenantFeatures:
      type: object
      additionalProperties: false
      required: [package_id, package_name, features]
      properties:
        package_id: {anyOf: [{type: string, format: uuid}, {type: 'null'}]}
        package_name: {anyOf: [{type: string, maxLength: 80}, {type: 'null'}]}
        features: {type: array, maxItems: 100, items: {type: string, maxLength: 40}}
```

(El estilo en línea `{...}` es YAML válido y lo acepta el exportador. Las descripciones no pueden contener `: `, porque rompen el YAML.)

En `TenantCreate.properties` agregar `package_id` con `anyOf` uuid o null (no requerido).

En `backend-v4/tests/export-contract.py` agregar `"features", "feature-packages"` a `admin_roots`. Luego, desde `backend-v4`: `python tests/export-contract.py` → `Exported 137 paths ...`.

- [ ] **Step 3b: Implementation**

Crear `backend-v4/src/FeatureAdmin.php`:

```php
<?php
declare(strict_types=1);
namespace Keeper;

/** Administracion de funciones (solo plataforma): catalogo, paquetes y funciones de cada cliente. */
final class FeatureAdmin
{
    private string $platform;
    public function __construct(private Database $db, private array $c, private Request $r) { $this->platform = Util::bin(AdminAuth::PLATFORM); }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        return match ($op) {
            'listFeatures' => AdminApi::response(['data' => array_map(fn ($code) => ['code' => $code, 'name' => Features::CATALOG[$code]['name'], 'group' => Features::CATALOG[$code]['group'],
                'level' => Features::CATALOG[$code]['level'], 'pending' => Features::CATALOG[$code]['pending']], Features::codes())]),
            'listFeaturePackages' => AdminApi::response(['data' => array_map(fn ($p) => $this->packageDto($p), $this->db->run('SELECT * FROM feature_packages WHERE tenant_id=? ORDER BY name', [$this->platform])->fetchAll())]),
            'createFeaturePackage', 'patchFeaturePackage' => $this->savePackage($id, $b),
            'getTenantFeatures' => AdminApi::response($this->tenantDto($this->c['tenant_id'])),
            'putTenantFeatures' => $this->putTenant($b),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }
    /** @param list<string> $codes */
    private function valid(array $codes): array
    {
        foreach ($codes as $code) { if (!isset(Features::CATALOG[$code])) { throw new ApiError(422, 'validation_failed', [], "unknown_feature:$code"); } }
        $codes = array_values(array_unique($codes)); sort($codes); return $codes;
    }
    private function packageDto(array $p): array
    {
        $features = array_column($this->db->run('SELECT feature_code FROM feature_package_items WHERE tenant_id=? AND package_id=? ORDER BY feature_code', [$this->platform, $p['id']])->fetchAll(), 'feature_code');
        $tenants = (int) $this->db->one('SELECT COUNT(*) n FROM tenants WHERE feature_package_id=? AND deleted_at IS NULL', [$p['id']])['n'];
        return ['id' => Util::id($p['id']), 'name' => $p['name'], 'features' => $features, 'tenants' => $tenants, 'version' => (int) $p['version']];
    }
    private function savePackage(?string $id, object $b): array
    {
        $codes = $this->valid($b->features);
        if ($id !== null) {
            $p = $this->db->one('SELECT * FROM feature_packages WHERE tenant_id=? AND id=? FOR UPDATE', [$this->platform, $id]);
            if (!$p) { throw new ApiError(404, 'resource_not_found'); }
            if ($this->r->header('if-match') !== '"' . $p['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
            $this->db->run('UPDATE feature_packages SET name=?,version=version+1 WHERE tenant_id=? AND id=?', [$b->name, $this->platform, $id]);
            $this->db->run('DELETE FROM feature_package_items WHERE tenant_id=? AND package_id=?', [$this->platform, $id]);
        } else {
            if ($this->db->one('SELECT id FROM feature_packages WHERE tenant_id=? AND name=?', [$this->platform, $b->name])) { throw new ApiError(409, 'invalid_transition', [], 'duplicate_name'); }
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO feature_packages (tenant_id,id,name) VALUES (?,?,?)', [$this->platform, $id, $b->name]);
        }
        foreach ($codes as $code) { $this->db->run('INSERT INTO feature_package_items (tenant_id,package_id,feature_code) VALUES (?,?,?)', [$this->platform, $id, $code]); }
        $c = $this->c; $c['tenant_id'] = $this->platform; $c['principal_id'] = (new AdminAuth($this->db, new RateLimiter()))->principal($c);
        (new Audit($this->db))->record($c, $this->r, $this->r->operation['operationId'], 'feature_package', $id, ['name', 'features']);
        return AdminApi::response($this->packageDto($this->db->one('SELECT * FROM feature_packages WHERE tenant_id=? AND id=?', [$this->platform, $id])), $this->r->method === 'POST' ? 201 : 200);
    }
    public function tenantDto(string $tenant): array
    {
        $t = $this->db->one('SELECT feature_package_id FROM tenants WHERE tenant_id=?', [$tenant]);
        $name = $t['feature_package_id'] === null ? null : ($this->db->one('SELECT name FROM feature_packages WHERE tenant_id=? AND id=?', [$this->platform, $t['feature_package_id']])['name'] ?? null);
        return ['package_id' => $t['feature_package_id'] === null ? null : Util::id($t['feature_package_id']), 'package_name' => $name, 'features' => Features::enabled($this->db, $tenant)];
    }
    /** Funciones del cliente = paquete de origen + ajustes de la venta. Apagar no borra datos. */
    public function apply(string $tenant, ?string $package, array $features): void
    {
        $codes = $this->valid($features);
        if ($package !== null && !$this->db->one('SELECT id FROM feature_packages WHERE tenant_id=? AND id=?', [$this->platform, $package])) { throw new ApiError(422, 'validation_failed', [], 'unknown_package'); }
        $this->db->run('UPDATE tenants SET feature_package_tenant_id=?,feature_package_id=?,auth_version=auth_version+1,version=version+1 WHERE tenant_id=?', [$package === null ? null : $this->platform, $package, $tenant]);
        $this->db->run('DELETE FROM tenant_features WHERE tenant_id=?', [$tenant]);
        foreach ($codes as $code) { $this->db->run('INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,?)', [$tenant, $code]); }
    }
    private function putTenant(object $b): array
    {
        $this->apply($this->c['tenant_id'], $b->package_id === null ? null : Util::bin($b->package_id), $b->features);
        (new Audit($this->db))->record($this->c, $this->r, 'putTenantFeatures', 'tenant', $this->c['tenant_id'], ['package_id', 'features']);
        return AdminApi::response($this->tenantDto($this->c['tenant_id']));
    }
}
```

En `backend-v4/src/AdminApi.php`, en el `match` de `handle()`, junto a `'getMyAccess'=>...`:

```php
            'listFeatures','listFeaturePackages','createFeaturePackage','patchFeaturePackage','getTenantFeatures','putTenantFeatures'=>(new FeatureAdmin($this->db,$this->c,$this->r))->dispatch($op,$id,$b),
```

En `tenants()`, dentro de `if ($op==='createTenant') {`, después del bucle de `role_templates`:

```php
            // Funciones del cliente nuevo: las de su paquete (sin paquete, solo el nucleo).
            if (isset($b->package_id)) {
                $pkg=Util::bin($b->package_id);
                $codes=array_column($this->db->run('SELECT feature_code FROM feature_package_items WHERE tenant_id=? AND package_id=?',[Util::bin(AdminAuth::PLATFORM),$pkg])->fetchAll(),'feature_code');
                (new FeatureAdmin($this->db,$this->c,$this->r))->apply($id,$pkg,$codes);
            }
```

En `public/assets/k3.js`, en `AUDIT_ACTIONS`, junto a `createTenant`:

```js
  createFeaturePackage: 'Creó un paquete de funciones', patchFeaturePackage: 'Editó un paquete de funciones', putTenantFeatures: 'Cambió las funciones contratadas de un cliente',
```

- [ ] **Step 4: Run test to verify it passes**

Run: la suite completa.
Expected: `PASS feature admin (...)`, `PASS ops (...)` (guardia de auditoría en español) y `SMOKE PASS`.

- [ ] **Step 5: Commit**

```bash
git add backend-v4/src/FeatureAdmin.php backend-v4/src/AdminApi.php backend-v4/public/assets/k3.js backend-v4/tests/features.php backend-v4/tests/smoke.php backend-v4/tests/export-contract.py backend-v4/config/contract.json docs/architecture/openapi-v4.yaml
git commit -m "Funciones: API de plataforma para paquetes y funciones por cliente; crear cliente desde un paquete

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: El menú solo muestra lo contratado

**Files:**
- Modify: `backend-v4/src/AdminApi.php` (`myAccess()`)
- Modify: `docs/architecture/openapi-v4.yaml` (`MyAccess.features`), `backend-v4/config/contract.json` (generado)
- Modify: `backend-v4/tests/features.php`

**Interfaces:**
- Consumes: `Features::forOperation`, `Features::enabled`.
- Produces: `GET /me/access` → `{platform, permissions:[{code,scopes}], features:[codes]}`. Las cuentas de cliente no reciben permisos cuya función (por permiso) no esté contratada. `nav-access.js` no cambia: oculta por permiso.

- [ ] **Step 1: Write the failing test**

Agregar a `backend-v4/tests/features.php`:

```php
function featureMenuTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $admin = adminFixture($db, $u, ['festivos.ver', 'usuarios.ver'], false);
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='festivos'", [$tenant]);
    $mine = expect(adminRequest($admin, 'GET', '/me/access'), 200, 'my access');
    $codes = array_column($mine['permissions'], 'code');
    check(!in_array('festivos.ver', $codes, true) && in_array('usuarios.ver', $codes, true), 'menu loses uncontracted function');
    check(!in_array('festivos', $mine['features'], true) && in_array('usb', $mine['features'], true), 'features listed');
    echo 'PASS feature menu (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `tests/smoke.php` agregar `featureMenuTests($db);` después de `featureAdminTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: la suite completa.
Expected: FAIL (validación de respuesta: `features` no existe, o `menu loses uncontracted function`).

- [ ] **Step 3: Write the implementation**

En `openapi-v4.yaml`, esquema `MyAccess`:
- agregar `features` a `required`;
- agregar la propiedad `features` (array de string, maxLength 40, maxItems 100).

Luego correr `python tests/export-contract.py`.

En `AdminApi.php`, `myAccess()`:
- en la rama de superadmin, devolver `'features'=>Features::enabled($this->db,$this->tenant)`;
- en la rama de cliente, reemplazar el `return` final por:

```php
        $features=Features::enabled($this->db,$this->tenant);
        $codes=array_values(array_filter(array_keys($by),function ($code) use ($features) { $f=Features::forOperation('',$code); return $f===null || in_array($f,$features,true); }));
        return self::response(['platform'=>false,'features'=>$features,'permissions'=>array_map(fn($code)=>['code'=>$code,'scopes'=>array_keys($by[$code])],$codes)]);
```

y la rama de superadmin:

```php
            return self::response(['platform'=>true,'features'=>Features::enabled($this->db,$this->tenant),'permissions'=>$rows]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: la suite completa.
Expected: `PASS feature menu (...)`, `PASS my access (...)` y `SMOKE PASS`.

- [ ] **Step 5: Commit**

```bash
git add backend-v4/src/AdminApi.php backend-v4/tests/features.php backend-v4/tests/smoke.php backend-v4/config/contract.json docs/architecture/openapi-v4.yaml
git commit -m "Funciones: /me/access solo da permisos de funciones contratadas; el menú oculta el resto

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Pantalla de superadmin: funciones del cliente y paquetes

**Files:**
- Modify: `backend-v4/public/assets/k3.js` (función `tenantsPage`)

**Interfaces:**
- Consumes: los endpoints de la Task 4.

- [ ] **Step 1: Implementation**

En `tenantsPage()` de `k3.js`:

1. Cargar al inicio:
   ```js
   const catalog = (await api('GET', '/features', { tenant: PLATFORM_TENANT })).data;
   let packages = (await api('GET', '/feature-packages', { tenant: PLATFORM_TENANT })).data;
   ```
2. En la columna Acciones de cada cliente no archivado (`c.id !== PLATFORM_TENANT`) agregar un botón «Funciones» que llame a `featuresEditor(c)`.
3. Agregar `renderPackages()`, llamada al final de `render()` después de `renderGroups()`, y las funciones:

```js
  // Funciones contratadas del cliente: paquete de origen + ajustes de la venta (docs/architecture/v4-clientes-funciones-accesos.md).
  const featureChecks = (parent, selected) => {
    const boxes = [];
    for (const group of [...new Set(catalog.map(f => f.group))]) {
      const fs = el('fieldset', undefined, 'perm-group'); fs.append(el('legend', group)); parent.append(fs);
      boxes.push(...checks(fs, catalog.filter(f => f.group === group).map(f => [f.code, f.name, `${f.level === 'person' ? 'Por persona' : 'Del cliente'}${f.pending ? ' · aún sin pantalla' : ''}`]), selected));
    }
    return boxes;
  };
  const featuresEditor = async c => {
    const current = await api('GET', `/tenants/${c.id}/features`, { tenant: c.id });
    const { dialog, body, ok, show } = dialogShell(`Funciones de ${c.branding?.display_name || c.name}`);
    dialog.classList.add('wide-dialog');
    body.append(el('p', 'Lo que el cliente compró. Lo que no esté marcado desaparece de su panel y la API lo rechaza; apagar no borra datos. El núcleo (organización, horarios, personas, equipos) siempre está incluido.', 'help'));
    const pkg = el('select'); pkg.append(new Option('Sin paquete (a medida)', '')); packages.forEach(p => pkg.append(new Option(p.name, p.id))); pkg.value = current.package_id || '';
    labeled(body, 'Paquete de origen', pkg, 'Al elegir un paquete se marcan sus funciones; después puedes ajustar las de esta venta.');
    const boxes = featureChecks(body, current.features);
    pkg.addEventListener('change', () => { const p = packages.find(x => x.id === pkg.value); if (p) boxes.forEach(b => { b.checked = p.features.includes(b.value); }); });
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); ok.disabled = true;
      try { await api('PUT', `/tenants/${c.id}/features`, { tenant: c.id, body: { package_id: pkg.value || null, features: boxes.filter(b => b.checked).map(b => b.value) } }); dialog.close(); done(); }
      catch (e) { show(e); ok.disabled = false; }
    });
    dialog.showModal();
  };
  const renderPackages = () => {
    const s = surface('Paquetes de funciones');
    s.append(el('p', 'Atajos para vender: un paquete marca funciones de una vez. Cambiar un paquete no cambia a los clientes que ya lo usan.', 'help'));
    const add = el('button', 'Crear paquete'); add.type = 'button'; add.addEventListener('click', () => packageEditor(null)); s.append(add);
    if (!packages.length) { s.append(el('p', 'Aún no hay paquetes.', 'help')); return; }
    table(s, 'Paquetes', [['Paquete', p => p.name], ['Funciones', p => String(p.features.length)], ['Clientes', p => String(p.tenants)],
      ['', p => { const b = el('button', 'Editar', 'link-button'); b.type = 'button'; b.addEventListener('click', () => packageEditor(p)); return b; }]], packages);
  };
  const packageEditor = p => {
    const { dialog, body, ok, show } = dialogShell(p ? `Editar ${p.name}` : 'Crear paquete');
    dialog.classList.add('wide-dialog');
    const name = labeled(body, 'Nombre', el('input')); name.maxLength = 80; name.value = p?.name || '';
    const boxes = featureChecks(body, p?.features || []);
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault(); if (!name.value.trim()) { show('Escribe el nombre.'); return; }
      ok.disabled = true;
      const payload = { name: name.value.trim(), features: boxes.filter(b => b.checked).map(b => b.value) };
      try {
        if (p) await api('PATCH', `/feature-packages/${p.id}`, { tenant: PLATFORM_TENANT, body: payload, ifMatch: p.version }); else await api('POST', '/feature-packages', { tenant: PLATFORM_TENANT, body: payload });
        packages = (await api('GET', '/feature-packages', { tenant: PLATFORM_TENANT })).data; dialog.close(); render(); done();
      } catch (e) { show(e.problem?.detail === 'duplicate_name' ? 'Ya hay un paquete con ese nombre.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
```

4. En el diálogo «Crear empresa» del mismo archivo, agregar un select «Paquete» con las mismas opciones y enviar `package_id: pkg.value || null` en el cuerpo de `POST /tenants`.

- [ ] **Step 2: Verify**

Run: `node --check backend-v4/public/assets/k3.js` → sin salida. Luego la suite completa → `SMOKE PASS` (la guardia de `allPages` y la de auditoría leen `k3.js`).

- [ ] **Step 3: Commit**

```bash
git add backend-v4/public/assets/k3.js
git commit -m "Funciones: en Empresas, funciones contratadas por cliente y paquetes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Despliegue a producción (devkeep)

**Files:** ninguno nuevo.

- [ ] **Step 1: Respaldo**

```bash
cd /c/Users/FumiWork/Documents/AZCKeeper && TS=$(date +%Y%m%d-%H%M%S) && ssh azc-root "mkdir -p /home/keeper/deploy-backups/$TS && mariadb-dump --single-transaction --triggers keeper_v4 | gzip > /home/keeper/deploy-backups/$TS/keeper_v4-pre0040.sql.gz && chown -R keeper:keeper /home/keeper/deploy-backups/$TS && stat -c %s /home/keeper/deploy-backups/$TS/keeper_v4-pre0040.sql.gz"
```

Expected: un tamaño en bytes mayor que 100000.

- [ ] **Step 2: Desplegar archivos y migrar**

```bash
F="config/contract.json src/Features.php src/FeatureAdmin.php src/AdminAccess.php src/AdminApi.php src/ExternalAuth.php public/assets/k3.js public/assets/api.js migrations/0040_features.sql"
git archive HEAD $(for f in $F; do echo backend-v4/$f; done) | ssh azc-root "mkdir -p /home/keeper/tmp/deploy-$TS && tar -x -C /home/keeper/tmp/deploy-$TS"
ssh azc-root "bash -s $TS $F" < artifacts/deploy-files.sh
ssh azc-root "cd /home/keeper/web/devkeep.azclegal.com/public_html && runuser -u keeper -- php config/migrate.php 2>&1 | tail -1"
```

Expected: `desplegado ...` y `APPLIED 0040_features.sql`.

- [ ] **Step 3: Verificar**

```bash
ssh azc-root 'mariadb -N keeper_v4 -e "SELECT t.name, COUNT(f.feature_code) FROM tenants t LEFT JOIN tenant_features f ON f.tenant_id=t.tenant_id WHERE t.deleted_at IS NULL GROUP BY t.tenant_id"; grep -h "failure=" /var/log/apache2/domains/devkeep.azclegal.com.error.log | tail -3'
```

Expected: cada cliente (salvo AZC Keeper) con 23 funciones; sin `failure=` nuevos posteriores al despliegue.

- [ ] **Step 4: Memoria (persistencia dual)**

Agregar una línea a `C:\Users\FumiWork\.claude\projects\C--\memory\azckeeper-v4-requisitos.md` y a `C:\Users\FumiWork\memoryClaude\memory\azckeeper-v4-requisitos.md` con: commits, migración 0040, conteo de aserciones y estado (fase 1 hecha; siguiente: fase 2, planes de plataforma por persona y agente).
