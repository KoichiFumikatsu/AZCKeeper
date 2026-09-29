# AZCKeeper v4 — Estado y Handoff (actualizado 2026-09-28)

Documento de traspaso para retomar en sesión nueva con contexto limpio. Cubre: qué está hecho,
qué falta, paridad con K3, cómo funciona, flujo de trabajo, logs/observabilidad, rutas y entorno.

---

## 1. Resumen ejecutivo

v4 es el rediseño de AZCKeeper tras descartar K4 (2026-09-15) y auditar K3. **Reemplaza el agente
per-user de K3 por un servicio Windows SYSTEM** que aplica enforcement real por HKLM, con backend PHP
multi-tenant y protocolo firmado (RFC 9421). Rama de trabajo: **`feature/keeper-v4`** (repo
`github.com/KoichiFumikatsu/AZCKeeper`), pusheada. Backend desplegado en **devkeep** (servidor propio .234).

Estado: **el núcleo está validado en un equipo real** (enrolamiento, sync, enforcement web/USB, Modo B,
wipe, puntos de restauración). Falta: la prueba real del auto-update (requiere construir la cadena de
firma de releases), un panel admin vivo, y un sistema de logs observable del agente.

---

## 2. Entorno y rutas

### Repos
- **Código**: `C:\Users\FumiWork\Documents\AZCKeeper` (repo git, rama `feature/keeper-v4`).
  - `backend-v4/` — backend PHP (agente + admin + externa + compilador de políticas).
  - `client-v4/` — agente C#/.NET 8 (`Keeper.Agent`, `Keeper.Session`, `Keeper.Shared`, `Keeper.Bootstrapper`).
  - `client-v4/build-installer.ps1` → `artifacts/AZCKeeper_v4_bootstrap_4.0.0.zip` (self-contained single-file).
- **Memoria (contexto entre sesiones)**: `C:\Users\FumiWork\memoryClaude` (repo git propio, `memory/azckeeper-v4-requisitos.md` es el principal). OJO: sesiones paralelas se han pisado este archivo 3 veces; commitear→pull --rebase→push.

### Servidor devkeep (pruebas) — `azc-root` por SSH
- Público **185.42.22.239** / LAN 192.168.12.234 (servidor propio, HestiaCP, MariaDB 11.4.7).
- Docroot: `/home/keeper/web/devkeep.azclegal.com/public_html/` (user Hestia `keeper`, PHP-8_3, BD `keeper_v4`).
- HTTPS válido: `https://devkeep.azclegal.com/v1/`. Admin dev: `admin@devkeep.azclegal.com` (clave reseteada a `DevAdmin-2026!d708`, **cambiar por la real**).
- `keep.azclegal.com` = prod-ready (BD `keeper_prod`), aún sin DNS/SSL apuntados; espejar tras el piloto.
- Access log Apache: `/var/log/apache2/domains/devkeep.azclegal.com.log` (aquí se ven POST /client/sync, GET /client/hardening con su código HTTP).
- Herramienta de diagnóstico dejada: `.../tools/check-enroll.php <thumbprint>`.
- Tenant de prueba "Grupo AZC": `00000000-0000-4000-8000-000000000002`. Plataforma: `...0001`.

### Equipo de prueba
- `DESKTOP-949SGVE`, **Win11 Pro**, TPM 2.0. Acceso por **DWService (shell SYSTEM)**.
- Usuario `AZC` (RID-1000, admin local). `Administrador` integrado deshabilitado (break-glass). `azcadmin` (RID-1002) creado por el Modo B.
- Agente instalado: servicio **`KeeperAgent`** (LocalSystem). device_id = `9e4f923e-f982-4204-9ded-79ec7998249d`.
- Rutas del agente: binarios `C:\ProgramData\AZCKeeper\bin\`, datos `C:\ProgramData\AZCKeeper\v4\` (device-key.dpapi, outbox.json, next-sync.json, restore-point.json, hardening\password.dpapi, hardening\state.json, staging\).
- Env del servicio: `HKLM\SYSTEM\CurrentControlSet\Services\KeeperAgent\Environment` (REG_MULTI_SZ): KEEPER_DATA_DIR, KEEPER_API_BASE, KEEPER_DEVICE_ID, KEEPER_ENABLE_HKLM. Opcional: KEEPER_SYNC_SECONDS (120), KEEPER_RESTORE_POINT_HOURS (24), KEEPER_ENABLE_HKLM (1=aplica, 0=dry-run).
- Punto de restauración **seq 4** ("Pre-ModoB-Keeper") como red.

---

## 3. Arquitectura — cómo funciona

Tres piezas:
1. **Backend PHP** (`backend-v4/`): API-first, 3 superficies — `/client/*` (agente, token+firma RFC 9421),
   admin (sesión+CSRF), `/ext/v1` (OAuth2 read). Compilador de políticas (precedencia
   plataforma→tenant→sede→área→usuario→equipo, área gana). Multi-tenant, rate-limit por device/tenant (NUNCA por IP).
2. **Agente C#** (`client-v4/`): servicio SYSTEM `Keeper.Agent` (enforcement + comandos + update + seguridad)
   + `Keeper.Session` (por usuario: tracking de actividad/ventanas/presencia + overlay de bloqueo/PIN, IPC named pipe)
   + `Keeper.Shared` (protocolo, política, IModule) + `Keeper.Bootstrapper` (instalar/actualizar/endurecer).
3. **Bootstrapper** (`Keeper.Bootstrapper.exe`): `--system-install` / `--system-uninstall` / `--system-update`
   (nuevo) / `--harden` / `--unharden`. Corre en el shell SYSTEM de DWService (sin UAC).

Autenticación agente: enrollment por ticket → device_token (bearer) + prueba de posesión (firma HTTP RFC 9421,
P-256, keyid = thumbprint JWK). ETag de política = campo `policy_version` en `/client/sync`.

---

## 4. Flujo de trabajo (extremo a extremo)

1. **Provisionar** (panel/API, una vez por equipo): crear enrollment con `device_id` (Modelo B: el
   aprovisionamiento fija el id) → ticket (10 min).
2. **Instalar**: transferir el ZIP + `installation.json` (api_base, tenant_id, device_id, enable_hklm) por
   DWService → `Keeper.Bootstrapper.exe --system-install` (shell SYSTEM). Crea el servicio, escribe el env,
   arranca. El agente enrola por ticket, obtiene device_token, guarda `device-key.dpapi`.
3. **Sync** (cada 120s): login por device → `/client/sync` (sube outbox: episodios/logs/seguridad; baja
   política si subió `policy_version` + comandos + release). Compila la política on-demand si falta.
4. **Enforcement**: al recibir política con reglas, el agente escribe HKLM (URLBlocklist, RemovableStorageDevices,
   AppLocker, DownloadRestrictions) si `enable_hklm=1`. Aplica y revierte según la política.
5. **Comandos remotos**: lock/unlock/restart/shutdown/**wipe** (nuevo) llegan en el sync response.
6. **Modo B (endurecimiento)**: clave compartida se carga UNA vez por empresa (`PUT /admin/tenants/{id}/hardening`)
   → el agente la baja (`GET /client/hardening`) y escribe `password.dpapi` protegido → IT corre
   `--harden` (crea azcadmin, S-1-5-114, degrada al usuario, oculta azcadmin). `--unharden` revierte.
7. **Auto-update** (código listo, prueba pendiente): el sync trae una `release` → el agente descarga el ZIP,
   verifica firma+hash, y lanza `--system-update` (reemplaza binarios preservando env/datos).

---

## 5. HECHO y validado en equipo real (2026-09-28)

Todo commiteado en `feature/keeper-v4` (commits: `abf6859`, `e27831e`, `cb818ae`, `58426c6`, `a1fbd3d`, `bc82316`, `9620f64`, `58540cd`, `a1a5f7e`).

- **Enrolamiento + sync**: validados. **5 bugs de diseño arreglados** (device_id Modelo B, backoff heredado,
  precisión de fecha 6 díg, policy on-demand, subscription/tier default). Migraciones ahora versionadas en git.
- **Enforcement vía agente** (ciclo automático backend→policy→sync→HKLM): web (URLBlocklist) y USB (Deny_Write)
  APLICAN y DESAPLICAN solos. AppLocker con modo Audit/Enforce. Descargas (DownloadRestrictions).
- **Wipe remoto** (`CommandType.Wipe`): borra perfiles de usuario + cipher /w, preserva cuentas de IT/sistema.
  Unit-tested; NO probado real (destructivo, requiere equipo desechable). Sin cifrado (BitLocker descartado por flota Home/Pro mixta).
- **Puntos de restauración periódicos** (módulo RestorePoint, 1/24h). Plus para IT. Existe en Home y Pro.
- **Provisión de clave azcadmin por API** (`HardeningPasswordModule`, escrito por Codex, revisado): el agente
  baja la clave y escribe `password.dpapi` (DPAPI LocalMachine, ACL solo SYSTEM/Administradores, atómico).
- **Modo B COMPLETO validado en equipo real** (harden + unharden, verificado contra Windows): azcadmin creado
  y verificado, S-1-5-114 aplicado, usuario degradado, azcadmin oculto; unharden restaura todo.
- **Auto-update — CÓDIGO completo** (bloques 1+2): modo `--system-update` en el bootstrapper (preserva env/datos
  + recovery del servicio) + descarga/verificación/aplicación en el agente. 393 tests verdes. Falta la prueba real.
- Tests totales: **Agent 275 + Bootstrapper 118 = 393 en verde**. Interop RFC9421 C#↔PHP probada por separado.

---

## 6. FALTA (pendientes priorizados)

### 2026-09-29 (noche, 3) — alta de equipos a escala (backend DESPLEGADO en devkeep 2026-09-29 17:36, backup deploy-backups/20260929-173614; agente sin release). 4.0.8 instalada por auto-update en DESKTOP-949SGVE
Diseño y uso: `docs/architecture/v4-alta-equipos.md`. Commits `b2a84cb`, `fbe5193`, `230c841` (+ reorden de la 0023).
- Backend: migración `0023_device_intake.sql` (equipos esperados, claves de alta, solicitudes, configuración,
  `devices.asset_code`, scope `expected-devices:write`); `src/DeviceIntake.php`; rutas `/enrollments/*`,
  `POST /client/enrollment-requests` y `POST /ext/v1/expected-devices:import`. Suite MySQL: 1412 aserciones.
- Panel: `alta.php` (Alta de equipos): cola de solicitudes, esperados manual/CSV, clave de alta y reglas.
- Agente: `KEEPER_ENROLLMENT_KEY` sin `device_id` → pide alta, reintenta, login por ticket, `device-id.txt`.
  Autoidentificación: `ask_document` → Keeper.Session en modo identificación pide la cédula.
- Bootstrapper: `installation.json` acepta `enrollment_key` (paquete genérico), redactado en logs.
- 4.0.9 (seq 10) firmada y subida a devkeep/releases (sha256 80349b1e...); FALTA registrarla (`bash client-v4/tools/register-release.sh client-v4/artifacts/release-4.0.9.json`). Trae: alta por clave, cedula, y hostname en inventario (sin ella el panel tarda hasta 1 h en mostrar un renombre).
- Renombre validado en DESKTOP-949SGVE -> ACT-0987 (orden succeeded 22:56 UTC, reinicio y sync 23:00).
- FALTA (antes): release del agente con esto (4.0.9) y prueba real: equipo nuevo con paquete genérico, cruce por serie,
  cola, aprobación, placa + renombre. Hasta entonces los agentes desplegados no usan estas rutas.

### 2026-09-29 (noche, 2) — renombrar equipo + serie del fabricante (4.0.8, seq 9, firmada y subida; FALTA registrar)
- 4.0.7 aplicada en el piloto (20:33Z) y sigue sincronizando. `recovery.json` PUBLICADO = release 4.0.7.
- `e97fc53`: comando `rename_computer` (panel: ficha > "Cambiar nombre del equipo"; ACT_0015 -> ACT-0015 porque el
  guion bajo no es valido en DNS; permiso equipos.editar; solo agentes >= 4.0.8). Migracion 0022 APLICADA en devkeep.
  Serie del fabricante por SMBIOS en el inventario (llave para cruzar con la placa `code` del Portal AZC; el portal
  tiene `inventory` con `code` y `serial` pero NO expone API de inventario todavia).
- FIX: comandos creados en el mismo segundo que el sync se entregaban un sync tarde.
- Pendiente: endpoint de inventario en el Portal AZC + cruce por serie en Keeper (nombre sugerido = placa).

### 2026-09-29 (noche) — resiliencia del auto-update (4.0.7, seq 8, firmada y subida; FALTA registrar)
- 4.0.6 VALIDADA: inventario real en la ficha (piloto = Windows 11 **Pro**, i5-8350U, 8 GB; FumiWork = Home).
- `b5d240a`: (1) AppLocker nunca bloquea a Keeper (excepcion Exe+Script para ProgramData\AZCKeeper; se rechaza
  toda ruta escribible que la cubra). ANTES: pasar a Enforce habria bloqueado Keeper.Session a toda la flota y
  posiblemente el propio update (caso K3/GitHub). (2) Vuelta atras: health.json del agente + bin.previous; si la
  version nueva no sincroniza en 5 min con el servidor alcanzable se restaura y se bloquea esa secuencia
  (update-blocked.json). (3) Limpieza de staging cada 6 h.
- `136ddfc`: rescate independiente `recovery\Keeper-Recovery.ps1` (PowerShell 5.1, tarea horaria SYSTEM, NO lo
  toca ningun update): si el agente lleva >6 h sin sync y el servidor responde, reinstala el paquete firmado de
  `{servidor}/releases/recovery.json`. PENDIENTE: publicar recovery.json (= release-4.0.7.json tras validarla).
- Pendiente tras 4.0.7: probar un rollback real con una release deliberadamente rota en el piloto.

### 2026-09-29 (tarde) — paquete, ficha de equipo y recompilacion
- **Paquete con runtime compartido** (`c3eb424`): 120 MB -> 62,5 MB. Transicion: 4.0.4 (seq 5, formato Legacy,
  171 MB, instalador que entiende ambos formatos) y luego 4.0.5 (seq 6, formato Shared). Ambas firmadas y subidas
  a devkeep; REGISTRAR EN ORDEN: primero 4.0.4, esperar a que el equipo este en 4.0.4, luego 4.0.5. Si se registra
  4.0.5 antes, un agente <= 4.0.3 no encuentra el bootstrapper y el update queda atascado en `applying`.
- **Ficha del equipo** en el panel (`0390032`): /equipo.php (desde Equipos) con resumen, estado por control
  (nuevo GET /devices/{id}/security) y ultimos 50 logs del agente. Desplegada.
- **Recompilacion acotada** (este commit): de 10,6 s a 0,45 s en cambios de persona/suscripcion/asignacion.
  Los cambios de alcance tenant siguen costando ~10 s con 300 equipos (~35 s con 1000): antes de la flota,
  pasar esos a segundo plano (cron `config/compile-pending.php`) o abaratar `snapshot()` por equipo.
- Pendientes menores: `LocalAccountHardening=unhardened` marca `degraded` a todo equipo sin Modo B (decision de
  producto); `client-v4/tools/generate.mjs --check` ya fallaba antes de estos cambios.

### RESULTADO 2026-09-29 — AUTO-UPDATE VALIDADO EN EQUIPO REAL (DESKTOP-949SGVE)
4.0.0 (trust seq 1) -> 4.0.1 (seq 2) solo, sin intervencion: descarga 14:19:37Z (UN solo GET de 126 MB), verificacion
14:24:37, `update_applying` 14:29:38, bootstrapper lanzado por el agente 14:29:46 (SOBREVIVE al stop del servicio),
`Agent 4.0.1 started ... sequence 2` 14:29:54. Corte del servicio ~16 s. Sin re-descarga posterior.
Hallazgos del piloto:
- Keeper.Session NO arrancaba: .NET Runtime 1023 "Failed to resolve full path of the current executable" -> bin solo
  daba acceso a SY/BA y Session corre con el token del usuario. Corregido en `a0b8abc` (bin: Usuarios lectura+ejecucion).
  Release 4.0.2 (seq 3) construida, firmada y subida a devkeep; FALTA registrarla (`tools/register-release.sh`).
- `devices.agent_version` queda en 4.0.0 tras el update: solo se escribe en /client/login y el agente reusa su token.
  Pendiente: forzar re-login cuando cambia la version del agente (sin tocar el contrato).
- La cadencia de 5 min entre descarga, verificacion y aplicacion hace que un update tarde ~10 min: aceptable.
- SEGUNDO CICLO 4.0.1 -> 4.0.2 (seq 3) VALIDADO 14:43-14:54Z: el bootstrapper aplico el ACL nuevo de bin, Keeper.Session
  corre en la sesion del usuario (primera vez) y a las 14:59Z llegaron los primeros episodios al servidor (explorer, idle:
  el usuario no estaba frente al equipo). `time_category` llega NULL: revisar si lo calcula el cron o si falta en ingesta.
- CORREGIDO en `cca5278` (desplegado Ingest.php en devkeep; backup deploy-backups/20260929-101627): time_category ahora
  lo envia el cliente y lo guarda la ingesta; el login reportaba "4.0.0" FIJO y el agente fuerza login al cambiar de
  version. Release 4.0.3 (seq 4) REGISTRADA y APLICADA sola 15:34:58Z (tercer ciclo): login forzado 15:36:33,
  `devices.agent_version = 4.0.3`, episodios desde 15:35:20 llegan con `time_category = work_hours`. VALIDADO.
- Tras un reinicio el primer sync gasta 4 peticiones y el planificador espera ~8 min (484 s) al siguiente: el equipo
  no recibe comandos (ni bloqueo remoto) en ese lapso. Es el presupuesto de peticiones; DECIDIR antes de la flota.
- Cambios rapidos de ventana generan episodios de 1-2 s: vigilar volumen de filas con 1000 equipos.

### ACTUALIZACIÓN 2026-09-28 (noche) — cadena de firma y logs HECHOS; falta solo la ejecución real
Commits `5ab0ca8` (logs + fix staging), `e71de31` (firma + Session en paquete + precheck), `69e1119` (backend:
oferta a equipos v4 + sync no cae por release no verificable). Tests: Agent 294, Bootstrapper 121, smoke backend 1261.
Paquetes 4.0.0 (seq 1, con trust) y 4.0.1 (seq 2, firmado) construidos y verificados en local contra el verificador
C# del agente Y el PHP del backend. **Siguiente paso: `docs/RUNBOOK-auto-update-piloto.md`** (servidor + equipo).
Bugs encontrados y corregidos al preparar la prueba (los 4 la habrían hecho fallar):
1. `--system-update` rechazaba el payload que el propio agente extrae en `v4\staging` (dentro de ProgramData\AZCKeeper).
2. `Keeper.Session.exe` no se empaquetaba y el lanzador exigía un `.dll` que en single-file no existe → Session
   (tracking de actividad/ventanas/presencia) NUNCA arrancó en el equipo real.
3. El backend solo ofrece releases si `devices.specs.architecture` existe, y nada en v4 escribe `specs` → nunca ofrecía.
4. Un agente ya actualizado re-descargaba el paquete entero para rechazarlo, en cada reinicio.
Hecho también: `GET /client/hardening` ya NO va en cada sync (primer sync del proceso, luego cada 6 h; cada 15 min
mientras el tenant no tenga clave cargada; tras error, siguiente sync). Tests: Agent 297, Bootstrapper 121.
Además: `UpdateManager` usaba versión fija 4.0.0 (ahora la del ensamblado); la salida del bootstrapper en modo
update se perdía (ahora `logs\bootstrapper-*.log`). El paquete pasó de ~60 MB a ~126 MB por Session (WinForms
self-contained arrastra el runtime de escritorio completo): relevante para el reparto a la flota.

### (Histórico) Inmediato — auto-update prueba real
La cadena de FIRMA de releases NO existe (hay que construirla). El agente instalado tiene trust vacío
(`installation-trust.json` ausente → `ReleaseKeys` vacío → rechaza todo update). Falta:
1. **Firmador de release** (reusar `SignedRelease` de `tests/Keeper.Agent.Tests/SecurityModuleTests.cs`, que
   ya arma un manifest JWS ES256 válido en C#): generar par P-256, escribir `installation-trust.json`
   (`ReleasePublicKeys{keyid:SPKI-base64}`, `BinaryHashes{}`, `InstalledSequence:1`, `Channel:"stable"`), y
   firmar el manifest JWS de cada release (formato exacto en `UpdateManager.cs` `ReleaseVerifier`).
2. `build-installer.ps1` debe **incluir** `installation-trust.json` en `agent/`.
3. Prueba: reinstalar 4.0.0 (con trust) → build 4.0.1 (cambio mínimo visible) → firmar → subir ZIP a devkeep
   (HTTPS) → registrar por `PUT/POST createRelease` (AdminApi:509) → el agente 4.0.0 lo aplica → queda 4.0.1.
   Verificar en Windows real que el proceso `--system-update` lanzado SOBREVIVE al STOP del servicio.
- OJO: `Sign-AzcRelease.ps1` es Authenticode del .exe (RSA, SmartScreen), NO el manifest JWS. No confundir.
- Alojamiento del paquete: piloto en devkeep; flota en CDN (GitHub Releases / bucket) + rollout por anillos
  (60MB × 1000 equipos satura el enlace del servidor propio).

### Huecos del agente (del inventario verificado)
- **DeviceLock real**: hoy es un overlay WinForms EVADIBLE (reinicio / matar `Keeper.Session`), NO bloquea el SO.
- **TamperGuard**: solo detecta/reporta hashes (reporta "unsupported" sin BinaryHashes en el trust); no protege ni auto-repara.
- **Fix de eficiencia**: `GET /client/hardening` se hace en CADA sync (duplica peticiones del ciclo estable) — hacerlo condicional/espaciado.
- **Anti-robo**: sin cifrado, el wipe solo cubre "equipo encendido+conectado", NO disco extraído ni offline. Decisión de negocio pendiente si se quiere más.

### Backend / operación
- **compilePending** recompila TODOS los devices del tenant (298) tras cada cambio admin → el `POST /users`
  tardó >20s. Optimizar (recompilar solo el device afectado o async) antes de la flota.
- **Marcar tier default por API/panel** (hoy por seed SQL).
- **Migrar a keep (prod)** tras el piloto (espejar backend, DNS+SSL).

### Panel admin (frente grande)
- El panel admin v4 es **MOCKUP** (`docs/design/2026-09-15-propuestas/panel-v4/`, 20 páginas HTML). El backend
  es API real, pero NO hay frontend admin vivo. Configurar reglas/tenants/hardening hoy es por curl/SQL.

---

## 7. Para alcanzar (y superar) K3

v4 ya **supera** a K3 en control: enforcement HKLM real (K3 usaba PAC/HKCU evadible), frontera de tenant,
auth con prueba de posesión (K3 daba bearer con solo el GUID = crítico de auditoría), updater firmado
(K3 ejecutaba ZIP sin firma), ~0.5 req/min objetivo (K3: 4-9 req/min). Wipe, Modo B, puntos de restauración: nuevos.

**Lo que K3 tiene y a v4 le falta para PARIDAD visible al cliente** (todo backend/reportes, el agente ya
captura los datos con `Keeper.Session`): cron de productividad, dashboard por persona, rankings/top de apps,
holidays/festivos (puntualidad), dual_job_alerts, install_coverage, suspicious_apps, dashboard de sedes,
vista de logs del cliente, server-health. Ver `docs/architecture/v4-modulos-y-brechas.md` (documento maestro
de módulos). En resumen: **el motor de datos v4 está; faltan los reportes/dashboards y el panel vivo que los muestre.**

---

## 8. Requerimientos de negocio v4 (referencia)

Control total del equipo (bloqueos SO/web/descargas/instalación/USB), anti-robo/protección de datos,
trabajo remoto, multi-empresa >1000 usuarios/empresa cada una con UNA IP de salida (no banear por IP),
bloqueo web sin tormenta de peticiones, panel rebrandeable, API documentada para terceros. RBAC parametrizable
(2 gates para roles.gestionar). Detalle en `memoryClaude/memory/azckeeper-v4-requisitos.md`.

---

## 9. Sistema de logs / observabilidad

**ACTUALIZACIÓN 2026-09-28: opción (1) implementada** (`5ab0ca8`). Log de texto por día UTC en
`C:\ProgramData\AZCKeeper\v4\logs\agent-yyyyMMdd.log` (Information+, 14 días, tope 20 MB/día) y
`bootstrapper-yyyyMMdd.log` en modo `--system-update`. Registra arranque (versión + estado del trust),
`sync_ok`/`sync_retry`/`sync_failed`, cada reporte de módulo (copia local del LogEntry que sube al servidor) y los
pasos del update. El EventLog se dejó en Warning+ a propósito: ahora `sync_failed`, excepciones de módulo y reportes
warn/error SÍ salen como Warning (antes todo era Information y se filtraba). Pendiente: opción (3), vista en panel.
Leer en el equipo: `Get-Content C:\ProgramData\AZCKeeper\v4\logs\agent-*.log -Tail 50`.

### Estado anterior (histórico)

**Hoy NO hay un log legible del agente.** [Seguro] Esta es una carencia real para ver qué pasa en las pruebas.
Lo que hay para observar:
- **Server (lo más útil hoy)**: access log Apache `/var/log/apache2/domains/devkeep.azclegal.com.log` — muestra
  cada `POST /client/sync`, `GET /client/hardening`, etc. con código HTTP y bytes. Así se diagnosticó toda la sesión.
- **BD del backend**: tabla `client_logs` (logs del agente que suben en el sync: componente, code, level,
  error_code) y `security_reports` (estado de cada control/módulo por device). Consultables con un script PHP
  en `.../tools/`. `device_hardening_status` para el Modo B.
- **Estado local del agente** (JSON, no logs): `C:\ProgramData\AZCKeeper\v4\` — `next-sync.json` (due del
  próximo sync), `outbox.json` (eventos pendientes con `sequence`), `restore-point.json`, `hardening\state.json`.
- **EventLog de Windows**: el agente escribe con `ILogger` nivel Information, pero el EventLog lo filtra
  (solo Warning+), así que "Agent started"/"sync_failed" NO se ven. Los eventos "Service started/stopped" son del SCM.

**Pendiente (requerimiento nuevo): logging observable del agente.** Opciones para la sesión nueva:
1. Log de archivo rotativo en `C:\ProgramData\AZCKeeper\v4\logs\agent-YYYYMMDD.log` (lo más simple para ver pruebas).
2. Bajar el nivel del EventLog provider a Information (verlo en el Visor de eventos).
3. Un panel/consulta que muestre `client_logs` + `security_reports` por device (ya suben al backend; falta mostrarlos).
Recomendación: (1) para el piloto (ver en el equipo por DWService) + (3) para producción (centralizado).

---

## 10. Cómo correr las cosas (comandos de referencia)

- **Build agente/instalador**: `cd client-v4; .\build-installer.ps1` → `artifacts\AZCKeeper_v4_bootstrap_4.0.0.zip`.
  Guards anti-OOM (dotnet deja ~130 procesos): `MSBUILDDISABLENODEREUSE=1`, `-maxcpucount:1 -nodeReuse:false`, `dotnet build-server shutdown` al final.
- **Tests**: `dotnet test client-v4/Keeper.sln -c Release` (393 tests). Con los guards.
- **Instalar en equipo** (shell SYSTEM DWService): `Keeper.Bootstrapper.exe --system-install` (con installation.json en la carpeta).
- **Actualizar en sitio**: `Keeper.Bootstrapper.exe --system-update --payload <dir>\agent` (preserva env/datos).
- **Endurecer/revertir**: `Keeper.Bootstrapper.exe --harden|--unharden --hardening-config hardening.json` (elevado).
- **Cambiar env del servicio** (device_id/hklm): editar `HKLM\SYSTEM\CurrentControlSet\Services\KeeperAgent\Environment` por PowerShell + `Restart-Service KeeperAgent`.
- **Backend**: editar en local, `scp` a devkeep docroot (solo tocar user/carpeta `keeper`, nunca nube/Nextcloud). Migraciones: `php config/migrate.php`.
- **SSH**: `ssh azc-root` (alias configurado).

---

## 11. Notas / gotchas
- Cadena de recuperación Modo B: azcadmin → shell SYSTEM DWService → `--unharden` → punto de restauración.
- La clave compartida de azcadmin NUNCA pasa por el chat de Claude (un filtro de seguridad la bloquea); se
  carga por la API admin (cifrada, auditada). El módulo que la baja al equipo lo escribió Codex (`9620f64`).
- `audit_log` es append-only (trigger): las pruebas destructivas van en BD `_test` aislada, no en devkeep.
- Reloj del equipo de prueba muestra hora local UTC+2 pero su UTC interno coincide con el server (firmas RFC9421 OK).
- Codex: usar para bloques acotados y bien especificados; Claude revisa y prueba (así se hizo el módulo de clave).
