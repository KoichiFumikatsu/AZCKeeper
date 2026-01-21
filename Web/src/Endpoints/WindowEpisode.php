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
 
        // 🔥 VALIDACIÓN: Sanitizar strings potencialmente peligrosos
        $processName = self::sanitizeString($processName, 190);
        $windowTitle = self::sanitizeString($windowTitle, 512);
 
        // Parse timestamps
        $startDt = \DateTime::createFromFormat('Y-m-d H:i:s', $startLocalTime);
        $endDt   = \DateTime::createFromFormat('Y-m-d H:i:s', $endLocalTime);
 
        if (!$startDt || !$endDt) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid datetime format (YYYY-MM-DD HH:MM:SS)']);
        }
 
        // 🔥 VALIDACIÓN: timestamps lógicos
        if ($endDt <= $startDt) {
            Http::json(400, ['ok' => false, 'error' => 'End time must be after start time']);
        }
 
        // 🔥 VALIDACIÓN: duración razonable (máximo 24 horas)
        $duration = max(0, (int)round((float)$durationSec));
        if ($duration > 86400) {
            Http::json(400, ['ok' => false, 'error' => 'Duration too long (max 24h)']);
        }
 
        $dayDate = $startDt->format('Y-m-d');
 
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
 
        // 🔥 PROTECCIÓN: usar try-catch para INSERT
        
        try {
            $st = $pdo->prepare("
                INSERT INTO keeper_window_episode
                  (user_id, device_id, start_at, end_at, duration_seconds,
                   process_name, app_name, window_title, is_in_call, call_app_hint, day_date, created_at)
                VALUES
                  (:uid, :did, :start, :end, :dur, :proc, :app, :title, :call, :hint, :day, NOW())
            ");
         
            $st->execute([
                'uid'   => $userId,
                'did'   => $deviceId,
                'start' => $startDt->format('Y-m-d H:i:s'),
                'end'   => $endDt->format('Y-m-d H:i:s'),
                'dur'   => $duration,
                'proc'  => $processName,
                'app'   => $processName, // Usar mismo valor o NULL
                'title' => $windowTitle,
                'call'  => $isCallApp ? 1 : 0,
                'hint'  => $isCallApp ? $processName : null, // Si es llamada, guardar proceso
                'day'   => $dayDate
            ]);

            Http::json(200, [
                'ok' => true,
                'episodeId' => (int)$pdo->lastInsertId()
            ]);
        } catch (\PDOException $e) {
            error_log("WindowEpisode INSERT error: " . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database insert failed', 'detail' => $e->getMessage()]);
        }
    }
 
    /**
     * Sanitiza y trunca strings para prevenir inyecciones y datos corruptos
     */
    private static function sanitizeString(?string $str, int $maxLen): string
    {
        if ($str === null) return '';
        
        // Remover caracteres de control excepto tabs/newlines
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
        
        // Truncar a longitud máxima (UTF-8 safe)
        if (mb_strlen($str, 'UTF-8') > $maxLen) {
            $str = mb_substr($str, 0, $maxLen, 'UTF-8');
        }
        
        return $str;
    }
}