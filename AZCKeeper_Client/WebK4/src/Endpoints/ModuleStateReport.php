<?php
namespace Keeper\Endpoints;

use PDO;
use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\ModuleStateRepo;

class ModuleStateReport
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
        $modules    = $body['modules'] ?? null;
        if (!$deviceGuid) Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        if (!is_array($modules) || !$modules) Http::json(400, ['ok' => false, 'error' => 'Missing modules']);
        if (count($modules) > self::MAX) Http::json(413, ['ok' => false, 'error' => 'Too many modules (max ' . self::MAX . ')']);

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        if ((int)$dev['user_id'] !== $userId) Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        if (($dev['status'] ?? 'active') !== 'active') Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        $deviceId = (int)$dev['id'];

        $rows = []; $errors = [];
        foreach ($modules as $i => $m) {
            if (!is_array($m)) { $errors[] = ['index' => $i, 'error' => 'Not an object']; continue; }
            $code    = $m['code']    ?? null;
            $running = $m['running'] ?? null;
            $detail  = $m['detail']  ?? null;

            if (!is_string($code) || trim($code) === '') { $errors[] = ['index' => $i, 'error' => 'Missing or invalid code']; continue; }
            if (mb_strlen($code, 'UTF-8') > 100) { $errors[] = ['index' => $i, 'error' => 'Code too long']; continue; }
            if (!is_bool($running)) { $errors[] = ['index' => $i, 'error' => 'Missing or invalid running']; continue; }
            if ($detail !== null && !is_array($detail)) { $errors[] = ['index' => $i, 'error' => 'Invalid detail']; continue; }

            $rows[] = [
                'code'    => $code,
                'running' => $running,
                'detail'  => $detail,
            ];
        }

        if (!$rows) Http::json(400, ['ok' => false, 'error' => 'No valid modules', 'errors' => $errors]);

        try {
            $n = ModuleStateRepo::report($pdo, $deviceId, $rows);
            Http::json(200, ['ok' => true, 'reported' => $n, 'skipped' => count($errors), 'errors' => $errors]);
        } catch (\PDOException $e) {
            error_log('ModuleStateReport upsert error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database upsert failed']);
        }
    }
}
