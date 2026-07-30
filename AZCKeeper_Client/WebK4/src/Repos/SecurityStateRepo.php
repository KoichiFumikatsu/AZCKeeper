<?php
namespace Keeper\Repos;

use PDO;

/**
 * Estado de seguridad reportado por el agente Keeper (presencia del agente y
 * estado de los controles del SO: AV, firewall, disco cifrado, etc). Un solo
 * registro por dispositivo (UNIQUE device_id): cada reporte reemplaza el
 * ultimo estado conocido. controls_hash permite saber si algo cambio respecto
 * al reporte anterior sin comparar el JSON completo en el cliente.
 */
class SecurityStateRepo {

  /**
   * @param array $controls estructura ya saneada por el endpoint
   * @return bool true si el estado cambio respecto al ultimo reporte conocido
   * @throws \JsonException si $controls no es UTF-8 valido (JSON_THROW_ON_ERROR)
   */
  public static function upsert(PDO $pdo, int $userId, int $deviceId, bool $agentPresent, array $controls): bool {
    $json = json_encode($controls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $hash = hash('sha256', $json);

    $st = $pdo->prepare("SELECT controls_hash FROM keeper_security_state WHERE device_id = :d LIMIT 1");
    $st->execute([':d' => $deviceId]);
    $prev = $st->fetchColumn();

    $changed = ($prev === false) || ($prev !== $hash);

    $up = $pdo->prepare("
      INSERT INTO keeper_security_state
        (user_id, device_id, reported_at, agent_present, controls_json, controls_hash)
      VALUES (:u, :d, NOW(), :ap, :cj, :ch)
      ON DUPLICATE KEY UPDATE
        user_id       = VALUES(user_id),
        reported_at   = NOW(),
        agent_present = VALUES(agent_present),
        controls_json = VALUES(controls_json),
        controls_hash = VALUES(controls_hash)
    ");
    $up->execute([
      ':u'  => $userId,
      ':d'  => $deviceId,
      ':ap' => $agentPresent ? 1 : 0,
      ':cj' => $json,
      ':ch' => $hash,
    ]);

    return $changed;
  }
}
