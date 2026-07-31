<?php
namespace Keeper\Endpoints;

use PDO;
use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\CommandRepo;
use Keeper\Services\TierResolver;

/**
 * Panel -> equipo: encola comandos servidor->equipo (apagado remoto,
 * diagnostico de red bajo demanda, etc) que el cliente recoge en su poll.
 *
 * NOTA DE ALCANCE: este endpoint usa autenticacion por sesion como marcador
 * temporal, igual que ProcessView. El RBAC de admin (keeper_admin_sessions)
 * llega con la fase del panel; hoy no se exige que el device pertenezca al
 * usuario de la sesion (es una accion de admin) y created_by se guarda como
 * NULL hasta que exista un admin_id real que asociarle.
 */
class AdminCommand
{
    // Comando -> modulo de catalogo requerido en el tier de la firma (null = sin tier).
    private const MODULE_FOR = [
        'lock'           => 'deviceLock',
        'shutdown'       => 'remoteShutdown',
        'restart'        => 'remoteShutdown',
        'logoff'         => 'remoteShutdown',
        'network_diag'   => 'networkDiagnostic',
        'screenshot_now' => 'screenshots',
    ];

    public static function enqueue(): void
    {
        $pdo  = Db::pdo();
        \Keeper\AdminAuth::require();
        $body = Http::readJson();

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        // TODO RBAC admin: hoy usa sesion como marcador, como ProcessView.

        $deviceGuid       = $body['deviceId']    ?? null;
        $commandType      = $body['commandType'] ?? null;
        $params           = $body['params']      ?? null;
        $expiresInSeconds = $body['expiresInSeconds'] ?? null;

        if (!$deviceGuid) Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        if (!is_string($commandType) || !array_key_exists($commandType, self::MODULE_FOR)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid commandType']);
        }
        if ($params !== null && !is_array($params)) Http::json(400, ['ok' => false, 'error' => 'Invalid params']);
        if ($expiresInSeconds !== null && (!is_numeric($expiresInSeconds) || (int)$expiresInSeconds <= 0)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid expiresInSeconds']);
        }

        // Resuelve el device por guid sin exigir que sea del usuario de la
        // sesion: esta es una accion de admin sobre cualquier device.
        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        $deviceId = (int)$dev['id'];

        // Candado de tier: la firma del equipo debe incluir el modulo del comando.
        $module = self::MODULE_FOR[$commandType];
        if ($module !== null && !TierResolver::userAllows($pdo, (int)$dev['user_id'], $module)) {
            Http::json(403, ['ok' => false, 'error' => 'El tier de la firma no incluye este control']);
        }

        try {
            // created_by = null por ahora; sera el admin_id real cuando exista RBAC.
            $commandId = CommandRepo::enqueue(
                $pdo,
                $deviceId,
                $commandType,
                $params,
                null,
                $expiresInSeconds !== null ? (int)$expiresInSeconds : null
            );
            Http::json(200, ['ok' => true, 'commandId' => $commandId]);
        } catch (\PDOException $e) {
            error_log('AdminCommand enqueue error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database error']);
        }
    }
}
