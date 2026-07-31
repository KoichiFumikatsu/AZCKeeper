<?php
namespace Keeper\Endpoints;

use PDO;
use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\UserRepo;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\AuditRepo;

/**
 * Login del cliente por cedula (CC). En Keeper 4 los usuarios entran por dos vias:
 *   - Import multi-tenant desde la BD del cliente (via principal).
 *   - Enrolamiento por CC desconocida (via secundaria): un equipo con una cedula
 *     que aun no esta en el sistema crea una SOLICITUD (keeper_users status='pending')
 *     que un admin aprueba desde el panel. Se pliega sobre keeper_users, sin tabla
 *     de solicitudes aparte.
 *
 * Validacion de contrasena (decision de Koichi, 2026-07-31): la identidad del agente pasa a
 * ser cedula + contrasena, como en Keeper 3. Tres caminos:
 *   1. Cedula desconocida         -> pending (solicitud de enrolamiento). No fija contrasena.
 *   2. Cedula existe, sin hash     -> el PRIMER login la fija. Se exige el patron por defecto
 *                                     z<cedula>Z@!$; si no coincide, 401. Asi la contrasena de
 *                                     un usuario sin credencial queda determinada por la regla,
 *                                     no por lo primero que alguien teclee.
 *   3. Cedula existe, con hash     -> password_verify; si falla, 401 (no enrola, no crea sesion).
 * El token DPAPI persiste en el cliente, asi que esto solo se pide en el primer arranque.
 */
class ClientLogin
{
    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $cc         = trim((string)($body['cc'] ?? ($body['CC'] ?? '')));
        $password   = (string)($body['password'] ?? ($body['Password'] ?? ''));
        $deviceGuid = $body['deviceId']   ?? ($body['DeviceId']   ?? null);
        $deviceName = $body['deviceName'] ?? ($body['DeviceName'] ?? null);
        $version    = $body['version']    ?? ($body['Version']    ?? null);

        // Rate-limit por IP: login es la unica via que crea filas sin autenticacion
        // (CC desconocida -> pending). Sin limite es un vector de llenado de BD y de
        // enumeracion de cedulas. 10 intentos por minuto por IP.
        $ipKey = (int)(sprintf('%u', crc32($_SERVER['REMOTE_ADDR'] ?? 'nil')) % 2147483647);
        if (!\RateLimiter::allow($ipKey, 'client-login', 10, 60)) {
            Http::json(429, ['ok' => false, 'error' => 'Too many attempts, retry later']);
        }

        if ($cc === '') Http::json(400, ['ok' => false, 'error' => 'Missing cc']);
        if (!$deviceGuid || !preg_match('/^[0-9a-fA-F-]{36}$/', $deviceGuid)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid or missing DeviceId']);
        }

        $user = UserRepo::findByCc($pdo, $cc);

        // Cedula desconocida -> crear solicitud de enrolamiento (pending) y avisar.
        if (!$user) {
            $newId = UserRepo::createPending($pdo, $cc, $deviceName ? "Equipo {$deviceName}" : null);
            AuditRepo::log($pdo, null, $newId, null, 'admin', 'enrollment_requested',
                "Solicitud de enrolamiento por CC {$cc}", ['cc' => $cc, 'device_guid' => $deviceGuid]);
            Http::json(202, ['ok' => false, 'status' => 'pending_enrollment',
                'message' => 'Equipo en espera de aprobacion']);
        }

        if ($user['status'] === 'pending') {
            Http::json(202, ['ok' => false, 'status' => 'pending_enrollment',
                'message' => 'Equipo en espera de aprobacion']);
        }
        if ($user['status'] !== 'active' || ($user['employment_status'] ?? 'active') === 'retired') {
            Http::json(403, ['ok' => false, 'status' => 'denied', 'error' => 'User not active']);
        }

        // --- Validacion de contrasena (caminos 2 y 3) ---
        $userId = (int)$user['id'];
        if ($password === '') {
            Http::json(401, ['ok' => false, 'status' => 'bad_credentials', 'error' => 'Missing password']);
        }
        if (empty($user['password_hash'])) {
            // Camino 2: sin hash -> se fija ahora, exigiendo el patron por defecto z<cc>Z@!$.
            $expected = 'z' . $cc . 'Z@!$';
            if (!hash_equals($expected, $password)) {
                Http::json(401, ['ok' => false, 'status' => 'bad_credentials',
                    'error' => 'La contrasena no cumple la regla por defecto']);
            }
            UserRepo::setPassword($pdo, $userId, password_hash($password, PASSWORD_DEFAULT));
            AuditRepo::log($pdo, null, $userId, null, 'admin', 'password_set',
                'Contrasena fijada en el primer login (patron por defecto)');
        } else {
            // Camino 3: verificar contra el hash guardado.
            if (!password_verify($password, $user['password_hash'])) {
                Http::json(401, ['ok' => false, 'status' => 'bad_credentials', 'error' => 'Invalid credentials']);
            }
        }

        // Enrolar/reasignar el equipo.
        $st = $pdo->prepare("SELECT id, user_id, status FROM keeper_devices WHERE device_guid = :g LIMIT 1");
        $st->execute([':g' => $deviceGuid]);
        $dev = $st->fetch();

        if (!$dev) {
            $ins = $pdo->prepare("INSERT INTO keeper_devices (user_id, device_guid, device_name, client_version, status)
                                  VALUES (:u, :g, :n, :v, 'active')");
            $ins->execute([':u' => $userId, ':g' => $deviceGuid, ':n' => $deviceName, ':v' => $version]);
            $deviceId = (int)$pdo->lastInsertId();
            AuditRepo::log($pdo, null, $userId, $deviceId, 'admin', 'device_enrolled', 'Equipo enrolado', ['device_guid' => $deviceGuid]);
        } else {
            $deviceId = (int)$dev['id'];
            if (($dev['status'] ?? 'active') !== 'active') {
                Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
            }
            if ((int)$dev['user_id'] !== $userId) {
                $pdo->prepare("UPDATE keeper_devices SET user_id = :u WHERE id = :id")->execute([':u' => $userId, ':id' => $deviceId]);
            }
            $pdo->prepare("UPDATE keeper_devices SET device_name = COALESCE(:n, device_name), client_version = COALESCE(:v, client_version), last_seen_at = NOW() WHERE id = :id")
                ->execute([':n' => $deviceName, ':v' => $version, ':id' => $deviceId]);
        }

        $session = SessionRepo::createSession($pdo, $userId, $deviceId);
        AuditRepo::log($pdo, null, $userId, $deviceId, 'admin', 'login', 'Login de cliente');

        Http::json(200, [
            'ok'           => true,
            'token'        => $session['token'],
            'expiresAtUtc' => $session['expiresAtUtc'],
            'displayName'  => $user['display_name'],
        ]);
    }
}
