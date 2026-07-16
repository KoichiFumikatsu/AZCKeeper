<?php
namespace Keeper\Repos;

use PDO;

/**
 * ClientLogRepo — lectura/escritura de `keeper_client_log`.
 *
 * Escritura: el endpoint /api/client/logs (batch, máx 50 por request).
 * Lectura:   el panel /admin/logs.php.
 * Retención: purgeOlderThan(), invocado por el cron nocturno.
 */
class ClientLogRepo
{
    public const RETENTION_DAYS = 30;

    /** Niveles y fuentes aceptados. Cualquier otro valor se normaliza. */
    public const LEVELS  = ['info', 'warn', 'error'];
    public const SOURCES = ['network', 'update', 'blocking', 'auth', 'tracking', 'core', 'other'];

    /**
     * Inserta un lote en una sola sentencia multi-row. Devuelve el número de filas insertadas.
     * $rows: [['level'=>, 'source'=>, 'message'=>, 'meta'=>?array, 'client_ts'=>?string], ...]
     */
    public static function insertBatch(PDO $pdo, ?int $userId, ?int $deviceId, ?string $clientVersion, array $rows): int
    {
        if (count($rows) === 0) return 0;

        $placeholders = [];
        $params = [];

        foreach ($rows as $k => $r) {
            $placeholders[] = "(:uid{$k}, :did{$k}, :lvl{$k}, :src{$k}, :msg{$k}, :meta{$k}, :ver{$k}, :cts{$k}, NOW())";
            $params["uid{$k}"]  = $userId;
            $params["did{$k}"]  = $deviceId;
            $params["lvl{$k}"]  = $r['level'];
            $params["src{$k}"]  = $r['source'];
            $params["msg{$k}"]  = $r['message'];
            $params["meta{$k}"] = isset($r['meta']) && $r['meta'] !== null
                ? json_encode($r['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
            $params["ver{$k}"]  = $clientVersion;
            $params["cts{$k}"]  = $r['client_ts'] ?? null;
        }

        $sql = "INSERT INTO keeper_client_log
                  (user_id, device_id, level, source, message, meta_json, client_version, client_ts, created_at)
                VALUES " . implode(',', $placeholders);

        $st = $pdo->prepare($sql);
        $st->execute($params);

        return count($rows);
    }

    /**
     * Búsqueda paginada para el panel. $filters: level, source, device_id, user_id, from, to, q.
     */
    public static function search(PDO $pdo, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::buildWhere($filters);

        $sql = "SELECT l.id, l.level, l.source, l.message, l.meta_json, l.client_version,
                       l.client_ts, l.created_at,
                       l.device_id, d.device_name,
                       l.user_id, u.display_name
                FROM keeper_client_log l
                LEFT JOIN keeper_devices d ON d.id = l.device_id
                LEFT JOIN keeper_users   u ON u.id = l.user_id
                {$where}
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT :lim OFFSET :off";

        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function countMatching(PDO $pdo, array $filters): int
    {
        [$where, $params] = self::buildWhere($filters);

        $st = $pdo->prepare("SELECT COUNT(*) FROM keeper_client_log l {$where}");
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();

        return (int)$st->fetchColumn();
    }

    /** Conteo por nivel en las últimas $hours horas — cabecera del panel. */
    public static function countsByLevel(PDO $pdo, int $hours = 24): array
    {
        $st = $pdo->prepare("SELECT level, COUNT(*) n FROM keeper_client_log
                             WHERE created_at >= NOW() - INTERVAL :h HOUR GROUP BY level");
        $st->bindValue(':h', $hours, PDO::PARAM_INT);
        $st->execute();

        $out = ['info' => 0, 'warn' => 0, 'error' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['level']] = (int)$r['n'];

        return $out;
    }

    /** Equipos con más errores en las últimas $hours horas. */
    public static function topOffenders(PDO $pdo, int $hours = 24, int $limit = 10): array
    {
        $st = $pdo->prepare("SELECT l.device_id, d.device_name, u.display_name, COUNT(*) n,
                                    MAX(l.created_at) ultimo
                             FROM keeper_client_log l
                             LEFT JOIN keeper_devices d ON d.id = l.device_id
                             LEFT JOIN keeper_users   u ON u.id = l.user_id
                             WHERE l.created_at >= NOW() - INTERVAL :h HOUR
                               AND l.level IN ('warn','error')
                             GROUP BY l.device_id, d.device_name, u.display_name
                             ORDER BY n DESC LIMIT :lim");
        $st->bindValue(':h', $hours, PDO::PARAM_INT);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Purga por retención. Borra en tandas para no bloquear la tabla en hosting compartido. */
    public static function purgeOlderThan(PDO $pdo, int $days = self::RETENTION_DAYS, int $batchSize = 5000): int
    {
        $total = 0;

        do {
            $st = $pdo->prepare("DELETE FROM keeper_client_log
                                 WHERE created_at < NOW() - INTERVAL :d DAY
                                 LIMIT :lim");
            $st->bindValue(':d', $days, PDO::PARAM_INT);
            $st->bindValue(':lim', $batchSize, PDO::PARAM_INT);
            $st->execute();

            $deleted = $st->rowCount();
            $total += $deleted;
        } while ($deleted === $batchSize);

        return $total;
    }

    private static function buildWhere(array $filters): array
    {
        $conds = [];
        $params = [];

        if (!empty($filters['level'])) {
            $conds[] = 'l.level = :level';
            $params[':level'] = $filters['level'];
        }
        if (!empty($filters['source'])) {
            $conds[] = 'l.source = :source';
            $params[':source'] = $filters['source'];
        }
        if (!empty($filters['device_id'])) {
            $conds[] = 'l.device_id = :did';
            $params[':did'] = (int)$filters['device_id'];
        }
        if (!empty($filters['user_id'])) {
            $conds[] = 'l.user_id = :uid';
            $params[':uid'] = (int)$filters['user_id'];
        }
        if (!empty($filters['from'])) {
            $conds[] = 'l.created_at >= :from';
            $params[':from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $conds[] = 'l.created_at <= :to';
            $params[':to'] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $conds[] = 'l.message LIKE :q';
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        return [$conds ? 'WHERE ' . implode(' AND ', $conds) : '', $params];
    }
}
