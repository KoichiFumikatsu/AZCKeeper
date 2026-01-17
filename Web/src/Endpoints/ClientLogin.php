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

            // display_name armado desde columnas reales
            $displayName = trim(implode(' ', array_filter([
                $emp['first_Name'] ?? '',
                $emp['second_Name'] ?? '',
                $emp['first_LastName'] ?? '',
                $emp['second_LastName'] ?? ''
            ])));

            $email = $emp['mail'] ?: ($emp['personal_mail'] ?? null);

            // 2) Asegurar keeper_user (upsert)
            // NOTA: esto asume que keeper_users tiene estas columnas.
            // Si tus columnas difieren, ajusto con tu SHOW CREATE TABLE keeper_users.
            $keeperUserId = self::ensureKeeperUser($pdo, [
                'legacy_employee_id' => (int)$emp['legacy_employee_id'],
                'legacy_cc' => (string)$emp['cc'],
                'display_name' => $displayName !== '' ? $displayName : (string)$emp['cc'],
                'email' => $email,
                'role' => $emp['role'] ?? null,
                'supervisor_legacy_id' => $emp['supervisor_id'] ?? null,
                'area_legacy_id' => $emp['area_id'] ?? null,
                'position_legacy' => $emp['position'] ?? null,
                'company_legacy' => $emp['company'] ?? null,
            ]);

            // 3) (Opcional) device: si keeper_devices existe con device_guid UNIQUE
            $keeperDeviceId = null;
            if ($deviceGuid) {
                $keeperDeviceId = self::ensureKeeperDevice($pdo, $keeperUserId, (string)$deviceGuid, $deviceName);
            }

            // 4) Emitir token plano + guardar hash en keeper_sessions.token_hash
            $tokenPlain = bin2hex(random_bytes(32)); // 64 chars
            $tokenHash  = Http::sha256Hex($tokenPlain); // 64 chars -> token_hash

            $stmt = $pdo->prepare("
                INSERT INTO keeper_sessions
                    (user_id, device_id, token_hash, issued_at, expires_at, revoked_at, ip, user_agent)
                VALUES
                    (:user_id, :device_id, :token_hash, NOW(), NULL, NULL, :ip, :ua)
            ");
            $stmt->execute([
                'user_id'    => $keeperUserId,
                'device_id'  => $keeperDeviceId,
                'token_hash' => $tokenHash,
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'         => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);

            Http::json(200, [
                'ok' => true,
                'token' => $tokenPlain,          // token plano al cliente (Bearer)
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
        // Esto necesita alineación con tu keeper_users real.
        // Si NO coincide, no improviso: me pasas SHOW CREATE TABLE keeper_users y lo ajusto exacto.
        //
        // Intento primero por legacy_employee_id
        $stmt = $pdo->prepare("SELECT id FROM keeper_users WHERE legacy_employee_id = :x LIMIT 1");
        $stmt->execute(['x' => $u['legacy_employee_id']]);
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];

        $stmt = $pdo->prepare("
            INSERT INTO keeper_users
              (legacy_employee_id, legacy_cc, display_name, email, role, supervisor_legacy_id, area_legacy_id, position_legacy, company_legacy, active, created_at, updated_at)
            VALUES
              (:legacy_employee_id, :legacy_cc, :display_name, :email, :role, :supervisor_legacy_id, :area_legacy_id, :position_legacy, :company_legacy, 1, NOW(), NOW())
        ");
        $stmt->execute([
            'legacy_employee_id' => $u['legacy_employee_id'],
            'legacy_cc' => $u['legacy_cc'],
            'display_name' => $u['display_name'],
            'email' => $u['email'],
            'role' => $u['role'],
            'supervisor_legacy_id' => $u['supervisor_legacy_id'],
            'area_legacy_id' => $u['area_legacy_id'],
            'position_legacy' => $u['position_legacy'],
            'company_legacy' => $u['company_legacy'],
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
