<?php
namespace Keeper\Repos;

use PDO;

/**
 * Eco del estado real de los modulos del cliente en cada equipo.
 * Mantiene keeper_device_module_state: un renglon por (device_id, module_code)
 * con el ultimo estado reportado, para distinguir en el panel entre apagado
 * a proposito, encendido y reportando, o encendido y sin reportar.
 */
class ModuleStateRepo {

  /**
   * @param array $modules filas ya validadas: code (string), running (bool),
   *   detail (array|null)
   * @return int numero de modulos reportados
   */
  public static function report(PDO $pdo, int $deviceId, array $modules): int {
    if (!$modules) return 0;

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("
        INSERT INTO keeper_device_module_state
          (device_id, module_code, is_running, reported_at, detail_json)
        VALUES (:d, :code, :run, NOW(), :detail)
        ON DUPLICATE KEY UPDATE
          is_running  = VALUES(is_running),
          reported_at = VALUES(reported_at),
          detail_json = VALUES(detail_json)
      ");

      foreach ($modules as $m) {
        $detail = $m['detail'] ?? null;
        $st->execute([
          ':d'      => $deviceId,
          ':code'   => $m['code'],
          ':run'    => !empty($m['running']) ? 1 : 0,
          ':detail' => $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
      }

      $pdo->commit();
      return count($modules);
    } catch (\PDOException $ex) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $ex;
    }
  }
}
