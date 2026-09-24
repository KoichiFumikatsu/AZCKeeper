# AZCKeeper v4 — cliente Windows por módulos

Solución .NET 8 independiente, organizada según §4 de `docs/architecture/v4-modulos-y-brechas.md`. Agent es el único proceso con red, clave de dispositivo, política persistente y autoridad de máquina. Session captura la sesión interactiva y presenta UI. No depende de K3/K4.

## Estructura

```text
src/
  Keeper.Shared/
    Protocol/                 DTO de /client/* y serialización JSON
    Policy/                   EffectivePolicy, Rule y Schedule generados
    Contracts/                IModule, ModuleContext, ModuleSnapshot, ModuleBase, SessionProtocol
  Keeper.Agent/
    Hosting/                  ModuleHost, Scheduler, SyncSchedule, SessionSupervisor, WindowsSessionLauncher
    Transport/                SyncClient, HttpMessageSigner, NetworkBackoffPolicy
    Storage/                  DurableOutbox, PolicyStore, DeviceKeyStore, AtomicFile, InstalledTrust
    Policy/                   PolicyCoordinator
    Modules/
      Enforcement/            WebEnforcer, UsbEnforcer, InstallEnforcer, DownloadEnforcer, ISystemPolicyStore
      Devices/                CommandExecutor, Inventory
      Update/                 UpdateManager, ReleaseVerifier
      Security/               TamperGuard, DeviceLock, PinVerifier
      Diagnostics/            AgentDiagnostics
  Keeper.Session/
    Modules/
      Activity/               ActivityTracker, WindowsInputIdleSource
      Windows/                WindowTracker, WindowsForegroundSource
      Calls/                  CallDetector
      Presence/               PresenceTracker, WorkSchedule
      UI/                     LockScreen
    Ipc/                      SessionClient, SessionBuffer
installer/
  Keeper.Installer/            Package.wxs, Bootstrap.ps1, README.md
 tests/Keeper.Agent.Tests/     xUnit, ejecución serial sin elevación
```

Todos los módulos concretos implementan `IModule`, directamente o mediante `ModuleBase`/`RegistryEnforcer`, y están registrados en `ModuleHost`. Session compila como enlace el mismo código de ModuleHost: no referencia el ejecutable Agent ni recibe su transporte/credenciales. El host serializa operaciones, conserva fallos por etapa y cierra módulos en orden inverso. `ModuleContext` solo proporciona outbox, reloj, log saneado y cancelación.

`EffectivePolicy` es el único modelo de política; su proyección IPC reutiliza el mismo tipo y elimina reglas/hosts/composición innecesarios para Session. `policy_version` es `long`/`long?`, entero JSON; `EffectivePolicy.version` sigue siendo revisión opaca. Los DTO y la política se generan reproduciblemente desde el OpenAPI existente, sin editar el contrato:

```powershell
node client-v4/tools/generate.mjs --check
# Regeneración, si cambia el contrato autorizado:
npm ci --prefix client-v4/tools
npm run generate --prefix client-v4/tools
```

## Implementación real y límites

| Módulo | Implementado | Límite / pendiente |
|---|---|---|
| Hosting/Transport | Servicio SCM LocalSystem; un scheduler; sync HTTPS RFC 9421 P-256/P1363; login con clave registrada; cancelación; captura continúa durante HTTP lento | Enrollment inicial, WSS y transferencias binarias aún sin implementar |
| Storage/Policy | Outbox JSON atómico con cuota; IDs y reintento estable; ACK individual; cuarentena; DPAPI CurrentUser; caché firmada por dispositivo; solo aplica versiones crecientes; restaura offline | JSON reutilizado, no SQLite. Firma local no equivale a firma del servidor ni impide restaurar un datadir antiguo |
| Web | HKLM Chrome/Edge/Brave; blocklist y excepciones de gestión; listas numeradas | Firefox, reglas con horarios/excepciones complejas, journal de propiedad y conflictos GPO pendientes |
| USB | Deny_All o almacenamiento de solo lectura; retirada de reglas | Excepciones por dispositivo y prueba de medios conectados pendientes; no bloquea todo el bus USB |
| Download | DownloadRestrictions=3 en los tres navegadores Chromium, o retirada | Solo regla incondicional `*`; no es DLP ni bloqueo de cualquier descarga del SO |
| Installation | AppLocker EXE/MSI/Script, Audit por defecto o Enforce; AppIDSvc automático/iniciado; respaldo y restauración de política local y arranque | Requiere ensayo en Windows; no restringe MSIX/Store ni DLL; revisar GPO/MDM, rutas redirigidas y editores del tenant |
| Devices | Inbox durable, rechazo individual, deduplicación y resultados en outbox; lock/unlock; apagado/reinicio forzado inmediato (`/f /t 0`) con adapter Windows y dry-run; adapter local WTS logoff; inventario hostname/SO/arquitectura/CPU lógico/RAM | OpenAPI no tiene `logoff` ni destino de sesión. Wipe/refresh_policy devuelven no soportado. `SyncRequest` no admite specs: `Inventory.Specs` queda solo local, sin consumidor de red, pendiente de ampliar el contrato |
| Update | Verifica JWS ES256 con clave pública anclada, todos los metadatos, secuencia/canal/arquitectura/versión mínima, tamaño y SHA-256 del paquete | Verificación de paquete local en staging; sin descarga, ejecución MSI, Authenticode del paquete, transición de confianza ni rollback automático. Estado final `verified_pending_install` |
| Security | DeviceLock durable, PIN PBKDF2-SHA256 con sal, comparación constante, espera progresiva persistente; TamperGuard verifica hashes de binarios | Bloqueo operativo; no anti-robo ni deshabilitación de cuentas. Sin trust provisionado TamperGuard informa `unsupported`. Sin watchdog externo ni reconciliación del registro |
| Diagnostics | Snapshot de módulos; logs y SecurityReport con ACK del sync; `applied` tras escritura real; `dry_run`/`pending_restart` como Failed y salud degradada | `applied` confirma escritura del registro, no prueba de que cada navegador/dispositivo haya recargado la política. Estados de update pendientes se reportan Unknown con código explícito y degradan la salud |
| Activity | GetLastInputInfo sin hooks, delta monotónico, umbral idle, override acotado por llamada, cortes por día/horario, rechazo de suspensión/cambio de reloj | Umbral local 600 s; contrato sin parámetros de tracking. Los segundos viajan en episodios, no se envía un segundo acumulado ActivitySnapshot que duplique el conteo |
| Windows/Calls | Proceso/título foreground, episodios activos/idle de hasta 120 s, cambio de ventana/título, cierre de episodio; heurística de llamadas por proceso/título | Muestreo al tick de Agent, sin hook de foreground. Llamada es heurística; no captura audio ni prueba conversación |
| Presence | Primer arranque de captura del día, fecha persistida por usuario, clasificación laboral/fuera de horario, turno nocturno, timezone, cálculo local de tardanza | Marca de inicio de captura, no auditoría de logon WTS. Logs `first_login`/`late_login`; no POST check-in sin `door_id`. Festivos y transmisión de métricas de puntualidad pendientes |
| UI/IPC | WinForms por monitor, PIN enmascarado, validación solo en Agent; pipe con ACL SYSTEM/SID y denegación NETWORK; identidad PID/SID/session; framing/correlación/cuotas | Una ventana topmost no es frontera anti-robo. Falta bandeja/centro de avisos. Integración real SYSTEM↔usuario requiere VM |
| Installer / puente K3 | Bootstrap con journal/CAS, logon de recuperación, escrow verificado, auditoría, logoff y tokens; lanzador con UAC único, firma/editor/antirrollback y herramientas de firma autofirmada | No desplegable aún: requiere intermediario IT para las rutas adminSession/CSRF, integración baseline/Session y confianza inicial del receptor K3. No hay MSI firmado ni ensayo de SO. Ver [contrato y límites](installer/Keeper.Installer/README.md) |

`WindowsSystemPolicyStore` solo escribe si `KEEPER_ENABLE_HKLM=1` y el proceso está elevado. En cualquier otro caso hace dry-run. `MemorySystemPolicyStore` es el fake de los tests. Las acciones de apagado/reinicio usan el mismo bloqueo de ejecución privilegiada. El build y los tests no arrancan Agent/Session ni ejecutan adapters de máquina.

### USB y bloqueo de instalación/ejecución

USB escribe `Deny_All` bajo `RemovableStorageDevices\{53f5630d-b6bf-11d0-94f2-00a0c91efb8b}` (AllStorage), y `Deny_Write` bajo `{53f56307-b6bf-11d0-94f2-00a0c91efb8b}` (Disks). Apply/Clear retiran también los valores antiguos de las ubicaciones incorrectas.

La elección predeterminada del tenant para bloquear instalación/ejecución es **AppLocker**, mediante una regla `Installation/Deny/*`. **DownloadEnforcer es la alternativa**, mediante `Download/Deny/*`: el tenant debe elegir una u otra, sin enviar ambas como requisito conjunto. Son módulos independientes; InstallEnforcer nunca escribe `DownloadRestrictions` ni genera una regla Download. No se añade una selección al contrato de política generado; IT provisiona la configuración siguiente por tenant en los equipos.

| Variable de Agent | Valor / comportamiento |
|---|---|
| `KEEPER_APPLOCKER_MODE` | `Audit` predeterminado de pruebas, reportado explícitamente como `audit` / `audit_not_blocking`; `Enforce` solo por elección explícita del tenant. Un valor desconocido reporta `unsupported` / `invalid_configuration` al reconciliar, sin impedir el arranque del agente. |
| `KEEPER_APPLOCKER_TRUSTED_PUBLISHERS` | Array JSON de nombres completos de editor AppLocker, sin comodines; por defecto `O=MICROSOFT CORPORATION, L=REDMOND, S=WASHINGTON, C=US`. Reemplaza la lista predeterminada. `[]` deja solo permisos por ruta. No equivale a confiar en cualquier firma válida ni al almacén de certificados TrustedPublisher. |
| `KEEPER_APPLOCKER_WRITABLE_PATHS` | Array JSON de rutas absolutas adicionales terminadas en `\*`, para Downloads/AppData/TEMP redirigidos y perfiles fuera de la ruta habitual. Ejemplo JSON: `["D:\\Profiles\\*\\AppData\\*","D:\\Downloads\\*","D:\\Temp\\*"]`. No acepta variables de entorno. |

Las colecciones Exe, Msi y Script permiten `%PROGRAMFILES%\*`, `%WINDIR%\*` y editores explícitos, con cualquier producto/binario/versión. La variable **propia de AppLocker** `%PROGRAMFILES%` cubre Program Files y Program Files (x86). Se deniega toda la colección bajo `%OSDRIVE%\Users\*\Downloads\*`, `%OSDRIVE%\Users\*\AppData\*` (incluye Roaming, Local y Local\Temp), `%WINDIR%\Temp\*`, `%OSDRIVE%\Temp\*` y las rutas adicionales. No se expanden rutas usando el perfil de SYSTEM. Las rutas personalizadas deben provisionarse: el agente no descubre Known Folders redirigidos ni el TEMP de cada usuario. [Variables admitidas por AppLocker](https://learn.microsoft.com/en-us/windows/security/application-security/application-control/app-control-for-business/applocker/understanding-the-path-rule-condition-in-applocker).

Las denegaciones por carpeta cubren `.exe`, `.scr` (PE aunque cambie su extensión), `.msi`, `.bat`, `.cmd`, `.ps1`, `.vbs` y los demás formatos reconocidos de cada colección. Se usa Everyone (`S-1-1-0`): **también afecta a administradores**, sin regla de bypass. Una denegación explícita prevalece incluso sobre un editor permitido; fuera de las carpetas denegadas solo se permite lo admitido por ruta/editor. Se mantiene una colección Appx que permite paquetes firmados para evitar bloquear incidentalmente apps de Windows al activar Exe; no se pretende bloquear Store/MSIX. No se habilita DLL. [Colecciones](https://learn.microsoft.com/en-us/windows/security/application-security/application-control/app-control-for-business/applocker/working-with-applocker-rules), [precedencia](https://learn.microsoft.com/en-us/windows/security/application-security/application-control/app-control-for-business/applocker/understanding-applocker-rule-behavior).

`Audit` genera XML con `EnforcementMode="AuditOnly"`; evalúa y registra en los canales AppLocker de Windows (8003 para EXE, 8006 para MSI/scripts), sin denegar por esta política. Reporta `State="audit"`, `ErrorCode="audit_not_blocking"` y ninguna `AppliedVersion`, incluso si se omite el modo. El SecurityReport enviado al backend usa `Unknown` con `audit_not_blocking`, y Diagnostics queda degradado: nunca reporta Audit como Applied. `Enforce` usa `Enabled` y reporta `applied` tras aplicar correctamente. No se recolectan estos eventos de Windows al backend en este cambio. El antiguo bloqueo `DisableMSI` se retira solo después de aplicar AppLocker con éxito; un fallo conserva el bloqueo MSI previo. `AlwaysInstallElevated=0` se fija en cada reconciliación y retirada como defensa anti-escalada, incluso en Audit. Otras políticas GPO/MDM existentes pueden seguir bloqueando. [Auditoría](https://learn.microsoft.com/en-us/windows/security/application-security/application-control/app-control-for-business/applocker/configure-an-applocker-policy-for-audit-only).

La configuración inválida (modo, editores, rutas o JSON) retira la política administrada mediante Clear y reporta `unsupported` / `invalid_configuration`, sin backoff periódico. Un error operativo al aplicar o restaurar sigue reportándose como `failed` y conserva los reintentos.

Mecanismo: `ISystemPolicyStore` extiende `IAppLockerPolicyStore`; `AppLockerPolicyStore` coordina un `IAppLockerMachine` sustituible por fake. El adaptador Windows guarda primero la política local (`Get-AppLockerPolicy -Local -Xml`) y los valores previos `Start`/`DelayedAutoStart` de AppIDSvc en un journal HKLM de 64 bits (`SOFTWARE\AZCKeeper\v4\AppLocker`, valor `Backup`). Configura `sc.exe config AppIDSvc start= auto`, inicia/espera el servicio y aplica el XML con Windows PowerShell `Set-AppLockerPolicy -XmlPolicy`, **sin Merge**, a la política local. El XML temporal se elimina en `finally`; los subprocesos no abren ventanas y tienen timeout con terminación. Todo está detrás del mismo gate de elevación/`KEEPER_ENABLE_HKLM`; los tests usan memoria o el gate desactivado. [Set-AppLockerPolicy](https://learn.microsoft.com/en-us/powershell/module/applocker/set-applockerpolicy).

Clear (retirada, Allow o regla no soportada) restaura la política previa y el arranque persistente, incluso tras reiniciar Agent; sin journal no modifica AppLocker/AppIDSvc. El journal solo se retira tras completar ambas restauraciones. Keeper administra la **política local completa** mientras la restricción está activa: no editarla desde otra herramienta en paralelo. Las GPO de dominio y CSP/MDM no se borran ni administran con este mecanismo. AppIDSvc es protegido: Windows impide devolverlo a Manual con `sc.exe`; se restauran sus valores de registro y puede permanecer ejecutándose hasta reiniciar Windows. No se fuerza su parada ni se desactiva un servicio que ya era automático. [Limitación de AppIDSvc](https://learn.microsoft.com/en-us/windows/security/application-security/application-control/app-control-for-business/applocker/configure-the-application-identity-service).

**Windows Pro y validación pendiente:** la base prevista para Modo B es Windows 10 Pro 2004+ actualizado o Windows 11 Pro, con AppLocker/Windows PowerShell disponibles y Agent elevado/LocalSystem. Pro por sí solo no demuestra compatibilidad: Microsoft eliminó la comprobación de edición en los sistemas cubiertos por KB5024351; Windows 10 anterior tiene restricciones distintas. [Requisitos oficiales](https://learn.microsoft.com/en-us/windows/security/application-security/application-control/app-control-for-business/applocker/requirements-to-use-applocker).

En una máquina de prueba, primero Audit: comprobar política local/efectiva, AppIDSvc, eventos 8003/8006, carpetas reales (incluidas redirecciones), editores requeridos y apps de Windows. Después Enforce: probar los siete tipos de archivo desde Downloads/AppData/TEMP, binarios permitidos desde ambos Program Files/Windows y por firma, denegación de firmados en carpetas prohibidas, usuario estándar y administrador. Comprobar particularidades de hosts de scripts/PowerShell (incluido Constrained Language), instaladores que extraen a TEMP y ACL de subcarpetas escribibles dentro de directorios permitidos. Ensayar retirada, cambio Audit/Enforce, reinicio de Agent/Windows, restauración de política previa y conflictos GPO/MDM. Los tests con fakes no validan la ejecución del motor AppLocker ni sustituyen ese ensayo de SO.

Los enforcers reintentan fallos a 1, 5 y 15 minutos (tope), y solo emiten log al cambiar estado/error. Una política nueva reinicia el backoff. El dry-run no declara versión aplicada. Ante política no soportada se retiran los valores del módulo, incluso los persistidos por un proceso anterior; si falla la limpieza, el estado es Failed y se reintenta con backoff. Sin permisos para limpiar, se informa `unsupported_cleanup_dry_run`.

La cuota de 1000 comandos cuenta trabajo pendiente de publicación. Los resultados ya entregados al outbox se conservan hasta la expiración para impedir reejecuciones, sin consumir esa cuota; se purgan al aceptar comandos y durante ticks. Los rechazados tienen un ID de evento estable para deduplicar reintentos en el outbox. El apagado/reinicio usa la variante forzada inmediata, sin ventana programada de 30 segundos; no hay confirmación posterior del arranque. Si el proceso termina antes de guardar el resultado, la recuperación informa fallo por interrupción y no repite la acción.

Update revisa staging cada cinco minutos y recuerda en memoria los IDs de releases que fallaron verificación, con log Error y diagnóstico Failed. Reofrecer el mismo ID no lo vuelve a leer/hashear; un ID nuevo puede verificarse inmediatamente. Reiniciar Agent permite volver a verificar. `GlobalMemoryStatusEx` fallido propaga el error al host; no publica RAM=0 como inventario válido.

La política continúa vigente sin red; no se retiran restricciones en shutdown ni se desbloquea por vencimiento de token. Sin caché válida no se fabrica una política permisiva. Caché corrupta detiene el arranque; estado de bloqueo corrupto falla cerrado y no admite cambios hasta reparación.

## Lógica rescatada de K3

| Referencia K3 | Destino v4 y cambio |
|---|---|
| Tracking/ActivityTracker.cs | Session/Modules/Activity: medición real activo/idle, override de llamadas y cortes de día. Reloj inyectable/monotónico; no timer, ni seed acumulado aplicado dos veces |
| Tracking/WindowsTracker.cs | Session/Modules/Windows y Calls: episodios por proceso/título y heurística de llamadas; duración comprobable y lotes acotados |
| Tracking/WorkSchedule.cs | Session/Modules/Presence/WorkSchedule: laboral, almuerzo y fuera de horario; límites de intervalos, días y timezone. El contrato no trae almuerzo: el clasificador lo admite como entrada local, sin inventar campos de política ni asumir 12–13 para todos |
| Blocking/KeyBlocker.cs | Session/Modules/UI/LockScreen + Agent/Modules/Security/DeviceLock: presentación fullscreen/PIN; se elimina validación y PIN en claro en Session |
| Network/OfflineQueue.cs | Se conserva y amplía Agent/Storage/DurableOutbox: persistencia previa al envío, ACK e idempotencia; no se descartan eventos después de cinco intentos |
| Network/NetworkBackoffPolicy.cs | Agent/Transport/NetworkBackoffPolicy + Hosting/SyncSchedule: distingue DNS, transitorio y throttling, reinicia escalada al cambiar de clase, jitter y Retry-After bajo el presupuesto común. 403 es autorización, no supuesto ban |

No se portaron CoreService, MasterTimer, hooks de teclado/mouse, timers HTTP independientes, PAC ni la doble jerarquía de configuración.

## Presupuesto y transporte

- Intervalo base mínimo 120 s; fase inicial estable 0–120 s y jitter adicional 0–12 s. Un sync en vuelo y ningún envío HTTP desde módulos o Session.
- Presupuesto **amortizado**: challenge/login/sync/GET excepcional pueden consumir hasta cuatro solicitudes dentro de un ciclo. Se espera al menos `solicitudes × intervalo` después; el deadline persistido reserva cuatro antes de enviar. No constituye un límite estricto de una solicitud por cada ventana móvil de 120 s. El backlog no adelanta envíos.
- Backoff por clase: DNS 5–60 s; transitorio 5–900 s; throttling 30–1.800 s; autorización 120–3.600 s. Siempre se aplica el máximo con presupuesto ordinario, sugerencia del servidor y Retry-After; jitter positivo adicional.
- Outbox hasta 16 MiB; lotes de 128 KiB/200 elementos; límites de 200 episodios, 50 logs, 20 resultados y un reporte de seguridad. Cuota agotada falla sin borrar datos confirmados pendientes; no hay compactación/retención automática de siete días.
- Session retiene hasta 500 episodios y 100 logs en memoria, publica hasta 20 de cada uno por intercambio y libera solo IDs confirmados después de escritura durable de Agent. Reporta saturación. Una terminación forzada o pérdida de Agent puede perder el buffer y el episodio en curso; no promete captura mientras no recibe ticks. Cierre cooperativo intenta persistir el último lote por IPC.
- Sin nuevos campos `tenant_id`, SID o session_id en Episode: el contrato no los permite; se valida la identidad local por pipe. El contrato actual limita la atribución de telemetría de varias sesiones del mismo dispositivo.

## Confianza, despliegue y configuración

`installation-trust.json` se provisiona junto con los binarios mediante el instalador firmado, dentro del directorio protegido de instalación. Es un documento local de instalación, no otra política de negocio. Contiene `ReleasePublicKeys` (key_id → SPKI DER público en Base64), `BinaryHashes` (ruta relativa → SHA-256), `InstalledSequence` entero positivo y `Channel`. No se obtiene desde una release ofrecida ni contiene claves privadas. TamperGuard usa esos hashes; el supervisor exige hashes válidos de Keeper.Session.exe y Keeper.Session.dll antes de lanzarlos. Sin raíces de confianza, el update permanece no soportado y no se lanza Session en SYSTEM.

El paquete local de update se coloca bajo `staging/<release-id-sin-guiones>.msi` en el datadir protegido. Un `key_id` desconocido no instala nada. Aprovisionar y verificar Authenticode/rollback es requisito adicional de producción.

El PIN no se pasa por variables de entorno, argv, JSON de política, snapshots ni logs. `PinVerifier.SaveProtectedAsync` permite a una herramienta de provisión bajo la identidad del servicio guardar `pin-verifier.dpapi`; no hay flujo remoto de provisión porque falta en el contrato. El Agent sin ese archivo admite unlock administrativo, pero no desbloqueo por PIN. DPAPI CurrentUser queda ligado a SYSTEM cuando se instala como servicio: no trasladar blobs creados por un usuario a SYSTEM.

El supervisor enumera sesiones WTS activas/desconectadas cada 10 s, usa WTSQueryUserToken/CreateEnvironmentBlock/CreateProcessAsUser, verifica hashes, evita duplicados y espera 30 s tras fallo. Todo queda bajo el scheduler; no hay un timer de red por sesión. El ejecutable Session debe estar junto a Agent en el staging instalado. No utiliza el token SYSTEM para la UI.

Variables de Agent: `KEEPER_DATA_DIR`, `KEEPER_DEVICE_ID`, `KEEPER_API_BASE` (HTTPS terminada en `/v1/`), `KEEPER_SYNC_SECONDS` (120–3.600), `KEEPER_ENABLE_HKLM` y `KEEPER_POLICY_FILE` (entrada local de pruebas tipo CachedPolicy). Sin API configurada opera offline. No se ejecutó contra producción. El servicio instalado aún requiere provisión de identidad/configuración por IT.

## Validación sin elevación

Desde la raíz, siempre en serie:

```powershell
$env:MSBUILDDISABLENODEREUSE = '1'
try {
    dotnet build client-v4/Keeper.sln -maxcpucount:1 -nodeReuse:false
    if ($LASTEXITCODE -ne 0) { throw 'Build failed' }
    dotnet test client-v4/Keeper.sln -maxcpucount:1 -nodeReuse:false
    if ($LASTEXITCODE -ne 0) { throw 'Tests failed' }
}
finally {
    dotnet build-server shutdown
    Get-Process dotnet,MSBuild,testhost -ErrorAction SilentlyContinue
}
```

`Directory.Build.props` mantiene nullable, warnings como errores, compilación sin servidor compartido y sin paralelismo; xUnit también ejecuta en serie. NuGet usa lockfiles y caché `.local/packages`. `NuGetAudit=false` es heredado del prototipo; no se realizó una auditoría de dependencias.

Validación tras Fase D: build con **0 advertencias y 0 errores**; xUnit **148 correctos, 0 fallidos, 0 omitidos** (116 anteriores y 32 casos nuevos). Además de la cobertura existente de transporte, enforcement, sesiones, outbox, comandos y updates, cubre enrollment con identidad propia, rechazo de credenciales administrativas, parsing protegido de recuperación, firma/editor/antirrollback del puente, rutas ZIP y pruebas PowerShell del journal, auditoría, parsing y Plan/WhatIf. No se ejecutaron operaciones privilegiadas del sistema.

La prueba de conexión real de named pipe fue denegada por el sandbox (`Access denied`). La suite final prueba serialización en streams, límites y composición de ACL sin abrir pipes reales. No sustituye una prueba de impersonación, PID, SID y sesión en Windows. El generador `--check` pasó, Bootstrap.ps1 pasó el parser de PowerShell y Package.wxs se validó como XML; no se compiló con WiX.

## Máquina de prueba Windows 11 Pro

1. Empaquetar/firmar MSI, binarios y bootstrap; provisionar trust, identidad y credenciales bajo SYSTEM; validar reparación y desinstalación administrativas.
2. Probar SCM/ACL, sesiones consola/RDP/cambio rápido, lanzamiento con token de usuario, rechazo de clientes falsos/remotos, corte IPC, reconexión, PIN, varios monitores y cierre cooperativo.
3. Verificar bloqueo real web/USB/descargas, retirada de reglas, reinicio, offline y conflictos GPO. Para instalación, completar el ensayo AppLocker Audit/Enforce y restauración descrito arriba antes de declarar cumplimiento.
4. Probar enforcement resistente a usuario estándar, manipulación de binarios/datos, crash/recovery y pérdida de captura; desarrollar watchdog y reconciliación pendientes.
5. Completar descarga/instalación Authenticode/rollback del update y aprovisionamiento de PIN. No presentar la verificación criptográfica aislada como updater completo.
6. Proveer el canal IT de migración conforme al [contrato real](installer/Keeper.Installer/README.md), validar escrow/recuperación y baseline/Session antes de degradar, y ensayar tokens estándar tras logoff. Los endpoints del backend existen; el acceso requiere adminSession/CSRF y no puede sustituirse por el bearer del dispositivo.
7. Resolver en el contrato horarios de almuerzo/festivos, tracking, firma de política de servidor, atribución multiusuario, logoff, métricas de presencia e inventario. No se modificó backend/OpenAPI para simular estas capacidades.
