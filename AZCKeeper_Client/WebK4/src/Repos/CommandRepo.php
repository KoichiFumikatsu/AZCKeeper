<?php
namespace Keeper\Repos;

use PDO;

/**
 * Cola de comandos servidor->equipo. Canal inverso al flujo normal de Keeper:
 * el panel encola una orden (apagado remoto, diagnostico de red bajo demanda,
 * etc) y el cliente la recoge en su siguiente poll.
 */
class CommandRepo {

  /**
   * Encola un comando 'pending' para un device. Si $expiresInSeconds no es
   * null, calcula expires_at a partir de NOW(); si es null, el comando no vence.
   * @return int id del comando encolado
   */
  public static function enqueue(PDO $pdo, int $deviceId, string $type, ?array $params, ?int $createdBy, ?int $expiresInSeconds): int {
    $paramsJson = $params !== null ? json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    if ($expiresInSeconds !== null) {
      $st = $pdo->prepare("
        INSERT INTO keeper_device_command
          (device_id, command_type, params_json, status, created_by, created_at, expires_at)
        VALUES
          (:dev, :type, :params, 'pending', :by, NOW(), DATE_ADD(NOW(), INTERVAL :s SECOND))
      ");
      $st->execute([
        ':dev' => $deviceId, ':type' => $type, ':params' => $paramsJson,
        ':by' => $createdBy, ':s' => $expiresInSeconds,
      ]);
    } else {
      $st = $pdo->prepare("
        INSERT INTO keeper_device_command
          (device_id, command_type, params_json, status, created_by, created_at, expires_at)
        VALUES
          (:dev, :type, :params, 'pending', :by, NOW(), NULL)
      ");
      $st->execute([
        ':dev' => $deviceId, ':type' => $type, ':params' => $paramsJson, ':by' => $createdBy,
      ]);
    }

    return (int)$pdo->lastInsertId();
  }

  /**
   * Devuelve los comandos 'pending' no vencidos de un device y, en la misma
   * llamada, los marca 'sent' (sent_at=NOW()). Los vencidos se marcan
   * 'expired' aparte y no se devuelven.
   * @return array<int,array{id:int,command_type:string,params_json:?array}>
   */
  public static function pendingFor(PDO $pdo, int $deviceId): array {
    $pdo->beginTransaction();
    try {
      // Vencidos primero: liberan la cola sin que el cliente los vea.
      $exp = $pdo->prepare("
        UPDATE keeper_device_command
        SET status = 'expired'
        WHERE device_id = :dev AND status = 'pending'
          AND expires_at IS NOT NULL AND expires_at <= NOW()
      ");
      $exp->execute([':dev' => $deviceId]);

      // Selecciona ids pendientes vigentes antes de marcarlos.
      $sel = $pdo->prepare("
        SELECT id, command_type, params_json
        FROM keeper_device_command
        WHERE device_id = :dev AND status = 'pending'
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY id ASC
        FOR UPDATE
      ");
      $sel->execute([':dev' => $deviceId]);
      $rows = $sel->fetchAll();

      if ($rows) {
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare("UPDATE keeper_device_command SET status = 'sent', sent_at = NOW() WHERE id IN ($ph)");
        $upd->execute($ids);
      }

      $pdo->commit();
    } catch (\PDOException $ex) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $ex;
    }

    return array_map(function ($r) {
      return [
        'id'           => (int)$r['id'],
        'command_type' => $r['command_type'],
        'params_json'  => $r['params_json'] !== null ? json_decode($r['params_json'], true) : null,
      ];
    }, $rows);
  }

  /**
   * El device reporta el resultado de un comando. Solo permite la transicion
   * desde 'sent' o 'acked', y solo si el comando pertenece a ese device.
   * @return bool true si se actualizo una fila
   */
  public static function reportResult(PDO $pdo, int $commandId, int $deviceId, string $status, ?array $result): bool {
    $resultJson = $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $st = $pdo->prepare("
      UPDATE keeper_device_command
      SET status = :status, completed_at = NOW(), result_json = :result
      WHERE id = :id AND device_id = :dev AND status IN ('sent','acked')
    ");
    $st->execute([
      ':status' => $status, ':result' => $resultJson, ':id' => $commandId, ':dev' => $deviceId,
    ]);

    return $st->rowCount() > 0;
  }
}
