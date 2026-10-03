# Captura según lo contratado (fase 2b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que no se conserve, y desde la versión 4.0.15 del agente que tampoco se capture, información que la persona no tiene contratada, y que la función «Títulos de ventana» pase a ser lo que realmente es: «Sitios web visitados».

**Architecture:**
1. **Servidor:** al recibir datos de un equipo, `Ingest` consulta las funciones de su persona con `PersonPlans::functions` y actúa así:
   - Sin `productividad`, acusa recibo de los episodios de ventanas pero no los guarda.
   - Sin `sitios`, no deriva el sitio del título.
   - Sin `productividad` ni `presencia`, no guarda el resumen de actividad.
2. **Política:** desde el agente 4.0.15 la política lleva `capture: {windows, window_titles, activity}`. A agentes anteriores no se les envía, porque rechazan campos desconocidos.
3. **Agente 4.0.15:** `WindowTracker` y el envío del resumen de actividad respetan `capture`. Si la política no trae `capture`, el agente captura todo, como hoy.

**Tech Stack:** PHP 8.3 (backend-v4), MySQL 8.4 / MariaDB 11.4, OpenAPI → `config/contract.json` y DTO C# (`client-v4/tools/generate.mjs`), agente C# .NET 8 (`client-v4`).

## Global Constraints

- **Decisiones de Koichi 2026-10-03:**
  - servidor **y** agente nuevo;
  - «Títulos de ventana» → «Sitios web visitados» (código `sitios`).
- **Hechos verificados:**
  - El servidor nunca guarda `window_title`: `Ingest.php:95` inserta `NULL` y solo usa el título para derivar `site` en procesos de navegador (`Ingest::site`).
  - El agente no captura ubicación.
  - El agente rechaza campos desconocidos en la política (`ProtocolJson.cs`, `UnmappedMemberHandling.Disallow`).
- **Política a agentes viejos:** todo campo nuevo se envía solo si `agent_version >= 4.0.15`, con el mismo patrón que `version_compare(..., '4.0.13', '>=')` de `PolicyCompiler.php:~136`.
- **Acuse de recibo:** el servidor debe acusar recibo como siempre de lo que descarta (episodio aceptado o snapshot aceptado), para que el agente no reintente indefinidamente.
- **Superadmin:** nunca queda bloqueado en la API. Esto no aplica a la captura: lo que se captura depende de la persona dueña del equipo.
- **Contrato y DTO:** tras editar `openapi-v4.yaml`, correr desde `backend-v4` `python tests/export-contract.py`, y desde la raíz del repositorio `node client-v4/tools/generate.mjs`. El YAML no debe tener descripciones con `: `.
- **Suite del servidor:** desde `backend-v4`, debe terminar en `SMOKE PASS`; base actual 2400.

  ```
  export PATH="$(ls -d /c/laragon/bin/mysql/mysql-8.4.3*)/bin:$(ls -d /c/laragon/bin/php/php-8.3.30*):$PATH" && powershell -NoProfile -File tests/mysql-smoke.ps1 -DebugResponses > "$SCRATCH/smoke.log" 2>&1; grep -n "FAIL\|SMOKE PASS" "$SCRATCH/smoke.log"
  ```

- **Suite del agente:** `dotnet test client-v4/Keeper.sln`. Luego restaurar los `packages.lock.json` modificados con `git checkout -- '**/packages.lock.json'`, como en sesiones anteriores.
- **Commits:** terminan con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Prohibido en las tareas 1-4:** deploy, ssh, push y publicar releases.

---

### Task 1: «Títulos de ventana» pasa a «Sitios web visitados» (`sitios`)

**Files:**
- Create: `backend-v4/migrations/0042_feature_sitios.sql`
- Modify: `backend-v4/src/Features.php` (clave `titulos` → `sitios`, nombre «Sitios web visitados»), `backend-v4/src/ExternalApi.php` (verificación de `include_titles` → `sitios`)
- Modify: tests que usan `'titulos'` (grep en `backend-v4/tests`) y la lista de 13 funciones de `0041`, que no se edita porque es historia: la migración nueva actualiza los datos.

- [ ] **Step 1: Test (RED)**

En `tests/features.php`, dentro de `featureCatalogTests`, agregar:

```php
    check(isset(Features::CATALOG['sitios']) && !isset(Features::CATALOG['titulos']) && Features::CATALOG['sitios']['name'] === 'Sitios web visitados', 'titles function is now visited websites');
```

Además, ajustar toda aserción existente que espere `'titulos'` para que espere `'sitios'`. Por ejemplo, la prueba externa con `include_titles` pasa a esperar `feature_not_contracted:sitios`.

Correr la suite. Debe fallar en `titles function is now visited websites`.

- [ ] **Step 2: Implementación**

`backend-v4/migrations/0042_feature_sitios.sql`:

```sql
-- "Titulos de ventana" vendia algo que el servidor no guarda (Ingest inserta window_title NULL); lo que existe es el
-- sitio web que se deriva del titulo del navegador. La funcion pasa a ser "Sitios web visitados" (codigo sitios).
UPDATE tenant_features SET feature_code = 'sitios' WHERE feature_code = 'titulos';
UPDATE feature_package_items SET feature_code = 'sitios' WHERE feature_code = 'titulos';
UPDATE person_plan_items SET feature_code = 'sitios' WHERE feature_code = 'titulos';
```

En `Features.php` reemplazar la entrada `'titulos' => [...]` por:

```php
        'sitios' => ['name' => 'Sitios web visitados', 'group' => 'Monitoreo y productividad', 'level' => 'person', 'ops' => [], 'permissions' => ['actividad.titulos_ver'], 'rules' => [], 'pending' => false],
```

Mantener la misma posición en el arreglo, porque el orden importa para la UI. En `ExternalApi.php`, cambiar `'titulos'` por `'sitios'` en la verificación de `include_titles`.

- [ ] **Step 3: GREEN.** Correr la suite completa: debe dar `SMOKE PASS`.
- [ ] **Step 4: Commit.** Mensaje: `Funciones: «Títulos de ventana» pasa a «Sitios web visitados» (sitios)`.

---

### Task 2: El servidor no guarda lo que la persona no tiene contratado

**Files:**
- Modify: `backend-v4/src/Ingest.php` (`episode()` ~77-95, `site()` ~70-76, `activity()` ~180-236)
- Test: `backend-v4/tests/plans.php`, nueva función `captureIngestTests(Database $db)` registrada en `smoke.php` después de la última prueba de planes

**Interfaces:**
- Consumes: `PersonPlans::functions(Database $db, string $tenant, string $user): list<string>`. En `Ingest`, `$this->c` trae `tenant_id` y `user_id` en binario. Confirmarlo leyendo el constructor.

- [ ] **Step 1: Test (RED)**

Escribir `captureIngestTests`. Para enviar episodios y snapshots de un equipo, reutilizar los helpers y la forma de `tests/smoke.php:~193-259`: leer cómo autentican un dispositivo y llaman `/client/episodes:batch` y `/client/sync`. Casos, cada uno con un `check` de nombre claro:

- (a) La persona tiene todo (plan «Completo» por defecto): un episodio de un navegador con un título que coincide con un `app_sites.pattern` se guarda y su `site` no es NULL.
- (b) Sin `sitios`: se quita `sitios` del cliente con `DELETE FROM tenant_features ...`. Un episodio igual se guarda con `site` NULL.
- (c) Sin `productividad`: se quita. Un episodio nuevo es aceptado en la respuesta, igual que antes, pero **no** queda fila en `episodes`.
- (d) Sin `productividad` ni `presencia`: un snapshot de actividad es aceptado en la respuesta, pero no queda fila nueva en `activity_snapshots`.
- (e) Con `presencia` sí y `productividad` no: el snapshot **sí** se guarda.

- [ ] **Step 2: Implementación**

En `Ingest`:
- Agregar un método privado `functions(): array` que guarde en caché por request el resultado de `PersonPlans::functions($this->db, $this->c['tenant_id'], $this->c['user_id'])`.
- En `episode()`: si `!in_array('productividad', $this->functions(), true)`, devolver el mismo resultado de «aceptado» que hoy devuelve un episodio guardado, sin insertar. Leer qué devuelve hoy el camino feliz para replicarlo.
- En el cálculo del sitio: llamar a `site()` solo si `in_array('sitios', $this->functions(), true)`; si no, `site` es NULL.
- En `activity()`: si la persona no tiene `productividad` ni `presencia`, acusar recibo igual que un snapshot aceptado, sin insertar.
- Agregar comentarios cortos en español, en el estilo del archivo.

- [ ] **Step 3: GREEN.** Correr la suite completa.
- [ ] **Step 4: Commit.** Mensaje: `Captura: el servidor no guarda programas, sitios ni actividad que la persona no tiene contratados`.

---

### Task 3: La política le dice al agente 4.0.15 qué capturar

**Files:**
- Modify: `docs/architecture/openapi-v4.yaml`, en el esquema `EffectivePolicy`. Agregar la propiedad opcional `capture`, un objeto con `additionalProperties: false` y requeridos `windows`, `window_titles` y `activity`, los tres boolean.
- Modify: `backend-v4/src/PolicyCompiler.php` (snapshot) y `backend-v4/src/PolicyComposer.php` (documento)
- Generated: `backend-v4/config/contract.json` y `client-v4/src/Keeper.Shared/Policy/Policy.g.cs`
- Test: `backend-v4/tests/plans.php`, nueva función `captureFlagsTests`

**Interfaces:**
- Produces:
  - La entrada del compositor acepta la clave opcional `'capture' => ['windows'=>bool,'window_titles'=>bool,'activity'=>bool] | null`.
  - Si la clave no es null, el documento compuesto incluye `capture` con esos valores.
- La regla de cálculo, en el compilador, con `$functions` de la persona:
  - `windows = productividad`;
  - `window_titles = productividad && sitios`;
  - `activity = productividad || presencia`.
- Solo se calcula si `$device === null` o `version_compare(agent_version, '4.0.15', '>=')`; en otro caso es `null`.

- [ ] **Step 1: Test (RED)**

En `captureFlagsTests`:
- Componer directamente con `PolicyComposer::compose(...)` usando el input mínimo de `planCompileTests`, más `'capture' => ['windows'=>false,'window_titles'=>false,'activity'=>true]`. Comprobar que `$doc->capture->windows === false` y `$doc->capture->activity === true`.
- Con `'capture' => null`, comprobar que el documento **no** tiene la propiedad `capture`.
- Con el compilador real y dos dispositivos, comprobar que el documento compilado:
  - para el de `agent_version` `'4.0.14'`, no trae `capture`;
  - para el de `'4.0.15'`, sí lo trae, con `windows=true`, porque el fixture tiene «Completo».
- Para fijar `agent_version`, leer cómo los tests existentes crean dispositivos y compilan (`tests/policy-compiler.php`).

- [ ] **Step 2: Implementación**

- **Esquema:** editar el YAML y regenerar `contract.json` y `Policy.g.cs`. Verificar con `git diff client-v4/src/Keeper.Shared/Policy/Policy.g.cs` que aparece el record de `capture`, y que `Capture` es nullable en `EffectivePolicy` por no ser requerido.
- **Compilador:** calcular `$capture` como dice Interfaces y devolverlo en el snapshot como `'capture' => $capture`.
- **Compositor:** si `($input['capture'] ?? null) !== null`, agregar `capture` al documento antes de validarlo y versionarlo, como objeto con los tres booleanos.
  - El hash o versión de la política debe cambiar cuando cambia `capture`, para que el agente reciba la actualización. Verificar cómo se calcula la versión: si hashea el documento, queda cubierto.

- [ ] **Step 3: GREEN.** Correr la suite completa del servidor y `dotnet build client-v4/Keeper.sln`; el DTO regenerado debe compilar.
- [ ] **Step 4: Commit.** Mensaje: `Captura: la política indica al agente 4.0.15 qué capturar según el plan`.

---

### Task 4: El agente 4.0.15 respeta `capture`

**Files:**
- Modify: `client-v4/src/Keeper.Session/Modules/Windows/WindowTracker.cs`, y donde se arma el envío del resumen de actividad. Buscarlo a partir de `ActivityTracker.cs` y de cómo llega `activity` a `/client/sync`.
- Test: `client-v4/tests/Keeper.Agent.Tests`, junto a `TrackingTests.cs` o `PolicyTests.cs`

**Interfaces:**
- Consumes: `EffectivePolicy.Capture` (nullable), regenerado en la Task 3.
  - `Capture == null` significa capturar todo: servidor viejo o agente sin restricción.

- [ ] **Step 1: Test (RED)**

Agregar pruebas unitarias:
- (a) Con una política sin `capture`, `WindowTracker` encola episodios con título, como hoy.
- (b) Con `capture.windows=false`, no encola episodios.
- (c) Con `capture.windows=true` y `window_titles=false`, encola episodios con `WindowTitle` nulo o vacío, conservando `ProcessName`.
- (d) Con `capture.activity=false`, el resumen de actividad no se envía al servidor. Probar en la capa que arma el envío. Si no hay una costura testeable, extraer un método puro pequeño que decida qué enviar y probarlo.

Usar los fakes que ya usan `TrackingTests.cs` y `PolicyTests.cs`.

- [ ] **Step 2: Implementación**

- `WindowTracker` guarda la `Capture` vigente en `ApplyPolicyAsync`, siguiendo el patrón de `ActivityTracker.ApplyPolicyAsync`, y la respeta al cerrar un episodio.
- El envío del resumen de actividad respeta `Capture.Activity`.
- `CallDetector` puede seguir leyendo títulos localmente para detectar llamadas: no sale del equipo.

- [ ] **Step 3: GREEN**

Correr `dotnet test client-v4/Keeper.sln`. Si `dotnet` modifica los `packages.lock.json`, restaurarlos con `git checkout -- '**/packages.lock.json'`.

- [ ] **Step 4: Commit.** Mensaje: `Agente: respeta la captura indicada por la política (programas, títulos, actividad)`.

---

### Task 5: Despliegue y release 4.0.15 (controlador y Koichi)

- [ ] **1. Servidor:**
  - respaldo de la base;
  - migración 0042 primero, porque renombra las funciones;
  - después el código:
    - `src/Features.php`, `src/ExternalApi.php`, `src/Ingest.php`, `src/PolicyCompiler.php`, `src/PolicyComposer.php`;
    - `config/contract.json`.

  Verificar que ya no quedan filas `titulos` en las 3 tablas y que no hay `failure=` nuevos. A los agentes ≤4.0.14 no se les envía `capture`, así que no se rompen.
- [ ] **2. Release 4.0.15:**
  - `client-v4/build-installer.ps1 -Version 4.0.15 -Sequence 16 -Layout Shared`, más las llaves de release (`C:\Users\FumiWork\Documents\AZCKeeper-release-keys`) y `-ArtifactUrl https://devkeep.azclegal.com/releases/AZCKeeper_v4_bootstrap_4.0.15.zip`;
  - subir el zip a `public_html/public/releases/`.
- [ ] **3. Koichi registra la versión:** `bash client-v4/tools/register-release.sh client-v4/artifacts/release-4.0.15.json`. Pide sus credenciales de superadmin.
- [ ] **4. Verificación:** en un equipo con 4.0.15, comprobar que la política recibida trae `capture`. Luego quitar `productividad` a un cliente de prueba y verificar que el equipo deja de enviar episodios.
- [ ] **5.** Memoria y ledger.
