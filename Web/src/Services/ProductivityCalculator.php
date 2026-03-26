<?php
namespace Keeper\Services;

use PDO;

/**
 * Calcula métricas diarias de productividad y focus score para un usuario.
 * Diseñado para ejecución server-side vía cron nocturno.
 */
class ProductivityCalculator {

  /**
   * Calcula y persiste métricas de productividad para un usuario en un día.
   *
   * @param PDO    $pdo
   * @param int    $userId
   * @param string $dayDate  "YYYY-MM-DD"
   * @return bool  true si se insertó/actualizó correctamente
   */
  public static function calculateDay(PDO $pdo, int $userId, string $dayDate): bool {
    // 1. Obtener dispositivo(s) activos del usuario ese día
    $devices = self::getActiveDevices($pdo, $userId, $dayDate);
    if (empty($devices)) return false;

    // Procesar cada dispositivo (normalmente 1)
    foreach ($devices as $deviceId) {
      self::calculateForDevice($pdo, $userId, $deviceId, $dayDate);
    }
    return true;
  }

  private static function getActiveDevices(PDO $pdo, int $userId, string $dayDate): array {
    $st = $pdo->prepare("
      SELECT DISTINCT device_id
      FROM keeper_activity_day
      WHERE user_id = :uid AND day_date = :day
    ");
    $st->execute([':uid' => $userId, ':day' => $dayDate]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
  }

  private static function calculateForDevice(PDO $pdo, int $userId, int $deviceId, string $dayDate): void {
    // --- Obtener datos base ---
    $activity = self::getDayActivity($pdo, $userId, $deviceId, $dayDate);
    if (!$activity) return;

    $schedule = \Keeper\Repos\PolicyRepo::getWorkSchedule($pdo, $userId);
    $workStart = $schedule['workStartTime'];
    $workEnd   = $schedule['workEndTime'];
    $lunchStart = $schedule['lunchStartTime'];
    $lunchEnd   = $schedule['lunchEndTime'];

    // Episodios del día en horario laboral (excl. lunch)
    $episodes = self::getWorkEpisodes($pdo, $userId, $deviceId, $dayDate, $workStart, $workEnd, $lunchStart, $lunchEnd);

    // Leisure apps config
    $leisureApps = self::getLeisureApps($pdo);

    // Ratio de actividad del día (para validar foco)
    $workActive = (float)$activity['work_hours_active_seconds'];
    $workIdle   = (float)$activity['work_hours_idle_seconds'];
    $workTotal  = $workActive + $workIdle;
    $activityRatio = $workTotal > 0 ? $workActive / $workTotal : 0;

    // --- 1. Context Switches ---
    $contextSwitches = self::countContextSwitches($episodes);

    // --- 2. Deep Work ---
    [$deepWorkSeconds, $deepWorkSessions, $longestStreak] = self::calculateDeepWork(
      $pdo, $episodes, $leisureApps, $activityRatio
    );

    // --- 3. Distraction Seconds ---
    $distractionSeconds = self::calculateDistraction($pdo, $userId, $deviceId, $dayDate, $workStart, $workEnd, $lunchStart, $lunchEnd, $leisureApps);

    // --- 4. Puntualidad ---
    [$firstActivityTime, $scheduledStart, $punctualityMinutes] = self::calculatePunctuality(
      $pdo, $userId, $deviceId, $dayDate, $workStart
    );

    // --- 5. Constancia ---
    $constancyPct = self::calculateConstancy($episodes, $workStart, $workEnd, $lunchStart, $lunchEnd);

    // --- 6. Focus Score (0-100) ---
    $weights = self::getFocusWeights($pdo);
    $focusScore = self::computeFocusScore(
      $contextSwitches, $deepWorkSeconds, $distractionSeconds,
      $punctualityMinutes, $constancyPct, $workTotal, $weights
    );

    // --- 7. Productivity % ---
    $productiveSec = max(0, $workActive - $distractionSeconds);
    $productivityPct = $workTotal > 0 ? (int)round(($productiveSec / $workTotal) * 100) : 0;
    $productivityPct = min(100, max(0, $productivityPct));

    // --- Persist ---
    self::upsert($pdo, [
      'user_id'                     => $userId,
      'device_id'                   => $deviceId,
      'day_date'                    => $dayDate,
      'context_switches'            => $contextSwitches,
      'deep_work_seconds'           => $deepWorkSeconds,
      'deep_work_sessions'          => $deepWorkSessions,
      'distraction_seconds'         => $distractionSeconds,
      'longest_focus_streak_seconds'=> $longestStreak,
      'focus_score'                 => $focusScore,
      'productivity_pct'            => $productivityPct,
      'constancy_pct'               => $constancyPct,
      'first_activity_time'         => $firstActivityTime,
      'scheduled_start'             => $scheduledStart,
      'punctuality_minutes'         => $punctualityMinutes,
    ]);
  }

  // ================================================================
  // DATA RETRIEVAL
  // ================================================================

  private static function getDayActivity(PDO $pdo, int $userId, int $deviceId, string $dayDate): ?array {
    $st = $pdo->prepare("
      SELECT active_seconds, idle_seconds, call_seconds,
             work_hours_active_seconds, work_hours_idle_seconds,
             lunch_active_seconds, lunch_idle_seconds,
             after_hours_active_seconds, after_hours_idle_seconds,
             first_event_at, last_event_at
      FROM keeper_activity_day
      WHERE user_id = :uid AND device_id = :did AND day_date = :day
      LIMIT 1
    ");
    $st->execute([':uid' => $userId, ':did' => $deviceId, ':day' => $dayDate]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  private static function getWorkEpisodes(PDO $pdo, int $userId, int $deviceId, string $dayDate,
                                           string $workStart, string $workEnd,
                                           string $lunchStart, string $lunchEnd): array {
    $st = $pdo->prepare("
      SELECT id, process_name, window_title, start_at, end_at, duration_seconds, is_in_call
      FROM keeper_window_episode
      WHERE user_id = :uid AND device_id = :did AND day_date = :day
        AND TIME(start_at) >= :ws AND TIME(start_at) < :we
        AND NOT (TIME(start_at) >= :ls AND TIME(start_at) < :le)
      ORDER BY start_at ASC
    ");
    $st->execute([
      ':uid' => $userId, ':did' => $deviceId, ':day' => $dayDate,
      ':ws'  => $workStart, ':we' => $workEnd,
      ':ls'  => $lunchStart, ':le' => $lunchEnd,
    ]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  private static function getLeisureApps(PDO $pdo): array {
    $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'leisure_apps' LIMIT 1");
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['setting_value'])) return ['apps' => [], 'windows' => []];

    $decoded = json_decode($row['setting_value'], true);
    if (!is_array($decoded)) return ['apps' => [], 'windows' => []];

    // Backward compat: flat array → apps only
    if (isset($decoded[0]) || empty($decoded)) {
      return ['apps' => array_values($decoded), 'windows' => []];
    }
    return [
      'apps'    => array_values($decoded['apps'] ?? []),
      'windows' => array_values($decoded['windows'] ?? []),
    ];
  }

  private static function getFocusWeights(PDO $pdo): array {
    $defaults = ['context_switches' => 20, 'deep_work' => 25, 'distraction' => 20, 'punctuality' => 15, 'constancy' => 20];
    $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'productivity.focus_weights' LIMIT 1");
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['setting_value'])) return $defaults;

    $decoded = json_decode($row['setting_value'], true);
    return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
  }

  private static function getDeepWorkThreshold(PDO $pdo): int {
    $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'productivity.deep_work_threshold_minutes' LIMIT 1");
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $val = $row ? (int)json_decode($row['setting_value'], true) : 25;
    return max(5, $val) * 60; // return seconds
  }

  // ================================================================
  // METRIC CALCULATIONS
  // ================================================================

  /**
   * 1. Context Switches: cambios de process_name entre episodios consecutivos.
   *    Excluye micro-switches <5s.
   */
  private static function countContextSwitches(array $episodes): int {
    $switches = 0;
    $prevProcess = null;
    foreach ($episodes as $ep) {
      $proc = strtolower(trim($ep['process_name'] ?? ''));
      if ($proc === '') continue;
      if ($ep['duration_seconds'] < 5) continue; // micro-switch = señal de foco, no distracción
      if ($prevProcess !== null && $proc !== $prevProcess) {
        $switches++;
      }
      $prevProcess = $proc;
    }
    return $switches;
  }

  /**
   * 2. Deep Work: agrupa episodios consecutivos de misma app (excl. leisure).
   *    Aplica ratio de actividad + penalización por títulos sospechosos y bloques >2h.
   *
   * @return array [deepWorkSeconds, deepWorkSessions, longestStreak]
   */
  private static function calculateDeepWork(PDO $pdo, array $episodes, array $leisureApps, float $activityRatio): array {
    $threshold = self::getDeepWorkThreshold($pdo);
    $leisureProcs = array_map('strtolower', $leisureApps['apps'] ?? []);
    $leisureWins  = array_map('strtolower', $leisureApps['windows'] ?? []);
    $suspiciousTitlePatterns = ['youtube', 'reddit', 'facebook', 'twitter', 'instagram', 'tiktok', 'netflix', 'twitch', 'x.com'];

    // Agrupa episodios consecutivos de mismo process_name
    $blocks = [];
    $currentBlock = null;
    foreach ($episodes as $ep) {
      $proc = strtolower(trim($ep['process_name'] ?? ''));
      if ($proc === '') continue;

      // Excluir leisure apps
      if (in_array($proc, $leisureProcs, true)) continue;
      $title = strtolower($ep['window_title'] ?? '');
      $isLeisureWindow = false;
      foreach ($leisureWins as $lw) {
        if ($lw !== '' && str_contains($title, $lw)) { $isLeisureWindow = true; break; }
      }
      if ($isLeisureWindow) continue;

      if ($currentBlock && $currentBlock['process'] === $proc) {
        $currentBlock['seconds'] += $ep['duration_seconds'];
        $currentBlock['episodes'][] = $ep;
      } else {
        if ($currentBlock) $blocks[] = $currentBlock;
        $currentBlock = [
          'process'  => $proc,
          'seconds'  => $ep['duration_seconds'],
          'episodes' => [$ep],
        ];
      }
    }
    if ($currentBlock) $blocks[] = $currentBlock;

    $totalDeepWork = 0;
    $sessionCount  = 0;
    $longestStreak = 0;

    foreach ($blocks as $block) {
      $seconds = $block['seconds'];

      // Validar con ratio de actividad
      $effectiveSeconds = (int)round($seconds * $activityRatio);

      // Penalizar títulos sospechosos en navegadores
      if (self::isBrowser($block['process'])) {
        $hasSuspicious = false;
        foreach ($block['episodes'] as $ep) {
          $t = strtolower($ep['window_title'] ?? '');
          foreach ($suspiciousTitlePatterns as $pat) {
            if (str_contains($t, $pat)) { $hasSuspicious = true; break 2; }
          }
        }
        if ($hasSuspicious) {
          $effectiveSeconds = (int)round($effectiveSeconds * 0.5);
        }
      }

      // Penalizar bloques >2h sin cambio de título (factor 0.7 después de 2h)
      if ($seconds > 7200) {
        $titlesInBlock = array_unique(array_map(fn($e) => strtolower($e['window_title'] ?? ''), $block['episodes']));
        if (count($titlesInBlock) <= 1) {
          $base = min($effectiveSeconds, 7200);
          $excess = $effectiveSeconds - $base;
          $effectiveSeconds = $base + (int)round($excess * 0.7);
        }
      }

      // Solo contar como sesión si supera umbral
      if ($effectiveSeconds >= $threshold) {
        $sessionCount++;
      }

      $totalDeepWork += $effectiveSeconds;
      $longestStreak = max($longestStreak, $effectiveSeconds);
    }

    return [$totalDeepWork, $sessionCount, $longestStreak];
  }

  /**
   * 3. Distraction: tiempo en leisure apps + ventanas de ocio en horario laboral.
   */
  private static function calculateDistraction(PDO $pdo, int $userId, int $deviceId, string $dayDate,
                                                string $workStart, string $workEnd,
                                                string $lunchStart, string $lunchEnd,
                                                array $leisureApps): int {
    $lApps = $leisureApps['apps'];
    $lWins = $leisureApps['windows'];
    if (empty($lApps) && empty($lWins)) return 0;

    $conditions = [];
    $params = [$userId, $deviceId, $dayDate, $workStart, $workEnd, $lunchStart, $lunchEnd];

    if (!empty($lApps)) {
      $ph = implode(',', array_fill(0, count($lApps), '?'));
      $conditions[] = "w.process_name IN ($ph)";
      $params = array_merge($params, array_values($lApps));
    }
    if (!empty($lWins)) {
      $likes = [];
      foreach ($lWins as $win) {
        $likes[] = "w.window_title LIKE ?";
        $params[] = '%' . $win . '%';
      }
      $conditions[] = '(' . implode(' OR ', $likes) . ')';
    }

    $orClause = implode(' OR ', $conditions);
    $st = $pdo->prepare("
      SELECT COALESCE(SUM(w.duration_seconds), 0)
      FROM keeper_window_episode w
      WHERE w.user_id = ? AND w.device_id = ? AND w.day_date = ?
        AND TIME(w.start_at) >= ? AND TIME(w.start_at) < ?
        AND NOT (TIME(w.start_at) >= ? AND TIME(w.start_at) < ?)
        AND ($orClause)
    ");
    $st->execute($params);
    return (int)$st->fetchColumn();
  }

  /**
   * 4. Puntualidad: primer episodio vs hora programada.
   *
   * @return array [firstActivityTime, scheduledStart, punctualityMinutes]
   */
  private static function calculatePunctuality(PDO $pdo, int $userId, int $deviceId,
                                                string $dayDate, string $workStart): array {
    $st = $pdo->prepare("
      SELECT MIN(start_at) AS first_at
      FROM keeper_window_episode
      WHERE user_id = :uid AND device_id = :did AND day_date = :day
      LIMIT 1
    ");
    $st->execute([':uid' => $userId, ':did' => $deviceId, ':day' => $dayDate]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $firstAt = $row['first_at'] ?? null;
    if (!$firstAt) {
      return [null, $workStart, 0];
    }

    $firstTime = date('H:i:s', strtotime($firstAt));
    $diffMinutes = (int)round((strtotime($dayDate . ' ' . $workStart) - strtotime($firstAt)) / 60);

    return [$firstTime, $workStart, $diffMinutes];
  }

  /**
   * 5. Constancia: % de bloques de 30 min con al menos 1 episodio.
   */
  private static function calculateConstancy(array $episodes, string $workStart, string $workEnd,
                                              string $lunchStart, string $lunchEnd): int {
    $wsMin = self::timeToMinutes($workStart);
    $weMin = self::timeToMinutes($workEnd);
    $lsMin = self::timeToMinutes($lunchStart);
    $leMin = self::timeToMinutes($lunchEnd);

    // Generar bloques de 30 min (excluir lunch)
    $totalBlocks = 0;
    $activeBlocks = 0;
    $blockStarts = [];

    for ($m = $wsMin; $m < $weMin; $m += 30) {
      $blockEnd = $m + 30;
      // Skip bloques que caen completamente en lunch
      if ($m >= $lsMin && $blockEnd <= $leMin) continue;
      $blockStarts[] = $m;
      $totalBlocks++;
    }

    if ($totalBlocks === 0) return 0;

    // Verificar qué bloques tienen actividad
    foreach ($blockStarts as $blockStart) {
      $blockEnd = $blockStart + 30;
      foreach ($episodes as $ep) {
        $epStart = self::timeToMinutes(date('H:i:s', strtotime($ep['start_at'])));
        if ($epStart >= $blockStart && $epStart < $blockEnd) {
          $activeBlocks++;
          break; // Solo necesitamos 1 episodio por bloque
        }
      }
    }

    return (int)round(($activeBlocks / $totalBlocks) * 100);
  }

  /**
   * 6. Focus Score compuesto (0-100).
   *
   * Componentes (con pesos configurables):
   * - context_switches: menos switches = mejor (invertido)
   * - deep_work: más deep work = mejor
   * - distraction: menos distracción = mejor (invertido)
   * - punctuality: más temprano = mejor
   * - constancy: más constante = mejor
   */
  private static function computeFocusScore(int $switches, int $deepWorkSec, int $distractionSec,
                                             int $punctualityMin, int $constancyPct,
                                             float $workTotal, array $weights): int {
    if ($workTotal <= 0) return 0;

    // Normalizar cada componente a 0-100

    // Context switches: 0 = 100, ≥80 = 0 (lineal invertido)
    $switchScore = max(0, 100 - ($switches * 100 / 80));

    // Deep work ratio: deep_work / work_total * 100
    $deepWorkScore = min(100, ($deepWorkSec / $workTotal) * 100);

    // Distracción: invertido, 0% = 100, ≥30% = 0
    $distractionPct = ($distractionSec / $workTotal) * 100;
    $distractionScore = max(0, 100 - ($distractionPct * 100 / 30));

    // Puntualidad: +15min o más temprano = 100, cada min tarde resta ~3.3 pts
    $punctualityScore = $punctualityMin >= 0
      ? 100 // On time or early
      : max(0, 100 + ($punctualityMin * 100 / 30)); // Late: -30min = 0

    // Constancia: ya es 0-100
    $constancyScore = min(100, max(0, $constancyPct));

    // Ponderar
    $total = $weights['context_switches'] + $weights['deep_work'] + $weights['distraction']
           + $weights['punctuality'] + $weights['constancy'];
    if ($total <= 0) $total = 100;

    $score = (
      $switchScore     * $weights['context_switches'] +
      $deepWorkScore   * $weights['deep_work'] +
      $distractionScore * $weights['distraction'] +
      $punctualityScore * $weights['punctuality'] +
      $constancyScore  * $weights['constancy']
    ) / $total;

    return min(100, max(0, (int)round($score)));
  }

  // ================================================================
  // PERSISTENCE
  // ================================================================

  private static function upsert(PDO $pdo, array $data): void {
    $st = $pdo->prepare("
      INSERT INTO keeper_focus_daily
        (user_id, device_id, day_date, context_switches, deep_work_seconds, deep_work_sessions,
         distraction_seconds, longest_focus_streak_seconds, focus_score, productivity_pct,
         constancy_pct, first_activity_time, scheduled_start, punctuality_minutes)
      VALUES
        (:user_id, :device_id, :day_date, :context_switches, :deep_work_seconds, :deep_work_sessions,
         :distraction_seconds, :longest_focus_streak_seconds, :focus_score, :productivity_pct,
         :constancy_pct, :first_activity_time, :scheduled_start, :punctuality_minutes)
      ON DUPLICATE KEY UPDATE
        context_switches             = VALUES(context_switches),
        deep_work_seconds            = VALUES(deep_work_seconds),
        deep_work_sessions           = VALUES(deep_work_sessions),
        distraction_seconds          = VALUES(distraction_seconds),
        longest_focus_streak_seconds = VALUES(longest_focus_streak_seconds),
        focus_score                  = VALUES(focus_score),
        productivity_pct             = VALUES(productivity_pct),
        constancy_pct                = VALUES(constancy_pct),
        first_activity_time          = VALUES(first_activity_time),
        scheduled_start              = VALUES(scheduled_start),
        punctuality_minutes          = VALUES(punctuality_minutes),
        updated_at                   = NOW()
    ");
    $st->execute($data);
  }

  // ================================================================
  // HELPERS
  // ================================================================

  private static function isBrowser(string $process): bool {
    $browsers = ['chrome', 'msedge', 'firefox', 'brave', 'opera', 'vivaldi', 'safari', 'iexplore', 'microsoftedge'];
    $proc = strtolower($process);
    foreach ($browsers as $b) {
      if (str_contains($proc, $b)) return true;
    }
    return false;
  }

  private static function timeToMinutes(string $time): int {
    $parts = explode(':', $time);
    return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
  }
}
