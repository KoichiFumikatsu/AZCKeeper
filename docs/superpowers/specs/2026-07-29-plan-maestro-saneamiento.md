# Plan maestro — Saneamiento de AZCKeeper (cliente, API y panel)

**Fecha:** 2026-07-29
**Rama:** `feature/modulo-seguridad`
**Producción vigente:** 3.0.3.2 · **Objetivo:** 4.0.0.0 con la base sana
**Estado:** diagnóstico completo, plan propuesto, pendiente de aprobación

Este documento consolida tres auditorías (acoplamiento, panel web, independencia de módulos) y propone el
orden de trabajo hasta 4.0.0.0. No sustituye al spec del Módulo de Seguridad
(`2026-07-29-modulo-seguridad-design.md`); lo precede.

---

## 0. Decisiones de encuadre (Koichi, 2026-07-29)

Estas cuatro decisiones cambian el alcance y el orden del resto del documento. Se tomaron después de
escribir el diagnóstico, así que **prevalecen sobre cualquier cosa que las contradiga más abajo.**

**0.1 — Keeper 4 arranca con datos desde cero. El 3 pasa a ser historial.**
No hay que preservar los datos actuales. Consecuencia mayor: **el saneamiento incluye el esquema.** Ya no
hay que arrastrar los defectos estructurales de la base; se corrigen en el diseño, sin migración dolorosa:

- `keeper_user_assignments`: un solo contrato de IDs, en vez del espacio ambiguo que hoy funciona por
  coincidencia del seed (§6.1). Termina el riesgo de clasificar a la persona equivocada.
- `keeper_window_episode`: índices correctos desde el diseño (ver 0.4 — es el sustrato del entregable
  principal).
- `keeper_activity_day`: replantear el `GREATEST` que es un trinquete monótono y que amplificó el doble
  seed hasta hacerlo imborrable.
- `keeper_focus_daily`: columna de cobertura, para que "no medido" no pueda disfrazarse de "medido con
  buen resultado" (§3.3).
- Borrar de raíz las tablas muertas: `keeper_module_catalog`, `keeper_device_locks`, `keeper_events`,
  `keeper_daily_metrics`, `keeper_handshake_log`.

Pendiente de definir: cómo queda el 3 como historial — base congelada de solo lectura, o export a un
archivo consultable.

**0.2 — El índice y el alcance de la corrupción quedan diferidos.** No se investiga el volumen del daño
ni se corre `SHOW INDEX FROM keeper_user_assignments` por ahora: si los datos parten de cero, el alcance
histórico es irrelevante y el índice se define en el esquema nuevo.

**0.3 — La Fase A se parte en dos, y solo la mitad sigue urgente.**
Partir de cero despriorizada los arreglos de datos: no tiene sentido detener una corrupción de datos que
igual se van a borrar. Queda así:

| Sigue urgente (independiente de los datos) | Se absorbe en el rediseño del esquema |
|---|---|
| A1 — PIN sin validar en `tryUnlock` | A2 — doble seed |
| A5 — `requireModule` + `canViewUser` en `users.php` | A3 — focus fabricado |
| A6 — `canDo` y scope en `policies.php` | A4 — `leisure_apps` vacío |
| A7 — SSRF de `organization.php` | A8 — índice de `day_date` |

**0.4 — Prioridad de producto #1: la vista de "qué hace cada persona".**
El entregable que más importa es el visor de procesos y ventanas por empleado, integrado en el panel:
buscador, filtros por franja horaria, proceso, usuario, equipo y llamada, estadísticas, orden, paginación
y export CSV.

Los requisitos ya están validados: el visor standalone entregado el 2026-07-10 se usó sobre 13.493
episodios reales. No hay que descubrir la funcionalidad, hay que construirla sobre un esquema que la
aguante.

**Esta prioridad reordena el plan:** la vista se alimenta de `keeper_window_episode`, la tabla peor
diseñada del sistema, hoy servida por `user-dashboard.php:319` sin `LIMIT` (~7.800 filas materializadas y
embebidas dos veces). El rediseño del esquema de esa tabla **no es un trabajo paralelo: es el cimiento
del entregable principal.** Se diseñan juntos.

**Requisito de diseño derivado (no opcional):** esta vista es la que más expone `window_title`, el campo
que puede contener nombres de casos, contrapartes y clientes de las firmas — el frente de secreto
profesional del §11.1 del spec del módulo. El diseño debe resolver tres cosas explícitamente: quién puede
ver títulos completos, si se enmascaran para roles no autorizados, y **auditar el acceso a la vista**
(quién consultó la actividad de quién). Lo último no es burocracia: es lo que protege al responsable de TI.

**0.5 — El sistema es multi-tenant por diseño: importa usuarios desde la BD del cliente.**
Dato aportado por Koichi que no estaba en el diagnóstico. Existe un mecanismo parametrizable para que un
cliente entregue sus usuarios sin capturarlos a mano: `keeper_data_sources` (host, puerto, base, usuario,
contraseña cifrada, `source_type`) resuelto por `Db::sourceFor(int $firmaId)` (`Db.php:204-232`), con
credenciales cifradas en AES-256-CBC usando `APP_KEY` del `.env` (`Db.php:235-273`), UI en
`organization.php` y probador de conexión en `organization.php:186-213`.

**Estado real: construido pero NO cableado.** `sourceFor()` no tiene ningún consumidor; solo se menciona
en un comentario de `LegacySyncService.php:19`. Ventaja para Keeper 4: se completa bien desde el diseño en
vez de parchear un flujo a medias.

Cuatro consecuencias:

1. **Reencuadre de A7.** El `test_connection` no es un bug accidental: es el probador de esta feature y la
   capacidad es necesaria. **No se elimina, se rediseña**: POST en vez de GET, credenciales fuera del
   query string, y bloqueo de rangos internos o lista blanca.

2. **La traducción de IDs pasa de "corrección" a requisito estructural (eleva §6.1).** Si cada cliente
   trae sus propios `area_id`, `cargo_id` y `firma_id` desde su propia base, esos espacios de
   identificadores **colisionan entre clientes por definición**. Guardar el ID externo sin traducir
   funciona con un solo tenant y por coincidencia; con dos clientes es incorrecto desde el primer día. El
   esquema nuevo necesita correspondencia explícita **origen + identificador externo → identidad interna**.
   Ya existe a medias: las columnas `legacy_*_id` de `keeper_areas`/`keeper_cargos` son ese mapeo para un
   solo tenant. Generalizarlas a `source_id + external_id` es la evolución natural.

3. **Custodia de credenciales de terceros.** `Db.php` mezcla hoy conexión, parseo de `.env`, criptografía
   y dominio multi-tenant. Guardar credenciales de bases de datos de clientes merece pieza propia, con
   rotación de llave contemplada. Verificado: `APP_KEY` vive en el `.env`, que está protegido por 403, y
   **`web.zip` (público) NO contiene `.env` ni dumps ni claves** — 76 archivos de código, y `config.php`
   son 29 líneas sin valores embebidos. Las credenciales de clientes **no** están comprometidas; el riesgo
   de `web.zip` se limita a exposición de código fuente.

4. **El esquema nuevo se diseña multi-tenant desde el principio**, no como parche, y el import desde
   fuente externa es un flujo de primera clase con su propia auditoría (qué se importó, desde dónde,
   cuándo, y qué se creó o actualizó).

---

## 1. El requisito que gobierna todo

En palabras de Koichi:

> "la idea inicial era que si desconectaba un módulo, no iba a dañar los demás módulos"

**Ese es el criterio de aceptación, y hoy no se cumple.** No porque el cliente falle —no falla— sino
porque el toggle no desconecta de verdad y porque el dato ausente se convierte, aguas arriba, en un
número que parece válido. El sistema **falla de forma semántica, no técnica**, y por eso nadie lo había
visto: nada lanza excepciones, nada divide por cero.

El §7 convierte ese criterio en pruebas verificables. Sin esas pruebas, el criterio es una intención.

---

## 2. Los ocho síntomas y sus causas

Koichi reportó ocho síntomas. No son ocho problemas: son cuatro causas con dos caras cada una.

### Cliente

| Síntoma | Causa raíz verificada |
|---|---|
| "Arreglar algo rompe otra cosa" | Estado estático mutable compartido: `LocalLogger` (22 campos estáticos + `HttpClient` propio), `TimeSync` (`_offsetSeconds` sin lock, escrito por Network, leído por Tracking para calcular deltas) |
| "No se puede apagar un módulo" | `InitializeModules()` corre una sola vez antes del primer handshake; el bloque de módulos del handshake solo muta `config.json` (`CoreService.cs:655-684`). **El toggle exige reinicio y el panel no lo dice** |
| "No se sabe si algo funciona" | No hay eco del estado observado. El panel muestra la *intención* (`policy_json`), no el estado real |
| "Cuesta agregar algo nuevo" | `PerformHandshake` (299 líneas) aplica siete módulos en un único `try`; no hay punto de extensión |

### Panel

| Síntoma | Causa raíz verificada |
|---|---|
| "Información dispersa" | `sedes-dashboard.php` es `index.php` agrupado por sede, con las mismas 6 consultas reimplementadas. Solo 16 enlaces internos en 22 páginas |
| "Números que no cuadran" | **4 fórmulas de Focus Score en 2 escalas** (0-10 y 0-100); `user-dashboard.php` muestra las dos en la misma pantalla. **"Online" son 2 min en 5 páginas y 15 min en el card del dashboard** |
| "Cosas que no encuentro" | El registro de módulos vive en **5 lugares que no coinciden**; `install_coverage` y `logs` son **inconcedibles a cualquier rol** |
| "Lento o se cuelga" | Ningún índice empieza por `day_date`: `index.php` hace **3 escaneos completos de 6,9 M filas** por carga |

---

## 3. Hallazgos que exigen acción inmediata

Independientes del plan. Están activos en producción.

### 3.1 `DeviceLock::tryUnlock` no valida el PIN — CRÍTICO

`Web/src/Endpoints/DeviceLock.php:65-87` devuelve `{ok:true, unlocked:true}` incondicionalmente, sin
comprobar el PIN y sin persistir nada. El desbloqueo del candado de dispositivo acepta cualquier entrada.

### 3.2 Doble seed de actividad: corrupción compuesta e imborrable — CRÍTICO

`CoreService.Start()` invoca `TryResumeTodayActivityFromServer()` dos veces: en `:146` y desde el
handshake en `:717-721`. Es `async void`, cede en el `await`, y ambas resoluciones llaman `SeedDayTotals`
→ `_currentDayActiveSeconds += seed` (`ActivityTracker.cs:162-170`) **sin guard de idempotencia**.
`ActivityDay.php:118-131` usa `GREATEST(active_seconds, VALUES(active_seconds))`: trinquete monótono que
nunca baja. Cada reinicio re-siembra el valor ya inflado y lo duplica otra vez. La ruta de login tiene el
mismo patrón (`:272` + `:275`).

**Los datos de actividad de la flota son sospechosos hasta que esto se corrija.** Es también el arreglo
más pequeño de los tres.

### 3.3 `focus_score = 55` fabricado desde una tabla vacía — CRÍTICO

Con `keeper_activity_day` poblado y `keeper_window_episode` vacío (WindowTracking apagado),
`ProductivityCalculator` da exactamente 55: switches sin datos → 100 (`:428`), distracción sin datos →
100 (`:434-435`), puntualidad sin datos → 100 (`:438-440`), deep work 0, constancia 0. Con pesos
20/25/20/15/20 sale 55. `productivity_pct` queda en 88-98%.

**El equipo sin monitoreo es el mejor de la flota** y sube en el ranking (`ProductivityRepo.php:97`
ordena por `avg_focus`, con `HAVING days_tracked > 0` que no ayuda porque las filas sí existen, solo
están huecas). En ámbar creíble, sin ningún marcador.

Amplificador independiente: `leisure_apps` está vacío y **ninguna migración lo puebla**
(`add_productivity_focus.sql:115-118` solo siembra dos flags), así que `:311` devuelve distracción 0. Una
instalación nueva muestra ~90% y focus 55 en toda la flota **con todos los módulos encendidos**.

### 3.4 Riesgos de autorización en el panel

- **`users.php` no llama `requireModule`** — única página de datos sin guard, y la que expone cédulas.
  Sus dos AJAX corren antes de cualquier chequeo: `?ajax=get_user&id=N` devuelve cédula y correo de
  cualquier usuario sin verificar scope (IDOR que cruza firmas); `?ajax=search_employee` enumera la BD
  legacy con tres caracteres.
- **`policies.php` no invoca `canDo` ni una vez.** Declara cinco permisos, incluido `can_force_push`, y
  no evalúa ninguno. Cualquier rol con el módulo reescribe la política global de 251 equipos y hace force
  push a la flota. El POST además ignora el scope.
- **`organization.php:186-213`** abre un `PDO` a cualquier host y puerto recibidos por GET y devuelve el
  error de conexión: SSRF con oráculo, y la contraseña viaja en el query string (queda en el access log
  del hosting y en el historial del navegador).

---

## 4. Fase A — Urgencias (antes de cualquier otra cosa)

No dependen del diseño y reducen daño activo.

| # | Cambio | Archivo |
|---|---|---|
| A1 | Validar el PIN en `tryUnlock` y persistir el resultado | `DeviceLock.php:65-87` |
| A2 | Guard de idempotencia en el seed: una sola aplicación por día y por arranque | `CoreService.cs:146,272`, `ActivityTracker.cs:156` |
| A3 | No escribir fila en `keeper_focus_daily` sin episodios, o marcarla con columna de cobertura | `ProductivityCalculator.php` |
| A4 | Poblar `leisure_apps` con un set inicial, o mostrar aviso explícito si está vacío | migración nueva |
| A5 | `requireModule('users')` + `canViewUser` en los dos AJAX | `users.php:11,19,54` |
| A6 | Aplicar los cinco `canDo` declarados + scope en el POST | `policies.php` |
| A7 | `test_connection` a POST con lista blanca de hosts | `organization.php:186-213` |
| A8 | Índice `(day_date, process_name, duration_seconds)` | `keeper_window_episode` |

**A2 y A3 requieren decidir qué hacer con los datos históricos ya corruptos.** Es una decisión de
negocio, no técnica: se documenta como zona de datos no confiables, o se purga y se recalcula.

---

## 5. Fase B — Núcleo del cliente

Convierte el criterio de desconexión en algo cierto por construcción.

### B1. Lifecycle explícito de módulos

```csharp
interface IKeeperModule {
    string Code { get; }
    void Configure(object cfg);
    void Start();
    void Stop();
}
```

`InitializeModules()` pasa a ser un bucle sobre un registro, y el handshake puede hacer `Start()`/`Stop()`
**en caliente**. Elimina de raíz el "solo-reinicio" y el congelamiento de valores en closures
(`CoreService.cs:913,958,1008`).

### B2. `try/catch` por módulo en la aplicación del handshake

Hoy son siete módulos en un `try` único (`:455-752`). Un `_configManager.Save()` que lance en `:554`
descarta WebBlocking, Updates, Logging, Modules y Timers de ese ciclo, en silencio. El orden importa y no
está declarado en ninguna parte.

### B3. Backoff por módulo; handshake exento del breaker compartido

Hay **una sola** instancia de `NetworkBackoffPolicy` (`ApiClient.cs:42`) y todo pasa por
`SendViaBackoffAsync`. Un 429 provocado por window-episodes suprime el handshake hasta 1800 s. **El plano
de control tiene que sobrevivir al fallo de cualquier módulo, o el kill switch no sirve.**

### B4. Adoptar el patrón de callbacks que ya funciona

`Tracking/` es el único sitio donde la independencia es real: los trackers exponen callbacks
(`ActivityTracker.cs:100,103`, `WindowsTracker.cs:70,75`) que Core cablea, y no conocen a nadie. Por eso
apagarlos no deja referencias colgantes. Deben adoptarlo, en orden:

1. **`KeyBlocker`** — recibe `ApiClient` (`:33-35`) y postea a mano a `client/device-lock/unlock`
   (`:568`). Debe exponer `Func<string,Task<bool>> OnUnlockAttempt`.
2. **`UpdateManager`** — recibe `ConfigManager` y `ApiClient`.
3. **`WebBlockingManager`** — su firma pública usa `ConfigManager.WebBlockingConfig`.
4. **Reporte de seguridad** — hoy inline en `CoreService` sin flag propio.

### B5. Capa de contratos real

`Contracts/` existe con **una** clase `internal`. Faltan 21 DTOs de `ApiClient` y 9 de `ConfigManager`.
Debe promoverse a **proyecto compartido** con tipos `public`: es prerequisito de `AZCKeeperAgent`, que
será un proyecto separado (como `AZCKeeperUpdater`, con cero referencias al cliente).

### B6. Eco del estado observado

El cliente reporta en cada handshake qué módulos tiene **realmente corriendo**, y el backend lo persiste
por dispositivo. Sin esto el panel no puede distinguir *cero porque está apagado* de *cero porque no
trabajó*. El patrón ya está construido y probado: es el reporte de `keeper_security_state` con su latido
de 24 h. Generalizarlo es el cambio de mayor retorno de esta fase.

### B7. Limpieza

`enableProcessTracking` es un checkbox que no hace nada (viaja panel→handshake→config y no se lee en
ningún sitio): implementarlo o borrarlo. `Core/MasterTimer.cs` es un diseño de tick unificado con cero
usos mientras cada módulo lleva su propio timer. `modulesConfig == null` deja el cliente **inconsistente,
no inerte**: `NullReferenceException` cada 5 minutos y un equipo que se reporta vivo sin recolectar nada.

---

## 6. Fase C — API y backend

| # | Cambio | Por qué |
|---|---|---|
| C1 | `admin_id` en `keeper_audit_log` + `AuditRepo::log(..., ?int)` | Hoy la tabla no tiene actor: puede decir a quién, nunca quién. **Invalida la bitácora del Módulo de Seguridad** |
| C2 | Corregir `$adminUser['id']` → `admin_id` en 7 sitios | La clave no existe; `updated_by` se guarda NULL en cada cambio de rol y de visibilidad |
| C3 | `PolicyWriteRepo` en `src/Repos/` + invalidar la caché de `PolicyRepo` al escribir | El versionado está copiado **4 veces**; `PolicyRepo` solo sabe leer. La caché de 60 s no se invalida: tras aplicar el candado los equipos reciben la política vieja hasta un minuto, indistinguible de "el servicio no aplicó" |
| C4 | Resolver el espacio de IDs de `keeper_user_assignments` | Dos contratos contradictorios; funciona **por coincidencia del seed**. Ver §6.1 |
| C5 | Auditar las acciones destructivas (hoy 21 de 22 páginas no auditan) | Borrado de usuario, de equipo, de cuenta admin, cambios de rol y de política: sin rastro |
| C6 | Transacciones donde faltan | `beginTransaction` aparece en **una** página. `organization.php:150-162` desvincula y borra sin transacción |
| C7 | Borrar código muerto | `HandshakeRepo`, `LegacyAuthRepo`, `EventIngest` (501), `keeper_module_catalog` (tabla que ningún PHP lee), `keeper_device_locks` (huérfana) |
| C8 | Throttle global (no por sesión) de `LegacySyncService` | Corre en **cada carga de página** del panel; el throttle es por sesión, así que N admins ⇒ N ejecuciones |

### 6.1 El riesgo más peligroso para el Módulo de Seguridad

`keeper_user_assignments` tiene dos contratos incompatibles para las mismas columnas: el esquema dice
`firm_id` → `keeper_firmas.id`; `populate_keeper_user_assignments.sql:18-20` dice `firm.id` legacy. El
**escritor** pone IDs legacy sin traducir (`LegacySyncService.php:158-161`, `assignments.php:150-157`); el
**lector** los trata como IDs de Keeper (`users.php:263-265`, `policies.php:253-254`, `scopeFilter()`).

Funciona **solo** porque el seed preservó los IDs (`keeper_org_independence.sql:110-134`), y nada mantiene
esa invariante: `organization.php:54-87` crea entidades con `legacy_*_id = NULL` sobre un `AUTO_INCREMENT`
compartido.

**Por qué es el peor fallo posible aquí:** el panel de cobertura decide por **cargo y área** quién
necesita candado. Si el ID apunta a la entidad equivocada, dirá que un abogado necesita candado y que un
agente no. Silenciosamente.

Primer comando a ejecutar: `SHOW INDEX FROM keeper_user_assignments` — si el UNIQUE de
`keeper_admin_auth.sql:60` no está en producción, hay filas duplicadas posibles y **todos** los
`SUM(active_seconds)` con `LEFT JOIN` a esa tabla están multiplicados.

---

## 7. Fase D — Panel web

| # | Cambio | Por qué |
|---|---|---|
| D1 | **Un solo registro de módulos** (slug, label, icono, acciones, roles) leído por los 5 consumidores | Hoy `install_coverage` y `logs` son inconcedibles a cualquier rol. "Seguridad" repetiría ese destino sin ningún error |
| D2 | `src/Panel/Metrics.php`: **una** definición de focus, productividad, online y ocio | 4 fórmulas en 2 escalas; "online" con dos umbrales; el umbral 120/900 escrito 9 veces |
| D3 | Absorber `sedes-dashboard.php` en `index.php` como `GROUP BY` seleccionable | Elimina ~650 líneas y 12 consultas duplicadas, y le da el `scopeFilter` que hoy no tiene |
| D4 | `LIMIT` y paginación real en `user-dashboard.php:319` y en la Query A de `users.php` | El primero materializa ~7.800 filas y las embebe **dos veces**; el segundo pagina en PHP tras traer todo |
| D5 | Vista consolidada por persona | Responde "cómo está este agente" en una pantalla en vez de cuatro |
| D6 | Enlaces cruzados | `devices.php → logs.php?device_id=`, KPI cards → `users.php?status=`, cobertura → dashboard de la persona. Desentierra capacidades ya construidas |
| D7 | Helper único de flash y de tabla+paginación | 5 patrones de flash y 3 de paginación |
| D8 | CSRF global; `RateLimiter` en `login.php` | CSRF existe en **una** página y protege la acción menos peligrosa |
| D9 | Vista per-device de módulos activos | Hoy no existe ninguna; el único display son 3 de ~12 flags, solo global |
| D10 | Autohospedar Tailwind y Alpine | Se cargan de CDN; el JIT de Tailwind no es apto para producción y la oficina ya tuvo su IP baneada |

**No borrar `policies_diagnostic_backup.php` todavía**: pese al nombre, es la **única UI de políticas por
dispositivo**. Migrar el scope `device` a `policies.php` primero.

---

## 8. Fase E — Módulo de Seguridad completo

Solo después de B, C y D. Es el spec `2026-07-29-modulo-seguridad-design.md`, con dos correcciones que
las auditorías obligan:

- **§9 (bitácora) depende de C1.** Sin `admin_id` no puede responder "quién otorgó esta excepción".
- **El panel de cobertura depende de C4.** Con el espacio de IDs ambiguo, clasifica por cargo y área
  contra datos que hoy funcionan por coincidencia.

`AZCKeeperAgent` depende de B5 (contratos compartidos) y B1 (lifecycle). Sin ellos, o referencia todo el
cliente —perdiendo la separación que sí logró `AZCKeeperUpdater`— o duplica el catálogo.

---

## 9. Criterio de aceptación verificable

El requisito del §1 se declara cumplido cuando estas pruebas pasan. **Sin ellas el criterio es una
intención, no un hecho.**

**Por cada módulo apagable, apagarlo y verificar:**

1. **No lanza** — cero excepciones nuevas en el log del cliente durante un turno completo.
2. **Los demás siguen** — cada módulo restante conserva su función medible.
3. **Se apaga sin reinicio** — el cambio surte efecto en el siguiente handshake, y el panel lo confirma.
4. **Degrada honestamente** — ninguna pantalla muestra un número que parezca válido derivado del dato
   ausente. Concretamente: apagar WindowTracking **no** debe producir focus 55 ni productividad 90%; debe
   producir "sin cobertura".
5. **El estado real es visible** — el panel distingue *apagado*, *encendido y reportando* y *encendido y
   sin reportar*. Hoy no puede.
6. **El plano de control sobrevive** — con el módulo en fallo permanente (429 sostenido), el handshake
   sigue llegando y el módulo se puede apagar remotamente.

La prueba 6 es la que hoy falla de forma más silenciosa y la que hace que el kill switch sea confiable.

---

## 10. Lo que NO se va a hacer

- Reescribir el panel. Tiene páginas de referencia reales (`productivity.php`, `logs.php`,
  `dual-job-alerts.php`) y **cero deuda de seguridad de la difícil**: no hay XSS ni inyección SQL en las
  22 páginas. El plan calca lo bueno.
- Reescribir `CoreService` de cero. Se le extrae el punto de extensión y se mueven responsabilidades; no
  se rehace.
- VDI, scopes de firma o sede en el motor de políticas, controles de ámbito de usuario: descartados en el
  spec del módulo y siguen descartados.
- Tocar `SystemProxyManager` más allá de lo hecho. Es la única clase que toca el registro de proxy de 251
  equipos; se mueve en un release propio, no junto a otros cambios.

---

## 11. Orden propuesto y por qué

```
A (urgencias)  →  B (núcleo cliente)  →  C (API)  →  D (panel)  →  E (Seguridad)  →  4.0.0.0
```

**A primero** porque hay corrupción de datos en curso y un endpoint de desbloqueo que no valida nada.

**B antes que C y D** porque el eco de estado (B6) es lo que le da al panel algo verdadero que mostrar;
construir las vistas antes sería construirlas sobre la intención otra vez.

**C antes que D** porque el panel debe consumir repos, no reimplementar el dominio. Al revés se
consolidan las páginas sobre SQL inline y hay que rehacerlas.

**E al final** porque sus dos piezas más delicadas —bitácora y cobertura— dependen de C1 y C4.

El demo 3.9.0.0 de la Fase 0 puede probarse en paralelo con A: son cambios disjuntos.
