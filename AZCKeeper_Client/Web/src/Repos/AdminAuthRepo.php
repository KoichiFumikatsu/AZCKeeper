<?php
namespace Keeper\Repos;

use PDO;

/**
 * AdminAuthRepo — autenticación y sesiones del panel admin.
 *
 * Tablas reales en BD:
 *   keeper_admin_accounts  → cuentas con rol y scope de firma/área/sociedad
 *   keeper_admin_sessions  → sesiones con cookie httpOnly (FK: admin_id)
 *   keeper_users           → email + password_hash para verificar credenciales
 */
class AdminAuthRepo
{
    public static function findByEmail(PDO $pdo, string $email): ?array
    {
        $st = $pdo->prepare("
            SELECT
                a.id AS admin_id,
                a.keeper_user_id,
                a.panel_role,
                a.firm_scope_id,
                a.area_scope_id,
                a.sociedad_scope_id,
                a.is_active AS admin_active,
                u.id AS user_id,
                u.email,
                u.display_name,
                u.password_hash,
                u.status AS user_status
            FROM keeper_admin_accounts a
            INNER JOIN keeper_users u ON u.id = a.keeper_user_id
            WHERE u.email = :email
            LIMIT 1
        ");
        $st->execute([':email' => $email]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function createSession(PDO $pdo, int $adminId, int $ttlSeconds = 28800): string
    {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);

        $expiresAt = (new \DateTime('now', new \DateTimeZone('UTC')))
            ->modify("+{$ttlSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $st = $pdo->prepare("
            INSERT INTO keeper_admin_sessions
                (admin_id, token_hash, ip, user_agent, expires_at)
            VALUES (:aid, :hash, :ip, :ua, :exp)
        ");
        $st->execute([
            ':aid'  => $adminId,
            ':hash' => $hash,
            ':ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':exp'  => $expiresAt,
        ]);

        $pdo->prepare("UPDATE keeper_admin_accounts SET last_login_at = NOW() WHERE id = :id")
            ->execute([':id' => $adminId]);

        return $token;
    }

    public static function validateSession(PDO $pdo, string $token): ?array
    {
        $hash = hash('sha256', $token);

        $st = $pdo->prepare("
            SELECT
                s.id AS session_id,
                s.admin_id,
                s.expires_at,
                a.keeper_user_id,
                a.panel_role,
                a.firm_scope_id,
                a.area_scope_id,
                a.sede_scope_id,
                a.sociedad_scope_id,
                a.is_active AS admin_active,
                u.display_name,
                u.email
            FROM keeper_admin_sessions s
            INNER JOIN keeper_admin_accounts a ON a.id = s.admin_id
            INNER JOIN keeper_users u ON u.id = a.keeper_user_id
            WHERE s.token_hash = :hash
              AND s.revoked_at IS NULL
              AND s.expires_at > NOW()
              AND a.is_active = 1
            LIMIT 1
        ");
        $st->execute([':hash' => $hash]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function revokeSession(PDO $pdo, string $token): void
    {
        $hash = hash('sha256', $token);
        $pdo->prepare("UPDATE keeper_admin_sessions SET revoked_at = NOW() WHERE token_hash = :h")
            ->execute([':h' => $hash]);
    }
}
