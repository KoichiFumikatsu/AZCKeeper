<?php
namespace Keeper\Repos;

use PDO;

class DeviceRepo {
  public static function findByGuid(PDO $pdo, string $guid): ?array {
    $st = $pdo->prepare("SELECT id, user_id, status FROM keeper_devices WHERE device_guid=:g LIMIT 1");
    $st->execute([':g' => $guid]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function create(PDO $pdo, int $userId, string $guid, ?string $name): int {
    $st = $pdo->prepare("
      INSERT INTO keeper_devices (user_id, device_guid, device_name, status, last_seen_at)
      VALUES (:u, :g, :n, 'active', NOW())
    ");
    $st->execute([':u' => $userId, ':g' => $guid, ':n' => $name]);
    return (int)$pdo->lastInsertId();
  }

  public static function touch(PDO $pdo, int $deviceId, ?string $name): void {
    $st = $pdo->prepare("
      UPDATE keeper_devices
      SET device_name = COALESCE(:n, device_name),
          last_seen_at = NOW()
      WHERE id=:id
    ");
    $st->execute([':n' => $name, ':id' => $deviceId]);
  }
}
