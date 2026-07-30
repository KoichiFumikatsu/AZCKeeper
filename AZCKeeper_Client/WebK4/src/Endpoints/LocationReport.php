<?php
namespace Keeper\Endpoints;

use PDO;
use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\LocationRepo;

/**
 * Ingesta de ubicacion reportada por el agente (GPS, wifi o IP segun el
 * metodo disponible en el dispositivo al momento de la captura).
 */
class LocationReport
{
    private const SOURCES = ['gps', 'wifi', 'ip'];

    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        $userId = (int)$sess['user_id'];

        $deviceGuid = $body['deviceId']   ?? null;
        $capturedAt = $body['capturedAt'] ?? null;
        $lat        = $body['latitude']   ?? null;
        $lng        = $body['longitude']  ?? null;
        $accuracyM  = $body['accuracyM']  ?? null;
        $source     = $body['source']     ?? null;

        if (!$deviceGuid) Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        if (!is_numeric($lat) || !is_numeric($lng)) Http::json(400, ['ok' => false, 'error' => 'Missing or invalid latitude/longitude']);
        $lat = (float)$lat;
        $lng = (float)$lng;
        if ($lat < -90.0 || $lat > 90.0) Http::json(400, ['ok' => false, 'error' => 'Latitude out of range']);
        if ($lng < -180.0 || $lng > 180.0) Http::json(400, ['ok' => false, 'error' => 'Longitude out of range']);
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid source']);
        }

        $cap = \DateTime::createFromFormat('Y-m-d H:i:s', (string)$capturedAt);
        if (!$cap) Http::json(400, ['ok' => false, 'error' => 'Invalid capturedAt']);

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        if ((int)$dev['user_id'] !== $userId) Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        if (($dev['status'] ?? 'active') !== 'active') Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        $deviceId = (int)$dev['id'];

        // Gate por tier server-side: ubicacion es modulo del nivel mas alto.
        if (!\Keeper\Services\TierResolver::userAllows($pdo, $userId, 'location')) {
            Http::json(403, ['ok' => false, 'error' => 'Module not licensed for this firm']);
        }

        $accuracyM = ($accuracyM !== null && $accuracyM !== '') ? (int)$accuracyM : null;

        try {
            $id = LocationRepo::insert(
                $pdo,
                $userId,
                $deviceId,
                $cap->format('Y-m-d H:i:s'),
                $lat,
                $lng,
                $accuracyM,
                $source
            );
            Http::json(200, ['ok' => true, 'id' => $id]);
        } catch (\PDOException $e) {
            error_log('LocationReport insert error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database insert failed']);
        }
    }
}
