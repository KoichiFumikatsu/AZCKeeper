<?php
namespace Keeper\Endpoints;

use PDO;
use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\CommandRepo;

/**
 * Canal servidor->equipo, lado cliente: el equipo recoge (poll) los comandos
 * que el panel encolo y reporta su resultado. Sirve al apagado remoto y al
 * diagnostico de red bajo demanda.
 */
class ClientCommands
{
    public static function poll(): void
    {
        $pdo = Db::pdo();

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        $userId = (int)$sess['user_id'];

        $deviceGuid = $_GET['deviceId'] ?? null;
        if (!$deviceGuid) Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        if ((int)$dev['user_id'] !== $userId) Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        if (($dev['status'] ?? 'active') !== 'active') Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        $deviceId = (int)$dev['id'];

        try {
            $commands = CommandRepo::pendingFor($pdo, $deviceId);
            Http::json(200, ['ok' => true, 'commands' => $commands]);
        } catch (\PDOException $e) {
            error_log('ClientCommands poll error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database error']);
        }
    }

    public static function result(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        $userId = (int)$sess['user_id'];

        $deviceGuid = $body['deviceId']  ?? null;
        $commandId  = $body['commandId'] ?? null;
        $status     = $body['status']    ?? null;
        $result     = $body['result']    ?? null;

        if (!$deviceGuid) Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        if ($commandId === null || !is_numeric($commandId)) Http::json(400, ['ok' => false, 'error' => 'Missing commandId']);
        if (!is_string($status) || !in_array($status, ['done', 'failed', 'acked'], true)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid status']);
        }
        if ($result !== null && !is_array($result)) Http::json(400, ['ok' => false, 'error' => 'Invalid result']);

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        if ((int)$dev['user_id'] !== $userId) Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        if (($dev['status'] ?? 'active') !== 'active') Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        $deviceId = (int)$dev['id'];

        try {
            $updated = CommandRepo::reportResult($pdo, (int)$commandId, $deviceId, $status, $result);
            Http::json(200, ['ok' => true, 'updated' => $updated]);
        } catch (\PDOException $e) {
            error_log('ClientCommands result error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database error']);
        }
    }
}
