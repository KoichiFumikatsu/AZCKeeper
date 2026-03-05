<?php
namespace Keeper\Repos;

use PDO;

class PolicyRepo {
  public static function getActiveGlobal(PDO $pdo): ?array {
    $st = $pdo->query("
      SELECT id, version, policy_json
      FROM keeper_policy_assignments
      WHERE scope='global' AND is_active=1
      ORDER BY priority ASC, id DESC
      LIMIT 1
    ");
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function getActiveUser(PDO $pdo, int $userId): ?array {
    $st = $pdo->prepare("
      SELECT id, version, policy_json
      FROM keeper_policy_assignments
      WHERE scope='user' AND user_id=:u AND is_active=1
      ORDER BY priority ASC, id DESC
      LIMIT 1
    ");
    $st->execute([':u' => $userId]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function getActiveDevice(PDO $pdo, int $deviceId): ?array {
    $st = $pdo->prepare("
      SELECT id, version, policy_json
      FROM keeper_policy_assignments
      WHERE scope='device' AND device_id=:d AND is_active=1
      ORDER BY priority ASC, id DESC
      LIMIT 1
    ");
    $st->execute([':d' => $deviceId]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /**
   * Obtiene el horario laboral activo para el usuario.
   * Prioridad: registro con user_id específico > registro global (user_id IS NULL).
   * Retorna array con work_start_time, work_end_time, lunch_start_time, lunch_end_time, timezone.
   * Si no existe ningún registro, retorna los defaults del sistema.
   */
  public static function getWorkSchedule(PDO $pdo, int $userId): array {
    // Intenta horario específico del usuario
    $st = $pdo->prepare("
      SELECT work_start_time, work_end_time, lunch_start_time, lunch_end_time, timezone
      FROM keeper_work_schedules
      WHERE user_id = :u AND is_active = 1
      LIMIT 1
    ");
    $st->execute([':u' => $userId]);
    $row = $st->fetch();

    if (!$row) {
      // Fallback: horario global (user_id IS NULL)
      $st = $pdo->query("
        SELECT work_start_time, work_end_time, lunch_start_time, lunch_end_time, timezone
        FROM keeper_work_schedules
        WHERE user_id IS NULL AND is_active = 1
        LIMIT 1
      ");
      $row = $st->fetch();
    }

    // Defaults si no hay ninguna configuración en BD
    return [
      'workStartTime'  => $row['work_start_time']  ?? '07:00:00',
      'workEndTime'    => $row['work_end_time']     ?? '19:00:00',
      'lunchStartTime' => $row['lunch_start_time']  ?? '12:00:00',
      'lunchEndTime'   => $row['lunch_end_time']    ?? '13:00:00',
      'timezone'       => $row['timezone']           ?? 'America/Bogota',
    ];
  }

}
