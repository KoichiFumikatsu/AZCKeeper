<?php
namespace Keeper\Repos;

use PDO;
use Keeper\Http;

class SessionRepo {

  public static function validateBearer(PDO $pdo, string $token): ?array {
    $hash = Http::sha256Hex($token);
    $st = $pdo->prepare("
      SELECT id, user_id, device_id, expires_at, revoked_at
      FROM keeper_sessions
      WHERE token_hash=:h
      LIMIT 1
    ");
    $st->execute([':h' => $hash]);
    $row = $st->fetch();
    if (!$row) return null;
    if (!empty($row['revoked_at'])) return null;
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) <= time()) return null;
    return $row;
  }

  public static function createSession(PDO $pdo, int $userId, ?int $deviceId, int $ttlSeconds = 2592000): array {
    $token = bin2hex(random_bytes(32)); // 64 hex chars
    $hash = Http::sha256Hex($token);

    $expiresAt = (new \DateTime('now', new \DateTimeZone('UTC')))
      ->modify("+{$ttlSeconds} seconds")
      ->format('Y-m-d H:i:s');

    $st = $pdo->prepare("
      INSERT INTO keeper_sessions (user_id, device_id, token_hash, expires_at, revoked_at, ip, user_agent, issued_at)
      VALUES (:u, :d, :h, :exp, NULL, :ip, :ua, NOW())
    ");
    $st->execute([
      ':u' => $userId,
      ':d' => $deviceId,
      ':h' => $hash,
      ':exp' => $expiresAt,
      ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
      ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);

    return [
      'token' => $token,
      'expiresAtUtc' => (new \DateTime($expiresAt, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')
    ];
  }
}
