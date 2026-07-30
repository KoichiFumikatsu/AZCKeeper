<?php
namespace Keeper\Repos;

use PDO;

/**
 * Ingesta del resumen diario de actividad (keeper_day_summary). El cliente
 * manda el TOTAL del dia, no un delta: el UPDATE siempre REEMPLAZA cada
 * columna con VALUES(columna), nunca acumula. La idempotencia la da la
 * clave unica (user_id, device_id, day_date): reenviar el mismo total
 * es inocuo.
 */
class DaySummaryRepo {

  /**
   * @param array $row fila ya validada: user_id, device_id, day_date,
   *   tz_offset_minutes, is_workday, activity_tracked, window_tracked,
   *   call_tracked, active_seconds, idle_seconds, call_seconds,
   *   work_active_seconds, work_idle_seconds, lunch_active_seconds,
   *   lunch_idle_seconds, after_hours_active_seconds,
   *   after_hours_idle_seconds, first_event_at, last_event_at
   */
  public static function upsert(PDO $pdo, array $row): void {
    $sql = "
      INSERT INTO keeper_day_summary
        (user_id, device_id, day_date, tz_offset_minutes, is_workday,
         activity_tracked, window_tracked, call_tracked,
         active_seconds, idle_seconds, call_seconds,
         work_active_seconds, work_idle_seconds,
         lunch_active_seconds, lunch_idle_seconds,
         after_hours_active_seconds, after_hours_idle_seconds,
         first_event_at, last_event_at)
      VALUES
        (:user_id, :device_id, :day_date, :tz_offset_minutes, :is_workday,
         :activity_tracked, :window_tracked, :call_tracked,
         :active_seconds, :idle_seconds, :call_seconds,
         :work_active_seconds, :work_idle_seconds,
         :lunch_active_seconds, :lunch_idle_seconds,
         :after_hours_active_seconds, :after_hours_idle_seconds,
         :first_event_at, :last_event_at)
      ON DUPLICATE KEY UPDATE
        tz_offset_minutes          = VALUES(tz_offset_minutes),
        is_workday                 = VALUES(is_workday),
        activity_tracked           = VALUES(activity_tracked),
        window_tracked             = VALUES(window_tracked),
        call_tracked               = VALUES(call_tracked),
        active_seconds             = VALUES(active_seconds),
        idle_seconds               = VALUES(idle_seconds),
        call_seconds               = VALUES(call_seconds),
        work_active_seconds        = VALUES(work_active_seconds),
        work_idle_seconds          = VALUES(work_idle_seconds),
        lunch_active_seconds       = VALUES(lunch_active_seconds),
        lunch_idle_seconds         = VALUES(lunch_idle_seconds),
        after_hours_active_seconds = VALUES(after_hours_active_seconds),
        after_hours_idle_seconds   = VALUES(after_hours_idle_seconds),
        first_event_at             = VALUES(first_event_at),
        last_event_at              = VALUES(last_event_at)
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
      ':user_id'                    => $row['user_id'],
      ':device_id'                  => $row['device_id'],
      ':day_date'                   => $row['day_date'],
      ':tz_offset_minutes'          => $row['tz_offset_minutes'],
      ':is_workday'                 => $row['is_workday'],
      ':activity_tracked'           => $row['activity_tracked'],
      ':window_tracked'             => $row['window_tracked'],
      ':call_tracked'               => $row['call_tracked'],
      ':active_seconds'             => $row['active_seconds'],
      ':idle_seconds'               => $row['idle_seconds'],
      ':call_seconds'               => $row['call_seconds'],
      ':work_active_seconds'        => $row['work_active_seconds'],
      ':work_idle_seconds'          => $row['work_idle_seconds'],
      ':lunch_active_seconds'       => $row['lunch_active_seconds'],
      ':lunch_idle_seconds'         => $row['lunch_idle_seconds'],
      ':after_hours_active_seconds' => $row['after_hours_active_seconds'],
      ':after_hours_idle_seconds'   => $row['after_hours_idle_seconds'],
      ':first_event_at'             => $row['first_event_at'],
      ':last_event_at'              => $row['last_event_at'],
    ]);
  }
}
