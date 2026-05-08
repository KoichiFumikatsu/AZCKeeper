<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\AuditRepo;
use Keeper\Repos\UserRepo;
use Keeper\Repos\PendingEnrollmentRepo;

class ClientLogin
{
    public static function handle(): void
    {
        try {
            $data = Http::jsonInput();

            $cc         = $data['username']   ?? ($data['Username']   ?? null);
            $password   = $data['password']   ?? ($data['Password']   ?? null);
            $deviceGuid = $data['deviceId']   ?? ($data['DeviceId']   ?? null);
            $deviceName = $data['deviceName'] ?? ($data['DeviceName'] ?? null);

            if (!$cc || !$password) {
                Http::json(400, ['ok' => false, 'error' => 'Missing username/password']);
            }

            $cc = trim((string)$cc);
            if ($cc === '' || !ctype_digit($cc)) {
                Http::json(400, ['ok' => false, 'error' => 'Username must be CC (numeric)']);
            }

            $pdo       = Db::pdo();
            $legacyPdo = Db::legacyPdo();

            // ── 1) Intentar validar contra LEGACY Employee ──────────────────────
            $emp        = null;
            $legacyDown = false;
            try {
                $stmt = $legacyPdo->prepare("
                    SELECT
                        e.id AS legacy_employee_id,
                        e.cc,
                        e.password,
                        e.first_Name,
                        e.second_Name,
                        e.first_LastName,
                        e.second_LastName,
                        e.mail,
                        e.personal_mail,
                        e.role,
                        e.supervisor_id,
                        e.area_id,
                        e.position,
                        e.position_id,
                        e.company,
                        e.sede_id
                    FROM employee e
                    WHERE e.cc = :cc
                    LIMIT 1
                ");
                $stmt->execute(['cc' => (int)$cc]);
                $emp = $stmt->fetch();
            } catch (\Throwable $legacyEx) {
                $legacyDown = true;
                error_log("ClientLogin: legacy BD no disponible — " . $legacyEx->getMessage());
            }

            // ── 2) Si legacy no encontró al usuario (o está caída) ──────────────
            $keeperUserId    = null;
            $skipLegacySync  = false;
            $displayName     = null;

            if (!$emp) {
                // Intentar auth directa en keeper_users (fallback)
                $keeperUser = UserRepo::findByCc($pdo, $cc);
                if ($keeperUser && !empty($keeperUser['password_hash'])
                    && password_verify($password, $keeperUser['password_hash'])) {
                    // Keeper fallback exitoso
                    $keeperUserId   = (int)$keeperUser['id'];
                    $displayName    = $keeperUser['display_name'] ?? null;
                    $skipLegacySync = true;
                } else {
                    // No existe en ninguna BD
                    if (!$legacyDown) {
                        // Legacy estaba disponible: CC definitivamente no existe en ningún lado.
                        // Guardar solicitud pendiente para revisión del admin.
                        $existing = PendingEnrollmentRepo::findPendingByCc($pdo, $cc);
                        if (!$existing) {
                            PendingEnrollmentRepo::create($pdo, [
                                'cc'            => $cc,
                                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                                'device_guid'   => $deviceGuid,
                                'device_name'   => $deviceName,
                                'attempted_ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
                            ]);
                        }
                        Http::json(403, [
                            'ok'      => false,
                            'error'   => 'access_pending',
                            'message' => 'Tu solicitud de acceso está pendiente de aprobación por un administrador.',
                        ]);
                    }
                    // Legacy caída y usuario no está en keeper
                    Http::json(503, [
                        'ok'      => false,
                        'error'   => 'service_unavailable',
                        'message' => 'Sistema temporalmente no disponible. Intenta más tarde.',
                    ]);
                }
            }

            // ── 3) Validar password legacy (si no usamos el path keeper fallback) ──
            if (!$skipLegacySync) {
                if ((string)$emp['password'] !== (string)$password) {
                    AuditRepo::log($pdo, null, null, 'login_failed', "Login fallido para CC={$cc}", [
                        'cc' => $cc, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);
                    Http::json(401, ['ok' => false, 'error' => 'Invalid credentials']);
                }

                // ✅ LEGACY VÁLIDO: sincronizar con keeper_users
                $displayName = trim(implode(' ', array_filter([
                    $emp['first_Name']    ?? '',
                    $emp['second_Name']   ?? '',
                    $emp['first_LastName']  ?? '',
                    $emp['second_LastName'] ?? '',
                ])));

                $email = $emp['mail'] ?: ($emp['personal_mail'] ?? null);

                $keeperUserId = self::ensureKeeperUser($pdo, [
                    'legacy_employee_id' => (int)$emp['legacy_employee_id'],
                    'cc'           => $emp['cc'],
                    'display_name' => $displayName ?: (string)$emp['cc'],
                    'email'        => $email,
                    'password'     => $password,
                ]);

                \Keeper\LegacySyncService::syncOne($pdo, $keeperUserId, [
                    'firm_id'  => $emp['company']     ? (int)$emp['company']     : null,
                    'area_id'  => $emp['area_id']     ? (int)$emp['area_id']     : null,
                    'cargo_id' => $emp['position_id'] ? (int)$emp['position_id'] : null,
                    'sede_id'  => $emp['sede_id']     ? (int)$emp['sede_id']     : null,
                ]);
            }

            // ── 4) Device ────────────────────────────────────────────────────────
            $keeperDeviceId = null;
            if ($deviceGuid) {
                $keeperDeviceId = self::ensureKeeperDevice($pdo, $keeperUserId, $deviceGuid, $deviceName);
            }

            // ── 5) Token ─────────────────────────────────────────────────────────
            $tokenPlain = bin2hex(random_bytes(32));
            $tokenHash  = hash('sha256', $tokenPlain);

            $stmt = $pdo->prepare("
                INSERT INTO keeper_sessions
                  (user_id, device_id, token_hash, issued_at, expires_at, ip, user_agent)
                VALUES (:uid, :did, :hash, NOW(), NULL, :ip, :ua)
            ");
            $stmt->execute([
                'uid'  => $keeperUserId,
                'did'  => $keeperDeviceId,
                'hash' => $tokenHash,
                'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);

            AuditRepo::log($pdo, $keeperUserId, $keeperDeviceId, 'login_ok', "Login exitoso CC={$cc}", [
                'cc' => $cc, 'deviceGuid' => $deviceGuid, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            Http::json(200, [
                'ok'           => true,
                'token'        => $tokenPlain,
                'userId'       => $keeperUserId,
                'deviceId'     => $keeperDeviceId,
                'displayName'  => $displayName ?: null,
                'expiresAtUtc' => null,
            ]);
        } catch (\Throwable $e) {
            error_log("ClientLogin error: " . $e->getMessage() . " | " . $e->getTraceAsString());
            $response = ['ok' => false, 'error' => 'Server error'];
            if (\Keeper\Config::get('DEBUG', 'false') === 'true') {
                $response['detail'] = $e->getMessage();
                $response['trace']  = $e->getTraceAsString();
            }
            Http::json(500, $response);
        }
    }

    private static function ensureKeeperUser(\PDO $pdo, array $u): int
    {
        $stmt = $pdo->prepare("SELECT id, password_hash FROM keeper_users WHERE legacy_employee_id = :x LIMIT 1");
        $stmt->execute(['x' => $u['legacy_employee_id']]);
        $row = $stmt->fetch();

        if ($row) {
            $sets   = ['cc=:cc', 'display_name=:dn', 'email=:em', 'updated_at=NOW()'];
            $params = ['cc' => $u['cc'], 'dn' => $u['display_name'], 'em' => $u['email'], 'id' => $row['id']];

            if (!$row['password_hash'] || !password_verify($u['password'], $row['password_hash'])) {
                $sets[]      = 'password_hash=:h';
                $params['h'] = password_hash($u['password'], PASSWORD_BCRYPT);
            }

            $upd = $pdo->prepare("UPDATE keeper_users SET " . implode(', ', $sets) . " WHERE id=:id");
            $upd->execute($params);
            return (int)$row['id'];
        }

        $passwordHash = password_hash($u['password'], PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO keeper_users
              (legacy_employee_id, cc, display_name, email, password_hash, status, created_at)
            VALUES (:lid, :cc, :dn, :em, :ph, 'active', NOW())
        ");
        $stmt->execute([
            'lid' => $u['legacy_employee_id'],
            'cc'  => $u['cc'],
            'dn'  => $u['display_name'],
            'em'  => $u['email'],
            'ph'  => $passwordHash,
        ]);
        return (int)$pdo->lastInsertId();
    }

    private static function ensureKeeperDevice(\PDO $pdo, int $userId, string $deviceGuid, ?string $deviceName): ?int
    {
        $stmt = $pdo->prepare("SELECT id FROM keeper_devices WHERE device_guid = :g LIMIT 1");
        $stmt->execute(['g' => $deviceGuid]);
        $row = $stmt->fetch();

        if ($row) {
            $upd = $pdo->prepare("UPDATE keeper_devices SET user_id=:uid, device_name=:dn, last_seen_at=NOW() WHERE id=:id");
            $upd->execute(['uid' => $userId, 'dn' => $deviceName, 'id' => $row['id']]);
            return (int)$row['id'];
        }

        $ins = $pdo->prepare("
            INSERT INTO keeper_devices (user_id, device_guid, device_name, created_at, last_seen_at)
            VALUES (:uid, :g, :dn, NOW(), NOW())
        ");
        $ins->execute(['uid' => $userId, 'g' => $deviceGuid, 'dn' => $deviceName]);
        return (int)$pdo->lastInsertId();
    }
}
