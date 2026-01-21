<?php
namespace Keeper\Endpoints;
 
use Keeper\Http;
use Keeper\Db;
 
class ForceHandshake
{
    public static function handle(): void
    {
        $pdo = Db::pdo();
        
        // Incrementar version para forzar actualización
        $pdo->exec("
            UPDATE keeper_policy_assignments 
            SET version = version + 1, updated_at = NOW() 
            WHERE scope = 'global' AND is_active = 1
        ");
        
        Http::json(200, [
            'ok' => true,
            'message' => 'Handshake forzado. Los clientes recibirán cambios en 60s o menos.'
        ]);
    }
}