# AZCKeeper v4 — API PHP

La superficie administrativa está implementada y documentada en [ADMIN.md](ADMIN.md): 47 operaciones admin y cuatro de sesión, RBAC por BD, tenant/alcances, CSRF, dos gates, políticas y releases firmadas. Requiere la migración adicional `0009_admin_surface.sql`. Las secciones siguientes describen la superficie del agente original; sus exclusiones administrativas quedan reemplazadas por `ADMIN.md`.

Implementación independiente en PHP 8.2+, PDO MySQL y OpenSSL. Sin frameworks ni paquetes Composer obligatorios. No modifica K3/K4 ni las ocho migraciones existentes. El document root es exclusivamente `public/`.

## Estructura

```text
backend-v4/
  public/index.php              Front controller
  src/                         Router, validación, firmas, auth, ingestión y recursos
  config/bootstrap.php         .env y autoload PSR-4
  config/compile.php           Compilación por dispositivo, tenant o toda la flota
  config/contract.json          Extracto literal de schemas/rutas del OpenAPI
  config/release-keys.json      Claves públicas JWK de confianza para releases
  config/migrate.php            Runner existente, cargando .env
  config/keygen.php             Genera clave AEAD sin imprimirla
  migrations/                  Esquema existente, sin modificaciones
  tests/mysql-smoke.ps1         MySQL aislado + servidor PHP + pruebas + limpieza
  tests/smoke.php               Flujo HTTP firmado y casos negativos
  tests/policy-compiler.php     Compilador, concurrencia y compilación → sync
  tests/export-contract.py      Regeneración opcional del extracto OpenAPI
  .env.example
  composer.json
```

## Ejecución local

Extensiones: `pdo_mysql`, `openssl`, `mbstring`, `json`. El smoke necesita también `curl` y MySQL >=8.0.30; se comprobó con PHP 8.3.30/MySQL 8.4.3 en Windows. Composer es opcional: `config/bootstrap.php` implementa el mismo autoload PSR-4.

Desde la raíz, PowerShell:

```powershell
Copy-Item backend-v4/.env.example backend-v4/.env
php backend-v4/config/keygen.php
# Editar .env: DSN, usuario/password o archivo de password, origen externo.
mysql --host=127.0.0.1 --user=root -p --execute="CREATE DATABASE keeper_v4 CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
php backend-v4/config/migrate.php
php -S 127.0.0.1:8080 -t backend-v4/public backend-v4/public/index.php
```

El wrapper de migración usa exactamente el runner y sus checksums existentes. Usar la credencial migradora para instalar; luego configurar la credencial de aplicación. Las variables del proceso prevalecen sobre `.env`. `KEEPER_DB_PASSWORD_FILE` se lee sin imprimir su contenido. No versionar `.env` ni claves privadas.

En despliegue, `KEEPER_ORIGIN` debe ser el origen HTTPS público exacto, sin path. Se exige TLS directo o `X-Forwarded-Proto: https` de una dirección incluida explícitamente en `KEEPER_TRUSTED_PROXY_IPS`. El proxy debe sobrescribir esa cabecera, preservar `Host`, método, URI completo, cuerpo y headers firmados, y bloquear acceso directo al upstream. Ninguna cabecera forwarded selecciona la autoridad firmada ni el tenant. HTTP local se admite únicamente con `KEEPER_ALLOW_HTTP_LOCAL=1` y origen loopback. `php -S` es solo para desarrollo.

Antes del primer login, el plano administrativo debe crear el ticket en `enrollments`, ligado a miembro activo, tenant y thumbprint autorizado, guardando únicamente SHA-256 del ticket. No hay un endpoint anónimo que cree tickets. Antes del primer sync debe existir una política compilada en `effective_policies`; sin ella se responde 503 con `Retry-After: 120`. Después del alta, invocar `PolicyCompiler::recompile()` o `php backend-v4/config/compile.php --device=UUID`. La emisión administrativa de tickets queda fuera de esta superficie. El smoke conserva sus fixtures originales y añade compilación real de políticas. Las reglas de precedencia, tier, horarios, hashes y disparadores están en [POLICY-COMPILER.md](POLICY-COMPILER.md).

## Endpoints

Todos bajo `/v1`. Las seis mutaciones autenticadas requieren `Idempotency-Key` UUID, bearer y firma.

| Método | Ruta | Resultado |
|---|---|---|
| POST | `/client/auth/challenges` | Nonce aleatorio de 256 bits, 60 s, outstanding independiente |
| POST | `/client/login` | Alta, recuperación con ticket o renovación con clave registrada; token 3600 s |
| POST | `/client/sync` | Política incremental, comandos, release, ACK individuales, renovación opcional |
| GET | `/client/policy` | Política del equipo; ETag numérico entre comillas y 304 |
| POST | `/client/episodes:batch` | Hasta 200 episodios/128 KiB, ACK durable por registro |
| GET | `/client/commands` | Comandos vigentes del equipo; cursor cifrado y vinculado al principal |
| POST | `/client/commands/{id}/result` | Resultado, dedupe por evento, conflicto terminal 409 |
| POST | `/client/security/report` | Controles declarados; dedupe por contenido y evento |
| POST | `/client/logs` | Hasta 50 logs estructurados; sin mensaje libre ni metadata arbitraria |
| POST | `/client/check-ins` | 201, miembro derivado del token, site validado dentro del tenant |
| GET | `/client/releases/{id}` | Manifest ES256 de release habilitada para tenant/anillo/arquitectura |

`DeviceToken` devuelve `access_token`, `token_type`, `expires_in`, `device_id`, `tenant_id`; no introduce un campo `device_token` ajeno al contrato. Errores `application/problem+json` con `type`, `title`, `status`, `code`, `request_id`. Cada respuesta lleva `X-Request-ID`; 401 incluye `WWW-Authenticate`.

## Firmas y transacciones

Perfil `sig1` de [RFC 9421 §3.3.4](https://www.rfc-editor.org/rfc/rfc9421.html#section-3.3.4): P-256/SHA-256, firma de 64 bytes `r || s` en base64. OpenSSL usa DER internamente; el verificador convierte el formato. `keyid` es base64url del JWK thumbprint SHA-256, calculado sobre `crv,kty,x,y` en ese orden. Solo se aceptan claves públicas P-256 válidas.

Componentes obligatorios: `@method`, `@target-uri`; `authorization` si existe, `content-digest` y `content-type` en POST, `idempotency-key` cuando se use. Se firma el SHA-256 del cuerpo transmitido, sin reconstruir JSON. Parámetros obligatorios: `created`, `expires`, `nonce`, `keyid`; `alg`, si aparece, debe ser `ecdsa-p256-sha256`. Vigencia máxima 60 s y tolerancia máxima de reloj 60 s. Nonces base64url de al menos 128 bits.

Login verifica la clave contra ticket/registro y consume ticket/challenge junto con la sesión en una transacción. Recuperación conserva tenant y miembro, revoca claves/sesiones anteriores e incrementa la revisión de autorización. GUID o bearer por sí solos no autentican.

Las otras rutas validan sesión, estados, asignación, revisiones y firma, y confirman el consumo del nonce antes del handler, incluso si después falla el negocio. La unicidad de `device_signature_nonces` evita carreras. La transacción de negocio revalida el contexto bajo lock antes de consultar idempotencia o escribir. No existe selección de tenant por cuerpo, header ni IP.

Idempotencia dura 24 h por tenant/principal/método/ruta/clave. Mismo cuerpo exacto reproduce la respuesta; cuerpo distinto devuelve 409. La respuesta se cifra AES-256-GCM con nonce/tag y AAD del ámbito, dentro de la transacción que confirma los efectos. `KEEPER_RESPONSE_KEY` es una clave base64 de 32 bytes fuera de BD. Conservarla durante la vigencia de las respuestas; cambiar su identificador sin conservar las claves previas hace que sus replays fallen con 503. Un replay nunca genera otro token.

Los episodios verifican intervalos, contadores, ventana tardía y asignaciones históricas; el trigger existente inserta `episode_ingest_keys`. Se marca cada día local afectado en `rollup_days` dentro de la misma transacción. UUID duplicado en un sobre produce 422 antes de escrituras de negocio; registros individualmente inválidos reciben ACK rejected mientras los válidos se confirman. Snapshots son valores absolutos monotónicos, no sumas. Un fallo de BD revierte la operación y no devuelve ACK de éxito.

Auditoría append-only de enrolamiento, recuperación, login, renovación, resultado de comando, seguridad y check-in con tenant, principal real y request ID. No contiene cuerpos, tokens, firmas, coordenadas, PIN ni claves. Los logs de diagnóstico del backend contienen solo clase de excepción y request ID. Los logs del agente aceptan campos tipados y códigos técnicos, rechazando nombres de secretos y texto libre.

## Límites y decisiones de integración

- Cuotas por tenant+device, compartidas entre tokens del mismo equipo: 2/min, burst 5. Tenant: `max(120,2*D)`/min y burst `max(20,ceil(.1*D))`, con D configurado por operador mediante `KEEPER_TENANT_FLEET`. Bootstrap: 5/min por ticket/registro, tenant 60/min, burst 20, desconocidos bajo bucket global. Outstanding por identidad: 20 por defecto. Variables en `.env.example` permiten ajustar los valores durante pruebas.
- Contadores token bucket en APCu, cuando está habilitado, con actualización atómica por clave; fallback a archivos `RATE_DIR/<2 hex>/<hash>.json` con `flock` por archivo. Los rechazos conservan los tokens fraccionarios acumulados. Los workers deben compartir la misma caché APCu o directorio de archivos; varias instancias necesitan un almacenamiento compartido adecuado. No se ha certificado capacidad de carga. Headers `RateLimit-*`, `X-RateLimit-Scope` y `Retry-After` cuando corresponde. La IP nunca es clave de negocio.
- `KEEPER_DEBUG=1` activa la validación del schema de respuesta para desarrollo. Por defecto está desactivada; el JSON del body se reutiliza para idempotencia cifrada y envío.
- El esquema real llama a la revisión `effective_policies.policy_version`. `SyncRequest/Response.policy_version` son enteros, mientras el YAML todavía define `EffectivePolicy.version` como string. Se conserva esa representación en el documento y se compara siempre el entero SQL. ETag es `"<policy_version>"`. No se cambió el OpenAPI fuera de esta carpeta.
- El login no ofrece campos de hardware en su schema. Un device nuevo se registra con edición/CPU `unknown`, RAM 0 y capacidades vacías. Una release exige `devices.specs.architecture` explícita (`x64`/`arm64`); sin ese dato no se ofrece un binario por suposición. La publicación de releases y el aprovisionamiento de specs corresponden al plano administrativo.
- `EffectivePolicy` se sirve del documento materializado por `PolicyCompiler` y se valida antes de exponerlo, incluyendo correspondencia de IDs, versión y ETag con la fila SQL. El documento de sync se omite si no hay una revisión superior o si excede el presupuesto inline de 256 KiB; GET permite recuperación.
- El contrato no define un control inequívoco para consentir captura de títulos. `window_title` se valida y participa en dedupe, pero no se almacena en el crudo hasta que exista esa autorización explícita. No se inventó un campo de política fuera del schema.
- `CommandResult.code` es opcional en OpenAPI, pero SQL exige resultado final no nulo: se almacena `unspecified` cuando se omite en un resultado terminal. Un código de estado running permanece únicamente en el evento de resultado.
- `report_hash` es una declaración del agente, no una prueba de integridad confiable; dedupe compara también controles canónicos y timestamp. Los datos declarados no son attestation.
- `config/release-keys.json` empieza vacío. Añadir JWK públicos por `key_id` mediante configuración del operador o `KEEPER_RELEASE_KEYS_FILE`. Se verifica JWS ES256 y correspondencia íntegra del payload con la representación. El agente/updater debe verificar firma, hash, tamaño y anti-downgrade antes de ejecutar; este backend no ejecuta ni descarga artefactos.
- Quedan fuera los endpoints administrativos/externos, jobs de rollup/purga y cambios de firewall/CSF. Los flujos administrativos deben invocar el compilador después de confirmar cambios de políticas, asignaciones, tiers u horarios. Las expiraciones de autenticación se comprueban en cada petición; un job operativo debe retirar nonces/challenges/sesiones e idempotencias vencidas según las reglas del esquema.

## Pruebas

El smoke HTTP/MySQL conserva las 133 aserciones originales y añade regresiones de refill fraccionario, conflictos de snapshot bajo READ COMMITTED y referencias URI. Incluye 6 aserciones de transporte. El harness elimina procesos y datadir de prueba.

```powershell
Get-ChildItem backend-v4 -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses
```

El harness usa `mysqld` y `php` del PATH, puertos 13384/18084 (parametrizables), MySQL loopback con datadir nuevo y base `keeper_v4_agent_test`. Aplica las ocho migraciones; levanta HTTP, firma peticiones con claves efímeras y comprueba persistencia real. En `finally` verifica que `@@datadir` corresponde al suyo, apaga MySQL, detiene PHP y elimina sus archivos, también al fallar. No conecta a producción. `smoke.php` exige una base terminada en `_test` y autorización explícita `KEEPER_TEST_ALLOW_FIXTURES=1`.

Incluye challenge → enrollment firmado → sync con política → sync sin cambios → episodios idempotentes → comando, más dos tenants, idempotencia cruzada, replay de firma, claves ajenas, cuerpos alterados, nonce expirado, recuperación, revocación, límites, cursors, snapshots y releases firmadas. `transport.php` verifica rechazo de HTTP inseguro y de cabeceras de proxies no confiables. El smoke también valida los Problems; `-DebugResponses` activa la validación de DTO de salida en el backend. No sustituye pruebas de carga ni ejecución del agente Windows.

Para actualizar el extracto de contrato: `python backend-v4/tests/export-contract.py` (PyYAML solo como herramienta de desarrollo). El archivo conserva schemas sin modificar y SHA-256 de la fuente.

## Hallazgos de auditoría cubiertos

| Auditoría K3 | Cierre en esta superficie v4 |
|---|---|
| #1 Re-enroll mediante GUID | Cerrado: ticket ligado a huella o clave registrada, firma obligatoria, nonce de un uso |
| #2 Tenant/propietario mutable por handshake | Cerrado para el agente: contexto autenticado y predicados tenant+device; no reasignación por sync/login |
| #7 Sesiones sin caducidad/revocación | Cerrado: TTL 3600 s, estado/asignación/versiones revalidados, recuperación revoca credenciales anteriores |
| #9 PIN y secretos en logs | Cerrado en el API nuevo: DTO estructurado, sin PIN/mensaje libre, auditoría saneada y respuestas idempotentes cifradas |
| #4 Tráfico y política completa repetida | Parte servidor resuelta: sync de 120 s, versión entera, política incremental y release piggyback; pendiente integrar el planificador del agente |
| #10 Ingestión sin dedupe | Cerrado en ingestión: ledger persistente, ACK individual y límites; rollups/purga operativa fuera de alcance |
| #3 Updater sin verificación | Se valida el manifest servido; la ejecución segura del updater sigue pendiente en el cliente |
| #6 Instalación desde cero | Se reutiliza y prueba el esquema reproducible ya existente, sin adjudicar cambios a sus migraciones |

Los hallazgos de K3 productivo, administración/CSRF, artefactos instalados y secretos históricos no se corrigen modificando este backend independiente.
