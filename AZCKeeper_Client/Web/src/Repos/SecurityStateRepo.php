<?php
namespace Keeper\Repos;

use PDO;

class SecurityStateRepo {

  /**
   * UPSERT del estado de controles de un dispositivo.
   * Devuelve true si el hash cambio respecto al ultimo reporte.
   */
  public static function upsert(PDO $pdo, int $userId, int $deviceId, bool $agentPresent, array $controls): bool {
    $json = json_encode($controls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $hash = hash('sha256', $json);

    $st = $pdo->prepare("SELECT controls_hash FROM keeper_security_state WHERE device_id = :d LIMIT 1");
    $st->execute([':d' => $deviceId]);
    $previous = $st->fetchColumn();

    $changed = ($previous === false) || ($previous !== $hash);

    $st = $pdo->prepare("
      INSERT INTO keeper_security_state
        (user_id, device_id, reported_at, agent_present, controls_json, controls_hash)
      VALUES
        (:u, :d, NOW(), :a, :j, :h)
      ON DUPLICATE KEY UPDATE
        user_id       = VALUES(user_id),
        reported_at   = VALUES(reported_at),
        agent_present = VALUES(agent_present),
        controls_json = VALUES(controls_json),
        controls_hash = VALUES(controls_hash)
    ");
    $st->execute([
      ':u' => $userId,
      ':d' => $deviceId,
      ':a' => $agentPresent ? 1 : 0,
      ':j' => $json,
      ':h' => $hash,
    ]);

    return $changed;
  }
}
