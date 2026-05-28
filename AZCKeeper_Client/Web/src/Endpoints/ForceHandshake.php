<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\AdminAuthRepo;

class ForceHandshake
{
    public static function handle(): void
    {
        $token = $_COOKIE['keeper_admin_token'] ?? '';
        if (!$token) {
            Http::json(401, ['ok' => false, 'error' => 'Unauthorized']);
            return;
        }

        $pdo = Db::pdo();

        if (!AdminAuthRepo::validateSession($pdo, $token)) {
            Http::json(401, ['ok' => false, 'error' => 'Unauthorized']);
            return;
        }
        
        // keeper_policy_assignments no tiene updated_at — solo incrementamos version
        $stmt = $pdo->prepare("
            UPDATE keeper_policy_assignments 
            SET version = version + 1
            WHERE scope = 'global' AND is_active = 1
        ");
        $stmt->execute();
        $affected = $stmt->rowCount();

        Http::json(200, [
            'ok' => true,
            'rowsUpdated' => $affected,
            'message' => 'Versión de política global incrementada. Los clientes aplicarán cambios en el próximo handshake.'
        ]);
    }
}