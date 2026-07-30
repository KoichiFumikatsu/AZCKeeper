<?php
namespace Keeper\Repos;

use PDO;

/**
 * Cobertura de instalacion: cruza los usuarios activos contra sus equipos para
 * saber a quien falta instalar el cliente. En Keeper 3 se clavaba por
 * legacy_employee_id para sobrevivir a no-enrolados; en K4 el usuario ya existe
 * por import, asi que la clave por user_id basta.
 */
class CoverageRepo {

  /** Umbral por defecto de "sin reportar" en dias. */
  public const STALE_DAYS = 7;

  public static function list(PDO $pdo, int $staleDays = self::STALE_DAYS): array {
    // Un dispositivo activo (el mas reciente) por usuario + su ultimo last_seen.
    $st = $pdo->prepare("
      SELECT u.id AS user_id, u.cc, u.display_name,
             COUNT(d.id) AS device_count,
             MAX(d.last_seen_at) AS last_seen,
             cn.is_exempt, cn.note
      FROM keeper_users u
      LEFT JOIN keeper_devices d ON d.user_id = u.id AND d.status = 'active'
      LEFT JOIN keeper_coverage_note cn ON cn.user_id = u.id
      WHERE u.status = 'active' AND u.employment_status = 'active'
      GROUP BY u.id, u.cc, u.display_name, cn.is_exempt, cn.note
      ORDER BY u.display_name
    ");
    $st->execute();
    $rows = $st->fetchAll();

    $staleTs = time() - $staleDays * 86400;
    foreach ($rows as &$r) {
      $r['is_exempt'] = (int)($r['is_exempt'] ?? 0);
      if ($r['is_exempt']) {
        $r['reason'] = 'exempt';
      } elseif ((int)$r['device_count'] === 0) {
        $r['reason'] = 'never_enrolled';
      } elseif (!$r['last_seen']) {
        $r['reason'] = 'no_report';
      } elseif (strtotime($r['last_seen']) < $staleTs) {
        $r['reason'] = 'stale';
      } else {
        $r['reason'] = 'covered';
      }
    }
    unset($r);
    return $rows;
  }

  public static function setNote(PDO $pdo, int $userId, bool $isExempt, ?string $note, ?int $adminId): void {
    $st = $pdo->prepare("
      INSERT INTO keeper_coverage_note (user_id, is_exempt, note, updated_by)
      VALUES (:u, :e, :n, :a)
      ON DUPLICATE KEY UPDATE is_exempt = VALUES(is_exempt), note = VALUES(note), updated_by = VALUES(updated_by)
    ");
    $st->execute([':u' => $userId, ':e' => $isExempt ? 1 : 0, ':n' => $note, ':a' => $adminId]);
  }
}
