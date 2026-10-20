# Puente AZC One legacy → Keeper v4 (instantánea y modo vivo)

Alimenta a Keeper con los datos reales de Grupo AZC desde el One legacy, que no tiene API, hasta que exista **One v2**. Lee la BD de One, arma el formato canónico `keeper.roster.v1` y lo sirve con un endpoint PHP de solo lectura. El conector REST de Keeper (`DataSync::restFetch`) lo consume como a cualquier fuente.

`serve.php` tiene dos modos:
- **files** (2026-10-06): `extract.php` escribe una instantánea en archivos JSON a partir de una copia de la BD, y `serve.php` la sirve tal cual (§2 y §3b).
- **live** (decisión 2026-10-20): `serve.php` arma el roster **al momento** desde la BD viva de One, con sesión de solo lectura y una caché privada de 5 min. El cron que ya tiene devkeep (cada 10 min) llama a `DataSync` contra esta URL; en el hosting **no hay cron** (§3).

Cuando exista **One v2** con API real, solo cambian la URL base y el token de la fuente (ver «Reemplazo por One v2»).

> **Datos personales.** La salida de `extract.php`, el directorio del servidor y la caché del modo vivo contienen nombres, cédulas y correos. Nunca van al repo, a un ticket ni a un chat. Por consola, en el log y en `counts-*.json` solo salen conteos.

| Archivo | Qué es |
|---|---|
| `RosterBuilder.php` | La lógica de mapeo (diseño §2): dado un PDO a la BD de One devuelve en memoria `org-units`, `schedules`, `people` y los conteos. También tiene la lectura segura del `config.php` de One, la sesión READ ONLY y la guarda de solo-SELECT. La usan `extract.php` y `serve.php` |
| `extract.php` | BD local de One → `<out>/grupo-azc/{org-units,schedules,people}/NNNN.json`, `manifest.json` y `<out>/counts-grupo-azc.json` (sin datos personales) |
| `serve.php` | Endpoint GET de solo lectura con el contrato exacto del conector (rutas, parámetros, sobre, paginación y Bearer), en modo `files` o `live` |
| `public-entry.php` | Entrada pública mínima del modo vivo: define la ruta de la config y hace `require` de `serve.php`, que vive fuera de `public/` |
| `serve.config.example.php` | Configuración de ejemplo del modo vivo, sin secretos |
| `company-map.json` | Código `employee.company` → empresa del grupo (decisión del dueño: 1 y 4 → AZC Legal, 2 → BilingueLaw, 3 → LawyerDesk, 5 → Matiz LegalTech; Grupo AZC sin personas) |
| `sites-location.json` | País, ciudad y zona horaria por sede de One. Se edita a mano |
| `check-local.php` | Prueba local: DataSync del repo contra `serve.php` (cualquier modo) sobre una BD de Keeper desechable |
| `check-live.php` | Prueba local del modo vivo: vivo = archivos (también paginado y con DataSync), solo-SELECT, READ ONLY, caché, TTL, bloqueo, 401 y 503 |

Diseño y mapeo: `docs/architecture/v4-puente-one-legacy.md` (§2 y §2.0, ids). Contrato del lado de Keeper: `.superpowers/sdd/one-keeper-changes-report.md` (commit `d9d1900`, K1–K4).

## 1. Cargar los datos de One en un MySQL desechable

Se necesitan solo `areas`, `cargos`, `firm`, `sedes`, `employee_schedule` y estas columnas de `employee`: `id, id_firm, CC, first_Name, second_Name, first_LastName, second_LastName, mail, company, sede_id, area_id, position_id, supervisor_id, role, created_at, updated_at`. **No se cargan** `password`, `pin`, `photo`, teléfonos, dirección, correo personal, tarjeta ni archivos. Si vienen en un volcado completo, se borran apenas se cargan.

Arrancar un `mysqld` aislado (datadir propio en una carpeta temporal y puerto libre, como `backend-v4/tests/mysql-smoke.ps1`):

```bash
B=$(ls -d /c/laragon/bin/mysql/mysql-8.4.3*)/bin; T=$(cygpath -w "$SCRATCH")
"$B/mysqld.exe" --no-defaults --datadir="$T\\data" --tmpdir="$T" --initialize-insecure
"$B/mysqld.exe" --no-defaults --datadir="$T\\data" --tmpdir="$T" --skip-log-bin --mysqlx=0 --bind-address=127.0.0.1 --port=13391 &
mysql -h127.0.0.1 -P13391 -uroot -e "SET GLOBAL local_infile=1; CREATE DATABASE one_legacy CHARACTER SET utf8mb4"
```

Extracción del 2026-10-06 (`raw-20261006/`):
- `catalog.gz` es un mysqldump de los 5 catálogos y se carga tal cual con `zcat catalog.gz | mysql ... one_legacy`. Luego hay que borrar los datos de contacto de las firmas cliente: `ALTER TABLE firm DROP COLUMN manager, DROP COLUMN mail_manager`.
- `employee.gz` es un TSV con cabecera y el texto `NULL` para los nulos. `LOAD DATA` **no** convierte ese `NULL` en una columna INT (lo carga como 0), así que se lee en variables y se aplica `NULLIF`:

```sql
SET FOREIGN_KEY_CHECKS=0; SET time_zone='+00:00';   -- el TSV viene en UTC (verificado contra el volcado del 2026-09-22)
CREATE TABLE employee (id int PRIMARY KEY, id_firm int, locacion_id int, type_CC int, CC varchar(20) NOT NULL,
  first_Name varchar(100), second_Name varchar(100), first_LastName varchar(100), second_LastName varchar(100), mail varchar(100),
  final_status varchar(50), country varchar(100), city varchar(100), sede_id int, company int, position varchar(255), exit_status int,
  role varchar(32), supervisor_id int, area_id int, created_at timestamp NULL, updated_at timestamp NULL, last_status varchar(16),
  position_id int, exonerado_horario tinyint(1), UNIQUE KEY (CC)) DEFAULT CHARSET=utf8mb4;
LOAD DATA LOCAL INFILE 'employee.tsv' INTO TABLE employee CHARACTER SET utf8mb4 FIELDS TERMINATED BY '\t' IGNORE 1 LINES
  (@id,@id_firm,@locacion_id,@type_CC,@CC,@first_Name,@second_Name,@first_LastName,@second_LastName,@mail,@final_status,@country,@city,
   @sede_id,@company,@position,@exit_status,@role,@supervisor_id,@area_id,@created_at,@updated_at,@last_status,@position_id,@exonerado_horario)
  SET id=NULLIF(@id,'NULL'), id_firm=NULLIF(@id_firm,'NULL'), /* … la misma forma para cada columna … */ exonerado_horario=NULLIF(@exonerado_horario,'NULL');
```

`CC` es `varchar(20)`, no `int` como dice `_meta/esquema.sql`: hay DNI de Honduras de 13 dígitos.

## 2. Generar la instantánea

```bash
php tools/one-legacy-snapshot/extract.php \
  --out='C:\Users\FumiWork\Documents\AZCKeeper-release-keys\one-snapshot' \
  --generated-at=2026-10-06T11:27:29Z \
  --dsn='mysql:host=127.0.0.1;port=13391;dbname=one_legacy;charset=utf8mb4'
```

- `--out` no puede estar dentro del repo; el script lo rechaza. Sin `--force`, no reemplaza una salida que ya existe.
- `--generated-at` debe ser **igual o anterior** al momento real de la extracción. Keeper lo guarda como `updated_since` tras una vuelta aplicada, y la primera incremental de One v2 pedirá «todo lo cambiado desde ahí». Un valor anterior solo reenvía cambios, lo que no hace daño. Un valor posterior perdería cambios hasta la vuelta completa diaria. Si no se conoce la hora exacta, se usa `MAX(updated_at)` de los datos: para la extracción del 2026-10-06 es `2026-10-06T11:27:29Z`.
- `--no-lunch` quita el almuerzo. Por defecto se agrega 12:00–13:00 a los días de 8 h o más que cubren esa franja (decisión P4). Este cambio no altera los ids de los horarios.
- `--page-size` (500 por defecto, 1–500) y `--min-active` (150 por defecto). El script falla si hay menos activos: la copia parecería incompleta.
- Avisa (sin datos personales) si una sede no está en `sites-location.json`, si el nombre no coincide, o si hay cédulas o correos repetidos.

Reglas que aplica (diseño §2): personas = todo `employee` menos `role='candidato'`; activo = `empleado|administrador|nomina|talento_humano|it`. Retirados y `NULL` se envían con `status:"inactive"` y Keeper nunca los crea. Horario = patrón por persona agrupado (`one-hor-` + 12 hex de `sha1("<zona>|<patrón>")`). Sin filas en `employee_schedule` se usa el defecto de One, L-V 08:00-16:30. Con filas pero sin días activos, `schedule_external_id:null` y Keeper le pone «Sin horario». Sin área o sin firma → `null` (Keeper usa «Sin área» o «Sin firma»). Jefe solo si existe, está activo y no es la misma persona. No se exportan **festivos ni ausencias**: Keeper es dueño de ambos.

## 3. Modo vivo en keep.azclegal.com (no está desplegado)

Corre en el mismo hosting que One (sitio K3 `keep.azclegal.com`, docroot `public/`) y lee la BD viva de One con el usuario que ya usa One. Ese usuario tiene **todos los permisos**, así que el código es estrictamente de lectura en tres capas:
- La sesión MySQL queda `READ ONLY` antes de cualquier consulta: `SET SESSION TRANSACTION READ ONLY` y `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY` (o `START TRANSACTION READ ONLY` si el motor no acepta la forma combinada). Después se comprueba `@@transaction_read_only = 1` (o `@@tx_read_only`) y, si no, no se lee nada.
- Toda consulta pasa por `RosterBuilder::select`, que rechaza lo que no sea un `SELECT` simple: sin `;`, `INTO`, `FOR UPDATE`, `FOR SHARE` ni `LOCK IN SHARE MODE`. PDO va con `MULTI_STATEMENTS` apagado.
- Solo se leen las columnas explícitas de §2: nunca `password`, `pin`, `photo`, teléfonos, dirección, correo personal ni tarjeta.

### 3.1 Estructura de despliegue

```
/home/www/keep.azclegal.com/
├── public/                                   docroot de K3
│   └── ob-<32 hex>/                          755   nombre no adivinable (ver 3.3)
│       ├── index.php                         644   copia de public-entry.php: 2 líneas, sin secretos
│       └── .htaccess                         644   opcional (ver abajo)
└── one-bridge/                               700   FUERA de public/
    ├── serve.php                             600
    ├── RosterBuilder.php                     600
    ├── company-map.json                      600
    ├── sites-location.json                   600
    ├── serve.config.php                      600   hash del token y ruta al config de One (de serve.config.example.php)
    └── cache/                                700   roster.json, roster.prev.json y build.lock (600, con datos personales)
```

- Lo único bajo `public/` es `index.php`, que solo define `ONE_BRIDGE_CONFIG` y hace `require` de `one-bridge/serve.php`. Si alguien lo lee, no obtiene nada útil.
- Los permisos 600/700 suponen que PHP corre con el usuario del sitio (FPM o suPHP por usuario). Si `/health` responde 503 `internal_error` o `not_configured` con todo en su sitio, PHP corre con otro usuario: hay que ajustar dueño o grupo, nunca abrir a 644 la config o la caché.
- PHP **8.0 o superior** con `pdo_mysql` y `mbstring` (el hosting sirve 8.0 por defecto y 8.2 seleccionable). Los archivos que se despliegan no usan nada de 8.1+, pero solo se ejecutaron en 8.3: se recomienda seleccionar 8.2 para el sitio.

`.htaccess` de `public/ob-<hex>/` (opcional: el filtro por IP también está en la config, `allowed_ips`):

```apache
Options -Indexes
DirectoryIndex index.php
CGIPassAuth On
RewriteEngine Off
```

`RewriteEngine Off` evita que las reglas de K3 en `public/.htaccess` manden `/ob-<hex>/index.php/...` al `index.php` de K3. Si el servidor no deja pasar `Authorization`, `serve.php` también lee `REDIRECT_HTTP_AUTHORIZATION`.

### 3.2 Configuración (`one-bridge/serve.config.php`)

Se copia de `serve.config.example.php`:

```php
<?php
return [
    'mode' => 'live',
    'token_sha256' => ['<sha256 hex del token>'],                     // caben dos para rotar sin cortes
    'one_config' => '/home/www/one.azclegal.com/includes/config.php',  // se LEE como texto, nunca se ejecuta
    'cache_dir' => '/home/www/keep.azclegal.com/one-bridge/cache',
    'cache_ttl' => 300,           // segundos que se reutiliza el roster antes de releer One
    'cache_stale_max' => 86400,   // si One no responde, se sirve la caché hasta esta edad; después, 503
    'maps_dir' => '/home/www/keep.azclegal.com/one-bridge',
    'allowed_ips' => ['185.42.22.239'],   // IP de salida de devkeep; [] = sin filtro
    'min_active' => 150, 'lunch' => true, 'page_size' => 500, 'allow_http' => false,
];
```

- **Credenciales de One.** `serve.php` no hace `require` del `config.php` de One, porque ese archivo es parte de la app y puede abrir sesiones, conectar o mandar cabeceras. `RosterBuilder::oneCredentials` lee el texto y extrae con una regex solo `define('DB_HOST'|'DB_NAME'|'DB_USER'|'DB_PASS'|'DB_PORT', <literal>)`.
  - Si una de esas constantes se define con una expresión (`getenv()`, `$_ENV`, concatenación) o dos veces con valores distintos, falla cerrado con `one_config_unparseable` o `one_config_ambiguous` en vez de adivinar.
  - Si One fija el puerto en otra parte, se pone en `'db_port'`.
  - Los valores nunca se registran, imprimen ni devuelven. Un error de conexión solo deja el SQLSTATE.
- **Caché.**
  - Una petición sin cursor usa `roster.json` si tiene menos de `cache_ttl` segundos. Si venció, la reconstruye con `flock` exclusivo sobre `build.lock`: las peticiones simultáneas esperan y usan el resultado, así que hay una sola lectura de One por vencimiento.
  - `generated_at` es `UTC_TIMESTAMP()` de MySQL tomado justo antes de abrir la instantánea consistente. Queda igual o anterior a la lectura, como pide el diseño §1.2: un cambio concurrente entra en la vuelta siguiente.
  - La versión anterior queda en `roster.prev.json`, para que una vuelta que ya pidió la página 1 termine con la misma versión. Si ninguna coincide con el cursor, 409 `snapshot_changed`. Un cursor nunca dispara una reconstrucción.
- **Fallos.**
  - Si One no responde, o la carga no pasa `min_active`, y hay caché de menos de `cache_stale_max`, se sirve esa caché. El log registra `serving stale cache age_s=N` con el motivo, sin datos. Keeper no avanza su `updated_since` más allá del `generated_at` viejo, así que no se pierde nada.
  - Sin caché usable: 503 `source_unavailable` (o `sanity_failed`).
  - `/health` informa `db`, `stale` y `cache_age_s`.

### 3.3 Token, carpeta y alta en Keeper

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'              # token: va solo a la fuente de Keeper, donde queda cifrado
php -r 'echo hash("sha256", trim(fgets(STDIN))), PHP_EOL;'     # pegar el token por stdin; el hash va a token_sha256
php -r 'echo "ob-", bin2hex(random_bytes(16)), PHP_EOL;'       # nombre de la carpeta pública
```

1. Subir `serve.php`, `RosterBuilder.php`, los dos mapas y `serve.config.php` a `one-bridge/`. Crear `one-bridge/cache/` (700) y copiar `public-entry.php` como `public/ob-<hex>/index.php`. Si la carpeta no es `/home/www/keep.azclegal.com/one-bridge/`, cambiar las dos rutas del `index.php`.
2. Comprobar desde devkeep:
   - `curl -s -H "Authorization: Bearer $TOKEN" https://keep.azclegal.com/ob-<hex>/index.php/api/keeper/v1/health` debe dar 200 con `"schema":"keeper.roster.v1"`, `"db":"ok"` y los conteos (unos 220 activos). Si devuelve HTML, la atendió K3: revisar el `.htaccess`.
   - Sin token debe dar 401; desde otra IP, 403 `forbidden`.
3. En la fuente de Keeper que hoy apunta a la instantánea, cambiar solo la **URL base** (`https://keep.azclegal.com/ob-<hex>/index.php/api/keeper/v1`) y el **token**. La empresa sigue siendo `grupo-azc`, y los dominios y los vínculos se conservan porque los `external_id` son los mismos.
4. Antes de dejarla en `live`, correr una **completa simulada**: trae todo lo que cambió en One desde la instantánea del 2026-10-06.

### 3.4 Apagar o quitar

- **Corte inmediato:** borrar `public/ob-<hex>/`; desde ese momento responde 404. Sin borrar nada: `'token_sha256' => []` en la config (503 `not_configured`).
- **En Keeper:** desactivar la fuente o devolver sus dominios a Keeper. No se borra nada.
- **Quitarlo del todo:** borrar `one-bridge/` completo, **incluida `cache/`**, que tiene datos personales, y descartar el token.

## 3b. Modo files: publicar una instantánea en devkeep (no está desplegado)

Disposición propuesta en devkeep (usuario Hestia `keeper`):

```
/home/keeper/one-snapshot/                 700 keeper:keeper   (fuera de todo docroot)
├── serve.config.php                       600   return ['snapshot_dir' => '/home/keeper/one-snapshot/grupo-azc', 'token_sha256' => ['<hex>']];
└── grupo-azc/  manifest.json, org-units/, schedules/, people/   (archivos 600, carpetas 700)
/home/keeper/web/devkeep.azclegal.com/public_html/one-snapshot/
├── index.php    ← copia de serve.php (CONFIG_FILE ya apunta a /home/keeper/one-snapshot/serve.config.php)
└── .htaccess
```

`.htaccess` del directorio público:

```apache
Options -Indexes
DirectoryIndex index.php
CGIPassAuth On
<FilesMatch "^(?!index\.php$)">
    Require all denied
</FilesMatch>
<RequireAny>
    Require ip 127.0.0.1 185.42.22.239 192.168.12.234
</RequireAny>
```

- Token: `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. En el servidor se guarda solo `hash('sha256', $token)`; caben dos hashes para rotar el token sin cortes. El token en claro va únicamente en la fuente de datos de Keeper, donde queda cifrado.
- URL base para Keeper: `https://devkeep.azclegal.com/one-snapshot/index.php/api/keeper/v1`. El endpoint toma el último segmento de la ruta (`org-units`, `schedules`, `people`, `health`). `holidays` y `absences` responden 404 `not_served`.
- Comprobar desde el servidor:
  - `curl -s -H "Authorization: Bearer $TOKEN" .../health` debe dar 200 con los conteos.
  - Sin token debe dar 401.
  - `curl .../one-snapshot/manifest.json` debe dar 403 o 404, nunca el archivo.
  - Si la raíz de Keeper reescribe todas las rutas a su propio `index.php` y `/one-snapshot/` no llega a este archivo, la alternativa es un dominio web aparte en Hestia (por ejemplo `onesnap.azclegal.com`, mismo usuario `keeper`) cuyo docroot tenga solo `index.php` y el `.htaccess`.
- Si Apache no recibe la cabecera `Authorization` (pasa con algunas configuraciones FPM), `serve.php` también lee `REDIRECT_HTTP_AUTHORIZATION`. Como alternativa a `CGIPassAuth`: `RewriteEngine On` + `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`.
- Garantías:
  - Solo atiende GET: cualquier otro método da 405.
  - Rechaza parámetros desconocidos con 400 y exige HTTPS (403 sin él, salvo `allow_http` para pruebas locales).
  - Falla cerrado: un error interno da 503 y nunca 200 con una lista vacía. Si la instantánea cambia entre páginas, responde 409 `snapshot_changed` y la vuelta falla sin mezclar versiones.
  - El log (`error_log`) solo registra endpoint, estado, página y número de items.
- Para actualizar la instantánea:
  1. Generar una nueva con `generated_at` mayor.
  2. Subirla a una carpeta nueva.
  3. Cambiar `snapshot_dir` en la configuración. El cambio es atómico.

## 4. Crear la fuente en Keeper y simular

Requisitos: el commit `d9d1900` y la migración `0045` desplegados, y el feature `one` activo en el tenant Grupo AZC (`DataSync::due`).

0. **Antes, en el panel** (Keeper todavía es dueño de todo):
   - Las empresas locales deben llamarse exactamente «AZC Legal», «BilingueLaw» y «LawyerDesk». «Matiz LegalTech» y «Grupo AZC» se crean si no existen.
   - Las sedes **no coinciden por nombre**. One envía «Tequendama Cali», «Palmetto Cali», «808 Medellín», «Granada» (Cali, CO) y «Honduras» (HN, ciudad sin confirmar). Keeper tiene «Tequendama», «Medellín», «Tegucigalpa», «CentroAmerica» y «República Dominicana». Para no duplicar, renombrar «Tequendama» → «Tequendama Cali» y «Medellín» → «808 Medellín». Si «Tegucigalpa» es la sede que One llama «Honduras», renombrarla también; esa decisión es del dueño.
   - «CentroAmerica» y «República Dominicana» no existen en One: quedan como sedes locales. La vuelta completa nunca desactiva algo que no está vinculado.
   - En Koichi Fumikatsu, Daniel Moreno y Andres Zafra, cargar la cédula con el mismo valor que su `CC` de One y dejar el correo igual al de One o vacío. Solo así se vinculan (`matched_document`). Si no, quedan como huérfanos pendientes y One crea a otra persona igual. Si el correo es el mismo, esa alta da `email_in_use` y no se crea.
1. Publicar el puente: modo vivo (§3) o una instantánea (§3b).
2. Panel → Integraciones → Fuentes de datos → nueva fuente REST:
   - nombre «AZC One (instantánea)», URL base de §3.3 (o §3b), empresa `grupo-azc` y el token;
   - modo `simulate`, frenos 5 / 5 % / 50 %.
3. **Etapa A, catálogos.**
   - Dominios: `empresas, firmas, sedes, areas, cargos, horarios`. `festivos` y `ausencias` se quedan en Keeper.
   - Correr una vuelta completa simulada y revisar.
   - Pasar a `live` y correr una completa: los pendientes de una simulación no se pueden aprobar.
   - Aprobar los `matched_name`.
4. **Etapa B, personas.**
   - Añadir `personas` y `jerarquia` y volver a `simulate`.
   - Correr una completa y revisar contra la tabla de abajo.
   - Si cuadra, `live` y una completa.
5. Para deshacer: desactivar la fuente y devolver los dominios a Keeper. No se borra nada.

**Esperado con la extracción del 2026-10-06** (resultado de `check-local.php` sobre un cliente con 3 empresas, las 5 sedes de arriba **sin renombrar** y 3 personas manuales, una con la cédula de One cargada):

| Vuelta | Resultado |
|---|---|
| Todo junto simulado (lo que **no** hay que hacer) | Catálogos bien, pero `personas error:unknown_reference` 219: las 3 empresas quedan pendientes por nombre y su gente no resuelve. Por eso las etapas van separadas |
| Etapa A (live) | Altas: empresas 2 (+3 `matched_name` pendientes), firmas 43, sedes 5 (ninguna coincide por nombre), áreas 14, cargos 41 y horarios 14. 0 errores |
| Etapa B (simulada, tras aprobar) | Personas: 219 altas, 1 `link:matched_document` + 1 actualización, 2 `orphan` pendientes y 0 errores. Jerarquía: 51 actualizaciones |
| Incremental | Sin cambios (la instantánea es estática) |

Activos por empresa: BilingueLaw 172, AZC Legal 47 y Matiz 1. Hay 94 inactivos que nunca se crean. 170 activos van a «Sin área» y 72 a «Sin firma».

## 5. Reemplazo por One v2

- En la fuente solo cambian la **URL base** y el **token**. La empresa sigue siendo `grupo-azc`, y los dominios y los vínculos (`entity_links`) se conservan.
- One v2 debe servir las mismas rutas (`/org-units`, `/schedules`, `/people`), los mismos parámetros, el mismo sobre (`complete`, el mismo `generated_at` en todas las páginas, `next_cursor`) y **exactamente los mismos `external_id`**: `one-company-<slug>`, `one-firm-<id>`, `one-sede-<id>`, `one-area-<id>`, `one-cargo-<id>` y `one-emp-<employee.id>`.
  - Si cambian, Keeper ve «todos nuevos y todos ausentes». La vuelta completa queda frenada por `too_many_deactivations`, y las altas fallan con `document_in_use`, porque la cédula ya está tomada.
- Horarios: si One v2 trae un catálogo propio con otros ids, Keeper crea los nuevos y reasigna a las personas. Los `one-hor-*` viejos quedan sin asignar, porque Keeper no desactiva horarios. Para conservarlos, One v2 debe usar la misma fórmula de id.
- `updated_since` queda en el `generated_at` de la instantánea, así que la primera incremental de One v2 trae todo lo cambiado desde la extracción. Aun así, conviene correr primero una **completa simulada** contra One v2.
- Después: apagar y quitar el modo vivo (§3.4). Si quedó publicada una instantánea, borrar `/home/keeper/one-snapshot/` y `public_html/one-snapshot/`, y quitar el hash del token.

## 6. Prueba local (`check-local.php`)

1. Crear una BD de Keeper desechable cuyo nombre empiece por `keeper_e2e` y migrarla con `backend-v4/migrations/run.php`.
2. Servir `serve.php` con `php -S` y `ONE_SNAPSHOT_CONFIG` apuntando a una configuración con `allow_http => true`.
3. Correr:

```bash
KEEPER_DB_DSN='mysql:host=127.0.0.1;port=13391;dbname=keeper_e2e;charset=utf8mb4' KEEPER_DB_USER=root \
KEEPER_RESPONSE_KEY=$(php -r 'echo base64_encode(random_bytes(32));') \
php tools/one-legacy-snapshot/check-local.php --base=http://127.0.0.1:18391/serve.php/api/keeper/v1 --token=$TOKEN --link-document
```

El `fetch` inyectado arma la misma URL, la misma consulta y las mismas cabeceras que `restFetch`; la única diferencia es que el transporte es http local. El script imprime conteos por dominio, acción y motivo, y 12 comprobaciones del contrato HTTP.

Sirve para los dos modos. Con la copia del 2026-10-06, su salida contra el modo vivo es idéntica a la del modo archivos (comprobado el 2026-10-20).

## 7. Prueba del modo vivo (`check-live.php`)

Requisitos en el mismo MySQL desechable:
- la copia de One cargada según §1 (BD `one_legacy`, usuario `root`, que tiene todos los permisos, como el usuario de One en producción);
- una BD de Keeper `keeper_e2e*` migrada;
- la instantánea de `extract.php` sacada de esa misma copia.

```bash
export KEEPER_DB_DSN='mysql:host=127.0.0.1;port=13397;dbname=keeper_e2e_live;charset=utf8mb4' KEEPER_DB_USER=root
export KEEPER_RESPONSE_KEY=$(php -r 'echo base64_encode(random_bytes(32));')
php tools/one-legacy-snapshot/check-live.php --mysql-port=13397 --one-db=one_legacy \
  --snapshot="$OUT/grupo-azc" --work="$SCRATCH/live-work"
```

`--work` debe estar vacía y fuera del repo, porque ahí quedan las cachés. El script levanta sus propios `php -S` con la entrada pública mínima y varias configuraciones, y los detiene por su handle al terminar. Imprime solo conteos y 46 comprobaciones:
- Guarda de solo-SELECT (12 sentencias rechazadas) y lectura del config de One sin ejecutarlo, con sus rechazos.
- `UPDATE`, `INSERT`, `DELETE` y `CREATE TABLE` fallan con 1792 dentro de la sesión, y la BD de One queda igual.
- Vivo = archivos item por item: completo, paginado de a 50 e incremental. DataSync del repo da los mismos items contra vivo, vivo paginado y archivos.
- Una sola construcción dentro del TTL y reconstrucción al vencer. El cursor se sirve desde la versión anterior y da 409 si ya no existe.
- Bloqueo: con `build.lock` tomado, nadie responde; al soltarlo, 3 servidores dan 200 con una sola construcción.
- 401 sin token y 403 desde una IP fuera de `allowed_ips`, sin tocar la BD; 503 sin BD ni caché, caché vieja con aviso, 503 pasado `cache_stale_max`, y logs sin token, credenciales ni datos personales.
