# Fase A1: actividad, reportes y migración

Aplicar `php config/migrate.php`. Nuevas migraciones: `0011_activity_rollups.sql` y
`0012_migration_escrow.sql`. No modificar migraciones ya aplicadas. PHP requiere PDO MySQL,
OpenSSL y sodium; MySQL 8.0.30+ o MariaDB 10.5+. Las migraciones nuevas usan SQL común a ambos
motores. Para MariaDB 10.5 el runner traduce la collation histórica `utf8mb4_0900_ai_ci`
a `utf8mb4_unicode_ci` al ejecutar, conservando archivos y hashes de migraciones anteriores.

## Cron

Desde `backend-v4/`, con las variables de conexión del servicio (o `.env` fuera del webroot):

```sh
php config/productivity-cron.php --max-days=5 --budget-seconds=20
php config/productivity-cron.php --tenant=UUID --from=2026-09-01 --to=2026-09-17 --max-days=5 --budget-seconds=20
```

La segunda forma encola un recálculo explícito; `to` es exclusivo. Para continuar una
reconstrucción, ejecutar sin `--from/--to`: repetir esos argumentos vuelve a ensuciar el rango.
Después de importar K3, encolar el rango de datos raw conservados una sola vez. No se
reconstruyen días fuera de `raw_days` ni de `aggregate_months`: conservar sus agregados importados.

Crontab del usuario del servicio (ajustar ruta absoluta):

```cron
* * * * * cd /srv/azckeeper/backend-v4 && /usr/bin/php config/productivity-cron.php --max-days=5 --budget-seconds=20 >> /var/log/keeper-productivity.log 2>&1
```

Cada unidad atómica es tenant+día. El presupuesto se comprueba entre días; un día iniciado se
termina o revierte entero. `GET_LOCK` evita dos workers para el mismo día; la espera de locks
InnoDB se limita a 2 segundos. No hay transacción que abarque toda la corrida. Agregación,
ordenación, ventanas y sumas se ejecutan en SQL; PHP sólo carga horarios y resultados agregados.
Los límites UTC explícitos sobre `event_date` permiten pruning de particiones.

`rollup_days` es el checkpoint: la transacción reemplaza las tres tablas y actualiza su revisión.
Un fallo revierte todo; un evento concurrente deja el día sucio al terminar la transacción.
La ingestión marca días en la zona del horario histórico, incluidos cruces de medianoche.
Los cambios posteriores de clasificación/horario requieren encolar el rango afectado.
Los checkpoints limpios se finalizan al cerrar `late_arrival_days` con dos días de margen
para husos horarios y episodios de hasta 24 h. El evento existente `maintain_episode_partitions`
conserva las particiones mientras haya días pendientes; requiere `event_scheduler=ON` y tablas
de zonas horarias del servidor cargadas. El worker no borra particiones compartidas ni raw.
Los reportes aplican la retención del tenant aunque una partición compartida siga presente.

Métricas:

- Activo/idle/llamadas: proporción del episodio dentro de cada día, con diferencia de pisos
  acumulados para conservar segundos al dividir medianoche. Llamadas son un subconjunto de activo;
  `Episode.call_seconds` es opcional y vale cero para datos antiguos.
- Productividad: `100 * segundos productivos / activos`; sin activo devuelve `null`.
- Focus: segundos activos clasificados productivos; score es su porcentaje sobre activo.
- Contexto: cambio de proceso entre episodios consecutivos del mismo usuario/asignación/equipo.
- Deep work: secuencias del mismo proceso productivo, sin idle y con pausas de hasta 60 s,
  que acumulan al menos 1.500 segundos activos. Se corta al cambiar día local.
- Distracción: segundos activos de apps clasificadas `unproductive`.
- Primera/última actividad: extremos de los segmentos con actividad positiva. No se conoce
  el instante exacto del idle dentro de un episodio; se usa distribución proporcional.
- Puntualidad: retraso no negativo de primera actividad frente a inicio del horario local.
  `no_laborable` identifica días fuera de `schedule_days`; festivos siguen en fase B.
- Equipos simultáneos suman tiempo de dispositivo; no se deduplican intervalos entre equipos.

## Contratos HTTP

Fuente: `docs/architecture/openapi-v4.yaml`; extracto runtime: `config/contract.json`.
Prefijo `/v1`. Todos los endpoints nuevos exigen cookie de sesión admin, `X-Tenant-ID`
y permiso; mutaciones también CSRF/origen. Tenant ajeno u objeto fuera del scope: 404;
permiso faltante: 403. `tenant` query, cuando está disponible, debe coincidir con el header.

| Método/ruta | Permiso | Contrato |
|---|---|---|
| GET `/reports/apps` | `reportes.ver` | from/to, user/area/site opcionales, limit 1–100; procesos, segundos, sesiones, usuarios, porcentaje antes del límite |
| GET `/reports/presence` | `reportes.ver` | day o from/to; roster diario, extremos de actividad, activo, retraso y estado |
| GET `/users/{id}/activity` | `reportes.ver` | from/to; serie diaria y totales ponderados |
| GET `/users/{id}/policies` | `reglas.ver` | PolicyCompiler por equipo; reglas efectivas con scope y assignment_ids ganadores |
| POST `/migration/authorizations` | `migracion.gestionar` | device_id, public_key_thumbprint SHA256 hex, expires_in 60–600; token de un uso |
| POST `/migration/authorizations/validate` | `migracion.gestionar` | device_id+token; valida sin consumir |
| GET `/migration/escrow-key` | `migracion.gestionar` | clave pública X25519 base64, key_id, algoritmo |
| POST `/devices/{id}/escrow` | `migracion.gestionar` | sobre cifrado y contexto; ACK durable con id/revisión/SHA256/fecha |
| POST `/devices/{id}/escrow/verify` | `migracion.gestionar` | revisión+SHA256 del ACK; prueba de descifrado sin revelar secreto |
| POST `/devices/{id}/escrow/recover` | `migracion.recuperar` | revisión+motivo; contraseña y audit_id, `no-store` |
| GET/PUT `/devices/{id}/migration` | `migracion.ver` / `migracion.gestionar` | consulta o transición con revisión incremental |

Los rangos de reportes son de 1–31 días, `from` inclusivo y `to` exclusivo.
Se admiten los alias existentes `user_id`, `area_id`, `site_id`; dos alias distintos se rechazan.
Presence y ficha usan días del horario histórico; apps usa días del tenant.

## Enrolamiento y escrow

IT emite el token después de reautenticarse (menos de cinco minutos). El bootstrap recibe
únicamente ese token y usa los endpoints firmados existentes `/client/auth/challenges` y
`/client/login`, enviándolo como `enrollment_ticket` con prueba de posesión de la clave P-256
autorizada. El backend consume `enrollments.status` en la misma transacción que emite la
identidad propia del equipo. La cookie admin nunca se entrega al bootstrap.
Los endpoints nuevos de escrow/estado son la superficie administrativa: la integración
del bootstrap debe usar un intermediario IT autenticado, sin copiar credenciales admin al equipo.

Provisionar una keypair sodium de 64 bytes con un gestor de secretos o este comando desde
un proceso autorizado; el comando no imprime el secreto:

```sh
php -r 'file_put_contents(getenv("KEEPER_ESCROW_KEY_FILE"), sodium_crypto_box_keypair());'
```

`KEEPER_ESCROW_KEY_FILE` debe estar fuera del webroot, legible sólo por el servicio y respaldado
en la bóveda. No sobrescribir la clave: los sobres existentes dependen de ella. No se reutiliza
`KEEPER_RESPONSE_KEY`. El key_id es SHA256 de la clave pública. Crear la clave una única vez.

El bootstrap cifra con `crypto_box_seal` este JSON de cinco campos:

```json
{"tenant_id":"UUID","device_id":"UUID","account_sid":"S-1-5-21-111-222-333-1001","revision":1,"password":"32 caracteres ASCII aleatorios"}
```

Envía el ciphertext en base64 estándar junto a tenant/device/SID/revisión/key_id. El binding
interno debe coincidir exactamente con el externo. `sha256` del ACK es el hash del ciphertext
decodificado. El ACK sólo sale después del COMMIT; se exige `innodb_flush_log_at_trx_commit=1`.
Reenviar el mismo sobre devuelve el mismo ACK; otro sobre en la misma revisión devuelve 409.
La prueba `/verify` vuelve a leer y descifrar la revisión actual persistida y comprueba el hash
recibido. No basta con recibir el sobre ni con un HTTP 200 de otra operación.
Una revisión nueva marca `excepcion` si el equipo ya había avanzado a `escrow_ok` o más allá;
debe verificarse su recuperación antes de retomar la secuencia.

Secuencia: `instalado → enrolado → escrow_ok → degradado → pendiente_reinicio → completo`.
Cada PUT lleva la revisión siguiente; un reintento idéntico devuelve el estado persistido.
`enrolado` exige enrolamiento de migración consumido y clave activa. `escrow_ok` y posteriores
exigen recuperación verificada. `completo` exige `standard_user_verified=true` reportado por IT.
`excepcion` conserva la última fase segura. Estos estados registran evidencia reportada;
el backend no puede comprobar por sí mismo el token Windows ni ejecutar la degradación.

Recuperar exige `migracion.recuperar` (no delegable), reauth reciente y una escritura de auditoría
exitosa dentro de la misma transacción. Auditoría guarda actor, tenant, recurso/revisión y hash
del motivo; nunca contraseña, sobre o token. No se usa caché de idempotencia para secretos.
Los permisos nuevos no se conceden automáticamente a roles existentes.

## Pruebas

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses -ActivityOnly -MariaDbDirectory C:\tools\mariadb-10.5.29-winx64
```

El harness crea MySQL y PHP aislados, habilita sodium sólo allí, genera una clave de prueba,
valida migraciones y contratos, ejecuta smoke previo y `tests/activity-migration.php`, y borra
procesos, clave, logs y datadir en `finally`. Los fixtures reproducen 238 personas asignadas,
298 equipos, 43.969 episodios y 17.310/20.756/20.704 agregados importados antes de recalcular.

Resultados verificados el 2026-09-17:

| Ejecución | Resultado |
|---|---|
| MySQL 8.4.3, smoke completo con validación de respuestas | 979 aserciones aprobadas |
| MySQL 8.4.3, A1 final tras ajuste de compatibilidad | 128 aserciones; volumen en 27,34 s |
| MariaDB 10.5.29, A1 con el mismo volumen | 128 aserciones; volumen en 34,47 s |
| Esquema/migraciones en ambos motores | 58 verificaciones; segunda aplicación sin cambios |
| Sintaxis PHP y correspondencia OpenAPI/extracto | Sin errores; sin claves YAML duplicadas |

La medición incluye carga de episodios/agregados, procesamiento de 20 días, recálculo de un día
y consultas de reportes; no es un benchmark de producción. A1 prueba además medianoche,
DST, puntualidad, deep work, rollback completo tras interrupción, permiso 403, tenant 404,
token consumido/expirado, revisión de escrow, bloqueo sin ACK/recuperación y fallo de auditoría.
