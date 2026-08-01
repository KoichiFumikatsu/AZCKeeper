<?php
namespace Keeper\Repos;

use PDO;

/**
 * keeper_client_log — telemetría Warn/Error que el cliente drena al panel. Escritura por
 * POST /client/logs (batch, tope 50/req). Lectura por el panel (pestaña "Log del cliente" en
 * audit.php). Retención por el cron.
 *
 * Diferencia con K3: el esquema K4 usa logged_at (reloj del cliente) + meta_json; sin
 * client_version (la versión ya vive en keeper_devices).
 */
class ClientLogRepo
{
    public const RETENTION_DAYS = 30;
    public const LEVELS  = ['debug', 'info', 'warn', 'error'];
    public const SOURCES = ['network', 'update', 'blocking', 'auth', 'tracking', 'core', 'diag', 'other'];

    /** @param array $rows [['level','source','message','meta'?,'logged_at'?], ...] */
    public static function insertBatch(PDO $pdo, ?int $userId, ?int $deviceId, array $rows): int
    {
        if (!$rows) return 0;
        $ph = []; $params = [];
        foreach ($rows as $k => $r) {
            $ph[] = "(:u{$k}, :d{$k}, :l{$k}, :s{$k}, :m{$k}, :j{$k}, :t{$k})";
            $params["u{$k}"] = $userId;
            $params["d{$k}"] = $deviceId;
            $params["l{$k}"] = $r['level'];
            $params["s{$k}"] = $r['source'];
            $params["m{$k}"] = $r['message'];
            $params["j{$k}"] = isset($r['meta']) && $r['meta'] !== null
                ? json_encode($r['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $params["t{$k}"] = $r['logged_at'] ?? gmdate('Y-m-d H:i:s');
        }
        $st = $pdo->prepare(
            "INSERT INTO keeper_client_log (user_id, device_id, level, source, message, meta_json, logged_at)
             VALUES " . implode(',', $ph));
        $st->execute($params);
        return count($rows);
    }

    /** Búsqueda paginada para el panel. $f: level, source, device_id, q, from, to. */
    public static function search(PDO $pdo, array $f, int $limit, int $offset, ?int $firmaId = null): array
    {
        [$where, $params] = self::buildWhere($f, $firmaId);
        $sql = "SELECT l.id, l.level, l.source, l.message, l.meta_json, l.logged_at, l.created_at,
                       l.device_id, COALESCE(d.label, d.device_name) AS device,
                       l.user_id, u.display_name
                FROM keeper_client_log l
                LEFT JOIN keeper_devices d ON d.id = l.device_id
                LEFT JOIN keeper_users   u ON u.id = l.user_id
                LEFT JOIN keeper_user_assignments a ON a.user_id = l.user_id
                {$where}
                ORDER BY l.id DESC
                LIMIT :lim OFFSET :off";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function count(PDO $pdo, array $f, ?int $firmaId = null): int
    {
        [$where, $params] = self::buildWhere($f, $firmaId);
        $st = $pdo->prepare("SELECT COUNT(*) FROM keeper_client_log l
                             LEFT JOIN keeper_user_assignments a ON a.user_id = l.user_id {$where}");
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    private static function buildWhere(array $f, ?int $firmaId): array
    {
        $w = []; $p = [];
        if (!empty($f['level'])  && in_array($f['level'], self::LEVELS, true))   { $w[] = "l.level = :level";   $p[':level'] = $f['level']; }
        if (!empty($f['source']) && in_array($f['source'], self::SOURCES, true)) { $w[] = "l.source = :source"; $p[':source'] = $f['source']; }
        if (!empty($f['device_id'])) { $w[] = "l.device_id = :did"; $p[':did'] = (int)$f['device_id']; }
        if (!empty($f['q']))    { $w[] = "l.message LIKE :q"; $p[':q'] = '%' . $f['q'] . '%'; }
        if (!empty($f['from'])) { $w[] = "l.logged_at >= :from"; $p[':from'] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to']))   { $w[] = "l.logged_at <= :to";   $p[':to']   = $f['to'] . ' 23:59:59'; }
        if ($firmaId !== null)  { $w[] = "(l.user_id IS NULL OR a.firma_id = " . (int)$firmaId . ")"; }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }

    /** Retención: borra lo más viejo que RETENTION_DAYS. Devuelve filas borradas. */
    public static function purge(PDO $pdo): int
    {
        $st = $pdo->prepare("DELETE FROM keeper_client_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL :d DAY");
        $st->execute([':d' => self::RETENTION_DAYS]);
        return $st->rowCount();
    }
}
