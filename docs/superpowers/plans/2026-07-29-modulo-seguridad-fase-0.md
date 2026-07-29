# Módulo de Seguridad — Fase 0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar el backend y el cliente de AZCKeeper listos para el Módulo de Seguridad, y entregar auditoría de solo-lectura del estado de políticas de la flota, sin tocar ningún equipo ni aplicar ningún bloqueo.

**Architecture:** Cinco cambios independientes. En backend se corrige el merge de políticas (bloqueante), se expone la composición de scopes, y se agrega tabla y endpoint para recibir el estado de seguridad. En el cliente se retira el stack PAC muerto y se agrega un lector de solo-lectura de `HKLM\SOFTWARE\Policies` que reporta qué controles existen hoy en cada equipo. Nada escribe en el registro: el componente que aplica políticas (`AZCKeeperAgent`) es Fase 1.

**Tech Stack:** PHP 8 (sin autoloader, `require_once` en `bootstrap.php`), MySQL 8.0.40, C# .NET 8 WinForms (`net8.0-windows`), xUnit 2.9.2.

**Spec:** `docs/superpowers/specs/2026-07-29-modulo-seguridad-design.md`

## Global Constraints

- Rama de trabajo: `DevLinux`. Es la fuente de verdad y el default del repo en GitHub.
- **El código PHP debe correr en PHP 8.0.** No usar `array_is_list()` (requiere 8.1). La versión de producción no está confirmada, así que se asume el piso más bajo.
- El backend PHP **no tiene composer ni autoloader**. Toda clase nueva requiere su `require_once` en `AZCKeeper_Client/Web/src/bootstrap.php`.
- MySQL de producción es 8.0.40 en hosting compartido. `innodb_buffer_pool_size` y demás variables **no son tuneables**.
- **Ningún cambio de esta fase toca un equipo de la flota ni escribe en el registro de Windows.**
- Regla del proyecto vigente desde 2026-05-08: cada parche requiere prueba de escritorio que confirme flujo y datos enviados/recibidos. Ningún hallazgo se cierra sin esa verificación.
- Compilación del cliente: `dotnet build AZCKeeper.sln` en Windows. Tests: `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj`.
- Commit por tarea completada, no acumular.
- **Rama de trabajo: `feature/modulo-seguridad`.** NO `DevLinux`. Este trabajo no va a producción hasta
  que estén todas las correcciones (saneamiento del núcleo y cambios del panel web).
- **`4.0.0.0` es el número objetivo del release final, NO de este parche.** Se reserva para cuando el
  conjunto completo esté listo. La versión vigente en producción es **3.0.3.2**.
- El único build que sale de esta fase es un **demo interno**, para el equipo de Koichi y dos equipos más.
  No se publica a la flota.

## Fuera de alcance de esta fase

- **Mover la instalación a `%ProgramFiles%`.** El spec la listaba en Fase 0; es incorrecto. `install.bat:22-25` instala en `%LOCALAPPDATA%\AZCKeeper\app` y `AZCKeeperUpdater` copia ahí sin privilegio. Mover a `%ProgramFiles%` rompe el auto-update de los 251 equipos hasta que exista `AZCKeeperAgent`. **Se traslada a Fase 1.**
- `UpdateManager.LastCriticalFlag` (minor de `progress.md`). Requiere diagnóstico previo que no se ha hecho; no se incluye para no meter un paso sin contenido verificado.
- Los demás minors de `progress.md` (xUnit1031 en `LocalPacServerTests`, fugas de `TcpListener`, `ReadTimeout`, getters sin lock, `UpdatePac` dead code, `SaveCacheToDisk` no atómico) **se resuelven por eliminación en la Tarea 5**, al borrarse los archivos que los contienen.

---

## File Structure

| Archivo | Responsabilidad | Tarea |
|---|---|---|
| `AZCKeeper_Client/Web/tests/run.php` | Runner de tests PHP sin dependencias | 1 |
| `AZCKeeper_Client/Web/tests/PolicyServiceTest.php` | Tests del merge de políticas | 1 |
| `AZCKeeper_Client/Web/src/PolicyService.php` | Merge de políticas (fix) | 1 |
| `AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php` | Exponer composición de scopes | 2 |
| `AZCKeeper_Client/Web/migrations/keeper_security_state.sql` | Tabla de estado reportado | 3 |
| `AZCKeeper_Client/Web/src/Repos/SecurityStateRepo.php` | Persistencia del estado | 4 |
| `AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php` | Endpoint de ingesta | 4 |
| `AZCKeeper_Client/Blocking/WebBlockingManager.cs` | Cache de política + limpieza de PAC legacy | 5 |
| `AZCKeeper_Client/Blocking/SystemProxyManager.cs` | Solo limpieza de PAC legacy | 5 |
| `AZCKeeper_Client/Security/SecurityControls.cs` | Catálogo y evaluación (puro, testeable) | 6 |
| `AZCKeeper_Client/Security/SecurityStateReader.cs` | Lectura de HKLM | 6 |
| `AZCKeeper.Tests/SecurityControlsTests.cs` | Tests del evaluador | 6 |
| `AZCKeeper_Client/Core/CoreService.cs` | Envío del reporte tras handshake | 7 |

---

## Task 1: Fix del merge de políticas

Es el cambio bloqueante. Sin él, cualquier política por persona hereda restos de la global.

**Files:**
- Create: `AZCKeeper_Client/Web/tests/run.php`
- Create: `AZCKeeper_Client/Web/tests/PolicyServiceTest.php`
- Modify: `AZCKeeper_Client/Web/src/PolicyService.php:5-14`

**Interfaces:**
- Consumes: nada.
- Produces: `Keeper\PolicyService::deepMerge(array $base, array $override): array` — firma sin cambios; cambia el comportamiento con listas.

- [ ] **Step 1: Crear el runner de tests**

No hay composer ni PHPUnit en este proyecto. Este runner es intencionalmente mínimo.

Crear `AZCKeeper_Client/Web/tests/run.php`:

```php
<?php
/**
 * Runner de tests sin dependencias. El backend no tiene composer.
 * Uso: php AZCKeeper_Client/Web/tests/run.php
 */

$GLOBALS['__tests_passed'] = 0;
$GLOBALS['__tests_failed'] = 0;

function assertSame_($expected, $actual, string $label): void {
    if ($expected === $actual) {
        $GLOBALS['__tests_passed']++;
        echo "  PASS  {$label}\n";
        return;
    }
    $GLOBALS['__tests_failed']++;
    echo "  FAIL  {$label}\n";
    echo "        esperado: " . json_encode($expected, JSON_UNESCAPED_SLASHES) . "\n";
    echo "        obtenido: " . json_encode($actual, JSON_UNESCAPED_SLASHES) . "\n";
}

function suite(string $name): void {
    echo "\n{$name}\n";
}

require_once __DIR__ . '/../src/PolicyService.php';
require_once __DIR__ . '/PolicyServiceTest.php';

echo "\n----------------------------------------\n";
echo "PASS: {$GLOBALS['__tests_passed']}  FAIL: {$GLOBALS['__tests_failed']}\n";
exit($GLOBALS['__tests_failed'] > 0 ? 1 : 0);
```

- [ ] **Step 2: Escribir los tests que fallan**

Crear `AZCKeeper_Client/Web/tests/PolicyServiceTest.php`:

```php
<?php
use Keeper\PolicyService;

suite('PolicyService::deepMerge');

// El bug: las listas se combinaban por índice numérico y heredaban la cola de la base.
assertSame_(
    ['domains' => ['solo-este.com']],
    PolicyService::deepMerge(
        ['domains' => ['facebook.com', 'instagram.com', 'x.com']],
        ['domains' => ['solo-este.com']]
    ),
    'lista mas corta reemplaza completa, no hereda cola'
);

assertSame_(
    ['domains' => []],
    PolicyService::deepMerge(
        ['domains' => ['facebook.com', 'x.com']],
        ['domains' => []]
    ),
    'lista vacia vacia la lista base'
);

assertSame_(
    ['domains' => ['a.com', 'b.com', 'c.com']],
    PolicyService::deepMerge(
        ['domains' => ['a.com']],
        ['domains' => ['a.com', 'b.com', 'c.com']]
    ),
    'lista mas larga reemplaza completa'
);

// Los mapas asociativos SI deben mergearse en profundidad.
assertSame_(
    ['webBlocking' => ['enabled' => true, 'syncIntervalSeconds' => 600]],
    PolicyService::deepMerge(
        ['webBlocking' => ['enabled' => false, 'syncIntervalSeconds' => 600]],
        ['webBlocking' => ['enabled' => true]]
    ),
    'mapa anidado se mergea en profundidad'
);

assertSame_(
    ['enabled' => true],
    PolicyService::deepMerge(['enabled' => false], ['enabled' => true]),
    'escalar sobrescribe'
);

assertSame_(
    ['a' => 1, 'b' => 2],
    PolicyService::deepMerge(['a' => 1], ['b' => 2]),
    'clave nueva se agrega'
);

// Caso realista completo: politica de usuario sobre global.
assertSame_(
    [
        'webBlocking' => [
            'enabled' => true,
            'syncIntervalSeconds' => 300,
            'domains' => ['facebook.com'],
        ],
        'blocking' => ['enableDeviceLock' => false],
    ],
    PolicyService::deepMerge(
        [
            'webBlocking' => [
                'enabled' => true,
                'syncIntervalSeconds' => 300,
                'domains' => ['facebook.com', 'instagram.com', 'x.com', 'netflix.com'],
            ],
            'blocking' => ['enableDeviceLock' => false],
        ],
        [
            'webBlocking' => ['domains' => ['facebook.com']],
        ]
    ),
    'politica de usuario acorta dominios sin tocar el resto'
);
```

- [ ] **Step 3: Correr los tests y verificar que fallan**

```bash
php AZCKeeper_Client/Web/tests/run.php
```

Esperado: FAIL en `lista mas corta reemplaza completa, no hereda cola` con
`obtenido: {"domains":["solo-este.com","instagram.com","x.com"]}`, FAIL en `lista vacia vacia la lista base`, y FAIL en `politica de usuario acorta dominios sin tocar el resto`. Exit code 1.

- [ ] **Step 4: Aplicar el fix**

Reemplazar el contenido completo de `AZCKeeper_Client/Web/src/PolicyService.php`:

```php
<?php
namespace Keeper;

class PolicyService {
  /**
   * Merge de politicas por scope (global -> user -> device).
   *
   * Los mapas asociativos se mergean en profundidad. Las LISTAS se reemplazan
   * completas: antes se combinaban por indice numerico, asi que una politica de
   * usuario con lista mas corta heredaba en silencio la cola de la global.
   */
  public static function deepMerge(array $base, array $override): array {
    foreach ($override as $k => $v) {
      if (is_array($v) && isset($base[$k]) && is_array($base[$k])
          && !self::isList($v) && !self::isList($base[$k])) {
        $base[$k] = self::deepMerge($base[$k], $v);
      } else {
        $base[$k] = $v;
      }
    }
    return $base;
  }

  /** array_is_list() es PHP 8.1+; produccion puede ser 8.0. */
  private static function isList(array $a): bool {
    return $a === [] || $a === array_values($a);
  }
}
```

- [ ] **Step 5: Correr los tests y verificar que pasan**

```bash
php AZCKeeper_Client/Web/tests/run.php
```

Esperado: `PASS: 7  FAIL: 0`. Exit code 0.

- [ ] **Step 6: Commit**

```bash
git add AZCKeeper_Client/Web/src/PolicyService.php AZCKeeper_Client/Web/tests/
git commit -m "fix(policy): las listas se reemplazan en el merge, no se mezclan por indice

deepMerge combinaba listas por indice numerico, asi que una politica de
usuario con lista mas corta heredaba la cola de la global y no habia forma
de acortar ni vaciar una lista desde un scope inferior. Bloqueante para el
modelo de asignacion por persona del Modulo de Seguridad.

Agrega runner de tests PHP sin dependencias (el backend no tiene composer)."
```

---

## Task 2: Exponer la composición de scopes en el handshake

`policyApplied.scope` reporta solo el scope ganador. Para auditar por persona hace falta saber qué capas se aplicaron.

**Files:**
- Modify: `AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:92-115` y `:135-139`

**Interfaces:**
- Consumes: `PolicyService::deepMerge()` de la Tarea 1.
- Produces: campo `policyApplied.composition` en la respuesta del handshake — array de objetos `{scope: string, policyId: int, version: int}` en orden de aplicación. Los campos `scope`, `policyId` y `version` existentes se conservan sin cambios para no romper clientes desplegados.

- [ ] **Step 1: Acumular la composición**

En `AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php`, reemplazar el bloque que va desde `$effective = json_decode(...)` hasta el cierre del `if ($policies['device'])`:

```php
    $effective      = json_decode($global['policy_json'], true) ?? [];
    $appliedScope   = 'global';
    $appliedId      = (int)$global['id'];
    $appliedVersion = (int)$global['version'];

    $composition = [[
      'scope'    => 'global',
      'policyId' => (int)$global['id'],
      'version'  => (int)$global['version'],
    ]];

    if ($policies['user']) {
      $u = json_decode($policies['user']['policy_json'], true);
      if (is_array($u)) {
        $effective      = PolicyService::deepMerge($effective, $u);
        $appliedScope   = 'user';
        $appliedId      = (int)$policies['user']['id'];
        $appliedVersion = (int)$policies['user']['version'];
        $composition[]  = [
          'scope'    => 'user',
          'policyId' => (int)$policies['user']['id'],
          'version'  => (int)$policies['user']['version'],
        ];
      }
    }

    if ($policies['device']) {
      $d = json_decode($policies['device']['policy_json'], true);
      if (is_array($d)) {
        $effective      = PolicyService::deepMerge($effective, $d);
        $appliedScope   = 'device';
        $appliedId      = (int)$policies['device']['id'];
        $appliedVersion = (int)$policies['device']['version'];
        $composition[]  = [
          'scope'    => 'device',
          'policyId' => (int)$policies['device']['id'],
          'version'  => (int)$policies['device']['version'],
        ];
      }
    }
```

- [ ] **Step 2: Incluirla en la respuesta**

En el mismo archivo, reemplazar el bloque `'policyApplied' => [...]`:

```php
      'policyApplied' => [
        'scope'       => $appliedScope,
        'policyId'    => $appliedId,
        'version'     => $appliedVersion,
        'composition' => $composition,
      ],
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php
```

Esperado: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php
git commit -m "feat(handshake): exponer la composicion de scopes en policyApplied

policyApplied.scope solo reportaba el scope ganador. Se agrega composition
con las capas efectivamente aplicadas y su version, para poder auditar por
persona que politica la cubre. Los campos existentes se conservan."
```

> **Prueba de escritorio requerida antes de dar por cerrada esta tarea:** golpear `/client/handshake` contra DEV con un usuario que tenga política de usuario y confirmar que `composition` trae dos entradas con sus `policyId` reales.

---

## Task 3: Tabla de estado de seguridad

**Files:**
- Create: `AZCKeeper_Client/Web/migrations/keeper_security_state.sql`

**Interfaces:**
- Produces: tabla `keeper_security_state`, una fila por `device_id` (estado actual, no histórico). Columnas que consumen las Tareas 4 y 7: `user_id`, `device_id`, `reported_at`, `agent_present`, `controls_json`, `controls_hash`.

- [ ] **Step 1: Escribir la migración**

Crear `AZCKeeper_Client/Web/migrations/keeper_security_state.sql`:

```sql
-- Modulo de Seguridad — estado de controles reportado por cada equipo.
-- Una fila por dispositivo (estado ACTUAL, no historico): el reporte llega en
-- cada handshake y se hace UPSERT. El historico se agrega si se necesita, no antes.

CREATE TABLE IF NOT EXISTS keeper_security_state (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT             NOT NULL,
  device_id      INT             NOT NULL,
  reported_at    DATETIME        NOT NULL,
  agent_present  TINYINT(1)      NOT NULL DEFAULT 0,
  controls_json  LONGTEXT        NOT NULL,
  controls_hash  CHAR(64)        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_security_state_device (device_id),
  KEY ix_security_state_reported (reported_at),
  KEY ix_security_state_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`agent_present` queda en 0 durante toda la Fase 0: el servicio `AZCKeeperAgent` llega en Fase 1. La columna existe desde ya para no migrar dos veces.

- [ ] **Step 2: Verificar la sintaxis SQL en local**

Si no hay MySQL local, revisar visualmente y aplicar en DEV. La verificación real es el Step 3.

- [ ] **Step 3: Aplicar en DEV y confirmar**

Aplicar la migración en el entorno DEV y confirmar con:

```sql
DESCRIBE keeper_security_state;
```

Esperado: 7 columnas, `uq_security_state_device` como UNIQUE sobre `device_id`.

- [ ] **Step 4: Commit**

```bash
git add AZCKeeper_Client/Web/migrations/keeper_security_state.sql
git commit -m "feat(db): tabla keeper_security_state para el Modulo de Seguridad

Una fila por dispositivo con el estado de controles reportado. UPSERT por
device_id. agent_present queda en 0 hasta Fase 1."
```

---

## Task 4: Endpoint de ingesta del estado

**Files:**
- Create: `AZCKeeper_Client/Web/src/Repos/SecurityStateRepo.php`
- Create: `AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php`
- Modify: `AZCKeeper_Client/Web/src/bootstrap.php`
- Modify: `AZCKeeper_Client/Web/public/index.php:50-62`

**Interfaces:**
- Consumes: tabla `keeper_security_state` de la Tarea 3.
- Produces: ruta `POST /api/client/security/report`. Payload: `{deviceId: string(guid), agentPresent: bool, controls: object}`. Respuesta 200: `{ok: true, changed: bool}`. La Tarea 7 consume este contrato desde el cliente.

- [ ] **Step 1: Crear el repositorio**

Crear `AZCKeeper_Client/Web/src/Repos/SecurityStateRepo.php`:

```php
<?php
namespace Keeper\Repos;

use PDO;

class SecurityStateRepo {

  /**
   * UPSERT del estado de controles de un dispositivo.
   * Devuelve true si el hash cambio respecto al ultimo reporte.
   */
  public static function upsert(PDO $pdo, int $userId, int $deviceId, bool $agentPresent, array $controls): bool {
    $json = json_encode($controls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $hash = hash('sha256', $json);

    $st = $pdo->prepare("SELECT controls_hash FROM keeper_security_state WHERE device_id = :d LIMIT 1");
    $st->execute([':d' => $deviceId]);
    $previous = $st->fetchColumn();

    $changed = ($previous === false) || ($previous !== $hash);

    $st = $pdo->prepare("
      INSERT INTO keeper_security_state
        (user_id, device_id, reported_at, agent_present, controls_json, controls_hash)
      VALUES
        (:u, :d, NOW(), :a, :j, :h)
      ON DUPLICATE KEY UPDATE
        user_id       = VALUES(user_id),
        reported_at   = VALUES(reported_at),
        agent_present = VALUES(agent_present),
        controls_json = VALUES(controls_json),
        controls_hash = VALUES(controls_hash)
    ");
    $st->execute([
      ':u' => $userId,
      ':d' => $deviceId,
      ':a' => $agentPresent ? 1 : 0,
      ':j' => $json,
      ':h' => $hash,
    ]);

    return $changed;
  }
}
```

- [ ] **Step 2: Crear el endpoint**

Crear `AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php`:

```php
<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\AuthService;
use Keeper\Repos\SecurityStateRepo;

/**
 * SecurityReport — recibe el estado de controles de seguridad observado en el equipo.
 *
 * Payload:
 *   {
 *     "deviceId": "<guid>",
 *     "agentPresent": false,
 *     "controls": { "<clave>": { "present": bool, "value": <int|string|null> }, ... }
 *   }
 *
 * En Fase 0 el cliente solo LEE el registro; no aplica nada. agentPresent viaja
 * en false hasta que exista AZCKeeperAgent (Fase 1).
 */
class SecurityReport
{
    private const MAX_CONTROLS = 100;

    public static function handle(): void
    {
        $sess   = AuthService::requireSession();
        $userId = (int)$sess['user_id'];

        $data = Http::jsonInput();

        $deviceGuid   = $data['deviceId']     ?? ($data['DeviceId']     ?? null);
        $agentPresent = $data['agentPresent'] ?? ($data['AgentPresent'] ?? false);
        $controls     = $data['controls']     ?? ($data['Controls']     ?? null);

        if (!$deviceGuid) {
            Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        }
        if (!is_array($controls)) {
            Http::json(400, ['ok' => false, 'error' => 'Missing or invalid controls object']);
        }
        if (count($controls) > self::MAX_CONTROLS) {
            Http::json(413, ['ok' => false, 'error' => 'Too many controls (max ' . self::MAX_CONTROLS . ')']);
        }

        $pdo = Db::pdo();

        $st = $pdo->prepare("SELECT id, user_id, status FROM keeper_devices WHERE device_guid = :g LIMIT 1");
        $st->execute([':g' => $deviceGuid]);
        $dev = $st->fetch();

        if (!$dev) {
            Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        }
        if ((int)$dev['user_id'] !== $userId) {
            Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        }
        if (($dev['status'] ?? 'active') !== 'active') {
            Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        }

        try {
            $changed = SecurityStateRepo::upsert(
                $pdo,
                $userId,
                (int)$dev['id'],
                (bool)$agentPresent,
                $controls
            );
            Http::json(200, ['ok' => true, 'changed' => $changed]);
        } catch (\PDOException $e) {
            error_log("SecurityReport UPSERT error: " . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database write failed']);
        }
    }
}
```

- [ ] **Step 3: Registrar en bootstrap**

En `AZCKeeper_Client/Web/src/bootstrap.php`, agregar junto a los otros `Repos`:

```php
require_once __DIR__ . '/Repos/SecurityStateRepo.php';
```

Y junto a los otros `Endpoints`:

```php
require_once __DIR__ . '/Endpoints/SecurityReport.php';
```

Sin esto, la ruta produce un fatal error de clase no encontrada. Es el bug latente que ya ocurrió con `WindowEpisodeBatch` y `ClientReEnroll`.

- [ ] **Step 4: Registrar la ruta**

En `AZCKeeper_Client/Web/public/index.php`, dentro del array `'POST' => [`, agregar después de la línea de `/client/re-enroll`:

```php
    '/client/security/report' => [Keeper\Endpoints\SecurityReport::class, 'handle'],
```

- [ ] **Step 5: Verificar sintaxis**

```bash
php -l AZCKeeper_Client/Web/src/Repos/SecurityStateRepo.php
php -l AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php
php -l AZCKeeper_Client/Web/public/index.php
```

Esperado: `No syntax errors detected` en los tres.

- [ ] **Step 6: Commit**

```bash
git add AZCKeeper_Client/Web/src/Repos/SecurityStateRepo.php \
        AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php \
        AZCKeeper_Client/Web/src/bootstrap.php \
        AZCKeeper_Client/Web/public/index.php
git commit -m "feat(api): endpoint POST /client/security/report

Recibe el estado de controles observado en el equipo y hace UPSERT en
keeper_security_state. Devuelve changed=true cuando el hash difiere del
ultimo reporte. En Fase 0 el cliente solo lee el registro."
```

> **Prueba de escritorio requerida:** POST contra DEV con un `deviceId` real y un objeto `controls` de dos claves. Confirmar 200 con `changed:true`, repetir el mismo payload y confirmar `changed:false`, y verificar la fila en `keeper_security_state`.

---

## Task 5: Retirar el stack PAC

Tres arquitecturas de web-blocking sin privilegio han fallado. El PAC además falla abierto. Se retira el mecanismo pero **se conserva la limpieza**, porque hay equipos con `AutoConfigURL` configurado que hay que dejar limpios.

**Files:**
- Delete: `AZCKeeper_Client/Blocking/LocalPacServer.cs`
- Delete: `AZCKeeper_Client/Blocking/PacContentBuilder.cs`
- Delete: `AZCKeeper.Tests/LocalPacServerTests.cs`
- Delete: `AZCKeeper.Tests/PacContentBuilderTests.cs`
- Delete: `AZCKeeper.Tests/PacContentBuilderPacJsTests.cs`
- Modify: `AZCKeeper_Client/Blocking/WebBlockingManager.cs` (reescritura completa)
- Modify: `AZCKeeper_Client/Core/CoreService.cs:798-800`
- Modify: `AZCKeeper.Tests/AZCKeeper.Tests.csproj`

**Interfaces:**
- Consumes: `SystemProxyManager.Restore()` y `SystemProxyManager.MigrateAwayFromPac()`, que ya existen.
- Produces: `WebBlockingManager` conserva `Initialize()`, `ApplyRemotePolicy()`, `Shutdown()`, `GetCachedDomains()`, `Enabled`, `DomainCount`. **Deja de exponer `PacActive` y `PacPort`.**

- [ ] **Step 1: Reescribir WebBlockingManager**

Reemplazar el contenido completo de `AZCKeeper_Client/Blocking/WebBlockingManager.cs`:

```csharp
using System;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AZCKeeper_Cliente.Config;
using AZCKeeper_Cliente.Logging;
using Microsoft.Win32;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Cache de la politica de dominios recibida del backend.
    ///
    /// NO aplica bloqueo. Tres arquitecturas sin privilegio fallaron (hosts+proxy,
    /// URLBlocklist en HKCU, PAC blackhole) y el PAC ademas falla ABIERTO: si el
    /// servidor local no responde, WinInet navega directo sin avisar. El enforcement
    /// pasa a AZCKeeperAgent (servicio elevado, Fase 1) via HKLM\SOFTWARE\Policies.
    ///
    /// Esta clase conserva la politica para el reporte de estado y limpia los
    /// residuos que dejaron los intentos anteriores en los equipos ya desplegados.
    /// </summary>
    internal sealed class WebBlockingManager
    {
        private readonly string _cacheDirectory;
        private readonly string _cacheFilePath;
        private readonly SystemProxyManager _systemProxy;

        private WebBlockingCache _currentCache;
        private readonly object _applyLock = new object();

        public bool Enabled => _currentCache?.Enabled == true;
        public int DomainCount => _currentCache?.Domains?.Length ?? 0;

        public WebBlockingManager()
        {
            string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            _cacheDirectory = Path.Combine(appData, "AZCKeeper", "Cache");
            _cacheFilePath = Path.Combine(_cacheDirectory, "web_block_cache.json");
            _systemProxy = new SystemProxyManager(_cacheDirectory);
        }

        public void Initialize(ConfigManager.WebBlockingConfig config, string apiBaseUrl)
        {
            lock (_applyLock)
            {
                // Limpieza de residuos de los intentos previos en equipos ya desplegados.
                CleanupLegacyUrlBlocklist();
                try { _systemProxy.MigrateAwayFromPac(); } catch { }

                _currentCache = LoadCacheFromDisk() ?? BuildCache(config, config?.PolicyVersion ?? 0);
                LocalLogger.Info($"WebBlockingManager: politica cacheada. Enabled={_currentCache.Enabled}, Domains={_currentCache.Domains.Length}. Enforcement delegado a AZCKeeperAgent.");
            }
        }

        public void ApplyRemotePolicy(ConfigManager.WebBlockingConfig config, int policyVersion, string apiBaseUrl)
        {
            lock (_applyLock)
            {
                var next = BuildCache(config, policyVersion);

                bool unchanged = _currentCache != null &&
                    _currentCache.PolicyVersion == next.PolicyVersion &&
                    string.Equals(_currentCache.DomainsHash ?? "", next.DomainsHash ?? "", StringComparison.OrdinalIgnoreCase) &&
                    _currentCache.Enabled == next.Enabled;

                if (unchanged) return;

                SaveCacheToDisk(next);
                _currentCache = next;
                LocalLogger.Info($"WebBlockingManager: politica actualizada. Version={next.PolicyVersion}, Domains={next.Domains.Length}");
            }
        }

        public string[] GetCachedDomains() => _currentCache?.Domains ?? Array.Empty<string>();

        public void Shutdown()
        {
            // Ya no hay PAC ni servidor local que detener.
        }

        private static void CleanupLegacyUrlBlocklist()
        {
            string[] roots =
            {
                @"SOFTWARE\Policies\Google\Chrome\URLBlocklist",
                @"SOFTWARE\Policies\Microsoft\Edge\URLBlocklist",
                @"SOFTWARE\Policies\BraveSoftware\Brave\URLBlocklist",
            };
            foreach (string r in roots)
            {
                try { Registry.CurrentUser.DeleteSubKeyTree(r, throwOnMissingSubKey: false); } catch { }
            }
        }

        private WebBlockingCache BuildCache(ConfigManager.WebBlockingConfig config, int policyVersion)
        {
            var domains = (config?.Domains ?? Array.Empty<string>())
                .Where(x => !string.IsNullOrWhiteSpace(x))
                .Select(x => x.Trim().ToLowerInvariant())
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .OrderBy(x => x, StringComparer.OrdinalIgnoreCase)
                .ToArray();

            return new WebBlockingCache
            {
                Enabled = config?.Enabled == true && domains.Length > 0,
                SyncIntervalSeconds = Math.Max(300, config?.SyncIntervalSeconds ?? 600),
                PolicyVersion = Math.Max(0, policyVersion),
                LastUpdatedUtc = DateTime.UtcNow.ToString("O"),
                Domains = domains,
                DomainsHash = ComputeDomainsHash(domains)
            };
        }

        private WebBlockingCache LoadCacheFromDisk()
        {
            try
            {
                if (!File.Exists(_cacheFilePath)) return null;
                string json = File.ReadAllText(_cacheFilePath);
                if (string.IsNullOrWhiteSpace(json)) return null;
                var cache = JsonSerializer.Deserialize<WebBlockingCache>(json);
                if (cache == null) return null;
                cache.Domains ??= Array.Empty<string>();
                cache.DomainsHash ??= ComputeDomainsHash(cache.Domains);
                cache.SyncIntervalSeconds = Math.Max(300, cache.SyncIntervalSeconds);
                return cache;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.LoadCacheFromDisk(): error.");
                return null;
            }
        }

        private void SaveCacheToDisk(WebBlockingCache cache)
        {
            try
            {
                Directory.CreateDirectory(_cacheDirectory);
                string json = JsonSerializer.Serialize(cache, new JsonSerializerOptions { WriteIndented = true });
                string tmp = _cacheFilePath + ".tmp";
                File.WriteAllText(tmp, json, Encoding.UTF8);
                // File.Move con overwrite es atomico; el delete-then-move anterior dejaba
                // ventana sin archivo si el proceso moria entre ambas operaciones.
                File.Move(tmp, _cacheFilePath, overwrite: true);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.SaveCacheToDisk(): error.");
            }
        }

        private static string ComputeDomainsHash(string[] domains)
        {
            using var sha = SHA256.Create();
            string joined = string.Join("\n", domains ?? Array.Empty<string>());
            return Convert.ToHexString(sha.ComputeHash(Encoding.UTF8.GetBytes(joined)));
        }

        private sealed class WebBlockingCache
        {
            public bool Enabled { get; set; }
            public int SyncIntervalSeconds { get; set; }
            public int PolicyVersion { get; set; }
            public string LastUpdatedUtc { get; set; }
            public string DomainsHash { get; set; }
            public string[] Domains { get; set; } = Array.Empty<string>();
        }
    }
}
```

Esto resuelve además el minor `SaveCacheToDisk` no atómico, usando `File.Move(..., overwrite: true)`.

- [ ] **Step 2: Borrar los archivos del PAC y sus tests**

```bash
git rm AZCKeeper_Client/Blocking/LocalPacServer.cs \
       AZCKeeper_Client/Blocking/PacContentBuilder.cs \
       AZCKeeper.Tests/LocalPacServerTests.cs \
       AZCKeeper.Tests/PacContentBuilderTests.cs \
       AZCKeeper.Tests/PacContentBuilderPacJsTests.cs
```

Esto elimina por completo los minors de `progress.md` asociados a `LocalPacServer`: xUnit1031 por uso de `.Result`, fuga de `TcpListener` en bind fallido, `ReadTimeout` fijo, getters sin lock y `UpdatePac` sin callers.

- [ ] **Step 3: Quitar Jint del proyecto de tests**

Jint solo existía para ejecutar el JavaScript del PAC emitido. En `AZCKeeper.Tests/AZCKeeper.Tests.csproj`, eliminar la línea:

```xml
    <PackageReference Include="Jint" Version="4.11.0" />
```

- [ ] **Step 4: Quitar PacActive del snapshot de diagnóstico**

En `AZCKeeper_Client/Core/CoreService.cs`, en el bloque que construye el snapshot (alrededor de la línea 798), eliminar la línea:

```csharp
                PacActive = _webBlockingManager?.PacActive ?? false,
```

Eliminar también la propiedad `PacActive` de la clase `DebugSnapshot` y cualquier referencia a ella en `DebugWindowForm.cs`. Buscarlas con:

```bash
grep -rn "PacActive" AZCKeeper_Client/
```

Esperado tras la limpieza: cero resultados.

- [ ] **Step 5: Compilar y correr los tests**

```bash
dotnet build AZCKeeper.sln
dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj
```

Esperado: build sin errores (los warnings nullable preexistentes del updater se mantienen), y los tests restantes (`LocalLoggerReportQueueTests`, `LocalLoggerRingBufferTests`, `NetworkBackoffPolicyTests`) en verde.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor(blocking): retirar el stack PAC; el enforcement pasa a Fase 1

Tres arquitecturas de web-blocking sin privilegio fallaron: hosts+proxy
(sobre-bloqueaba), URLBlocklist en HKCU (rama solo-lectura, nunca escribio)
y PAC blackhole (no funciona). El PAC ademas falla ABIERTO: si el servidor
local no responde, WinInet navega directo sin avisar.

WebBlockingManager queda como cache de politica y limpieza de residuos en
equipos ya desplegados. Se borran LocalPacServer y PacContentBuilder con sus
tests, y Jint del proyecto de tests. Resuelve por eliminacion los minors de
LocalPacServer documentados en progress.md, y SaveCacheToDisk pasa a
File.Move con overwrite (atomico)."
```

> **Prueba de escritorio requerida:** instalar el build self-contained sobre un equipo con PAC activo y confirmar que `AutoConfigURL` queda vacío y la navegación es directa. Usar `build/package`, nunca `bin/Debug` (framework-dependent lanza el popup de .NET faltante).

---

## Task 6: Lector de estado de controles

Lee `HKLM\SOFTWARE\Policies` y produce el objeto `controls` del reporte. **Solo lectura**, que no requiere privilegio.

**Files:**
- Create: `AZCKeeper_Client/Contracts/SecurityControlState.cs`
- Create: `AZCKeeper_Client/Security/SecurityControls.cs`
- Create: `AZCKeeper_Client/Security/SecurityStateReader.cs`
- Create: `AZCKeeper.Tests/SecurityControlsTests.cs`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `AZCKeeper_Cliente.Contracts.SecurityControlState` con `Present` (bool) y `Value` (object).
  - `AZCKeeper_Cliente.Security.SecurityControlDefinition` con `Key` (string), `RegistryPath` (string), `ValueName` (string).
  - `SecurityControls.All` — `IReadOnlyList<SecurityControlDefinition>`.
  - `SecurityControls.Evaluate(IReadOnlyDictionary<string, object> raw)` → `Dictionary<string, SecurityControlState>`, puro y testeable sin registro.
  - `SecurityStateReader.Read()` → `Dictionary<string, SecurityControlState>`, lee el registro real.

> **Decisión arquitectónica (2026-07-29, tras la auditoría).** `SecurityControlState` es un contrato de
> datos y va en `Contracts/`, **no** en `Security/`. Motivo: si viviera en `Security/`, `ApiClient` —que
> está en `Network/` y hoy solo importa Auth, Config y Logging— tendría que importar `Security` para poder
> enviarlo, creando una dependencia Network→Security en el nodo más central del cliente.
>
> `Contracts/` es una carpeta **sin dependencias de ningún módulo**: solo clases de datos. Es el primer
> ladrillo de la capa que el sistema nunca tuvo, y la razón por la que hoy los 21 DTOs de `ApiClient`
> viven dentro de `ApiClient` y los 8 de configuración dentro de `ConfigManager`. En Fase 1,
> `AZCKeeperAgent` (proyecto separado, como `AZCKeeperUpdater`, que tiene cero `ProjectReference`) podrá
> compartir estos contratos sin referenciar `AZCKeeper_Client` completo.

- [ ] **Step 1: Escribir el test que falla**

Crear `AZCKeeper.Tests/SecurityControlsTests.cs`:

```csharp
using System.Collections.Generic;
using AZCKeeper_Cliente.Security;
using Xunit;

public class SecurityControlsTests
{
    [Fact]
    public void Evaluate_MarcaAusenteLoQueNoEstaEnElRegistro()
    {
        var raw = new Dictionary<string, object>();

        var result = SecurityControls.Evaluate(raw);

        Assert.False(result["chrome.DownloadRestrictions"].Present);
        Assert.Null(result["chrome.DownloadRestrictions"].Value);
    }

    [Fact]
    public void Evaluate_MarcaPresenteYConservaElValor()
    {
        var raw = new Dictionary<string, object>
        {
            ["chrome.DownloadRestrictions"] = 3
        };

        var result = SecurityControls.Evaluate(raw);

        Assert.True(result["chrome.DownloadRestrictions"].Present);
        Assert.Equal(3, result["chrome.DownloadRestrictions"].Value);
    }

    [Fact]
    public void Evaluate_DevuelveTodosLosControlesDelCatalogo()
    {
        var result = SecurityControls.Evaluate(new Dictionary<string, object>());

        Assert.Equal(SecurityControls.All.Count, result.Count);
    }

    [Fact]
    public void All_NoTieneClavesDuplicadas()
    {
        var seen = new HashSet<string>();
        foreach (var c in SecurityControls.All)
        {
            Assert.True(seen.Add(c.Key), $"clave duplicada: {c.Key}");
        }
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

```bash
dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter SecurityControlsTests
```

Esperado: error de compilación, `SecurityControls` no existe.

- [ ] **Step 3: Crear el contrato de datos**

Crear `AZCKeeper_Client/Contracts/SecurityControlState.cs`. Esta carpeta es nueva y **no debe importar
ningún módulo del proyecto**: solo `System`.

```csharp
namespace AZCKeeper_Cliente.Contracts
{
    /// <summary>
    /// Estado observado de un control de seguridad en el equipo.
    ///
    /// Vive en Contracts/ y no en Security/ a proposito: lo consumen tanto el modulo
    /// Security (que lo produce) como Network/ApiClient (que lo envia). Si viviera en
    /// Security/, ApiClient tendria que importar Security y se crearia una dependencia
    /// entre modulos. Contracts/ no depende de nada.
    /// </summary>
    internal sealed class SecurityControlState
    {
        public bool Present { get; set; }
        public object Value { get; set; }
    }
}
```

- [ ] **Step 4: Escribir el catálogo y el evaluador**

Crear `AZCKeeper_Client/Security/SecurityControls.cs`:

```csharp
using System.Collections.Generic;
using AZCKeeper_Cliente.Contracts;

namespace AZCKeeper_Cliente.Security
{
    /// <summary>Definicion de un control: donde vive en el registro.</summary>
    internal sealed class SecurityControlDefinition
    {
        public string Key { get; }
        public string RegistryPath { get; }
        public string ValueName { get; }

        public SecurityControlDefinition(string key, string registryPath, string valueName)
        {
            Key = key;
            RegistryPath = registryPath;
            ValueName = valueName;
        }
    }

    // NOTA: SecurityControlState NO se define aqui. Vive en Contracts/ (Step 3).
    // Definirla tambien en Security/ dejaria a Contracts/ sin consumidores reales
    // y anularia el proposito de la carpeta.

    /// <summary>
    /// Catalogo de controles del Modulo de Seguridad y evaluacion del estado leido.
    /// La evaluacion es pura: no toca el registro, para poder testearla.
    /// </summary>
    internal static class SecurityControls
    {
        public static readonly IReadOnlyList<SecurityControlDefinition> All = new List<SecurityControlDefinition>
        {
            // Navegador — Chrome
            new SecurityControlDefinition("chrome.DownloadRestrictions",        @"SOFTWARE\Policies\Google\Chrome", "DownloadRestrictions"),
            new SecurityControlDefinition("chrome.DeveloperToolsAvailability",  @"SOFTWARE\Policies\Google\Chrome", "DeveloperToolsAvailability"),
            new SecurityControlDefinition("chrome.BrowserSignin",               @"SOFTWARE\Policies\Google\Chrome", "BrowserSignin"),
            new SecurityControlDefinition("chrome.SyncDisabled",                @"SOFTWARE\Policies\Google\Chrome", "SyncDisabled"),
            new SecurityControlDefinition("chrome.IncognitoModeAvailability",   @"SOFTWARE\Policies\Google\Chrome", "IncognitoModeAvailability"),
            new SecurityControlDefinition("chrome.PrintingEnabled",             @"SOFTWARE\Policies\Google\Chrome", "PrintingEnabled"),
            new SecurityControlDefinition("chrome.PasswordManagerEnabled",      @"SOFTWARE\Policies\Google\Chrome", "PasswordManagerEnabled"),

            // Navegador — Edge
            new SecurityControlDefinition("edge.DownloadRestrictions",          @"SOFTWARE\Policies\Microsoft\Edge", "DownloadRestrictions"),
            new SecurityControlDefinition("edge.DeveloperToolsAvailability",    @"SOFTWARE\Policies\Microsoft\Edge", "DeveloperToolsAvailability"),
            new SecurityControlDefinition("edge.BrowserSignin",                 @"SOFTWARE\Policies\Microsoft\Edge", "BrowserSignin"),
            new SecurityControlDefinition("edge.SyncDisabled",                  @"SOFTWARE\Policies\Microsoft\Edge", "SyncDisabled"),
            new SecurityControlDefinition("edge.InPrivateModeAvailability",     @"SOFTWARE\Policies\Microsoft\Edge", "InPrivateModeAvailability"),

            // Sistema
            new SecurityControlDefinition("system.UsbStorStart",                @"SYSTEM\CurrentControlSet\Services\USBSTOR", "Start"),
            new SecurityControlDefinition("system.RemovableStorageDenyAll",     @"SOFTWARE\Policies\Microsoft\Windows\RemovableStorageDevices", "Deny_All"),
            new SecurityControlDefinition("system.OneDriveDisablePersonalSync", @"SOFTWARE\Policies\Microsoft\OneDrive", "DisablePersonalSync"),
            new SecurityControlDefinition("system.ConsentPromptBehaviorUser",   @"SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System", "ConsentPromptBehaviorUser"),
            new SecurityControlDefinition("system.PromptOnSecureDesktop",       @"SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System", "PromptOnSecureDesktop"),
            new SecurityControlDefinition("system.EnableLUA",                   @"SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System", "EnableLUA"),

            // SRP
            new SecurityControlDefinition("srp.DefaultLevel",                   @"SOFTWARE\Policies\Microsoft\Windows\Safer\CodeIdentifiers", "DefaultLevel"),
        };

        /// <summary>
        /// Convierte los valores crudos leidos del registro en el mapa de estado del reporte.
        /// Una clave ausente en <paramref name="raw"/> se reporta como Present=false.
        /// </summary>
        public static Dictionary<string, SecurityControlState> Evaluate(IReadOnlyDictionary<string, object> raw)
        {
            var result = new Dictionary<string, SecurityControlState>();
            foreach (var def in All)
            {
                bool present = raw != null && raw.TryGetValue(def.Key, out object value) && value != null;
                result[def.Key] = new SecurityControlState
                {
                    Present = present,
                    Value = present ? raw[def.Key] : null
                };
            }
            return result;
        }
    }
}
```

- [ ] **Step 5: Correr el test y verificar que pasa**

```bash
dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter SecurityControlsTests
```

Esperado: 4 tests en verde.

> Si el proyecto de tests no puede ver las clases por ser `internal`, agregar en `AZCKeeper_Client/AZCKeeper_Client.csproj` dentro de un `<ItemGroup>`:
> ```xml
> <AssemblyAttribute Include="System.Runtime.CompilerServices.InternalsVisibleToAttribute">
>   <_Parameter1>AZCKeeper.Tests</_Parameter1>
> </AssemblyAttribute>
> ```

- [ ] **Step 6: Escribir el lector del registro**

Crear `AZCKeeper_Client/Security/SecurityStateReader.cs`:

```csharp
using System;
using System.Collections.Generic;
using AZCKeeper_Cliente.Logging;
using Microsoft.Win32;

namespace AZCKeeper_Cliente.Security
{
    /// <summary>
    /// Lee de HKLM el estado de los controles del catalogo. SOLO LECTURA:
    /// leer el registro no requiere privilegio elevado. Quien escribe es
    /// AZCKeeperAgent (Fase 1).
    /// </summary>
    internal static class SecurityStateReader
    {
        public static Dictionary<string, SecurityControlState> Read()
        {
            var raw = new Dictionary<string, object>();

            foreach (var def in SecurityControls.All)
            {
                try
                {
                    using var key = Registry.LocalMachine.OpenSubKey(def.RegistryPath, writable: false);
                    if (key == null) continue;

                    object value = key.GetValue(def.ValueName);
                    if (value != null) raw[def.Key] = value;
                }
                catch (Exception ex)
                {
                    LocalLogger.Error(ex, $"SecurityStateReader: error leyendo {def.Key}.");
                }
            }

            return SecurityControls.Evaluate(raw);
        }
    }
}
```

- [ ] **Step 7: Compilar**

```bash
dotnet build AZCKeeper.sln
```

Esperado: build sin errores.

- [ ] **Step 8: Commit**

```bash
git add AZCKeeper_Client/Contracts/ AZCKeeper_Client/Security/ AZCKeeper.Tests/SecurityControlsTests.cs
git commit -m "feat(security): lector de solo-lectura del estado de controles en HKLM

Catalogo de 19 controles del Modulo de Seguridad, evaluador puro y testeable,
y lector de HKLM. Leer el registro no requiere privilegio; quien escribe es
AZCKeeperAgent en Fase 1. Permite auditar que politicas existen hoy en la
flota sin tocar ningun equipo."
```

---

## Task 7: Envío del reporte tras el handshake

**Files:**
- Modify: `AZCKeeper_Client/Core/CoreService.cs`
- Modify: `AZCKeeper_Client/Network/ApiClient.cs`

**Interfaces:**
- Consumes: `AZCKeeper_Cliente.Security.SecurityStateReader.Read()` de la Tarea 6, que devuelve `Dictionary<string, AZCKeeper_Cliente.Contracts.SecurityControlState>`; endpoint `POST /client/security/report` de la Tarea 4.
- Produces: `ApiClient.ReportSecurityStateAsync(string deviceGuid, bool agentPresent, Dictionary<string, AZCKeeper_Cliente.Contracts.SecurityControlState> controls)` → `Task<bool>`.

> **Importante:** el tipo del diccionario es `Contracts.SecurityControlState`, **no** `Security.SecurityControlState`.
> `ApiClient` debe importar `AZCKeeper_Cliente.Contracts` y **nunca** `AZCKeeper_Cliente.Security`. Ese es el
> punto entero de haber creado `Contracts/` en la Tarea 6: `Network` no debe conocer módulos de negocio.

- [ ] **Step 1: Agregar el método al ApiClient**

En `AZCKeeper_Client/Network/ApiClient.cs`, agregar el método siguiendo el patrón exacto de los demás envíos POST del archivo (ver `SendLoginAsync`, líneas 105-122): URL relativa contra `_httpClient.BaseAddress`, serialización con `_jsonOptions`, y ruteo por `SendViaBackoffAsync` como los otros 9 call sites, para no sostener bans de firewall.

```csharp
        public async Task<bool> ReportSecurityStateAsync(
            string deviceGuid,
            bool agentPresent,
            Dictionary<string, AZCKeeper_Cliente.Contracts.SecurityControlState> controls)
        {
            try
            {
                if (_httpClient.BaseAddress == null)
                {
                    LocalLogger.Warn("ApiClient.ReportSecurityStateAsync(): BaseAddress es null.");
                    return false;
                }

                const string url = "client/security/report";

                var payload = new
                {
                    deviceId = deviceGuid,
                    agentPresent = agentPresent,
                    controls = controls
                };

                string json = JsonSerializer.Serialize(payload, _jsonOptions);
                using var content = new StringContent(json, Encoding.UTF8, "application/json");

                // CreateRequest() es OBLIGATORIO: es lo unico que adjunta el token
                // (Authorization: Bearer + el fallback X-Auth-Token que este hosting
                // necesita porque no propaga Authorization a PHP). Construir el
                // HttpRequestMessage a mano produce un 401 SILENCIOSO: 401 se clasifica
                // como Success en NetworkBackoffPolicy, no lanza y no hace backoff.
                using var httpRequest = CreateRequest(HttpMethod.Post, url, content);

                using var response = await SendViaBackoffAsync(httpRequest).ConfigureAwait(false);
                bool ok = response != null && response.IsSuccessStatusCode;
                if (!ok)
                {
                    LocalLogger.Warn($"ApiClient.ReportSecurityStateAsync(): respuesta no exitosa. HTTP {(response != null ? (int)response.StatusCode : 0)}.");
                }
                return ok;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "ApiClient.ReportSecurityStateAsync(): error.");
                return false;
            }
        }
```

- [ ] **Step 2: Llamarlo al final del handshake**

> **Corrección aplicada tras la auditoría arquitectónica (2026-07-29).** La versión anterior de este paso
> insertaba un `await` dentro de `PerformHandshake` alrededor de la línea 609. **Eso no compila:**
> `PerformHandshake` es `private void`, no `async` (`CoreService.cs:452`), y no puede marcarse `async`
> porque se invoca síncronamente desde `Start()`, `PrepareLoginUi` y dos closures de timer. Además, esa
> ubicación metía una ida y vuelta de red en medio de la aplicación de configuración, retrasando los
> bloques de Updates, Logging, Modules y Timers.
>
> El patrón correcto ya existe en el mismo archivo: `ReportPendingLogs()` se llama al final del handshake
> (`CoreService.cs:744`), fire-and-forget, con `try/catch` que nunca rompe el flujo. Se calca ese patrón.

En `AZCKeeper_Client/Core/CoreService.cs`, justo después de la llamada a `ReportPendingLogs()` en la
línea 744, agregar:

```csharp
                ReportSecurityState();
```

Y definir el método hermano junto a `ReportPendingLogs` (que está en la línea 757), síncrono por fuera
como el resto del archivo:

```csharp
        /// <summary>
        /// Auditoria de solo-lectura: reporta que controles de seguridad existen hoy en HKLM.
        /// No aplica nada; el enforcement es de AZCKeeperAgent (Fase 1).
        /// Nunca rompe el handshake: cualquier fallo se traga.
        /// </summary>
        private void ReportSecurityState()
        {
            try
            {
                string deviceGuid = _configManager.CurrentConfig.DeviceId;
                if (string.IsNullOrWhiteSpace(deviceGuid)) return;

                var state = AZCKeeper_Cliente.Security.SecurityStateReader.Read();
                _apiClient.ReportSecurityStateAsync(deviceGuid, agentPresent: false, controls: state)
                          .GetAwaiter().GetResult();
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService: error reportando estado de seguridad.");
            }
        }
```

`CurrentConfig.DeviceId` es el GUID del dispositivo y está declarado en `ConfigManager.cs:341` como
`public string DeviceId { get; set; }`. Es el mismo valor que el endpoint resuelve contra
`keeper_devices.device_guid`. El `.GetAwaiter().GetResult()` es el patrón que ya usa `PerformHandshake`
en las líneas 471-473 para llamar código async desde un método síncrono.

- [ ] **Step 3: Compilar y correr todos los tests**

```bash
dotnet build AZCKeeper.sln
dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj
```

Esperado: build sin errores; todos los tests en verde.

- [ ] **Step 4: Commit**

```bash
git add AZCKeeper_Client/Core/CoreService.cs AZCKeeper_Client/Network/ApiClient.cs
git commit -m "feat(security): reportar el estado de controles en cada handshake

El cliente lee HKLM y envia el estado observado a /client/security/report.
Solo lectura: no aplica ninguna politica. Da inventario real de que
controles existen en la flota antes de desplegar AZCKeeperAgent."
```

> **Prueba de escritorio requerida — es el cierre de la fase:** correr el build self-contained de `build/package` en un equipo real, confirmar en el log del cliente que el POST sale, y verificar la fila en `keeper_security_state` con los 19 controles y `agent_present=0`. Confirmar también que un segundo handshake devuelve `changed:false`.

---

## Task 8: Bump de versión a 4.0.0.0

Va al final: el número de versión debe reflejar todo el código ya integrado.

**Files:**
- Modify: `build-release.bat:12`
- Modify: `build-release.sh:16`

**Interfaces:**
- Consumes: todas las tareas anteriores integradas.
- Produces: `build/AZCKeeper_v4.0.0.0.zip` al correr el builder sin argumentos.

- [ ] **Step 1: Cambiar el default en el builder de Windows**

En `build-release.bat`, línea 12, reemplazar:

```bat
if "%VERSION%"=="" set VERSION=3.0.2.0
```

por:

```bat
if "%VERSION%"=="" set VERSION=4.0.0.0
```

Actualizar también el ejemplo del comentario en la línea 10 para que diga `build-release.bat 4.0.0.1`.

- [ ] **Step 2: Cambiar el default en el builder de Linux**

En `build-release.sh`, línea 16, reemplazar:

```sh
VERSION="${1:-3.0.2.0}"
```

por:

```sh
VERSION="${1:-4.0.0.0}"
```

Actualizar también el ejemplo del comentario en la línea 7 para que diga `./build-release.sh 4.0.0.0`.

- [ ] **Step 3: Construir y verificar la versión embebida**

```bash
./build-release.bat
```

Esperado: se genera `build/AZCKeeper_v4.0.0.0.zip`. Verificar el `FileVersion` embebido en el ejecutable publicado:

```powershell
(Get-Item build\package\AZCKeeper_Client.exe).VersionInfo.FileVersion
```

Esperado: `4.0.0.0`

- [ ] **Step 4: Commit**

```bash
git add build-release.bat build-release.sh
git commit -m "chore: bump de version a 4.0.0.0

Major: retira el stack PAC, cambia la semantica del merge de politicas
(las listas se reemplazan) y estrena el Modulo de Seguridad. La version
anterior publicada es 3.0.2.8."
```

---

## Task 9: Enviar el reporte solo cuando cambia

Decisión de Koichi (2026-07-29) tras el review final. Hoy el reporte viaja en cada handshake: ~251 POST
cada 5 minutos y ~72.000 UPSERT diarios inútiles contra el hosting compartido cuyo CSF/LFD ya baneó la
IP de la oficina el 2026-07-08, dejando 110 equipos incomunicados. Los controles de un equipo no cambian
casi nunca.

**Files:**
- Create: `AZCKeeper_Client/Security/SecurityReportCache.cs`
- Modify: `AZCKeeper_Client/Core/CoreService.cs` (método `ReportSecurityState`)
- Test: `AZCKeeper.Tests/SecurityReportCacheTests.cs`

**Interfaces:**
- Consumes: `SecurityStateReader.Read()`, `ApiClient.ReportSecurityStateAsync(...)`.
- Produces: `SecurityReportCache` con `ComputeHash(IReadOnlyDictionary<string, SecurityControlState>) : string`,
  `ShouldSend(string hash, DateTime nowUtc) : bool` y `MarkSent(string hash, DateTime nowUtc) : void`.

- [ ] **Step 1: Escribir los tests que fallan**

Crear `AZCKeeper.Tests/SecurityReportCacheTests.cs`:

```csharp
using System;
using System.Collections.Generic;
using AZCKeeper_Cliente.Contracts;
using AZCKeeper_Cliente.Security;
using Xunit;

public class SecurityReportCacheTests
{
    private static Dictionary<string, SecurityControlState> Estado(int valor) =>
        new Dictionary<string, SecurityControlState>
        {
            ["chrome.DownloadRestrictions"] = new SecurityControlState { Present = true, Value = valor }
        };

    [Fact]
    public void ComputeHash_EsEstableParaElMismoEstado()
    {
        var c = new SecurityReportCache(null);
        Assert.Equal(c.ComputeHash(Estado(3)), c.ComputeHash(Estado(3)));
    }

    [Fact]
    public void ComputeHash_CambiaSiCambiaUnValor()
    {
        var c = new SecurityReportCache(null);
        Assert.NotEqual(c.ComputeHash(Estado(3)), c.ComputeHash(Estado(4)));
    }

    [Fact]
    public void ShouldSend_EsTrueLaPrimeraVez()
    {
        var c = new SecurityReportCache(null);
        Assert.True(c.ShouldSend("abc", DateTime.UtcNow));
    }

    [Fact]
    public void ShouldSend_EsFalseSiElHashNoCambioYNoVencioElLatido()
    {
        var c = new SecurityReportCache(null);
        var t0 = new DateTime(2026, 7, 29, 8, 0, 0, DateTimeKind.Utc);
        c.MarkSent("abc", t0);
        Assert.False(c.ShouldSend("abc", t0.AddHours(3)));
    }

    [Fact]
    public void ShouldSend_EsTrueSiElHashCambio()
    {
        var c = new SecurityReportCache(null);
        var t0 = new DateTime(2026, 7, 29, 8, 0, 0, DateTimeKind.Utc);
        c.MarkSent("abc", t0);
        Assert.True(c.ShouldSend("xyz", t0.AddMinutes(5)));
    }

    [Fact]
    public void ShouldSend_EsTrueTrasVencerElLatidoAunqueNoCambie()
    {
        var c = new SecurityReportCache(null);
        var t0 = new DateTime(2026, 7, 29, 8, 0, 0, DateTimeKind.Utc);
        c.MarkSent("abc", t0);
        Assert.True(c.ShouldSend("abc", t0.AddHours(25)));
    }
}
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

```bash
dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter SecurityReportCacheTests
```

Esperado: error de compilación, `SecurityReportCache` no existe.

- [ ] **Step 3: Implementar la caché**

Crear `AZCKeeper_Client/Security/SecurityReportCache.cs`. El parámetro `cacheDirectory` acepta `null`
para operar solo en memoria, que es lo que usan los tests.

```csharp
using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AZCKeeper_Cliente.Contracts;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Security
{
    /// <summary>
    /// Evita reenviar el estado de seguridad cuando no cambio. Los controles de un equipo
    /// son practicamente estaticos; sin esto el handshake arrastra un POST cada 300s por
    /// equipo contra un hosting compartido que ya bloqueo la IP de la oficina por volumen.
    /// Mantiene un latido: cada HeartbeatHours se reenvia aunque no haya cambios, para que
    /// el panel distinga "sin cambios" de "equipo que dejo de reportar".
    /// </summary>
    internal sealed class SecurityReportCache
    {
        private const int HeartbeatHours = 24;

        private readonly string _cacheFilePath;
        private string _lastHash;
        private DateTime _lastSentUtc = DateTime.MinValue;

        public SecurityReportCache(string cacheDirectory)
        {
            if (!string.IsNullOrWhiteSpace(cacheDirectory))
            {
                _cacheFilePath = Path.Combine(cacheDirectory, "security_report_cache.json");
                LoadFromDisk();
            }
        }

        /// <summary>Hash estable del estado: ordena por clave para no depender del orden de lectura.</summary>
        public string ComputeHash(IReadOnlyDictionary<string, SecurityControlState> controls)
        {
            var sb = new StringBuilder();
            foreach (var kv in (controls ?? new Dictionary<string, SecurityControlState>())
                     .OrderBy(k => k.Key, StringComparer.Ordinal))
            {
                sb.Append(kv.Key).Append('=')
                  .Append(kv.Value?.Present == true ? '1' : '0').Append(':')
                  .Append(FormatValue(kv.Value?.Value)).Append('\n');
            }

            using var sha = SHA256.Create();
            return Convert.ToHexString(sha.ComputeHash(Encoding.UTF8.GetBytes(sb.ToString())));
        }

        private static string FormatValue(object value)
        {
            if (value == null) return "";
            if (value is string[] arr) return string.Join("|", arr);
            return Convert.ToString(value, System.Globalization.CultureInfo.InvariantCulture) ?? "";
        }

        public bool ShouldSend(string hash, DateTime nowUtc)
        {
            if (_lastHash == null) return true;
            if (!string.Equals(_lastHash, hash, StringComparison.Ordinal)) return true;
            return (nowUtc - _lastSentUtc).TotalHours >= HeartbeatHours;
        }

        public void MarkSent(string hash, DateTime nowUtc)
        {
            _lastHash = hash;
            _lastSentUtc = nowUtc;
            SaveToDisk();
        }

        private void LoadFromDisk()
        {
            try
            {
                if (_cacheFilePath == null || !File.Exists(_cacheFilePath)) return;
                string json = File.ReadAllText(_cacheFilePath);
                if (string.IsNullOrWhiteSpace(json)) return;
                var state = JsonSerializer.Deserialize<CacheState>(json);
                if (state == null) return;
                _lastHash = state.Hash;
                if (DateTime.TryParse(state.LastSentUtc, null,
                        System.Globalization.DateTimeStyles.RoundtripKind, out DateTime parsed))
                {
                    _lastSentUtc = parsed;
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "SecurityReportCache.LoadFromDisk(): error.");
            }
        }

        private void SaveToDisk()
        {
            try
            {
                if (_cacheFilePath == null) return;
                Directory.CreateDirectory(Path.GetDirectoryName(_cacheFilePath));
                string json = JsonSerializer.Serialize(new CacheState
                {
                    Hash = _lastHash,
                    LastSentUtc = _lastSentUtc.ToString("O")
                });
                string tmp = _cacheFilePath + ".tmp";
                File.WriteAllText(tmp, json, Encoding.UTF8);
                File.Move(tmp, _cacheFilePath, overwrite: true);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "SecurityReportCache.SaveToDisk(): error.");
            }
        }

        private sealed class CacheState
        {
            public string Hash { get; set; }
            public string LastSentUtc { get; set; }
        }
    }
}
```

- [ ] **Step 4: Correr los tests y verificar que pasan**

```bash
dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter SecurityReportCacheTests
```

Esperado: 6 tests en verde.

- [ ] **Step 5: Cablearla en CoreService**

En `AZCKeeper_Client/Core/CoreService.cs`, declarar el campo junto a los otros módulos:

```csharp
        private Security.SecurityReportCache _securityReportCache;
```

Inicializarlo en `InitializeModules()`, junto a donde se construye `_webBlockingManager`, usando el
mismo directorio de caché que ya usa el cliente:

```csharp
            _securityReportCache = new Security.SecurityReportCache(
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
                             "AZCKeeper", "Cache"));
```

Y reemplazar el cuerpo de `ReportSecurityState()` por:

```csharp
        private void ReportSecurityState()
        {
            try
            {
                string deviceGuid = _configManager.CurrentConfig.DeviceId;
                if (string.IsNullOrWhiteSpace(deviceGuid)) return;

                var state = Security.SecurityStateReader.Read();
                string hash = _securityReportCache.ComputeHash(state);
                DateTime nowUtc = DateTime.UtcNow;

                // Sin cambios y con el latido vigente: no gastar un POST.
                if (!_securityReportCache.ShouldSend(hash, nowUtc)) return;

                bool ok = _apiClient.ReportSecurityStateAsync(deviceGuid, agentPresent: false, controls: state)
                                    .GetAwaiter().GetResult();
                if (ok) _securityReportCache.MarkSent(hash, nowUtc);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService: error reportando estado de seguridad.");
            }
        }
```

`MarkSent` solo se llama si el POST tuvo éxito: si falla, el siguiente handshake reintenta.

- [ ] **Step 6: Compilar y correr toda la suite**

```bash
dotnet build AZCKeeper.sln
dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj
```

Esperado: build sin errores; todos los tests en verde.

- [ ] **Step 7: Commit**

```bash
git add AZCKeeper_Client/Security/SecurityReportCache.cs AZCKeeper_Client/Core/CoreService.cs AZCKeeper.Tests/SecurityReportCacheTests.cs
git commit -m "feat(security): enviar el reporte solo cuando cambia el estado

El reporte viajaba en cada handshake: ~251 POST cada 5 min y ~72k UPSERT
diarios inutiles contra el hosting compartido cuyo CSF/LFD ya baneo la IP de
la oficina el 2026-07-08. Los controles de un equipo son casi estaticos.

SecurityReportCache guarda el hash del ultimo estado enviado y omite el POST
si no cambio, con latido de 24h para que el panel distinga 'sin cambios' de
'equipo que dejo de reportar'. MarkSent solo se aplica si el POST tuvo exito."
```

---

## Task 10: Extender el catálogo a subclaves enumeradas, Brave y Edge simétrico

Decisión de Koichi (2026-07-29) tras el review final. El catálogo actual solo expresa valores planos, así
que no puede leer `URLBlocklist`, `URLAllowlist`, `ExtensionInstallBlocklist` ni `ExtensionInstallAllowlist`
— que son **subclaves con valores numerados `1..n`**, y son justo los controles que motivan la Fase 1.
`ExtensionInstallBlocklist=*` es el que cierra el pendiente de VPN por extensión abierto desde 2026-06-18.

**Files:**
- Modify: `AZCKeeper_Client/Security/SecurityControls.cs`
- Modify: `AZCKeeper_Client/Security/SecurityStateReader.cs`
- Modify: `AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php` (`sanitizeControls`)
- Modify: `AZCKeeper.Tests/SecurityControlsTests.cs`
- Modify: `AZCKeeper.Tests/SecurityReportContractTests.cs`

**Interfaces:**
- Produces: `SecurityControlDefinition` gana la propiedad `IsEnumeratedSubkey` (bool). Cuando es `true`,
  `ValueName` es `null` y `RegistryPath` apunta a la subclave cuyos valores `1..n` se leen como lista.
  `SecurityControlState.Value` pasa a admitir `string[]` además de `int`/`string`/`null`.

- [ ] **Step 1: Escribir los tests que fallan**

Añadir a `AZCKeeper.Tests/SecurityControlsTests.cs`:

```csharp
    [Fact]
    public void All_IncluyeLosControlesDeListaQueMotivanLaFase1()
    {
        var claves = new HashSet<string>();
        foreach (var c in SecurityControls.All) claves.Add(c.Key);

        Assert.Contains("chrome.URLBlocklist", claves);
        Assert.Contains("chrome.ExtensionInstallBlocklist", claves);
        Assert.Contains("edge.URLBlocklist", claves);
        Assert.Contains("brave.URLBlocklist", claves);
    }

    [Fact]
    public void All_LosControlesDeListaSonSubclavesEnumeradas()
    {
        foreach (var c in SecurityControls.All)
        {
            bool esLista = c.Key.EndsWith("URLBlocklist") || c.Key.EndsWith("URLAllowlist")
                        || c.Key.EndsWith("ExtensionInstallBlocklist") || c.Key.EndsWith("ExtensionInstallAllowlist");
            Assert.Equal(esLista, c.IsEnumeratedSubkey);
            if (c.IsEnumeratedSubkey) Assert.Null(c.ValueName);
            else Assert.NotNull(c.ValueName);
        }
    }

    [Fact]
    public void All_CubreLosTresNavegadoresPorIgual()
    {
        var porNavegador = new Dictionary<string, int> { ["chrome."] = 0, ["edge."] = 0, ["brave."] = 0 };
        foreach (var c in SecurityControls.All)
            foreach (var p in new[] { "chrome.", "edge.", "brave." })
                if (c.Key.StartsWith(p)) porNavegador[p]++;

        Assert.Equal(porNavegador["chrome."], porNavegador["edge."]);
        Assert.Equal(porNavegador["chrome."], porNavegador["brave."]);
    }

    [Fact]
    public void Evaluate_ConservaUnValorDeLista()
    {
        var raw = new Dictionary<string, object>
        {
            ["chrome.URLBlocklist"] = new[] { "facebook.com", "x.com" }
        };

        var result = SecurityControls.Evaluate(raw);

        Assert.True(result["chrome.URLBlocklist"].Present);
        Assert.Equal(new[] { "facebook.com", "x.com" }, (string[])result["chrome.URLBlocklist"].Value);
    }
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

```bash
dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter SecurityControlsTests
```

Esperado: error de compilación (`IsEnumeratedSubkey` no existe) y fallos de contenido del catálogo.

- [ ] **Step 3: Extender el contrato y el catálogo**

En `AZCKeeper_Client/Security/SecurityControls.cs`, añadir la propiedad a la definición y un constructor
que la acepte, conservando el existente para los valores planos:

```csharp
    internal sealed class SecurityControlDefinition
    {
        public string Key { get; }
        public string RegistryPath { get; }
        public string ValueName { get; }
        /// <summary>true = RegistryPath es una subclave con valores "1".."n" que se leen como lista.</summary>
        public bool IsEnumeratedSubkey { get; }

        public SecurityControlDefinition(string key, string registryPath, string valueName)
            : this(key, registryPath, valueName, false) { }

        public SecurityControlDefinition(string key, string registryPath, string valueName, bool isEnumeratedSubkey)
        {
            Key = key;
            RegistryPath = registryPath;
            ValueName = valueName;
            IsEnumeratedSubkey = isEnumeratedSubkey;
        }
    }
```

En el catálogo `All`, dejar los tres navegadores con el **mismo conjunto** de controles. Para cada uno de
`chrome` (`SOFTWARE\Policies\Google\Chrome`), `edge` (`SOFTWARE\Policies\Microsoft\Edge`) y `brave`
(`SOFTWARE\Policies\BraveSoftware\Brave`), incluir estos valores planos:

`DownloadRestrictions`, `DeveloperToolsAvailability`, `BrowserSignin`, `SyncDisabled`,
`IncognitoModeAvailability`, `PrintingEnabled`, `PasswordManagerEnabled`

y estas cuatro subclaves enumeradas (`ValueName` en `null`, `isEnumeratedSubkey` en `true`), con la ruta
del navegador más `\<NombreDeLaSubclave>`:

`URLBlocklist`, `URLAllowlist`, `ExtensionInstallBlocklist`, `ExtensionInstallAllowlist`

Mantener sin cambios los seis controles de sistema y el de SRP que ya existen.

> **CORRECCIÓN (2026-07-29, tras el review de esta tarea).** La versión anterior de esta nota afirmaba
> que `IncognitoModeAvailability` era el nombre correcto también en Edge. **Es falso.** Microsoft renombró
> ese policy al portarlo de Chromium: en Edge se llama **`InPrivateModeAvailability`**, en la misma ruta
> de registro. Usar el nombre de Chrome haría que el control reporte `Present=false` siempre, aunque esté
> configurado por GPO o Intune — un falso negativo silencioso, justo lo que esta fase existe para evitar.
>
> El catálogo correcto es: Chrome y Brave usan `IncognitoModeAvailability`; **Edge usa
> `InPrivateModeAvailability`**. `URLBlocklist`, `URLAllowlist`, `ExtensionInstallBlocklist` y
> `ExtensionInstallAllowlist` sí conservan el mismo nombre en los tres.
>
> La simetría que exige el test es de **cantidad** de controles por navegador, no de nombres de valor,
> así que el catálogo sigue siendo simétrico con el nombre correcto.

- [ ] **Step 4: Enseñar al lector a leer subclaves enumeradas**

En `AZCKeeper_Client/Security/SecurityStateReader.cs`, dentro del `foreach`, ramificar según el tipo de
control. La lectura sigue siendo **solo lectura**: `writable: false` en todos los casos.

```csharp
                    using var key = Registry.LocalMachine.OpenSubKey(def.RegistryPath, writable: false);
                    if (key == null) continue;

                    if (def.IsEnumeratedSubkey)
                    {
                        // Subclave con valores "1".."n": se leen todos y se ordenan numericamente.
                        var items = new List<string>();
                        foreach (string name in key.GetValueNames())
                        {
                            object v = key.GetValue(name);
                            if (v != null) items.Add(Convert.ToString(v));
                        }
                        if (items.Count > 0) raw[def.Key] = items.ToArray();
                    }
                    else
                    {
                        object value = key.GetValue(def.ValueName);
                        if (value != null) raw[def.Key] = value;
                    }
```

Añadir `using System.Collections.Generic;` si falta.

- [ ] **Step 5: Correr los tests y verificar que pasan**

```bash
dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter SecurityControlsTests
```

Esperado: todos en verde.

- [ ] **Step 6: Aceptar listas en el servidor**

**Sin este paso las listas llegan vacías:** hoy `sanitizeControls()` descarta cualquier `value` que sea
array, convirtiéndolo a `null`.

En `AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php`, dentro de `sanitizeControls()`, aceptar
arrays de strings además de los tipos actuales, acotando tanto la cantidad de elementos como el largo de
cada uno. Añadir junto a las otras constantes de la clase:

```php
    private const MAX_LIST_ITEMS = 200;
```

Y en la rama que hoy descarta los valores fuera de contrato, tratar el array antes de descartarlo:

```php
            } elseif (is_array($value)) {
                // Subclaves enumeradas (URLBlocklist, ExtensionInstallBlocklist...): lista de strings.
                $lista = [];
                foreach ($value as $item) {
                    if (!is_string($item)) continue;
                    $lista[] = mb_substr($item, 0, self::MAX_VALUE_LEN, 'UTF-8');
                    if (count($lista) >= self::MAX_LIST_ITEMS) break;
                }
                $value = $lista;
            } elseif (!is_int($value) && !is_string($value) && $value !== null) {
```

Ajusta la estructura del `if/elseif` a como esté escrita realmente la función; lo vinculante es que un
array de strings sobreviva acotado y que todo lo demás fuera de `int|string|null` siga descartándose.

- [ ] **Step 7: Extender el test de contrato**

En `AZCKeeper.Tests/SecurityReportContractTests.cs`, añadir un caso que serialice un control cuyo `Value`
sea `string[]` y afirme que el JSON produce un array de strings:

```csharp
    [Fact]
    public void Serializacion_DeUnaListaProduceArrayDeStrings()
    {
        var controls = new Dictionary<string, SecurityControlState>
        {
            ["chrome.URLBlocklist"] = new SecurityControlState
            {
                Present = true,
                Value = new[] { "facebook.com", "x.com" }
            }
        };

        string json = JsonSerializer.Serialize(controls, JsonOptionsIgualesAlApiClient());

        Assert.Contains("\"chrome.URLBlocklist\"", json);
        Assert.Contains("\"value\":[\"facebook.com\",\"x.com\"]", json);
    }
```

Usa el mismo helper de opciones que ya tiene ese archivo de tests; si se llama distinto, ajusta el nombre.

- [ ] **Step 8: Compilar, correr todo y verificar sintaxis PHP**

```bash
dotnet build AZCKeeper.sln
dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj
php -l AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php
php AZCKeeper_Client/Web/tests/run.php
```

Esperado: build sin errores, todos los tests de C# en verde, `No syntax errors detected`, y el runner PHP
sin fallos.

- [ ] **Step 9: Commit**

```bash
git add AZCKeeper_Client/Security/ AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php AZCKeeper.Tests/
git commit -m "feat(security): catalogo con subclaves enumeradas, Brave y Edge simetrico

El contrato SecurityControlDefinition(key, path, valueName) no podia expresar
URLBlocklist, URLAllowlist, ExtensionInstallBlocklist ni ExtensionInstallAllowlist,
que son subclaves con valores numerados 1..n. Son justo los controles que motivan
la Fase 1: ExtensionInstallBlocklist=* cierra el pendiente de VPN por extension
abierto desde 2026-06-18, y URLBlocklist es el enforcement central del modulo.

Se agrega IsEnumeratedSubkey al contrato, el lector aprende a enumerar la subclave
(sigue siendo solo lectura), se suma Brave y se simetriza Edge con Chrome. En el
servidor, sanitizeControls() acepta listas de strings acotadas (200 elementos,
512 chars cada uno); sin eso las listas llegaban vacias por el descarte de arrays."
```

---

## Criterio de salida de la Fase 0

- [ ] `php AZCKeeper_Client/Web/tests/run.php` → sin fallos
- [ ] `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj` → todo verde
- [ ] `grep -rn "PacActive\|LocalPacServer\|PacContentBuilder" AZCKeeper_Client/ AZCKeeper.Tests/` → cero resultados
- [ ] Política de usuario probada contra DEV sin heredar restos de la global
- [ ] `keeper_security_state` con al menos una fila real reportada desde un equipo
- [ ] Cero equipos de la flota con política aplicada — esta fase no aplica nada

Cumplido esto, la Fase 1 (servicio `AZCKeeperAgent` + migración a `%ProgramFiles%`) tiene toda su base lista.
