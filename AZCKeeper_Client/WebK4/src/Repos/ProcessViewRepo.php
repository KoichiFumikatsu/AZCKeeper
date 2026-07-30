<?php
namespace Keeper\Repos;

use PDO;

/**
 * Lecturas de la vista de procesos. El resumen sale del rollup diario (decenas de
 * filas); el detalle del episodio crudo, ordenado cronologicamente por el indice
 * ix_ep_user_day_start, con LIMIT/OFFSET reales.
 */
class ProcessViewRepo {

  /** Resumen por proceso en el rango, desde keeper_episode_daily. */
  public static function summary(PDO $pdo, int $userId, string $from, string $to): array {
    $st = $pdo->prepare("
      SELECT process_name,
             SUM(total_seconds) AS total_seconds,
             SUM(episode_count) AS episode_count,
             SUM(call_seconds)  AS call_seconds
      FROM keeper_episode_daily
      WHERE user_id = :u AND day_date BETWEEN :f AND :t
      GROUP BY process_name
      ORDER BY total_seconds DESC
    ");
    $st->execute([':u' => $userId, ':f' => $from, ':t' => $to]);
    return $st->fetchAll();
  }

  /** Detalle paginado, orden cronologico completo (day_date, start_at). */
  public static function detail(PDO $pdo, int $userId, string $from, string $to, int $limit, int $offset): array {
    $st = $pdo->prepare("
      SELECT id, day_date, start_at, end_at, duration_seconds, process_name, window_title, is_in_call
      FROM keeper_episode
      WHERE user_id = :u AND day_date BETWEEN :f AND :t
      ORDER BY day_date, start_at
      LIMIT :lim OFFSET :off
    ");
    $st->bindValue(':u', $userId, PDO::PARAM_INT);
    $st->bindValue(':f', $from);
    $st->bindValue(':t', $to);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
  }

  public static function detailCount(PDO $pdo, int $userId, string $from, string $to): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM keeper_episode WHERE user_id = :u AND day_date BETWEEN :f AND :t");
    $st->execute([':u' => $userId, ':f' => $from, ':t' => $to]);
    return (int)$st->fetchColumn();
  }
}
