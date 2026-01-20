<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;

class ClientLogin
{
    public static function handle(): void
    {
        try {
            $data = Http::jsonInput();

            // camelCase + tolerancia PascalCase (por robustez)
            $cc         = $data['username']   ?? ($data['Username']   ?? null);
            $password   = $data['password']   ?? ($data['Password']   ?? null);
            $deviceGuid = $data['deviceId']   ?? ($data['DeviceId']   ?? null);
            $deviceName = $data['deviceName'] ?? ($data['DeviceName'] ?? null);

            if (!$cc || !$password) {
                Http::json(400, ['ok' => false, 'error' => 'Missing username/password']);
            }

            // Producción: username debe ser CC numérico
            $cc = trim((string)$cc);
            if ($cc === '' || !ctype_digit($cc)) {
                Http::json(400, ['ok' => false, 'error' => 'Username must be CC (numeric)']);
            }

            $pdo = Db::pdo();

            // 1) Validar contra LEGACY Employee (cc + password plano)
            // Campos reales según tu tabla: first_Name, second_Name, first_LastName, second_LastName, mail, personal_mail, role, supervisor_id, area_id, position, company
            $stmt = $pdo->prepare("
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
                    e.company
                FROM employee e
                WHERE e.cc = :cc
                LIMIT 1
            ");
            $stmt->execute(['cc' => (int)$cc]);
            $emp = $stmt->fetch();

            if (!$emp || (string)$emp['password'] !== (string)$password) {
                Http::json(401, ['ok' => false, 'error' => 'Invalid credentials']);
            }
             
            // ✅ LEGACY VÁLIDO: ahora migrar a keeper_users con hash
             
            $displayName = trim(implode(' ', array_filter([
                $emp['first_Name'] ?? '', $emp['second_Name'] ?? '',
                $emp['first_LastName'] ?? '', $emp['second_LastName'] ?? ''
            ])));
             
            $email = $emp['mail'] ?: ($emp['personal_mail'] ?? null);
             
            // 2) Asegurar keeper_user CON password_hash
            $keeperUserId = self::ensureKeeperUser($pdo, [
                'legacy_employee_id' => (int)$emp['legacy_employee_id'],
                'display_name' => $displayName ?: (string)$emp['cc'],
                'email' => $email,
                'password' => $password  // ← PASAR PASSWORD PARA HASHEAR
            ]);
             
            // 3) Device
            $keeperDeviceId = null;
            if ($deviceGuid) {
                $keeperDeviceId = self::ensureKeeperDevice($pdo, $keeperUserId, $deviceGuid, $deviceName);
            }
             
            // 4) Token (sin expiración para persistencia permanente)
            $tokenPlain = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $tokenPlain);
             
            $stmt = $pdo->prepare("
                INSERT INTO keeper_sessions
                  (user_id, device_id, token_hash, issued_at, expires_at, ip, user_agent)
                VALUES (:uid, :did, :hash, NOW(), NULL, :ip, :ua)
            ");
            $stmt->execute([
                'uid' => $keeperUserId,
                'did' => $keeperDeviceId,
                'hash' => $tokenHash,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
             
            Http::json(200, [
                'ok' => true,
                'token' => $tokenPlain,
                'userId' => $keeperUserId,
                'deviceId' => $keeperDeviceId,
                'expiresAtUtc' => null
            ]);
        }
        catch (\Throwable $e) {
            Http::json(500, [
                'ok' => false,
                'error' => 'Server error',
                'detail' => $e->getMessage()
            ]);
        }
    }

    private static function ensureKeeperUser(\PDO $pdo, array $u): int
    {
        $stmt = $pdo->prepare("SELECT id, password_hash FROM keeper_users WHERE legacy_employee_id = :x LIMIT 1");
        $stmt->execute(['x' => $u['legacy_employee_id']]);
        $row = $stmt->fetch();
        
        $passwordHash = password_hash($u['password'], PASSWORD_BCRYPT);
        
        if ($row) {
            // Usuario existe: actualizar hash si cambió password
            if (!$row['password_hash'] || !password_verify($u['password'], $row['password_hash'])) {
                $upd = $pdo->prepare("UPDATE keeper_users SET password_hash=:h, updated_at=NOW() WHERE id=:id");
                $upd->execute(['h' => $passwordHash, 'id' => $row['id']]);
            }
            return (int)$row['id'];
        }
        
        // Crear nuevo
        $stmt = $pdo->prepare("
            INSERT INTO keeper_users
              (legacy_employee_id, display_name, email, password_hash, status, created_at)
            VALUES (:lid, :dn, :em, :ph, 'active', NOW())
        ");
        $stmt->execute([
            'lid' => $u['legacy_employee_id'],
            'dn' => $u['display_name'],
            'em' => $u['email'],
            'ph' => $passwordHash
        ]);
        
        return (int)$pdo->lastInsertId();
    }

    private static function ensureKeeperDevice(\PDO $pdo, int $userId, string $deviceGuid, ?string $deviceName): ?int
    {
        // Esto necesita alineación con tu keeper_devices real.
        // Si NO coincide, me pasas SHOW CREATE TABLE keeper_devices y lo ajusto exacto.

        $stmt = $pdo->prepare("SELECT id FROM keeper_devices WHERE device_guid = :g LIMIT 1");
        $stmt->execute(['g' => $deviceGuid]);
        $row = $stmt->fetch();

        if ($row) {
            $upd = $pdo->prepare("UPDATE keeper_devices SET user_id=:uid, device_name=:dn, last_seen_at=NOW() WHERE id=:id");
            $upd->execute(['uid'=>$userId,'dn'=>$deviceName,'id'=>$row['id']]);
            return (int)$row['id'];
        }

        $ins = $pdo->prepare("
            INSERT INTO keeper_devices (user_id, device_guid, device_name, created_at, last_seen_at)
            VALUES (:uid, :g, :dn, NOW(), NOW())
        ");
        $ins->execute(['uid'=>$userId,'g'=>$deviceGuid,'dn'=>$deviceName]);

        return (int)$pdo->lastInsertId();
    }
}
