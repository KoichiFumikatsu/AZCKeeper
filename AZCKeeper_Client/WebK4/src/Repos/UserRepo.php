<?php
namespace Keeper\Repos;

use PDO;

class UserRepo {

  public static function findByCc(PDO $pdo, string $cc): ?array {
    $st = $pdo->prepare("SELECT id, cc, display_name, status, employment_status, password_hash FROM keeper_users WHERE cc = :cc LIMIT 1");
    $st->execute([':cc' => $cc]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /** Fija (o limpia con null) el hash de contraseña de un usuario. */
  public static function setPassword(PDO $pdo, int $userId, ?string $hash): void {
    $st = $pdo->prepare("UPDATE keeper_users SET password_hash = :h WHERE id = :id");
    $st->execute([':h' => $hash, ':id' => $userId]);
  }

  /** Crea un usuario en estado 'pending' = solicitud de enrolamiento por CC desconocida. */
  public static function createPending(PDO $pdo, string $cc, ?string $displayName): int {
    $st = $pdo->prepare("INSERT INTO keeper_users (cc, display_name, status) VALUES (:cc, :n, 'pending')");
    $st->execute([':cc' => $cc, ':n' => $displayName]);
    return (int)$pdo->lastInsertId();
  }

  public static function setStatus(PDO $pdo, int $userId, string $status): bool {
    $st = $pdo->prepare("UPDATE keeper_users SET status = :s WHERE id = :id");
    $st->execute([':s' => $status, ':id' => $userId]);
    return $st->rowCount() > 0;
  }
}
