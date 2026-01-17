<?php
namespace Keeper;

use PDO;
use Keeper\Db;
use Keeper\Http;
use Keeper\Repos\SessionRepo;

class AuthService
{
    /**
     * Exige Authorization: Bearer <token>
     * Valida contra keeper_sessions.token_hash (sha256 del token plano).
     * Retorna fila de sesión: {id,user_id,device_id,expires_at,revoked_at}
     */
    public static function requireSession(): array
    {
        $pdo = Db::pdo();

        $token = Http::bearerToken();
        if (!$token) {
            Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        }

        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) {
            Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        }

        return $sess;
    }
}
