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

  /**
   * @param int|null $firmaId Recorta a una firma (scope de un admin de firma). null = todas.
   *
   * Los conteos de equipos van por subconsulta y no por COUNT+GROUP BY: al sumar los JOIN
   * organizacionales (firma/cargo/sede) un GROUP BY sobre el join inflaria device_count.
   * keeper_user_assignments tiene UNIQUE(user_id), asi que ese join no duplica filas.
   */
  public static function list(PDO $pdo, int $staleDays = self::STALE_DAYS, ?int $firmaId = null): array {
    $sql = "
      SELECT u.id AS user_id, u.cc, u.display_name,
             (SELECT COUNT(*) FROM keeper_devices d
               WHERE d.user_id = u.id AND d.status = 'active') AS device_count,
             (SELECT MAX(d2.last_seen_at) FROM keeper_devices d2
               WHERE d2.user_id = u.id AND d2.status = 'active') AS last_seen,
             cn.is_exempt, cn.note,
             f.nombre AS firma, c.nombre AS cargo, se.nombre AS sede
      FROM keeper_users u
      LEFT JOIN keeper_coverage_note cn   ON cn.user_id = u.id
      LEFT JOIN keeper_user_assignments a ON a.user_id  = u.id
      LEFT JOIN keeper_firmas f  ON f.id  = a.firma_id
      LEFT JOIN keeper_cargos c  ON c.id  = a.cargo_id
      LEFT JOIN keeper_sedes  se ON se.id = a.sede_id
      WHERE u.status = 'active' AND u.employment_status = 'active'
    ";
    if ($firmaId !== null) $sql .= " AND a.firma_id = :firma ";
    $sql .= " ORDER BY u.display_name IS NULL, u.display_name, u.cc";

    $st = $pdo->prepare($sql);
    if ($firmaId !== null) $st->bindValue(':firma', $firmaId, PDO::PARAM_INT);
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
