<?php
namespace Keeper\Repos;

use PDO;

class HandshakeRepo {
  public static function insert(PDO $pdo, int $userId, int $deviceId, ?string $clientVersion, array $req, array $resp): void {
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
