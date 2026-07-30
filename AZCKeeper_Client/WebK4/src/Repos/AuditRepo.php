<?php
namespace Keeper\Repos;

use PDO;

/**
 * Bitacora con ACTOR. En Keeper 3 la tabla tenia sujeto (user_id/device_id) pero
 * no admin_id, asi que podia decir a quien pero nunca quien. keeper_audit_log de
 * K4 lleva admin_id (el actor) y event_category (admin|import|security|data_access|system).
 */
class AuditRepo {

  public static function log(
    PDO $pdo,
    ?int $adminId,
    ?int $userId,
    ?int $deviceId,
    string $category,
    string $type,
    ?string $message = null,
    ?array $meta = null
  ): void {
    $st = $pdo->prepare("
      INSERT INTO keeper_audit_log
        (admin_id, user_id, device_id, event_category, event_type, message, meta_json, ip, created_at)
      VALUES (:a, :u, :d, :cat, :type, :msg, :meta, :ip, NOW())
    ");
    $st->execute([
      ':a'    => $adminId,
      ':u'    => $userId,
      ':d'    => $deviceId,
      ':cat'  => $category,
      ':type' => $type,
      ':msg'  => $message,
      ':meta' => $meta !== null ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
      ':ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
  }
}
