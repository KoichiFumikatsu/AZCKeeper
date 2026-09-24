<?php
declare(strict_types=1);
/**
 * Trae de K3 el desglose horario que v4 no tenia: laboral / almuerzo / fuera de horario,
 * mas las llamadas. Sin estas columnas el panel solo puede mostrar un total de actividad,
 * y no existen ni la Productividad ni las alertas de actividad fuera de horario.
 *
 * Portatil en dos pasos, porque el servidor de destino no alcanza al MySQL de K3:
 *   Donde K3 SI es alcanzable:  php k3-breakdown-backfill.php --export=desglose.jsonl
 *   Transferir el archivo, y en el destino:
 *     php k3-breakdown-backfill.php --import=desglose.jsonl --dry-run
 *     php k3-breakdown-backfill.php --import=desglose.jsonl
 *
 * K3 se lee en SOLO LECTURA, con la transaccion marcada de solo lectura. El destino solo
 * recibe UPDATE sobre filas que ya existen: nunca inserta ni borra.
 *
 * Dos decisiones que explican la forma del archivo:
 *
 *  1. Se exporta POR DISPOSITIVO, no ya sumado. El import original de v4 descarto los
 *     dispositivos cuyo usuario o asignacion no resolvia, asi que sumar aqui todos los de
 *     K3 produciria un desglose mayor que el total que ya esta en v4. Al llevar el detalle,
 *     el destino suma exactamente los mismos dispositivos que conoce.
 *
 *  2. Se traen TAMBIEN active_seconds e idle_seconds. Los totales que hay en v4 son una
 *     foto del instante en que corrio el import; el desglose se lee despues. Para un dia
 *     que aun estaba acumulando, el desglose resultaria mayor que su propio total. Al
 *     actualizar ambos del mismo origen y el mismo instante, la fila queda coherente.
 *
 * NO se trae `samples_count`: en K3 cuenta envios HTTP del dia (~2.880 en una jornada),
 * no muestras del usuario. En v4 `sample_count` significa muestras reales, y copiar un
 * numero con el mismo nombre y otro significado dejaria el dato mintiendo para siempre.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Los nombres coinciden en K3 y en v4, asi que el traslado es directo. */
const BREAKDOWN = ['work_hours_active_seconds', 'work_hours_idle_seconds', 'lunch_active_seconds',
    'lunch_idle_seconds', 'after_hours_active_seconds', 'after_hours_idle_seconds', 'call_seconds'];
const TOTALS = ['active_seconds', 'idle_seconds'];
const COLUMNS = [...TOTALS, ...BREAKDOWN];

/** Mismo namespace que uso import-k3.php; sin el, los ids derivados no coincidirian. */
const ID_NAMESPACE = '6c40ce1e-ff53-51be-b17c-88e792106073';
/** Deriva aceptable entre el total y la suma de sus franjas, en segundos. */
const TOLERANCE = 60;
const BATCH = 500;

function env(string $name, bool $required = true): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($required) { throw new RuntimeException("Falta la variable de entorno $name."); }
        return '';
    }
    return $value;
}

function connect(string $dsnVar, string $userVar, string $passVar, bool $readOnly): PDO
{
    $dsn = env($dsnVar);
    if (!str_starts_with($dsn, 'mysql:')) { throw new RuntimeException("$dsnVar debe ser un DSN mysql:"); }
    $file = getenv($passVar . '_FILE');
    $password = $file ? rtrim((string) file_get_contents($file), "\r\n") : env($passVar, false);
    $pdo = new PDO($dsn, env($userVar), $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    // El origen queda incapacitado para escribir aunque alguien edite el SQL mas abajo.
    if ($readOnly) { $pdo->exec('SET SESSION TRANSACTION READ ONLY'); }
    return $pdo;
}

/** Deriva el mismo UUID que uso el import original: namespace + tenant + tipo + id legacy. */
function derivedId(string $tenantBinary, string $type, string $legacy): string
{
    $namespace = (string) hex2bin(str_replace('-', '', ID_NAMESPACE));
    $hash = substr(sha1($namespace . bin2hex($tenantBinary) . ':' . $type . ':' . $legacy, true), 0, 16);
    $hash[6] = chr((ord($hash[6]) & 15) | 80);
    $hash[8] = chr((ord($hash[8]) & 63) | 128);
    return $hash;
}

function exportBreakdown(string $path): void
{
    $source = connect('K3_DSN', 'K3_USER', 'K3_PASSWORD', true);
    $stream = @fopen($path, 'xb');
    if ($stream === false) { throw new RuntimeException('La ruta de salida debe ser nueva.'); }
    $write = static function (array $row) use ($stream): void {
        $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
        if (fwrite($stream, $line) !== strlen($line)) { throw new RuntimeException('Escritura incompleta.'); }
    };

    try {
        $write(['type' => 'k3-breakdown', 'version' => 2,
            'source_database' => $source->query('SELECT DATABASE()')->fetchColumn(),
            'exported_at' => gmdate('c')]);

        // Si (usuario,dispositivo,dia) estuviera repetido, gana el id mas alto, igual que
        // hizo el import original.
        $select = implode(',', array_map(static fn($c) => "a.`$c`", COLUMNS));
        $rows = $source->query(
            "SELECT a.user_id, a.day_date, a.device_id, $select
             FROM keeper_activity_day a
             JOIN (SELECT user_id, device_id, day_date, MAX(id) AS id
                   FROM keeper_activity_day GROUP BY user_id, device_id, day_date) w ON w.id = a.id
             ORDER BY a.user_id, a.day_date, a.device_id"
        );

        $records = 0;
        $group = [];
        $key = null;
        $flush = static function () use (&$group, &$key, &$records, $write): void {
            if ($key === null || !$group) { return; }
            [$user, $day] = explode('|', $key, 2);
            $write(['type' => 'day', 'legacy_user_id' => $user, 'day' => $day, 'devices' => $group]);
            ++$records;
            $group = [];
        };
        foreach ($rows as $row) {
            $next = $row['user_id'] . '|' . $row['day_date'];
            if ($key !== null && $key !== $next) { $flush(); }
            $key = $next;
            $device = [];
            foreach (COLUMNS as $column) {
                $device[$column] = $row[$column] === null ? null : (int) round((float) $row[$column]);
            }
            $group[(string) $row['device_id']] = $device;
        }
        $flush();

        $write(['type' => 'end', 'records' => $records]);
        if (!fflush($stream)) { throw new RuntimeException('No se pudo completar el archivo.'); }
        fwrite(STDERR, "Exportadas $records filas usuario/dia.\n");
    } finally { fclose($stream); }
}

/** Valida el archivo entero; se recorre dos veces para no escribir nada de un archivo truncado. */
function records($stream): Generator
{
    rewind($stream);
    $started = false;
    $seen = 0;
    $ended = false;
    $line = 0;
    while (($text = fgets($stream, 4194305)) !== false) {
        ++$line;
        if (trim($text) === '') { continue; }
        if ($ended) { throw new RuntimeException("Hay contenido despues del cierre, linea $line."); }
        try { $row = json_decode($text, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException("JSON invalido en la linea $line."); }
        if (!is_array($row)) { throw new RuntimeException("Registro invalido en la linea $line."); }
        if (!$started) {
            if (($row['type'] ?? null) !== 'k3-breakdown' || ($row['version'] ?? null) !== 2) {
                throw new RuntimeException('Cabecera incompatible: se esperaba un desglose de K3 version 2.');
            }
            $started = true;
            continue;
        }
        if (($row['type'] ?? null) === 'end') {
            if (($row['records'] ?? null) !== $seen) { throw new RuntimeException('El conteo final no coincide: archivo truncado.'); }
            $ended = true;
            continue;
        }
        if (($row['type'] ?? null) !== 'day' || !isset($row['legacy_user_id'], $row['day'])
            || !is_array($row['devices'] ?? null) || !$row['devices']
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $row['day'])) {
            throw new RuntimeException("Registro invalido en la linea $line.");
        }
        ++$seen;
        yield $row;
    }
    if (!$started || !$ended) { throw new RuntimeException('Archivo incompleto: falta el cierre.'); }
}

function importBreakdown(string $path, bool $dry): void
{
    $db = connect('KEEPER_DB_DSN', 'KEEPER_DB_USER', 'KEEPER_DB_PASSWORD', false);
    $tenant = $db->query("SELECT tenant_id FROM tenants WHERE tenant_id<>UNHEX('00000000000040008000000000000001') ORDER BY tenant_id LIMIT 1")->fetchColumn();
    if ($tenant === false) { throw new RuntimeException('No se encontro el tenant de datos.'); }

    $refs = [];
    $query = $db->prepare('SELECT external_ref,user_id FROM user_external_refs WHERE tenant_id=? AND source=?');
    $query->execute([$tenant, 'k3']);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $refs[$row['external_ref']] = $row['user_id']; }

    $known = [];
    $query = $db->prepare('SELECT id FROM devices WHERE tenant_id=?');
    $query->execute([$tenant]);
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) { $known[$id] = true; }

    $stream = @fopen($path, 'rb');
    if ($stream === false) { throw new RuntimeException('No se pudo abrir el archivo.'); }

    $stats = ['leidas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0, 'usuario_no_importado' => 0,
        'dia_no_encontrado' => 0, 'sin_dispositivo_conocido' => 0, 'dispositivos_descartados' => 0, 'desglose_incoherente' => 0];
    try {
        foreach (records($stream) as $_) {}   // valida el archivo completo antes de tocar nada

        $set = implode(',', array_map(static fn($c) => "`$c`=?", COLUMNS));
        $where = "tenant_id=? AND user_id=? AND day=? AND calculation_version LIKE 'k3-import-%'";
        $different = 'NOT (' . implode(' AND ', array_map(static fn($c) => "`$c` <=> ?", COLUMNS)) . ')';
        $update = $db->prepare("UPDATE day_summary SET $set WHERE $where AND $different");
        $probe = $db->prepare("SELECT COUNT(*) FROM day_summary WHERE $where");
        $changed = $db->prepare("SELECT COUNT(*) FROM day_summary WHERE $where AND $different");

        if (!$dry) { $db->beginTransaction(); }
        $n = 0;
        foreach (records($stream) as $row) {
            ++$stats['leidas'];
            $user = $refs[(string) $row['legacy_user_id']] ?? null;
            if ($user === null) { ++$stats['usuario_no_importado']; continue; }

            // Solo los dispositivos que v4 conoce: los demas nunca entraron en el total.
            $sums = array_fill_keys(COLUMNS, 0);
            $used = 0;
            foreach ($row['devices'] as $legacyDevice => $counters) {
                if (!isset($known[derivedId($tenant, 'device', (string) $legacyDevice)])) {
                    ++$stats['dispositivos_descartados'];
                    continue;
                }
                ++$used;
                foreach (COLUMNS as $column) { $sums[$column] += (int) ($counters[$column] ?? 0); }
            }
            if ($used === 0) { ++$stats['sin_dispositivo_conocido']; continue; }

            // El desglose de una franja no puede sumar mas que el total del que forma parte.
            // Hasta 60 s es deriva normal entre dos contadores que avanzan por separado; por
            // encima, la fila de K3 esta corrupta (su rehidratacion del dia podia sumar las
            // categorias dos veces). Esas filas se dejan en NULL = desconocido: recortarlas
            // seria inventar un reparto horario que nadie midio.
            $incoherent = false;
            $splits = [
                'active_seconds' => ['work_hours_active_seconds', 'lunch_active_seconds', 'after_hours_active_seconds'],
                'idle_seconds' => ['work_hours_idle_seconds', 'lunch_idle_seconds', 'after_hours_idle_seconds'],
            ];
            foreach ($splits as $total => $parts) {
                $sum = 0;
                foreach ($parts as $part) { $sum += $sums[$part]; }
                if ($sum > $sums[$total] + TOLERANCE) { $incoherent = true; }
            }
            if ($incoherent) { ++$stats['desglose_incoherente']; continue; }

            $values = array_values($sums);
            $key = [$tenant, $user, $row['day']];

            $probe->execute($key);
            if ((int) $probe->fetchColumn() === 0) { ++$stats['dia_no_encontrado']; continue; }

            if ($dry) {
                $changed->execute([...$key, ...$values]);
                $moved = (int) $changed->fetchColumn();
            } else {
                $update->execute([...$values, ...$key, ...$values]);
                $moved = $update->rowCount();
            }
            $stats['actualizadas'] += $moved;
            if ($moved === 0) { ++$stats['sin_cambios']; }

            if (!$dry && ++$n % BATCH === 0) { $db->commit(); $db->beginTransaction(); }
        }
        if (!$dry && $db->inTransaction()) { $db->commit(); }
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    } finally { fclose($stream); }

    echo ($dry ? "PROYECCION (no se escribio nada)\n" : "APLICADO\n");
    foreach ($stats as $name => $value) { printf("  %-26s %d\n", $name, $value); }
}

$options = getopt('', ['export:', 'import:', 'dry-run']);
try {
    if (isset($options['export'], $options['import'])) { throw new RuntimeException('Elige un solo modo.'); }
    if (isset($options['export'])) { exportBreakdown((string) $options['export']); }
    elseif (isset($options['import'])) { importBreakdown((string) $options['import'], isset($options['dry-run'])); }
    else {
        fwrite(STDERR, "Uso:\n  --export=archivo.jsonl   (donde K3 es alcanzable; K3_DSN/K3_USER/K3_PASSWORD)\n"
            . "  --import=archivo.jsonl [--dry-run]   (en el destino; KEEPER_DB_DSN/KEEPER_DB_USER/KEEPER_DB_PASSWORD)\n");
        exit(2);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
