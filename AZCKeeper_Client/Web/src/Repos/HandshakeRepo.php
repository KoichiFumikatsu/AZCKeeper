<?php
namespace Keeper\Repos;

use PDO;

class HandshakeRepo {
  /**
   * Inserta log de handshake solo si hay un cambio de policy_version
   * o si es el primer handshake del día para este device.
   * Evita 100 INSERTs/min innecesarios con 500 usuarios.
   */
  public static function insert(PDO $pdo, int $userId, int $deviceId, ?string $clientVersion, array $req, array $resp): void {
    // Solo loguear si cambió la versión de política o es el primero del día
    $st = $pdo->prepare("
      SELECT id FROM keeper_handshake_log
      WHERE device_id = :d
        AND created_at >= CURDATE()
        AND created_at < CURDATE() + INTERVAL 1 DAY
      ORDER BY id DESC LIMIT 1
    ");
    $st->execute([':d' => $deviceId]);
    $last = $st->fetch();

    // Si ya hubo un handshake hoy, comparar policy version en response
    if ($last) {
      $currentVersion = $resp['policyApplied']['version'] ?? null;
      // Leer version del ultimo log para comparar
      $stV = $pdo->prepare("SELECT response_json FROM keeper_handshake_log WHERE id = :id");
      $stV->execute([':id' => $last['id']]);
      $lastRow = $stV->fetch();
      $lastResp = $lastRow ? json_decode($lastRow['response_json'], true) : null;
      $lastVersion = $lastResp['policyApplied']['version'] ?? null;

      // Si la versión no cambió, no insertar (evita INSERTs redundantes)
      if ($currentVersion !== null && $currentVersion === $lastVersion) {
        return;
      }
    }

    $st = $pdo->prepare("
      INSERT INTO keeper_handshake_log (user_id, device_id, client_version, request_json, response_json, created_at)
      VALUES (:u, :d, :v, :rq, :rs, NOW())
    ");
    $st->execute([
      ':u' => $userId,
      ':d' => $deviceId,
      ':v' => $clientVersion,
      ':rq' => json_encode($req, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ':rs' => json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
  }
}
