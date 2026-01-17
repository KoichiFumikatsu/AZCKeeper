<?php
namespace Keeper\Repos;

use PDO;

class UserRepo {

  public static function findByLegacyEmployeeId(PDO $pdo, int $legacyId): ?array {
    $st = $pdo->prepare("SELECT * FROM keeper_users WHERE legacy_employee_id=:id LIMIT 1");
    $st->execute([':id' => $legacyId]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function createFromLegacy(PDO $pdo, int $legacyId, ?string $email, ?string $displayName): int {
    $st = $pdo->prepare("
      INSERT INTO keeper_users (legacy_employee_id, email, display_name, status, created_at, updated_at)
      VALUES (:legacy_id, :email, :name, 'active', NOW(), NOW())
    ");
    $st->execute([
      ':legacy_id' => $legacyId,
      ':email' => $email,
      ':name' => $displayName ?: ('LegacyUser#' . $legacyId),
    ]);
    return (int)$pdo->lastInsertId();
  }
}
