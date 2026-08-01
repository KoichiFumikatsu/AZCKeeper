<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\AuditRepo;

/**
 * Recuperación de sesión por device_guid: un equipo YA enrolado que perdió su token (config
 * borrada, DPAPI ilegible tras cambio de perfil) recupera sesión SIN credenciales. La prueba
 * es el device_guid: solo funciona si el equipo ya estaba enrolado y activo. Cierra la brecha
 * de resiliencia de K3 (equipos que quedaban fuera hasta reinstalar).
 *
 * Rate-limit por IP (igual que login): sin límite, un atacante podría enumerar device_guids.
 */
class ClientReEnroll
{
    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $ipKey = (int)(sprintf('%u', crc32($_SERVER['REMOTE_ADDR'] ?? 'nil')) % 2147483647);
        if (!\RateLimiter::allow($ipKey, 'client-reenroll', 10, 60)) {
            Http::json(429, ['ok' => false, 'error' => 'Too many attempts, retry later']);
        }

        $deviceGuid = $body['deviceId'] ?? ($body['DeviceId'] ?? null);
        $deviceName = $body['deviceName'] ?? ($body['DeviceName'] ?? null);
        $version    = $body['version'] ?? ($body['Version'] ?? null);
        if (!$deviceGuid || !preg_match('/^[0-9a-fA-F-]{36}$/', $deviceGuid)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid or missing DeviceId']);
        }

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        // Solo equipos YA enrolados y activos. Uno desconocido no se auto-enrola por esta vía
        // (eso es /client/login con CC): re-enroll es recuperación, no alta.
        if (!$dev || ($dev['status'] ?? 'active') !== 'active') {
            Http::json(404, ['ok' => false, 'status' => 'not_enrolled', 'error' => 'Device not enrolled']);
        }
        $userId = (int)$dev['user_id'];
        $deviceId = (int)$dev['id'];

        DeviceRepo::touch($pdo, $deviceId, $deviceName, $version);
        $session = SessionRepo::createSession($pdo, $userId, $deviceId);
        try { AuditRepo::log($pdo, null, $userId, $deviceId, 'admin', 'reenroll', 'Sesión recuperada por device_guid'); } catch (\Throwable $e) {}

        Http::json(200, [
            'ok' => true,
            'token' => $session['token'],
            'expiresAtUtc' => $session['expiresAtUtc'],
        ]);
    }
}
