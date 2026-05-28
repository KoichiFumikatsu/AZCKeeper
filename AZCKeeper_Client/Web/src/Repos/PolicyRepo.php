<?php
namespace Keeper\Repos;

use PDO;

class PolicyRepo {

  /**
   * Cache en memoria del proceso FastCGI para política global.
   * TTL de 60s alineado con el intervalo de handshake.
   */
  private static ?array $_globalCache = null;
  private static int $_globalCacheAt = 0;
  private const GLOBAL_CACHE_TTL = 60;

  /**
   * Cache en memoria para work schedules por userId.
   * TTL de 300s — los horarios cambian raramente (configuración anual).
   */
  private static array $_scheduleCache = [];
  private static array $_scheduleCacheAt = [];
  private const SCHEDULE_CACHE_TTL = 300;

  /**
   * Resuelve la pol�tica efectiva final en UNA sola query con UNION.
   * Orden de prioridad: device > user > global (el �ltimo en el UNION gana en deepMerge).
   * Retorna array con las 3 filas ordenadas [global, user, device] para hacer merge en PHP.
   * As� pasamos de 3 SELECTs separados a 1 sola ida a la BD.
   */
  public static function getAllPolicies(PDO $pdo, int $userId, int $deviceId): array {
    // Pol�tica global: puede usar cache
    $global = self::getActiveGlobal($pdo);

    // User + device en 1 query
    $st = $pdo->prepare("
      (SELECT 'user' AS scope, id, version, policy_json
       FROM keeper_policy_assignments
       WHERE scope='user' AND user_id=:u AND is_active=1
       ORDER BY priority ASC, id DESC LIMIT 1)
      UNION ALL
      (SELECT 'device' AS scope, id, version, policy_json
       FROM keeper_policy_assignments
       WHERE scope='device' AND device_id=:d AND is_active=1
       ORDER BY priority ASC, id DESC LIMIT 1)
    ");
    $st->execute([':u' => $userId, ':d' => $deviceId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return [
      'global' => $global,
      'user'   => self::findByScope($rows, 'user'),
      'device' => self::findByScope($rows, 'device'),
    ];
  }

  private static function findByScope(array $rows, string $scope): ?array {
    foreach ($rows as $r) {
      if ($r['scope'] === $scope) return $r;
    }
    return null;
  }

  public static function getActiveGlobal(PDO $pdo): ?array {
    $now = time();
    if (self::$_globalCache !== null && ($now - self::$_globalCacheAt) < self::GLOBAL_CACHE_TTL) {
      return self::$_globalCache;
    }
    $st = $pdo->query("
      SELECT id, version, policy_json
      FROM keeper_policy_assignments
      WHERE scope='global' AND is_active=1
      ORDER BY priority ASC, id DESC
      LIMIT 1
    ");
    $row = $st->fetch();
    self::$_globalCache = $row ?: null;
    self::$_globalCacheAt = $now;
    return self::$_globalCache;
  }

  /**
   * Obtiene el horario laboral activo para el usuario en 1 query con UNION.
   * Prioridad: usuario específico > global (user_id IS NULL) > defaults.
   * Cache en memoria de 300s: los horarios cambian raramente.
   */
  public static function getWorkSchedule(PDO $pdo, int $userId): array {
    $now = time();
    if (isset(self::$_scheduleCache[$userId])
        && ($now - self::$_scheduleCacheAt[$userId]) < self::SCHEDULE_CACHE_TTL) {
      return self::$_scheduleCache[$userId];
    }

    $st = $pdo->prepare("
      (SELECT work_start_time, work_end_time, lunch_start_time, lunch_end_time, timezone, 1 AS priority
       FROM keeper_work_schedules WHERE user_id = :u AND is_active = 1 LIMIT 1)
      UNION ALL
      (SELECT work_start_time, work_end_time, lunch_start_time, lunch_end_time, timezone, 2 AS priority
       FROM keeper_work_schedules WHERE user_id IS NULL AND is_active = 1 LIMIT 1)
      ORDER BY priority ASC LIMIT 1
    ");
    $st->execute([':u' => $userId]);
    $row = $st->fetch();

    $schedule = [
      'workStartTime'  => $row['work_start_time']  ?? '07:00:00',
      'workEndTime'    => $row['work_end_time']     ?? '19:00:00',
      'lunchStartTime' => $row['lunch_start_time']  ?? '12:00:00',
      'lunchEndTime'   => $row['lunch_end_time']    ?? '13:00:00',
      'timezone'       => $row['timezone']           ?? 'America/Bogota',
    ];

    self::$_scheduleCache[$userId]   = $schedule;
    self::$_scheduleCacheAt[$userId] = $now;

    return $schedule;
  }

}
