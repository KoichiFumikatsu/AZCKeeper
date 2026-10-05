# Accesos: 1 rol + 1 alcance por persona (fase 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que cada persona (y cada cuenta de grupo en cada empresa de su grupo) tenga un solo acceso al panel, compuesto por 1 rol (qué puede hacer) y 1 alcance propio (qué datos ve: todo, filtros Empresa/Ciudad/Sede/Área, su equipo o solo lo propio), con delegación segura y aplicación uniforme en todo lo que lista o lee datos.

**Architecture:**
- Tabla nueva `access_grants`: una fila por persona, o por cuenta de grupo y empresa. Lleva el rol, el tipo de alcance (`all|filters|team|self`) y el origen (`manual|rule|default`).
- Los filtros viven en `access_grant_units` (empresa, sede y área, con FK a `org_units`) y en `access_grant_cities` (ciudad, como texto de la sede).
- `AccessScope` es el valor del alcance: validación, SQL, pertenencia y cobertura. `AccessGrants` guarda y lee los accesos.
- `AdminAccess` se reescribe sobre un único acceso. Sigue siendo el único punto de aplicación: `usersSql`, `user()`, `requireFull`, filtros, objetivos, ubicación de personas y delegación.
- La migración `0043` copia el alcance de cada rol a cada persona que lo tiene. Si alguien tiene varios roles, deja el de mayor alcance y lo reporta en `access_migration_report`.
- Las tablas viejas (`user_roles`, `admin_account_roles`, `role_*_scopes`, `roles.scope_kind`) quedan congeladas: el código deja de usarlas.
- Orden: migración → clases → el código pasa a leer `access_grants`, con un puente temporal que copia el alcance viejo del rol (Tareas 3 a 7) → API nueva (Tareas 5 a 8) → pantallas → cierre. La suite queda verde al final de cada tarea.

**Tech Stack:** PHP 8.3 sin framework (backend-v4), MySQL 8.4 local / MariaDB 11.4 producción, OpenAPI `docs/architecture/openapi-v4.yaml` → `backend-v4/config/contract.json`, JS de módulos en `backend-v4/public/assets/`, `node --test` para la función pura del resumen.

## Global Constraints

- **Diseño fuente:** `docs/architecture/v4-clientes-funciones-accesos.md` §1, §4, §6, §7, §8 y §9 (fase 3). Ver al final «Decisiones abiertas para Koichi»; este plan sigue la recomendación de cada una.
- **Modelo (§4, copiado):**
  - **Rol.** Define qué puede hacer: un conjunto de permisos.
    - Los roles base vienen de plantillas de la plataforma: Corporativo, Gerente, Coordinador, IT, Colaborador.
    - El cliente solo edita roles o crea roles propios si compró `autogestion_accesos`.
    - Un rol nunca incluye permisos de funciones que el cliente no compró.
  - **Alcance.** Define qué datos ve. Va en la persona, no en el rol.
    - `filters`: Empresa, Ciudad, Sede y Área. Cada filtro es «todas» o una lista. Dentro de un filtro, los valores se suman (O); entre filtros, se cruzan (Y).
    - `team`: quienes le reportan, directa o indirectamente.
    - `self`: solo él. **Es el valor por defecto.**
  - **Delegación segura.** Nadie da un rol con permisos que no tiene, ni un alcance más amplio que el propio.
  - **Asignación automática por cargo.** Propone el rol, no el alcance.
  - **Una persona, un acceso.** Si un caso no encaja, se amplía su alcance; no se le dan dos roles.
- **Alcance en la API:** objeto `AccessScope` `{kind, company_ids, cities, site_ids, area_ids}`.
  - `kind` ∈ `all|filters|team|self`.
  - Las cuatro listas siempre van presentes. Solo pueden tener valores con `kind = filters`, y en ese caso al menos una no está vacía.
  - En el servidor, un `filters` sin valores no ve nada (`1=0`), nunca «todo».
- **Errores (§7, copiados):**

  | Código | Mensaje en el panel |
  |---|---|
  | `feature_not_contracted:<f>` | La función no está contratada por el cliente. |
  | `scope_denied` | Tu alcance no incluye esa empresa, sede o área. |
  | `delegation_exceeds_own_access` | No puedes dar un acceso más amplio que el tuyo. |

  En la API los tres van como `403`, `code = permission_denied` y `detail = <código>` (patrón de `feature_not_contracted` en fase 1).
- **Dónde va cada error:**
  - **404 `resource_not_found`, como hoy:** detalle de una persona, un equipo o un dato de persona fuera del alcance, para no revelar que existe.
  - **403 `scope_denied`:**
    - un filtro pedido (`company_id`, `city`, `site_id`, `area_id`) fuera del alcance;
    - un objetivo de regla (sede/área/empresa) fuera del alcance;
    - crear o mover una persona fuera del alcance;
    - una pantalla de todo el cliente (`requireFull`).
  - **403 `delegation_exceeds_own_access`:** dar permisos que uno no tiene, permisos no delegables o `roles.gestionar`, o un alcance más amplio.
- **Reglas de cobertura (delegación):**
  - Alcance `all`: cubre todo.
  - El alcance pedido `all` solo lo cubre `all`.
  - `self` se puede dar a cualquier persona que uno ve.
  - `team` solo lo da quien tiene `team`, y solo a alguien de su propio equipo.
  - `filters` cubre `filters` si cada filtro que uno limita, el otro también lo limita, a un subconjunto. Las ciudades se comparan sin mayúsculas ni tildes.
  - Cambiar o quitar un acceso exige también cubrir el acceso actual de esa persona.
- **Superadmin:** nunca queda bloqueado. Su alcance es `all` y no pasa por la cobertura, pero no da permisos `platform_only` a roles de cliente (como hoy).
- **Compuerta vieja:** `tenants.rbac_self_management` (setRbacGate) se mantiene igual en esta fase. Para un cliente, `roles.gestionar` exige compuerta encendida **y** la función `autogestion_accesos`.
- **Esquema:**
  - Tablas nuevas con `tenant_id` obligatorio y FKs que incluyen `tenant_id` (`migrations/verify.php`).
  - Columna de tipo de unidad: `VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin`, igual que `org_units.kind`.
  - En la migración, los `ALTER` van al final del archivo (no son idempotentes).
- **SQL compatible MySQL 8.4 y MariaDB 11.4:**
  - Funciones de ventana (`ROW_NUMBER() OVER`), sí. `UNHEX(REPLACE(UUID(),'-',''))` para ids, sí.
  - Nada de `UPDATE` con subconsulta sobre la misma tabla (error 1093), ni CTE recursivas, ni `JSON_TABLE`, ni `ADD COLUMN IF NOT EXISTS`.
  - Los CHECK fallan con 3819 en MySQL y 4025 en MariaDB (`rejected()` ya traduce).
- **Contrato:**
  - Tras editar `openapi-v4.yaml`, correr desde `backend-v4` `python tests/export-contract.py`, y desde la raíz `node client-v4/tools/generate.mjs`. Se espera que `client-v4` no cambie: son esquemas del panel.
  - El YAML usa CRLF: preservarlo. Descripciones sin `: `.
- **Auditoría:** toda operación de escritura del contrato tiene su texto en `AUDIT_ACTIONS` de `public/assets/k3.js` (lo vigila `tests/ops.php`).
- **Suite completa:** desde `backend-v4`. Debe terminar en `SMOKE PASS`; la base actual es 2506.

  ```
  export PATH="$(ls -d /c/laragon/bin/mysql/mysql-8.4.3*)/bin:$(ls -d /c/laragon/bin/php/php-8.3.30*):$PATH" && powershell -NoProfile -File tests/mysql-smoke.ps1 -DebugResponses > "$SCRATCH/smoke.log" 2>&1; grep -n "FAIL\|SMOKE PASS" "$SCRATCH/smoke.log"
  ```
- **Commits:** terminan con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Nada de deploy, ssh ni push en las tareas 1 a 10.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `backend-v4/migrations/0043_access_role_scope.sql` (nuevo) | Tablas de accesos y reporte, plantilla Corporativo, nombres Gerente/Coordinador, conversión rol → persona, `role_templates.offered`. |
| `backend-v4/migrations/verify.php` | Conteos de plantillas/roles y guardias de `access_grants`. |
| `backend-v4/src/AccessScope.php` (nuevo) | Valor del alcance: forma, carga/guardado, SQL, pertenencia, cobertura, ciudades. |
| `backend-v4/src/AccessGrants.php` (nuevo) | Leer/guardar el acceso de una persona o cuenta de grupo, acceso por defecto, asignación con delegación, DTO. |
| `backend-v4/src/AdminAccess.php` | Reescrito sobre un único acceso. |
| `backend-v4/src/AdminApi.php` | Llamadores: personas, filtros, reportes, reglas, roles, organización, `/me/access`, `PUT /users/{id}/access`, DTO de persona. |
| `backend-v4/src/ActivityReports.php`, `backend-v4/src/ExternalApi.php`, `backend-v4/src/DataSync.php` | Filtros con `scope_denied`, etiquetas de rol y acceso por defecto. |
| `backend-v4/src/PanelAccounts.php`, `backend-v4/src/CompanyGroups.php`, `backend-v4/src/AdminAuth.php`, `backend-v4/src/TenantPurge.php` | Cuentas y cuentas de grupo sobre `access_grants`. |
| `backend-v4/src/RoleRules.php` | La regla por cargo propone el rol y no toca el alcance. |
| `docs/architecture/openapi-v4.yaml` → `backend-v4/config/contract.json` | `AccessScope`, `Access`, `AccessInput`, `GroupAccessGrant`, `PUT /users/{id}/access`, cuentas, roles sin alcance, `MyAccess`. |
| `backend-v4/tests/access.php` (nuevo), `backend-v4/tests/smoke.php`, `backend-v4/tests/admin.php` y demás pruebas tocadas | Pruebas. |
| `backend-v4/public/assets/access-scope.js` (nuevo), `backend-v4/tests/access-summary.test.mjs` (nuevo) | Frase legible del acceso y su prueba con node. |
| `backend-v4/public/assets/k3.js`, `api.js`, `app.js`, `backend-v4/public/roles.php` | Pantallas: Personas, Cuentas, Roles; mensajes de error. |
| `backend-v4/ADMIN.md` | Documentación del modelo de accesos. |

---

### Task 1: Migración 0043 — tablas de accesos, plantillas y conversión rol → persona

**Files:**
- Create: `backend-v4/migrations/0043_access_role_scope.sql`
- Create: `backend-v4/tests/access.php`
- Modify: `backend-v4/migrations/verify.php:68-69` (conteos) y después de `:92` (guardias)
- Modify: `backend-v4/tests/smoke.php` (require y llamada)

**Interfaces:**
- Produces:
  - Tabla `access_grants`: `tenant_id`, `id`, `user_id` NULL, `admin_tenant_id` NULL, `admin_id` NULL, `role_id`, `scope_kind ENUM('all','filters','team','self')`, `source ENUM('manual','rule','default')`, `rule_id` NULL, `version`, `created_at`.
  - Tablas `access_grant_units` (`tenant_id`, `grant_id`, `unit_id`, `unit_kind`) y `access_grant_cities` (`tenant_id`, `grant_id`, `city`).
  - Tabla `access_migration_report`: `tenant_id`, `id`, `user_id`, `admin_tenant_id`, `admin_id`, `kept_role_id` NULL, `dropped_roles`, `lost_permissions`, `created_at`.
  - Columna `role_templates.offered`.
  - Marcadores `-- BEGIN conversion` / `-- END conversion` en el SQL; la variable `@access_tenant` limita la conversión a un cliente.
  - Helper de pruebas `accessConversion(Database $db, string $tenant): void`.

- [ ] **Step 1: Write the failing test** — crear `backend-v4/tests/access.php`:

```php
<?php
declare(strict_types=1);
use Keeper\{AccessGrants,AccessScope,Database,Util};

// Fase 3 de v4-clientes-funciones-accesos: 1 rol + 1 alcance por persona (2026-10-05).

/** Vuelve a correr la conversion de 0043 (entre sus marcadores) solo sobre un cliente de prueba. */
function accessConversion(Database $db, string $tenant): void
{
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/0043_access_role_scope.sql');
    $start = strpos($sql, '-- BEGIN conversion'); $end = strpos($sql, '-- END conversion');
    check($start !== false && $end !== false && $end > $start, 'conversion markers present');
    $db->run('SET @access_tenant = ?', [$tenant]);
    foreach (preg_split('/;\s*\R/', substr($sql, $start, $end - $start)) as $statement) {
        $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
        if ($statement !== '') { $db->run($statement); }
    }
    $db->run('SET @access_tenant = NULL');
}

function accessMigrationTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $legacyRole = function (string $name, string $kind, array $permissions, array $units = [], ?string $seed = null) use ($db, $tenant): string {
        $id = Util::bin(Util::uuid());
        $db->run('INSERT INTO roles (tenant_id,id,name,seed_key,scope_kind) VALUES (?,?,?,?,?)', [$tenant, $id, $name, $seed, $kind]);
        foreach ($permissions as $p) { $db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)', [$tenant, $id, $p]); }
        foreach ($units as $unit) { $db->run("INSERT INTO role_{$kind}_scopes (tenant_id,role_id,{$kind}_id) VALUES (?,?,?)", [$tenant, $id, $unit]); }
        return $id;
    };
    $area2 = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'area','Agentes de servicio')", [$tenant, $area2]);
    $colab = $legacyRole('Colaborador', 'self', [], [], 'colaborador');
    $coord = $legacyRole('Coordinación legado', 'area', ['usuarios.ver', 'reportes.ver'], [$u['area_id'], $area2]);
    $sede = $legacyRole('Sede legado', 'site', ['usuarios.ver', 'equipos.ver'], [$u['site_id']]);
    $todo = $legacyRole('Gerencia legado', 'tenant', ['usuarios.ver']);
    $p1 = adminMember($db, $u); $p2 = adminMember($db, $u); $p3 = adminMember($db, $u); $p4 = adminMember($db, $u);
    $bind = fn (array $p, string $role) => $db->run('INSERT INTO user_roles (tenant_id,user_id,role_id) VALUES (?,?,?)', [$tenant, $p['id'], $role]);
    $bind($p1, $colab);                     // solo el Colaborador automatico
    $bind($p2, $coord); $bind($p2, $colab); // Coordinacion + Colaborador: queda Coordinacion, sin perdida
    $bind($p3, $sede); $bind($p3, $todo);   // dos roles reales: queda el de toda la empresa y se reporta lo perdido
    accessConversion($db, $tenant);

    $grant = fn (array $p) => $db->one('SELECT * FROM access_grants WHERE tenant_id=? AND user_id=?', [$tenant, $p['id']]);
    $units = fn (array $g) => array_column($db->run('SELECT unit_id FROM access_grant_units WHERE tenant_id=? AND grant_id=? ORDER BY unit_id', [$tenant, $g['id']])->fetchAll(), 'unit_id');
    $report = fn (array $p) => $db->one('SELECT * FROM access_migration_report WHERE tenant_id=? AND user_id=?', [$tenant, $p['id']]);
    $g1 = $grant($p1);
    check($g1['role_id'] === $colab && $g1['scope_kind'] === 'self' && $g1['source'] === 'default', 'collaborator keeps self as the default access');
    $g2 = $grant($p2); $want = [$u['area_id'], $area2]; sort($want, SORT_STRING);
    check($g2['role_id'] === $coord && $g2['scope_kind'] === 'filters' && $g2['source'] === 'manual' && $units($g2) === $want, 'area role scope copied to the person');
    $g3 = $grant($p3);
    check($g3['role_id'] === $todo && $g3['scope_kind'] === 'all' && $units($g3) === [], 'widest role kept');
    check($grant($p4) === null, 'person without roles gets no access');
    check($report($p1) === null, 'single role not reported');
    $r2 = $report($p2);
    check($r2 !== null && $r2['kept_role_id'] === $coord && $r2['dropped_roles'] === 'Colaborador (self)' && $r2['lost_permissions'] === '', 'lossless merge reported');
    $r3 = $report($p3);
    check($r3 !== null && $r3['dropped_roles'] === 'Sede legado (site)' && $r3['lost_permissions'] === 'equipos.ver', 'lost permissions reported');

    // Cuenta de grupo: en la otra empresa queda un acceso (todo o filtros); si solo tenia equipo/solo lo propio, se reporta.
    $b = secondTenant($db); $tb = $b['tenant_id'];
    $roleB = Util::bin(Util::uuid()); $teamB = Util::bin(Util::uuid());
    $db->run("INSERT INTO roles (tenant_id,id,name,scope_kind) VALUES (?,?,'Directivo','tenant')", [$tb, $roleB]);
    $db->run("INSERT INTO roles (tenant_id,id,name,scope_kind) VALUES (?,?,'Equipo B','team')", [$tb, $teamB]);
    $account = Util::bin(Util::uuid()); $other = Util::bin(Util::uuid());
    $db->run("INSERT INTO admin_accounts (tenant_id,id,user_id,email,password_hash) VALUES (?,?,?,?,'x')", [$tenant, $account, $p1['id'], Util::uuid() . '@example.test']);
    $db->run("INSERT INTO admin_accounts (tenant_id,id,user_id,email,password_hash) VALUES (?,?,?,?,'x')", [$tenant, $other, $p2['id'], Util::uuid() . '@example.test']);
    $db->run('INSERT INTO admin_account_roles (admin_tenant_id,admin_id,tenant_id,role_id) VALUES (?,?,?,?)', [$tenant, $account, $tb, $roleB]);
    $db->run('INSERT INTO admin_account_roles (admin_tenant_id,admin_id,tenant_id,role_id) VALUES (?,?,?,?)', [$tenant, $other, $tb, $teamB]);
    accessConversion($db, $tb);
    $gg = $db->one('SELECT * FROM access_grants WHERE tenant_id=? AND admin_tenant_id=? AND admin_id=?', [$tb, $tenant, $account]);
    check($gg !== null && $gg['role_id'] === $roleB && $gg['scope_kind'] === 'all' && $gg['user_id'] === null, 'group account access in the other company');
    check($db->one('SELECT 1 x FROM access_grants WHERE tenant_id=? AND admin_id=?', [$tb, $other]) === null, 'team-only group role gives no access');
    $rg = $db->one('SELECT * FROM access_migration_report WHERE tenant_id=? AND admin_id=?', [$tb, $other]);
    check($rg !== null && $rg['kept_role_id'] === null && $rg['dropped_roles'] === 'Equipo B (team)', 'group account without usable role reported');

    // Idempotente: correrla otra vez no duplica nada.
    accessConversion($db, $tenant); accessConversion($db, $tb);
    check((int) $db->one('SELECT COUNT(*) n FROM access_grants WHERE tenant_id=?', [$tenant])['n'] === 3, 'conversion is idempotent (grants)');
    check((int) $db->one('SELECT COUNT(*) n FROM access_migration_report WHERE tenant_id IN (?,?)', [$tenant, $tb])['n'] === 3, 'conversion is idempotent (report)');
    echo 'PASS access migration (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `backend-v4/tests/smoke.php`:
- agregar `require __DIR__ . '/access.php';` después de `require __DIR__ . '/plans.php';`;
- agregar `accessMigrationTests($db);` justo antes de `echo "SMOKE PASS: $checks assertions\n";`.

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL. `file_get_contents(...0043_access_role_scope.sql)` no encuentra el archivo y el log muestra `conversion markers present`.

- [ ] **Step 3: Write the migration** — crear `backend-v4/migrations/0043_access_role_scope.sql`:

```sql
-- Accesos: 1 rol + 1 alcance por persona (docs/architecture/v4-clientes-funciones-accesos.md §4 y §6, fase 3).
-- El alcance deja de vivir en el rol: va en la persona (access_grants.user_id) o, para una cuenta de grupo, en la cuenta
-- dentro de cada otra empresa de su grupo (admin_tenant_id + admin_id). Las tablas viejas (user_roles,
-- admin_account_roles, role_area_scopes, role_site_scopes y roles.scope_kind) quedan congeladas: el codigo deja de
-- leerlas y escribirlas; se borran en la fase 4.

CREATE TABLE IF NOT EXISTS access_grants (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NULL,
  admin_tenant_id BINARY(16) NULL,
  admin_id BINARY(16) NULL,
  role_id BINARY(16) NOT NULL,
  scope_kind ENUM('all','filters','team','self') NOT NULL DEFAULT 'self',
  source ENUM('manual','rule','default') NOT NULL DEFAULT 'manual',
  rule_id BINARY(16) NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY access_grants_user (tenant_id, user_id),
  UNIQUE KEY access_grants_account (tenant_id, admin_tenant_id, admin_id),
  KEY access_grants_role (tenant_id, role_id),
  KEY access_grants_holder (admin_tenant_id, admin_id),
  CONSTRAINT access_grants_user_fk FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  CONSTRAINT access_grants_account_fk FOREIGN KEY (admin_tenant_id, admin_id) REFERENCES admin_accounts (tenant_id, id),
  CONSTRAINT access_grants_role_fk FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id),
  CONSTRAINT access_grants_rule_fk FOREIGN KEY (tenant_id, rule_id) REFERENCES panel_role_rules (tenant_id, id),
  CONSTRAINT access_grants_one_holder CHECK ((user_id IS NOT NULL AND admin_id IS NULL AND admin_tenant_id IS NULL)
    OR (user_id IS NULL AND admin_id IS NOT NULL AND admin_tenant_id IS NOT NULL AND admin_tenant_id <> tenant_id)),
  CONSTRAINT access_grants_group_scope CHECK (user_id IS NOT NULL OR scope_kind IN ('all','filters')),
  CONSTRAINT access_grants_rule_source CHECK ((source = 'rule') = (rule_id IS NOT NULL)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS access_grant_units (
  tenant_id BINARY(16) NOT NULL,
  grant_id BINARY(16) NOT NULL,
  unit_id BINARY(16) NOT NULL,
  unit_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, grant_id, unit_id),
  KEY access_grant_units_unit (tenant_id, unit_id),
  CONSTRAINT access_grant_units_grant FOREIGN KEY (tenant_id, grant_id) REFERENCES access_grants (tenant_id, id) ON DELETE CASCADE,
  CONSTRAINT access_grant_units_org FOREIGN KEY (tenant_id, unit_id, unit_kind) REFERENCES org_units (tenant_id, id, kind),
  CONSTRAINT access_grant_units_kind CHECK (unit_kind IN ('company','site','area')),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Ciudad: texto de la sede (org_units.city); la collation ai_ci la compara sin mayusculas ni tildes.
CREATE TABLE IF NOT EXISTS access_grant_cities (
  tenant_id BINARY(16) NOT NULL,
  grant_id BINARY(16) NOT NULL,
  city VARCHAR(80) NOT NULL,
  PRIMARY KEY (tenant_id, grant_id, city),
  CONSTRAINT access_grant_cities_grant FOREIGN KEY (tenant_id, grant_id) REFERENCES access_grants (tenant_id, id) ON DELETE CASCADE,
  CONSTRAINT access_grant_cities_text CHECK (CHAR_LENGTH(TRIM(city)) > 0),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Reporte de la migracion (§6): personas o cuentas de grupo que tenian varios roles. Sin FK a roles/personas: sobrevive.
CREATE TABLE IF NOT EXISTS access_migration_report (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NULL,
  admin_tenant_id BINARY(16) NULL,
  admin_id BINARY(16) NULL,
  kept_role_id BINARY(16) NULL,
  dropped_roles VARCHAR(2000) NOT NULL,
  lost_permissions VARCHAR(2000) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Plantillas de la plataforma (§4): Corporativo, Gerente, Coordinador, IT, Colaborador.
INSERT INTO role_templates (seed_key, name, scope_kind, platform_only)
SELECT 'corporativo', 'Corporativo', 'tenant', 0
WHERE NOT EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'corporativo');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'corporativo', p.code FROM permissions p
WHERE p.platform_only = FALSE
  AND p.code IN ('empresas.ver', 'empresas.editar', 'usuarios.ver', 'organizacion.ver', 'horarios.ver', 'equipos.ver', 'reglas.ver', 'roles.ver',
    'reportes.ver', 'actividad.ver', 'actividad.titulos_ver', 'presencia.ver', 'presencia.ubicacion_ver', 'festivos.ver', 'cumplimiento.ver',
    'cobertura.ver', 'auditoria.ver', 'notificaciones.ver', 'integraciones.ver', 'tiers.ver')
  AND NOT EXISTS (SELECT 1 FROM role_template_permissions t WHERE t.seed_key = 'corporativo' AND t.permission_code = p.code);

UPDATE role_templates SET name = 'Gerente', version = version + 1 WHERE seed_key = 'gerencia' AND name <> 'Gerente';
UPDATE role_templates SET name = 'Coordinador', version = version + 1 WHERE seed_key = 'coordinacion' AND name <> 'Coordinador';

-- Rol Corporativo en cada cliente existente (los demas roles del cliente no se renombran ni se borran).
INSERT INTO roles (tenant_id, id, name, seed_key, seed_version, scope_kind)
SELECT t.tenant_id, UNHEX(REPLACE(UUID(), '-', '')), 'Corporativo', 'corporativo', 1, 'tenant'
FROM tenants t
WHERE t.tenant_id <> 0x00000000000040008000000000000001 AND t.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM roles r WHERE r.tenant_id = t.tenant_id AND (r.seed_key = 'corporativo' OR r.name = 'Corporativo'));

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, tp.permission_code
FROM roles r JOIN role_template_permissions tp ON tp.seed_key = 'corporativo'
WHERE r.seed_key = 'corporativo'
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = tp.permission_code);

SET @access_tenant = NULL;

-- BEGIN conversion (tests/access.php la vuelve a ejecutar con @access_tenant = un cliente de prueba; sin ; dentro de textos)
-- 1. Personas: de sus roles activos queda uno, el de mayor alcance (todo > sede/area con mas personas > equipo > propio);
--    su alcance pasa a la persona. Colaborador solo = acceso por defecto; puesto por regla = origen regla.
INSERT INTO access_grants (tenant_id, id, user_id, role_id, scope_kind, source, rule_id)
SELECT ranked.tenant_id, UNHEX(REPLACE(UUID(), '-', '')), ranked.user_id, ranked.role_id,
  CASE ranked.scope_kind WHEN 'tenant' THEN 'all' WHEN 'site' THEN 'filters' WHEN 'area' THEN 'filters' WHEN 'team' THEN 'team' ELSE 'self' END,
  CASE WHEN ranked.rule_id IS NOT NULL THEN 'rule' WHEN ranked.seed_key IN ('colaborador', 'collaborator') THEN 'default' ELSE 'manual' END,
  ranked.rule_id
FROM (
  SELECT c.*, ROW_NUMBER() OVER (PARTITION BY c.tenant_id, c.user_id ORDER BY c.kind_rank DESC, c.reach DESC, c.permission_count DESC, c.role_id) rn
  FROM (
    SELECT ur.tenant_id, ur.user_id, ur.role_id, ur.rule_id, r.scope_kind, r.seed_key,
      CASE r.scope_kind WHEN 'tenant' THEN 4 WHEN 'site' THEN 3 WHEN 'area' THEN 3 WHEN 'team' THEN 2 ELSE 1 END kind_rank,
      CASE r.scope_kind
        WHEN 'site' THEN (SELECT COUNT(*) FROM users x JOIN role_site_scopes s ON s.tenant_id = x.tenant_id AND s.site_id = x.site_id
          WHERE s.tenant_id = r.tenant_id AND s.role_id = r.id AND x.status = 'active')
        WHEN 'area' THEN (SELECT COUNT(*) FROM users x JOIN role_area_scopes s ON s.tenant_id = x.tenant_id AND s.area_id = x.area_id
          WHERE s.tenant_id = r.tenant_id AND s.role_id = r.id AND x.status = 'active')
        ELSE 0 END reach,
      (SELECT COUNT(*) FROM role_permissions p WHERE p.tenant_id = r.tenant_id AND p.role_id = r.id) permission_count
    FROM user_roles ur JOIN roles r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.active = TRUE
    WHERE @access_tenant IS NULL OR ur.tenant_id = @access_tenant
  ) c
) ranked
WHERE ranked.rn = 1
  AND NOT EXISTS (SELECT 1 FROM access_grants g WHERE g.tenant_id = ranked.tenant_id AND g.user_id = ranked.user_id);

-- 2. Cuentas de grupo en otras empresas: solo roles de toda la empresa, sede o area (equipo/propio no ven nada alli).
INSERT INTO access_grants (tenant_id, id, admin_tenant_id, admin_id, role_id, scope_kind, source)
SELECT ranked.tenant_id, UNHEX(REPLACE(UUID(), '-', '')), ranked.admin_tenant_id, ranked.admin_id, ranked.role_id,
  IF(ranked.scope_kind = 'tenant', 'all', 'filters'), 'manual'
FROM (
  SELECT c.*, ROW_NUMBER() OVER (PARTITION BY c.tenant_id, c.admin_tenant_id, c.admin_id ORDER BY c.kind_rank DESC, c.reach DESC, c.permission_count DESC, c.role_id) rn
  FROM (
    SELECT g.tenant_id, g.admin_tenant_id, g.admin_id, g.role_id, r.scope_kind,
      CASE r.scope_kind WHEN 'tenant' THEN 4 ELSE 3 END kind_rank,
      CASE r.scope_kind
        WHEN 'site' THEN (SELECT COUNT(*) FROM users x JOIN role_site_scopes s ON s.tenant_id = x.tenant_id AND s.site_id = x.site_id
          WHERE s.tenant_id = r.tenant_id AND s.role_id = r.id AND x.status = 'active')
        WHEN 'area' THEN (SELECT COUNT(*) FROM users x JOIN role_area_scopes s ON s.tenant_id = x.tenant_id AND s.area_id = x.area_id
          WHERE s.tenant_id = r.tenant_id AND s.role_id = r.id AND x.status = 'active')
        ELSE 0 END reach,
      (SELECT COUNT(*) FROM role_permissions p WHERE p.tenant_id = r.tenant_id AND p.role_id = r.id) permission_count
    FROM admin_account_roles g JOIN roles r ON r.tenant_id = g.tenant_id AND r.id = g.role_id AND r.active = TRUE
    WHERE r.scope_kind IN ('tenant', 'site', 'area') AND (@access_tenant IS NULL OR g.tenant_id = @access_tenant)
  ) c
) ranked
WHERE ranked.rn = 1
  AND NOT EXISTS (SELECT 1 FROM access_grants x WHERE x.tenant_id = ranked.tenant_id AND x.admin_tenant_id = ranked.admin_tenant_id AND x.admin_id = ranked.admin_id);

-- 3. Filtros: las sedes o areas del rol que quedo pasan al acceso (nadie gana ni pierde visibilidad).
INSERT INTO access_grant_units (tenant_id, grant_id, unit_id, unit_kind)
SELECT g.tenant_id, g.id, s.site_id, 'site'
FROM access_grants g
JOIN roles r ON r.tenant_id = g.tenant_id AND r.id = g.role_id AND r.scope_kind = 'site'
JOIN role_site_scopes s ON s.tenant_id = r.tenant_id AND s.role_id = r.id
WHERE g.scope_kind = 'filters' AND (@access_tenant IS NULL OR g.tenant_id = @access_tenant)
  AND NOT EXISTS (SELECT 1 FROM access_grant_units u WHERE u.tenant_id = g.tenant_id AND u.grant_id = g.id);

INSERT INTO access_grant_units (tenant_id, grant_id, unit_id, unit_kind)
SELECT g.tenant_id, g.id, a.area_id, 'area'
FROM access_grants g
JOIN roles r ON r.tenant_id = g.tenant_id AND r.id = g.role_id AND r.scope_kind = 'area'
JOIN role_area_scopes a ON a.tenant_id = r.tenant_id AND a.role_id = r.id
WHERE g.scope_kind = 'filters' AND (@access_tenant IS NULL OR g.tenant_id = @access_tenant)
  AND NOT EXISTS (SELECT 1 FROM access_grant_units u WHERE u.tenant_id = g.tenant_id AND u.grant_id = g.id);

-- 4. Reporte (§6): toda persona con varios roles activos; lost_permissions vacio = no perdio nada.
INSERT INTO access_migration_report (tenant_id, id, user_id, kept_role_id, dropped_roles, lost_permissions)
SELECT g.tenant_id, UNHEX(REPLACE(UUID(), '-', '')), g.user_id, g.role_id,
  (SELECT GROUP_CONCAT(CONCAT(r.name, ' (', r.scope_kind, ')') ORDER BY r.name SEPARATOR ', ')
     FROM user_roles ur JOIN roles r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.active = TRUE
     WHERE ur.tenant_id = g.tenant_id AND ur.user_id = g.user_id AND ur.role_id <> g.role_id),
  COALESCE((SELECT GROUP_CONCAT(DISTINCT p.permission_code ORDER BY p.permission_code SEPARATOR ', ')
     FROM user_roles ur JOIN roles r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.active = TRUE
     JOIN role_permissions p ON p.tenant_id = r.tenant_id AND p.role_id = r.id
     WHERE ur.tenant_id = g.tenant_id AND ur.user_id = g.user_id AND ur.role_id <> g.role_id
       AND NOT EXISTS (SELECT 1 FROM role_permissions k WHERE k.tenant_id = g.tenant_id AND k.role_id = g.role_id AND k.permission_code = p.permission_code)), '')
FROM access_grants g
WHERE g.user_id IS NOT NULL AND (@access_tenant IS NULL OR g.tenant_id = @access_tenant)
  AND (SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.active = TRUE
       WHERE ur.tenant_id = g.tenant_id AND ur.user_id = g.user_id) > 1
  AND NOT EXISTS (SELECT 1 FROM access_migration_report m WHERE m.tenant_id = g.tenant_id AND m.user_id = g.user_id);

-- 5. Reporte de cuentas de grupo: varios roles en una empresa, o ninguno utilizable (kept_role_id NULL).
INSERT INTO access_migration_report (tenant_id, id, admin_tenant_id, admin_id, kept_role_id, dropped_roles, lost_permissions)
SELECT x.tenant_id, UNHEX(REPLACE(UUID(), '-', '')), x.admin_tenant_id, x.admin_id, g.role_id,
  (SELECT GROUP_CONCAT(CONCAT(r.name, ' (', r.scope_kind, ')') ORDER BY r.name SEPARATOR ', ')
     FROM admin_account_roles ar JOIN roles r ON r.tenant_id = ar.tenant_id AND r.id = ar.role_id AND r.active = TRUE
     WHERE ar.tenant_id = x.tenant_id AND ar.admin_tenant_id = x.admin_tenant_id AND ar.admin_id = x.admin_id AND (g.role_id IS NULL OR ar.role_id <> g.role_id)),
  COALESCE((SELECT GROUP_CONCAT(DISTINCT p.permission_code ORDER BY p.permission_code SEPARATOR ', ')
     FROM admin_account_roles ar JOIN roles r ON r.tenant_id = ar.tenant_id AND r.id = ar.role_id AND r.active = TRUE
     JOIN role_permissions p ON p.tenant_id = r.tenant_id AND p.role_id = r.id
     WHERE ar.tenant_id = x.tenant_id AND ar.admin_tenant_id = x.admin_tenant_id AND ar.admin_id = x.admin_id AND (g.role_id IS NULL OR ar.role_id <> g.role_id)
       AND NOT EXISTS (SELECT 1 FROM role_permissions k WHERE k.tenant_id = x.tenant_id AND k.role_id = g.role_id AND k.permission_code = p.permission_code)), '')
FROM (SELECT DISTINCT ar.tenant_id, ar.admin_tenant_id, ar.admin_id
      FROM admin_account_roles ar JOIN roles r ON r.tenant_id = ar.tenant_id AND r.id = ar.role_id AND r.active = TRUE
      WHERE @access_tenant IS NULL OR ar.tenant_id = @access_tenant) x
LEFT JOIN access_grants g ON g.tenant_id = x.tenant_id AND g.admin_tenant_id = x.admin_tenant_id AND g.admin_id = x.admin_id
WHERE (g.id IS NULL OR (SELECT COUNT(*) FROM admin_account_roles ar JOIN roles r ON r.tenant_id = ar.tenant_id AND r.id = ar.role_id AND r.active = TRUE
       WHERE ar.tenant_id = x.tenant_id AND ar.admin_tenant_id = x.admin_tenant_id AND ar.admin_id = x.admin_id) > 1)
  AND NOT EXISTS (SELECT 1 FROM access_migration_report m WHERE m.tenant_id = x.tenant_id AND m.admin_tenant_id = x.admin_tenant_id AND m.admin_id = x.admin_id);
-- END conversion

-- Al final: los ALTER no son idempotentes; si algo anterior falla, reintentar el archivo es seguro.
-- Plantillas que se ofrecen a clientes nuevos: las cinco del §4. Direccion, RRHH y Admin Empresa quedan solo en clientes que ya las tienen.
ALTER TABLE role_templates ADD COLUMN offered BOOLEAN NOT NULL DEFAULT TRUE;
UPDATE role_templates SET offered = FALSE WHERE seed_key IN ('direccion', 'rrhh', 'admin_empresa');
```

En `backend-v4/migrations/verify.php`, reemplazar las líneas 68-69:

```php
    verify(countRows($pdo, 'SELECT COUNT(*) FROM role_templates') === 9, 'nine seed roles (Corporativo added in 0043)');
    verify(countRows($pdo, 'SELECT COUNT(*) FROM roles') === 9, 'platform role plus eight demo roles');
    verify(countRows($pdo, 'SELECT COUNT(*) FROM role_templates WHERE platform_only = FALSE AND offered = TRUE') === 5, 'five templates offered to new clients');
    verify(countRows($pdo, "SELECT COUNT(*) FROM role_templates WHERE seed_key IN ('gerencia','coordinacion') AND name IN ('Gerente','Coordinador')") === 2, 'templates renamed');
    verify(countRows($pdo, "SELECT COUNT(*) FROM access_grants WHERE tenant_id = 0x00000000000040008000000000000002 AND user_id = 0x10000000000040008000000000000007
        AND role_id = 0x1000000000004000800000000000006b AND scope_kind = 'self' AND source = 'default'") === 1, 'demo collaborator migrated to one default access');
```

Y después de la línea 92 (`rejected(... 'cross-tenant role binding')`):

```php
    rejected($pdo, "INSERT INTO access_grants (tenant_id,id,user_id,role_id) VALUES ($other," . fixtureId(90) . ",$user," . seedId(107) . ')', 1452, 'cross-tenant access grant');
    rejected($pdo, "INSERT INTO access_grants (tenant_id,id,role_id) VALUES ($tenant," . fixtureId(91) . ',' . seedId(107) . ')', 3819, 'access grant needs exactly one holder');
```

- [ ] **Step 4: Run test to verify it passes**

Run: suite completa.
Expected:
- `PASS access migration (17 assertions)` (o el número que salga, sin `FAIL`);
- `SMOKE PASS: N assertions` con N > 2506;
- `verify.php` sin `FAIL`.

- [ ] **Step 5: Commit**

```bash
git add backend-v4/migrations/0043_access_role_scope.sql backend-v4/migrations/verify.php backend-v4/tests/access.php backend-v4/tests/smoke.php
git commit -m "Accesos: migración 0043 (alcance del rol pasa a la persona, plantillas, reporte de varios roles)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `AccessScope` y `AccessGrants`

**Files:**
- Create: `backend-v4/src/AccessScope.php`, `backend-v4/src/AccessGrants.php`
- Modify: `backend-v4/tests/access.php` (pruebas y helper `accessScopeBody`), `backend-v4/tests/smoke.php` (llamada)

**Interfaces:**
- Consumes: tablas de Task 1.
- Produces (ids siempre en binario salvo en `dto()`):
  - `new AccessScope(string $kind, array $companies = [], array $cities = [], array $sites = [], array $areas = [])` con propiedades `readonly` de esos nombres.
  - `AccessScope::all(): self`, `AccessScope::own(): self`.
  - `AccessScope::fromInput(Database $db, string $tenant, object $o): self`: valida el `AccessScope` del contrato. Puede lanzar 422 `validation_failed` con detalle:
    - `scope_lists_only_for_filters`;
    - `scope_filters_empty`;
    - `unknown_company`, `unknown_site`, `unknown_area`, `unknown_city`.
  - `AccessScope::load(Database $db, string $tenant, string $grantId, string $kind): self`.
  - `->save(Database $db, string $tenant, string $grantId): void`.
  - `->dto(): array` → `{kind, company_ids, cities, site_ids, area_ids}`.
  - `->isEmptyFilter(): bool`.
  - `->filterSql(string $alias): array{0:string,1:list}`: la fila del alias debe tener `tenant_id`, `company_id`, `site_id` y `area_id`.
  - `->matches(Database $db, string $tenant, array $person): bool`: `$person` con `company_id`, `site_id`, `area_id`.
  - `->covers(self $child): bool` (solo `all`/`filters`).
  - `->list(string $kind): list` con `$kind` ∈ `company|city|site|area`.
  - `AccessScope::cityKey(string $city): string`.
  - `AccessGrants::forUser(Database $db, string $tenant, string $user): ?array`.
  - `AccessGrants::forGroupAccount(Database $db, string $tenant, string $adminTenant, string $admin): ?array`.
  - `AccessGrants::forContext(Database $db, array $c): ?array`.
  - Fila de acceso: columnas de `access_grants` + `role_name` + `scope` (`AccessScope`).
  - `AccessGrants::permissions(Database $db, string $tenant, string $role): list<string>`: rol activo, sin `platform_only`, ordenado.
  - `AccessGrants::setForUser(Database $db, string $tenant, string $user, string $role, AccessScope $scope, string $source, ?string $rule = null): void`.
  - `AccessGrants::setForGroupAccount(Database $db, string $tenant, string $adminTenant, string $admin, string $role, AccessScope $scope): void`: lanza 422 `group_scope_invalid` si el alcance no es `all|filters`.
  - `AccessGrants::removeForUser(Database $db, string $tenant, string $user): void`.
  - `AccessGrants::defaultRole(Database $db, string $tenant): ?string`.
  - `AccessGrants::grantDefault(Database $db, string $tenant, string $user): void`.
  - `AccessGrants::dto(?array $grant): ?array` → `{role_id, role_name, scope, source}`.
  - Helper de pruebas `accessScopeBody(string $kind, array $lists = []): array`.

- [ ] **Step 1: Write the failing test** — agregar al final de `backend-v4/tests/access.php`:

```php
/** Cuerpo AccessScope del contrato: las cuatro listas siempre presentes y en el orden del DTO (se compara con ===). */
function accessScopeBody(string $kind, array $lists = []): array
{
    $body = ['kind' => $kind];
    foreach (['company_ids', 'cities', 'site_ids', 'area_ids'] as $key) { $body[$key] = $lists[$key] ?? []; }
    return $body;
}

function accessScopeTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $db->run("UPDATE org_units SET city='Medellín' WHERE tenant_id=? AND id=?", [$tenant, $u['site_id']]);
    $company = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'company','BilingueLaw')", [$tenant, $company]);
    $db->run('UPDATE users SET company_id=? WHERE tenant_id=? AND id=?', [$company, $tenant, $u['id']]);
    $fail = function (array $body, string $detail) use ($db, $tenant): void {
        try { AccessScope::fromInput($db, $tenant, json_decode(json_encode($body))); check(false, "scope rejected: $detail"); }
        catch (Keeper\ApiError $e) { check($e->status === 422 && $e->detail === $detail, "scope rejected: $detail"); }
    };
    $fail(accessScopeBody('all', ['area_ids' => [Util::id($u['area_id'])]]), 'scope_lists_only_for_filters');
    $fail(accessScopeBody('filters'), 'scope_filters_empty');
    $fail(accessScopeBody('filters', ['area_ids' => [Util::id($u['site_id'])]]), 'unknown_area');
    $fail(accessScopeBody('filters', ['cities' => ['Atlántida']]), 'unknown_city');
    $s = AccessScope::fromInput($db, $tenant, json_decode(json_encode(accessScopeBody('filters', ['cities' => ['  MEDELLIN '], 'company_ids' => [Util::id($company), Util::id($company)]]))));
    check($s->kind === 'filters' && $s->companies === [$company] && $s->cities === ['MEDELLIN'], 'duplicates collapsed, city trimmed');

    // Guardar y leer.
    $role = AccessGrants::defaultRole($db, $tenant) ?? (function () use ($db, $tenant) {
        $id = Util::bin(Util::uuid()); $db->run("INSERT INTO roles (tenant_id,id,name,seed_key,scope_kind) VALUES (?,?,'Colaborador','colaborador','self')", [$tenant, $id]); return $id; })();
    AccessGrants::setForUser($db, $tenant, $u['id'], $role, $s, 'manual');
    $g = AccessGrants::forUser($db, $tenant, $u['id']);
    check($g['role_name'] === 'Colaborador' && $g['scope']->kind === 'filters' && $g['scope']->companies === [$company] && $g['scope']->cities === ['MEDELLIN'], 'access round trip');
    check(AccessGrants::dto($g)['scope'] === accessScopeBody('filters', ['company_ids' => [Util::id($company)], 'cities' => ['MEDELLIN']]), 'dto shape');

    // SQL: O dentro de un filtro, Y entre filtros; ciudad sin mayusculas ni tildes; filtros vacios no ven nada.
    $sees = function (AccessScope $scope) use ($db, $tenant, $u): bool {
        [$where, $args] = $scope->filterSql('u');
        return (bool) $db->one("SELECT u.id FROM users u WHERE u.tenant_id=? AND u.id=? AND $where", [$tenant, $u['id'], ...$args]);
    };
    $other = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'area','Otra área')", [$tenant, $other]);
    check($sees($s), 'company AND city match');
    check($sees(new AccessScope('filters', [], [], [], [$other, $u['area_id']])), 'OR inside a filter');
    check(!$sees(new AccessScope('filters', [$company], [], [], [$other])), 'AND across filters');
    check(!$sees(new AccessScope('filters')), 'empty filters see nothing');
    check($s->matches($db, $tenant, ['company_id' => $company, 'site_id' => $u['site_id'], 'area_id' => $u['area_id']]), 'placement inside');
    check(!$s->matches($db, $tenant, ['company_id' => null, 'site_id' => $u['site_id'], 'area_id' => $u['area_id']]), 'person without company is outside a company filter');

    // Cobertura (delegacion): cada filtro que limito, el otro tambien lo limita a un subconjunto.
    $area = new AccessScope('filters', [], [], [], [$u['area_id']]);
    check(AccessScope::all()->covers($area) && $area->covers(new AccessScope('filters', [], [], [$u['site_id']], [$u['area_id']])), 'narrower filters covered');
    check(!$area->covers(new AccessScope('filters', [], [], [$u['site_id']])), 'dropping my area filter widens');
    check(!$area->covers(AccessScope::all()) && !$area->covers(new AccessScope('filters', [], [], [], [$other])), 'all or other area not covered');
    check((new AccessScope('filters', [], ['Medellín']))->covers(new AccessScope('filters', [], ['MEDELLIN'])), 'cities compared without case or accents');

    // Acceso por defecto: Colaborador, solo lo propio.
    $p = adminMember($db, $u);
    AccessGrants::grantDefault($db, $tenant, $p['id']);
    $d = AccessGrants::forUser($db, $tenant, $p['id']);
    check($d['role_id'] === $role && $d['scope']->kind === 'self' && $d['source'] === 'default', 'default access');
    try { AccessGrants::setForGroupAccount($db, $tenant, Util::bin(Util::uuid()), Util::bin(Util::uuid()), $role, new AccessScope('team')); check(false, 'group scope'); }
    catch (Keeper\ApiError $e) { check($e->detail === 'group_scope_invalid', 'group accounts only get all or filters'); }
    echo 'PASS access scope (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `tests/smoke.php` agregar `accessScopeTests($db);` después de `accessMigrationTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL `Class "Keeper\AccessScope" not found`.

- [ ] **Step 3: Write `backend-v4/src/AccessScope.php`**

```php
<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Alcance de un acceso (docs/architecture/v4-clientes-funciones-accesos.md §4): que datos ve una persona.
 * all = todo el cliente; filters = Empresa, Ciudad, Sede y Area (lista vacia = «todas»; dentro de un filtro se suman (O),
 * entre filtros se cruzan (Y)); team = el y quienes le reportan; self = solo el (por defecto).
 * Ids en binario; ciudades como el texto de la sede (org_units.city). Un filters sin valores no ve nada.
 */
final class AccessScope
{
    /** @param list<string> $companies @param list<string> $cities @param list<string> $sites @param list<string> $areas */
    public function __construct(public readonly string $kind, public readonly array $companies = [], public readonly array $cities = [], public readonly array $sites = [], public readonly array $areas = []) {}

    public static function all(): self { return new self('all'); }
    public static function own(): self { return new self('self'); }

    /** AccessScope del contrato; las unidades deben existir (no eliminadas) en el cliente y la ciudad, en alguna sede. */
    public static function fromInput(Database $db, string $tenant, object $o): self
    {
        $lists = ['company_ids' => array_values($o->company_ids), 'cities' => array_values($o->cities), 'site_ids' => array_values($o->site_ids), 'area_ids' => array_values($o->area_ids)];
        $any = (bool) array_filter($lists);
        if ($o->kind !== 'filters') {
            if ($any) { throw new ApiError(422, 'validation_failed', [], 'scope_lists_only_for_filters'); }
            return new self($o->kind);
        }
        if (!$any) { throw new ApiError(422, 'validation_failed', [], 'scope_filters_empty'); }
        $units = [];
        foreach (['company_ids' => 'company', 'site_ids' => 'site', 'area_ids' => 'area'] as $key => $kind) {
            $units[$kind] = [];
            foreach ($lists[$key] as $id) {
                $bin = Util::bin($id);
                if (!$db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind=? AND deleted_at IS NULL', [$tenant, $bin, $kind])) { throw new ApiError(422, 'validation_failed', [], "unknown_$kind"); }
                if (!in_array($bin, $units[$kind], true)) { $units[$kind][] = $bin; }
            }
        }
        $cities = [];
        foreach ($lists['cities'] as $city) {
            $clean = preg_replace('/\s+/u', ' ', trim($city));
            if (!$db->one("SELECT id FROM org_units WHERE tenant_id=? AND kind='site' AND deleted_at IS NULL AND city=? LIMIT 1", [$tenant, $clean])) { throw new ApiError(422, 'validation_failed', [], 'unknown_city'); }
            if (!in_array(self::cityKey($clean), array_map([self::class, 'cityKey'], $cities), true)) { $cities[] = $clean; }
        }
        return new self('filters', $units['company'], $cities, $units['site'], $units['area']);
    }

    public static function load(Database $db, string $tenant, string $grantId, string $kind): self
    {
        if ($kind !== 'filters') { return new self($kind); }
        $u = ['company' => [], 'site' => [], 'area' => []];
        foreach ($db->run('SELECT unit_id,unit_kind FROM access_grant_units WHERE tenant_id=? AND grant_id=? ORDER BY unit_id', [$tenant, $grantId])->fetchAll() as $r) { $u[$r['unit_kind']][] = $r['unit_id']; }
        $cities = array_column($db->run('SELECT city FROM access_grant_cities WHERE tenant_id=? AND grant_id=? ORDER BY city', [$tenant, $grantId])->fetchAll(), 'city');
        return new self('filters', $u['company'], $cities, $u['site'], $u['area']);
    }

    public function save(Database $db, string $tenant, string $grantId): void
    {
        $db->run('DELETE FROM access_grant_units WHERE tenant_id=? AND grant_id=?', [$tenant, $grantId]);
        $db->run('DELETE FROM access_grant_cities WHERE tenant_id=? AND grant_id=?', [$tenant, $grantId]);
        foreach (['company' => $this->companies, 'site' => $this->sites, 'area' => $this->areas] as $kind => $ids) {
            foreach ($ids as $id) { $db->run('INSERT INTO access_grant_units (tenant_id,grant_id,unit_id,unit_kind) VALUES (?,?,?,?)', [$tenant, $grantId, $id, $kind]); }
        }
        foreach ($this->cities as $city) { $db->run('INSERT INTO access_grant_cities (tenant_id,grant_id,city) VALUES (?,?,?)', [$tenant, $grantId, $city]); }
    }

    public function dto(): array
    {
        $ids = static fn (array $v): array => array_map([Util::class, 'id'], $v);
        return ['kind' => $this->kind, 'company_ids' => $ids($this->companies), 'cities' => $this->cities, 'site_ids' => $ids($this->sites), 'area_ids' => $ids($this->areas)];
    }

    public function isEmptyFilter(): bool { return $this->kind === 'filters' && !$this->companies && !$this->cities && !$this->sites && !$this->areas; }

    /** @return list<string> */
    public function list(string $kind): array
    {
        return match ($kind) { 'company' => $this->companies, 'city' => $this->cities, 'site' => $this->sites, 'area' => $this->areas };
    }

    /** Condicion de filtros sobre una fila con tenant_id, company_id, site_id y area_id (users o user_assignments). */
    public function filterSql(string $alias): array
    {
        if ($this->kind !== 'filters' || $this->isEmptyFilter()) { return ['1=0', []]; }
        $parts = []; $args = [];
        $marks = static fn (array $v): string => implode(',', array_fill(0, count($v), '?'));
        foreach (['company' => $this->companies, 'site' => $this->sites, 'area' => $this->areas] as $kind => $ids) {
            if ($ids) { $parts[] = "$alias.{$kind}_id IN (" . $marks($ids) . ')'; array_push($args, ...$ids); }
        }
        if ($this->cities) {
            $parts[] = "EXISTS (SELECT 1 FROM org_units scope_city WHERE scope_city.tenant_id=$alias.tenant_id AND scope_city.id=$alias.site_id AND scope_city.city IN (" . $marks($this->cities) . '))';
            array_push($args, ...$this->cities);
        }
        return ['(' . implode(' AND ', $parts) . ')', $args];
    }

    /** ¿Una persona ubicada asi (company_id, site_id, area_id binarios o null) cae dentro de este alcance? */
    public function matches(Database $db, string $tenant, array $person): bool
    {
        if ($this->kind === 'all') { return true; }
        if ($this->kind !== 'filters' || $this->isEmptyFilter()) { return false; }
        foreach (['company' => $this->companies, 'site' => $this->sites, 'area' => $this->areas] as $kind => $ids) {
            if ($ids && !in_array($person[$kind . '_id'] ?? null, $ids, true)) { return false; }
        }
        if ($this->cities) {
            if (($person['site_id'] ?? null) === null) { return false; }
            $marks = implode(',', array_fill(0, count($this->cities), '?'));
            return (bool) $db->one("SELECT id FROM org_units WHERE tenant_id=? AND id=? AND city IN ($marks)", [$tenant, $person['site_id'], ...$this->cities]);
        }
        return true;
    }

    /** Delegacion: ¿este alcance cubre al otro? Cada filtro que este limita, el otro tambien lo limita, a un subconjunto. */
    public function covers(self $child): bool
    {
        if ($this->kind === 'all') { return true; }
        if ($this->kind !== 'filters' || $child->kind !== 'filters' || $this->isEmptyFilter() || $child->isEmptyFilter()) { return false; }
        foreach ([[$this->companies, $child->companies], [$this->sites, $child->sites], [$this->areas, $child->areas]] as [$mine, $theirs]) {
            if ($mine && (!$theirs || array_diff($theirs, $mine))) { return false; }
        }
        if ($this->cities) {
            $mine = array_map([self::class, 'cityKey'], $this->cities); $theirs = array_map([self::class, 'cityKey'], $child->cities);
            if (!$theirs || array_diff($theirs, $mine)) { return false; }
        }
        return true;
    }

    /** Ciudad comparable en PHP como la compara la base (sin mayusculas ni tildes). */
    public static function cityKey(string $city): string
    {
        return strtr(mb_strtolower(preg_replace('/\s+/u', ' ', trim($city))), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
```

- [ ] **Step 4: Write `backend-v4/src/AccessGrants.php`**

```php
<?php
declare(strict_types=1);
namespace Keeper;

/** Acceso al panel = 1 rol + 1 alcance (§4): de una persona en su cliente, o de una cuenta de grupo en otra empresa del grupo. */
final class AccessGrants
{
    private const SELECT = 'SELECT g.*,r.name role_name FROM access_grants g JOIN roles r ON r.tenant_id=g.tenant_id AND r.id=g.role_id';

    public static function forUser(Database $db, string $tenant, string $user): ?array
    {
        return self::withScope($db, $db->one(self::SELECT . ' WHERE g.tenant_id=? AND g.user_id=?', [$tenant, $user]));
    }

    public static function forGroupAccount(Database $db, string $tenant, string $adminTenant, string $admin): ?array
    {
        return self::withScope($db, $db->one(self::SELECT . ' WHERE g.tenant_id=? AND g.admin_tenant_id=? AND g.admin_id=?', [$tenant, $adminTenant, $admin]));
    }

    /** Acceso de la sesion: cuenta de grupo fuera de su empresa casa, o la persona de la cuenta. */
    public static function forContext(Database $db, array $c): ?array
    {
        if (($c['group_id'] ?? null) !== null && $c['tenant_id'] !== $c['admin_tenant_id']) { return self::forGroupAccount($db, $c['tenant_id'], $c['admin_tenant_id'], $c['admin_id']); }
        return ($c['user_id'] ?? null) === null ? null : self::forUser($db, $c['tenant_id'], $c['user_id']);
    }

    private static function withScope(Database $db, ?array $g): ?array
    {
        if ($g === null) { return null; }
        $g['scope'] = AccessScope::load($db, $g['tenant_id'], $g['id'], $g['scope_kind']);
        return $g;
    }

    /** @return list<string> permisos del rol activo, sin los de plataforma */
    public static function permissions(Database $db, string $tenant, string $role): array
    {
        return array_column($db->run('SELECT p.permission_code code FROM roles r JOIN role_permissions p ON p.tenant_id=r.tenant_id AND p.role_id=r.id JOIN permissions m ON m.code=p.permission_code AND m.platform_only=FALSE WHERE r.tenant_id=? AND r.id=? AND r.active=TRUE ORDER BY p.permission_code', [$tenant, $role])->fetchAll(), 'code');
    }

    public static function setForUser(Database $db, string $tenant, string $user, string $role, AccessScope $scope, string $source, ?string $rule = null): void
    {
        $old = $db->one('SELECT id FROM access_grants WHERE tenant_id=? AND user_id=? FOR UPDATE', [$tenant, $user]);
        $id = $old['id'] ?? Util::bin(Util::uuid());
        if ($old) { $db->run('UPDATE access_grants SET role_id=?,scope_kind=?,source=?,rule_id=?,version=version+1 WHERE tenant_id=? AND id=?', [$role, $scope->kind, $source, $rule, $tenant, $id]); }
        else { $db->run('INSERT INTO access_grants (tenant_id,id,user_id,role_id,scope_kind,source,rule_id) VALUES (?,?,?,?,?,?,?)', [$tenant, $id, $user, $role, $scope->kind, $source, $rule]); }
        $scope->save($db, $tenant, $id);
    }

    public static function setForGroupAccount(Database $db, string $tenant, string $adminTenant, string $admin, string $role, AccessScope $scope): void
    {
        if (!in_array($scope->kind, ['all', 'filters'], true)) { throw new ApiError(422, 'validation_failed', [], 'group_scope_invalid'); }
        $db->run('DELETE FROM access_grants WHERE tenant_id=? AND admin_tenant_id=? AND admin_id=?', [$tenant, $adminTenant, $admin]);
        $id = Util::bin(Util::uuid());
        $db->run("INSERT INTO access_grants (tenant_id,id,admin_tenant_id,admin_id,role_id,scope_kind,source) VALUES (?,?,?,?,?,?,'manual')", [$tenant, $id, $adminTenant, $admin, $role, $scope->kind]);
        $scope->save($db, $tenant, $id);
    }

    public static function removeForUser(Database $db, string $tenant, string $user): void
    {
        $db->run('DELETE FROM access_grants WHERE tenant_id=? AND user_id=?', [$tenant, $user]);
    }

    public static function defaultRole(Database $db, string $tenant): ?string
    {
        return $db->one("SELECT id FROM roles WHERE tenant_id=? AND seed_key IN ('colaborador','collaborator') AND active=TRUE ORDER BY seed_key='colaborador' DESC LIMIT 1", [$tenant])['id'] ?? null;
    }

    /** Persona nueva: Colaborador, solo lo propio (§4). Sin rol Colaborador activo, sin acceso. */
    public static function grantDefault(Database $db, string $tenant, string $user): void
    {
        $role = self::defaultRole($db, $tenant);
        if ($role === null) { self::removeForUser($db, $tenant, $user); return; }
        self::setForUser($db, $tenant, $user, $role, AccessScope::own(), 'default');
    }

    /** Access del contrato. */
    public static function dto(?array $g): ?array
    {
        return $g === null ? null : ['role_id' => Util::id($g['role_id']), 'role_name' => $g['role_name'], 'scope' => $g['scope']->dto(), 'source' => $g['source']];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: suite completa.
Expected: `PASS access scope (...)` y `SMOKE PASS`.

- [ ] **Step 6: Commit**

```bash
git add backend-v4/src/AccessScope.php backend-v4/src/AccessGrants.php backend-v4/tests/access.php backend-v4/tests/smoke.php
git commit -m "Accesos: AccessScope (filtros, equipo, propio, cobertura) y AccessGrants

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: El panel lee `access_grants` — `AdminAccess` reescrito y puente temporal para las APIs viejas

**Files:**
- Modify: `backend-v4/src/AdminAccess.php` (reescritura completa)
- Modify: `backend-v4/src/AccessScope.php` (puente `fromLegacy` / `fromLegacyRole`), `backend-v4/src/AccessGrants.php` (puente `assignLegacy`)
- Modify: `backend-v4/src/AdminApi.php`, en estos puntos:
  - `tenants()`: rama de cuenta de grupo en `listTenants`, ~línea 170;
  - `deleteOrgUnit`, ~línea 250;
  - `organization`, guardia de desactivar, ~285-286;
  - `userDto`, ~314;
  - `users()`: `setUserRoles` ~362-371, ubicación ~386-401 y alta ~408-410;
  - `myAccess`, ~454-466;
  - `roles()`, ~510-525;
  - `policyTarget`, ~647.
- Modify: `backend-v4/src/PanelAccounts.php`, `backend-v4/src/RoleRules.php:39-57`, `backend-v4/src/CompanyGroups.php:63`, `backend-v4/src/AdminAuth.php:57`, `backend-v4/src/TenantPurge.php:32`, `backend-v4/src/ExternalApi.php:99`, `backend-v4/src/DataSync.php:475-476`
- Modify: `docs/architecture/openapi-v4.yaml`: `MyAccess.permissions.items.scopes.items.enum` agrega `filters`.
- Modify tests:
  - `backend-v4/tests/admin.php` (`adminFixture`);
  - `backend-v4/tests/role-rules.php:30`;
  - `backend-v4/tests/company-groups.php:50`;
  - `backend-v4/tests/admin-review.php:101-104`;
  - `backend-v4/tests/group-structure.php:138`;
  - `backend-v4/tests/access.php` (fixture `accessOrg`, `accessAccount`, `accessSeen`, pruebas);
  - `backend-v4/tests/smoke.php`.

**Interfaces:**
- Consumes: Task 2.
- Produces (`AdminAccess`):
  - `__construct(Database $db, array $c, string $permission, ?string $operation = null)`, igual que hoy.
  - `scope(): AccessScope`.
  - `full(): bool`: alcance `all`.
  - `requireFull(): void`: si no, 403 `permission_denied` / `scope_denied`.
  - `rolesGate()`, `usersSql(string $alias='u', string $userKey='id'): array`, `team(): array` y `user(string $id): array`, igual que hoy.
  - `allowsPlacement(array $person): bool`.
  - `allowsUnitTarget(string $kind, string $id): bool`.
  - `delegate(array $permissions, AccessScope $scope, ?string $target): void`: 403 `permission_denied` / `delegation_exceeds_own_access`.
  - **Se borran** `hasScopeKind()` y `allowsScope()`.
- Puente temporal (se borra en Task 8):
  - `AccessScope::fromLegacy(string $kind, array $ids): self`.
  - `AccessScope::fromLegacyRole(Database $db, string $tenant, string $role): self`.
  - `AccessGrants::assignLegacy(Database $db, AdminAccess $by, string $tenant, string $user, array $roleIds): void`.
- Pruebas:
  - `accessOrg(Database $db): array`;
  - `accessAccount(Database $db, array $o, string $key, array $permissions, AccessScope $scope): array`;
  - `accessSeen(array $o, array $admin, string $path, string $field = 'id'): list<string>`.

- [ ] **Step 1: Write the failing test** — agregar a `backend-v4/tests/access.php`:

```php
/**
 * Cliente de los casos del §8. Tiene dos empresas (AZC Legal, BilingueLaw), tres sedes en tres ciudades y dos áreas.
 * Las ocho personas tienen equipo y actividad del 2026-09-10.
 * Jerarquía: ag808 reporta a coord2 y jur808 a ag808. También queda «base», la persona de secondTenant() sin empresa.
 */
function accessOrg(Database $db): array
{
    $base = secondTenant($db); $tenant = $base['tenant_id'];
    for ($w = 1; $w <= 7; $w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$tenant, $base['schedule_id'], $w]); }
    $unit = function (string $kind, string $name, ?string $city = null) use ($db, $tenant): string {
        $id = Util::bin(Util::uuid());
        $db->run('INSERT INTO org_units (tenant_id,id,kind,name,city) VALUES (?,?,?,?,?)', [$tenant, $id, $kind, $name, $city]);
        return $id;
    };
    $o = ['tenant' => $tenant, 'base' => $base, 'azc' => $unit('company', 'AZC Legal'), 'bl' => $unit('company', 'BilingueLaw'),
        'teq' => $unit('site', 'Tequendama', 'Cali'), 'med' => $unit('site', 'Medellín 808', 'Medellín'), 'torre' => $unit('site', 'Torre Bogotá', 'Bogotá'),
        'agentes' => $unit('area', 'Agentes de servicio'), 'juridica' => $unit('area', 'Jurídica')];
    $people = ['zafra' => ['azc', 'torre', 'juridica'], 'bl1' => ['bl', 'teq', 'agentes'], 'bl2' => ['bl', 'torre', 'juridica'], 'juan' => ['azc', 'teq', 'agentes'],
        'ag808' => ['azc', 'med', 'agentes'], 'agTorre' => ['azc', 'torre', 'agentes'], 'jur808' => ['azc', 'med', 'juridica'], 'coord2' => ['azc', 'med', 'agentes']];
    foreach ($people as $key => [$company, $site, $area]) {
        $id = Util::bin(Util::uuid()); $assignment = Util::bin(Util::uuid());
        $db->run('INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id,company_id) VALUES (?,?,?,?,?,?,?,?,?)',
            [$tenant, $id, $key, $base['firm_id'], $o[$site], $o[$area], $base['position_id'], $base['schedule_id'], $o[$company]]);
        $db->run("INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,'2026-09-01')",
            [$tenant, $assignment, $id, $base['firm_id'], $o[$site], $o[$area], $base['position_id'], $base['schedule_id'], $o[$company]]);
        $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities,last_seen_at) VALUES (?,?,?,?,'4.0','test','test',1024,'[]',UTC_TIMESTAMP(6))",
            [$tenant, Util::bin(Util::uuid()), $id, 'PC-' . $key]);
        $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through) VALUES (?,'2026-09-10',?,?,120,30,90,240,50,75,'access-test','America/Bogota','2026-09-11','2026-09-10 23:00:00')",
            [$tenant, $id, $assignment]);
        $o[$key] = $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $id]);
    }
    $db->run('UPDATE users SET reports_to_user_id=? WHERE tenant_id=? AND id=?', [$o['coord2']['id'], $tenant, $o['ag808']['id']]);
    $db->run('UPDATE users SET reports_to_user_id=? WHERE tenant_id=? AND id=?', [$o['ag808']['id'], $tenant, $o['jur808']['id']]);
    return $o;
}

/** Cuenta del panel de una persona del cliente de casos, con su rol de prueba y el alcance dado. */
function accessAccount(Database $db, array $o, string $key, array $permissions, AccessScope $scope): array
{
    $a = adminFixture($db, $o[$key], $permissions);
    AccessGrants::setForUser($db, $o['tenant'], $o[$key]['id'], $a['role'], $scope, 'manual');
    return $a;
}

/** Claves de accessOrg (ordenadas) de las personas que devuelve un listado del panel. */
function accessSeen(array $o, array $admin, string $path, string $field = 'id'): array
{
    $byId = [];
    foreach ($o as $key => $v) { if (is_array($v) && isset($v['display_name'], $v['id'])) { $byId[Util::id($v['id'])] = $key; } }
    $seen = array_map(fn ($r) => $byId[$r[$field]] ?? 'otro', expect(adminRequest($admin, 'GET', $path), 200, "list $path")['data']);
    sort($seen); return $seen;
}

function accessEvaluationTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $tid = Util::id($o['tenant']);
    $perms = ['usuarios.ver', 'equipos.ver', 'reglas.ver']; // reglas.ver: el 403 de /policies debe ser por alcance, no por permiso
    $users = '/users?limit=100'; $devices = '/tenants/' . $tid . '/devices?limit=100';
    $all = accessAccount($db, $o, 'zafra', $perms, AccessScope::all());
    $area = accessAccount($db, $o, 'juan', $perms, new AccessScope('filters', [], [], [], [$o['agentes']]));
    $city = accessAccount($db, $o, 'jur808', $perms, new AccessScope('filters', [], ['Medellín']));
    $team = accessAccount($db, $o, 'coord2', $perms, new AccessScope('team'));
    $own = accessAccount($db, $o, 'agTorre', $perms, AccessScope::own());
    $mix = accessAccount($db, $o, 'bl1', $perms, new AccessScope('filters', [$o['bl'], $o['azc']], [], [$o['teq']]));
    $empty = accessAccount($db, $o, 'bl2', $perms, new AccessScope('filters'));
    check(accessSeen($o, $all, $users) === ['ag808', 'agTorre', 'base', 'bl1', 'bl2', 'coord2', 'juan', 'jur808', 'zafra'], 'all sees the whole client');
    check(accessSeen($o, $area, $users) === ['ag808', 'agTorre', 'bl1', 'coord2', 'juan'], 'area filter, every site and company');
    check(accessSeen($o, $area, $devices, 'user_id') === ['ag808', 'agTorre', 'bl1', 'coord2', 'juan'], 'devices follow the same scope');
    check(accessSeen($o, $city, $users) === ['ag808', 'coord2', 'jur808'], 'city filter through the site');
    check(accessSeen($o, $team, $users) === ['ag808', 'coord2', 'jur808'], 'team = self + direct + indirect reports');
    check(accessSeen($o, $own, $users) === ['agTorre'], 'self sees only self');
    check(accessSeen($o, $mix, $users) === ['bl1', 'juan'], 'OR inside company, AND with site');
    check(accessSeen($o, $empty, $users) === [], 'filters without values see nothing');
    check(adminRequest($area, 'GET', '/users/' . Util::id($o['jur808']['id']))[0] === 404, 'outside detail stays 404');
    $full = adminRequest($area, 'GET', '/policies');
    check($full[0] === 403 && $full[1]['detail'] === 'scope_denied', 'whole-client screens need scope all');
    $mine = expect(adminRequest($area, 'GET', '/me/access'), 200, 'my access');
    check(array_column($mine['permissions'], 'scopes', 'code') === ['equipos.ver' => ['filters'], 'reglas.ver' => ['filters'], 'usuarios.ver' => ['filters']], 'my access reports filters');
    echo 'PASS access evaluation (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `tests/smoke.php` agregar `accessEvaluationTests($db);` después de `accessScopeTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL en `PASS access evaluation`. `AdminAccess` todavía lee `user_roles`: la cuenta creada por `adminFixture` ve según su rol viejo `tenant`, así que `area filter...` falla.

- [ ] **Step 3: Rewrite `backend-v4/src/AdminAccess.php`**

```php
<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Autorizacion de una peticion del panel (docs/architecture/v4-clientes-funciones-accesos.md §4 y §7). La persona (o la
 * cuenta de grupo en otra empresa) tiene un solo acceso: 1 rol (permisos) + 1 alcance (datos). Superadmin: todo.
 * 403 permission_denied con detalle feature_not_contracted:<f>, scope_denied o delegation_exceeds_own_access.
 */
final class AdminAccess
{
    private ?array $grant = null;
    private AccessScope $scope;
    /** @var list<string> */
    private array $permissions = [];

    public function __construct(private Database $db, public readonly array $c, public readonly string $permission, ?string $operation = null)
    {
        if ($c['platform']) {
            if (!$db->one('SELECT code FROM permissions WHERE code=?', [$permission])) { throw new ApiError(403, 'permission_denied'); }
            $this->scope = AccessScope::all();
            return;
        }
        $this->grant = AccessGrants::forContext($db, $c);
        $this->permissions = $this->grant === null ? [] : AccessGrants::permissions($db, $c['tenant_id'], $this->grant['role_id']);
        if (!in_array($permission, $this->permissions, true)) { throw new ApiError(403, 'permission_denied'); }
        $this->scope = $this->grant['scope'];
        // Funcion no contratada por el cliente: aunque el rol tenga el permiso (superadmin nunca queda bloqueado).
        $feature = Features::forOperation($operation ?? '', $permission);
        if ($feature !== null && !Features::has($db, $c['tenant_id'], $feature)) { throw new ApiError(403, 'permission_denied', [], "feature_not_contracted:$feature"); }
    }

    public function scope(): AccessScope { return $this->scope; }
    public function full(): bool { return $this->scope->kind === 'all'; }
    public function requireFull(): void { if (!$this->full()) { throw new ApiError(403, 'permission_denied', [], 'scope_denied'); } }
    public function rolesGate(): void
    {
        if (!$this->c['platform'] && !$this->c['gate']) { throw new ApiError(403, 'tenant_rbac_disabled'); }
    }

    /** Condicion SQL de las personas del alcance sobre $alias (users, o user_assignments con $userKey='user_id'). */
    public function usersSql(string $alias = 'u', string $userKey = 'id'): array
    {
        return match ($this->scope->kind) {
            'all' => ['1=1', []],
            'self' => ($this->c['user_id'] ?? null) === null ? ['1=0', []] : ["$alias.$userKey=?", [$this->c['user_id']]],
            'team' => ($team = $this->team()) ? ["$alias.$userKey IN (" . implode(',', array_fill(0, count($team), '?')) . ')', $team] : ['1=0', []],
            'filters' => $this->scope->filterSql($alias),
        };
    }

    private ?array $teamIds = null;
    /** La persona de la cuenta y quienes le reportan, directa o indirectamente (alcance "team", §4). */
    public function team(): array
    {
        if ($this->teamIds !== null) { return $this->teamIds; }
        if (($this->c['user_id'] ?? null) === null) { return $this->teamIds = []; }
        $seen = [bin2hex($this->c['user_id']) => $this->c['user_id']]; $level = [$this->c['user_id']];
        for ($depth = 0; $level && $depth < 20; $depth++) {
            $next = [];
            foreach ($this->db->run('SELECT id FROM users WHERE tenant_id=? AND reports_to_user_id IN (' . implode(',', array_fill(0, count($level), '?')) . ')', [$this->c['tenant_id'], ...$level])->fetchAll() as $row) {
                if (!isset($seen[bin2hex($row['id'])])) { $seen[bin2hex($row['id'])] = $row['id']; $next[] = $row['id']; }
            }
            $level = $next;
        }
        return $this->teamIds = array_values($seen);
    }

    /** Persona dentro del alcance; fuera, 404 (no se revela que existe). */
    public function user(string $id): array
    {
        [$where, $args] = $this->usersSql();
        $u = $this->db->one("SELECT u.* FROM users u WHERE u.tenant_id=? AND u.id=? AND $where", [$this->c['tenant_id'], $id, ...$args]);
        if (!$u) { throw new ApiError(404, 'resource_not_found'); }
        return $u;
    }

    /** Crear o mover una persona: su nueva ubicacion debe caer dentro del alcance (equipo o propio no crean ni mueven). */
    public function allowsPlacement(array $person): bool
    {
        return $this->scope->matches($this->db, $this->c['tenant_id'], $person);
    }

    /** Objetivo de una regla (sede, area o empresa): afecta a todos los de esa unidad, asi que el alcance debe limitarse a ella. */
    public function allowsUnitTarget(string $kind, string $id): bool
    {
        if ($this->full()) { return true; }
        if ($this->scope->kind !== 'filters' || !in_array($id, $this->scope->list($kind), true)) { return false; }
        foreach (['company', 'city', 'site', 'area'] as $other) { if ($other !== $kind && $this->scope->list($other)) { return false; } }
        return true;
    }

    /** Delegacion segura (§4): nadie da permisos que no tiene ni un alcance mas amplio que el propio. */
    public function delegate(array $permissions, AccessScope $scope, ?string $target): void
    {
        $this->rolesGate();
        foreach ($permissions as $code) {
            $p = $this->db->one('SELECT * FROM permissions WHERE code=?', [$code]);
            if (!$p || $p['platform_only']) { throw new ApiError(403, 'permission_denied'); }
            if (!$this->c['platform'] && ($code === 'roles.gestionar' || !$p['delegable'] || !in_array($code, $this->permissions, true))) {
                throw new ApiError(403, 'permission_denied', [], 'delegation_exceeds_own_access');
            }
        }
        if (!$this->c['platform'] && !$this->covers($scope, $target)) { throw new ApiError(403, 'permission_denied', [], 'delegation_exceeds_own_access'); }
    }

    private function covers(AccessScope $child, ?string $target): bool
    {
        if ($this->full()) { return true; }
        return match ($child->kind) {
            'all' => false,
            'self' => $target !== null && $this->sees($target),
            'team' => $target !== null && $this->scope->kind === 'team' && in_array($target, $this->team(), true),
            'filters' => $this->scope->covers($child),
        };
    }

    private function sees(string $user): bool
    {
        [$where, $args] = $this->usersSql();
        return (bool) $this->db->one("SELECT u.id FROM users u WHERE u.tenant_id=? AND u.id=? AND $where", [$this->c['tenant_id'], $user, ...$args]);
    }
}
```

- [ ] **Step 4: Puente temporal (se borra en Task 8)**

Agregar a `AccessScope.php`, antes de `cityKey`:

```php
    /** PUENTE Tareas 3-7 (se borra en la Tarea 8): alcance del modelo viejo (rol con tenant/site/area/team/self). */
    public static function fromLegacy(string $kind, array $ids): self
    {
        return match ($kind) {
            'tenant' => self::all(), 'team' => new self('team'), 'site' => new self('filters', [], [], $ids), 'area' => new self('filters', [], [], [], $ids), default => self::own(),
        };
    }

    /** PUENTE Tareas 3-7: el alcance que tenia un rol en el modelo viejo. */
    public static function fromLegacyRole(Database $db, string $tenant, string $role): self
    {
        $kind = $db->one('SELECT scope_kind FROM roles WHERE tenant_id=? AND id=?', [$tenant, $role])['scope_kind'] ?? 'self';
        $ids = in_array($kind, ['site', 'area'], true) ? array_column($db->run("SELECT {$kind}_id id FROM role_{$kind}_scopes WHERE tenant_id=? AND role_id=? ORDER BY {$kind}_id", [$tenant, $role])->fetchAll(), 'id') : [];
        return self::fromLegacy($kind, $ids);
    }
```

Agregar a `AccessGrants.php`:

```php
    /**
     * PUENTE de las Tareas 3 a 7 (se borra en la Tarea 8). Atiende los role_ids viejos (0 o 1) de setUserRoles y de las cuentas.
     * Copia el alcance viejo del rol a la persona. [] quita el acceso manual (vuelve al por defecto); lo que puso una regla se queda.
     */
    public static function assignLegacy(Database $db, AdminAccess $by, string $tenant, string $user, array $roleIds): void
    {
        $roleIds = array_values(array_unique($roleIds));
        if (count($roleIds) > 1) { throw new ApiError(422, 'validation_failed', [], 'one_role_only'); }
        $current = self::forUser($db, $tenant, $user);
        if ($current !== null && (!$roleIds && $current['source'] !== 'manual')) { return; }
        if ($current !== null) { $by->delegate(self::permissions($db, $tenant, $current['role_id']), $current['scope'], $user); }
        if (!$roleIds) { self::grantDefault($db, $tenant, $user); return; }
        $role = $db->one('SELECT * FROM roles WHERE tenant_id=? AND id=?', [$tenant, Util::bin($roleIds[0])]);
        if (!$role) { throw new ApiError(422, 'validation_failed', [], 'role_not_found'); }
        if (!$role['active']) { throw new ApiError(409, 'invalid_transition'); }
        $scope = AccessScope::fromLegacyRole($db, $tenant, $role['id']);
        $by->delegate(self::permissions($db, $tenant, $role['id']), $scope, $user);
        self::setForUser($db, $tenant, $user, $role['id'], $scope, 'manual');
    }
```

- [ ] **Step 5: Llamadores en `backend-v4/src/AdminApi.php`**

1. `tenants()`, rama de cuenta de grupo (línea ~170): reemplazar la subconsulta sobre `admin_account_roles` por

```php
                $ids=array_values(array_unique([$this->c['admin_tenant_id'],...array_column($this->db->run('SELECT DISTINCT g.tenant_id FROM access_grants g JOIN company_group_members m ON m.tenant_id=g.tenant_id AND m.group_id=? WHERE g.admin_tenant_id=? AND g.admin_id=?',[$this->c['group_id'],$this->c['admin_tenant_id'],$this->c['admin_id']])->fetchAll(),'tenant_id')]));
```

2. `deleteOrgUnit`, entrada `'roles'` de `$uses` (línea ~250): reemplazar por

```php
            'accesos'=>['SELECT COUNT(*) n FROM access_grant_units WHERE tenant_id=? AND unit_id=?',[$this->tenant,$id]],
```

3. `organization()`, guardia de desactivar (líneas ~285-286): reemplazar las dos líneas de `role_area_scopes`/`role_site_scopes` por

```php
                || $this->db->one('SELECT grant_id FROM access_grant_units WHERE tenant_id=? AND unit_id=? LIMIT 1',[$this->tenant,$id])
```

4. `userDto` (línea ~314): reemplazar la consulta de `$roles` y el valor `role_ids` por el acceso único (el contrato todavía dice `role_ids`; cambia en Task 5):

```php
        $grant=AccessGrants::forUser($this->db,$this->tenant,$r['id']);
```

y en `$out`: `'role_ids'=>$grant===null?[]:[Util::id($grant['role_id'])],`.

5. `users()`, rama `setUserRoles` (líneas ~362-371): reemplazar el cuerpo por

```php
        if ($op==='setUserRoles') {
            $this->unique($b->role_ids);
            AccessGrants::assignLegacy($this->db,$this->access,$this->tenant,$id,$b->role_ids);
            $this->db->run('UPDATE users SET version=version+1 WHERE tenant_id=? AND id=?',[$this->tenant,$id]); $this->invalidateUser($id);
        } else {
```

6. `users()`, ubicación (bloque `if (!$this->access->full()) { $allowed=false; foreach (['area','site'] ...) ... }`, líneas ~386-401): reemplazar desde `if (!$this->access->full()) {` hasta antes de `$p=$next['area_id']??null;` por

```php
            if (!$this->access->full()) {
                // Crear o mover una persona: su ubicacion debe quedar dentro del alcance (§4). Equipo o propio no crean ni mueven.
                if (!$this->access->allowsPlacement($next)) { throw new ApiError(403,'permission_denied',[],'scope_denied'); }
```

El resto del bloque (cadena de ancestros del área) queda igual.

7. `users()`, alta (líneas ~408-410): reemplazar las tres líneas `$role=...; if (!$role) {...}; if ($role) {...}` por

```php
                AccessGrants::grantDefault($this->db,$this->tenant,$id);
```

8. `myAccess()`: reemplazar desde `if (($this->c['group_id']??null)!==null ...` hasta el `return` final por

```php
        $grant=AccessGrants::forContext($this->db,$this->c);
        $codes=$grant===null?[]:AccessGrants::permissions($this->db,$this->tenant,$grant['role_id']);
        $kind=$grant===null?null:($grant['scope']->kind==='all'?'tenant':$grant['scope']->kind);
        $features=Features::enabled($this->db,$this->tenant);
        $codes=array_values(array_filter($codes,function ($code) use ($features) { $f=Features::forOperation('',$code); return $f===null || in_array($f,$features,true); }));
        return self::response(['platform'=>false,'features'=>$features,'permissions'=>array_map(fn($code)=>['code'=>$code,'scopes'=>[$kind]],$codes)]);
```

9. `roles()` (líneas ~512-524):
   - la línea `if ($id) { ... allowsScope ... delegate(...) }` pasa a

```php
        if ($id) { $old=$this->row('roles',$id); $dto=$this->roleDto($old); if ($op==='getRole') { return $this->result($dto); } $this->match($old); $this->access->delegate($dto['permissions'],AccessScope::fromLegacy($dto['scope']['kind'],array_map([Util::class,'bin'],$dto['scope']['resource_ids'])),null); }
```

   - `$this->access->delegate($b->permissions,$b->scope);` pasa a

```php
        $this->access->delegate($b->permissions,AccessScope::fromLegacy($kind,array_map([Util::class,'bin'],$b->scope->resource_ids)),null);
```

   - la invalidación `foreach ($this->db->run('SELECT user_id FROM user_roles ...` pasa a

```php
        foreach ($this->db->run('SELECT user_id FROM access_grants WHERE tenant_id=? AND role_id=? AND user_id IS NOT NULL',[$this->tenant,$id])->fetchAll() as $user) { $this->invalidateUser($user['user_id']); }
```

10. `policyTarget` (línea ~647): la rama final pasa a

```php
        else { $this->org($target,$kind); if (!$this->access->allowsUnitTarget($kind,$target)) { throw new ApiError(403,'permission_denied',[],'scope_denied'); } }
```

- [ ] **Step 6: Demás archivos**

1. `AdminAuth::groupTenants`, consulta (línea 57):

```php
        return array_column($this->db->run('SELECT DISTINCT g.tenant_id FROM access_grants g JOIN company_group_members m ON m.tenant_id=g.tenant_id AND m.group_id=? JOIN tenants t ON t.tenant_id=g.tenant_id AND t.status=\'active\' WHERE g.admin_tenant_id=? AND g.admin_id=? ORDER BY g.tenant_id', [$a['group_id'], $a['tenant_id'], $adminId])->fetchAll(), 'tenant_id');
```

2. `CompanyGroups::save`, línea 63 (sacar una empresa del grupo):

```php
                $this->db->run('DELETE g FROM access_grants g JOIN admin_accounts a ON a.tenant_id=g.admin_tenant_id AND a.id=g.admin_id WHERE a.group_id=? AND g.tenant_id=?', [$id, $gone]);
```

3. `TenantPurge::purge`, línea 32: reemplazar `DELETE FROM admin_account_roles ...` por

```php
        $this->db->run('DELETE FROM access_grants WHERE admin_tenant_id=? OR tenant_id=?', [$tenant, $tenant]);
```

4. `ExternalApi::members`, línea 99:

```php
        $roles=$this->db->run('SELECT g.user_id,r.name FROM access_grants g JOIN roles r ON r.tenant_id=g.tenant_id AND r.id=g.role_id WHERE g.tenant_id=? AND g.user_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY r.name,r.id',[$this->tenant,...$ids])->fetchAll();
```

5. `DataSync::createPerson`, líneas 475-476: reemplazar las dos líneas de `$role` por `AccessGrants::grantDefault($this->db, $this->tenant, $id);`.

6. `RoleRules::apply`, líneas 39-57: reemplazar el cuerpo después de armar `$desired` por la versión sobre accesos. Lo manual manda; la regla copia por ahora el alcance viejo del rol (puente; la Task 7 lo cambia).

```php
    public static function apply(Database $db, string $tenant): array
    {
        $desired = [];
        foreach ($db->run('SELECT p.* FROM panel_role_rules p JOIN roles r ON r.tenant_id=p.tenant_id AND r.id=p.role_id AND r.active=TRUE AND r.assignable_by_rule=TRUE WHERE p.tenant_id=? AND p.active=TRUE ORDER BY p.created_at', [$tenant])->fetchAll() as $rule) {
            foreach (self::matching($db, $tenant, $rule) as $user) { $desired[bin2hex($user)] ??= [$user, $rule['role_id'], $rule['id']]; }
        }
        $added = 0; $removed = 0;
        foreach ($db->run("SELECT user_id FROM access_grants WHERE tenant_id=? AND source='rule' AND user_id IS NOT NULL", [$tenant])->fetchAll() as $row) {
            if (!isset($desired[bin2hex($row['user_id'])])) { AccessGrants::grantDefault($db, $tenant, $row['user_id']); $removed++; }
        }
        foreach ($desired as [$user, $role, $rule]) {
            $g = AccessGrants::forUser($db, $tenant, $user);
            if ($g !== null && ($g['source'] === 'manual' || ($g['role_id'] === $role && $g['rule_id'] === $rule))) { continue; }
            AccessGrants::setForUser($db, $tenant, $user, $role, AccessScope::fromLegacyRole($db, $tenant, $role), 'rule', $rule);
            $added++;
        }
        return [$added, $removed];
    }
```

7. `PanelAccounts`:
   - borrar `validateRoles` y `setRoles`;
   - agregar `private function delegator(): AdminAccess { return $this->c['platform'] ? $this->access : new AdminAccess($this->db, $this->c, 'roles.gestionar'); }`.
   - En `create()`, rama de empresa, reemplazar `$roles = $this->validateRoles($b->role_ids);` (se borra) y `$this->setRoles($user['id'], $roles);` por

```php
            if ($b->role_ids) { AccessGrants::assignLegacy($this->db, $this->delegator(), $this->tenant, $user['id'], $b->role_ids); }
            elseif (AccessGrants::forUser($this->db, $this->tenant, $user['id']) === null) { AccessGrants::grantDefault($this->db, $this->tenant, $user['id']); }
```

   - En `patch()`, reemplazar `$this->setRoles($a['user_id'], $this->validateRoles($b->role_ids));` por `AccessGrants::assignLegacy($this->db, $this->delegator(), $this->tenant, $a['user_id'], $b->role_ids);`.
   - En `setGroup()`:
     - reemplazar `DELETE FROM admin_account_roles ...` por `$this->db->run('DELETE FROM access_grants WHERE admin_tenant_id=? AND admin_id=?', [$this->tenant, $account]);`;
     - dentro del bucle de `$grants`, reemplazar el `foreach (array_unique($g->role_ids) ...)` por

```php
            $ids = array_values(array_unique($g->role_ids));
            if (count($ids) > 1) { throw new ApiError(422, 'validation_failed', [], 'one_role_only'); }
            foreach ($ids as $rid) {
                if (!$this->db->one('SELECT id FROM roles WHERE tenant_id=? AND id=? AND active=TRUE', [$t, Util::bin($rid)])) { throw new ApiError(422, 'validation_failed', [], 'role_not_found'); }
                AccessGrants::setForGroupAccount($this->db, $t, $this->tenant, $account, Util::bin($rid), AccessScope::fromLegacyRole($this->db, $t, Util::bin($rid)));
            }
```

   - `groupDto`: la consulta pasa a `SELECT g.tenant_id,t.name,g.role_id FROM access_grants g JOIN tenants t ON t.tenant_id=g.tenant_id WHERE g.admin_tenant_id=? AND g.admin_id=? ORDER BY t.name,g.role_id`, con `$r['role_id']`.
   - `dto`: `$g = $a['is_platform_admin'] ? null : AccessGrants::forUser($this->db, $a['tenant_id'], $a['user_id']); $roles = $g === null ? [] : [Util::id($g['role_id'])];`.

8. `docs/architecture/openapi-v4.yaml`, esquema `MyAccess`: en `scopes.items.enum` agregar `- filters` después de `- team`. Luego exportar el contrato.

- [ ] **Step 7: Pruebas existentes**

1. `tests/admin.php`, `adminFixture`: después de `foreach ($scopeIds ...) {...}` y de `INSERT INTO user_roles`, agregar el acceso. Se conservan las filas viejas del rol porque el puente las copia.

```php
        $kind=['tenant'=>'all','site'=>'filters','area'=>'filters'][$scope]??$scope;
        Keeper\AccessGrants::setForUser($db,$tenant,$u['id'],$role,new Keeper\AccessScope($kind,[],[],$scope==='site'?$scopeIds:[],$scope==='area'?$scopeIds:[]),'manual');
```

2. `tests/role-rules.php:30`: `$byRule = fn (string $user) => (bool) $db->one("SELECT 1 FROM access_grants WHERE tenant_id=? AND user_id=? AND role_id=? AND source='rule'", [$tenant, Util::bin($user), Util::bin($jefe['id'])]);`
3. `tests/company-groups.php:50`: `check(!$db->one('SELECT 1 FROM access_grants WHERE tenant_id=? AND admin_id IS NOT NULL', [$tb]), 'group access there removed');`
4. `tests/admin-review.php:101-104`: el rol de referencia pasa a un acceso con la unidad.

```php
        $grantId = Util::bin(Util::uuid());
        $db->run("INSERT INTO access_grants (tenant_id,id,user_id,role_id,scope_kind) SELECT ?,?,?,id,'filters' FROM roles WHERE tenant_id=? LIMIT 1", [$tenant, $grantId, adminMember($db, $base)['id'], $tenant]);
        $db->run('INSERT INTO access_grant_units (tenant_id,grant_id,unit_id,unit_kind) VALUES (?,?,?,?)', [$tenant, $grantId, Util::bin($unit['id']), $kind]);
        expect(adminRequest($admin, 'PATCH', '/organization/' . $unit['id'], array_replace($unitBody, ['active' => false]), ['if-match' => '"1"']), 409, $kind . ' access scope prevents deactivation');
        $db->run('DELETE FROM access_grants WHERE tenant_id=? AND id=?', [$tenant, $grantId]);
```

   Esto reemplaza las líneas `$roleId=...` hasta `DELETE FROM role_{$kind}_scopes ...`.
5. `tests/group-structure.php:138`: `check($codes['festivos.ver'] === ['filters'], 'scope reported (festivos needs whole company, so the menu hides it)');`

- [ ] **Step 8: Run test to verify it passes**

Run: `python tests/export-contract.py` (desde `backend-v4`), luego la suite completa.
Expected: `PASS access evaluation`, `PASS admin`, `PASS role rules`, `PASS company groups`, `PASS panel accounts` y `SMOKE PASS`.

Si una aserción vieja esperaba `404` en un objetivo de regla fuera de alcance, el código nuevo devuelve `403 scope_denied`. Buscarla con `grep -n "target_type" backend-v4/tests/*.php` y cambiar solo esa expectativa.

Verificar también: `grep -n "hasScopeKind\|allowsScope\|user_roles\|admin_account_roles" backend-v4/src/*.php` solo debe mostrar el puente `fromLegacyRole` (`role_{$kind}_scopes`) y nada de `user_roles`/`admin_account_roles`.

- [ ] **Step 9: Commit**

```bash
git add backend-v4/src backend-v4/tests docs/architecture/openapi-v4.yaml backend-v4/config/contract.json
git commit -m "Accesos: AdminAccess sobre un solo acceso por persona (filtros, equipo, propio) con puente para las APIs viejas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `scope_denied` en filtros y casos del §8 sobre datos reales

**Files:**
- Modify: `backend-v4/src/AdminAccess.php` (`requireFilter`)
- Modify: `backend-v4/src/AdminApi.php` (`filterUsers` ~327-341, `report` ~773-782)
- Modify: `backend-v4/src/ActivityReports.php:19-38` (`filter`)
- Modify: `backend-v4/tests/access.php`, `backend-v4/tests/smoke.php`

**Interfaces:**
- Consumes: Task 3 (`accessOrg`, `accessAccount`, `accessSeen`).
- Produces: `AdminAccess::requireFilter(string $kind, string $value): void`, con `$kind` ∈ `company|city|site|area` y el valor binario (o texto para ciudad). Lanza 403 `permission_denied` / `scope_denied`.

- [ ] **Step 1: Write the failing test** — agregar a `backend-v4/tests/access.php`:

```php
// Casos del §8 sobre listados reales: personas, equipos, reporte de personas, cobertura, actividad, reglas y API externa.
function accessCaseTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $tid = Util::id($o['tenant']);
    $perms = ['usuarios.ver', 'equipos.ver', 'reportes.ver', 'cobertura.ver', 'reglas.ver', 'reglas.editar'];
    $zafra = accessAccount($db, $o, 'zafra', $perms, AccessScope::all());
    $bl = accessAccount($db, $o, 'bl2', $perms, new AccessScope('filters', [$o['bl']]));
    $juan = accessAccount($db, $o, 'juan', $perms, new AccessScope('filters', [], [], [], [$o['agentes']]));
    $coord2 = accessAccount($db, $o, 'coord2', $perms, new AccessScope('filters', [], [], [$o['teq'], $o['med']], [$o['agentes']]));
    $med = accessAccount($db, $o, 'jur808', $perms, new AccessScope('filters', [], ['Medellín']));
    $cases = [
        [$zafra, ['ag808', 'agTorre', 'base', 'bl1', 'bl2', 'coord2', 'juan', 'jur808', 'zafra'], 'Zafra ve todo'],
        [$bl, ['bl1', 'bl2'], 'la gerente de BilingueLaw solo ve BilingueLaw'],
        [$juan, ['ag808', 'agTorre', 'bl1', 'coord2', 'juan'], 'Juan solo ve Agentes de servicio, en todas las sedes'],
        [$coord2, ['ag808', 'bl1', 'coord2', 'juan'], 'el coordinador de dos sedes solo ve esas dos'],
    ];
    $day = '?from=2026-09-10&to=2026-09-11';
    foreach ($cases as [$a, $want, $label]) {
        $active = array_values(array_diff($want, ['base'])); // base no tiene equipo ni actividad en el rango
        check(accessSeen($o, $a, '/users?limit=100') === $want, "$label: personas");
        check(accessSeen($o, $a, '/tenants/' . $tid . '/devices?limit=100', 'user_id') === $active, "$label: equipos");
        check(accessSeen($o, $a, '/reports/people' . $day, 'user_id') === $active, "$label: reporte de personas");
        check(accessSeen($o, $a, '/devices/install-coverage?limit=100', 'user_id') === $want, "$label: cobertura");
    }
    // Detalle fuera del alcance: 404; dentro: 200.
    check(adminRequest($juan, 'GET', '/users/' . Util::id($o['jur808']['id']) . '/activity' . $day)[0] === 404, 'actividad fuera del alcance: 404');
    check(adminRequest($juan, 'GET', '/users/' . Util::id($o['ag808']['id']) . '/activity' . $day)[0] === 200, 'actividad dentro del alcance');
    // Filtro pedido fuera del alcance: scope_denied (§7). Dentro, o en un filtro «todas», acota.
    $denied = fn (array $r) => $r[0] === 403 && ($r[1]['detail'] ?? null) === 'scope_denied';
    check($denied(adminRequest($juan, 'GET', '/users?area_id=' . Util::id($o['juridica']))), 'Juan no filtra por otra área');
    check(accessSeen($o, $juan, '/users?site_id=' . Util::id($o['torre'])) === ['agTorre'], 'filtrar dentro del alcance acota');
    check($denied(adminRequest($bl, 'GET', '/users?company_id=' . Util::id($o['azc']))), 'BilingueLaw no filtra por AZC');
    check($denied(adminRequest($juan, 'GET', '/reports/people' . $day . '&area_id=' . Util::id($o['juridica']))), 'reportes: área ajena');
    check($denied(adminRequest($juan, 'GET', '/reports/productivity?from=2026-09-01&to=2026-10-01&area_id=' . Util::id($o['juridica']))), 'productividad: área ajena');
    check(accessSeen($o, $med, '/users?limit=100') === ['ag808', 'coord2', 'jur808'], 'alcance por ciudad');
    check($denied(adminRequest($med, 'GET', '/users?city=Cali')), 'ciudad ajena');
    check(accessSeen($o, $med, '/users?city=MEDELLIN') === ['ag808', 'coord2', 'jur808'], 'ciudad propia sin mayúsculas ni tildes');
    // Reglas: el objetivo debe estar dentro del alcance.
    $rule = fn (string $type, string $target) => ['name' => 'Regla ' . $type, 'target_type' => $type, 'target_id' => Util::id($target), 'enabled' => true, 'rules' => []];
    check(adminRequest($juan, 'POST', '/policies', $rule('area', $o['agentes']))[0] === 201, 'Juan pone reglas a su área');
    check($denied(adminRequest($juan, 'POST', '/policies', $rule('site', $o['teq']))), 'Juan no pone reglas a una sede entera');
    check($denied(adminRequest($juan, 'GET', '/policies')), 'pantallas de todo el cliente: scope_denied');
    // API externa: la credencial es de todo el cliente (no hay alcance de cuenta).
    $key = externalCredential($db, $o['base'], 'api_key', ['team:read']);
    check(count(expect(externalRequest('/ext/v1/my-team' . $day . '&limit=100', $key['headers']), 200, 'external team')['data']) === 9, 'la API externa ve todo el cliente');
    echo 'PASS access cases (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `tests/smoke.php` agregar `accessCaseTests($db);` después de `accessEvaluationTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL en `Juan no filtra por otra área`. Hoy devuelve 200 con lista vacía.

- [ ] **Step 3: Implementation**

En `AdminAccess.php`, después de `allowsUnitTarget`:

```php
    /** Filtro pedido en un listado (empresa, ciudad, sede o area): fuera del alcance, 403 scope_denied (§7). En «todas», acota. */
    public function requireFilter(string $kind, string $value): void
    {
        if ($this->scope->kind !== 'filters' || !($list = $this->scope->list($kind))) { return; }
        $inside = $kind === 'city'
            ? in_array(AccessScope::cityKey($value), array_map([AccessScope::class, 'cityKey'], $list), true)
            : in_array($value, $list, true);
        if (!$inside) { throw new ApiError(403, 'permission_denied', [], 'scope_denied'); }
    }
```

En `AdminApi::filterUsers`:
- en la línea de `company_id`, después de `$this->org(...)`, llamar `$this->access->requireFilter('company',Util::bin($_GET['company_id']));`;
- en la de `city`, al inicio, `$this->access->requireFilter('city',trim($_GET['city']));`;
- en el bucle, después de `$this->org(Util::bin($_GET[$f]),substr($f,0,-3));`, agregar `$this->access->requireFilter(substr($f,0,-3),Util::bin($_GET[$f]));`.

En `AdminApi::report`:
- en el bucle, en la rama `else { $this->org($id,substr($f,0,-3)); }`, agregar `$this->access->requireFilter(substr($f,0,-3),$id);`;
- en `company_id`, después de `$this->org($id,'company');`, agregar `$this->access->requireFilter('company',$id);`;
- en la rama `city` (línea ~783), al inicio del bloque `if (isset($_GET['city'])) {`, agregar `$this->access->requireFilter('city',trim($_GET['city']));`.

En `ActivityReports::filter`:
- en `elseif (!$this->db->one(... kind=? ...)) {404}`, agregar a continuación `if ($kind!=='user') { $this->access->requireFilter($kind,$id); }`;
- después de validar `company_id`, `$this->access->requireFilter('company',$id);`;
- antes de la condición de `city`, `$this->access->requireFilter('city',trim($_GET['city']));`.

- [ ] **Step 4: Run test to verify it passes**

Run: suite completa.
Expected: `PASS access cases (...)` y `SMOKE PASS`.

- [ ] **Step 5: Commit**

```bash
git add backend-v4/src/AdminAccess.php backend-v4/src/AdminApi.php backend-v4/src/ActivityReports.php backend-v4/tests/access.php backend-v4/tests/smoke.php
git commit -m "Accesos: scope_denied en filtros fuera del alcance y casos del diseño sobre personas, equipos, reportes y reglas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `PUT /users/{id}/access` con delegación segura (reemplaza `setUserRoles`)

**Files:**
- Modify: `docs/architecture/openapi-v4.yaml`:
  - path `/users/{id}/roles` → `/users/{id}/access`;
  - esquemas nuevos `AccessScope`, `AccessInput`, `Access`;
  - `User.role_ids` → `User.access`;
  - se borra `RoleBindings`.
- Modify: `backend-v4/src/AccessGrants.php` (`assign`), `backend-v4/src/AdminApi.php` (ruteo, `userAccess`, `userDto`, `users()` sin `setUserRoles`)
- Modify: `backend-v4/public/assets/k3.js` (`AUDIT_ACTIONS`: `setUserRoles` → `setUserAccess`)
- Modify: `backend-v4/tests/admin.php:95-98`, `backend-v4/tests/access.php`, `backend-v4/tests/smoke.php`

**Interfaces:**
- Consumes: Task 3 (`AdminAccess::delegate`), Task 2.
- Produces:
  - `AccessGrants::assign(Database $db, AdminAccess $by, string $tenant, string $user, object $input): void`.
    - `$input` es el `AccessInput` `{role_id, scope}`.
    - Errores: 422 `role_not_found`, 409 `role_inactive`, 422 de forma (`AccessScope::fromInput`) y 403 `delegation_exceeds_own_access`.
    - Conserva el origen `rule` si el rol no cambia.
  - Operación `setUserAccess`: `PUT /users/{id}/access`, `If-Match` = versión de la persona, respuesta `User`, `x-permission: roles.gestionar`.
  - `User.access`: `Access | null`.

- [ ] **Step 1: Write the failing test** — agregar a `backend-v4/tests/access.php`:

```php
// Delegacion segura (§4): nadie da permisos que no tiene ni un alcance mas amplio que el propio; nadie amplia el suyo.
function accessDelegationTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $t = $o['tenant'];
    $db->run('UPDATE tenants SET rbac_self_management=TRUE WHERE tenant_id=?', [$t]);
    $perms = ['usuarios.ver', 'roles.ver', 'roles.gestionar'];
    $juan = accessAccount($db, $o, 'juan', $perms, new AccessScope('filters', [], [], [], [$o['agentes']]));
    $bl = accessAccount($db, $o, 'bl2', $perms, new AccessScope('filters', [$o['bl']]));
    $coord2 = accessAccount($db, $o, 'coord2', $perms, new AccessScope('filters', [], [], [$o['teq'], $o['med']], [$o['agentes']]));
    $role = function (string $name, array $permissions) use ($db, $t): string {
        $id = Util::bin(Util::uuid());
        $db->run("INSERT INTO roles (tenant_id,id,name,scope_kind) VALUES (?,?,?,'self')", [$t, $id, $name]);
        foreach ($permissions as $p) { $db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)', [$t, $id, $p]); }
        return $id;
    };
    $viewer = $role('Solo ver personas', ['usuarios.ver']); $wider = $role('Ver equipos', ['equipos.ver']);
    $put = function (array $a, string $who, string $roleId, array $scope) use ($db, $o): array {
        $v = $db->one('SELECT version FROM users WHERE tenant_id=? AND id=?', [$o['tenant'], $o[$who]['id']])['version'];
        return adminRequest($a, 'PUT', '/users/' . Util::id($o[$who]['id']) . '/access', ['role_id' => Util::id($roleId), 'scope' => $scope], ['if-match' => '"' . $v . '"']);
    };
    $exceeds = fn (array $r) => $r[0] === 403 && ($r[1]['detail'] ?? null) === 'delegation_exceeds_own_access';
    $agentes = accessScopeBody('filters', ['area_ids' => [Util::id($o['agentes'])]]);
    $ok = $put($juan, 'agTorre', $viewer, $agentes);
    check($ok[0] === 200 && $ok[1]['access']['role_name'] === 'Solo ver personas' && $ok[1]['access']['scope'] === $agentes && $ok[1]['access']['source'] === 'manual', 'Juan da su mismo alcance');
    check($exceeds($put($juan, 'agTorre', $viewer, accessScopeBody('all'))), 'Juan no da todo el cliente');
    check($exceeds($put($juan, 'agTorre', $viewer, accessScopeBody('filters', ['area_ids' => [Util::id($o['juridica'])]]))), 'Juan no da otra área');
    check($exceeds($put($juan, 'agTorre', $viewer, accessScopeBody('filters', ['site_ids' => [Util::id($o['torre'])]]))), 'quitar el filtro de área amplía');
    check($put($juan, 'agTorre', $viewer, accessScopeBody('filters', ['site_ids' => [Util::id($o['torre'])], 'area_ids' => [Util::id($o['agentes'])]]))[0] === 200, 'acotar más es válido');
    check($exceeds($put($juan, 'agTorre', $wider, $agentes)), 'Juan no da permisos que no tiene');
    check($put($juan, 'jur808', $viewer, $agentes)[0] === 404, 'persona fuera del alcance: 404');
    check($exceeds($put($juan, 'juan', $viewer, accessScopeBody('all'))), 'nadie amplía su propio acceso');
    check($exceeds($put($bl, 'bl1', $viewer, accessScopeBody('filters', ['company_ids' => [Util::id($o['azc'])]]))), 'BilingueLaw no da AZC');
    check($put($bl, 'bl1', $viewer, accessScopeBody('filters', ['company_ids' => [Util::id($o['bl'])]]))[0] === 200, 'BilingueLaw da BilingueLaw');
    check($exceeds($put($coord2, 'ag808', $viewer, accessScopeBody('team'))), 'un alcance de filtros no da equipo');
    check($put($coord2, 'ag808', $viewer, accessScopeBody('self'))[0] === 200, 'solo lo propio a alguien visible');
    check($exceeds($put($coord2, 'juan', $viewer, accessScopeBody('self'))), 'no toca el acceso de quien tiene roles.gestionar');
    // Forma del alcance y del rol (superadmin no pasa por la delegacion, si por la forma).
    $platform = sharedPlatform($db); $platform['tenant'] = $t;
    check($put($platform, 'jur808', $viewer, accessScopeBody('filters'))[1]['detail'] === 'scope_filters_empty', 'filtros sin valores');
    check($put($platform, 'jur808', $viewer, accessScopeBody('self', ['area_ids' => [Util::id($o['agentes'])]]))[1]['detail'] === 'scope_lists_only_for_filters', 'listas solo con filtros');
    $db->run('UPDATE roles SET active=FALSE WHERE tenant_id=? AND id=?', [$t, $wider]);
    check($put($platform, 'jur808', $wider, accessScopeBody('self'))[1]['detail'] === 'role_inactive', 'rol inactivo');
    $set = $put($platform, 'jur808', $viewer, accessScopeBody('all'));
    check($set[0] === 200 && $set[1]['access']['scope']['kind'] === 'all', 'superadmin da cualquier alcance');
    echo 'PASS access delegation (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

En `tests/admin.php`, reemplazar las líneas 95-97 (`/roles` → `/access`):

```php
    expect(adminRequest($delegate,'PUT','/users/'.Util::id($delegateUser['id']).'/access',['role_id'=>Util::id($admin['role']),'scope'=>accessScopeBody('all')],['if-match'=>'"1"']),403,'indirect meta assignment blocked');
    $u=expect(adminRequest($admin,'GET','/users/'.Util::id($readerUser['id'])),200,'read role recipient');
    expect(adminRequest($platformTenant,'PUT','/users/'.Util::id($readerUser['id']).'/access',['role_id'=>$role['id'],'scope'=>accessScopeBody('all')],['if-match'=>'"'.$u['version'].'"']),200,'platform assigns role');
```

En `tests/smoke.php` agregar `accessDelegationTests($db);` después de `accessCaseTests($db);`.

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL. `PUT /users/{id}/access` no existe en el contrato: 404 o `route not found`, con la aserción `Juan da su mismo alcance`.

- [ ] **Step 3: Contrato** — en `docs/architecture/openapi-v4.yaml` (CRLF):

1. Path `/users/{id}/roles` (línea ~6122), editado en el lugar:
   - la clave pasa a `/users/{id}/access:`;
   - `operationId: setUserAccess`;
   - `summary: Dar el acceso de una persona (1 rol + 1 alcance)`;
   - `description: Delegación segura. Nadie da un rol con permisos que no tiene ni un alcance más amplio que el propio (403 delegation_exceeds_own_access). Invalida las sesiones de la persona y se audita.` (sin `: ` dentro);
   - en la respuesta 200, `description: Acceso dado`;
   - `requestBody` → `$ref: '#/components/schemas/AccessInput'`;
   - `x-permission: roles.gestionar` se queda.
2. Borrar el esquema `RoleBindings` (línea ~16502).
3. Agregar, junto a `RoleScope`:

```yaml
    AccessScope:
      type: object
      additionalProperties: false
      description: Qué datos ve la persona. all = todo el cliente; filters = empresa, ciudad, sede y área (lista vacía = todas; O dentro de un filtro, Y entre filtros); team = ella y quienes le reportan; self = solo ella.
      required:
      - kind
      - company_ids
      - cities
      - site_ids
      - area_ids
      properties:
        kind:
          type: string
          enum:
          - all
          - filters
          - team
          - self
        company_ids:
          type: array
          maxItems: 100
          items:
            type: string
            format: uuid
        cities:
          type: array
          maxItems: 100
          items:
            type: string
            minLength: 1
            maxLength: 80
        site_ids:
          type: array
          maxItems: 100
          items:
            type: string
            format: uuid
        area_ids:
          type: array
          maxItems: 100
          items:
            type: string
            format: uuid
    AccessInput:
      type: object
      additionalProperties: false
      required:
      - role_id
      - scope
      properties:
        role_id:
          type: string
          format: uuid
        scope:
          $ref: '#/components/schemas/AccessScope'
    Access:
      type: object
      additionalProperties: false
      required:
      - role_id
      - role_name
      - scope
      - source
      properties:
        role_id:
          type: string
          format: uuid
        role_name:
          type: string
          maxLength: 120
        scope:
          $ref: '#/components/schemas/AccessScope'
        source:
          type: string
          description: manual = lo dio una persona; rule = lo propuso una regla por cargo; default = Colaborador por defecto.
          enum:
          - manual
          - rule
          - default
```

4. En el esquema `User` (línea ~16315):
   - en `required`, reemplazar `- role_ids` por `- access`;
   - en `properties`, reemplazar el bloque `role_ids:` (líneas ~16414-16420) por

```yaml
        access:
          anyOf:
          - $ref: '#/components/schemas/Access'
          - type: 'null'
```

Luego `python tests/export-contract.py`.

- [ ] **Step 4: Implementation**

En `AccessGrants.php`:

```php
    /** Dar el acceso de una persona (AccessInput) con delegacion segura. Cambiar solo el alcance no le quita el rol a la regla que lo puso. */
    public static function assign(Database $db, AdminAccess $by, string $tenant, string $user, object $input): void
    {
        $role = $db->one('SELECT * FROM roles WHERE tenant_id=? AND id=?', [$tenant, Util::bin($input->role_id)]);
        if (!$role) { throw new ApiError(422, 'validation_failed', [], 'role_not_found'); }
        if (!$role['active']) { throw new ApiError(409, 'invalid_transition', [], 'role_inactive'); }
        $scope = AccessScope::fromInput($db, $tenant, $input->scope);
        $current = self::forUser($db, $tenant, $user);
        // Cambiar un acceso tambien es delegar: no se toca el de alguien con mas de lo que uno tiene.
        if ($current !== null) { $by->delegate(self::permissions($db, $tenant, $current['role_id']), $current['scope'], $user); }
        $by->delegate(self::permissions($db, $tenant, $role['id']), $scope, $user);
        $keepRule = $current !== null && $current['source'] === 'rule' && $current['role_id'] === $role['id'];
        self::setForUser($db, $tenant, $user, $role['id'], $scope, $keepRule ? 'rule' : 'manual', $keepRule ? $current['rule_id'] : null);
    }
```

El test `rol inactivo` espera `detail = role_inactive` con 409: `adminRequest` devuelve el detalle igual.

En `AdminApi.php`:
- en el `match` de `dispatch`, quitar `'setUserRoles'` de la línea de `users()` y agregar `'setUserAccess'=>$this->userAccess($id,$b),` junto a `'setUserPlan'`;
- en `users()`, borrar la rama `if ($op==='setUserRoles') {...} else {`: el contenido del `else` queda en el nivel de arriba. Quitar la `}` de cierre que sobra y la condición `if ($op!=='setUserRoles')` antes de `RoleRules::apply` (queda la llamada sola);
- agregar, junto a `userPlan`:

```php
    /** Acceso de una persona = 1 rol + 1 alcance (§4), con delegacion segura. */
    private function userAccess(string $id, object $b): array
    {
        $this->access->user($id); $old=$this->row('users',$id); $this->match($old);
        AccessGrants::assign($this->db,$this->access,$this->tenant,$id,$b);
        $this->db->run('UPDATE users SET version=version+1 WHERE tenant_id=? AND id=?',[$this->tenant,$id]); $this->invalidateUser($id);
        $this->audit('user',$id,['access']);
        return $this->result($this->userDto($this->row('users',$id)));
    }
```

- en `userDto`, reemplazar `'role_ids'=>...` por `'access'=>AccessGrants::dto($grant),`.

En `public/assets/k3.js`, en `AUDIT_ACTIONS`, reemplazar `setUserRoles: 'Cambió los roles de una persona'` por `setUserAccess: 'Cambió el acceso de una persona'`.

- [ ] **Step 5: Run test to verify it passes**

Run: suite completa.
Expected: `PASS access delegation (...)`, `PASS admin`, `PASS ops` (texto de auditoría) y `SMOKE PASS`.

Si algún test leía `role_ids` de una persona, buscarlo con `grep -n "'role_ids'\]" backend-v4/tests/*.php`. Cambiarlo a `['access']['role_id']`; las cuentas del panel siguen con `role_ids` hasta la Task 6.

- [ ] **Step 6: Commit**

```bash
git add docs/architecture/openapi-v4.yaml backend-v4/config/contract.json backend-v4/src/AccessGrants.php backend-v4/src/AdminApi.php backend-v4/public/assets/k3.js backend-v4/tests
git commit -m "Accesos: PUT /users/{id}/access con delegación segura (reemplaza los roles por persona)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Cuentas del panel y cuentas de grupo con `access` / `group_access`

**Files:**
- Modify: `docs/architecture/openapi-v4.yaml`:
  - `PanelAccount`, `PanelAccountInput` y `PanelAccountPatch`;
  - `GroupRoleGrant` → `GroupAccessGrant`.
- Modify: `backend-v4/src/PanelAccounts.php`
- Modify: `backend-v4/tests/panel-accounts.php`, `backend-v4/tests/company-groups.php`, `backend-v4/tests/role-rules.php:36-44`

**Interfaces:**
- Consumes: Task 5 (`AccessGrants::assign`, esquemas `AccessInput`/`Access`/`AccessScope`).
- Produces:
  - `PanelAccountInput`: `{kind, email, display_name, user_id, access?: AccessInput|null, group_id?, group_access?: GroupAccessGrant[]}`.
  - `PanelAccountPatch`: `{active, access?: AccessInput|null, group_id?, group_access?}`.
  - `PanelAccount`: reemplaza `role_ids` por `access: Access|null` y `group_roles` por `group_access: [{tenant_id, tenant_name, role_id, role_name, scope}]`.
  - `GroupAccessGrant`: `{tenant_id, role_id, scope}`.
  - Errores de grupo: 422 `group_scope_invalid` y `tenant_not_in_group`, y 422 `validation_failed` / `duplicate_tenant` si se repite una empresa.

- [ ] **Step 1: Write the failing test** — cambiar las pruebas a la forma nueva.

`tests/panel-accounts.php`:
- línea 27: `$body = ['kind' => 'company', 'email' => $email, 'display_name' => null, 'user_id' => Util::id($u['id']), 'access' => ['role_id' => Util::id($role), 'scope' => accessScopeBody('all')]];`
- línea 33: `check($created['account']['access']['role_id'] === Util::id($role) && $created['account']['access']['scope']['kind'] === 'all' && $created['account']['user_name'] === $u['display_name'], 'account linked to person and access');`
- línea 57: el cuerpo de plataforma sin `role_ids`: `['kind' => 'platform', 'email' => $superEmail, 'display_name' => 'Superadmin de prueba', 'user_id' => null]`.
- línea 77: `['active' => false]`; línea 79: `['active' => true]` con etiqueta `'enable account keeps its access'`; línea 94: `['active' => false]`.
- después de la línea 79, agregar:

```php
    $a2 = expect(adminRequest($platform, 'GET', '/panel-accounts'), 200, 'list again')['data'];
    check(array_values(array_filter($a2, fn ($x) => $x['id'] === $acct))[0]['access']['role_id'] === Util::id($role), 'access survives enable/disable');
    check(adminRequest($platform, 'POST', '/panel-accounts', ['kind' => 'platform', 'email' => 'x.' . $superEmail, 'display_name' => 'X', 'user_id' => null, 'access' => ['role_id' => Util::id($role), 'scope' => accessScopeBody('all')]])[0] === 422, 'platform accounts have no access');
```

`tests/company-groups.php`:
- líneas 26-27: `$body = ['kind' => 'company', 'email' => $email, 'display_name' => null, 'user_id' => Util::id($a['id']), 'access' => ['role_id' => $roleA, 'scope' => accessScopeBody('all')], 'group_id' => $group['id'], 'group_access' => [['tenant_id' => Util::id($tb), 'role_id' => $roleB, 'scope' => accessScopeBody('all')]]];`
- línea 28: `$bad = adminRequest($platform, 'POST', '/panel-accounts', ['group_access' => [['tenant_id' => Util::id($c['tenant_id']), 'role_id' => $roleB, 'scope' => accessScopeBody('all')]]] + $body);`
- línea 31: `check($created['account']['group_name'] === 'Grupo AZC (holding)' && $created['account']['group_access'][0]['role_id'] === $roleB && $created['account']['group_access'][0]['scope']['kind'] === 'all', 'account carries group access');`
- después de la línea 31, agregar:

```php
    $teamBad = adminRequest($platform, 'POST', '/panel-accounts', ['email' => 'otro.' . $email, 'user_id' => Util::id(adminMember($db, $a)['id']), 'group_access' => [['tenant_id' => Util::id($tb), 'role_id' => $roleB, 'scope' => accessScopeBody('team')]]] + $body);
    check($teamBad[0] === 422 && $teamBad[1]['detail'] === 'group_scope_invalid', 'group access only all or filters');
```

`tests/role-rules.php`:
- línea 36: cuerpo sin `role_ids` (`['kind' => 'company', 'email' => $email, 'display_name' => null, 'user_id' => $boss]`);
- línea 44: `check(adminRequest($platform, 'PATCH', '/panel-accounts/' . $acct['account']['id'], ['active' => true])[0] === 200 && $byRule($boss), 'editing the account keeps the rule role');`.

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL `create company account` con 422: el contrato todavía exige `role_ids` y no admite `access`.

- [ ] **Step 3: Contrato** — en `openapi-v4.yaml`:

1. Renombrar `GroupRoleGrant` a `GroupAccessGrant` con

```yaml
    GroupAccessGrant:
      type: object
      additionalProperties: false
      required:
      - tenant_id
      - role_id
      - scope
      properties:
        tenant_id:
          type: string
          format: uuid
        role_id:
          type: string
          format: uuid
        scope:
          $ref: '#/components/schemas/AccessScope'
```

2. `PanelAccount`:
   - en `required`, reemplazar `- group_roles` por `- group_access` y `- role_ids` por `- access`;
   - en `properties`, reemplazar el bloque `group_roles:` por

```yaml
        group_access:
          type: array
          maxItems: 50
          items:
            type: object
            additionalProperties: false
            required:
            - tenant_id
            - tenant_name
            - role_id
            - role_name
            - scope
            properties:
              tenant_id:
                type: string
                format: uuid
              tenant_name:
                type: string
                maxLength: 120
              role_id:
                type: string
                format: uuid
              role_name:
                type: string
                maxLength: 120
              scope:
                $ref: '#/components/schemas/AccessScope'
```

   - y el bloque `role_ids:` por

```yaml
        access:
          anyOf:
          - $ref: '#/components/schemas/Access'
          - type: 'null'
```

3. `PanelAccountInput`:
   - en `required`, quitar `- role_ids`;
   - `group_roles` → `group_access` con `items: $ref: '#/components/schemas/GroupAccessGrant'`;
   - reemplazar `role_ids:` por

```yaml
        access:
          anyOf:
          - $ref: '#/components/schemas/AccessInput'
          - type: 'null'
```

4. `PanelAccountPatch`:
   - `required` queda solo `- active`;
   - mismo cambio de `group_roles` → `group_access` y `role_ids` → `access` (`AccessInput | null`).

Luego `python tests/export-contract.py`.

- [ ] **Step 4: Implementation** en `backend-v4/src/PanelAccounts.php`:

1. `create()`, rama plataforma: `if (($b->access ?? null) !== null || $b->user_id !== null) { throw new ApiError(422, 'validation_failed'); }`.
2. `create()`, rama empresa: reemplazar las líneas del puente (`assignLegacy` / `grantDefault`) por

```php
            if (($b->access ?? null) !== null) { AccessGrants::assign($this->db, $this->delegator(), $this->tenant, $user['id'], $b->access); }
            elseif (AccessGrants::forUser($this->db, $this->tenant, $user['id']) === null) { AccessGrants::grantDefault($this->db, $this->tenant, $user['id']); }
```

   y `$this->setGroup($id, $b->group_id, $b->group_access ?? []);`. En la auditoría, los campos pasan a `['kind', 'email', 'user_id', 'access']`.
3. `patch()`:
   - plataforma: `if (($b->access ?? null) !== null) { throw new ApiError(422, 'validation_failed'); }`;
   - empresa: reemplazar `assignLegacy(...)` por `if (($b->access ?? null) !== null) { AccessGrants::assign($this->db, $this->delegator(), $this->tenant, $a['user_id'], $b->access); }`;
   - el grupo pasa a `$b->group_access ?? []`;
   - en la auditoría, los campos pasan a `['active', 'access']`.
4. `setGroup()`: reemplazar el bucle de `$grants` por

```php
        $seen = [];
        foreach ($grants as $g) {
            $t = Util::bin($g->tenant_id);
            if ($t === $this->tenant || !in_array($t, $members, true)) { throw new ApiError(422, 'validation_failed', [], 'tenant_not_in_group'); }
            if (isset($seen[bin2hex($t)])) { throw new ApiError(422, 'validation_failed', [], 'duplicate_tenant'); }
            $seen[bin2hex($t)] = true;
            $role = $this->db->one('SELECT id FROM roles WHERE tenant_id=? AND id=? AND active=TRUE', [$t, Util::bin($g->role_id)]);
            if (!$role) { throw new ApiError(422, 'validation_failed', [], 'role_not_found'); }
            AccessGrants::setForGroupAccount($this->db, $t, $this->tenant, $account, $role['id'], AccessScope::fromInput($this->db, $t, $g->scope));
        }
```

5. `groupDto()`:

```php
    private function groupDto(array $a): array
    {
        if (($a['group_id'] ?? null) === null) { return ['group_id' => null, 'group_name' => null, 'group_access' => []]; }
        $access = [];
        foreach ($this->db->run('SELECT g.tenant_id,t.name tenant_name,g.admin_tenant_id,g.admin_id FROM access_grants g JOIN tenants t ON t.tenant_id=g.tenant_id WHERE g.admin_tenant_id=? AND g.admin_id=? ORDER BY t.name', [$a['tenant_id'], $a['id']])->fetchAll() as $r) {
            $g = AccessGrants::forGroupAccount($this->db, $r['tenant_id'], $r['admin_tenant_id'], $r['admin_id']);
            $access[] = ['tenant_id' => Util::id($r['tenant_id']), 'tenant_name' => $r['tenant_name'], 'role_id' => Util::id($g['role_id']), 'role_name' => $g['role_name'], 'scope' => $g['scope']->dto()];
        }
        return ['group_id' => Util::id($a['group_id']), 'group_name' => $this->db->one('SELECT name FROM company_groups WHERE id=?', [$a['group_id']])['name'] ?? null, 'group_access' => $access];
    }
```

6. `dto()`: reemplazar `$roles = ...` y `'role_ids' => $roles` por `'access' => $a['is_platform_admin'] ? null : AccessGrants::dto(AccessGrants::forUser($this->db, $a['tenant_id'], $a['user_id'])),`.
7. Actualizar el comentario de cabecera de la clase: «lo que ve lo decide su acceso: 1 rol + 1 alcance».

- [ ] **Step 5: Run test to verify it passes**

Run: suite completa.
Expected: `PASS panel accounts`, `PASS company groups`, `PASS role rules` y `SMOKE PASS`.

Luego `grep -n "assignLegacy" backend-v4/src/*.php`: solo debe quedar su definición en `AccessGrants.php`.

- [ ] **Step 6: Commit**

```bash
git add docs/architecture/openapi-v4.yaml backend-v4/config/contract.json backend-v4/src/PanelAccounts.php backend-v4/tests
git commit -m "Accesos: cuentas del panel y de grupo con un acceso (rol + alcance) por empresa

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: La regla por cargo propone el rol y nunca toca el alcance

**Files:**
- Modify: `backend-v4/src/AccessGrants.php` (`revertToDefault`), `backend-v4/src/RoleRules.php` (`apply`, comentario de cabecera)
- Modify: `backend-v4/tests/role-rules.php`

**Interfaces:**
- Consumes: Task 5 (`PUT /users/{id}/access` conserva el origen `rule` si el rol no cambia).
- Produces: `AccessGrants::revertToDefault(Database $db, string $tenant, string $user): void`. Devuelve el rol por defecto, conserva el alcance y deja el origen en `default`. Sin rol por defecto, quita el acceso.

- [ ] **Step 1: Write the failing test** — en `tests/role-rules.php`, después de la línea `check($byRule($boss) && $byRule($p1['id']) && !$byRule($p2['id']), ...)`:

```php
    // La regla propone el rol, no el alcance (§4): el jefe queda con «solo lo propio» hasta que alguien le da «su equipo».
    $scopeOf = fn (string $user) => $db->one('SELECT scope_kind FROM access_grants WHERE tenant_id=? AND user_id=?', [$tenant, Util::bin($user)])['scope_kind'];
    check($scopeOf($boss) === 'self', 'rule never sets the scope');
    $bv = $db->one('SELECT version FROM users WHERE tenant_id=? AND id=?', [$tenant, $u['id']])['version'];
    expect(adminRequest($platform, 'PUT', '/users/' . $boss . '/access', ['role_id' => $jefe['id'], 'scope' => accessScopeBody('team')], ['if-match' => '"' . $bv . '"']), 200, 'give the boss his team');
    check($byRule($boss) && $scopeOf($boss) === 'team', 'changing only the scope keeps the rule role');
```

y al final, después de `rule role removed when condition stops`:

```php
    check($scopeOf($boss) === 'team', 'applying rules again keeps the scope');
    check($db->one("SELECT source FROM access_grants WHERE tenant_id=? AND user_id=?", [$tenant, Util::bin($p1['id'])])['source'] === 'default', 'stopped matching: back to the default role');
```

Además, como el puente ya no copia el alcance del rol, el bloque «El jefe ve a su equipo» usa el alcance dado arriba. No cambia: `$seen === $want` sigue igual.

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL `rule never sets the scope`. Hoy el puente copia `team` del rol viejo.

- [ ] **Step 3: Implementation**

En `AccessGrants.php`:

```php
    /** La regla que puso el rol dejo de cumplirse: vuelve el rol por defecto; el alcance se conserva (la regla nunca lo toco). */
    public static function revertToDefault(Database $db, string $tenant, string $user): void
    {
        $g = self::forUser($db, $tenant, $user);
        if ($g === null) { return; }
        $role = self::defaultRole($db, $tenant);
        if ($role === null) { self::removeForUser($db, $tenant, $user); return; }
        self::setForUser($db, $tenant, $user, $role, $g['scope'], 'default');
    }
```

En `RoleRules::apply`:
- cambiar `AccessGrants::grantDefault($db, $tenant, $row['user_id'])` por `AccessGrants::revertToDefault($db, $tenant, $row['user_id'])`;
- cambiar `AccessScope::fromLegacyRole($db, $tenant, $role)` por `$g['scope'] ?? AccessScope::own()`;
- comentario de la clase: «Asignacion automatica por cargo (§4): propone el rol, no el alcance. Solo a personas sin acceso manual; si deja de cumplirse, vuelve el rol por defecto con el mismo alcance.»

- [ ] **Step 4: Run test to verify it passes**

Run: suite completa.
Expected: `PASS role rules` y `SMOKE PASS`.

- [ ] **Step 5: Commit**

```bash
git add backend-v4/src/AccessGrants.php backend-v4/src/RoleRules.php backend-v4/tests/role-rules.php
git commit -m "Accesos: la regla por cargo propone el rol y nunca cambia el alcance

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Roles sin alcance, plantillas del §4 y fin del puente

**Files:**
- Modify: `docs/architecture/openapi-v4.yaml`: `Role` y `RoleInput` sin `scope`; se borra `RoleScope`.
- Modify: `backend-v4/src/AdminApi.php`:
  - `tenants()`: alta de cliente, siembra de roles;
  - `roleDto`;
  - `roles()`;
  - `organization()`: lectura sin alcance «todo».
- Modify: `backend-v4/src/AccessScope.php` y `backend-v4/src/AccessGrants.php`: borrar `fromLegacy`, `fromLegacyRole` y `assignLegacy`.
- Modify: `backend-v4/tests/admin.php` (`adminFixture` sin filas viejas; `$roleBody`; línea 90), `backend-v4/tests/role-rules.php:23-27`, `backend-v4/tests/access.php`, `backend-v4/tests/smoke.php`

**Interfaces:**
- Consumes: Tasks 3-7.
- Produces:
  - `Role`: `{assignable_by_rule, id, tenant_id, name, permissions, active, seed_key, version}`.
  - `RoleInput`: `{name, permissions, active, assignable_by_rule?}`.
  - `listRoles` y `getRole` solo exigen `roles.ver`. `createRole` y `patchRole` exigen alcance `all` (`scope_denied`).
  - Guardar un rol con un permiso de función no contratada da 403 `feature_not_contracted:<f>`, también a superadmin.
  - `listOrganization` y `getOrgUnit` solo exigen `organizacion.ver`.
  - Un cliente nuevo recibe solo plantillas con `offered = TRUE`, con los permisos de sus funciones.

- [ ] **Step 1: Write the failing test** — agregar a `backend-v4/tests/access.php`:

```php
// Roles (§4): sin alcance, de todo el cliente, nunca con funciones no compradas; plantillas Corporativo, Gerente, Coordinador, IT, Colaborador.
function accessRoleTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $t = $o['tenant'];
    $db->run('UPDATE tenants SET rbac_self_management=TRUE WHERE tenant_id=?', [$t]);
    $juan = accessAccount($db, $o, 'juan', ['usuarios.ver', 'roles.ver', 'roles.gestionar', 'organizacion.ver'], new AccessScope('filters', [], [], [], [$o['agentes']]));
    $platform = sharedPlatform($db); $platform['tenant'] = $t;
    $body = ['name' => 'Lectura', 'permissions' => ['usuarios.ver'], 'active' => true];
    $made = expect(adminRequest($platform, 'POST', '/roles', $body), 201, 'role without scope');
    check(!array_key_exists('scope', $made), 'roles carry no scope');
    check(adminRequest($juan, 'GET', '/roles')[0] === 200 && adminRequest($juan, 'GET', '/organization')[0] === 200, 'a scoped delegate can read roles and organization to delegate');
    $denied = adminRequest($juan, 'POST', '/roles', ['name' => 'Otro'] + $body);
    check($denied[0] === 403 && $denied[1]['detail'] === 'scope_denied', 'only scope all creates roles');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='cumplimiento'", [$t]);
    $off = adminRequest($platform, 'POST', '/roles', ['name' => 'Cumplimiento', 'permissions' => ['cumplimiento.ver'], 'active' => true]);
    check($off[0] === 403 && $off[1]['detail'] === 'feature_not_contracted:cumplimiento', 'a role never includes an uncontracted function');
    // Cliente nuevo: las cinco plantillas, con solo lo de su paquete.
    $pkg = expect(adminRequest($platform, 'POST', '/feature-packages', ['name' => 'Solo productividad ' . substr(Util::uuid(), 0, 6), 'features' => ['productividad']]), 201, 'package');
    $new = expect(adminRequest(sharedPlatform($db), 'POST', '/tenants', ['name' => 'Cliente roles ' . substr(Util::uuid(), 0, 6), 'timezone' => 'America/Bogota', 'package_id' => $pkg['id'],
        'branding' => ['display_name' => 'Cliente roles', 'logo_url' => 'https://example.test/logo.svg', 'primary_color' => '#3C4082', 'accent_color' => '#5A61B3']]), 201, 'new client');
    $names = array_column($db->run('SELECT name FROM roles WHERE tenant_id=? ORDER BY name', [Util::bin($new['id'])])->fetchAll(), 'name');
    check($names === ['Colaborador', 'Coordinador', 'Corporativo', 'Gerente', 'IT'], 'new client gets the five templates');
    check(!$db->one("SELECT 1 x FROM role_permissions p JOIN roles r ON r.tenant_id=p.tenant_id AND r.id=p.role_id WHERE p.tenant_id=? AND p.permission_code IN ('cumplimiento.ver','cobertura.ver','equipos.apagar')", [Util::bin($new['id'])]), 'templates drop uncontracted permissions');
    check((bool) $db->one("SELECT 1 x FROM role_permissions p JOIN roles r ON r.tenant_id=p.tenant_id AND r.id=p.role_id WHERE p.tenant_id=? AND r.name='Corporativo' AND p.permission_code='reportes.ver'", [Util::bin($new['id'])]), 'contracted permissions kept');
    echo 'PASS access roles (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
```

Agregar `accessRoleTests($db);` en `smoke.php` después de `accessDelegationTests($db);`.

Pruebas existentes, en la misma tarea:
- `tests/admin.php:83`: `$roleBody=['name'=>'Read only','permissions'=>['usuarios.ver'],'active'=>true];`
- `tests/admin.php:90`: `expect(adminRequest($scoped,'POST','/roles',$roleBody),403,'scoped delegate cannot create roles');`
- `tests/admin.php:94`: el `array_replace($roleBody, [...])` ya no lleva `scope`; queda igual.
- `tests/admin.php`, `adminFixture`:
  - borrar `foreach ($scopeIds ...)` sobre `role_{$scope}_scopes` y el `INSERT INTO user_roles`;
  - el `INSERT INTO roles` usa `scope_kind` fijo `'self'`, con el comentario «columna vieja sin uso: el alcance va en la persona».
- `tests/role-rules.php:23` y `:27`: quitar `'scope' => [...]` de los cuerpos. Reemplazar `check($plain['assignable_by_rule'] === false && $plain['scope']['kind'] === 'team', 'team scope role')` por `check($plain['assignable_by_rule'] === false, 'manual role');`.

- [ ] **Step 2: Run test to verify it fails**

Run: suite completa.
Expected: FAIL `role without scope`. El contrato todavía exige `scope` en `RoleInput` y responde 422.

- [ ] **Step 3: Contrato** — en `openapi-v4.yaml`:
- `RoleInput`: quitar `- scope` de `required` y la propiedad `scope`.
- `Role`: quitar `- scope` de `required` y la propiedad `scope`. En `description` del rol (si no tiene, agregar) poner `Un conjunto de permisos. El alcance va en el acceso de cada persona.`
- Borrar el esquema `RoleScope`.

Luego `python tests/export-contract.py`.

- [ ] **Step 4: Implementation** en `AdminApi.php`:

1. `roleDto`: quitar el cálculo de `$ids`/`$kind` y la clave `'scope'`.
2. `roles()`: reemplazar el cuerpo completo por

```php
    private function roles(string $op,?string $id,?object $b): array
    {
        if ($op==='listRoles') { return $this->page('SELECT * FROM roles WHERE tenant_id=?',[$this->tenant],fn($x)=>$this->roleDto($x)); }
        if ($op==='getRole') { return $this->result($this->roleDto($this->row('roles',$id))); }
        // Los roles son de todo el cliente: crearlos o editarlos exige alcance «todo» (§4).
        $this->access->requireFull();
        if ($id) { $old=$this->row('roles',$id); $this->match($old); $this->access->delegate(AccessGrants::permissions($this->db,$this->tenant,$id),AccessScope::all(),null); }
        $this->unique($b->permissions);
        $this->contracted($b->permissions);
        $this->access->delegate($b->permissions,AccessScope::all(),null);
        if ($id) {
            $this->db->run('DELETE FROM role_permissions WHERE tenant_id=? AND role_id=?',[$this->tenant,$id]);
            $this->update('roles',$id,['name'=>$b->name,'active'=>(int)$b->active,'assignable_by_rule'=>(int)($b->assignable_by_rule??$old['assignable_by_rule'])]);
        } else {
            // scope_kind: columna vieja sin uso (el alcance va en la persona, 0043).
            $id=Util::bin(Util::uuid()); $this->db->run("INSERT INTO roles (tenant_id,id,name,scope_kind,active,assignable_by_rule) VALUES (?,?,?,'self',?,?)",[$this->tenant,$id,$b->name,(int)$b->active,(int)($b->assignable_by_rule??false)]);
        }
        foreach ($b->permissions as $permission) { $this->db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)',[$this->tenant,$id,$permission]); }
        foreach ($this->db->run('SELECT user_id FROM access_grants WHERE tenant_id=? AND role_id=? AND user_id IS NOT NULL',[$this->tenant,$id])->fetchAll() as $user) { $this->invalidateUser($user['user_id']); }
        $this->db->run('UPDATE tenants SET auth_version=auth_version+1 WHERE tenant_id=?',[$this->tenant]);
        RoleRules::apply($this->db,$this->tenant);
        $this->audit('role',$id,array_keys(get_object_vars($b))); return $this->result($this->roleDto($this->row('roles',$id)),$op==='createRole'?201:200);
    }
    /** Un rol nunca incluye permisos de funciones que el cliente no compro (§4), ni siquiera si lo guarda superadmin. */
    private function contracted(array $permissions): void
    {
        foreach ($permissions as $code) {
            $f=Features::forOperation('',$code);
            if ($f!==null && !Features::has($this->db,$this->tenant,$f)) { throw new ApiError(403,'permission_denied',[],"feature_not_contracted:$f"); }
        }
    }
```

3. `organization()`: cambiar la primera línea `$this->access->requireFull();` por

```php
        // Leer la estructura (empresas, sedes, areas, cargos) no es dato de personas: quien delega la necesita para elegir el alcance.
        if (!in_array($op,['listOrganization','getOrgUnit'],true)) { $this->access->requireFull(); }
```

4. `tenants()`, `createTenant`: mover la siembra de roles **después** del bloque `if (isset($b->package_id)) {...}` y reemplazarla por

```php
            // Roles base del cliente nuevo (§4): las plantillas ofrecidas, solo con permisos de sus funciones (o del nucleo).
            foreach ($this->db->run('SELECT * FROM role_templates WHERE platform_only=FALSE AND offered=TRUE ORDER BY seed_key')->fetchAll() as $template) {
                $role=Util::bin(Util::uuid());
                $this->db->run("INSERT INTO roles (tenant_id,id,name,seed_key,seed_version,scope_kind) VALUES (?,?,?,?,?,'self')",[$id,$role,$template['name'],$template['seed_key'],$template['version']]);
                foreach (array_column($this->db->run('SELECT permission_code FROM role_template_permissions WHERE seed_key=?',[$template['seed_key']])->fetchAll(),'permission_code') as $code) {
                    $f=Features::forOperation('',$code);
                    if ($f===null || Features::has($this->db,$id,$f)) { $this->db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)',[$id,$role,$code]); }
                }
            }
```

5. Borrar el puente:
   - `AccessScope::fromLegacy`, `AccessScope::fromLegacyRole`;
   - `AccessGrants::assignLegacy`.

- [ ] **Step 5: Run test to verify it passes**

Run: suite completa.
Expected: `PASS access roles`, `PASS admin` y `SMOKE PASS`.

Luego:

```bash
grep -n "fromLegacy\|assignLegacy\|user_roles\|admin_account_roles\|role_site_scopes\|role_area_scopes\|scope_kind'=>\|RoleScope" backend-v4/src/*.php
```

Expected: ninguna línea. Única excepción: `scope_kind` en los INSERT de roles, con el valor fijo `'self'`.

- [ ] **Step 6: Commit**

```bash
git add docs/architecture/openapi-v4.yaml backend-v4/config/contract.json backend-v4/src backend-v4/tests
git commit -m "Accesos: roles sin alcance, solo con funciones contratadas, plantillas del diseño y fin del puente viejo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Pantallas — acceso en la ficha de la persona, Cuentas y Roles

**Files:**
- Create: `backend-v4/public/assets/access-scope.js`, `backend-v4/tests/access-summary.test.mjs`
- Modify: `backend-v4/public/assets/k3.js`:
  - import;
  - `scopeText` (se borra);
  - `accounts()`, `people()`, `rolesPage()`;
  - `AUDIT_FIELDS`.
- Modify: `backend-v4/public/assets/api.js` (`errorMessage`), `backend-v4/public/assets/app.js:117`, `backend-v4/public/roles.php`

**Interfaces:**
- Consumes: Tasks 5, 6, 8 (shapes `Access`, `AccessScope`, `PanelAccount.access`, `group_access`, `Role` sin `scope`).
- Produces:
  - `scopeSummary(roleName: string, scope: AccessScope|null, names: Map<string,string>): string`, en `access-scope.js`.
  - `accessEditor(parent, { roles, org, current, groupOnly }) → { read(): {role_id, scope}|null, valid(): boolean }`, en `k3.js`.

- [ ] **Step 1: Write the failing test** — crear `backend-v4/tests/access-summary.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { scopeSummary } from '../public/assets/access-scope.js';

const names = new Map([['a1', 'Agentes de servicio'], ['s1', 'Tequendama'], ['s2', 'Medellín 808'], ['c1', 'BilingueLaw']]);
const scope = (kind, lists = {}) => ({ kind, company_ids: [], cities: [], site_ids: [], area_ids: [], ...lists });

test('coordinador de un área en todas las sedes (ejemplo del diseño)', () => {
  assert.equal(scopeSummary('Coordinador', scope('filters', { area_ids: ['a1'] }), names), 'Coordinador · Área: Agentes de servicio · todas las sedes');
});
test('coordinador de dos sedes', () => {
  assert.equal(scopeSummary('Coordinador', scope('filters', { site_ids: ['s1', 's2'], area_ids: ['a1'] }), names), 'Coordinador · Sede: Tequendama, Medellín 808 · Área: Agentes de servicio');
});
test('gerente de una empresa', () => {
  assert.equal(scopeSummary('Gerente', scope('filters', { company_ids: ['c1'] }), names), 'Gerente · Empresa: BilingueLaw · todas las sedes');
});
test('ciudad sin área', () => {
  assert.equal(scopeSummary('IT', scope('filters', { cities: ['Medellín'] }), names), 'IT · Ciudad: Medellín · todas las áreas');
});
test('todo, equipo, propio y sin acceso', () => {
  assert.equal(scopeSummary('Corporativo', scope('all'), names), 'Corporativo · Todo el cliente');
  assert.equal(scopeSummary('Jefe', scope('team'), names), 'Jefe · Su equipo (quienes le reportan)');
  assert.equal(scopeSummary('Colaborador', scope('self'), names), 'Colaborador · Solo lo propio');
  assert.equal(scopeSummary(null, null, names), 'Sin acceso');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (desde la raíz): `node --test backend-v4/tests/access-summary.test.mjs`
Expected: FAIL `Cannot find module .../access-scope.js`.

- [ ] **Step 3: Write `backend-v4/public/assets/access-scope.js`**

```js
// Frase legible de un acceso (docs/architecture/v4-clientes-funciones-accesos.md §5):
// «Coordinador · Área: Agentes de servicio · todas las sedes». Puro (sin DOM): lo prueba tests/access-summary.test.mjs.
export function scopeSummary(roleName, scope, names) {
  if (!scope) return 'Sin acceso';
  const role = roleName || 'Sin rol';
  if (scope.kind === 'all') return `${role} · Todo el cliente`;
  if (scope.kind === 'team') return `${role} · Su equipo (quienes le reportan)`;
  if (scope.kind === 'self') return `${role} · Solo lo propio`;
  const label = ids => ids.map(id => names.get(id) || id).join(', ');
  const parts = [role];
  if (scope.company_ids.length) parts.push(`Empresa: ${label(scope.company_ids)}`);
  if (scope.cities.length) parts.push(`Ciudad: ${scope.cities.join(', ')}`);
  if (scope.site_ids.length) parts.push(`Sede: ${label(scope.site_ids)}`);
  if (scope.area_ids.length) parts.push(`Área: ${label(scope.area_ids)}`);
  if (!scope.site_ids.length && !scope.cities.length) parts.push('todas las sedes');
  else if (!scope.area_ids.length) parts.push('todas las áreas');
  return parts.join(' · ');
}
```

Run: `node --test backend-v4/tests/access-summary.test.mjs`
Expected: `# pass 5`, `# fail 0`.

- [ ] **Step 4: `k3.js`, editor de acceso** — agregar `import { scopeSummary } from './access-scope.js';` a los imports. Borrar la constante `scopeText` (líneas 264-265) y agregar, en su lugar:

```js
// Editor de un acceso: rol (qué puede hacer) + alcance (qué datos ve), con la frase legible al pie (§4 y §5).
// groupOnly: cuenta de grupo en otra empresa, solo «todo» o filtros.
function accessEditor(parent, { roles, org, current = null, groupOnly = false }) {
  const box = el('fieldset', undefined, 'perm-group'); box.append(el('legend', 'Acceso al panel')); parent.append(box);
  const n = names(org);
  const role = el('select'); role.append(new Option('Elige el rol…', ''));
  roles.filter(r => r.active || r.id === current?.role_id).sort((a, b) => a.name.localeCompare(b.name, 'es')).forEach(r => role.append(new Option(r.name, r.id)));
  role.value = current?.role_id || ''; labeled(box, 'Rol (qué puede hacer)', role);
  const kind = el('select');
  [['all', 'Todo el cliente'], ['filters', 'Solo ciertas empresas, ciudades, sedes o áreas'], ...(groupOnly ? [] : [['team', 'Su equipo (ella y quienes le reportan)'], ['self', 'Solo lo propio']])]
    .forEach(([v, t]) => kind.append(new Option(t, v)));
  kind.value = current?.scope.kind || (groupOnly ? 'all' : 'self'); labeled(box, 'Alcance (qué datos ve)', kind);
  const filters = el('div'); box.append(filters);
  const units = k => org.filter(o => o.kind === k && o.active).sort((a, b) => a.name.localeCompare(b.name, 'es')).map(o => [o.id, o.name]);
  const cities = [...new Set(org.filter(o => o.kind === 'site' && o.city).map(o => o.city))].sort((a, b) => a.localeCompare(b, 'es')).map(c => [c, c]);
  const picked = {};
  for (const [key, title, items] of [['company_ids', 'Empresas', units('company')], ['cities', 'Ciudades', cities], ['site_ids', 'Sedes', units('site')], ['area_ids', 'Áreas', units('area')]]) {
    const fs = el('fieldset', undefined, 'perm-group'); fs.append(el('legend', `${title} (sin marcar = todas)`)); filters.append(fs);
    if (items.length) picked[key] = checks(fs, items, current?.scope[key] || []);
    else { picked[key] = []; fs.append(el('p', `No hay ${title.toLowerCase()} creadas.`, 'help')); }
  }
  const line = el('p', '', 'help'); box.append(line);
  const read = () => {
    if (!role.value) return null;
    const scope = { kind: kind.value, company_ids: [], cities: [], site_ids: [], area_ids: [] };
    if (kind.value === 'filters') for (const key of Object.keys(picked)) scope[key] = picked[key].filter(c => c.checked).map(c => c.value);
    return { role_id: role.value, scope };
  };
  const valid = () => { const a = read(); return !!a && (a.scope.kind !== 'filters' || ['company_ids', 'cities', 'site_ids', 'area_ids'].some(k => a.scope[k].length)); };
  const sync = () => { filters.hidden = kind.value !== 'filters'; const a = read(); line.textContent = a ? scopeSummary(role.selectedOptions[0].textContent, a.scope, n) : 'Sin rol elegido: la persona conserva su acceso actual.'; };
  box.addEventListener('change', sync); sync();
  return { read, valid };
}
const SCOPE_INCOMPLETE = 'Marca al menos una empresa, ciudad, sede o área, o elige otro alcance.';
```

- [ ] **Step 5: `k3.js`, ficha de la persona (`people()`)**

1. Después de `const personPlans = ...`, agregar:

```js
  // Acceso al panel en la ficha (§5): lo edita quien puede dar accesos (superadmin o roles.gestionar).
  const myAccess = isSuper ? null : await optional(api('GET', '/me/access'));
  const canAssign = isSuper || !!myAccess?.permissions?.some(p => p.code === 'roles.gestionar');
  const roles = canAssign ? ((await optional(allPages('/roles'))) || []) : [];
```

2. En la tabla, antes de `['Estado', ...]`, agregar la columna `['Acceso', u => u.access ? scopeSummary(u.access.role_name, u.access.scope, n) : 'Sin acceso'],`.
3. En `editor`, después del bloque `planSel`:

```js
    const accessEd = u && canAssign && roles.length ? accessEditor(body, { roles, org, current: u.access }) : null;
```

4. En el submit, después de `if (missing.length) {...}`, agregar `if (accessEd?.read() && !accessEd.valid()) { show(SCOPE_INCOMPLETE); return; }`. Después del bloque del plan (antes de `list = u ? ...; dialog.close()`), agregar:

```js
        const wanted = accessEd?.read();
        const had = u?.access ? { role_id: u.access.role_id, scope: u.access.scope } : null;
        if (wanted && JSON.stringify(wanted) !== JSON.stringify(had)) {
          try { saved = await api('PUT', `/users/${u.id}/access`, { body: wanted, ifMatch: u.version }); list = list.map(x => x.id === u.id ? saved : x); u = saved; }
          catch (e) { show('Los datos se guardaron, pero el acceso no: ' + (e.status === 412 ? 'Alguien más la modificó: recarga la página.' : errorMessage(e))); ok.disabled = false; render(); return; }
        }
```

5. Cambiar la ayuda del select «Reporta a (jefe)» a `'Con esto el jefe puede ver a su equipo si su acceso tiene el alcance "Su equipo".'`.

- [ ] **Step 6: `k3.js`, Cuentas (`accounts()`)**

1. `companyData`: guardar `org: org || []` además de `n`: `.then(([roles, org]) => ({ roles: roles || [], org: org || [], n: names(org || []) }))`.
2. `rolesText` → renombrar a `accessText`:

```js
  const accessText = a => {
    if (a.kind === 'platform') return 'Todo, en todas las empresas';
    const own = a.access ? scopeSummary(a.access.role_name, a.access.scope, data.get(a.tenant_id)?.n || new Map()) : 'Sin acceso: no verá nada';
    return a.group_id ? `${own} · Grupo ${a.group_name}: también ${a.group_access.map(g => g.tenant_name).join(', ') || 'ninguna otra empresa aún'}` : own;
  };
```

   En la tabla, la columna pasa a `['Acceso', accessText]`. Cambiar los textos de ayuda «ven lo que permitan sus roles…» por «ven lo que permita su acceso: un rol y un alcance (todo, ciertas empresas, ciudades, sedes o áreas, su equipo o solo lo propio).»
3. En `actionsFor`:
   - `button('Roles', () => editRoles(a))` → `button('Acceso', () => editAccess(a))`;
   - el PATCH de activar/desactivar pasa a `{ active: !a.active }`.
4. Borrar `roleItems` y `editRoles`. Agregar:

```js
  const editAccess = async a => {
    const d = await companyData(a.tenant_id);
    const { dialog, body, ok, show } = dialogShell(`Acceso de ${a.user_name || a.email}`);
    dialog.classList.add('wide-dialog');
    const ed = accessEditor(body, { roles: d.roles, org: d.org, current: a.access });
    dialog.querySelector('form').addEventListener('submit', async ev => {
      ev.preventDefault();
      if (!ed.read()) { show('Elige el rol.'); return; }
      if (!ed.valid()) { show(SCOPE_INCOMPLETE); return; }
      ok.disabled = true;
      try { await api('PATCH', `/panel-accounts/${a.id}`, { tenant: a.tenant_id, body: { active: a.active, access: ed.read() } }); dialog.close(); await refresh(); }
      catch (e) { show(e.code === 'tenant_rbac_disabled' ? 'La empresa no tiene habilitada la gestión de sus propios roles: pídesela a la plataforma.' : e); ok.disabled = false; }
    });
    dialog.showModal();
  };
```

5. `groupSection`: reemplazar el `for (const tid ...)` y el `return`:

```js
    const picks = [];
    for (const tid of g.tenant_ids.filter(t => t !== home)) {
      const d = await companyData(tid);
      inner.append(el('h3', companyName(tid)));
      const given = current?.group_access?.find(x => x.tenant_id === tid) || null;
      const use = el('input'); use.type = 'checkbox'; use.checked = !!given;
      const ul = el('label', undefined, 'check'); ul.append(use, ' Tiene acceso en esta empresa'); inner.append(ul);
      const holder = el('div'); inner.append(holder);
      const ed = d.roles.length ? accessEditor(holder, { roles: d.roles, org: d.org, current: given, groupOnly: true }) : (holder.append(el('p', 'Esta empresa no tiene roles todavía.', 'help')), null);
      const syncUse = () => { holder.hidden = !use.checked; }; use.addEventListener('change', syncUse); syncUse();
      picks.push([tid, use, ed]);
    }
    const sync = () => { inner.hidden = !on.checked; }; on.addEventListener('change', sync); sync();
    return () => on.checked
      ? { group_id: g.id, group_access: picks.filter(([, use, ed]) => use.checked && ed?.read()).map(([tid, , ed]) => ({ tenant_id: tid, ...ed.read() })) }
      : { group_id: null, group_access: [] };
```

6. `editGroup`: el PATCH pasa a `{ active: a.active, ...read() }`.
7. `create()`:
   - en `drawCompany`, reemplazar `companyBox.append(el('p', 'Roles: …')); boxes = checks(companyBox, roleItems(d)); if (!d.roles.length) …` por

```js
      accessEd = d.roles.length ? accessEditor(companyBox, { roles: d.roles, org: d.org, current: null }) : null;
      if (!d.roles.length) companyBox.append(el('p', 'Aún no hay roles en esta empresa: créalos en Roles y permisos. Sin rol, la persona queda con su acceso actual.', 'help'));
```

   - declarar `let person = null, accessEd = null, readGroup = null;` (en lugar de `boxes = []`);
   - en el submit, antes de `ok.disabled = true`: `if (!owner && accessEd?.read() && !accessEd.valid()) { show(SCOPE_INCOMPLETE); return; }`;
   - los payloads pasan a

```js
        const payload = owner
          ? { kind: 'platform', email: email.value.trim(), display_name: display.value.trim(), user_id: null }
          : { kind: 'company', email: email.value.trim(), display_name: null, user_id: person.value, ...(accessEd?.read() ? { access: accessEd.read() } : {}), ...(readGroup ? readGroup() : {}) };
```

- [ ] **Step 7: `k3.js`, Roles (`rolesPage()`)**

1. Tabla: borrar la columna `['Alcance', r => scopeText(r, n)]`. Texto de ayuda: `'Un rol es un conjunto de permisos. Qué datos ve cada persona (su alcance) se elige en su ficha de Personas o en Cuentas del panel.'`
2. `editor`: borrar `kind`, `scopeBox`, `drawScope`, `scopeBoxes` y la validación de sedes/áreas. El payload pasa a `{ name: name.value.trim(), permissions: all.filter(b => b.checked).map(b => b.value), active: active.checked, assignable_by_rule: auto.checked }`. El mensaje del catch para 403 se deja a `errorMessage(e)` (`show(e)`): ya traduce `delegation_exceeds_own_access`, `scope_denied` y `feature_not_contracted`. Se conserva el caso `tenant_rbac_disabled`.
3. Editor de reglas: en el select de rol, `new Option(r.name, r.id)` (sin `scopeText`). En la ayuda de «Asignación automática», agregar al final: `' La regla propone el rol; el alcance de cada persona no cambia.'`.
4. `org` ya no se usa en `rolesPage`, salvo en el editor de reglas (cargos y áreas). Se queda.

- [ ] **Step 8: `AUDIT_FIELDS`, `api.js`, `app.js`, `roles.php`**

1. `k3.js` `AUDIT_FIELDS`: reemplazar `role_ids: 'roles'` por `access: 'acceso'` y `group_roles: 'roles en el grupo'` por `group_access: 'accesos en el grupo'`.
2. `api.js` `errorMessage`: reemplazar la línea de `feature` por

```js
  const detail = error?.problem?.detail || '';
  if (/^feature_not_contracted:/.test(detail)) message = 'La función no está contratada por el cliente.';
  if (detail === 'scope_denied') message = 'Tu alcance no incluye esa empresa, sede o área.';
  if (detail === 'delegation_exceeds_own_access') message = 'No puedes dar un acceso más amplio que el tuyo.';
```

3. `app.js:117`: `columns: [['Rol', 'name'], ['Permisos', r => r.permissions.join(', ') || 'Sin permisos'], ['Estado', r => r.active ? 'Activo' : 'Inactivo']]`.
4. `public/roles.php`: `$subtitle = 'Qué puede hacer cada rol. Qué datos ve cada persona se elige en su acceso.';`

- [ ] **Step 9: Verify**

Run:
- `node --check backend-v4/public/assets/k3.js && node --check backend-v4/public/assets/api.js && node --check backend-v4/public/assets/app.js && node --check backend-v4/public/assets/access-scope.js && php -l backend-v4/public/roles.php` → sin errores;
- `node --test backend-v4/tests/access-summary.test.mjs` → `# pass 5`;
- `grep -n "scopeText\|role_ids\|group_roles\|setUserRoles" backend-v4/public/assets/*.js` → sin líneas;
- suite completa → `SMOKE PASS`. Incluye `PASS ops`: guardias de `allPages` y de textos de auditoría.

Manual (agent-browser o navegador, contra `php -S` local con datos de la suite si se quiere): en Personas, editar una persona, elegir «Coordinador» + filtros con un área y ver la frase «Coordinador · Área: … · todas las sedes».

- [ ] **Step 10: Commit**

```bash
git add backend-v4/public backend-v4/tests/access-summary.test.mjs
git commit -m "Accesos: rol + alcance en la ficha de la persona y en Cuentas, con frase legible; Roles sin alcance

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Cierre — documentación, guardias y verificación final

**Files:**
- Modify: `backend-v4/ADMIN.md:11,24,26,28`
- Test: suite completa, `node --test`, export del contrato y generador de DTO.

**Interfaces:**
- Consumes: todo lo anterior.

- [ ] **Step 1: Documentación** — en `backend-v4/ADMIN.md`:

- Línea 11, tabla de Miembros: `PUT /users/{id}/roles` → `PUT /users/{id}/access`.
- Línea 24: reemplazar por

  > `x-permission` del contrato selecciona el permiso; los comandos usan la correspondencia exacta documentada por acción. `AdminAccess` lee en cada petición el único acceso de la persona (`access_grants`: 1 rol + 1 alcance) o, para una cuenta de grupo, su acceso en esa empresa. El atributo de plataforma está en `admin_accounts`: un nombre de rol nunca lo concede.

- Línea 26: reemplazar la frase de alcance por

  > Los listados y detalles de personas, equipos, actividad, reportes, ausencias, cobertura, señales de cumplimiento y logs aplican el alcance de la persona. Puede ser: todo; filtros Empresa/Ciudad/Sede/Área (O dentro de un filtro, Y entre filtros); su equipo; o solo lo propio. Productividad usa la asignación organizativa histórica.
  >
  > IDs fuera del alcance devuelven 404. Un filtro pedido, un objetivo de regla, una persona creada o movida fuera del alcance, o una pantalla de todo el cliente, devuelven 403 `scope_denied`. La API externa usa credenciales de todo el cliente y no tiene alcance de cuenta.

- Línea 28: reemplazar por

  > Autogestión de accesos: exige la compuerta de la empresa, la función `autogestion_accesos` y `roles.gestionar`. Plataforma puede operar con la compuerta apagada.
  >
  > Crear o editar roles exige alcance «todo». Un rol nunca incluye permisos de funciones no contratadas (`feature_not_contracted:<f>`). Dar un acceso valida que el rol no tenga permisos que el actor no tiene (ni `roles.gestionar`, ni no delegables) y que el alcance no sea más amplio que el propio. Así nadie amplía su propio acceso (`delegation_exceeds_own_access`).
  >
  > La asignación por cargo propone el rol y nunca toca el alcance. Cambiar un acceso invalida las sesiones de la persona.

- [ ] **Step 2: Verificación final**

Run, en orden:
1. `cd backend-v4 && python tests/export-contract.py && git diff --exit-code config/contract.json` → sin diferencias (el contrato ya estaba exportado).
2. `node client-v4/tools/generate.mjs && git status --short client-v4` → sin cambios.
3. `node --test backend-v4/tests/access-summary.test.mjs` → `# pass 5`.
4. Guardia de restos:

```bash
grep -n "user_roles\|admin_account_roles\|role_site_scopes\|role_area_scopes\|hasScopeKind\|allowsScope\|fromLegacy\|assignLegacy\|setUserRoles\|RoleBindings\|GroupRoleGrant\|RoleScope" backend-v4/src backend-v4/public docs/architecture/openapi-v4.yaml -r
```

   → sin líneas.
5. Suite completa → `SMOKE PASS: N assertions`. N debe ser mayor que 2506, sin `FAIL`, con las líneas `PASS access migration`, `PASS access scope`, `PASS access evaluation`, `PASS access cases`, `PASS access delegation` y `PASS access roles`.

- [ ] **Step 3: Commit**

```bash
git add backend-v4/ADMIN.md
git commit -m "Accesos: documentación del modelo 1 rol + 1 alcance

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Despliegue (lo hace el controlador, no un subagente)

- [ ] **Step 1:** respaldo de la base con `mariadb-dump`, y `chown -R keeper:keeper` del directorio del respaldo.
- [ ] **Step 2:** antes de migrar, guardar la foto de hoy para comparar:

```sql
SELECT HEX(ur.tenant_id), u.display_name, r.name, r.scope_kind FROM user_roles ur JOIN users u ON u.tenant_id=ur.tenant_id AND u.id=ur.user_id JOIN roles r ON r.tenant_id=ur.tenant_id AND r.id=ur.role_id ORDER BY 1,2;
SELECT HEX(admin_tenant_id), HEX(admin_id), HEX(tenant_id), HEX(role_id) FROM admin_account_roles;
```

- [ ] **Step 3:** probar `0043` sobre una copia en MariaDB:
  1. crear una base temporal en el servidor;
  2. cargar el respaldo;
  3. correr `config/migrate.php` contra ella con su DSN;
  4. revisar `SELECT * FROM access_migration_report`;
  5. borrar la base temporal.

  Si falla, parar y reportar. Es la única prueba en MariaDB: la suite corre en MySQL 8.4.
- [ ] **Step 4:** en la misma ventana, desplegar `migrations/0043_access_role_scope.sql` y correr `config/migrate.php`; enseguida, desplegar el código:
  - `config/contract.json`;
  - `src/AccessScope.php`, `src/AccessGrants.php`, `src/AdminAccess.php`, `src/AdminApi.php`, `src/ActivityReports.php`, `src/ExternalApi.php`, `src/DataSync.php`, `src/PanelAccounts.php`, `src/CompanyGroups.php`, `src/AdminAuth.php`, `src/TenantPurge.php`, `src/RoleRules.php`;
  - `public/assets/access-scope.js`, `public/assets/k3.js`, `public/assets/api.js`, `public/assets/app.js`, `public/roles.php`.

  Entre migración y código el panel viejo sigue leyendo `user_roles`: minimizar esa ventana.
- [ ] **Step 5:** verificar en producción.
  - Grupo AZC (`0x6E888B88B3EF4AC8ADAED2FCF12D6B12`): `SELECT u.display_name, r.name, g.scope_kind, g.source FROM access_grants g JOIN users u ON u.tenant_id=g.tenant_id AND u.id=g.user_id JOIN roles r ON r.tenant_id=g.tenant_id AND r.id=g.role_id WHERE g.tenant_id=0x6E888B88B3EF4AC8ADAED2FCF12D6B12;` debe coincidir con la foto del Step 2: el rol de mayor alcance de cada persona y el mismo alcance.
  - `access_migration_report`: reportar cada fila a Koichi. `lost_permissions` vacío = sin pérdida.
  - Roles: existe el rol «Corporativo» en Grupo AZC, BilingueLaw y LawyerDesk.
  - Entrar al panel con la cuenta de Grupo AZC: ve lo mismo que antes; la ficha de la persona muestra la frase del acceso; en el log no hay `failure=` nuevos.
- [ ] **Step 6:** registrar en memoria (persistencia dual) y en el ledger. Si se usó el grafo de graphify, anotar qué se consultó.

---

## Decisiones abiertas para Koichi

Cada una indica la opción recomendada, que es la que sigue este plan.

1. **Plantillas.**
   - Hoy hay Gerencia, Dirección, Coordinación, IT, RRHH, Admin Empresa y Colaborador. El diseño dice Corporativo, Gerente, Coordinador, IT y Colaborador.
   - **Recomiendo:**
     - renombrar las plantillas Gerencia → Gerente y Coordinación → Coordinador;
     - crear Corporativo (lectura de todo + editar empresa);
     - dejar de ofrecer Dirección, RRHH y Admin Empresa a clientes nuevos;
     - en clientes existentes no renombrar ni borrar roles: solo agregar Corporativo.
2. **Permisos de Corporativo.** **Recomiendo:** los de Gerencia + `actividad.titulos_ver`, `presencia.ubicacion_ver`, `festivos.ver`, `cumplimiento.ver`, `cobertura.ver` e `integraciones.ver` (lista exacta en `0043`). Sin permisos de escritura salvo `empresas.editar`.
3. **Dato fuera del alcance pedido por id.** El diseño dice `scope_denied`; hoy es 404. **Recomiendo:**
   - 404 en el detalle de una persona o equipo, para no revelar que existe;
   - `scope_denied` en filtros, objetivos de regla, ubicación de personas y pantallas de todo el cliente.
4. **«Hoy no hay ninguno» con varios roles es falso.** Toda persona creada recibió Colaborador automático, así que quien además tiene un rol de admin tiene dos. **Recomiendo:** conservar el de mayor alcance (todo > sede/área con más personas > equipo > propio) y reportar todos los casos en `access_migration_report`. Esos casos dan `lost_permissions` vacío.
5. **Cuentas de grupo.** **Recomiendo:** 1 rol + 1 alcance por cada otra empresa del grupo, solo «todo» o filtros. «Equipo» y «solo lo propio» no tienen persona allí. Las cuentas que hoy solo tienen ese tipo de rol quedan sin acceso en esa empresa y se reportan; hoy tampoco ven nada allí.
6. **Tablas viejas.** **Recomiendo:**
   - dejar `user_roles`, `admin_account_roles`, `role_*_scopes` y `roles.scope_kind` congeladas en esta fase, como red de reversa;
   - borrarlas en la fase 4, tras validar producción.
7. **Compuerta `rbac_self_management` frente a la función `autogestion_accesos`.** **Recomiendo** exigir ambas en esta fase (sin cambio de comportamiento) y unificarlas en la fase 4.
8. **¿Quién crea o edita roles?** **Recomiendo:**
   - solo cuentas con alcance «todo», porque los roles son de todo el cliente;
   - leer roles y la estructura (empresas, sedes, áreas) solo exige el permiso de lectura, porque un coordinador que delega necesita elegir rol y alcance.
9. **Regla por cargo.** **Recomiendo:**
   - asigna el rol solo a personas sin acceso manual (lo manual manda) y nunca toca el alcance;
   - cuando deja de cumplirse, vuelve Colaborador con el mismo alcance;
   - cambiar solo el alcance no le quita el rol a la regla.
10. **Delegación de filtros conservadora.** **Recomiendo:**
    - cada filtro que limita el delegador lo debe limitar también el delegado, a un subconjunto. Ejemplo: quien está limitado a «Ciudad: Cali» no puede dar «Sede: Tequendama» sin también «Ciudad: Cali»;
    - «equipo» solo lo da quien tiene «equipo», a alguien de su equipo;
    - «solo lo propio» se puede dar a cualquiera visible.
11. **Persona sin empresa** («todo el cliente», `company_id` NULL). **Recomiendo** que no entre en un filtro de Empresa.
12. **API externa.** **Recomiendo** dejarla sin alcance de cuenta: las credenciales son de todo el cliente. Se documenta y se prueba que ve todo.
13. **Quien tiene `roles.gestionar` no puede cambiar su propio acceso**, ni el de otra persona con `roles.gestionar`. **Recomiendo** mantenerlo así: es la regla de hoy y garantiza «nadie amplía su propio acceso». Esos cambios los hace superadmin.
14. **Mensaje de `feature_not_contracted`.** El panel hoy dice «La empresa no tiene contratada esta función (x). Pídela a AZC Keeper.». **Recomiendo** usar el texto del §7: «La función no está contratada por el cliente.».
