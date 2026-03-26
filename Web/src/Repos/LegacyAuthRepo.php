<?php
namespace Keeper\Repos;

use PDO;

class LegacyAuthRepo {

  /**
   * Login legacy por CC + password (password en texto plano).
   * Username debe ser numérico (cc).
   *
   * Retorna info para keeper_users:
   * - legacy_employee_id
   * - email (mail o personal_mail)
   * - display_name
   * - role, supervisor_id, area_id, position_id, company
   */
  public static function validate(PDO $legacyPdo, string $username, string $password): ?array {

    $cc = trim($username);
    if ($cc === '' || !ctype_digit($cc)) {
      return null; // producción: solo CC
    }

    $sql = "
      SELECT
        e.id AS legacy_employee_id,
        e.mail,
        e.personal_mail,
        CONCAT(
          TRIM(COALESCE(e.first_Name,'')),
          ' ',
          TRIM(COALESCE(e.second_Name,'')),
          ' ',
          TRIM(COALESCE(e.first_LastName,'')),
          ' ',
          TRIM(COALESCE(e.second_LastName,''))
        ) AS display_name,
        e.role,
        e.supervisor_id,
        e.area_id,
        e.position_id,
        e.company
      FROM employee e
      WHERE
        e.cc = :cc
        AND e.password = :p
      LIMIT 1
    ";

    $st = $legacyPdo->prepare($sql);
    $st->execute([
      ':cc' => (int)$cc,
      ':p'  => $password
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $row['email'] = $row['mail'] ?: ($row['personal_mail'] ?? null);

    return $row;
  }
}
