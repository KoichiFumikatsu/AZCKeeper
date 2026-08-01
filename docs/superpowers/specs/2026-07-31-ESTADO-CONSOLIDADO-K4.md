# AZCKeeper Keeper 4 — ESTADO CONSOLIDADO (2026-07-31)

Documento maestro de estado: **hecho / decidido / descartado / pendiente**. Fuente única de verdad
del avance de Keeper 4. Se guarda en el repo y en la memoria dual.

## 0. Contexto y encuadre

- **K3** = producción vigente (`keep.azclegal.com`, versión 3.0.3.x). Pasa a **historial** (arranca K4
  desde cero, sin migrar datos — decisión de Koichi 2026-07-29).
- **K4** = reescritura. Rama `feature/modulo-seguridad` (**pusheada a origin**, NO tocar master/DevLinux).
  DEV = `devkeep.azclegal.com` + BD `pipezafra_keepdev`. Repo local `C:\Users\FumiWork\Documents\AZCKeeper`.
- **Objetivo (Koichi):** K4 = **K3 completo + lo nuevo**, cada función **igual o mejor**.
- **Componentes:** cliente C# `AZCKeeper.K4/` (per-user, invisible), agente elevado `AZCKeeperAgent/`
  (SYSTEM), backend+panel PHP `AZCKeeper_Client/WebK4/`, esquema en `AZCKeeper_Client/Web/migrations/keeper4/`.

## 1. HECHO Y VERIFICADO EN DEV

### 1.1 Esquema (migraciones keeper4/, 19 migraciones)
36+ tablas: identidad multi-tenant (keeper_users/firmas/areas/cargos/sedes/sociedades/external_ref/
user_assignments), actividad (keeper_episode particionada, episode_daily, day_summary, focus_daily,
app_classification), operación (devices, sessions, device_command, client_releases), panel+auditoría
(admin_accounts/sessions, panel_roles, panel_settings, audit_log con actor), licenciamiento (module,
tier, tier_module, firma_module_override), comandos+datos (screenshot, location), features portadas
(dual_job_alert, coverage_note). Migraciones recientes: 13 panel_roles, 14 diagnostic, 15 diag_role,
16 release_force_update, 17 device_idle, 18 remote_control_role, 19 device_specs.

### 1.2 Backend API K4 (`WebK4/`, ~50 archivos) — verificado E2E
Cliente: `/client/login` (CC+password, 3 caminos), `/client/handshake` (política global→user→device,
recorte por tier, bloque diagnostics, guarda idle+specs), `/client/episodes/batch`, `/client/activity-day`,
`/client/module-state`, `/client/commands`+`/result`, `/client/security/report`, `/client/screenshots`,
`/client/location`, `/client/diagnostics`. Admin: `/admin/process-view`, `/admin/coverage`+`/note`,
`/admin/commands`, `/admin/enrollment`. Cron: `/cron/productivity` (+ purga diagnóstico). Review opus
integrada (1 Critical + 5 Important corregidos).

### 1.3 Panel K4 (`WebK4/public/admin/`, 14 módulos) — verificado E2E
Login sesión (AdminAuthRepo), **RBAC editable** (keeper_panel_roles, panelCan lee BD), **CSRF** en todos
los POST (HMAC de la cookie, gate central en admin_auth). Páginas: index (tablero seguridad/agente),
process-view (visor procesos + **tarjeta Equipo/specs**, IP/MAC solo IT), users (+reset password +
**columna presencia**), pending-users, devices (etiqueta/renombrar/revocar), coverage, dual-job (+catálogo
señales), tiers (tier×módulo), policies (global por módulo), audit (con actor), releases (feed +
force_update), **diagnostics (en vivo por persona)**, **remote-control (tab, tier)**, roles (matriz +
cuentas admin). no-access. Identidad visual K3 (Tailwind/Inter, corp navy #003a5d, rojo #be1622).

### 1.4 Cliente K4 (`AZCKeeper.K4/`) — 81/81 tests, build 0/0
Login primer arranque (FirstRunLogin: entorno+cédula+password; token DPAPI persiste), re-login silencioso
en 401. ResidentHost (loop+backoff+flush único, invisible, mutex único). Módulos independientes
(IKeeperModule, host aplica en caliente): ActivityModule, WindowModule (episodios + flag IsCallApp),
CommandModule (shutdown real/restart/logoff/lock/network_diag, ejecutor inyectable), ScreenshotModule
(metadata; blob=stub). OfflineQueue, NetworkBackoffPolicy, K4UpdateManager (auto-aplica crítico/forzado),
StartupManager + self-install per-user (Setup de un archivo, updater embebido). **LocalLogger** (anillo+
archivo). **DiagnosticLoop+DiagnosticSnapshot** (sube al panel cuando IT marca). **Presencia**: reporta
idleSeconds en handshake. **WinDeviceSpecs** (SO/CPU/RAM/disco/modelo/serial/IP/MAC/GPU/monitores, primer
handshake). Courier del agente (AgentReportReader). Versión desde el assembly (build-release -p:Version).

### 1.5 Agente elevado (`AZCKeeperAgent/`) — SOLO NÚCLEO
SelfTest (canario HKLM, anti-fallo-silencioso), AgentCycle, PolicyEnforcer (re-asserta), ControlCatalog.
BrowserRing (URLBlocklist/DownloadRestrictions/ExtensionInstallBlocklist HKLM Chrome/Edge/Brave, valores
Fase 0), courier (agent-report.json atómico). **NO tiene: host de servicio Windows, instalador (sc create),
ni recibe política del servidor** (Program.cs es runner dev con política hardcodeada).

### 1.6 Features de esta sesión (2026-07-31) — todas verificadas E2E
- **Login con entorno + password** (5 casos HTTP + reset panel).
- **Diagnóstico en vivo por persona** (snapshot real con ventana en foco capturada; toggle; RBAC).
- **Presencia** (activa/ausente/offline/sin-keeper; columna users + resumen dashboard).
- **Control remoto**: doble candado (RBAC remote-control + tier), acciones (lock/apagar real/reiniciar/
  logoff), **tab propio** remote-control.php con botones grandes + screenshot desactivado; quitado de devices.
- **Specs del equipo** (tarjeta en process-view; IP/MAC solo IT).
- **CSRF** en todo el panel. **force_update** en releases (paridad K3). **.htaccess** (dominio pelado→panel,
  sin romper la API). **Informe de brechas K3→K4**.

### 1.7 Releases publicadas (GitHub, pre-release beta, target feature/modulo-seguridad)
- v4.0.0.1-beta y v4.0.0.2-beta (mismo código, para probar el salto de auto-update).
- v4.0.0.3-beta (presencia+control).
- **v4.0.0.4-beta = COMPLETA (presencia+control+specs)** — el build para la revisión.
- **Feed DEV:** 4.0.0.1 ACTIVA (base a instalar), 4.0.0.4 inactiva (TARGET del auto-update). Prueba:
  instalar 4.0.0.1 → panel Activar+Forzar 4.0.0.4.

## 2. DECIDIDO (decisiones de diseño, no re-litigar)

- **Datos desde cero**; K3 pasa a historial; el saneamiento incluye el esquema.
- **Diagnóstico solo en el panel** (near-real-time por sondeo; el hosting no da streaming/egreso), sin
  ventana local. Efímero (snapshots 2h, flag 4h auto-off).
- **Login:** entorno (solo Desarrollo hoy; K4 no tiene prod) + cédula + password. Password sin hash→se
  fija exigiendo patrón `z<cédula>Z@!$`; con hash→password_verify. Token DPAPI no se re-pregunta.
- **Control remoto = capacidad que se VENDE por tier.** Doble candado: permiso RBAC `remote-control` +
  tier de la firma (deviceLock/remoteShutdown). Scope por firma. Un admin de firma controla SU gente si
  su firma compró el tier. Vive en tab propio (no en Dispositivos).
- **Dos instalaciones distintas:** cliente per-user (sin admin, install por control de pantalla DWService)
  vs agente elevado SYSTEM (admin una vez, install por shell SYSTEM DWService `sc create`).
- **Bloqueo web va en el AGENTE elevado** (HKLM, no HKCU — el HKCU es solo-lectura, causó los fracasos de
  K3). Falla cerrado. El PAC/hosts/URLBlocklist-HKCU histórico se descartó.
- **Presencia:** idle reportado en el handshake normal (barato); offline>10min, ausente>5min.
- **Specs:** IP/MAC solo para rol it/superadmin; los admins de firma no los ven.
- **Feed DEV en canal estable (is_beta=0)** aunque el GitHub sea pre-release, para que el cliente por
  defecto (AllowBeta=false) jale el update sin activar beta en cada equipo.
- **Sección legal** (Ley 1581, aviso/consentimiento) delegada a la gerencia jurídica (2026-07-29).
- **GPS/location** y **Anillo 2** (USB/UAC/SRP/CMD) reservados para revisión conjunta con Koichi.

## 3. DESCARTADO

- **Mensaje / llamada al usuario** (eran ejemplos; Koichi los omitió).
- **Web-blocking por PAC/hosts/proxy/URLBlocklist-HKCU** (K3): fracasó 3 veces por falta de privilegio
  elevado; se reemplaza por URLBlocklist en HKLM vía agente.
- **VDI** (copiar/pegar, captura): USD 6-8k/mes a 200 puestos, descartado.
- **Discord logging** (2026-07-16): "si ya está en la página, no lo necesito".
- **Screenshot on-demand YA**: depende del object storage (Nextcloud) pendiente; el botón queda desactivado.
- **Sacar el webhook/código inerte de Discord del cliente**: obligaría a reconstruir releases ya publicadas.

## 4. PENDIENTE — brechas K3→K4 (detalle en 2026-07-31-brechas-k3-vs-k4.md)

**TIER 1 (bloquean paridad):**
1. **Bloqueo web operativo** — FALTA end-to-end: agente como servicio SYSTEM + instalador + política
   server-driven (panel→ProgramData→agente→HKLM→applied→reporte) + panel de dominios + limpieza de
   residuos legacy en el cliente. *(Lo que Koichi pide ver.)*
2. **Bloqueo de equipo con PIN** — FALTA: KeyBlocker (fullscreen multi-monitor + hook teclado + PIN +
   device-lock/unlock). K4 solo LockWorkStation nativo.
3. **Organización + asignaciones + import de usuarios** — FALTA: organization.php + assignments.php +
   import multi-tenant (keeper_source) / LegacySync. Sin alta real de org/usuarios.
4. **Tracking de llamadas real** — REGRESIÓN: K4 solo flag IsCallApp, CallSeconds=0; falta módulo
   callTracking (segundos en llamada + override actividad-por-llamada).
5. **Horario laboral + categorización (trabajo/almuerzo/fuera) + reanudar día** — REGRESIÓN/FALTA:
   handshake K4 no manda workSchedule; sin GET /client/activity-day se pierden datos al reiniciar.

**TIER 2 (visibilidad/soporte):**
6. Logging cliente→panel (POST /client/logs + ClientLogRepo + drain + página histórica) — FALTA.
7. Productividad/Focus Score UI (productivity.php) — FALTA.
8. Dashboard KPIs de productividad (index es tablero de seguridad) — REGRESIÓN.

**TIER 3 (resiliencia):**
9. Re-enroll por device_guid (+ endpoint) — FALTA. 10. Force-handshake (bump política flota) — FALTA.
11. TimeSync con hora del servidor — FALTA.

**TIER 4 (secundarias):** sedes-dashboard, server-health, policies (override usuario/batch/scope device),
episodio único, object storage real de screenshots, verificar equivalencia de fórmulas productividad
K3(ProductivityCalculator) vs K4(Metrics).

**Orden propuesto:** web blocking → org/import → device lock PIN → calidad productividad → logging →
resiliencia → secundarias. Empezar por web blocking arrastra construir el agente-como-servicio, que
después ejecuta el device lock y el Anillo 2.

**Además pendiente (operación):** revisión módulo por módulo con 3 equipos en DEV (paso de Koichi:
instalar 4.0.0.1 → Activar+Forzar 4.0.0.4 → probar presencia/control/specs); verificación ELEVADA del
agente en equipo de prueba.

## 5. Infra / gotchas reutilizables

- **Hosting server1872 SIN egreso HTTP/HTTPS** (curl a github/google = error 7; DNS sí). Nada server-side
  consulta APIs externas. Por eso "Verificar" del asset no funciona; tamaño se pone a mano.
- **open_basedir desactiva CURLOPT_FOLLOWLOCATION en silencio** → seguir redirects a mano.
- **Zona del panel = −05:00** → leer TIMESTAMP como UTC desfasa 5h; usar UNIX_TIMESTAMP().
- **gh release create con 160MB de assets TIMEOUT 2min y deja DRAFT** → crear release sin assets primero,
  subir con `gh release upload` (background), publicar con `--draft=false`; el download tarda ~20s en
  propagar tras salir de draft. gh se autentica con `GH_TOKEN=$(git credential fill)`.
- **build-release.ps1 limpia build/ en cada corrida** → guardar assets versionados FUERA de build/ entre
  builds (nombres de salida fijos).
- **Fork exhaustion MSYS** (askpass ssh + builds): matar bash/ssh/dotnet/msbuild/VBCSCompiler por
  PowerShell y esperar; el harness levanta shell fresco.
- **Contaminación de roles en DEV**: gerente tenía permisos raros de pruebas de roles.php; default limpio
  = process-view,coverage,dual-job,users. Si un rol "hereda" permisos raros, es contaminación de pruebas.
- **Acceso BD DEV:** `ssh keeper-hosting` (askpass auto) → `cd ~/www/devkeep.azclegal.com; MYSQL_PWD=$(sed
  -n 's/^DB_PASS=//p' .env) mysql -h $(grep ^DB_HOST= .env|cut -d= -f2-) -u pipezafra_keepdev pipezafra_keepdev`.
- **Deploy panel/backend:** scp a `keeper-hosting:www/devkeep.azclegal.com/{public,src}/...`. Lint remoto
  `/usr/local/php8.2/bin/php -n -l`. Un SSH por comando (límite de forks).

## 6. Datos de prueba / accesos DEV

- **Panel:** http://devkeep.azclegal.com (o /admin → redirige al login). Superadmin
  `techsupport@azclegal.com` / `Keeper4Demo!`. Gerente `gerente@azclegal.com` / `Gerente4Demo!`.
- **Cliente de prueba:** cc `K4TEST` = user 1 "K4 QA" (firma 3, con override deviceLock+remoteShutdown
  para que salgan los botones de control). Password re-provisionable (patrón `zK4TESTZ@!$`).
- **Instalar (base):** GitHub v4.0.0.1-beta AZCKeeper4-Setup-v4.0.0.1.exe. Target: v4.0.0.4-beta.
- **Deuda conocida:** el panel no valida CSRF... (RESUELTO esta sesión). GPS excluido. Object storage
  pendiente. Sección legal en jurídica.

## 7. Índice de docs (docs/superpowers/)
specs: modulo-seguridad-design, plan-maestro-saneamiento, keeper4-* (esquema/API/panel/tiers/inventario),
webblock-pac, firma-historial, 2026-07-31-{k4-diagnostico-y-login, presencia-y-control-remoto,
specs-equipo-y-tab-control-remoto, control-remoto-catalogo-backlog, **brechas-k3-vs-k4**, **ESTE doc**}.
plans: keeper4-*, webblock, k4-diagnostico-y-login, presencia-y-control-remoto.
Progreso SDD (gitignored): `.superpowers/sdd/progress.md`.

---

## 8. AVANCE AUTÓNOMO 2026-07-31 (Koichi fuera) — brechas cerradas

Plan: `docs/superpowers/plans/2026-07-31-cierre-brechas-autonomo.md`. Todo commiteado+pusheado en
`feature/modulo-seguridad`. Solo lo seguro/verificable en DEV; el enforcement elevado y las decisiones
de sistema quedan para Koichi.

- **BUG P0 CORREGIDO (crítico):** la política global se guardaba PLANA (`{enableX}`) pero el handshake
  (clipToTier) y el cliente (ToModuleConfig) esperan los flags bajo `{"modules":{...}}` → **ningún
  módulo llegaba al cliente** (0 en config, todo expected=false). Arreglado en policies.php (envuelve
  en modules; lectura tolerante) + migrada la global de DEV. Verificado: cliente ahora aplica 11 módulos.
- **A — Bloqueo web LADO SERVIDOR:** policies.php gana "Bloqueo web" (dominios/descargas/extensiones →
  policy_json.webBlocking) + "Horario laboral"; handshake normaliza (InputValidator) y solo manda
  webBlocking si el tier lo permite. Verificado E2E (dominios normalizados en el handshake).
- **B — Logging cliente→panel:** POST /client/logs (redacta secretos) + ClientLogRepo + drain del
  LocalLogger (Warn/Error, cursor) + página client-logs.php (pestaña desde audit) + purga 30d. Verificado.
- **C — Tracking de llamadas real:** CallTrackingModule (segundos en llamada por día) + CallDetection
  compartido; ActivityModule manda CallSeconds real. Verificado (tests).
- **D — Horario + categorización:** WorkSchedule (trabajo/almuerzo/fuera) en handshake + panel;
  ActivityModule categoriza y manda work/lunch/afterHours active+idle. Verificado E2E (handshake).
- **E — Resiliencia:** re-enroll por device_guid (POST /client/re-enroll + fallback en el cliente) +
  TimeSync (ServerSyncedClock, offset del serverTimeUtc, no toca el reloj del SO). Verificado (404/200).
- 90/90 tests cliente. Firma 3 (K4TEST) tiene override de módulos de tracking+webBlocking para pruebas;
  dominios web de ejemplo (facebook/youtube/*.tiktok) y horario 07:30-17:30 configurados en DEV.

**QUEDA PENDIENTE (necesita a Koichi o es sub-feature aparte):**
- **Bloqueo web ENFORCEMENT (agente elevado):** servicio Windows SYSTEM + instalador (`sc create`) +
  **decisión de transporte** de la política al agente sin manipulación del usuario (ProgramData con ACL
  vs agente jala del server) + su verificación en equipo de prueba. NO tocado (cambio de sistema).
- **D3 — Resume del día:** GET /client/activity-day + el cliente retoma contadores al arrancar (no
  perder datos al reiniciar a media jornada). Sub-feature aparte, no hecha.
- **Device lock con PIN** (Tier 1 #2), **organización/import de usuarios** (Tier 1 #3): no tocadas.
- Tier 4: productivity.php (Focus UI), sedes-dashboard, server-health, screenshot object storage.
