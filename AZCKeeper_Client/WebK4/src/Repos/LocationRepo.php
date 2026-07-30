<?php
namespace Keeper\Repos;

use PDO;

/**
 * Ingesta de ubicacion reportada por el agente (GPS, geolocalizacion por wifi
 * o por IP segun el metodo disponible en el dispositivo).
 */
class LocationRepo {

  public static function insert(
    PDO $pdo,
    int $userId,
    int $deviceId,
    string $capturedAt,
    float $lat,
    float $lng,
    ?int $accuracyM,
    string $source
  ): int {
    $st = $pdo->prepare("
      INSERT INTO keeper_location
        (user_id, device_id, captured_at, latitude, longitude, accuracy_m, source)
      VALUES
        (:u, :d, :cap, :lat, :lng, :acc, :src)
    ");
    $st->execute([
      ':u'   => $userId,
      ':d'   => $deviceId,
      ':cap' => $capturedAt,
      ':lat' => $lat,
      ':lng' => $lng,
      ':acc' => $accuracyM,
      ':src' => $source,
    ]);
    return (int)$pdo->lastInsertId();
  }
}
