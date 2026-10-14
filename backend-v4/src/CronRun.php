<?php
declare(strict_types=1);
namespace Keeper;

/** Última corrida de cada trabajo programado (tabla cron_runs), para mostrarla en Salud del servidor. */
final class CronRun
{
    /** Cada cuánto corre y a partir de cuántos minutos sin éxito se considera atrasado. */
    private const PLATFORM = "\x00\x00\x00\x00\x00\x00\x40\x00\x80\x00\x00\x00\x00\x00\x00\x01";

    public const JOBS = [
        'productivity' => ['label' => 'Cálculo de productividad', 'every' => 'cada hora', 'stale_minutes' => 120],
        'retention' => ['label' => 'Revisión de retención', 'every' => 'cada noche', 'stale_minutes' => 1560],
    ];

    /** Nunca lanza: registrar la corrida no puede tumbar el trabajo ni esconder su error real. */
    public static function start(Database $db, string $job): void
    {
        try {
            $db->run("INSERT INTO cron_runs (tenant_id,job,started_at,status) VALUES (?,?,UTC_TIMESTAMP(6),'running') ON DUPLICATE KEY UPDATE started_at=VALUES(started_at),finished_at=NULL,status='running'", [self::PLATFORM, $job]);
        } catch (\Throwable) {}
    }

    public static function ok(Database $db, string $job): void
    {
        try {
            $db->run("UPDATE cron_runs SET status='ok',finished_at=UTC_TIMESTAMP(6),last_success_at=UTC_TIMESTAMP(6),error_class=NULL,error_message=NULL WHERE tenant_id=? AND job=?", [self::PLATFORM, $job]);
        } catch (\Throwable) {}
    }

    /** Otra copia ya estaba corriendo: ni fallo ni éxito, y no borra el último éxito. */
    public static function skipped(Database $db, string $job): void
    {
        try { $db->run("UPDATE cron_runs SET status='skipped',finished_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND job=?", [self::PLATFORM, $job]); } catch (\Throwable) {}
    }

    public static function failed(Database $db, string $job, string $class, string $message): void
    {
        try {
            $db->run("INSERT INTO cron_runs (tenant_id,job,started_at,finished_at,status,error_class,error_message) VALUES (?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'failed',?,?)
                ON DUPLICATE KEY UPDATE finished_at=UTC_TIMESTAMP(6),status='failed',error_class=VALUES(error_class),error_message=VALUES(error_message)",
                [self::PLATFORM, $job, mb_substr($class, 0, 120), mb_substr(self::clean($message), 0, 500)]);
        } catch (\Throwable) {}
    }

    public static function fail(Database $db, string $job, \Throwable $e): void
    {
        self::failed($db, $job, get_class($e), $e->getMessage());
    }

    /** Mensaje apto para pantalla: sin valores literales (pueden ser datos personales), correos, números largos ni saltos de línea. */
    public static function clean(string $message): string
    {
        $m = preg_replace(["/'[^']*'/u", '/"[^"]*"/u', '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '/\b[0-9a-f]{16,}\b/i', '/\d{6,}/', '/\s+/u'],
            ["'?'", '"?"', '?', '?', '?', '?', ' '], $message) ?? '';
        return trim($m);
    }

    /**
     * Una entrada por trabajo para la API. status: ok | failed | stale | running | never.
     * Falló gana sobre atrasado: el último intento terminó en error aunque haya habido un éxito reciente.
     */
    public static function health(Database $db): array
    {
        $rows = [];
        foreach ($db->run('SELECT * FROM cron_runs WHERE tenant_id=?', [self::PLATFORM])->fetchAll() as $r) { $rows[$r['job']] = $r; }
        $now = (int) $db->one('SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP()) t')['t'];
        $out = [];
        foreach (self::JOBS as $job => $meta) {
            $r = $rows[$job] ?? null;
            $success = $r['last_success_at'] ?? null;
            $age = $success === null ? null : $now - (new \DateTimeImmutable($success, new \DateTimeZone('UTC')))->getTimestamp();
            $overdue = $age === null || $age > $meta['stale_minutes'] * 60;
            $status = $r === null ? 'never' : ($r['status'] === 'failed' ? 'failed' : ($overdue ? 'stale' : ($r['status'] === 'running' ? 'running' : 'ok')));
            $out[] = ['job' => $job, 'label' => $meta['label'], 'schedule' => $meta['every'], 'stale_after_minutes' => $meta['stale_minutes'], 'status' => $status,
                'last_started_at' => $r ? Util::time($r['started_at']) : null,
                'last_finished_at' => isset($r['finished_at']) ? Util::time($r['finished_at']) : null,
                'last_success_at' => $success === null ? null : Util::time($success),
                'error_class' => $r['error_class'] ?? null, 'error_message' => $r['error_message'] ?? null];
        }
        return $out;
    }
}
