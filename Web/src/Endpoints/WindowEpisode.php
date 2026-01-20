<?php
namespace Keeper\Endpoints;
 
use Keeper\Http;
use Keeper\Db;
use Keeper\AuthService;
 
class WindowEpisode
{
    public static function handle(): void
    {
        $sess = AuthService::requireSession();
        $userId = (int)$sess['user_id'];
 
        $data = Http::jsonInput();
 
        // Cliente serializa camelCase + fallback PascalCase
        $deviceGuid     = $data['deviceId']        ?? ($data['DeviceId']        ?? null);
        $startLocalTime = $data['startLocalTime']  ?? ($data['StartLocalTime']  ?? null);
        $endLocalTime   = $data['endLocalTime']    ?? ($data['EndLocalTime']    ?? null);
        $durationSec    = $data['durationSeconds'] ?? ($data['DurationSeconds'] ?? null);
        $processName    = $data['processName']     ?? ($data['ProcessName']     ?? null);
        $windowTitle    = $data['windowTitle']     ?? ($data['WindowTitle']     ?? null);
        $isCallApp      = $data['isCallApp']       ?? ($data['IsCallApp']       ?? false);
 
        if (!$deviceGuid || !$startLocalTime || !$endLocalTime || $durationSec === null) {
            Http::json(400, ['ok' => false, 'error' => 'Missing required fields']);
        }
 
        // Parse timestamps (cliente envía "yyyy-MM-dd HH:mm:ss")
        $startDt = \DateTime::createFromFormat('Y-m-d H:i:s', $startLocalTime);
        $endDt   = \DateTime::createFromFormat('Y-m-d H:i:s', $endLocalTime);
 
        if (!$startDt || !$endDt) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid datetime format (YYYY-MM-DD HH:MM:SS)']);
        }
 
        $dayDate = $startDt->format('Y-m-d');
        $duration = max(0, (int)round((float)$durationSec));
 
        $pdo = Db::pdo();
 
        // Resolver device_id
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
 
        // Insertar episodio
        $st = $pdo->prepare("
            INSERT INTO keeper_window_episode
              (user_id, device_id, start_at, end_at, duration_seconds,
               process_name, window_title, is_in_call, day_date, created_at)
            VALUES
              (:uid, :did, :start, :end, :dur,
               :proc, :title, :call, :day, NOW())
        ");
 
        $st->execute([
            'uid'   => $userId,
            'did'   => $deviceId,
            'start' => $startDt->format('Y-m-d H:i:s'),
            'end'   => $endDt->format('Y-m-d H:i:s'),
            'dur'   => $duration,
            'proc'  => substr($processName ?? '', 0, 190),
            'title' => substr($windowTitle ?? '', 0, 512),
            'call'  => $isCallApp ? 1 : 0,
            'day'   => $dayDate
        ]);
 
        Http::json(200, [
            'ok' => true,
            'episodeId' => (int)$pdo->lastInsertId()
        ]);
    }
}