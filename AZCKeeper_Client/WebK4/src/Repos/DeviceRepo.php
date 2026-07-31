<?php
namespace Keeper\Repos;

use PDO;

class DeviceRepo {

  public static function findByGuid(PDO $pdo, string $guid): ?array {
    $st = $pdo->prepare("SELECT id, user_id, status, device_name, client_version FROM keeper_devices WHERE device_guid = :g LIMIT 1");
    $st->execute([':g' => $guid]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function touch(PDO $pdo, int $deviceId, ?string $name, ?string $version, ?int $idleSeconds = null): void {
    $st = $pdo->prepare("
      UPDATE keeper_devices
      SET last_seen_at = NOW(),
          device_name = COALESCE(:n, device_name),
          client_version = COALESCE(:v, client_version),
          last_idle_seconds = COALESCE(:idle, last_idle_seconds)
      WHERE id = :id
    ");
    $st->execute([':n' => $name, ':v' => $version, ':idle' => $idleSeconds, ':id' => $deviceId]);
  }
}
