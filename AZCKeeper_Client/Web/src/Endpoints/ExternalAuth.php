<?php
namespace Keeper\Endpoints;

use Keeper\Config;
use Keeper\Db;
use Keeper\Http;
use Keeper\Repos\AuditRepo;
use RateLimiter;

/**
 * ExternalAuth — puente de identidad para plataformas externas (MOAZC).
 *
 * Todas las rutas exigen el header X-Bridge-Secret igual a MOAZC_BRIDGE_SECRET (.env).
 * Solo lectura sobre keeper, salvo el sembrado del password_hash cuando se valida
 * por legacy (mismo comportamiento que ClientLogin).
 *
 *   POST /api/external/verify-credentials  {email, password}
 *   GET  /api/external/roster
 *   GET  /api/external/sites-and-firms
 */
class ExternalAuth
{
    private const SELECT_USER = "
        SELECT
            u.id            AS keeper_user_id,
            u.legacy_employee_id,
            u.cc,
            u.display_name  AS full_name,
            u.email,
            u.status,
            u.employment_status,
            ua.sede_id,
            s.nombre        AS sede_name,
            ua.firm_id,
            f.nombre        AS firm_name,
            ua.area_id,
            a.nombre        AS area
        FROM keeper_users u
        LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
        LEFT JOIN keeper_sedes  s ON s.id = ua.sede_id
        LEFT JOIN keeper_firmas f ON f.id = ua.firm_id
        LEFT JOIN keeper_areas  a ON a.id = ua.area_id
    ";

    public static function verifyCredentials(): void
    {
        self::requireSecret();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!RateLimiter::allow(crc32($ip), 'external-verify-ip', 60, 300)) {
            Http::json(429, ['ok' => false, 'error' => 'rate_limited']);
        }

        $data     = Http::jsonInput();
        $email    = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        if ($email === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Http::json(400, ['ok' => false, 'error' => 'invalid_request']);
        }
        if (!RateLimiter::allow(crc32($email), 'external-verify-email', 10, 300)) {
            Http::json(429, ['ok' => false, 'error' => 'rate_limited']);
        }

        try {
            $pdo  = Db::pdo();
            $user = self::findByEmail($pdo, $email);

            $valid = false;
            if ($user && !empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
                $valid = true;
            } else {
                // Fallback: legacy employee (password en claro) — siembra el hash en keeper_users.
                $emp = self::findLegacyByEmail($email);
                if ($emp && (string)$emp['password'] === $password) {
                    $keeperUserId = self::ensureKeeperUser($pdo, $emp, $password);
                    \Keeper\LegacySyncService::syncOne($pdo, $keeperUserId, [
                        'firm_id'  => $emp['company']     ? (int)$emp['company']     : null,
                        'area_id'  => $emp['area_id']     ? (int)$emp['area_id']     : null,
                        'cargo_id' => $emp['position_id'] ? (int)$emp['position_id'] : null,
                        'sede_id'  => $emp['sede_id']     ? (int)$emp['sede_id']     : null,
                    ]);
                    $user  = self::findById($pdo, $keeperUserId);
                    $valid = (bool)$user;
                }
            }

            if (!$valid) {
                AuditRepo::log($pdo, $user['keeper_user_id'] ?? null, null, 'external_login_failed',
                    "MOAZC login fallido para {$email}", ['ip' => $ip]);
                Http::json(401, ['ok' => false, 'error' => 'invalid_credentials']);
            }

            AuditRepo::log($pdo, (int)$user['keeper_user_id'], null, 'external_login_ok',
                "MOAZC login OK {$email}", ['ip' => $ip]);
            Http::json(200, ['ok' => true, 'user' => self::publicUser($user)]);
        } catch (\Throwable $e) {
            error_log("ExternalAuth::verifyCredentials error: " . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'server_error']);
        }
    }

    public static function roster(): void
    {
        self::requireSecret();
        try {
            $rows = Db::pdo()->query(self::SELECT_USER . " WHERE u.status = 'active' ORDER BY u.display_name")->fetchAll();
            Http::json(200, ['ok' => true, 'users' => array_map([self::class, 'publicUser'], $rows)]);
        } catch (\Throwable $e) {
            error_log("ExternalAuth::roster error: " . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'server_error']);
        }
    }

    public static function sitesAndFirms(): void
    {
        self::requireSecret();
        try {
            $pdo   = Db::pdo();
            $sedes = $pdo->query("SELECT id, nombre AS name, activa AS is_active FROM keeper_sedes ORDER BY nombre")->fetchAll();
            $firms = $pdo->query("SELECT id, nombre AS name FROM keeper_firmas ORDER BY nombre")->fetchAll();
            Http::json(200, [
                'ok'    => true,
                'sedes' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'is_active' => (bool)$r['is_active']], $sedes),
                'firms' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $firms),
            ]);
        } catch (\Throwable $e) {
            error_log("ExternalAuth::sitesAndFirms error: " . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'server_error']);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private static function requireSecret(): void
    {
        $expected = (string)Config::get('MOAZC_BRIDGE_SECRET', '');
        $given    = (string)($_SERVER['HTTP_X_BRIDGE_SECRET'] ?? '');
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            Http::json($expected === '' ? 503 : 401, ['ok' => false, 'error' => 'unauthorized']);
        }
    }

    private static function findByEmail(\PDO $pdo, string $email): ?array
    {
        // El SELECT base no incluye password_hash; se inserta aquí sin duplicar el JOIN.
        $sql = str_replace("u.display_name  AS full_name,", "u.display_name  AS full_name, u.password_hash,", self::SELECT_USER)
             . " WHERE LOWER(u.email) = :e LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute(['e' => $email]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private static function findById(\PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare(self::SELECT_USER . " WHERE u.id = :id LIMIT 1");
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private static function findLegacyByEmail(string $email): ?array
    {
        try {
            $st = Db::legacyPdo()->prepare("
                SELECT e.id AS legacy_employee_id, e.cc, e.password, e.first_Name, e.second_Name,
                       e.first_LastName, e.second_LastName, e.mail, e.area_id, e.position_id, e.company, e.sede_id
                FROM employee e
                WHERE LOWER(e.mail) = :e
                LIMIT 1
            ");
            $st->execute(['e' => $email]);
            $row = $st->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log("ExternalAuth: legacy BD no disponible — " . $e->getMessage());
            return null;
        }
    }

    private static function ensureKeeperUser(\PDO $pdo, array $emp, string $password): int
    {
        $displayName = trim(implode(' ', array_filter([
            $emp['first_Name'] ?? '', $emp['second_Name'] ?? '', $emp['first_LastName'] ?? '', $emp['second_LastName'] ?? '',
        ]))) ?: (string)$emp['cc'];
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $st = $pdo->prepare("SELECT id FROM keeper_users WHERE legacy_employee_id = :x LIMIT 1");
        $st->execute(['x' => (int)$emp['legacy_employee_id']]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare("UPDATE keeper_users SET password_hash = :h, email = COALESCE(email, :em), updated_at = NOW() WHERE id = :id")
                ->execute(['h' => $hash, 'em' => $emp['mail'], 'id' => $row['id']]);
            return (int)$row['id'];
        }
        $pdo->prepare("
            INSERT INTO keeper_users (legacy_employee_id, cc, display_name, email, password_hash, status, created_at)
            VALUES (:lid, :cc, :dn, :em, :ph, 'active', NOW())
        ")->execute([
            'lid' => (int)$emp['legacy_employee_id'], 'cc' => $emp['cc'], 'dn' => $displayName,
            'em' => $emp['mail'], 'ph' => $hash,
        ]);
        return (int)$pdo->lastInsertId();
    }

    private static function publicUser(array $u): array
    {
        return [
            'keeper_user_id'    => (int)$u['keeper_user_id'],
            'full_name'         => $u['full_name'],
            'email'             => $u['email'],
            'cc'                => $u['cc'],
            'status'            => $u['status'],
            'employment_status' => $u['employment_status'] ?? 'active',
            'sede_id'           => $u['sede_id'] !== null ? (int)$u['sede_id'] : null,
            'sede_name'         => $u['sede_name'],
            'firm_id'           => $u['firm_id'] !== null ? (int)$u['firm_id'] : null,
            'firm_name'         => $u['firm_name'],
            'area'              => $u['area'],
        ];
    }
}
