# Planes por persona (fase 2a) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que cada persona tenga un plan de la plataforma (o el plan por defecto de su cliente), y que el equipo de cada persona solo reciba reglas de funciones que su cliente compró **y** su plan incluye.

**Architecture:**
- Planes de la plataforma: `person_plans` y `person_plan_items`, con funciones de nivel persona de `Features::CATALOG`.
- El cliente tiene un plan por defecto (`tenants.default_plan_*`). La persona puede tener un plan explícito (`users.plan_*`).
- `PersonPlans::functions()` resuelve las funciones activas de una persona: funciones del cliente ∩ funciones del plan efectivo.
- El compilador de políticas deja de usar los tiers y módulos viejos. Cada regla pasa si su tipo es núcleo, o si su función (`Features::forRuleKind`) está entre las de la persona.
- Los cambios marcan recompilación y un cron recompila los clientes pendientes.

**Tech Stack:** PHP 8.3 sin framework (backend-v4), MySQL 8.4 local / MariaDB 11.4 producción, OpenAPI `docs/architecture/openapi-v4.yaml` → `backend-v4/config/contract.json`, JS de módulos en `backend-v4/public/assets/`.

## Global Constraints

- **Diseño fuente:** `docs/architecture/v4-clientes-funciones-accesos.md` §3 (planes por persona, sin cupos, conteo para facturar, permiso `planes.asignar`). Esta fase es la 2a.
  - Sin cambios en el agente C#.
  - Decisiones de Koichi 2026-10-03:
    - persona sin plan = plan por defecto del cliente;
    - si el cliente no tiene plan por defecto, su equipo no recibe reglas de funciones vendibles y el panel lo avisa.
- **Funciones de nivel persona:** las de `Features::CATALOG` con `level === 'person'`. Un plan solo puede incluir esas.
- **Tipos de regla:** `web`, `usb`, `download` e `installation` dependen de su función (`Features::forRuleKind`). Los demás (`schedule`, `os`, `encryption`) son núcleo y siempre pasan.
- **Superadmin:** nunca queda bloqueado en la API. El compilador no tiene excepción para superadmin: compila para la persona dueña del equipo.
- **Esquema:**
  - Tablas nuevas con `tenant_id` obligatorio y FKs que incluyen `tenant_id` (lo exige `migrations/verify.php`).
  - Las tablas de plataforma usan `tenant_id = 0x00000000000040008000000000000001` con CHECK.
  - Una columna que apunta a una tabla de plataforma desde un cliente lleva su par `*_tenant_id` (patrón de `tenants.feature_package_*`).
- **Contrato:**
  - Tras editar `openapi-v4.yaml` correr `python tests/export-contract.py` desde `backend-v4`.
  - Raíz de ruta nueva en `admin_roots` de `tests/export-contract.py`.
  - El YAML usa CRLF: preservarlo.
  - Descripciones sin `: `.
- **Auditoría:** toda operación de escritura nueva lleva su texto en `AUDIT_ACTIONS` de `public/assets/k3.js` (lo vigila `tests/ops.php`).
- **Permisos:** todo permiso nuevo se clasifica en `Features.php` (lo vigila `featureCatalogTests`).
- **Suite completa:** desde `backend-v4` (debe terminar en `SMOKE PASS`; base actual 2306):
  `export PATH="$(ls -d /c/laragon/bin/mysql/mysql-8.4.3*)/bin:$(ls -d /c/laragon/bin/php/php-8.3.30*):$PATH" && powershell -NoProfile -File tests/mysql-smoke.ps1 -DebugResponses > "$SCRATCH/smoke.log" 2>&1; grep -n "FAIL\|SMOKE PASS" "$SCRATCH/smoke.log"`
- **Commits:** terminan con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Nada de deploy, ssh ni push en las tareas 1-5.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `backend-v4/migrations/0041_person_plans.sql` (nuevo) | Tablas de planes, plan por defecto del cliente, plan de la persona, permiso `planes.asignar`, plan «Completo» sembrado y recompilación de todos los clientes. |
| `backend-v4/src/PersonPlans.php` (nuevo) | Resolución: plan efectivo y funciones activas de una persona; marcar recompilación. |
| `backend-v4/src/PolicyComposer.php`, `backend-v4/src/PolicyCompiler.php` | El filtrado de reglas pasa de módulos o tiers a funciones. |
| `backend-v4/src/FeatureAdmin.php` | CRUD de planes de plataforma, plan por defecto, uso por plan. |
| `backend-v4/src/AdminApi.php` | `PUT /users/{id}/plan`, `plan_id` en la persona y enrutamiento. |
| `backend-v4/src/ComplianceWorker.php`, `backend-v4/src/DataSync.php` | Los trabajos de fondo respetan `cumplimiento` y `one`. |
| `docs/architecture/openapi-v4.yaml` | Rutas y esquemas nuevos. |
| `backend-v4/tests/plans.php` (nuevo), `backend-v4/tests/policy-compiler.php`, `backend-v4/tests/policy-exceptions.php`, `backend-v4/tests/smoke.php` | Pruebas. |
| `backend-v4/public/assets/k3.js`, `backend-v4/public/assets/nav-access.js`, `backend-v4/public/partials/sidebar.php` | Pantallas. |

---

### Task 1: Planes de plataforma y resolución por persona

**Files:**
- Create: `backend-v4/migrations/0041_person_plans.sql`, `backend-v4/src/PersonPlans.php`, `backend-v4/tests/plans.php`
- Modify: `backend-v4/src/Features.php` (agregar `'planes.asignar'` a `CORE_PERMISSIONS`)
- Modify: `backend-v4/tests/smoke.php` (require de `plans.php`, llamada, fixture `secondTenant()`)

**Interfaces:**
- Produces:
  - `PersonPlans::effectivePlan(Database $db, string $tenant, string $user): ?string`. Recibe binarios y devuelve el id binario del plan: el de la persona si es activo; si no, el por defecto del cliente si es activo; si no, null.
  - `PersonPlans::functions(Database $db, string $tenant, string $user): list<string>`. Devuelve los códigos de nivel persona, ordenados, presentes en las funciones del cliente **y** en el plan efectivo. Si la persona está inactiva o no hay plan, devuelve `[]`.
  - `PersonPlans::markRecompile(Database $db, string $tenant): void`.
  - Constante `PersonPlans::FULL_PLAN = 'Completo'`.

- [ ] **Step 1: Write the failing test** — crear `backend-v4/tests/plans.php`:

```php
<?php
declare(strict_types=1);
use Keeper\{Database,Features,PersonPlans,Util};

// Fase 2a: planes de la plataforma por persona (2026-10-03).
function planResolutionTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $platform = Util::bin('00000000-0000-4000-8000-000000000001');
    $u = secondTenant($db); $tenant = $u['tenant_id']; $user = $u['id'];
    $full = $db->one("SELECT id FROM person_plans WHERE tenant_id=? AND name='Completo'", [$platform])['id'];
    $person = array_values(array_filter(Features::codes(), fn ($c) => Features::CATALOG[$c]['level'] === 'person'));
    sort($person);
    // El fixture deja el plan "Completo" por defecto: la persona recibe todas las funciones por persona del cliente.
    check(PersonPlans::effectivePlan($db, $tenant, $user) === $full, 'default plan of the client applies');
    check(PersonPlans::functions($db, $tenant, $user) === $person, 'full plan = every person-level function');
    // Plan propio de la persona: manda sobre el por defecto.
    $basic = Util::bin(Util::uuid());
    $db->run("INSERT INTO person_plans (tenant_id,id,name,badge_label) VALUES (?,?,'Básico prueba','B')", [$platform, $basic]);
    foreach (['productividad', 'usb'] as $f) { $db->run('INSERT INTO person_plan_items (tenant_id,plan_id,feature_code) VALUES (?,?,?)', [$platform, $basic, $f]); }
    $db->run('UPDATE users SET plan_tenant_id=?,plan_id=? WHERE tenant_id=? AND id=?', [$platform, $basic, $tenant, $user]);
    check(PersonPlans::functions($db, $tenant, $user) === ['productividad', 'usb'], 'own plan wins');
    // Solo lo que el cliente compro.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='usb'", [$tenant]);
    check(PersonPlans::functions($db, $tenant, $user) === ['productividad'], 'client must have bought it');
    // Plan inactivo: vuelve al por defecto del cliente.
    $db->run('UPDATE person_plans SET active=FALSE WHERE tenant_id=? AND id=?', [$platform, $basic]);
    check(PersonPlans::effectivePlan($db, $tenant, $user) === $full, 'inactive own plan falls back to default');
    // Sin plan propio ni por defecto: nada.
    $db->run('UPDATE users SET plan_tenant_id=NULL,plan_id=NULL WHERE tenant_id=? AND id=?', [$tenant, $user]);
    $db->run('UPDATE tenants SET default_plan_tenant_id=NULL,default_plan_id=NULL WHERE tenant_id=?', [$tenant]);
    check(PersonPlans::effectivePlan($db, $tenant, $user) === null && PersonPlans::functions($db, $tenant, $user) === [], 'no plan, no functions');
    // Persona inactiva: nada.
    $db->run('UPDATE tenants SET default_plan_tenant_id=?,default_plan_id=? WHERE tenant_id=?', [$platform, $full, $tenant]);
    $db->run("UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?", [$tenant, $user]);
    check(PersonPlans::functions($db, $tenant, $user) === [], 'inactive person gets nothing');
    // La migracion: plan Completo con todas las funciones por persona y permiso nuevo.
    $items = array_column($db->run('SELECT feature_code FROM person_plan_items WHERE tenant_id=? AND plan_id=? ORDER BY feature_code', [$platform, $full])->fetchAll(), 'feature_code');
    check($items === $person, 'seeded full plan has every person-level function');
    check((bool) $db->one("SELECT code FROM permissions WHERE code='planes.asignar'"), 'permission planes.asignar exists');
    PersonPlans::markRecompile($db, $tenant);
    check((bool) $db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?', [$tenant]), 'recompile marked');
    echo 'PASS plan resolution (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `tests/smoke.php`:
- agregar `require __DIR__ . '/plans.php';` después de `require __DIR__ . '/features.php';`;
- agregar `planResolutionTests($db);` después de `featureMenuTests($db);`;
- en `secondTenant()`, después del bucle que inserta `tenant_features`, agregar:

```php
    // Plan por defecto de los clientes de prueba: "Completo" (como los clientes existentes al migrar 0041).
    $db->run("UPDATE tenants SET default_plan_tenant_id=0x00000000000040008000000000000001, default_plan_id=(SELECT id FROM person_plans WHERE tenant_id=0x00000000000040008000000000000001 AND name='Completo') WHERE tenant_id=?", [$tenant]);
```

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL por la tabla `person_plans`, que no existe.

- [ ] **Step 3: Migration** — crear `backend-v4/migrations/0041_person_plans.sql`:

```sql
-- Planes por persona de la plataforma (v4-clientes-funciones-accesos, fase 2a). Persona sin plan propio = plan por
-- defecto de su cliente. Un plan solo incluye funciones de nivel persona (lo valida la API con src/Features.php).

CREATE TABLE IF NOT EXISTS person_plans (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(80) NOT NULL,
  badge_label VARCHAR(120) NOT NULL DEFAULT '',
  active BOOLEAN NOT NULL DEFAULT TRUE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY person_plans_name (tenant_id, name),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT person_plans_platform CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS person_plan_items (
  tenant_id BINARY(16) NOT NULL,
  plan_id BINARY(16) NOT NULL,
  feature_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, plan_id, feature_code),
  FOREIGN KEY (tenant_id, plan_id) REFERENCES person_plans (tenant_id, id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT person_plan_items_platform CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE tenants
  ADD COLUMN default_plan_tenant_id BINARY(16) NULL,
  ADD COLUMN default_plan_id BINARY(16) NULL,
  ADD CONSTRAINT tenants_default_plan FOREIGN KEY (default_plan_tenant_id, default_plan_id) REFERENCES person_plans (tenant_id, id),
  ADD CONSTRAINT tenants_default_plan_pair CHECK ((default_plan_id IS NULL) = (default_plan_tenant_id IS NULL));

ALTER TABLE users
  ADD COLUMN plan_tenant_id BINARY(16) NULL,
  ADD COLUMN plan_id BINARY(16) NULL,
  ADD CONSTRAINT users_plan FOREIGN KEY (plan_tenant_id, plan_id) REFERENCES person_plans (tenant_id, id),
  ADD CONSTRAINT users_plan_pair CHECK ((plan_id IS NULL) = (plan_tenant_id IS NULL));

INSERT IGNORE INTO permissions (code, label, resource, action, delegable, platform_only)
  VALUES ('planes.asignar', 'Asignar el plan de cada persona', 'planes', 'asignar', TRUE, FALSE);

-- Plan "Completo": todas las funciones de nivel persona. Queda por defecto en los clientes existentes, asi sus equipos
-- siguen (o empiezan a) recibir sus reglas sin que nadie tenga que asignar planes primero.
INSERT INTO person_plans (tenant_id, id, name, badge_label)
  VALUES (0x00000000000040008000000000000001, UNHEX(REPLACE(UUID(),'-','')), 'Completo', 'Todas las funciones por persona');
INSERT INTO person_plan_items (tenant_id, plan_id, feature_code)
  SELECT p.tenant_id, p.id, f.code FROM person_plans p
  CROSS JOIN (SELECT 'productividad' code UNION ALL SELECT 'titulos' UNION ALL SELECT 'presencia' UNION ALL SELECT 'puertas'
    UNION ALL SELECT 'ubicacion' UNION ALL SELECT 'web' UNION ALL SELECT 'usb' UNION ALL SELECT 'descargas'
    UNION ALL SELECT 'instalaciones' UNION ALL SELECT 'comandos' UNION ALL SELECT 'borrado' UNION ALL SELECT 'endurecimiento'
    UNION ALL SELECT 'cumplimiento') f
  WHERE p.tenant_id = 0x00000000000040008000000000000001 AND p.name = 'Completo';
UPDATE tenants t JOIN person_plans p ON p.tenant_id = 0x00000000000040008000000000000001 AND p.name = 'Completo'
  SET t.default_plan_tenant_id = p.tenant_id, t.default_plan_id = p.id
  WHERE t.tenant_id <> 0x00000000000040008000000000000001 AND t.deleted_at IS NULL;
-- Las politicas de todos los clientes se recompilan con la nueva regla (lo procesa config/compile-pending.php).
INSERT INTO admin_policy_recompiles (tenant_id)
  SELECT tenant_id FROM tenants WHERE tenant_id <> 0x00000000000040008000000000000001 AND deleted_at IS NULL
  ON DUPLICATE KEY UPDATE revision = revision + 1, requested_at = UTC_TIMESTAMP(6);
```

(Antes de escribirla, confirmar con una lectura las columnas reales de `admin_policy_recompiles`: `tenant_id`, `revision` y `requested_at`, que son las que usa `AdminApi::dirty()`. La lista de 13 códigos debe coincidir con las funciones `level === 'person'` de `Features::CATALOG`, y la prueba lo verifica.)

- [ ] **Step 4: Resolver** — crear `backend-v4/src/PersonPlans.php`:

```php
<?php
declare(strict_types=1);
namespace Keeper;

/** Plan efectivo de una persona y sus funciones activas (v4-clientes-funciones-accesos §3). */
final class PersonPlans
{
    public const FULL_PLAN = 'Completo';

    /** Plan propio activo, si no el por defecto activo del cliente, si no null (ids binarios). */
    public static function effectivePlan(Database $db, string $tenant, string $user): ?string
    {
        $own = $db->one('SELECT p.id FROM users u JOIN person_plans p ON p.tenant_id=u.plan_tenant_id AND p.id=u.plan_id AND p.active=TRUE WHERE u.tenant_id=? AND u.id=?', [$tenant, $user]);
        if ($own) { return $own['id']; }
        $default = $db->one('SELECT p.id FROM tenants t JOIN person_plans p ON p.tenant_id=t.default_plan_tenant_id AND p.id=t.default_plan_id AND p.active=TRUE WHERE t.tenant_id=?', [$tenant]);
        return $default['id'] ?? null;
    }

    /** @return list<string> funciones de nivel persona que el cliente compro y el plan efectivo incluye */
    public static function functions(Database $db, string $tenant, string $user): array
    {
        $u = $db->one('SELECT status FROM users WHERE tenant_id=? AND id=?', [$tenant, $user]);
        if (!$u || $u['status'] !== 'active') { return []; }
        $plan = self::effectivePlan($db, $tenant, $user);
        if ($plan === null) { return []; }
        return array_column($db->run('SELECT i.feature_code FROM person_plan_items i JOIN tenant_features f ON f.tenant_id=? AND f.feature_code=i.feature_code
            WHERE i.tenant_id=0x00000000000040008000000000000001 AND i.plan_id=? ORDER BY i.feature_code', [$tenant, $plan])->fetchAll(), 'feature_code');
    }

    /** Pide recompilar las politicas del cliente (la request actual o config/compile-pending.php la procesan). */
    public static function markRecompile(Database $db, string $tenant): void
    {
        $db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1,requested_at=UTC_TIMESTAMP(6)', [$tenant]);
    }
}
```

En `backend-v4/src/Features.php`, agregar `'planes.asignar'` al final de `CORE_PERMISSIONS`.

- [ ] **Step 5: Run test to verify it passes**

Run: suite completa.
Expected: `PASS plan resolution (...)` y `SMOKE PASS`.

- [ ] **Step 6: Commit**

```bash
git add backend-v4/migrations/0041_person_plans.sql backend-v4/src/PersonPlans.php backend-v4/src/Features.php backend-v4/tests/plans.php backend-v4/tests/smoke.php
git commit -m "Planes por persona: tablas de plataforma, plan por defecto del cliente y resolución de funciones

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: El compilador filtra reglas por funciones de la persona

**Files:**
- Modify: `backend-v4/src/PolicyComposer.php:10,23`
- Modify: `backend-v4/src/PolicyCompiler.php:129-133,150,157`
- Modify: `backend-v4/tests/policy-compiler.php`, `backend-v4/tests/policy-exceptions.php`, y cualquier otra prueba que arme un input del compositor con la clave `modules`
- Test: `backend-v4/tests/plans.php` (nueva función)

**Interfaces:**
- Consumes: `PersonPlans::functions()` (Task 1); `Features::forRuleKind()` (fase 1).
- Produces:
  - `PolicyComposer::ruleAllowed(string $kind, array $functions): bool`: true si `Features::forRuleKind($kind) === null` o si esa función está en `$functions`.
  - El input del compositor y el snapshot del compilador usan la clave `functions` (list<string>) en lugar de `modules`.

- [ ] **Step 1: Write the failing test** — agregar a `backend-v4/tests/plans.php`:

```php
// El equipo de una persona solo recibe reglas de funciones que su cliente compro y su plan incluye.
function planCompileTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    check(Keeper\PolicyComposer::ruleAllowed('schedule', []) && Keeper\PolicyComposer::ruleAllowed('os', []), 'core rule kinds always pass');
    check(!Keeper\PolicyComposer::ruleAllowed('usb', ['web']) && Keeper\PolicyComposer::ruleAllowed('usb', ['usb']), 'sellable rule kinds need their function');
    $rules = [(object) ['id' => Util::uuid(), 'kind' => 'web', 'effect' => 'deny', 'targets' => ['facebook.com'], 'schedule_id' => null, 'priority' => 0],
        (object) ['id' => Util::uuid(), 'kind' => 'usb', 'effect' => 'deny', 'targets' => ['*'], 'schedule_id' => null, 'priority' => 0]];
    $input = fn (array $functions) => ['tenant_id' => Util::uuid(), 'device_id' => Util::uuid(), 'functions' => $functions,
        'assignments' => [['id' => Util::uuid(), 'scope' => 'tenant', 'priority' => 0, 'rules' => $rules, 'management_hosts' => []]], 'work_schedule' => null, 'rule_schedules' => []];
    $kinds = fn (object $doc) => array_values(array_unique(array_map(fn ($r) => $r->kind, $doc->rules)));
    check($kinds(Keeper\PolicyComposer::compose($input(['web', 'usb']))) === ['usb', 'web'] || $kinds(Keeper\PolicyComposer::compose($input(['web', 'usb']))) === ['web', 'usb'], 'both rules with both functions');
    check($kinds(Keeper\PolicyComposer::compose($input(['web']))) === ['web'], 'usb dropped without its function');
    check(Keeper\PolicyComposer::compose($input([]))->rules === [], 'no functions, no sellable rules');
    echo 'PASS plan compile (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

(Si `compose()` exige más claves en el input, como `work_schedule` o `rule_schedules`, leer el input que arman las pruebas existentes en `tests/policy-exceptions.php` y copiar su forma exacta, cambiando solo `modules` por `functions`.)

En `tests/smoke.php` agregar `planCompileTests($db);` después de `planResolutionTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL con `Call to undefined method Keeper\PolicyComposer::ruleAllowed()`.

- [ ] **Step 3: Implementation**

En `PolicyComposer.php`:
1. Reemplazar la constante `RULE_MODULES` por el método:

```php
    /** Un tipo de regla nucleo siempre pasa; uno vendible (web, usb, download, installation) necesita su funcion. */
    public static function ruleAllowed(string $kind, array $functions): bool
    {
        $feature = Features::forRuleKind($kind);
        return $feature === null || in_array($feature, $functions, true);
    }
```

2. La línea 23 pasa a ser:

```php
                if (!self::ruleAllowed($rule->kind, $input['functions'])) { continue; }
```

En `PolicyCompiler.php`:
1. Reemplazar el bloque de las líneas 129-133 (`$modules = []; if ($user['status'] === 'active') { ...tier/tier_module/firma_module_override... }`) por:

```php
        // Funciones de la persona: las que su cliente compro y su plan (propio o el por defecto del cliente) incluye.
        $functions = PersonPlans::functions($this->db, $tenant, $userId);
```

2. La línea 150 pasa a ser:

```php
                if ($rule->schedule_id !== null && PolicyComposer::ruleAllowed($rule->kind, $functions)) {
```

3. En el `return` de la línea 157, cambiar `'modules' => $modules` por `'functions' => $functions`.

Buscar con grep en `backend-v4/src` y `backend-v4/tests` toda referencia a `RULE_MODULES` y a la clave `'modules'` de inputs del compositor, y migrarla. Ejemplos: `tests/policy-compiler.php:213` y `tests/policy-exceptions.php:14` pasan a `'functions' => ['web', 'usb', 'descargas', 'instalaciones']`, más lo que cada prueba necesite.

Las pruebas de `tests/policy-compiler.php` (~líneas 146-162) verifican que las reglas desaparecen al quitar el tier, con un override negativo de firma, con un tier inactivo y con una suscripción cancelada o vencida. Se reescriben para el nuevo modelo con estos casos, cada uno con su `check` y un nombre claro:
- (a) persona sin plan propio y cliente sin plan por defecto → `rules === []`;
- (b) cliente con plan por defecto que incluye `web` → la regla web se compila;
- (c) plan propio sin `usb` → la regla usb no se compila, aunque el cliente tenga `usb`;
- (d) cliente sin `web` aunque el plan la tenga → no se compila;
- (e) persona inactiva → `rules === []`.

Los inserts directos de `tier`, `tier_module` y `subscriptions` que esas pruebas usan para habilitar reglas (~líneas 45-47) se reemplazan por:
- dejar al cliente de prueba con el plan «Completo» por defecto, como hace `secondTenant()`;
- o insertar un plan de prueba en `person_plans` y `person_plan_items` y asignarlo con `UPDATE users SET plan_tenant_id=..., plan_id=...`.

Las pruebas de la API de tiers (`tests/admin.php`, `tests/external.php`) se dejan intactas: la API vieja de tiers sigue existiendo, solo que ya no decide reglas.

- [ ] **Step 4: Run test to verify it passes**

Run: suite completa.
Expected:
- `PASS plan compile (...)`, `PASS policy compiler ...` y `SMOKE PASS`;
- la cuenta de aserciones puede variar por la reescritura; anotar el antes y el después en el reporte.

- [ ] **Step 5: Commit**

```bash
git add backend-v4/src/PolicyComposer.php backend-v4/src/PolicyCompiler.php backend-v4/tests
git commit -m "Planes por persona: el compilador entrega solo reglas de funciones del cliente y del plan

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: API de planes (plataforma), plan por defecto, uso y plan de cada persona

**Files:**
- Modify: `backend-v4/src/FeatureAdmin.php`, `backend-v4/src/AdminApi.php`
- Modify: `docs/architecture/openapi-v4.yaml`, `backend-v4/tests/export-contract.py`, `backend-v4/config/contract.json` (generado)
- Modify: `backend-v4/public/assets/k3.js` (solo `AUDIT_ACTIONS`)
- Test: `backend-v4/tests/plans.php` (nueva función `planApiTests`)

**Interfaces:**
- Consumes: `PersonPlans::*` (Task 1), `Features::CATALOG`.
- Produces:
  - `GET /person-plans` → `listPersonPlans` → `{data:[PersonPlan]}`. Plataforma, `empresas.gestionar`, `x-platform-only`.
  - `POST /person-plans` → `createPersonPlan`, body `PersonPlanInput {name, badge_label, features[], active}` → 201 `PersonPlan`.
  - `PATCH /person-plans/{id}` → `patchPersonPlan`, con If-Match, mismo body → 200 `PersonPlan`.
  - `PersonPlan = {id, name, badge_label, features[], active, people:int, version}`. `people` es el número de personas activas cuyo plan efectivo es este, en todos los clientes.
  - `GET /tenants/{id}/plan-usage` → `getTenantPlanUsage` → `{default_plan_id|null, data:[{plan_id|null, name, people}]}`. Plataforma. Agrupa por plan efectivo a las personas activas del cliente; `plan_id` null con name `Sin plan` cuenta a quienes no tienen ninguno.
  - `GET/PUT /tenants/{id}/features`: `TenantFeatures` agrega `default_plan_id` (uuid|null, requerido en la respuesta). En `TenantFeaturesInput` es opcional: si viene, se guarda; si no, no se toca.
  - `PUT /users/{id}/plan` → `setUserPlan`, body `{plan_id: uuid|null}`, `x-permission: planes.asignar`, con If-Match (versión de la persona) → 200 `User`. Null = vuelve al plan por defecto.
  - `User.plan_id` (uuid|null): el plan propio, no el efectivo.

- [ ] **Step 1: Write the failing test** — agregar a `backend-v4/tests/plans.php`:

```php
function planApiTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tid = Util::id($u['tenant_id']);
    $platform = sharedPlatform($db); $platform['tenant'] = $u['tenant_id'];
    $pro = expect(adminRequest($platform, 'POST', '/person-plans', ['name' => 'Pro ' . substr(Util::uuid(), 0, 6), 'badge_label' => 'Pro', 'features' => ['productividad', 'usb', 'web'], 'active' => true]), 201, 'create plan');
    check($pro['features'] === ['productividad', 'usb', 'web'] && $pro['people'] === 0, 'plan stored');
    check(adminRequest($platform, 'POST', '/person-plans', ['name' => 'Malo', 'badge_label' => '', 'features' => ['festivos'], 'active' => true])[1]['detail'] === 'not_person_feature:festivos', 'client-level functions rejected in plans');
    $features = expect(adminRequest($platform, 'GET', '/tenants/' . $tid . '/features'), 200, 'client functions');
    check($features['default_plan_id'] !== null, 'client has a default plan');
    $set = expect(adminRequest($platform, 'PUT', '/tenants/' . $tid . '/features', ['package_id' => null, 'features' => $features['features'], 'default_plan_id' => $pro['id']]), 200, 'set default plan');
    check($set['default_plan_id'] === $pro['id'], 'default plan stored');
    $usage = expect(adminRequest($platform, 'GET', '/tenants/' . $tid . '/plan-usage'), 200, 'usage');
    check($usage['default_plan_id'] === $pro['id'] && $usage['data'][0]['plan_id'] === $pro['id'] && $usage['data'][0]['people'] === 1, 'person counted under the default plan');
    // Plan propio de una persona, con permiso planes.asignar.
    $admin = adminFixture($db, $u, ['usuarios.ver', 'planes.asignar'], false);
    $basic = expect(adminRequest($platform, 'POST', '/person-plans', ['name' => 'Básico ' . substr(Util::uuid(), 0, 6), 'badge_label' => 'B', 'features' => ['productividad'], 'active' => true]), 201, 'second plan');
    $person = expect(adminRequest($admin, 'GET', '/users/' . Util::id($u['id'])), 200, 'person');
    $moved = expect(adminRequest($admin, 'PUT', '/users/' . $person['id'] . '/plan', ['plan_id' => $basic['id']], ['if-match' => '"' . $person['version'] . '"']), 200, 'assign own plan');
    check($moved['plan_id'] === $basic['id'], 'person shows own plan');
    check(Keeper\PersonPlans::functions($db, $u['tenant_id'], $u['id']) === ['productividad'], 'own plan applies');
    $back = expect(adminRequest($admin, 'PUT', '/users/' . $person['id'] . '/plan', ['plan_id' => null], ['if-match' => '"' . $moved['version'] . '"']), 200, 'back to default');
    check($back['plan_id'] === null, 'back to client default');
    $noPerm = adminFixture($db, $u, ['usuarios.ver'], false);
    check(adminRequest($noPerm, 'PUT', '/users/' . $person['id'] . '/plan', ['plan_id' => $basic['id']], ['if-match' => '"' . $back['version'] . '"'])[0] === 403, 'assigning plans needs planes.asignar');
    $edited = expect(adminRequest($platform, 'PATCH', '/person-plans/' . $pro['id'], ['name' => $pro['name'], 'badge_label' => 'Pro', 'features' => ['productividad', 'web'], 'active' => true], ['if-match' => '"' . $pro['version'] . '"']), 200, 'edit plan');
    check($edited['features'] === ['productividad', 'web'], 'plan edited');
    check((bool) $db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?', [$u['tenant_id']]), 'clients using the plan get recompiled');
    check(adminRequest($admin, 'GET', '/person-plans')[0] === 403, 'plans catalog is platform-only');
    echo 'PASS plan api (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `tests/smoke.php` agregar `planApiTests($db);` después de `planCompileTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL `create plan` con 404 (la ruta no existe).

- [ ] **Step 3: Contract**

Agregar a `openapi-v4.yaml`:
- **Rutas** `/person-plans` (GET `listPersonPlans`, POST `createPersonPlan`) y `/person-plans/{id}` (PATCH `patchPersonPlan`, con IfMatch), copiando la forma exacta de `/feature-packages` y `/feature-packages/{id}`.
- **Ruta** `/tenants/{id}/plan-usage` (GET `getTenantPlanUsage`), copiando la forma de `GET /tenants/{id}/features`. Todas con `x-permission: empresas.gestionar` y `x-platform-only: true`.
- **Ruta** `/users/{id}/plan` (PUT `setUserPlan`):
  - forma de `PUT /users/{id}/subscription`, que tiene adminSession + csrfToken, y parámetros Tenant, Id e IfMatch;
  - `x-permission: planes.asignar`, sin `x-platform-only`;
  - requestBody `UserPlanInput {plan_id: uuid|null}` (requerido); respuesta 200 `User`.
- **Esquemas** antes de `    OrgUnit:`:
  - `PersonPlanInput`: `name` minLength 1 / maxLength 80; `badge_label` maxLength 120; `features` como array de string maxLength 40, uniqueItems, maxItems 100; `active` boolean; todos requeridos.
  - `PersonPlan`: los de `PersonPlanInput` más `id` uuid, `people` integer ≥0 y `version` integer ≥1; todos requeridos.
  - `PersonPlanList`: `{data: PersonPlan[] maxItems 500}`.
  - `PlanUsage`:
    - `default_plan_id`: uuid|null.
    - `data`: array maxItems 500 de `{plan_id: uuid|null, name: string maxLength 80, people: integer ≥0}`.
  - `UserPlanInput`.
- **Cambios en esquemas existentes:**
  - `TenantFeatures`: propiedad y requerido `default_plan_id` (uuid|null).
  - `TenantFeaturesInput`: propiedad opcional `default_plan_id` (uuid|null).
  - `User`: propiedad `plan_id` (uuid|null), no requerida.

En `tests/export-contract.py`, agregar `"person-plans"` a `admin_roots`. Luego, desde `backend-v4`, correr `python tests/export-contract.py`.

- [ ] **Step 4: Implementation**

En `FeatureAdmin.php`:
1. En `dispatch()` agregar los brazos:

```php
            'listPersonPlans' => AdminApi::response(['data' => array_map(fn ($p) => $this->planDto($p), $this->db->run('SELECT * FROM person_plans WHERE tenant_id=? ORDER BY name', [$this->platform])->fetchAll())]),
            'createPersonPlan', 'patchPersonPlan' => $this->savePlan($id, $b),
            'getTenantPlanUsage' => AdminApi::response($this->usage($this->c['tenant_id'])),
```

2. Agregar estos métodos:

```php
    /** @param list<string> $codes funciones de nivel persona */
    private function personFeatures(array $codes): array
    {
        $codes = $this->valid($codes);
        foreach ($codes as $code) { if (Features::CATALOG[$code]['level'] !== 'person') { throw new ApiError(422, 'validation_failed', [], "not_person_feature:$code"); } }
        return $codes;
    }
    private function planDto(array $p): array
    {
        $features = array_column($this->db->run('SELECT feature_code FROM person_plan_items WHERE tenant_id=? AND plan_id=? ORDER BY feature_code', [$this->platform, $p['id']])->fetchAll(), 'feature_code');
        $people = (int) $this->db->one("SELECT COUNT(*) n FROM users u JOIN tenants t ON t.tenant_id=u.tenant_id AND t.deleted_at IS NULL
            WHERE u.status='active' AND COALESCE(u.plan_id, t.default_plan_id)=?", [$p['id']])['n'];
        return ['id' => Util::id($p['id']), 'name' => $p['name'], 'badge_label' => $p['badge_label'], 'features' => $features, 'active' => (bool) $p['active'], 'people' => $people, 'version' => (int) $p['version']];
    }
    private function savePlan(?string $id, object $b): array
    {
        $codes = $this->personFeatures($b->features);
        if ($id !== null) {
            $p = $this->db->one('SELECT * FROM person_plans WHERE tenant_id=? AND id=? FOR UPDATE', [$this->platform, $id]);
            if (!$p) { throw new ApiError(404, 'resource_not_found'); }
            if ($this->r->header('if-match') !== '"' . $p['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
            $dup = $this->db->one('SELECT id FROM person_plans WHERE tenant_id=? AND name=? AND id<>?', [$this->platform, $b->name, $id]);
            if ($dup) { throw new ApiError(409, 'invalid_transition', [], 'duplicate_name'); }
            $this->db->run('UPDATE person_plans SET name=?,badge_label=?,active=?,version=version+1 WHERE tenant_id=? AND id=?', [$b->name, $b->badge_label, (int) $b->active, $this->platform, $id]);
            $this->db->run('DELETE FROM person_plan_items WHERE tenant_id=? AND plan_id=?', [$this->platform, $id]);
        } else {
            if ($this->db->one('SELECT id FROM person_plans WHERE tenant_id=? AND name=?', [$this->platform, $b->name])) { throw new ApiError(409, 'invalid_transition', [], 'duplicate_name'); }
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO person_plans (tenant_id,id,name,badge_label,active) VALUES (?,?,?,?,?)', [$this->platform, $id, $b->name, $b->badge_label, (int) $b->active]);
        }
        foreach ($codes as $code) { $this->db->run('INSERT INTO person_plan_items (tenant_id,plan_id,feature_code) VALUES (?,?,?)', [$this->platform, $id, $code]); }
        // Todo cliente con alguien en este plan (propio o por defecto) recompila sus politicas.
        foreach ($this->db->run('SELECT DISTINCT t.tenant_id FROM tenants t LEFT JOIN users u ON u.tenant_id=t.tenant_id AND u.plan_id=? WHERE t.deleted_at IS NULL AND (t.default_plan_id=? OR u.id IS NOT NULL)', [$id, $id])->fetchAll() as $t) {
            PersonPlans::markRecompile($this->db, $t['tenant_id']);
        }
        $c = $this->c; $c['tenant_id'] = $this->platform; $c['principal_id'] = (new AdminAuth($this->db, new RateLimiter()))->principal($c);
        (new Audit($this->db))->record($c, $this->r, $this->r->operation['operationId'], 'person_plan', $id, ['name', 'features', 'active']);
        return AdminApi::response($this->planDto($this->db->one('SELECT * FROM person_plans WHERE tenant_id=? AND id=?', [$this->platform, $id])), $this->r->method === 'POST' ? 201 : 200);
    }
    private function usage(string $tenant): array
    {
        $default = $this->db->one('SELECT default_plan_id FROM tenants WHERE tenant_id=?', [$tenant])['default_plan_id'];
        $rows = $this->db->run("SELECT COALESCE(u.plan_id, t.default_plan_id) plan, COUNT(*) people FROM users u JOIN tenants t ON t.tenant_id=u.tenant_id
            WHERE u.tenant_id=? AND u.status='active' GROUP BY plan ORDER BY people DESC", [$tenant])->fetchAll();
        return ['default_plan_id' => $default === null ? null : Util::id($default), 'data' => array_map(fn ($r) => ['plan_id' => $r['plan'] === null ? null : Util::id($r['plan']),
            'name' => $r['plan'] === null ? 'Sin plan' : ($this->db->one('SELECT name FROM person_plans WHERE tenant_id=? AND id=?', [$this->platform, $r['plan']])['name'] ?? 'Sin plan'), 'people' => (int) $r['people']], $rows)];
    }
```

3. En `tenantDto()`:
   - leer también `default_plan_id` en la consulta de tenants;
   - devolver `'default_plan_id' => ... Util::id(...) o null`.
4. En `putTenant()`, antes de `apply()`, agregar este bloque. Además, siempre que `putTenant` cambie funciones, llamar `PersonPlans::markRecompile($this->db, $this->c['tenant_id'])`:

```php
        if (property_exists($b, 'default_plan_id')) {
            $plan = $b->default_plan_id === null ? null : Util::bin($b->default_plan_id);
            if ($plan !== null && !$this->db->one('SELECT id FROM person_plans WHERE tenant_id=? AND id=?', [$this->platform, $plan])) { throw new ApiError(422, 'validation_failed', [], 'unknown_plan'); }
            $this->db->run('UPDATE tenants SET default_plan_tenant_id=?,default_plan_id=? WHERE tenant_id=?', [$plan === null ? null : $this->platform, $plan, $this->c['tenant_id']]);
        }
```

En `AdminApi.php`:
1. Enrutar `'listPersonPlans','createPersonPlan','patchPersonPlan','getTenantPlanUsage'` al mismo brazo de `FeatureAdmin` que ya enruta `listFeatures`.
2. Agregar el brazo `'setUserPlan'=>$this->userPlan($id,$b),` y el método:

```php
    /** Plan propio de una persona (null = el por defecto de su cliente). */
    private function userPlan(string $id, object $b): array
    {
        $this->access->user($id); $old=$this->row('users',$id); $this->match($old);
        $plan=$b->plan_id===null?null:Util::bin($b->plan_id);
        if ($plan!==null && !$this->db->one('SELECT id FROM person_plans WHERE tenant_id=? AND id=? AND active=TRUE',[Util::bin(AdminAuth::PLATFORM),$plan])) { throw new ApiError(422,'validation_failed',[],'unknown_plan'); }
        $this->db->run('UPDATE users SET plan_tenant_id=?,plan_id=?,version=version+1 WHERE tenant_id=? AND id=?',[$plan===null?null:Util::bin(AdminAuth::PLATFORM),$plan,$this->tenant,$id]);
        $this->audit('user',$id,['plan_id']); $this->dirty($this->userDevices($id));
        return $this->result($this->userDto($this->row('users',$id)));
    }
```

3. En `userDto()` agregar `$out['plan_id']=$r['plan_id']===null?null:Util::id($r['plan_id']);`.

En `public/assets/k3.js`, en `AUDIT_ACTIONS`, junto a `putTenantFeatures`:

```js
  createPersonPlan: 'Creó un plan por persona', patchPersonPlan: 'Editó un plan por persona', setUserPlan: 'Cambió el plan de una persona',
```

- [ ] **Step 5: Run test to verify it passes**

Run: suite completa.
Expected: `PASS plan api (...)`, `PASS ops (...)` y `SMOKE PASS`.

- [ ] **Step 6: Commit**

```bash
git add backend-v4/src/FeatureAdmin.php backend-v4/src/AdminApi.php backend-v4/public/assets/k3.js backend-v4/tests docs/architecture/openapi-v4.yaml backend-v4/config/contract.json
git commit -m "Planes por persona: API de planes, plan por defecto del cliente, uso para facturar y plan de cada persona

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Los trabajos de fondo respetan lo contratado

**Files:**
- Modify: `backend-v4/src/ComplianceWorker.php` (inicio de `calculate()`), `backend-v4/src/DataSync.php` (`due()`)
- Test: `backend-v4/tests/plans.php` (nueva función `planJobsTests`)

**Interfaces:**
- Consumes: `Features::has()`.

- [ ] **Step 1: Write the failing test**

Agregar `planJobsTests(Database $db)` a `tests/plans.php`, registrada después de `planApiTests`:
1. Con `secondTenant()`, insertar una fuente REST que esté vencida (`data_sources` con `active=TRUE`, `kind='rest'`, `interval_minutes` no nulo y sin corrida reciente). Antes, leer en `src/DataSync.php::due()` qué columnas exactas exige y cómo la crean las pruebas de `tests/data-sync.php`.
2. Comprobar que el id de la fuente aparece en `(new DataSync($db))->due()`.
3. Borrar la función `one` del cliente (`DELETE FROM tenant_features ... 'one'`) y comprobar que la fuente ya no aparece en `due()`.
4. Para el cumplimiento: con la función `cumplimiento` apagada, llamar `(new ComplianceWorker($db))->calculate($tenant, gmdate('Y-m-d'))` y comprobar que no inserta filas en `dual_job_alerts` para ese cliente. Basta con que la cuenta antes y después sea igual.

Imprimir `PASS plan jobs (N assertions)`.

- [ ] **Step 2: Run test to verify it fails** (la fuente sigue en `due()` con `one` apagada).

- [ ] **Step 3: Implementation**

- `ComplianceWorker::calculate()`, primera línea: `if (!Features::has($this->db, $tenant, 'cumplimiento')) { return; }`. Antes, confirmar si `$tenant` es binario leyendo cómo lo llama `ProductivityWorker.php:~129`. Si llega como id de texto, usar `Util::bin`.
- `DataSync::due()`: agregar a su consulta `AND EXISTS (SELECT 1 FROM tenant_features f WHERE f.tenant_id=<alias>.tenant_id AND f.feature_code='one')`, usando el alias real de la tabla en esa consulta.

- [ ] **Step 4: Run test to verify it passes** — suite completa, con `PASS plan jobs` y `SMOKE PASS`.

- [ ] **Step 5: Commit**

```bash
git add backend-v4/src/ComplianceWorker.php backend-v4/src/DataSync.php backend-v4/tests
git commit -m "Planes por persona: doble empleo y sincronización con One solo para clientes que los contrataron

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Pantallas: planes en Empresas, plan de cada persona y retiro del «Planes» viejo

**Files:**
- Modify: `backend-v4/public/assets/k3.js`, `backend-v4/public/assets/nav-access.js`, `backend-v4/public/partials/sidebar.php`

**Interfaces:**
- Consumes: los endpoints de Task 3.

- [ ] **Step 1: Implementation**

1. **Empresas** (`tenantsPage` en `k3.js`):
   - cargar `let personPlans = (await api('GET', '/person-plans', { tenant: PLATFORM_TENANT })).data;` junto a `packages`;
   - agregar una sección «Planes por persona», con la misma forma que `renderPackages`/`packageEditor`:
     - tabla con Plan, Funciones (cantidad), Personas (`people`) y Editar;
     - editor con nombre, etiqueta, activo y casillas solo de las funciones `level === 'person'` del `catalog`, agrupadas igual;
     - POST `/person-plans` o PATCH con `ifMatch`; refrescar `personPlans` y `render()`.
   - en `featuresEditor(c)`:
     - agregar un select «Plan por defecto de sus personas», con la opción `Sin plan (sus equipos no reciben controles)` y los planes activos, y enviar `default_plan_id` en el PUT;
     - debajo, una línea con el uso de `GET /tenants/${c.id}/plan-usage`, por ejemplo «38 personas en Pro · 212 en Básico»;
     - si el cliente queda sin plan por defecto, mostrar el aviso con la clase `notice`.
2. **Personas** (`people()` en `k3.js`): en el editor de una persona existente, si `u` existe y la cuenta es superadmin o tiene `planes.asignar`, agregar un select «Plan», con la opción `Por defecto del cliente` (valor `''`) y los planes activos de `GET /person-plans` cuando la cuenta es superadmin.
   - Para una cuenta de cliente, esa ruta es solo de plataforma. En ese caso, cargar los nombres desde `GET /tenants/{tenant}/plan-usage`, que también es solo de plataforma.
   - Si no hay forma de listar planes para la cuenta de cliente, **no inventar un endpoint**: mostrar el select solo a superadmin y anotarlo en el reporte como pendiente.
   - Al guardar, si el valor cambió, llamar `api('PUT', `/users/${u.id}/plan`, { body: { plan_id: valor || null }, ifMatch: saved.version })` después del PATCH de la persona.
   - Saber si es superadmin: leer cómo `k3.js` obtiene `session.is_platform_admin`.
3. **Retiro del «Planes» viejo** (tiers por cliente):
   - En `partials/sidebar.php`, quitar la entrada `['tiers', 'Planes', '/planes.php', false]` del grupo «Control».
   - En `nav-access.js`, quitar la entrada `tiers` de `NEEDS`.
   - La guardia de `myAccessTests` exige que todo ítem del menú tenga regla; al quitar ambos sigue consistente.
   - El archivo `planes.php` y la página `tiersPage` quedan en el código, sin enlace.

- [ ] **Step 2: Verify**

`node --check backend-v4/public/assets/k3.js`, `node --check backend-v4/public/assets/nav-access.js` y `php -l backend-v4/public/partials/sidebar.php` → sin errores. Luego la suite completa → `SMOKE PASS` (incluye `PASS ops` y `PASS my access`).

- [ ] **Step 3: Commit**

```bash
git add backend-v4/public/assets/k3.js backend-v4/public/assets/nav-access.js backend-v4/public/partials/sidebar.php
git commit -m "Planes por persona: planes en Empresas, plan por defecto y uso por cliente, plan en la ficha de la persona

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Despliegue (lo hace el controlador, no un subagente)

- [ ] **Step 1:** respaldo de la base con `mariadb-dump`, y `chown -R keeper:keeper` del directorio del respaldo.
- [ ] **Step 2:** desplegar **solo** `migrations/0041_person_plans.sql` y correr `config/migrate.php`, porque el código nuevo consulta tablas nuevas. Verificar:
  - el plan «Completo» tiene 13 funciones;
  - cada cliente activo lo tiene por defecto;
  - existen las filas en `admin_policy_recompiles`.
- [ ] **Step 3:** desplegar el código:
  - `config/contract.json`;
  - `src/PersonPlans.php`, `src/PolicyComposer.php`, `src/PolicyCompiler.php`, `src/FeatureAdmin.php`, `src/AdminApi.php`, `src/Features.php`, `src/ComplianceWorker.php`, `src/DataSync.php`;
  - `public/assets/k3.js`, `public/assets/nav-access.js`, `public/partials/sidebar.php`.
- [ ] **Step 4:** programar el cron Hestia del usuario keeper cada 5 minutos: `php .../config/compile-pending.php >> .../private/compile-pending.log 2>&1`. Leer antes `config/compile-pending.php` para confirmar que se puede correr por CLI. Correrlo una vez a mano como keeper y verificar que `admin_policy_recompiles` queda vacía.
- [ ] **Step 5:** en `effective_policies` (o la tabla de políticas compiladas), comprobar que el equipo activo de AZCLegal recibió una versión nueva de política. Confirmar en el log que no hay `failure=` nuevos.
- [ ] **Step 6:** registrar en memoria (persistencia dual) y en el ledger.
