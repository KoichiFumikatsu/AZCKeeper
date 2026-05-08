<?php
namespace Keeper\Endpoints;
 
use Keeper\Http;
use Keeper\Db;
 
/**
 * ForceHandshake:
 * Incrementa el campo `version` de la política global activa.
 * El cliente C# compara el policyVersion recibido en cada handshake:
 * si cambió, sabe que debe re-aplicar la configuración aunque el intervalo
 * normal no haya expirado.
 * 
 * NOTA: Este endpoint debe protegerse con autenticación de admin antes de
 * exponerlo en producción. Por ahora solo se usa internamente desde el panel.
 */
class ForceHandshake
{
    public static function handle(): void
    {
        $pdo = Db::pdo();
        
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