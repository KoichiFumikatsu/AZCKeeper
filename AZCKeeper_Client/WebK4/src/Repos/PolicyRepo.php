<?php
namespace Keeper\Repos;

use PDO;

class PolicyRepo {

  private static ?array $_globalCache = null;
  private static int $_globalCacheAt = 0;
  private const GLOBAL_CACHE_TTL = 60;

  /** Global (cache 60s) + user + device en 1 UNION. Orden de merge: global -> user -> device. */
  public static function getAllPolicies(PDO $pdo, int $userId, int $deviceId): array {
    $global = self::getActiveGlobal($pdo);

    $st = $pdo->prepare("
      (SELECT 'user' AS scope, id, version, policy_json
       FROM keeper_policy_assignments
       WHERE scope='user' AND user_id=:u AND is_active=1
       ORDER BY priority ASC, id DESC LIMIT 1)
      UNION ALL
      (SELECT 'device' AS scope, id, version, policy_json
       FROM keeper_policy_assignments
       WHERE scope='device' AND device_id=:d AND is_active=1
       ORDER BY priority ASC, id DESC LIMIT 1)
    ");
    $st->execute([':u' => $userId, ':d' => $deviceId]);
    $rows = $st->fetchAll();

    return [
      'global' => $global,
      'user'   => self::findByScope($rows, 'user'),
      'device' => self::findByScope($rows, 'device'),
    ];
  }

  private static function findByScope(array $rows, string $scope): ?array {
    foreach ($rows as $r) if ($r['scope'] === $scope) return $r;
    return null;
  }

  public static function getActiveGlobal(PDO $pdo): ?array {
    $now = time();
    if (self::$_globalCache !== null && ($now - self::$_globalCacheAt) < self::GLOBAL_CACHE_TTL) {
      return self::$_globalCache;
    }
    $st = $pdo->query("
      SELECT id, version, policy_json
      FROM keeper_policy_assignments
      WHERE scope='global' AND is_active=1
      ORDER BY priority ASC, id DESC LIMIT 1
    ");
    $row = $st->fetch();
    self::$_globalCache = $row ?: null;
    self::$_globalCacheAt = $now;
    return self::$_globalCache;
  }

  /** Firma a la que esta asignado el usuario (para el recorte por tier). */
  public static function firmaOfUser(PDO $pdo, int $userId): ?int {
    $st = $pdo->prepare("SELECT firma_id FROM keeper_user_assignments WHERE user_id = :u LIMIT 1");
    $st->execute([':u' => $userId]);
    $v = $st->fetchColumn();
    return $v ? (int)$v : null;
  }
}
