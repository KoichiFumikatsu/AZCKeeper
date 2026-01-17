<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\AuthService;

class ActivityDayGet
{
    public static function handle(): void
    {
        $sess = AuthService::requireSession();
        $userId = (int)$sess['user_id'];

        $data = Http::jsonInput();
        $deviceGuid = $data['deviceId'] ?? null;
        $dateStr    = $data['date'] ?? null;

        if (!$deviceGuid || !$dateStr) {
            Http::json(400, ['ok'=>false,'error'=>'Missing fields (deviceId,date)']);
        }

        $day = \DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$day) Http::json(400, ['ok'=>false,'error'=>'Invalid date format (YYYY-MM-DD)']);
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
            SELECT
              day_date,
              tz_offset_minutes,
              active_seconds,
              idle_seconds,
              call_seconds,
              samples_count,
              first_event_at,
              last_event_at
            FROM keeper_activity_day
            WHERE user_id = :uid AND device_id = :did AND day_date = :day
            LIMIT 1
        ");
        $st->execute(['uid'=>$userId,'did'=>$deviceId,'day'=>$dayDate]);
        $row = $st->fetch();

        if (!$row) {
            Http::json(200, [
                'ok' => true,
                'found' => false,
                'day' => $dayDate,
                'activeSeconds' => 0,
                'idleSeconds' => 0,
                'callSeconds' => 0,
                'tzOffsetMinutes' => -300,
                'samplesCount' => 0
            ]);
        }

        Http::json(200, [
            'ok' => true,
            'found' => true,
            'day' => $row['day_date'],
            'activeSeconds' => (int)$row['active_seconds'],
            'idleSeconds' => (int)$row['idle_seconds'],
            'callSeconds' => (int)$row['call_seconds'],
            'tzOffsetMinutes' => (int)$row['tz_offset_minutes'],
            'samplesCount' => (int)$row['samples_count'],
            'firstEventAt' => $row['first_event_at'],
            'lastEventAt' => $row['last_event_at']
        ]);
    }
}
