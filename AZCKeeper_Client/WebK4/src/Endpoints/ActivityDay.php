<?php
namespace Keeper\Endpoints;

use PDO;
use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\DaySummaryRepo;

/**
 * Ingesta del resumen diario de actividad. El cliente manda el TOTAL del dia
 * (no un delta); el repo ASIGNA cada columna via ON DUPLICATE KEY UPDATE
 * col = VALUES(col), nunca acumula. Reenviar el mismo total es inocuo gracias
 * a la clave unica (user_id, device_id, day_date).
 */
class ActivityDay
{
    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        $userId = (int)$sess['user_id'];

        $deviceGuid = $body['deviceId'] ?? ($body['DeviceId'] ?? null);
        $dayDate    = $body['dayDate']  ?? ($body['DayDate']  ?? null);

        if (!$deviceGuid) Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        if (!$dayDate)    Http::json(400, ['ok' => false, 'error' => 'Missing dayDate']);

        $dd = \DateTime::createFromFormat('Y-m-d', $dayDate);
        if (!$dd || $dd->format('Y-m-d') !== $dayDate) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid dayDate (expected Y-m-d)']);
        }

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        if ((int)$dev['user_id'] !== $userId) Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        if (($dev['status'] ?? 'active') !== 'active') Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        $deviceId = (int)$dev['id'];

        $pick = static function (array $b, string $camel, string $pascal) {
            return $b[$camel] ?? ($b[$pascal] ?? null);
        };
        $sec = static function ($v): int {
            return max(0, (int)($v ?? 0));
        };
        $flag = static function ($v): int {
            return !empty($v) ? 1 : 0;
        };

        $tzOffsetRaw = $pick($body, 'tzOffsetMinutes', 'TzOffsetMinutes');
        $tzOffsetMinutes = is_numeric($tzOffsetRaw) ? (int)$tzOffsetRaw : -300; // Colombia por defecto, no UTC

        $isWorkdayRaw = $pick($body, 'isWorkday', 'IsWorkday');
        $isWorkday = $isWorkdayRaw === null ? 1 : $flag($isWorkdayRaw);

        // Banderas de cobertura: por defecto en 0 si faltan (no se inventa cobertura).
        $activityTracked = $flag($pick($body, 'activityTracked', 'ActivityTracked'));
        $windowTracked   = $flag($pick($body, 'windowTracked', 'WindowTracked'));
        $callTracked     = $flag($pick($body, 'callTracked', 'CallTracked'));

        $activeSeconds  = $sec($pick($body, 'activeSeconds', 'ActiveSeconds'));
        $idleSeconds    = $sec($pick($body, 'idleSeconds', 'IdleSeconds'));
        $callSeconds    = $sec($pick($body, 'callSeconds', 'CallSeconds'));

        $workActiveSeconds = $sec($pick($body, 'workActiveSeconds', 'WorkActiveSeconds'));
        $workIdleSeconds   = $sec($pick($body, 'workIdleSeconds', 'WorkIdleSeconds'));

        $lunchActiveSeconds = $sec($pick($body, 'lunchActiveSeconds', 'LunchActiveSeconds'));
        $lunchIdleSeconds   = $sec($pick($body, 'lunchIdleSeconds', 'LunchIdleSeconds'));

        $afterHoursActiveSeconds = $sec($pick($body, 'afterHoursActiveSeconds', 'AfterHoursActiveSeconds'));
        $afterHoursIdleSeconds   = $sec($pick($body, 'afterHoursIdleSeconds', 'AfterHoursIdleSeconds'));

        $firstEventAtRaw = $pick($body, 'firstEventAt', 'FirstEventAt');
        $lastEventAtRaw  = $pick($body, 'lastEventAt', 'LastEventAt');

        $parseDateTime = static function ($v): ?string {
            if (!$v || !is_string($v)) return null;
            $d = \DateTime::createFromFormat('Y-m-d H:i:s', $v);
            return ($d && $d->format('Y-m-d H:i:s') === $v) ? $v : null;
        };
        $firstEventAt = $parseDateTime($firstEventAtRaw);
        $lastEventAt  = $parseDateTime($lastEventAtRaw);

        $row = [
            'user_id'                    => $userId,
            'device_id'                  => $deviceId,
            'day_date'                   => $dayDate,
            'tz_offset_minutes'          => $tzOffsetMinutes,
            'is_workday'                 => $isWorkday,
            'activity_tracked'           => $activityTracked,
            'window_tracked'             => $windowTracked,
            'call_tracked'               => $callTracked,
            'active_seconds'             => $activeSeconds,
            'idle_seconds'               => $idleSeconds,
            'call_seconds'               => $callSeconds,
            'work_active_seconds'        => $workActiveSeconds,
            'work_idle_seconds'          => $workIdleSeconds,
            'lunch_active_seconds'       => $lunchActiveSeconds,
            'lunch_idle_seconds'         => $lunchIdleSeconds,
            'after_hours_active_seconds' => $afterHoursActiveSeconds,
            'after_hours_idle_seconds'   => $afterHoursIdleSeconds,
            'first_event_at'             => $firstEventAt,
            'last_event_at'              => $lastEventAt,
        ];

        try {
            DaySummaryRepo::upsert($pdo, $row);
            Http::json(200, ['ok' => true, 'dayDate' => $dayDate]);
        } catch (\PDOException $e) {
            error_log('ActivityDay upsert error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database upsert failed']);
        }
    }
}
