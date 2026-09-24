# Importar K3 en v4 dev

Requiere PHP 8.2+, PDO MySQL, mbstring y las migraciones v4 aplicadas. No migra esquemas ni crea tenants. El bootstrap carga `backend-v4/.env`; las variables del proceso prevalecen. **`KEEPER_DB_*` debe apuntar a dev.** El usuario K3 debe tener únicamente permiso `SELECT`.

```powershell
$env:K3_DSN = 'mysql:host=HOST_K3;port=3306;dbname=BD_K3;charset=utf8mb4'
$env:K3_USER = 'lector_k3'
$env:K3_PASSWORD_FILE = 'C:\secretos\k3-password.txt'
# Alternativa: K3_PASSWORD inyectado por el gestor de secretos.
# KEEPER_DB_DSN, KEEPER_DB_USER y KEEPER_DB_PASSWORD[_FILE]: .env de dev.
$env:IMPORT_TENANT_ID = '00000000-0000-4000-8000-000000000002'
$env:IMPORT_TENANT_NAME = 'Grupo AZC' # opcional
$env:K3_EPISODE_DAYS = '3'
$env:K3_EPISODE_MAX = '50000'
php backend-v4/tools/import-k3.php --dry-run
php backend-v4/tools/import-k3.php
php backend-v4/tools/import-k3.php --only=org,users,devices
php backend-v4/tools/import-k3.php --only=daily,episodes
```

Sin `IMPORT_TENANT_ID` exige exactamente un tenant no-plataforma. El nombre opcional actualiza `tenants` y `branding`. `org` incluye horarios y defaults; `users` también crea esos defaults e importa horarios porque son dependencias obligatorias. Los demás grupos reutilizan las dependencias existentes, siempre dentro del tenant seleccionado. Sin dependencias, omiten filas. No copia contraseñas ni habilita acceso al panel para los usuarios importados.

| K3 | v4 / criterio |
|---|---|
| `keeper_firmas` | `org_units`, `kind=firm` |
| `keeper_sedes` | `org_units`, `kind=site` |
| `keeper_areas` | `org_units`, `kind=area`; padres primero, ciclos/huérfanos omitidos |
| `keeper_cargos` | `org_units`, `kind=position` |
| `keeper_work_schedules` | `schedules` y `schedule_days`; activos, mayor id por usuario; sin horario propio se usa el respaldo del tenant. Los horarios globales K3 se conservan en el catálogo |
| `keeper_users` | `users`, nombre = `display_name` → `cc` → `email` (primer valor no vacío); solo se omite por nombre si faltan los tres; `locked` o retirado → `inactive`. `user_external_refs`: `k3` = id K3, `k3:cc`, `k3:legacy_employee_id`, `k3:status`, `k3:employment_status`. Los dos estados usan `id:valor` por la unicidad del destino |
| `keeper_user_assignments` | `user_assignments`; dimensiones faltantes o no resolubles usan las unidades de respaldo. Cada intervalo termina al comenzar el siguiente; misma fecha conserva mayor id. Se usa el horario actual, pues K3 no relaciona horarios históricos con asignaciones |
| `keeper_devices` | `devices` y `device_assignments`; GUID, serial, estado de presencia y `day_summary_json` en `specs.k3`. Sin especificaciones de hardware en el esquema fuente: `os_edition/cpu=unknown`, `ram_bytes=0`, capacidades vacías |
| `keeper_activity_day` | `episode_daily` por equipo con proceso marcador `__k3_daily__`, categoría `unclassified`, `episode_count=0`; `first_event_at/last_event_at` → `first_activity/last_activity`. `day_summary` suma equipos por usuario/día y toma MIN de primera actividad y MAX de última, ignorando NULL. Último evento → `data_through` |
| `keeper_focus_daily` | `focus_daily`: suma `deep_work_seconds`, media simple de `focus_score` entre equipos. También mapea las siete columnas de `0013`: `first_activity_time`, `scheduled_start`, `punctuality_minutes`, `productivity_pct`, `constancy_pct`, `deep_work_sessions`, `longest_focus_streak_seconds` (reglas debajo). `productivity_pct` ponderado por segundos activos alimenta `day_summary`; sin métricas completas deja porcentaje NULL y segundos productivos 0 |
| `keeper_window_episode` | `episodes`, únicamente ventana reciente, páginas de hasta 1.000 filas y límite total obligatorio; los triggers crean `episode_ingest_keys`. Duración → activos, idle=0 porque el origen no lo desglosa |

Fase B: las columnas de foco, primera/última actividad, puntualidad y sociedad ya se importan; véase [PHASE-B.md](PHASE-B.md). Permanecen las limitaciones de pausas de almuerzo y de desglose de actividad simultánea entre equipos.

Fechas `DATETIME` de eventos K3 se interpretan como UTC; `day_date` se conserva. Para `keeper_activity_day.first_event_at/last_event_at`, `K3_DATETIME_TIMEZONE` permite declarar otra zona de origen si se ha verificado que esa instalación guarda horas locales; por defecto es `UTC`. Se convierten explícitamente a UTC con microsegundos para v4 y para el archivo portable. **No restar cinco horas al guardar en v4**: `ActivityReports` espera instantes UTC en `day_summary/episode_daily`; el calendario aplica la zona del horario del tenant. Por ejemplo, `2026-09-16 13:05:00` UTC representa `08:05:00` en `America/Bogota`. Con `K3_DATETIME_TIMEZONE=America/Bogota`, un origen `08:05:00` se almacena como `13:05:00` UTC. El tipo SQL DATETIME por sí solo no identifica una zona; no se infiere del reloj de la máquina ni de la sesión MySQL.

`focus_daily.first_activity_time` y `scheduled_start` son `TIME`, horas locales del horario del tenant: se copian sin conversión UTC. El archivo declara este contrato en su cabecera; requiere que K3 y v4 usen el mismo día/zona laboral. No se reconstruye el día desde un TIME ni se cambia `day_date`. Si hay varios equipos, se toma la primera hora no nula (empate: menor id de equipo), junto con su horario y puntualidad. La puntualidad conserva signo K3 (positivo = temprano, negativo = tarde); queda NULL si falta la primera hora o la métrica. Productividad y constancia usan media simple de valores no nulos (todos NULL → NULL); sesiones se suman y la racha toma MAX. Se conserva el mayor id fuente por usuario/equipo/día antes de agregar. El ETL normal además deja puntualidad NULL en días no laborables según el calendario destino; el portable restaura la métrica fuente sin recalcular calendarios.

Los `TIMESTAMP` se leen como epoch para evitar la zona de la sesión origen. Para cada resumen se elige la última asignación que intersecta ese día en la zona del horario. No se divide un total diario entre cambios intradía. Episodios exigen que ambas asignaciones cubran todo el intervalo, como requieren los triggers. La asignación de respaldo permite importar actividad anterior al historial K3. Cruces de una reasignación real, propietarios incompatibles y eventos posteriores a la baja del equipo siguen siendo omisiones. Los diarios conservan el usuario reportado por la actividad aunque el equipo tenga otro propietario actual, ya que su esquema lo permite.

## Backfill portable: destino sin acceso a K3

Sirve para reparar diarios ya importados con actividad y horas NULL, sin volver a importar usuarios, equipos, episodios ni totales. Requiere las migraciones destino que incluyen `0011` y `0013`. Ambos modos son excluyentes entre sí y con `--only`.

1. **Exportar desde la máquina cuya IP sí tiene acceso a K3.** Solo necesita las variables `K3_*`; no conecta a la BD v4. Usa el mismo lector protegido: SELECT preparado, READ ONLY verificado y multi-statement deshabilitado. Exporta todo el histórico diario con paginación de 1.000 filas.

   ```powershell
   $env:K3_DSN = 'mysql:host=HOST_K3;port=3306;dbname=BD_K3;charset=utf8mb4'
   $env:K3_USER = 'lector_k3'
   $env:K3_PASSWORD_FILE = 'C:\secretos\k3-password.txt'
   $env:K3_DATETIME_TIMEZONE = 'UTC'
   php backend-v4/tools/import-k3.php --export-backfill=k3-backfill.jsonl
   if ($LASTEXITCODE -ne 0) { throw 'Exportación incompleta' }
   ```

   La ruta debe ser nueva: no sobrescribe archivos existentes. El JSONL contiene una cabecera versionada (nombre de BD fuente para rechazar ese destino y convenciones horarias), registros por usuario/día y un cierre con el número de registros. Incluye únicamente id de usuario K3, día, límites de actividad y las siete métricas de foco. Los registros de actividad incluyen el id numérico K3 del equipo y sus límites: es necesario para no asignar a todos los equipos las mismas horas. No exporta nombres, correo, cédulas, títulos de ventanas, payloads ni credenciales. El id de usuario es `keeper_users.id`, correspondiente a `user_external_refs.source='k3'`; **no** es `legacy_employee_id` ni `k3:legacy_employee_id`.

2. **Transferir el archivo completo al destino.** Por ejemplo, desde esa misma máquina:

   ```powershell
   scp k3-backfill.jsonl usuario@DESTINO:/ruta/privada/k3-backfill.jsonl
   ```

   Es texto JSONL comprimible: opcionalmente usar `gzip k3-backfill.jsonl`, transferir `.gz` y ejecutar `gunzip k3-backfill.jsonl.gz` en destino. El importador recibe el JSONL descomprimido. Conservar el archivo fuera del directorio público y eliminarlo tras la reparación.

3. **Importar en el destino.** Configurar allí `KEEPER_DB_DSN`, `KEEPER_DB_USER`, `KEEPER_DB_PASSWORD_FILE` (o contraseña inyectada) e `IMPORT_TENANT_ID` para v4, como en el ETL normal. No requiere credenciales K3, DNS ni conectividad hacia producción; tampoco lee `K3_DATETIME_TIMEZONE`, porque el archivo ya lleva los instantes en UTC.

   ```sh
   php backend-v4/tools/import-k3.php --import-backfill=/ruta/privada/k3-backfill.jsonl --dry-run
   php backend-v4/tools/import-k3.php --import-backfill=/ruta/privada/k3-backfill.jsonl
   ```

El importador valida tipos, fechas, rangos, versión y cierre del archivo completo antes de actualizar. Resuelve usuarios por `(tenant_id, source='k3', external_ref)`, aunque su UUID destino no sea el generado por el ETL. Los equipos conservan el UUID determinista por tenant/id K3 del importador original. Solo actualiza filas existentes de `calculation_version='k3-import-v2'`; en `episode_daily` exige además equipo correspondiente y `process_name='__k3_daily__'`. No crea usuarios, dispositivos o diarios faltantes ni modifica los totales, otras versiones de cálculo o timestamps de cálculo. Los valores NULL fuente también se restauran como NULL. Si hay varias asignaciones importadas para un usuario/día, se actualizan las filas coincidentes; no se distribuye el agregado intradía.

Usa el mismo bloqueo por tenant y transacciones de hasta 500 registros. Un error SQL revierte el lote actual; los anteriores permanecen confirmados y se puede reejecutar. Repetir el mismo archivo no suma métricas ni crea duplicados. Un archivo truncado o inválido se rechaza antes del primer lote. Continúa si falta un usuario o un diario, contándolos por separado.

Conteos específicos del backfill:

- Exportación: `leidas` = filas K3 (incluidos duplicados), `exportadas` = registros usuario/día tras deduplicar y agregar. `actualizadas=0`, `no_encontradas=0`: no hay búsqueda en destino durante esta fase.
- Importación, `user_external_refs`: registros leídos, encontrados y no encontrados. Un usuario puede aparecer en actividad y foco; son conteos de registros, no de personas únicas.
- Por tabla destino: `leidas` = candidatos, `actualizadas` = filas cuyos valores cambiaron, `sin_cambios` = filas idénticas, `no_encontradas` = candidatos sin fila importada compatible. Un usuario inexistente se cuenta en `user_external_refs` y no se intenta en las tablas. En `--dry-run`, las actualizaciones son proyectadas.

## Pruebas aisladas

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tools/test-k3-backfill.ps1
php -l backend-v4/tools/import-k3.php
php -l backend-v4/tools/k3-backfill.php
php -l backend-v4/tools/test-k3-backfill.php
```

El arnés requiere PHP con PDO MySQL y `mysqld` de MySQL 8 en PATH. Crea un servidor aislado en `127.0.0.1:13386` (configurable con `-MySqlPort`), usa las definiciones reales de las tablas y las migraciones `0011/0013`, y elimina proceso, BD y archivos al terminar. No usa las credenciales del `.env` para las conexiones de prueba. Comprueba el caso de 160 filas con segundos activos y horas NULL, segunda ejecución idéntica, 600 ids ausentes, usuario resuelto por referencia con UUID distinto, paginación, lotes, múltiples equipos, nulos, conversión UTC, dry-run, archivo truncado, aislamiento entre tenants, conservación de diarios nativos y las protecciones de lectura K3.

Seguridad e idempotencia:

- La conexión K3 es privada y solo expone `SELECT` preparado; deshabilita sentencias múltiples y rechaza otras instrucciones. Cada conexión, incluidas las reconexiones, ejecuta `SET SESSION TRANSACTION READ ONLY`; si falla o el servidor no confirma el modo, aborta. Después intenta únicamente los ajustes de sesión `net_read_timeout=120`, `net_write_timeout=120` y `wait_timeout=600`; si el lector no tiene permiso, los ignora silenciosamente. No modifica variables globales ni ejecuta DML/DDL en K3. Se rechaza un destino con el mismo nombre de BD o que contenga `keeper_users`.
- UUIDv5 fijos por namespace + tenant + tipo + id K3 (o clave fija `default`), almacenados como `BINARY(16)`. Upsert por búsqueda de clave seguida de UPDATE o INSERT: evita los triggers BEFORE INSERT de asignaciones y no sobrescribe otro usuario por un email duplicado. Episodios existentes se omiten: son inmutables.
- Lotes de 500, INSERT multirregistro y transacciones; si falla una fila por integridad, se aísla con savepoints y se cuenta. Errores de esquema o conexión no recuperables detienen la ejecución con código 1, sin imprimir credenciales ni valores. Los lotes anteriores quedan confirmados y se puede reejecutar.
- Retención y particiones permanecen intactas; si falta configuración de retención se crea con el valor v4 de 30 días. La ventana son los últimos N días desde el reloj UTC de dev, no desde el máximo histórico K3: `K3_EPISODE_DAYS=1..90`, `K3_EPISODE_MAX=1..50000`. No rellena la muestra con filas más antiguas si omite algunas.
- Al cambiar una asignación se eliminan únicamente resúmenes anteriores del mismo importador/usuario/día en dev para evitar duplicar totales; también se retiran días de horario obsoletos. No purga usuarios, equipos ni datos ausentes del origen.
- `--dry-run` hace solo lecturas y muestra conteos **proyectados**; no ejecuta triggers ni comprueba todos los conflictos de unicidad. En el resumen, “leídas” son candidatos destino (pueden ser agregados); se muestran aparte las filas leídas de cada tabla K3. “Actualizadas” incluye filas existentes sin cambios. Las omisiones incluyen motivos, sin datos personales.

Defaults del tenant (idempotentes, también en `--only=users`):

- `org_units` activas: `area` **Sin área**, `site` **Sin sede**, `position` **Sin cargo**, `firm` **Sin firma**. Solo reemplazan dimensiones ausentes, ambiguas o sin catálogo destino.
- Horario **K3 por defecto (almuerzo 12:00-13:00)**, L–V 08:00–18:00, `America/Bogota`. El esquema `schedules` no tiene columna `active` ni columnas de almuerzo: el horario queda disponible y asignado; el almuerzo se conserva como convención en el nombre. El cálculo de horas esperadas mantiene el tramo completo de 10 horas, porque v4 no representa pausas estructuradas.
- Cada usuario sin historial recibe una asignación abierta con las cuatro unidades de respaldo y su horario resuelto. Si hay historial, se crea un intervalo de respaldo anterior a la primera asignación, sin cambiar sus fechas reales. Inicio convencional: `1970-01-01 00:00:00 UTC`; no afirma una fecha real de contratación. Fechas de asignación ausentes usan ese mismo inicio. `users.created_at` ausente usa la última asignación o el instante de importación si no hay historial.
- K3 no aporta historial de propietarios: `device_assignments` usa el propietario actual desde el inicio convencional de 1970 hasta la baja, si existe. El `created_at` real del equipo queda en `specs.k3.created_at`. Hostname vacío usa GUID y luego id K3; versión ausente usa `unknown`. Se mantienen hardware desconocido, RAM 0, capacidades vacías y los defaults SQL de versión/estado/acceso.

Lectura y recuperación:

- Todas las tablas K3 se leen con columnas explícitas y keyset, sin `OFFSET`, máximo 1.000 filas por SELECT. Los diarios usan la clave compuesta usuario/día/equipo/id para mantener agrupación y deduplicación incluso al cruzar páginas; episodios usan `(start_at DESC,id DESC)` y respetan `K3_EPISODE_MAX` en total.
- Cada página se recibe completa y se cierra antes de procesarla en dev. Una caída al recibir una página no entrega filas parciales: se vuelve a pedir desde el último cursor procesado, con hasta tres reconexiones y espera acotada.
- Si se agotan los reintentos, confirma los lotes destino pendientes ya completos, imprime el resumen y señala tabla, filas procesadas y último id. Sale con código 1 para distinguir el resultado incompleto. No escribe agregados cuya agrupación no haya terminado; los conteos de lecturas K3 incluyen esas filas. La reejecución recupera lo faltante mediante los mismos UUID y claves.

Proyección para los volúmenes reportados: 238 usuarios y 298 equipos, salvo corrupción o conflictos reales del destino. Las 17.344 filas de foco y 20.759 de actividad dejan de depender de organización/horario completos; foco y resumen se agregan por usuario/día, por lo que sus filas destino pueden ser menos. Se esperan hasta 20.499 episodios para la misma ventana/muestra, sujetos a fechas, duración, retención y compatibilidad de propietarios. Los conteos exactos de producción requieren repetir el dry-run.
