<?php
namespace Keeper\Endpoints;

use PDO;
use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\ScreenshotRepo;

/**
 * Ingesta de METADATA de screenshots. IMPORTANTE: este endpoint solo recibe
 * la metadata (objectKey, hash, tamano, trigger). El blob del PNG NO viaja
 * por aqui: el agente lo sube a object storage por otra via y reporta aqui
 * unicamente el objectKey resultante para dejar indice/auditoria.
 */
class ScreenshotMeta
{
    private const TRIGGERS = ['scheduled', 'event', 'on_demand'];

    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        $userId = (int)$sess['user_id'];

        $deviceGuid = $body['deviceId']    ?? null;
        $capturedAt = $body['capturedAt']  ?? null;
        $objectKey  = $body['objectKey']   ?? null;
        $sha256     = $body['sha256']      ?? null;
        $sizeBytes  = $body['sizeBytes']   ?? null;
        $trigger    = $body['triggerType'] ?? null;
        $commandId  = $body['commandId']   ?? null;

        if (!$deviceGuid) Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        if (!is_string($objectKey) || trim($objectKey) === '') Http::json(400, ['ok' => false, 'error' => 'Missing objectKey']);
        if (!is_string($sha256) || strlen($sha256) !== 64 || !ctype_xdigit($sha256)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid sha256']);
        }
        if (!is_string($trigger) || !in_array($trigger, self::TRIGGERS, true)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid triggerType']);
        }

        $cap = \DateTime::createFromFormat('Y-m-d H:i:s', (string)$capturedAt);
        if (!$cap) Http::json(400, ['ok' => false, 'error' => 'Invalid capturedAt']);

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        if ((int)$dev['user_id'] !== $userId) Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        if (($dev['status'] ?? 'active') !== 'active') Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        $deviceId = (int)$dev['id'];

        // Gate por tier server-side: capturas es modulo del nivel mas alto. Aunque un
        // cliente comprometido intente subir, si la firma no lo compro, se rechaza aqui.
        if (!\Keeper\Services\TierResolver::userAllows($pdo, $userId, 'screenshots')) {
            Http::json(403, ['ok' => false, 'error' => 'Module not licensed for this firm']);
        }

        $sizeBytes = ($sizeBytes !== null && $sizeBytes !== '') ? (int)$sizeBytes : null;
        $commandId = ($commandId !== null && $commandId !== '') ? (int)$commandId : null;

        try {
            $id = ScreenshotRepo::insert(
                $pdo,
                $userId,
                $deviceId,
                $cap->format('Y-m-d H:i:s'),
                mb_substr($objectKey, 0, 512, 'UTF-8'),
                strtolower($sha256),
                $sizeBytes,
                $trigger,
                $commandId
            );
            Http::json(200, ['ok' => true, 'id' => $id]);
        } catch (\PDOException $e) {
            error_log('ScreenshotMeta insert error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database insert failed']);
        }
    }
}
