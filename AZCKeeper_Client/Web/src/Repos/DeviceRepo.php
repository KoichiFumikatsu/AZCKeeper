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

  public static function create(PDO $pdo, int $userId, string $guid, ?string $name, ?string $version = null): int {
    $st = $pdo->prepare("
      INSERT INTO keeper_devices (user_id, device_guid, device_name, client_version, status, last_seen_at)
      VALUES (:u, :g, :n, :v, 'active', NOW())
    ");
    $st->execute([':u' => $userId, ':g' => $guid, ':n' => $name, ':v' => $version]);
    return (int)$pdo->lastInsertId();
  }

  /**
   * Actualiza last_seen_at solo si han pasado m�s de 60 segundos desde la �ltima actualizaci�n.
   * Evita 100 UPDATEs/min en keeper_devices con 500 usuarios haciendo handshake cada 5 min.
   * El valor sigue siendo preciso a �1 minuto para realtime-status.php (umbral: 15 min).
   */
  public static function touch(PDO $pdo, int $deviceId, ?string $name, ?string $version = null): void {
    $st = $pdo->prepare("
      UPDATE keeper_devices
      SET device_name = COALESCE(:n, device_name),
          client_version = COALESCE(:v, client_version),
          last_seen_at = NOW()
      WHERE id = :id
        AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 60 SECOND)
    ");
    $st->execute([':n' => $name, ':v' => $version, ':id' => $deviceId]);
  }
}
