<?php
/**
 * Endpoint de diagnóstico temporal.
 * ELIMINAR después de diagnosticar.
 */
require_once __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_GET['user_id'] ?? 0);
if (!$userId) {
    echo json_encode(['error' => 'user_id requerido']);
    exit;
}

$pdo = Keeper\Db::pdo();

$utcTz    = new DateTimeZone('UTC');
$bogotaTz = new DateTimeZone('America/Bogota');
$nowPhpUtc   = (new DateTime('now', $utcTz))->format('Y-m-d H:i:s');
$todayBogota = (new DateTime('now', $bogotaTz))->format('Y-m-d');

$mysqlNow = $pdo->query("SELECT NOW() as now_local, UTC_TIMESTAMP() as now_utc, @@global.time_zone as tz_global, @@session.time_zone as tz_session")->fetch();

// ?? DEFINICIÓN EXACTA DE COLUMNAS ?????????????????????????????????????????
// Muestra DEFAULT y EXTRA para detectar ON UPDATE CURRENT_TIMESTAMP
$columns = $pdo->query("
    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'keeper_activity_day'
    ORDER BY ORDINAL_POSITION
")->fetchAll(PDO::FETCH_ASSOC);

// ?? TRIGGERS ??????????????????????????????????????????????????????????????
$triggers = $pdo->query("
    SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT
    FROM information_schema.TRIGGERS
    WHERE EVENT_OBJECT_TABLE = 'keeper_activity_day'
    AND TRIGGER_SCHEMA = DATABASE()
")->fetchAll(PDO::FETCH_ASSOC);

// ?? SHOW CREATE TABLE (fuente de verdad absoluta) ??????????????????????????
$createTable = $pdo->query("SHOW CREATE TABLE keeper_activity_day")->fetch(PDO::FETCH_ASSOC);

// ?? DISPOSITIVOS Y ACTIVIDAD ???????????????????????????????????????????????
$devices = $pdo->prepare("SELECT id, device_guid, device_name, last_seen_at FROM keeper_devices WHERE user_id = ? AND status = 'active'");
$devices->execute([$userId]);
$devRows = $devices->fetchAll(PDO::FETCH_ASSOC);
$deviceIds = array_column($devRows, 'id');

$actRows = [];
if (!empty($deviceIds)) {
    $in = implode(',', $deviceIds);
    $actRows = $pdo->query("
        SELECT device_id, day_date, active_seconds, idle_seconds,
               last_event_at, updated_at,
               TIMESTAMPDIFF(SECOND, last_event_at, UTC_TIMESTAMP()) as event_delta_utc,
               payload_json
        FROM keeper_activity_day
        WHERE user_id = {$userId} AND device_id IN ($in)
        AND day_date = '{$todayBogota}'
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($actRows as &$row) {
        $payload = json_decode($row['payload_json'] ?? '{}', true);
        $lastEventAtRaw = $payload['lastEventAt'] ?? $payload['LastEventAt'] ?? null;
        $tzOff = (int)($payload['tzOffsetMinutes'] ?? $payload['TzOffsetMinutes'] ?? -300);

        $lastEventAtUtc = null;
        if ($lastEventAtRaw) {
            $tzInterval = abs($tzOff);
            $tzSign     = $tzOff >= 0 ? '+' : '-';
            $tzString   = sprintf('%s%02d:%02d', $tzSign, intdiv($tzInterval, 60), $tzInterval % 60);
            try {
                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $lastEventAtRaw, new DateTimeZone($tzString));
                if ($dt) { $dt->setTimezone($utcTz); $lastEventAtUtc = $dt->format('Y-m-d H:i:s'); }
            } catch (Exception $e) {}
        }

        $row['payload_lastEventAt_local'] = $lastEventAtRaw;
        $row['payload_lastEventAt_utc']   = $lastEventAtUtc;
        $row['bd_vs_payload_diff_seconds'] = ($lastEventAtUtc && $row['last_event_at'])
            ? (strtotime($row['last_event_at']) - strtotime($lastEventAtUtc))
            : null;
        unset($row['payload_json']);
    }
    unset($row);
}

echo json_encode([
    'php_now_utc'        => $nowPhpUtc,
    'php_today_bogota'   => $todayBogota,
    'mysql_tz_global'    => $mysqlNow['tz_global'],
    'mysql_tz_session'   => $mysqlNow['tz_session'],
    'table_columns'      => $columns,
    'triggers_on_table'  => $triggers,
    'create_table'       => $createTable['Create Table'] ?? null,
    'activity_today'     => $actRows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
