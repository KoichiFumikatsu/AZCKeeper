<?php
declare(strict_types=1);
namespace Keeper\Endpoints;

use Keeper\Db;
use Keeper\Http;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\SessionRepo;

/**
 * Endpoint temporal de re-enrollment para clientes que perdieron su token.
 *
 * Flujo:
 *   1. Cliente envía device_guid (ya lo tiene en config.json)
 *   2. Se busca el dispositivo en keeper_devices → se obtiene user_id
 *   3. Se verifica que el dispositivo esté activo y el usuario exista
 *   4. Se crea una nueva sesión (token) y se retorna
 *
 * Seguridad:
 *   - Solo funciona para dispositivos YA registrados (no crea nuevos)
 *   - El dispositivo debe estar en status 'active'
 *   - El usuario debe existir y estar activo en keeper_users
 *   - Rate-limited por IP (máx 10 intentos/minuto)
 *   - Se puede desactivar desde keeper_panel_settings (clave: enable_re_enroll)
 *
 * POST /client/re-enroll
 * Body: { "deviceGuid": "...", "deviceName": "..." }
 */
class ClientReEnroll
{
    public static function handle(): void
    {
        $pdo = Db::pdo();

        // Verificar si el endpoint está habilitado (default: habilitado)
        $setting = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 're_enroll_enabled'");
        $setting->execute();
        $enabled = $setting->fetchColumn();
        if ($enabled === '0' || $enabled === 'false') {
            Http::json(403, ['ok' => false, 'error' => 'Re-enrollment disabled']);
        }

        // Rate limit: máx 10 intentos por IP por minuto
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateSt = $pdo->prepare("
            SELECT COUNT(*) FROM keeper_audit_log 
            WHERE event_type = 're_enroll_attempt' 
              AND JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.ip')) = :ip 
              AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $rateSt->execute([':ip' => $ip]);
        if ((int)$rateSt->fetchColumn() >= 10) {
            Http::json(429, ['ok' => false, 'error' => 'Too many attempts']);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $deviceGuid = trim($body['deviceGuid'] ?? '');
        $deviceName = trim($body['deviceName'] ?? '');

        if (strlen($deviceGuid) < 10) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid deviceGuid']);
        }

        // Registrar intento en audit
        $auditSt = $pdo->prepare("
            INSERT INTO keeper_audit_log (event_type, message, meta_json, created_at)
            VALUES ('re_enroll_attempt', :msg, :meta, NOW())
        ");
        $auditSt->execute([
            ':msg' => "Re-enroll attempt from {$ip}",
            ':meta' => json_encode(['ip' => $ip, 'deviceGuid' => $deviceGuid], JSON_UNESCAPED_UNICODE),
        ]);

        // 1. Buscar dispositivo
        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) {
            Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        }

        if (($dev['status'] ?? '') !== 'active') {
            Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        }

        $userId = (int)$dev['user_id'];
        $deviceId = (int)$dev['id'];

        // 2. Verificar usuario existe y está activo
        $userSt = $pdo->prepare("SELECT id, display_name, status FROM keeper_users WHERE id = :id");
        $userSt->execute([':id' => $userId]);
        $user = $userSt->fetch();

        if (!$user) {
            Http::json(404, ['ok' => false, 'error' => 'User not found']);
        }
        if (($user['status'] ?? 'active') !== 'active') {
            Http::json(403, ['ok' => false, 'error' => 'User inactive']);
        }

        // 3. Actualizar device touch
        DeviceRepo::touch($pdo, $deviceId, $deviceName ?: null, null);

        // 4. Crear sesión
        $session = SessionRepo::createSession($pdo, $userId, $deviceId);

        // 5. Registrar éxito en audit
        $auditSt = $pdo->prepare("
            INSERT INTO keeper_audit_log (user_id, device_id, event_type, message, meta_json, created_at)
            VALUES (:u, :d, 're_enroll_success', :msg, :meta, NOW())
        ");
        $auditSt->execute([
            ':u' => $userId,
            ':d' => $deviceId,
            ':msg' => "Re-enroll success for device {$deviceGuid}",
            ':meta' => json_encode([
                'ip' => $ip,
                'deviceGuid' => $deviceGuid,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        Http::json(200, [
            'ok'          => true,
            'token'       => $session['token'],
            'userId'      => $userId,
            'deviceId'    => $deviceId,
            'displayName' => $user['display_name'] ?: null,
            'expiresAtUtc' => $session['expiresAtUtc'],
        ]);
    }
}
