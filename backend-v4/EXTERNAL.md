# API externa v1

Contrato: `../docs/architecture/openapi-v4.yaml`. Las once operaciones de lectura
son `GET /ext/v1/dashboard`, `/my-team`, `/members/{id}`, `/members/{id}/devices`,
`/members/{id}/activity`, `/members/{id}/check-ins`, `/members/{id}/subscription`,
`/tiers`, `/productivity`, `/organization` y `/notifications`, todas bajo `/ext/v1`.
El router no acepta `/v1/ext/v1` ni métodos de escritura externos.

## Credenciales y autorización

`POST /v1/oauth/token` acepta exclusivamente `client_secret_basic` y
`application/x-www-form-urlencoded`, con `grant_type=client_credentials` y `scope`
opcional (lista separada por espacios). Sin `scope` usa los concedidos; un scope
no concedido devuelve `400 invalid_scope`. Devuelve un token opaco aleatorio de
256 bits, `token_type=Bearer`, `expires_in=900` y `scope`; no hay refresh token.
La introspección local valida el hash, audiencia `keeper-external-v1`, vigencia,
revocación, versión de integración y estados de cliente/integración/tenant en
cada petición. Los errores OAuth 400/401 respetan `OAuthError`.

La alternativa es `X-API-Key: <public_prefix>.<secreto-base64url>`, con secreto
de 32 bytes aleatorios (43 caracteres base64url sin padding). `secret_hash` guarda
SHA-256 binario del secreto, tanto en `api_keys` como en `oauth_clients`, conforme
a sus columnas `BINARY(32)`. Los access tokens también se persisten solo hasheados.
Enviar API key y Authorization juntos devuelve 400.

El aprovisionamiento existente debe registrar la integración, su principal
`principals.kind=integration`, los `integration_scopes` y la credencial. No se
incluye creación/rotación de credenciales en esta superficie de lectura.
La migración `0010_external_global_credentials.sql` exige unicidad global de
`oauth_clients.client_id` y `api_keys.public_prefix`, conservando las claves
compuestas existentes. El lookup usa ese identificador global; nunca un tenant
enviado por el cliente. Aplicar la migración antes de desplegar este código.
Si hay duplicados históricos, asignar identificadores nuevos y distribuir las
credenciales afectadas antes de migrar; el índice rechaza duplicados sin borrar
ni reasignar datos. Las semillas existentes no insertan estas credenciales.

Se exigen los scopes exactos `x-required-scopes` del OpenAPI para ambas formas
de autenticación. OAuth intersecta además con `oauth_token_scopes`; ampliar una
concesión no amplía tokens ya emitidos. Faltantes: 403 `insufficient_scope`.
`include_titles=true` necesita `activity-titles:read`, y
`include_location=true` necesita `location:read`, además de los scopes base.
Por defecto se omiten títulos y ubicación, incluso con esos scopes.

El tenant procede exclusivamente de la credencial. IDs, joins, agregados y
filtros se recortan por tenant; miembros/filtros ajenos devuelven 404.
`X-Tenant-ID` y parámetros selectores de tenant se rechazan. Los GET se ejecutan
en transacciones MySQL `READ ONLY` con snapshot consistente. Solo el endpoint
OAuth escribe tokens, scopes y auditoría; no registra secretos en auditoría.

## Lecturas, paginación y cuotas

Las métricas consultan `day_summary`, `episode_daily` y `focus_daily`.
La precedencia de `day_summary` se resuelve por miembro/asignación/día; los
episodios diarios completan únicamente las asignaciones/días ausentes. Esto
evita sumar dos veces un resumen y sus episodios. Los filtros históricos de
área/sede usan `user_assignments`. Las marcas de frescura incluyen focus.

El contrato `EpisodePage` de `/activity` contiene eventos individuales; por eso
esa única ruta consulta `episodes`, con índice de tenant/miembro/fecha,
particiones de fecha, límite de 100 y cursor. Ningún KPI consulta crudo.
Check-ins usa `check_ins`; las fechas se convierten desde la zona del tenant a
UTC, incluyendo DST, con `from` inclusiva y `to` exclusiva. Máximo 31 días;
productividad admite 366. Un dashboard con más de 100 áreas exige filtro (422).

Las colecciones usan los cursores cifrados de `Resources`, TTL de 15 minutos,
ligados a tenant, integración, versiones de autorización, scopes, ruta, filtros
y orden/snapshot. La actividad desempata también por dispositivo, porque un
event ID puede repetirse en dispositivos distintos. Las notificaciones filtran
los cinco tipos publicados y generan títulos fijos sin copiar texto privado.

`RateLimiter` aplica buckets separados de agente/admin: integración 120/min,
burst 30; tenant 600/min, burst 100. OAuth añade 10/min por cliente, burst 10;
las cuotas legítimas se consumen solo tras autenticar el secreto y la vigencia.
Los fallos usan otro bucket (`external:abuse:`, 5/min, burst 5), por tipo e
identificador público; los formatos desconocidos comparten un bucket de abuso.
Agotarlo nunca bloquea una credencial válida ni consume cuotas del tenant.
No se limita por IP. OAuth ejecuta la E/S del limiter fuera de la transacción;
antes de emitir revalida secreto, vigencia y revocación con `FOR UPDATE`.
Clientes inexistentes, revocados o con secreto incorrecto recorren una consulta
y una comparación `hash_equals` de 32 bytes, con digest dummy para credenciales
no vigentes, y el mismo error de autenticación. Las fechas preservan DATETIME(6).
Variables opcionales: `KEEPER_EXTERNAL_RATE`, `KEEPER_EXTERNAL_BURST`,
`KEEPER_EXTERNAL_TENANT_RATE`, `KEEPER_EXTERNAL_TENANT_BURST`,
`KEEPER_OAUTH_RATE`, `KEEPER_OAUTH_BURST`.
Las lecturas admiten hasta 4 consultas simultáneas por integración y 16 por
tenant, con leases de archivo que se liberan al terminar. Se emiten headers
`RateLimit-*`, `X-RateLimit-Scope` y `Retry-After` para 429.
El backend de cuotas reutilizado es APCu/archivos; despliegues con varios nodos
deben compartir los contadores y `KEEPER_RATE_DIR` con locking fiable.

Las respuestas declaran `Cache-Control: private, max-age=30` y
`Vary: Authorization, X-API-Key`. El BFF del portal debe guardar los secretos
servidor-servidor y aislar su caché por credencial/filtros. Una credencial
empresarial da acceso al tenant; no representa al usuario final del portal.

## Verificación

`tests/external.php` se ejecuta dentro del smoke completo:

```powershell
& backend-v4/tests/mysql-smoke.ps1 -DebugResponses
```

Prueba ambas credenciales, respuestas contra los schemas, scopes y elevación,
IDOR en las cinco rutas de miembro, filtros y cursores cruzados, proyecciones
sin datos privados, vigencia/revocación, métricas con crudo inaccesible,
concurrencia y cuotas compartidas. `tests/external-auth.php` añade unicidad global,
aislamiento de abuso, scopes opcionales, instrumentación de `hash_equals` y E/S,
revocación concurrente y precisión de microsegundos. La instrumentación verifica
el trabajo comparable de las rutas de error; no certifica latencia constante de
MySQL ni del sistema operativo. El harness usa MySQL 8.4 aislado y elimina
procesos/datadir en `finally`. Ajusta cuotas solo para pruebas deterministas.
