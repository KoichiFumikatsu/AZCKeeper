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
}
