<?php
namespace Keeper\Repos;

use PDO;

/**
 * Autenticación y sesiones del panel admin (RBAC real que reemplaza el stopgap AdminAuth
 * de X-Admin-Key para humanos). En K4 la cuenta admin es autocontenida: email +
 * password_hash + rol + scope viven en keeper_admin_accounts (no vía keeper_users como en
 * K3). La sesión es una cookie httpOnly cuyo sha256 se guarda en keeper_admin_sessions.
 */
class AdminAuthRepo
{
    /** Verifica credenciales. Devuelve la cuenta si el password calza y está activa, o null. */
    public static function login(PDO $pdo, string $email, string $password): ?array
    {
        $st = $pdo->prepare("
            SELECT id, email, display_name, password_hash, panel_role,
                   firma_scope_id, area_scope_id, sede_scope_id, is_active
            FROM keeper_admin_accounts
            WHERE email = :email AND is_active = 1
            LIMIT 1
        ");
        $st->execute([':email' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!password_verify($password, $row['password_hash'])) return null;
        unset($row['password_hash']);
        return $row;
    }

    /** Crea una sesión y devuelve el token en claro (para la cookie). Guarda solo su sha256. */
    public static function createSession(PDO $pdo, int $adminId, int $ttlSeconds = 28800): string
    {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $exp   = (new \DateTime('now', new \DateTimeZone('UTC')))
                    ->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

        $st = $pdo->prepare("
            INSERT INTO keeper_admin_sessions (admin_id, token_hash, ip, expires_at)
            VALUES (:aid, :hash, :ip, :exp)
        ");
        $st->execute([
            ':aid'  => $adminId,
            ':hash' => $hash,
            ':ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ':exp'  => $exp,
        ]);
        return $token;
    }

    /** Valida una cookie de sesión. Devuelve la cuenta (con scope) o null si no sirve. */
    public static function validateSession(PDO $pdo, string $token): ?array
    {
        $hash = hash('sha256', $token);
        $st = $pdo->prepare("
            SELECT s.id AS session_id, s.admin_id, s.expires_at,
                   a.email, a.display_name, a.panel_role,
                   a.firma_scope_id, a.area_scope_id, a.sede_scope_id
            FROM keeper_admin_sessions s
            INNER JOIN keeper_admin_accounts a ON a.id = s.admin_id
            WHERE s.token_hash = :hash
              AND s.revoked_at IS NULL
              AND (s.expires_at IS NULL OR s.expires_at > UTC_TIMESTAMP())
              AND a.is_active = 1
            LIMIT 1
        ");
        $st->execute([':hash' => $hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function revokeSession(PDO $pdo, string $token): void
    {
        $hash = hash('sha256', $token);
        $pdo->prepare("UPDATE keeper_admin_sessions SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = :h")
            ->execute([':h' => $hash]);
    }
}
