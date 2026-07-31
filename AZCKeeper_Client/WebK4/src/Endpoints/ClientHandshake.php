<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\PolicyService;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\PolicyRepo;
use Keeper\Repos\DiagnosticRepo;
use Keeper\Services\TierResolver;

/**
 * Handshake de Keeper 4. Resuelve la politica efectiva (global -> user -> device)
 * y la RECORTA contra el tier de la firma del usuario: un modulo que el tier no
 * permite queda en false, aunque la politica lo pida. El cliente nunca recibe un
 * modulo que la firma no compro.
 */
class ClientHandshake
{
    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $version    = $body['version']    ?? ($body['Version']    ?? null);
        $deviceGuid = $body['deviceId']   ?? ($body['DeviceId']   ?? null);
        $deviceName = $body['deviceName'] ?? ($body['DeviceName'] ?? null);
        $idleSeconds = isset($body['idleSeconds']) && is_numeric($body['idleSeconds']) ? max(0, (int)$body['idleSeconds']) : null;

        if (!$deviceGuid || !preg_match('/^[0-9a-fA-F-]{36}$/', $deviceGuid)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid or missing DeviceId']);
        }

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);

        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);

        $userId   = (int)$sess['user_id'];
        $deviceId = (int)($sess['device_id'] ?? 0);

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(403, ['ok' => false, 'error' => 'Device not enrolled']);
        $deviceId = (int)$dev['id'];
        // El equipo debe pertenecer al usuario de la sesion: si no, un token valido
        // podria pisar device_name/version/last_seen y jalar la politica de otro equipo.
        if ((int)$dev['user_id'] !== $userId) {
            Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        }
        if (($dev['status'] ?? 'active') !== 'active') {
            Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        }
        DeviceRepo::touch($pdo, $deviceId, $deviceName, $version, $idleSeconds);

        // Specs del equipo (llegan solo en el primer handshake de cada arranque). Se guardan
        // crudas; tope de 8 KB para que no infle la fila.
        if (isset($body['specs']) && is_array($body['specs'])) {
            $specsJson = json_encode($body['specs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($specsJson !== false && strlen($specsJson) <= 8192) {
                try { DeviceRepo::saveSpecs($pdo, $deviceId, $specsJson); }
                catch (\Throwable $e) { error_log('saveSpecs: ' . $e->getMessage()); }
            }
        }

        // 1. Composicion de la politica: global -> user -> device.
        $policies = PolicyRepo::getAllPolicies($pdo, $userId, $deviceId);
        $global = $policies['global'];
        if (!$global) Http::json(500, ['ok' => false, 'error' => 'No active global policy']);

        $effective   = json_decode($global['policy_json'], true) ?? [];
        $composition = [['scope' => 'global', 'policyId' => (int)$global['id'], 'version' => (int)$global['version']]];

        foreach (['user', 'device'] as $scope) {
            if ($policies[$scope]) {
                $layer = json_decode($policies[$scope]['policy_json'], true);
                if (is_array($layer)) {
                    $effective     = PolicyService::deepMerge($effective, $layer);
                    $composition[] = ['scope' => $scope, 'policyId' => (int)$policies[$scope]['id'], 'version' => (int)$policies[$scope]['version']];
                }
            }
        }

        // 2. Recorte por tier: la firma del usuario define que modulos estan permitidos.
        $firmaId  = PolicyRepo::firmaOfUser($pdo, $userId);
        $allowed  = TierResolver::effectiveModules($pdo, $firmaId);
        $effective = self::clipToTier($effective, $allowed);

        // 3. Modo diagnostico: si IT marco a esta persona en el panel, el cliente entra en
        // el loop rapido (sube snapshots cada intervalSeconds hasta untilUtc). Sin sesion
        // activa -> enabled:false y el cliente sigue en cadencia normal.
        $diagSess = DiagnosticRepo::activeSession($pdo, $userId);
        $diagnostics = $diagSess
            ? ['enabled' => true, 'intervalSeconds' => 4,
               'untilUtc' => gmdate('Y-m-d\TH:i:s\Z', strtotime($diagSess['expires_at'] . ' UTC'))]
            : ['enabled' => false];

        Http::json(200, [
            'ok'            => true,
            'serverTimeUtc' => gmdate('Y-m-d\TH:i:s\Z'),
            'displayName'   => $sess['display_name'] ?? null,
            'policyApplied' => [
                'scope'       => end($composition)['scope'],
                'composition' => $composition,
                'firmaId'     => $firmaId,
                'allowedModules' => $allowed,
            ],
            'effectiveConfig' => $effective,
            'diagnostics'     => $diagnostics,
        ]);
    }

    /**
     * Fuerza a false en effectiveConfig.modules todo modulo que el tier no permita.
     * El mapeo entre el codigo de catalogo ('windowTracking') y el flag del cliente
     * ('enableWindowTracking') es directo con el prefijo 'enable'.
     */
    private static function clipToTier(array $effective, array $allowed): array
    {
        if (!isset($effective['modules']) || !is_array($effective['modules'])) return $effective;

        $allowedFlags = [];
        foreach ($allowed as $code) {
            $allowedFlags['enable' . ucfirst($code)] = true;
        }
        foreach ($effective['modules'] as $flag => $val) {
            // Solo recorta flags que corresponden a un modulo de catalogo; deja
            // pasar los que no (ej. enableDebugWindow, que no es modulo tarifable).
            if (str_starts_with($flag, 'enable') && self::isCatalogFlag($flag)) {
                if (empty($allowedFlags[$flag])) {
                    $effective['modules'][$flag] = false;
                }
            }
        }
        return $effective;
    }

    private static function isCatalogFlag(string $flag): bool
    {
        // Flags que SI corresponden a modulos de catalogo (recortables por tier).
        static $catalog = [
            'enableActivityTracking', 'enableWindowTracking', 'enableCallTracking',
            'enableProcessView', 'enableWebBlocking', 'enableDeviceLock',
            'enableSecurity', 'enableNetworkDiagnostic', 'enableRemoteShutdown',
            'enableScreenshots', 'enableLocation',
        ];
        return in_array($flag, $catalog, true);
    }
}
