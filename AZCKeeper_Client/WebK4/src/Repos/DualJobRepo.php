<?php
namespace Keeper\Repos;

use PDO;

class DualJobRepo {

  /**
   * UPSERT de una alerta de doble empleo. UNIQUE (user_id, day_date, alert_type)
   * hace que recalcular el dia actualice la evidencia en vez de duplicar. No pisa
   * el estado de revision si un admin ya la reviso (solo refresca evidencia en 'open').
   */
  public static function upsertAlert(PDO $pdo, int $userId, ?int $deviceId, string $day, string $alertType, array $evidence): void {
    $st = $pdo->prepare("
      INSERT INTO keeper_dual_job_alert (user_id, device_id, day_date, alert_type, evidence_json, status, created_at)
      VALUES (:u, :dev, :d, :t, :ev, 'open', NOW())
      ON DUPLICATE KEY UPDATE
        device_id     = VALUES(device_id),
        evidence_json = VALUES(evidence_json)
    ");
    $st->execute([
      ':u' => $userId, ':dev' => $deviceId, ':d' => $day, ':t' => $alertType,
      ':ev' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
  }

  public static function clearOpen(PDO $pdo, int $userId, string $day, string $alertType): void {
    // Si el recalculo ya no encuentra señales, cierra la alerta abierta (no toca las revisadas).
    $st = $pdo->prepare("
      UPDATE keeper_dual_job_alert SET status='dismissed'
      WHERE user_id=:u AND day_date=:d AND alert_type=:t AND status='open'
    ");
    $st->execute([':u' => $userId, ':d' => $day, ':t' => $alertType]);
  }
}
