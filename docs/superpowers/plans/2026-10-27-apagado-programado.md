# Apagado y reinicio programados — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** TI programa apagados/reinicios (una vez o semanales) por alcance con excepciones; el agente los ejecuta en el equipo: sin uso → ejecuta; en uso → aviso con mensaje y «Posponer» una vez; resultados visibles en el panel.

**Architecture:** El servidor guarda programaciones y alcances (migración 0070), las resuelve por equipo y las envía en `POST /v1/client/sync` como `power_schedules[]` solo a agentes ≥ 4.0.30. El agente (módulo `PowerScheduler`) calcula ocurrencias en la zona horaria de la empresa, pregunta inactividad a las sesiones por el IPC existente, muestra el aviso con `PowerNoticeWindow` y ejecuta con el `IDeviceActions` existente. Los resultados vuelven en `SyncRequest.power_schedule_runs[]` (mismo patrón que `locations[]`/`location_acks`).

**Tech Stack:** PHP 8.3 (backend-v4, MySQL 8.4 / MariaDB 11.4), OpenAPI 3 contrato `docs/architecture/openapi-v4.yaml` + `backend-v4/tests/export-contract.py` + `client-v4/tools/generate.mjs`, .NET 8 (Keeper.Agent, Keeper.Session WinForms, Keeper.Shared), xUnit, JS ES modules del panel (`backend-v4/public/assets/k3.js`).

**Spec:** `docs/superpowers/specs/2026-10-27-apagado-programado-design.md` (leerlo antes de cada tarea).

## Global Constraints

- Versión mínima del agente para recibir `power_schedules`: **`4.0.30`** (4.0.29 ya está reservada para Ubicación: `PolicyCompiler::LOCATION_MIN_AGENT='4.0.29'`). Constante `PowerSchedules::MIN_AGENT = '4.0.30'`.
- Agentes < 4.0.30 **nunca** reciben el campo (deserialización estricta `UnmappedMemberHandling.Disallow` rompe el sync entero).
- Valores por defecto (spec §1): `idle_minutes` 15 (rango 5–120), `notice_minutes` 10 (1–30), `postpone_minutes` 60 (`null` o 15–240), `window_minutes` 180 (0–720), `message` 0–280 caracteres sin control, `name` 1–80, `action` `shutdown`|`restart` (defecto `shutdown`), `recurrence` `once`|`weekly`, `days` ISO 1–7 (lunes=1), `time_local` `HH:MM`.
- Mensaje por defecto si `message` vacío: «TI programó el apagado de este equipo.» / «TI programó el reinicio de este equipo.»
- Permisos: crear/editar/borrar una programación `shutdown` exige `equipos.apagar`; `restart` exige `equipos.reiniciar`; listar/ver exige `equipos.ver`. Función contratada `comandos` (se aplica sola vía `Features::forOperation`). Alcances y excepciones dentro del alcance de quien crea (`403 permission_denied`, detail `scope_denied`).
- Coincidencia de dos programaciones en el mismo minuto para un equipo: una sola ejecución, gana `restart`.
- Orden manual (CommandExecutor) con aviso pendiente gana sobre la programada → outcome `replaced`.
- Outcomes exactos: `executed_idle`, `executed_after_notice`, `postponed`, `executed_by_person`, `cancelled_by_it`, `replaced`, `skipped_suspended`, `not_needed`, `unsupported`.
- Todo texto visible en español. Comentarios del código en español, mismo estilo denso del repo.
- Contrato: editar solo el yaml; luego `python backend-v4/tests/export-contract.py` y `cd client-v4/tools && npm run generate && npm run check`. `tests/smoke.php` exige `source_sha256` del yaml en LF.
- Migraciones nuevas: `SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci; SET time_zone = '+00:00';`, PK `(tenant_id, id)`, toda FK incluye `tenant_id`, InnoDB (`migrations/verify.php`).
- Cliente: `TreatWarningsAsErrors=true`; tras compilar/probar, `git checkout -- '**/packages.lock.json'` si cambian.
- Nada se despliega ni publica en este plan.

## File Structure

Servidor:
- Create `backend-v4/migrations/0070_power_schedules.sql` — tablas `power_schedules`, `power_schedule_targets`, `power_schedule_runs`.
- Create `backend-v4/src/PowerSchedules.php` — CRUD admin, validación, resolución por equipo (`clientSchedules`), ingesta de resultados (`ingestRuns`), DTOs.
- Modify `backend-v4/src/AdminApi.php` — permiso por acción en el constructor; ops en `handle()`.
- Modify `backend-v4/src/Application.php` — `/client/sync`: enviar `power_schedules`, ingerir `power_schedule_runs`, devolver `power_schedule_acks`.
- Modify `docs/architecture/openapi-v4.yaml`, regenerar `backend-v4/config/contract.json`, `client-v4/src/Keeper.Shared/Protocol/Protocol.g.cs`.
- Create `backend-v4/tests/power-schedules.php`; modify `backend-v4/tests/smoke.php`, `backend-v4/tests/mysql-smoke.ps1` (`-PowerScheduleOnly`).
- Panel: create `backend-v4/public/apagado-programado.php`, `backend-v4/public/assets/power-schedules.js`; modify `backend-v4/public/assets/k3.js` (router), `backend-v4/public/partials/sidebar.php`, `backend-v4/public/assets/nav-access.js`, `backend-v4/public/assets/device-actions.js` (botón «Programar» en la ficha).

Cliente:
- Create `client-v4/src/Keeper.Agent/Modules/Devices/PowerScheduleCalendar.cs` — cálculo puro de ocurrencias.
- Create `client-v4/src/Keeper.Agent/Modules/Devices/PowerScheduler.cs` — módulo.
- Modify `client-v4/src/Keeper.Shared/Contracts/SessionProtocol.cs` — `PowerNotice` ampliado, `IdleSeconds`, `PowerChoice` en `SessionResponse` + validación.
- Modify `client-v4/src/Keeper.Agent/Hosting/SessionSupervisor.cs` — guardar inactividad por sesión, reenviar `PowerChoice`.
- Modify `client-v4/src/Keeper.Session/Ipc/SessionClient.cs`, `client-v4/src/Keeper.Session/Ipc/SessionBuffer.cs`, `client-v4/src/Keeper.Session/Program.cs`, `client-v4/src/Keeper.Session/Modules/UI/PowerNoticeWindow.cs`.
- Modify `client-v4/src/Keeper.Agent/Program.cs` — registrar módulo y cablear `OnResponse`.
- Outbox: modify `client-v4/src/Keeper.Shared/Contracts/Modules.cs` (`IEventSink`) y la implementación del outbox, siguiendo exactamente cómo se añadió `LocationPoint` / `locations[]` / `location_acks`.
- Tests: create `client-v4/tests/Keeper.Agent.Tests/PowerScheduleCalendarTests.cs`, `PowerSchedulerTests.cs`; modify `PowerNoticeTests.cs` / `IpcTests.cs`.

---

### Task 1: Contrato y migración

**Files:**
- Create: `backend-v4/migrations/0070_power_schedules.sql`
- Modify: `docs/architecture/openapi-v4.yaml` (paths admin nuevos; schemas; `SyncResponse.power_schedules`, `SyncResponse.power_schedule_acks`, `SyncRequest.power_schedule_runs`)
- Regenerate: `backend-v4/config/contract.json`, `client-v4/src/Keeper.Shared/Protocol/Protocol.g.cs`

**Interfaces:**
- Produces (contrato): ops `listPowerSchedules` (GET `/admin/power-schedules`, `x-permission: equipos.ver`), `createPowerSchedule` (POST, `x-permission: equipos.apagar`, 201), `getPowerSchedule` (GET `/admin/power-schedules/{id}`, `equipos.ver`), `patchPowerSchedule` (PATCH, `IfMatch`, `equipos.apagar`), `deletePowerSchedule` (DELETE, `IfMatch`, `equipos.apagar`, 204), `listPowerScheduleRuns` (GET `/admin/power-schedules/{id}/runs`, `equipos.ver`). Schemas `PowerSchedule`, `PowerScheduleInput`, `PowerScheduleTarget`, `PowerScheduleRun`, `PowerScheduleDirective`, `PowerScheduleRunReport`.
- Produces (C# generado): `PowerScheduleDirective` con `Id (Guid)`, `Version (long)`, `Action (PowerScheduleDirectiveAction: Shutdown|Restart)`, `Recurrence (PowerScheduleDirectiveRecurrence: Once|Weekly)`, `Days (List<long>?)`, `TimeLocal (string)`, `RunOn (DateOnly?)`, `ValidFrom (DateOnly)`, `ValidUntil (DateOnly?)`, `Timezone (string)`, `Message (string)`, `IdleMinutes`, `NoticeMinutes`, `PostponeMinutes (long?)`, `WindowMinutes`; `SyncResponse.PowerSchedules (List<PowerScheduleDirective>?)`, `SyncResponse.PowerScheduleAcks (List<Guid>?)`; `SyncRequest.PowerScheduleRuns (List<PowerScheduleRunReport>?)` con `EventId (Guid)`, `ScheduleId (Guid)`, `OccurrenceAt (DateTimeOffset)`, `Outcome (PowerScheduleRunReportOutcome)`, `Detail (string?)`.

- [ ] **Step 1: Migración**

```sql
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET time_zone = '+00:00';

-- Apagado/reinicio programado (spec docs/superpowers/specs/2026-10-27-apagado-programado-design.md).
CREATE TABLE IF NOT EXISTS power_schedules (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(80) NOT NULL,
  action ENUM('shutdown','restart') NOT NULL DEFAULT 'shutdown',
  recurrence ENUM('once','weekly') NOT NULL,
  days_mask TINYINT UNSIGNED NOT NULL DEFAULT 0,          -- bit 0 = lunes ... bit 6 = domingo (solo weekly)
  time_local CHAR(5) NOT NULL,                            -- HH:MM en tenants.timezone
  run_on DATE NULL,                                       -- solo once
  valid_from DATE NOT NULL,
  valid_until DATE NULL,
  message VARCHAR(280) NOT NULL DEFAULT '',
  idle_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  notice_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  postpone_minutes SMALLINT UNSIGNED NULL DEFAULT 60,
  window_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 180,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_by BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  KEY (tenant_id, status),
  CONSTRAINT power_schedules_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT power_schedules_shape CHECK ((recurrence='once' AND run_on IS NOT NULL AND days_mask=0) OR (recurrence='weekly' AND run_on IS NULL AND days_mask BETWEEN 1 AND 127))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS power_schedule_targets (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  schedule_id BINARY(16) NOT NULL,
  kind ENUM('tenant','site','area','user','device') NOT NULL,
  ref_id BINARY(16) NOT NULL,                             -- tenant_id para kind=tenant
  excluded BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, schedule_id, kind, ref_id, excluded),
  CONSTRAINT power_schedule_targets_schedule_fk FOREIGN KEY (tenant_id, schedule_id) REFERENCES power_schedules (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS power_schedule_runs (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  schedule_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  occurrence_at DATETIME(6) NOT NULL,
  outcome ENUM('executed_idle','executed_after_notice','postponed','executed_by_person','cancelled_by_it','replaced','skipped_suspended','not_needed','unsupported') NOT NULL,
  detail VARCHAR(100) NULL,
  event_id BINARY(16) NOT NULL,
  reported_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, event_id),
  UNIQUE KEY (tenant_id, schedule_id, device_id, occurrence_at, outcome),
  KEY (tenant_id, schedule_id, device_id, occurrence_at),
  CONSTRAINT power_schedule_runs_schedule_fk FOREIGN KEY (tenant_id, schedule_id) REFERENCES power_schedules (tenant_id, id) ON DELETE CASCADE,
  CONSTRAINT power_schedule_runs_device_fk FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

Nota: `postponed` y el resultado final de la misma ocurrencia son dos filas (por eso `outcome` está en la clave única).

- [ ] **Step 2: Schemas en el yaml** (bajo `components/schemas`, junto a `WindowsSecurityDirective` ~L15526)

```yaml
    PowerScheduleTarget:
      type: object
      additionalProperties: false
      required: [kind, id]
      properties:
        kind: {type: string, enum: [tenant, site, area, user, device]}
        id: {type: string, format: uuid}
    PowerScheduleInput:
      type: object
      additionalProperties: false
      description: Programación de apagado/reinicio. En PATCH todos los campos son opcionales.
      properties:
        name: {type: string, minLength: 1, maxLength: 80}
        action: {type: string, enum: [shutdown, restart]}
        recurrence: {type: string, enum: [once, weekly]}
        days: {type: array, items: {type: integer, minimum: 1, maximum: 7}, maxItems: 7, uniqueItems: true}
        time_local: {type: string, pattern: '^([01][0-9]|2[0-3]):[0-5][0-9]$'}
        run_on: {type: string, format: date, nullable: true}
        valid_from: {type: string, format: date}
        valid_until: {type: string, format: date, nullable: true}
        targets: {type: array, items: {$ref: '#/components/schemas/PowerScheduleTarget'}, minItems: 1, maxItems: 200}
        exclusions: {type: array, items: {$ref: '#/components/schemas/PowerScheduleTarget'}, maxItems: 200}
        message: {type: string, maxLength: 280}
        idle_minutes: {type: integer, minimum: 5, maximum: 120}
        notice_minutes: {type: integer, minimum: 1, maximum: 30}
        postpone_minutes: {type: integer, minimum: 15, maximum: 240, nullable: true}
        window_minutes: {type: integer, minimum: 0, maximum: 720}
        status: {type: string, enum: [active, paused]}
    PowerSchedule:
      type: object
      additionalProperties: false
      required: [id, name, action, recurrence, days, time_local, run_on, valid_from, valid_until, targets, exclusions, message, idle_minutes, notice_minutes, postpone_minutes, window_minutes, status, version, created_at, updated_at]
      properties:
        id: {type: string, format: uuid}
        name: {type: string}
        action: {type: string, enum: [shutdown, restart]}
        recurrence: {type: string, enum: [once, weekly]}
        days: {type: array, items: {type: integer}}
        time_local: {type: string}
        run_on: {type: string, format: date, nullable: true}
        valid_from: {type: string, format: date}
        valid_until: {type: string, format: date, nullable: true}
        targets: {type: array, items: {$ref: '#/components/schemas/PowerScheduleTarget'}}
        exclusions: {type: array, items: {$ref: '#/components/schemas/PowerScheduleTarget'}}
        message: {type: string}
        idle_minutes: {type: integer}
        notice_minutes: {type: integer}
        postpone_minutes: {type: integer, nullable: true}
        window_minutes: {type: integer}
        status: {type: string, enum: [active, paused]}
        version: {type: integer}
        created_at: {type: string, format: date-time}
        updated_at: {type: string, format: date-time}
    PowerScheduleRun:
      type: object
      additionalProperties: false
      required: [device_id, hostname, occurrence_at, outcome, detail, reported_at]
      properties:
        device_id: {type: string, format: uuid}
        hostname: {type: string}
        occurrence_at: {type: string, format: date-time}
        outcome: {type: string, enum: [executed_idle, executed_after_notice, postponed, executed_by_person, cancelled_by_it, replaced, skipped_suspended, not_needed, unsupported]}
        detail: {type: string, nullable: true}
        reported_at: {type: string, format: date-time}
    PowerScheduleDirective:
      type: object
      additionalProperties: false
      description: Programación ya resuelta para este equipo (sin listas de alcance). Solo agentes >= 4.0.30.
      required: [id, version, action, recurrence, time_local, valid_from, timezone, message, idle_minutes, notice_minutes, window_minutes]
      properties:
        id: {type: string, format: uuid}
        version: {type: integer}
        action: {type: string, enum: [shutdown, restart]}
        recurrence: {type: string, enum: [once, weekly]}
        days: {type: array, items: {type: integer, minimum: 1, maximum: 7}, maxItems: 7}
        time_local: {type: string, pattern: '^([01][0-9]|2[0-3]):[0-5][0-9]$'}
        run_on: {type: string, format: date}
        valid_from: {type: string, format: date}
        valid_until: {type: string, format: date}
        timezone: {type: string, maxLength: 64}
        message: {type: string, maxLength: 280}
        idle_minutes: {type: integer}
        notice_minutes: {type: integer}
        postpone_minutes: {type: integer}
        window_minutes: {type: integer}
    PowerScheduleRunReport:
      type: object
      additionalProperties: false
      required: [event_id, schedule_id, occurrence_at, outcome]
      properties:
        event_id: {type: string, format: uuid}
        schedule_id: {type: string, format: uuid}
        occurrence_at: {type: string, format: date-time}
        outcome: {type: string, enum: [executed_idle, executed_after_notice, postponed, executed_by_person, cancelled_by_it, replaced, skipped_suspended, not_needed, unsupported]}
        detail: {type: string, maxLength: 100}
```

En `SyncResponse.properties` (~L22906) añadir:
```yaml
          power_schedules:
            type: array
            maxItems: 50
            items: {$ref: '#/components/schemas/PowerScheduleDirective'}
          power_schedule_acks:
            type: array
            maxItems: 100
            items: {type: string, format: uuid}
```
En `SyncRequest.properties` (~L22844) añadir:
```yaml
          power_schedule_runs:
            type: array
            maxItems: 100
            items: {$ref: '#/components/schemas/PowerScheduleRunReport'}
```

- [ ] **Step 3: Paths admin** — copiar la forma de `/absences` (L14435) y `/absences/{id}` (L14580): seguridad `adminSession` (+ `csrfToken` en POST/PATCH/DELETE), parámetro `Id`, `IfMatch` en PATCH/DELETE, `x-max-body-bytes: 16384` en POST/PATCH, respuestas `PowerSchedule` (200/201), lista `{data: PowerSchedule[]}`, runs `{data: PowerScheduleRun[]}`, errores estándar (`$ref` a los mismos Problem que `/absences`). OperationIds y `x-permission` exactos de **Interfaces** arriba.

- [ ] **Step 4: Exportar y generar**

Run: `python backend-v4/tests/export-contract.py` y `cd client-v4/tools && npm run generate && npm run check`
Expected: sin errores; `git diff --stat` muestra `contract.json`, `Protocol.g.cs` cambiados. Verificar que el hash del yaml se calcula sobre LF (`git -c core.autocrlf=false` si el working copy está en CRLF; ver incidente 7f0647b).

- [ ] **Step 5: Suite completa sin cambios funcionales**

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses` y `dotnet build client-v4/Keeper.sln -maxcpucount:1 -nodeReuse:false`
Expected: `SMOKE PASS`, migraciones 70 aplicadas, verify OK, replay SKIP; build 0 errores.

- [ ] **Step 6: Commit** `git commit -m "Apagado programado: contrato y migración 0070"`

---

### Task 2: CRUD admin de programaciones

**Files:**
- Create: `backend-v4/src/PowerSchedules.php`
- Modify: `backend-v4/src/AdminApi.php` (constructor ~L28 override de permiso; `handle()` ~L169-220)
- Create: `backend-v4/tests/power-schedules.php`; Modify: `backend-v4/tests/smoke.php` (require + llamada + flag `KEEPER_TEST_POWER_SCHEDULE_ONLY`), `backend-v4/tests/mysql-smoke.ps1` (`-PowerScheduleOnly`)

**Interfaces:**
- Consumes: tablas Task 1; `AdminAccess` (`user()`, `requireFull()`, `allowsUnitTarget()`), `AdminApi::response()`, `Audit::record()`, `ApiError`.
- Produces: `final class PowerSchedules { public const MIN_AGENT = '4.0.30'; public function __construct(Database $db, array $c, ?AdminAccess $access, Request $r); public function dispatch(string $op, ?string $id, ?object $b): array; public static function permissionFor(string $action): string; }`

- [ ] **Step 1: Tests que fallan** (`backend-v4/tests/power-schedules.php`)

```php
<?php
// Apagado programado: CRUD, validación, permisos por acción, alcance y auditoría.
function powerScheduleAdminTests(Database $db): void
{
    $a = adminBase($db);                                   // usar el mismo fixture base que windows-security.php
    $tenant = $a['tenant_id'];
    $both = adminFixture($db, $a, ['equipos.ver', 'equipos.apagar', 'equipos.reiniciar']);
    $offOnly = adminFixture($db, $a, ['equipos.ver', 'equipos.apagar']);
    $scoped = adminFixture($db, adminMember($db, $a), ['equipos.ver', 'equipos.apagar'], false, 'self');
    $tenantId = Util::id($tenant);
    $base = ['name' => 'Viernes', 'recurrence' => 'weekly', 'days' => [5], 'time_local' => '19:00',
        'valid_from' => '2026-11-01', 'targets' => [['kind' => 'tenant', 'id' => $tenantId]]];

    $s = expect(adminRequest($both, 'POST', '/admin/power-schedules', $base), 201, 'create weekly shutdown');
    check($s['action'] === 'shutdown' && $s['idle_minutes'] === 15 && $s['notice_minutes'] === 10 && $s['postpone_minutes'] === 60 && $s['window_minutes'] === 180, 'defaults applied');
    check($s['days'] === [5] && $s['exclusions'] === [] && $s['status'] === 'active', 'shape');

    expect(adminRequest($both, 'POST', '/admin/power-schedules', $base + ['extra' => 1]), 422, 'closed body');
    check(adminRequest($both, 'POST', '/admin/power-schedules', array_merge($base, ['days' => []]))[1]['detail'] === 'invalid_days', 'weekly needs days');
    check(adminRequest($both, 'POST', '/admin/power-schedules', array_merge($base, ['recurrence' => 'once']))[1]['detail'] === 'invalid_run_on', 'once needs run_on');
    check(adminRequest($both, 'POST', '/admin/power-schedules', array_merge($base, ['valid_until' => '2026-10-01']))[1]['detail'] === 'invalid_range', 'until before from');

    // Reiniciar exige equipos.reiniciar.
    expect(adminRequest($offOnly, 'POST', '/admin/power-schedules', $base + ['action' => 'restart']), 403, 'restart needs equipos.reiniciar');
    expect(adminRequest($offOnly, 'POST', '/admin/power-schedules', $base), 201, 'shutdown with equipos.apagar');
    // Alcance: un admin 'self' no puede apuntar a toda la empresa.
    check(adminRequest($scoped, 'POST', '/admin/power-schedules', $base)[1]['detail'] === 'scope_denied', 'tenant target needs full scope');

    $p = expect(adminRequest($both, 'PATCH', '/admin/power-schedules/' . $s['id'], ['status' => 'paused'], ['if-match' => '"' . $s['version'] . '"']), 200, 'pause');
    check($p['status'] === 'paused' && $p['version'] === $s['version'] + 1, 'version bumped');
    expect(adminRequest($both, 'PATCH', '/admin/power-schedules/' . $s['id'], ['status' => 'active'], ['if-match' => '"' . $s['version'] . '"']), 412, 'stale if-match');
    $list = expect(adminRequest($both, 'GET', '/admin/power-schedules'), 200, 'list');
    check(count($list['data']) === 2, 'list both');
    expect(adminRequest($both, 'DELETE', '/admin/power-schedules/' . $s['id'], null, ['if-match' => '"' . $p['version'] . '"']), 204, 'delete');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND resource_type='power_schedule'", [$tenant])['n'] >= 3, 'audited');
}
```

(Usar los mismos helpers de fixture que `tests/windows-security.php`; si `adminBase` no existe, copiar cómo ese archivo obtiene `$a`.)

- [ ] **Step 2: Correr y ver fallar** — `mysql-smoke.ps1 -PowerScheduleOnly` → FAIL (404 en `/admin/power-schedules` hasta el handler) .

- [ ] **Step 3: Implementar `PowerSchedules.php`** (validación + CRUD)

```php
<?php
declare(strict_types=1);
namespace Keeper;

// Apagado/reinicio programado (docs/superpowers/specs/2026-10-27-apagado-programado-design.md).
final class PowerSchedules
{
    public const MIN_AGENT = '4.0.30';
    private const KINDS = ['tenant', 'site', 'area', 'user', 'device'];
    private string $tenant;

    public function __construct(private Database $db, private array $c, private ?AdminAccess $access, private Request $r)
    { $this->tenant = $c['tenant_id']; }

    public static function permissionFor(string $action): string
    { return $action === 'restart' ? 'equipos.reiniciar' : 'equipos.apagar'; }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        return match ($op) {
            'listPowerSchedules' => AdminApi::response(['data' => array_map(fn ($s) => $this->dto($s), $this->db->run('SELECT * FROM power_schedules WHERE tenant_id=? ORDER BY created_at DESC LIMIT 500', [$this->tenant])->fetchAll())]),
            'getPowerSchedule' => AdminApi::response($this->dto($this->load($id, false))),
            'createPowerSchedule' => $this->save(null, $b),
            'patchPowerSchedule' => $this->save($id, $b),
            'deletePowerSchedule' => $this->delete($id),
            'listPowerScheduleRuns' => $this->runs($id),
        };
    }

    private function load(string $id, bool $lock): array
    {
        $s = $this->db->one('SELECT * FROM power_schedules WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$this->tenant, $id]);
        if (!$s) { throw new ApiError(404, 'resource_not_found'); }
        return $s;
    }

    private function save(?string $id, object $b): array
    {
        $old = $id === null ? null : $this->load($id, true);
        if ($old && $this->r->header('if-match') !== '"' . $old['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
        $cur = $old ? $this->dto($old) : ['action' => 'shutdown', 'days' => [], 'run_on' => null, 'valid_from' => (new \DateTimeImmutable('now', new \DateTimeZone($this->c['timezone'])))->format('Y-m-d'),
            'valid_until' => null, 'message' => '', 'idle_minutes' => 15, 'notice_minutes' => 10, 'postpone_minutes' => 60, 'window_minutes' => 180, 'status' => 'active', 'targets' => [], 'exclusions' => []];
        $v = array_merge($cur, get_object_vars($b));
        foreach (['name', 'recurrence', 'time_local'] as $k) { if (!isset($v[$k])) { throw new ApiError(422, 'validation_failed'); } }
        if ($v['recurrence'] === 'weekly') {
            if (!$v['days']) { throw new ApiError(422, 'validation_failed', [], 'invalid_days'); }
            $v['run_on'] = null;
        } else {
            if ($v['run_on'] === null) { throw new ApiError(422, 'validation_failed', [], 'invalid_run_on'); }
            $v['days'] = []; $v['valid_until'] = null;
        }
        if ($v['valid_until'] !== null && $v['valid_until'] < $v['valid_from']) { throw new ApiError(422, 'validation_failed', [], 'invalid_range'); }
        if (preg_match('/\p{Cc}/u', (string) $v['message'])) { throw new ApiError(422, 'validation_failed'); }
        // El permiso del contrato es equipos.apagar; reiniciar exige además el suyo (y cambiar de reinicio a apagado, ambos).
        foreach (array_unique([$v['action'], $old['action'] ?? $v['action']]) as $act) {
            if (!$this->access->has(self::permissionFor($act))) { throw new ApiError(403, 'permission_denied'); }
        }
        $targets = array_map(fn ($t) => (object) $t, $v['targets']); $excl = array_map(fn ($t) => (object) $t, $v['exclusions']);
        if (!$targets) { throw new ApiError(422, 'validation_failed'); }
        foreach (array_merge($targets, $excl) as $t) { $this->checkTarget($t->kind, Util::bin($t->id)); }

        $mask = 0; foreach ($v['days'] as $d) { $mask |= 1 << ($d - 1); }
        $cols = ['name' => $v['name'], 'action' => $v['action'], 'recurrence' => $v['recurrence'], 'days_mask' => $mask, 'time_local' => $v['time_local'],
            'run_on' => $v['run_on'], 'valid_from' => $v['valid_from'], 'valid_until' => $v['valid_until'], 'message' => $v['message'],
            'idle_minutes' => $v['idle_minutes'], 'notice_minutes' => $v['notice_minutes'], 'postpone_minutes' => $v['postpone_minutes'],
            'window_minutes' => $v['window_minutes'], 'status' => $v['status']];
        if ($old) {
            $set = implode(',', array_map(fn ($k) => "$k=?", array_keys($cols)));
            $this->db->run("UPDATE power_schedules SET $set, version=version+1 WHERE tenant_id=? AND id=?", [...array_values($cols), $this->tenant, $id]);
        } else {
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO power_schedules (tenant_id,id,created_by,' . implode(',', array_keys($cols)) . ') VALUES (?,?,?' . str_repeat(',?', count($cols)) . ')',
                [$this->tenant, $id, $this->c['principal_id'], ...array_values($cols)]);
        }
        $this->db->run('DELETE FROM power_schedule_targets WHERE tenant_id=? AND schedule_id=?', [$this->tenant, $id]);
        foreach ([[$targets, 0], [$excl, 1]] as [$list, $ex]) {
            foreach ($list as $t) {
                $this->db->run('INSERT IGNORE INTO power_schedule_targets (tenant_id,id,schedule_id,kind,ref_id,excluded) VALUES (?,?,?,?,?,?)',
                    [$this->tenant, Util::bin(Util::uuid()), $id, $t->kind, Util::bin($t->id), $ex]);
            }
        }
        (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'power_schedule', $id, array_keys(get_object_vars($b)));
        return AdminApi::response($this->dto($this->load($id, false)), $old ? 200 : 201, ['ETag' => '"' . $this->load($id, false)['version'] . '"']);
    }

    // Mismo criterio que AdminApi::policyTarget(): empresa exige alcance total; persona/equipo dentro del alcance; sede/área activas y permitidas.
    private function checkTarget(string $kind, string $ref): void
    {
        if (!in_array($kind, self::KINDS, true)) { throw new ApiError(422, 'validation_failed'); }
        if ($kind === 'tenant') { if ($ref !== $this->tenant) { throw new ApiError(404, 'resource_not_found'); } $this->access->requireFull(); return; }
        if ($kind === 'user') { $this->access->user($ref); return; }
        if ($kind === 'device') {
            $d = $this->db->one('SELECT user_id FROM devices WHERE tenant_id=? AND id=?', [$this->tenant, $ref]);
            if (!$d) { throw new ApiError(404, 'resource_not_found'); } $this->access->user($d['user_id']); return;
        }
        if (!$this->db->one("SELECT 1 FROM org_units WHERE tenant_id=? AND id=? AND kind=? AND status='active'", [$this->tenant, $ref, $kind])) { throw new ApiError(422, 'validation_failed'); }
        if (!$this->access->allowsUnitTarget($kind, $ref)) { throw new ApiError(403, 'permission_denied', [], 'scope_denied'); }
    }

    private function delete(string $id): array
    {
        $old = $this->load($id, true);
        if ($this->r->header('if-match') !== '"' . $old['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
        if (!$this->access->has(self::permissionFor($old['action']))) { throw new ApiError(403, 'permission_denied'); }
        $this->db->run('DELETE FROM power_schedules WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'power_schedule', $id, ['deleted']);
        return AdminApi::response(null, 204);
    }

    private function runs(string $id): array
    {
        $this->load($id, false);
        // Última fila por equipo (la más reciente por occurrence_at y reported_at).
        $rows = $this->db->run('SELECT r.*, d.hostname FROM power_schedule_runs r JOIN devices d ON d.tenant_id=r.tenant_id AND d.id=r.device_id
            WHERE r.tenant_id=? AND r.schedule_id=? AND NOT EXISTS (SELECT 1 FROM power_schedule_runs n WHERE n.tenant_id=r.tenant_id AND n.schedule_id=r.schedule_id
            AND n.device_id=r.device_id AND (n.occurrence_at>r.occurrence_at OR (n.occurrence_at=r.occurrence_at AND n.reported_at>r.reported_at))) ORDER BY d.hostname LIMIT 1000', [$this->tenant, $id])->fetchAll();
        $visible = array_filter($rows, fn ($x) => $this->access->sees(['id' => $this->db->one('SELECT user_id FROM devices WHERE tenant_id=? AND id=?', [$this->tenant, $x['device_id']])['user_id']]));
        return AdminApi::response(['data' => array_values(array_map(fn ($x) => ['device_id' => Util::id($x['device_id']), 'hostname' => $x['hostname'],
            'occurrence_at' => Util::time($x['occurrence_at']), 'outcome' => $x['outcome'], 'detail' => $x['detail'], 'reported_at' => Util::time($x['reported_at'])], $visible))]);
    }

    private function dto(array $s): array
    {
        $t = $this->db->run('SELECT kind, ref_id, excluded FROM power_schedule_targets WHERE tenant_id=? AND schedule_id=? ORDER BY kind, ref_id', [$this->tenant, $s['id']])->fetchAll();
        $map = fn ($ex) => array_values(array_map(fn ($x) => ['kind' => $x['kind'], 'id' => Util::id($x['ref_id'])], array_filter($t, fn ($x) => (bool) $x['excluded'] === $ex)));
        $days = []; for ($d = 1; $d <= 7; $d++) { if ((int) $s['days_mask'] & (1 << ($d - 1))) { $days[] = $d; } }
        return ['id' => Util::id($s['id']), 'name' => $s['name'], 'action' => $s['action'], 'recurrence' => $s['recurrence'], 'days' => $days,
            'time_local' => $s['time_local'], 'run_on' => $s['run_on'], 'valid_from' => $s['valid_from'], 'valid_until' => $s['valid_until'],
            'targets' => $map(false), 'exclusions' => $map(true), 'message' => $s['message'], 'idle_minutes' => (int) $s['idle_minutes'],
            'notice_minutes' => (int) $s['notice_minutes'], 'postpone_minutes' => $s['postpone_minutes'] === null ? null : (int) $s['postpone_minutes'],
            'window_minutes' => (int) $s['window_minutes'], 'status' => $s['status'], 'version' => (int) $s['version'],
            'created_at' => Util::time($s['created_at']), 'updated_at' => Util::time($s['updated_at'])];
    }
}
```

Verificar firmas reales antes de usar: `AdminAccess::sees()` (L153) — si recibe otra forma, adaptar el filtro de `runs()`; `Util::time`, `Util::id`, `Util::bin`, `Util::uuid`; `$c['principal_id']` (si `created_by` debe ser el admin, usar el campo que use `tenant_windows_security.updated_by`).

- [ ] **Step 4: Cablear en `AdminApi`**

En `handle()` (match L169-220) añadir:
```php
'listPowerSchedules','getPowerSchedule','createPowerSchedule','patchPowerSchedule','deletePowerSchedule','listPowerScheduleRuns'
    => (new PowerSchedules($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
```
El permiso del contrato (`equipos.apagar`) se mantiene en el constructor; el chequeo de `equipos.reiniciar` lo hace `PowerSchedules::save/delete` con `$access->has()` (no hace falta override en el constructor).

- [ ] **Step 5: Registrar el test** — en `smoke.php` `require __DIR__ . '/power-schedules.php';` y en el flujo principal `powerScheduleAdminTests($db);` junto a `windowsSecurityTests($db)` (L524); flag `KEEPER_TEST_POWER_SCHEDULE_ONLY` como los de L185-188; en `mysql-smoke.ps1` parámetro `-PowerScheduleOnly` que exporta ese env (copiar `-WindowsSecurityOnly`).

- [ ] **Step 6: Pasar** — `mysql-smoke.ps1 -PowerScheduleOnly -DebugResponses` → PASS; luego suite completa → `SMOKE PASS`.

- [ ] **Step 7: Commit** `git commit -m "Apagado programado: CRUD admin con permisos por acción y alcance"`

---

### Task 3: Resolución por equipo, sync y resultados

**Files:**
- Modify: `backend-v4/src/PowerSchedules.php` (añadir `clientSchedules`, `ingestRuns`)
- Modify: `backend-v4/src/Application.php` (`case '/client/sync':` L183-236)
- Modify: `backend-v4/tests/power-schedules.php` (función `powerScheduleSyncTests`)

**Interfaces:**
- Consumes: Task 1-2.
- Produces: `public function clientSchedules(string $device): array` (lista de `PowerScheduleDirective` como arrays) y `public function ingestRuns(string $device, array $runs): array` (devuelve los `event_id` aceptados o duplicados, como uuids texto).

- [ ] **Step 1: Tests que fallan**

```php
function powerScheduleSyncTests(Database $db): void
{
    // Reusar el patrón de tests/windows-security.php: enroll + $sync($key,$token,$n) + UPDATE devices SET agent_version.
    [$a, $key, $token, $device, $sync] = powerScheduleDevice($db);   // helper local copiado de windows-security.php
    $admin = adminFixture($db, $a, ['equipos.ver', 'equipos.apagar', 'equipos.reiniciar']);
    $tenantId = Util::id($a['tenant_id']); $deviceId = Util::id($device);
    $s = expect(adminRequest($admin, 'POST', '/admin/power-schedules', ['name' => 'Todos', 'recurrence' => 'weekly', 'days' => [1, 5], 'time_local' => '19:00',
        'valid_from' => '2026-01-01', 'targets' => [['kind' => 'tenant', 'id' => $tenantId]], 'message' => 'Hasta el lunes']), 201, 'create');

    $db->run("UPDATE devices SET agent_version='4.0.29' WHERE tenant_id=? AND id=?", [$a['tenant_id'], $device]);
    check(!array_key_exists('power_schedules', $sync($key, $token, 1)), 'agent 4.0.29 never receives power_schedules');
    $db->run("UPDATE devices SET agent_version='4.0.30' WHERE tenant_id=? AND id=?", [$a['tenant_id'], $device]);
    $got = $sync($key, $token, 2)['power_schedules'];
    check(count($got) === 1 && $got[0]['id'] === $s['id'] && $got[0]['days'] === [1, 5] && $got[0]['timezone'] === 'America/Bogota' && $got[0]['message'] === 'Hasta el lunes', 'resolved directive');
    check(!isset($got[0]['targets']) && !isset($got[0]['exclusions']), 'no target lists sent');

    // Excepción por equipo gana sobre la empresa.
    expect(adminRequest($admin, 'PATCH', '/admin/power-schedules/' . $s['id'], ['exclusions' => [['kind' => 'device', 'id' => $deviceId]]], ['if-match' => '"1"']), 200, 'exclude device');
    check($sync($key, $token, 3)['power_schedules'] === [], 'excluded device gets nothing');
    // Pausada o vencida: no se envía.
    // Mensaje vacío -> texto por defecto.
    // (añadir ambos casos con el mismo patrón)

    // Resultados idempotentes.
    expect(adminRequest($admin, 'PATCH', '/admin/power-schedules/' . $s['id'], ['exclusions' => []], ['if-match' => '"2"']), 200, 'include again');
    $ev = Util::uuid();
    $run = ['event_id' => $ev, 'schedule_id' => $s['id'], 'occurrence_at' => '2026-10-30T00:00:00.000000Z', 'outcome' => 'executed_idle'];
    $r1 = $sync($key, $token, 4, ['power_schedule_runs' => [$run]]);
    check($r1['power_schedule_acks'] === [$ev], 'run acked');
    $r2 = $sync($key, $token, 5, ['power_schedule_runs' => [$run]]);
    check($r2['power_schedule_acks'] === [$ev], 'duplicate acked, not duplicated');
    $runs = expect(adminRequest($admin, 'GET', '/admin/power-schedules/' . $s['id'] . '/runs'), 200, 'runs');
    check(count($runs['data']) === 1 && $runs['data'][0]['outcome'] === 'executed_idle', 'run visible in panel');
    // Programación de otra empresa: rechazada sin romper el sync.
}
```

(Si el helper `$sync` de `windows-security.php` no acepta cuerpo extra, extenderlo con un 4º parámetro opcional que se mezcla en el cuerpo del `SyncRequest`.)

- [ ] **Step 2: Correr y ver fallar.**

- [ ] **Step 3: Implementar resolución**

```php
    // Programaciones activas y vigentes que aplican a este equipo: incluidas por algún alcance y sin ninguna exclusión.
    // Equipo -> persona por device_assignments vigente; persona -> sede/área por user_assignments vigente (o users), como PolicyCompiler::snapshot().
    public function clientSchedules(string $device): array
    {
        $now = Util::now();
        $d = $this->db->one('SELECT d.id, COALESCE(a.user_id, d.user_id) user_id FROM devices d LEFT JOIN device_assignments a ON a.tenant_id=d.tenant_id AND a.device_id=d.id
            AND a.starts_at<=? AND (a.ends_at IS NULL OR a.ends_at>?) WHERE d.tenant_id=? AND d.id=?', [$now, $now, $this->tenant, $device]);
        if (!$d) { return []; }
        $org = $this->db->one('SELECT site_id, area_id FROM user_assignments WHERE tenant_id=? AND user_id=? AND starts_at<=? AND (ends_at IS NULL OR ends_at>?)', [$this->tenant, $d['user_id'], $now, $now])
            ?? $this->db->one('SELECT site_id, area_id FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $d['user_id']]);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($this->c['timezone'])))->format('Y-m-d');
        $match = "(t.kind='tenant' AND t.ref_id=s.tenant_id) OR (t.kind='device' AND t.ref_id=?) OR (t.kind='user' AND t.ref_id=?) OR (t.kind='site' AND t.ref_id<=>?) OR (t.kind='area' AND t.ref_id<=>?)";
        $args = [$device, $d['user_id'], $org['site_id'] ?? null, $org['area_id'] ?? null];
        $rows = $this->db->run("SELECT s.* FROM power_schedules s WHERE s.tenant_id=? AND s.status='active'
            AND ((s.recurrence='once' AND s.run_on>=DATE_SUB(?, INTERVAL 1 DAY)) OR (s.recurrence='weekly' AND s.valid_from<=DATE_ADD(?, INTERVAL 7 DAY) AND (s.valid_until IS NULL OR s.valid_until>=DATE_SUB(?, INTERVAL 1 DAY))))
            AND EXISTS (SELECT 1 FROM power_schedule_targets t WHERE t.tenant_id=s.tenant_id AND t.schedule_id=s.id AND t.excluded=FALSE AND ($match))
            AND NOT EXISTS (SELECT 1 FROM power_schedule_targets t WHERE t.tenant_id=s.tenant_id AND t.schedule_id=s.id AND t.excluded=TRUE AND ($match))
            ORDER BY s.created_at LIMIT 50", [$this->tenant, $today, $today, $today, ...$args, ...$args])->fetchAll();
        return array_map(function ($s) {
            $dto = $this->dto($s);
            $out = ['id' => $dto['id'], 'version' => $dto['version'], 'action' => $dto['action'], 'recurrence' => $dto['recurrence'], 'time_local' => $dto['time_local'],
                'valid_from' => $dto['valid_from'], 'timezone' => $this->c['timezone'],
                'message' => $dto['message'] !== '' ? $dto['message'] : ($dto['action'] === 'restart' ? 'TI programó el reinicio de este equipo.' : 'TI programó el apagado de este equipo.'),
                'idle_minutes' => $dto['idle_minutes'], 'notice_minutes' => $dto['notice_minutes'], 'window_minutes' => $dto['window_minutes']];
            if ($dto['days']) { $out['days'] = $dto['days']; }
            if ($dto['run_on'] !== null) { $out['run_on'] = $dto['run_on']; }
            if ($dto['valid_until'] !== null) { $out['valid_until'] = $dto['valid_until']; }
            if ($dto['postpone_minutes'] !== null) { $out['postpone_minutes'] = $dto['postpone_minutes']; }
            return $out;
        }, $rows);
    }

    // Resultados del agente: idempotentes por event_id; una programación de otra empresa o borrada se confirma sin guardar.
    public function ingestRuns(string $device, array $runs): array
    {
        $acks = [];
        foreach ($runs as $x) {
            $sid = Util::bin($x->schedule_id);
            if ($this->db->one('SELECT 1 FROM power_schedules WHERE tenant_id=? AND id=?', [$this->tenant, $sid])) {
                $this->db->run('INSERT IGNORE INTO power_schedule_runs (tenant_id,id,schedule_id,device_id,occurrence_at,outcome,detail,event_id) VALUES (?,?,?,?,?,?,?,?)',
                    [$this->tenant, Util::bin(Util::uuid()), $sid, $device, Util::dbTime($x->occurrence_at), $x->outcome, $x->detail ?? null, Util::bin($x->event_id)]);
            }
            $acks[] = strtolower($x->event_id);
        }
        return $acks;
    }
```

(Verificar el nombre del helper que convierte un `date-time` del contrato a `DATETIME(6)` en `Ingest.php` — usar ese en lugar de `Util::dbTime` si se llama distinto. `$c` del agente debe tener `tenant_id` y `timezone`: confirmado en `Auth.php` L169.)

- [ ] **Step 4: Cablear en `Application.php`** (dentro de `case '/client/sync':`, tras `windows_security` L204)

```php
            // Apagado programado: solo agentes >= 4.0.30 (los anteriores rechazan campos desconocidos y dejarían de sincronizar).
            if (version_compare($c['agent_version'], PowerSchedules::MIN_AGENT, '>=')) {
                $power = new PowerSchedules($db, $c, null, $r);
                if (!empty($r->body->power_schedule_runs)) { $response['power_schedule_acks'] = $power->ingestRuns($c['device_id'], $r->body->power_schedule_runs); }
                $response['power_schedules'] = $power->clientSchedules($c['device_id']);
            }
```
(Usar el nombre real de la clave del id de equipo en `$c`, el mismo que usa `Ingest`.)

- [ ] **Step 5: Pasar** `-PowerScheduleOnly -DebugResponses` y suite completa.
- [ ] **Step 6: Commit** `git commit -m "Apagado programado: resolución por equipo en el sync y resultados del agente"`

---

### Task 4: Panel

**Files:**
- Create: `backend-v4/public/apagado-programado.php`, `backend-v4/public/assets/power-schedules.js`
- Modify: `backend-v4/public/assets/k3.js` (router `start()` ~L4585), `backend-v4/public/partials/sidebar.php` (`$portal` grupo `equipos` L61-76), `backend-v4/public/assets/nav-access.js` (`NEEDS`), `backend-v4/public/assets/device-actions.js` (`remoteActionsSection` L182: botón «Programar apagado»)

**Interfaces:**
- Consumes: `api()` de `api.js`, helpers de `k3.js` (`table`, `dialogShell`, `labeled`, `checks`, `confirmBox`, `badge`, `empty`), endpoints Task 2.
- Produces: `export async function powerSchedulesPage({ tenant, content, status, action })` y `export function openPowerScheduleDialog({ tenant, preset, onSaved })`.

- [ ] **Step 1: Página stub** (`apagado-programado.php`)
```php
<?php
$page = 'power-schedules'; $title = 'Apagado programado'; $subtitle = 'Apaga o reinicia equipos a una hora fija, una vez o cada semana.';
require __DIR__ . '/partials/layout-k3.php';
```
- [ ] **Step 2: Menú y acceso** — en `sidebar.php` grupo `equipos`: `['power-schedules', 'Apagado programado', 'apagado-programado.php']` (el texto debe ser igual a `$title`; lo verifica `tests/panel-architecture.php`). En `nav-access.js` `NEEDS`: `'power-schedules': ['equipos.ver', false]`. No tocar `FEATURE_OF` (el test exige exactamente 5).
- [ ] **Step 3: Router** — en `k3.js` `start()`: `else if (page === 'power-schedules') await (await import('./power-schedules.js')).powerSchedulesPage({ tenant, content: $('page-content'), status: $('page-status'), action: $('page-action') });` (seguir el patrón de `protectionPage`).
- [ ] **Step 4: `power-schedules.js`** — lista: columnas Nombre, Acción (Apagar/Reiniciar), Cuándo («Viernes 19:00», «Una vez 30/10 18:00»), Aplica a (n alcances, m excepciones), Estado (badge Activa/Pausada); acciones por fila: Editar, Pausar/Reanudar (`PATCH status`, `ifMatch`), Ver resultados (tabla de `/runs` con Equipo, Ocurrencia en hora local, Resultado en español: `executed_idle`→«Apagado sin uso», `executed_after_notice`→«Ejecutado tras aviso», `postponed`→«Pospuesto», `executed_by_person`→«La persona lo ejecutó», `cancelled_by_it`→«Cancelado por TI», `replaced`→«Reemplazado por orden manual», `skipped_suspended`→«No ejecutado: equipo suspendido», `not_needed`→«No necesario (equipo apagado)», `unsupported`→«No soportado: actualizar agente»), Borrar (confirmación escrita con el nombre). Botón de cabecera «Nueva programación». Diálogo con todos los campos de spec §1 (días como casillas L-D, hora `type=time`, fecha `type=date`, selectores de alcance/excepciones reutilizando los de reglas — buscar en `k3.js` el selector de destino de políticas y reutilizarlo), valores por defecto rellenos, y aviso visible: «Si el equipo está en uso, la persona verá el aviso y podrá posponer una vez.» Errores con `errorMessage(e)`.
  Leer `?equipo=<uuid>` de la URL: si existe, abrir el diálogo prellenado con `recurrence:'once'`, target `device`.
- [ ] **Step 5: Ficha del equipo** — en `remoteActionsSection` añadir enlace/botón «Programar apagado» → `apagado-programado.php?equipo=${d.id}` solo si `remoteAvailability('shutdown', access, d)` lo permite.
- [ ] **Step 6: Comprobar** — `php backend-v4/tests/panel-architecture.php` (o como lo invoque `smoke.php`) y `tests/group-structure.php` pasan; prueba manual en la instancia de prueba local: crear, pausar, ver resultados.
- [ ] **Step 7: Commit** `git commit -m "Apagado programado: página del panel y acceso desde la ficha del equipo"`

---

### Task 5: Calendario de ocurrencias (agente, lógica pura)

**Files:**
- Create: `client-v4/src/Keeper.Agent/Modules/Devices/PowerScheduleCalendar.cs`
- Test: `client-v4/tests/Keeper.Agent.Tests/PowerScheduleCalendarTests.cs`

**Interfaces:**
- Consumes: `PowerScheduleDirective` (Task 1 generado).
- Produces: `public static class PowerScheduleCalendar { public static DateTimeOffset? Latest(PowerScheduleDirective s, DateTimeOffset nowUtc); }` — la ocurrencia más reciente `≤ nowUtc` (UTC) dentro de vigencia, o null. `public static bool InWindow(PowerScheduleDirective s, DateTimeOffset occurrence, DateTimeOffset nowUtc)` → `nowUtc - occurrence <= WindowMinutes`.

- [ ] **Step 1: Tests**

```csharp
using Keeper.Agent.Modules.Devices;
using Keeper.Shared.Protocol;

public class PowerScheduleCalendarTests
{
    private static PowerScheduleDirective Weekly(params long[] days) => new()
    {
        Id = Guid.NewGuid(), Version = 1, Action = PowerScheduleDirectiveAction.Shutdown, Recurrence = PowerScheduleDirectiveRecurrence.Weekly,
        Days = [.. days], TimeLocal = "19:00", ValidFrom = new DateOnly(2026, 10, 1), Timezone = "America/Bogota", Message = "m",
        IdleMinutes = 15, NoticeMinutes = 10, WindowMinutes = 180,
    };

    [Fact]
    public void ViernesALas19DeBogotaEsMedianocheUtc()
    {
        // 2026-10-30 es viernes; 19:00 Bogotá (UTC-5) = 2026-10-31 00:00Z.
        var now = new DateTimeOffset(2026, 10, 31, 0, 5, 0, TimeSpan.Zero);
        Assert.Equal(new DateTimeOffset(2026, 10, 31, 0, 0, 0, TimeSpan.Zero), PowerScheduleCalendar.Latest(Weekly(5), now));
    }

    [Fact]
    public void AntesDeLaHoraDevuelveLaSemanaAnterior()
    {
        var now = new DateTimeOffset(2026, 10, 30, 23, 0, 0, TimeSpan.Zero); // viernes 18:00 Bogotá
        Assert.Equal(new DateTimeOffset(2026, 10, 24, 0, 0, 0, TimeSpan.Zero), PowerScheduleCalendar.Latest(Weekly(5), now));
    }

    [Fact]
    public void FueraDeVigenciaNoHayOcurrencia()
    {
        var s = Weekly(5) with { ValidFrom = new DateOnly(2026, 11, 1) };
        Assert.Null(PowerScheduleCalendar.Latest(s, new DateTimeOffset(2026, 10, 31, 0, 5, 0, TimeSpan.Zero)));
        var ended = Weekly(5) with { ValidUntil = new DateOnly(2026, 10, 29) };
        Assert.Equal(new DateTimeOffset(2026, 10, 24, 0, 0, 0, TimeSpan.Zero), PowerScheduleCalendar.Latest(ended, new DateTimeOffset(2026, 10, 31, 0, 5, 0, TimeSpan.Zero)));
    }

    [Fact]
    public void UnaVez()
    {
        var s = Weekly() with { Recurrence = PowerScheduleDirectiveRecurrence.Once, Days = null, RunOn = new DateOnly(2026, 10, 28), TimeLocal = "18:30" };
        Assert.Null(PowerScheduleCalendar.Latest(s, new DateTimeOffset(2026, 10, 28, 23, 29, 0, TimeSpan.Zero)));
        Assert.Equal(new DateTimeOffset(2026, 10, 28, 23, 30, 0, TimeSpan.Zero), PowerScheduleCalendar.Latest(s, new DateTimeOffset(2026, 10, 29, 3, 0, 0, TimeSpan.Zero)));
    }

    [Fact]
    public void VentanaDeTresHoras()
    {
        var s = Weekly(5); var occ = new DateTimeOffset(2026, 10, 31, 0, 0, 0, TimeSpan.Zero);
        Assert.True(PowerScheduleCalendar.InWindow(s, occ, occ.AddMinutes(180)));
        Assert.False(PowerScheduleCalendar.InWindow(s, occ, occ.AddMinutes(181)));
    }

    [Fact]
    public void ZonaHorariaInvalidaNoProgramaNada() =>
        Assert.Null(PowerScheduleCalendar.Latest(Weekly(5) with { Timezone = "Nada/Nada" }, DateTimeOffset.UtcNow));
}
```
(Si el generador produce `record` con `required`/`init`, `with` funciona; si genera `sealed class`, construir instancias completas en un helper.)

- [ ] **Step 2: Ver fallar** — `dotnet test client-v4/tests/Keeper.Agent.Tests/Keeper.Agent.Tests.csproj -c Release -m:1 -nodeReuse:false --filter "FullyQualifiedName~PowerScheduleCalendar"` → error de compilación (clase inexistente).

- [ ] **Step 3: Implementar**

```csharp
using System.Globalization;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Devices;

/// <summary>Ocurrencias de una programación en la zona horaria de la empresa (IANA, igual que PolicyCoordinator).</summary>
public static class PowerScheduleCalendar
{
    public static DateTimeOffset? Latest(PowerScheduleDirective s, DateTimeOffset nowUtc)
    {
        TimeZoneInfo zone;
        try { zone = TimeZoneInfo.FindSystemTimeZoneById(s.Timezone); }
        catch (Exception ex) when (ex is TimeZoneNotFoundException or InvalidTimeZoneException) { return null; }
        if (!TimeOnly.TryParseExact(s.TimeLocal, "HH:mm", CultureInfo.InvariantCulture, DateTimeStyles.None, out var time)) { return null; }
        var today = DateOnly.FromDateTime(TimeZoneInfo.ConvertTime(nowUtc, zone).DateTime);
        // Se mira hoy y los 7 días anteriores: basta para semanal y para "una vez" reciente.
        for (var back = 0; back <= 7; back++)
        {
            var day = today.AddDays(-back);
            if (s.Recurrence == PowerScheduleDirectiveRecurrence.Once ? day != s.RunOn : !Weekly(s, day)) continue;
            var local = day.ToDateTime(time, DateTimeKind.Unspecified);
            if (zone.IsInvalidTime(local)) local = local.AddHours(1); // salto de horario de verano: la hora no existe
            var utc = new DateTimeOffset(TimeZoneInfo.ConvertTimeToUtc(local, zone), TimeSpan.Zero);
            if (utc <= nowUtc) return utc;
        }
        return null;
    }

    public static bool InWindow(PowerScheduleDirective s, DateTimeOffset occurrence, DateTimeOffset nowUtc) =>
        nowUtc >= occurrence && nowUtc - occurrence <= TimeSpan.FromMinutes(s.WindowMinutes);

    private static bool Weekly(PowerScheduleDirective s, DateOnly day)
    {
        if (day < s.ValidFrom || (s.ValidUntil is { } until && day > until)) return false;
        var iso = day.DayOfWeek == DayOfWeek.Sunday ? 7 : (long)day.DayOfWeek;
        return s.Days?.Contains(iso) == true;
    }
}
```
(Ajustar tipos a lo que genere `generate.mjs`: `Days` puede ser `List<long>`/`ICollection<long>`; `Version`/minutos son `long`.)

- [ ] **Step 4: Pasar** el filtro → PASS.
- [ ] **Step 5: Commit** `git commit -m "Apagado programado: calendario de ocurrencias en el agente"`

---

### Task 6: Protocolo de sesión, inactividad y aviso con «Posponer»

**Files:**
- Modify: `client-v4/src/Keeper.Shared/Contracts/SessionProtocol.cs` (L19-20 `PowerNotice`; `SessionResponse`; `Validate` L30-53)
- Modify: `client-v4/src/Keeper.Session/Ipc/SessionClient.cs`, `client-v4/src/Keeper.Session/Ipc/SessionBuffer.cs` (L54), `client-v4/src/Keeper.Session/Program.cs` (L36, L50, L56)
- Modify: `client-v4/src/Keeper.Session/Modules/UI/PowerNoticeWindow.cs`
- Modify: `client-v4/src/Keeper.Agent/Hosting/SessionSupervisor.cs` (`Peer` L169-179, `ExchangeAsync` L106-136)
- Test: `client-v4/tests/Keeper.Agent.Tests/PowerNoticeTests.cs`, `IpcTests.cs`

**Interfaces:**
- Produces:
  ```csharp
  public sealed record PowerNotice(PowerNoticeAction Action, DateTimeOffset Deadline, bool Cancelled = false,
      string? Message = null, long? PostponeMinutes = null, bool Compact = false, Guid? RunId = null);
  public enum PowerChoiceKind { Postpone, Now }
  public sealed record PowerChoice(Guid RunId, PowerChoiceKind Kind);
  // SessionResponse gana: long? IdleSeconds = null, PowerChoice? PowerChoice = null
  ```
  En `SessionSupervisor`: `public IReadOnlyList<long?> IdleSeconds()` (una entrada por sesión verificada; `null` = sin dato) y `public Action<PowerChoice>? OnPowerChoice { get; init; }`.
  En `PowerNoticeWindow`: `public PowerChoice? TakeChoice()`.
- `Validate`: `IdleSeconds` null o 0..31_536_000; `Message` ≤ 280 sin control (solo en request, el agente lo genera); `PowerChoice.RunId != Guid.Empty`.

- [ ] **Step 1: Tests** — en `IpcTests.cs`: round-trip JSON de `SessionResponse` con `IdleSeconds=42` y `PowerChoice(id, Postpone)` → `"idle_seconds":42`, `"power_choice":{"run_id":"…","kind":"postpone"}`; campos omitidos cuando son null (compatibilidad). `Validate` rechaza `IdleSeconds=-1`. En `PowerNoticeTests.cs`: `PowerNoticeText.Message` usa el mensaje de TI si viene (`"Hasta el lunes"` aparece en el texto) y conserva el texto actual si no; supervisor: con `FakeConnection` que responde `IdleSeconds=30`, `IdleSeconds()` devuelve `[30]`; si responde `PowerChoice`, `OnPowerChoice` se invoca una vez.
- [ ] **Step 2: Ver fallar** (`--filter "FullyQualifiedName~Ipc|FullyQualifiedName~PowerNotice"`).
- [ ] **Step 3: Implementar**
  - `SessionProtocol.cs`: ampliar `PowerNotice` y `SessionResponse` con parámetros opcionales al final (no romper constructores existentes); validar.
  - `SessionClient`: recibir `IInputIdleSource idle` y `Func<bool> locked`; en cada respuesta `IdleSeconds = locked() ? 31_536_000 : (long)idle.GetIdleTime().TotalSeconds` (DeviceLock no cuenta como uso, spec §3) y `PowerChoice = notice?.TakeChoice()`. Pasar ambos por `SessionBuffer.Response(...)` (añadir parámetros opcionales).
  - `Program.cs` (Session): reutilizar la instancia `WindowsInputIdleSource` de L50 y la expresión de bloqueo de L51 para `SessionClient`.
  - `PowerNoticeWindow`: cuando `notice.PostponeMinutes is not null` y no `Cancelled` y no `Compact`, mostrar botones «Posponer {N}» y «{Apagar|Reiniciar} ahora» en el panel inferior junto a `_toggle`; al pulsar, guardar `PowerChoice(notice.RunId!.Value, kind)` en un campo `volatile` y ocultar el botón; `TakeChoice()` lo devuelve una vez y lo limpia. `Compact = true` → abrir directamente en modo compacto (tarjeta abajo a la derecha) sin botón de posponer. `PowerNoticeText.Message(action, remaining, message)` antepone el mensaje de TI si existe. Mantener `ShowWithoutActivation` (no robar foco).
  - `SessionSupervisor`: guardar `IdleSeconds` de cada respuesta en `Peer.Idle`; `IdleSeconds()` devuelve los de peers verificados; si la respuesta trae `PowerChoice`, invocar `OnPowerChoice`.
- [ ] **Step 4: Pasar** el filtro y luego `dotnet test client-v4/Keeper.sln -maxcpucount:1 -nodeReuse:false`.
- [ ] **Step 5: Commit** `git commit -m "Apagado programado: inactividad por sesión y aviso con Posponer"`

---

### Task 7: Módulo `PowerScheduler` y resultados

**Files:**
- Create: `client-v4/src/Keeper.Agent/Modules/Devices/PowerScheduler.cs`
- Modify: `client-v4/src/Keeper.Agent/Program.cs` (moduleList L115-121; `OnResponse` L170-177; `SessionSupervisor` L138-139: `powerNotice` = `() => commands.PendingNotice ?? scheduler.PendingNotice`, `OnPowerChoice = scheduler.Choose`)
- Modify: outbox (`IEventSink` en `client-v4/src/Keeper.Shared/Contracts/Modules.cs` + su implementación + armado del `SyncRequest` + manejo de `power_schedule_acks`) **copiando exactamente el camino de `LocationPoint`/`locations`/`location_acks`** (buscar `LocationPoint` y `LocationAcks` en el agente).
- Test: `client-v4/tests/Keeper.Agent.Tests/PowerSchedulerTests.cs`; `MemoryEvents` en `TestSupport.cs` gana `PowerRuns`.

**Interfaces:**
- Consumes: `PowerScheduleCalendar` (Task 5), `PowerNotice`/`PowerChoice`/`SessionSupervisor.IdleSeconds()` (Task 6), `IDeviceActions`, `CommandExecutor.PendingNotice`, `AtomicFile`.
- Produces:
  ```csharp
  public sealed class PowerScheduler(string statePath, IDeviceActions actions, Func<IReadOnlyList<long?>> idleSeconds,
      Func<PowerNotice?> manualNotice, Func<DateTimeOffset> bootTimeUtc) : ModuleBase
  {
      public override string Name => "PowerScheduler";
      public PowerNotice? PendingNotice { get; }
      public Task OfferAsync(IReadOnlyList<PowerScheduleDirective>? schedules, CancellationToken ct); // desde OnResponse
      public void Choose(PowerChoice choice);                                                       // desde SessionSupervisor
  }
  // IEventSink: Task EnqueueAsync(PowerScheduleRunReport run, CancellationToken ct)
  ```
  Estado persistido (`power-schedules.json`, `ProtocolJson.Options`): `Schedules` (última lista recibida), `Active` (`RunId, ScheduleId, Version, Occurrence, Action, Deadline?, Postponed, Started`), `Done` (pares `ScheduleId+Occurrence` de los últimos 14 días).

- [ ] **Step 1: Tests** (rig como `PowerNoticeTests`: `TestClock`, `TestDirectory`, `RecordingActions`, `MemoryEvents`; idle inyectado como lista mutable)

Casos (uno por `[Fact]`, todos con programación semanal viernes 19:00 Bogotá):
1. `SinUsoSeEjecutaSinPreguntar`: idle `[1200]` (20 min) al llegar la hora → `Actions.Executed == [Shutdown]`, run `executed_idle`, sin `PendingNotice`.
2. `SinSesionesSeEjecuta`: idle `[]` → igual que 1.
3. `EnUsoAvisaYEjecutaAlVencer`: idle `[30]` → `PendingNotice` con `Deadline = hora + 10 min`, `Message`, `PostponeMinutes = 60`, `Compact=false`; tras `Advance(10 min)` → `Shutdown`, run `executed_after_notice`.
4. `PosponerUnaSolaVez`: aviso; `Choose(Postpone)` → run `postponed`, `PendingNotice == null`; `Advance(50 min)` → `PendingNotice` con `Compact = true`, `PostponeMinutes = null`, `Deadline = +60 min`; un segundo `Choose(Postpone)` se ignora; `Advance(10 min)` → `Shutdown`, run `executed_after_notice`.
5. `AhoraLoEjecutaLaPersona`: `Choose(Now)` → `Shutdown` inmediato, run `executed_by_person`.
6. `OrdenManualGana`: `manualNotice` devuelve un aviso → la programada no muestra nada, run `replaced`, sin ejecutar.
7. `TiPausaDuranteElAviso`: aviso activo; `OfferAsync([])` → `PendingNotice` con `Cancelled = true` durante 60 s, run `cancelled_by_it`, sin ejecutar.
8. `SuspendidoFueraDeVentana`: el reloj salta de 18:00 a 22:30 (`ShiftWallClock`), `bootTimeUtc` anterior a la ocurrencia → run `skipped_suspended`, sin ejecutar.
9. `SuspendidoDentroDeVentana`: salta a 21:00 → misma lógica que 1/3.
10. `EquipoApagadoAntes`: `bootTimeUtc` posterior a la ocurrencia → run `not_needed`.
11. `ReinicioGanaEnElMismoMinuto`: dos programaciones a la misma hora (shutdown y restart) → una sola ejecución `Restart`; la otra `replaced`.
12. `SobreviveAlReinicioDelAgente`: aviso activo pospuesto; crear un `PowerScheduler` nuevo sobre el mismo `statePath` → conserva `Postponed` y `Deadline`; no repite `postponed`.
13. `NoRepiteLaMismaOcurrencia`: tras ejecutar, otro tick dentro de la ventana no vuelve a ejecutar.
14. `DryRun`: `actions` lanza `NotSupportedException("device_actions_dry_run")` → no se cae el módulo; se reporta en log, sin run de ejecución.

- [ ] **Step 2: Ver fallar** (`--filter "FullyQualifiedName~PowerScheduler"`).
- [ ] **Step 3: Implementar** el módulo con este algoritmo (en `TickAsync`, limitado a cada 30 s salvo con aviso activo, que se revisa cada tick):
  1. Si `Active` es null: para cada programación, `occ = Latest(s, now)`; descartar si `occ` es null, si `(ScheduleId, occ)` está en `Done`, o si `!InWindow`. Si `bootTimeUtc() > occ` → `not_needed`; si fuera de ventana y el equipo estuvo suspendido (`occ < bootTime` falso y `now - occ > window`) → `skipped_suspended`. Agrupar las que caen en el mismo minuto: elegir `restart` si hay; las otras → `replaced`.
  2. Elegida una: si `manualNotice()` no es null → `replaced`. Si `idleSeconds()` está vacía o todas `≥ IdleMinutes*60` → ejecutar → `executed_idle`. Si no → `Active` con `Deadline = now + NoticeMinutes`, guardar.
  3. Con `Active`: `PendingNotice` = aviso con `Message`, `PostponeMinutes` (null si `Postponed`), `Compact = Postponed`, `RunId`; si `Postponed` y faltan > `NoticeMinutes` para `Deadline` → `PendingNotice = null` (aún no se muestra). Al llegar a `Deadline` → `Started = true`, guardar, ejecutar → `executed_after_notice`. Si la programación desaparece o cambia de `Version` en `OfferAsync` → aviso cancelado 60 s → `cancelled_by_it`.
  4. `Choose(Postpone)` solo si `!Postponed && PostponeMinutes is not null && RunId` coincide: `Postponed = true`, `Deadline = now + PostponeMinutes`, run `postponed`. `Choose(Now)`: ejecutar → `executed_by_person`.
  5. Toda run va a `Context.Outbox.EnqueueAsync(new PowerScheduleRunReport{EventId = Guid.NewGuid(), ScheduleId, OccurrenceAt, Outcome, Detail})` y la ocurrencia a `Done`. Guardar con `AtomicFile.WriteAsync` antes de ejecutar (`Started = true` evita repetir tras un corte).
  `bootTimeUtc` en producción: `() => DateTimeOffset.UtcNow - TimeSpan.FromMilliseconds(Environment.TickCount64)`.
- [ ] **Step 4: Cablear** en `Program.cs`: crear `scheduler` antes de `SessionSupervisor`; añadirlo a `moduleList`; `OnResponse`: `await scheduler.OfferAsync(response.PowerSchedules, ct);`; outbox y acks como `locations`.
- [ ] **Step 5: Pasar** el filtro y la solución completa (`dotnet test client-v4/Keeper.sln -maxcpucount:1 -nodeReuse:false`); restaurar `packages.lock.json` si cambian.
- [ ] **Step 6: Commit** `git commit -m "Apagado programado: módulo PowerScheduler en el agente"`

---

### Task 8: Verificación integral y documentación

**Files:**
- Modify: `backend-v4/ADMIN.md` (sección «Apagado programado»: endpoints, permisos, versión mínima 4.0.30, outcomes, comportamiento sin uso/en uso/posponer/ventana)
- Modify: `docs/architecture/v4-client-agent.md` (módulo `PowerScheduler`)

- [ ] **Step 1:** Suite completa servidor en MySQL y en MariaDB (`-MariaDbDirectory C:\tools\mariadb-10.5.29-winx64` o la instancia `/dev/shm` de devkeep con `nice -n 19 ionice -c3`, archivo con `backend-v4` y `docs`) → `SMOKE PASS` en ambas.
- [ ] **Step 2:** `cd client-v4/tools && npm run check` → sin diferencias; `dotnet test client-v4/Keeper.sln -maxcpucount:1 -nodeReuse:false` → todo verde.
- [ ] **Step 3:** Prueba de integración local con `tests/Keeper.Agent.IntegrationHarness` si soporta sync real: programación `once` a 2 min con agente en dry-run → run `unsupported`/log `device_actions_dry_run` sin apagar. Si el harness no lo soporta, documentarlo y dejar la prueba real para el piloto (spec §4).
- [ ] **Step 4:** Documentación y commit `git commit -m "Apagado programado: documentación"`.

---

## Self-review (hecho)

- Cobertura spec: §1 campos/defectos/reglas → Task 1-2; permisos/alcance → Task 2; §2 servidor/sync/resultados → Task 3; agente → Task 5, 7; sesión → Task 6; panel → Task 4; §3 casos límite → Task 7 tests 6-14 (reloj desfasado: `PowerScheduleCalendar` usa `nowUtc` del `TimeProvider`; el agente ya corrige con `ServerClock` — usar `Context.Clock.GetUtcNow()` + `serverClock.Confirmed` si |offset| > 5 min, igual que `CommandExecutor.ServerOffset`); §4 pruebas → Task 2,3,5,6,7,8; §5 despliegue fuera de este plan.
- Cambio respecto a la spec: versión mínima **4.0.30** (no 4.0.29), porque 4.0.29 ya es la de Ubicación.
- Tipos consistentes: `PowerScheduleDirective`, `PowerScheduleRunReport`, `PowerNotice(... Message, PostponeMinutes, Compact, RunId)`, `PowerChoice(RunId, Kind)`, `PowerSchedules::MIN_AGENT`.
