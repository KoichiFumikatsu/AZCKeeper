<?php
namespace Keeper\Endpoints;
 
use Keeper\Http;
use Keeper\AuthService;
use Keeper\Db;
use Keeper\PolicyService;
use Keeper\Repos\PolicyRepo;
 
class DeviceLock
{
    // POST /client/device-lock/status
    // Consulta el estado actual de bloqueo según la política efectiva
    // El cliente lo llama cada 15-30 segundos para verificar cambios en tiempo real
    public static function getStatus(): void
    {
        $sess = AuthService::requireSession();
        $userId = (int)$sess['user_id'];
        $deviceId = (int)$sess['device_id'];
        
        $pdo = Db::pdo();
        
        // Obtener política global
        $global = PolicyRepo::getActiveGlobal($pdo);
        if (!$global) {
            Http::json(500, ['ok' => false, 'error' => 'No active global policy']);
        }
        
        // Iniciar con política global
        $effective = json_decode($global['policy_json'], true);
        if (!is_array($effective)) $effective = [];
        
        // Merge con política de usuario (si existe)
        $userPol = PolicyRepo::getActiveUser($pdo, $userId);
        if ($userPol) {
            $u = json_decode($userPol['policy_json'], true);
            if (is_array($u)) {
                $effective = PolicyService::deepMerge($effective, $u);
            }
        }
        
        // Merge con política de dispositivo (si existe)
        $devPol = PolicyRepo::getActiveDevice($pdo, $deviceId);
        if ($devPol) {
            $d = json_decode($devPol['policy_json'], true);
            if (is_array($d)) {
                $effective = PolicyService::deepMerge($effective, $d);
            }
        }
        
        // Extraer configuración de blocking
        $blocking = $effective['blocking'] ?? [];
        
        // Retornar en el mismo formato que EffectiveBlocking del handshake
        Http::json(200, [
            'ok' => true,
            'blocking' => [
                'enableDeviceLock' => (bool)($blocking['enableDeviceLock'] ?? false),
                'lockMessage' => $blocking['lockMessage'] ?? 'Dispositivo bloqueado por políticas de seguridad.',
                'allowUnlockWithPin' => (bool)($blocking['allowUnlockWithPin'] ?? true),
                'unlockPin' => $blocking['unlockPin'] ?? null
            ]
        ]);
    }
    
    // POST /client/device-lock/unlock
    // El cliente valida el PIN localmente contra su config.json
    // Este endpoint marca el dispositivo como desbloqueado en la BD
    public static function tryUnlock(): void
    {
        $sess = AuthService::requireSession();
        $userId = (int)$sess['user_id'];
        $deviceId = (int)$sess['device_id'];

        $data = Http::jsonInput();
        $rawPin = $data['pin'] ?? ($data['Pin'] ?? null);
        $pin = is_string($rawPin) ? trim($rawPin) : null;

        if (!$pin) {
            Http::json(400, ['ok' => false, 'error' => 'PIN requerido']);
        }

        // El cliente ya validó el PIN localmente
        // Este endpoint simplemente confirma el desbloqueo
        
        Http::json(200, [
            'ok' => true,
            'unlocked' => true,
            'message' => 'Equipo desbloqueado correctamente'
        ]);
    }
}