<?php
declare(strict_types=1);
use Keeper\{CronRun,Database};

// Salud del servidor (2026-10-14): cada cron deja su ultima corrida en cron_runs y la API la expone como `jobs`.
// Incidente: el calculo de productividad fallo horas con PDOException y el cron solo guardaba la clase.

function cronJob(array $jobs, string $job): array { foreach ($jobs as $j) { if ($j['job'] === $job) { return $j; } } throw new RuntimeException("sin $job"); }

function cronScript(string $script, array $args): array
{
    $proc = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/config/' . $script], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    return [proc_close($proc), $err];
}

function cronRunsTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $db->run('DELETE FROM cron_runs');
    $jobs = CronRun::health($db);
    check(array_column($jobs, 'job') === ['productivity', 'retention'] && array_unique(array_column($jobs, 'status')) === ['never'], 'sin corridas: ambos trabajos en never');

    CronRun::start($db, 'productivity');
    $j = cronJob(CronRun::health($db), 'productivity');
    check($j['status'] === 'stale' && $j['last_started_at'] !== null && $j['last_success_at'] === null, 'corriendo sin exito previo: atrasado, con hora de inicio');
    CronRun::ok($db, 'productivity');
    $j = cronJob(CronRun::health($db), 'productivity');
    check($j['status'] === 'ok' && $j['last_success_at'] !== null && $j['last_finished_at'] !== null && $j['error_message'] === null, 'ok: fin y exito registrados');

    // Fallo real: PDOException con valores literales y un correo en el mensaje.
    try { $db->run("SELECT * FROM tabla_que_no_existe WHERE correo='ana@empresa.com' AND doc=1234567890"); check(false, 'consulta invalida debe fallar'); }
    catch (Throwable $e) { CronRun::fail($db, 'productivity', $e); }
    $j = cronJob(CronRun::health($db), 'productivity');
    check($j['status'] === 'failed' && $j['error_class'] === 'PDOException' && str_contains($j['error_message'], 'SQLSTATE[42S02]'), 'fallo: clase y mensaje de la excepcion');
    check(!str_contains($j['error_message'], 'ana@empresa.com') && !str_contains($j['error_message'], '1234567890') && $j['last_success_at'] !== null, 'mensaje sin correo ni numeros largos; conserva el ultimo exito');
    CronRun::failed($db, 'retention', 'X', "linea1\n\nlinea2 'secreto' " . str_repeat('a ', 600));
    $j = cronJob(CronRun::health($db), 'retention');
    check(mb_strlen($j['error_message']) <= 500 && !str_contains($j['error_message'], "\n") && !str_contains($j['error_message'], 'secreto'), 'mensaje recortado a 500 y en una linea');

    // Atrasado: ultimo exito hace mas del umbral (productividad 2 h, retencion 26 h).
    foreach ([['productivity', 119, 'ok'], ['productivity', 121, 'stale'], ['retention', 25 * 60, 'ok'], ['retention', 27 * 60, 'stale']] as [$job, $minutes, $expected]) {
        $db->run("INSERT INTO cron_runs (tenant_id,job,started_at,finished_at,last_success_at,status) VALUES (0x00000000000040008000000000000001,?,UTC_TIMESTAMP(6)-INTERVAL ? MINUTE,UTC_TIMESTAMP(6)-INTERVAL ? MINUTE,UTC_TIMESTAMP(6)-INTERVAL ? MINUTE,'ok')
            ON DUPLICATE KEY UPDATE started_at=VALUES(started_at),finished_at=VALUES(finished_at),last_success_at=VALUES(last_success_at),status='ok',error_class=NULL,error_message=NULL", [$job, $minutes, $minutes, $minutes]);
        check(cronJob(CronRun::health($db), $job)['status'] === $expected, "$job con exito hace $minutes min: $expected");
    }
    // Una corrida omitida (otra copia en curso) no cuenta como fallo ni como exito.
    $db->run("UPDATE cron_runs SET last_success_at=UTC_TIMESTAMP(6)-INTERVAL 10 MINUTE WHERE job='retention'");
    CronRun::skipped($db, 'retention');
    $j = cronJob(CronRun::health($db), 'retention');
    check($j['status'] === 'ok' && $j['last_success_at'] !== null, 'omitida: no pisa el ultimo exito');
    // Fallo reciente gana sobre exito reciente.
    CronRun::failed($db, 'productivity', 'PDOException', 'Lock wait timeout exceeded');
    check(cronJob(CronRun::health($db), 'productivity')['status'] === 'failed', 'ultimo intento fallido => failed aunque el exito sea reciente');
    $db->run('DELETE FROM cron_runs');

    // Los scripts reales.
    [$code, $err] = cronScript('productivity-cron.php', ['--tenant=no-es-uuid']);
    $j = cronJob(CronRun::health($db), 'productivity');
    check($code === 1 && str_contains($err, 'productivity_failed:') && $j['status'] === 'failed' && $j['error_class'] !== null && $j['error_message'] !== '', 'productivity-cron registra clase y mensaje del fallo');
    $db->run('DELETE FROM cron_runs');
    [$code] = cronScript('retention-cron.php', ['--batch=0']);
    $j = cronJob(CronRun::health($db), 'retention');
    check($code === 1 && $j['status'] === 'failed' && $j['error_class'] === 'InvalidArgumentException', 'retention-cron registra el fallo de argumentos');
    [$code] = cronScript('productivity-cron.php', ['--max-days=1', '--budget-seconds=5']);
    $j = cronJob(CronRun::health($db), 'productivity');
    check($code === 0 && $j['status'] === 'ok' && $j['last_success_at'] !== null, 'productivity-cron correcto deja ok con hora');
    $db->run('DELETE FROM cron_runs');
    echo 'PASS cron runs (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
