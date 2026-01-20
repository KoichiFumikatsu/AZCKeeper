<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\AuthService;

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

        $payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $st = $pdo->prepare("
            INSERT INTO keeper_activity_day
              (user_id, device_id, day_date, tz_offset_minutes,
               active_seconds, idle_seconds, call_seconds,
               samples_count, first_event_at, last_event_at, payload_json,
               created_at, updated_at)
            VALUES
              (:uid, :did, :day, :tz, :a, :i, :c, :samples, NOW(), NOW(), :payload, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              tz_offset_minutes = VALUES(tz_offset_minutes),
              active_seconds = GREATEST(active_seconds, VALUES(active_seconds)),
              idle_seconds   = GREATEST(idle_seconds, VALUES(idle_seconds)),
              call_seconds   = GREATEST(call_seconds, VALUES(call_seconds)),
              samples_count  = samples_count + VALUES(samples_count),
              last_event_at  = VALUES(last_event_at),
              payload_json   = VALUES(payload_json),
              updated_at     = NOW()
        ");
         
        $st->execute([
            'uid' => $userId,
            'did' => $deviceId,
            'day' => $dayDate,
            'tz'  => $tzOff,
            'a'   => $active,
            'i'   => $idle,
            'c'   => $call,
            'samples' => $samples,
            'payload' => $payloadJson
        ]);

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
                'samplesCount' => $samples
            ],
            'serverTimeUtc' => Http::nowUtcIso()
        ]);
    }

    // GET /client/activity-day?deviceId=...&dayDate=YYYY-MM-DD
    public static function handleGet(): void
    {
        $sess = AuthService::requireSession();
        $userId = (int)$sess['user_id'];

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
                   samples_count, first_event_at, last_event_at
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
        ]);
    }
}
