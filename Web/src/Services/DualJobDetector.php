<?php
namespace Keeper\Services;

use PDO;

/**
 * Detecta patrones sospechosos de doble empleo en una ventana deslizante de 30 días.
 * Genera alertas para revisión humana — nunca bloquea automáticamente.
 */
class DualJobDetector {

  private const WINDOW_DAYS = 30;
  private const DEDUP_DAYS  = 7;

  /**
   * Analiza un usuario en busca de señales de doble empleo.
   * Crea alertas en keeper_dual_job_alerts si se detectan patrones.
   *
   * @param PDO $pdo
   * @param int $userId
   * @return int Cantidad de alertas creadas
   */
  public static function analyze(PDO $pdo, int $userId): int {
    $alertsCreated = 0;
    $today = date('Y-m-d');
    $windowStart = date('Y-m-d', strtotime("-" . self::WINDOW_DAYS . " days"));

    // Verificar si dual_job está habilitado
    if (!self::isEnabled($pdo)) return 0;

    $settings = self::getSettings($pdo);
    $schedule = \Keeper\Repos\PolicyRepo::getWorkSchedule($pdo, $userId);

    // 1. After-Hours Pattern
    $alertsCreated += self::checkAfterHoursPattern($pdo, $userId, $windowStart, $today, $settings);

    // 2. Foreign Apps (suspicious apps)
    $alertsCreated += self::checkForeignApps($pdo, $userId, $windowStart, $today, $schedule);

    // 3. Remote Desktop / VMs en horario laboral
    $alertsCreated += self::checkRemoteDesktop($pdo, $userId, $windowStart, $today, $schedule);

    // 4. Suspicious Idle (alta inactividad + after-hours)
    $alertsCreated += self::checkSuspiciousIdle($pdo, $userId, $windowStart, $today);

    return $alertsCreated;
  }

  // ================================================================
  // DETECTION METHODS
  // ================================================================

  /**
   * 1. After-Hours Pattern: ≥N días con >X segundos de actividad after-hours.
   */
  private static function checkAfterHoursPattern(PDO $pdo, int $userId,
                                                  string $from, string $to, array $settings): int {
    if (self::hasRecentAlert($pdo, $userId, 'after_hours_pattern')) return 0;

    $thresholdDays    = (int)($settings['after_hours_threshold_days'] ?? 5);
    $minSeconds       = (int)($settings['after_hours_min_seconds'] ?? 3600);

    $st = $pdo->prepare("
      SELECT day_date, after_hours_active_seconds
      FROM keeper_activity_day
      WHERE user_id = :uid AND day_date BETWEEN :from AND :to
        AND after_hours_active_seconds >= :min_sec
        AND is_workday = 1
      ORDER BY day_date DESC
    ");
    $st->execute([':uid' => $userId, ':from' => $from, ':to' => $to, ':min_sec' => $minSeconds]);
    $days = $st->fetchAll(PDO::FETCH_ASSOC);
    $count = count($days);

    if ($count < $thresholdDays) return 0;

    $severity = $count >= 15 ? 'high' : 'medium';
    $evidence = [
      'days_with_after_hours' => $count,
      'threshold_days'        => $thresholdDays,
      'min_seconds'           => $minSeconds,
      'sample_days'           => array_slice(array_map(fn($d) => [
        'date'    => $d['day_date'],
        'seconds' => (int)$d['after_hours_active_seconds'],
      ], $days), 0, 10),
    ];

    return self::createAlert($pdo, $userId, $to, 'after_hours_pattern', $severity, $evidence);
  }

  /**
   * 2. Foreign Apps: uso de apps sospechosas (keeper_suspicious_apps).
   */
  private static function checkForeignApps(PDO $pdo, int $userId,
                                            string $from, string $to, array $schedule): int {
    if (self::hasRecentAlert($pdo, $userId, 'foreign_app')) return 0;

    // Obtener patrones activos
    $st = $pdo->prepare("SELECT app_pattern, category, description FROM keeper_suspicious_apps WHERE is_active = 1");
    $st->execute();
    $patterns = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($patterns)) return 0;

    // Buscar coincidencias en episodios
    $conditions = [];
    $params = [$userId, $from, $to];
    foreach ($patterns as $p) {
      $conditions[] = "LOWER(w.process_name) LIKE ?";
      $params[] = '%' . strtolower($p['app_pattern']) . '%';
      $conditions[] = "LOWER(w.window_title) LIKE ?";
      $params[] = '%' . strtolower($p['app_pattern']) . '%';
    }
    $orClause = implode(' OR ', $conditions);

    $st = $pdo->prepare("
      SELECT w.day_date, w.process_name, w.window_title,
             SUM(w.duration_seconds) AS total_seconds,
             COUNT(*) AS episode_count
      FROM keeper_window_episode w
      WHERE w.user_id = ? AND w.day_date BETWEEN ? AND ?
        AND ($orClause)
      GROUP BY w.day_date, w.process_name, w.window_title
      HAVING total_seconds >= 60
      ORDER BY total_seconds DESC
      LIMIT 20
    ");
    $st->execute($params);
    $matches = $st->fetchAll(PDO::FETCH_ASSOC);

    if (empty($matches)) return 0;

    $totalDays = count(array_unique(array_column($matches, 'day_date')));
    $totalSec  = array_sum(array_column($matches, 'total_seconds'));

    $severity = ($totalDays >= 5 || $totalSec >= 7200) ? 'high'
              : ($totalDays >= 3 ? 'medium' : 'low');

    $evidence = [
      'distinct_days'   => $totalDays,
      'total_seconds'   => $totalSec,
      'matches'         => array_slice(array_map(fn($m) => [
        'date'         => $m['day_date'],
        'process'      => $m['process_name'],
        'title'        => mb_substr($m['window_title'] ?? '', 0, 100),
        'seconds'      => (int)$m['total_seconds'],
        'episodes'     => (int)$m['episode_count'],
      ], $matches), 0, 10),
    ];

    return self::createAlert($pdo, $userId, $to, 'foreign_app', $severity, $evidence);
  }

  /**
   * 3. Remote Desktop/VMs: >30 min/día en horario laboral, ≥3 días.
   */
  private static function checkRemoteDesktop(PDO $pdo, int $userId,
                                              string $from, string $to, array $schedule): int {
    if (self::hasRecentAlert($pdo, $userId, 'remote_desktop')) return 0;

    $workStart = $schedule['workStartTime'];
    $workEnd   = $schedule['workEndTime'];

    // Solo patrones de categoría remote_desktop y vm
    $st = $pdo->prepare("
      SELECT app_pattern FROM keeper_suspicious_apps
      WHERE is_active = 1 AND category IN ('remote_desktop', 'vm')
    ");
    $st->execute();
    $patterns = $st->fetchAll(PDO::FETCH_COLUMN);
    if (empty($patterns)) return 0;

    $conditions = [];
    $params = [$userId, $from, $to, $workStart, $workEnd];
    foreach ($patterns as $p) {
      $conditions[] = "LOWER(w.process_name) LIKE ?";
      $params[] = '%' . strtolower($p) . '%';
    }
    $orClause = implode(' OR ', $conditions);

    $st = $pdo->prepare("
      SELECT w.day_date, SUM(w.duration_seconds) AS total_sec
      FROM keeper_window_episode w
      WHERE w.user_id = ? AND w.day_date BETWEEN ? AND ?
        AND TIME(w.start_at) >= ? AND TIME(w.start_at) < ?
        AND ($orClause)
      GROUP BY w.day_date
      HAVING total_sec >= 1800
      ORDER BY total_sec DESC
    ");
    $st->execute($params);
    $days = $st->fetchAll(PDO::FETCH_ASSOC);

    if (count($days) < 3) return 0;

    $severity = count($days) >= 10 ? 'high' : 'medium';
    $evidence = [
      'days_detected' => count($days),
      'sample_days'   => array_slice(array_map(fn($d) => [
        'date'    => $d['day_date'],
        'seconds' => (int)$d['total_sec'],
      ], $days), 0, 10),
    ];

    return self::createAlert($pdo, $userId, $to, 'remote_desktop', $severity, $evidence);
  }

  /**
   * 4. Suspicious Idle: >70% idle en horario + >30 min after-hours, ≥5 días.
   */
  private static function checkSuspiciousIdle(PDO $pdo, int $userId,
                                               string $from, string $to): int {
    if (self::hasRecentAlert($pdo, $userId, 'suspicious_idle')) return 0;

    $st = $pdo->prepare("
      SELECT day_date,
             work_hours_active_seconds AS wa,
             work_hours_idle_seconds AS wi,
             after_hours_active_seconds AS aha
      FROM keeper_activity_day
      WHERE user_id = :uid AND day_date BETWEEN :from AND :to
        AND is_workday = 1
    ");
    $st->execute([':uid' => $userId, ':from' => $from, ':to' => $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $suspiciousDays = [];
    foreach ($rows as $r) {
      $workTotal = (float)$r['wa'] + (float)$r['wi'];
      if ($workTotal <= 0) continue;
      $idlePct = (float)$r['wi'] / $workTotal;
      $afterHoursActive = (float)$r['aha'];

      if ($idlePct >= 0.70 && $afterHoursActive >= 1800) {
        $suspiciousDays[] = [
          'date'              => $r['day_date'],
          'idle_pct'          => round($idlePct * 100, 1),
          'after_hours_sec'   => (int)$afterHoursActive,
        ];
      }
    }

    if (count($suspiciousDays) < 5) return 0;

    $severity = count($suspiciousDays) >= 15 ? 'high' : 'medium';
    $evidence = [
      'days_detected' => count($suspiciousDays),
      'sample_days'   => array_slice($suspiciousDays, 0, 10),
    ];

    return self::createAlert($pdo, $userId, $to, 'suspicious_idle', $severity, $evidence);
  }

  // ================================================================
  // HELPERS
  // ================================================================

  private static function isEnabled(PDO $pdo): bool {
    $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'dual_job.enabled' LIMIT 1");
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row && strtolower(trim($row['setting_value'], '"')) === 'true';
  }

  private static function getSettings(PDO $pdo): array {
    $keys = ['dual_job.after_hours_threshold_days', 'dual_job.after_hours_min_seconds'];
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT setting_key, setting_value FROM keeper_panel_settings WHERE setting_key IN ($ph)");
    $st->execute($keys);
    $settings = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $key = str_replace('dual_job.', '', $row['setting_key']);
      $settings[$key] = json_decode($row['setting_value'], true) ?? $row['setting_value'];
    }
    return $settings;
  }

  private static function hasRecentAlert(PDO $pdo, int $userId, string $alertType): bool {
    $st = $pdo->prepare("
      SELECT 1 FROM keeper_dual_job_alerts
      WHERE user_id = :uid AND alert_type = :type
        AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
      LIMIT 1
    ");
    $st->execute([':uid' => $userId, ':type' => $alertType, ':days' => self::DEDUP_DAYS]);
    return (bool)$st->fetchColumn();
  }

  private static function createAlert(PDO $pdo, int $userId, string $dayDate,
                                       string $alertType, string $severity, array $evidence): int {
    $st = $pdo->prepare("
      INSERT INTO keeper_dual_job_alerts
        (user_id, day_date, alert_type, severity, evidence_json)
      VALUES
        (:uid, :day, :type, :sev, :evidence)
    ");
    $st->execute([
      ':uid'      => $userId,
      ':day'      => $dayDate,
      ':type'     => $alertType,
      ':sev'      => $severity,
      ':evidence' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
    ]);
    return 1;
  }
}
