# AZCKeeper v4 — migraciones MySQL

Baseline independiente para backend PHP, MySQL **8.0.30 o posterior**, InnoDB. No importa, modifica ni elimina datos de K3/WebK4. Los ocho SQL reconstruyen el esquema desde una base vacía: **71 tablas**, más `schema_migrations` si se usa el runner. No requiere tablas legacy, datos externos, Composer, cron ni un framework para instalarse.

Fuentes: [OpenAPI v4](../../docs/architecture/openapi-v4.yaml), [contrato API](../../docs/architecture/v4-api.md), [agente](../../docs/architecture/v4-client-agent.md), [brief](../../docs/architecture/v4-requisitos-brief.md) y [auditoría K3](../../docs/audits/2026-09-15-auditoria-k3.md).

## Aplicación desde cero

Requisitos: MySQL con `CHECK` habilitado, `STRICT_TRANS_TABLES`, UTF-8; PHP CLI con `pdo_mysql` para el runner. El mínimo 8.0.30 permite `CREATE TRIGGER IF NOT EXISTS`; no es compatible con MySQL 5.7 ni MariaDB. Todas las conexiones de aplicación deben usar UTC y modo estricto. Se necesitan permisos DDL, `TRIGGER`, `EVENT`, lectura/escritura y `GET_LOCK` para instalar; esas credenciales no son las del backend en ejecución. El mantenimiento automático requiere `event_scheduler=ON` y las tablas de zonas horarias de MySQL cargadas para convertir las zonas IANA de los horarios.

Desde la raíz del repositorio, PowerShell:

```powershell
# -p solicita el password sin incluirlo en argv; usar una base exclusiva para v4.
mysql --host=127.0.0.1 --user=root -p --execute="CREATE DATABASE keeper_v4 CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
$env:KEEPER_DB_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=keeper_v4;charset=utf8mb4'
$env:KEEPER_DB_USER = 'keeper_migrator'
$env:KEEPER_DB_PASSWORD_FILE = 'C:\ruta-protegida\keeper-migrator-password.txt'
php backend-v4/migrations/run.php
if ($LASTEXITCODE -ne 0) { throw 'Falló la migración' }
```

Crear previamente el usuario `keeper_migrator` con permisos sobre esa base. El archivo de password se lee directamente, nunca se imprime; se eliminan únicamente CR/LF finales. También se admite `KEEPER_DB_PASSWORD`, inyectado por el gestor de secretos. No guardar credenciales en esta carpeta.

Linux:

```sh
export KEEPER_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=keeper_v4;charset=utf8mb4'
export KEEPER_DB_USER=keeper_migrator
export KEEPER_DB_PASSWORD_FILE=/run/secrets/keeper_migrator_password
php backend-v4/migrations/run.php
```

Orden obligatorio:

| Archivo | Contenido |
|---|---|
| `0001_tenants_catalogs.sql` | Tenants, marca, permisos, plantillas de roles, scopes y módulos globales |
| `0002_organization_identity_rbac.sql` | Organización, miembros, asignaciones, roles y sesiones administrativas |
| `0003_devices_access.sql` | Dispositivos, enrolamiento, claves, tokens, comandos e integraciones |
| `0004_policies_tiers_releases.sql` | Documentos/versiones de política, tiers, suscripciones y releases |
| `0005_activity_presence.sql` | Episodios, dedupe, snapshots, agregados, presencia y telemetría |
| `0006_audit_idempotency_notifications.sql` | Actores, auditoría, idempotencia y notificaciones/webhooks |
| `0007_integrity_guards.sql` | Integridad de episodios y registro auxiliar de vigencias; triggers |
| `0008_seeds.sql` | Semillas deterministas, en una transacción |
| `0009_admin_surface.sql` | Paginación administrativa y recompilaciones pendientes |
| `0010_external_global_credentials.sql` | UNIQUE global para `oauth_clients.client_id` y `api_keys.public_prefix` |

`0010` debe aplicarse antes del código de autenticación externa que consulta
identificadores globales. Conserva los índices y FKs por tenant. Las semillas
de `0008` no insertan clientes OAuth ni API keys; el smoke verifica semillas e
inserciones entre tenants con los nuevos índices. En instalaciones existentes,
comprobar ambos identificadores con `GROUP BY ... HAVING COUNT(*) > 1` y resolver
los duplicados mediante nuevos identificadores antes de migrar. No se borran
credenciales automáticamente. Los dos `ALTER TABLE` hacen commit implícito:
si falla el segundo, el primero permanece aplicado; tras resolver la causa,
retirar únicamente el índice nuevo ya creado antes de reintentar el archivo
con el runner. La repetición de una migración completada se omite por checksum.

El runner toma un lock por base, aplica en orden, conserva SHA-256 de cada archivo y omite los ya aplicados. Rechaza cambios de checksum en una migración aplicada. DDL en MySQL hace commit implícito: un fallo puede dejar parte del archivo instalada. Corregir la causa y repetir **el mismo archivo**; `IF NOT EXISTS` y las semillas sin sobreescritura permiten reanudar la instalación. Las siguientes evoluciones deben tener números nuevos, no editar los SQL desplegados.

Para `0008_seeds.sql`, el runner usa `PDO::beginTransaction()`, confirma con `commit()` las semillas y su registro en `schema_migrations` juntos y ejecuta `rollBack()` ante fallo. El SQL semilla no abre ni confirma transacciones. El splitter respeta strings, identificadores entre backticks, comentarios `--`/`#`/`/* */` y `DELIMITER`; admite `; -- nota` y comentarios dentro del statement. Usa el modo SQL fijado por el runner; no cambiar a `NO_BACKSLASH_ESCAPES` dentro de una migración.

También pueden ejecutarse con el cliente `mysql`, usando `SOURCE backend-v4/migrations/0001_tenants_catalogs.sql;` y luego cada archivo en el orden de la tabla. Ese modo no crea el historial/checksums del runner. No alternar ambos mecanismos sobre una base ya gestionada. `IF NOT EXISTS` no detecta drift de una tabla creada manualmente y no convierte este baseline en un upgrade desde K3. No desactivar `FOREIGN_KEY_CHECKS`.

En ese modo manual, envolver **solo** `0008_seeds.sql` en `START TRANSACTION` y ejecutar `COMMIT` únicamente si todos sus statements tuvieron éxito; ante cualquier error ejecutar `ROLLBACK`. El runner PHP realiza este control automáticamente.

Para resetear **una base de desarrollo exclusiva de v4**, detener sus procesos y ejecutar explícitamente:

```sql
DROP DATABASE keeper_v4;
CREATE DATABASE keeper_v4 CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

Después repetir el comando PHP. El runner no incluye reset automático. No usar ese procedimiento sobre bases compartidas o de producción.

## Tablas y correspondencia con la API

Los DTO, `*Input`, `*Create`, `*Patch`, `*Page`, ACK y respuestas HTTP no requieren tablas propias. Dashboard, TeamMember, MemberDashboard y ProductivityReport son proyecciones de miembros/asignaciones, dispositivos y agregados, no copias del mismo dato.

| Dominio / recursos OpenAPI | Tablas |
|---|---|
| Tenant, Branding, OrgUnit, Schedule | `tenants`, `branding`, `org_units`, `sociedades`, `schedules`, `schedule_days` |
| User y vigencias | `users`, `user_external_refs`, `user_assignments`, `assignment_intervals` |
| Permission, Role, RoleScope | `permissions`, `role_templates`, `role_template_permissions`, `roles`, `role_permissions`, `role_area_scopes`, `role_site_scopes`, `user_roles` |
| AdminSession, Csrf, Reauth | `admin_accounts`, `admin_sessions`, `admin_pre_sessions` |
| Device, PublicKey, EnrollmentTicket, Challenge, DeviceToken, Sync | `devices`, `device_assignments`, `device_keys`, `enrollments`, `device_challenges`, `device_sessions`, `device_signature_nonces`, `device_sync_state` |
| Command, CommandResult, SecurityReport, LogEntry | `device_command`, `device_command_results`, `security_reports`, `client_logs` |
| Policy, Rule, EffectivePolicy | `policy_documents`, `policy_versions`, `policy_rules`, `policy_assignments`, `effective_policies` |
| Episode, ActivitySnapshot, productividad/dashboard | `episodes`, `episode_ingest_keys`, `activity_snapshots`, `episode_daily`, `day_summary`, `focus_daily`, `app_classification`, `rollup_days`, `retention_settings` |
| CheckIn, Location | `doors`, `check_ins` |
| Tier, Subscription | `modules`, `tier`, `tier_module`, `firma_module_override`, `subscriptions` |
| Integration, IntegrationSecret, AccessToken | `scopes`, `integrations`, `integration_scopes`, `api_keys`, `oauth_clients`, `oauth_access_tokens`, `oauth_token_scopes` |
| AuditEntry e idempotencia | `principals`, `audit_log`, `idempotency_keys` |
| Notification, Webhook | `notifications`, `webhooks`, `webhook_events`, `webhook_scopes`, `webhook_deliveries` |
| Release, ReleaseDeployment | `client_releases`, `release_deployments` |

`org_units.kind` conserva exactamente `firm/site/area/position` del contrato. Son las firmas/sedes/áreas/cargos; los discriminadores y FKs compuestas impiden usar, por ejemplo, una sede como firma. `sociedades` es una entidad adicional exigida por el brief. `users` exige tenant, firma, área, sede, cargo y horario. Su proyección actual se actualiza en la misma transacción que cierra/abre `user_assignments`; el área y sede constituyen una intersección, no una unión. Las referencias externas son opcionales y únicas por `(tenant, source, external_ref)`; no existe `legacy_employee_id` obligatorio.

Los IDs son UUID públicos almacenados como `BINARY(16)`; PHP convierte con `hex2bin(str_replace('-', '', $uuid))`, o SQL con `UUID_TO_BIN(uuid)` **sin swap**. Usar `BIN_TO_UUID(id)` para salida. Hashes SHA-256/thumbprints se almacenan como 32 bytes, no como 64 caracteres; la API convierte SHA-256 a hexadecimal y el thumbprint JWK a base64url. Fechas de eventos son `DATETIME(6)` UTC; `day` en agregados es fecha de negocio con zona IANA, no necesariamente `DATE(started_at)`.

## Frontera de tenant y autoridad de plataforma

Todas las tablas de datos tienen `tenant_id NOT NULL`. Toda FK a datos empresariales incluye tenant y apunta a PK/UNIQUE explícita. No hay cascadas de eliminación ni unicidad global de correos de miembros. Las únicas tablas de negocio sin tenant son **catálogos globales**: `permissions`, `role_templates`, `role_template_permissions`, `scopes`, `modules`. `schema_migrations` es metadato de despliegue.

La plataforma usa el tenant reservado `00000000-0000-4000-8000-000000000001`; demo usa `00000000-0000-4000-8000-000000000002`. El reservado no es una empresa cliente ni un contexto seleccionable por un admin empresarial. Aloja cuentas Super admin, pre-sesiones sin identidad, política global y releases de plataforma. No se usa `tenant_id=NULL` como comodín.

Excepciones explícitas, acotadas por `CHECK` y FK: `release_deployments.release_tenant_id` siempre apunta al reservado; `principals.admin_tenant_id` solo puede ser el tenant del evento o el reservado. Así se audita una actuación de plataforma dentro de una empresa sin atribuírsela a un administrador local. La API devuelve `tenant_id=null` en las proyecciones globales que así lo especifican; ese null no existe como pertenencia de almacenamiento. Políticas globales solo tienen `scope='global'` en el reservado; el compilador las consulta por ese contexto fijo y materializa la política efectiva dentro del tenant destinatario.

MySQL no aporta RLS: las FKs impiden referencias cruzadas, pero **no autorizan SELECT/UPDATE/DELETE**. PHP debe exigir `TenantContext` y filtrar `(tenant_id,id)` en todas las consultas, joins, conteos, cachés y jobs. La identidad autenticada fija el contexto; un UUID, header, prefijo de credencial o IP nunca demuestra pertenencia. Los descriptores `resource_type/resource_id` de auditoría/notificaciones no son relaciones polimórficas autorizantes: resolverlos siempre bajo el tenant registrado y validar el objeto al crear la notificación.

Las cuentas normales referencian un miembro local. `is_platform_admin` solo es válido en el reservado y constituye autoridad de plataforma, independiente del nombre de un rol. El backend de empresa no debe tener DML directo sobre catálogos globales, gate, autoridad de plataforma, ledger de ingestión ni `assignment_intervals`. Utilizar credenciales de servicios separadas o una capa PHP central de autorización con privilegios mínimos; los triggers se ejecutan con el DEFINER de instalación, que debe conservarse como cuenta técnica restringida.

`assignment_intervals` solo lo mantienen triggers. Es necesario para usar lecturas **actuales y bloqueantes** sin bloquear la tabla que disparó el trigger (MySQL lo prohíbe). Serializa por usuario/equipo y rechaza solapamientos en altas y cambios, incluidos intervalos cerrados de suscripciones. Los intervalos son `[starts_at,ends_at)`; adyacentes se admiten. Hay además unicidad del intervalo abierto. PHP debe bloquear los padres en orden estable y reintentar deadlocks; nunca modificar el histórico organizativo de episodios ya aceptados. Cambios de organización cierran una vigencia y crean otra. Al reasignar equipo, cerrar la vigencia y revocar tokens en la misma transacción; en transferencia de empresa crear identidad nueva, dejando el histórico en origen.

## Semillas y RBAC

Hay 41 permisos (incluidos los condicionales de títulos/GPS y todos los comandos), 12 scopes read, 6 módulos, 8 plantillas versionadas y 8 instancias de rol: Super admin en plataforma y siete en demo. Los nombres de permiso siguen las acciones del YAML: `refresh_policy` corresponde a `reglas.aplicar`, los extras son `actividad.titulos_ver` y `presencia.ubicacion_ver`.

| Rol | Alcance semilla | Permisos predeterminados |
|---|---|---|
| Super admin | Plataforma con contexto explícito | Todo el catálogo; gate, catálogo maestro, transferencias, publicación y meta-permiso |
| Gerencia | Empresa | Lecturas del núcleo, auditoría y edición de empresa |
| Dirección | Área | Lecturas del núcleo y edición de reglas |
| Coordinación | Área | Lecturas del núcleo y edición de horarios |
| IT | Empresa | Inventario, enrolamiento/asignación, lock/unlock/restart/shutdown, aplicar/editar políticas, auditoría y despliegue; sin wipe/transferencia/publicación |
| RRHH | Empresa | Miembros, organización, horarios, reportes, presencia sin GPS, tiers y notificaciones de lectura |
| Admin Empresa | Empresa | Lecturas del núcleo y reportes de su empresa |
| Colaborador | Propio | Ningún permiso de panel y sin login |

La matriz exacta es `0008_seeds.sql`; los defaults son conservadores y editables, no una jerarquía implícita. Ningún rol empresarial recibe `roles.gestionar`, títulos, GPS o wipe de fábrica. Dirección/Coordinación tienen el área demo explícita. Un alcance área/sede sin filas se interpreta como **ningún acceso**; nunca empresa completa. PHP crea/edita rol y alcances en una transacción y valida lista no vacía para esos tipos. Los dos gates son obligatorios: `tenants.rbac_self_management=false` de fábrica y permiso `roles.gestionar`. Solo Super admin cambia el gate o concede ese meta-permiso, incluso indirectamente por clonación/asignación. SQL guarda la matriz; PHP debe comprobar al actor real, no inferir autorización de un valor de sesión SQL manipulable.

Al crear otro tenant, copiar las siete plantillas empresariales y sus permisos con UUID nuevos, conservar `seed_key/seed_version`, crear sus scopes locales, marca y retención. No copiar Super admin. Los roles pueden clonarse mediante `cloned_from_id` y desactivarse con `active`; no se eliminan para conservar historia. Editar una plantilla no modifica automáticamente instancias existentes. Incrementar `auth_version`, invalidar sesiones/cachés y auditar cambios de gate, permisos, roles y estados.

Demo incluye firma/sede/área/cargo/sociedad, horario de lunes a viernes, colaborador sin login, su asignación, Essential/Pro/Business y una suscripción Essential por miembro. Los módulos semilla son una propuesta inicial editable: Essential=`devices,activity`; Pro añade `productivity,presence`; Business añade `policies,integrations`. No se inventan precios. `firma_module_override` prevalece sobre tier para el módulo de la firma; los entitlements no conceden permisos RBAC. La política global inicial es **vacía**, válida para compilar, sin controles ni hosts inventados.

No se crean passwords conocidos, cuentas habilitadas, tickets, claves privadas, releases ficticias ni firmas falsas. El primer Super admin debe aprovisionarse por canal administrativo seguro con `password_hash()`; el runner solo instala esquema/datos de referencia. Publicar la primera release exige un artefacto y manifest auténticamente firmados. Las semillas usan IDs fijos, no sobrescriben valores existentes y toleran repetición sobre una instalación intacta. No reaplicar manualmente semillas sobre permisos personalizados eliminados: podría reintroducir relaciones predeterminadas; el runner evita esto al omitir el archivo aplicado.

## Autenticación, sesiones y comandos

| Registro | Límite persistido |
|---|---|
| `admin_pre_sessions` | 5 minutos, consumo de un uso |
| `admin_sessions` | 8 horas absolutas y 30 minutos desde `last_seen_at`, ambos NOT NULL y CHECK |
| `enrollments` | Pendiente/consumido/revocado/expirado; ticket hash, miembro y thumbprint; máximo 10 minutos |
| `device_challenges` | Device **o** enrollment, nonce hash, máximo 60 segundos |
| `device_sessions` | Máximo 1 hora; tenant + equipo + miembro + vigencia + clave por FKs |
| `device_signature_nonces` | Unicidad tenant/clave/nonce, vigencia entre 120 y 300 segundos |
| `api_keys` | Hash de secreto aleatorio, vigencia máxima 90 días |
| `oauth_access_tokens` | Tokens opacos con hash, audience fija, máximo 15 minutos y scopes subordinados a integración |
| `idempotency_keys` | Intención de 24 horas; CHECK acepta duración positiva hasta 25 horas para tolerar diferencias de timestamps; ámbito tenant + principal + método + ruta + clave |

Los CHECK verifican duración máxima, no el paso del reloj: cada petición comprueba expiración, revocación, cuenta/miembro/empresa/equipo activos y versiones de autorización. Reauth requiere `reauthenticated_at >= now-5min`. Renovar idle solo si la sesión aún era válida, limitado por su vencimiento absoluto. La entrega del CSRF puede derivarse de la cookie con HMAC del servidor, persistiendo su hash; no se puede reconstruir un token aleatorio a partir de su hash. La política de cookies/CSRF/origen y RFC 9421 se implementa en PHP, no en SQL.

Registrar únicamente coordenadas públicas P-256 y thumbprint verificado; nunca aceptar que login reemplace la clave registrada. Consumir challenge/ticket mediante UPDATE condicionado a no consumido/no expirado, crear identidad/token y auditar dentro de una transacción. Al consumir enrollment completar `device_id`; la secuencia de sync está ligada a ese enrollment y equipo por FK. Revocar/recuperar/reasignar incrementa versiones y revoca sesiones. Las API keys/client_id deben llevar un prefijo de localización inequívoco generado por servidor; el hash/secreto autentica, el prefijo no autoriza.

`device_command` contiene expiración, secuencia por equipo, estado y resultado final. `device_command_results` deduplica por equipo/evento y su FK impide resultados de otro equipo. PHP impone transiciones y conflicto de resultado terminal; la secuencia y `envelope_jws` reservan persistencia para el futuro contrato WSS. El esquema puede representar wipe, pero el agente descrito lo declara no soportado. No se habilita por almacenar el enum.

Auditoría es append-only y referencia un actor tipado real de `principals`. Para serializar AuditEntry, devolver el ID administrativo/device/integration referenciado, no exponer el surrogate local como si fuera ese recurso. `reason` y campos modificados se saneen; no guardar PIN, Authorization, secretos o cuerpos completos. Respuestas de idempotencia se almacenan cifradas con AEAD (nonce/tag dentro del blob y clave fuera de BD), porque pueden incluir un token renovado. El backend compara `request_hash` y devuelve replay o 409; no emite otro token para una respuesta ya registrada.

## Ingestión, particiones y retención

`episodes` está particionada por **fecha UTC de inicio** (`event_date = DATE(started_at)`), inicialmente por meses julio de 2026–diciembre de 2027, con partición anterior y `p_future`. El event `maintain_episode_partitions` queda habilitado, comienza un día después de instalarse y se ejecuta diariamente en UTC. Reorganiza `p_future` mes a mes hasta cubrir el mes actual y los doce siguientes; recupera meses pendientes si el scheduler estuvo detenido.

MySQL no permite FKs desde/hacia InnoDB particionada y exige incluir la columna de partición en toda clave única. Por eso:

1. La PK de `episodes` incluye `(tenant_id,device_id,event_id,event_date)`.
2. El trigger inserta atómicamente `episode_ingest_keys`, sin particionar, cuya PK es **`(tenant_id,device_id,event_id)`**. Sus FKs validan el mismo tenant, miembro y ambas vigencias. También valida intervalos y ventana de ingestión. La unicidad no puede eludirse cambiando la fecha.
3. Un fallo del crudo revierte el ledger. No usar `INSERT IGNORE`/`REPLACE`: PHP interpreta duplicate key, lee el hash existente y distingue ACK duplicate de rejected/event_conflict. Nunca responder accepted antes del COMMIT.
4. El crudo y ledger son inmutables por UPDATE. No se puede borrar ledger mientras exista el crudo o su fecha siga dentro de la ventana de ingestión. La cuenta de aplicación no escribe directamente en el ledger.

Documentación primaria: [restricciones de InnoDB particionada](https://dev.mysql.com/doc/refman/8.0/en/partitioning-limitations-storage-engines.html) y [claves únicas y columna de partición](https://dev.mysql.com/doc/refman/8.0/en/partitioning-limitations-partitioning-keys-unique-keys.html).

Los defaults operativos están en `retention_settings`: crudo y títulos **90 días**, llegada tardía **30 días**, agregados **13 meses**, logs **30 días**. Son decisiones iniciales de almacenamiento, ajustables por negocio; no una conclusión legal. `raw_days >= late_arrival_days` está comprobado por SQL. La deduplicación dura toda la vida del crudo. Nunca ampliar la ventana de aceptación más allá de la cobertura conservada del ledger: primero conservar/recuperar dedupe o mantener un corte explícito para no resucitar eventos purgados.

Plan de jobs PHP, separado del request HTTP:

1. **Ingestión:** validar UUID/cuerpo/firma/estado y hash canónico en servidor; insertar evento y marcar `rollup_days` dirty, aumentando `input_revision` para cada día **local** afectado, dentro de la misma transacción. Se divide el intervalo para cálculo al cruzar medianoche local; no se cambia el UUID del evento crudo. Si cruza una vigencia de asignación, dividir/cuarentenar antes de aceptar. Snapshots son contadores absolutos monotónicos; nunca sumarlos a episodios para contar dos veces la misma actividad.
2. **Rollup reanudable:** bloquear el checkpoint tenant/día, leer revisión de entrada, recalcular `episode_daily`, `day_summary`, `focus_daily` y reemplazar sus filas en una transacción. Usar revisión/calculation_version para no limpiar dirty si llegaron eventos nuevos. Resolver área∩sede mediante la asignación histórica. Deduplicar intervalos solapados entre equipos al calcular totales por miembro; no sumar focus scores ni porcentajes sin sus denominadores. Exponer `calculated_at/data_through`.
3. **Finalización:** cerrar solo días fuera de la ventana tardía y sin trabajo dirty; guardar `finalized_at`. Los días limítrofes se comprueban en zona de negocio, incluyendo UTC± y DST. Cambios de clasificación invalidan días aún reconstruibles; después de purgar crudo solo queda la precisión del rollup por proceso, no títulos ni detalle temporal.
4. **Purga de crudo:** por tenant, fuera de su retención y con todos los días locales afectados finalizados. DELETE en lotes de hasta 5.000 filas, presupuesto inicial de 30 s por ejecución y checkpoint reanudable. Bloquear/coordinar cambios de retención durante el corte; no truncar toda la tabla. El job se detiene si falta un rollup.
5. **DROP de partición automático:** el event elimina particiones cuyo límite superior sea menor o igual a `UTC_DATE() - MAX(raw_days)` de todos los tenants, usando 90 días para tenants sin configuración. Antes comprueba que cada día local afectado por el crudo tenga un rollup finalizado, limpio y con revisión actual; si falta un rollup o una conversión de zona horaria, detiene la purga. Las particiones son compartidas: con retenciones diferentes, el job por tenant sigue purgando filas del que vence antes. DROP PARTITION no dispara triggers. Después el job PHP purga ledger de ese rango en lotes, verificando ausencia de crudo y ventana tardía cerrada. Primero crudo, después ledger, nunca al revés.
6. **Agregados:** purgar después de 13 meses por fecha de negocio, o archivar cifrados antes. Purgar logs separadamente a 30 días; idempotencia expirada a 24 h; sesiones/challenges/nonces tras expirar. Índices de expiración/fecha permiten lotes sin recorrer usuarios. Auditoría y manifests no se purgan por estos jobs; archivado tiene procedimiento/credencial separada.

El administrador debe habilitar y persistir el scheduler (requiere privilegios de administración del servidor); la migración no modifica variables globales:

```sql
SET PERSIST event_scheduler = ON;
SELECT @@GLOBAL.event_scheduler;
SELECT event_name, status, last_executed
FROM information_schema.events
WHERE event_schema = DATABASE() AND event_name = 'maintain_episode_partitions';
```

El DEFINER del event debe conservar permisos `EVENT`, `ALTER`, `DROP` y lectura sobre la base. Comparte con el runner el lock `keeper_v4:` + los primeros 48 caracteres de `SHA2(DATABASE(),256)`; los jobs que cambien retención, horarios o invaliden rollups deben usar ese mismo lock durante el cambio. Evita solapamientos y libera el lock también ante error. Vigilar `last_executed`, el log de errores y el crecimiento de `p_future`; `last_executed` registra intentos, no garantiza éxito. DDL requiere metadata lock y puede reorganizar datos; probar su coste con carga representativa. El event no purga ledger, agregados, logs ni tokens. Referencia: [CREATE EVENT](https://dev.mysql.com/doc/refman/8.0/en/create-event.html) y [operaciones sobre particiones](https://dev.mysql.com/doc/refman/8.0/en/alter-table-partition-operations.html).

Índices de detalle: `(tenant,user,started_at,event_id)`, `(tenant,device,started_at,event_id)` y `(tenant,started_at,event_id)`. Las consultas incluyen **`event_date` entre límites UTC** para partition pruning, además de tiempo, tenant y alcance; `started_at` solo no garantiza pruning de RANGE COLUMNS(event_date). Ledger usa índice global por fecha para purga. Agregados tienen PK tenant/día/miembro/asignación y acceso miembro/día; equipos tienen tenant/estado/last_seen. No hay índices de texto completo sobre títulos.

El coste adicional del ledger es deliberado: evita duplicados entre fechas y mantiene las FKs que la tabla particionada no admite. La auditoría estima ~960.000 episodios/día con 1.000 equipos y 2 episodios/minuto durante 8 h; 90 días suponen ~86,4 millones más el ledger. Particionar no reduce esas escrituras. Medir tamaño/índices, EXPLAIN ANALYZE, lotes y workers en staging; estos archivos no certifican capacidad para 1.000 equipos.

## Contratos pendientes y límites del cierre

- `openapi-v4.yaml` aún representa `Device.policy_version`, `SyncRequest/Response.policy_version` y `EffectivePolicy.version` como string. La decisión final de `v4-api.md` y esta tarea prevalecen: SQL usa **BIGINT UNSIGNED monotónico**. Antes de generar DTO, reconciliar el YAML; estos archivos no lo modifican. ETag es ese entero serializado/entrecomillado, ligado al tenant/device. No ordenar strings lexicográficamente.
- Cada cambio efectivo, también en capa global/horario/compilador, asigna bajo lock una revisión superior desde `tenants.policy_version` y materializa una nueva `effective_policies`. No usar solo la versión del documento local ni resetear contadores tras una reasignación. `devices.policy_version` es la última reportada; la mayor efectiva persistida es la deseada. JSON de documento y filas `policy_rules` se publican juntos, validados contra el mismo contrato; los schedules y composición usados deben quedar copiados en el documento inmutable. No sumar una cabecera/campo wire nuevo.
- WSS, sobres de política/comando, screenshots, bootstrap/escrow y parámetros detallados de enforcers siguen pendientes de contrato en `v4-client-agent.md`. Solo se reserva almacenamiento pertinente; no se inventan endpoints ni se codifica shell en `targets`.
- `AdminLogin` solo acepta email/password y no especifica desambiguación de un mismo email en varios tenants. Se conserva unicidad por tenant; PHP debe resolver contexto por un mecanismo de login acordado o rechazar ambiguos sin elegir arbitrariamente una cuenta. No convertir una coincidencia de email en acceso a varias empresas.
- Hash/firma de release son obligatorios e inmutables, pero SQL no verifica criptografía. Verificar JWS ES256, campos exteriores, origen HTTPS autorizado, hash, longitud, arquitectura y secuencia en backend/updater. La clave privada queda fuera de BD; la raíz pública se ancla en updater. Una cadena arbitraria no se vuelve firma válida por cumplir un CHECK.

| Auditoría | Cierre en esta entrega | Pendiente de implementación/operación |
|---|---|---|
| §3.3 migraciones irreproducibles | Baseline ordenado, enrollment/pending real, semillas, sin ALTER duplicados ni eliminación de sociedades legacy | Migración/backfill de K3 y aprovisionar primer admin/release auténticos |
| §6.4/§6.5 crecimiento e índices | Dedupe fuerte, partición mensual, índices por tenant y tiempo, agregados y checkpoints; retención documentada | Jobs, carga, EXPLAIN, dimensionamiento, backup/restauración |
| §6/§9 frontera de tenant | FKs/PK/UNIQUE compuestas y puente validado para episodios; roles/scopes separados | TenantContext, autorización HTTP, pruebas IDOR y aislamiento de cachés |
| §9.1 sesiones y re-enroll | TTL obligatorio, revocación/versiones, tickets de un uso, clave pública, nonce y tokens ligados al equipo/vigencia | RFC 9421 real, consumo transaccional, estado activo en cada petición |
| §9.2 actor/RBAC | Actor con FK, audit append-only, gate OFF, meta-permiso y permisos de plataforma separados | CSRF, reauth, anti-escalada y auditoría en cada mutación |
| §9.5 updater sin integridad | Hash/longitud/JWS/key_id obligatorios, secuencia y manifests inmutables, rollout por tenant | Firma/validación real antes de extraer/ejecutar en updater |

## Verificación reproducible

Usar una base desechable cuyo nombre termine en `_test` o `_validation` (admite sufijo numérico), aplicar las migraciones y ejecutar:

```powershell
php -l backend-v4/migrations/run.php
php -l backend-v4/migrations/verify.php
php backend-v4/migrations/run.php
php backend-v4/migrations/verify.php
php backend-v4/migrations/run.php # SKIP de los ocho archivos
```

`verify.php` comprueba estructura, semillas y restricciones con fixtures dentro de una transacción que revierte al terminar, también ante fallo. No ejecutarlo sobre la base productiva. Para comprobar además repetición directa de SQL, hacerlo únicamente sobre la base desechable e intacta. El runner no usa framework ni imprime secretos.

Revalidación local (2026-09-16): instalación desde cero en MySQL **8.4.3**, PHP **8.3.30**; **58 comprobaciones** de `verify.php` aprobadas; segunda ejecución del runner omite los ocho archivos; reaplicación directa de los ocho SQL conserva los conteos de las 72 tablas; ambos PHP pasan `php -l`. Las regresiones adicionales verificaron strings/comentarios/delimitadores del splitter, rollback real con fallo inyectado al final del seed y reintento exitoso. Con el scheduler activo se comprobó creación de meses futuros, protección de la mayor retención, bloqueo por rollup pendiente o zona no disponible, DROP de una partición vencida con datos y rollup finalizado, conservación del ledger y repetición idempotente. No se ejecutó MySQL 8.0 exacto ni carga/concurrencia; repetir en la versión exacta de despliegue antes de producción.
