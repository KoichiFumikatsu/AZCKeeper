<?php
namespace Keeper\Repos;

use PDO;

class AuditRepo {
  public static function log(PDO $pdo, ?int $userId, ?int $deviceId, string $type, ?string $msg, $meta=null): void {
    $st = $pdo->prepare("
      INSERT INTO keeper_audit_log (user_id, device_id, event_type, message, meta_json, created_at)
      VALUES (:u, :d, :t, :m, :j, NOW())
    ");
    $st->execute([
      ':u' => $userId,
      ':d' => $deviceId,
      ':t' => $type,
      ':m' => $msg ? substr($msg, 0, 512) : null,
      ':j' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
  }
}
