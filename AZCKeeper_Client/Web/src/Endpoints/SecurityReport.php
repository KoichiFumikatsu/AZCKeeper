<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\AuthService;
use Keeper\Repos\SecurityStateRepo;

/**
 * SecurityReport — recibe el estado de controles de seguridad observado en el equipo.
 *
 * Payload:
 *   {
 *     "deviceId": "<guid>",
 *     "agentPresent": false,
 *     "controls": { "<clave>": { "present": bool, "value": <int|string|null> }, ... }
 *   }
 *
 * En Fase 0 el cliente solo LEE el registro; no aplica nada. agentPresent viaja
 * en false hasta que exista AZCKeeperAgent (Fase 1).
 */
class SecurityReport
{
    private const MAX_CONTROLS = 100;

    public static function handle(): void
    {
        $sess   = AuthService::requireSession();
        $userId = (int)$sess['user_id'];

        $data = Http::jsonInput();

        $deviceGuid   = $data['deviceId']     ?? ($data['DeviceId']     ?? null);
        $agentPresent = $data['agentPresent'] ?? ($data['AgentPresent'] ?? false);
        $controls     = $data['controls']     ?? ($data['Controls']     ?? null);

        if (!$deviceGuid) {
            Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        }
        if (!is_array($controls)) {
            Http::json(400, ['ok' => false, 'error' => 'Missing or invalid controls object']);
        }
        if (count($controls) > self::MAX_CONTROLS) {
            Http::json(413, ['ok' => false, 'error' => 'Too many controls (max ' . self::MAX_CONTROLS . ')']);
        }

        $pdo = Db::pdo();

        $st = $pdo->prepare("SELECT id, user_id, status FROM keeper_devices WHERE device_guid = :g LIMIT 1");
        $st->execute([':g' => $deviceGuid]);
        $dev = $st->fetch();

        if (!$dev) {
            Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        }
        if ((int)$dev['user_id'] !== $userId) {
            Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        }
        if (($dev['status'] ?? 'active') !== 'active') {
            Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        }

        try {
            $changed = SecurityStateRepo::upsert(
                $pdo,
                $userId,
                (int)$dev['id'],
                (bool)$agentPresent,
                $controls
            );
            Http::json(200, ['ok' => true, 'changed' => $changed]);
        } catch (\PDOException $e) {
            error_log("SecurityReport UPSERT error: " . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database write failed']);
        }
    }
}
