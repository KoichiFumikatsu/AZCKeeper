<?php
namespace Keeper\Endpoints;
 
use Keeper\Http;
use Keeper\AuthService;
 
class DeviceLock
{
    // POST /client/device-lock/unlock
    // El cliente valida el PIN localmente contra su config.json
    // Este endpoint solo registra que se desbloqueó
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
        // (podría registrar eventos si fuera necesario)
        
        Http::json(200, [
            'ok' => true,
            'unlocked' => true,
            'message' => 'Equipo desbloqueado correctamente'
        ]);
    }
}