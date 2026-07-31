<?php
namespace Keeper\Repos;

use PDO;

/**
 * Diagnostico en vivo por persona. Dos tablas: la SESION (flag por usuario con expiracion
 * de seguridad) y los SNAPSHOTS efimeros que sube el cliente mientras esta marcado.
 *
 * Todo el tiempo se maneja en UTC (el servidor corre en UTC; expires_at se compara contra
 * UTC_TIMESTAMP). El auto-apagado es implicito: una sesion vencida se trata como ausente en
 * activeSession() y la purga() la borra.
 */
class DiagnosticRepo
{
    /** Retencion de snapshots, en horas. */
    public const SNAPSHOT_RETENTION_HOURS = 2;
    /** Duracion por defecto del flag, en horas (auto-apagado si IT olvida desmarcar). */
    public const SESSION_HOURS = 4;

    /** Sesion de diagnostico ACTIVA (no vencida) de un usuario, o null. */
    public static function activeSession(PDO $pdo, int $userId): ?array
    {
        $st = $pdo->prepare(
            "SELECT user_id, enabled_by, started_at, expires_at
             FROM keeper_diagnostic_session
             WHERE user_id = :u AND expires_at > UTC_TIMESTAMP()
             LIMIT 1");
        $st->execute([':u' => $userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Enciende (o renueva) el diagnostico de un usuario por $hours horas. */
    public static function setSession(PDO $pdo, int $userId, ?int $adminId, int $hours = self::SESSION_HOURS): void
    {
        $st = $pdo->prepare(
            "INSERT INTO keeper_diagnostic_session (user_id, enabled_by, started_at, expires_at)
             VALUES (:u, :by, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL :h HOUR)
             ON DUPLICATE KEY UPDATE
               enabled_by = VALUES(enabled_by),
               started_at = UTC_TIMESTAMP(),
               expires_at = UTC_TIMESTAMP() + INTERVAL :h2 HOUR");
        $st->execute([':u' => $userId, ':by' => $adminId, ':h' => $hours, ':h2' => $hours]);
    }

    /** Apaga el diagnostico de un usuario (borra el flag). */
    public static function clearSession(PDO $pdo, int $userId): void
    {
        $pdo->prepare("DELETE FROM keeper_diagnostic_session WHERE user_id = :u")->execute([':u' => $userId]);
    }

    /** Inserta un snapshot. $payloadJson debe ser JSON valido (el endpoint lo valida). */
    public static function insertSnapshot(PDO $pdo, int $userId, int $deviceId, ?string $clientTs, string $payloadJson): void
    {
        $st = $pdo->prepare(
            "INSERT INTO keeper_diagnostic_snapshot (user_id, device_id, client_ts, payload)
             VALUES (:u, :d, :ts, :p)");
        $st->execute([':u' => $userId, ':d' => $deviceId, ':ts' => $clientTs, ':p' => $payloadJson]);
    }

    /**
     * Snapshot mas reciente de un usuario, o null. Incluye captured_epoch (UNIX_TIMESTAMP,
     * independiente de la zona de sesion): el panel corre con time_zone=-05:00, asi que leer
     * el TIMESTAMP crudo y tratarlo como UTC desfasaria la edad 5h. El epoch evita todo eso.
     */
    public static function latestSnapshot(PDO $pdo, int $userId): ?array
    {
        $st = $pdo->prepare(
            "SELECT id, device_id, captured_at, UNIX_TIMESTAMP(captured_at) AS captured_epoch,
                    client_ts, payload
             FROM keeper_diagnostic_snapshot
             WHERE user_id = :u ORDER BY id DESC LIMIT 1");
        $st->execute([':u' => $userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Purga: snapshots mas viejos que la retencion + flags vencidos. Devuelve cuantas filas
     * borro en total (para el resumen del cron).
     */
    public static function purge(PDO $pdo): int
    {
        $n = 0;
        $st = $pdo->prepare(
            "DELETE FROM keeper_diagnostic_snapshot
             WHERE captured_at < UTC_TIMESTAMP() - INTERVAL :h HOUR");
        $st->execute([':h' => self::SNAPSHOT_RETENTION_HOURS]);
        $n += $st->rowCount();

        $st = $pdo->query("DELETE FROM keeper_diagnostic_session WHERE expires_at < UTC_TIMESTAMP()");
        $n += $st->rowCount();
        return $n;
    }
}
