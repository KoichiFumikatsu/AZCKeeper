<?php
namespace Keeper\Endpoints;
 
use Keeper\Http;
use Keeper\Db;
use Keeper\AuthService;
 
class DeviceLock
{
    // POST /client/device-lock/status
    public static function getStatus(): void
    {
        $sess = AuthService::requireSession();
        $userId = (int)$sess['user_id'];
        $deviceId = (int)$sess['device_id'];
 
        $pdo = Db::pdo();
 
        // Verificar si hay un bloqueo activo
        $st = $pdo->prepare("
            SELECT lock_reason, unlock_pin_hash, locked_at
            FROM keeper_device_locks
            WHERE device_id = :did AND is_active = 1
            LIMIT 1
        ");
        $st->execute(['did' => $deviceId]);
        $lock = $st->fetch();
 
        if (!$lock) {
            Http::json(200, [
                'ok' => true,
                'locked' => false
            ]);
        }
 
        Http::json(200, [
            'ok' => true,
            'locked' => true,
            'lockReason' => $lock['lock_reason'] ?? 'Equipo bloqueado por IT',
            'lockedAt' => $lock['locked_at'],
            'allowUnlock' => !empty($lock['unlock_pin_hash'])
        ]);
    }
 
    // POST /client/device-lock/unlock
    public static function tryUnlock(): void
    {
        $sess = AuthService::requireSession();
        $deviceId = (int)$sess['device_id'];
 
        $data = Http::jsonInput();
        $pin = $data['pin'] ?? ($data['Pin'] ?? null);
 
        if (!$pin) {
            Http::json(400, ['ok' => false, 'error' => 'PIN requerido']);
        }
 
        $pdo = Db::pdo();
 
        $st = $pdo->prepare("
            SELECT id, unlock_pin_hash
            FROM keeper_device_locks
            WHERE device_id = :did AND is_active = 1
            LIMIT 1
        ");
        $st->execute(['did' => $deviceId]);
        $lock = $st->fetch();
 
        if (!$lock) {
            Http::json(404, ['ok' => false, 'error' => 'No hay bloqueo activo']);
        }
 
        $pinHash = hash('sha256', $pin);
 
        if ($pinHash !== $lock['unlock_pin_hash']) {
            Http::json(401, ['ok' => false, 'error' => 'PIN incorrecto']);
        }
 
        // Desbloquear
        $upd = $pdo->prepare("
            UPDATE keeper_device_locks
            SET is_active = 0, unlocked_at = NOW()
            WHERE id = :id
        ");
        $upd->execute(['id' => $lock['id']]);
 
        Http::json(200, [
            'ok' => true,
            'unlocked' => true,
            'message' => 'Equipo desbloqueado correctamente'
        ]);
    }
}