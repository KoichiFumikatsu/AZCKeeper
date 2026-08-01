<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\ClientLogRepo;

/**
 * Ingesta de logs cliente→panel: el cliente drena sus Warn/Error para que IT los vea sin
 * entrar al equipo. Auth por bearer + device de la sesión. Tope 50/req. Redacta secretos:
 * estas filas se renderizan en el panel, y un token filtrado en un mensaje quedaría expuesto.
 */
class ClientLogBatch
{
    private const MAX = 50;

    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $deviceGuid = $body['deviceId'] ?? ($body['DeviceId'] ?? null);
        if (!$deviceGuid || !preg_match('/^[0-9a-fA-F-]{36}$/', $deviceGuid)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid or missing DeviceId']);
        }
        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        $userId = (int)$sess['user_id'];

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(403, ['ok' => false, 'error' => 'Device not enrolled']);
        if ((int)$dev['user_id'] !== $userId) Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        $deviceId = (int)$dev['id'];

        $entries = $body['entries'] ?? ($body['Entries'] ?? null);
        if (!is_array($entries)) Http::json(400, ['ok' => false, 'error' => 'Missing entries']);

        $rows = [];
        foreach (array_slice($entries, 0, self::MAX) as $e) {
            if (!is_array($e)) continue;
            $level  = strtolower((string)($e['level']  ?? ($e['Level']  ?? 'warn')));
            $source = strtolower((string)($e['source'] ?? ($e['Source'] ?? 'other')));
            $msg    = (string)($e['message'] ?? ($e['Message'] ?? ''));
            if ($msg === '') continue;
            if (!in_array($level, ClientLogRepo::LEVELS, true))   $level = 'warn';
            if (!in_array($source, ClientLogRepo::SOURCES, true)) $source = 'other';
            $rows[] = [
                'level'     => $level,
                'source'    => $source,
                'message'   => mb_substr(self::redact($msg), 0, 1024, 'UTF-8'),
                'logged_at' => self::normalizeTs($e['ts'] ?? ($e['Ts'] ?? null)),
            ];
        }

        $n = 0;
        if ($rows) { try { $n = ClientLogRepo::insertBatch($pdo, $userId, $deviceId, $rows); } catch (\Throwable $ex) { error_log('client-logs: ' . $ex->getMessage()); } }
        Http::json(200, ['ok' => true, 'stored' => $n]);
    }

    /** Quita caracteres de control y redacta secretos evidentes (tokens, passwords, keys). */
    private static function redact(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $s);
        $s = preg_replace('/([a-f0-9]{40,})/i', '[redacted]', $s);            // tokens/hashes largos
        $s = preg_replace('/(password|pass|token|apikey|api_key|secret)\s*[=:]\s*\S+/i', '$1=[redacted]', $s);
        return $s;
    }

    private static function normalizeTs($ts): string
    {
        if (is_string($ts) && $ts !== '' && ($t = strtotime($ts)) !== false) return gmdate('Y-m-d H:i:s', $t);
        return gmdate('Y-m-d H:i:s');
    }
}
