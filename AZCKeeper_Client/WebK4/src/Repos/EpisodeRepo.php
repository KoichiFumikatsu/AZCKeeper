<?php
namespace Keeper\Repos;

use PDO;

/**
 * Ingesta de episodios. Inserta el detalle en keeper_episode y mantiene el rollup
 * diario keeper_episode_daily (que responde todo lo agregado sin escanear el detalle).
 */
class EpisodeRepo {

  /**
   * @param array $rows filas ya validadas: user_id, device_id, day_date, start_at,
   *   end_at, duration_seconds, process_name, window_title, is_in_call
   * @return int numero de episodios insertados
   */
  public static function insertBatch(PDO $pdo, array $rows): int {
    if (!$rows) return 0;

    $pdo->beginTransaction();
    try {
      // Detalle multi-row.
      $ph = []; $params = [];
      foreach ($rows as $k => $r) {
        $ph[] = "(:u{$k},:dev{$k},:day{$k},:s{$k},:e{$k},:dur{$k},:p{$k},:t{$k},:c{$k})";
        $params += [
          ":u{$k}"=>$r['user_id'], ":dev{$k}"=>$r['device_id'], ":day{$k}"=>$r['day_date'],
          ":s{$k}"=>$r['start_at'], ":e{$k}"=>$r['end_at'], ":dur{$k}"=>$r['duration_seconds'],
          ":p{$k}"=>$r['process_name'], ":t{$k}"=>$r['window_title'], ":c{$k}"=>$r['is_in_call'],
        ];
      }
      $sql = "INSERT INTO keeper_episode
                (user_id, device_id, day_date, start_at, end_at, duration_seconds, process_name, window_title, is_in_call)
              VALUES " . implode(',', $ph);
      $pdo->prepare($sql)->execute($params);

      // Rollup diario por proceso, un UPSERT por episodio.
      $up = $pdo->prepare("
        INSERT INTO keeper_episode_daily
          (user_id, day_date, process_name, total_seconds, episode_count, call_seconds, first_start_at, last_end_at)
        VALUES (:u,:day,:p,:dur,1,:call,:s,:e)
        ON DUPLICATE KEY UPDATE
          total_seconds  = total_seconds + VALUES(total_seconds),
          episode_count  = episode_count + 1,
          call_seconds   = call_seconds  + VALUES(call_seconds),
          first_start_at = LEAST(first_start_at, VALUES(first_start_at)),
          last_end_at    = GREATEST(last_end_at, VALUES(last_end_at))
      ");
      foreach ($rows as $r) {
        $up->execute([
          ':u'=>$r['user_id'], ':day'=>$r['day_date'], ':p'=>$r['process_name'],
          ':dur'=>$r['duration_seconds'], ':call'=>($r['is_in_call'] ? $r['duration_seconds'] : 0),
          ':s'=>$r['start_at'], ':e'=>$r['end_at'],
        ]);
      }

      $pdo->commit();
      return count($rows);
    } catch (\PDOException $ex) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $ex;
    }
  }
}
