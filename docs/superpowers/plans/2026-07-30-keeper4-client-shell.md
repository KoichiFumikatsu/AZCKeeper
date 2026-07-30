# Keeper 4 — Client Shell Implementation Plan

> **For agentic workers:** este plan continúa el cliente K4 (proyecto `AZCKeeper.K4`, AssemblyName `AZCKeeper4`, net8.0-windows, WinForms, Nullable=enable). El core (`CoreService`, `ModuleHost`, `K4ApiClient`, módulos) ya existe y está verificado. El shell lo envuelve para hacerlo un app residente real.

**Goal:** Convertir el runner de desarrollo en un cliente residente invisible que arranca con Windows, mantiene sesión sin re-loguear, corre el ciclo de handshake con backoff, sobrevive sin red (cola offline), se auto-actualiza, cierra con flush síncrono, y transporta el reporte del agente elevado al panel.

**Architecture:** App WinForms sin ventana ni bandeja (invisible, apropiado para BPO). `Program.Main [STAThread]` → mutex de instancia única → `ApplicationContext` sin ventana → timer de handshake que maneja `CoreService.RunOnceAsync()` con `NetworkBackoffPolicy` → hooks `ProcessExit`/`SessionEnding` para flush síncrono → `Application.Run`. Todo per-user, **sin admin** (el agente elevado es la parte SYSTEM, proyecto aparte).

**Tech Stack:** C# net8.0-windows, WinForms, DPAPI (`ProtectedData` CurrentUser), HKCU Run key, HttpClient. Sin SQLite (la cola es un store propio de archivos).

## Global Constraints
- Namespace del cliente K4: `AZCKeeper.K4.*` (NO `AZCKeeper_Cliente` de K3). `Nullable=enable`.
- Mutex **distinto** al de K3 para coexistencia con producción 3.0.3.2: `Local\AZCKeeper_K4_SingleInstance`.
- Rutas per-user bajo `%APPDATA%\AZCKeeper4\` (NO `AZCKeeper`, para no chocar con K3): `Config\`, `Auth\`, `Queue\`. Updates/logs bajo `%LOCALAPPDATA%\AZCKeeper4\`.
- Nada requiere admin. Auth por `X-Auth-Token` (el hosting no propaga Authorization).
- Re-login: guardar CC+contraseña cifrados con DPAPI (decisión de Koichi, estilo K3).
- Cola offline: store propio de archivos JSON, thread-safe con lock. Sin dependencia nativa.
- Cada etapa: compila 0/0, tests verdes, y donde aplique smoke contra DEV, antes de commit.
- No tocar producción (`DevLinux`). Trabajo en `feature/modulo-seguridad`.

---

## Etapa 1 — Cliente residente corrible

### Task 1: K4Config (store de configuración + device GUID)
**Files:** Create `AZCKeeper.K4/Shell/K4Config.cs`, `AZCKeeper.K4/Shell/K4Paths.cs`; Test `AZCKeeper.K4.Tests/K4ConfigTests.cs`
**Interfaces:**
- Produces: `K4Paths` (static, rutas per-user bajo %APPDATA%\AZCKeeper4); `K4Config` con `LoadOrCreate(string? dir=null)`, `Save()`, `EnsureDeviceId()`, props `BaseUrl`, `DeviceId`, `Cc`, `Version`, `HandshakeIntervalSeconds`, `OfflineRetrySeconds`, `Updates{Enable,IntervalMinutes,AutoDownload,AllowBeta}`.
- Store: JSON en `K4Paths.ConfigFile`. Guardado atómico (temp+move). `EnsureDeviceId` genera GUID v4 si falta y guarda.
Tests: crea config con GUID estable; segunda carga reusa el GUID; save atómico deja JSON válido.

### Task 2: K4CredentialStore (DPAPI: token + credenciales)
**Files:** Create `AZCKeeper.K4/Shell/K4CredentialStore.cs`; Test `AZCKeeper.K4.Tests/K4CredentialStoreTests.cs`
**Interfaces:**
- Produces: `SaveToken(string)`, `string? LoadToken()`, `ClearToken()`, `SaveCredentials(string cc, string pass)`, `(string cc,string pass)? LoadCredentials()`, `HasCredentials()`.
- DPAPI `ProtectedData.Protect/Unprotect`, `DataProtectionScope.CurrentUser`. Archivos `Auth\token.bin`, `Auth\credentials.bin` (formato `cc\npass`). Escritura atómica.
- NOTA en código: la credencial queda reversible en la máquina (decisión aceptada). Loguear solo preview del token.
Tests: round-trip token; round-trip credenciales; clear borra archivo; LoadToken sin archivo → null. (DPAPI real en Windows CI local.)

### Task 3: Token persistente en K4ApiClient
**Files:** Modify `AZCKeeper.K4/Core/K4ApiClient.cs`; Test `AZCKeeper.K4.Tests/K4ApiClientTokenTests.cs`
**Interfaces:**
- Add: `void RestoreToken(string token)`, `string? CurrentToken { get; }`, `event Action<string?>? TokenChanged`. `LoginAsync` dispara `TokenChanged` al setear token. Add `void ClearToken()` (para 401).
- Consumes: nada nuevo. No romper `IApiClient`.
Tests: RestoreToken hace `HasToken` true; LoginAsync exitoso dispara TokenChanged con el token; ClearToken lo limpia.

### Task 4: NetworkBackoffPolicy (port de K3)
**Files:** Create `AZCKeeper.K4/Shell/NetworkBackoffPolicy.cs`; Test `AZCKeeper.K4.Tests/NetworkBackoffPolicyTests.cs`
**Interfaces:**
- Produces: enum `NetworkFailureKind {Success,Dns,Transient,Throttled}`; `static NetworkFailureKind Classify(int status, Exception?)`; `Register(int,Exception?)`, `Reset()`, props `IsBackingOff`, `BackoffUntilUtc`, `ConsecutiveFailures`, `LastKind`; ctor testeable `(Func<DateTime> nowUtc, Func<double> rng)`.
- Exponencial base*2^(n-1) + jitter 20%, caps: Dns 60s, Transient 300s, Throttled 1800s. Cambio de clase resetea contador.
Tests: clasifica 429→Throttled, 503→Transient; backoff crece; Reset limpia; jitter dentro de rango con rng fijo.

### Task 5: ResidentHost (loop + flush) y Program.cs residente
**Files:** Create `AZCKeeper.K4/Shell/ResidentHost.cs`; Modify `AZCKeeper.K4/Program.cs`; Test `AZCKeeper.K4.Tests/ResidentHostTests.cs`
**Interfaces:**
- `ResidentHost(CoreService core, K4Config cfg, NetworkBackoffPolicy backoff, Action<string>? log)`: `Task StartAsync()` (loop async con timer: cada tick, si no backoff → `RunOnceAsync`; en fallo → `backoff.Register` y reprograma; en éxito → `Reset`), `void RequestStop()`, `Task FlushAndStopAsync()` (llama `core.StopAll()` y espera el flush — síncrono desde el punto de vista del caller).
- `Program.cs`: `[STAThread]`, mutex `Local\AZCKeeper_K4_SingleInstance` (si no es nueva instancia, sale), construye config/api/host/core (registra módulos reales), restaura token desde credential store, suscribe `TokenChanged`→`SaveToken`, `AppDomain.UnhandledException`+`Application.ThreadException`→log, `ProcessExit`+`SessionEnding`→`FlushAndStopAsync().Wait()`, arranca `ResidentHost`, `Application.Run(new ApplicationContext())`.
Tests (ResidentHost, con core fake): tras un ciclo fallido registra backoff y no llama RunOnce mientras backOff; éxito resetea; FlushAndStopAsync llama StopAll una vez.

### Task 6: Re-login silencioso
**Files:** Modify `AZCKeeper.K4/Core/CoreService.cs` (o `ResidentHost`), `AZCKeeper.K4/Core/K4ApiClient.cs`; Test en `ResidentHostTests`/`CoreServiceTests`
**Interfaces:**
- En `RunOnceAsync`: si `HandshakeAsync` devuelve 401/None por token inválido y hay credenciales guardadas → `ClearToken`, `LoginAsync(cc,pass)`, reintentar handshake una vez. Necesita que `K4ApiClient` distinga 401 (agregar señal, p.ej. `HandshakeAsync` devuelve estado o un `LastStatus`).
Tests: con token inválido y credenciales, re-loguea y sigue; sin credenciales, reporta fallo sin loop.

**Fin Etapa 1: `AZCKeeper4.exe` corre residente, invisible, mantiene sesión, sobrevive caídas de red con backoff, cierra con flush. Verificar: compila, tests, y correr el exe apuntando a DEV unos minutos.**

---

## Etapa 2 — Persistencia offline, arranque, updater

### Task 7: OfflineQueue (store de archivos)
**Files:** Create `AZCKeeper.K4/Shell/OfflineQueue.cs`; Test `AZCKeeper.K4.Tests/OfflineQueueTests.cs`
**Interfaces:**
- Produces: `Enqueue(string endpoint, string payloadJson)`, `IReadOnlyList<QueueItem> Peek(int max=50)`, `MarkSent(long id)`, `MarkRetried(long id, string err)`, `CleanupDeadLetters()`, `int PendingCount()`. `QueueItem(long Id, string Endpoint, string PayloadJson, int RetryCount)`.
- Store: un archivo JSON por item en `%APPDATA%\AZCKeeper4\Queue\` (`{ticks}_{seq}.json`), o un JSONL con índice — elegir archivo-por-item (borrado = MarkSent trivial y atómico). Thread-safe con lock. Descarta al llegar a RetryCount>=5 (dead letter).
Tests: enqueue/peek FIFO; MarkSent borra; MarkRetried incrementa; dead letter tras 5; thread-safe (2 hilos encolando).

### Task 8: Cola integrada en K4ApiClient
**Files:** Modify `AZCKeeper.K4/Core/K4ApiClient.cs`; Test `K4ApiClientQueueTests.cs`
**Interfaces:**
- Inyectar `OfflineQueue?` y `NetworkBackoffPolicy?`. En envíos idempotentes (episodes/batch, activity-day, module-state, security): si backoff activo o el POST falla con error de red → `Enqueue`. Método `DrainAsync(int batch=10)` que reintenta lo pendiente. El `ResidentHost` llama `DrainAsync` en un timer (`OfflineRetrySeconds`).
Tests (con HttpMessageHandler fake): POST que falla encola; DrainAsync reenvía y MarkSent; backoff activo encola sin pegar a red.

### Task 9: StartupManager (HKCU Run) + K4UpdateManager + helper
**Files:** Create `AZCKeeper.K4/Shell/StartupManager.cs`, `AZCKeeper.K4/Shell/K4UpdateManager.cs`; reuse `AZCKeeperUpdater/` (helper existente); Modify `Program.cs`; Tests `StartupManagerTests.cs`, `K4UpdateManagerTests.cs`
**Interfaces:**
- `StartupManager`: `EnableStartup()`, `DisableStartup()`, `bool IsEnabled()` — HKCU Run, valor `AZCKeeper4`, target el exe instalado. Sin admin.
- `K4UpdateManager(K4Config, K4ApiClient, log)`: `Task<UpdateDecision> CheckAsync()` (GET `client/version`, compara `System.Version`, respeta AllowBeta/minimum/force), `Task ApplyAsync(UpdateDecision)` (descarga ZIP a `%LOCALAPPDATA%\AZCKeeper4\Updates`, valida tamaño, extrae, lanza `AZCKeeperUpdater.exe` con args, `Application.Exit`). Decisión pura testeable aparte de la descarga.
Tests: decisión (latest>current, force, minimum, beta on/off) con respuestas fake; IsEnabled refleja el registro (usar subkey de prueba). La descarga/swap NO se testea automatizado (I/O de proceso) — se verifica a mano.

**Fin Etapa 2: cliente arranca con Windows, no pierde datos sin red, y se auto-actualiza. `client/version` endpoint en backend K4 si falta.**

---

## Etapa 3 — Courier del agente elevado

### Task 10: AgentReportReader (courier lado cliente)
**Files:** Create `AZCKeeper.K4/Shell/AgentReportReader.cs`; Test `AZCKeeper.K4.Tests/AgentReportReaderTests.cs`
**Interfaces:**
- Produces: `AgentReportReader(string? path=null, Func<DateTime>? nowUtc=null)`; `AgentCourierState Read()`. `AgentCourierState(bool Present, bool Stale, JsonElement? Enforcement)`. Lee `%ProgramData%\AZCKeeper\agent-report.json`; si no existe → Present=false; si `reportedAt` > 15 min → Stale=true (el server igual lo verá viejo, pero el cliente marca la señal). Devuelve el JSON de agentEnforcement tal cual para adjuntar.
Tests: sin archivo → Present=false; archivo fresco → Present=true, Stale=false, trae elevated; archivo viejo → Stale=true; JSON corrupto → Present=false (no revienta).

### Task 11: Reporte de seguridad con agentEnforcement
**Files:** Modify `AZCKeeper.K4/Core/K4ApiClient.cs` (add `ReportSecurityAsync`), `AZCKeeper.K4/Core/CoreService.cs` (llamar en el ciclo con lo que da el reader); Test `K4ApiClientSecurityTests.cs`
**Interfaces:**
- `K4ApiClient.ReportSecurityAsync(bool agentPresent, object controls, object? agentEnforcement)` → POST `client/security/report` con `deviceId`, `agentPresent`, `controls`, `agentEnforcement`. `CoreService.RunOnceAsync` (o un tick propio) lee el courier y llama esto.
Tests (handler fake): arma el payload correcto (agentEnforcement adjunto cuando Present); sin agente, agentPresent=false y sin bloque.
Smoke: extender `AZCKeeper.K4Smoke` o correr el cliente y verificar la fila en `keeper_security_state` en DEV (ya probado 8/8 el endpoint).

**Fin Etapa 3: el ciclo agente→archivo→cliente→server→panel cierra de punta a punta con el cliente real.**

---

## Self-Review notas
- Coexistencia K3/K4: mutex y rutas distintos (constraint) — verificado en cada task que toca disco/registro/mutex.
- El flush síncrono (Task 5) resuelve el follow-up conocido del runner actual.
- Task 6 depende de que K4ApiClient exponga el status del handshake (agregado en Task 3/6).
- `client/version` endpoint: si no existe en WebK4, agregarlo en Task 9 (backend) leyendo `keeper_client_releases` (tabla ya creada en migración 03).
