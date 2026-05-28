<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\AuthService;

/**
 * WindowEpisodeBatch — versión masiva de WindowEpisode.
 *
 * Payload esperado:
 *   {
 *     "deviceId": "<guid>",
 *     "episodes": [
 *       { "startLocalTime": "...", "endLocalTime": "...", "durationSeconds": n,
 *         "processName": "...", "windowTitle": "...", "isCallApp": bool },
 *       ...
 *     ]
 *   }
 *
 * Inserta hasta 50 episodios por request en una sola sentencia INSERT multi-row
 * dentro de una transacción. Devuelve el número de episodios aceptados y un
 * arreglo de errores por posición (si alguno falla la validación individual).
 *
 * El endpoint single /client/window-episode sigue existiendo sin cambios para
 * compatibilidad con clientes antiguos.
 */
class WindowEpisodeBatch
{
    private const MAX_EPISODES_PER_BATCH = 50;

    public static function handle(): void
    {
        $sess = AuthService::requireSession();
        $userId = (int)$sess['user_id'];

        $data = Http::jsonInput();

        $deviceGuid = $data['deviceId'] ?? ($data['DeviceId'] ?? null);
        $episodes   = $data['episodes'] ?? ($data['Episodes'] ?? null);

        if (!$deviceGuid) {
            Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        }
        if (!is_array($episodes) || count($episodes) === 0) {
            Http::json(400, ['ok' => false, 'error' => 'Missing or empty episodes array']);
        }
        if (count($episodes) > self::MAX_EPISODES_PER_BATCH) {
            Http::json(413, ['ok' => false, 'error' => 'Too many episodes (max ' . self::MAX_EPISODES_PER_BATCH . ')']);
        }

        $pdo = Db::pdo();

        // Resolver device una sola vez
        $st = $pdo->prepare("SELECT id, user_id, status FROM keeper_devices WHERE device_guid = :g LIMIT 1");
        $st->execute(['g' => $deviceGuid]);
        $dev = $st->fetch();

        if (!$dev) {
            Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        }

        $deviceId = (int)$dev['id'];

        if ((int)$dev['user_id'] !== $userId) {
            Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        }
        if (($dev['status'] ?? 'active') !== 'active') {
            Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        }

        // Validar cada episodio individualmente; acumular los válidos
        $rows = [];
        $errors = [];

        foreach ($episodes as $i => $ep) {
            if (!is_array($ep)) {
                $errors[] = ['index' => $i, 'error' => 'Not an object'];
                continue;
            }

            $startLocalTime = $ep['startLocalTime']  ?? ($ep['StartLocalTime']  ?? null);
            $endLocalTime   = $ep['endLocalTime']    ?? ($ep['EndLocalTime']    ?? null);
            $durationSec    = $ep['durationSeconds'] ?? ($ep['DurationSeconds'] ?? null);
            $processName    = $ep['processName']     ?? ($ep['ProcessName']     ?? null);
            $windowTitle    = $ep['windowTitle']     ?? ($ep['WindowTitle']     ?? null);
            $isCallApp      = $ep['isCallApp']       ?? ($ep['IsCallApp']       ?? false);

            if (!$startLocalTime || !$endLocalTime || $durationSec === null) {
                $errors[] = ['index' => $i, 'error' => 'Missing required fields'];
                continue;
            }

            $startDt = \DateTime::createFromFormat('Y-m-d H:i:s', $startLocalTime);
            $endDt   = \DateTime::createFromFormat('Y-m-d H:i:s', $endLocalTime);

            if (!$startDt || !$endDt) {
                $errors[] = ['index' => $i, 'error' => 'Invalid datetime format'];
                continue;
            }

            if ($endDt <= $startDt) {
                $errors[] = ['index' => $i, 'error' => 'End time must be after start time'];
                continue;
            }

            $duration = max(0, (int)round((float)$durationSec));
            if ($duration > 86400) {
                $errors[] = ['index' => $i, 'error' => 'Duration too long (max 24h)'];
                continue;
            }

            $processName = self::sanitizeString($processName, 190);
            $windowTitle = self::sanitizeString($windowTitle, 512);

            $rows[] = [
                'uid'   => $userId,
                'did'   => $deviceId,
                'start' => $startDt->format('Y-m-d H:i:s'),
                'end'   => $endDt->format('Y-m-d H:i:s'),
                'dur'   => $duration,
                'proc'  => $processName,
                'app'   => $processName,
                'title' => $windowTitle,
                'call'  => $isCallApp ? 1 : 0,
                'hint'  => $isCallApp ? $processName : null,
                'day'   => $startDt->format('Y-m-d'),
            ];
        }

        if (count($rows) === 0) {
            Http::json(400, [
                'ok' => false,
                'error' => 'No valid episodes in batch',
                'errors' => $errors,
            ]);
        }

        // INSERT multi-row en una sola sentencia dentro de transacción
        $placeholders = [];
        $params = [];
        foreach ($rows as $k => $r) {
            $placeholders[] = "(:uid{$k}, :did{$k}, :start{$k}, :end{$k}, :dur{$k}, :proc{$k}, :app{$k}, :title{$k}, :call{$k}, :hint{$k}, :day{$k}, NOW())";
            foreach ($r as $col => $val) {
                $params["{$col}{$k}"] = $val;
            }
        }

        $sql = "INSERT INTO keeper_window_episode
                  (user_id, device_id, start_at, end_at, duration_seconds,
                   process_name, app_name, window_title, is_in_call, call_app_hint, day_date, created_at)
                VALUES " . implode(',', $placeholders);

        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $firstId = (int)$pdo->lastInsertId();
            $pdo->commit();

            Http::json(200, [
                'ok'       => true,
                'inserted' => count($rows),
                'skipped'  => count($errors),
                'errors'   => $errors,
                'firstId'  => $firstId,
            ]);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("WindowEpisodeBatch INSERT error: " . $e->getMessage());
            Http::json(500, [
                'ok'    => false,
                'error' => 'Database insert failed',
                'detail' => $e->getMessage(),
            ]);
        }
    }

    private static function sanitizeString(?string $str, int $maxLen): string
    {
        if ($str === null) return '';
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
        if (mb_strlen($str, 'UTF-8') > $maxLen) {
            $str = mb_substr($str, 0, $maxLen, 'UTF-8');
        }
        return $str;
    }
}
