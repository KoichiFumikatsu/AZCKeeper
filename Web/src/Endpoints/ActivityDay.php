<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\AuthService;
use RateLimiter;

class ActivityDay
{
    public static function handle(): void
    {
        $sess = AuthService::requireSession();
        $userId = (int)$sess['user_id'];
     
        $data = Http::jsonInput();
     
        // Cliente C# serializa camelCase (mantengo fallback PascalCase)
        $deviceGuid = $data['deviceId'] ?? ($data['DeviceId'] ?? null);
     
        // FIX: dayDate (cliente) + fallback
        $dateStr =
            $data['dayDate'] ?? ($data['DayDate'] ?? (
            $data['date'] ?? ($data['Date'] ?? null)
        ));
     
        $active = $data['activeSeconds'] ?? ($data['ActiveSeconds'] ?? null);
        $idle   = $data['idleSeconds']   ?? ($data['IdleSeconds'] ?? null);
        $call   = $data['callSeconds']   ?? ($data['CallSeconds'] ?? null);
     
        $tzOff   = $data['tzOffsetMinutes'] ?? ($data['TzOffsetMinutes'] ?? null);
        $samples = $data['samplesCount']    ?? ($data['SamplesCount'] ?? null);
     
        // ==================== NUEVAS COLUMNAS DE CATEGORIZACIÓN ====================
        $workActive = $data['workHoursActiveSeconds'] ?? ($data['WorkHoursActiveSeconds'] ?? 0);
        $workIdle = $data['workHoursIdleSeconds'] ?? ($data['WorkHoursIdleSeconds'] ?? 0);
        $lunchActive = $data['lunchActiveSeconds'] ?? ($data['LunchActiveSeconds'] ?? 0);
        $lunchIdle = $data['lunchIdleSeconds'] ?? ($data['LunchIdleSeconds'] ?? 0);
        $afterActive = $data['afterHoursActiveSeconds'] ?? ($data['AfterHoursActiveSeconds'] ?? 0);
        $afterIdle = $data['afterHoursIdleSeconds'] ?? ($data['AfterHoursIdleSeconds'] ?? 0);
        
        // Día laborable: lunes-viernes = 1, sábado-domingo = 0
        $isWorkday = isset($data['isWorkday']) ? ($data['isWorkday'] ? 1 : 0) : 
                     (isset($data['IsWorkday']) ? ($data['IsWorkday'] ? 1 : 0) : 1); // default=1 para compatibilidad
     
        // Compat: si el cliente manda InactiveSeconds
        if ($idle === null) {
            $idle = $data['inactiveSeconds'] ?? ($data['InactiveSeconds'] ?? null);
        }
     
        if (!$deviceGuid || !$dateStr || $active === null || $idle === null) {
            Http::json(400, ['ok'=>false,'error'=>'Missing fields (deviceId,dayDate,activeSeconds,idleSeconds)']);
        }
     
        $active = max(0, (int)round((float)$active));
        $idle   = max(0, (int)round((float)$idle));
        $call   = max(0, (int)round((float)($call ?? 0)));
        
        // Sanitizar categorías
        $workActive = max(0, (int)round((float)$workActive));
        $workIdle = max(0, (int)round((float)$workIdle));
        $lunchActive = max(0, (int)round((float)$lunchActive));
        $lunchIdle = max(0, (int)round((float)$lunchIdle));
        $afterActive = max(0, (int)round((float)$afterActive));
        $afterIdle = max(0, (int)round((float)$afterIdle));
     
        $tzOff = ($tzOff === null) ? -300 : (int)$tzOff;
        $samples = ($samples === null) ? 1 : max(1, (int)$samples);
     
        $day = \DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$day) Http::json(400, ['ok'=>false,'error'=>'Invalid date format (YYYY-MM-DD)']);
        $dayDate = $day->format('Y-m-d');
     
        $pdo = Db::pdo();
     
        // Resolver device_id real desde device_guid
        $st = $pdo->prepare("SELECT id, user_id, status FROM keeper_devices WHERE device_guid = :g LIMIT 1");
        $st->execute(['g' => (string)$deviceGuid]);
        $dev = $st->fetch();
        if (!$dev) Http::json(404, ['ok'=>false,'error'=>'Device not found']);
     
        $deviceId = (int)$dev['id'];
     
        if ((int)$dev['user_id'] !== $userId) {
            Http::json(403, ['ok'=>false,'error'=>'Device does not belong to session user']);
        }
        if (($dev['status'] ?? 'active') !== 'active') {
            Http::json(403, ['ok'=>false,'error'=>'Device revoked']);
        }
     
        // Validar y sanitizar JSON
        $payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
         
        if ($payloadJson === false || json_last_error() !== JSON_ERROR_NONE) {
            Http::json(500, ['ok'=>false,'error'=>'Failed to encode payload JSON: ' . json_last_error_msg()]);
        }
         
        // Rechazar payloads excesivamente grandes (límite razonable: 16KB)
        if (strlen($payloadJson) > 16384) {
            error_log("ActivityDay: payload excede 16KB (" . strlen($payloadJson) . " bytes). Rechazando.");
            Http::json(413, ['ok'=>false,'error'=>'Payload too large (max 16KB)']);
        }
         
        $st = $pdo->prepare("
            INSERT INTO keeper_activity_day
              (user_id, device_id, day_date, tz_offset_minutes,
               active_seconds, idle_seconds, call_seconds,
               samples_count, first_event_at, last_event_at, payload_json,
               work_hours_active_seconds, work_hours_idle_seconds,
               lunch_active_seconds, lunch_idle_seconds,
               after_hours_active_seconds, after_hours_idle_seconds,
               is_workday,
               created_at, updated_at)
            VALUES
              (:uid, :did, :day, :tz, :a, :i, :c, :samples, NOW(), NOW(), :payload,
               :work_a, :work_i, :lunch_a, :lunch_i, :after_a, :after_i,
               :is_workday,
               NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              tz_offset_minutes = VALUES(tz_offset_minutes),
              active_seconds = GREATEST(active_seconds, VALUES(active_seconds)),
              idle_seconds   = GREATEST(idle_seconds, VALUES(idle_seconds)),
              call_seconds   = GREATEST(call_seconds, VALUES(call_seconds)),
              samples_count  = samples_count + VALUES(samples_count),
              last_event_at  = VALUES(last_event_at),
              payload_json   = VALUES(payload_json),
              work_hours_active_seconds = GREATEST(work_hours_active_seconds, VALUES(work_hours_active_seconds)),
              work_hours_idle_seconds = GREATEST(work_hours_idle_seconds, VALUES(work_hours_idle_seconds)),
              lunch_active_seconds = GREATEST(lunch_active_seconds, VALUES(lunch_active_seconds)),
              lunch_idle_seconds = GREATEST(lunch_idle_seconds, VALUES(lunch_idle_seconds)),
              after_hours_active_seconds = GREATEST(after_hours_active_seconds, VALUES(after_hours_active_seconds)),
              after_hours_idle_seconds = GREATEST(after_hours_idle_seconds, VALUES(after_hours_idle_seconds)),
              is_workday = VALUES(is_workday),
              updated_at     = NOW()
        ");
         
        try {
            $st->execute([
                'uid' => $userId,
                'did' => $deviceId,
                'day' => $dayDate,
                'tz'  => $tzOff,
                'a'   => $active,
                'i'   => $idle,
                'c'   => $call,
                'samples' => $samples,
                'payload' => $payloadJson,
                'work_a' => $workActive,
                'work_i' => $workIdle,
                'lunch_a' => $lunchActive,
                'lunch_i' => $lunchIdle,
                'after_a' => $afterActive,
                'after_i' => $afterIdle,
                'is_workday' => $isWorkday
            ]);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'Invalid JSON') !== false) {
                Http::json(500, ['ok'=>false,'error'=>'MySQL rejected JSON payload']);
            }
            throw $e;
        }
        
        Http::json(200, [
            'ok' => true,
            'userId' => $userId,
            'deviceId' => $deviceId,
            'dayDate' => $dayDate,
            'stored' => [
                'activeSeconds' => $active,
                'idleSeconds' => $idle,
                'callSeconds' => $call,
                'tzOffsetMinutes' => $tzOff,
                'samplesCount' => $samples,
                'workHoursActive' => $workActive,
                'workHoursIdle' => $workIdle,
                'lunchActive' => $lunchActive,
                'lunchIdle' => $lunchIdle,
                'afterHoursActive' => $afterActive,
                'afterHoursIdle' => $afterIdle
            ],
            'serverTimeUtc' => Http::nowUtcIso()
        ]);
    }

        // GET /client/activity-day?deviceId=...&dayDate=YYYY-MM-DD
    public static function handleGet(): void
    {
        $sess = AuthService::requireSession();
        $userId = (int)$sess['user_id'];

        // Rate limiting: 30 peticiones por minuto para prevenir scraping masivo
        if (!RateLimiter::allow($userId, 'activity-day-get', 30, 60)) {
            Http::json(429, ['ok' => false, 'error' => 'Rate limit exceeded. Maximum 30 requests per minute.']);
            return;
        }
     
        $deviceGuid = $_GET['deviceId'] ?? ($_GET['DeviceId'] ?? null);
        $dayDate    = $_GET['dayDate']  ?? ($_GET['DayDate']  ?? null);
     
        if (!$deviceGuid || !$dayDate) {
            Http::json(400, ['ok'=>false,'error'=>'Missing query params (deviceId,dayDate)']);
        }
     
        $day = \DateTime::createFromFormat('Y-m-d', $dayDate);
        if (!$day) Http::json(400, ['ok'=>false,'error'=>'Invalid dayDate (YYYY-MM-DD)']);
        $dayDate = $day->format('Y-m-d');
     
        $pdo = Db::pdo();
     
        $st = $pdo->prepare("SELECT id, user_id, status FROM keeper_devices WHERE device_guid = :g LIMIT 1");
        $st->execute(['g' => (string)$deviceGuid]);
        $dev = $st->fetch();
        if (!$dev) Http::json(404, ['ok'=>false,'error'=>'Device not found']);
     
        if ((int)$dev['user_id'] !== $userId) {
            Http::json(403, ['ok'=>false,'error'=>'Device does not belong to session user']);
        }
        if (($dev['status'] ?? 'active') !== 'active') {
            Http::json(403, ['ok'=>false,'error'=>'Device revoked']);
        }
     
        $deviceId = (int)$dev['id'];
     
        $st = $pdo->prepare("
            SELECT day_date, tz_offset_minutes, active_seconds, idle_seconds, call_seconds,
                   samples_count, first_event_at, last_event_at,
                   work_hours_active_seconds, work_hours_idle_seconds,
                   lunch_active_seconds, lunch_idle_seconds,
                   after_hours_active_seconds, after_hours_idle_seconds,
                   is_workday
            FROM keeper_activity_day
            WHERE user_id = :u AND device_id = :d AND day_date = :day
            LIMIT 1
        ");
        $st->execute(['u'=>$userId,'d'=>$deviceId,'day'=>$dayDate]);
        $row = $st->fetch();
     
        if (!$row) {
            Http::json(200, ['ok'=>true,'found'=>false,'dayDate'=>$dayDate]);
        }
     
        Http::json(200, [
            'ok' => true,
            'found' => true,
            'dayDate' => $row['day_date'],
            'tzOffsetMinutes' => (int)$row['tz_offset_minutes'],
            'activeSeconds' => (int)$row['active_seconds'],
            'idleSeconds' => (int)$row['idle_seconds'],
            'callSeconds' => (int)$row['call_seconds'],
            'samplesCount' => (int)$row['samples_count'],
            'firstEventAt' => $row['first_event_at'],
            'lastEventAt' => $row['last_event_at'],
            'workHoursActiveSeconds' => (int)($row['work_hours_active_seconds'] ?? 0),
            'workHoursIdleSeconds' => (int)($row['work_hours_idle_seconds'] ?? 0),
            'lunchActiveSeconds' => (int)($row['lunch_active_seconds'] ?? 0),
            'lunchIdleSeconds' => (int)($row['lunch_idle_seconds'] ?? 0),
            'afterHoursActiveSeconds' => (int)($row['after_hours_active_seconds'] ?? 0),
            'afterHoursIdleSeconds' => (int)($row['after_hours_idle_seconds'] ?? 0),
            'isWorkday' => (bool)($row['is_workday'] ?? true),
        ]);
    }
}
