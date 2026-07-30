<?php
namespace Keeper\Repos;

use PDO;

class FocusRepo {

  /** UPSERT en keeper_focus_daily. focus_score/productivity_pct pueden ser NULL. */
  public static function upsert(PDO $pdo, array $m): void {
    $st = $pdo->prepare("
      INSERT INTO keeper_focus_daily
        (user_id, day_date, window_tracked, focus_score, productivity_pct,
         deep_work_seconds, distraction_seconds, switch_count, computed_at)
      VALUES (:u, :d, :wt, :fs, :pp, :dw, :ds, :sc, NOW())
      ON DUPLICATE KEY UPDATE
        window_tracked      = VALUES(window_tracked),
        focus_score         = VALUES(focus_score),
        productivity_pct    = VALUES(productivity_pct),
        deep_work_seconds   = VALUES(deep_work_seconds),
        distraction_seconds = VALUES(distraction_seconds),
        switch_count        = VALUES(switch_count),
        computed_at         = NOW()
    ");
    $st->execute([
      ':u'  => $m['user_id'], ':d' => $m['day_date'], ':wt' => $m['window_tracked'],
      ':fs' => $m['focus_score'], ':pp' => $m['productivity_pct'],
      ':dw' => $m['deep_work_seconds'], ':ds' => $m['distraction_seconds'], ':sc' => $m['switch_count'],
    ]);
  }
}
