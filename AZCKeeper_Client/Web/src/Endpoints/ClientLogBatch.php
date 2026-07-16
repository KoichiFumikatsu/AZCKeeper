<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\AuthService;
use Keeper\Repos\ClientLogRepo;

/**
 * ClientLogBatch — ingesta de logs del cliente Windows (Warn/Error + eventos de update).
 *
 * Payload:
 *   {
 *     "deviceId": "<guid>",
 *     "clientVersion": "3.0.2.9",
 *     "logs": [
 *       { "level": "warn", "source": "network", "message": "...",
 *         "clientTs": "2026-07-16 10:31:02", "meta": { ... } },
 *       ...
 *     ]
 *   }
 *
 * Tope de 50 por request. El cliente drena su buffer solo cuando este endpoint
 * confirma 200, de modo que cada línea se persiste una vez.
 *
 * El cliente ya sanitiza secretos antes de enviar (LocalLogger::Sanitize), pero aquí
 * se vuelve a redactar: estas filas terminan renderizadas en el panel, y un token que
 * se cuele queda expuesto a cualquiera con acceso a /admin/logs.php.
 */
class ClientLogBatch
{
    private const MAX_LOGS_PER_BATCH = 50;

    public static function handle(): void
    {
        $sess = AuthService::requireSession();
        $userId = (int)$sess['user_id'];

        $data = Http::jsonInput();

        $deviceGuid    = $data['deviceId']      ?? ($data['DeviceId']      ?? null);
        $clientVersion = $data['clientVersion'] ?? ($data['ClientVersion'] ?? null);
        $logs          = $data['logs']          ?? ($data['Logs']          ?? null);

        if (!$deviceGuid) {
            Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        }
        if (!is_array($logs) || count($logs) === 0) {
            Http::json(400, ['ok' => false, 'error' => 'Missing or empty logs array']);
        }
        if (count($logs) > self::MAX_LOGS_PER_BATCH) {
            Http::json(413, ['ok' => false, 'error' => 'Too many logs (max ' . self::MAX_LOGS_PER_BATCH . ')']);
        }

        $pdo = Db::pdo();

        $st = $pdo->prepare("SELECT id, user_id, status FROM keeper_devices WHERE device_guid = :g LIMIT 1");
        $st->execute(['g' => $deviceGuid]);
        $dev = $st->fetch();

        if (!$dev) {
            Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        }
        if ((int)$dev['user_id'] !== $userId) {
            Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        }
        if (($dev['status'] ?? 'active') !== 'active') {
            Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        }

        $deviceId = (int)$dev['id'];
        $rows = [];
        $skipped = 0;

        foreach ($logs as $entry) {
            if (!is_array($entry)) { $skipped++; continue; }

            $message = $entry['message'] ?? ($entry['Message'] ?? null);
            if (!$message || trim($message) === '') { $skipped++; continue; }

            $level  = strtolower((string)($entry['level']  ?? ($entry['Level']  ?? 'warn')));
            $source = strtolower((string)($entry['source'] ?? ($entry['Source'] ?? 'other')));

            if (!in_array($level, ClientLogRepo::LEVELS, true))   $level = 'warn';
            if (!in_array($source, ClientLogRepo::SOURCES, true)) $source = 'other';

            $rows[] = [
                'level'     => $level,
                'source'    => $source,
                'message'   => self::sanitize($message, 512),
                'meta'      => isset($entry['meta']) && is_array($entry['meta']) ? $entry['meta'] : null,
                'client_ts' => self::parseClientTs($entry['clientTs'] ?? ($entry['ClientTs'] ?? null)),
            ];
        }

        if (count($rows) === 0) {
            Http::json(400, ['ok' => false, 'error' => 'No valid logs in batch', 'skipped' => $skipped]);
        }

        try {
            $inserted = ClientLogRepo::insertBatch(
                $pdo, $userId, $deviceId, self::sanitize($clientVersion, 20), $rows
            );

            Http::json(200, ['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
        } catch (\PDOException $e) {
            error_log("ClientLogBatch INSERT error: " . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database insert failed']);
        }
    }

    /** Quita caracteres de control, redacta secretos y trunca. */
    private static function sanitize(?string $str, int $maxLen): ?string
    {
        if ($str === null) return null;

        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);

        $str = preg_replace('/\bBearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', 'Bearer ***REDACTED***', $str);
        $str = preg_replace('/\b(authToken|token|apiAuthToken|password)\b\s*[:=]\s*[^\s,;]+/i', '$1=***REDACTED***', $str);
        $str = preg_replace('#https?://(canary\.)?discord(app)?\.com/api/webhooks/\S+#i', '***DISCORD_WEBHOOK_REDACTED***', $str);

        if (mb_strlen($str, 'UTF-8') > $maxLen) {
            $str = mb_substr($str, 0, $maxLen, 'UTF-8');
        }

        return $str;
    }

    /** El reloj del cliente puede estar desfasado; se guarda tal cual y se ordena por created_at. */
    private static function parseClientTs($raw): ?string
    {
        if (!$raw || !is_string($raw)) return null;

        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $raw);

        return $dt ? $dt->format('Y-m-d H:i:s') : null;
    }
}
