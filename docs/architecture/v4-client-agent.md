# AZCKeeper v4 — arquitectura del cliente/agente Windows

Fecha: 2026-09-16. Estado: diseño propuesto, sin implementación ni despliegue.

## 0. Alcance, decisiones y evidencia

Diseño para **Windows 11 Pro actualizado, WORKGROUP, usuarios actualmente administradores locales y C#/.NET como lenguaje decidido**. El estado objetivo es un servicio SYSTEM que administra el equipo y usuarios de trabajo estándar. La administración debe funcionar fuera de oficina, sin dominio, VPN obligatoria ni puertos entrantes. BitLocker se reserva para una fase futura: este documento no propone activarlo ahora.

Insumos: [brief v4](v4-requisitos-brief.md), [auditoría K3](../audits/2026-09-15-auditoria-k3.md), en particular §4 —modularidad del cliente, aunque la petición lo referencia como §3—, §5 y §9; contraste puntual del checkout `1638bd4`. Ese checkout conserva PAC; no se confunde con `931fd12`, base principal de la auditoría. K3 es WinForms `net8.0-windows` y SQLite. Se mantiene C#/.NET; la versión soportada del runtime se fijará al implementar y distribuirá con los artefactos firmados.

**Discrepancias que condicionan el diseño:**

- El brief registra FumiWork como Home y deja abierta la edición de la flota. La instrucción actual fija **Pro** como plataforma objetivo; el preflight verificará cada equipo y detendrá la migración automática de ediciones no homologadas. No se infiere la edición de toda la flota desde este PC.
- La afirmación histórica «AppLocker no funciona fuera de Enterprise/Pro» está desactualizada: Microsoft eliminó la comprobación de edición para las versiones indicadas de Windows 10 y Windows 11. Pro actualizado es apto, sujeto a configuración y prueba de enforcement. [Requisitos de AppLocker](https://learn.microsoft.com/en-us/windows/security/application-security/application-control/app-control-for-business/applocker/requirements-to-use-applocker).
- HKCU no requiere elevación para escribir en el perfil propio. El defecto arquitectónico de K3 es confiar en un contexto modificable por el usuario y en una aplicación por sesión; elevar no corrige una política de navegador no admitida o mal aplicada. v4 escribe políticas de máquina desde SYSTEM y verifica su efecto.
- «Control total» significa autoridad administrativa y enforcement de los controles homologados dentro del Windows administrado. No equivale a resistencia absoluta frente a otro administrador, acceso físico, arranque externo o reinstalación. **Sin cifrado, el bloqueo remoto no protege los archivos de un disco extraído.**

Durante la revisión apareció [openapi-v4.yaml](openapi-v4.yaml), borrador OpenAPI 3.1.1 de la tarea API: producto v4, **contrato HTTP v1**, base `/v1`. Se contrastaron sus schemas `EffectivePolicy`, `Rule`, `SyncRequest`, `SyncResponse`, `Ack`, `DeviceToken` y `Command`, y sus esquemas de autenticación. La primera lectura tenía `paths` vacío: no se da por implementado ningún endpoint. Este diseño adopta los nombres existentes y distingue las ampliaciones pendientes en §10.4; no crea otro OpenAPI ni modifica el de la otra tarea.

## 1. Procesos y fronteras de confianza

```text
                         Keeper / tenant autenticado
                    API HTTPS + gateway WSS de comandos
                                  ^  |
             salida TCP 443       |  |       sin listener público local
                                  |  v
  Equipo Windows 11 Pro ------------------------------------------------
  | Keeper.Agent.exe — Windows Service, LocalSystem, sesión 0           |
  |  ModuleHost + PolicyCoordinator + Scheduler                         |
  |  Outbox SQLite + TransportCoordinator + CommandInbox                |
  |  Enforcers HKLM/archivos/AppLocker/WDAC + cuentas + actualizaciones   |
  |  SecretStore + supervisión de sesiones                              |
  |        |                         |                                  |
  |        | CreateProcessAsUser     | CreateProcessAsUser               |
  |        v                         v                                  |
  | Keeper.Session.exe [SID A, 1]  Keeper.Session.exe [SID B, 2]          |
  | bandeja, UI/PIN, captura       bandeja, UI/PIN, captura               |
  |        ^                         ^                                  |
  |        +--- named pipes locales con ACL y protocolo versionado -----|
  |                                                                    |
  | Keeper.Shared.dll: política, DTO de red, eventos y protocolo IPC     |
  | Keeper.Installer: MSI WiX firmado + bootstrap firmado de migración   |
  ----------------------------------------------------------------------
```

### 1.1 Keeper.Agent

Servicio de inicio automático, sin interfaz gráfica. Arranca sin usuario conectado, carga la última política válida, reconcilia controles y mantiene la conexión de gestión. Es el único propietario de credenciales del dispositivo, estado persistente, aplicación de políticas de máquina y transporte de red.

LocalSystem permite operar SCM, HKLM, cuentas locales y sesiones; obliga a limitar cuidadosamente entradas y operaciones. Los módulos son componentes compilados en la distribución, no DLL arbitrarias descargables. Las órdenes remotas se traducen a operaciones tipadas permitidas; no se expone un shell SYSTEM genérico ni ejecución de rutas enviadas por Session.

### 1.2 Keeper.Session

Una instancia por sesión interactiva y SID, incluyendo cambio rápido de usuario y sesiones RDP existentes. El servicio enumera sesiones al arrancar y procesa notificaciones WTS de logon/logoff/conexión/desconexión. No presupone que solo exista la sesión de consola activa.

Lanzamiento: `WTSQueryUserToken(sessionId)` → token primario del usuario → `CreateEnvironmentBlock` → `CreateProcessAsUserW`, con ruta absoluta firmada y `winsta0\default`. Gestionar perfil si es necesario y cerrar handles. Nunca usar el token SYSTEM para la UI ni heredar secretos del entorno del servicio. [WTSQueryUserToken](https://learn.microsoft.com/en-us/windows/win32/api/wtsapi32/nf-wtsapi32-wtsqueryusertoken), [CreateProcessAsUserW](https://learn.microsoft.com/en-us/windows/win32/api/processthreadsapi/nf-processthreadsapi-createprocessasuserw).

Session funciona con el token no elevado del usuario. Muestra bandeja, estado y UI de bloqueo/PIN; captura actividad, ventanas y pantalla en su escritorio. El servicio no puede capturar el escritorio del usuario desde sesión 0. Un mutex por sesión y el supervisor evitan instancias duplicadas; el reinicio tiene backoff para evitar ciclos de crash.

### 1.3 IPC y Shared

Named pipes exclusivamente locales, con rechazo de clientes remotos, DACL para SYSTEM y el SID autorizado; comprobar además PID, token y session ID del extremo contra el proceso lanzado. La ACL por SID sola no distingue otros procesos del mismo usuario. Mensajes con versión, tipo, correlación y límites de longitud/frecuencia. Validar todos los payloads: Session es un origen de telemetría, no una autoridad de seguridad.

Session puede publicar eventos, consultar su estado y solicitar validar un PIN; no puede cambiar política, deshabilitar módulos, recuperar `device_token`/contraseña de `azcadmin` ni ordenar instalaciones. Agent entrega solo la proyección necesaria del mismo modelo de política, sin secretos. Un usuario puede terminar o manipular procesos de su sesión: se reporta falta de captura y se relanza; no se considera la telemetría una atestación infalsificable.

`Keeper.Shared` es una biblioteca sin red, UI ni dependencias de persistencia. Al implementar, generar en ella **los DTO de red y el modelo de política desde los schemas de OpenAPI**, con generación reproducible y comprobación de diferencias en CI. IPC agrega su envelope local y reutiliza esos tipos: no crea otro `EffectiveConfig`. Los adapters de Windows convierten ese único modelo a registro/XML/JSON sin mantener una segunda jerarquía de configuración de negocio. Resolver las ampliaciones de §10.4 en el schema fuente antes de generar; no parchear DTO a mano.

### 1.4 Keeper.Installer

MSI WiX firmado, instalación por máquina en `%ProgramFiles%\AZCKeeper`, con servicio, ACL, recuperación SCM y desinstalación administrativa. Bootstrap firmado del mismo proyecto para la transición K3→v4 y su única elevación. El MSI instala binarios; el coordinador de bootstrap mantiene el journal de aprovisionamiento y degradación, que no se trata como una custom action irreversible escondida en el rollback del MSI.

## 2. Contrato modular: eliminar el god object

Contrato conceptual, conservando los nombres solicitados:

```csharp
interface IModule
{
    string Name { get; }
    ValueTask Init(ModuleContext ctx);
    ValueTask ApplyPolicy(EffectivePolicy policy);
    ValueTask Tick();
    ModuleSnapshot Snapshot();
    ValueTask Shutdown();
}
```

`ModuleContext` entrega reloj monotónico/UTC, cancelación, logger saneado, publicación de eventos y capacidades locales específicas. **No entrega HttpClient, sockets, URL remota ni credenciales. Ningún módulo habla a la red directamente.** Los adaptadores de transporte pertenecen exclusivamente a `TransportCoordinator`, invocado por el planificador.

Semántica:

- `Init` se espera una vez. `ApplyPolicy` recibe una instancia validada e inmutable; es idempotente por revisión y no recrea timers ni hace HTTP. Puede habilitar/deshabilitar y reconfigurar el módulo en caliente.
- `Tick` ejecuta trabajo local vencido, con duración acotada. Los callbacks del SO publican en colas del host; no reentran en el módulo. El host serializa las llamadas de cada módulo y desacopla operaciones lentas del despacho global.
- `Snapshot` devuelve salud y estado observado: revisión deseada/aplicada, `applied`, `pending_restart`, `unsupported` o `failed`, último error saneado y contadores. Tener un valor escrito no prueba enforcement.
- `Shutdown` detiene captura y cierra episodios antes de persistir el lote final. No retira las políticas de seguridad ni depende de completar un request durante el cierre de Windows.
- El host contiene fallos por módulo y reporta degradación. No marca todo el equipo conforme cuando un enforcer falla; no reinicia el servicio completo ante cada error aislado.

| Módulo | Proceso | Responsabilidad y salida |
|---|---|---|
| `ActivityModule` | Session | `GetLastInputInfo`, activo/inactivo, suspensión y horario; publica segmentos/snapshots, sin registrar pulsaciones. |
| `WindowModule` | Session | Eventos de foreground, fallback local, cierre de episodios y timestamps. |
| `CallModule` | Session | Clasifica ventanas/procesos de llamadas y publica intervalos; heurística, no prueba de conversación ni grabación de audio. |
| `ScreenshotModule` | Session | Captura bajo política/orden tipada; entrega bytes por IPC acotado a Agent. No captura escritorio seguro; responde `unavailable` si no hay escritorio accesible. |
| `SessionUiModule` | Session | Bandeja, avisos, pantalla de bloqueo operativo y entrada de PIN. No decide desbloqueos. |
| `WebEnforcementModule` | Agent | Políticas HKLM Chrome/Edge/Brave y `policies.json` Firefox. |
| `DownloadEnforcementModule` | Agent | Restricciones de descarga según capacidades del navegador homologado. |
| `ApplicationControlModule` | Agent | AppLocker/WDAC, Windows Installer, Store y rutas de evasión por software no autorizado. |
| `UsbEnforcementModule` | Agent | Almacenamiento extraíble y excepciones aprobadas, sin confundirlo con todo dispositivo USB. |
| `ExtensionEnforcementModule` | Agent | Blocklist/allowlist de extensiones de navegador. |
| `DeviceLockModule` | Agent | Estado duradero de bloqueo, sesiones/cuentas afectadas y desbloqueo autorizado. |
| `CommandModule` | Agent | Inbox, validación, deduplicación y dispatch de comandos permitidos; publica resultados. |
| `LocalAdminModule` | Agent | Aprovisionamiento y rotación de `azcadmin`; solicita escrow al coordinador, sin HTTP propio. |
| `LoggingModule` | Ambos | Redacción en origen y buffers limitados. Session envía por IPC; Agent añade a outbox. Sin webhook directo. |

`PolicyCoordinator`, `ModuleHost`, `Scheduler`, `SessionSupervisor`, `OutboxStore` y `TransportCoordinator` son infraestructura con responsabilidades separadas. El host ensambla módulos; no absorbe su lógica, evitando reconstruir `CoreService` bajo otro nombre.

## 3. Planificador único y presupuesto de red

### 3.1 Un dueño de tiempos y envíos

Un `Scheduler` en Agent mantiene el calendario de trabajo con reloj monotónico, cola de vencimientos y cancelación. Despacha ticks locales a Session por IPC cuando hacen falta; eventos WTS/foreground y el message loop de UI no son nuevos planificadores de red. Ningún módulo posee su propio timer periódico de HTTP.

Separar **captura**, **persistencia** y **transmisión**: muestrear inactividad cada segundo no exige enviar cada segundo. Un cambio de dominio se evalúa localmente, sin consulta al backend por navegación. El PAC de K3 era loopback: la auditoría no lo identifica como productor remoto por URL.

Valores iniciales de diseño, ajustables por política dentro de límites admitidos por servidor y cliente:

| Trabajo | Cadencia/presupuesto inicial |
|---|---|
| Sync ordinario | Base de 120 s y jitter positivo de 0–12 s, aproximadamente 0,48–0,5 req/min; fase por dispositivo. Respetar `next_sync_after_seconds` del contrato, cuyo mínimo es 120 s. |
| Arranque/reconexión de flota | Distribuir el primer intento en 0–120 s; no hacer handshake inmediato más otro tras jitter. Persistir el próximo intento cuando corresponda. |
| Concurrencia | Un sync en vuelo; un canal WSS; como máximo una transferencia binaria adicional autorizada. |
| Lote de sync | Hasta 200 eventos y 128 KiB de JSON descomprimido; vale el límite que se alcance primero. |
| Descarga de update | Disponibilidad en sync, elegibilidad local por anillo/ventana; sin timer `/client/version`. Transferencia con cuota de bytes independiente y globalmente coordinada. |
| Drenaje offline | Usa el siguiente sync y su mismo presupuesto. No genera un POST por evento ni ráfagas hasta vaciar la cola. |
| Comandos interactivos | Canal WSS con cuota de mensajes/bytes; ACK inmediato pequeño y resultado persistido. No hace flush de toda la telemetría. |

Las cifras son objetivos a medir, no capacidades verificadas de la infraestructura actual. Payloads superiores al límite se fragmentan o se rechazan por contrato, sin bucle de reintento de un registro imposible de enviar. Las políticas tienen su propia cota explícita de respuesta, propuesta inicial 512 KiB, además de límites de reglas de cada navegador.

### 3.2 Outbox durable, ACK e idempotencia

SQLite pertenece a Agent, con un solo escritor, transacciones y acceso restringido. Se persiste **antes** de intentar red. Los eventos llevan `event_id` estable, `device_id`, SID/session ID de origen, secuencia, fecha UTC, versión de esquema y datos tipados. La empresa efectiva la deriva el servidor del token; nunca confía en un tenant enviado por Session.

Session entrega con ACK de persistencia local; conserva un buffer limitado hasta recibirlo. Si Agent no está disponible, acumula hasta su cuota y reporta la discontinuidad al recuperarse. No se promete captura completa tras terminar a la fuerza un proceso per-user ni después de agotar ese buffer.

El servidor deduplica por `(tenant_id, device_id, event_id)` y confirma después de persistencia durable. Un identificador local de lote y `SyncRequest.sequence` permiten correlación, pero no sustituyen la idempotencia por evento cuando cambia la composición del lote. No agregar `request_id` al body actual, que no lo admite. El ACK distingue aceptados, duplicados, errores permanentes por registro y pendientes reintentables. Un HTTP 200 con rechazos parciales no vacía toda la cola.

Snapshots acumulados se coalescen por dispositivo/sesión/día y revisión monotónica. No eliminar una revisión nueva cuando llega ACK de la anterior en vuelo. Episodios conservan IDs; cortes por medianoche/horario son deterministas. No repetir el seed de totales en arranque y primer sync. Tras timeout después de commit se reenvían los mismos IDs.

Cuotas iniciales propuestas: outbox de telemetría de 256 MiB/7 días, spool de screenshots separado de 128 MiB/24 h, ajustables y medidos en piloto. Priorizar comandos/resultados y estado de seguridad; compactar snapshots y logs repetidos antes de descartar telemetría vieja. Toda pérdida por cuota/retención genera contador y evento de discontinuidad reservado. Un fallo de red o un quinto intento nunca basta para borrar datos. Registros permanentemente inválidos pasan a cuarentena acotada. No permitir crecimiento ilimitado ni saturar disco del equipo.

### 3.3 `/client/sync` versionado y ETag de política

Ruta de integración prevista: `POST /v1/client/sync`, usando la base `/v1` del OpenAPI. Autenticación `Authorization: Bearer <device_token>` **y firma HTTP** conforme a `deviceBearer` + `deviceSignature` del borrador; no basta el bearer. Agrupa actividad, episodios, logs, seguridad, recibos de comandos y disponibilidad de update. Presencia/capacidades se integran según schemas de la tarea API.

| Dirección | Contenido según los schemas existentes |
|---|---|
| `SyncRequest` | `protocol_version=1`, `sequence`, `policy_version` conocida o null, `release_id` o null; opcionales `activity`, `episodes` (máx. 200), `logs` (50), `security` y `command_results` (20). |
| `SyncResponse` | `protocol_version=1`, `server_time`, `next_sync_after_seconds`, `policy_version`, `policy` y `release` anulables, `commands`, `episode_acks`, `log_acks`, `security_ack`, `activity_ack`, `command_acks`; renovación opcional `token`. |
| `Ack` | `event_id`, `status=accepted/duplicate/rejected`, `retryable` y `code` opcional. El cliente traduce esta semántica a persistencia/retención individual. |

`EffectivePolicy.etag` es el identificador opaco del documento de política, no el ETag del response compuesto de sync. El hash representa la composición completa: empresa, sede/área, usuario, dispositivo, horarios y versión del compilador de política. El request actual lleva `policy_version`, no `policy_etag`: conciliar el envío del ETag conocido en una cabecera específica de política o extensión del schema antes de implementar; no inventar un campo que `additionalProperties=false` rechazaría. `policy=null` señala ausencia de documento nuevo; el borrador no incluye `policy_changed`. **ETag ahorra bytes y recomposición; el planificador y el batching reducen requests.**

El POST responde 200 con ACK aunque la política sea idéntica. No usar `If-None-Match`/304 para saltarse la ingestión del POST. Si el contrato futuro incluye un GET independiente de política para diagnóstico/recuperación, ese recurso sí puede usar ETag y 304; no se consulta periódicamente además de sync. [Semántica HTTP condicional](https://www.rfc-editor.org/rfc/rfc9110.html#section-13.1.2).

### 3.4 Backoff, cuotas y NAT

- Un coordinador calcula `not_before`: máximo entre presupuesto local, backoff, `Retry-After` y sugerencia válida del servidor. Red caída/5xx: exponencial con jitter, base propuesta 5 s y techo 15 min; los intentos siguen sujetos al presupuesto ordinario, sin dos mecanismos que se sumen.
- 429 respeta `Retry-After` como mínimo, incluso si supera el techo del backoff; añadir dispersión posterior. Un solo intento de prueba al reabrir el circuito. No gastar intentos de un evento cuando ni siquiera se hizo red.
- 401 con código de credencial inválida/expirada permite una renovación controlada con prueba de posesión. 403 es autorización/infraestructura: no borrar token ni volver a enrollar en bucle. Revocación explícita pasa a estado de recuperación administrada.
- Reconexión WSS comparte la coordinación de fallos del dispositivo. Un proxy que rechaza WebSocket puede abrir un circuito específico de ese transporte sin bloquear sync HTTPS sano. Tras caída de red general no se lanzan loops independientes de sync, WSS, logs y update.
- El rate limit se aplica a la identidad **`device_token` validado → `(tenant_id, device_id)`**, con contadores atómicos compartidos entre instancias. Rotar el token no reinicia cuota. Propuesta de servidor: 1 request/min sostenido y burst 3 para sync, por encima de los 0,5/min ordinarios; comandos, bootstrap y binarios tienen cuotas explícitas separadas.
- Cuota agregada por tenant proporcional a flota y recuperación, con equidad entre dispositivos. **La IP no representa al tenant ni al dispositivo.** Endpoints sin autenticar necesitan controles por invitación/prueba de posesión y protección de infraestructura que tolere NAT, no un baneo de empresa por actividad legítima.

### 3.5 Capacidad resultante

| Equipos tras una IP | K3, 4,4–9 req/min/equipo | v4 ordinario, 0,5 req/min/equipo |
|---:|---:|---:|
| 180 | 13,2–27 req/s | 1,5 req/s |
| 1.000 | 73,3–150 req/s | 8,33 req/s |
| 5.000 | 366,7–750 req/s | 41,67 req/s |

Modelo de carga a cadencia base de 120 s, no benchmark: reducción aproximada de **88,6–94,4%** frente a esos escenarios K3, sin sumar tráfico extraordinario. Para 1.000 equipos quedan 500 sync/min, distribuidos en el tiempo —algo menos al sumar jitter positivo—, y aproximadamente 1.000 conexiones WSS persistentes. Los frames WSS consumen recursos aunque no sean requests HTTP nuevos.

El backend necesita gateway asíncrono para conexiones persistentes, ingestión por lotes, caché de política efectiva e índices/idempotencia; no un worker PHP-FPM reservado por dispositivo. El hosting compartido con baneo CSF por IP no satisface este contrato. Ajustar proxy/firewall/LFD y límites de conexiones antes del rollout: cambiar el rate limiter de aplicación no elimina un ban anterior a la aplicación.

Keep-alive/HTTP/2 reducen reconexiones por equipo, pero no convierten 1.000 equipos en una sola conexión. Batching tampoco elimina filas de telemetría. Medir requests, sockets, bytes, filas, edad de outbox y latencia por separado. Si la producción sostenida supera 200 eventos/128 KiB por 120 s, revisar granularidad/compresión/cuotas y capacidad del servidor; no acelerar silenciosamente la cadencia.

## 4. Política única, tipada, versionada y offline

`EffectivePolicy` generado es la única definición compartida. Su forma actual contiene `tenant_id`, `device_id`, `version`, `etag`, `rules`, `schedules`, `composition` y `management_hosts`. El servidor resuelve herencia y conflictos; no repetir la composición global/tenant en cada cliente. Los overlays por SID y el sobre firmado requieren ampliar ese contrato, no crear un `ClientPolicy` paralelo.

La tabla siguiente describe **requisitos semánticos de ampliación**, no propiedades JSON que ya acepte el borrador. Representarlos mediante variantes tipadas de `Rule` y metadatos del envelope en el mismo OpenAPI; conservar los nombres wire existentes donde ya cubren el requisito. `version` es hoy un string opaco: no ordenarlo lexicográficamente como revisión monotónica.

| Campo/grupo | Tipo y significado |
|---|---|
| Identidad | `schema_version`, `revision` monotónica, `tenant_id`, `device_id`, `issued_at`, hash de composición y compatibilidad mínima de agente. |
| Firma | Envelope con `key_id`, algoritmo fijado por versión y firma sobre bytes exactos de payload; no firmar un JSON y verificar otro reserializado. |
| `tracking` | Flags y parámetros tipados de actividad/ventanas/llamadas, umbrales y captura de títulos. |
| `schedule` | Zona horaria, días aplicables y tramos; UTC para eventos y reloj monotónico para duraciones. |
| `web`, `downloads`, `extensions` | Patrones normalizados, excepciones, enums de modo y navegadores admitidos. |
| `applications`, `usb` | Auditoría/enforcement, catálogo de aplicaciones permitidas, clases de almacenamiento y excepciones. |
| `device_lock` | Estado, revisión, motivo visible y modo operativo/anti-robo; ningún PIN en claro. |
| `screenshots`, `logging` | Activación, frecuencia máxima, tamaño/retención y redacción. |
| `transport`, `updates` | Cadencia dentro del presupuesto, cuotas, canal/anillo y ventana; sin libertad para cambiar a cualquier host. |
| `session_overrides` | Restricciones por SID del mismo schema; nunca otra clase paralela de configuración. |

Enums y unidades son explícitos: segundos con sufijo `_seconds`, bytes con `_bytes`, sin duplicados minutos/segundos ni diccionarios libres para flags de seguridad. El cliente rechaza versiones mayores desconocidas y controles obligatorios no soportados; mantiene la última política válida y reporta incompatibilidad. Los campos opcionales nuevos solo se ignoran si el contrato permite hacerlo sin debilitar controles.

La política se firma en el servidor; el Agent verifica con claves públicas ancladas en el instalador. Elegir y fijar, por ejemplo, ECDSA P-256/SHA-256 y formato de firma para la versión inicial. `key_id` selecciona una clave ya confiable; no convierte en confiable una clave incluida por el emisor. Rotación mediante transición firmada por confianza existente. Separar claves de política, releases y escrow.

Guardar envelope y estado de aplicación en `%ProgramData%\AZCKeeper`, ACL para servicio/SYSTEM, escritura temporal + sustitución atómica, última versión válida y journal por enforcer. Verificar firma, destino y revisión al cargar. Una revisión inferior solo se acepta mediante autorización de rollback firmada y explícita; el ETag no sustituye firma ni protección frente a replay.

Flujo: validar todo → registrar intención → aplicar diferencias por módulo → leer estado observado → guardar resultados. Registro/archivos/AppLocker no comparten una transacción atómica: el journal permite reanudar o revertir lo propio, sin declarar éxito global tras aplicación parcial. Política sin cambios no reinicia captura, update o conexiones. La retirada de una regla es una operación explícita; el apagado del servicio no desinstala controles.

**Sin red:** continuar con la última política válida y sus horarios; persistir eventos; mantener bloqueo ya recibido, restricciones y control de cuentas. Una pérdida de red o vencimiento del token no desbloquea el equipo. Separar frescura de política de autorización: marcarla desactualizada tras el umbral, sin levantar restricciones por vencimiento. Excepciones temporales expiran conservadoramente; el reloj de pared local no puede extenderlas indefinidamente. Primera instalación exige baseline firmado y enrollment antes de degradar al usuario.

Las políticas HKLM son comunes a todas las sesiones. No alternar su contenido según quién tenga el foreground: causa carreras y permite evasiones. Para requisitos distintos por usuario, el servidor calcula una base de máquina compatible con todos —por ejemplo, unión de bloqueos e intersección de permisos—; reglas realmente individuales requieren un mecanismo con alcance por SID, como AppLocker. Si una excepción per-user no se representa sin debilitar esa base, informar `unsupported` en vez de prometerla. Horarios/UI/tracking sí se aplican por sesión.

Un PIN operativo se valida en Agent, con verificador protegido, límite de intentos y espera progresiva; no aparece en política compartida, logs, argv ni snapshots. El desbloqueo anti-robo requiere una orden administrativa válida o recuperación IT: no lo habilita el PIN cotidiano.

## 5. Enforcement en Windows 11 Pro

Agent aplica configuración local de máquina sin requerir AD/GPO de dominio. Cada control tiene prueba funcional además de lectura del registro. Versiones de navegador, edición/build de Windows y estado de Application Identity forman parte de capacidades reportadas.

### 5.1 Matriz de mecanismos

| Control | Mecanismo SYSTEM | Verificación y límite |
|---|---|---|
| Web Chrome | `HKLM\SOFTWARE\Policies\Google\Chrome\URLBlocklist`, entradas numeradas `REG_SZ`; `URLAllowlist` para excepciones explícitas. | `chrome://policy` y navegación real. Normalizar sintaxis del fabricante; no tratar patrón URL como regex arbitraria. [Chrome URLBlocklist](https://chromeenterprise.google/policies/url-blocklist/). |
| Web Edge | `HKLM\SOFTWARE\Policies\Microsoft\Edge\URLBlocklist` y `URLAllowlist`. | `edge://policy`, perfiles/invitado/privado homologados; impedir perfiles personales que no respeten los controles exigidos. Límite documentado de 1.000 entradas: rechazar exceso, no truncarlo. [Edge URLBlocklist](https://learn.microsoft.com/en-us/deployedge/microsoft-edge-policies/URLBlocklist). |
| Web Brave | `HKLM\SOFTWARE\Policies\BraveSoftware\Brave\URLBlocklist` y excepciones compatibles. | `brave://policy`; homologar el template/versionado propio de Brave, no asumir equivalencia completa con Chrome. [Políticas Brave](https://support.brave.com/hc/en-us/articles/360039248271-Group-Policy). |
| Web Firefox | `<instalación protegida de Firefox>\distribution\policies.json`, `policies.WebsiteFilter.Block/Exceptions`. | `about:policies`, sintaxis y límites propios. Merge atómico con políticas existentes y ACL del directorio; bloquear copias portables/no administradas mediante control de aplicaciones. [Políticas Mozilla](https://mozilla.github.io/policy-templates/#websitefilter). |
| Descargas Chromium | `DownloadRestrictions` como `REG_DWORD` en la raíz HKLM del navegador; `3` para bloquear todas las descargas soportadas por esa política. Otros modos se mapean desde enums. | Prueba de descarga y refresh en cada versión. No bloquea guardar página/PDF ni convierte la política en DLP. [Chrome](https://chromeenterprise.google/policies/download-restrictions/), [Edge](https://learn.microsoft.com/en-us/deployedge/microsoft-edge-policies/DownloadRestrictions). |
| Descargas Firefox | Sin atribuirle `DownloadRestrictions` de Chromium. El catálogo consultado no ofrece un equivalente general de bloqueo total. | Con `downloads=block_all`, Firefox no se admite hasta homologar una solución equivalente; se exige navegador compatible. Ajustar carpeta o bloquear tipos peligrosos no equivale a bloquear descargas. [Catálogo Mozilla](https://mozilla.github.io/policy-templates/). |
| Instalación/ejecución | Usuario estándar + AppLocker para ejecutables, MSI, scripts, DLL y apps empaquetadas; WDAC/App Control for Business como base fuerte de código permitido cuando se homologue. | Auditoría antes de enforcement; reglas de publisher/hash o rutas realmente no escribibles. Cubrir instalaciones per-user, portables, scripts, launchers y herramientas de descarga. AppLocker requiere Application Identity operativo. |
| Windows Installer | `HKLM\SOFTWARE\Policies\Microsoft\Windows\Installer\DisableMSI=1` como base: restringe MSI no administrados; `AlwaysInstallElevated` no habilitado en ámbitos de máquina/usuario. | `DisableMSI=2` bloquea incluso reparaciones y actualizaciones MSI: solo con ventana de mantenimiento controlada. No bloquear el propio MSI Keeper. EXE/MSIX/portables se controlan aparte. [DisableMSI](https://learn.microsoft.com/en-us/windows/win32/msi/disablemsi). |
| Microsoft Store off | En Pro, AppLocker con reglas de aplicaciones empaquetadas para impedir Store y paquetes no autorizados; controlar también App Installer/winget y sideloading según catálogo. | `RemoveWindowsStore` está marcado como no soportado en Pro en la política documentada: no basta escribir esa clave. Probar Store, instalación alternativa y continuidad de actualizaciones autorizadas. [Soporte de RemoveWindowsStore](https://learn.microsoft.com/en-us/windows/client-management/mdm/policy-csp-admx-windowsstore), [Consideraciones de Store](https://learn.microsoft.com/en-us/windows/configuration/store/). |
| USB de almacenamiento | `HKLM\SOFTWARE\Policies\Microsoft\Windows\RemovableStorageDevices`: `Deny_All` o reglas por clase para lectura/escritura/ejecución. | Probar medio ya conectado y reconexión; informar reinicio si corresponde. No bloquea todo el bus USB: teclado, mouse y otros periféricos requieren tratamiento distinto. [RemovableStorage](https://learn.microsoft.com/en-us/windows/client-management/mdm/policy-csp-admx-removablestorage). |
| Extensiones | Chromium: `ExtensionInstallBlocklist` y excepciones permitidas; preferir compilación coherente a `ExtensionSettings` cuando se necesite mayor detalle. Firefox: `ExtensionSettings`, default bloqueado y IDs permitidos. | Probar instalación y extensión previamente instalada; no mezclar políticas con precedencia contradictoria. Excepciones/forzadas se validan por navegador y WORKGROUP. [Chrome ExtensionSettings](https://chromeenterprise.google/policies/extension-settings/), [Firefox ExtensionSettings](https://mozilla.github.io/policy-templates/#extensionsettings). |
| Bloqueo operativo | Agent conserva estado y autoriza UI/PIN; Session presenta el aviso y puede bloquear sesión con primitivas Windows. | Una ventana a pantalla completa no es una frontera de seguridad; matar Session no cambia el estado del Agent. |
| Anti-robo | Orden duradera y autenticada, deshabilitar cuentas de trabajo administradas y cerrar sus sesiones; preservar canal SYSTEM y recuperación `azcadmin`. | No basta `LockWorkStation`: el usuario podría volver con su contraseña. Persistir y reaplicar tras reinicio. Sin conexión no llega una orden nueva. Sin BitLocker no hay protección del disco offline. |

AppLocker aporta control operacional y defensa en profundidad; Microsoft recomienda App Control for Business para protección robusta frente a amenazas. Se propone AppLocker primero por reglas por usuario y transición auditada; WDAC se añade tras inventario, pruebas de compatibilidad y recuperación, con políticas de máquina. No activar ambos motores con reglas contradictorias. [Límites de AppLocker](https://learn.microsoft.com/en-us/windows/configuration/lock-down-windows-10-applocker), [Disponibilidad de App Control](https://learn.microsoft.com/en-us/windows/security/application-security/application-control/windows-defender-application-control/feature-availability).

### 5.2 Alcance real y prevención de auto-bloqueo

SYSTEM es el propietario operativo estable porque escribe HKLM, Program Files, políticas de ejecución y cuentas, antes y después del logon. HKCU representa solo un usuario; desde SYSTEM además apunta al perfil del servicio, no al usuario interactivo. El fallo K3 no se soluciona ejecutando toda su UI como administrador.

La política web de navegador no filtra todo TCP/HTTP del sistema. El perfil estricto combina navegadores administrados y allowlist de aplicaciones, limita intérpretes/herramientas que permitan evasión y prueba perfiles alternos. No afirmar bloqueo universal de descargas desde toda aplicación autorizada ni prevención completa de exfiltración. Si se exige inspección/filtrado de cualquier tráfico, será necesario un diseño adicional de proxy/WFP/DLP; no se declara resuelto por URLBlocklist.

API, gateway y distribución firmada tienen rutas de gestión protegidas de conflictos de política. Agent usa su configuración de transporte de máquina, sin heredar PAC/HKCU del usuario; un proxy empresarial necesario se configura explícitamente. No desactivar validación TLS para que una red funcione. La allowlist de gestión es mínima y no permite navegar libremente por dominios ajenos desde Session.

Los enforcers registran exactamente qué valores/archivos/reglas pertenecen a Keeper y sus valores previos. No borran listas de otra herramienta ni restauran un proxy solo por contener `127.0.0.1`. Si detectan otro administrador de políticas, reportan conflicto y detienen la toma de propiedad hasta resolución de IT.

### 5.3 Anti-robo y recuperación

`DeviceLockModule` distingue bloqueo operativo reversible por PIN de **bloqueo anti-robo de equipo**. Este último registra primero orden/revisión en almacenamiento SYSTEM, deshabilita únicamente los SIDs de trabajo inventariados y cierra sesiones —incluidas desconectadas— según la orden. Al no crear nuevas sesiones de esas cuentas, no depende de mostrar una UI antes del logon. El panel informa efectos parciales y pérdida potencial de trabajo no guardado antes de emitir la orden.

No deshabilitar SYSTEM, cuentas internas del SO ni el admin de recuperación. Registrar cuáles cuentas estaban habilitadas previamente; desbloquear no reactiva cuentas que ya estaban deshabilitadas por otro motivo. El servicio continúa conectado sin sesión. Reversión por orden firmada/autenticada o procedimiento IT con `azcadmin`; el PIN ordinario no abre ese camino. Verificar Microsoft accounts vinculadas y formas de logon reales en el piloto; no prometer bloqueo de cuentas no incluidas en inventario.

**BitLocker + TPM + custodia de recovery keys queda anotado para futuro, fuera de implementación actual.** No activar cifrado, cambiar protectores, rotar recovery keys ni implementar borrado remoto en estas fases. El alcance actual de anti-robo es denegar uso del Windows administrado después de recibir la orden; cifrado es la dependencia pendiente para proteger datos con acceso físico.

## 6. Anti-tamper y mantenimiento legítimo

| Superficie | Protección propuesta |
|---|---|
| Servicio | DACL del objeto SCM: usuario estándar sin `STOP`, `CHANGE_CONFIG`, `DELETE` ni cambios de seguridad. Ruta absoluta entrecomillada, sin carpetas de carga escribibles por usuario. |
| Binarios | Program Files, ACL de escritura para SYSTEM/instalación autorizada; usuarios solo lectura/ejecución de lo necesario. Firma de binarios y verificación de los componentes cargados/actualizados. |
| Estado y registro | ProgramData privado y claves HKLM de Keeper protegidas. Journal, política firmada y hash de integridad; reconciliación de drift con eventos cuando sea posible y comprobación periódica local. |
| Secretos | Almacén bajo identidad SYSTEM, DPAPI y ACL; claves de dispositivo con almacenamiento CNG/TPM cuando se homologue. DPAPI de máquina por sí solo no protege si cualquier usuario puede leer el blob. |
| Recuperación de Agent | Recovery actions SCM para crash; watchdog mediante tarea SYSTEM protegida que verifica heartbeat local y reinicia un servicio atascado con límite de intentos. Reutiliza binario firmado en modo healthcheck, sin otro agente con red. |
| Recuperación de Session | Supervisor WTS en Agent y heartbeat IPC. Si el usuario termina Session, se relanza y se reporta pérdida de captura; los controles de máquina siguen presentes. |
| Actualización/desinstalación | Ventana de mantenimiento acotada y autorizada, visible al watchdog; paquete firmado y rollback probado. Un booleano escribible por usuario no desactiva la protección. |

No usar ACL que dejen a Windows Installer o a recuperación IT sin posibilidad de reparar. Probar reparación y desinstalación administrativa tanto conectado como offline. Suspender watchdog solo para una operación validada, con expiración y reanudación tras crash.

**Límite:** un administrador local puede tomar propiedad, alterar servicios/tareas, recuperar secretos o modificar el arranque. ACL + watchdog dificultan manipulación accidental y por usuario estándar; no son blindaje absoluto contra admin. Por eso degradar usuarios no es opcional para cumplir el modelo. Ocultar `azcadmin` solo reduce exposición visual, no lo vuelve indetectable ni seguro por sí mismo.

Keeper no se anuncia como Protected Process Light ni como servicio antimalware protegido. Ese mecanismo tiene requisitos de firma/ELAM ajenos a un servicio .NET común. [Protección de servicios antimalware](https://learn.microsoft.com/en-us/windows/win32/services/protecting-anti-malware-services-).

## 7. Bootstrap sin visitar 1.000 equipos

### 7.1 Prerrequisitos de confianza

**Firmar y verificar el updater es un prerrequisito de producción.** No basta agregar un hash al ZIP ni firmar únicamente el MSI: K3 ejecuta el updater incluido en el paquete antes de verificarlo. La release puente debe incorporar verificador confiable, manifests firmados con versión/canal/tamaño/SHA-256, editor esperado anclado, antirrollback y validación antes de extraer/ejecutar. Artefactos y ejecutables Authenticode firmados y sellados temporalmente; staging protegido y reverificación después de elevar para evitar sustitución entre comprobación y uso.

Hay un límite de arranque de confianza: el K3 ya instalado **no autentica criptográficamente su primera release puente**. Usar ese canal como transporte no arregla retroactivamente esa carencia. La transición requiere validar la release puente mediante un medio ya confiable —RMM existente si lo hubiera, o distribución verificada por IT y comprobación del editor esperado en la elevación—. Esta segunda opción conserva una sola elevación, pero depende de la validación humana inicial; no se presenta como garantía automática frente a un canal K3 ya comprometido. Si no puede establecerse esa confianza, ese equipo queda pendiente de recuperación asistida y no se degrada.

Antes del piloto deben existir enrollment autenticado, aislamiento por tenant, escrow de contraseñas y recuperación administrativa. El GUID o bearer heredado obtenido por el reenroll vulnerable no bastan para autorizar la migración de identidad: emitir autorización de migración de un solo uso para tenant/device, tras validación administrativa independiente.

### 7.2 Secuencia con journal reanudable

```text
K3 transporta release puente verificada
  -> preflight + artefactos firmados preparados
  -> consentimiento UAC UNA vez (usuario todavía admin)
  -> bootstrap elevado -> MSI -> Agent SYSTEM sano
  -> enrollment v4 con prueba de posesión y tenant fijo
  -> crear azcadmin -> comprobar cuenta y acceso de recuperación
  -> escrow durable y recuperable confirmado por Keeper
  -> baseline aplicada + sesión lanzada + recuperación comprobada
  -> retirar a usuarios de trabajo del grupo Administradores
  -> cerrar sesiones/reiniciar para eliminar tokens admin antiguos
  -> verificar usuario estándar -> ocultar azcadmin -> confirmar migración
```

1. **Preflight no elevado:** identificar release/canal, edición/build, disco, servicios previos, sesiones y conectividad. Preparar descarga escalonada por tenant/anillo. Si se cancela UAC, conservar K3 y marcar pendiente; no reintentar con prompts constantes.
2. **Una elevación:** ejecutar bootstrap firmado con UAC `runas`. Para un admin con política UAC de consentimiento, pide consentimiento, no contraseña. No cambiar ni sortear UAC. Si el equipo exige credenciales por política, está fuera de esa premisa y se registra como excepción. El mismo coordinador elevado instala y aprovisiona; no encadena prompts.
3. **Instalar y comprobar:** MSI firmado instala Agent/Session, ACL y recuperación. Verificar servicio SYSTEM activo, IPC y conectividad. La desinstalación/migración de K3 será posterior al éxito, sin dos capturadores publicando la misma actividad.
4. **Identidad:** Agent genera identidad/prueba de posesión y consume la autorización de migración ligada a empresa y dispositivo. Recibe `device_token` propio, nunca el token del usuario interactivo como credencial definitiva. Backend rechaza reasignaciones implícitas entre empresas.
5. **Crear `azcadmin`:** contraseña aleatoria criptográfica única por equipo, por ejemplo 32 caracteres compatibles con complejidad local; no derivada del GUID, serial, empresa ni contraseña común. Usar APIs de cuentas, sin secretos en comandos/logs/MSI properties. Registrar SID creado; grupos por SID bien conocido, no nombre localizado. Si el nombre ya existe y no está probado que lo creó Keeper, detener aprovisionamiento, no sobrescribirlo.
6. **Verificar antes de degradar:** cuenta habilitada, pertenece a Administradores (`S-1-5-32-544`), credencial probada mediante logon controlado y vía de acceso de recuperación funcional. No copiar perfiles ni cambiar contraseñas del usuario de trabajo. La contraseña admin se trata solo en memoria protegida y almacén restringido durante el proceso.
7. **Escrow confirmado:** enviar a Keeper un sobre cifrado para la bóveda con tenant/device/SID/revisión de secreto. Exigir ACK de persistencia y verificación de que la bóveda puede recuperar el secreto; un 200 de «mensaje encolado» no basta. Si hay timeout, consultar/reintentar con el mismo ID de operación. Sin confirmación, **no degradar**.
8. **Preparar recuperación y política:** comprobar que instalación/update de Keeper están permitidos; arrancar Session; probar la política baseline y conectividad de gestión. AppLocker/WDAC permanecen en auditoría hasta homologar aplicaciones. Guardar estado previo de grupos, políticas y cuentas; validar mecanismo IT para reparar offline. El piloto comprueba además logon manual y elevación con `azcadmin`.
9. **Degradar por SID:** retirar de Administradores a los usuarios de trabajo inventariados y asegurar membresía apropiada de Users. Revisar todas las cuentas administrativas, incluida Administrator integrada, para no dejar una contraseña conocida que permita volver a elevar. Conservar únicamente cuentas de recuperación aprobadas, con credenciales bajo control de IT. Una cuenta desconocida bloquea la conformidad; no borrarla automáticamente.
10. **Hacer efectiva la degradación:** los tokens ya emitidos conservan privilegios. Avisar y programar cierre de todas las sesiones afectadas o reinicio controlado —preferido si quedan procesos elevados—; no declarar éxito por haber editado un grupo. Tras nuevo logon, verificar token estándar, ausencia de otros tokens admin de trabajo y funcionamiento de apps/Session.
11. **Ocultar `azcadmin`:** proponer `HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\SpecialAccounts\UserList\azcadmin=0` como medida visual. Homologar su efecto en pantalla de logon y UAC en las builds objetivo; comprobar entrada explícita `\.\azcadmin` y credencial de recuperación. Si ocultarlo impide recuperar, conservarlo visible y reportar excepción hasta resolverla. Ocultar nunca implica deshabilitar ni borrar la cuenta.
12. **Cerrar migración:** confirmar salud, estado estándar, escrow y capacidad de actualización. Drenar/importar datos K3 con IDs de migración; retirar su autoarranque/captura solo cuando Agent y Session sean dueños. Futuras actualizaciones las ejecuta el servicio SYSTEM con firma, sin nuevas elevaciones por el usuario.

Cada paso deja un journal sin secretos y es reanudable después de corte eléctrico. Antes de degradar, cualquier fallo mantiene al usuario admin y declara bootstrap incompleto. Después de degradar, **nunca revertir borrando el único admin de recuperación ni desinstalando el canal de gestión**. La reparación usa Agent sano, reinstalación firmada por IT o `azcadmin`; no una promoción automática a admin por pérdida de red. Rollback de binarios y rollback de privilegios son operaciones distintas.

### 7.3 Gestión tipo LAPS en WORKGROUP

Se implementa custodia/rotación **tipo LAPS propia de Keeper**. Windows LAPS respalda a AD/Entra según unión del equipo; no se presume esa infraestructura en WORKGROUP. [Windows LAPS y estados de unión](https://learn.microsoft.com/en-us/windows-server/identity/laps/laps-overview).

La bóveda cifra secretos por tenant/dispositivo con claves fuera de la BD de aplicación; permisos separados para registrar, recuperar y rotar; auditoría de cada lectura. `device_token` puede escribir su propio escrow, no leer contraseñas de otros dispositivos ni listar la bóveda. No incluirlas en inventario, snapshots, exportaciones ni logs. Acceso IT autenticado y restringido a la empresa correspondiente.

Rotación con estados: generar nuevo secreto → guardar versión pendiente cifrada y confirmar custodia → cambiar cuenta local y verificarla → confirmar activación al servidor. Mantener recuperables las versiones necesarias hasta resolver el ACK ambiguo; nunca descartar la única contraseña que realmente funciona. Rotar por calendario y después de uso administrativo confirmado. Si no hay red/bóveda, posponer rotación conservando recuperación válida; no generar una contraseña irrecuperable.

Despliegue por anillos propuestos: laboratorio → 5 equipos IT → 20–50 equipos representativos → lotes de hasta 100 con pausas y cuotas por tenant. Detener expansión ante cualquier pérdida de admin recuperable, firma inválida, democión incompleta o fallo de aplicación crítica. Los equipos apagados o sin consentimiento permanecen pendientes visibles; el mecanismo evita visitas rutinarias, no elimina la necesidad de intervención en excepciones.

## 8. Comunicaciones y comandos remotos

HTTPS para sync/enrollment/artefactos, WSS para órdenes/notificaciones; salida 443 desde cualquier red permitida. Validación normal de certificado/nombre, credenciales solo en cabeceras o frames de autenticación seguros, nunca query strings. Timeouts y cancelación; cliente HTTP persistente administrado por infraestructura de Agent. Firma de política y release complementa TLS.

**Opción elegida: WebSocket persistente por equipo** en gateway asíncrono. Autenticación ligada a dispositivo/empresa, renovación/revocación sin loops y heartbeat con jitter inferior al timeout de inactividad del proxy. Inicialmente probar 45–75 s para ping/pong y medir consumo real. Cambios de red, suspensión y reanudación reconstruyen el canal con backoff; no hay puertos entrantes ni dependencia de la IP de oficina.

El gateway entrega comandos pequeños completos y autenticados; la política llega por sync tras una notificación coalescida, o se admite envelope firmado acotado si se incorpora al protocolo. El comando no exige esperar el siguiente sync para ejecutarse. Objetivo de aceptación: recepción p95 ≤5 s en equipos despiertos con canal sano; no es garantía para equipos apagados/offline ni durante backoff.

Reutilizar `Command` del OpenAPI: `id`, `tenant_id`, `device_id`, `type`, `status`, `created_at`, `expires_at` y `result`. El envelope WSS agrega versión/transporte y debe acordar secuencia/revisión y autorización verificable en el contrato. Persistir inbox antes de ejecutar. Duplicados devuelven el resultado anterior; órdenes vencidas se rechazan. Bloquear/desbloquear necesita revisión ordenable para que un lock viejo reenviado no anule un unlock reciente: el schema actual aún no la incluye. Mapear recepción/ejecución a los estados existentes `delivered`, `running`, `succeeded`, `failed`, `expired`; el panel no confunde recepción con éxito.

El enum actual incluye `wipe`, pero esta versión del Agent **no lo implementa**: anunciar capacidad ausente y responder `failed` con código `unsupported_command`, sin ejecutar borrado ni reinterpretar la orden. Screenshots tampoco aparecen en ese enum: añadir el contrato correspondiente antes de habilitar su solicitud remota. El PIN y el modo anti-robo requieren distinguirse explícitamente; no inferir ambos de un `lock` ambiguo.

La entrega es al menos una vez. Efectos como bloquear/reconciliar son idempotentes; operaciones no idempotentes necesitan registro duradero y comprobación del estado antes de reintentar. Validación de permisos y tenant en backend antes de emitir y en Agent sobre el destino; nunca confiar en el tenant declarado por quien abre el socket. El desbloqueo sensible/recuperación usa autorización firmada y auditable.

El resultado vuelve por WSS cuando está disponible y queda en outbox hasta confirmación durable. Sync permite recuperar comandos/ACK perdidos. Una pérdida del socket no autoriza aumentar el polling indefinidamente: se declara capacidad remota degradada.

**Alternativa explícita: long-poll HTTPS**, solo si proxy/gateway lo requiere. Una solicitud pendiente, por ejemplo 150 s, produce aproximadamente 0,4 req/min/equipo incluso sin comandos; sumada al sync da **~0,9 req/min**, no 0,5. Un timeout de 25 s añadiría 2,4 req/min. Requiere gateway asíncrono, límites de conexiones y cuota separada; no sostener PHP-FPM por equipo. Si se activa, registrar el modo y ajustar el objetivo medido, sin esconder tráfico adicional. WSS es la elección para conservar ~0,5 requests HTTP/min en régimen estable y respuesta a órdenes en segundos.

Screenshots y paquetes no se envían dentro del lote de telemetría. Agent conserva un spool y `TransportCoordinator` realiza upload/download autorizado con límites, hash, caducidad y destino admitido. Las rutas exactas de transferencia se acordarán en OpenAPI. Transferencias, bootstrap, renovación excepcional, reconnect HTTP de WSS y sync adelantado por política cuentan como extras medibles; no se presentan como parte gratis del objetivo.

«Remoto» aquí cubre administración, comandos, bloqueo y screenshots. Si posteriormente se requiere escritorio interactivo con video/input, necesita protocolo, permisos y presupuesto propios; no se promete que WSS de comandos lo implemente automáticamente.

## 9. Mapeo K3 → v4

Rutas bajo `AZCKeeper_Client/`, salvo indicación. Reusar significa extraer lógica contrastada y adaptarla al contrato; no mover el god object completo al servicio.

| K3 / evidencia local | Decisión v4 |
|---|---|
| `Core/CoreService.cs` | Reescribir como composición de hosts/coordinadores. Retirar auth, UI, envío y timers mezclados; no heredar sus singletons ni `async void` de trabajo. |
| `Tracking/ActivityTracker.cs` | Reusar detección local/criterios tras pruebas; Session, reloj inyectado, eventos y cierre correcto. Corregir recuperación/seed doble. |
| `Tracking/WindowsTracker.cs` (`WindowTracker`) | Reusar hook de foreground y reglas útiles de episodios. Reescribir lifecycle/serialización/persistencia; cerrar antes del flush; separar llamadas. |
| Heurística de llamadas en `WindowTracker` | Extraer a `CallModule`; validar clasificación y no equipararla a llamada confirmada. |
| `Tracking/WorkSchedule.cs`, `Core/TimeSync.cs` | Conservar reglas contrastadas; unificar zona/días aplicables y reloj de duraciones. Evitar estado estático global y timestamps de recepción como actividad. |
| `Network/NetworkBackoffPolicy.cs` y tests | Reusar ideas de clasificación/jitter; ampliar a `Retry-After`, presupuesto y prueba única de recuperación. |
| `Network/OfflineQueue.cs` | Reescribir outbox con persistencia antes de red, ACK parcial, IDs estables, cuotas y coalescencia. No conservar borrado al quinto intento. |
| `Network/ApiClient.cs` | Reemplazar fanout por transporte de sync y gateway. Generar DTO del OpenAPI conciliado. Eliminar fallback de lote a N requests y reenroll por GUID. |
| `Config/ConfigManager.cs` (`ClientConfig`, `*Config`) + `ApiClient.EffectiveConfig`/`Effective*` | Eliminar ambas jerarquías de política y su copia manual. Migración unidireccional hacia `EffectivePolicy` único; secretos/estado operativo en stores distintos sin duplicar política. |
| `Blocking/WebBlockingManager.cs`, `SystemProxyManager.cs`, `LocalPacServer.cs`, `PacContentBuilder.cs` | Reescribir enforcement mediante políticas de máquina. Usar reglas/casos de dominio como insumo de pruebas, sin transportar PAC a v4. Restaurar solo cambios legacy comprobados durante migración. |
| `Blocking/KeyBlocker.cs` | Reusar como máximo presentación; rehacer autorización de PIN y bloqueo en Agent. La ventana/topmost no es anti-robo. |
| `Logging/LocalLogger.cs` y tests de buffers | Reusar límites/deduplicación útiles, eliminar estáticos y webhook directo; redacción de PIN/secretos/títulos según política, outbox único. |
| `Update/UpdateManager.cs`, `AZCKeeperUpdater/Program.cs` | Reescribir cadena de confianza, instalación MSI, staging, rollback y anillos. No reusar ejecución de ZIP no verificado ni check reiniciado por handshake. |
| `Startup/StartupManager.cs`, `Program.cs` | Sustituir autoarranque por usuario por SCM y supervisor WTS; un solo propietario de captura durante transición. |
| `Core/MasterTimer.cs`, `Tracking/KeyboardHook.cs`, `Tracking/MouseHook.cs` | **Eliminar en la implementación v4**: sin consumidores útiles identificados; los hooks independientes son stubs, no captura requerida. No renombrar MasterTimer como solución sin implementar el scheduler nuevo. |
| Screenshots, USB, instalación, cuentas y sesión SYSTEM | Componentes nuevos para este cliente; no se declara que ya existan en el alcance K3 leído. |
| `AZCKeeper.Tests/*` | Portar pruebas de comportamiento aplicables; tests PAC sirven de referencia legacy. Añadir fallos de concurrencia, sync, firma, recovery y sesión en suites v4. |

Los ocho timers señalados en el análisis no se traducen a ocho jobs HTTP. Se inventaria cada responsabilidad al migrar: captura/UI local, deadline de sync, recuperación, actualización y reconciliación. Se conserva únicamente el trabajo necesario bajo el calendario común; no se conserva el número de temporizadores como requisito.

## 10. Layout propuesto, pruebas y fases

### 10.1 Solución

Layout futuro; no se crean estos proyectos en esta tarea:

```text
Keeper.sln
src/
  Keeper.Agent/                  # servicio, composición y módulos privilegiados
    Hosting/                    # ModuleHost, Scheduler, SessionSupervisor
    Policy/                     # validación, firma, journal y reconciliación
    Transport/                  # sync, WSS, transferencias, renovación
    Storage/                    # outbox/inbox, secreto local, cuotas
    Modules/                    # web, descargas, apps, USB, lock, cuentas, logs
  Keeper.Session/                # WinForms inicialmente; UI y módulos por sesión
    Modules/                    # actividad, ventanas, llamadas, screenshots
    Ipc/
  Keeper.Shared/                 # sin HTTP/UI/Windows registry
    Generated/                  # DTO/schema OpenAPI: no editar a mano
    Modules/                    # contrato, snapshots y contexto local
    Ipc/                        # envelope local versionado
  Keeper.Windows/               # wrappers Win32 estrechos y adaptadores comprobables
installer/
  Keeper.Installer/              # WiX MSI + bootstrap firmado, journal de migración
tests/
  Keeper.UnitTests/              # reloj falso, scheduler, política, dedup, redacción
  Keeper.ContractTests/          # OpenAPI/IPC, compatibilidad y fixtures firmados
  Keeper.IntegrationTests/       # SQLite, fallos de red, sync, tenancy y ACK
  Keeper.WindowsTests/           # VM Pro: SCM, sesiones, ACL, policies, cuentas
  Keeper.InstallerTests/         # VM: upgrade, fallo de energía, rollback, escrow
  Keeper.LoadTests/              # flota simulada/NAT y reconexión; entorno staging
docs/architecture/
  v4-client-agent.md
  openapi-v4.yaml                # contrato de la tarea API; completar brechas de §10.4
```

Agent y Session dependen de Shared y adaptadores Windows, no entre sí. WiX empaqueta versiones compatibles. Generación fija versión de herramienta y hash del schema; CI detecta drift y compatibilidad N/N-1 de IPC/protocolo para upgrades. El código generado no contiene una segunda política editable.

### 10.2 Plan de implementación por fases

| Fase | Entrega | Criterio para avanzar |
|---|---|---|
| **1. Prototipo local primero** | En VM Windows 11 Pro: servicio SYSTEM, **dos enforcers: web HKLM y descargas**, política local de prueba y lanzamiento de Session con `CreateProcessAsUser`. Sin degradar usuarios reales. | Políticas observables en navegador y bloqueo funcional; Session usa token de usuario; sobreviven reboot/offline; cerrar Session no elimina control; prueba con usuario estándar y varias sesiones. |
| **2. Núcleo y contrato** | ModuleHost/Scheduler, Shared/OpenAPI conciliado, política firmada, SQLite outbox/inbox, sync y generación de DTO; tracking migrado. | Sin solapamientos, política idéntica sin reinicios, ACK parcial/timeout tras commit sin pérdida/duplicado, cierre final correcto, schema inválido rechazado, 24 h offline con política aplicada. |
| **3. Identidad y canal remoto** | Enrollment con posesión, aislamiento tenant/device, renovación/revocación, gateway WSS, comandos tipados, lock y screenshots. | Tenant A no obtiene control de B; replay/orden vencida rechazados; p95 de entrega ≤5 s conectado; bloqueo persiste sin Session y recupera por IT. |
| **4. Distribución y recuperación segura** | Updater/release puente y MSI firmados, confianza inicial resuelta, bóveda tipo LAPS, bootstrap reanudable, creación/verificación/escrow antes de degradación. | Paquete alterado/editor incorrecto/downgrade rechazados; corte en cada paso no pierde último admin; consentimiento único en perfil UAC esperado; reboot confirma estándar; recuperación offline comprobada. **Prerrequisito del piloto real.** |
| **5. Enforcement completo y piloto** | AppLocker en auditoría→enforcement, Store, USB, extensiones; WDAC primero auditado y promovido tras homologar; anti-tamper y migración K3 por anillos. | Aplicaciones de trabajo/update/recovery funcionan; vías per-user/portable/MSI/Store y reinicios probadas; ninguna cuenta admin de trabajo residual; cada control reporta su estado real. |
| **6. Capacidad y expansión** | Carga 180/1.000/5.000, NAT único por empresa, tuning de gateway/infraestructura, anillos ampliados y retiro K3. | ~0,5 sync HTTP/min/equipo estable, extras separados, sin baneo NAT; 429 y reconexión escalonadas; backlog converge dentro de cuota; sin pérdida de recuperación administrativa. |

BitLocker no forma parte de estas fases. Queda como proyecto posterior con cifrado, TPM, custodia y pruebas de recovery antes de afirmar protección de archivos ante robo.

### 10.3 Pruebas de aceptación que bloquean producción

- **Eficiencia:** reloj simulado y carga real de staging muestran un sync en vuelo, ninguna descarga de política idéntica, sin HTTP por Tick/URL/log individual y sin ráfaga tras reconexión de 1.000 equipos. Medir frames WSS y binarios además de HTTP.
- **Persistencia:** matar Agent durante commit/ACK, reiniciar tras cierre de episodio, responder ACK parcial y repetir comandos. No hay duplicados lógicos ni borrado de eventos no confirmados salvo retención explícita registrada.
- **Enforcement:** reiniciar, desconectar red, cambiar usuario, usar perfil alternativo, intentar software portable/per-user y alterar HKCU. Comprobar rechazo funcional en builds/navegadores homologados, no solo valores del registro.
- **Privilegios:** un usuario estándar no para/reconfigura servicio, escribe binarios/secretos ni usa IPC como proxy SYSTEM. Una excepción con admin local se muestra como protección parcial, nunca como equipo conforme.
- **Bootstrap/recovery:** fallo antes/después de escrow y democión, UAC rechazado, contraseña pendiente de rotación, cuenta preexistente, admin oculto y sesión admin antigua. Debe existir siempre un camino IT comprobado de recuperación.
- **Seguridad de contrato:** token ajeno, GUID conocido sin posesión, destino tenant distinto, firma incorrecta, downgrade, orden repetida/expirada, secreto en logs y request sobredimensionado se rechazan o contienen según diseño.

### 10.4 Coordinación pendiente con backend

El borrador apareció durante esta entrega. Se adopta HTTP v1 y protocolo `1`; no usar `/api/v4` solo porque el producto se llame v4. `DeviceToken` establece TTL de 3.600 s y `SyncResponse.token` permite renovación dentro del sync. `deviceSignature` exige `Signature`/`Signature-Input` RFC 9421 y clave registrada/ligada al ticket: integrar prueba de posesión en el transporte único y terminar de especificar componentes cubiertos, nonce y tolerancia temporal con la tarea API.

| Brecha observada en el borrador leído | Resolución requerida antes de implementación |
|---|---|
| `EffectivePolicy.version` opaca y sin sobre firmado ni secuencia antirrollback | Añadir envelope autenticado, revisión ordenable, compatibilidad y rotación de claves sin duplicar `EffectivePolicy`. |
| `Rule` usa `kind/effect/targets:string[]`; no representa todos los parámetros de módulos | Variantes tipadas por control y parámetros de tracking/transporte/overlays; no codificar JSON o shell dentro de strings. `encryption` se anuncia no soportado por ahora. |
| Sync envía `policy_version`; ETag solo está en `EffectivePolicy.etag` | Definir comparador/cabecera de ETag conocido y semántica de política omitida; mantener ACK 200 del POST. |
| `ActivitySnapshot` tiene `snapshot_id` y totales por device/día; ACK usa `event_id` | Acordar correspondencia `snapshot_id`→ACK y agregación por dispositivo sin duplicar tiempo entre sesiones; si se necesitan totales por SID, ampliar schema explícitamente. |
| Seguridad tiene `controls`; no presupone campos libres de capacidades ni revisión aplicada | Definir en schemas el reporte de soporte, drift y aplicación por control, con límites. |
| `Command` no tiene revisión de lock, modo anti-robo, screenshot ni envelope WSS | Ampliar contrato tipado y mapeo de estados; `wipe` permanece no soportado en Agent. |
| Bootstrap, escrow de `azcadmin` y transferencias todavía requieren contrato completo | Fijar operaciones, permisos, sobres cifrados, ACK durable y recuperación; no enviar passwords como eventos/logs genéricos. |
| Límites por colección no garantizan cota global de bytes | Acordar 128 KiB total/200 eventos agregados o ajustar de forma explícita; respetar también límites particulares 200/50/20 del borrador. |

El contrato de frames WSS requiere especificación propia enlazada desde OpenAPI; no asumir que OpenAPI por sí solo describe el stream bidireccional. Reconciliar estas brechas en el schema fuente antes de generar Shared; no crear aliases silenciosos ni una jerarquía de compatibilidad permanente. Los mecanismos Windows se validan en VMs Pro: en esta tarea solo se contrastaron fuentes y código existente, no se ejecutaron pruebas de enforcement, instalación o carga.
