<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\EpisodeRepo;

class EpisodeBatch
{
    private const MAX = 50;

    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        $userId = (int)$sess['user_id'];

        $deviceGuid = $body['deviceId'] ?? null;
        $episodes   = $body['episodes'] ?? null;
        if (!$deviceGuid) Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        if (!is_array($episodes) || !$episodes) Http::json(400, ['ok' => false, 'error' => 'Missing episodes']);
        if (count($episodes) > self::MAX) Http::json(413, ['ok' => false, 'error' => 'Too many episodes (max ' . self::MAX . ')']);

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        if ((int)$dev['user_id'] !== $userId) Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        if (($dev['status'] ?? 'active') !== 'active') Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        $deviceId = (int)$dev['id'];

        $rows = []; $errors = [];
        foreach ($episodes as $i => $ep) {
            if (!is_array($ep)) { $errors[] = ['index' => $i, 'error' => 'Not an object']; continue; }
            $start = $ep['startLocalTime'] ?? null;
            $end   = $ep['endLocalTime']   ?? null;
            $dur   = $ep['durationSeconds'] ?? null;
            $proc  = $ep['processName']    ?? null;
            $title = $ep['windowTitle']    ?? null;
            $call  = !empty($ep['isCallApp']);

            if (!$start || !$end || $dur === null || !$proc) { $errors[] = ['index' => $i, 'error' => 'Missing required fields']; continue; }
            $sd = \DateTime::createFromFormat('Y-m-d H:i:s', $start);
            $ed = \DateTime::createFromFormat('Y-m-d H:i:s', $end);
            if (!$sd || !$ed || $ed <= $sd) { $errors[] = ['index' => $i, 'error' => 'Invalid datetimes']; continue; }
            $d = max(0, (int)round((float)$dur));
            if ($d > 86400) { $errors[] = ['index' => $i, 'error' => 'Duration too long']; continue; }

            $rows[] = [
                'user_id'          => $userId,
                'device_id'        => $deviceId,
                'day_date'         => $sd->format('Y-m-d'),
                'start_at'         => $sd->format('Y-m-d H:i:s'),
                'end_at'           => $ed->format('Y-m-d H:i:s'),
                'duration_seconds' => $d,
                'process_name'     => mb_substr($proc, 0, 190, 'UTF-8'),
                'window_title'     => $title !== null ? mb_substr($title, 0, 512, 'UTF-8') : null,
                'is_in_call'       => $call ? 1 : 0,
            ];
        }

        if (!$rows) Http::json(400, ['ok' => false, 'error' => 'No valid episodes', 'errors' => $errors]);

        try {
            $n = EpisodeRepo::insertBatch($pdo, $rows);
            Http::json(200, ['ok' => true, 'inserted' => $n, 'skipped' => count($errors), 'errors' => $errors]);
        } catch (\PDOException $e) {
            error_log('EpisodeBatch insert error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database insert failed']);
        }
    }
}
