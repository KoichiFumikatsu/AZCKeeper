<?php
namespace Keeper\Repos;

use PDO;

/**
 * Repositorio para consultar métricas de productividad y alertas de doble empleo.
 * Lectura de datos pre-calculados en keeper_focus_daily y keeper_dual_job_alerts.
 */
class ProductivityRepo {

  /**
   * Métricas diarias de un usuario (para user-dashboard).
   */
  public static function getDailyMetrics(PDO $pdo, int $userId, string $from, string $to): array {
    $st = $pdo->prepare("
      SELECT f.day_date, f.focus_score, f.productivity_pct, f.constancy_pct,
             f.context_switches, f.deep_work_seconds, f.deep_work_sessions,
             f.distraction_seconds, f.longest_focus_streak_seconds,
             f.first_activity_time, f.scheduled_start, f.punctuality_minutes
      FROM keeper_focus_daily f
      WHERE f.user_id = :uid AND f.day_date BETWEEN :from AND :to
      ORDER BY f.day_date DESC
    ");
    $st->execute([':uid' => $userId, ':from' => $from, ':to' => $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Focus score promedio de un usuario en un rango.
   */
  public static function getAvgFocusScore(PDO $pdo, int $userId, string $from, string $to): ?float {
    $st = $pdo->prepare("
      SELECT AVG(f.focus_score) AS avg_score
      FROM keeper_focus_daily f
      WHERE f.user_id = :uid AND f.day_date BETWEEN :from AND :to
    ");
    $st->execute([':uid' => $userId, ':from' => $from, ':to' => $to]);
    $val = $st->fetchColumn();
    return $val !== false ? round((float)$val, 1) : null;
  }

  /**
   * KPIs globales para el dashboard de productividad.
   * Filtrado por scope del admin.
   */
  public static function getGlobalKPIs(PDO $pdo, string $from, string $to,
                                        string $scopeSql = '', array $scopeParams = []): array {
    $st = $pdo->prepare("
      SELECT
        ROUND(AVG(f.focus_score), 1)       AS avg_focus,
        ROUND(AVG(f.productivity_pct), 1)  AS avg_productivity,
        ROUND(AVG(f.constancy_pct), 1)     AS avg_constancy,
        ROUND(AVG(f.context_switches), 1)  AS avg_switches,
        ROUND(AVG(f.deep_work_seconds), 0) AS avg_deep_work_sec,
        ROUND(AVG(f.punctuality_minutes), 1) AS avg_punctuality,
        COUNT(DISTINCT f.user_id)          AS user_count
      FROM keeper_focus_daily f
      INNER JOIN keeper_users u ON u.id = f.user_id AND u.status = 'active'
      LEFT JOIN keeper_user_assignments ka ON ka.keeper_user_id = u.id
      WHERE f.day_date BETWEEN :from AND :to
      $scopeSql
    ");
    $params = array_merge([':from' => $from, ':to' => $to], $scopeParams);
    $st->execute($params);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * Ranking individual: top/bottom usuarios por focus score.
   */
  public static function getUserRanking(PDO $pdo, string $from, string $to,
                                         string $scopeSql = '', array $scopeParams = [],
                                         string $order = 'DESC', int $limit = 20, int $offset = 0): array {
    $orderSafe = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    $st = $pdo->prepare("
      SELECT
        u.id AS user_id, u.display_name, u.email,
        ka.firm_id, ka.area_id, ka.sede_id,
        COALESCE(fi.nombre, '') AS firma_nombre,
        COALESCE(ar.nombre, '') AS area_nombre,
        ROUND(AVG(f.focus_score), 1) AS avg_focus,
        ROUND(AVG(f.productivity_pct), 1) AS avg_productivity,
        ROUND(AVG(f.constancy_pct), 1) AS avg_constancy,
        ROUND(AVG(f.context_switches), 0) AS avg_switches,
        ROUND(AVG(f.deep_work_seconds), 0) AS avg_deep_work_sec,
        COUNT(f.id) AS days_tracked
      FROM keeper_focus_daily f
      INNER JOIN keeper_users u ON u.id = f.user_id AND u.status = 'active'
      LEFT JOIN keeper_user_assignments ka ON ka.keeper_user_id = u.id
      LEFT JOIN keeper_firmas fi ON fi.id = ka.firm_id
      LEFT JOIN keeper_areas ar ON ar.id = ka.area_id
      WHERE f.day_date BETWEEN :from AND :to
      $scopeSql
      GROUP BY u.id
      HAVING days_tracked > 0
      ORDER BY avg_focus $orderSafe
      LIMIT :lim OFFSET :off
    ");
    $params = array_merge([':from' => $from, ':to' => $to, ':lim' => $limit, ':off' => $offset], $scopeParams);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Total de usuarios con métricas (para paginación).
   */
  public static function getUserRankingCount(PDO $pdo, string $from, string $to,
                                              string $scopeSql = '', array $scopeParams = []): int {
    $st = $pdo->prepare("
      SELECT COUNT(DISTINCT f.user_id)
      FROM keeper_focus_daily f
      INNER JOIN keeper_users u ON u.id = f.user_id AND u.status = 'active'
      LEFT JOIN keeper_user_assignments ka ON ka.keeper_user_id = u.id
      WHERE f.day_date BETWEEN :from AND :to
      $scopeSql
    ");
    $params = array_merge([':from' => $from, ':to' => $to], $scopeParams);
    $st->execute($params);
    return (int)$st->fetchColumn();
  }

  /**
   * Ranking por equipo (firma o área).
   */
  public static function getTeamRanking(PDO $pdo, string $from, string $to,
                                         string $groupBy = 'firm',
                                         string $scopeSql = '', array $scopeParams = []): array {
    $groupCol = $groupBy === 'area' ? 'ka.area_id' : 'ka.firm_id';
    $nameJoin = $groupBy === 'area'
      ? 'LEFT JOIN keeper_areas grp ON grp.id = ka.area_id'
      : 'LEFT JOIN keeper_firmas grp ON grp.id = ka.firm_id';

    $st = $pdo->prepare("
      SELECT
        $groupCol AS group_id,
        COALESCE(grp.nombre, 'Sin asignar') AS group_name,
        ROUND(AVG(f.focus_score), 1)       AS avg_focus,
        ROUND(AVG(f.productivity_pct), 1)  AS avg_productivity,
        ROUND(AVG(f.constancy_pct), 1)     AS avg_constancy,
        COUNT(DISTINCT f.user_id)          AS user_count
      FROM keeper_focus_daily f
      INNER JOIN keeper_users u ON u.id = f.user_id AND u.status = 'active'
      LEFT JOIN keeper_user_assignments ka ON ka.keeper_user_id = u.id
      $nameJoin
      WHERE f.day_date BETWEEN :from AND :to
      $scopeSql
      GROUP BY $groupCol
      ORDER BY avg_focus DESC
    ");
    $params = array_merge([':from' => $from, ':to' => $to], $scopeParams);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Tendencias semanales (últimas N semanas).
   */
  public static function getWeeklyTrends(PDO $pdo, string $from, string $to,
                                          string $scopeSql = '', array $scopeParams = []): array {
    $st = $pdo->prepare("
      SELECT
        YEARWEEK(f.day_date, 1) AS yw,
        MIN(f.day_date) AS week_start,
        ROUND(AVG(f.focus_score), 1)       AS avg_focus,
        ROUND(AVG(f.productivity_pct), 1)  AS avg_productivity,
        ROUND(AVG(f.constancy_pct), 1)     AS avg_constancy,
        ROUND(AVG(f.context_switches), 0)  AS avg_switches,
        COUNT(DISTINCT f.user_id)          AS user_count
      FROM keeper_focus_daily f
      INNER JOIN keeper_users u ON u.id = f.user_id AND u.status = 'active'
      LEFT JOIN keeper_user_assignments ka ON ka.keeper_user_id = u.id
      WHERE f.day_date BETWEEN :from AND :to
      $scopeSql
      GROUP BY yw
      ORDER BY yw ASC
    ");
    $params = array_merge([':from' => $from, ':to' => $to], $scopeParams);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  // ================================================================
  // ALERTAS DUAL-JOB
  // ================================================================

  /**
   * Obtener alertas con filtros.
   */
  public static function getAlerts(PDO $pdo, string $scopeSql = '', array $scopeParams = [],
                                    ?string $type = null, ?string $severity = null,
                                    ?bool $reviewed = null, int $limit = 50, int $offset = 0): array {
    $where = '';
    $params = $scopeParams;

    if ($type !== null) {
      $where .= ' AND a.alert_type = :type';
      $params[':type'] = $type;
    }
    if ($severity !== null) {
      $where .= ' AND a.severity = :sev';
      $params[':sev'] = $severity;
    }
    if ($reviewed !== null) {
      $where .= ' AND a.is_reviewed = :rev';
      $params[':rev'] = $reviewed ? 1 : 0;
    }

    $st = $pdo->prepare("
      SELECT a.*, u.display_name, u.email,
             COALESCE(fi.nombre, '') AS firma_nombre,
             COALESCE(ar.nombre, '') AS area_nombre,
             rev_admin.display_name AS reviewed_by_name
      FROM keeper_dual_job_alerts a
      INNER JOIN keeper_users u ON u.id = a.user_id
      LEFT JOIN keeper_user_assignments ka ON ka.keeper_user_id = u.id
      LEFT JOIN keeper_firmas fi ON fi.id = ka.firm_id
      LEFT JOIN keeper_areas ar ON ar.id = ka.area_id
      LEFT JOIN keeper_admin_accounts rev_acc ON rev_acc.id = a.reviewed_by
      LEFT JOIN keeper_users rev_admin ON rev_admin.id = rev_acc.keeper_user_id
      WHERE 1=1 $scopeSql $where
      ORDER BY a.is_reviewed ASC, a.severity DESC, a.created_at DESC
      LIMIT :lim OFFSET :off
    ");
    $params[':lim'] = $limit;
    $params[':off'] = $offset;
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Contar alertas (para paginación y KPIs).
   */
  public static function getAlertCounts(PDO $pdo, string $scopeSql = '', array $scopeParams = []): array {
    $st = $pdo->prepare("
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN a.is_reviewed = 0 THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN a.severity = 'high' AND a.is_reviewed = 0 THEN 1 ELSE 0 END) AS high_pending,
        SUM(CASE WHEN a.severity = 'medium' AND a.is_reviewed = 0 THEN 1 ELSE 0 END) AS medium_pending,
        SUM(CASE WHEN a.severity = 'low' AND a.is_reviewed = 0 THEN 1 ELSE 0 END) AS low_pending
      FROM keeper_dual_job_alerts a
      INNER JOIN keeper_users u ON u.id = a.user_id
      LEFT JOIN keeper_user_assignments ka ON ka.keeper_user_id = u.id
      WHERE 1=1 $scopeSql
    ");
    $st->execute($scopeParams);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'pending' => 0, 'high_pending' => 0, 'medium_pending' => 0, 'low_pending' => 0];
  }

  /**
   * Obtener alertas de un usuario.
   */
  public static function getUserAlerts(PDO $pdo, int $userId, int $limit = 10): array {
    $st = $pdo->prepare("
      SELECT a.*
      FROM keeper_dual_job_alerts a
      WHERE a.user_id = :uid
      ORDER BY a.created_at DESC
      LIMIT :lim
    ");
    $st->execute([':uid' => $userId, ':lim' => $limit]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Marcar alerta como revisada con resultado (productive / unproductive).
   */
  public static function reviewAlert(PDO $pdo, int $alertId, int $adminId, string $result, string $notes = ''): bool {
    $validResults = ['productive', 'unproductive'];
    if (!in_array($result, $validResults, true)) return false;
    $st = $pdo->prepare("
      UPDATE keeper_dual_job_alerts
      SET is_reviewed = 1, review_result = :result, reviewed_by = :admin, reviewed_at = NOW(), notes = :notes
      WHERE id = :id
    ");
    return $st->execute([':id' => $alertId, ':result' => $result, ':admin' => $adminId, ':notes' => $notes]);
  }

  /**
   * Obtener apps sospechosas (para CRUD en settings).
   */
  public static function getSuspiciousApps(PDO $pdo, bool $activeOnly = true): array {
    $where = $activeOnly ? 'WHERE is_active = 1' : '';
    $st = $pdo->query("SELECT * FROM keeper_suspicious_apps $where ORDER BY category, app_pattern");
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Agregar app sospechosa.
   */
  public static function addSuspiciousApp(PDO $pdo, string $pattern, string $category, string $desc = ''): bool {
    $st = $pdo->prepare("
      INSERT INTO keeper_suspicious_apps (app_pattern, category, description)
      VALUES (:pat, :cat, :desc)
      ON DUPLICATE KEY UPDATE category = VALUES(category), description = VALUES(description), is_active = 1
    ");
    return $st->execute([':pat' => $pattern, ':cat' => $category, ':desc' => $desc]);
  }

  /**
   * Toggle app sospechosa.
   */
  public static function toggleSuspiciousApp(PDO $pdo, int $id, bool $active): bool {
    $st = $pdo->prepare("UPDATE keeper_suspicious_apps SET is_active = :active WHERE id = :id");
    return $st->execute([':id' => $id, ':active' => $active ? 1 : 0]);
  }

  /**
   * Eliminar app sospechosa.
   */
  public static function deleteSuspiciousApp(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("DELETE FROM keeper_suspicious_apps WHERE id = :id");
    return $st->execute([':id' => $id]);
  }

  // ==================== APP CLASSIFICATIONS ====================

  public static function getAppClassifications(PDO $pdo, bool $activeOnly = true): array {
    $where = $activeOnly ? 'WHERE is_active = 1' : '';
    $st = $pdo->query("SELECT * FROM keeper_app_classifications $where ORDER BY classification, app_pattern");
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  public static function addAppClassification(PDO $pdo, string $pattern, string $classification, string $desc = ''): bool {
    $valid = ['productive', 'unproductive'];
    if (!in_array($classification, $valid, true)) return false;
    $st = $pdo->prepare("
      INSERT INTO keeper_app_classifications (app_pattern, classification, description)
      VALUES (:pat, :cls, :desc)
      ON DUPLICATE KEY UPDATE classification = VALUES(classification), description = VALUES(description), is_active = 1
    ");
    return $st->execute([':pat' => $pattern, ':cls' => $classification, ':desc' => $desc]);
  }

  public static function toggleAppClassification(PDO $pdo, int $id, bool $active): bool {
    $st = $pdo->prepare("UPDATE keeper_app_classifications SET is_active = :active WHERE id = :id");
    return $st->execute([':id' => $id, ':active' => $active ? 1 : 0]);
  }

  public static function deleteAppClassification(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("DELETE FROM keeper_app_classifications WHERE id = :id");
    return $st->execute([':id' => $id]);
  }
}
