# AZCKeeper v4 — diseño de API

Estado: **contrato propuesto, no implementado**. Fecha: 2026-09-16. Producto v4; versión de API HTTP v1. Contrato ejecutable por herramientas: [openapi-v4.yaml](openapi-v4.yaml), OpenAPI 3.1.1. No se cambia código, infraestructura ni datos de K3.

## 1. Alcance y decisiones

Este diseño desarrolla el [brief v4](v4-requisitos-brief.md), incluida su **corrección final «TODO EN KEEPER, API-FIRST»**, y la [auditoría K3](../audits/2026-09-15-auditoria-k3.md), especialmente §§2, 5, 6, 8 y 9 y su suplemento. No replica el inventario de defectos. Se contrastaron el router y la baseline SQL del checkout `1638bd4`: hoy existen `/api/client/*` y el bridge `/api/external/*`; **no existen las rutas v1 aquí propuestas**. El inventario del router está en §2.2 de la auditoría; §2.3 inventaría el panel.

Keeper es el motor y el panel completo. El portal cliente futuro, estilo LawyerDesk, se conectará a contratos ya definidos. No se construye ese portal aquí. Las pantallas estáticas de panel-v4 tampoco prueban que las APIs o funciones estén implementadas.

- API-first: recursos y casos de uso compartidos por panel, agente e integraciones; el HTML no contiene la única implementación del negocio. GET de lectura no sincroniza usuarios legacy ni dispara recálculos.
- Contrato independiente del lenguaje: HTTPS, JSON UTF-8, OpenAPI 3.1, IDs UUID opacos, fechas RFC 3339 en UTC, zona IANA de negocio por tenant, duraciones en segundos y tamaños en bytes. Ningún DTO expone una clase PHP/Go/.NET ni una fila SQL completa.
- Dos superficies de consumo: **dispositivo** (ingestión y control de su equipo) y **externa de lectura** (portal/terceros). El panel usa además un **plano administrativo** separado por autenticación y autorización, documentado en el mismo archivo.
- Denegación por defecto: tenant, identidad, permiso/scope y objeto se verifican en todas las rutas. Menús, IDs difíciles de adivinar y dirección IP no son controles de autorización.
- Más de 1.000 miembros/equipos por empresa: lotes, caché compartida, agregados, paginación en almacenamiento, trabajos reanudables y límites por identidad. Una empresa sale por una IP; no se identifica ni penaliza al tenant por NAT.
- Roles, asignaciones de permisos, catálogos, tiers, marca, horarios y valores de política son datos editables. Parametrizar no significa aceptar SQL, shell o código de reglas arbitrario. Añadir una capacidad nueva de enforcement requiere implementación; cambiar sus valores/permisos no requiere release.

### 1.1 Backend: comparación y recomendación sin elección de stack

| Opción | Encaje con este repositorio | Coste/riesgo a evaluar |
|---|---|---|
| PHP moderno | Reutiliza conocimiento y lógica PHP/MySQL; permite separar HTTP, aplicación y repositorios por tenant. | El modelo actual de PHP mezclado con páginas no se conserva. Medir workers, acceso a BD y caché; un gateway de conexiones persistentes sería una pieza adicional. |
| Go | Candidato a servicio de ingestión/gateway compacto y explícito en concurrencia. | Nueva implementación y lenguaje operativo para este equipo; no elimina consultas caras ni defectos de aislamiento. |
| .NET | El repositorio ya contiene agente/updater C#; permite aprovechar conocimiento del equipo en backend. | Reescritura/migración del PHP, nueva operación y medición de memoria/pools. Compartir C# no debe acoplar el contrato al binario del agente. |

**Recomendación:** evaluar primero PHP moderno y .NET por continuidad del equipo; incluir Go en la prueba de ingestión si su coste de mantenimiento es aceptable. Elegir después de ejecutar el mismo contrato y carga en staging. No se selecciona backend en este documento ni se atribuye una ventaja de rendimiento sin medición. La reducción principal de tráfico viene del protocolo y del planificador, no del lenguaje. Hosting con baneo NAT incompatible queda descartado; infraestructura debe admitir cuotas L7, workers y contadores compartidos.

## 2. URLs, versiones y compatibilidad

| Plano | Base efectiva | Ejemplo |
|---|---|---|
| Agente | `/v1` | `POST /v1/client/sync` |
| Panel administrativo e identidad | `/v1` | `GET /v1/users`; `POST /v1/oauth/token` |
| Externa de lectura | `/ext/v1` | `GET /ext/v1/dashboard` |

El YAML tiene `servers: [{url: /v1}]`. **Cada path `/ext/v1/...` sobrescribe `servers` con `/`**; su URL nunca es `/v1/ext/v1/...`. Son URLs relativas al origen HTTPS que se configure en despliegue, no promesas de endpoints ya publicados en `keep.azclegal.com`.

Cambios aditivos compatibles permanecen en v1; consumidores toleran campos nuevos de respuesta. Requests desconocidos se rechazan para evitar mass assignment. Cambios de significado, campos obligatorios nuevos o nuevas variantes de enums que rompan consumidores exigen v2 o negociación explícita. `info.version` identifica la revisión del contrato, no la versión del agente. `SyncRequest.protocol_version=1` identifica el sobre de sync y no sustituye el prefijo HTTP.

Deprecación propuesta: aviso documentado y cabeceras `Deprecation`, `Sunset` y `Link` a la guía de migración con al menos 180 días; una vulnerabilidad crítica puede exigir retirar antes una ruta insegura. Convivencia legacy mediante adaptador aislado, sin reactivar re-enroll por GUID. Migrar por anillos. No fallback individual automático por cada episodio ni `verify-credentials` legacy accesible a integraciones read-only.

## 3. Autenticación, tenancy y autorización

### 3.1 Panel: sesión administrativa y CSRF

`GET /auth/csrf` crea una pre-sesión de cinco minutos o devuelve el token CSRF de la sesión. `POST /auth/login` exige esa cookie y `X-CSRF-Token`, origen permitido y credencial de cuenta/usuario activo; error indistinguible para cuenta inexistente/inactiva. Regenera ID y CSRF al autenticar. Passwords con hash robusto; no se importa comparación legacy en claro.

Cookie `__Host-keeper_admin`: `Secure`, `HttpOnly`, `SameSite=Strict`, `Path=/`, sin `Domain`. Expira tras **30 minutos de inactividad y ocho horas absolutas**, con máximos no ampliables por tenant; se permite una política más estricta. Todas las mutaciones, incluido logout, exigen sesión + CSRF (AND en OpenAPI); no hay logout por GET. Revalidar estado del principal y permisos efectivos en cada petición. Rotar/inutilizar sesión al cambiar credenciales o privilegios. `POST /auth/reauth` establece una marca de reautenticación en servidor válida cinco minutos para wipe, transferencia y recuperación de clave.

En recursos administrativos sin tenant en path, `X-Tenant-ID` es obligatorio, incluso para Super admin. Es **selector**, nunca prueba de pertenencia: se valida contra la sesión. En `/tenants/{id}/...` el ID del path fija el contexto autorizado. En `/tenants`, un admin empresarial solo lista su empresa; plataforma lista las autorizadas globalmente. No hay semántica «sin selector = todas las empresas». Las pocas operaciones globales llevan `x-platform-only`.

### 3.2 Agente: token ligado al dispositivo y prueba de posesión

No existe un `/client/re-enroll` que emita credenciales por GUID. El usuario colaborador no necesita login para que IT enrole su equipo.

1. Instalador/servicio genera una clave P-256, preferentemente no exportable y respaldada por TPM cuando esté disponible; almacenamiento restringido al servicio SYSTEM. La huella de clave pública se entrega al operador por el procedimiento de instalación.
2. Admin autorizado crea `POST /enrollments`: miembro activo, huella de clave y, en recuperación, dispositivo previo. Devuelve ticket aleatorio de **un uso y 10 minutos**, ligado a tenant, miembro y huella; persistir su hash. Recuperación exige reauth y motivo auditado.
3. `POST /client/auth/challenges` con ticket o ID registrado devuelve nonce aleatorio de **60 segundos**. El nonce no es una credencial ni permite obtener datos. No invalidar otros challenges al emitir uno; limitar outstanding por identidad para impedir que conocer un ID bloquee al dispositivo.
4. `POST /client/login` firma la petición. Alta/recuperación: ticket + clave que corresponde a la huella autorizada. Renovación: `device_id` + prueba con la **clave ya registrada**; la clave suministrada no puede reemplazarla. Consumir nonce/ticket atómicamente. Token opaco de **una hora**, ligado a tenant, device, membresía, vigencia de asignación y huella. Ni password de usuario ni GUID solo emiten token.
5. Peticiones normales: `Authorization: Bearer ...` **y** firma HTTP de la clave ligada al token. Sync puede renovar cuando resten menos de diez minutos. Tras expiración se obtiene challenge y se usa la misma clave registrada; no se crea una sesión perpetua ni se ignoran bajas/revocaciones. Si se pierde la clave, requiere recuperación administrativa; no hay fallback por GUID.

Perfil de firma **Keeper HTTP Signatures v1**, basado en [RFC 9421](https://www.rfc-editor.org/rfc/rfc9421.html): headers `Signature-Input` y `Signature`, etiqueta `sig1`, algoritmo `ecdsa-p256-sha256`, parámetros `keyid`, `created`, `expires`, `nonce`. Cubrir `@method`, `@target-uri`; también `authorization` cuando exista, `content-digest` y `content-type` cuando haya cuerpo, e `idempotency-key` cuando se use. SHA-256 del cuerpo exacto transmitido en `Content-Digest`; no reconstruir JSON para verificarlo. HTTPS obligatorio; proxy confiable preserva el URI externo y no acepta cabeceras forwarded de Internet como autoridad.

En login, nonce emitido por servidor y keyid=huella de la clave ligada al ticket/registro. En tráfico autenticado, nonce aleatorio nuevo de al menos 128 bits generado por el agente; caché atómica antirreplay por clave durante al menos 120 segundos. `expires-created` máximo 60 segundos y tolerancia de reloj máxima 60 segundos. La huella es JWK thumbprint SHA-256 sobre los campos públicos canónicos de P-256; nunca incluye clave privada. Un reintento conserva cuerpo/Idempotency-Key pero genera firma/nonce nuevos. Validar firma, pertenencia, estados activos y replay antes de ejecutar. Los SDK pueden requerir un interceptor: `deviceSignature` se representa como securityScheme de header por limitación descriptiva, no como API key real ni OAuth DPoP.

Inactivar usuario/tenant, revocar device, recuperar clave o transferir equipo revoca tokens y la autorización de renovación. La revocación se observa en la siguiente petición mediante registro/versiones de credenciales, no solo al expirar el bearer. Un estado de dispositivo firmado sigue siendo una declaración del agente, no attestation de hardware ni prueba de actividad humana.

### 3.3 Externa: API key por empresa y OAuth2 client credentials

Cada integración pertenece a **exactamente un tenant**, con propietario, scopes permitidos, expiración, estado y auditoría. Un tercero con varias empresas recibe credenciales independientes. No hereda credenciales de empleado, cookie admin, secreto de cron ni `MOAZC_BRIDGE_SECRET` global.

- **API key** en `X-API-Key`: identificador/prefijo público + secreto aleatorio de 256 bits, hash persistido, valor mostrado solo al crear/rotar. Caducidad inicial máxima propuesta de 90 días, renovación explícita y solapamiento de claves máximo 24 horas. Nunca en URL.
- **OAuth2 client_credentials**: `POST /v1/oauth/token`, `client_secret_basic`, cuerpo form-urlencoded; access token 15 minutos, `aud=keeper-external-v1`, `sub=integration_id`, `tenant_id`, `scope`, `exp`, `jti`; sin refresh token. Scopes solicitados deben ser subconjunto de los concedidos. Validar emisor/audiencia/firma/expiración o introspección si se elige token opaco; la representación del token es decisión de implementación.
- Son **alternativas OR**, no dos secretos obligatorios simultáneos. Si llegan ambos, rechazar como petición ambigua. OAuth también comprueba integración y tenant activos; revocar integración invalida tokens derivados. Los scopes de API key son idénticos a los OAuth y se exigen por `x-required-scopes`; OpenAPI no puede expresar scopes OAuth dentro de un esquema apiKey.
- No hay scopes write externos. Ninguna integración puede gestionar roles, bloquear equipos, enrolar, generar credenciales ni mutar tiers. Un scope read granular es un bundle explícito: por ejemplo `team:read` permite nombre, rol, badge y resumen de métricas de `TeamMember`, pero no episodios ni ficha administrativa completa.

| Scopes | Proyección permitida |
|---|---|
| `dashboard:read`, `team:read`, `members:read` | Agregados de empresa, lista del equipo y resumen por miembro, respectivamente. |
| `devices:read`, `organization:read` | Specs/estado mínimo y catálogos; sin tokens, claves, PIN o conexiones de fuentes. |
| `activity:read`, `productivity:read` | Episodios sin títulos; series agregadas de focus/productividad. |
| `activity-titles:read` | Requiere también `activity:read` e `include_titles=true`. Si falta scope, 403; no silenciosamente ignorado. |
| `presence:read`, `location:read` | Check-in sin GPS; GPS solo con ambos scopes e `include_location=true`. |
| `tiers:read`, `notifications:read` | Catálogo/suscripción y feed saneado. |

El navegador del futuro portal no almacena API keys ni client secrets. Su backend/BFF autentica a sus personas, selecciona la integración autorizada y llama a Keeper servidor-servidor. `client_credentials` identifica una aplicación, no al usuario final. Si el portal requiere autoservicio individual con aislamiento impuesto por Keeper, debe añadirse Authorization Code + PKCE/delegación antes de habilitarlo; no simularlo pasando un `user_id` libre con una credencial empresarial. CORS no es autorización: allowlist exacta si se habilita, nunca comodín con cookies.

### 3.4 Frontera dura de tenant

TenantContext inmutable se resuelve **antes** del handler. En dispositivo: token + clave; en externa: integración; en panel: sesión + selector autorizado. Cliente/externa no aceptan `tenant_id` o `X-Tenant-ID` como selector; campos/headers de selección inesperados se rechazan. Todo repositorio exige contexto, incluso consultas por ID, contadores, búsquedas, agregados, auditoría, notificaciones y trabajos en segundo plano.

Predicado lógico obligatorio: `tenant_id = contexto.tenant_id AND id = solicitado`, seguido de alcance de rol (sede/área/propio) donde proceda. FKs/unicidad compuestas por tenant en el esquema futuro impiden referencias cruzadas; no se propone que el SQL actual ya las tenga. Validar también cada ID referenciado en cuerpos y filtros. Objeto fuera de tenant/alcance devuelve **404** sin confirmar existencia; permiso de acción/scope faltante devuelve **403**. Alcance nulo/vacío nunca equivale a global.

Tenant y revisión de autorización integran claves de caché, cursor, idempotencia, colas y almacenamiento de artefactos privados. Invalidar caché de permisos al cambiar gates/roles/estado; un token vigente no congela autorización antigua. No confiar en el GUID, IP, relación legacy ni miembro enviado por cliente. Pruebas cruzadas incluyen joins, filtros, exportaciones futuras, respuestas condicionales y firmas de descarga.

Transferir dispositivo es acción **solo plataforma**: autorizar origen y destino, reauth, confirmación y auditoría en ambos. Dar de baja identidad origen y crear otra en destino, revocando clave/sesiones y requiriendo enrolamiento nuevo. El histórico queda en origen. Reasignar miembro dentro de tenant también revoca credenciales y cambia la vigencia de asignación; validar eventos contra su vigencia, drenando o poniendo en cuarentena backlog previo. Sync/login nunca reasignan silenciosamente propietario.

### 3.5 RBAC: datos, semillas y dos gates

Roles semilla: **Super admin, Gerencia, Dirección, Coordinación, IT, RRHH, Admin Empresa y Colaborador**. Super admin es capacidad de plataforma, no un nombre que pueda autodeclararse en un rol custom. Semillas se copian/versionan; editar un tenant no modifica los demás. Admin Empresa ve reportes solo de su empresa por defecto. Colaborador existe con tenant/firma, sede, área, cargo y horario obligatorios, pero **sin login en v4.0**.

Autorización = permiso de acción ∩ alcance de rol ∩ tenant ∩ estado activo. Alcances: empresa, área, sede o propio. `resource_ids` debe ser no vacío en área/sede; vacío en tenant/propio y jamás permite ampliar alcance. Todas las referencias pertenecen al mismo tenant. Permisos como `equipos.bloquear`, `reglas.editar` o `roles.gestionar` son entradas del catálogo ligadas a acciones registradas.

| Actor/estado | Puede administrar roles empresariales |
|---|---|
| Super admin | Sí, con contexto de empresa y auditoría. |
| Gate empresa `rbac_self_management=false` (default) | Ningún rol del tenant, aunque conserve el meta-permiso. Solo AZC/plataforma. |
| Gate ON, sin `roles.gestionar` | No. |
| Gate ON + `roles.gestionar` | Sí, exclusivamente dentro de su alcance y permisos delegables. |

**Solo Super admin concede `roles.gestionar`**, directa o indirectamente al asignar un rol que lo contenga, y solo él activa/revoca el gate empresa. El delegado no se otorga permisos que no tenga ni amplía su alcance; se aplica también al editar un rol propio, clonar y reasignar roles. Revocar gate corta acceso en la siguiente petición. El catálogo maestro es de plataforma; crear un nombre de permiso no crea una capacidad nueva. Todo cambio queda auditado con actor real.

## 4. Recursos y correspondencia con K3

**Los nombres v4 de esta tabla son recursos lógicos propuestos, no tablas existentes ni migraciones aprobadas.** La columna K3 es origen de información a transformar, no permiso para publicar esa tabla. Tenant se propone como empresa comercial estable; firma es una unidad legal dentro del tenant. En la migración inicial puede haber una firma por tenant; no se infiere aislamiento de crear una fila en `keeper_firmas`.

| Recurso v4 | Identidad/relaciones y reglas | Origen K3 o estado |
|---|---|---|
| Tenant / Branding | Empresa estable; timezone, estado, gate OFF y marca sin HTML arbitrario. | `keeper_firmas`, `keeper_sociedades`, `keeper_panel_settings`; **Tenant nuevo**. |
| OrgUnit / Schedule | Firma, sede, área, cargo; jerarquía dentro del tenant. Horarios locales con días y zona; fin <= inicio cruza medianoche. | `keeper_firmas`, `keeper_sedes`, `keeper_areas`, `keeper_cargos`, `keeper_work_schedules`. |
| User (membresía) | Registro completo del colaborador. Persona transversal futura separada; `id` público identifica membresía, no CC global. Una asignación vigente sin intervalos solapados. | `keeper_users`, `keeper_user_assignments`; identidad legacy por fuente+tenant. |
| Device / DeviceKey / DeviceSession / Enrollment | Equipo exacto, miembro, capacidades reales del SO, clave y vigencias; no todas las ediciones Windows soportan los mismos controles. | `keeper_devices`, `keeper_sessions`, `keeper_enrollment_requests`; clave/PoP nuevos. |
| Policy / Rule / EffectivePolicy | Web, descargas, instalación, horario, SO, USB/cifrado; compilación de plataforma→tenant→sede→área→usuario→equipo. Mismo nivel: prioridad explícita y conflicto rechazado, no orden de fila SQL. | `keeper_policy_assignments`, `keeper_work_schedules`; nuevas capas y compilador. |
| Episode / ActivitySnapshot | UUID de evento, intervalos UTC, origen device autenticado y asignación histórica. | `keeper_window_episode`, `keeper_activity_day`. |
| ProductivityReport / Dashboard | Agregados por tenant/miembro/día, focus, cobertura y versión de cálculo. | `keeper_focus_daily`, `keeper_activity_day`, `keeper_window_episode`; lectura agregada rediseñada. |
| CheckIn | Presencia con fuente door/agent/manual; GPS opcional con precisión, no geolocalización inventada. | Integración uhppoted/location mencionada en brief; **sin tabla K3 verificada aquí**. |
| Role / Permission / AdminSession | Permisos + alcance, dos gates; cuenta administrativa separada de membresía. | `keeper_panel_roles`, `keeper_admin_accounts`, `keeper_admin_sessions`; ampliar modelo. |
| Tier / Subscription | Catálogo editable, badge y entitlements; asignación temporal por miembro, sin solapamientos. Tier no concede permisos RBAC. | **Nuevos**; Essential/Pro/Business como semillas, no enum fijo ni precio inventado. |
| Command / SecurityReport | Acción remota, expiración y resultado; desired/applied/unsupported diferenciados. | `keeper_device_locks`, `keeper_security_state`; cola de comandos nueva. |
| Release / ReleaseDeployment | Manifest inmutable firmado de plataforma; visibilidad/anillo por tenant. | `keeper_client_releases`; integridad y rollout nuevos. |
| AuditEntry / LogEntry | Auditoría append-only de decisiones; logs técnicos estructurados con allowlist. | `keeper_audit_log`, `keeper_client_log`. |
| Integration / Webhook / Notification | Credenciales/suscripciones por empresa; feed saneado y eventos autorizados. | **Nuevos**; no reutilizar bridge global ni webhook arbitrario del cliente. |

La política efectiva nunca devuelve PIN ni credenciales de fuentes. Exclusiones de hosts de gestión pertenecen a plataforma y no se pueden anular con una regla de tenant. El agente informa `unsupported` cuando no puede aplicar una capacidad; el API no convierte Windows Home o un agente per-user en enforcement fuerte. No se decide aquí la edición de toda la flota ni se resuelve el agente SYSTEM.

## 5. Sincronización, idempotencia y presupuesto de red

### 5.1 Un planificador, un sync versionado

Cadencia estable inicial: **120 s**, fase determinista por device + jitter ±10 %, jitter de arranque 0–120 s. Un planificador por servicio; no otro handshake inmediato al arrancar ni timer de actualización reiniciado al recibir política idéntica. Una solicitud normal en vuelo por equipo, máximo dos solo para descarga separada de artefactos. No configurar los endpoints auxiliares como timers simultáneos.

`POST /client/sync` incluye `protocol_version`, secuencia persistente de transporte, versión efectiva conocida de política, release conocida y, si hay cambios, snapshot diario, episodios, logs, seguridad y resultados de comandos. El servidor responde hora, próximo intervalo, ACK por registro, comandos vigentes y release elegible solo si cambió. `policy=null` si coincide versión; si cambia incluye política completa acotada. `release=null` significa que no hay un manifest nuevo que entregar, no una orden de borrar el instalado.

El contador `sequence` se persiste por enrolamiento y no vuelve a cero al reiniciar; no sustituye IDs de evento ni obliga a rechazar un lote offline antiguo. Un nuevo enrolamiento inicia otra vigencia. El snapshot de actividad tiene secuencia por device/día: menor o igual con el mismo contenido no vuelve a sumar; igual con contenido distinto se rechaza. Intervalos válidos: fin > inicio, duración <=24 h, active+idle <=duración y reloj futuro <=5 min; separar episodios que crucen el corte diario para agregación. El dueño se fija por vigencia de asignación, no por usuario enviado.

Seguridad se envía si cambia o al menos cada 24 h; logs únicamente si hay entradas. Release se decide en servidor con caché y anillo, sin sondeo de versión independiente. El backend calcula agregados en workers idempotentes después de persistir ingestión, no dentro del POST. Devuelve `data_through`/`calculated_at` en lecturas para hacer visible el retraso.

Objetivo **0,5 solicitudes/min/device** en régimen estable, incluyendo ingestión ordinaria en sync: 1.000 equipos ≈8,33 solicitudes/s; 5.000 ≈41,67/s. Es modelo, no benchmark ni techo garantizado durante backlog, enrolamientos, política grande o incidentes. No reduce por sí mismo el número de filas de episodios. Polling de 120 s implica latencia de comandos hasta aproximadamente un intervalo + red; un equipo offline no ofrece SLA de bloqueo inmediato. Si luego se requiere reacción en segundos, añadir señal push de invalidación con fallback, no multiplicar polling ni ocupar un worker por long-poll sin medir.

### 5.2 ETag de política y caché

`GET /client/policy` acepta `If-None-Match` y devuelve 200 + ETag o **304 sin body**. ETag fuerte incluye tenant, device, composición completa (incluidas capas heredadas), horarios y revisión del compilador. `Cache-Control: private, no-cache`, `Vary: Authorization`; nunca caché compartida entre tenants por la URL sola.

**POST sync no responde 304**: tiene ACK y resultados de ingestión. Usa `policy_version` en el body y devuelve `policy=null` si no cambió. ETag evita bytes/recompilación, no elimina solicitudes. El ahorro de solicitudes depende del planificador único. `/client/policy` es recuperación puntual, no polling adicional. Política compilada máxima 1 MiB descomprimido; si excede el presupuesto inline de respuesta sync (256 KiB), sync mantiene `policy=null`, anuncia la versión nueva y el agente hace un único GET condicional para obtenerla. Rechazar publicación si excede 1 MiB; no truncar reglas.

### 5.3 Ingestión idempotente y ACK

- `Idempotency-Key` UUID obligatorio en sync, batch y reportes; ámbito tenant + identidad estable del device/admin + método/ruta. Persistir resultado **24 h**. Misma clave y mismo cuerpo canónico: reproducir respuesta; distinto cuerpo: 409 `idempotency_conflict`. La firma/nonce cambia en reintentos sin cambiar el cuerpo ni la clave. Las respuestas persistidas con token renovado se cifran y restringen como secreto; si el replay contiene un token ya expirado, el agente usa challenge/login con su clave registrada. No se genera otro token bajo el mismo replay.
- UUID de episodio generado **antes de encolarlo**, inmutable en sync y `/episodes:batch`. Unicidad propuesta `(tenant_id, device_id, event_id)` más hash canónico del contenido. Un UUID existente con cuerpo distinto devuelve ACK rejected `event_conflict`, no duplicate.
- Dedupe por evento dura toda la retención del crudo; aceptar eventos hasta 30 días de antigüedad como presupuesto inicial parametrizable. Al purgar, rechazar por `event_too_old` lo que queda fuera de la ventana, para que expirar el registro de dedupe no reintroduzca episodios. Cambiar ventana exige retener dedupe al menos esa duración. Retención definitiva se valida con negocio.
- 200 de batch/sync contiene ACK **por cada evento presentado**: `accepted`, `duplicate` o `rejected`, `retryable` y código si se rechaza. UUID repetido dentro de un mismo lote: 422 de sobre antes de escribir. Sobre inválido: ninguna escritura; errores individuales: se aceptan los válidos y se detallan los rechazados. Un ACK solo ocurre después de persistir de forma duradera; nunca porque quedó en memoria.
- Agente elimina del outbox solo accepted/duplicate. Rechazo permanente va a cuarentena diagnóstica acotada; no borrado silencioso al quinto intento. Errores reintentables conservan UUID; lote corregido usa nueva Idempotency-Key. Timeout después de commit se reenvía sin duplicar.
- 429/503 se reintentan después de `Retry-After`, con jitter y backoff exponencial compartido hasta 15 min; 401 abre renovación autenticada, 403 no borra credenciales ni dispara re-enroll. Circuito abierto no consume intentos de envío. Backlog: máximo un lote adicional/min dentro de cuota; dar prioridad a ACK/comandos/sync sobre logs.

No se promete exactly-once de ejecución remota. Comandos se entregan al menos una vez y tienen ID, expiración y estado. Agente persiste journal antes de ejecutar; tras crash reconcilia estado sin repetir ciegamente wipe/restart. Resultado de un comando ajeno al device es 404. Resultado terminal repetido idéntico es idempotente; transición terminal contradictoria es 409. `unlock` requiere un comando firmado/autorizado; desaparece el endpoint que acepta cualquier PIN no vacío.

### 5.4 Rate limits por identidad y tenant, nunca por IP empresarial

Valores **iniciales para staging**, parametrizables por plataforma. Cada request autenticado consume bucket de identidad y tenant de su superficie; los contadores son atómicos/compartidos entre instancias. Identidad de `device_token` se normaliza a **tenant+device**, de modo que rotar token no reinicia presupuesto. Coste inicial una unidad/request; validación adicional de bytes/filas/concurrencia impide eludir presupuesto enviando cuerpos enormes.

| Superficie | Cuota sostenida inicial | Burst / separación |
|---|---|---|
| Agente, total de rutas | 2/min por device; operación normal 0,5/min | Burst 5. Tenant: `max(120, 2 × D)`/min, burst `max(20, ceil(0,1 × D))`; D = flota autorizada configurada por plataforma, no declarada por agente. |
| Externa | 120/min por integración | Burst 30; tenant 600/min, burst 100. Máx. 4 consultas pesadas simultáneas/integración y 16/tenant. |
| Panel | 120/min por sesión/admin | Burst 30; tenant 600/min, burst 100; bucket separado de ingestión. |
| Login/challenge | 5 intentos/min por cuenta, ticket o registro de device; 20 challenges outstanding/device | Tenant válido 60/min, burst 20; bootstrap con tenant desconocido usa cuota global y cola acotada. No deducir tenant de un ID arbitrario. |
| OAuth token | 10/min por client_id válido | Cuota de integración/tenant; errores de cliente desconocido bajo presupuesto global. |

No baneo HTTP por IP ni bucket de negocio por NAT. La IP puede figurar como señal operativa, con retención acotada; protección volumétrica de infraestructura no debe bloquear una IP empresarial por comportamiento esperado de 1.000+ equipos. Ajustar WAF/CSF/LFD y conexiones/keep-alive antes del rollout: un 429 de aplicación no deshace un ban previo de firewall. Separar solicitudes HTTP, conexiones nuevas y concurrencia en mediciones.

Headers de contrato en respuestas autorizadas y 429: `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` (segundos hasta reposición), `X-RateLimit-Scope` (`device`, `tenant`, `integration`, `admin`, `bootstrap`). Se informa el bucket con menor presupuesto útil, sin IDs secretos. `Retry-After` en 429/503 es el máximo tiempo necesario para volver a cumplir **todos** los buckets agotados; el cliente espera al menos ese valor. Se especifica esta semántica propia, sin atribuirla a un estándar de rate-limit concreto. 304 también consume cuota.

## 6. Inventario contractual

Tablas generadas desde los paths/operationId del YAML para no divergir. Rutas de agente/panel/auth relativas a `/v1`; rutas externas absolutas `/ext/v1/...`. `A` = sesión; `A+C` = sesión y CSRF; `D+F` = device bearer y firma; `F` = firma con ticket/clave registrada; `K/O` = API key **o** OAuth. Los permisos admin son datos, no scopes OAuth. `T` = tenant y alcance autorizados; `D` = tenant/device exactos de credencial; `K` = tenant de integración; `P` = plataforma explícita; casos especiales se detallan en §3 y `x-tenant-boundary`. Recursos remiten a §4, que distingue fuentes K3 de modelos nuevos.

### 6.1 Agente Windows

| Método | Ruta | Auth | Permiso / scope | Tenant | Descripción | Recursos |
|---|---|---|---|---|---|---|
| POST | `/client/auth/challenges` | Ticket/ID | — | Ticket/registro | Obtener nonce de un uso, TTL 60 s | Enrollment, DeviceKey |
| POST | `/client/login` | F | — | Ticket/registro | Alta, recuperación o renovación con posesión | Enrollment, DeviceSession |
| POST | `/client/sync` | D+F | — | D | Sincronizar con deltas y ACK por registro | Device, Policy, Episode, ActivitySnapshot, Command, Release, SecurityReport, LogEntry |
| GET | `/client/policy` | D+F | — | D | Leer política condicional | Policy, Schedule |
| POST | `/client/episodes:batch` | D+F | — | D | Ingerir backlog con ACK individual | Episode |
| GET | `/client/commands` | D+F | — | D | Consultar comandos propios pendientes | Command |
| POST | `/client/commands/{id}/result` | D+F | — | D | Registrar resultado de comando propio | Command, AuditEntry |
| POST | `/client/security/report` | D+F | — | D | Registrar controles declarados | SecurityReport |
| POST | `/client/logs` | D+F | — | D | Registrar logs estructurados saneados | LogEntry |
| POST | `/client/check-ins` | D+F | — | D | Registrar check-in del miembro vinculado | CheckIn |
| GET | `/client/releases/{id}` | D+F | — | D | Obtener manifest firmado autorizado | Release |

### 6.2 Plano administrativo

| Método | Ruta | Auth | Permiso / scope | Tenant | Descripción | Recursos |
|---|---|---|---|---|---|---|
| GET | `/tenants` | A | empresas.ver | T | Listar empresas autorizadas | Tenant |
| POST | `/tenants` | A+C | empresas.gestionar | P | Crear empresa con gate OFF | Tenant |
| GET | `/tenants/{id}` | A | empresas.ver | T | Leer empresa y branding | Tenant |
| PATCH | `/tenants/{id}` | A+C | empresas.editar | T | Editar configuración de empresa | Tenant, AuditEntry |
| PATCH | `/tenants/{id}/rbac-gate` | A+C | empresas.rbac_habilitar | P | Activar o revocar autonomía RBAC | Tenant, AuditEntry |
| GET | `/tenants/{id}/devices` | A | equipos.ver | T | Listar equipos de empresa | Device |
| GET | `/devices/{id}` | A | equipos.ver | T | Leer equipo/specs y capacidades | Device |
| PATCH | `/devices/{id}` | A+C | equipos.editar | T | Renombrar, revocar o dar de baja | Device, DeviceSession, AuditEntry |
| POST | `/devices/{id}/assignment` | A+C | equipos.asignar | T | Reasignar miembro dentro del tenant | Device, DeviceSession, AuditEntry |
| POST | `/devices/{id}/transfer` | A+C | equipos.transferir | P | Transferir entre empresas con nuevo enrolamiento | Device, Enrollment, AuditEntry |
| POST | `/devices/{id}/commands` | A+C | equipos.<acción> | T | Encolar acción remota | Device, Command, AuditEntry |
| GET | `/devices/{id}/commands` | A | equipos.ver | T | Consultar estado de comandos | Command |
| POST | `/enrollments` | A+C | equipos.enrolar | T | Emitir ticket ligado a clave | Enrollment, Device, User, AuditEntry |
| GET | `/users` | A | usuarios.ver | T | Listar membresías | User |
| POST | `/users` | A+C | usuarios.crear | T | Crear colaborador sin login | User, OrgUnit |
| GET | `/users/{id}` | A | usuarios.ver | T | Leer registro completo | User |
| PATCH | `/users/{id}` | A+C | usuarios.editar | T | Editar registro o inactivar | User, DeviceSession, AdminSession, AuditEntry |
| PUT | `/users/{id}/roles` | A+C | roles.gestionar | T | Asignar roles sin autoescalada | User, Role, AuditEntry |
| GET | `/users/{id}/dashboard` | A | reportes.ver | T | Dashboard por miembro | User, ProductivityReport |
| GET | `/users/{id}/activity` | A | actividad.ver | T | Actividad paginada | Episode |
| GET | `/users/{id}/check-ins` | A | presencia.ver | T | Histórico de check-in | CheckIn |
| GET | `/dashboard` | A | reportes.ver | T | KPIs, gráficas y roster preview | Dashboard |
| GET | `/organization` | A | organizacion.ver | T | Leer firmas, sedes, áreas y cargos | OrgUnit |
| POST | `/organization` | A+C | organizacion.editar | T | Crear unidad organizativa | OrgUnit |
| GET | `/organization/{id}` | A | organizacion.ver | T | Leer unidad organizativa | OrgUnit |
| PATCH | `/organization/{id}` | A+C | organizacion.editar | T | Editar o desactivar unidad | OrgUnit, AuditEntry |
| GET | `/schedules` | A | horarios.ver | T | Leer horarios | Schedule |
| POST | `/schedules` | A+C | horarios.editar | T | Crear horario | Schedule |
| GET | `/schedules/{id}` | A | horarios.ver | T | Leer horario | Schedule |
| PUT | `/schedules/{id}` | A+C | horarios.editar | T | Actualizar horario | Schedule, Policy, AuditEntry |
| GET | `/policies` | A | reglas.ver | T | Listar políticas/reglas | Policy |
| POST | `/policies` | A+C | reglas.editar | T | Crear política | Policy, Rule |
| GET | `/policies/{id}` | A | reglas.ver | T | Leer política editable | Policy |
| PATCH | `/policies/{id}` | A+C | reglas.editar | T | Publicar nueva revisión de política | Policy, Rule, AuditEntry |
| GET | `/roles` | A | roles.ver | T | Leer roles semilla/custom | Role |
| POST | `/roles` | A+C | roles.gestionar | T | Crear o clonar rol | Role, AuditEntry |
| GET | `/roles/{id}` | A | roles.ver | T | Leer rol/alcance | Role |
| PATCH | `/roles/{id}` | A+C | roles.gestionar | T | Editar/desactivar rol | Role, AuditEntry |
| GET | `/permissions` | A | roles.ver | T | Leer catálogo maestro | Permission |
| GET | `/permissions/{code}` | A | roles.ver | T | Leer permiso registrado | Permission |
| PATCH | `/permissions/{code}` | A+C | permisos.gestionar | P | Editar metadata/delegabilidad de permiso | Permission, AuditEntry |
| GET | `/reports/productivity` | A | reportes.ver | T | Productividad y focus agregado | ProductivityReport |
| GET | `/tiers` | A | tiers.ver | T | Leer tiers de empresa | Tier |
| POST | `/tiers` | A+C | tiers.gestionar | T | Crear tier parametrizable | Tier |
| GET | `/tiers/{id}` | A | tiers.ver | T | Leer tier | Tier |
| PATCH | `/tiers/{id}` | A+C | tiers.gestionar | T | Editar/desactivar tier | Tier, AuditEntry |
| GET | `/users/{id}/subscription` | A | tiers.ver | T | Leer suscripción de miembro | Subscription |
| PUT | `/users/{id}/subscription` | A+C | tiers.gestionar | T | Asignar/programar tier por miembro | Subscription, AuditEntry |
| GET | `/releases` | A | releases.ver | T | Leer releases visibles al tenant | Release, ReleaseDeployment |
| POST | `/releases` | A+C | releases.publicar | P | Registrar manifest firmado por pipeline | Release, AuditEntry |
| POST | `/release-deployments` | A+C | releases.desplegar | T | Seleccionar anillo/rollout de empresa | ReleaseDeployment, AuditEntry |
| GET | `/audit` | A | auditoria.ver | T | Consultar auditoría del alcance | AuditEntry |
| GET | `/integrations` | A | integraciones.gestionar | T | Leer credenciales sin secreto | Integration |
| POST | `/integrations` | A+C | integraciones.gestionar | T | Crear API key/cliente OAuth de empresa | Integration, AuditEntry |
| POST | `/integrations/{id}/rotate` | A+C | integraciones.gestionar | T | Rotar secreto; solapamiento máximo 24 h | Integration, AuditEntry |
| DELETE | `/integrations/{id}` | A+C | integraciones.gestionar | T | Revocar integración y tokens | Integration, AuditEntry |
| GET | `/webhooks` | A | notificaciones.gestionar | T | Leer destinos de eventos | Webhook |
| POST | `/webhooks` | A+C | notificaciones.gestionar | T | Registrar destino HTTPS y scopes | Webhook, AuditEntry |
| DELETE | `/webhooks/{id}` | A+C | notificaciones.gestionar | T | Desactivar entregas | Webhook, AuditEntry |
| GET | `/notifications` | A | notificaciones.ver | T | Leer notificaciones | Notification |

### 6.3 API externa de lectura

| Método | Ruta | Auth | Permiso / scope | Tenant | Descripción | Recursos |
|---|---|---|---|---|---|---|
| GET | `/ext/v1/dashboard` | K/O | dashboard:read | K | KPIs, gráficas y roster preview | Dashboard |
| GET | `/ext/v1/my-team` | K/O | team:read | K | Lista y ranking con tier/rol/métricas | User, Tier, ProductivityReport |
| GET | `/ext/v1/members/{id}` | K/O | members:read | K | Perfil mínimo y tendencia por miembro | User, ProductivityReport |
| GET | `/ext/v1/members/{id}/devices` | K/O | members:read + devices:read | K | Equipos/specs del miembro sin secretos | Device |
| GET | `/ext/v1/members/{id}/activity` | K/O | members:read + activity:read; adicional según flags | K | Actividad paginada sin títulos por defecto | Episode |
| GET | `/ext/v1/members/{id}/check-ins` | K/O | members:read + presence:read; adicional según flags | K | Check-in sin GPS por defecto | CheckIn |
| GET | `/ext/v1/members/{id}/subscription` | K/O | members:read + tiers:read | K | Suscripción y tier por miembro | Subscription |
| GET | `/ext/v1/tiers` | K/O | tiers:read | K | Catálogo de tiers/badges | Tier |
| GET | `/ext/v1/productivity` | K/O | productivity:read | K | Series de productividad/focus | ProductivityReport |
| GET | `/ext/v1/organization` | K/O | organization:read | K | Firmas, sedes, áreas y cargos | OrgUnit |
| GET | `/ext/v1/notifications` | K/O | notifications:read | K | Feed de eventos saneados | Notification |

### 6.4 Identidad y sesión

| Método | Ruta | Auth | Permiso / scope | Tenant | Descripción | Recursos |
|---|---|---|---|---|---|---|
| GET | `/auth/csrf` | Pre-sesión/A | — | Principal | Crear pre-sesión de login u obtener CSRF | AdminSession |
| POST | `/auth/login` | Pre-sesión + CSRF | — | Principal | Iniciar sesión y regenerar cookie | AdminSession |
| POST | `/auth/logout` | A+C | — | Principal | Revocar sesión y borrar cookie | AdminSession |
| POST | `/auth/reauth` | A+C | — | Principal | Reautenticar acción crítica por cinco minutos | AdminSession |
| POST | `/oauth/token` | OAuth Basic | — | Principal | Emitir token client_credentials | Integration |

Las escrituras de configuración con `If-Match` usan el ETag del GET del recurso; 428 si falta y 412 ante conflicto. PATCH de política/rol/tier/unidad reemplaza su definición editable completa tipada; PATCH de usuario/device/tenant acepta campos parciales. No hay borrado físico de usuarios/equipos con histórico: inactivar/dar de baja preserva trazabilidad. La suscripción inicialmente ausente devuelve 404 con ETag de estado vacío para autorizar su primera asignación condicional.

Las rutas de tiers, horarios y notificaciones quedan contratadas aunque su UI sea placeholder y su implementación se programe después del núcleo. No se inventan servicios de cases, hiring, AI reception, tareas de staffing, nómina, PTO o facturación comparativa estadounidense. Las promesas fuera del motor Keeper requieren requisitos y contratos propios; una pantalla placeholder no devuelve métricas simuladas como datos reales.

## 7. Errores, filtros, páginas y límites

Errores JSON usan `application/problem+json` y estructura basada en [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html), con extensiones `code`, `request_id` y `errors` de campos. Sin SQL, stack, nombres de credenciales, PIN ni cuerpos crudos. Ejemplo:

```json
{
  "type": "urn:keeper:problem:insufficient-scope",
  "title": "Permiso insuficiente",
  "status": 403,
  "code": "insufficient_scope",
  "request_id": "94db6c98-b468-4e94-a753-9a58f2d20100",
  "detail": "La credencial no permite esta operación."
}
```

| HTTP | Códigos representativos / comportamiento |
|---|---|
| 400 / 415 | `invalid_json`, `ambiguous_credentials`, `invalid_header` / `unsupported_media_type`. |
| 401 | `invalid_credentials`, `session_expired`, `token_expired`, `invalid_signature`, `replayed_proof`; desafío auth cuando aplica. |
| 403 | `insufficient_scope`, `permission_denied`, `csrf_invalid`, `tenant_rbac_disabled`, `reauth_required`. No indica token expirado. |
| 404 | `resource_not_found`, también para IDs/cursors de otro tenant; no confirmar existencia. |
| 409 | `idempotency_conflict`, `assignment_conflict`, `invalid_transition`. |
| 412 / 428 | `version_conflict` / `precondition_required`. |
| 413 / 422 | `payload_too_large` / `validation_failed`, `invalid_range`, `unsupported_capability`, `cursor_expired`. |
| 429 | `rate_limited`, headers de cuota + Retry-After. |
| 500 / 503 | `internal_error` / `temporarily_unavailable`; sin ACK no persistido. |

OAuth `/oauth/token` conserva el formato de error del protocolo (`error`, `error_description`) en 400/401; 429/5xx usan Problem. Fallos por evento dentro de un batch válido son ACK, no 200 ambiguo ni descarte silencioso. Request ID generado por servidor en cabecera/cuerpo y propagado a jobs/auditoría.

Páginas: `{data, next_cursor, generated_at, data_through}`; `limit=50`, máximo 100, orden estable `(created_at,id)` o `(started_at,event_id)` para episodios. `my-team` ordena por nombre normalizado+id, sin ranking oculto diferente del orden de página. Cursor opaco firmado, TTL 15 min, vinculado a tenant, principal/revisión de autorización, filtros, orden y snapshot de lectura; nunca OFFSET sobre millones de filas ni `fetchAll` para luego recortar. No se promete total_count costoso. `next_cursor=null` indica fin.

Fechas `from` inclusiva y `to` exclusiva, en zona del tenant; convertir límites a UTC incluyendo DST. Máximo 31 días para detalle, dashboard, miembro, check-ins y auditoría; 366 para `/reports/productivity` y `/ext/v1/productivity`. `from < to` obligatorio. Filtros allowlist: área, sede, miembro y status solo donde el YAML los declara; IDs de filtros siempre autorizados. Texto de títulos nunca sirve como filtro SQL libre. Exportaciones grandes futuras serán jobs paginados autorizados, no un `limit=all`; su contrato se define antes de exponerlas.

Lecturas agregadas usan el mismo modelo para panel/externa, sin sumar dos veces a un usuario por tener varios dispositivos. `null` en métricas significa muestra insuficiente; cero significa cero medido. `coverage_percent`, `calculation_version` y `data_through` muestran cobertura/método/frescura. Los widgets resumen no descargan todos los episodios. Roster es preview de 20; `my-team` pagina el resto. Dashboard máximo 31 puntos y 100 áreas; tenants con mayor cardinalidad requieren filtro o un agregado paginado antes de publicar ese widget, nunca truncado silencioso.

Límites antes de deserializar: sync/batch **128 KiB**, máximo 200 episodios; logs 32 KiB/50 entradas; security 32 KiB/100 controles; resultado comando 8 KiB; check-in/challenge 4 KiB; login device 8 KiB. Mutaciones pequeñas restantes 64 KiB. Headers acumulados máximo 16 KiB. Contar bytes UTF-8 y descomprimidos, no caracteres; rechazar codificaciones no soportadas y bombas de compresión. Respuestas paginadas también acotadas; límite de longitud de campos en esquemas. Publicar política valida además tamaño compilado.

## 8. Consumo del portal cliente futuro

| Pantalla/componente | Endpoint externo | Scopes mínimos | Consideraciones |
|---|---|---|---|
| Command Center | `/ext/v1/dashboard?from=...&to=...` | `dashboard:read` | KPIs online/horas, salida semanal, carga por área, roster preview. No ahorro monetario inventado. |
| My Team / ranking / miembros | `/ext/v1/my-team`; `/ext/v1/productivity` | `team:read`; `productivity:read` para serie separada | Paginación, filtros sede/área, badge, rol y focus. Ordenar ranking sobre agregado completo si se añade ese contrato; no sobre una página aislada. |
| Miembro / Overview | `/ext/v1/members/{id}` | `members:read` | Perfil mínimo y tendencia; el registro administrativo completo no se expone. |
| Activity & Logs | `/ext/v1/members/{id}/activity` | `members:read` + `activity:read` | Actividad de negocio, **no logs técnicos**. Títulos solo con scope adicional. |
| Acceso y dispositivos | `/ext/v1/members/{id}/devices` | `members:read` + `devices:read` | Equipo/specs/estado reportado; sin botones de comando con credencial read-only. |
| Check-in history / GPS | `/ext/v1/members/{id}/check-ins` | `members:read` + `presence:read`; `location:read` para GPS | Fuente/precisión visibles; no se inventa GPS de un control de puerta. |
| Tier badge / suscripción | `/ext/v1/tiers`; `/ext/v1/members/{id}/subscription` | `tiers:read`; además `members:read` en detalle | Tier por persona, no plan global único del tenant. Gestión por API administrativa. |
| Filtros y feed | `/ext/v1/organization`; `/ext/v1/notifications` | Scopes correspondientes | Feed saneado; actualizar por webhook/invalidez o revalidación espaciada. |

El BFF cachea por tenant+integración+scopes+filtros y reutiliza agregados; jamás genera una consulta por equipo para pintar el dashboard. Actualización inicial orientativa 60 s visible, detener al ocultar pestaña, respetar cuotas y `data_through`. Las respuestas no garantizan datos en tiempo real: sync 120 s + agregación. HR/PTO, staffing y billing no tienen datos contractuales en este diseño y no deben mostrarse como implementados.

Webhooks se administran solo con sesión/permisos, no por la API externa. Propuesta de evento: `event_id`, `tenant_id`, `type`, `resource_id`, `occurred_at`, sin títulos, GPS ni secretos; eventos iniciales `device.status_changed`, `command.completed`, `policy.changed`, `subscription.changed`, `report.ready`. Entrega HTTPS firmada (HTTP Signatures, clave de plataforma conocida por canal autenticado), event_id estable, al menos una vez; el receptor deduplica y luego consulta la API con sus scopes. Reintentos con jitter hasta 24 h, cola de fallos y auditoría; nunca envíos directos sin control desde cada agente. Destino público validado, no redes privadas/link-local/metadata ni redirects; revalidar DNS/destino en cada entrega. Notificaciones internas y webhooks comparten autorización de tenant y allowlist de eventos.

## 9. Cierre de hallazgos y criterios de aceptación

**«Cierre de diseño» no significa remediación de K3 ni cierre en producción.** Para cerrar un hallazgo operacional hacen falta implementación, migración, pruebas y despliegue. Los criterios siguientes hacen comprobable este contrato.

| Auditoría / hallazgo | Control contractual | Prueba necesaria antes de declarar cerrado |
|---|---|---|
| §9.1 re-enroll por GUID | Ticket ligado a clave o clave registrada + nonce/firma; sin fallback. | GUID conocido, clave sustituta, nonce repetido, ticket usado/expirado y token sin clave no emiten/usan sesión. Recuperación válida revoca anterior. |
| §6.2 IDOR/reasignación y suplemento bridge global | TenantContext, predicado de objeto, constraints, DTO allowlist, API key/OAuth por empresa. | Matriz A/B en todas las rutas, filtros, cursor, policy 304, caches, resultados de comandos y futuros exports; cero cruce. Login/sync no reasignan. |
| §9.1 sesiones sin expiración/inactivos | TTLs, revocación, estado activo y clave ligada a device exacto. | Expirado/revocado/inactivo falla en siguiente request; 403/429 no activa reenrolamiento. |
| §9.2 CSRF/guards y meta-permisos | Sesión+CSRF, permiso por acción, reauth y dos gates. | Mutaciones sin CSRF fallan; gate OFF bloquea delegado; clonado/rol propio no escalan; actor auditado correcto. |
| §9.5 updater sin firma | `/client/releases/{id}` + manifest JWS, raíz de confianza anclada, hash/longitud, origen permitido y secuencia anti-downgrade. | Rechaza paquete modificado, manifest falso, downgrade, clave desconocida y helper sin verificar **antes de extraer/ejecutar**. |
| §9.3 PIN y secretos en logs/config | Política sin PIN; logs estructurados allowlist y redacción en origen/servidor. Unlock por comando autorizado. | PIN/token/password/Authorization/ticket no aparecen en local, backend, trazas HTTP ni auditoría; revisar históricos y rotar secretos expuestos como trabajo de migración. |
| §5 tormenta/NAT | Un sync 120 s, deltas, ETag GET, jitter, backoff, cuotas device/tenant. | Carga 180/1.000/5.000 devices por IP simulada, incluyendo reinicio simultáneo/backlog/429; sin ban NAT y con tasa/bytes/SQL medidos. |
| §2.4 y §§4–5 batch ambiguo/duplicados | ACK duradero por evento, UUID estable, dedupe y snapshots monotónicos. | Timeout después de commit y reenvío por otro endpoint no duplica; errores parciales no pierden registros. |
| §9.3–9.4 exposición/abuso | DTO mínimo, scopes de títulos/GPS, Problem, presupuestos previos a parseo. | Sin metadatos secretos ni errores SQL; body excesivo 413 temprano; scope base no obtiene título/GPS. CSV futuro neutraliza fórmulas. |
| §9.6 bloqueo y proxy | Hosts de gestión no bloqueables; política cacheada ≠ control aplicado; capacidades reportadas. | Prueba agente en ediciones reales; proxy no intercepta su propia gestión; control unsupported se muestra como tal. |

Updater: la API no es raíz de confianza. La clave privada de firma permanece fuera del backend; `manifest_jws` contiene todos los campos firmados y el cliente coteja la representación exterior. Verificar firma de manifest, SHA-256, tamaño, canal/arquitectura, secuencia monotónica y compatibilidad con un updater ya confiable. No ejecutar helper del ZIP hasta verificarlo. Rotación de raíz mediante cadena autorizada por raíz previa; rollback solo con autorización firmada explícita, no aceptando cualquier versión menor. Releases globales inmutables; rollout/visibilidad por tenant, sin URL de descarga arbitraria suministrada por un admin empresarial.

Persisten fuera de esta entrega: migraciones reproducibles/backfill de identidad y dedupe, limpieza de secretos históricos, cambios de CSF/WAF, agente SYSTEM y claves TPM, criptografía real del updater, drivers/control del SO, agregación/retención/jobs, integración de puertas/GPS, implementación OAuth y mediciones de capacidad. La política de retención propuesta no constituye una decisión legal ni una migración aplicada.

## 10. Validación del artefacto y referencias

Validación local: YAML parseado con Node y `yaml` 2.8.1 (claves duplicadas rechazadas); contrato validado por `@apidevtools/swagger-parser` 12.1.0. Comprobación adicional de referencias internas, operationId únicos, parámetros de path, scopes declarados, solo GET en externa, CSRF en mutaciones administrativas y override de URL externa. Resultado: **66 paths, 87 operaciones (11 agente, 60 administración, 11 externas, 5 identidad), 94 schemas y 1.781 referencias internas resueltas**.

Las dependencias de validación se cargaron en memoria desde bundles versionados; no se instalaron paquetes ni se modificó código. No se ejecutaron pruebas de endpoints, carga, permisos reales o criptografía: no hay implementación v4 que certificar. Solo se entregan estos dos documentos y no se hacen commits.

Base normativa del formato: [OpenAPI 3.1.1](https://spec.openapis.org/oas/v3.1.1.html). Semántica de GET condicional/ETag y precondiciones: [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110.html). El YAML fija esquemas, security requirements y paths; extensiones `x-tenant-boundary`, `x-permission`, `x-required-scopes`, `x-conditional-scopes` y `x-max-body-bytes` son obligaciones de implementación, no enforcement automático de OpenAPI.

---

## Decisiones cerradas (2026-09-16, Koichi)

- **Backend v4 = PHP.** El contrato de este documento (API-first, multi-tenant, RBAC 2 gates, rate-limit por
  device/tenant) se implementa en PHP. Se reutiliza lo aprovechable de K3 (`AZCKeeper_Client/Web/`) corrigiendo
  los hallazgos de la auditoría; nada de esto cambia el contrato OpenAPI, que es language-agnostic.
- **ETag de política = `policy_version`.** No se agrega cabecera ni campo nuevo (respeta `additionalProperties=false`).
  El agente envía en `/client/sync` el `policy_version` que tiene; el servidor devuelve el documento de política
  **solo si su versión es mayor** (si es igual, `policy=null` = sin cambios). Ese entero cumple el rol de ETag:
  corta el reenvío innecesario de la política. Un GET de política independiente con ETag/304 queda reservado
  solo para diagnóstico/recuperación, no para el ciclo periódico.
