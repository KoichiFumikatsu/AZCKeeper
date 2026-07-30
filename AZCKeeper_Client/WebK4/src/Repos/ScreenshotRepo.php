<?php
namespace Keeper\Repos;

use PDO;

/**
 * Metadata de screenshots capturadas por el agente. El blob (el PNG) NO pasa
 * por aqui: viaja a object storage por otra via y este repo solo persiste el
 * indice (object_key, hash, tamano) para auditoria y para que el cliente
 * pueda recuperarlo despues.
 */
class ScreenshotRepo {

  public static function insert(
    PDO $pdo,
    int $userId,
    int $deviceId,
    string $capturedAt,
    string $objectKey,
    string $sha256,
    ?int $sizeBytes,
    string $trigger,
    ?int $commandId
  ): int {
    $st = $pdo->prepare("
      INSERT INTO keeper_screenshot
        (user_id, device_id, captured_at, received_at, object_key, sha256, size_bytes, trigger_type, command_id)
      VALUES
        (:u, :d, :cap, NOW(), :ok, :sha, :sz, :tr, :cmd)
    ");
    $st->execute([
      ':u'   => $userId,
      ':d'   => $deviceId,
      ':cap' => $capturedAt,
      ':ok'  => $objectKey,
      ':sha' => $sha256,
      ':sz'  => $sizeBytes,
      ':tr'  => $trigger,
      ':cmd' => $commandId,
    ]);
    return (int)$pdo->lastInsertId();
  }
}
